<?php

use App\Application\Clock;
use App\Console\Commands\ProvisionTenantCommand;
use App\Infrastructure\Db\SettingsReader;
use App\Infrastructure\Db\WeeklyPattern;
use App\Models\User;
use App\Tenancy\InvalidSlug;
use App\Tenancy\ProvisioningRefused;
use App\Tenancy\Role;
use App\Tenancy\SlugProblem;
use App\Tenancy\Tenant;
use App\Tenancy\TenantProvisioner;
use App\Tenancy\TenantStatus;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\MigrationEnded;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Tests\Support\FixedClock;
use Tests\Support\WeeklyHours;

pest()->use(RefreshDatabase::class);

function schemaExists(string $schema): bool
{
    return DB::table('pg_namespace')->where('nspname', $schema)->exists();
}

/**
 * @return list<string>
 */
function tenantSchemas(): array
{
    /** @var list<string> */
    return DB::table('pg_namespace')->where('nspname', 'like', 'tenant\_%')->orderBy('nspname')->pluck('nspname')->all();
}

/**
 * Runs the console command and returns its exit code and everything it printed.
 *
 * @param  array<string, mixed>  $input
 * @return array{int, string}
 */
function provisionFromConsole(array $input): array
{
    $code = Artisan::call('tenant:provision', $input);

    return [$code, Artisan::output()];
}

/**
 * @return array<string, mixed>
 */
function commandInput(string $slug): array
{
    return [
        'slug' => $slug,
        '--name' => 'Acme Salon',
        '--timezone' => 'Europe/Athens',
        '--currency' => 'EUR',
        '--owner-name' => 'Alex Owner',
        '--owner-email' => 'owner@example.com',
        '--owner-password' => 'correct horse battery staple',
    ];
}

it('creates the central row active, on the floor, with the details given', function () {
    $tenant = provisionTenant('Acme')->tenant;

    expect($tenant->status)->toBe(TenantStatus::Active)
        ->and($tenant->entitlements)->toBe([])
        ->and($tenant->slug)->toBe('acme')
        ->and($tenant->schema_name)->toBe('tenant_acme')
        ->and($tenant->name)->toBe('Acme Salon')
        ->and($tenant->timezone)->toBe('Europe/Athens')
        ->and($tenant->currency)->toBe('EUR')
        ->and($tenant->fresh()?->status)->toBe(TenantStatus::Active);
});

it('creates the schema named from the slug and runs every tenant migration inside it, once', function () {
    provisionTenant('acme');

    expect(schemaExists('tenant_acme'))->toBeTrue()
        ->and(DB::table('tenant_acme.migrations')->orderBy('id')->pluck('batch', 'migration')->all())->toBe([
            '0001_01_01_000000_create_settings_table' => 1,
            '0001_01_01_000001_create_staff_table' => 1,
            '0001_01_01_000002_create_staff_days_table' => 1,
            '0001_01_01_000003_create_bookings_table' => 1,
            '0001_01_01_000004_create_booking_items_table' => 1,
            '0001_01_01_000005_create_services_table' => 1,
            '0001_01_01_000006_create_service_staff_table' => 1,
            '0001_01_01_000007_create_availability_rules_table' => 1,
            '0001_01_01_000008_create_blackouts_table' => 1,
            '0001_01_01_000009_add_on_approval_timeout_to_bookings' => 1,
            '0001_01_01_000010_create_audit_log_table' => 1,
            '0001_01_01_000011_allow_a_nulled_manage_token_hash' => 1,
            '0001_01_01_000012_add_booking_items_service_foreign_key' => 1,
            '0001_01_01_000013_add_booking_items_staff_foreign_key' => 1,
            '0001_01_01_000014_add_start_interval_to_settings' => 1,
            '0001_01_01_000015_create_payment_connections_table' => 1,
            '0001_01_01_000016_add_manage_token_sealed_to_bookings' => 1,
            '0001_01_01_000017_add_setup_markers_to_settings' => 1,
            '0001_01_01_000018_add_profile_to_settings' => 1,
            '0001_01_01_000019_add_cover_and_gallery_to_settings' => 1,
            '0001_01_01_000020_add_map_to_settings' => 1,
            '0001_01_01_000021_add_category_and_online_to_services_and_staff' => 1,
            '0001_01_01_000022_add_photo_title_and_bio_to_staff' => 1,
            '0001_01_01_000023_add_source_and_channel_to_bookings' => 1,
            '0001_01_01_000024_create_booking_meta_table' => 1,
            '0001_01_01_000025_create_customer_links_and_sessions_tables' => 1,
            '0001_01_01_000026_add_support_actor_to_audit_log' => 1,
            '0001_01_01_000027_bound_payment_ttl_and_horizon_and_link_the_implicit_member' => 1,
            '0001_01_01_000028_allow_an_erased_customer_on_bookings' => 1,
            '0001_01_01_000029_add_language_to_settings_and_bookings' => 1,
            '0001_01_01_000030_create_shift_patterns_and_shift_overrides' => 1,
            '0001_01_01_000031_add_colour_by_to_settings' => 1,
            '0001_01_01_000032_drop_availability_rules' => 1,
            '0001_01_01_000033_add_theme_to_settings' => 1,
            '0001_01_01_000034_add_colour_to_staff' => 1,
            '0001_01_01_000035_create_customer_profiles_and_notes' => 1,
            '0001_01_01_000040_drop_brand_accent_from_settings' => 1,
            '0001_01_01_000041_add_created_at_index_to_audit_log' => 1,
        ])
        ->and(DB::table('migrations')->where('migration', 'like', '%settings%')->exists())->toBeFalse();
});

