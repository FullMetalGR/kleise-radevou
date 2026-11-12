<?php

use App\Application\Payment\ConnectionState;
use App\Application\Payment\PaymentAdapter;
use App\Application\Payment\PaymentNote;
use App\Application\Payment\PaymentNotFlagged;
use App\Application\Payment\PaymentProvider;
use App\Application\Payment\ProviderRegistry;
use App\Application\Payment\RegistrationRefused;
use App\Infrastructure\Db\BookingReader;
use App\Infrastructure\ExpireHoldsFactory;
use App\Infrastructure\Payment\PaymentWebhook;
use App\Infrastructure\PaymentFactory;
use App\Jobs\FlagPayment;
use App\Models\User;
use App\Notifications\LatePaymentNotice;
use App\Tenancy\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\Response;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Routing\Route as RouteEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakePaymentAdapter;
use Tests\Support\FixtureDay;
use Tests\TestCase;

pest()->use(DatabaseTruncation::class);

/*
 * Money that has already moved: a cancelled booking a payment landed for has that payment
 * flagged with a note, after the commit, and nothing is ever refunded. luna's Colour is an
 * online service, Haircut a pay-on-site one and Bridal trial an online one that asks for
 * approval; the fake is connected and active.
 *
 * Not wrapped in a transaction: the hold, the paid transition and the cancel enter the
 * transaction runner, which refuses to open inside another, and the fake records whether a
 * transaction was open when it was asked, which only means something here.
 */

beforeEach(function () {
    dropDemoSchemas();
    seedDemo();
    FixtureDay::pinClock();
    Http::preventStrayRequests();
    settingsOf('luna')->update(['reminder_lead_hours' => 0]);
    connectPaymentFake();
    Exceptions::fake();
    flagLogged(true);
    Log::listen(function (MessageLogged $line): void {
        flagLogged()->append([$line->level, $line->message]);
    });
});

afterEach(function () {
    Http::assertNothingSent();
    dropDemoSchemas();
    DB::table('users')->delete();
    DB::table('tenants')->delete();
});

/**
 * A hold for Casey on luna's service named, visited at the pay hop where it is online and not
 * a request, so the session pd_1 is recorded.
 *
 * @return array{uuid: string, manage_token: string}
 */
function flagHold(TestCase $test, string $service = 'Colour', bool $hop = true): array
{
    $ids = fn (string $table, string $name): mixed => DB::table('tenant_luna.'.$table)->where('name', $name)->value('id');
    $held = $test->postJson(tenantUrl('luna', '/api/v1/holds'), [
        'customer_name' => 'Casey Customer',
        'customer_email' => 'casey@example.com',
        'items' => [['service_id' => $ids('services', $service), 'staff_id' => $ids('staff', 'Elena Papadaki')]],
        'start' => (new DateTimeImmutable(FixtureDay::START, new DateTimeZone(FixtureDay::ZONE)))->format(DateTimeImmutable::RFC3339),
    ])->assertCreated()->json();

    assert(is_array($held) && is_string($held['uuid']) && is_string($held['manage_token']));
    $held = ['uuid' => $held['uuid'], 'manage_token' => $held['manage_token']];

    if ($hop) {
        flagHop($test, $held)->assertStatus(303);
    }

    return $held;
}

/**
 * @param  array{uuid: string, manage_token: string}  $held
 * @return TestResponse<Response>
 */
function flagHop(TestCase $test, array $held): TestResponse
{
    return $test->get(tenantUrl('luna', '/pay/'.$held['uuid'].'?'.http_build_query(['token' => $held['manage_token']])));
}

/**
 * The fake's notification that the payment named moved for the booking, as Paydesk posts it.
 *
 * @param  array{uuid: string}  $held
 */
function flagPaid(TestCase $test, array $held, ?string $payment = 'pd_1', string $event = 'evt_1'): void
{
    $body = json_encode(['id' => $event, 'type' => FakePaymentAdapter::SETTLED, 'booking' => $held['uuid'], 'payment' => $payment], JSON_THROW_ON_ERROR);

    $test->call('POST', centralUrl('/webhooks/'.FakePaymentAdapter::SLUG.'/hook-token-luna'), [], [], [], [
        'HTTP_PAYDESK_SEAL' => FakePaymentAdapter::seal($body, paymentFakeCredentials()['signing_phrase']),
        'CONTENT_TYPE' => 'application/json',
    ], $body)->assertOk();
}

