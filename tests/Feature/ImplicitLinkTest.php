<?php

use App\Http\Controllers\Admin\ServiceController;
use App\Infrastructure\Cache\StaffDayMaskCache;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\FixtureDay;
use Tests\Support\TenantMigration;
use Tests\TestCase;

pest()->use(DatabaseTruncation::class);

/*
 * The implicit link: a service is offered online from its first day. A service created while the
 * implicit member is the only staff row is linked to that member in the insert's own transaction,
 * so a floor tenant's new service has a performer and the booking page offers its starts at once;
 * a tenant with a second staff row keeps the checklist as the only writer of links. Services a
 * floor tenant made before the rule are linked by migration 000027, proven here on a schema taken
 * back before it: the service it finds unperformed is booked once the migration has run.
 *
 * Not wrapped in a transaction: the booking enters the transaction runner, which refuses to open
 * inside another. acme is provisioned for real, in Europe/Athens, its implicit member working
 * Monday to Friday 09:00 to 17:00; FixtureDay's Tuesday is such a day, and the clock stands on the
 * Monday before. The mask cache is cold, so every answer comes from the rows.
 */

beforeEach(function () {
    DB::statement('drop schema if exists tenant_acme cascade');
    FixtureDay::pinClock();
    app()->instance(StaffDayMaskCache::class, coldMaskCache());
    provisionTenant('acme');
});

afterEach(function () {
    DB::statement('drop schema if exists tenant_acme cascade');
    DB::table('users')->delete();
    DB::table('tenants')->delete();
});

/**
 * Creates a service as the owner's form does and answers its id.
 */
function linkedOnCreate(TestCase $test, string $name): int
{
    $owner = User::query()->where('email', 'owner@example.com')->sole();

    $test->actingAs($owner)->from(tenantUrl('acme', '/admin/services'))->json('POST', tenantUrl('acme', '/admin/services'), [
        'name' => $name, 'duration_min' => 30, 'currency' => 'EUR', 'payment_mode' => 'onsite',
    ], ['X-Inertia' => 'true'])->assertRedirect();

    expect(session('flash'))->toBe(['kind' => 'success', 'message' => adminLine(ServiceController::CREATED)]);

    $id = DB::table('tenant_acme.services')->where('name', $name)->value('id');
    assert(is_int($id));

    return $id;
}

/**
 * The starts acme's public availability offers for one service on FixtureDay's Tuesday.
 *
 * @return list<string>
 */
function startsOnTheTuesday(TestCase $test, int $serviceId): array
{
    $query = http_build_query([
        'items' => (string) json_encode([['service_id' => $serviceId, 'staff_id' => null]]),
        'from' => FixtureDay::DATE,
        'to' => (new DateTimeImmutable(FixtureDay::DATE, new DateTimeZone(FixtureDay::ZONE)))->add(new DateInterval('P1D'))->format('Y-m-d'),
    ]);
    $days = $test->get(tenantUrl('acme', '/api/v1/availability?'.$query))->assertOk()->json();
    assert(is_array($days));
    $starts = $days[FixtureDay::DATE] ?? [];
    assert(is_array($starts));

    return array_values(array_map(fn (mixed $start): string => is_string($start) ? $start : '', $starts));
}

/**
 * The staff ids that perform one service of acme.
 *
 * @return list<int>
 */
function performersOf(int $serviceId): array
{
    /** @var list<int> */
    return DB::table('tenant_acme.service_staff')->where('service_id', $serviceId)->orderBy('staff_id')->pluck('staff_id')->all();
}

it('links a service created while the implicit member is the only staff row to that member, and offers it on the booking page at once', function () {
    $cut = linkedOnCreate($this, 'Cut');
    $starts = startsOnTheTuesday($this, $cut);

    // 09:00 to 16:30 in Athens on the quarter hour, UTC+2 in November.
    expect(performersOf($cut))->toBe([implicitStaffId('tenant_acme')])
        ->and($starts)->toHaveCount(31)
        ->and($starts[0] ?? null)->toBe('2026-11-03T07:00:00+00:00')
        ->and($starts[30] ?? null)->toBe('2026-11-03T14:30:00+00:00');
});

it('links nothing on create where a second staff row exists, the checklist being the only writer there, and the booking page offers nothing for the service nobody performs', function () {
    DB::table('tenant_acme.staff')->insert(['name' => 'Sam', 'status' => 'active', 'sort' => 1]);

    $cut = linkedOnCreate($this, 'Cut');

    expect(performersOf($cut))->toBe([])
        ->and(DB::table('tenant_acme.service_staff')->count())->toBe(0)
        ->and(startsOnTheTuesday($this, $cut))->toBe([]);
});

it('books, once migration 000027 has run, the service a floor tenant made before the rule and nobody performed', function () {
    TenantMigration::takeBack('tenant_acme', TenantMigration::BOUNDS_AND_LINK);
    $cut = DB::table('tenant_acme.services')->insertGetId(serviceRow('Cut'));

    expect(performersOf($cut))->toBe([])
        ->and(startsOnTheTuesday($this, $cut))->toBe([]);

    $code = Artisan::call('tenant:migrate', ['--slug' => 'acme']);

    expect($code)->toBe(0)
        ->and(trim(Artisan::output()))->toBe('tenant_acme: ran '.TenantMigration::BOUNDS_AND_LINK)
        ->and(performersOf($cut))->toBe([implicitStaffId('tenant_acme')])
        ->and(startsOnTheTuesday($this, $cut))->toContain('2026-11-03T08:00:00+00:00');

    $held = $this->postJson(tenantUrl('acme', '/api/v1/holds'), [
        'customer_name' => 'Perpetua Vandersnoot',
        'customer_email' => 'perpetua.vandersnoot@example.test',
        'customer_phone' => '+30 210 1234567',
        'items' => [['service_id' => $cut, 'staff_id' => null]],
        'start' => FixtureDay::start()->format(DateTimeImmutable::RFC3339),
    ])->assertCreated();

    $uuid = $held->json('uuid');

    expect($uuid)->toBeString()
        ->and(DB::table('tenant_acme.booking_items')->get(['service_id', 'staff_id'])->map(fn (stdClass $item): array => [$item->service_id, $item->staff_id])->all())
        ->toBe([[$cut, implicitStaffId('tenant_acme')]]);
});