it('stores a new tenant\'s start interval as 15, the column default, and reads the row\'s value through the settings reader', function () {
    $tenant = provisionTenant('acme')->tenant;

    expect(DB::table('tenant_acme.settings')->where('id', 1)->value('start_interval_min'))->toBe(15)
        ->and(app(SettingsReader::class)->read($tenant)->startIntervalMin)->toBe(15);

    // The reader passes the column rather than leaving the constructor's own default of 15 to
    // stand in for it, which would read 15 here whatever the row held.
    DB::table('tenant_acme.settings')->where('id', 1)->update(['start_interval_min' => 30]);

    expect(app(SettingsReader::class)->read($tenant)->startIntervalMin)->toBe(30);
});

it('leaves both setup markers unset on a new tenant, the default week seeded by direct insert and stamping nothing', function () {
    $tenant = provisionTenant('acme')->tenant;
    $settings = app(SettingsReader::class)->read($tenant);

    expect(DB::table('tenant_acme.settings')->where('id', 1)->first(['hours_confirmed_at', 'link_copied_at']))->toEqual((object) ['hours_confirmed_at' => null, 'link_copied_at' => null])
        ->and([$settings->hoursConfirmed, $settings->linkCopied])->toBe([false, false]);
});

it('creates the implicit staff member named from the tenant, linked to no user, active', function () {
    provisionTenant('acme', ['name' => 'Acme Hair Studio']);

    $staff = DB::table('tenant_acme.staff')->get();

    expect($staff)->toHaveCount(1)
        ->and($staff[0]?->name)->toBe('Acme Hair Studio')
        ->and($staff[0]?->user_id)->toBeNull()
        ->and($staff[0]?->email)->toBeNull()
        ->and($staff[0]?->status)->toBe('active')
        ->and($staff[0]?->is_implicit)->toBeTrue();
});

/**
 * The pattern of a tenant's implicit staff member as (cadence, starts_on, ends_on, windows), each
 * window [weekday, start, end], or null where it holds none.
 *
 * @return array{int, string, string|null, list<array{int, string, string}>}|null
 */
function implicitWeek(string $schema): ?array
{
    $implicitId = implicitStaffId($schema);
    $pattern = WeeklyPattern::stored(DB::connection(), $schema.'.', [$implicitId])[$implicitId] ?? null;

    return $pattern === null ? null : [
        $pattern['cadence_weeks'],
        $pattern['starts_on'],
        $pattern['ends_on'],
        array_map(fn (array $window): array => [$window['weekday'], $window['start'], $window['end']], $pattern['windows']),
    ];
}

