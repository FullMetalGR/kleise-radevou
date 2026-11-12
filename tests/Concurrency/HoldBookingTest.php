<?php

use App\Application\ChainUnavailable;
use App\Application\Checkpoint;
use App\Application\Clock;
use App\Application\Dto\Customer;
use App\Application\Dto\HoldItem;
use App\Application\Dto\HoldPlan;
use App\Application\Dto\HoldRequest;
use App\Application\Dto\Intake;
use App\Application\Dto\SegmentChoice;
use App\Application\HoldBooking;
use App\Domain\Audit\Actor;
use App\Domain\Booking\SegmentConflict;
use App\Domain\Enum\ApprovalTimeout;
use App\Domain\Enum\BookingSource;
use App\Domain\Enum\BookingStatus;
use App\Domain\Enum\HoldClass;
use App\Domain\Enum\PaymentMode;
use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use App\Infrastructure\Cache\StaffDayMaskCache;
use App\Infrastructure\Db\AuditRow;
use App\Infrastructure\Db\BlockingItems;
use App\Infrastructure\Db\LockedAvailability;
use App\Infrastructure\Db\LockManager;
use App\Infrastructure\Db\MasksFromRows;
use App\Infrastructure\Db\Pool;
use App\Infrastructure\Db\PostgresBookingWriter;
use App\Infrastructure\HoldBookingFactory;
use App\Tenancy\Tenant;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Assert;
use Tests\Concurrency\Harness\ConcurrentRun;
use Tests\Concurrency\Observer;
use Tests\Support\FixedClock;
use Tests\Support\WeeklyHours;
use Tests\TestCase;

/*
 * HoldBooking under the write protocol through the catalog-driven request: the query log of one
 * hold, the rollback of a conflict, and one interleaving, on tenants provisioned for real and
 * dropped afterwards. Each tenant gets a Cut of sixty minutes and a Trim of thirty on its
 * implicit staff member, who works Monday to Friday 09:00 to 17:00 in Athens; acme also gets Sam
 * on a second service. 2026-10-01 is a Thursday, so 09:00 UTC is 12:00 in Athens, inside the
 * hours. The clock stands on the day before.
 *
 * Worker closures run in another process and call only autoloaded code and the framework;
 * every instant they take is UTC text, so the captures serialize, and the clock they stand
 * on is spelled inside each closure, since nothing declared in this file exists there.
 */

beforeEach(function () {
    DB::statement('drop schema if exists tenant_acme cascade');
    DB::statement('drop schema if exists tenant_bravo cascade');
    provisionTenant('acme');
    $this->catalog = seedHoldCatalog('tenant_acme');
    app()->instance(Clock::class, FixedClock::at('2026-09-30 12:00:00'));
});

afterEach(function () {
    // A worker still holding a row lock would make the drop wait forever; the base case abandons
    // runs only after these hooks, so abandon here first.
    ConcurrentRun::abandonAll();
    DB::statement('drop schema if exists tenant_acme cascade');
    DB::statement('drop schema if exists tenant_bravo cascade');
});

/**
 * The Cut and the Trim on the implicit member, and Sam with a Colour of his own and the Cut
 * too, on Thursdays 09:00 to 17:00. Each schema numbers its rows from one, so the ids agree
 * across tenants.
 *
 * @return array{cut: int, trim: int, colour: int, sam: int}
 */
function seedHoldCatalog(string $schema): array
{
    $cut = DB::table($schema.'.services')->insertGetId(serviceRow('Cut', ['duration_min' => 60, 'price_minor' => 2500]));
    $trim = DB::table($schema.'.services')->insertGetId(serviceRow('Trim', ['duration_min' => 30, 'price_minor' => 1500]));
    DB::table($schema.'.service_staff')->insert([['service_id' => $cut, 'staff_id' => 1], ['service_id' => $trim, 'staff_id' => 1]]);
    $sam = DB::table($schema.'.staff')->insertGetId(['name' => 'Sam', 'status' => 'active', 'sort' => 1]);
    $colour = DB::table($schema.'.services')->insertGetId(serviceRow('Colour', ['duration_min' => 60, 'price_minor' => 2500]));
    DB::table($schema.'.service_staff')->insert([['service_id' => $colour, 'staff_id' => $sam], ['service_id' => $cut, 'staff_id' => $sam]]);
    WeeklyHours::add($schema, $sam, 4, '09:00:00', '17:00:00');

    return ['cut' => $cut, 'trim' => $trim, 'colour' => $colour, 'sam' => $sam];
}

