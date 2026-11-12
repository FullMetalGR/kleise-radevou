<?php

use App\Domain\Availability\FreeBusyMask;
use App\Domain\Availability\Granularity;
use App\Domain\Availability\StaffDayMaskSet;
use App\Domain\Availability\WindowGeometry;
use App\Infrastructure\Db\MasksFromRows;
use App\Infrastructure\Db\NotBookedMask;
use App\Infrastructure\Db\PostgresAvailabilityRepository;
use App\Infrastructure\Db\SettingsReader;
use App\Infrastructure\Db\SqlText;
use App\Tenancy\Tenant;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\WeeklyHours;

pest()->use(RefreshDatabase::class);

/*
 * The availability repository over real rows on a tenant in Europe/Athens: weekday rules through
 * the helper that writes their weekly pattern beside them, patterns and overrides written whole,
 * blackouts and booking items in, the two masks of every staff-day out, through the geometry and
 * the builders. The read opens no transaction of its own, so the wrapping transaction of this suite
 * is harmless, and now() inside it is the transaction's start, which every expiry here is set
 * against. The repository runs over the null store here, so every read is cold and about the rows;
 * the cache has its own file.
 *
 * 2026-04-06 is a Monday and April in Athens is UTC+3, so 09:00 local is slot 108 and 06:00Z.
 */

beforeEach(function () {
    provisionTenant('acme');
    itemService('tenant_acme');
    $this->ann = DB::table('tenant_acme.staff')->insertGetId(['name' => 'Ann', 'status' => 'active', 'sort' => 1]);
    $this->ben = DB::table('tenant_acme.staff')->insertGetId(['name' => 'Ben', 'status' => 'active', 'sort' => 2]);
    $this->cal = DB::table('tenant_acme.staff')->insertGetId(['name' => 'Cal', 'status' => 'active', 'sort' => 3]);
});

function availabilityRepository(): PostgresAvailabilityRepository
{
    return new PostgresAvailabilityRepository(
        app(DatabaseManager::class),
        app(SettingsReader::class),
        Tenant::query()->where('slug', 'acme')->sole(),
        coldMaskCache(),
    );
}

function addRule(int $staffId, int $weekday, string $start, string $end): void
{
    WeeklyHours::add('tenant_acme', $staffId, $weekday, $start, $end);
}

/**
 * The days of the window on which the member's open mask has a slot set.
 *
 * @return list<string>
 */
function openDaysOf(StaffDayMaskSet $set, int $staffId): array
{
    $openDays = [];

    foreach ($set->window->days as $day) {
        if ($set->on($staffId, $day->day)->open->setIndices() !== []) {
            $openDays[] = $day->day;
        }
    }

    return $openDays;
}

function addWeek(int $staffId, string $start = '09:00:00', string $end = '17:00:00'): void
{
    foreach ([1, 2, 3, 4, 5] as $weekday) {
        addRule($staffId, $weekday, $start, $end);
    }
}

function addBlackout(?int $staffId, string $date, ?string $start = null, ?string $end = null): void
{
    DB::table('tenant_acme.blackouts')->insert(['staff_id' => $staffId, 'date' => $date, 'start_time' => $start, 'end_time' => $end, 'reason' => 'test']);
}

/**
 * A booking in the given status with one item on the staff member, its block range as UTC text
 * and its expiry, when it has one, an hour ahead of the transaction's now or a minute behind it.
 */
function addItem(int $staffId, string $status, string $blockStart, string $blockEnd, ?string $expiry = null, bool $blocks = true, string $onApprovalTimeout = 'expire'): int
{
    $bookingId = DB::table('tenant_acme.bookings')->insertGetId([
        ...bookingRow(),
        'status' => $status,
        'hold_class' => in_array($status, ['pending', 'awaiting_approval', 'awaiting_payment'], true) ? 'checkout' : null,
        'hold_expires_at' => match ($expiry) {
            'live' => DB::raw("now() + interval '1 hour'"),
            'lapsed' => DB::raw("now() - interval '1 minute'"),
            null => null,
            default => throw new InvalidArgumentException($expiry),
        },
        'on_approval_timeout' => $onApprovalTimeout,
    ]);

    DB::table('tenant_acme.booking_items')->insert([
        ...itemRow($bookingId, $staffId, $blockStart, $blockEnd),
        'start_utc' => '2026-04-06 07:00:00+00',
        'end_utc' => '2026-04-06 08:00:00+00',
        'blocks' => $blocks,
    ]);

    return $bookingId;
}

