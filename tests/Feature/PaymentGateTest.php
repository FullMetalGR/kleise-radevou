<?php

use App\Application\Payment\ConnectionState;
use App\Application\Payment\TestConnection;
use App\Http\Controllers\Admin\PaymentsController;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\SetTenantLocale;
use App\Infrastructure\ExpireHoldsFactory;
use App\Infrastructure\Payment\LoggedPayments;
use App\Models\User;
use App\Tenancy\Slug;
use App\Tenancy\Tenant;
use App\Tenancy\TenantUrls;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\TestCase as Harness;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FakePaymentAdapter;
use Tests\Support\FixtureDay;
use Tests\TestCase;

pest()->use(DatabaseTruncation::class);

/*
 * Tenant payments as one suite a reader runs by this file's name. Its matrix is the dataset
 * below: each row names the case that holds it, by file and by the case's name as written there,
 * and the first case here proves every cited case still stands under that name. Where the matrix
 * has a hole this file fills it: the degrade over no connection and the three non-working states
 * end to end, the return that decides nothing, the one test holding both secrets and the manage
 * token, and the surface of every payment route.
 *
 * Not wrapped in a transaction: a hold enters the transaction runner, which refuses to open
 * inside another. The pinned clock is the one the hold's start and the manage token's lifetime
 * read; liveness is the database's now(). The fake is the provider every case meets.
 */

beforeEach(function () {
    $this->withoutVite();
    dropDemoSchemas();
    seedDemo();
    FixtureDay::pinClock();
    Http::preventStrayRequests();
    seamTransport()->flush();
});

afterEach(function () {
    Http::assertNothingSent();
    dropDemoSchemas();
    DB::table('users')->delete();
    DB::table('tenants')->delete();
});

/**
 * A Colour hold for Casey on luna at the start given, read back as the booking app reads it.
 *
 * @return array{uuid: string, manage_token: string, checkout_url?: string}
 */
function gateHold(TestCase $test, string $start = FixtureDay::START): array
{
    $ids = fn (string $table, string $name): mixed => DB::table('tenant_luna.'.$table)->where('name', $name)->value('id');
    $held = $test->postJson(tenantUrl('luna', '/api/v1/holds'), [
        'customer_name' => 'Casey Customer',
        'customer_email' => 'casey@example.com',
        'items' => [['service_id' => $ids('services', 'Colour'), 'staff_id' => $ids('staff', 'Elena Papadaki')]],
        'start' => (new DateTimeImmutable($start, new DateTimeZone(FixtureDay::ZONE)))->format(DateTimeImmutable::RFC3339),
    ])->assertCreated()->json();

    assert(is_array($held) && is_string($held['uuid']) && is_string($held['manage_token']));
    $shape = ['uuid' => $held['uuid'], 'manage_token' => $held['manage_token']];

    return isset($held['checkout_url']) && is_string($held['checkout_url']) ? [...$shape, 'checkout_url' => $held['checkout_url']] : $shape;
}

/**
 * The pay hop of a hold, as checkout_url names it.
 *
 * @param  array{uuid: string, manage_token: string}  $held
 */
function gatePay(array $held): string
{
    return tenantUrl('luna', '/pay/'.$held['uuid'].'?'.http_build_query(['token' => $held['manage_token']]));
}

/**
 * @param  array{uuid: string, manage_token: string}  $held
 */
function gateManageLink(array $held): string
{
    return app(TenantUrls::class)->manageLink(Slug::of('luna'), $held['uuid'], $held['manage_token']);
}

/**
 * A settled-payment notice for the booking, posted to luna's own-mode endpoint as Paydesk posts
 * it, sealed with luna's signing phrase unless a seal is given.
 *
 * @param  array<string, mixed>  $extra
 * @return TestResponse<Illuminate\Http\Response>
 */
