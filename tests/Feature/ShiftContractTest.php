<?php

use App\Application\Clock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\FixedClock;
use Tests\Support\TenantMigration;
use Tests\Support\WeeklyHours;

pest()->use(RefreshDatabase::class);

/*
 * The expand and contract over a member's week: migration 000030 copies the weekday rules into one
 * weekly pattern per member with rules, from the first day of the week holding 2000-01-01 in the
 * tenant's week start, and migration 000032 drops the rules once nothing reads them. A new schema
 * holds the patterns alone, the provisioner's default week among them; 000032 run in place on a
 * live schema leaves every pattern as it was; and a schema taken back before both, its rules
 * written back by 000032's down() from the patterns, comes through tenant:migrate with the same
 * weekly patterns. No case here reads or writes the rules table: the down() writes it and the copy
 * reads it. The clock, pinned in each case, moves nothing of either.
 */

/**
 * @return array<int, array{starts_on: string, cadence_weeks: int, ends_on: string|null, windows: mixed}>
 */
function contractPatterns(string $schema): array
{
    $patterns = [];

    foreach (DB::table($schema.'.shift_patterns')->orderBy('staff_id')->get() as $row) {
        assert(is_int($row->staff_id) && is_string($row->windows) && is_string($row->starts_on) && is_int($row->cadence_weeks) && ($row->ends_on === null || is_string($row->ends_on)));
        $patterns[$row->staff_id] = ['starts_on' => $row->starts_on, 'cadence_weeks' => $row->cadence_weeks, 'ends_on' => $row->ends_on, 'windows' => json_decode($row->windows, true)];
    }

    return $patterns;
}

/**
 * Two members beside the implicit one: one with a split Monday, a Tuesday and an evening to
 * midnight, one with no week.
 *
 * @return array{int, int, int}
 */
function contractFixture(): array
{
    $implicit = implicitStaffId('tenant_acme');
    $sam = DB::table('tenant_acme.staff')->insertGetId(['name' => 'Sam', 'status' => 'active']);
    $idle = DB::table('tenant_acme.staff')->insertGetId(['name' => 'Idle', 'status' => 'active']);

    WeeklyHours::week('tenant_acme', $sam, [[2, '10:00', '12:00'], [1, '14:00', '18:00'], [1, '09:00', '13:00'], [5, '20:00', '24:00']]);

    return [$implicit, $sam, $idle];
}

it('builds a new schema with no weekday rules and the default week\'s pattern written by the provisioner, holding from the week of 2000-01-01', function () {
    app()->instance(Clock::class, FixedClock::at('2026-11-04 10:00', 'Europe/Athens'));
    provisionTenant('acme');

    expect(DB::getSchemaBuilder()->hasTable('tenant_acme.availability_rules'))->toBeFalse()
        ->and(contractPatterns('tenant_acme'))->toBe([
            implicitStaffId('tenant_acme') => ['starts_on' => '1999-12-27', 'cadence_weeks' => 1, 'ends_on' => null, 'windows' => array_map(fn (int $weekday): array => ['end' => '17:00', 'start' => '09:00', 'weekday' => $weekday], [1, 2, 3, 4, 5])],
        ])
        ->and(DB::table('tenant_acme.shift_overrides')->count())->toBe(0);
});

it('drops the rules in place on a live schema through tenant:migrate, every pattern and override left as it was', function () {
    app()->instance(Clock::class, FixedClock::at('2026-11-04 23:30', 'Europe/Athens'));
    provisionTenant('acme');
    [, $sam] = contractFixture();
    WeeklyHours::pattern('tenant_acme', $sam, '2026-11-02', 2, '2027-01-31', [[3, '10:00', '19:00']]);
    DB::table('tenant_acme.shift_overrides')->insert(['staff_id' => $sam, 'date' => '2026-11-11', 'windows' => '[]']);
    $before = [contractPatterns('tenant_acme'), DB::table('tenant_acme.shift_overrides')->get(['staff_id', 'date', 'windows'])->all()];

    TenantMigration::takeBack('tenant_acme', TenantMigration::RULES_DROPPED);
    expect(DB::getSchemaBuilder()->hasTable('tenant_acme.availability_rules'))->toBeTrue();

    expect(Artisan::call('tenant:migrate', ['--slug' => 'acme']))->toBe(0);

    expect(DB::getSchemaBuilder()->hasTable('tenant_acme.availability_rules'))->toBeFalse()
        ->and([contractPatterns('tenant_acme'), DB::table('tenant_acme.shift_overrides')->get(['staff_id', 'date', 'windows'])->all()])->toEqual($before)
        ->and(DB::table('tenant_acme.migrations')->where('migration', TenantMigration::RULES_DROPPED)->exists())->toBeTrue();
});

it('brings a schema taken back before the copy through it again: one weekly pattern per member with a week, from the first day of the week holding 2000-01-01 in the tenant\'s week start, and none for a member without', function (int $startOfWeek, string $startsOn) {
    app()->instance(Clock::class, FixedClock::at('2026-11-08 00:30', 'Europe/Athens'));
    provisionTenant('acme');
    DB::table('tenant_acme.settings')->where('id', 1)->update(['start_of_week' => $startOfWeek]);
    [$implicit, $sam, $idle] = contractFixture();

    TenantMigration::takeBack('tenant_acme', TenantMigration::RULES_DROPPED);
    TenantMigration::takeBack('tenant_acme', TenantMigration::SHIFTS);
    expect(DB::getSchemaBuilder()->hasTable('tenant_acme.shift_patterns'))->toBeFalse();

    expect(Artisan::call('tenant:migrate', ['--slug' => 'acme']))->toBe(0);

    $default = array_map(fn (int $weekday): array => ['end' => '17:00', 'start' => '09:00', 'weekday' => $weekday], [1, 2, 3, 4, 5]);

    expect(contractPatterns('tenant_acme'))->toBe([
        $implicit => ['starts_on' => $startsOn, 'cadence_weeks' => 1, 'ends_on' => null, 'windows' => $default],
        $sam => ['starts_on' => $startsOn, 'cadence_weeks' => 1, 'ends_on' => null, 'windows' => [
            ['end' => '13:00', 'start' => '09:00', 'weekday' => 1],
            ['end' => '18:00', 'start' => '14:00', 'weekday' => 1],
            ['end' => '12:00', 'start' => '10:00', 'weekday' => 2],
            ['end' => '24:00', 'start' => '20:00', 'weekday' => 5],
        ]],
    ])
        ->and(array_key_exists($idle, contractPatterns('tenant_acme')))->toBeFalse()
        ->and(DB::getSchemaBuilder()->hasTable('tenant_acme.availability_rules'))->toBeFalse();
})->with([
    'a Monday start, Monday 1999-12-27' => [1, '1999-12-27'],
    'a Sunday start, Sunday 1999-12-26' => [7, '1999-12-26'],
]);
