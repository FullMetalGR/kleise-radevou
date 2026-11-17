<?php

use App\Application\Clock;
use App\Http\AdminSession;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\StaffServicesController;
use App\Http\CountryNames;
use App\Infrastructure\Db\SqlText;
use App\Jobs\PrepareMap;
use App\Models\User;
use App\Tenancy\Slug;
use App\Tenancy\Tenant;
use App\Tenancy\TenantUrls;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FixtureDay;
use Tests\Support\WeeklyHours;
use Tests\TestCase;

pest()->use(RefreshDatabase::class);

/*
 * The setup wizard's Business profile, Services and Team steps on the server: the data each
 * step's panel is handed inside Home's setup prop, the words it borrows, and its write. Each
 * write is the full screen's own under the owner's permission and the subscription gate, with
 * the screen's rules and refusals, and answers with a redirect back to Home, never to the new
 * row's page.
 *
 * The seeded businesses have finished setup, so each test opens it again on luna, which holds the
 * staff feature, services and three members beyond its built-in one, or on pier, which holds no
 * feature.
 */

beforeEach(function () {
    $this->withoutVite();
    seedDemo();
    FixtureDay::pinClock();
});

/** The profile step's body: About, the address and Phone, each given or blank. */
const STEPS_PROFILE = [
    'about' => 'A small studio by the sea.', 'address_line1' => 'Ermou 12', 'address_line2' => '', 'city' => 'Athens',
    'postal_code' => '10563', 'country' => 'GR', 'phone' => '+30 210 000 0000',
];

/** Opens setup again on a seeded business, every setup fact unset. */
function stepsOpen(string $slug = 'luna'): void
{
    settingsOf($slug)->update([
        'setup_opened_at' => null, 'setup_finished_at' => null, 'setup_profile_saved_at' => null,
        'setup_team_skipped_at' => null, 'setup_payments_skipped_at' => null, 'setup_link_finished_at' => null,
        'hours_confirmed_at' => null, 'link_copied_at' => null,
    ]);
}

function stepsOwner(string $slug = 'luna'): User
{
    return User::query()->where('email', sprintf('owner@%s.example', $slug))->sole();
}

/**
 * A step's write as the wizard sends it, from Home.
 *
 * @param  array<string, mixed>  $body
 * @return TestResponse<Response>
 */
function stepsWrite(TestCase $test, User $user, string $method, string $path, array $body, string $slug = 'luna'): TestResponse
{
    return $test->actingAs($user)->from(tenantUrl($slug, '/admin'))->call($method, tenantUrl($slug, '/admin/setup'.$path), $body, [], [], ['HTTP_X_INERTIA' => 'true', 'HTTP_ACCEPT' => 'application/json']);
}

/**
 * Home's setup data for one step, as the owner reads it.
 *
 * @return array<string, mixed>|null
 */
function stepsData(TestCase $test, string $step, string $slug = 'luna'): ?array
{
    $data = data_get($test->actingAs(stepsOwner($slug))->get(tenantUrl($slug, '/admin'))->assertOk()->viewData('page'), 'props.setup.data.'.$step);
    assert($data === null || is_array($data));

    /** @var array<string, mixed>|null $data */
    return $data;
}

/**
 * Whether the step reads as done on Home.
 */
function stepsDone(TestCase $test, string $step, string $slug = 'luna'): bool
{
    $steps = data_get($test->actingAs(stepsOwner($slug))->get(tenantUrl($slug, '/admin'))->assertOk()->viewData('page'), 'props.setup.steps');
    assert(is_array($steps));

    foreach ($steps as $fact) {
        if (is_array($fact) && ($fact['key'] ?? null) === $step) {
            return $fact['done'] === true;
        }
    }

    throw new LogicException(sprintf('No %s step on Home.', $step));
}