/**
 * A worker that confirms one booking of acme and then stands on its row lock until the test
 * lets it commit.
 *
 * Raw SQL rather than a use case: what is on trial here is the reap statement, which must not
 * expire a booking somebody confirmed while it waited. The interleaving is reachable in the
 * engine and not only in this file: a guarded transition locks the key set of the booking it
 * moves and a hold locks the key set of its own new items, while the reap is scoped by one
 * range from the earliest block start to the latest block end. So the reap reaches a staff-day
 * no key of the hold holds as soon as that window crosses midnight in the tenant's zone and
 * one of the locked members works only one side of it, and a transition on a booking sitting
 * there takes locks disjoint from the hold's and commits underneath it.
 */
function confirmWorker(int $bookingId): Closure
{
    return function () use ($bookingId): array {
        DB::beginTransaction();
        $changed = DB::update('update tenant_acme.bookings set status = ?, updated_at = now() where id = ?', ['confirmed', $bookingId]);
        Checkpoint::reach('confirm.locked');
        DB::commit();

        return ['changed' => $changed];
    };
}

function holdTenant(string $slug = 'acme'): Tenant
{
    return Tenant::query()->where('slug', $slug)->sole();
}

function holdUseCase(Tenant $tenant): HoldBooking
{
    return app(HoldBookingFactory::class)->for($tenant, Pool::Whole);
}

/**
 * A request for the parent to hold: items as [serviceId, staffId or null] and the start as UTC
 * text.
 *
 * @param  list<array{int, int|null}>  $items
 */
function holdRequestOf(array $items, string $start, bool $sameStaff = false): HoldRequest
{
    return new HoldRequest(
        new Customer('Casey Customer', 'casey@example.com', '+30 210 0000000'),
        array_map(fn (array $item): SegmentChoice => new SegmentChoice($item[0], $item[1]), $items),
        new DateTimeImmutable($start, new DateTimeZone('UTC')),
        new Intake(BookingSource::Embed),
        $sameStaff,
    );
}

/**
 * What the closure throws as the domain conflict. Anything else, or nothing, fails the test.
 */
function conflictFrom(Closure $run): SegmentConflict
{
    try {
        $run();
    } catch (SegmentConflict $conflict) {
        return $conflict;
    }

    Assert::fail('No SegmentConflict was thrown.');
}

/**
 * A hold already past its expiry on staff member 1, as the sweeper would find it: pending,
 * checkout class, its item still blocking.
 *
 * @return int the booking id
 */
function seedExpiredHold(string $start, string $end): int
{
    $bookingId = DB::table('tenant_acme.bookings')->insertGetId([
        ...bookingRow(),
        'status' => 'pending',
        'hold_class' => 'checkout',
        'hold_expires_at' => DB::raw("now() - interval '1 minute'"),
    ]);

    DB::table('tenant_acme.booking_items')->insert(itemRow($bookingId, 1, $start, $end));

    return $bookingId;
}

/**
 * The bookings of a tenant with their items, oldest first, every instant in UTC text.
 *
 * @return list<array<mixed>>
 */
function bookingsIn(string $schema = 'tenant_acme'): array
{
    $rows = [];

    foreach (DB::select(sprintf(
        'select b.id, b.status, b.hold_class, i.sort, i.staff_id, i.blocks,'
        ." to_char(i.start_utc at time zone 'UTC', 'YYYY-MM-DD HH24:MI') as start_utc,"
        ." to_char(i.end_utc at time zone 'UTC', 'YYYY-MM-DD HH24:MI') as end_utc,"
        ." to_char(i.block_start_utc at time zone 'UTC', 'YYYY-MM-DD HH24:MI') as block_start_utc,"
        ." to_char(i.block_end_utc at time zone 'UTC', 'YYYY-MM-DD HH24:MI') as block_end_utc"
        .' from %1$s.bookings b join %1$s.booking_items i on i.booking_id = b.id order by b.id, i.sort',
        $schema,
    )) as $row) {
        assert($row instanceof stdClass);

        $rows[] = get_object_vars($row);
    }

    return $rows;
}

/**
 * How many pairs of blocking items on one staff member overlap: the invariant, read directly.
 */
function overlappingBlockingPairs(string $schema = 'tenant_acme'): int
{
    $count = DB::scalar(sprintf(
        'select count(*) from %1$s.booking_items a join %1$s.booking_items b'
        .' on a.id < b.id and a.staff_id = b.staff_id and a.blocks and b.blocks and a.block_span && b.block_span',
        $schema,
    ));

    assert(is_int($count));

    return $count;
}

/**
 * A worker holding one chain for a tenant through the factory, on a clock standing on the day
 * before the fixtures. The items are [serviceId, staffId or null] and the start UTC text. It
 * reports what it got, the booking or the segment that conflicted, with every statement its
 * connection ran, how many times the runner retried, and the ids the second reap statement was
 * given.
 *
 * @param  list<array{int, int|null}>  $items
 */
