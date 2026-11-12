<?php

use App\Application\Clock;
use App\Application\Notification\MessageKey;
use App\Application\Notification\PlatformMessage;
use App\Application\Notification\PlatformMessenger;
use App\Billing\Entitlements;
use App\Http\Middleware\ResolveTenant;
use App\Infrastructure\Db\BlackoutRecords;
use App\Infrastructure\Db\TextArray;
use App\Site\DemoSalon;
use App\Site\SiteLanguage;
use App\Tenancy\BusinessCategory;
use App\Tenancy\InvalidSlug;
use App\Tenancy\Signup;
use App\Tenancy\SlugProblem;
use App\Tenancy\Tenant;
use App\Tenancy\TenantProvisioner;
use App\Tenancy\TenantStatus;
use App\Tenancy\TenantUrls;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\Mime\Email;
use Tests\Support\FixedClock;
use Tests\Support\RecordingPlatformMessages;
use Tests\TestCase;

pest()->use(RefreshDatabase::class);

/*
 * The demo tenant: the one reserved label the resolver serves, which demo:reset alone builds,
 * from nothing and over itself, as a free hair salon with a week of bookings relative to the
 * day, sending nothing on the way, and the nightly schedule that rebuilds it.
 */

function demoHost(string $label): string
{
    return $label.'.'.app(TenantUrls::class)->centralHost();
}

it('serves the demo label through the resolver, the one reserved label that resolves, and still refuses www, api, console and admin', function () {
    Tenant::factory()->create(['slug' => 'demo']);
    $resolver = app(ResolveTenant::class);

    expect($resolver->tenantOf(demoHost('demo'))?->slug)->toBe('demo');

    foreach (['www', 'api', 'console', 'admin'] as $label) {
        expect($resolver->tenantOf(demoHost($label)))->toBeNull($label);
    }
});

/**
 * A signup for the demo label as the command hands the provisioner one, every detail the test
 * reads back spelled here.
 */
function demoSignup(string $slug = 'demo'): Signup
{
    return new Signup(
        slug: $slug,
        name: 'Demo Salon',
        timezone: new DateTimeZone('Europe/Athens'),
        currency: 'EUR',
        ownerName: 'Demo Owner',
        ownerEmail: 'owner@demo.example',
        ownerPassword: 'a secret nobody is told',
        entitlements: Entitlements::all(),
        category: BusinessCategory::HairSalon->value,
        locale: SiteLanguage::Greek,
    );
}

/**
 * Rebuilds the demo through the provisioner with a seed that leaves one marker row, and
 * answers the tenant.
 */
function rebuiltDemo(string $marker = 'Marker'): Tenant
{
    return app(TenantProvisioner::class)->rebuildDemo(demoSignup(), function (Tenant $tenant, Connection $db) use ($marker): void {
        $db->table($tenant->schema_name.'.staff')->insert(['name' => $marker, 'status' => 'active', 'sort' => 1]);
    });
}

/**
 * The columns of the demo's row: the free shape and the name.
 *
 * @return array<string, mixed>
 */
function demoRow(): array
{
    $row = DB::table('tenants')->where('slug', 'demo')->sole();

    return [
        'status' => $row->status,
        'name' => $row->name,
        'timezone' => $row->timezone,
        'currency' => $row->currency,
        'category' => $row->category,
        'locale' => $row->locale,
        'entitlements' => $row->entitlements,
        'subscription_state' => $row->subscription_state,
        'trial_ends_at' => $row->trial_ends_at,
        'grace_ends_at' => $row->grace_ends_at,
        'closing_at' => $row->closing_at,
        'suspended_at' => $row->suspended_at,
        'terms_accepted_at' => $row->terms_accepted_at,
        'terms_version' => $row->terms_version,
        'subscriptions' => DB::table('subscriptions')->where('tenant_id', $row->id)->count(),
    ];
}

/**
 * @return list<string>
 */
function demoStaffNames(): array
{
    /** @var list<string> $names */
    $names = DB::table('tenant_demo.staff')->orderBy('id')->pluck('name')->all();

    return $names;
}