/**
 * @param  non-empty-list<int>  $staffIds
 */
function masksOf(array $staffIds, string $firstDay = '2026-04-06', int $dayCount = 1): StaffDayMaskSet
{
    return availabilityRepository()->masks($staffIds, $firstDay, $dayCount);
}

it('answers one pair of masks per day of the window for every staff member asked for, a member with no rows reading closed and unbooked', function () {
    addWeek($this->ann);

    $set = masksOf([$this->ann, $this->ben], '2026-04-06', 2);

    expect($set->staffIds())->toBe([$this->ann, $this->ben])
        ->and($set->window->days[0]->day)->toBe('2026-04-06')
        ->and($set->window->days[1]->day)->toBe('2026-04-07')
        ->and($set->daysOf($this->ann))->toHaveCount(2)
        ->and($set->on($this->ann, '2026-04-06')->open->setIndices())->toBe(range(108, 203))
        ->and($set->on($this->ann, '2026-04-07')->open->setIndices())->toBe(range(108, 203))
        ->and($set->on($this->ann, '2026-04-06')->notBooked->equals(FreeBusyMask::allSet(288)))->toBeTrue()
        ->and($set->on($this->ben, '2026-04-06')->open->setIndices())->toBe([])
        ->and($set->on($this->ben, '2026-04-06')->notBooked->equals(FreeBusyMask::allSet(288)))->toBeTrue();
});

it('opens a pattern\'s weekday only on dates from its starts_on to its ends_on, both ends inclusive, and no other weekday', function () {
    WeeklyHours::pattern('tenant_acme', $this->ann, '2026-04-06', 1, '2026-04-13', [[1, '09:00', '17:00']]);

    $set = masksOf([$this->ann], '2026-03-30', 22);

    expect(openDaysOf($set, $this->ann))->toBe(['2026-04-06', '2026-04-13'])
        ->and($set->on($this->ann, '2026-04-13')->open->setIndices())->toBe(range(108, 203));
});

it('opens a pattern of two weeks on the week holding its starts_on and every second week after, counted from that week\'s first day on the tenant\'s week start', function () {
    WeeklyHours::pattern('tenant_acme', $this->ann, '2026-04-08', 2, null, [[1, '09:00', '17:00'], [3, '09:00', '17:00']]);

    expect(openDaysOf(masksOf([$this->ann], '2026-03-30', 36), $this->ann))->toBe(['2026-04-08', '2026-04-20', '2026-04-22', '2026-05-04']);

    DB::table('tenant_acme.settings')->where('id', 1)->update(['start_of_week' => 3]);

    expect(openDaysOf(masksOf([$this->ann], '2026-03-30', 36), $this->ann))->toBe(['2026-04-08', '2026-04-13', '2026-04-22', '2026-04-27']);
});

it('opens a pattern of three weeks on every third week, and keeps it to its ends_on', function () {
    WeeklyHours::pattern('tenant_acme', $this->ann, '2026-04-06', 3, null, [[1, '09:00', '17:00']]);

    expect(openDaysOf(masksOf([$this->ann], '2026-03-30', 56), $this->ann))->toBe(['2026-04-06', '2026-04-27', '2026-05-18']);

    WeeklyHours::pattern('tenant_acme', $this->ann, '2026-04-06', 3, '2026-05-17', [[1, '09:00', '17:00']]);

    expect(openDaysOf(masksOf([$this->ann], '2026-03-30', 56), $this->ann))->toBe(['2026-04-06', '2026-04-27']);
});