function holdWorker(string $slug, array $items, string $start, ?string $gate = null): Closure
{
    return function () use ($slug, $items, $start, $gate): array {
        if ($gate !== null) {
            Checkpoint::reach($gate);
        }

        app()->instance(Clock::class, FixedClock::at('2026-09-30 12:00:00'));
        $tenant = Tenant::query()->where('slug', $slug)->sole();
        $useCase = app(HoldBookingFactory::class)->for($tenant, Pool::Whole);
        $request = new HoldRequest(
            new Customer('Casey Customer', 'casey@example.com'),
            array_map(fn (array $item): SegmentChoice => new SegmentChoice($item[0], $item[1]), $items),
            new DateTimeImmutable($start, new DateTimeZone('UTC')),
            new Intake(BookingSource::Embed),
        );
        $observer = Observer::attach();
        DB::enableQueryLog();

        try {
            $held = $useCase->hold($request, Actor::customer());
            $report = ['held' => $held->uuid, 'total' => $held->total->minor];
        } catch (SegmentConflict $conflict) {
            $report = ['conflict' => $conflict->segment, 'wrapped' => $conflict->getPrevious() !== null];
        }

        $unblock = array_values(array_filter(
            DB::getQueryLog(),
            fn (array $entry): bool => $entry['query'] === BlockingItems::unblockSql(),
        ));

        return [
            ...$report,
            'statements' => $observer->statements,
            'retries' => count($observer->logged('Transaction retried')),
            'reaped' => $unblock[0]['bindings'][0] ?? null,
        ];
    };
}

/**
 * A worker attempting several holds in a row on staff member 1 of the tenant, each as
 * [serviceId, start in UTC text], timing each attempt and reporting how it ended.
 *
 * @param  list<array{int, string}>  $plan
 */
function contentionWorker(string $slug, array $plan): Closure
{
    return function () use ($slug, $plan): array {
        app()->instance(Clock::class, FixedClock::at('2026-09-30 12:00:00'));
        $tenant = Tenant::query()->where('slug', $slug)->sole();
        $useCase = app(HoldBookingFactory::class)->for($tenant, Pool::Whole);
        $observer = Observer::attach();
        $results = [];

        foreach ($plan as [$serviceId, $start]) {
            $request = new HoldRequest(
                new Customer('Casey Customer', 'casey@example.com'),
                [new SegmentChoice($serviceId, 1)],
                new DateTimeImmutable($start, new DateTimeZone('UTC')),
                new Intake(BookingSource::Embed),
            );
            $began = hrtime(true);

            try {
                $useCase->hold($request, Actor::customer());
                $ended = 'booked';
            } catch (SegmentConflict) {
                $ended = 'conflict';
            } catch (ChainUnavailable) {
                $ended = 'unavailable';
            } catch (Throwable $failure) {
                $ended = $failure::class;
            }

            $results[] = ['ended' => $ended, 'ms' => (hrtime(true) - $began) / 1e6];
        }

        return ['results' => $results, 'retries' => count($observer->logged('Transaction retried'))];
    };
}

/**
 * A plan built by hand, as the planner would have made it: one item per [staffId, start, end]
 * in UTC text on the Cut, no buffers, each at 2500 minor units.
 *
 * @param  list<array{int, string, string}>  $items
 */
function holdPlanOf(array $items): HoldPlan
{
    $utc = new DateTimeZone('UTC');

    return new HoldPlan(
        new Customer('Casey Customer', 'casey@example.com'),
        array_map(fn (array $item): HoldItem => new HoldItem($item[0], 1, new DateTimeImmutable($item[1], $utc), new DateTimeImmutable($item[2], $utc), new DateTimeImmutable($item[1], $utc), new DateTimeImmutable($item[2], $utc), null, new Money(2500, Currency::of('EUR'))), $items),
        BookingStatus::Pending,
        HoldClass::Checkout,
        15,
        PaymentMode::Onsite,
        false,
        ApprovalTimeout::Expire,
        new Intake(BookingSource::Embed),
    );
}

/**
 * The statements the runner opens every transaction with.
 *
 * @return list<string>
 */
function holdPrologue(): array
{
    return [
        'set local search_path to "tenant_acme", "public"',
        "set local lock_timeout = '3s'",
        "set local statement_timeout = '10s'",
        "select current_setting('transaction_isolation')",
        'select current_schema()',
    ];
}

/**
 * The statements of the availability step, bare-named, on the transaction.
 *
 * @return list<string>
 */
function holdAvailabilityStatements(): array
{
    return [
        LockedAvailability::granularitySql(),
        MasksFromRows::patternsSql(''),
        MasksFromRows::overridesSql(''),
        MasksFromRows::blackoutsSql(''),
        MasksFromRows::itemsSql(''),
    ];
}

/**
 * The statements of one transaction: everything from its prologue on, the advisory reads before
 * it left out.
 *
 * @param  list<string>  $statements
 * @return list<string>
 */