function gateDeliver(TestCase $test, string $uuid, string $event = 'evt_1', ?string $seal = null, array $extra = []): TestResponse
{
    $body = json_encode(['id' => $event, 'type' => FakePaymentAdapter::SETTLED, 'booking' => $uuid, 'payment' => 'pd_1', ...$extra], JSON_THROW_ON_ERROR);

    return $test->call('POST', centralUrl('/webhooks/'.FakePaymentAdapter::SLUG.'/hook-token-luna'), [], [], [], [
        'HTTP_PAYDESK_SEAL' => $seal ?? FakePaymentAdapter::seal($body, paymentFakeCredentials()['signing_phrase']),
        'CONTENT_TYPE' => 'application/json',
    ], $body);
}

/**
 * The columns a payment may move, read raw.
 *
 * @return array{status: string, payment_ref: mixed, hold_class: mixed, hold_expires_at: mixed, manage_token_sealed: mixed}
 */
function gateRow(string $uuid): array
{
    $row = (array) DB::table('tenant_luna.bookings')->where('uuid', $uuid)->first(['status', 'payment_ref', 'hold_class', 'hold_expires_at', 'manage_token_sealed']);

    return ['status' => is_string($row['status']) ? $row['status'] : '', 'payment_ref' => $row['payment_ref'], 'hold_class' => $row['hold_class'], 'hold_expires_at' => $row['hold_expires_at'], 'manage_token_sealed' => $row['manage_token_sealed']];
}

function gateConfirmations(string $uuid): int
{
    $id = DB::table('tenant_luna.bookings')->where('uuid', $uuid)->value('id');

    return DB::table('tenant_luna.audit_log')->where('booking_id', $id)->where('action', 'confirmed')->count();
}

/**
 * The payment connection of a tenant as the section reads it, the stored check decoded.
 *
 * @return array{state: mixed, account_ref: mixed, last_check_ok: mixed, requirements: mixed}
 */
function gateConnection(string $slug): array
{
    $row = (array) DB::table('tenant_'.$slug.'.payment_connections')->sole(['state', 'account_ref', 'last_check_ok', 'requirements']);

    return ['state' => $row['state'], 'account_ref' => $row['account_ref'], 'last_check_ok' => $row['last_check_ok'], 'requirements' => is_string($row['requirements']) ? json_decode($row['requirements'], true) : null];
}

/**
 * A reported exception as the handler would log it: its class, its message and its trace with
 * every argument whole, down to the frame where the test harness stands in for the web server.
 * The harness's own request call and the frames below it hold the address the test built, the
 * credential in its query and all, and exist in no deployment, whose frames there hold the
 * kernel and a Request object; every frame above is the application's and is searched.
 */
function gateReported(Throwable $report): string
{
    $trace = $report->getTraceAsString();
    $harness = strpos($trace, ' '.Harness::class.'->call(');

    return $report::class.' '.$report->getMessage().' '.($harness === false ? $trace : substr($trace, 0, $harness));
}

/**
 * Every row of every table the gate's tenants and the central schema hold, as text.
 */
function gateAtRest(): string
{
    $tables = DB::select("select table_schema as s, table_name as t from information_schema.tables where table_schema in ('public', 'tenant_luna', 'tenant_pier') and table_type = 'BASE TABLE'");
    $text = [];
    foreach ($tables as $table) {
        $table = (array) $table;
        assert(is_string($table['s']) && is_string($table['t']));
        $rows = DB::scalar(sprintf('select string_agg(r::text, chr(10)) from "%s"."%s" r', $table['s'], $table['t']));
        $text[] = is_string($rows) ? $rows : '';
    }

    return implode("\n", $text);
}

