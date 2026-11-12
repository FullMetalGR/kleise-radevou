<?php

use App\Application\BookingUnknown;
use App\Application\ChainUnavailable;
use App\Application\Clock;
use App\Application\Dto\Customer;
use App\Application\Dto\HoldRequest;
use App\Application\Dto\Intake;
use App\Application\Dto\SegmentChoice;
use App\Application\Event\BookingRescheduled;
use App\Application\MoverRefused;
use App\Application\RescheduleBooking;
use App\Application\TransitionRefused;
use App\Domain\Audit\Actor;
use App\Domain\Availability\CalendarDay;
use App\Domain\Booking\SegmentConflict;
use App\Domain\Booking\TransitionRefusal;
use App\Domain\Enum\BookingSource;
use App\Domain\Enum\BookingStatus;
use App\Infrastructure\BookingTransitionFactory;
use App\Infrastructure\Cache\StaffDayMaskCache;
use App\Infrastructure\Db\LockManager;
use App\Infrastructure\Db\Pool;
use App\Infrastructure\Db\PostgresBookingWriter;
use App\Infrastructure\HoldBookingFactory;
use App\Tenancy\Tenant;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;
use Tests\Support\FixedClock;
use Tests\Support\FixtureDay;
use Tests\Support\LunaFixture;
use Tests\TestCase;

pest()->use(DatabaseTruncation::class);

/*
 * RescheduleBooking on luna's real rows through the factory: what a move writes, what it leaves
 * alone, what it refuses, and what the lock holds while it does it.
 *
 * Not wrapped in a transaction: every write here enters the transaction runner, which refuses to
 * open inside another. The mask cache is the null store, so every availability read comes from
 * the rows alone.
 *
 * luna is in Europe/Athens, UTC+2 in November. 2026-11-03 is a Tuesday and 2026-11-04 a
 * Wednesday; Elena works both, 09:00 to 13:00 and 14:00 to 18:00, and Nikos 10:00 to 19:00. The
 * clock stands on the Monday before unless a test moves it, which is outside every window here.
 * The customer of these holds is given a name, an address and a phone nothing else in the
 * fixtures shares, so the privacy assertion has something distinctive to look for.
 */

const MOVE_CUSTOMER_NAME = 'Ignatius Blenkinsopp';

const MOVE_CUSTOMER_EMAIL = 'ignatius.blenkinsopp@example.test';

const MOVE_CUSTOMER_PHONE = '+30 210 2468013';

beforeEach(function () {
    dropDemoSchemas();
    seedDemo();
    app()->instance(StaffDayMaskCache::class, coldMaskCache());
    moveClockAt('2026-11-02 06:00:00');
});

afterEach(function () {
    DB::purge('move_probe');
    dropDemoSchemas();
    DB::table('users')->delete();
    DB::table('tenants')->delete();
});

/**
 * The instant every use case built after this call reads as now. The factory is resolved fresh
 * each time, so moving the clock moves what the next write compares a window against.
 */
function moveClockAt(string $utc): void
{
    app()->instance(Clock::class, FixedClock::at($utc));
}

function moveTenant(): Tenant
{
    return Tenant::query()->where('slug', 'luna')->sole();
}

function moveUseCase(): RescheduleBooking
{
    return app(BookingTransitionFactory::class)->reschedule(moveTenant());
}

function moveLunaId(string $table, string $name): int
{
    $id = DB::table('tenant_luna.'.$table)->where('name', $name)->value('id');

    assert(is_int($id));

    return $id;
}

/**
 * The reschedule window a service imposes on the customer, in hours, set on the catalog row.
 * The policy reads it at move time, so a test sets it before it moves and not before it books.
 */
function moveWindowOf(string $service, int $hours): void
{
    DB::table('tenant_luna.services')->where('name', $service)->update(['reschedule_window_hours' => $hours]);
}

/**
 * An Athens wall-clock time as an instant.
 */
