<?php

use App\Application\ChainUnavailable;
use App\Application\Clock;
use App\Application\Dto\Customer;
use App\Application\Dto\HoldItem;
use App\Application\Dto\HoldPlan;
use App\Application\Dto\HoldRequest;
use App\Application\Dto\Intake;
use App\Application\Dto\SegmentChoice;
use App\Application\HoldBooking;
use App\Domain\Audit\Actor;
use App\Domain\Availability\Granularity;
use App\Domain\Availability\WindowGeometry;
use App\Domain\Booking\SegmentConflict;
use App\Domain\Enum\ApprovalTimeout;
use App\Domain\Enum\BookingSource;
use App\Domain\Enum\BookingStatus;
use App\Domain\Enum\HoldClass;
use App\Domain\Enum\PaymentMode;
use App\Domain\Money\Currency;
use App\Domain\Money\CurrencyMismatch;
use App\Domain\Money\Money;
use App\Http\ErrorEnvelope;
use App\Infrastructure\Cache\StaffDayMaskCache;
use App\Infrastructure\Db\MasksFromRows;
use App\Infrastructure\Db\Pool;
use App\Infrastructure\HoldBookingFactory;
use App\Tenancy\Tenant;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Assert;
use Tests\Support\FixedClock;
use Tests\Support\FixtureDay;

pest()->use(DatabaseTruncation::class);

/*
 * The hold from the catalog on luna's real rows, through the factory: what a chain lands as,
 * who gets assigned, what is refused before a transaction opens and what under the lock, what
 * the booking copies from its services, what the cache holds after the commit, and that the
 * factory's parts share one tenant.
 *
 * Not wrapped in a transaction: every hold enters the transaction runner, which refuses to open
 * inside another. The central tables are truncated before each test and the demo schemas
 * dropped and the central rows deleted after.
 *
 * luna is in Europe/Athens, UTC+2 in November. 2026-11-03 is a Tuesday, when Elena works 09:00
 * to 13:00 and 14:00 to 18:00 and Nikos 10:00 to 19:00. The clock stands on the Monday before.
 */

beforeEach(function () {
    dropDemoSchemas();
    seedDemo();
    chainClockAt('2026-11-02 06:00:00');
});

afterEach(function () {
    dropDemoSchemas();
    DB::table('users')->delete();
    DB::table('tenants')->delete();
});

function chainClockAt(string $text): void
{
    app()->instance(Clock::class, FixedClock::at($text));
}

function chainTenant(string $slug = 'luna'): Tenant
{
    return Tenant::query()->where('slug', $slug)->sole();
}

function chainUseCase(string $slug = 'luna'): HoldBooking
{
    return app(HoldBookingFactory::class)->for(chainTenant($slug), Pool::Whole);
}

function chainLunaId(string $table, string $name): int
{
    $id = DB::table('tenant_luna.'.$table)->where('name', $name)->value('id');

    assert(is_int($id));

    return $id;
}

function athensAt(string $text): DateTimeImmutable
{
    return new DateTimeImmutable($text, new DateTimeZone('Europe/Athens'));
}

/**
 * @param  list<array{string, string|null}>  $items  service name and staff name or null
 */
function chainRequest(array $items, string $start, bool $sameStaff = false): HoldRequest
{
    return new HoldRequest(
        new Customer('Casey Customer', 'casey@example.com', '+30 210 0000000'),
        array_map(fn (array $item): SegmentChoice => new SegmentChoice(chainLunaId('services', $item[0]), $item[1] === null ? null : chainLunaId('staff', $item[1])), $items),
        athensAt($start),
        new Intake(BookingSource::Embed),
        $sameStaff,
    );
}

/**
 * The buffers and the processing gap of one of luna's seeded services, the three columns the
 * demo catalog leaves at zero. The chain shapes need a cleanup running into a setup, and no
 * seeded service carries a buffer.
 */
function chainBuffers(string $service, int $before, int $after, int $gapMin = 0): void
{
    DB::table('tenant_luna.services')->where('name', $service)->update([
        'buffer_before_min' => $before,
        'buffer_after_min' => $after,
        'processing_gap_min' => $gapMin,
    ]);
}

/**
 * The bookings of a schema with their items, oldest first, every instant as Athens wall-clock
 * text.
 *
 * @return list<array<mixed>>
 */