it('gives the implicit staff member the default working week, Monday to Friday 09:00 to 17:00, as a weekly pattern from the one start with no end', function () {
    provisionTenant('acme');

    expect(implicitWeek('tenant_acme'))->toBe([1, '1999-12-27', null, [
        [1, '09:00', '17:00'],
        [2, '09:00', '17:00'],
        [3, '09:00', '17:00'],
        [4, '09:00', '17:00'],
        [5, '09:00', '17:00'],
    ]])->and(DB::table('tenant_acme.shift_patterns')->count())->toBe(1)
        ->and(DB::table('tenant_acme.shift_overrides')->count())->toBe(0)
        ->and(DB::table('tenant_acme.blackouts')->count())->toBe(0);
});

it('keeps the hours a tenant edited: a run after the default week was replaced writes no pattern', function () {
    provisionTenant('acme');
    WeeklyHours::clear('tenant_acme');
    WeeklyHours::add('tenant_acme', implicitStaffId('tenant_acme'), 1, '10:00:00', '14:00:00');

    provisionTenant('acme');

    expect(implicitWeek('tenant_acme'))->toBe([1, '1999-12-27', null, [[1, '10:00', '14:00']]])
        ->and(DB::table('tenant_acme.shift_patterns')->pluck('windows')->all())->toBe(['[{"end": "14:00", "start": "10:00", "weekday": 1}]']);
});

it('adds the default week to a tenant a failed run left without one, and only then', function () {
    Event::listen(MigrationEnded::class, function (): void {
        throw new RuntimeException('injected');
    });
    expect(fn () => provisionTenant('acme'))->toThrow(RuntimeException::class);
    Event::forget(MigrationEnded::class);

    provisionTenant('acme');
    provisionTenant('acme');

    expect(implicitWeek('tenant_acme')[3] ?? null)->toHaveCount(5)
        ->and(DB::table('tenant_acme.shift_patterns')->count())->toBe(1);
});

it('creates the owner user from the signup with the owner role and a hashed password', function () {
    $tenant = provisionTenant('acme', ['ownerEmail' => 'Alex@Example.com', 'ownerPassword' => 'hunter2hunter2'])->tenant;

    $owner = User::query()->where('tenant_id', $tenant->id)->sole();

    expect($owner->name)->toBe('Alex Owner')
        ->and($owner->email)->toBe('Alex@Example.com')
        ->and($owner->role)->toBe(Role::Owner)
        ->and(Hash::check('hunter2hunter2', $owner->password))->toBeTrue()
        ->and($owner->tenant?->is($tenant))->toBeTrue();
});

it('changes nothing on a second run with the same slug', function () {
    $first = provisionTenant('acme');
    $before = [
        'tenant' => DB::table('tenants')->where('slug', 'acme')->first(),
        'users' => DB::table('users')->count(),
        'staff' => DB::table('tenant_acme.staff')->count(),
        'patterns' => DB::table('tenant_acme.shift_patterns')->orderBy('id')->get()->all(),
        'settings' => DB::table('tenant_acme.settings')->first(),
        'migrations' => DB::table('tenant_acme.migrations')->count(),
        'schemas' => tenantSchemas(),
    ];

    $second = provisionTenant('ACME', ['name' => 'Renamed', 'ownerEmail' => 'other@example.com']);

    expect($second->alreadyActive)->toBeTrue()
        ->and($second->created)->toBeFalse()
        ->and($first->alreadyActive)->toBeFalse()
        ->and($first->created)->toBeTrue()
        ->and($second->tenant->is($first->tenant))->toBeTrue()
        ->and(DB::table('tenants')->where('slug', 'acme')->first())->toEqual($before['tenant'])
        ->and(DB::table('users')->count())->toBe($before['users'])
        ->and(DB::table('tenant_acme.staff')->count())->toBe($before['staff'])
        ->and(DB::table('tenant_acme.shift_patterns')->orderBy('id')->get()->all())->toEqual($before['patterns'])
        ->and(DB::table('tenant_acme.settings')->first())->toEqual($before['settings'])
        ->and(DB::table('tenant_acme.migrations')->count())->toBe($before['migrations'])
        ->and(tenantSchemas())->toBe($before['schemas']);
});

