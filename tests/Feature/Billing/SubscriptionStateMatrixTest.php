<?php

use App\Application\Clock;
use App\Billing\ResubscribeRefused;
use App\Billing\SubscriptionSync;
use App\Domain\Booking\ManageToken;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FakeBillingProvider;
use Tests\Support\FixtureDay;
use Tests\Support\LunaFixture;

pest()->use(DatabaseTruncation::class);

/*
 * Two axes as one table. Each of the six subscription states, with the staff feature stored on
 * the tenant row and without it, meets the five kinds of request a lapse is decided over: a
 * configuration write (a tenant-wide closure, which the floor has), a lifecycle action of the
 * admin (a cancel), a public route (a guest's cancel through the manage token, with no admin
 * session), an admin read (the calendar) and the billing routes (the portal, and the Billing
 * screen's checkout). The state refuses configuration writes with the gate's envelope and
 * nothing else; the checkout, which the gate never stands in front of either, opens a session in
 * the three states it serves and declines with its own refusal in the others. The feature
 * reaches the screens, through the entitlements every page is handed, exactly while the state is
 * active, grace or trial and the row stores it.
 *
 * The answers are spelled row by row rather than derived from isActive(), so the table is the
 * decision and a state whose answer moves fails here until the table is amended on purpose.
 *
 * The two cancels enter the transaction runner, so this file truncates rather than wrapping
 * each test in a transaction, and seeds the demo afresh. luna is in Europe/Athens and its
 * bookings here start on the fixture's Tuesday, under a clock pinned 26 hours before the
 * fixture's start, so both cancels stand outside the policy window and reach the row whatever
 * the real date is. The file's one literal date, the closure, is read under that clock, and
 * the grace end is dated from it, so a grace row is a grace still running by the clock the
 * file pins; the last case holds that against the sweep.
 *
 * The trial, is a sixth state answered as active is, the stored set honoured; its rows carry a
 * customer and no subscription, as a trial whose checkout has opened would, and an end seven
 * days on from the pinned now. A trial the hourly lapse ended is then read as canceled, row for
 * row: the trial's end is set an hour before the pinned now and the lapse run before the
 * requests.
 */

const STATE_MATRIX_ADMITS = 'admits';

const STATE_MATRIX_REFUSES = 'refuses';

const STATE_MATRIX_TOKEN = 'matrix-manage-token';

/** Past the gate and answered with the route's own refusal, worded, nothing landed. */
const STATE_MATRIX_DECLINES = 'declines';

beforeEach(function () {
    $this->withoutVite();
    dropDemoSchemas();
    seedDemo();
    FixtureDay::pinClock();
});

afterEach(function () {
    dropDemoSchemas();
    DB::table('subscriptions')->delete();
    DB::table('users')->delete();
    DB::table('tenants')->delete();
});

/**
 * luna in the state and with the entitlements given, subscribed at the provider so the portal
 * has a customer to open, the grace end set in grace alone as the sync leaves it: the seven
 * days of billing.grace_days on from the pinned now, a window the clock has not reached. A trial
 * has a customer and no subscription, and its end the interval given on from the pinned now.
 */
function stateMatrixTenant(string $state, string $stored, string $trialEnd = 'P7D'): void
{
    $trial = $state === 'trial';
    DB::table('subscriptions')->insert([
        'tenant_id' => LunaFixture::tenant()->id,
        'provider_customer_id' => 'cus_luna',
        'provider_subscription_id' => $trial ? null : 'sub_luna',
        'status' => $trial ? null : 'active',
        'items' => json_encode($trial ? [] : [['price' => 'price_floor_test', 'quantity' => 1]], JSON_THROW_ON_ERROR),
    ]);
    billingSet([
        'subscription_state' => $state,
        'entitlements' => $stored,
        'grace_ends_at' => $state === 'grace' ? stateMatrixFromNow('P7D') : null,
        'trial_ends_at' => $trial ? stateMatrixFromNow($trialEnd) : null,
    ]);
}

/**
 * The pinned now moved by an ISO 8601 interval, a leading minus moving it back, as UTC text with
 * its offset.
 */
function stateMatrixFromNow(string $interval): string
{
    $now = app(Clock::class)->now();
    $moved = str_starts_with($interval, '-') ? $now->sub(new DateInterval(substr($interval, 1))) : $now->add(new DateInterval($interval));

    return $moved->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:sP');
}