/**
 * The booking cancelled by the customer through their link, or by luna's owner from the admin.
 *
 * @param  array{uuid: string, manage_token: string}  $held
 * @return TestResponse<Response>
 */
function flagCancel(TestCase $test, array $held, string $by = 'customer'): TestResponse
{
    if ($by === 'customer') {
        return $test->postJson(tenantUrl('luna', '/api/v1/bookings/'.$held['uuid'].'/cancel'), ['token' => $held['manage_token']]);
    }

    return $test->actingAs(User::query()->where('email', 'owner@luna.example')->sole())
        ->from(tenantUrl('luna', '/admin/bookings/'.$held['uuid']))
        ->post(tenantUrl('luna', '/admin/bookings/'.$held['uuid'].'/cancel'));
}

/**
 * @return list<list<string>>
 */
function flagsAsked(): array
{
    return array_values(array_filter(paymentFake()->asked, fn (array $question): bool => $question[0] === 'flagPayment'));
}

function flagStatus(string $uuid): mixed
{
    return DB::table('tenant_luna.bookings')->where('uuid', $uuid)->value('status');
}

/**
 * The booking's status and payment_ref, the two a case states before it acts.
 *
 * @return list<mixed>
 */
function flagRow(string $uuid): array
{
    $row = DB::table('tenant_luna.bookings')->where('uuid', $uuid)->first(['status', 'payment_ref']);
    assert($row instanceof stdClass);

    return [$row->status, $row->payment_ref];
}

/**
 * The guard a case asking the provider nothing needs: the flag job ran to its end, so nothing
 * was reported (the job reports every throw as PaymentNotFlagged) and no error line was logged
 * (a throw out of a dispatch is logged as a follow-up that could not be queued). Without it a
 * job that died before the provider would pass for one that decided not to ask.
 */
function flagQuiet(): void
{
    Exceptions::assertNothingReported();
    expect(array_filter(flagLogged()->getArrayCopy(), fn (array $line): bool => $line[0] === 'error'))->toBe([]);
}

/**
 * Every line logged during the case, as [level, message], begun afresh by each case.
 *
 * @return ArrayObject<int, array{0: string, 1: string}>
 */
function flagLogged(bool $fresh = false): ArrayObject
{
    static $lines = null;

    if ($fresh || ! $lines instanceof ArrayObject) {
        /** @var ArrayObject<int, array{0: string, 1: string}> $begun */
        $begun = new ArrayObject;
        $lines = $begun;
    }

    return $lines;
}

/**
 * The late payment's notices sent during the case, as [to, the booking it names], read off the
 * subject the notice alone carries and the line naming the booking by its reference, each read
 * through the translator.
 *
 * @return list<array{string, string}>
 */
function flagOwnerTold(): array
{
    $told = [];

    $subject = businessMailLine('business-mail.late_payment.subject', ['business' => 'Luna Hair Studio']);
    $reference = preg_quote(businessMailLine('business-mail.late_payment.reference'), '/');

    foreach (seamSent() as $mail) {
        if ((string) $mail->getSubject() === $subject) {
            preg_match('/^'.$reference.': (\S+)$/m', (string) $mail->getTextBody(), $named);
            $told[] = [implode(',', array_map(fn ($to): string => $to->getAddress(), $mail->getTo())), $named[1] ?? ''];
        }
    }

    return $told;
}

