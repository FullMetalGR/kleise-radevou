<?php

use App\Application\Clock;
use App\Infrastructure\Db\PostgresAvailabilityRepository;
use App\Infrastructure\Db\SettingsReader;
use App\Infrastructure\Db\WeeklyPattern;
use App\Tenancy\Tenant;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\FixedClock;
use Tests\Support\WeeklyHours;

pest()->use(RefreshDatabase::class);

/*
 * The demo hours: what luna's named staff hold after the seeder runs, that pier's implicit member
 * keeps the default week, that luna's two blackouts are there, and that a second run changes no
 * row; and that every week is a weekly shift pattern holding from the week of 2000-01-01, Monday
 * 1999-12-27, whatever the clock reads; it is pinned on Wednesday 2026-04-01 all the same, the
 * file spelling dates. 2026-04-06 is a Monday.
 */

beforeEach(function () {
    app()->instance(Clock::class, FixedClock::at('2026-04-01 09:00', 'Europe/Athens'));
});

/**
 * A staff member's weekly pattern as (weekday, start, end), in weekday then start order; a
 * pattern is checked weekly with no end on the way.
 *
 * @return list<array{int, string, string}>
 */
function weekOf(string $schema, string $name): array
{
    $staffId = DB::table($schema.'.staff')->where('name', $name)->value('id');
    assert(is_int($staffId));
    $pattern = WeeklyPattern::stored(DB::connection(), $schema.'.', [$staffId])[$staffId] ?? null;

    if ($pattern === null) {
        return [];
    }

    expect($pattern['cadence_weeks'])->toBe(1)->and($pattern['ends_on'])->toBeNull();

    return array_map(fn (array $window): array => [$window['weekday'], $window['start'], $window['end']], $pattern['windows']);
}

/**
 * @return list<array<string, mixed>>
 */
function blackoutsOf(string $schema): array
{
    $blackouts = [];

    foreach (DB::table($schema.'.blackouts', 'blackout')
        ->leftJoin($schema.'.staff as staff', 'staff.id', '=', 'blackout.staff_id')
        ->orderBy('blackout.date')
        ->orderBy('blackout.id')
        ->get(['staff.name as staff', 'blackout.date', 'blackout.start_time', 'blackout.end_time', 'blackout.reason']) as $row) {
        $blackouts[] = ['staff' => $row->staff, 'date' => $row->date, 'start_time' => $row->start_time, 'end_time' => $row->end_time, 'reason' => $row->reason];
    }

    return $blackouts;
}

it('gives luna\'s named staff different weeks, Elena\'s split around lunch, and leaves the implicit members on the default week', function () {
    seedDemo();

    $default = [[1, '09:00', '17:00'], [2, '09:00', '17:00'], [3, '09:00', '17:00'], [4, '09:00', '17:00'], [5, '09:00', '17:00']];

    expect(weekOf('tenant_luna', 'Elena Papadaki'))->toBe([
        [1, '09:00', '13:00'], [1, '14:00', '18:00'],
        [2, '09:00', '13:00'], [2, '14:00', '18:00'],
        [3, '09:00', '13:00'], [3, '14:00', '18:00'],
        [4, '09:00', '13:00'], [4, '14:00', '18:00'],
        [5, '09:00', '13:00'], [5, '14:00', '18:00'],
    ])->and(weekOf('tenant_luna', 'Nikos Georgiou'))->toBe([
        [2, '10:00', '19:00'], [3, '10:00', '19:00'], [4, '10:00', '19:00'], [5, '10:00', '19:00'], [6, '10:00', '19:00'],
    ])->and(weekOf('tenant_luna', 'Luna Hair Studio'))->toBe($default)
        ->and(weekOf('tenant_pier', 'Pier Physiotherapy'))->toBe($default)
        ->and(DB::table('tenant_pier.shift_patterns')->count())->toBe(1)
        ->and(DB::table('tenant_luna.shift_patterns')->count())->toBe(3)
        ->and(DB::table('tenant_luna.shift_patterns')->pluck('starts_on')->unique()->all())->toBe(['1999-12-27'])
        ->and(DB::table('tenant_pier.shift_patterns')->pluck('starts_on')->all())->toBe(['1999-12-27']);
});