it('reads a date\'s override before the pattern: its windows that day, an empty one not working, and the pattern on every other date', function () {
    WeeklyHours::pattern('tenant_acme', $this->ann, '2026-03-30', 1, null, [[1, '09:00', '17:00'], [2, '09:00', '17:00']]);
    DB::table('tenant_acme.shift_overrides')->insert([
        ['staff_id' => $this->ann, 'date' => '2026-04-06', 'windows' => '[]'],
        ['staff_id' => $this->ann, 'date' => '2026-04-07', 'windows' => '[{"start": "12:00", "end": "13:00"}, {"start": "22:00", "end": "24:00"}]'],
        ['staff_id' => $this->ann, 'date' => '2026-04-08', 'windows' => '[{"start": "10:00", "end": "11:00"}]'],
        ['staff_id' => $this->ben, 'date' => '2026-04-08', 'windows' => '[{"start": "10:00", "end": "11:00"}]'],
    ]);

    $set = masksOf([$this->ann, $this->cal], '2026-04-06', 8);

    expect($set->on($this->ann, '2026-04-06')->open->setIndices())->toBe([])
        ->and($set->on($this->ann, '2026-04-07')->open->setIndices())->toBe([...range(144, 155), ...range(264, 287)])
        ->and($set->on($this->ann, '2026-04-08')->open->setIndices())->toBe(range(120, 131))
        ->and($set->on($this->ann, '2026-04-13')->open->setIndices())->toBe(range(108, 203))
        ->and($set->on($this->cal, '2026-04-08')->open->setIndices())->toBe([]);
});

it('subtracts a closure from an override\'s windows as from the pattern\'s', function () {
    DB::table('tenant_acme.shift_overrides')->insert(['staff_id' => $this->ann, 'date' => '2026-04-08', 'windows' => '[{"start": "09:00", "end": "17:00"}]']);
    addBlackout($this->ann, '2026-04-08', '12:00:00', '13:00:00');

    expect(masksOf([$this->ann], '2026-04-08')->on($this->ann, '2026-04-08')->open->setIndices())->toBe([...range(108, 143), ...range(156, 203)]);
});

it('yields two runs of free slots for a split day around lunch', function () {
    addRule($this->ann, 1, '09:00:00', '12:00:00');
    addRule($this->ann, 1, '13:00:00', '17:00:00');

    expect(masksOf([$this->ann])->on($this->ann, '2026-04-06')->open->setIndices())->toBe([...range(108, 143), ...range(156, 203)]);
});

it('sets the slots to the end of the day for a rule closing at 24:00:00', function () {
    addRule($this->ann, 1, '22:00:00', '24:00:00');

    expect(masksOf([$this->ann])->on($this->ann, '2026-04-06')->open->setIndices())->toBe(range(264, 287));
});

it('closes the day for every staff member asked for on a tenant-wide blackout without times, and only that day', function () {
    addWeek($this->ann);
    addWeek($this->ben);
    addBlackout(null, '2026-04-06');

    $set = masksOf([$this->ann, $this->ben], '2026-04-06', 2);

    expect($set->on($this->ann, '2026-04-06')->open->setIndices())->toBe([])
        ->and($set->on($this->ben, '2026-04-06')->open->setIndices())->toBe([])
        ->and($set->on($this->ann, '2026-04-07')->open->setIndices())->toBe(range(108, 203))
        ->and($set->on($this->ben, '2026-04-07')->open->setIndices())->toBe(range(108, 203));
});

it('closes the window of a personal blackout with times for that member alone', function () {
    addWeek($this->ann);
    addWeek($this->ben);
    addBlackout($this->ann, '2026-04-07', '12:00:00', '13:00:00');

    $set = masksOf([$this->ann, $this->ben], '2026-04-06', 2);

    expect($set->on($this->ann, '2026-04-07')->open->setIndices())->toBe([...range(108, 143), ...range(156, 203)])
        ->and($set->on($this->ben, '2026-04-07')->open->setIndices())->toBe(range(108, 203))
        ->and($set->on($this->ann, '2026-04-06')->open->setIndices())->toBe(range(108, 203));
});

it('reads a blackout window on the autumn clock-change day from rows and closes both passes of the repeated hour', function () {
    addRule($this->ann, 7, '00:00:00', '24:00:00');
    addBlackout($this->ann, '2026-10-25', '03:00:00', '04:00:00');

    $masks = masksOf([$this->ann], '2026-10-25')->on($this->ann, '2026-10-25');

    expect($masks->open->width)->toBe(300)
        ->and($masks->open->setIndices())->toBe([...range(0, 35), ...range(60, 299)]);
});

