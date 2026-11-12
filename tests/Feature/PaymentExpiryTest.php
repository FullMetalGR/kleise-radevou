<?php

use App\Application\Payment\CheckoutNotExpired;
use App\Application\Payment\ConnectionState;
use App\Application\Payment\PaymentNote;
use App\Application\Payment\ProviderRegistry;
use App\Infrastructure\ExpireHoldsFactory;
use App\Jobs\ExpireCheckout;
use App\Models\User;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakePaymentAdapter;
use Tests\Support\FixtureDay;
use Tests\TestCase;

pest()->use(DatabaseTruncation::class);

/*
 * A session that outlives its hold is expired: a hold that ends unpaid carrying a session, by
 * the sweeper's lapse, the reap's, a release, a cancel or an on-site settlement at the hop, has
 * that session expired after the commit, where the descriptor declares canExpireCheckout. luna's
 * Colour is an online service and Haircut a pay-on-site one; the fake is connected and active. A
 * descriptor that leaves the capability undeclared is no provider: the registry refuses it at
 * registration, naming the field, so no road reaches a provider with one (PaymentPortTest and
 * PaymentFlagTest pin the refusal).
 *
 * Not wrapped in a transaction: the hold, the sweep and every transition enter the transaction
 * runner, which refuses to open inside another, and the fake records whether a transaction was
 * open when it was asked, which only means something here. No transaction open is no staff-day
 * lock held, since every such lock is a row lock taken inside one.
 */

beforeEach(function () {
    dropDemoSchemas();
    seedDemo();
    FixtureDay::pinClock();
    Http::preventStrayRequests();
    settingsOf('luna')->update(['reminder_lead_hours' => 0]);
    connectPaymentFake();
    Exceptions::fake();
});

afterEach(function () {
    Http::assertNothingSent();
    dropDemoSchemas();
    DB::table('users')->delete();
    DB::table('tenants')->delete();
});

/**
 * A hold for Casey with Elena, the fixture's start moved on by the hours given so two holds
 * stand side by side, visited at the pay hop where asked, which records the fake's next session.
 *
 * @return array{uuid: string, manage_token: string}
 */
function expiryHold(TestCase $test, int $later = 0, bool $hop = true, string $service = 'Colour'): array
{
    $ids = fn (string $table, string $name): mixed => DB::table('tenant_luna.'.$table)->where('name', $name)->value('id');
    $start = FixtureDay::start()->add(new DateInterval(sprintf('PT%dH', $later)));
    $held = $test->postJson(tenantUrl('luna', '/api/v1/holds'), [
        'customer_name' => 'Casey Customer',
        'customer_email' => 'casey@example.com',
        'items' => [['service_id' => $ids('services', $service), 'staff_id' => $ids('staff', 'Elena Papadaki')]],
        'start' => $start->format(DateTimeImmutable::RFC3339),
    ])->assertCreated()->json();

    assert(is_array($held) && is_string($held['uuid']) && is_string($held['manage_token']));
    $held = ['uuid' => $held['uuid'], 'manage_token' => $held['manage_token']];

    if ($hop) {
        expiryHop($test, $held)->assertStatus(303);
    }

    return $held;
}

/**
 * @param  array{uuid: string, manage_token: string}  $held
 * @return TestResponse<Response>
 */
function expiryHop(TestCase $test, array $held): TestResponse
{
    return $test->get(tenantUrl('luna', '/pay/'.$held['uuid'].'?'.http_build_query(['token' => $held['manage_token']])));
}

/**
 * The hold's expiry moved a second into the past, by the database's clock, as the sweeper reads it.
 *
 * @param  array{uuid: string}  $held
 */
function expiryLapse(array $held): void
{
    DB::table('tenant_luna.bookings')->where('uuid', $held['uuid'])->update(['hold_expires_at' => DB::raw("now() - interval '1 second'")]);
}