function holdTransactionOf(array $statements): array
{
    $from = array_search(holdPrologue()[0], $statements, true);

    return $from === false ? [] : array_slice($statements, $from);
}

/**
 * The statements a worker reported, each a string.
 *
 * @param  array<mixed>  $report
 * @return list<string>
 */
function holdStatementsOf(array $report): array
{
    $statements = [];

    foreach (is_array($report['statements'] ?? null) ? $report['statements'] : [] as $statement) {
        assert(is_string($statement));
        $statements[] = $statement;
    }

    return $statements;
}

/**
 * The availability read of the parent, on the wire, for the Cut on staff member 1 over the
 * Thursday: the UTC instants offered.
 *
 * @return list<string>
 */
function holdStartsOffered(TestCase $test, int $cut): array
{
    $query = ['items' => (string) json_encode([['service_id' => $cut, 'staff_id' => 1]]), 'from' => '2026-10-01', 'to' => '2026-10-02'];
    $starts = $test->getJson(tenantUrl('acme', '/api/v1/availability?'.http_build_query($query)))->assertOk()->json('2026-10-01');
    $offered = [];

    foreach (is_array($starts) ? $starts : [] as $start) {
        assert(is_string($start));
        $offered[] = $start;
    }

    return $offered;
}

it('issues, for one successful two-item hold, the write protocol\'s sequence with the settings, rules, blackouts and items statements between the overlap checks and the insert, the audit insert after the last item insert, the items statement again after it, and no cache access inside the transaction', function () {
    $store = new class extends ArrayStore
    {
        /** @var list<int> */
        public array $levelsAtPut = [];

        public function put($key, $value, $seconds): bool
        {
            $this->levelsAtPut[] = DB::transactionLevel();

            return parent::put($key, $value, $seconds);
        }
    };
    app()->instance(StaffDayMaskCache::class, new StaffDayMaskCache(new CacheRepository($store)));
    $catalog = $this->catalog;
    $tenant = holdTenant();
    $observer = Observer::attach();
    DB::enableQueryLog();

    $held = holdUseCase($tenant)->hold(holdRequestOf([[$catalog['cut'], 1], [$catalog['colour'], $catalog['sam']]], '2026-10-01 09:00'), Actor::customer());

    $log = DB::getQueryLog();
    $reap = array_values(array_filter($log, fn (array $entry): bool => $entry['query'] === PostgresBookingWriter::reapBookingsSql()));
    $overlaps = array_values(array_filter($log, fn (array $entry): bool => $entry['query'] === PostgresBookingWriter::overlapSql()));
    $items = array_values(array_filter($log, fn (array $entry): bool => $entry['query'] === MasksFromRows::itemsSql('')));

    expect(holdTransactionOf($observer->statements))->toBe([
        ...holdPrologue(),
        LockManager::sql(2),
        PostgresBookingWriter::reapBookingsSql(),
        BlockingItems::unblockSql(),
        PostgresBookingWriter::overlapSql(),
        PostgresBookingWriter::overlapSql(),
        ...holdAvailabilityStatements(),
        PostgresBookingWriter::insertBookingSql(),
        PostgresBookingWriter::insertItemSql(),
        PostgresBookingWriter::insertItemSql(),
        AuditRow::sql(),
        MasksFromRows::itemsSql(''),
    ])
        ->and($reap[0]['bindings'] ?? null)->toBe(['{1,2}', '2026-10-01 09:00:00.000000+00:00', '2026-10-01 11:00:00.000000+00:00'])
        ->and($overlaps[0]['bindings'] ?? null)->toBe([1, '2026-10-01 09:00:00.000000+00:00', '2026-10-01 10:00:00.000000+00:00'])
        ->and($overlaps[1]['bindings'] ?? null)->toBe([2, '2026-10-01 10:00:00.000000+00:00', '2026-10-01 11:00:00.000000+00:00'])
        ->and($items)->toHaveCount(2)
        ->and($items[1]['bindings'] ?? null)->toBe(['{1,2}', '2026-09-30 21:00:00.000000+00:00', '2026-10-01 21:00:00.000000+00:00'])
        ->and($held->uuid)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/')
        ->and($held->total->minor)->toBe(5000)
        ->and($observer->logs)->toBe([])
        ->and(count($store->levelsAtPut))->toBeGreaterThanOrEqual(2)
        ->and(array_values(array_unique($store->levelsAtPut)))->toBe([0])
        ->and(DB::transactionLevel())->toBe(0);
});