/**
 * A new service as the Services step sends one: the name, the duration and the price it asks for,
 * every other field the Services screen's blank value, and the business's currency.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function stepsService(array $overrides = []): array
{
    return [
        'name' => 'Beard trim', 'description' => '', 'duration_min' => 20, 'processing_gap_min' => 0, 'buffer_before_min' => 0,
        'buffer_after_min' => 0, 'price_minor' => 1250, 'payment_mode' => 'onsite', 'requires_approval' => false,
        'approval_hold_hours' => null, 'on_approval_timeout' => 'expire', 'cancel_window_hours' => 24, 'reschedule_window_hours' => 24,
        'lead_time_min' => 0, 'horizon_days' => 60, 'category' => null, 'online' => true, 'currency' => 'EUR', ...$overrides,
    ];
}

/**
 * A new member as the Team step sends one: the name and the services picked, every other field
 * the Staff screen's blank value.
 *
 * @param  list<int>  $serviceIds
 * @return array<string, mixed>
 */
function stepsMember(string $name, array $serviceIds = []): array
{
    return ['name' => $name, 'email' => null, 'user_id' => null, 'status' => 'active', 'online' => true, 'title' => null, 'bio' => null, 'service_ids' => $serviceIds];
}

/**
 * A row's columns by name, so two rows compare whatever order the table holds them in.
 *
 * @param  array<string, mixed>  $row
 * @return array<string, mixed>
 */
function stepsSorted(array $row): array
{
    ksort($row);

    return $row;
}

/** A support session of luna open for an hour from the clock's now. */
function stepsSupportSession(): int
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