it('flags the payment that landed once, after the commit, with the note naming the booking, whoever cancels', function (string $by) {
    $held = flagHold($this);
    flagPaid($this, $held);

    expect(flagStatus($held['uuid']))->toBe('confirmed')
        ->and(flagsAsked())->toBe([]);

    flagCancel($this, $held, $by)->assertSessionHasNoErrors();

    expect(flagStatus($held['uuid']))->toBe('cancelled')
        ->and(flagsAsked())->toBe([['flagPayment', $held['uuid'], 'pd_1', PaymentNote::cancelled($held['uuid'])]])
        ->and(PaymentNote::cancelled($held['uuid']))->toBe('Booking '.$held['uuid'].' was cancelled. The slot is released and nothing has been refunded.')
        ->and(end(paymentFake()->transactionOpen))->toBeFalse()
        // A cancelled booking's flag mails nobody: the owner's late-payment notice is the
        // late road's alone.
        ->and(flagOwnerTold())->toBe([]);
})->with(['customer', 'admin']);

it('names the payment the notification said moved, which need not be the session the hop recorded', function () {
    $held = flagHold($this);
    flagPaid($this, $held, 'pd_paid_elsewhere');

    expect(DB::table('tenant_luna.bookings')->where('uuid', $held['uuid'])->value('payment_ref'))->toBe('pd_paid_elsewhere');

    flagCancel($this, $held)->assertSuccessful();

    expect(flagsAsked())->toBe([['flagPayment', $held['uuid'], 'pd_paid_elsewhere', PaymentNote::cancelled($held['uuid'])]]);
});

it('asks the provider nothing where no payment landed, a reference alone included, from each cancellable state', function (string $state, array $before) {
    $held = match ($state) {
        'pending, a session recorded' => flagHold($this),
        'pending, no session' => flagHold($this, 'Colour', false),
        'awaiting approval' => flagHold($this, 'Bridal trial', false),
        'awaiting payment, a session recorded' => (function (): array {
            $held = flagHold($this);
            DB::table('tenant_luna.bookings')->where('uuid', $held['uuid'])->update(['status' => 'awaiting_payment', 'hold_class' => 'payment']);

            return $held;
        })(),
        'confirmed on site at the hop after a session was recorded' => (function (): array {
            $held = flagHold($this);
            connectPaymentFake(ConnectionState::Disabled);
            flagHop($this, $held)->assertStatus(303);
            connectPaymentFake();

            return $held;
        })(),
        default => flagHold($this, 'Haircut', false),
    };

    if ($state === 'confirmed, paid on site, no reference') {
        $this->postJson(tenantUrl('luna', '/api/v1/bookings/'.$held['uuid'].'/confirm'), ['token' => $held['manage_token']])->assertSuccessful();
    }

    expect(flagRow($held['uuid']))->toBe($before);

    flagCancel($this, $held)->assertSuccessful();

    expect(flagStatus($held['uuid']))->toBe('cancelled')
        ->and(flagsAsked())->toBe([]);
    flagQuiet();
})->with([
    'pending, a session recorded' => ['pending, a session recorded', ['pending', 'pd_1']],
    'pending, no session' => ['pending, no session', ['pending', null]],
    'awaiting approval' => ['awaiting approval', ['awaiting_approval', null]],
    'awaiting payment, a session recorded' => ['awaiting payment, a session recorded', ['awaiting_payment', 'pd_1']],
    'confirmed on site at the hop after a session was recorded' => ['confirmed on site at the hop after a session was recorded', ['confirmed', 'pd_1']],
    'confirmed, paid on site, no reference' => ['confirmed, paid on site, no reference', ['confirmed', null]],
]);

it('keeps the recorded session where the notice names no payment, an empty id read as none, and the cancel names that session', function (?string $payment) {
    $held = flagHold($this);
    flagPaid($this, $held, $payment);

    expect(flagRow($held['uuid']))->toBe(['confirmed', 'pd_1']);

    flagCancel($this, $held)->assertSuccessful();

    expect(flagsAsked())->toBe([['flagPayment', $held['uuid'], 'pd_1', PaymentNote::cancelled($held['uuid'])]]);
})->with(['no payment id' => [null], 'an empty payment id' => ['']]);