function chainRows(string $schema = 'tenant_luna'): array
{
    $rows = [];

    foreach (DB::select(sprintf(
        'select b.id, b.uuid::text as uuid, b.status, b.hold_class, b.total_minor, b.currency, b.payment_mode, b.requires_approval, b.on_approval_timeout,'
        .' i.sort, i.service_id, i.staff_id, i.price_minor, i.blocks,'
        ." to_char(i.start_utc at time zone 'Europe/Athens', 'YYYY-MM-DD HH24:MI') as start_at,"
        ." to_char(i.end_utc at time zone 'Europe/Athens', 'YYYY-MM-DD HH24:MI') as end_at,"
        ." to_char(i.block_start_utc at time zone 'Europe/Athens', 'YYYY-MM-DD HH24:MI') as block_start,"
        ." to_char(i.block_end_utc at time zone 'Europe/Athens', 'YYYY-MM-DD HH24:MI') as block_end,"
        ." to_char(i.processing_ends_utc at time zone 'Europe/Athens', 'YYYY-MM-DD HH24:MI') as processing_ends"
        .' from %1$s.bookings b join %1$s.booking_items i on i.booking_id = b.id order by b.id, i.sort',
        $schema,
    )) as $row) {
        assert($row instanceof stdClass);

        $rows[] = get_object_vars($row);
    }

    return $rows;
}

/**
 * The one booking row of a schema, as a map. No row, or more than one, fails the test.
 *
 * @return array<mixed>
 */
function chainBooking(string $schema = 'tenant_luna'): array
{
    $rows = DB::select(sprintf(
        'select status, hold_class, total_minor, currency, payment_mode, requires_approval, on_approval_timeout,'
        ." hold_expires_at = created_at + interval '15 minutes' as after_checkout_ttl,"
        ." hold_expires_at = created_at + interval '48 hours' as after_approval_ttl,"
        ." to_char(hold_expires_at at time zone 'UTC', 'YYYY-MM-DD HH24:MI:SS.US') as expires_at"
        .' from %s.bookings',
        $schema,
    ));
    $row = $rows[0] ?? null;

    if (count($rows) !== 1 || ! $row instanceof stdClass) {
        Assert::fail(sprintf('Expected one booking, got %d.', count($rows)));
    }

    return get_object_vars($row);
}

/**
 * The one row a query returns, as a map. No row, or more than one, fails the test.
 *
 * @return array<mixed>
 */
function chainOne(string $sql): array
{
    $rows = DB::select($sql);
    $row = $rows[0] ?? null;

    if (count($rows) !== 1 || ! $row instanceof stdClass) {
        Assert::fail(sprintf('Expected one row, got %d.', count($rows)));
    }

    return get_object_vars($row);
}

/**
 * The statements a closure ran, by their text.
 *
 * @return list<string>
 */
function chainStatements(Closure $run): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        $run();
    } finally {
        $log = DB::getQueryLog();
        DB::disableQueryLog();
        DB::flushQueryLog();
    }

    return array_values(array_map(fn (array $entry): string => $entry['query'], $log));
}

/**
 * What the closure throws, of the class expected. Anything else, or nothing, fails the test.
 *
 * @template T of Throwable
 *
 * @param  class-string<T>  $class
 * @return T
 */
function chainRefusal(Closure $run, string $class): Throwable
{
    try {
        $run();
    } catch (Throwable $thrown) {
        if ($thrown instanceof $class) {
            return $thrown;
        }

        throw $thrown;
    }

    Assert::fail(sprintf('No %s was thrown.', $class));
}

/**
 * A plan built by hand, as the planner would have made it from stale rows: one item per
 * [staff name, start, end] in Athens wall-clock text, on the Haircut, with no buffers.
 *
 * @param  list<array{string, string, string}>  $items
 */
