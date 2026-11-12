<?php

use App\Application\Clock;
use App\Domain\Booking\ManageToken;
use App\Domain\Enum\Decision;
use App\Http\Middleware\RequiresActiveSubscription;
use App\Http\Middleware\RequiresManageToken;
use App\Models\User;
use App\Tenancy\DecisionLinks;
use App\Tenancy\TenantUrls;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Routing\Route as RegisteredRoute;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FixtureDay;
use Tests\Support\LunaFixture;
use Tests\TestCase;

pest()->use(DatabaseTruncation::class);

/*
 * The freeze list walked route by route under a lapsed tenant. Every route registered on a
 * tenant subdomain is requested, the public and guest routes and the whole admin surface alike,
 * as the owner, who holds every permission, so no permission refusal can stand in front of the
 * gate on a write. Each answer is read for the gate's refusal, the subscription_required
 * envelope or its account_closing one on a closing tenant, and exactly the configuration writes
 * may carry it.
 *
 * The central host's routes, the signup form and its post, the pending page, the billing webhook
 * and /up, are left out, as ApiSurfaceTest's walk leaves them out: the gate reads the current
 * tenant, and no tenant is current on that host, so none of them can meet it.
 *
 * The configuration writes are spelled out here rather than read off the routes, so the walk
 * does not ask the gate which routes the gate is on: a lifecycle route or a read gaining the
 * gate fails here, and so does a configuration write losing it.
 *
 * Every other answer is fine whatever it is, a 404, a 419, a 422 or a guard's refusal, as long
 * as it is not the gate's. The routes behind a manage token are handed a token that matches, and
 * the decision pages a link minted for the owner, so neither guard stands in front of the gate
 * either. Each route with a booking in its path gets a booking of its own, so a cancel early in
 * the walk leaves nothing lapsed for a route after it.
 *
 * The walk's bookings start at the fixture's literal date, so the application clock is pinned
 * 26 hours before that start: the guest's cancel and reschedule stand outside their policy
 * window whatever the real date is, the manage token is inside its thirty days, and the
 * decision link expires a day after the pinned now. The walk then says so: both guest routes
 * answer 200, so a policy refusal standing in for "not the gate's" fails here rather than
 * passing as one. The approval hold's own expiry is the database's, two days past its now(),
 * and meets no fixture date.
 *
 * A trial the hourly lapse ended is walked as well: its row is written in trial with an end an
 * hour before the pinned now and the lapse run, so it is frozen as any canceled tenant is
 * because the lapse made it so. So is a tenant the platform closed: its trial ended thirty days
 * before the pinned now, counted in UTC as the lapse counts them so the row stands on the
 * lapse's own boundary across the October change, and the one run of the lapse ends it and
 * closes it, so its status is closing; it still answers its booking page, its API and its admin,
 * its configuration writes refused by the gate's closing envelope, account_closing, which reads
 * the status beside the state. So is a tenant its owner closed from the Danger zone while its
 * subscription state is still active, the free shape cancelling nothing: the status alone
 * freezes it. The owner's close is walked in every state, last, since it closes an open account:
 * it answers its redirect where the account was open and its 409 where it was closing, and never
 * the gate's refusal.
 *
 * Lifecycle actions enter the transaction runner, so this file truncates rather than wrapping
 * each test in a transaction, and seeds the demo afresh.
 */

/** The configuration writes, the only routes the subscription gate may run on. */
const FREEZE_CONFIGURATION_WRITES = [
    'admin.services.store',
    'admin.services.update',
    'admin.services.destroy',
    'admin.staff.services',
    'admin.staff.store',
    'admin.staff.update',
    'admin.staff.destroy',
    'admin.staff.photo.store',
    'admin.staff.photo.destroy',
    'admin.staff.colour',
    'admin.staff.blackouts.store',
    'admin.staff.blackouts.destroy',
    'admin.blackouts.store',
    'admin.blackouts.destroy',
    'admin.shifts.pattern',
    'admin.shifts.days.update',
    'admin.shifts.days.destroy',
    'admin.settings.update',
    'admin.settings.colour-by',
    'admin.opening-hours.update',
    'admin.settings.logo.store',
    'admin.settings.logo.destroy',
    'admin.settings.cover.store',
    'admin.settings.cover.destroy',
    'admin.settings.gallery.store',
    'admin.settings.gallery.destroy',
    'admin.settings.payments.store',
    'admin.settings.payments.platform',
    'admin.settings.payments.platform.resume',
    'admin.settings.payments.test',
    'admin.settings.payments.rotate',
    'admin.settings.payments.disable',
    'admin.settings.payments.enable',
    'admin.settings.payments.destroy',
];

const FREEZE_TOKEN = 'freeze-walk-manage-token';

/** The two guest lifecycle routes the walk follows past the gate, to the row. */
const FREEZE_GUEST_CANCEL = 'api.booking.cancel';

const FREEZE_GUEST_RESCHEDULE = 'api.booking.reschedule';