it('flags a late payment with its own note, tells the owner once, confirms nothing, revives nothing, marks the event, and flags once per event', function (string $road, string $status) {
    $held = flagHold($this);

    match ($road) {
        'released' => $this->deleteJson(tenantUrl('luna', '/api/v1/holds/'.$held['uuid'].'?token='.$held['manage_token']))->assertSuccessful(),
        'cancelled' => flagCancel($this, $held)->assertSuccessful(),
        'lapsed' => DB::table('tenant_luna.bookings')->where('uuid', $held['uuid'])->update(['hold_expires_at' => DB::raw("now() - interval '1 second'")]),
        'lapsed and swept' => (function () use ($held): void {
            DB::table('tenant_luna.bookings')->where('uuid', $held['uuid'])->update(['hold_expires_at' => DB::raw("now() - interval '1 second'")]);
            app(ExpireHoldsFactory::class)->for(Tenant::query()->where('slug', 'luna')->sole())->sweep();
        })(),
        'settled on site at the hop' => (function () use ($held): void {
            connectPaymentFake(ConnectionState::Disabled);
            flagHop($this, $held)->assertStatus(303);
            connectPaymentFake();
        })(),
        default => flagPaid($this, $held, 'pd_1', 'evt_paid'),
    };
    $before = flagRow($held['uuid']);

    expect($before)->toBe([$status, 'pd_1'])
        ->and(flagsAsked())->toBe([]);
    seamTransport()->flush();

    flagPaid($this, $held, 'pd_late', 'evt_late');
    flagPaid($this, $held, 'pd_late', 'evt_late');

    expect(flagRow($held['uuid']))->toBe($before)
        ->and(DB::table('payment_events')->where('event_id', 'evt_late')->whereNotNull('processed_at')->count())->toBe(1)
        ->and(flagsAsked())->toBe([['flagPayment', $held['uuid'], 'pd_late', PaymentNote::late($held['uuid'])]])
        ->and(PaymentNote::late($held['uuid']))->toBe('Booking '.$held['uuid'].' was no longer waiting for this payment when it arrived, so the payment bought nothing. Nothing has been refunded.')
        ->and(end(paymentFake()->transactionOpen))->toBeFalse()
        ->and(array_values(array_filter(flagLogged()->getArrayCopy(), fn (array $line): bool => $line[1] === PaymentWebhook::LATE)))->toBe([['warning', PaymentWebhook::LATE]])
        ->and(flagOwnerTold())->toBe([['owner@luna.example', $held['uuid']]])
        ->and(seamSent())->toHaveCount(1);
    flagQuiet();
})->with([
    'released' => ['released', 'cancelled'],
    'cancelled' => ['cancelled', 'cancelled'],
    'lapsed' => ['lapsed', 'pending'],
    'lapsed and swept' => ['lapsed and swept', 'expired'],
    'settled on site at the hop' => ['settled on site at the hop', 'confirmed'],
    'confirmed by another payment' => ['confirmed by another payment', 'confirmed'],
]);

it('reads another notice of the payment that confirmed the booking as its repeat, not a late payment, whatever the booking became', function (?string $payment, bool $cancelled) {
    $held = flagHold($this);
    flagPaid($this, $held, 'pd_paid');

    if ($cancelled) {
        flagCancel($this, $held)->assertSuccessful();
    }

    $asked = flagsAsked();
    flagPaid($this, $held, $payment, 'evt_again');

    expect(flagsAsked())->toBe($asked)
        ->and(array_filter(flagLogged()->getArrayCopy(), fn (array $line): bool => $line[1] === PaymentWebhook::LATE))->toBe([]);
    flagQuiet();
})->with([
    'the same payment, confirmed' => ['pd_paid', false],
    'the same payment, cancelled since' => ['pd_paid', true],
    'no payment named, confirmed' => [null, false],
]);

it('reads a notice naming a payment as the confirming one\'s where the notice that confirmed the booking named none, and flags nothing', function () {
    $held = flagHold($this);
    flagPaid($this, $held, null);

    expect(flagRow($held['uuid']))->toBe(['confirmed', 'pd_1']);

    flagPaid($this, $held, 'pd_real', 'evt_again');

    expect(flagRow($held['uuid']))->toBe(['confirmed', 'pd_1'])
        ->and(flagsAsked())->toBe([])
        ->and(array_filter(flagLogged()->getArrayCopy(), fn (array $line): bool => $line[1] === PaymentWebhook::LATE))->toBe([]);
    flagQuiet();
});