it('builds the demo from nothing through the provisioner: the free shape, every feature, Europe/Athens, EUR, hair_salon, Greek, its schema seeded and one owner', function () {
    app()->instance(Clock::class, FixedClock::at('2026-11-03 09:00', 'Europe/Athens'));

    $tenant = rebuiltDemo();

    expect($tenant->slug)->toBe('demo')
        ->and($tenant->status)->toBe(TenantStatus::Active)
        ->and(demoRow())->toBe([
            'status' => 'active',
            'name' => 'Demo Salon',
            'timezone' => 'Europe/Athens',
            'currency' => 'EUR',
            'category' => 'hair_salon',
            'locale' => 'el',
            'entitlements' => '{staff}',
            'subscription_state' => 'active',
            'trial_ends_at' => null,
            'grace_ends_at' => null,
            'closing_at' => null,
            'suspended_at' => null,
            'terms_accepted_at' => null,
            'terms_version' => null,
            'subscriptions' => 0,
        ])
        ->and(demoStaffNames())->toBe(['Demo Salon', 'Marker'])
        ->and(DB::table('tenant_demo.settings')->count())->toBe(1)
        ->and(DB::table('users')->where('tenant_id', $tenant->id)->get(['name', 'email', 'role'])->map(fn (object $user): array => (array) $user)->all())->toBe([
            ['name' => 'Demo Owner', 'email' => 'owner@demo.example', 'role' => 'owner'],
        ]);
});

it('rebuilds the demo over itself: one tenant row under the same id, one owner, a fresh schema with a fresh seed, whatever shape the row was left in', function () {
    app()->instance(Clock::class, FixedClock::at('2026-11-03 09:00', 'Europe/Athens'));
    $first = rebuiltDemo('First');
    DB::table('tenants')->where('id', $first->id)->update(['status' => 'suspended', 'suspended_at' => '2026-11-03 10:00:00+00', 'suspension_reason' => 'a test', 'subscription_state' => 'canceled', 'entitlements' => '{}', 'name' => 'Renamed', 'terms_accepted_at' => '2026-11-03 10:00:00+00', 'terms_version' => '2026-11-03']);
    DB::table('users')->insert(['tenant_id' => $first->id, 'name' => 'Someone Else', 'email' => 'else@demo.example', 'password' => 'x', 'role' => 'manager']);

    $second = rebuiltDemo('Second');

    expect($second->id)->toBe($first->id)
        ->and(DB::table('tenants')->count())->toBe(1)
        ->and(demoRow())->toMatchArray(['status' => 'active', 'name' => 'Demo Salon', 'subscription_state' => 'active', 'entitlements' => '{staff}', 'suspended_at' => null, 'terms_accepted_at' => null, 'terms_version' => null])
        ->and(DB::table('tenants')->where('id', $first->id)->value('suspension_reason'))->toBeNull()
        ->and(demoStaffNames())->toBe(['Demo Salon', 'Second'])
        ->and(DB::table('users')->where('tenant_id', $first->id)->pluck('email')->all())->toBe(['owner@demo.example'])
        ->and(DB::table('pg_namespace')->where('nspname', 'like', 'tenant\_%')->count())->toBe(1);
});

it('rebuilds only the label configuration names, whatever signup it is handed', function () {
    expect(fn () => app(TenantProvisioner::class)->rebuildDemo(demoSignup('acme'), fn () => null))
        ->toThrow(LogicException::class, '"acme"');

    expect(DB::table('tenants')->count())->toBe(0);
});

it('leaves the demo row alone while it rebuilds, provisioning with no owner a day on, where the sweep of abandoned signups would give a reservation back', function () {
    app()->instance(Clock::class, FixedClock::at('2026-11-01 09:00', 'Europe/Athens'));
    $tenant = rebuiltDemo();
    DB::table('tenants')->where('id', $tenant->id)->update(['status' => 'provisioning']);
    DB::table('users')->where('tenant_id', $tenant->id)->delete();
    app()->instance(Clock::class, FixedClock::at('2026-11-03 09:00', 'Europe/Athens'));

    expect(app(TenantProvisioner::class)->sweepAbandoned())->toBe(0)
        ->and(DB::table('tenants')->where('id', $tenant->id)->value('status'))->toBe('provisioning');
});