function moveAt(string $athens): DateTimeImmutable
{
    return new DateTimeImmutable($athens, new DateTimeZone('Europe/Athens'));
}

/**
 * A chain held the way the booking page takes one: the services in order on the member named,
 * at the Athens wall-clock time given.
 *
 * @param  non-empty-list<string>  $services
 * @return string the uuid of the booking it left
 */
function moveHeld(array $services = ['Haircut'], string $start = FixtureDay::START, string $staff = 'Elena Papadaki'): string
{
    return app(HoldBookingFactory::class)->for(moveTenant(), Pool::Whole)->hold(
        new HoldRequest(
            new Customer(MOVE_CUSTOMER_NAME, MOVE_CUSTOMER_EMAIL, MOVE_CUSTOMER_PHONE),
            array_map(fn (string $service): SegmentChoice => new SegmentChoice(moveLunaId('services', $service), moveLunaId('staff', $staff)), $services),
            moveAt($start),
            new Intake(BookingSource::Embed),
        ),
        Actor::customer(),
    )->uuid;
}

/**
 * A held booking settled, so a test has a confirmed one to move.
 *
 * @param  non-empty-list<string>  $services
 */
function moveConfirmed(array $services = ['Haircut'], string $start = FixtureDay::START, string $staff = 'Elena Papadaki'): string
{
    $uuid = moveHeld($services, $start, $staff);
    app(BookingTransitionFactory::class)->confirm(moveTenant())->confirm($uuid, Actor::customer());

    return $uuid;
}

/**
 * An instant as the server writes one: UTC text with the offset written out.
 */
function moveInstant(string $athens): string
{
    return moveAt($athens)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');
}

/**
 * A booking of luna written straight to the rows, in the state the test names, with one Haircut
 * item on Elena over the Athens hour given. Some of these states are reachable no other way.
 *
 * @param  string  $expiry  past, future, or none
 * @return string the uuid
 */
function moveSeeded(string $status, ?string $holdClass, string $expiry, string $hour = '10', bool $blocks = true, string $timeout = 'expire'): string
{
    $uuid = (string) Str::uuid();

    $bookingId = DB::table('tenant_luna.bookings')->insertGetId([
        'source' => 'embed',
        'uuid' => $uuid,
        'status' => $status,
        'hold_class' => $holdClass,
        'hold_expires_at' => match ($expiry) {
            'past' => DB::raw("now() - interval '1 minute'"),
            'future' => DB::raw("now() + interval '2 days'"),
            'none' => null,
            default => throw new LogicException(sprintf('An expiry is past, future or none, not "%s".', $expiry)),
        },
        'customer_name' => MOVE_CUSTOMER_NAME,
        'customer_email' => MOVE_CUSTOMER_EMAIL,
        'customer_phone' => MOVE_CUSTOMER_PHONE,
        'total_minor' => 3500,
        'currency' => 'EUR',
        'payment_mode' => 'onsite',
        'requires_approval' => false,
        'on_approval_timeout' => $timeout,
        'manage_token_hash' => str_repeat('c', 64),
    ]);

    DB::table('tenant_luna.booking_items')->insert([
        'booking_id' => $bookingId,
        'sort' => 0,
        'service_id' => moveLunaId('services', 'Haircut'),
        'staff_id' => moveLunaId('staff', 'Elena Papadaki'),
        'start_utc' => moveInstant('2026-11-03 '.$hour.':00'),
        'end_utc' => moveInstant('2026-11-03 '.$hour.':45'),
        'block_start_utc' => moveInstant('2026-11-03 '.$hour.':00'),
        'block_end_utc' => moveInstant('2026-11-03 '.$hour.':45'),
        'price_minor' => 3500,
        'blocks' => $blocks,
    ]);

    return $uuid;
}

/**
 * The booking row as it stands.
 *
 * @return array<string, mixed>
 */