function expirySweep(): void
{
    app(ExpireHoldsFactory::class)->for(Tenant::query()->where('slug', 'luna')->sole())->sweep();
}

/**
 * The booking cancelled by the customer through their link, or by luna's owner from the admin.
 *
 * @param  array{uuid: string, manage_token: string}  $held
 */
function expiryCancel(TestCase $test, array $held, string $by): void
{
    if ($by === 'customer') {
        $test->postJson(tenantUrl('luna', '/api/v1/bookings/'.$held['uuid'].'/cancel'), ['token' => $held['manage_token']])->assertSuccessful();

        return;
    }

    $test->actingAs(User::query()->where('email', 'owner@luna.example')->sole())
        ->from(tenantUrl('luna', '/admin/bookings/'.$held['uuid']))
        ->post(tenantUrl('luna', '/admin/bookings/'.$held['uuid'].'/cancel'))
        ->assertRedirect(tenantUrl('luna', '/admin/bookings/'.$held['uuid']))
        ->assertSessionHasNoErrors();
}

/**
 * The fake's notification that the payment named moved for the booking, as Paydesk posts it.
 *
 * @param  array{uuid: string}  $held
 */
function expiryPaid(TestCase $test, array $held, string $payment, string $event): void
{
    $body = json_encode(['id' => $event, 'type' => FakePaymentAdapter::SETTLED, 'booking' => $held['uuid'], 'payment' => $payment], JSON_THROW_ON_ERROR);

    $test->call('POST', centralUrl('/webhooks/'.FakePaymentAdapter::SLUG.'/hook-token-luna'), [], [], [], [
        'HTTP_PAYDESK_SEAL' => FakePaymentAdapter::seal($body, paymentFakeCredentials()['signing_phrase']),
        'CONTENT_TYPE' => 'application/json',
    ], $body)->assertOk();
}

/**
 * The customer moving the booking through their link to the fixture's start moved on by the
 * hours given, as the reschedule dialog sends it.
 *
 * @param  array{uuid: string, manage_token: string}  $held
 */
function expiryMove(TestCase $test, array $held, int $later = 5): void
{
    $test->postJson(tenantUrl('luna', '/api/v1/bookings/'.$held['uuid'].'/reschedule'), [
        'token' => $held['manage_token'],
        'start' => FixtureDay::start()->add(new DateInterval(sprintf('PT%dH', $later)))->format(DateTimeImmutable::RFC3339),
    ])->assertOk();
}

/**
 * Every expiry the fake was asked, as [booking, session, whether a transaction was open], the
 * probe paired with its question by walking the record in the order both were written.
 *
 * @return list<array{string, string, bool|null}>
 */
function expiriesAsked(FakePaymentAdapter $fake): array
{
    $probed = 0;
    $asked = [];

    foreach ($fake->asked as $question) {
        if (! in_array($question[0], ['checkoutUrl', 'flagPayment', 'expireCheckout'], true)) {
            continue;
        }

        $open = $fake->transactionOpen[$probed] ?? null;
        $probed++;

        if ($question[0] === 'expireCheckout') {
            $asked[] = [$question[1], $question[2] ?? '', $open];
        }
    }

    return $asked;
}

/**
 * @return list<list<string>>
 */
function expiryFlagsAsked(FakePaymentAdapter $fake): array
{
    return array_values(array_filter($fake->asked, fn (array $question): bool => $question[0] === 'flagPayment'));
}

/**
 * @param  array{uuid: string}  $held
 * @return list<mixed>
 */
function expiryRow(array $held): array
{
    $row = DB::table('tenant_luna.bookings')->where('uuid', $held['uuid'])->first(['status', 'payment_ref']);
    assert($row instanceof stdClass);

    return [$row->status, $row->payment_ref];
}

/**
 * The hold's end as the row holds it, read with its offset.
 *
 * @param  array{uuid: string}  $held
 */
