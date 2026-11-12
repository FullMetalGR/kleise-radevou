<?php

use App\Application\Notification\MessageKey;
use App\Billing\EntitlementResolver;
use App\Billing\Entitlements;
use App\Http\AdminProps;
use App\Models\User;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

pest()->use(RefreshDatabase::class);

beforeEach(fn () => $this->withoutVite());

/*
 * The shared props, from the one middleware, as every page receives them.
 */

beforeEach(function () {
    provisionTenant('luna', ['name' => 'Luna Hair Studio', 'ownerEmail' => 'owner@luna.example'], ['staff']);
});

function lunaUser(string $email = 'owner@luna.example'): User
{
    return User::query()->where('email', $email)->sole();
}

it('hands every page the tenant, the user, the support session as null for a user, the permissions, the entitlements, the subscription, the settings, the nav, the flash and the brand', function () {
    $owner = lunaUser();

    $this->actingAs($owner)->get(tenantUrl('luna', '/admin'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Home')
            ->where('tenant', ['name' => 'Luna Hair Studio', 'slug' => 'luna', 'timezone' => 'Europe/Athens', 'currency' => 'EUR'])
            ->where('auth.user', ['id' => $owner->id, 'name' => 'Alex Owner', 'role' => 'owner'])
            ->where('support', null)
            ->where('permissions', ['viewBookings', 'viewOwnCalendar', 'manageBookings', 'approveBookings', 'manageSettings', 'manageBilling'])
            ->where('entitlements', ['staff'])
            ->where('subscription', ['state' => 'active'])
            ->where('free', true)
            ->where('settings.granularity', 5)
            ->where('settings.week_start', 1)
            ->where('settings.date_format', 'd/m/Y')
            ->where('settings.time_format', 'H:i')
            ->where('settings.colour_by', 'staff')
            ->has('settings.messages', 17)
            ->where('settings.messages.0', ['key' => 'acknowledged', 'label' => adminLine(MessageKey::Acknowledged->labelKey()), 'audience' => 'customer', 'owners_alone' => false, 'locked' => false])
            ->where('nav', [
                ['label' => navLabel('home'), 'url' => '/admin', 'group' => navLabel('groups.schedule'), 'pending' => null, 'current' => true],
                ['label' => navLabel('calendar'), 'url' => '/admin/calendar', 'group' => navLabel('groups.schedule'), 'pending' => null, 'current' => false],
                ['label' => navLabel('bookings'), 'url' => '/admin/bookings', 'group' => navLabel('groups.schedule'), 'pending' => null, 'current' => false],
                ['label' => navLabel('shifts'), 'url' => '/admin/shifts', 'group' => navLabel('groups.schedule'), 'pending' => null, 'current' => false],
                ['label' => navLabel('services'), 'url' => '/admin/services', 'group' => navLabel('groups.business'), 'pending' => null, 'current' => false],
                ['label' => navLabel('staff'), 'url' => '/admin/staff', 'group' => navLabel('groups.business'), 'pending' => null, 'current' => false],
                ['label' => navLabel('customers'), 'url' => '/admin/customers', 'group' => navLabel('groups.business'), 'pending' => null, 'current' => false],
                ['label' => navLabel('reports'), 'url' => '/admin/reports', 'group' => navLabel('groups.business'), 'pending' => null, 'current' => false],
                ['label' => navLabel('team'), 'url' => '/admin/team', 'group' => navLabel('groups.business'), 'pending' => null, 'current' => false],
                ['label' => navLabel('settings'), 'url' => '/admin/settings', 'group' => navLabel('groups.business'), 'pending' => null, 'current' => false],
                ['label' => navLabel('billing'), 'url' => '/admin/billing', 'group' => navLabel('groups.business'), 'pending' => null, 'current' => false],
            ])
            ->where('flash', null)
            ->where('brand', ['name' => siteName()]));
});

it('carries every message key of the catalog in the settings prop, in the catalog\'s order', function () {
    $this->actingAs(lunaUser())->get(tenantUrl('luna', '/admin'))
        ->assertInertia(fn (Assert $page) => $page->where(
            'settings.messages',
            fn (Collection $messages): bool => $messages->pluck('key')->all() === array_map(fn (MessageKey $key): string => $key->value, MessageKey::cases()),
        ));
});

it('derives permissions from the role on the server and filters the nav by them', function () {
    addTenantUser('staff@luna.example', 'staff');

    $this->actingAs(lunaUser('staff@luna.example'))->get(tenantUrl('luna', '/admin/calendar'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Calendar')
            ->where('pinned', true)
            ->where('auth.user.role', 'staff')
            ->where('permissions', ['viewOwnCalendar', 'approveBookings'])
            ->where('nav', [
                ['label' => navLabel('calendar'), 'url' => '/admin/calendar', 'group' => navLabel('groups.schedule'), 'pending' => null, 'current' => true],
            ]));
});

it('answers the floor from the row on a tenant whose row holds the empty set, Closures standing in Staff\'s place on the rail at the same URL', function () {
    DB::table('tenants')->where('slug', 'luna')->update(['entitlements' => '{}']);

    $this->actingAs(lunaUser())->get(tenantUrl('luna', '/admin'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('entitlements', [])
            ->where('nav', [
                ['label' => navLabel('home'), 'url' => '/admin', 'group' => navLabel('groups.schedule'), 'pending' => null, 'current' => true],
                ['label' => navLabel('calendar'), 'url' => '/admin/calendar', 'group' => navLabel('groups.schedule'), 'pending' => null, 'current' => false],
                ['label' => navLabel('bookings'), 'url' => '/admin/bookings', 'group' => navLabel('groups.schedule'), 'pending' => null, 'current' => false],
                ['label' => navLabel('services'), 'url' => '/admin/services', 'group' => navLabel('groups.business'), 'pending' => null, 'current' => false],
                ['label' => navLabel('closures'), 'url' => '/admin/staff', 'group' => navLabel('groups.business'), 'pending' => null, 'current' => false],
                ['label' => navLabel('customers'), 'url' => '/admin/customers', 'group' => navLabel('groups.business'), 'pending' => null, 'current' => false],
                ['label' => navLabel('reports'), 'url' => '/admin/reports', 'group' => navLabel('groups.business'), 'pending' => null, 'current' => false],
                ['label' => navLabel('team'), 'url' => '/admin/team', 'group' => navLabel('groups.business'), 'pending' => null, 'current' => false],
                ['label' => navLabel('settings'), 'url' => '/admin/settings', 'group' => navLabel('groups.business'), 'pending' => null, 'current' => false],
                ['label' => navLabel('billing'), 'url' => '/admin/billing', 'group' => navLabel('groups.business'), 'pending' => null, 'current' => false],
            ]));
});

it('shows a staff user without the feature the Calendar alone, the Closures entry standing under viewBookings as the Staff entry does', function () {
    DB::table('tenants')->where('slug', 'luna')->update(['entitlements' => '{}']);
    addTenantUser('staff@luna.example', 'staff');

    $this->actingAs(lunaUser('staff@luna.example'))->get(tenantUrl('luna', '/admin/calendar'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('entitlements', [])
            ->where('permissions', ['viewOwnCalendar', 'approveBookings'])
            ->where('nav', [
                ['label' => navLabel('calendar'), 'url' => '/admin/calendar', 'group' => navLabel('groups.schedule'), 'pending' => null, 'current' => true],
            ]));
});

it('lights the Staff screen\'s one entry on its list and on a member\'s page reached by URL, Staff with the feature and Closures without it', function (string $entitlements, string $entry, bool $detail, int $entries, int $at) {
    DB::table('tenants')->where('slug', 'luna')->update(['entitlements' => $entitlements]);
    $implicit = DB::table('tenant_luna.staff')->where('is_implicit', true)->value('id');
    assert(is_int($implicit));

    $this->actingAs(lunaUser())->get(tenantUrl('luna', $detail ? '/admin/staff/'.$implicit : '/admin/staff'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Staff')
            ->has('nav', $entries)
            ->where('nav.'.$at, ['label' => navLabel($entry), 'url' => '/admin/staff', 'group' => navLabel('groups.business'), 'pending' => null, 'current' => true])
            ->where('nav', fn (Collection $nav): bool => $nav->where('current', true)->count() === 1));
})->with([
    'the list with the feature' => ['{staff}', 'staff', false, 11, 5],
    "a member's page with the feature" => ['{staff}', 'staff', true, 11, 5],
    'the list without the feature' => ['{}', 'closures', false, 10, 4],
    "a member's page without the feature" => ['{}', 'closures', true, 10, 4],
]);

it('leaves the floor working under a bound resolver answering the empty set, whatever the row holds', function () {
    expect(Tenant::query()->where('slug', 'luna')->sole()->entitlements)->toBe(['staff']);
    app()->instance(EntitlementResolver::class, new class implements EntitlementResolver
    {
        public function resolve(Tenant $tenant): Entitlements
        {
            return Entitlements::none();
        }
    });

    $this->actingAs(lunaUser())->get(tenantUrl('luna', '/admin'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('entitlements', [])->has('nav', 10)->where('nav.4.label', navLabel('closures')));
});

it('carries grace_ends_at inside grace and only there', function () {
    DB::table('tenants')->where('slug', 'luna')->update(['subscription_state' => 'grace', 'grace_ends_at' => '2026-10-01 09:00:00+00']);

    $this->actingAs(lunaUser())->get(tenantUrl('luna', '/admin'))
        ->assertInertia(fn (Assert $page) => $page->where('subscription', ['state' => 'grace', 'grace_ends_at' => '2026-10-01T09:00:00+00:00']));

    DB::table('tenants')->where('slug', 'luna')->update(['subscription_state' => 'past_due']);

    $this->actingAs(lunaUser())->get(tenantUrl('luna', '/admin'))
        ->assertInertia(fn (Assert $page) => $page->where('subscription', ['state' => 'past_due']));
});

it('carries trial_ends_at in a trial and in canceled after one, and not in canceled after a subscription', function () {
    DB::table('tenants')->where('slug', 'luna')->update(['subscription_state' => 'trial', 'trial_ends_at' => '2026-10-01 09:00:00+00']);

    $this->actingAs(lunaUser())->get(tenantUrl('luna', '/admin'))
        ->assertInertia(fn (Assert $page) => $page->where('subscription', ['state' => 'trial', 'trial_ends_at' => '2026-10-01T09:00:00+00:00']));

    DB::table('tenants')->where('slug', 'luna')->update(['subscription_state' => 'canceled']);

    $this->actingAs(lunaUser())->get(tenantUrl('luna', '/admin'))
        ->assertInertia(fn (Assert $page) => $page->where('subscription', ['state' => 'canceled', 'trial_ends_at' => '2026-10-01T09:00:00+00:00']));

    DB::table('tenants')->where('slug', 'luna')->update(['trial_ends_at' => null]);

    $this->actingAs(lunaUser())->get(tenantUrl('luna', '/admin'))
        ->assertInertia(fn (Assert $page) => $page->where('subscription', ['state' => 'canceled']));
});

it('tells the page whether the tenant is in the free shape, its plan the console\'s, through the one predicate the console and Billing read: {case}', function (string $state, ?string $trialEnd, bool $subscribed, bool $free) {
    DB::table('tenants')->where('slug', 'luna')->update(['subscription_state' => $state, 'trial_ends_at' => $trialEnd]);

    if ($subscribed) {
        DB::table('subscriptions')->insert(['tenant_id' => Tenant::query()->where('slug', 'luna')->value('id'), 'provider_customer_id' => 'cus_luna']);
    }

    $this->actingAs(lunaUser())->get(tenantUrl('luna', '/admin'))
        ->assertInertia(fn (Assert $page) => $page->where('free', $free));
})->with([
    'active, no subscriptions row, no trial end: as tenant:provision and the console write it' => ['active', null, false, true],
    'active beside a subscriptions row: a paying tenant' => ['active', null, true, false],
    'in a trial' => ['trial', '2026-10-01 09:00:00+00', false, false],
    'a trial that ended unpaid' => ['canceled', '2026-10-01 09:00:00+00', false, false],
    'past due with no subscriptions row, a frozen state no writer leaves' => ['past_due', null, false, false],
]);

it('carries nothing of the free shape to the sign-in page, which is outside the shell', function () {
    $this->get(tenantUrl('luna', '/admin/login'))
        ->assertInertia(fn (Assert $page) => $page->missing('free'));
});

it('raises on a trial with no end rather than rendering an open-ended trial', function () {
    $tenant = Tenant::query()->where('slug', 'luna')->sole();
    $tenant->setRawAttributes([...$tenant->getAttributes(), 'subscription_state' => 'trial', 'trial_ends_at' => null]);

    AdminProps::subscriptionOf($tenant);
})->throws(LogicException::class);

it('raises on a subscription state outside the six rather than rendering one', function () {
    $tenant = Tenant::query()->where('slug', 'luna')->sole();
    $tenant->setRawAttributes([...$tenant->getAttributes(), 'subscription_state' => 'paused']);

    AdminProps::subscriptionOf($tenant);
})->throws(ValueError::class);

it('raises on grace with no end rather than rendering an open-ended grace', function () {
    $tenant = Tenant::query()->where('slug', 'luna')->sole();
    $tenant->setRawAttributes([...$tenant->getAttributes(), 'subscription_state' => 'grace', 'grace_ends_at' => null]);

    AdminProps::subscriptionOf($tenant);
})->throws(LogicException::class);

it('consumes a flash into the next page once', function () {
    $this->actingAs(lunaUser())
        ->withSession(['flash' => ['kind' => 'error', 'message' => 'That did not work.'], '_flash' => ['old' => ['flash'], 'new' => []]])
        ->get(tenantUrl('luna', '/admin'))
        ->assertInertia(fn (Assert $page) => $page->where('flash', ['kind' => 'error', 'message' => 'That did not work.']));

    $this->actingAs(lunaUser())->get(tenantUrl('luna', '/admin'))
        ->assertInertia(fn (Assert $page) => $page->where('flash', null));
});

it('answers a signed-in user lacking the route\'s permission 403', function () {
    addTenantUser('staff@luna.example', 'staff');

    $this->actingAs(lunaUser('staff@luna.example'))->get(tenantUrl('luna', '/admin/bookings'))->assertForbidden();
});

it('renders an unknown admin path as the 404 page inside the shell', function () {
    $this->actingAs(lunaUser())->get(tenantUrl('luna', '/admin/nothing-here'))
        ->assertNotFound()
        ->assertInertia(fn (Assert $page) => $page->component('NotFound')->has('nav', 11)->where('nav', fn (Collection $nav): bool => $nav->where('current', true)->count() === 0));
});

it('leaves the hosted page and the API outside the prefix answering as before', function () {
    $this->actingAs(lunaUser())->get(tenantUrl('luna', '/'))->assertOk()->assertHeader('Content-Type', 'text/html; charset=UTF-8');

    $this->getJson(tenantUrl('luna', '/api/v1/bookings/7b7f0e5a-0000-4000-8000-000000000001'))
        ->assertStatus(400)
        ->assertJsonPath('code', 'invalid_request');

    $this->get(tenantUrl('luna', '/nothing-here'))->assertNotFound();
});

it('renders a fresh tenant\'s admin with the neutral theme on its body, from the server, and hands the page the word', function () {
    $this->actingAs(lunaUser())->get(tenantUrl('luna', '/admin'))
        ->assertOk()
        ->assertSee('<body data-theme="neutral">', false)
        ->assertInertia(fn (Assert $page) => $page->where('theme', 'neutral'));
});

it('renders the admin, inside the shell and on the sign-in page, in the theme the settings row names', function () {
    DB::table('tenant_luna.settings')->where('id', 1)->update(['theme' => 'violet']);

    $this->actingAs(lunaUser())->get(tenantUrl('luna', '/admin/bookings'))
        ->assertOk()
        ->assertSee('<body data-theme="violet">', false)
        ->assertInertia(fn (Assert $page) => $page->where('theme', 'violet'));

    auth()->logout();

    $this->get(tenantUrl('luna', '/admin/login'))
        ->assertOk()
        ->assertSee('<body data-theme="violet">', false);
});