it('still refuses the label on the signup, on tenant:provision and on the provisioner\'s own path', function () {
    $this->from(centralUrl('/signup'))->post(centralUrl('/signup'), signupForm(['slug' => 'demo']))
        ->assertRedirect(centralUrl('/signup'))
        ->assertSessionHasErrors(['slug' => signupSentence('errors.slug.'.SlugProblem::Reserved->value)]);

    expect(Artisan::call('tenant:provision', [...demoCommandInput(), 'slug' => 'demo']))->toBe(2)
        ->and(Artisan::output())->toContain(SlugProblem::Reserved->detail())
        ->and(fn () => provisionTenant('demo'))->toThrow(InvalidSlug::class)
        ->and(DB::table('tenants')->count())->toBe(0);
});

/**
 * @return array<string, string>
 */
function demoCommandInput(): array
{
    return [
        '--name' => 'Acme Salon',
        '--timezone' => 'Europe/Athens',
        '--currency' => 'EUR',
        '--owner-name' => 'Alex Owner',
        '--owner-email' => 'owner@example.com',
        '--owner-password' => 'correct horse battery staple',
    ];
}

/*
 * The command and its seed, under a pinned clock: Tuesday 2026-11-03 at 09:00 in Athens is the
 * reset's day, so the week it writes runs from Wednesday the 28th of October to Monday the 9th
 * of November.
 */

const DEMO_DAY = '2026-11-03';

function demoClockAt(string $text): void
{
    app()->instance(Clock::class, FixedClock::at($text, DemoSalon::ZONE));
}

/**
 * Runs demo:reset with both seams recording, and answers the exit code, the output and what
 * each seam and the queue took during the reset. The queue is counted from before the reset as
 * the seam is: the jobs table is central, and a file that truncates rather than rolls back may
 * have left a row there before this one ran.
 *
 * @return array{code: int, output: string, platform: list<PlatformMessage>, tenant: list<Email>, jobs: int}
 */
function demoReset(): array
{
    $platform = new RecordingPlatformMessages;
    app()->instance(PlatformMessenger::class, $platform);
    $before = count(seamSent());
    $queued = DB::table('jobs')->count();

    $code = Artisan::call('demo:reset');

    return [
        'code' => $code,
        'output' => Artisan::output(),
        'platform' => $platform->sent,
        'tenant' => array_slice(seamSent(), $before),
        'jobs' => DB::table('jobs')->count() - $queued,
    ];
}

/**
 * Every booking of the demo with its items and its trail, oldest first: the status and the
 * hold columns, each item's local day and blocking flag, and the trail's actions in order.
 *
 * @return list<array{status: string, hold_class: string|null, hold_expires_at: string|null, requires_approval: bool, source: string, days: non-empty-list<string>, blocks: list<bool>, trail: list<string>}>
 */
function demoBookings(): array
{
    $bookings = [];

    foreach (DB::table('tenant_demo.bookings')->orderBy('id')->get() as $booking) {
        assert(is_int($booking->id) && is_string($booking->status) && is_bool($booking->requires_approval) && is_string($booking->source));

        $days = [];
        $blocks = [];
        $trail = [];

        foreach (DB::select('select (start_utc at time zone ?) ::date::text as day, blocks from tenant_demo.booking_items where booking_id = ? order by sort', [DemoSalon::ZONE, $booking->id]) as $item) {
            assert($item instanceof stdClass && is_string($item->day) && is_bool($item->blocks));
            $days[] = $item->day;
            $blocks[] = $item->blocks;
        }

        foreach (DB::table('tenant_demo.audit_log')->where('booking_id', $booking->id)->orderBy('id')->pluck('action') as $action) {
            assert(is_string($action));
            $trail[] = $action;
        }

        assert($days !== []);

        $bookings[] = [
            'status' => $booking->status,
            'hold_class' => is_string($booking->hold_class) ? $booking->hold_class : null,
            'hold_expires_at' => is_string($booking->hold_expires_at) ? $booking->hold_expires_at : null,
            'requires_approval' => $booking->requires_approval,
            'source' => $booking->source,
            'days' => $days,
            'blocks' => $blocks,
            'trail' => $trail,
        ];
    }

    return $bookings;
}