function expiryHoldEnd(array $held): DateTimeImmutable
{
    $row = DB::table('tenant_luna.bookings')->where('uuid', $held['uuid'])->first(['hold_expires_at']);
    assert($row instanceof stdClass && is_string($row->hold_expires_at));

    return (new DateTimeImmutable($row->hold_expires_at, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));
}

it('expires, after the batch has committed, the session of every hold the sweep ended unpaid, one call each, and asks nothing for a hold that carried none', function () {
    $fake = paymentFake();
    $first = expiryHold($this);
    $second = expiryHold($this, 4);
    $none = expiryHold($this, 7, false, 'Haircut');
    expiryLapse($first);
    expiryLapse($second);
    expiryLapse($none);

    expirySweep();

    expect(expiryRow($first))->toBe(['expired', 'pd_1'])
        ->and(expiryRow($second))->toBe(['expired', 'pd_2'])
        ->and(expiryRow($none))->toBe(['expired', null])
        ->and(expiriesAsked($fake))->toEqualCanonicalizing([[$first['uuid'], 'pd_1', false], [$second['uuid'], 'pd_2', false]])
        ->and($fake->expired)->toEqualCanonicalizing(['pd_1', 'pd_2'])
        ->and(expiryFlagsAsked($fake))->toBe([]);
    Exceptions::assertNothingReported();

    // A second sweep finds nothing to end and asks nothing again.
    expirySweep();

    expect(expiriesAsked($fake))->toHaveCount(2);
});

it('reports a provider that throws at an expiry once per booking, carrying no secret, leaves every ended hold standing and goes on to the next booking', function () {
    $fake = paymentFake();
    $first = expiryHold($this);
    $second = expiryHold($this, 4);
    expiryLapse($first);
    expiryLapse($second);
    $secrets = paymentFakeCredentials();
    $fake->expireFailsWith = 'Paydesk refused token '.$secrets['api_token'].' signed with '.$secrets['signing_phrase'];

    expirySweep();

    expect(expiryRow($first))->toBe(['expired', 'pd_1'])
        ->and(expiryRow($second))->toBe(['expired', 'pd_2'])
        ->and(expiriesAsked($fake))->toEqualCanonicalizing([[$first['uuid'], 'pd_1', false], [$second['uuid'], 'pd_2', false]])
        ->and($fake->expired)->toBe([])
        ->and(DB::table('failed_jobs')->count())->toBe(0);
    Exceptions::assertReportedCount(2);

    foreach ([$first, $second] as $held) {
        Exceptions::assertReported(function (CheckoutNotExpired $report) use ($held, $secrets): bool {
            $said = $report->getMessage();

            return str_contains($said, $held['uuid'])
                && str_contains($said, RuntimeException::class)
                && $report->getPrevious() === null
                && array_filter($secrets, fn (string $secret): bool => str_contains($said, $secret)) === []
                && ! str_contains($said, 'Paydesk refused');
        });
    }
});

it('expires the session of a hold the reap ended, after the write that ended it commits', function () {
    $fake = paymentFake();
    $lapsed = expiryHold($this);
    expiryLapse($lapsed);

    // A second hold on the same slot reaps the first inside its own transaction.
    $taker = expiryHold($this, 0, false, 'Haircut');

    expect(expiryRow($lapsed))->toBe(['expired', 'pd_1'])
        ->and(expiryRow($taker)[0])->toBe('pending')
        ->and(expiriesAsked($fake))->toBe([[$lapsed['uuid'], 'pd_1', false]]);
    Exceptions::assertNothingReported();
});

it('expires the session of a hold the customer released, after the release commits', function () {
    $fake = paymentFake();
    $held = expiryHold($this);

    $this->deleteJson(tenantUrl('luna', '/api/v1/holds/'.$held['uuid'].'?token='.$held['manage_token']))->assertSuccessful();

    expect(expiryRow($held))->toBe(['cancelled', 'pd_1'])
        ->and(expiriesAsked($fake))->toBe([[$held['uuid'], 'pd_1', false]])
        ->and(expiryFlagsAsked($fake))->toBe([]);
    Exceptions::assertNothingReported();
});