dataset('the matrix as cited', [
    'the paid path, own mode, the customer never returning' => ['tests/Feature/PaymentWebhookTest.php', 'confirms a held online booking from the notification alone: no confirm request, no return, the hold columns null'],
    'the paid path in both modes over the real adapter, the customer never returning' => ['tests/Feature/StripePaymentTest.php', 'runs the paid path end to end with the real adapter in either mode: the hop opens a Stripe session, a signed completion on the mode\'s endpoint confirms the booking, the customer never returns'],
    'the paid path in platform mode, once however often, a bad signature refused' => ['tests/Feature/StripePaymentTest.php', 'confirms a booking paid through a platform connection on the platform endpoint, its tenant found by the account, once however often it arrives, and refuses and records a bad signature'],
    'the browser takes the checkout_url branch to a manage page reading confirmed' => ['tests/Browser/CheckoutJourneyTest.php', 'takes the checkout_url branch from the review step through the pay hop to the manage page, and the notification alone confirms the booking the page then reads'],
    'the degrade: no checkout_url under the null provider' => ['tests/Feature/PayHopTest.php', 'leaves checkout_url off the hold of a free service, a pay-on-site one, an online one under the null provider and an online one awaiting approval'],
    'the degrade: the hop under the null provider settles on site' => ['tests/Feature/PayHopTest.php', 'settles the live hold on site through the shared guarded write when the connection stopped working between the hold and the press, sends the customer to the manage page reading confirmed, told they pay at the venue, with the link in the confirmation, and a second visit finds it confirmed and is told nothing'],
    'the degrade: an approval under no connection or a pending one confirms as before' => ['tests/Feature/ApprovalPaymentTest.php', 'lands an approval confirmed as before where nothing can take the money: an online request under no connection or a pending one, and a pay-on-site request under a working one'],
    'the notification twice' => ['tests/Feature/PaymentWebhookTest.php', 'confirms once for the same notification delivered twice: one confirmation, one audit row, the second answered 200 and handling nothing'],
    'the bad signature, refused, recorded, not processed' => ['tests/Feature/PaymentWebhookTest.php', 'refuses a bad signature with the envelope, records it refused with none of its body, and processes nothing'],
    'the bad signature on the platform endpoint' => ['tests/Feature/Admin/PlatformModeTest.php', 'refuses a bad signature with the envelope and records it, logs and ignores an account no connection holds, and does nothing on a repeat delivery'],
    'the hold follows the session at the hop' => ['tests/Feature/PayHopTest.php', 'sends a live hold on to the provider\'s page, writing the session\'s id to payment_ref and moving the hold\'s expiry to the session\'s end in the same write, with no transaction open while the provider was asked'],
    'the hold ends with a session the provider ends before it' => ['tests/Feature/PayHopTest.php', 'shortens the hold to a session the provider ends before it, since the two end together'],
    'the session expired when the sweep ends a hold unpaid' => ['tests/Feature/PaymentExpiryTest.php', 'expires, after the batch has committed, the session of every hold the sweep ended unpaid, one call each, and asks nothing for a hold that carried none'],
    'the session expired when a reap ends a hold unpaid' => ['tests/Feature/PaymentExpiryTest.php', 'expires the session of a hold the reap ended, after the write that ended it commits'],
    'the session expired when the customer releases a hold' => ['tests/Feature/PaymentExpiryTest.php', 'expires the session of a hold the customer released, after the release commits'],
    'the session expired when a held, unpaid booking is cancelled' => ['tests/Feature/PaymentExpiryTest.php', 'asks the expiry alone on a cancel of a held, unpaid booking carrying a session, whoever cancels and from either payable hold'],
    'the late payment, logged, marked, confirming nothing' => ['tests/Feature/PaymentWebhookTest.php', 'logs as late, marks and confirms nothing for a booking no longer held, whichever road ended its hold'],
    'the late payment with its note and the owner told once' => ['tests/Feature/PaymentFlagTest.php', 'flags a late payment with its own note, tells the owner once, confirms nothing, revives nothing, marks the event, and flags once per event'],
    'the late payment\'s message to the owners alone' => ['tests/Feature/PaymentWebhookTest.php', 'tells the owners alone, once per event, that a late payment bought nothing and that refunding it is theirs to do in their provider\'s dashboard, naming the booking and carrying no secret, whether or not the notice named the payment'],
    'the return carrying what the provider added reads the booking as it stands' => ['tests/Feature/PayHopTest.php', 'brings the customer back to the manage page with the token, which reads the booking as it stands whatever the provider added to the address'],
    'paying is never frozen: the hop under a lapsed subscription' => ['tests/Feature/PayHopTest.php', 'opens the hop while the subscription is lapsed, canceled or incomplete: paying is a guest route and never frozen'],
    'paying is never frozen: the notification under a lapsed subscription' => ['tests/Feature/PaymentWebhookTest.php', 'confirms the booking of a tenant whose subscription is past due, canceled or incomplete'],
]);