it('raises the domain conflict naming the zero-based failing segment, rolls back, and leaves no row of the booking or its items and no mutex row it created', function () {
    $existing = DB::table('tenant_acme.bookings')->insertGetId(bookingRow());
    DB::table('tenant_acme.booking_items')->insert(itemRow($existing, 2, '2026-10-01 10:30+00', '2026-10-01 11:30+00'));
    $tenant = holdTenant();
    $observer = Observer::attach();

    // The plan is made by hand, as the planner would have made it from a read that predated
    // the booking: the protocol, not the advisory read, is what refuses here.
    $conflict = conflictFrom(fn () => holdUseCase($tenant)->execute(holdPlanOf([[1, '2026-10-01 09:00', '2026-10-01 10:00'], [2, '2026-10-01 10:00', '2026-10-01 11:00']]), Actor::customer()));

    expect($conflict->segment)->toBe(1)
        ->and($conflict->block->staffId)->toBe(2)
        ->and($conflict->getMessage())->toContain('Segment 1 overlaps a blocking item of staff member 2')
        ->and($conflict->getPrevious())->toBeNull()
        ->and($observer->statements)->toBe([
            ...holdPrologue(),
            LockManager::sql(2),
            PostgresBookingWriter::reapBookingsSql(),
            BlockingItems::unblockSql(),
            PostgresBookingWriter::overlapSql(),
            PostgresBookingWriter::overlapSql(),
        ])
        ->and($observer->logs)->toBe([])
        ->and(DB::table('tenant_acme.bookings')->count())->toBe(1)
        ->and(DB::table('tenant_acme.booking_items')->count())->toBe(1)
        ->and(DB::table('tenant_acme.staff_days')->count())->toBe(0)
        ->and(DB::transactionLevel())->toBe(0)
        ->and(DB::getPdo()->inTransaction())->toBeFalse();
});

it('lets exactly one of two workers released together hold overlapping ranges on one staff member; the loser gets the domain conflict, not a database error (test 1)', function () {
    $outcomes = ConcurrentRun::of([
        'a' => holdWorker('acme', [[1, 1]], '2026-10-01 09:00'),
        'b' => holdWorker('acme', [[1, 1]], '2026-10-01 09:30'),
    ])->run();

    $a = returnedArray($outcomes['a']);
    $b = returnedArray($outcomes['b']);
    $winner = isset($a['held']) ? $a : $b;
    $loser = $winner === $a ? $b : $a;
    $rows = bookingsIn();

    expect($outcomes['a']->threw())->toBeFalse()
        ->and($outcomes['b']->threw())->toBeFalse()
        ->and($winner['held'] ?? null)->toBeString()
        ->and($loser['conflict'] ?? null)->toBe(0)
        ->and($loser['wrapped'] ?? null)->toBeFalse()
        ->and($loser['statements'] ?? null)->toContain(PostgresBookingWriter::overlapSql())
        ->and($loser['statements'] ?? null)->not->toContain(PostgresBookingWriter::insertBookingSql())
        ->and([$a['retries'] ?? null, $b['retries'] ?? null])->toBe([0, 0])
        ->and($rows)->toHaveCount(1)
        ->and($rows[0])->toMatchArray(['status' => 'pending', 'staff_id' => 1, 'blocks' => true])
        ->and(DB::table('tenant_acme.staff_days')->where('staff_id', 1)->value('rev'))->toBe(0);
});

it('blocks the second worker at step 3 on the mutex row, seen in the lock view, while the first stands after step 5; the first commits, the second proceeds and conflicts at its own step 5 (test 6)', function () {
    $run = ConcurrentRun::of([
        'a' => holdWorker('acme', [[1, 1]], '2026-10-01 09:00'),
        'b' => holdWorker('acme', [[1, 1]], '2026-10-01 09:30', 'b.start'),
    ])->holding('b.start', HoldBooking::AFTER_VALIDATION)->start();
    $run->release();
    $run->awaitCheckpoint('a', HoldBooking::AFTER_VALIDATION);
    $run->releaseCheckpoint(HoldBooking::AFTER_VALIDATION, 'b');
    $run->releaseCheckpoint('b.start');
    $waiting = lockWaiter('insert into staff_days');
    $locks = locksOf($waiting['pid']);
    $bFinishedWhileAStood = $run->hasOutcome('b');
    $bookingsWhileAStood = DB::table('tenant_acme.bookings')->count();
    $run->releaseCheckpoint(HoldBooking::AFTER_VALIDATION, 'a');
    $outcomes = $run->wait();

    $a = returnedArray($outcomes['a']);
    $b = returnedArray($outcomes['b']);
    $ungranted = array_values(array_filter($locks, fn (array $lock): bool => ! $lock['granted']));
    $rows = bookingsIn();

    expect($waiting['wait_event'])->toBeIn(['transactionid', 'tuple'])
        ->and($locks)->toContain(['locktype' => 'relation', 'relation' => 'tenant_acme.staff_days', 'granted' => true])
        ->and($ungranted)->toHaveCount(1)
        ->and($ungranted[0]['locktype'] ?? null)->toBeIn(['transactionid', 'tuple'])
        ->and($bFinishedWhileAStood)->toBeFalse()
        ->and($bookingsWhileAStood)->toBe(0)
        ->and($a['held'] ?? null)->toBeString()
        ->and($b['conflict'] ?? null)->toBe(0)
        ->and(holdTransactionOf(holdStatementsOf($b)))->toBe([
            ...holdPrologue(),
            LockManager::sql(1),
            PostgresBookingWriter::reapBookingsSql(),
            BlockingItems::unblockSql(),
            PostgresBookingWriter::overlapSql(),
        ])
        ->and($rows)->toHaveCount(1)
        ->and($rows[0])->toMatchArray(['status' => 'pending', 'staff_id' => 1, 'blocks' => true, 'start_utc' => '2026-10-01 09:00']);
});