it('asks the expiry alone on a cancel of a held, unpaid booking carrying a session, whoever cancels and from either payable hold', function (string $by, string $status) {
    $fake = paymentFake();
    $held = expiryHold($this);

    if ($status === 'awaiting_payment') {
        DB::table('tenant_luna.bookings')->where('uuid', $held['uuid'])->update(['status' => 'awaiting_payment', 'hold_class' => 'payment']);
    }

    expiryCancel($this, $held, $by);

    expect(expiryRow($held))->toBe(['cancelled', 'pd_1'])
        ->and(expiriesAsked($fake))->toBe([[$held['uuid'], 'pd_1', false]])
        ->and(array_values(array_filter($fake->asked, fn (array $question): bool => $question[0] !== 'checkoutUrl')))->toBe([['expireCheckout', $held['uuid'], 'pd_1']]);
    Exceptions::assertNothingReported();
})->with(['customer', 'admin'])->with(['pending', 'awaiting_payment']);

it('expires the session an earlier press opened when the hop settles the hold on site, after the settlement commits', function () {
    $fake = paymentFake();
    $held = expiryHold($this);
    $fake->unavailable = true;

    expiryHop($this, $held)->assertStatus(303);

    expect(expiryRow($held))->toBe(['confirmed', 'pd_1'])
        ->and(expiriesAsked($fake))->toBe([[$held['uuid'], 'pd_1', false]])
        ->and(expiryFlagsAsked($fake))->toBe([]);
    Exceptions::assertNothingReported();
});

it('cannot reach the provider on an on-site settlement whose connection no longer works, since the tenant\'s provider is then the null one, and the session ends at its own end', function () {
    $fake = paymentFake();
    $held = expiryHold($this);
    connectPaymentFake(ConnectionState::Disabled);

    expiryHop($this, $held)->assertStatus(303);

    expect(expiryRow($held))->toBe(['confirmed', 'pd_1'])
        ->and(expiriesAsked($fake))->toBe([]);
    Exceptions::assertNothingReported();
});

it('never asks to expire anything for a booking a payment landed for, at its confirmation or at its cancel, which is flagged alone', function () {
    $fake = paymentFake();
    $held = expiryHold($this);
    expiryPaid($this, $held, 'pd_pay', 'evt_pay');

    expect(expiryRow($held))->toBe(['confirmed', 'pd_pay'])
        ->and(expiriesAsked($fake))->toBe([]);

    expiryCancel($this, $held, 'customer');

    expect(expiryRow($held)[0])->toBe('cancelled')
        ->and(expiriesAsked($fake))->toBe([])
        ->and(array_map(fn (array $question): string => $question[2] ?? '', expiryFlagsAsked($fake)))->toBe(['pd_pay']);
    Exceptions::assertNothingReported();
});