it('names every row of the matrix it cites by file and case name, each case still standing there under that name', function (string $file, string $case) {
    $source = file_get_contents(base_path($file));

    expect($source)->toBeString()
        ->and(str_contains((string) $source, "it('".str_replace("'", "\\'", $case)))->toBeTrue();
})->with('the matrix as cited');

it('runs an online service as an onsite one end to end where no connection works: no checkout_url, the booking app confirms one hold, the hop settles another on site, and no provider is asked anything', function (?ConnectionState $state) {
    if ($state !== null) {
        connectPaymentFake($state);
    }

    $confirmed = gateHold($this);
    $hopped = gateHold($this, FixtureDay::DATE.' 14:00');

    expect($confirmed)->not->toHaveKey('checkout_url')
        ->and($hopped)->not->toHaveKey('checkout_url');

    $this->postJson(tenantUrl('luna', '/api/v1/bookings/'.$confirmed['uuid'].'/confirm'), ['token' => $confirmed['manage_token']])->assertOk();
    $this->get(gatePay($hopped))->assertStatus(303)->assertRedirect(gateManageLink($hopped));

    foreach ([$confirmed, $hopped] as $held) {
        expect(gateRow($held['uuid']))->toMatchArray(['status' => 'confirmed', 'payment_ref' => null, 'hold_class' => null, 'hold_expires_at' => null, 'manage_token_sealed' => null])
            ->and($this->getJson(tenantUrl('luna', '/api/v1/bookings/'.$held['uuid'].'?token='.$held['manage_token']))->assertOk()->json('status'))->toBe('confirmed')
            ->and(gateConfirmations($held['uuid']))->toBe(1);
    }

    expect(paymentFake()->asked)->toBe([])
        ->and(paymentFake()->created())->toBe(0)
        ->and(DB::table('payment_events')->count())->toBe(0);
})->with([
    'no connection' => [null],
    'a pending connection' => [ConnectionState::Pending],
    'a restricted connection' => [ConnectionState::Restricted],
    'a disabled connection' => [ConnectionState::Disabled],
]);

it('lets the return decide nothing: a return with forged parameters, a return before the notification and no return at all each leave the booking where the notification puts it', function (string $return) {
    connectPaymentFake();
    $held = gateHold($this);
    $back = $this->get(gatePay($held))->assertStatus(303)->headers->get('Location');
    assert(is_string($back));
    $before = gateRow($held['uuid']);
    $forged = $back.'&'.http_build_query(['paydesk_session' => 'pd_forged', 'status' => 'confirmed', 'paid' => '1', 'redirect_status' => 'succeeded']);

    match ($return) {
        'forged parameters' => $this->get($forged)->assertOk(),
        'an early return' => $this->get($back)->assertOk(),
        default => null,
    };

    expect(gateRow($held['uuid']))->toBe($before)
        ->and($before)->toMatchArray(['status' => 'pending', 'payment_ref' => 'pd_1'])
        ->and(gateConfirmations($held['uuid']))->toBe(0);

    gateDeliver($this, $held['uuid'])->assertOk();

    if ($return !== 'no return') {
        $this->get($return === 'forged parameters' ? $forged : $back)->assertOk();
    }

    expect(gateRow($held['uuid']))->toMatchArray(['status' => 'confirmed', 'payment_ref' => 'pd_1', 'hold_class' => null, 'hold_expires_at' => null, 'manage_token_sealed' => null])
        ->and(gateConfirmations($held['uuid']))->toBe(1);
})->with(['forged parameters', 'an early return', 'no return']);