/*
 * One item on Ann from 10:00 to 11:00 local with a ten-minute buffer either side: the block range
 * is 09:50 to 11:10, slots 118 to 133. What the not-booked mask shows depends on the booking's
 * status, its expiry against now() and its timeout policy, never on the blocks flag.
 */
it('reads the item of a booking that is {what} as {reading}', function (string $status, ?string $expiry, bool $blocks, string $onApprovalTimeout, string $reading) {
    addItem($this->ann, $status, '2026-04-06 06:50:00+00', '2026-04-06 08:10:00+00', $expiry, $blocks, $onApprovalTimeout);

    $notBooked = masksOf([$this->ann])->on($this->ann, '2026-04-06')->notBooked;

    expect($notBooked->setIndices())->toBe($reading === 'busy' ? [...range(0, 117), ...range(134, 287)] : range(0, 287));
})->with([
    'confirmed' => ['confirmed', null, true, 'expire', 'busy'],
    'pending with a live hold' => ['pending', 'live', true, 'expire', 'busy'],
    'awaiting approval with a live hold' => ['awaiting_approval', 'live', true, 'expire', 'busy'],
    'awaiting payment with a live hold' => ['awaiting_payment', 'live', true, 'expire', 'busy'],
    'pending with a lapsed hold, no sweeper having run' => ['pending', 'lapsed', true, 'expire', 'free'],
    'awaiting payment with a lapsed hold' => ['awaiting_payment', 'lapsed', true, 'expire', 'free'],
    'cancelled' => ['cancelled', null, false, 'expire', 'free'],
    'rejected' => ['rejected', null, false, 'expire', 'free'],
    'expired' => ['expired', null, false, 'expire', 'free'],
    'completed' => ['completed', null, true, 'expire', 'free'],
    'a no-show' => ['no_show', null, true, 'expire', 'free'],
    'expired with its blocks flag still true' => ['expired', null, true, 'expire', 'free'],
    'confirmed with its blocks flag false' => ['confirmed', null, false, 'expire', 'busy'],
    'awaiting approval past its expiry under auto_approve' => ['awaiting_approval', 'lapsed', true, 'auto_approve', 'busy'],
    'awaiting approval past its expiry under expire' => ['awaiting_approval', 'lapsed', true, 'expire', 'free'],
    'awaiting approval with a live hold under auto_approve' => ['awaiting_approval', 'live', true, 'auto_approve', 'busy'],
]);

it('leaves the open mask untouched by an item and the not-booked mask untouched by a rule: the two masks stay separate', function () {
    addWeek($this->ann);
    addItem($this->ann, 'confirmed', '2026-04-06 03:00:00+00', '2026-04-06 04:00:00+00');

    $masks = masksOf([$this->ann])->on($this->ann, '2026-04-06');

    expect($masks->open->setIndices())->toBe(range(108, 203))
        ->and($masks->notBooked->setIndices())->toBe([...range(0, 71), ...range(84, 287)]);
});

it('clears an item on one member and nothing on another', function () {
    addItem($this->ann, 'confirmed', '2026-04-06 06:50:00+00', '2026-04-06 08:10:00+00');

    $set = masksOf([$this->ann, $this->ben]);

    expect($set->on($this->ann, '2026-04-06')->notBooked->setIndices())->toBe([...range(0, 117), ...range(134, 287)])
        ->and($set->on($this->ben, '2026-04-06')->notBooked->equals(FreeBusyMask::allSet(288)))->toBeTrue();
});