/** The press of a copy button, recorded in every state: it configures nothing. */
const FREEZE_LINK_COPIED = 'admin.settings.link-copied';

/**
 * The owner's close from the Danger zone, answered in every state and never gated: walked last,
 * since it closes an open account and every write after it would read the closing.
 */
const FREEZE_CLOSE = 'admin.settings.close';

/**
 * The booking page, the API's reads, the admin's own screens, a customer's export and the
 * tenant's, data rights and no configuration, and Reports' CSV and the Team screen, each
 * answered whatever the state.
 */
const FREEZE_ANSWERING = ['GET hosted', 'GET api.config', 'GET api.services', 'GET admin.home', 'GET admin.calendar', 'GET admin.billing', 'GET admin.services', 'GET admin.customers', 'GET admin.customers.csv', 'GET admin.customers.show', 'GET admin.customers.export', 'GET admin.settings.export', 'GET admin.reports', 'GET admin.reports.csv', 'GET admin.team'];

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
 * Every route of a tenant subdomain in registration order: the surface ApiSurfaceTest pins.
 *
 * @return list<RegisteredRoute>
 */
function freezeRoutes(): array
{
    $domain = '{tenant}.'.app(TenantUrls::class)->centralHost();

    return array_values(array_filter(Route::getRoutes()->getRoutes(), fn (RegisteredRoute $route): bool => $route->getDomain() === $domain));
}

/**
 * A booking of luna for one route to act on: a request awaiting a decision for the decision
 * pages, a confirmed appointment for everything else, each answering the walk's manage token.
 * None blocks the diary, so as many as the walk needs sit side by side.
 */
function freezeBooking(bool $awaitingDecision): string
{
    $uuid = $awaitingDecision
        ? LunaFixture::booking('awaiting_approval', 'approval', 'future', blocks: false, service: 'Bridal trial', requiresApproval: true)
        : LunaFixture::booking('confirmed', blocks: false);

    DB::table('tenant_luna.bookings')->where('uuid', $uuid)->update(['manage_token_hash' => ManageToken::hashOf(FREEZE_TOKEN)]);

    return $uuid;
}

/**
 * One request of the walk: the route's path with plausible values in its parameters, the
 * owner signed in, a manage token where the route's guard reads one and a signed link where the
 * route is a decision page. A body is sent empty, the guest's reschedule aside: the gate runs
 * before any validation, so an empty body is refused by it exactly as a full one would be.
 *
 * @return TestResponse<Response>
 */
function freezeRequest(TestCase $test, RegisteredRoute $route, string $method, User $owner): TestResponse
{
    $uri = $route->uri();
    $decisionPage = in_array(DecisionLinks::ROUTE, [$route->getName(), explode('.', (string) $route->getName())[0]], true);
    $uuid = str_contains($uri, '{uuid}') ? freezeBooking($decisionPage) : '';
    $email = '';

    // A customer's page names an address a booking was made under: one of the walk's own.
    if (str_contains($uri, '{email}')) {
        freezeBooking(false);
        $email = rawurlencode(LunaFixture::CUSTOMER_EMAIL);
    }

    if ($decisionPage) {
        $url = app(DecisionLinks::class)->link(LunaFixture::tenant(), $uuid, Decision::Approve, $owner->id, app(Clock::class)->now()->add(new DateInterval('P1D')));

        return $test->actingAs($owner)->call($method, $url);
    }

    $path = strtr($uri, [
        '{uuid}' => $uuid,
        '{email}' => $email,
        '{id}' => (string) (str_starts_with($uri, 'admin/staff/') ? LunaFixture::id('staff', 'Elena Papadaki') : LunaFixture::id('services', 'Haircut')),
        '{file}' => 'logo.png',
        '{path}' => 'nowhere',
    ]);
    $url = tenantUrl('luna', '/'.ltrim($path, '/'));
    $tokened = in_array(RequiresManageToken::NAME, $route->gatherMiddleware(), true);

    if (str_starts_with($uri, 'api/')) {
        return $method === 'POST'
            ? $test->actingAs($owner)->json($method, $url, $tokened ? ['token' => FREEZE_TOKEN, ...freezeMove($route)] : [])
            : $test->actingAs($owner)->json($method, $url.($tokened ? '?token='.FREEZE_TOKEN : ''));
    }

    return $test->actingAs($owner)->call($method, $url);
}

/**
 * The one body of the walk that is not empty: the guest's reschedule is handed a start, an
 * hour after the fixture's on the same Tuesday, so the move runs past its validation and its
 * policy window and lands, which is what "never frozen" means for it.
 *
 * @return array<string, string>
 */
function freezeMove(RegisteredRoute $route): array
{
    return $route->getName() === FREEZE_GUEST_RESCHEDULE
        ? ['start' => FixtureDay::start()->add(new DateInterval('PT1H'))->format(DateTimeImmutable::RFC3339)]
        : [];
}

/**
 * The state named by the gate's refusal, closing for its refusal of a closing account, or null
 * for any other answer.
 *
 * @param  TestResponse<Response>  $response
 */