/**
 * The earliest and the latest local day the bookings given hold an item on.
 *
 * @param  list<array{days: non-empty-list<string>}>  $bookings
 * @return array{string, string}
 */
function demoDaySpan(array $bookings): array
{
    $days = array_merge(...array_column($bookings, 'days'));
    assert($days !== []);

    return [min($days), max($days)];
}

/**
 * The starts the demo answers for a service over a run of days, through the resolver and the
 * public availability endpoint, as the booking page asks.
 *
 * @return array<string, list<string>>
 */
function demoStarts(TestCase $test, string $service, string $from, string $to): array
{
    $serviceId = DB::table('tenant_demo.services')->where('name', $service)->value('id');
    assert(is_int($serviceId));

    $query = http_build_query(['items' => (string) json_encode([['service_id' => $serviceId, 'staff_id' => null]]), 'from' => $from, 'to' => $to]);

    /** @var array<string, list<string>> $starts */
    $starts = $test->getJson(tenantUrl('demo', '/api/v1/availability?'.$query))->assertOk()->json();

    return $starts;
}

it('builds the demo through the command: a free hair salon in Greek under the configured label, its owner under a reserved-domain address with a random password printed nowhere, drawn afresh by a second run', function () {
    demoClockAt(DEMO_DAY.' 09:00');

    $first = demoReset();
    $tenant = Tenant::query()->where('slug', 'demo')->sole();
    $owner = DB::table('users')->where('tenant_id', $tenant->id)->sole();
    assert(is_string($owner->password));

    expect($first['code'])->toBe(0)
        ->and($first['output'])->toContain('Demo tenant "demo" rebuilt')
        ->and(demoRow())->toBe([
            'status' => 'active',
            'name' => DemoSalon::NAME,
            'timezone' => 'Europe/Athens',
            'currency' => 'EUR',
            'category' => 'hair_salon',
            'locale' => 'el',
            'entitlements' => '{staff}',
            'subscription_state' => 'active',
            'trial_ends_at' => null,
            'grace_ends_at' => null,
            'closing_at' => null,
            'suspended_at' => null,
            'terms_accepted_at' => null,
            'terms_version' => null,
            'subscriptions' => 0,
        ])
        ->and($owner->email)->toBe('owner@demo.example')
        ->and($owner->role)->toBe('owner')
        ->and(Hash::isHashed($owner->password))->toBeTrue()
        ->and($first['output'])->not->toContain('password')->not->toContain($owner->password)
        ->and(preg_match('/[0-9a-f]{64}/', $first['output']))->toBe(0);

    $second = demoReset();

    expect($second['code'])->toBe(0)
        ->and(DB::table('tenants')->count())->toBe(1)
        ->and(DB::table('users')->where('tenant_id', $tenant->id)->pluck('password')->sole())->not->toBe($owner->password);
});