it('clears the tail of one day and the head of the next for an item crossing local midnight, and only the next day for one starting exactly at it', function () {
    // 23:30 to 00:30 local is 20:30Z to 21:30Z; local midnight of the 7th is 21:00Z.
    addItem($this->ann, 'confirmed', '2026-04-06 20:30:00+00', '2026-04-06 21:30:00+00');
    addItem($this->ben, 'confirmed', '2026-04-06 21:00:00+00', '2026-04-06 21:30:00+00');
    addItem($this->cal, 'confirmed', '2026-04-06 20:00:00+00', '2026-04-06 21:00:00+00');

    $set = masksOf([$this->ann, $this->ben, $this->cal], '2026-04-06', 2);

    expect($set->on($this->ann, '2026-04-06')->notBooked->setIndices())->toBe(range(0, 281))
        ->and($set->on($this->ann, '2026-04-07')->notBooked->setIndices())->toBe(range(6, 287))
        ->and($set->on($this->ben, '2026-04-06')->notBooked->equals(FreeBusyMask::allSet(288)))->toBeTrue()
        ->and($set->on($this->ben, '2026-04-07')->notBooked->setIndices())->toBe(range(6, 287))
        ->and($set->on($this->cal, '2026-04-06')->notBooked->setIndices())->toBe(range(0, 275))
        ->and($set->on($this->cal, '2026-04-07')->notBooked->equals(FreeBusyMask::allSet(288)))->toBeTrue();
});

it('ignores an item outside the window and one on a member not asked for', function () {
    addItem($this->ann, 'confirmed', '2026-04-05 06:50:00+00', '2026-04-05 08:10:00+00');
    addItem($this->ann, 'confirmed', '2026-04-07 06:50:00+00', '2026-04-07 08:10:00+00');
    addItem($this->ben, 'confirmed', '2026-04-06 06:50:00+00', '2026-04-06 08:10:00+00');

    expect(masksOf([$this->ann])->on($this->ann, '2026-04-06')->notBooked->equals(FreeBusyMask::allSet(288)))->toBeTrue();
});

it('fetches three staff members over seven days with one patterns statement, one overrides statement, one blackouts statement, one revisions statement and one items statement carrying the one predicate, after the settings read', function () {
    addWeek($this->ann);
    addWeek($this->ben);
    addBlackout(null, '2026-04-08');
    addItem($this->cal, 'confirmed', '2026-04-06 06:50:00+00', '2026-04-06 08:10:00+00');

    $repository = availabilityRepository();
    DB::enableQueryLog();
    $repository->masks([$this->ann, $this->ben, $this->cal], '2026-04-06', 7);
    $log = DB::getQueryLog();
    DB::disableQueryLog();

    $naming = fn (string $table): array => array_values(array_filter($log, fn (array $entry): bool => str_contains($entry['query'], $table)));

    expect($log)->toHaveCount(6)
        ->and($log[0]['query'])->toContain('settings')
        ->and($naming('shift_patterns'))->toHaveCount(1)
        ->and($naming('shift_overrides'))->toHaveCount(1)
        ->and($naming('blackouts'))->toHaveCount(1)
        ->and($naming('staff_days'))->toHaveCount(1)
        ->and($naming('booking_items'))->toHaveCount(1)
        ->and($naming('booking_items')[0]['query'])->toContain(MasksFromRows::blockingPredicate())
        ->and($naming('shift_patterns')[0]['bindings'])->toBe([sprintf('{%d,%d,%d}', $this->ann, $this->ben, $this->cal)])
        ->and($naming('shift_overrides')[0]['bindings'])->toBe(['2026-04-06', '2026-04-12', sprintf('{%d,%d,%d}', $this->ann, $this->ben, $this->cal)])
        ->and($naming('blackouts')[0]['bindings'])->toBe(['2026-04-06', '2026-04-12', sprintf('{%d,%d,%d}', $this->ann, $this->ben, $this->cal)])
        ->and($naming('staff_days')[0]['bindings'])->toBe([sprintf('{%d,%d,%d}', $this->ann, $this->ben, $this->cal), '2026-04-06', '2026-04-12'])
        ->and($naming('booking_items')[0]['bindings'])->toBe([sprintf('{%d,%d,%d}', $this->ann, $this->ben, $this->cal), '2026-04-05 21:00:00.000000+00:00', '2026-04-12 21:00:00.000000+00:00']);
});

it('binds every instant as UTC text with its offset and never a date object', function () {
    $repository = availabilityRepository();
    DB::enableQueryLog();
    $repository->masks([$this->ann], '2026-10-25', 2);
    $log = DB::getQueryLog();
    DB::disableQueryLog();

    $instants = [];

    foreach ($log as $entry) {
        foreach ($entry['bindings'] as $binding) {
            expect($binding)->not->toBeInstanceOf(DateTimeInterface::class);

            if (is_string($binding) && preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}/', $binding) === 1) {
                $instants[] = $binding;
            }
        }
    }

    expect($instants)->toBe(['2026-10-24 21:00:00.000000+00:00', '2026-10-26 22:00:00.000000+00:00'])
        ->and($instants)->each->toMatch('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}\+00:00$/');
});