it('leaves no schema behind and the row in provisioning when a failure lands after the schema is created', function () {
    Event::listen(MigrationEnded::class, function (): void {
        throw new RuntimeException('injected after the first tenant migration');
    });

    expect(fn () => provisionTenant('acme'))->toThrow(RuntimeException::class, 'injected');

    $tenant = Tenant::query()->where('slug', 'acme')->sole();

    expect($tenant->status)->toBe(TenantStatus::Provisioning)
        ->and(schemaExists('tenant_acme'))->toBeFalse()
        ->and(User::query()->where('tenant_id', $tenant->id)->exists())->toBeFalse()
        ->and(DB::scalar("select current_setting('search_path')"))->toBe('public');
});

it('finishes a tenant a failed run left in provisioning', function () {
    Event::listen(MigrationEnded::class, function (): void {
        throw new RuntimeException('injected');
    });
    expect(fn () => provisionTenant('acme'))->toThrow(RuntimeException::class);
    Event::forget(MigrationEnded::class);

    $retried = provisionTenant('acme');

    expect($retried->alreadyActive)->toBeFalse()
        ->and($retried->created)->toBeFalse()
        ->and($retried->tenant->status)->toBe(TenantStatus::Active)
        ->and(schemaExists('tenant_acme'))->toBeTrue()
        ->and(DB::table('tenant_acme.migrations')->count())->toBe(38)
        ->and(DB::table('tenant_acme.staff')->count())->toBe(1)
        ->and(implicitWeek('tenant_acme')[3] ?? null)->toHaveCount(5)
        ->and(DB::table('tenant_acme.settings')->count())->toBe(1)
        ->and(User::query()->where('tenant_id', $retried->tenant->id)->count())->toBe(1);
});

it('leaves a provisioning row it did not create unbuilt when told to finish only its own, another run\'s build in flight, the free tenant\'s race', function () {
    $other = provisionTenant('acme');
    DB::table('tenants')->where('slug', 'acme')->update(['status' => 'provisioning']);
    DB::table('users')->where('tenant_id', $other->tenant->id)->delete();
    DB::statement('drop schema tenant_acme cascade');
    $before = DB::table('tenants')->where('slug', 'acme')->first();

    $found = app(TenantProvisioner::class)->provision(signupFor('acme', ['ownerEmail' => 'second@example.com']), finishFound: false);

    expect($found->created)->toBeFalse()
        ->and($found->alreadyActive)->toBeFalse()
        ->and($found->tenant->status)->toBe(TenantStatus::Provisioning)
        ->and(DB::table('tenants')->where('slug', 'acme')->first())->toEqual($before)
        ->and(schemaExists('tenant_acme'))->toBeFalse()
        ->and(DB::table('users')->where('tenant_id', $other->tenant->id)->count())->toBe(0);
});

it('hands back an active row it did not create unchanged when told to finish only its own', function () {
    provisionTenant('acme');
    $before = DB::table('tenants')->where('slug', 'acme')->first();

    $found = app(TenantProvisioner::class)->provision(signupFor('acme', ['ownerEmail' => 'second@example.com']), finishFound: false);

    expect([$found->created, $found->alreadyActive])->toBe([false, true])
        ->and(DB::table('tenants')->where('slug', 'acme')->first())->toEqual($before)
        ->and(DB::table('users')->where('email', 'second@example.com')->exists())->toBeFalse();
});

it('leaves the central search path where it found it', function () {
    $before = DB::scalar("select current_setting('search_path')");

    provisionTenant('acme');

    expect(DB::scalar("select current_setting('search_path')"))->toBe($before)
        ->and(DB::scalar('select current_schema()'))->toBe('public');
});