it('seeds the salon: four stylists with weekly hours beside the implicit member, six services each offered by the stylists who do them, every email switch off, no coordinates and no payment provider', function () {
    demoClockAt(DEMO_DAY.' 09:00');

    demoReset();

    $staff = DB::table('tenant_demo.staff')->orderBy('sort')->orderBy('id')->get(['name', 'is_implicit', 'status', 'online', 'title']);
    $windowsByStaff = DB::table('tenant_demo.shift_patterns', 'pattern')->join('tenant_demo.staff as staff', 'staff.id', '=', 'pattern.staff_id')->orderBy('staff.name')->selectRaw('staff.name, jsonb_array_length(pattern.windows) as windows')->pluck('windows', 'name')->all();
    $services = DB::table('tenant_demo.services')->orderBy('sort')->get(['name', 'duration_min', 'price_minor', 'currency', 'payment_mode', 'requires_approval', 'status', 'category']);
    $settings = DB::table('tenant_demo.settings')->where('id', 1)->sole();

    expect($staff->pluck('name')->all())->toBe([DemoSalon::NAME, ...array_keys(DemoSalon::STYLISTS)])
        ->and($staff->pluck('is_implicit')->all())->toBe([true, false, false, false, false])
        ->and($staff->pluck('status')->unique()->all())->toBe(['active'])
        ->and($staff->pluck('online')->unique()->all())->toBe([true])
        ->and($staff->skip(1)->pluck('title')->all())->toBe(array_column(DemoSalon::STYLISTS, 'title'))
        ->and($windowsByStaff)->toBe([
            'Eleni Papadopoulou' => 5,
            'Giorgos Antoniou' => 5,
            DemoSalon::NAME => 5,
            'Katerina Nikolaou' => 5,
            'Maria Konstantinou' => 5,
        ])
        ->and(DB::table('tenant_demo.shift_patterns')->where('cadence_weeks', '>', 1)->pluck('staff_id')->all())->toBe([DB::table('tenant_demo.staff')->where('name', DemoSalon::FORTNIGHTLY)->value('id')])
        ->and(DB::table('tenant_demo.shift_patterns')->where('cadence_weeks', 1)->pluck('starts_on')->unique()->all())->toBe(['1999-12-27'])
        ->and($services->count())->toBe(6)
        ->and($services->pluck('name')->all())->toBe(array_keys(DemoSalon::SERVICES))
        ->and($services->pluck('currency')->unique()->all())->toBe(['EUR'])
        ->and($services->pluck('payment_mode')->unique()->all())->toBe(['onsite'])
        ->and($services->pluck('status')->unique()->all())->toBe(['active'])
        ->and($services->where('requires_approval', true)->pluck('name')->all())->toBe([DemoSalon::APPROVAL_SERVICE])
        ->and($services->every(fn (object $service): bool => is_int($service->duration_min) && $service->duration_min % 15 === 0 && is_int($service->price_minor) && $service->price_minor > 0 && is_string($service->category)))->toBeTrue();

    foreach (DemoSalon::SERVICES as $name => $service) {
        $offeredBy = DB::table('tenant_demo.service_staff', 'assignment')
            ->join('tenant_demo.services as service', 'service.id', '=', 'assignment.service_id')
            ->join('tenant_demo.staff as staff', 'staff.id', '=', 'assignment.staff_id')
            ->where('service.name', $name)
            ->orderBy('staff.sort')
            ->pluck('staff.name')
            ->all();

        expect($offeredBy)->toBe($service['staff'], $name);
    }

    assert(is_string($settings->emails_off));

    expect(TextArray::decode($settings->emails_off))->toBe(array_map(fn (MessageKey $key): string => $key->value, MessageKey::cases()))
        ->and($settings->lat)->toBeNull()
        ->and($settings->lng)->toBeNull()
        ->and($settings->map_state)->toBe('none')
        ->and($settings->city)->toBe('Athens')
        ->and(DB::table('tenant_demo.payment_connections')->count())->toBe(0);
});

function demoStaffId(string $name): int
{
    return BlackoutRecords::int(DB::table('tenant_demo.staff')->where('name', $name)->value('id'));
}

/**
 * The dates in the tenant's zone the stylist holds a visit on, in order.
 *
 * @return list<string>
 */
function demoVisitDays(string $name): array
{
    return array_values(array_map(BlackoutRecords::string(...), DB::table('tenant_demo.booking_items')->where('staff_id', demoStaffId($name))
        ->selectRaw('distinct (start_utc at time zone ?)::date::text as day', [DemoSalon::ZONE])->orderBy('day')->pluck('day')->all()));
}

/**
 * The stylist's overrides as stored, by date.
 *
 * @return array<string, mixed>
 */
function demoOverrides(string $name): array
{
    return DB::table('tenant_demo.shift_overrides')->where('staff_id', demoStaffId($name))->orderBy('date')->get(['date', 'windows'])
        ->mapWithKeys(fn (object $row): array => [BlackoutRecords::string($row->date) => json_decode(BlackoutRecords::string($row->windows), true)])->all();
}

