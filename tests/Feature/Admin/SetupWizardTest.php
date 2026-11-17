<?php

use App\Application\Clock;
use App\Http\AdminSession;
use App\Http\Setup\SetupWizard;
use App\Infrastructure\Db\SqlText;
use App\Models\User;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FixtureDay;
use Tests\Support\WeeklyHours;
use Tests\TestCase;

pest()->use(RefreshDatabase::class);

/*
 * The setup wizard as Home offers it: to an owner in their own session alone, never on the demo
 * account, never while the subscription has lapsed or the account is closing, and never once
 * setup is finished; each step's fact read fresh from its own rows on every answer; the business
 * recorded as finished on the first answer that finds every step that applies closed; and the
 * wizard's own body-less writes, the automatic opening's record, a skip and Finish.
 *
 * The seeded businesses are established, their setup finished, so each test here opens setup on
 * luna or pier itself. luna holds the staff feature, services, two active members beyond its own
 * built-in one and no payment connection; pier holds no feature and its built-in member alone.
 */

beforeEach(function () {
    $this->withoutVite();
    seedDemo();
    FixtureDay::pinClock();
});

/** Opens setup again on a seeded business, every setup fact unset. */
function setupOpen(string $slug): void
{
    settingsOf($slug)->update([
        'setup_opened_at' => null, 'setup_finished_at' => null, 'setup_profile_saved_at' => null,
        'setup_team_skipped_at' => null, 'setup_payments_skipped_at' => null, 'setup_link_finished_at' => null,
        'hours_confirmed_at' => null, 'link_copied_at' => null,
    ]);
}

/** Opens setup again on luna with every step that applies closed and nothing yet recorded as finished. */
function setupEveryStepClosed(): void
{
    setupOpen('luna');
    settingsOf('luna')->update([
        'about' => 'Haircuts since 1998.', 'hours_confirmed_at' => DB::raw('now()'),
        'setup_payments_skipped_at' => DB::raw('now()'), 'setup_link_finished_at' => DB::raw('now()'),
    ]);
}

function setupOwner(string $slug = 'luna'): User
{
    return User::query()->where('email', sprintf('owner@%s.example', $slug))->sole();
}

/**
 * Home's `setup` prop for the viewer, or null where Home sends none.
 *
 * @return array{steps: list<array{key: string, done: bool, skipped: bool}>, opened: bool, opens: string|null, data: array<string, array<string, mixed>|null>}|null
 */
function setupProp(TestCase $test, User $viewer, string $slug = 'luna'): ?array
{
    $setup = data_get($test->actingAs($viewer)->get(tenantUrl($slug, '/admin'))->assertOk()->viewData('page'), 'props.setup');

    if ($setup === null) {
        return null;
    }

    assert(is_array($setup) && is_array($setup['steps'] ?? null) && is_bool($setup['opened'] ?? null) && is_array($setup['data'] ?? null));

    /** @var array{steps: list<array{key: string, done: bool, skipped: bool}>, opened: bool, opens: string|null, data: array<string, array<string, mixed>|null>} $setup */
    return $setup;
}

/**
 * Each step's key and whether it reads as closed, done or skipped, in order.
 *
 * @return array<string, bool>
 */
function setupClosed(TestCase $test, User $viewer, string $slug = 'luna'): array
{
    $closed = [];

    foreach (setupProp($test, $viewer, $slug)['steps'] ?? [] as $step) {
        $closed[$step['key']] = $step['done'] || $step['skipped'];
    }

    return $closed;
}

/**
 * @return TestResponse<Response>
 */
function setupWrite(TestCase $test, User $user, string $path, string $slug = 'luna'): TestResponse
{
    return $test->actingAs($user)->from(tenantUrl($slug, '/admin'))->post(tenantUrl($slug, '/admin/setup'.$path), [], ['X-Inertia' => 'true']);
}

/** A working primary payment connection on luna, the one Settings > Payments calls connected. */
function setupConnected(string $state = 'active', ?bool $checkOk = true): void
{
    DB::table('tenant_luna.payment_connections')->insert([
        'provider' => 'stripe', 'mode' => 'own', 'state' => $state, 'webhook_token' => 'hook-token-setup', 'is_primary' => true, 'last_check_ok' => $checkOk,
    ]);
}