it('builds the two clock-change days at their own widths, 276 and 300, with 96 slots from a 09:00 to 17:00 rule on each', function () {
    addRule($this->ann, 7, '09:00:00', '17:00:00');

    $spring = masksOf([$this->ann], '2026-03-29')->on($this->ann, '2026-03-29');
    $autumn = masksOf([$this->ann], '2026-10-25')->on($this->ann, '2026-10-25');

    expect($spring->open->width)->toBe(276)
        ->and($spring->notBooked->width)->toBe(276)
        ->and($spring->open->setIndices())->toBe(range(96, 191))
        ->and($autumn->open->width)->toBe(300)
        ->and($autumn->notBooked->width)->toBe(300)
        ->and($autumn->open->setIndices())->toBe(range(120, 215));
});

it('takes the granularity from the settings row', function () {
    DB::table('tenant_acme.settings')->where('id', 1)->update(['granularity_min' => 15]);
    addWeek($this->ann);

    $masks = masksOf([$this->ann])->on($this->ann, '2026-04-06');

    expect($masks->open->width)->toBe(96)
        ->and($masks->open->setIndices())->toBe(range(36, 67))
        ->and(masksOf([$this->ann])->window->granularity->equals(Granularity::minutes(15)))->toBeTrue();
});

it('is one code path with two callers: the same rows read on a connection whose search path is already set give the same masks', function () {
    addWeek($this->ann);
    addBlackout($this->ann, '2026-04-07', '12:00:00', '13:00:00');
    addItem($this->ann, 'confirmed', '2026-04-06 06:50:00+00', '2026-04-06 08:10:00+00');
    addItem($this->ann, 'pending', '2026-04-07 06:50:00+00', '2026-04-07 08:10:00+00', 'lapsed');

    $qualified = masksOf([$this->ann, $this->ben], '2026-04-06', 2);

    $window = WindowGeometry::spanning('2026-04-06', 2, new DateTimeZone('Europe/Athens'), Granularity::default());
    DB::statement('set local search_path to "tenant_acme", "public"');
    $onPath = MasksFromRows::onSearchPath(DB::connection())->build($window, [$this->ann, $this->ben]);
    DB::statement('set local search_path to public');

    foreach ([$this->ann, $this->ben] as $staffId) {
        foreach (['2026-04-06', '2026-04-07'] as $day) {
            expect($onPath->on($staffId, $day)->open->equals($qualified->on($staffId, $day)->open))->toBeTrue()
                ->and($onPath->on($staffId, $day)->notBooked->equals($qualified->on($staffId, $day)->notBooked))->toBeTrue();
        }
    }

    expect($qualified->on($this->ann, '2026-04-06')->notBooked->setIndices())->toBe([...range(0, 117), ...range(134, 287)])
        ->and($qualified->on($this->ann, '2026-04-07')->open->setIndices())->toBe([...range(108, 143), ...range(156, 203)])
        ->and($qualified->on($this->ann, '2026-04-07')->notBooked->equals(FreeBusyMask::allSet(288)))->toBeTrue()
        ->and(DB::scalar('select current_schema()'))->toBe('public');
});

it('refuses a window of no days and a staff id below one before touching the rows', function () {
    DB::enableQueryLog();

    expect(fn () => masksOf([$this->ann], '2026-04-06', 0))->toThrow(InvalidArgumentException::class, 'at least one day')
        ->and(fn () => masksOf([0]))->toThrow(InvalidArgumentException::class, 'A staff id is a positive integer, not 0.')
        ->and(array_filter(DB::getQueryLog(), fn (array $entry): bool => str_contains($entry['query'], 'shift_patterns')))->toBe([]);

    DB::disableQueryLog();
});

/**
 * The not-booked half alone, as the cache reader asks for it: per staff member, per day of
 * the window in Europe/Athens, the mask and the earliest hold expiry beside it.
 *
 * @param  non-empty-list<int>  $staffIds
 * @return non-empty-array<int, list<NotBookedMask>>
 */
