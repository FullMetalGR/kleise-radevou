<?php

use App\Application\Clock;
use App\Application\Dto\Customer;
use App\Application\Dto\HoldRequest;
use App\Application\Dto\Intake;
use App\Application\Dto\SegmentChoice;
use App\Domain\Audit\Actor;
use App\Domain\Availability\Granularity;
use App\Domain\Enum\BookingSource;
use App\Infrastructure\Cache\StaffDayMaskCache;
use App\Infrastructure\Db\Pool;
use App\Infrastructure\HoldBookingFactory;
use App\Tenancy\Tenant;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FixedClock;
use Tests\Support\StartInterval;
use Tests\Support\WeeklyHours;
use Tests\TestCase;

pest()->use(DatabaseTruncation::class);

/*
 * The mask cache on the wire, GET /api/v1/availability over the array store: the cold and warm
 * comparison, what a warm read costs, what a hold does to the key, what two tenants never share,
 * what is never cached, what a lapsing hold does to an entry, and the round trip at the three
 * widths a year in Athens has.
 *
 * Not wrapped in a transaction: one test holds a booking through the transaction runner, which
 * refuses to open inside another. The central tables are truncated before each test and the
 * tenant schemas dropped and the central rows deleted after, so the wrapped files that follow
 * start from nothing. The endpoint file is this file's cold base; its fixture helpers are
 * repeated here under their own names so that this file runs on its own.
 *
 * Every tenant here offers a start every five minutes, the grid's own step, so each answer holds
 * every free position of its masks; the interval thins an answer after the masks are read and
 * has no part in what is stored.
 *
 * 2026-04-06 is a Monday and April in Athens is UTC+3, so 09:00 local is 06:00Z.
 */

beforeEach(function () {
    dropCacheTenants();
});

afterEach(function () {
    dropCacheTenants();
    DB::table('users')->delete();
    DB::table('tenants')->delete();
});

function dropCacheTenants(): void
{
    dropDemoSchemas();

    foreach (['acme', 'bravo'] as $slug) {
        DB::statement(sprintf('drop schema if exists tenant_%s cascade', $slug));
    }
}

function cacheClockAt(string $text): void
{
    app()->instance(Clock::class, FixedClock::at($text));
}

/**
 * Binds the mask cache over a fresh array store and hands the store back, so a test can look
 * inside it. Every read after this is warm once it has been made once.
 */
function warmStore(): CacheRepository
{
    $store = new CacheRepository(new ArrayStore);
    app()->instance(StaffDayMaskCache::class, new StaffDayMaskCache($store));

    return $store;
}

function nullStore(): void
{
    app()->instance(StaffDayMaskCache::class, coldMaskCache());
}

/**
 * @param  list<array{int, int|null}>  $items
 */
function cacheUrl(string $slug, array $items, string $from, string $to): string
{
    $query = [
        'items' => (string) json_encode(array_map(fn (array $item): array => ['service_id' => $item[0], 'staff_id' => $item[1]], $items)),
        'from' => $from,
        'to' => $to,
    ];

    return tenantUrl($slug, '/api/v1/availability?'.http_build_query($query));
}

/**
 * One GET with the query log captured: the response and the statements it ran.
 *
 * @return array{TestResponse<Response>, list<string>}
 */
function readLogged(TestCase $test, string $url): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $response = $test->getJson($url)->assertOk();
    $log = DB::getQueryLog();
    DB::disableQueryLog();
    DB::flushQueryLog();

    return [$response, array_values(array_map(fn (array $entry): string => $entry['query'], $log))];
}

/**
 * @param  list<string>  $statements
 */
function naming(array $statements, string $table): int
{
    return count(array_filter($statements, fn (string $statement): bool => str_contains($statement, $table)));
}

/**
 * Every wall-clock reading from one to another inclusive on a day in Athens, five minutes
 * apart, spelled as the wire spells a start.
 *
 * @return list<string>
 */