function moveRow(string $uuid): array
{
    $row = DB::selectOne(
        'select status, hold_class, hold_expires_at::text as hold_expires_at, total_minor'
        .' from tenant_luna.bookings where uuid = ?::uuid',
        [$uuid],
    );

    assert($row instanceof stdClass);

    /** @var array<string, mixed> $booking */
    $booking = get_object_vars($row);

    return $booking;
}

/**
 * The booking's items in chain order, each spelled as the member, the service, the Athens
 * range, the contended range, the price and whether it blocks.
 *
 * @return list<string>
 */
function moveItems(string $uuid): array
{
    $athens = new DateTimeZone('Europe/Athens');
    $utc = new DateTimeZone('UTC');
    $items = [];

    foreach (DB::select(
        'select m.name as staff, s.name as service, i.start_utc::text as start_utc, i.end_utc::text as end_utc,'
        .' i.block_start_utc::text as block_start_utc, i.block_end_utc::text as block_end_utc,'
        .' i.processing_ends_utc::text as processing_ends_utc, i.price_minor, i.blocks'
        .' from tenant_luna.bookings b'
        .' join tenant_luna.booking_items i on i.booking_id = b.id'
        .' join tenant_luna.staff m on m.id = i.staff_id'
        .' join tenant_luna.services s on s.id = i.service_id'
        .' where b.uuid = ?::uuid order by i.sort',
        [$uuid],
    ) as $row) {
        assert($row instanceof stdClass);
        assert(is_string($row->staff) && is_string($row->service) && is_string($row->start_utc) && is_string($row->end_utc));
        assert(is_string($row->block_start_utc) && is_string($row->block_end_utc) && is_int($row->price_minor) && is_bool($row->blocks));

        $wall = fn (string $text): string => (new DateTimeImmutable($text, $utc))->setTimezone($athens)->format('m-d H:i');

        $items[] = sprintf(
            '%s %s %s-%s [%s-%s] %d %s',
            $row->staff,
            $row->service,
            $wall($row->start_utc),
            $wall($row->end_utc),
            $wall($row->block_start_utc),
            $wall($row->block_end_utc),
            $row->price_minor,
            $row->blocks ? 'blocking' : 'free',
        );
    }

    return $items;
}

/**
 * The trail of luna, oldest first, each row with the booking it names and its payload decoded.
 *
 * @return list<array<mixed>>
 */
function moveTrail(): array
{
    $rows = [];

    foreach (DB::select(
        'select a.actor, a.action, a.payload::text as payload, b.uuid::text as uuid'
        .' from tenant_luna.audit_log a join tenant_luna.bookings b on b.id = a.booking_id order by a.id'
    ) as $row) {
        assert($row instanceof stdClass && is_string($row->payload));

        /** @var array<string, mixed> $payload */
        $payload = json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR);
        ksort($payload);

        $values = get_object_vars($row);
        $values['payload'] = $payload;

        $rows[] = $values;
    }

    return $rows;
}

/**
 * What the closure threw as a refusal of the kind named. Anything else, or nothing, fails.
 *
 * @template T of Throwable
 *
 * @param  class-string<T>  $kind
 * @return T
 */
function moveRefusal(string $kind, Closure $run): Throwable
{
    try {
        $run();
    } catch (Throwable $thrown) {
        if ($thrown instanceof $kind) {
            return $thrown;
        }

        throw $thrown;
    }

    Assert::fail(sprintf('No %s was thrown.', $kind));
}

/**
 * The statements one run issued, each with its bindings, in order.
 *
 * @return list<array{sql: string, bindings: array<mixed>}>
 */
function moveTrace(Closure $run): array
{
    $trace = [];
    DB::listen(function (QueryExecuted $query) use (&$trace): void {
        $trace[] = ['sql' => $query->sql, 'bindings' => $query->bindings];
    });

    $run();

    return $trace;
}

/**
 * Where a statement first stands in a trace. A statement the run never issued fails the test
 * rather than answering a position no comparison would mean anything against.
 *
 * @param  list<array{sql: string, bindings: array<mixed>}>  $trace
 */