it('judges a new slug through the slug rules', function (string $slug, SlugProblem $problem) {
    try {
        provisionTenant($slug);
    } catch (InvalidSlug $e) {
        expect($e->problem)->toBe($problem)
            ->and(Tenant::query()->count())->toBe(0)
            ->and(tenantSchemas())->toBe([]);

        return;
    }

    $this->fail('No InvalidSlug was thrown.');
})->with([
    'a reserved label' => ['api', SlugProblem::Reserved],
    'a malformed label' => ['acme_salon', SlugProblem::InvalidCharacter],
]);

it('refuses to touch a {status} tenant', function (string $status) {
    provisionTenant('acme');
    Tenant::query()->where('slug', 'acme')->update(['status' => $status]);

    expect(fn () => provisionTenant('acme'))->toThrow(ProvisioningRefused::class, $status);
})->with(['suspended', 'closing']);

it('gives two tenants in one database separate schemas that see only their own rows', function () {
    provisionTenant('acme', ['name' => 'Acme Salon']);
    provisionTenant('bravo', ['name' => 'Bravo Studio', 'ownerEmail' => 'owner@example.com']);

    expect(tenantSchemas())->toBe(['tenant_acme', 'tenant_bravo'])
        ->and(DB::table('tenant_acme.staff')->pluck('name')->all())->toBe(['Acme Salon'])
        ->and(DB::table('tenant_bravo.staff')->pluck('name')->all())->toBe(['Bravo Studio'])
        ->and(DB::table('tenant_bravo.migrations')->count())->toBe(38)
        ->and(DB::table('users')->count())->toBe(2)
        ->and(DB::table('pg_tables')->where('schemaname', 'public')->whereIn('tablename', ['settings', 'staff', 'staff_days', 'bookings', 'booking_items'])->exists())->toBeFalse();
});

it('provisions from the console and ends with the hosted page URL and the embed snippet', function () {
    config(['app.url' => 'https://kleiseradevou.gr']);

    [$code, $output] = provisionFromConsole(commandInput('acme'));

    expect($code)->toBe(0)
        ->and($output)->toContain('Tenant "acme" is active.')
        ->toContain('Hosted page: https://acme.kleiseradevou.gr/')
        ->toContain("Embed snippet:\n<div class=\"kr-booking\" data-theme=\"neutral\"></div>\n<script src=\"https://acme.kleiseradevou.gr/embed.js\" async></script>\n")
        ->and(Tenant::query()->where('slug', 'acme')->sole()->status)->toBe(TenantStatus::Active)
        ->and(DB::table('tenants')->where('slug', 'acme')->first(['terms_accepted_at', 'terms_version']))->toEqual((object) ['terms_accepted_at' => null, 'terms_version' => null])
        ->and(schemaExists('tenant_acme'))->toBeTrue();
});

it('says so and exits successfully when run a second time from the console', function () {
    [$first] = provisionFromConsole(commandInput('acme'));
    [$second, $output] = provisionFromConsole(commandInput('acme'));

    expect($first)->toBe(0)
        ->and($second)->toBe(0)
        ->and($output)->toContain('Tenant "acme" is already active; nothing changed.')
        ->toContain('Embed snippet:');
});

it('refuses {what} from the console with exit code 2 before touching the database', function (string $option, string $value, string $message) {
    [$code, $output] = provisionFromConsole([...commandInput('acme'), $option => $value]);

    expect($code)->toBe(2)
        ->and($output)->toContain($message)
        ->and(Tenant::query()->count())->toBe(0)
        ->and(tenantSchemas())->toBe([]);
})->with([
    'a missing name' => ['--name', '', 'The name field is required.'],
    'an unknown timezone' => ['--timezone', 'Mars/Olympus', 'The timezone field must be a valid timezone.'],
    'a lower-case currency' => ['--currency', 'eur', 'The currency must be three upper-case letters.'],
    'a malformed owner email' => ['--owner-email', 'not-an-email', 'The owner email field must be a valid email address.'],
    'a missing owner password' => ['--owner-password', '', 'The owner password field is required.'],
    'a reserved slug' => ['slug', 'admin', 'That subdomain name is reserved.'],
    'a locale that is neither en nor el' => ['--locale', 'de', ProvisionTenantCommand::BAD_LOCALE],
    'a locale left empty' => ['--locale', '', ProvisionTenantCommand::BAD_LOCALE],
]);