function athensEvery(string $day, string $from, string $to): array
{
    $local = new DateTimeZone('Europe/Athens');
    $utc = new DateTimeZone('UTC');
    $end = new DateTimeImmutable($day.' '.$to, $local);
    $starts = [];

    for ($at = new DateTimeImmutable($day.' '.$from, $local); $at <= $end; $at = $at->add(new DateInterval('PT5M'))) {
        $starts[] = $at->setTimezone($utc)->format(DateTimeImmutable::RFC3339);
    }

    return $starts;
}

function athens(string $text): DateTimeImmutable
{
    return (new DateTimeImmutable($text, new DateTimeZone('Europe/Athens')))->setTimezone(new DateTimeZone('UTC'));
}

function cacheStaff(string $schema, string $name): int
{
    return DB::table($schema.'.staff')->insertGetId(['name' => $name, 'status' => 'active']);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function cacheService(string $schema, string $name, array $overrides = []): int
{
    return DB::table($schema.'.services')->insertGetId(serviceRow($name, $overrides));
}

function cacheAssign(string $schema, int $serviceId, int ...$staffIds): void
{
    foreach ($staffIds as $staffId) {
        DB::table($schema.'.service_staff')->insert(['service_id' => $serviceId, 'staff_id' => $staffId]);
    }
}

function cacheRule(string $schema, int $staffId, int $weekday, string $start, string $end): void
{
    WeeklyHours::add($schema, $staffId, $weekday, $start, $end);
}

/**
 * A booking in the given status with one item on the staff member, the block range as local
 * readings in Athens, and its expiry, when it has one, against the database's now: an hour
 * ahead, a minute behind, or eight seconds ahead for a hold a test waits out, wide enough
 * that the cold and the warm read both finish inside it on a loaded box.
 */
function cacheItem(string $schema, int $staffId, string $status, string $blockStart, string $blockEnd, ?string $expiry = null): int
{
    $bookingId = DB::table($schema.'.bookings')->insertGetId([
        ...bookingRow(),
        'status' => $status,
        'hold_class' => $expiry === null ? null : 'checkout',
        'hold_expires_at' => match ($expiry) {
            'live' => DB::raw("now() + interval '1 hour'"),
            'lapsed' => DB::raw("now() - interval '1 minute'"),
            'soon' => DB::raw("now() + interval '8 seconds'"),
            null => null,
            default => throw new InvalidArgumentException($expiry),
        },
    ]);

    DB::table($schema.'.booking_items')->insert(itemRow($bookingId, $staffId, athens($blockStart)->format('Y-m-d H:i:sP'), athens($blockEnd)->format('Y-m-d H:i:sP')));

    return $bookingId;
}

/**
 * The stored form under a key, which the test expects to be there.
 */
function storedAt(CacheRepository $store, string $key): string
{
    $stored = $store->get($key);

    assert(is_string($stored));

    return $stored;
}

function cacheLunaId(string $table, string $name): int
{
    $id = DB::table('tenant_luna.'.$table)->where('name', $name)->value('id');

    assert(is_int($id));

    return $id;
}

/**
 * Ann on acme with a thirty-minute service on Mondays 09:00 to 12:00: the one-member fixture
 * most tests here read.
 *
 * @return array{int, int, string} Ann, the service, and the URL for the Monday
 */
function annOnMonday(): array
{
    provisionTenant('acme');
    StartInterval::set('tenant_acme', 5);
    cacheClockAt('2026-04-06 05:00:00');
    $ann = cacheStaff('tenant_acme', 'Ann');
    $cut = cacheService('tenant_acme', 'Cut', ['duration_min' => 30]);
    cacheAssign('tenant_acme', $cut, $ann);
    cacheRule('tenant_acme', $ann, 1, '09:00:00', '12:00:00');

    return [$ann, $cut, cacheUrl('acme', [[$cut, null]], '2026-04-06', '2026-04-07')];
}

/**
 * Holds the service on the staff member from the start through the factory-built use case,
 * over the mask cache bound in the container at the time, so a warm store here is warmed by
 * the hold itself as well as by the reads.
 */
function holdOnAcme(int $staffId, int $serviceId, string $start): void
{
    $tenant = Tenant::query()->where('slug', 'acme')->sole();

    app(HoldBookingFactory::class)->for($tenant, Pool::Whole)->hold(new HoldRequest(
        new Customer('Casey Customer', 'casey@example.com'),
        [new SegmentChoice($serviceId, $staffId)],
        athens($start),
        new Intake(BookingSource::Embed),
    ), Actor::customer());
}

it('answers one request on luna, over bookings, holds, blackouts and a split day, in byte-identical JSON cold, warm and over a null store; the warm read issues one revisions statement and no items statement, the cold and null reads both', function () {
    seedDemo();
    StartInterval::set('tenant_luna', 5);
    cacheClockAt('2026-11-30 06:00:00'); // 08:00 in Athens on the Monday
    $elena = cacheLunaId('staff', 'Elena Papadaki');
    $nikos = cacheLunaId('staff', 'Nikos Georgiou');
    cacheItem('tenant_luna', $elena, 'confirmed', '2026-11-30 10:00', '2026-11-30 11:00');
    cacheItem('tenant_luna', $elena, 'pending', '2026-11-30 14:00', '2026-11-30 15:00', 'lapsed');
    cacheItem('tenant_luna', $nikos, 'pending', '2026-12-05 12:00', '2026-12-05 13:00', 'live');
    DB::table('tenant_luna.blackouts')->insert(['staff_id' => null, 'date' => '2026-12-03', 'reason' => 'Stocktake']);
    $url = cacheUrl('luna', [[cacheLunaId('services', 'Haircut'), null]], '2026-11-30', '2026-12-07');

    warmStore();
    [$cold, $coldLog] = readLogged($this, $url);
    [$warm, $warmLog] = readLogged($this, $url);
    nullStore();
    [$null, $nullLog] = readLogged($this, $url);

    // Monday is Elena's alone, her booking taking 09:20 to 10:55 and her lapsed hold nothing;
    // the split day shows from Tuesday with Nikos covering the lunch; Thursday is the blackout;
    // Friday shows Nikos's seeded dentist window as the gap neither person covers; Saturday
    // is Nikos alone with his hold taking 11:20 to 12:55.
    $cold->assertExactJson([
        '2026-11-30' => [...athensEvery('2026-11-30', '09:00', '09:15'), ...athensEvery('2026-11-30', '11:00', '12:15'), ...athensEvery('2026-11-30', '14:00', '17:15')],
        '2026-12-01' => athensEvery('2026-12-01', '09:00', '18:15'),
        '2026-12-02' => athensEvery('2026-12-02', '09:00', '18:15'),
        '2026-12-03' => [],
        '2026-12-04' => [...athensEvery('2026-12-04', '09:00', '13:15'), ...athensEvery('2026-12-04', '14:00', '18:15')],
        '2026-12-05' => [...athensEvery('2026-12-05', '10:00', '11:15'), ...athensEvery('2026-12-05', '13:00', '18:15')],
        '2026-12-06' => [],
    ]);

    expect($warm->getContent())->toBe($cold->getContent())
        ->and($null->getContent())->toBe($cold->getContent())
        ->and(naming($coldLog, 'staff_days'))->toBe(1)
        ->and(naming($coldLog, 'booking_items'))->toBe(1)
        ->and(naming($warmLog, 'staff_days'))->toBe(1)
        ->and(naming($warmLog, 'booking_items'))->toBe(0)
        ->and(naming($nullLog, 'staff_days'))->toBe(1)
        ->and(naming($nullLog, 'booking_items'))->toBe(1)
        ->and(naming($warmLog, 'shift_patterns'))->toBe(1)
        ->and(naming($warmLog, 'shift_overrides'))->toBe(1)
        ->and(naming($warmLog, 'blackouts'))->toBe(1);
});

it('reads an empty window\'s walk to the next day through the store as every read is: the answer is byte-identical cold, warm and over a null store, and the warm read issues no items statement', function () {
    [, $cut] = annOnMonday();
    $url = cacheUrl('acme', [[$cut, null]], '2026-04-07', '2026-04-08');

    warmStore();
    [$cold, $coldLog] = readLogged($this, $url);
    [$warm, $warmLog] = readLogged($this, $url);
    nullStore();
    [$null, $nullLog] = readLogged($this, $url);

    // Ann works Mondays alone: the Tuesday is empty and the walk finds the Monday after it.
    $cold->assertExactJson(['2026-04-07' => [], 'next_available_from' => '2026-04-13']);

    expect($warm->getContent())->toBe($cold->getContent())
        ->and($null->getContent())->toBe($cold->getContent())
        ->and(naming($coldLog, 'booking_items'))->toBe(2)
        ->and(naming($warmLog, 'booking_items'))->toBe(0)
        ->and(naming($nullLog, 'booking_items'))->toBe(2)
        ->and(naming($warmLog, 'staff_days'))->toBe(2);
});

it('keys a staff-day with no mutex row as absent and serves it warm until its first hold, whose row carries rev 0: the hold itself stores the post-insert mask under that revision, so the next read is warm and shows the slot gone, the earlier entries still in the store', function () {
    [$ann, $cut, $url] = annOnMonday();
    $absent = StaffDayMaskCache::key('tenant_acme', $ann, '2026-04-06', Granularity::default(), null);
    $first = StaffDayMaskCache::key('tenant_acme', $ann, '2026-04-06', Granularity::default(), 0);
    $second = StaffDayMaskCache::key('tenant_acme', $ann, '2026-04-06', Granularity::default(), 1);

    $store = warmStore();
    [$cold, $coldLog] = readLogged($this, $url);
    [$warm, $warmLog] = readLogged($this, $url);

    expect($cold->json('2026-04-06'))->toBe(athensEvery('2026-04-06', '09:00', '11:30'))
        ->and($warm->getContent())->toBe($cold->getContent())
        ->and(naming($coldLog, 'booking_items'))->toBe(1)
        ->and(naming($warmLog, 'booking_items'))->toBe(0)
        ->and(DB::table('tenant_acme.staff_days')->count())->toBe(0)
        ->and($store->has($absent))->toBeTrue()
        ->and($store->has($first))->toBeFalse();

    holdOnAcme($ann, $cut, '2026-04-06 10:00');
    [$afterFirst, $afterFirstLog] = readLogged($this, $url);

    expect(DB::table('tenant_acme.staff_days')->where('staff_id', $ann)->where('day', '2026-04-06')->value('rev'))->toBe(0)
        ->and(naming($afterFirstLog, 'staff_days'))->toBe(1)
        ->and(naming($afterFirstLog, 'booking_items'))->toBe(0)
        ->and($afterFirst->json('2026-04-06'))->toBe([...athensEvery('2026-04-06', '09:00', '09:30'), ...athensEvery('2026-04-06', '10:30', '11:30')])
        ->and($store->has($absent))->toBeTrue()
        ->and($store->has($first))->toBeTrue()
        ->and($store->has($second))->toBeFalse();

    holdOnAcme($ann, $cut, '2026-04-06 11:00');
    [$afterSecond, $afterSecondLog] = readLogged($this, $url);
    [$warmAgain, $warmAgainLog] = readLogged($this, $url);

    expect(DB::table('tenant_acme.staff_days')->where('staff_id', $ann)->where('day', '2026-04-06')->value('rev'))->toBe(1)
        ->and(naming($afterSecondLog, 'booking_items'))->toBe(0)
        ->and($afterSecond->json('2026-04-06'))->toBe([...athensEvery('2026-04-06', '09:00', '09:30'), ...athensEvery('2026-04-06', '10:30', '10:30'), ...athensEvery('2026-04-06', '11:30', '11:30')])
        ->and(naming($warmAgainLog, 'booking_items'))->toBe(0)
        ->and($warmAgain->getContent())->toBe($afterSecond->getContent())
        ->and($store->has($absent))->toBeTrue()
        ->and($store->has($first))->toBeTrue()
        ->and($store->has($second))->toBeTrue();
});

it('never reads an entry stored for one tenant for another with the same staff id and day', function () {
    provisionTenant('acme');
    provisionTenant('bravo');
    StartInterval::set('tenant_acme', 5);
    StartInterval::set('tenant_bravo', 5);
    cacheClockAt('2026-04-06 05:00:00');
    $urls = [];

    // Each tenant's implicit staff member is row 1 of its own schema, on the default week.
    foreach (['acme', 'bravo'] as $slug) {
        $cut = cacheService('tenant_'.$slug, 'Cut', ['duration_min' => 30]);
        cacheAssign('tenant_'.$slug, $cut, 1);
        $urls[$slug] = cacheUrl($slug, [[$cut, null]], '2026-04-06', '2026-04-07');
    }

    cacheItem('tenant_acme', 1, 'confirmed', '2026-04-06 10:00', '2026-04-06 11:00');

    $store = warmStore();
    [$acme, $acmeLog] = readLogged($this, $urls['acme']);
    [$bravo, $bravoLog] = readLogged($this, $urls['bravo']);
    [$bravoAgain, $bravoAgainLog] = readLogged($this, $urls['bravo']);

    expect(DB::table('tenant_bravo.staff')->min('id'))->toBe(1)
        ->and($acme->json('2026-04-06'))->toBe([...athensEvery('2026-04-06', '09:00', '09:30'), ...athensEvery('2026-04-06', '11:00', '16:30')])
        ->and($bravo->json('2026-04-06'))->toBe(athensEvery('2026-04-06', '09:00', '16:30'))
        ->and(naming($acmeLog, 'booking_items'))->toBe(1)
        ->and(naming($bravoLog, 'booking_items'))->toBe(1)
        ->and(naming($bravoAgainLog, 'booking_items'))->toBe(0)
        ->and($bravoAgain->getContent())->toBe($bravo->getContent())
        ->and($store->has(StaffDayMaskCache::key('tenant_acme', 1, '2026-04-06', Granularity::default(), null)))->toBeTrue()
        ->and($store->has(StaffDayMaskCache::key('tenant_bravo', 1, '2026-04-06', Granularity::default(), null)))->toBeTrue();
});

it('does not cache the open mask\'s overrides either: a day edited alone between two warm reads changes the second answer, which still issues no items statement', function () {
    [$ann, , $url] = annOnMonday();

    warmStore();
    readLogged($this, $url);
    [$before] = readLogged($this, $url);
    DB::table('tenant_acme.shift_overrides')->insert(['staff_id' => $ann, 'date' => '2026-04-06', 'windows' => '[{"start": "10:00", "end": "11:00"}]']);
    [$after, $afterLog] = readLogged($this, $url);

    expect($before->json('2026-04-06'))->toBe(athensEvery('2026-04-06', '09:00', '11:30'))
        ->and($after->json('2026-04-06'))->toBe(athensEvery('2026-04-06', '10:00', '10:30'))
        ->and(naming($afterLog, 'booking_items'))->toBe(0)
        ->and(naming($afterLog, 'shift_overrides'))->toBe(1);
});

it('does not cache the open mask: a rule added between two warm reads changes the second answer, which still issues no items statement', function () {
    [$ann, , $url] = annOnMonday();

    warmStore();
    readLogged($this, $url);
    [$before, $beforeLog] = readLogged($this, $url);
    cacheRule('tenant_acme', $ann, 1, '13:00:00', '14:00:00');
    [$after, $afterLog] = readLogged($this, $url);

    expect($before->json('2026-04-06'))->toBe(athensEvery('2026-04-06', '09:00', '11:30'))
        ->and($after->json('2026-04-06'))->toBe([...athensEvery('2026-04-06', '09:00', '11:30'), ...athensEvery('2026-04-06', '13:00', '13:30')])
        ->and(naming($beforeLog, 'booking_items'))->toBe(0)
        ->and(naming($afterLog, 'booking_items'))->toBe(0)
        ->and(naming($afterLog, 'shift_patterns'))->toBe(1)
        ->and(naming($afterLog, 'shift_overrides'))->toBe(1);
});

it('treats an entry as a miss once the earliest live hold it counted lapses, with no write on that staff-day in between: the read after issues the items statement and offers the slot, the read before does not', function () {
    [$ann, , $url] = annOnMonday();
    $hold = cacheItem('tenant_acme', $ann, 'pending', '2026-04-06 10:00', '2026-04-06 11:00', 'soon');
    $key = StaffDayMaskCache::key('tenant_acme', $ann, '2026-04-06', Granularity::default(), null);

    $store = warmStore();
    [$cold, $coldLog] = readLogged($this, $url);
    [$before, $beforeLog] = readLogged($this, $url);

    expect($cold->json('2026-04-06'))->toBe([...athensEvery('2026-04-06', '09:00', '09:30'), ...athensEvery('2026-04-06', '11:00', '11:30')])
        ->and($before->getContent())->toBe($cold->getContent())
        ->and(naming($coldLog, 'booking_items'))->toBe(1)
        ->and(naming($beforeLog, 'booking_items'))->toBe(0)
        ->and($store->has($key))->toBeTrue()
        ->and(StaffDayMaskCache::decode(storedAt($store, $key), 288)?->earliestHoldExpiry)->not->toBeNull();

    // Wait the hold out by the database's clock, the one the predicate and the reader share.
    $remaining = DB::scalar('select extract(epoch from (hold_expires_at - now())) from tenant_acme.bookings where id = ?', [$hold]);
    assert(is_float($remaining) || is_int($remaining) || is_string($remaining));
    usleep((int) (max(0.0, (float) $remaining) * 1_000_000) + 100_000);

    [$after, $afterLog] = readLogged($this, $url);

    expect(naming($afterLog, 'staff_days'))->toBe(1)
        ->and(naming($afterLog, 'booking_items'))->toBe(1)
        ->and($after->json('2026-04-06'))->toBe(athensEvery('2026-04-06', '09:00', '11:30'))
        ->and(DB::table('tenant_acme.staff_days')->count())->toBe(0)
        ->and(DB::table('tenant_acme.bookings')->where('id', $hold)->value('status'))->toBe('pending');
});

it('round-trips the masks of the two clock-change days and a plain day through the store: a warm read over 2026-03-29 and one over 2026-10-25 answer the cold bytes with no items statement', function (string $clock, string $from, string $to, int $width) {
    provisionTenant('acme');
    StartInterval::set('tenant_acme', 5);
    cacheClockAt($clock);
    $ann = cacheStaff('tenant_acme', 'Ann');
    $cut = cacheService('tenant_acme', 'Cut', ['duration_min' => 30]);
    cacheAssign('tenant_acme', $cut, $ann);

    foreach (range(1, 7) as $weekday) {
        cacheRule('tenant_acme', $ann, $weekday, '00:00:00', '24:00:00');
    }

    cacheItem('tenant_acme', $ann, 'confirmed', $from.' 10:00', $from.' 11:00');
    $url = cacheUrl('acme', [[$cut, null]], $from, $to);

    $store = warmStore();
    [$cold, $coldLog] = readLogged($this, $url);
    [$warm, $warmLog] = readLogged($this, $url);
    $stored = storedAt($store, StaffDayMaskCache::key('tenant_acme', $ann, $from, Granularity::default(), null));

    // Every weekday is open, so the widened window keeps the day's last starts; the booking
    // takes the seventeen starts from 09:35 to 10:55, and the stored mask the twelve slots of
    // its hour.
    expect($warm->getContent())->toBe($cold->getContent())
        ->and(naming($coldLog, 'booking_items'))->toBe(1)
        ->and(naming($warmLog, 'booking_items'))->toBe(0)
        ->and($cold->json($from))->toHaveCount($width - 17)
        ->and(StaffDayMaskCache::decode($stored, $width)?->mask->setIndices())->toHaveCount($width - 12)
        ->and(StaffDayMaskCache::decode($stored, $width === 288 ? 276 : 288))->toBeNull();
})->with([
    'the spring day, 276 slots' => ['2026-03-28 12:00:00', '2026-03-29', '2026-03-30', 276],
    'a plain day, 288 slots' => ['2026-04-05 12:00:00', '2026-04-06', '2026-04-07', 288],
    'the autumn day, 300 slots' => ['2026-10-24 12:00:00', '2026-10-25', '2026-10-26', 300],
]);