it('reaps an expired hold exactly once when two workers compete for its range: the first stands past its lock, the second plans the same range as free and blocks on the mutex row, the first books and commits, the second reaps nothing and conflicts, and the old hold ends expired with its item not blocking (test 7)', function () {
    $old = seedExpiredHold('2026-10-01 09:00+00', '2026-10-01 10:00+00');

    // The second worker plans while the first holds the lock and has written no booking, so
    // its plan reads the range as free whatever the machine's load; it then waits on the lock
    // and meets the first's committed hold only under it: the conflict, never the planner's
    // refusal.
    $run = ConcurrentRun::of([
        'a' => holdWorker('acme', [[1, 1]], '2026-10-01 09:30'),
        'b' => holdWorker('acme', [[1, 1]], '2026-10-01 09:30', 'b.start'),
    ])->holding('b.start', HoldBooking::AFTER_LOCK)->start();
    $run->release();
    $run->awaitCheckpoint('a', HoldBooking::AFTER_LOCK);
    $run->releaseCheckpoint(HoldBooking::AFTER_LOCK, 'b');
    $run->releaseCheckpoint('b.start');
    $waiting = lockWaiter('insert into staff_days');
    $bFinishedWhileAStood = $run->hasOutcome('b');
    $run->releaseCheckpoint(HoldBooking::AFTER_LOCK, 'a');
    $outcomes = $run->wait();

    $a = returnedArray($outcomes['a']);
    $b = returnedArray($outcomes['b']);
    $rows = bookingsIn();

    expect($waiting['wait_event'])->toBeIn(['transactionid', 'tuple'])
        ->and($bFinishedWhileAStood)->toBeFalse()
        ->and($a['held'] ?? null)->toBeString()
        ->and($a['reaped'] ?? null)->toBe('{'.$old.'}')
        ->and($b['conflict'] ?? null)->toBe(0)
        ->and($b['reaped'] ?? null)->toBe('{}')
        ->and($rows)->toHaveCount(2)
        // The reap ends the hold as well as the status, like every transition out of a held
        // state, so the row carries neither a class nor an expiry afterwards. Which hold it was
        // is not lost with them: the reap reads it off the locked row before the update, and the
        // trail is where it is kept.
        ->and($rows[0])->toMatchArray(['id' => $old, 'status' => 'expired', 'hold_class' => null, 'blocks' => false, 'start_utc' => '2026-10-01 09:00'])
        ->and(DB::scalar("select payload->>'hold_class' from tenant_acme.audit_log where action = 'expired'"))->toBe('checkout')
        ->and($rows[1])->toMatchArray(['status' => 'pending', 'hold_class' => 'checkout', 'blocks' => true, 'start_utc' => '2026-10-01 09:30'])
        ->and(overlappingBlockingPairs())->toBe(0);
});