it('writes the language --locale names on the new row: {case}', function (?string $option, string $locale) {
    [$code] = provisionFromConsole($option === null ? commandInput('luna') : [...commandInput('luna'), '--locale' => $option]);

    expect($code)->toBe(0)
        ->and(DB::table('tenants')->where('slug', 'luna')->value('locale'))->toBe($locale)
        ->and(ProvisionTenantCommand::BAD_LOCALE)->toBe('The locale must be en or el.');
})->with([
    'English where none is named' => [null, 'en'],
    'English named' => ['en', 'en'],
    'Greek named' => ['el', 'el'],
]);

it('fails from the console with exit code 1 on a suspended tenant', function () {
    provisionTenant('acme');
    Tenant::query()->where('slug', 'acme')->update(['status' => 'suspended']);

    [$code, $output] = provisionFromConsole(commandInput('acme'));

    expect($code)->toBe(1)
        ->and($output)->toContain('Tenant "acme" is suspended and cannot be provisioned.');
});

it('writes the slugs --features names on the new row, which is active', function () {
    [$code] = provisionFromConsole([...commandInput('luna'), '--features' => 'staff']);

    expect($code)->toBe(0)
        ->and(DB::table('tenants')->where('slug', 'luna')->value('entitlements'))->toBe('{staff}')
        ->and(DB::table('tenants')->where('slug', 'luna')->value('subscription_state'))->toBe('active');
});

it('writes the empty entitlement set on a new row provisioned without --features', function () {
    [$code] = provisionFromConsole([...commandInput('sol'), '--owner-email' => 'owner@sol.example']);

    expect($code)->toBe(0)
        ->and(DB::table('tenants')->where('slug', 'sol')->value('entitlements'))->toBe('{}');
});

it('refuses a slug --features does not know with a sentence, and provisions nothing', function () {
    [$code, $output] = provisionFromConsole([...commandInput('luna'), '--features' => 'staff,nonsense']);

    expect($code)->toBe(2)
        ->and($output)->toContain('"nonsense" is not a feature this build knows; the features are: staff.')
        ->and(DB::table('tenants')->where('slug', 'luna')->exists())->toBeFalse();
});

it('says in its description that it bypasses billing', function () {
    expect(app(ProvisionTenantCommand::class)->getDescription())->toContain('bypasses billing');
});

it('refuses from the console a slug a signup holds, and touches nothing', function (bool $customerRecorded, bool $ownerKept) {
    $tenant = app(TenantProvisioner::class)->reserve(signupFor('luna', ['ownerEmail' => 'holder@luna.example']));
    if ($customerRecorded) {
        syncWith()->attachCustomer($tenant, openedCheckout('cus_luna'));
    }
    if (! $ownerKept) {
        DB::table('users')->where('tenant_id', $tenant->id)->delete();
    }
    $before = [DB::table('tenants')->where('slug', 'luna')->first(), DB::table('users')->orderBy('id')->get(), DB::table('subscriptions')->get()];

    [$code, $output] = provisionFromConsole(commandInput('luna'));

    expect($code)->toBe(1)
        ->and($output)->toContain(ProvisioningRefused::heldBySignup($tenant)->getMessage())
        ->and(ProvisioningRefused::heldBySignup($tenant)->getMessage())->toBe('Tenant "luna" is held by a signup in progress and cannot be provisioned from the console.')
        ->and([DB::table('tenants')->where('slug', 'luna')->first(), DB::table('users')->orderBy('id')->get(), DB::table('subscriptions')->get()])->toEqual($before)
        ->and(schemaExists('tenant_luna'))->toBeFalse();
})->with([
    'its owner and its subscriptions row' => [true, true],
    'its owner alone, the customer never recorded' => [false, true],
    'its subscriptions row alone, the owner gone' => [true, false],
]);