function movePositionOf(array $trace, string $sql): int
{
    $at = array_search($sql, array_map(fn (array $entry): string => $entry['sql'], $trace), true);

    if (! is_int($at)) {
        Assert::fail(sprintf('The run issued no statement "%s".', $sql));
    }

    return $at;
}

/**
 * The start times luna offers for the chain named with Elena pinned, on the Athens date given,
 * as the public read answers them. Read over the wire, because what a customer is offered next
 * is the question, and the mask cache is cold here so the answer comes from the rows.
 *
 * @param  non-empty-list<string>  $services
 * @return list<string>
 */
function moveOffered(TestCase $test, array $services = ['Haircut'], string $day = FixtureDay::DATE): array
{
    // The exclusive end of the one-day window, counted on the calendar through the one helper
    // that counts calendar days here. The codebase bans modify() with a wall-clock unit, and a
    // date label is not the place to make an exception to a rule read by pattern.
    $next = CalendarDay::plusDays($day, 1);
    $starts = $test->getJson(tenantUrl('luna', '/api/v1/availability?'.http_build_query([
        'items' => (string) json_encode(array_map(
            fn (string $service): array => ['service_id' => moveLunaId('services', $service), 'staff_id' => moveLunaId('staff', 'Elena Papadaki')],
            $services,
        )),
        'from' => $day,
        'to' => $next,
    ])))->assertOk()->json($day);

    assert(is_array($starts));

    return array_values(array_map(function (mixed $start): string {
        assert(is_string($start));

        return (new DateTimeImmutable($start, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('Europe/Athens'))
            ->format('H:i');
    }, $starts));
}

/**
 * A connection of its own to the same database, for asking what another connection has
 * committed while a listener runs.
 */
function moveProbe(): Connection
{
    /** @var array<string, mixed> $pgsql */
    $pgsql = config('database.connections.pgsql');
    config(['database.connections.move_probe' => $pgsql]);

    return DB::connection('move_probe');
}

it('moves a confirmed two-segment chain: the old items are gone, the new ones carry the sold staff, the sold prices and true blocks, the status and the total stand, one audit row names both starts, the event follows the commit, and the old slots are offered again', function () {
    $seen = [];
    Event::listen(BookingRescheduled::class, function (BookingRescheduled $event) use (&$seen): void {
        $seen[] = [
            'uuid' => $event->uuid,
            'status' => $event->status->value,
            'hold_class' => $event->holdClass?->value,
            'actor' => $event->actor->value,
            'moved_from' => $event->movedFrom->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i'),
            'moved_to' => $event->movedTo->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i'),
            'level' => DB::transactionLevel(),
            'committed' => moveProbe()->scalar(
                'select i.start_utc::text from tenant_luna.bookings b join tenant_luna.booking_items i on i.booking_id = b.id'
                .' where b.uuid = ?::uuid order by i.sort limit 1',
                [$event->uuid],
            ),
        ];
    });

    // The Colour's price is changed after the sale, so a move that read the catalog would
    // restate it and the total would move with it.
    $uuid = moveConfirmed(['Haircut', 'Colour'], '2026-11-03 10:00');
    DB::table('tenant_luna.services')->where('name', 'Colour')->update(['price_minor' => 9900]);
    $before = moveRow($uuid);

    moveUseCase()->reschedule($uuid, Actor::customer(), moveAt('2026-11-04 15:00'));

    $trail = moveTrail();

    expect(moveItems($uuid))->toBe([
        'Elena Papadaki Haircut 11-04 15:00-11-04 15:45 [11-04 15:00-11-04 15:45] 3500 blocking',
        'Elena Papadaki Colour 11-04 15:45-11-04 16:45 [11-04 15:45-11-04 16:45] 8000 blocking',
    ])
        ->and(moveRow($uuid))->toBe([
            'status' => 'confirmed',
            'hold_class' => null,
            'hold_expires_at' => null,
            'total_minor' => $before['total_minor'],
        ])
        ->and($before['total_minor'])->toBe(11500)
        ->and($trail)->toHaveCount(3)
        ->and($trail[2])->toMatchArray(['uuid' => $uuid, 'action' => 'rescheduled', 'actor' => 'customer'])
        ->and($trail[2]['payload'])->toBe([
            'moved_from' => '2026-11-03T08:00:00.000000+00:00',
            'starts_at' => '2026-11-04T13:00:00.000000+00:00',
        ])
        ->and($seen)->toBe([[
            'uuid' => $uuid,
            'status' => 'confirmed',
            'hold_class' => null,
            'actor' => 'customer',
            'moved_from' => '2026-11-03 08:00',
            'moved_to' => '2026-11-04 13:00',
            'level' => 0,
            'committed' => moveProbe()->scalar(
                'select i.start_utc::text from tenant_luna.bookings b join tenant_luna.booking_items i on i.booking_id = b.id'
                .' where b.uuid = ?::uuid order by i.sort limit 1',
                [$uuid],
            ),
        ]])
        // The Tuesday is free again where the chain was, and the Wednesday is taken where it is.
        ->and(moveOffered($this, ['Haircut', 'Colour'], '2026-11-03'))->toContain('10:00')
        ->and(moveOffered($this, ['Haircut', 'Colour'], '2026-11-04'))->not->toContain('15:00');
});

it('moves a live awaiting_approval request, keeping its status, its hold class and the exact expiry it had', function () {
    $uuid = moveHeld(['Bridal trial'], '2026-11-03 10:00');
    $before = moveRow($uuid);

    moveUseCase()->reschedule($uuid, Actor::customer(), moveAt('2026-11-03 15:00'));

    expect($before)->toMatchArray(['status' => 'awaiting_approval', 'hold_class' => 'approval'])
        ->and($before['hold_expires_at'])->toBeString()
        ->and(moveRow($uuid))->toBe([
            'status' => 'awaiting_approval',
            'hold_class' => 'approval',
            'hold_expires_at' => $before['hold_expires_at'],
            'total_minor' => $before['total_minor'],
        ])
        ->and(moveItems($uuid))->toBe(['Elena Papadaki Bridal trial 11-03 15:00-11-03 17:00 [11-03 15:00-11-03 17:00] 15000 blocking']);
});

it('refuses the engine and a job on every status, before any statement reaches the database, and moves nothing', function (string $status, ?string $holdClass, string $expiry) {
    $uuid = LunaFixture::booking($status, $holdClass, $expiry, blocks: in_array($status, ['pending', 'awaiting_approval', 'awaiting_payment', 'confirmed'], true));
    $before = LunaFixture::row($uuid);

    $moves = moveUseCase();

    foreach ([Actor::system(), Actor::job('expire-holds')] as $actor) {
        $refused = null;
        $trace = moveTrace(function () use ($moves, $uuid, $actor, &$refused): void {
            try {
                $moves->reschedule($uuid, $actor, moveAt('2026-11-03 15:00'));
            } catch (MoverRefused $e) {
                $refused = $e;
            }
        });

        expect($refused)->toBeInstanceOf(MoverRefused::class)
            ->and($trace)->toBe([]);
    }

    expect(LunaFixture::row($uuid))->toBe($before)
        ->and(LunaFixture::trail())->toBe([]);
})->with([
    'a live checkout hold' => ['pending', 'checkout', 'future'],
    'a live request' => ['awaiting_approval', 'approval', 'future'],
    'a live payment hold' => ['awaiting_payment', 'payment', 'future'],
    'a confirmed booking' => ['confirmed', null, 'none'],
    'a completed booking' => ['completed', null, 'none'],
    'a no-show' => ['no_show', null, 'none'],
    'a cancelled booking' => ['cancelled', null, 'none'],
    'a rejected request' => ['rejected', null, 'none'],
    'an expired hold' => ['expired', null, 'none'],
]);

it('moves a booking thirty minutes later on the same member, the old item deleted before the first overlap check so the booking cannot conflict with itself', function () {
    $uuid = moveConfirmed();

    $trace = moveTrace(fn () => moveUseCase()->reschedule($uuid, Actor::customer(), moveAt('2026-11-03 10:30')));

    expect(moveItems($uuid))->toBe(['Elena Papadaki Haircut 11-03 10:30-11-03 11:15 [11-03 10:30-11-03 11:15] 3500 blocking'])
        ->and(movePositionOf($trace, PostgresBookingWriter::deleteItemsSql()))
        ->toBeLessThan(movePositionOf($trace, PostgresBookingWriter::overlapSql()));
});

it('locks the union of the staff-days it is leaving and the staff-days it is taking, sorted and de-duplicated, for a move across days', function () {
    // Three segments over two members on the Tuesday, moving to the Wednesday. Elena carries two
    // of them, so her Tuesday and her Wednesday are each named twice and counted once; and the
    // six item-days come out as four pairs in one statement, ascending by member and then by
    // day, which is the order that keeps two movers crossing over each other from deadlocking.
    $elena = moveLunaId('staff', 'Elena Papadaki');
    $nikos = moveLunaId('staff', 'Nikos Georgiou');
    $uuid = app(HoldBookingFactory::class)->for(moveTenant(), Pool::Whole)->hold(
        new HoldRequest(
            new Customer(MOVE_CUSTOMER_NAME, MOVE_CUSTOMER_EMAIL, MOVE_CUSTOMER_PHONE),
            [
                new SegmentChoice(moveLunaId('services', 'Haircut'), $elena),
                new SegmentChoice(moveLunaId('services', 'Colour'), $nikos),
                new SegmentChoice(moveLunaId('services', 'Haircut'), $elena),
            ],
            moveAt('2026-11-03 10:00'),
            new Intake(BookingSource::Embed),
        ),
        Actor::customer(),
    )->uuid;
    app(BookingTransitionFactory::class)->confirm(moveTenant())->confirm($uuid, Actor::customer());

    $trace = moveTrace(fn () => moveUseCase()->reschedule($uuid, Actor::customer(), moveAt('2026-11-04 10:00')));
    $locks = array_values(array_filter($trace, fn (array $entry): bool => str_starts_with($entry['sql'], 'insert into staff_days')));

    expect($elena)->toBeLessThan($nikos)
        ->and($locks)->toHaveCount(1)
        ->and($locks[0]['bindings'])->toBe([
            $elena, '2026-11-03',
            $elena, '2026-11-04',
            $nikos, '2026-11-03',
            $nikos, '2026-11-04',
        ])
        ->and(moveItems($uuid))->toBe([
            'Elena Papadaki Haircut 11-04 10:00-11-04 10:45 [11-04 10:00-11-04 10:45] 3500 blocking',
            'Nikos Georgiou Colour 11-04 10:45-11-04 11:45 [11-04 10:45-11-04 11:45] 8000 blocking',
            'Elena Papadaki Haircut 11-04 12:15-11-04 13:00 [11-04 12:15-11-04 13:00] 3500 blocking',
        ]);
});

it('refuses a start the pinned member cannot take and a start past the horizon as unavailable naming the segment, with nothing written', function () {
    $uuid = moveConfirmed();
    $before = moveItems($uuid);

    // 13:15 Athens is inside Elena's lunch break, and she is the member the booking was sold to.
    $outsideHours = moveRefusal(ChainUnavailable::class, fn () => moveUseCase()->reschedule($uuid, Actor::customer(), moveAt('2026-11-03 13:15')));
    DB::table('tenant_luna.services')->where('name', 'Haircut')->update(['horizon_days' => 3]);
    $pastHorizon = moveRefusal(ChainUnavailable::class, fn () => moveUseCase()->reschedule($uuid, Actor::customer(), moveAt('2026-11-09 10:00')));

    expect($outsideHours->segment)->toBe(0)
        ->and($pastHorizon->segment)->toBe(0)
        ->and(moveItems($uuid))->toBe($before)
        ->and(array_column(moveTrail(), 'action'))->toBe(['held', 'confirmed']);
});

it('refuses a start somebody else already holds as an overlap naming the segment, with nothing written', function () {
    $mine = moveConfirmed(['Haircut'], '2026-11-03 10:00');
    $before = moveItems($mine);
    app(HoldBookingFactory::class)->for(moveTenant(), Pool::Whole)->hold(
        new HoldRequest(
            new Customer('Robin Rival', 'robin@example.test'),
            [new SegmentChoice(moveLunaId('services', 'Haircut'), moveLunaId('staff', 'Elena Papadaki'))],
            moveAt('2026-11-03 15:00'),
            new Intake(BookingSource::Embed),
        ),
        Actor::customer(),
    );

    $conflict = moveRefusal(SegmentConflict::class, fn () => moveUseCase()->reschedule($mine, Actor::customer(), moveAt('2026-11-03 15:00')));

    expect($conflict->segment)->toBe(0)
        ->and(moveItems($mine))->toBe($before)
        ->and(array_column(moveTrail(), 'action'))->toBe(['held', 'confirmed', 'held']);
});

it('measures the customer\'s window back from the start the booking has now, with the largest reschedule window in the chain: one second past it is refused and the limit itself is allowed', function () {
    // Colour is the second segment and carries the longer window, so the chain's limit is 48
    // hours before the Haircut starts and not 24.
    moveWindowOf('Haircut', 24);
    moveWindowOf('Colour', 48);
    $uuid = moveConfirmed(['Haircut', 'Colour'], '2026-11-03 09:00');

    // 09:00 in Athens in November is 07:00 UTC; 48 elapsed hours earlier is 07:00 UTC on the
    // first. The window is measured from there and never from the start being asked for.
    moveClockAt('2026-11-01 07:00:01');
    $refused = moveRefusal(TransitionRefused::class, fn () => moveUseCase()->reschedule($uuid, Actor::customer(), moveAt('2026-11-04 09:00')));
    $untouched = moveItems($uuid);

    moveClockAt('2026-11-01 07:00:00');
    moveUseCase()->reschedule($uuid, Actor::customer(), moveAt('2026-11-04 09:00'));

    expect($refused->reason)->toBe(TransitionRefusal::WindowClosed)
        ->and($refused->status)->toBe(BookingStatus::Confirmed)
        ->and($untouched)->toBe([
            'Elena Papadaki Haircut 11-03 09:00-11-03 09:45 [11-03 09:00-11-03 09:45] 3500 blocking',
            'Elena Papadaki Colour 11-03 09:45-11-03 10:45 [11-03 09:45-11-03 10:45] 8000 blocking',
        ])
        ->and(array_column(moveTrail(), 'action'))->toBe(['held', 'confirmed', 'rescheduled'])
        ->and(moveItems($uuid))->toBe([
            'Elena Papadaki Haircut 11-04 09:00-11-04 09:45 [11-04 09:00-11-04 09:45] 3500 blocking',
            'Elena Papadaki Colour 11-04 09:45-11-04 10:45 [11-04 09:45-11-04 10:45] 8000 blocking',
        ]);
});

it('refuses a move from every status a move does not reach, and from a held booking whose expiry has passed, with nothing written and the original items untouched', function (string $status, ?string $holdClass, string $expiry, TransitionRefusal $reason) {
    $uuid = moveSeeded($status, $holdClass, $expiry);
    $before = moveItems($uuid);

    $refused = moveRefusal(TransitionRefused::class, fn () => moveUseCase()->reschedule($uuid, Actor::customer(), moveAt('2026-11-03 15:00')));

    expect($refused->reason)->toBe($reason)
        ->and($refused->status)->toBe(BookingStatus::from($status))
        ->and(moveItems($uuid))->toBe($before)
        ->and(moveTrail())->toBe([]);
})->with([
    'completed' => ['completed', null, 'none', TransitionRefusal::NotReschedulable],
    'no_show' => ['no_show', null, 'none', TransitionRefusal::NotReschedulable],
    'cancelled' => ['cancelled', null, 'none', TransitionRefusal::NotReschedulable],
    'rejected' => ['rejected', null, 'none', TransitionRefusal::NotReschedulable],
    'expired' => ['expired', null, 'none', TransitionRefusal::NotReschedulable],
    // A held status whose expiry is behind the server's own now: there is no held time left to
    // move, and the 410 is kept for a hold a confirm was about.
    'pending past its expiry' => ['pending', 'checkout', 'past', TransitionRefusal::HoldLapsed],
    'awaiting_approval past its expiry' => ['awaiting_approval', 'approval', 'past', TransitionRefusal::HoldLapsed],
    'awaiting_payment past its expiry' => ['awaiting_payment', null, 'past', TransitionRefusal::HoldLapsed],
]);

it('refuses an auto-approving request past its expiry too, because the booking page asks for an expiry in the future and the client cannot mirror a policy the guest view does not carry', function () {
    $uuid = moveSeeded('awaiting_approval', 'approval', 'past', timeout: 'auto_approve');

    $refused = moveRefusal(TransitionRefused::class, fn () => moveUseCase()->reschedule($uuid, Actor::customer(), moveAt('2026-11-03 15:00')));

    expect($refused->reason)->toBe(TransitionRefusal::HoldLapsed)
        ->and($refused->status)->toBe(BookingStatus::AwaitingApproval)
        ->and(moveTrail())->toBe([]);
});

it('refuses a move whose service the catalog no longer offers and whose sold member no longer performs it as unavailable naming the segment, while a cancel of the same booking still lands', function () {
    $gone = moveConfirmed();
    $unassigned = moveConfirmed(['Haircut'], '2026-11-03 11:00');

    DB::table('tenant_luna.services')->where('name', 'Haircut')->update(['status' => 'inactive']);
    $serviceGone = moveRefusal(ChainUnavailable::class, fn () => moveUseCase()->reschedule($gone, Actor::customer(), moveAt('2026-11-03 15:00')));
    DB::table('tenant_luna.services')->where('name', 'Haircut')->update(['status' => 'active']);

    DB::table('tenant_luna.staff')->where('name', 'Elena Papadaki')->update(['status' => 'inactive']);
    $staffGone = moveRefusal(ChainUnavailable::class, fn () => moveUseCase()->reschedule($unassigned, Actor::customer(), moveAt('2026-11-03 15:00')));

    // The way out is still open on both of them: a booking that cannot be moved can be given up.
    app(BookingTransitionFactory::class)->cancel(moveTenant())->cancel($gone, Actor::customer());
    app(BookingTransitionFactory::class)->cancel(moveTenant())->cancel($unassigned, Actor::customer());

    expect($serviceGone->segment)->toBe(0)
        ->and($staffGone->segment)->toBe(0)
        ->and(moveRow($gone))->toMatchArray(['status' => 'cancelled'])
        ->and(moveRow($unassigned))->toMatchArray(['status' => 'cancelled'])
        ->and(array_column(moveTrail(), 'action'))->toBe(['held', 'confirmed', 'held', 'confirmed', 'cancelled', 'cancelled']);
});

it('answers a booking nobody has, whatever the start', function () {
    expect(fn () => moveUseCase()->reschedule((string) Str::uuid(), Actor::customer(), moveAt('2026-11-03 15:00')))
        ->toThrow(BookingUnknown::class);
});

it('reaches its two checkpoints in order, the staff-days held before the booking row', function () {
    expect(RescheduleBooking::AFTER_LOCK)->toBe('reschedule.locked')
        ->and(RescheduleBooking::AFTER_REREAD)->toBe('reschedule.reread')
        ->and(RescheduleBooking::ATTEMPTS)->toBe(2)
        ->and(LockManager::sql(1))->toContain('insert into staff_days');
});