function chainPlanOf(array $items): HoldPlan
{
    $haircut = chainLunaId('services', 'Haircut');

    return new HoldPlan(
        new Customer('Casey Customer', 'casey@example.com'),
        array_map(fn (array $item): HoldItem => new HoldItem(chainLunaId('staff', $item[0]), $haircut, athensAt($item[1]), athensAt($item[2]), athensAt($item[1]), athensAt($item[2]), null, new Money(3500, Currency::of('EUR'))), $items),
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
 * A row for an approval request on the staff member on 2026-11-03 from 10:00 to 12:00 Athens,
 * a minute past its window by the database's clock, with the given timeout policy, its item
 * still blocking as no sweeper has run.
 */
function chainRequestRow(int $staffId, string $onApprovalTimeout): int
{
    $bookingId = DB::table('tenant_luna.bookings')->insertGetId([
        ...bookingRow(),
        'status' => 'awaiting_approval',
        'hold_class' => 'approval',
        'hold_expires_at' => DB::raw("now() - interval '1 minute'"),
        'on_approval_timeout' => $onApprovalTimeout,
    ]);

    $start = FixtureDay::start()->setTimezone(new DateTimeZone('UTC'));
    DB::table('tenant_luna.booking_items')->insert(itemRow($bookingId, $staffId, $start->format('Y-m-d H:i:sP'), $start->add(new DateInterval('PT2H'))->format('Y-m-d H:i:sP')));

    return $bookingId;
}

it('lands a cut on Elena then a colour on Nikos with its processing gap as one pending checkout booking of two items whose starts, ends, block ranges and processing end follow from the chain, prices from the services and the total their sum', function () {
    $held = chainUseCase()->hold(chainRequest([['Haircut', 'Elena Papadaki'], ['Colour', 'Nikos Georgiou']], '2026-11-03 10:00'), Actor::customer());
    $rows = chainRows();
    $elena = chainLunaId('staff', 'Elena Papadaki');
    $nikos = chainLunaId('staff', 'Nikos Georgiou');

    expect($rows)->toHaveCount(2)
        ->and($rows[0])->toMatchArray([
            'uuid' => $held->uuid, 'status' => 'pending', 'hold_class' => 'checkout', 'total_minor' => 11500, 'currency' => 'EUR',
            'payment_mode' => 'online', 'requires_approval' => false, 'on_approval_timeout' => 'expire',
            'sort' => 0, 'service_id' => chainLunaId('services', 'Haircut'), 'staff_id' => $elena, 'price_minor' => 3500, 'blocks' => true,
            'start_at' => '2026-11-03 10:00', 'end_at' => '2026-11-03 10:45', 'block_start' => '2026-11-03 10:00', 'block_end' => '2026-11-03 10:45', 'processing_ends' => null,
        ])
        ->and($rows[1])->toMatchArray([
            'sort' => 1, 'service_id' => chainLunaId('services', 'Colour'), 'staff_id' => $nikos, 'price_minor' => 8000, 'blocks' => true,
            'start_at' => '2026-11-03 10:45', 'end_at' => '2026-11-03 11:45', 'block_start' => '2026-11-03 10:45', 'block_end' => '2026-11-03 11:45', 'processing_ends' => '2026-11-03 12:15',
        ])
        ->and($held->total->minor)->toBe(11500)
        ->and($held->total->currency->code)->toBe('EUR')
        ->and($held->holdExpiresAt->format('Y-m-d H:i:s.u'))->toBe(chainBooking()['expires_at'])
        ->and(chainBooking()['after_checkout_ttl'])->toBeTrue()
        ->and(DB::table('tenant_luna.staff_days')->orderBy('staff_id')->get(['staff_id', 'day', 'rev'])->map(fn (stdClass $row): array => (array) $row)->all())
        ->toBe([['staff_id' => $elena, 'day' => '2026-11-03', 'rev' => 0], ['staff_id' => $nikos, 'day' => '2026-11-03', 'rev' => 0]]);
});

it('starts the segment after a colour when its processing gap ends, the member free during the gap', function () {
    chainUseCase()->hold(chainRequest([['Colour', 'Nikos Georgiou'], ['Haircut', 'Elena Papadaki']], '2026-11-03 10:00'), Actor::customer());
    $rows = chainRows();

    expect($rows[0])->toMatchArray(['start_at' => '2026-11-03 10:00', 'end_at' => '2026-11-03 11:00', 'processing_ends' => '2026-11-03 11:30'])
        ->and($rows[1])->toMatchArray(['start_at' => '2026-11-03 11:30', 'end_at' => '2026-11-03 12:15', 'processing_ends' => null]);
});

it('lands a same-member chain of a cleanup running with no gap into the next setup as two rows whose block ranges meet at the second segment\'s start, the member occupied from the first setup to the last cleanup, against the real exclusion constraint', function () {
    chainBuffers('Haircut', 10, 15);
    chainBuffers('Colour', 15, 20);
    $elena = chainLunaId('staff', 'Elena Papadaki');

    // Raw, the two blocks are 09:50 to 11:00 and 10:30 to 12:05 on one member, which the
    // constraint would refuse; the boundary at 10:45 lets them in without giving any of the
    // stretch back.
    chainUseCase()->hold(chainRequest([['Haircut', 'Elena Papadaki'], ['Colour', 'Elena Papadaki']], '2026-11-03 10:00'), Actor::customer());
    $rows = chainRows();
    $exclusion = chainOne("select pg_get_constraintdef(oid) as definition from pg_constraint where conrelid = 'tenant_luna.booking_items'::regclass and contype = 'x'");

    expect($rows)->toHaveCount(2)
        ->and($rows[0])->toMatchArray([
            'sort' => 0, 'staff_id' => $elena, 'blocks' => true,
            'start_at' => '2026-11-03 10:00', 'end_at' => '2026-11-03 10:45', 'block_start' => '2026-11-03 09:50', 'block_end' => '2026-11-03 10:45',
        ])
        ->and($rows[1])->toMatchArray([
            'sort' => 1, 'staff_id' => $elena, 'blocks' => true,
            'start_at' => '2026-11-03 10:45', 'end_at' => '2026-11-03 11:45', 'block_start' => '2026-11-03 10:45', 'block_end' => '2026-11-03 12:05',
        ])
        ->and($exclusion['definition'])->toContain('EXCLUDE USING gist')
        ->and($exclusion['definition'])->toContain('WHERE (blocks)');
});

it('keeps on the member a cleanup that outruns the whole block after it: the chain lands as one unbroken stretch, a competing hold inside that cleanup is refused, and one at its end is taken', function () {
    chainBuffers('Colour', 0, 30);
    $nikos = chainLunaId('staff', 'Nikos Georgiou');

    // Half an hour of cleanup after a colour, then a twenty-minute trim: the trim's row carries
    // the ten minutes of cleanup that outlive it, so the member stays occupied to 12:30.
    chainUseCase()->hold(chainRequest([['Colour', 'Nikos Georgiou'], ['Beard trim', 'Nikos Georgiou']], '2026-11-03 11:00'), Actor::customer());
    $chain = chainRows();
    $refused = chainRefusal(fn () => chainUseCase()->hold(chainRequest([['Beard trim', 'Nikos Georgiou']], '2026-11-03 12:20'), Actor::customer()), ChainUnavailable::class);
    chainUseCase()->hold(chainRequest([['Beard trim', 'Nikos Georgiou']], '2026-11-03 12:30'), Actor::customer());

    expect($chain)->toHaveCount(2)
        ->and($chain[0])->toMatchArray([
            'sort' => 0, 'staff_id' => $nikos, 'blocks' => true,
            'start_at' => '2026-11-03 11:00', 'end_at' => '2026-11-03 12:00', 'block_start' => '2026-11-03 11:00', 'block_end' => '2026-11-03 12:00',
        ])
        ->and($chain[1])->toMatchArray([
            'sort' => 1, 'staff_id' => $nikos, 'blocks' => true,
            'start_at' => '2026-11-03 12:00', 'end_at' => '2026-11-03 12:20', 'block_start' => '2026-11-03 12:00', 'block_end' => '2026-11-03 12:30',
        ])
        ->and($refused->segment)->toBe(0)
        ->and(chainRows()[2])->toMatchArray(['start_at' => '2026-11-03 12:30', 'block_start' => '2026-11-03 12:30', 'block_end' => '2026-11-03 12:50'])
        ->and(DB::table('tenant_luna.bookings')->count())->toBe(2);
});

it('honours a pick; with no pick takes the least loaded eligible member, the lowest id on a tie, the same member on every run; and with same_staff lands every segment on one member', function () {
    // Rows are deleted between holds here without a revision bump, which a warm store would
    // rightly not notice: every read runs cold.
    app()->instance(StaffDayMaskCache::class, coldMaskCache());
    $elena = chainLunaId('staff', 'Elena Papadaki');
    $nikos = chainLunaId('staff', 'Nikos Georgiou');
    $useCase = chainUseCase();
    $staffOf = function (): array {
        $staff = [];

        foreach (chainRows() as $row) {
            assert(is_int($row['staff_id']));
            $staff[] = $row['staff_id'];
        }

        return $staff;
    };
    $wipe = function (): void {
        DB::table('tenant_luna.booking_items')->delete();
        DB::table('tenant_luna.bookings')->delete();
    };

    $useCase->hold(chainRequest([['Haircut', 'Nikos Georgiou']], '2026-11-03 10:00'), Actor::customer());
    $picked = $staffOf();
    $wipe();

    $runs = [];

    for ($run = 0; $run < 3; $run++) {
        $useCase->hold(chainRequest([['Haircut', null]], '2026-11-03 10:00'), Actor::customer());
        $runs[] = $staffOf();
        $wipe();
    }

    // A confirmed booking on Elena that day makes Nikos the lighter of the two.
    $booked = DB::table('tenant_luna.bookings')->insertGetId(bookingRow());
    DB::table('tenant_luna.booking_items')->insert(itemRow($booked, $elena, '2026-11-03 13:00:00+00', '2026-11-03 14:00:00+00'));
    $useCase->hold(chainRequest([['Haircut', null]], '2026-11-03 10:00'), Actor::customer());
    $lighter = $staffOf();
    $wipe();

    $useCase->hold(chainRequest([['Haircut', null], ['Colour', null]], '2026-11-03 10:00', sameStaff: true), Actor::customer());
    $sameStaff = $staffOf();

    expect($elena)->toBeLessThan($nikos)
        ->and($picked)->toBe([$nikos])
        ->and($runs)->toBe([[$elena], [$elena], [$elena]])
        ->and($lighter)->toBe([$elena, $nikos])
        ->and($sameStaff)->toBe([$elena, $elena]);
});

it('refuses before any transaction opens, naming the segment and writing nothing: a pick outside their hours, a start on a blackout, one inside the lead time, and one past the horizon', function (array $items, string $start, ?Closure $arrange, string $sentence, int $segment) {
    /** @var list<array{string, string|null}> $items */
    if ($arrange !== null) {
        $arrange();
    }

    $refused = null;
    $statements = chainStatements(function () use (&$refused, $items, $start): void {
        $refused = chainRefusal(fn () => chainUseCase()->hold(chainRequest($items, $start), Actor::customer()), ChainUnavailable::class);
    });

    expect($refused?->segment)->toBe($segment)
        ->and($refused?->getMessage())->toContain($sentence)
        ->and(array_filter($statements, fn (string $statement): bool => str_starts_with($statement, 'set local')))->toBe([])
        ->and(DB::table('tenant_luna.bookings')->count())->toBe(0)
        ->and(DB::table('tenant_luna.staff_days')->count())->toBe(0);
})->with([
    'Elena picked on a Saturday' => [[['Haircut', 'Elena Papadaki']], '2026-11-07 11:00', null, 'No eligible staff member can start segment 0', 0],
    'a cut then Nikos inside his blackout' => [[['Haircut', 'Elena Papadaki'], ['Beard trim', 'Nikos Georgiou']], '2026-12-04 14:00', null, 'segment 1', 1],
    'the tenant-wide blackout' => [[['Haircut', null]], '2026-12-25 11:00', null, 'segment 0', 0],
    'inside a two-hour lead time' => [[['Haircut', null], ['Colour', null]], '2026-11-02 09:30', fn () => DB::table('tenant_luna.services')->where('name', 'Colour')->update(['lead_time_min' => 120]), 'its lead time', 0],
    'past a three-day horizon' => [[['Haircut', null], ['Colour', null]], '2026-11-10 11:00', fn () => DB::table('tenant_luna.services')->where('name', 'Colour')->update(['horizon_days' => 3]), 'its horizon', 0],
]);

it('refuses under the lock, as the unavailable refusal naming the segment with nothing written, a plan placing a member outside their hours or on a blackout, even though no item overlaps', function () {
    $outside = chainRefusal(fn () => chainUseCase()->execute(chainPlanOf([['Nikos Georgiou', '2026-11-03 10:00', '2026-11-03 10:45'], ['Elena Papadaki', '2026-11-03 13:15', '2026-11-03 14:00']]), Actor::customer()), ChainUnavailable::class);
    $blackout = chainRefusal(fn () => chainUseCase()->execute(chainPlanOf([['Nikos Georgiou', '2026-12-04 15:00', '2026-12-04 15:45']]), Actor::customer()), ChainUnavailable::class);

    expect($outside->segment)->toBe(1)
        ->and($outside->getMessage())->toContain('outside their availability')
        ->and($blackout->segment)->toBe(0)
        ->and(DB::table('tenant_luna.bookings')->count())->toBe(0)
        ->and(DB::table('tenant_luna.booking_items')->count())->toBe(0)
        ->and(DB::table('tenant_luna.staff_days')->count())->toBe(0)
        ->and(DB::transactionLevel())->toBe(0);
});

it('answers the two refusals the lock can raise with two codes: a member the tenant is not open for is 409 unavailable with its segment, a range somebody already holds is 409 overlap with its own', function () {
    // Both are raised on the hold path against the same rows, one after the other, so the
    // codes are compared on refusals this engine actually produced rather than on refusals
    // built by hand. The blackout is the case the old vocabulary got wrong: it was answered as
    // somebody else's booking, which is a sentence about a thing that did not happen.
    chainUseCase()->hold(chainRequest([['Haircut', 'Elena Papadaki']], '2026-11-03 10:00'), Actor::customer());

    $blackout = chainRefusal(fn () => chainUseCase()->execute(chainPlanOf([['Nikos Georgiou', '2026-12-04 15:00', '2026-12-04 15:45']]), Actor::customer()), ChainUnavailable::class);
    $taken = chainRefusal(fn () => chainUseCase()->execute(chainPlanOf([['Elena Papadaki', '2026-11-03 10:15', '2026-11-03 11:00']]), Actor::customer()), SegmentConflict::class);

    $unavailable = ErrorEnvelope::for($blackout);
    $overlap = ErrorEnvelope::for($taken);

    expect([$unavailable->code, $unavailable->status, $unavailable->data])->toBe(['unavailable', 409, ['segment' => 0]])
        ->and($unavailable->detail)->toBe(apiSentence(ErrorEnvelope::UNAVAILABLE_DETAIL))
        ->and([$overlap->code, $overlap->status, $overlap->data])->toBe(['overlap', 409, ['segment' => 0]])
        ->and($overlap->detail)->toBe(apiSentence(ErrorEnvelope::OVERLAP_DETAIL))
        ->and($unavailable->code)->not->toBe($overlap->code)
        ->and(DB::table('tenant_luna.bookings')->count())->toBe(1);
});

it('copies payment_mode and requires_approval from the services: an approval service lands awaiting_approval on the approval class expiring after the settings default, or after the service\'s own window', function () {
    app()->instance(StaffDayMaskCache::class, coldMaskCache());
    chainUseCase()->hold(chainRequest([['Haircut', 'Elena Papadaki'], ['Bridal trial', 'Elena Papadaki']], '2026-11-03 09:00'), Actor::customer());
    $defaulted = chainBooking();

    DB::table('tenant_luna.booking_items')->delete();
    DB::table('tenant_luna.bookings')->delete();
    DB::table('tenant_luna.services')->where('name', 'Bridal trial')->update(['approval_hold_hours' => 6]);
    chainUseCase()->hold(chainRequest([['Bridal trial', 'Elena Papadaki']], '2026-11-03 09:00'), Actor::customer());
    $own = chainOne("select hold_expires_at = created_at + interval '6 hours' as after_own_window from tenant_luna.bookings");

    expect($defaulted)->toMatchArray(['status' => 'awaiting_approval', 'hold_class' => 'approval', 'payment_mode' => 'online', 'requires_approval' => true, 'after_approval_ttl' => true, 'after_checkout_ttl' => false])
        ->and($own['after_own_window'])->toBeTrue();
});

it('lands a plain service pending on the checkout class expiring after the settings row\'s checkout_ttl_min, proven by changing that setting', function () {
    DB::table('tenant_luna.settings')->where('id', 1)->update(['checkout_ttl_min' => 20]);

    chainUseCase()->hold(chainRequest([['Haircut', 'Elena Papadaki']], '2026-11-03 09:00'), Actor::customer());
    $row = chainOne("select status, hold_class, payment_mode, requires_approval, hold_expires_at = created_at + interval '20 minutes' as after_twenty from tenant_luna.bookings");

    expect($row)->toBe(['status' => 'pending', 'hold_class' => 'checkout', 'payment_mode' => 'onsite', 'requires_approval' => false, 'after_twenty' => true]);
});

it('copies on_approval_timeout as expire for a chain of one auto_approve and one expire approval service, and auto_approve when its only approval service says so', function () {
    app()->instance(StaffDayMaskCache::class, coldMaskCache());
    DB::table('tenant_luna.services')->where('name', 'Bridal trial')->update(['on_approval_timeout' => 'auto_approve']);
    DB::table('tenant_luna.services')->where('name', 'Colour')->update(['requires_approval' => true]);

    chainUseCase()->hold(chainRequest([['Bridal trial', 'Elena Papadaki'], ['Colour', 'Nikos Georgiou']], '2026-11-03 09:00'), Actor::customer());
    $mixed = chainBooking();
    DB::table('tenant_luna.booking_items')->delete();
    DB::table('tenant_luna.bookings')->delete();

    chainUseCase()->hold(chainRequest([['Bridal trial', 'Elena Papadaki'], ['Haircut', 'Nikos Georgiou']], '2026-11-03 09:00'), Actor::customer());
    $auto = chainBooking();

    expect($mixed)->toMatchArray(['status' => 'awaiting_approval', 'on_approval_timeout' => 'expire'])
        ->and($auto)->toMatchArray(['status' => 'awaiting_approval', 'on_approval_timeout' => 'auto_approve']);
});

it('refuses a competing hold on the slot of an auto-approve request past its window as a conflict naming the segment, before the transaction by the advisory read and under the lock by the reap leaving it, while the request stays awaiting_approval and blocking', function () {
    $elena = chainLunaId('staff', 'Elena Papadaki');
    $request = chainRequestRow($elena, 'auto_approve');
    $expiring = chainRequestRow(chainLunaId('staff', 'Nikos Georgiou'), 'expire');

    $advisory = chainRefusal(fn () => chainUseCase()->hold(chainRequest([['Haircut', 'Elena Papadaki']], '2026-11-03 10:30'), Actor::customer()), ChainUnavailable::class);
    $locked = chainRefusal(fn () => chainUseCase()->execute(chainPlanOf([['Elena Papadaki', '2026-11-03 10:30', '2026-11-03 11:15']]), Actor::customer()), SegmentConflict::class);
    chainUseCase()->hold(chainRequest([['Haircut', 'Nikos Georgiou']], '2026-11-03 10:30'), Actor::customer());

    $rows = chainRows();

    expect($advisory->segment)->toBe(0)
        ->and($locked->segment)->toBe(0)
        ->and($locked->getMessage())->toContain('overlaps a blocking item')
        ->and($rows[0])->toMatchArray(['id' => $request, 'status' => 'awaiting_approval', 'on_approval_timeout' => 'auto_approve', 'blocks' => true])
        ->and($rows[1])->toMatchArray(['id' => $expiring, 'status' => 'expired', 'blocks' => false])
        ->and($rows[2])->toMatchArray(['status' => 'pending', 'staff_id' => chainLunaId('staff', 'Nikos Georgiou'), 'blocks' => true, 'start_at' => '2026-11-03 10:30']);
});

it('refuses a chain mixing two currencies before any transaction opens, with nothing written', function () {
    DB::table('tenant_luna.services')->where('name', 'Beard trim')->update(['currency' => 'GBP']);

    $statements = chainStatements(function (): void {
        chainRefusal(fn () => chainUseCase()->hold(chainRequest([['Haircut', 'Nikos Georgiou'], ['Beard trim', 'Nikos Georgiou']], '2026-11-03 10:00'), Actor::customer()), CurrencyMismatch::class);
    });

    expect(array_filter($statements, fn (string $statement): bool => str_starts_with($statement, 'set local') || str_contains($statement, 'shift_patterns')))->toBe([])
        ->and(DB::table('tenant_luna.bookings')->count())->toBe(0);
});

it('stores, after the commit and through the container\'s default store, the post-insert mask of each staff-day it locked under the returned revision, byte-identical to a cold build, so the availability read that follows is warm and answers the cold bytes', function () {
    $elena = chainLunaId('staff', 'Elena Papadaki');
    $nikos = chainLunaId('staff', 'Nikos Georgiou');
    $haircut = chainLunaId('services', 'Haircut');
    $url = tenantUrl('luna', '/api/v1/availability?'.http_build_query(['items' => (string) json_encode([['service_id' => $haircut, 'staff_id' => $elena]]), 'from' => '2026-11-03', 'to' => '2026-11-04']));
    Cache::flush();

    chainUseCase()->hold(chainRequest([['Haircut', 'Elena Papadaki'], ['Colour', 'Nikos Georgiou']], '2026-11-03 10:00'), Actor::customer());

    $keys = [
        StaffDayMaskCache::key('tenant_luna', $elena, '2026-11-03', Granularity::default(), 0),
        StaffDayMaskCache::key('tenant_luna', $nikos, '2026-11-03', Granularity::default(), 0),
    ];
    $window = WindowGeometry::spanning('2026-11-03', 1, new DateTimeZone('Europe/Athens'), Granularity::default());
    $cold = MasksFromRows::forSchema(DB::connection(), 'tenant_luna')->notBookedMasks($window, [$elena, $nikos]);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $warm = $this->getJson($url)->assertOk();
    $warmLog = array_map(fn (array $entry): string => $entry['query'], DB::getQueryLog());
    DB::disableQueryLog();

    app()->instance(StaffDayMaskCache::class, coldMaskCache());
    $coldRead = $this->getJson($url)->assertOk();

    $storedForNikos = Cache::get($keys[1]);
    assert(is_string($storedForNikos));

    expect(Cache::get($keys[0]))->toBe(StaffDayMaskCache::encode($cold[$elena][0]))
        ->and($storedForNikos)->toBe(StaffDayMaskCache::encode($cold[$nikos][0]))
        ->and(StaffDayMaskCache::decode($storedForNikos, 288)?->earliestHoldExpiry)->not->toBeNull()
        ->and(array_filter($warmLog, fn (string $statement): bool => str_contains($statement, 'booking_items')))->toBe([])
        ->and(count(array_filter($warmLog, fn (string $statement): bool => str_contains($statement, 'staff_days'))))->toBe(1)
        ->and($warm->getContent())->toBe($coldRead->getContent())
        ->and($warm->json('2026-11-03'))->toContain('2026-11-03T07:00:00+00:00')
        ->and($warm->json('2026-11-03'))->not->toContain('2026-11-03T08:00:00+00:00');
});

it('touches the store only after the commit: the advisory read and the hold both write at transaction level zero, the hold under the locked revisions, and a refused hold writes nothing new', function () {
    $store = new class extends ArrayStore
    {
        /** @var list<array{string, int}> the key and the transaction level at each write */
        public array $puts = [];

        public function put($key, $value, $seconds): bool
        {
            $this->puts[] = [(string) $key, DB::transactionLevel()];

            return parent::put($key, $value, $seconds);
        }
    };
    app()->instance(StaffDayMaskCache::class, new StaffDayMaskCache(new CacheRepository($store)));
    $elena = chainLunaId('staff', 'Elena Papadaki');
    $nikos = chainLunaId('staff', 'Nikos Georgiou');

    chainUseCase()->hold(chainRequest([['Haircut', 'Elena Papadaki'], ['Colour', 'Nikos Georgiou']], '2026-11-03 10:00'), Actor::customer());
    $afterHold = $store->puts;
    chainRefusal(fn () => chainUseCase()->hold(chainRequest([['Haircut', 'Elena Papadaki']], '2026-11-03 10:00'), Actor::customer()), ChainUnavailable::class);
    chainRefusal(fn () => chainUseCase()->execute(chainPlanOf([['Elena Papadaki', '2026-11-03 10:00', '2026-11-03 10:45']]), Actor::customer()), SegmentConflict::class);

    $levels = array_unique(array_map(fn (array $put): int => $put[1], $store->puts));
    $keys = array_map(fn (array $put): string => $put[0], $afterHold);

    expect(array_values($levels))->toBe([0])
        ->and(array_slice($keys, -2))->toBe([
            StaffDayMaskCache::key('tenant_luna', $elena, '2026-11-03', Granularity::default(), 0),
            StaffDayMaskCache::key('tenant_luna', $nikos, '2026-11-03', Granularity::default(), 0),
        ])
        ->and($store->puts)->toBe($afterHold);
});

it('is built for one tenant by the factory, whose parts share that tenant: a hold on pier lands in pier\'s schema, in pier\'s currency and zone, on pier\'s settings, and luna is untouched', function () {
    DB::table('tenant_pier.settings')->where('id', 1)->update(['checkout_ttl_min' => 25]);
    $assessment = DB::table('tenant_pier.services')->where('name', 'Initial assessment')->value('id');
    assert(is_int($assessment));

    // pier is in Europe/London on the default week; 2026-11-03 at 10:00 London is 12:00 in Athens.
    $held = chainUseCase('pier')->hold(new HoldRequest(
        new Customer('Casey Customer', 'casey@example.com'),
        [new SegmentChoice($assessment, null)],
        new DateTimeImmutable('2026-11-03 10:00', new DateTimeZone('Europe/London')),
        new Intake(BookingSource::Embed),
    ), Actor::customer());
    $row = chainOne("select currency, total_minor, hold_expires_at = created_at + interval '25 minutes' as after_pier_ttl from tenant_pier.bookings");
    $sources = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app'), FilesystemIterator::SKIP_DOTS)) as $file) {
        assert($file instanceof SplFileInfo);
        $source = (string) file_get_contents($file->getPathname());

        if (str_contains($source, 'new HoldBooking(')) {
            $sources[$file->getPathname()] = $source;
        }
    }

    expect($held->total->currency->code)->toBe('GBP')
        ->and($row)->toBe(['currency' => 'GBP', 'total_minor' => 6500, 'after_pier_ttl' => true])
        ->and(DB::table('tenant_pier.staff_days')->value('day'))->toBe('2026-11-03')
        ->and(DB::table('tenant_luna.bookings')->count())->toBe(0)
        ->and(DB::table('tenant_luna.staff_days')->count())->toBe(0)
        ->and(array_keys($sources))->toHaveCount(1)
        ->and(array_values($sources)[0])->toContain('final readonly class HoldBookingFactory');
});