/** A support session of luna open for an hour from the clock's now, as the console's open leaves it. */
function setupSupportSession(): int
{
    $now = app(Clock::class)->now();

    return DB::table('support_sessions')->insertGetId([
        'operator_id' => addOperator()->id,
        'tenant_id' => Tenant::query()->where('slug', 'luna')->value('id'),
        'opened_at' => SqlText::instant($now),
        'expires_at' => SqlText::instant($now->add(new DateInterval('PT60M'))),
        'created_at' => SqlText::instant($now),
    ]);
}

it('sends the seeded businesses\' owners no setup and no setup words, their setup being finished', function () {
    foreach (['luna', 'pier'] as $slug) {
        $this->actingAs(setupOwner($slug))->get(tenantUrl($slug, '/admin'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Home')->missing('setup')->missing('strings.setup'));
    }
});

it('hands an owner in setup the six steps with their facts, the automatic opening on the first open step, each step\'s data and the setup words', function () {
    setupOpen('luna');
    $members = DB::table('tenant_luna.staff')->where('status', 'active')->where('is_implicit', false)->orderBy('sort')->orderBy('id')->pluck('name')->all();
    $hours = ['mode' => 'members', 'week' => array_map(fn (int $weekday): array => ['weekday' => $weekday, 'start' => '09:00', 'end' => '17:00'], [1, 2, 3, 4, 5]), 'members' => $members, 'differs' => true, 'hourless' => false];
    $payments = data_get($this->actingAs(setupOwner())->get(tenantUrl('luna', '/admin/settings'))->viewData('page'), 'props.payments');
    // The Business profile's seven fields, its images and their limits and the countries as the
    // Settings screen holds them; the services and the members as the Services and Staff screens
    // list them, the built-in member left out.
    $settings = data_get($this->actingAs(setupOwner())->get(tenantUrl('luna', '/admin/settings'))->viewData('page'), 'props');
    $catalog = data_get($this->actingAs(setupOwner())->get(tenantUrl('luna', '/admin/services'))->viewData('page'), 'props.services');
    $staff = data_get($this->actingAs(setupOwner())->get(tenantUrl('luna', '/admin/staff'))->viewData('page'), 'props.staff');
    assert(is_array($settings) && is_array($settings['values']) && is_array($settings['limits']) && is_array($catalog) && is_array($staff));
    $profile = [
        'values' => array_intersect_key($settings['values'], array_flip(['about', 'address_line1', 'address_line2', 'city', 'postal_code', 'country', 'phone'])),
        'logo' => $settings['logo'],
        'cover' => $settings['cover'],
        'map' => $settings['map'],
        'limits' => ['logo' => $settings['limits']['logo'], 'cover' => $settings['limits']['cover']],
        'countries' => $settings['countries'],
    ];
    $services = [];
    $team = ['members' => [], 'services' => []];

    foreach ($catalog as $row) {
        assert(is_array($row));
        $services[] = array_intersect_key($row, array_flip(['id', 'name', 'duration_min', 'price_minor', 'currency']));
        $team['services'][] = array_intersect_key($row, array_flip(['id', 'name', 'status']));
    }

    foreach ($staff as $member) {
        assert(is_array($member));

        if ($member['is_implicit'] === false) {
            $team['members'][] = ['id' => $member['id'], 'name' => $member['name'], 'service_ids' => $member['service_ids']];
        }
    }

    $this->actingAs(setupOwner())->get(tenantUrl('luna', '/admin'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Home')
            ->where('strings.setup', __('admin/setup', [], 'en'))
            ->where('setup', [
                'steps' => [
                    ['key' => 'profile', 'done' => false, 'skipped' => false],
                    ['key' => 'services', 'done' => true, 'skipped' => false],
                    ['key' => 'team', 'done' => true, 'skipped' => false],
                    ['key' => 'hours', 'done' => false, 'skipped' => false],
                    ['key' => 'payments', 'done' => false, 'skipped' => false],
                    ['key' => 'link', 'done' => false, 'skipped' => false],
                ],
                'opened' => false,
                'opens' => 'profile',
                'data' => ['profile' => $profile, 'services' => ['services' => $services], 'team' => $team, 'hours' => $hours, 'payments' => $payments, 'link' => ['hosted_url' => hostedPage('luna')]],
            ]));
});

it('hands an owner without the staff feature five steps, with no Team', function () {
    setupOpen('pier');

    $setup = setupProp($this, setupOwner('pier'), 'pier');

    expect(array_column($setup['steps'] ?? [], 'key'))->toBe(['profile', 'services', 'hours', 'payments', 'link'])
        ->and(array_keys($setup['data'] ?? []))->toBe(['profile', 'services', 'hours', 'payments', 'link']);
});

it('sends no part of setup to a manager, a staff member or a support session, whatever the steps, their answers recording nothing as finished', function (bool $closed) {
    $closed ? setupEveryStepClosed() : setupOpen('luna');
    $manager = User::query()->findOrFail(addTenantUser('mira@luna.example', 'manager'));
    $staff = User::query()->findOrFail(addTenantUser('elena@luna.example', 'staff', staff: 'Elena Papadaki'));

    $this->actingAs($manager)->get(tenantUrl('luna', '/admin'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Home')->missing('setup')->missing('setup_finished')->missing('strings.setup'));
    $this->actingAs($staff)->get(tenantUrl('luna', '/admin/calendar'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->missing('setup')->missing('setup_finished')->missing('strings.setup'));
    $this->withSession([AdminSession::SUPPORT_KEY => setupSupportSession()])->get(tenantUrl('luna', '/admin'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('auth.user.role', 'support')->missing('setup')->missing('setup_finished')->missing('strings.setup'));

    expect(settingsOf('luna')->value('setup_finished_at'))->toBeNull();
})->with(['with steps still open' => [false], 'with every step closed' => [true]]);

it('hands a second owner the business\'s state, the opening spent by the first owner\'s wizard', function () {
    setupOpen('luna');
    $second = User::query()->findOrFail(addTenantUser('nikos@luna.example', 'owner'));

    setupWrite($this, setupOwner(), '/opened')->assertRedirect(tenantUrl('luna', '/admin'));

    expect(setupProp($this, $second))->toMatchArray(['opened' => true, 'opens' => null]);
});

it('offers nothing on the demo account, and records nothing as finished where its every step is closed', function () {
    setupOpen('luna');
    config(['site.demo_slug' => 'luna']);

    expect(setupProp($this, setupOwner()))->toBeNull();

    setupEveryStepClosed();
    expect(setupProp($this, setupOwner()))->toBeNull()
        ->and(settingsOf('luna')->value('setup_finished_at'))->toBeNull();
});

it('offers nothing while the subscription has lapsed or the account is closing, offers it again once the subscription is back, and records nothing as finished meanwhile', function () {
    setupOpen('luna');

    DB::table('tenants')->where('slug', 'luna')->update(['subscription_state' => 'past_due']);
    expect(setupProp($this, setupOwner()))->toBeNull();

    DB::table('tenants')->where('slug', 'luna')->update(['subscription_state' => 'active', 'status' => 'closing', 'closing_at' => '2026-11-01 06:00:00+00']);
    expect(setupProp($this, setupOwner()))->toBeNull();

    DB::table('tenants')->where('slug', 'luna')->update(['status' => 'active', 'closing_at' => null]);
    expect(setupProp($this, setupOwner()))->not->toBeNull()
        ->and(settingsOf('luna')->value('setup_finished_at'))->toBeNull();

    setupEveryStepClosed();
    DB::table('tenants')->where('slug', 'luna')->update(['subscription_state' => 'past_due']);
    expect(setupProp($this, setupOwner()))->toBeNull()
        ->and(settingsOf('luna')->value('setup_finished_at'))->toBeNull();

    DB::table('tenants')->where('slug', 'luna')->update(['subscription_state' => 'active', 'status' => 'closing', 'closing_at' => '2026-11-01 06:00:00+00']);
    expect(setupProp($this, setupOwner()))->toBeNull()
        ->and(settingsOf('luna')->value('setup_finished_at'))->toBeNull();
});

it('reads Business profile as done once saved from the wizard or once About, an address line, a city, a postal code or a phone is set, never by a logo, a cover or the default country alone', function (array $columns, bool $done) {
    setupOpen('luna');

    foreach ($columns as $column => $value) {
        settingsOf('luna')->update([(string) $column => $value]);
    }

    expect(setupClosed($this, setupOwner())['profile'])->toBe($done);
})->with([
    'nothing' => [[], false],
    'a logo and a cover' => [['brand_logo_path' => 'luna/logo.png', 'cover_path' => 'luna/cover.png'], false],
    'the country alone' => [['country' => 'CY'], false],
    'About' => [['about' => 'Haircuts since 1998.'], true],
    'a street line' => [['address_line1' => 'Ermou 12'], true],
    'a second line' => [['address_line2' => 'First floor'], true],
    'a city' => [['city' => 'Athens'], true],
    'a postal code' => [['postal_code' => '105 63'], true],
    'a phone' => [['phone' => '+30 210 000 0000'], true],
    'a save from the wizard' => [['setup_profile_saved_at' => '2026-11-01 06:00:00+00'], true],
]);

it('reads Services as done while a service exists in any status, and open again once none does', function () {
    setupOpen('luna');

    DB::table('tenant_luna.services')->update(['status' => 'inactive']);
    expect(setupClosed($this, setupOwner())['services'])->toBeTrue();

    DB::table('tenant_luna.service_staff')->delete();
    DB::table('tenant_luna.services')->delete();
    expect(setupClosed($this, setupOwner())['services'])->toBeFalse();
});

it('reads Team as done while a member beyond the built-in one exists, in any status, or once skipped', function () {
    setupOpen('luna');

    DB::table('tenant_luna.staff')->whereRaw('not is_implicit')->update(['status' => 'inactive']);
    expect(setupClosed($this, setupOwner())['team'])->toBeTrue();

    DB::table('tenant_luna.service_staff')->delete();
    WeeklyHours::clear('tenant_luna');
    DB::table('tenant_luna.staff')->whereRaw('not is_implicit')->delete();
    expect(setupClosed($this, setupOwner())['team'])->toBeFalse();

    setupWrite($this, setupOwner(), '/team/skip')->assertRedirect(tenantUrl('luna', '/admin'));
    expect(setupProp($this, setupOwner())['steps'][2] ?? null)->toBe(['key' => 'team', 'done' => false, 'skipped' => true]);
});

it('reads Opening hours as done once confirmed, and without the staff feature also while more than one member is active, where Settings shows the roster', function () {
    setupOpen('pier');
    expect(setupClosed($this, setupOwner('pier'), 'pier')['hours'])->toBeFalse();

    DB::table('tenant_pier.staff')->insert(['name' => 'Sam Physio', 'status' => 'active', 'sort' => 2, 'is_implicit' => false]);
    expect(setupClosed($this, setupOwner('pier'), 'pier')['hours'])->toBeTrue();

    DB::table('tenant_pier.staff')->where('name', 'Sam Physio')->update(['status' => 'inactive']);
    expect(setupClosed($this, setupOwner('pier'), 'pier')['hours'])->toBeFalse();

    settingsOf('pier')->update(['hours_confirmed_at' => DB::raw('now()')]);
    expect(setupClosed($this, setupOwner('pier'), 'pier')['hours'])->toBeTrue();
});

it('never reads luna\'s members as done hours, the staff feature giving the step one week for every member', function () {
    setupOpen('luna');

    expect(setupClosed($this, setupOwner())['hours'])->toBeFalse();
});

it('reads Online payments as done while the primary connection is working, or once skipped', function (string $state, ?bool $checkOk, bool $done) {
    setupOpen('luna');
    setupConnected($state, $checkOk);

    expect(setupClosed($this, setupOwner())['payments'])->toBe($done);
})->with([
    'active and checked' => ['active', true, true],
    'active and never checked' => ['active', null, true],
    'active and the last check failed' => ['active', false, false],
    'still onboarding' => ['restricted', true, false],
]);

it('records a skip of Online payments, which counts as closed', function () {
    setupOpen('luna');

    setupWrite($this, setupOwner(), '/payments/skip')->assertRedirect(tenantUrl('luna', '/admin'));

    expect(setupProp($this, setupOwner())['steps'][4] ?? null)->toBe(['key' => 'payments', 'done' => false, 'skipped' => true]);
});

it('reads Booking link as done once the link was copied or Finish was pressed, keeping the copied marker to its name', function () {
    setupOpen('luna');
    expect(setupClosed($this, setupOwner())['link'])->toBeFalse();

    setupWrite($this, setupOwner(), '/finish')->assertRedirect(tenantUrl('luna', '/admin'));
    expect(setupClosed($this, setupOwner())['link'])->toBeTrue()
        ->and(settingsOf('luna')->value('link_copied_at'))->toBeNull();

    settingsOf('luna')->update(['setup_link_finished_at' => null, 'link_copied_at' => DB::raw('now()')]);
    expect(setupClosed($this, setupOwner())['link'])->toBeTrue();
});

it('records setup as finished on the first answer finding every step closed, and never offers it again whatever changes', function () {
    setupOpen('luna');
    settingsOf('luna')->update(['about' => 'Haircuts since 1998.', 'hours_confirmed_at' => DB::raw('now()'), 'setup_payments_skipped_at' => DB::raw('now()')]);

    expect(setupProp($this, setupOwner()))->not->toBeNull()
        ->and(settingsOf('luna')->value('setup_finished_at'))->toBeNull();

    setupWrite($this, setupOwner(), '/finish');
    expect(setupProp($this, setupOwner()))->toBeNull()
        ->and(settingsOf('luna')->value('setup_finished_at'))->not->toBeNull();

    DB::table('tenant_luna.service_staff')->delete();
    DB::table('tenant_luna.services')->delete();
    settingsOf('luna')->update(['about' => null]);
    expect(setupProp($this, setupOwner()))->toBeNull();
});

it('says setup was finished just now on the one answer that recorded it, and on no answer before or after', function () {
    setupOpen('luna');
    settingsOf('luna')->update(['about' => 'Haircuts since 1998.', 'hours_confirmed_at' => DB::raw('now()'), 'setup_payments_skipped_at' => DB::raw('now()')]);
    $home = fn (): TestResponse => $this->actingAs(setupOwner())->get(tenantUrl('luna', '/admin'))->assertOk();

    $home()->assertInertia(fn (Assert $page) => $page->has('setup')->missing('setup_finished'));

    setupWrite($this, setupOwner(), '/finish');
    $home()->assertInertia(fn (Assert $page) => $page->where('setup_finished', true)->missing('setup')->missing('strings.setup'));
    expect(settingsOf('luna')->value('setup_finished_at'))->not->toBeNull();

    $home()->assertInertia(fn (Assert $page) => $page->missing('setup_finished')->missing('setup'));
});

it('opens by itself on the first open step until the wizard is recorded as having come up, and never again after', function () {
    setupOpen('luna');
    settingsOf('luna')->update(['about' => 'Haircuts since 1998.']);

    expect(setupProp($this, setupOwner()))->toMatchArray(['opened' => false, 'opens' => 'hours'])
        ->and(setupProp($this, setupOwner()))->toMatchArray(['opened' => false, 'opens' => 'hours']);

    setupWrite($this, setupOwner(), '/opened')->assertRedirect(tenantUrl('luna', '/admin'))->assertSessionMissing('flash');
    expect(setupProp($this, setupOwner()))->toMatchArray(['opened' => true, 'opens' => null]);
});

it('opens on the step a flash names when it applies, whether or not the wizard came up before', function () {
    setupOpen('luna');
    settingsOf('luna')->update(['setup_opened_at' => DB::raw('now()')]);

    $named = fn (string $step): mixed => data_get($this->actingAs(setupOwner())->withSession([SetupWizard::OPEN_ON => $step])->get(tenantUrl('luna', '/admin'))->viewData('page'), 'props.setup.opens');

    expect($named('payments'))->toBe('payments')
        ->and($named('nothing'))->toBeNull();

    setupOpen('pier');
    $pier = data_get($this->actingAs(setupOwner('pier'))->withSession([SetupWizard::OPEN_ON => 'team'])->get(tenantUrl('pier', '/admin'))->viewData('page'), 'props.setup.opens');
    expect($pier)->toBe('profile');
});

it('stamps each of the wizard\'s facts once, a second press moving nothing, and answers a redirect back with no flash', function (string $path, string $column) {
    setupOpen('luna');

    setupWrite($this, setupOwner(), $path)->assertRedirect(tenantUrl('luna', '/admin'))->assertSessionMissing('flash');
    expect(settingsOf('luna')->where($column, DB::raw('now()'))->exists())->toBeTrue();

    // The test's transaction holds now() still, so the first stamp is moved back to see a second land.
    settingsOf('luna')->update([$column => '2020-01-01 00:00:00+00']);
    setupWrite($this, setupOwner(), $path)->assertRedirect(tenantUrl('luna', '/admin'));
    expect(settingsOf('luna')->where($column, '2020-01-01 00:00:00+00')->exists())->toBeTrue();
})->with([
    'the automatic opening' => ['/opened', 'setup_opened_at'],
    'Team skipped' => ['/team/skip', 'setup_team_skipped_at'],
    'Online payments skipped' => ['/payments/skip', 'setup_payments_skipped_at'],
    'Finish' => ['/finish', 'setup_link_finished_at'],
]);

it('refuses the wizard\'s writes to a manager, a staff member and a support session, nothing recorded', function () {
    setupOpen('luna');
    $manager = User::query()->findOrFail(addTenantUser('mira@luna.example', 'manager'));
    $staff = User::query()->findOrFail(addTenantUser('elena@luna.example', 'staff', staff: 'Elena Papadaki'));
    $paths = ['/opened', '/team/skip', '/payments/skip', '/finish'];

    foreach ($paths as $path) {
        setupWrite($this, $manager, $path)->assertForbidden();
        setupWrite($this, $staff, $path)->assertForbidden();
    }

    $support = setupSupportSession();

    foreach ($paths as $path) {
        $this->withSession([AdminSession::SUPPORT_KEY => $support])->from(tenantUrl('luna', '/admin'))
            ->post(tenantUrl('luna', '/admin/setup'.$path), [], ['X-Inertia' => 'true'])
            ->assertForbidden();
    }

    $columns = ['setup_opened_at', 'setup_team_skipped_at', 'setup_payments_skipped_at', 'setup_link_finished_at'];

    expect((array) settingsOf('luna')->first($columns))->toBe(array_fill_keys($columns, null));
});

it('records the wizard\'s writes on a lapsed subscription, and has no skip for any step but Team and Online payments, nothing recorded', function () {
    setupOpen('luna');

    DB::table('tenants')->where('slug', 'luna')->update(['subscription_state' => 'past_due']);
    setupWrite($this, setupOwner(), '/opened')->assertRedirect(tenantUrl('luna', '/admin'));
    expect(settingsOf('luna')->value('setup_opened_at'))->not->toBeNull();

    // No route takes the post, and the admin's catch-all for a page that is not there reads only.
    foreach (['profile', 'services', 'hours', 'link'] as $step) {
        setupWrite($this, setupOwner(), '/'.$step.'/skip')->assertMethodNotAllowed();
    }

    $columns = ['setup_profile_saved_at', 'hours_confirmed_at', 'setup_team_skipped_at', 'setup_payments_skipped_at', 'setup_link_finished_at', 'link_copied_at'];

    expect((array) settingsOf('luna')->first($columns))->toBe(array_fill_keys($columns, null));
});

it('answers a skip of Team with not found on an account without the staff feature, the step not being its own, and records nothing', function () {
    setupOpen('pier');

    setupWrite($this, setupOwner('pier'), '/team/skip', 'pier')->assertNotFound();
    expect(settingsOf('pier')->value('setup_team_skipped_at'))->toBeNull();

    setupWrite($this, setupOwner('pier'), '/payments/skip', 'pier')->assertRedirect(tenantUrl('pier', '/admin'));
    expect(settingsOf('pier')->value('setup_payments_skipped_at'))->not->toBeNull();
});