function stateMatrixOwner(): User
{
    return User::query()->where('email', 'owner@luna.example')->sole();
}

/**
 * The state the gate's refusal names, or null for any other answer: the gate's envelope is the
 * only answer carrying subscription_required.
 *
 * @param  TestResponse<Response>  $response
 */
function stateMatrixRefusal(TestResponse $response): ?string
{
    $body = json_decode((string) $response->getContent(), true);

    if ($response->getStatusCode() !== 403 || ! is_array($body) || ($body['code'] ?? null) !== 'subscription_required') {
        return null;
    }

    $state = data_get($body, 'data.state');

    return is_string($state) ? $state : '';
}

/**
 * One cell of the table as observed: refused by the gate naming the state and nothing landed,
 * admitted with the answer the route gives and its effect landed, or the status it answered
 * instead, which matches no cell and so fails the row.
 *
 * @param  TestResponse<Response>  $response
 */
function stateMatrixCell(TestResponse $response, string $state, bool $answered, bool $landed): string
{
    $refusal = stateMatrixRefusal($response);

    if ($refusal !== null) {
        return $refusal === $state && ! $landed ? STATE_MATRIX_REFUSES : sprintf('refused naming "%s"%s', $refusal, $landed ? ' after it landed' : '');
    }

    return $answered && $landed ? STATE_MATRIX_ADMITS : sprintf('answered %d%s', $response->getStatusCode(), $landed ? '' : ' and landed nothing');
}

/**
 * The checkout's cell: the gate's refusal as stateMatrixCell reads it; admitted where the route
 * serves the state, the external redirect to the provider's page with the session recorded; or
 * declined where it does not, resubscribe_refused's sentence flashed on the redirect back and
 * nothing recorded.
 *
 * @param  TestResponse<Response>  $response
 */
function stateMatrixCheckoutCell(TestResponse $response, string $state): string
{
    $recorded = DB::table('subscriptions')->whereNotNull('checkout_session_id')->exists();

    if ($response->isRedirect(tenantUrl('luna', '/admin/billing')) && session('flash') === ['kind' => 'error', 'message' => adminLine(ResubscribeRefused::SENTENCE)]) {
        return $recorded ? 'declined after it recorded a session' : STATE_MATRIX_DECLINES;
    }

    $opened = $response->getStatusCode() === 409 && $response->headers->get('X-Inertia-Location') === FakeBillingProvider::checkoutUrl('cus_luna', 'staff', tenantUrl('luna', '/admin/billing'));

    return stateMatrixCell($response, $state, $opened, $recorded);
}

/**
 * One row of the table: the state, the entitlements the row stores, the answer each kind of
 * request gets, and the entitlements every page is handed.
 *
 * @param  array<string, string>  $answers
 * @param  list<string>  $entitlements
 * @return array{string, string, array<string, string>, list<string>, bool}
 */
function stateMatrixRow(string $state, string $stored, array $answers, array $entitlements, bool $lapsedTrial = false): array
{
    return [$state, $stored, $answers, $entitlements, $lapsedTrial];
}