it('borrows the Settings, Services and Staff sections beside setup\'s own while the wizard is offered', function () {
    stepsOpen();

    $this->actingAs(stepsOwner())->get(tenantUrl('luna', '/admin'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Home')
            ->where('strings.settings', __('admin/settings', [], 'en'))
            ->where('strings.services', __('admin/services', [], 'en'))
            ->where('strings.staff', __('admin/staff', [], 'en')));
});

it('borrows no section once setup is finished, and no Staff section in setup where the plan holds no staff feature', function () {
    $this->actingAs(stepsOwner())->get(tenantUrl('luna', '/admin'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Home')
            ->missing('setup')
            ->missing('strings.settings')
            ->missing('strings.services')
            ->missing('strings.staff'));

    stepsOpen('pier');

    $this->actingAs(stepsOwner('pier'))->get(tenantUrl('pier', '/admin'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Home')
            ->has('setup')
            ->where('strings.settings', __('admin/settings', [], 'en'))
            ->where('strings.services', __('admin/services', [], 'en'))
            ->missing('strings.staff'));
});

it('hands the Business profile step its fields\' stored values, the logo, the cover, the map, the two images\' sizes and the countries', function () {
    stepsOpen();
    settingsOf('luna')->update([...STEPS_PROFILE, 'address_line2' => null, 'public_email' => 'hello@luna.example', 'brand_logo_path' => 'luna/logo/mark.png', 'cover_path' => null, 'map_state' => 'pending']);
    $limits = SettingsController::limits();

    expect(stepsData($this, 'profile'))->toBe([
        'values' => [...STEPS_PROFILE, 'address_line2' => null],
        'logo' => ['name' => 'mark.png', 'url' => app(TenantUrls::class)->logo(Slug::of('luna'), 'mark.png')],
        'cover' => null,
        'map' => ['state' => 'pending', 'image' => null],
        'limits' => ['logo' => $limits['logo'], 'cover' => $limits['cover']],
        'countries' => app(CountryNames::class)->in(),
    ]);
});

it('saves About, the address and Phone alone, every other setting and the tenant row\'s currency left as they were, and marks the step done', function () {
    stepsOpen();
    settingsOf('luna')->update(['public_email' => 'hello@luna.example', 'website' => 'https://luna.example', 'important_info' => 'Bring a towel.', 'theme' => 'green', 'reminder_lead_hours' => 12]);
    $before = (array) settingsOf('luna')->first();
    $currency = DB::table('tenants')->where('slug', 'luna')->value('currency');

    stepsWrite($this, stepsOwner(), 'PUT', '/profile', STEPS_PROFILE)
        ->assertRedirect(tenantUrl('luna', '/admin'))
        ->assertSessionHas('flash', ['kind' => 'success', 'message' => adminLine(SettingsController::SAVED)]);

    $after = (array) settingsOf('luna')->first();
    $moved = ['setup_profile_saved_at', 'updated_at', 'map_state', 'lat', 'lng', 'map_path'];
    expect(stepsSorted(array_intersect_key($after, STEPS_PROFILE)))->toBe(stepsSorted([...STEPS_PROFILE, 'address_line2' => null]))
        ->and(array_diff_key($after, STEPS_PROFILE, array_flip($moved)))->toBe(array_diff_key($before, STEPS_PROFILE, array_flip($moved)))
        ->and($after['setup_profile_saved_at'])->not->toBeNull()
        ->and(DB::table('tenants')->where('slug', 'luna')->value('currency'))->toBe($currency)
        ->and(stepsDone($this, 'profile'))->toBeTrue();
});

it('marks the step done on an empty save, every field landing as null', function () {
    stepsOpen();
    settingsOf('luna')->update([...STEPS_PROFILE]);

    stepsWrite($this, stepsOwner(), 'PUT', '/profile', array_fill_keys(array_keys(STEPS_PROFILE), ''))->assertRedirect(tenantUrl('luna', '/admin'));

    expect(stepsSorted(array_intersect_key((array) settingsOf('luna')->first(), STEPS_PROFILE)))->toBe(stepsSorted(array_fill_keys(array_keys(STEPS_PROFILE), null)))
        ->and(stepsDone($this, 'profile'))->toBeTrue();
});

it('prepares the map when the save changes the address, as the Settings save does, and leaves it when the address stands', function () {
    config(['services.google.maps_key' => 'maps-key-for-tests']);
    Queue::fake();
    stepsOpen();
    settingsOf('luna')->update(['address_line1' => null, 'address_line2' => null, 'city' => null, 'postal_code' => null, 'country' => null, 'map_state' => 'none']);

    stepsWrite($this, stepsOwner(), 'PUT', '/profile', STEPS_PROFILE)->assertRedirect(tenantUrl('luna', '/admin'));

    expect(settingsOf('luna')->value('map_state'))->toBe('pending');
    Queue::assertPushed(PrepareMap::class, fn (PrepareMap $job): bool => $job->tenantId === Tenant::query()->where('slug', 'luna')->value('id'));

    stepsWrite($this, stepsOwner(), 'PUT', '/profile', [...STEPS_PROFILE, 'about' => 'Another line.'])->assertRedirect(tenantUrl('luna', '/admin'));
    Queue::assertPushed(PrepareMap::class, 1);
});

it('refuses a profile field with the Settings save\'s own sentence at 400, naming the field, and writes nothing', function (string $field, string $value, string $key, array $values) {
    /** @var array<string, string> $values */
    stepsOpen();
    $before = (array) settingsOf('luna')->first();

    stepsWrite($this, stepsOwner(), 'PUT', '/profile', [...STEPS_PROFILE, $field => $value])
        ->assertStatus(400)->assertJsonPath('data.field', $field)->assertJsonPath('data.detail', adminLine($key, $values));

    expect((array) settingsOf('luna')->first())->toBe($before);
})->with([
    'About too long' => ['about', str_repeat('a', 2001), 'admin/settings.validation.about', ['max' => '2000']],
    'a lowercase country' => ['country', 'gr', 'admin/settings.validation.country', []],
    'a phone too long' => ['phone', str_repeat('1', 41), 'admin/settings.validation.phone', ['max' => '40']],
]);

it('refuses a profile body missing a field, and a field it does not ask for is never written', function () {
    stepsOpen();
    $body = STEPS_PROFILE;
    unset($body['phone']);

    stepsWrite($this, stepsOwner(), 'PUT', '/profile', $body)->assertStatus(400)->assertJsonPath('data.field', 'phone');

    // What the Settings save and the two image uploads write, each named here as they name it and by its column.
    $kept = [
        'public_email' => 'hello@luna.example', 'website' => 'https://luna.example', 'theme' => 'green', 'reminder_lead_hours' => 12,
        'brand_logo_path' => 'luna/logo/mark.png', 'cover_path' => 'luna/cover/front.jpg',
    ];
    settingsOf('luna')->update($kept);
    $tenant = (array) DB::table('tenants')->where('slug', 'luna')->first(['currency', 'timezone']);

    stepsWrite($this, stepsOwner(), 'PUT', '/profile', [
        ...STEPS_PROFILE, 'public_email' => 'other@luna.example', 'website' => 'https://other.example', 'theme' => 'red', 'reminder_lead_hours' => 48,
        'brand_logo_path' => 'luna/logo/other.png', 'cover_path' => 'luna/cover/other.jpg', 'logo' => 'other.png', 'cover' => 'other.jpg',
        'currency' => 'USD', 'timezone' => 'America/New_York',
    ])->assertRedirect(tenantUrl('luna', '/admin'));

    expect(stepsSorted(array_intersect_key((array) settingsOf('luna')->first(), $kept)))->toBe(stepsSorted($kept))
        ->and($tenant)->toBe(['currency' => 'EUR', 'timezone' => 'Europe/Athens'])
        ->and((array) DB::table('tenants')->where('slug', 'luna')->first(['currency', 'timezone']))->toBe($tenant)
        ->and(settingsOf('luna')->value('about'))->toBe(STEPS_PROFILE['about']);
});

it('leaves the Business profile step\'s stamp where it stood on a later save', function () {
    stepsOpen();

    stepsWrite($this, stepsOwner(), 'PUT', '/profile', STEPS_PROFILE)->assertRedirect(tenantUrl('luna', '/admin'));
    $first = settingsOf('luna')->value('setup_profile_saved_at');
    expect($first)->not->toBeNull();

    // Every write of one test reads the same instant from the database, so the stamp is moved back
    // by hand: a save that stamped again would bring it forward.
    settingsOf('luna')->update(['setup_profile_saved_at' => '2026-01-05 09:00:00+00']);
    $stood = settingsOf('luna')->value('setup_profile_saved_at');
    expect($stood)->not->toBe($first);

    stepsWrite($this, stepsOwner(), 'PUT', '/profile', [...STEPS_PROFILE, 'about' => 'Another line.'])->assertRedirect(tenantUrl('luna', '/admin'));

    expect(settingsOf('luna')->value('setup_profile_saved_at'))->toBe($stood)
        ->and(settingsOf('luna')->value('about'))->toBe('Another line.')
        ->and(stepsDone($this, 'profile'))->toBeTrue();
});

it('hands the Services step every service in the catalog\'s order with its duration and its price in its own currency', function () {
    stepsOpen();
    $rows = DB::table('tenant_luna.services')->orderBy('sort')->orderBy('id')->get(['id', 'name', 'duration_min', 'price_minor', 'currency'])->map(fn (object $row): array => (array) $row)->all();

    expect(stepsData($this, 'services'))->toBe(['services' => $rows])
        ->and($rows)->not->toBe([]);
});

it('adds a service with the Services screen\'s blank values, back on Home with its flash and never on its page, and the step reads done', function () {
    stepsOpen();
    DB::table('tenant_luna.service_staff')->delete();
    DB::table('tenant_luna.services')->delete();
    expect(stepsDone($this, 'services'))->toBeFalse();

    stepsWrite($this, stepsOwner(), 'POST', '/services', stepsService())
        ->assertRedirect(tenantUrl('luna', '/admin'))
        ->assertSessionHas('flash', ['kind' => 'success', 'message' => adminLine('admin/services.flashes.created')]);

    expect(DB::table('tenant_luna.services')->first(['name', 'duration_min', 'price_minor', 'currency', 'status', 'payment_mode', 'online', 'horizon_days']))
        ->toEqual((object) ['name' => 'Beard trim', 'duration_min' => 20, 'price_minor' => 1250, 'currency' => 'EUR', 'status' => 'active', 'payment_mode' => 'onsite', 'online' => true, 'horizon_days' => 60])
        ->and(stepsDone($this, 'services'))->toBeTrue();
});

it('links a service added from the step to the built-in member where they are the only staff row, as the Services screen does', function () {
    settingsOf('pier')->update(['setup_finished_at' => null]);
    $implicit = DB::table('tenant_pier.staff')->where('is_implicit', true)->value('id');

    stepsWrite($this, stepsOwner('pier'), 'POST', '/services', stepsService(['currency' => 'GBP']), 'pier')->assertRedirect(tenantUrl('pier', '/admin'));

    $id = DB::table('tenant_pier.services')->where('name', 'Beard trim')->value('id');
    expect(DB::table('tenant_pier.service_staff')->where('service_id', $id)->pluck('staff_id')->all())->toBe([$implicit]);
});

it('refuses a service off the granularity grid or with no name with the Services screen\'s own sentence at 400, naming the field, and writes nothing', function (array $overrides, string $field, string $key, array $values) {
    /** @var array<string, mixed> $overrides */
    /** @var array<string, string> $values */
    stepsOpen();
    $count = DB::table('tenant_luna.services')->count();

    stepsWrite($this, stepsOwner(), 'POST', '/services', stepsService($overrides))
        ->assertStatus(400)->assertJsonPath('data.field', $field)->assertJsonPath('data.detail', adminLine($key, $values));

    expect(DB::table('tenant_luna.services')->count())->toBe($count);
})->with([
    'duration off the grid' => [['duration_min' => 47], 'duration_min', 'admin/services.validation.duration_grid', ['n' => '5']],
    'blank name' => [['name' => '   '], 'name', 'admin/services.validation.name', []],
]);

it('hands the Team step every member but the built-in one, with the services each performs, and every service to pick from', function () {
    stepsOpen();
    $members = DB::table('tenant_luna.staff')->where('is_implicit', false)->orderBy('sort')->orderBy('id')->get(['id', 'name'])->map(fn (object $row): array => [
        'id' => $row->id, 'name' => $row->name, 'service_ids' => DB::table('tenant_luna.service_staff')->where('staff_id', $row->id)->orderBy('service_id')->pluck('service_id')->all(),
    ])->all();
    $services = DB::table('tenant_luna.services')->orderBy('sort')->orderBy('id')->get(['id', 'name', 'status'])->map(fn (object $row): array => (array) $row)->all();

    expect(stepsData($this, 'team'))->toBe(['members' => $members, 'services' => $services])
        ->and($members)->not->toBe([]);
});

it('adds a member with the services picked, back on Home with the Staff screen\'s flash and never on their page, and the step reads done', function () {
    stepsOpen();
    $ids = [];

    foreach (DB::table('tenant_luna.services')->orderBy('id')->limit(2)->pluck('id') as $id) {
        assert(is_int($id));
        $ids[] = $id;
    }

    stepsWrite($this, stepsOwner(), 'POST', '/team', stepsMember('Nikos Daskalakis', $ids))
        ->assertRedirect(tenantUrl('luna', '/admin'))
        ->assertSessionHas('flash', ['kind' => 'success', 'message' => adminLine('admin/staff.flashes.created')]);

    $member = DB::table('tenant_luna.staff')->where('name', 'Nikos Daskalakis')->sole();
    expect([$member->status, $member->online, $member->is_implicit])->toBe(['active', true, false])
        ->and(DB::table('tenant_luna.service_staff')->where('staff_id', $member->id)->orderBy('service_id')->pluck('service_id')->all())->toBe($ids);

    stepsWrite($this, stepsOwner(), 'POST', '/team', stepsMember('Eleni Markou'))->assertRedirect(tenantUrl('luna', '/admin'));
    expect(DB::table('tenant_luna.service_staff')->where('staff_id', DB::table('tenant_luna.staff')->where('name', 'Eleni Markou')->value('id'))->count())->toBe(0);
});

it('reads Team as done once a member is added from the step on a business with the built-in member alone', function () {
    stepsOpen();
    DB::table('tenant_luna.service_staff')->delete();
    WeeklyHours::clear('tenant_luna');
    DB::table('tenant_luna.staff')->whereRaw('not is_implicit')->delete();
    expect(stepsDone($this, 'team'))->toBeFalse();

    stepsWrite($this, stepsOwner(), 'POST', '/team', stepsMember('Nikos Daskalakis'))->assertRedirect(tenantUrl('luna', '/admin'));

    expect(stepsDone($this, 'team'))->toBeTrue();
});

it('refuses a member with no name or a service the business lacks with the Staff screen\'s own refusal, naming the field, and writes nothing', function () {
    stepsOpen();
    $count = DB::table('tenant_luna.staff')->count();

    stepsWrite($this, stepsOwner(), 'POST', '/team', stepsMember(''))
        ->assertStatus(400)->assertJsonPath('data.field', 'name')->assertJsonPath('data.detail', adminLine('admin/staff.validation.name'));
    stepsWrite($this, stepsOwner(), 'POST', '/team', stepsMember('Nikos Daskalakis', [999999]))
        ->assertStatus(400)->assertJsonPath('data.field', 'service_ids')->assertJsonPath('data.detail', adminLine(StaffServicesController::UNKNOWN_SERVICE));

    expect(DB::table('tenant_luna.staff')->count())->toBe($count);
});

it('answers the Team step\'s add 404 where the plan holds no staff feature, before reading the body', function () {
    settingsOf('pier')->update(['setup_finished_at' => null]);
    $count = DB::table('tenant_pier.staff')->count();

    stepsWrite($this, stepsOwner('pier'), 'POST', '/team', stepsMember('Nikos Daskalakis'), 'pier')->assertNotFound();
    stepsWrite($this, stepsOwner('pier'), 'POST', '/team', [], 'pier')->assertNotFound();

    expect(DB::table('tenant_pier.staff')->count())->toBe($count);
});

it('refuses the three steps\' writes to a manager, a staff member and a support session, and to the owner while the subscription has lapsed, writing nothing', function () {
    stepsOpen();
    $manager = User::query()->findOrFail(addTenantUser('mira@luna.example', 'manager'));
    $staff = User::query()->findOrFail(addTenantUser('elena@luna.example', 'staff', staff: 'Elena Papadaki'));
    $writes = [['PUT', '/profile', STEPS_PROFILE], ['POST', '/services', stepsService()], ['POST', '/team', stepsMember('Nikos Daskalakis')]];
    $counts = fn (): array => [settingsOf('luna')->value('about'), DB::table('tenant_luna.services')->count(), DB::table('tenant_luna.staff')->count()];
    $before = $counts();

    foreach ($writes as [$method, $path, $body]) {
        stepsWrite($this, $manager, $method, $path, $body)->assertForbidden();
        stepsWrite($this, $staff, $method, $path, $body)->assertForbidden();
    }

    DB::table('tenants')->where('slug', 'luna')->update(['subscription_state' => 'past_due']);

    foreach ($writes as [$method, $path, $body]) {
        stepsWrite($this, stepsOwner(), $method, $path, $body)->assertForbidden()->assertJsonPath('code', 'subscription_required');
    }

    DB::table('tenants')->where('slug', 'luna')->update(['subscription_state' => 'active']);
    $this->withSession([AdminSession::SUPPORT_KEY => stepsSupportSession()]);

    foreach ($writes as [$method, $path, $body]) {
        $this->call($method, tenantUrl('luna', '/admin/setup'.$path), $body, [], [], ['HTTP_X_INERTIA' => 'true', 'HTTP_ACCEPT' => 'application/json'])->assertForbidden();
    }

    expect($counts())->toBe($before);
});