it('carries neither the tenant\'s secret nor the platform\'s, nor the manage token once the confirmation is sent or the hold has lapsed, in any log record, exception, answer, prop, flash, queue payload or row at rest', function () {
    // The engine renders every argument of every frame whole, so the traces searched below are
    // not cut to the fifteen characters of the default, under which no secret here could match:
    // both settings pinned and restored as tests/Unit/MessageNotSentTest.php pins them.
    $wasIgnoringArgs = ini_get('zend.exception_ignore_args');
    $wasTruncatingAt = ini_get('zend.exception_string_param_max_len');
    ini_set('zend.exception_ignore_args', '0');
    ini_set('zend.exception_string_param_max_len', '1000');

    try {
        config(['payments.platform_fee' => ['percent' => '1.5'], 'payments.platform.'.FakePaymentAdapter::SLUG => FakePaymentAdapter::PLATFORM_KEYS]);
        Exceptions::fake();
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $message) use (&$logged): void {
            $logged[] = $message->message.' '.json_encode($message->context);
        });
        $queued = [];
        Event::listen(JobProcessing::class, function (JobProcessing $event) use (&$queued): void {
            $queued[] = $event->job->getRawBody();
        });
        $tenantSecrets = [paymentFakeCredentials()['api_token'], paymentFakeCredentials()['signing_phrase']];
        $platformSecrets = array_values(FakePaymentAdapter::PLATFORM_KEYS);
        $echo = 'Paydesk said '.implode(' and ', [...$tenantSecrets, ...$platformSecrets]);
        $answers = [];
        $flashes = [];
        $settings = fn (string $slug): string => tenantUrl($slug, '/admin/settings');
        $write = function (string $slug, string $path, array $body = []) use (&$answers, &$flashes, $settings): TestResponse {
            $owner = User::query()->where('email', 'owner@'.$slug.'.example')->sole();
            $answer = $this->actingAs($owner)->from($settings($slug))
                ->json('POST', tenantUrl($slug, '/admin/settings/payments'.$path), $body, ['X-Inertia' => 'true']);
            $answers[] = $answer->baseResponse;
            $flashes[] = (string) json_encode(app('session.store')->all());

            return $answer;
        };

        // The save, a test call the provider refuses echoing what it was sent, and one that
        // throws saying the same. Each answers the redirect back with its flash, which says only
        // whether the call passed; the failed check lands on the row with its reason, struck of
        // every value the connection declares, which are all an own-mode check is lent, or
        // naming the class thrown alone, and the throw is logged once at warning, its message
        // struck the same way.
        $write('luna', '', ['provider' => FakePaymentAdapter::SLUG, 'credentials' => paymentFakeCredentials()])
            ->assertRedirect($settings('luna'))->assertSessionHas('flash', ['kind' => 'success', 'message' => adminLine(PaymentsController::SAVED)]);
        paymentFake()->checkFailsWith = 'Paydesk said '.implode(' and ', $tenantSecrets);
        $write('luna', '/test')->assertRedirect($settings('luna'))->assertSessionHas('flash', ['kind' => 'error', 'message' => adminLine(PaymentsController::TEST_FAILED)]);
        paymentFake()->checkFailsWith = null;
        expect(gateConnection('luna'))->toMatchArray(['state' => 'restricted', 'last_check_ok' => false, 'requirements' => ['reason' => 'Paydesk said [secret] and [secret]']]);
        paymentFake()->checkThrows = new RuntimeException('Paydesk said '.implode(' and ', paymentFakeCredentials()));
        $write('luna', '/test')->assertRedirect($settings('luna'))->assertSessionHas('flash', ['kind' => 'error', 'message' => adminLine(PaymentsController::TEST_FAILED)]);
        paymentFake()->checkThrows = null;
        // The class's line beside its key and value, in any key order: the English is the en
        // line filled.
        $threw = ['class' => RuntimeException::class];
        expect(gateConnection('luna'))->toMatchArray(['state' => 'restricted', 'last_check_ok' => false])
            ->and(gateConnection('luna')['requirements'])->toEqual(['reason' => adminLine(TestConnection::REASON_UNEXPECTED, $threw), 'key' => TestConnection::REASON_UNEXPECTED, 'values' => $threw])
            ->and(array_values(array_filter($logged, fn (string $line): bool => str_starts_with($line, LoggedPayments::CHECK_THREW))))
            ->toBe([LoggedPayments::CHECK_THREW.' '.json_encode(['provider' => FakePaymentAdapter::SLUG, 'exception' => RuntimeException::class, 'message' => 'Paydesk said [secret] and [secret] and [secret]'])]);

        // A checkout the provider fails echoing the secrets and the return address, then the retry.
        connectPaymentFake();
        $paid = gateHold($this);
        paymentFake()->failWith = $echo.' returning to '.gateManageLink($paid);
        $customer = [$this->get(gatePay($paid))->assertStatus(502)->baseResponse];
        paymentFake()->failWith = null;
        $customer[] = $this->get(gatePay($paid))->assertStatus(303)->baseResponse;

        // A notice refused for its seal, carrying the secrets in its body, then the one that
        // confirms the booking and sends the confirmation: the token's third moment.
        $answers[] = gateDeliver($this, $paid['uuid'], 'evt_forged', 'forged', ['note' => $echo])->assertStatus(400)->baseResponse;
        $answers[] = gateDeliver($this, $paid['uuid'], 'evt_paid')->assertOk()->baseResponse;

        // Platform mode on pier: the connect, which opens the account, writes it pending and
        // sends the owner to the hosted onboarding; then a notice refused for its seal and one
        // verified, which moves the row to active.
        $write('pier', '/platform', ['provider' => FakePaymentAdapter::SLUG])->assertStatus(409)->assertHeader('X-Inertia-Location', 'https://onboarding.paydesk.test/pdk_acct_1/1');
        expect(paymentFake()->accounts)->toBe(['pdk_acct_1'])
            ->and(gateConnection('pier'))->toMatchArray(['state' => 'pending', 'account_ref' => 'pdk_acct_1', 'last_check_ok' => null]);
        $standing = fn (string $event): string => (string) json_encode(['id' => $event, 'type' => FakePaymentAdapter::ACCOUNT, 'account' => 'pdk_acct_1', 'standing' => 'taking', 'outstanding' => [], 'note' => $echo]);
        foreach (['forged', null] as $seal) {
            $body = $standing('evt_standing_'.($seal ?? 'sealed'));
            $answers[] = $this->call('POST', centralUrl('/webhooks/'.FakePaymentAdapter::SLUG), [], [], [], [
                'HTTP_PAYDESK_SEAL' => $seal ?? FakePaymentAdapter::seal($body, FakePaymentAdapter::PLATFORM_KEYS['signing_phrase']),
                'CONTENT_TYPE' => 'application/json',
            ], $body)->assertStatus($seal === null ? 200 : 400)->baseResponse;
        }
        expect(gateConnection('pier'))->toMatchArray(['state' => 'active', 'last_check_ok' => true, 'requirements' => null]);

        // A queued job and an audit row: the customer cancels the paid booking, which flags its
        // payment; and a hold carrying a sealed token lapses and is swept, which expires its
        // session.
        $customer[] = $this->postJson(tenantUrl('luna', '/api/v1/bookings/'.$paid['uuid'].'/cancel'), ['token' => $paid['manage_token']])->assertSuccessful()->baseResponse;
        $lapsed = gateHold($this, FixtureDay::DATE.' 14:00');
        $customer[] = $this->get(gatePay($lapsed))->assertStatus(303)->baseResponse;
        expect(gateRow($lapsed['uuid'])['manage_token_sealed'])->toBeString();
        DB::table('tenant_luna.bookings')->where('uuid', $lapsed['uuid'])->update(['hold_expires_at' => DB::raw("now() - interval '1 second'")]);
        app(ExpireHoldsFactory::class)->for(Tenant::query()->where('slug', 'luna')->sole())->sweep();

        // The pages' props: the Settings screen of each tenant as its owner opens it.
        $props = [];
        foreach (['luna', 'pier'] as $slug) {
            $owner = User::query()->where('email', 'owner@'.$slug.'.example')->sole();
            $props[] = (string) json_encode($this->actingAs($owner)->get($settings($slug))->assertOk()->viewData('page'));
        }

        $reported = array_map(fn (mixed $report): string => $report instanceof Throwable ? gateReported($report) : '', Exceptions::reported());
        $rest = gateAtRest();
        $kept = implode("\n", [...$logged, ...$reported, ...$queued, ...$props, ...$flashes, $rest,
            ...array_map(fn (Response $answer): string => $answer->headers.$answer->getContent(), $answers)]);
        // The customer's answers and the mail carry the manage token by design, so they are
        // searched for the secrets alone.
        $sent = implode("\n", [...array_map(fn (Response $answer): string => $answer->headers.$answer->getContent(), $customer),
            ...array_map(fn ($message): string => $message->toString(), seamSent())]);

        foreach ([...$tenantSecrets, ...$platformSecrets] as $secret) {
            expect($kept)->not->toContain($secret)
                ->and($sent)->not->toContain($secret);
        }
        foreach ([$paid, $lapsed] as $held) {
            expect($kept)->not->toContain($held['manage_token'])
                ->and(gateRow($held['uuid'])['manage_token_sealed'])->toBeNull();
        }

        // The search is not blind: each road ran and left its trace where the search looked,
        // the admin writes and the notices beside their calls above, the rest here.
        expect(gateRow($paid['uuid'])['status'])->toBe('cancelled')
            ->and(gateRow($lapsed['uuid'])['status'])->toBe('expired')
            ->and(implode("\n", $reported))->toContain(Kernel::class.'->handle(Object(')->not->toContain(Harness::class.'->call(')
            ->and($queued)->not->toBe([])
            ->and($logged)->not->toBe([])
            ->and(DB::table('payment_events')->whereNotNull('refusal')->count())->toBe(2)
            ->and(DB::table('tenant_luna.audit_log')->count())->toBeGreaterThan(0)
            ->and(paymentFake()->expired)->toBe(['pd_2'])
            ->and(collect(paymentFake()->asked)->pluck(0)->all())->toContain('flagPayment')
            ->and($sent)->toContain($paid['manage_token']);
    } finally {
        ini_set('zend.exception_ignore_args', is_string($wasIgnoringArgs) ? $wasIgnoringArgs : '0');
        ini_set('zend.exception_string_param_max_len', is_string($wasTruncatingAt) ? $wasTruncatingAt : '15');
    }
});