function notBookedOf(array $staffIds, string $firstDay = '2026-04-06', int $dayCount = 1): array
{
    $window = WindowGeometry::spanning($firstDay, $dayCount, new DateTimeZone('Europe/Athens'), Granularity::default());

    return MasksFromRows::forSchema(DB::connection(), 'tenant_acme')->notBookedMasks($window, $staffIds);
}

/**
 * A booking's expiry as the server spells it back, the spelling the reader compares in.
 */
function expiryOf(int $bookingId): string
{
    $expiry = DB::scalar('select '.SqlText::instantColumn('hold_expires_at').' from tenant_acme.bookings where id = ?', [$bookingId]);

    assert(is_string($expiry));

    return $expiry;
}

function spelled(?DateTimeImmutable $instant): ?string
{
    return $instant?->format(SqlText::INSTANT);
}

it('carries beside each not-booked mask the earliest expiry among the live held items that cleared a slot on that day, an auto-approving request\'s window among them though it stays busy past it, since the sweeper grants it there into a payment hold no revision announces, and none where only a write frees them', function () {
    $sooner = addItem($this->ann, 'pending', '2026-04-06 06:50:00+00', '2026-04-06 08:10:00+00', 'live');
    $later = addItem($this->ann, 'awaiting_payment', '2026-04-06 09:00:00+00', '2026-04-06 10:00:00+00', 'live');
    DB::table('tenant_acme.bookings')->where('id', $later)->update(['hold_expires_at' => DB::raw("now() + interval '2 hours'")]);
    addItem($this->ann, 'confirmed', '2026-04-06 11:00:00+00', '2026-04-06 12:00:00+00');
    addItem($this->ben, 'confirmed', '2026-04-06 06:50:00+00', '2026-04-06 08:10:00+00');
    $granted = addItem($this->cal, 'awaiting_approval', '2026-04-06 06:50:00+00', '2026-04-06 08:10:00+00', 'lapsed', true, 'auto_approve');

    $masks = notBookedOf([$this->ann, $this->ben, $this->cal]);

    expect($masks[$this->ann][0]->mask->setIndices())->toBe([...range(0, 117), ...range(134, 143), ...range(156, 167), ...range(180, 287)])
        ->and(spelled($masks[$this->ann][0]->earliestHoldExpiry))->toBe(expiryOf($sooner))
        ->and($masks[$this->ben][0]->mask->setIndices())->toBe([...range(0, 117), ...range(134, 287)])
        ->and($masks[$this->ben][0]->earliestHoldExpiry)->toBeNull()
        ->and($masks[$this->cal][0]->mask->setIndices())->toBe([...range(0, 117), ...range(134, 287)])
        ->and(spelled($masks[$this->cal][0]->earliestHoldExpiry))->toBe(expiryOf($granted));
});

it('lets a lapsed hold bound nothing, since the statement never returns it', function () {
    addItem($this->ann, 'pending', '2026-04-06 06:50:00+00', '2026-04-06 08:10:00+00', 'lapsed');

    $masks = notBookedOf([$this->ann]);

    expect($masks[$this->ann][0]->mask->equals(FreeBusyMask::allSet(288)))->toBeTrue()
        ->and($masks[$this->ann][0]->earliestHoldExpiry)->toBeNull();
});

it('bounds a day by a live hold only where the hold cleared a slot of it: one crossing midnight bounds both days, one starting at midnight or lying on the next day bounds that day alone', function () {
    $crossing = addItem($this->ann, 'pending', '2026-04-06 20:30:00+00', '2026-04-06 21:30:00+00', 'live');
    $atMidnight = addItem($this->ben, 'pending', '2026-04-06 21:00:00+00', '2026-04-06 21:30:00+00', 'live');
    $tuesday = addItem($this->cal, 'pending', '2026-04-07 06:50:00+00', '2026-04-07 08:10:00+00', 'live');

    $masks = notBookedOf([$this->ann, $this->ben, $this->cal], '2026-04-06', 2);

    expect(spelled($masks[$this->ann][0]->earliestHoldExpiry))->toBe(expiryOf($crossing))
        ->and(spelled($masks[$this->ann][1]->earliestHoldExpiry))->toBe(expiryOf($crossing))
        ->and($masks[$this->ben][0]->earliestHoldExpiry)->toBeNull()
        ->and(spelled($masks[$this->ben][1]->earliestHoldExpiry))->toBe(expiryOf($atMidnight))
        ->and($masks[$this->cal][0]->earliestHoldExpiry)->toBeNull()
        ->and(spelled($masks[$this->cal][1]->earliestHoldExpiry))->toBe(expiryOf($tuesday))
        ->and($masks[$this->ben][0]->mask->equals(FreeBusyMask::allSet(288)))->toBeTrue();
});