function freezeRefusal(TestResponse $response): ?string
{
    $body = json_decode((string) $response->getContent(), true);

    if (is_array($body) && ($body['code'] ?? null) === RequiresActiveSubscription::CLOSING) {
        return $response->getStatusCode().' closing';
    }

    if (! is_array($body) || ($body['code'] ?? null) !== 'subscription_required') {
        return null;
    }

    $state = data_get($body, 'data.state');

    return $response->getStatusCode().' '.(is_string($state) ? $state : '');
}

it('refuses exactly the configuration writes of a lapsed or closing tenant with the gate\'s envelope, and no public route, no guest route, no lifecycle action, no read and not the close', function (string $state, ?string $trialEndedAgo = null, string $status = 'active', bool $ownerClosed = false) {
    $owner = User::query()->where('email', 'owner@luna.example')->sole();

    if ($trialEndedAgo !== null) {
        billingSet(['subscription_state' => 'trial', 'entitlements' => '{staff}', 'trial_ends_at' => app(Clock::class)->now()->setTimezone(new DateTimeZone('UTC'))->sub(new DateInterval($trialEndedAgo))->format('Y-m-d H:i:sP')]);
        Artisan::call('billing:lapse');
    } else {
        billingSet(['subscription_state' => $state, 'grace_ends_at' => null]);
    }

    // The owner's own close of luna, the console's free shape, which cancels nothing: the
    // subscription state stays active and the status alone freezes.
    if ($ownerClosed) {
        $this->actingAs($owner)->post(tenantUrl('luna', '/admin/settings/close'))->assertRedirect(tenantUrl('luna', '/admin/billing'));
    }

    expect(DB::table('tenants')->where('slug', 'luna')->value('subscription_state'))->toBe($state)
        ->and(DB::table('tenants')->where('slug', 'luna')->value('status'))->toBe($status);
    $answers = [];
    $refused = [];
    $close = null;

    foreach (freezeRoutes() as $route) {
        foreach (array_diff(array_filter($route->methods(), is_string(...)), ['HEAD']) as $method) {
            $name = $route->getName() ?? $route->uri();

            if ($name === FREEZE_CLOSE) {
                $close = $route;

                continue;
            }

            $response = freezeRequest($this, $route, $method, $owner);
            $answers[$method.' '.$name] = $response->getStatusCode();

            if (($refusal = freezeRefusal($response)) !== null) {
                $refused[$name] = $refusal;
            }
        }
    }

    expect($close)->not->toBeNull('the owner\'s close is not registered');
    assert($close instanceof RegisteredRoute);
    $closed = freezeRequest($this, $close, 'POST', $owner);
    $answers['POST '.FREEZE_CLOSE] = $closed->getStatusCode();

    if (($refusal = freezeRefusal($closed)) !== null) {
        $refused[FREEZE_CLOSE] = $refusal;
    }

    $walked = array_map(fn (RegisteredRoute $route): string => $route->getName() ?? $route->uri(), freezeRoutes());
    $expected = array_fill_keys(array_values(array_intersect($walked, FREEZE_CONFIGURATION_WRITES)), $status === 'closing' ? '403 closing' : '403 '.$state);

    expect(array_values(array_diff(FREEZE_CONFIGURATION_WRITES, $walked)))->toBe([], 'a configuration write the list names is not registered')
        ->and(count($answers))->toBeGreaterThan(count(FREEZE_CONFIGURATION_WRITES))
        ->and($refused)->toBe($expected, json_encode($answers, JSON_THROW_ON_ERROR))
        ->and([$answers['POST '.FREEZE_GUEST_CANCEL] ?? null, $answers['POST '.FREEZE_GUEST_RESCHEDULE] ?? null])->toBe([200, 200], 'the guest\'s cancel and reschedule did not land: '.json_encode($answers, JSON_THROW_ON_ERROR))
        ->and(array_combine(FREEZE_ANSWERING, array_map(fn (string $key): ?int => $answers[$key] ?? null, FREEZE_ANSWERING)))->toBe(array_fill_keys(FREEZE_ANSWERING, 200), 'the booking page, the API or the admin did not answer')
        ->and($answers['POST '.FREEZE_LINK_COPIED] ?? null)->toBe(302, 'the copy button\'s press was not recorded: it configures nothing and is not gated')
        ->and($answers['POST '.FREEZE_CLOSE])->toBe($status === 'closing' ? 409 : 302, 'the owner\'s close was gated: an open account closes and a closing one is refused as closing');
})->with([
    'past_due' => ['past_due'],
    'canceled' => ['canceled'],
    'incomplete' => ['incomplete'],
    'a trial the hourly lapse ended' => ['canceled', 'PT1H'],
    'a tenant the platform closed thirty days after its trial ended unpaid' => ['canceled', 'P30D', 'closing'],
    'a tenant its owner closed with the subscription still active' => ['active', null, 'closing', true],
]);