it('creates an active tenant with no subscriptions row from the console, a run that finishes its own half-built row included', function () {
    [$code] = provisionFromConsole(commandInput('luna'));

    Event::listen(MigrationEnded::class, function (): void {
        throw new RuntimeException('injected');
    });
    expect(fn () => provisionFromConsole([...commandInput('sol'), '--owner-email' => 'owner@sol.example']))->toThrow(RuntimeException::class);
    Event::forget(MigrationEnded::class);
    expect(DB::table('tenants')->where('slug', 'sol')->value('status'))->toBe('provisioning');

    [$finished, $output] = provisionFromConsole([...commandInput('sol'), '--owner-email' => 'owner@sol.example']);

    expect([$code, $finished])->toBe([0, 0])
        ->and($output)->toContain('Tenant "sol" is active.')
        ->and($output)->toContain(ProvisionTenantCommand::FINISHED)
        ->and(DB::table('tenants')->whereIn('slug', ['luna', 'sol'])->orderBy('slug')->pluck('status')->all())->toBe(['active', 'active'])
        ->and(DB::table('subscriptions')->count())->toBe(0);
});

/*
 * The trial's build is the claimed road's alone, the signup's reservation built by its link's
 * click; the console's road above keeps out of the trial.
 */

it('builds a reserved row as a trial on the claimed road: active, in trial until seven days from the clock, on the full feature set, with no subscriptions row, the click\'s own write in the build\'s commit', function () {
    app()->instance(Clock::class, FixedClock::at('2026-10-01 09:00', 'Europe/Athens'));
    $reserved = app(TenantProvisioner::class)->reserve(signupFor('acme'));
    $verified = 0;

    $found = app(TenantProvisioner::class)->provisionClaimedTrial($reserved->id, function (Connection $db) use (&$verified): void {
        $verified++;
        expect($db->transactionLevel())->toBeGreaterThan(0);
    });
    $row = (array) DB::selectOne("select status, subscription_state, entitlements, trial_ends_at = '2026-10-08 06:00:00+00'::timestamptz as ends_on_time from tenants where slug = 'acme'");

    expect($found)->toBe(TenantStatus::Provisioning)
        ->and($verified)->toBe(1)
        ->and($row)->toBe(['status' => 'active', 'subscription_state' => 'trial', 'entitlements' => '{staff}', 'ends_on_time' => true])
        ->and(schemaExists('tenant_acme'))->toBeTrue()
        ->and(DB::table('subscriptions')->exists())->toBeFalse();
});

it('builds a trial on the full set whatever the signup names, and a second build changes nothing', function () {
    app()->instance(Clock::class, FixedClock::at('2026-10-01 09:00'));
    $reserved = app(TenantProvisioner::class)->reserve(signupFor('acme', features: []));
    app(TenantProvisioner::class)->provisionClaimedTrial($reserved->id, fn (Connection $db) => null);
    app()->instance(Clock::class, FixedClock::at('2026-10-03 09:00'));
    $verified = 0;

    $again = app(TenantProvisioner::class)->provisionClaimedTrial($reserved->id, function () use (&$verified): void {
        $verified++;
    });

    expect($again)->toBe(TenantStatus::Active)
        ->and($verified)->toBe(0)
        ->and(DB::table('tenants')->where('slug', 'acme')->value('entitlements'))->toBe('{staff}')
        ->and(DB::scalar("select trial_ends_at = '2026-10-08 09:00:00+00'::timestamptz from tenants where slug = 'acme'"))->toBeTrue();
});

it('keeps the console path out of the trial: active, its --features, no trial end', function () {
    [$code] = provisionFromConsole([...commandInput('luna'), '--features' => 'staff']);

    expect($code)->toBe(0)
        ->and(DB::table('tenants')->where('slug', 'luna')->value('subscription_state'))->toBe('active')
        ->and(DB::table('tenants')->where('slug', 'luna')->value('trial_ends_at'))->toBeNull();
});