it('expires, after the move commits, the session a booking still waiting for its payment carried, and clears payment_ref so the next pay hop opens a session of its own for the same total, which a move leaves as the hold fixed it; the hold stands', function (string $status) {
    $fake = paymentFake();
    $held = expiryHold($this);

    if ($status === 'awaiting_payment') {
        DB::table('tenant_luna.bookings')->where('uuid', $held['uuid'])->update(['status' => 'awaiting_payment', 'hold_class' => 'payment']);
    }

    $before = DB::table('tenant_luna.bookings')->where('uuid', $held['uuid'])->first(['hold_class', 'hold_expires_at']);
    expiryMove($this, $held);
    $moved = DB::table('tenant_luna.bookings')->where('uuid', $held['uuid'])->first(['hold_class', 'hold_expires_at', 'total_minor']);

    assert($before instanceof stdClass && $moved instanceof stdClass);

    expect(expiryRow($held))->toBe([$status, null])
        ->and([$moved->hold_class, $moved->hold_expires_at])->toBe([$before->hold_class, $before->hold_expires_at])
        ->and(expiriesAsked($fake))->toBe([[$held['uuid'], 'pd_1', false]])
        ->and($fake->expired)->toBe(['pd_1'])
        ->and(expiryFlagsAsked($fake))->toBe([]);

    expiryHop($this, $held)->assertStatus(303);

    expect(expiryRow($held))->toBe([$status, 'pd_2'])
        ->and($fake->sessions['pd_2']['total']->minor ?? null)->toBe($moved->total_minor)
        ->and($fake->sessions['pd_2']['total'] ?? null)->toEqual($fake->sessions['pd_1']['total'] ?? null)
        ->and(expiriesAsked($fake))->toHaveCount(1);
    Exceptions::assertNothingReported();
})->with(['pending', 'awaiting_payment']);

it('lengthens a hold past its term by the provider\'s floor once: hop, move, hop, move, hop leave a pending checkout hold at its first session\'s end, every later session ending with the hold as it stands and keyed by the sessions the moves cleared, the first two expired', function () {
    $fake = paymentFake();
    $held = expiryHold($this, 0, false);
    $term = expiryHoldEnd($held);

    expiryHop($this, $held)->assertStatus(303);
    $first = expiryHoldEnd($held);
    expiryMove($this, $held);
    $moved = expiryHoldEnd($held);
    expiryHop($this, $held)->assertStatus(303);
    $second = expiryHoldEnd($held);
    expiryMove($this, $held, 4);
    $movedAgain = expiryHoldEnd($held);
    expiryHop($this, $held)->assertStatus(303);
    $third = expiryHoldEnd($held);
    $cleared = DB::table('tenant_luna.audit_log')->where('action', 'rescheduled')->whereRaw("payload->>'session_cleared' = 'true'")->count();

    expect($first->getTimestamp() - $term->getTimestamp())->toBe($fake->sessionOverrunSeconds)
        ->and([$moved, $second, $movedAgain, $third])->each->toEqual($first)
        ->and(expiryRow($held))->toBe(['pending', 'pd_3'])
        ->and($fake->expired)->toBe(['pd_1', 'pd_2'])
        ->and($cleared)->toBe(2)
        ->and(array_map(fn (array $session): array => [$session['askedEnd']->getTimestamp(), $session['endsAt']->getTimestamp(), $session['sessionsCleared']], $fake->sessions))
        ->toBe([
            'pd_1' => [$term->getTimestamp(), $first->getTimestamp(), 0],
            'pd_2' => [$first->getTimestamp(), $first->getTimestamp(), 1],
            'pd_3' => [$first->getTimestamp(), $first->getTimestamp(), 2],
        ]);
    Exceptions::assertNothingReported();
});

it('asks nothing of the provider on a move of a booking carrying no session or of one its payment confirmed, whose payment stays named', function (bool $paid) {
    $fake = paymentFake();
    $held = expiryHold($this, 0, $paid);

    if ($paid) {
        expiryPaid($this, $held, 'pd_pay', 'evt_pay');
    }

    expiryMove($this, $held);

    expect(expiryRow($held))->toBe($paid ? ['confirmed', 'pd_pay'] : ['pending', null])
        ->and(expiriesAsked($fake))->toBe([])
        ->and(expiryFlagsAsked($fake))->toBe([]);
    Exceptions::assertNothingReported();
})->with(['no session' => [false], 'paid' => [true]]);