it('asks the provider to expire a session only where its descriptor declares that it can', function (?bool $declared) {
    $held = flagHold($this);
    $fake = paymentFake();
    $fake->canExpireCheckout = $declared;
    app()->forgetInstance(ProviderRegistry::class);
    $tenant = Tenant::query()->where('slug', 'luna')->sole();
    $booking = app(BookingReader::class)->snapshot($tenant, $held['uuid']);
    assert($booking !== null);
    $expire = fn () => app(PaymentFactory::class)->provider($tenant)->expireCheckout($booking, 'pd_1');

    // A descriptor declaring neither is no provider of this build: the registry refuses it,
    // naming the field, so nothing undeclared is ever asked.
    $declared === null ? expect($expire)->toThrow(RegistrationRefused::class, 'canExpireCheckout') : $expire();

    expect($fake->expired)->toBe($declared === true ? ['pd_1'] : []);
})->with(['declared' => [true], 'not declared' => [false], 'undeclared' => [null]]);

it('asks the provider nothing on a reject', function () {
    $held = flagHold($this, 'Bridal trial', false);

    $this->actingAs(User::query()->where('email', 'owner@luna.example')->sole())
        ->from(tenantUrl('luna', '/admin/bookings/'.$held['uuid']))
        ->post(tenantUrl('luna', '/admin/bookings/'.$held['uuid'].'/reject'), ['reason' => 'Fully booked that week.'])
        ->assertSessionHasNoErrors();

    expect(flagStatus($held['uuid']))->toBe('rejected')
        ->and(paymentFake()->asked)->toBe([]);
    flagQuiet();
});

it('reports a provider that throws at the flag once, carrying no secret, and the cancel reads as landed to whoever pressed it', function (string $by) {
    $held = flagHold($this);
    flagPaid($this, $held);
    Exceptions::fake();
    $secrets = paymentFakeCredentials();
    paymentFake()->flagFailsWith = 'Paydesk refused token '.$secrets['api_token'].' signed with '.$secrets['signing_phrase'];

    $answer = flagCancel($this, $held, $by);

    $by === 'customer' ? $answer->assertSuccessful() : $answer->assertRedirect(tenantUrl('luna', '/admin/bookings/'.$held['uuid']))->assertSessionHasNoErrors();
    expect(flagStatus($held['uuid']))->toBe('cancelled')
        ->and(flagsAsked())->toHaveCount(1);
    Exceptions::assertReportedCount(1);
    Exceptions::assertReported(function (PaymentNotFlagged $report) use ($held, $secrets): bool {
        $said = $report->getMessage();

        return str_contains($said, $held['uuid'])
            && str_contains($said, RuntimeException::class)
            && $report->getPrevious() === null
            && array_filter($secrets, fn (string $secret): bool => str_contains($said, $secret)) === []
            && ! str_contains($said, 'Paydesk refused');
    });
})->with(['customer', 'admin']);

it('reports a provider that throws at a late payment\'s flag once, carrying no secret, answers the notice 200 and leaves the event marked', function () {
    $held = flagHold($this);
    DB::table('tenant_luna.bookings')->where('uuid', $held['uuid'])->update(['hold_expires_at' => DB::raw("now() - interval '1 second'")]);
    $secrets = paymentFakeCredentials();
    paymentFake()->flagFailsWith = 'Paydesk refused token '.$secrets['api_token'].' signed with '.$secrets['signing_phrase'];

    flagPaid($this, $held, 'pd_late', 'evt_late');

    expect(flagsAsked())->toHaveCount(1)
        ->and(DB::table('payment_events')->where('event_id', 'evt_late')->whereNotNull('processed_at')->count())->toBe(1)
        ->and(flagOwnerTold())->toBe([['owner@luna.example', $held['uuid']]]);
    Exceptions::assertReportedCount(1);
    Exceptions::assertReported(function (PaymentNotFlagged $report) use ($held, $secrets): bool {
        $said = $report->getMessage();

        return str_contains($said, $held['uuid'])
            && str_contains($said, RuntimeException::class)
            && $report->getPrevious() === null
            && array_filter($secrets, fn (string $secret): bool => str_contains($said, $secret)) === []
            && ! str_contains($said, 'Paydesk refused');
    });
});