it('leaves alone a hold a concurrent transaction confirmed while the reap stood on its row lock, and hands back nothing for it, so neither the trail nor the events name a booking that was never expired (new interleaving)', function () {
    $catalog = $this->catalog;
    // Inside the window the chain spans, on a locked staff member, overlapping neither of the
    // chain's own items: the reap chooses this booking, step 5 has no quarrel with it, and the
    // hold goes on to commit, so what the trail holds afterwards can be read.
    $old = seedExpiredHold('2026-10-01 10:00+00', '2026-10-01 11:00+00');
    $reaping = strstr(PostgresBookingWriter::reapBookingsSql(), '?', true);
    assert(is_string($reaping));

    $run = ConcurrentRun::of([
        'confirm' => confirmWorker($old),
        'hold' => holdWorker('acme', [[$catalog['cut'], 1], [$catalog['colour'], $catalog['sam']]], '2026-10-01 09:00', 'hold.start'),
    ])->holding('confirm.locked', 'hold.start')->start();
    $run->release();
    // The confirmation stands on the row lock with nothing committed, so the reap's snapshot
    // still shows the booking pending and past its expiry: the choice it makes is the stale one.
    $run->awaitCheckpoint('confirm', 'confirm.locked');
    $run->releaseCheckpoint('hold.start');
    $waiting = lockWaiter($reaping);
    // Only now does the confirmation commit. The reap is past its snapshot and inside the wait
    // for that row, which is the one moment at which a statement carrying no condition of its
    // own expires a confirmed booking.
    $run->releaseCheckpoint('confirm.locked');
    $outcomes = $run->wait();

    $hold = returnedArray($outcomes['hold']);
    $rows = bookingsIn();
    $trail = DB::table('tenant_acme.audit_log')->orderBy('id')->pluck('action')->all();

    expect($waiting['wait_event'])->toBeIn(['transactionid', 'tuple'])
        ->and($outcomes['confirm']->threw())->toBeFalse()
        ->and(returnedArray($outcomes['confirm'])['changed'] ?? null)->toBe(1)
        ->and($outcomes['hold']->threw())->toBeFalse()
        ->and($hold['held'] ?? null)->toBeString()
        ->and($hold['reaped'] ?? null)->toBe('{}')
        ->and($rows)->toHaveCount(3)
        ->and($rows[0])->toMatchArray(['id' => $old, 'status' => 'confirmed', 'blocks' => true, 'start_utc' => '2026-10-01 10:00'])
        ->and($rows[1])->toMatchArray(['status' => 'pending', 'staff_id' => 1, 'blocks' => true, 'start_utc' => '2026-10-01 09:00'])
        ->and($rows[2])->toMatchArray(['status' => 'pending', 'staff_id' => 2, 'blocks' => true, 'start_utc' => '2026-10-01 10:00'])
        ->and($trail)->toBe(['held'])
        ->and(overlappingBlockingPairs())->toBe(0);
});

it('lets two tenants hold the same staff id on the same day at once: both stand past their lock at the same moment, neither waited on the other, both succeed (test 16)', function () {
    provisionTenant('bravo', ['ownerEmail' => 'bravo@example.com']);
    seedHoldCatalog('tenant_bravo');

    $run = ConcurrentRun::of([
        'acme' => holdWorker('acme', [[1, 1]], '2026-10-01 09:00'),
        'bravo' => holdWorker('bravo', [[1, 1]], '2026-10-01 09:00'),
    ])->holding(HoldBooking::AFTER_LOCK)->start();
    $run->release();
    $run->awaitCheckpoint('acme', HoldBooking::AFTER_LOCK);
    $run->awaitCheckpoint('bravo', HoldBooking::AFTER_LOCK);
    // Both stand past their lock at once, so neither could have waited on the other's mutex row.
    // The only lock waits in the view are the two checkpoint holds, on the harness's advisory lock.
    $blocked = DB::scalar("select count(*) from pg_stat_activity where datname = current_database() and wait_event_type = 'Lock' and wait_event <> 'advisory'");
    $run->releaseCheckpoint(HoldBooking::AFTER_LOCK);
    $outcomes = $run->wait();

    $acme = returnedArray($outcomes['acme']);
    $bravo = returnedArray($outcomes['bravo']);

    expect($blocked)->toBe(0)
        ->and($acme['held'] ?? null)->toBeString()
        ->and($bravo['held'] ?? null)->toBeString()
        ->and($acme['held'] ?? null)->not->toBe($bravo['held'] ?? null)
        ->and([$acme['retries'] ?? null, $bravo['retries'] ?? null])->toBe([0, 0])
        ->and(bookingsIn('tenant_acme'))->toHaveCount(1)
        ->and(bookingsIn('tenant_bravo'))->toHaveCount(1)
        ->and(DB::table('tenant_acme.staff_days')->count())->toBe(1)
        ->and(DB::table('tenant_bravo.staff_days')->count())->toBe(1);
});

it('refuses under lock a start an availability read offered as free, once a competing hold on it has committed, naming the segment; the read that follows no longer offers it (new interleaving)', function () {
    $run = ConcurrentRun::of([
        'a' => holdWorker('acme', [[1, 1]], '2026-10-01 09:00'),
        'b' => holdWorker('acme', [[1, 1]], '2026-10-01 09:30', 'b.start'),
    ])->holding('b.start', HoldBooking::AFTER_VALIDATION)->start();
    $run->release();
    $run->awaitCheckpoint('a', HoldBooking::AFTER_VALIDATION);
    // A stands past its validation with nothing committed: the read offers the start B wants.
    $offeredWhileAStood = holdStartsOffered($this, 1);
    // B plans on that same picture, then blocks on the mutex row A holds.
    $run->releaseCheckpoint(HoldBooking::AFTER_VALIDATION, 'b');
    $run->releaseCheckpoint('b.start');
    $waiting = lockWaiter('insert into staff_days');
    $run->releaseCheckpoint(HoldBooking::AFTER_VALIDATION, 'a');
    $outcomes = $run->wait();
    $offeredAfter = holdStartsOffered($this, 1);

    $a = returnedArray($outcomes['a']);
    $b = returnedArray($outcomes['b']);
    $rows = bookingsIn();

    expect($offeredWhileAStood)->toContain('2026-10-01T09:30:00+00:00')
        ->and($waiting['wait_event'])->toBeIn(['transactionid', 'tuple'])
        ->and($a['held'] ?? null)->toBeString()
        ->and($b['conflict'] ?? null)->toBe(0)
        ->and($b['wrapped'] ?? null)->toBeFalse()
        ->and(holdTransactionOf(holdStatementsOf($b)))->toContain(PostgresBookingWriter::overlapSql())
        ->and($offeredAfter)->not->toContain('2026-10-01T09:30:00+00:00')
        ->and($offeredAfter)->toContain('2026-10-01T10:00:00+00:00')
        ->and($rows)->toHaveCount(1)
        ->and($rows[0])->toMatchArray(['status' => 'pending', 'staff_id' => 1, 'blocks' => true, 'start_utc' => '2026-10-01 09:00'])
        ->and(DB::table('tenant_acme.staff_days')->count())->toBe(1);
});