it('reports a provider that throws at a move\'s expiry once, carrying no secret, and the move stands', function () {
    $fake = paymentFake();
    $held = expiryHold($this);
    $fake->expireFailsWith = 'Paydesk refused token '.paymentFakeCredentials()['api_token'];

    expiryMove($this, $held);

    expect(expiryRow($held))->toBe(['pending', null])
        ->and(expiriesAsked($fake))->toBe([[$held['uuid'], 'pd_1', false]])
        ->and(DB::table('failed_jobs')->count())->toBe(0);
    Exceptions::assertReportedCount(1);
    Exceptions::assertReported(fn (CheckoutNotExpired $report): bool => str_contains($report->getMessage(), $held['uuid']) && ! str_contains($report->getMessage(), paymentFakeCredentials()['api_token']));
});

it('does not ask a provider whose descriptor declares that it cannot expire a session, on any road', function (string $road) {
    $fake = paymentFake();
    $held = expiryHold($this);
    $fake->canExpireCheckout = false;
    app()->forgetInstance(ProviderRegistry::class);

    match ($road) {
        'swept' => (function () use ($held): void {
            expiryLapse($held);
            expirySweep();
        })(),
        'released' => $this->deleteJson(tenantUrl('luna', '/api/v1/holds/'.$held['uuid'].'?token='.$held['manage_token']))->assertSuccessful(),
        'settled on site at the hop' => (function () use ($held, $fake): void {
            $fake->unavailable = true;
            expiryHop($this, $held)->assertStatus(303);
        })(),
        'moved' => expiryMove($this, $held),
        default => expiryCancel($this, $held, 'customer'),
    };

    // A move clears the session whether or not the provider can expire it, so the next pay hop
    // opens one of its own; every other road leaves the reference as recorded.
    expect(expiryRow($held)[1])->toBe($road === 'moved' ? null : 'pd_1')
        ->and(expiriesAsked($fake))->toBe([]);
    Exceptions::assertNothingReported();
})->with(['swept', 'released', 'cancelled', 'settled on site at the hop', 'moved']);

it('queues no expiry for a confirmation that is neither a payment\'s nor the hop\'s settlement, here of an on-site hold carrying no session', function () {
    Queue::fake([ExpireCheckout::class]);
    $held = expiryHold($this, 0, false, 'Haircut');

    $this->postJson(tenantUrl('luna', '/api/v1/bookings/'.$held['uuid'].'/confirm'), ['token' => $held['manage_token']])->assertSuccessful();

    expect(expiryRow($held))->toBe(['confirmed', null]);
    Queue::assertNotPushed(ExpireCheckout::class);
});

it('leaves to its own end the session an earlier press opened when the booking app\'s confirm settles the hold unpaid while the connection works and its provider answers unavailable, and a payment landing at it is late', function () {
    $fake = paymentFake();
    $held = expiryHold($this);
    $fake->unavailable = true;

    $this->postJson(tenantUrl('luna', '/api/v1/bookings/'.$held['uuid'].'/confirm'), ['token' => $held['manage_token']])->assertSuccessful();

    expect(expiryRow($held))->toBe(['confirmed', 'pd_1'])
        ->and(expiriesAsked($fake))->toBe([]);

    expiryPaid($this, $held, 'pd_late', 'evt_late');

    expect(expiryRow($held))->toBe(['confirmed', 'pd_1'])
        ->and(expiriesAsked($fake))->toBe([])
        ->and(expiryFlagsAsked($fake))->toBe([['flagPayment', $held['uuid'], 'pd_late', PaymentNote::late($held['uuid'])]]);
    Exceptions::assertNothingReported();
});

it('cannot reach a session no row records: the hop that wrote nothing because the hold lapsed while the provider was being asked', function () {
    $fake = paymentFake();
    $held = expiryHold($this, 0, false);
    $fake->whileAsked = fn () => expiryLapse($held);

    expiryHop($this, $held)->assertStatus(409);
    expirySweep();

    expect($fake->created())->toBe(1)
        ->and(expiryRow($held))->toBe(['expired', null])
        ->and(expiriesAsked($fake))->toBe([]);
    Exceptions::assertNothingReported();
});