it('answers each kind of request in each subscription state as the table spells it, and hands the screens the staff feature only while the state is active, grace or trial and the row stores it', function (string $state, string $stored, array $answers, array $entitlements, bool $lapsedTrial) {
    if ($lapsedTrial) {
        stateMatrixTenant('trial', $stored, '-PT1H');
        Artisan::call('billing:lapse');
        expect(DB::table('tenants')->where('slug', 'luna')->value('subscription_state'))->toBe($state);
    } else {
        stateMatrixTenant($state, $stored);
    }
    $owner = stateMatrixOwner();
    $forAdminCancel = LunaFixture::booking('confirmed', start: FixtureDay::START);
    $forGuestCancel = LunaFixture::booking('confirmed', start: FixtureDay::DATE.' 12:00');
    DB::table('tenant_luna.bookings')->where('uuid', $forGuestCancel)->update(['manage_token_hash' => ManageToken::hashOf(STATE_MATRIX_TOKEN)]);

    $read = $this->actingAs($owner)->get(tenantUrl('luna', '/admin/calendar'));
    $seen = data_get($read->viewData('page'), 'props.entitlements');

    $closures = DB::table('tenant_luna.blackouts')->whereNull('staff_id');
    $before = $closures->clone()->count();
    $write = $this->actingAs($owner)->from(tenantUrl('luna', '/admin/staff'))->post(tenantUrl('luna', '/admin/blackouts'), ['date' => '2027-03-09']);
    $written = $closures->clone()->count() === $before + 1;

    $lifecycle = $this->actingAs($owner)->from(tenantUrl('luna', '/admin/bookings/'.$forAdminCancel))->post(tenantUrl('luna', '/admin/bookings/'.$forAdminCancel.'/cancel'));

    $this->app['auth']->forgetGuards();
    $this->assertGuest();
    $public = $this->postJson(tenantUrl('luna', '/api/v1/bookings/'.$forGuestCancel.'/cancel'), ['token' => STATE_MATRIX_TOKEN]);

    $billing = $this->actingAs($owner)->post(tenantUrl('luna', '/admin/billing/portal'), [], ['X-Inertia' => 'true']);
    $checkout = $this->actingAs($owner)->from(tenantUrl('luna', '/admin/billing'))->post(tenantUrl('luna', '/admin/billing/checkout'), ['plan' => 'staff'], ['X-Inertia' => 'true']);

    expect([
        'write' => stateMatrixCell($write, $state, $write->isRedirect(tenantUrl('luna', '/admin/staff')), $written),
        'lifecycle' => stateMatrixCell($lifecycle, $state, $lifecycle->isRedirect(tenantUrl('luna', '/admin/bookings/'.$forAdminCancel)), LunaFixture::row($forAdminCancel)['status'] === 'cancelled'),
        'public' => stateMatrixCell($public, $state, $public->getStatusCode() === 200, LunaFixture::row($forGuestCancel)['status'] === 'cancelled'),
        'read' => stateMatrixCell($read, $state, $read->getStatusCode() === 200, data_get($read->viewData('page'), 'component') === 'Calendar'),
        'billing' => stateMatrixCell($billing, $state, $billing->getStatusCode() === 409, $billing->headers->get('X-Inertia-Location') === 'https://billing.test/portal/cus_luna'),
        'checkout' => stateMatrixCheckoutCell($checkout, $state),
    ])->toBe($answers)
        ->and($seen)->toBe($entitlements);
})->with([
    'active, staff stored' => stateMatrixRow('active', '{staff}', ['write' => STATE_MATRIX_ADMITS, 'lifecycle' => STATE_MATRIX_ADMITS, 'public' => STATE_MATRIX_ADMITS, 'read' => STATE_MATRIX_ADMITS, 'billing' => STATE_MATRIX_ADMITS, 'checkout' => STATE_MATRIX_DECLINES], ['staff']),
    'active, the floor' => stateMatrixRow('active', '{}', ['write' => STATE_MATRIX_ADMITS, 'lifecycle' => STATE_MATRIX_ADMITS, 'public' => STATE_MATRIX_ADMITS, 'read' => STATE_MATRIX_ADMITS, 'billing' => STATE_MATRIX_ADMITS, 'checkout' => STATE_MATRIX_DECLINES], []),
    'grace, staff stored' => stateMatrixRow('grace', '{staff}', ['write' => STATE_MATRIX_ADMITS, 'lifecycle' => STATE_MATRIX_ADMITS, 'public' => STATE_MATRIX_ADMITS, 'read' => STATE_MATRIX_ADMITS, 'billing' => STATE_MATRIX_ADMITS, 'checkout' => STATE_MATRIX_DECLINES], ['staff']),
    'grace, the floor' => stateMatrixRow('grace', '{}', ['write' => STATE_MATRIX_ADMITS, 'lifecycle' => STATE_MATRIX_ADMITS, 'public' => STATE_MATRIX_ADMITS, 'read' => STATE_MATRIX_ADMITS, 'billing' => STATE_MATRIX_ADMITS, 'checkout' => STATE_MATRIX_DECLINES], []),
    'past_due, staff stored' => stateMatrixRow('past_due', '{staff}', ['write' => STATE_MATRIX_REFUSES, 'lifecycle' => STATE_MATRIX_ADMITS, 'public' => STATE_MATRIX_ADMITS, 'read' => STATE_MATRIX_ADMITS, 'billing' => STATE_MATRIX_ADMITS, 'checkout' => STATE_MATRIX_DECLINES], []),
    'past_due, the floor' => stateMatrixRow('past_due', '{}', ['write' => STATE_MATRIX_REFUSES, 'lifecycle' => STATE_MATRIX_ADMITS, 'public' => STATE_MATRIX_ADMITS, 'read' => STATE_MATRIX_ADMITS, 'billing' => STATE_MATRIX_ADMITS, 'checkout' => STATE_MATRIX_DECLINES], []),
    'canceled, staff stored' => stateMatrixRow('canceled', '{staff}', ['write' => STATE_MATRIX_REFUSES, 'lifecycle' => STATE_MATRIX_ADMITS, 'public' => STATE_MATRIX_ADMITS, 'read' => STATE_MATRIX_ADMITS, 'billing' => STATE_MATRIX_ADMITS, 'checkout' => STATE_MATRIX_ADMITS], []),
    'canceled, the floor' => stateMatrixRow('canceled', '{}', ['write' => STATE_MATRIX_REFUSES, 'lifecycle' => STATE_MATRIX_ADMITS, 'public' => STATE_MATRIX_ADMITS, 'read' => STATE_MATRIX_ADMITS, 'billing' => STATE_MATRIX_ADMITS, 'checkout' => STATE_MATRIX_ADMITS], []),
    'incomplete, staff stored' => stateMatrixRow('incomplete', '{staff}', ['write' => STATE_MATRIX_REFUSES, 'lifecycle' => STATE_MATRIX_ADMITS, 'public' => STATE_MATRIX_ADMITS, 'read' => STATE_MATRIX_ADMITS, 'billing' => STATE_MATRIX_ADMITS, 'checkout' => STATE_MATRIX_DECLINES], []),
    'incomplete, the floor' => stateMatrixRow('incomplete', '{}', ['write' => STATE_MATRIX_REFUSES, 'lifecycle' => STATE_MATRIX_ADMITS, 'public' => STATE_MATRIX_ADMITS, 'read' => STATE_MATRIX_ADMITS, 'billing' => STATE_MATRIX_ADMITS, 'checkout' => STATE_MATRIX_DECLINES], []),
    'trial, staff stored' => stateMatrixRow('trial', '{staff}', ['write' => STATE_MATRIX_ADMITS, 'lifecycle' => STATE_MATRIX_ADMITS, 'public' => STATE_MATRIX_ADMITS, 'read' => STATE_MATRIX_ADMITS, 'billing' => STATE_MATRIX_ADMITS, 'checkout' => STATE_MATRIX_ADMITS], ['staff']),
    'trial, the floor' => stateMatrixRow('trial', '{}', ['write' => STATE_MATRIX_ADMITS, 'lifecycle' => STATE_MATRIX_ADMITS, 'public' => STATE_MATRIX_ADMITS, 'read' => STATE_MATRIX_ADMITS, 'billing' => STATE_MATRIX_ADMITS, 'checkout' => STATE_MATRIX_ADMITS], []),
    'a trial the lapse ended, staff stored' => stateMatrixRow('canceled', '{staff}', ['write' => STATE_MATRIX_REFUSES, 'lifecycle' => STATE_MATRIX_ADMITS, 'public' => STATE_MATRIX_ADMITS, 'read' => STATE_MATRIX_ADMITS, 'billing' => STATE_MATRIX_ADMITS, 'checkout' => STATE_MATRIX_ADMITS], [], true),
    'a trial the lapse ended, the floor' => stateMatrixRow('canceled', '{}', ['write' => STATE_MATRIX_REFUSES, 'lifecycle' => STATE_MATRIX_ADMITS, 'public' => STATE_MATRIX_ADMITS, 'read' => STATE_MATRIX_ADMITS, 'billing' => STATE_MATRIX_ADMITS, 'checkout' => STATE_MATRIX_ADMITS], [], true),
]);

it('stores a trial end the pinned clock has not reached, so a trial row of the table is a trial still running and the lapse ends nothing', function () {
    stateMatrixTenant('trial', '{staff}');

    expect(app(SubscriptionSync::class)->lapseTrials())->toBe(0)
        ->and(DB::table('tenants')->where('slug', 'luna')->value('subscription_state'))->toBe('trial');
});

it('stores a grace end the pinned clock has not reached, so a grace row of the table is a window still open and the sweep lapses nothing', function () {
    stateMatrixTenant('grace', '{staff}');

    expect(app(SubscriptionSync::class)->lapseExpired())->toBe(0)
        ->and(DB::table('tenants')->where('slug', 'luna')->value('subscription_state'))->toBe('grace');
});