it('keeps one hot staff-day consistent under sustained N-way contention through the catalog: no two blocking items overlap, bookings plus conflicts equal attempts, no retries, bounded latency (test 18)', function () {
    $workers = 8;
    $attemptsEach = 6;
    mt_srand(18);
    $closures = [];

    for ($worker = 0; $worker < $workers; $worker++) {
        $plan = [];

        for ($attempt = 0; $attempt < $attemptsEach; $attempt++) {
            // Starts on the grid between 06:00 and 13:00 UTC, 09:00 to 16:00 in Athens, so a Cut
            // of an hour or a Trim of half an hour ends inside the hours.
            $startMinute = mt_rand(0, 84) * 5;
            $plan[] = [
                mt_rand(0, 1) === 0 ? 1 : 2,
                sprintf('2026-10-01 %02d:%02d', 6 + intdiv($startMinute, 60), $startMinute % 60),
            ];
        }

        $closures['w'.$worker] = contentionWorker('acme', $plan);
    }

    $outcomes = ConcurrentRun::of($closures)->budget(30.0)->run();

    $attempts = 0;
    $bookings = 0;
    $conflicts = 0;
    $unavailable = 0;
    $retries = 0;
    $failures = [];
    $latencies = [];

    foreach ($outcomes as $name => $outcome) {
        $report = returnedArray($outcome);
        $retries += is_int($report['retries'] ?? null) ? $report['retries'] : 0;

        foreach (is_array($report['results'] ?? null) ? $report['results'] : [] as $result) {
            assert(is_array($result) && is_string($result['ended'] ?? null) && is_float($result['ms'] ?? null));
            $attempts++;
            $latencies[] = $result['ms'];

            match ($result['ended']) {
                'booked' => $bookings++,
                'conflict' => $conflicts++,
                'unavailable' => $unavailable++,
                default => $failures[] = $name.': '.$result['ended'],
            };
        }
    }

    sort($latencies);
    $p99 = $latencies[max(0, (int) ceil(0.99 * count($latencies)) - 1)] ?? 0.0;

    fwrite(STDOUT, sprintf(
        "\n  test 18: attempts=%d bookings=%d conflicts=%d unavailable=%d retries=%d p99=%.1fms (%d workers x %d attempts)\n",
        $attempts,
        $bookings,
        $conflicts,
        $unavailable,
        $retries,
        $p99,
        $workers,
        $attemptsEach,
    ));

    // One staff-day means one mutex row per transaction, so no lock cycle can form and no
    // deadlock is possible; READ COMMITTED never raises a serialization failure. The retry
    // bound is therefore zero. The latency bound is the lock timeout: an attempt that waited
    // past it would have ended busy, not booked or conflicted. A slot already taken is refused
    // by the planner's advisory read as unavailable when the read saw the booking, and under
    // the lock as a conflict when it did not; both are the domain answer, and every attempt
    // ends as one of the three.
    expect($failures)->toBe([])
        ->and($attempts)->toBe($workers * $attemptsEach)
        ->and($bookings + $conflicts + $unavailable)->toBe($attempts)
        ->and($bookings)->toBeGreaterThan(0)
        ->and($conflicts + $unavailable)->toBeGreaterThan(0)
        ->and($retries)->toBe(0)
        ->and($p99)->toBeLessThan(3000.0)
        ->and(DB::table('tenant_acme.bookings')->count())->toBe($bookings)
        ->and(DB::table('tenant_acme.booking_items')->where('blocks', true)->count())->toBe($bookings)
        ->and(overlappingBlockingPairs())->toBe(0)
        ->and(DB::table('tenant_acme.staff_days')->where('staff_id', 1)->value('rev'))->toBe($bookings - 1);
});