it('pins every payment route with its one access, its subscription gate and its limit: the configuration writes gated, the customer\'s routes and the two notification endpoints never, and the two live provider calls alone under a limit, ahead of the permission', function () {
    $declared = collect([
        'pay', 'pay.link', 'pay.link.act', 'webhooks.payments', 'webhooks.platform',
        'admin.settings.payments.store', 'admin.settings.payments.platform', 'admin.settings.payments.platform.resume', 'admin.settings.payments.test',
        'admin.settings.payments.rotate', 'admin.settings.payments.disable', 'admin.settings.payments.enable', 'admin.settings.payments.destroy',
    ])->mapWithKeys(function (string $name): array {
        $route = Route::getRoutes()->getByName($name);
        assert($route !== null);
        $declared = array_filter($route->gatherMiddleware(), fn (mixed $middleware): bool => is_string($middleware) && ! in_array($middleware, ['web', ResolveTenant::class, SetTenantLocale::class, HandleInertiaRequests::class], true));

        $excluded = array_filter($route->excludedMiddleware(), is_string(...));

        return [$name => [implode('|', array_filter($route->methods(), is_string(...))), array_values(array_diff($declared, $excluded))]];
    })->all();

    $configures = ['permission:manageSettings', 'subscription'];
    // Save and test and Test connection each ask the provider, so the two share a limit of their
    // own in the billing writes' shape, set ahead of the permission.
    $checks = ['throttle:payment-checks', ...$configures];

    expect($declared)->toBe([
        'pay' => ['GET|HEAD', ['manage-token']],
        'pay.link' => ['GET|HEAD', ['pay-link']],
        'pay.link.act' => ['POST', ['pay-link']],
        'webhooks.payments' => ['POST', ['payment-signature']],
        'webhooks.platform' => ['POST', ['platform-signature']],
        'admin.settings.payments.store' => ['POST', $checks],
        'admin.settings.payments.platform' => ['POST', $configures],
        'admin.settings.payments.platform.resume' => ['POST', $configures],
        'admin.settings.payments.test' => ['POST', $checks],
        'admin.settings.payments.rotate' => ['POST', $configures],
        'admin.settings.payments.disable' => ['POST', $configures],
        'admin.settings.payments.enable' => ['POST', $configures],
        'admin.settings.payments.destroy' => ['DELETE', $configures],
    ]);
});