it('seeds a stylist every two weeks from the sample week\'s first day, keeping visits in the on week alone', function () {
    demoClockAt(DEMO_DAY.' 09:00');

    demoReset();

    $fortnightly = DB::table('tenant_demo.shift_patterns')->where('staff_id', demoStaffId(DemoSalon::FORTNIGHTLY))->sole();
    $visits = demoVisitDays(DemoSalon::FORTNIGHTLY);

    expect([$fortnightly->starts_on, $fortnightly->cadence_weeks, $fortnightly->ends_on])->toBe(['2026-11-02', DemoSalon::FORTNIGHTLY_CADENCE, null])
        ->and(json_decode(BlackoutRecords::string($fortnightly->windows), true))->toHaveCount(count(DemoSalon::STYLISTS[DemoSalon::FORTNIGHTLY]['hours']))
        ->and($visits)->not->toBeEmpty()
        ->and(array_filter($visits, fn (string $day): bool => $day < '2026-11-02' || $day >= '2026-11-09'))->toBe([])
        ->and(settingsOf('demo')->value('hours_confirmed_at'))->toBeNull();
});

it('seeds a stylist\'s day of the sample week changed for that day only, an override of that date, the day keeping its visits', function () {
    demoClockAt(DEMO_DAY.' 09:00');

    demoReset();

    [$name] = DemoSalon::CHANGED_DAY;

    expect(demoOverrides($name))->toEqual(['2026-11-04' => [['start' => '09:00', 'end' => '13:00'], ['start' => '14:00', 'end' => '18:00']]])
        ->and(DB::table('tenant_demo.shift_patterns')->where('staff_id', demoStaffId($name))->value('cadence_weeks'))->toBe(1)
        ->and(in_array('2026-11-04', demoVisitDays($name), true))->toBeTrue();
});

it('seeds a stylist\'s working day of the sample week off, an override with no window, keeping no visit on it', function () {
    demoClockAt(DEMO_DAY.' 09:00');

    demoReset();

    [$name] = DemoSalon::DAY_OFF;

    expect(demoOverrides($name))->toBe(['2026-11-05' => []])
        ->and(in_array('2026-11-05', demoVisitDays($name), true))->toBeFalse()
        ->and(DB::table('tenant_demo.shift_overrides')->count())->toBe(2);
});

it('seeds a week of bookings relative to the day: past days\' visits completed and one no-show, their items no longer blocking; today\'s and the coming days\' confirmed; one request awaiting approval on a live hold; every trail as the transitions write one', function () {
    demoClockAt(DEMO_DAY.' 09:00');

    demoReset();

    $bookings = demoBookings();
    [$firstDay, $lastDay] = demoDaySpan($bookings);
    $past = array_values(array_filter($bookings, fn (array $booking): bool => min($booking['days']) < DEMO_DAY));
    $later = array_values(array_filter($bookings, fn (array $booking): bool => min($booking['days']) >= DEMO_DAY));
    $statuses = array_count_values(array_column($bookings, 'status'));

    expect($bookings)->not->toBeEmpty()
        ->and($firstDay)->toBe('2026-10-28')
        ->and($lastDay)->toBe('2026-11-09')
        ->and(array_unique(array_merge(...array_column($past, 'blocks'))))->toBe([false])
        ->and(array_unique(array_merge(...array_column($later, 'blocks'))))->toBe([true])
        ->and($statuses['no_show'] ?? 0)->toBe(1)
        ->and($statuses['awaiting_approval'] ?? 0)->toBe(1)
        ->and($statuses['completed'] ?? 0)->toBeGreaterThan(5)
        ->and($statuses['confirmed'] ?? 0)->toBeGreaterThan(5)
        ->and(array_keys($statuses))->each->toBeIn(['completed', 'no_show', 'confirmed', 'awaiting_approval'])
        ->and(array_unique(array_column($bookings, 'source')))->toEqualCanonicalizing(['hosted', 'admin']);

    foreach ($bookings as $booking) {
        $trail = $booking['trail'];
        $opening = $booking['source'] === 'admin' ? ['confirmed'] : ['held', 'confirmed'];

        match ($booking['status']) {
            'completed' => expect($trail)->toBe([...$opening, 'completed']),
            'no_show' => expect($trail)->toBe([...$opening, 'no_show']),
            'confirmed' => expect($trail)->toBe($opening),
            default => expect($trail)->toBe(['held'])
                ->and($booking['hold_class'])->toBe('approval')
                ->and($booking['requires_approval'])->toBeTrue()
                ->and(min($booking['days']))->toBeGreaterThan(DEMO_DAY)
                ->and(new DateTimeImmutable((string) $booking['hold_expires_at'], new DateTimeZone('UTC')) > app(Clock::class)->now())->toBeTrue(),
        };

        if ($booking['status'] !== 'awaiting_approval') {
            expect($booking['hold_class'])->toBeNull()->and($booking['hold_expires_at'])->toBeNull();
        }
    }

    $ownerId = DB::table('users')->where('role', 'owner')->value('id');
    assert(is_int($ownerId));

    expect(auditOffences())->toBe([])
        ->and(DB::table('tenant_demo.audit_log')->where('actor', 'like', 'user:%')->distinct()->pluck('actor')->all())->toBe(['user:'.$ownerId]);
});