it('reports a read the flag job makes that throws once, on either road, asks the provider nothing and leaves no failed_jobs row', function (?string $latePayment) {
    $held = flagHold($this);
    flagPaid($this, $held);
    DB::table('tenant_luna.bookings')->where('uuid', $held['uuid'])->update(['status' => 'cancelled']);
    DB::statement('alter table tenant_luna.bookings rename to bookings_away');

    try {
        FlagPayment::dispatch(Tenant::query()->where('slug', 'luna')->sole()->id, $held['uuid'], $latePayment);
    } finally {
        DB::statement('alter table tenant_luna.bookings_away rename to bookings');
    }

    expect(flagsAsked())->toBe([])
        ->and(DB::table('failed_jobs')->count())->toBe(0);
    Exceptions::assertReportedCount(1);
    Exceptions::assertReported(fn (PaymentNotFlagged $report): bool => str_contains($report->getMessage(), $held['uuid'])
        && str_contains($report->getMessage(), QueryException::class)
        && $report->getPrevious() === null);
})->with(['the late road' => ['pd_late'], 'the cancel road' => [null]]);

it('tells the owner of a late payment whose notice names no payment, and flags nothing, since there is no payment to leave a note on', function () {
    $held = flagHold($this);
    DB::table('tenant_luna.bookings')->where('uuid', $held['uuid'])->update(['hold_expires_at' => DB::raw("now() - interval '1 second'")]);
    seamTransport()->flush();

    flagPaid($this, $held, null, 'evt_late');
    flagPaid($this, $held, null, 'evt_late');

    expect(flagsAsked())->toBe([])
        ->and(flagOwnerTold())->toBe([['owner@luna.example', $held['uuid']]]);
    flagQuiet();
});

it('never asks a provider whose descriptor declares that no note can be left on a payment', function () {
    $held = flagHold($this);
    flagPaid($this, $held);
    // A descriptor is read once, when its adapter is registered, so the registry is built again.
    paymentFake()->supportsRefundFlag = false;
    app()->forgetInstance(ProviderRegistry::class);

    flagCancel($this, $held)->assertSuccessful();

    expect(flagStatus($held['uuid']))->toBe('cancelled')
        ->and(flagsAsked())->toBe([]);
    flagQuiet();
});

it('has no road that refunds: no operation of the port, no method of an adapter or a payment class, and no route', function () {
    $named = [];

    // The payment classes, and the late payment's road to the owner beside the flag's job.
    $roads = [app_path('Jobs/FlagPayment.php'), app_path('Notifications/AnnounceLatePayment.php'), app_path('Jobs/SendLatePaymentNotice.php'), app_path('Notifications/LatePaymentNotice.php')];

    foreach ([...glob(app_path('Application/Payment/*.php')) ?: [], ...glob(app_path('Application/Payment/*/*.php')) ?: [], ...glob(app_path('Infrastructure/Payment/*.php')) ?: [], ...$roads] as $file) {
        $class = 'App\\'.str_replace(['/', '.php'], ['\\', ''], substr($file, strlen(app_path().'/')));
        assert(class_exists($class) || interface_exists($class) || enum_exists($class));

        foreach ((new ReflectionClass($class))->getMethods() as $method) {
            $named[] = $class.'::'.$method->getName();
        }
    }

    $routes = array_map(fn (RouteEntry $route): string => $route->uri().' '.($route->getName() ?? ''), Route::getRoutes()->getRoutes());

    expect($named)->toContain(PaymentProvider::class.'::flagPayment', PaymentAdapter::class.'::verify', LatePaymentNotice::class.'::of')
        ->and(array_filter($named, fn (string $name): bool => stripos($name, 'refund') !== false))->toBe([])
        ->and(array_filter($routes, fn (string $route): bool => stripos($route, 'refund') !== false))->toBe([]);
});