it('joins the two halves in build to the same masks the repository answers', function () {
    addWeek($this->ann);
    addBlackout($this->ann, '2026-04-07', '12:00:00', '13:00:00');
    addItem($this->ann, 'confirmed', '2026-04-06 06:50:00+00', '2026-04-06 08:10:00+00');

    $window = WindowGeometry::spanning('2026-04-06', 2, new DateTimeZone('Europe/Athens'), Granularity::default());
    $rows = MasksFromRows::forSchema(DB::connection(), 'tenant_acme');
    $built = $rows->build($window, [$this->ann]);
    $open = $rows->openMasks($window, [$this->ann]);
    $notBooked = $rows->notBookedMasks($window, [$this->ann]);
    $answered = masksOf([$this->ann], '2026-04-06', 2);

    foreach ([0, 1] as $position) {
        $day = $window->days[$position]->day;

        expect($open[$this->ann][$position]->equals($built->on($this->ann, $day)->open))->toBeTrue()
            ->and($notBooked[$this->ann][$position]->mask->equals($built->on($this->ann, $day)->notBooked))->toBeTrue()
            ->and($answered->on($this->ann, $day)->open->equals($built->on($this->ann, $day)->open))->toBeTrue()
            ->and($answered->on($this->ann, $day)->notBooked->equals($built->on($this->ann, $day)->notBooked))->toBeTrue();
    }
});

it('counts each member\'s load on one day as their blocking items overlapping it by the one predicate, a member with none absent, an item crossing in from the day before counted and one on the next day not', function () {
    addItem($this->ann, 'confirmed', '2026-04-06 06:50:00+00', '2026-04-06 08:10:00+00');
    addItem($this->ann, 'pending', '2026-04-06 09:00:00+00', '2026-04-06 10:00:00+00', 'live');
    addItem($this->ann, 'pending', '2026-04-06 11:00:00+00', '2026-04-06 12:00:00+00', 'lapsed');
    addItem($this->ann, 'cancelled', '2026-04-06 13:00:00+00', '2026-04-06 14:00:00+00');
    addItem($this->ann, 'awaiting_approval', '2026-04-06 15:00:00+00', '2026-04-06 16:00:00+00', 'lapsed', true, 'auto_approve');
    addItem($this->ben, 'confirmed', '2026-04-05 20:30:00+00', '2026-04-05 21:30:00+00');
    addItem($this->ben, 'confirmed', '2026-04-06 21:00:00+00', '2026-04-06 22:00:00+00');
    addItem($this->cal, 'confirmed', '2026-04-06 21:30:00+00', '2026-04-06 22:30:00+00');

    $repository = availabilityRepository();
    DB::enableQueryLog();
    $loads = $repository->loads([$this->ann, $this->ben, $this->cal], '2026-04-06');
    $log = DB::getQueryLog();
    DB::disableQueryLog();

    // Ann: the confirmed item, the live hold and the auto-approving request; Ben: the item
    // crossing in from Sunday night, not the one on Tuesday; Cal: only an item on Tuesday.
    expect($loads)->toBe([$this->ann => 3, $this->ben => 1])
        ->and($log)->toHaveCount(2)
        ->and($log[0]['query'])->toContain('settings')
        ->and($log[1]['query'])->toBe(MasksFromRows::loadsSql('"tenant_acme".'))
        ->and($log[1]['bindings'])->toBe([sprintf('{%d,%d,%d}', $this->ann, $this->ben, $this->cal), '2026-04-05 21:00:00.000000+00:00', '2026-04-06 21:00:00.000000+00:00']);
});