it('leaves free starts on every open day of the coming week, answered through the resolver on demo.{central host}, and none on Sunday', function () {
    demoClockAt(DEMO_DAY.' 09:00');

    demoReset();

    $women = demoStarts($this, 'Women\'s haircut', '2026-11-04', '2026-11-11');
    $men = demoStarts($this, 'Men\'s haircut', '2026-11-04', '2026-11-11');

    foreach (['2026-11-04', '2026-11-05', '2026-11-06', '2026-11-07', '2026-11-09', '2026-11-10'] as $open) {
        expect($women[$open] ?? [])->not->toBeEmpty($open)->and($men[$open] ?? [])->not->toBeEmpty($open);
    }

    expect($women['2026-11-08'] ?? null)->toBe([])
        ->and($men['2026-11-08'] ?? null)->toBe([]);
});

/**
 * The demo's mutex rows: the one revision every row carries, and the first and last day.
 *
 * @return array{revision: int, first: string, last: string, rows: int}
 */
function demoMutexRows(): array
{
    $revisions = [];
    $days = [];

    foreach (DB::table('tenant_demo.staff_days')->get(['day', 'rev']) as $row) {
        assert(is_string($row->day) && is_int($row->rev));
        $revisions[$row->rev] = $row->rev;
        $days[] = $row->day;
    }

    assert($days !== []);

    expect($revisions)->toHaveCount(1);

    return ['revision' => array_values($revisions)[0], 'first' => min($days), 'last' => max($days), 'rows' => count($days)];
}

it('keys every staff-day of the booking horizon by the reset, so no mask cached under the schema it replaced can answer for the new seed', function () {
    demoClockAt(DEMO_DAY.' 09:00');
    demoReset();
    $first = demoMutexRows();

    demoClockAt('2026-11-04 04:00');
    demoReset();
    $second = demoMutexRows();

    expect($first)->toMatchArray(['first' => '2026-10-27', 'last' => '2027-01-04', 'rows' => 5 * 70])
        ->and($second)->toMatchArray(['first' => '2026-10-28', 'last' => '2027-01-05', 'rows' => 5 * 70])
        ->and($second['revision'])->toBeGreaterThan($first['revision']);
});

it('seeds relative to the new day when run again, one tenant row and one owner left', function () {
    demoClockAt(DEMO_DAY.' 09:00');
    demoReset();

    demoClockAt('2026-11-05 04:00');
    $again = demoReset();
    [$firstDay, $lastDay] = demoDaySpan(demoBookings());

    expect($again['code'])->toBe(0)
        ->and(DB::table('tenants')->count())->toBe(1)
        ->and(DB::table('users')->count())->toBe(1)
        ->and($firstDay)->toBe('2026-10-30')
        ->and($lastDay)->toBe('2026-11-11')
        ->and(array_count_values(array_column(demoBookings(), 'status')))->toMatchArray(['no_show' => 1, 'awaiting_approval' => 1]);
});