it('gives luna one tenant-wide blackout on a date and one personal blackout with a window, and pier none', function () {
    seedDemo();

    expect(blackoutsOf('tenant_luna'))->toBe([
        ['staff' => 'Nikos Georgiou', 'date' => '2026-12-04', 'start_time' => '14:00:00', 'end_time' => '16:00:00', 'reason' => 'Dentist'],
        ['staff' => null, 'date' => '2026-12-25', 'start_time' => null, 'end_time' => null, 'reason' => 'Christmas Day'],
    ])->and(DB::table('tenant_pier.blackouts')->count())->toBe(0);
});

it('reads through the repository as the weeks describe: Elena\'s Monday has the lunch gap, Nikos is off on Monday and on Saturday, and Christmas closes everyone', function () {
    seedDemo();

    $tenant = Tenant::query()->where('slug', 'luna')->sole();
    $repository = new PostgresAvailabilityRepository(app(DatabaseManager::class), app(SettingsReader::class), $tenant, coldMaskCache());
    $elena = DB::table('tenant_luna.staff')->where('name', 'Elena Papadaki')->value('id');
    $nikos = DB::table('tenant_luna.staff')->where('name', 'Nikos Georgiou')->value('id');
    assert(is_int($elena) && is_int($nikos));

    $week = $repository->masks([$elena, $nikos], '2026-04-06', 7);
    $christmas = $repository->masks([$elena, $nikos], '2026-12-25', 1);

    expect($week->on($elena, '2026-04-06')->open->setIndices())->toBe([...range(108, 155), ...range(168, 215)])
        ->and($week->on($nikos, '2026-04-06')->open->setIndices())->toBe([])
        ->and($week->on($nikos, '2026-04-07')->open->setIndices())->toBe(range(120, 227))
        ->and($week->on($nikos, '2026-04-11')->open->setIndices())->toBe(range(120, 227))
        ->and($week->on($elena, '2026-04-11')->open->setIndices())->toBe([])
        ->and($christmas->on($elena, '2026-12-25')->open->setIndices())->toBe([])
        ->and($christmas->on($nikos, '2026-12-25')->open->setIndices())->toBe([]);
});

it('changes no row on a second run, and says so', function () {
    $first = seedDemo();
    $before = [
        'blackouts' => DB::table('tenant_luna.blackouts')->orderBy('id')->get()->all(),
        'pier' => DB::table('tenant_pier.shift_patterns')->orderBy('id')->get()->all(),
        'patterns' => DB::table('tenant_luna.shift_patterns')->orderBy('id')->get()->all(),
    ];

    $second = seedDemo();

    expect($first)->toContain('Hours of "luna": 15 windows and 2 blackouts added.')
        ->toContain('Hours of "pier" are already in place; nothing changed.')
        ->and($second)->toContain('Hours of "luna" are already in place; nothing changed.')
        ->toContain('Hours of "pier" are already in place; nothing changed.')
        ->and(DB::table('tenant_luna.blackouts')->orderBy('id')->get()->all())->toEqual($before['blackouts'])
        ->and(DB::table('tenant_pier.shift_patterns')->orderBy('id')->get()->all())->toEqual($before['pier'])
        ->and(DB::table('tenant_luna.shift_patterns')->orderBy('id')->get()->all())->toEqual($before['patterns']);
});

it('keeps hours a member already holds: a run after Elena\'s week was replaced adds nothing to her', function () {
    seedDemo();
    $elena = DB::table('tenant_luna.staff')->where('name', 'Elena Papadaki')->value('id');
    assert(is_int($elena));
    WeeklyHours::clear('tenant_luna', $elena);
    WeeklyHours::add('tenant_luna', $elena, 6, '10:00:00', '14:00:00');

    seedDemo();

    expect(weekOf('tenant_luna', 'Elena Papadaki'))->toBe([[6, '10:00', '14:00']]);
});