it('records no message through either seam during a reset, and queues no job', function () {
    demoClockAt(DEMO_DAY.' 09:00');

    $reset = demoReset();

    expect($reset['code'])->toBe(0)
        ->and($reset['platform'])->toBe([])
        ->and($reset['tenant'])->toBe([])
        ->and($reset['jobs'])->toBe(0);
});

it('runs daily at 04:00 Europe/Athens in the console schedule, without overlapping, beside the hourly commands', function () {
    $scheduled = array_values(array_filter(
        app(Schedule::class)->events(),
        fn (object $event): bool => is_string($event->command ?? null) && str_contains($event->command, 'demo:reset'),
    ));

    expect($scheduled)->toHaveCount(1)
        ->and($scheduled[0]->expression)->toBe('0 4 * * *')
        ->and($scheduled[0]->timezone)->toBe('Europe/Athens')
        ->and($scheduled[0]->withoutOverlapping)->toBeTrue();
});

it('serves the label configuration names and no other reserved one', function () {
    config(['site.demo_slug' => 'staging']);
    Tenant::factory()->create(['slug' => 'demo']);
    Tenant::factory()->create(['slug' => 'staging']);
    $resolver = app(ResolveTenant::class);

    expect($resolver->tenantOf(demoHost('staging'))?->slug)->toBe('staging')
        ->and($resolver->tenantOf(demoHost('demo')))->toBeNull();
});

it('seeds a profile for three of the sample customers, tags on each and a birthday and a language on one, every one an address the week books', function () {
    demoClockAt(DEMO_DAY.' 09:00');

    demoReset();

    $rows = [];

    foreach (DB::table('tenant_demo.customer_profiles')->orderBy('email')->get(['email', 'tags', 'birthday', 'language', 'blocked_at']) as $row) {
        assert(is_string($row->email) && is_string($row->tags));
        $rows[$row->email] = ['tags' => TextArray::decode($row->tags), 'birthday' => $row->birthday, 'language' => $row->language, 'blocked' => $row->blocked_at !== null];
    }

    $expected = [];

    foreach (DemoSalon::PROFILES as $email => $profile) {
        $expected[$email] = [...$profile, 'blocked' => array_key_exists($email, DemoSalon::BLOCKED)];
    }

    ksort($expected);

    expect($rows)->toBe($expected);

    foreach (array_keys(DemoSalon::PROFILES) as $email) {
        expect(DB::table('tenant_demo.bookings')->where('customer_email', $email)->exists())->toBeTrue($email);
    }
});

it('blocks one sample customer with the staff\'s reason and writes the owner\'s notes on another, newest first by the reset\'s clock, each an address with a profile', function () {
    demoClockAt(DEMO_DAY.' 09:00');

    demoReset();

    $owner = DB::table('users')->where('email', DemoSalon::OWNER_EMAIL)->first(['id', 'name']);
    assert($owner !== null);

    expect(DB::table('tenant_demo.customer_profiles')->whereNotNull('blocked_at')->pluck('blocked_reason', 'email')->all())->toBe(DemoSalon::BLOCKED);

    foreach (DemoSalon::NOTES as $email => $notes) {
        expect(DB::table('tenant_demo.customer_profiles')->where('email', $email)->exists())->toBeTrue($email)
            ->and(DB::table('tenant_demo.customer_notes')->where('email', $email)->orderByDesc('created_at')->pluck('body')->all())->toBe(array_map(fn (array $note): string => $note[0], $notes));
    }

    assert(is_int($owner->id));

    expect(DB::table('tenant_demo.customer_notes')->distinct()->pluck('author_actor')->all())->toBe(['user:'.$owner->id])
        ->and(DB::table('tenant_demo.customer_notes')->distinct()->pluck('author_name')->all())->toBe([$owner->name]);
});
