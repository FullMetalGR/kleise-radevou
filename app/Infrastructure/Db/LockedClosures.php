<?php

declare(strict_types=1);

namespace App\Infrastructure\Db;

use App\Application\Checkpoint;
use App\Application\Dto\BookingRecord;
use App\Domain\Booking\LockKey;
use App\Domain\Booking\LockKeySet;
use App\Domain\Enum\ApprovalTimeout;
use App\Domain\Enum\BookingStatus;
use App\Tenancy\SearchPath;
use App\Tenancy\Tenant;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use LogicException;
use PDOException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * A closure added the way the admin adds one over time that may already hold bookings: the
 * closure written and the bookings inside it read back in one transaction, under the same
 * staff-day locks a new booking takes, so no booking lands inside the closure without being
 * among those read.
 *
 * **Which bookings count.** A confirmed booking; a request or a booking awaiting its payment
 * whose hold is still live by the database's clock; and a request whose policy is to approve
 * itself, whatever its hold's expiry, since that one keeps its time past the expiry and the
 * sweeper then confirms it, reading no closure. Any other lapsed hold is already over, waiting
 * for the sweeper to say so. No guard lets anybody decide a lapsed request, the self-approving
 * one included, so a cancel inside the closure leaves that one as it is and names it. A checkout
 * hold, still pending, never counts. A booking counts once, however many of its services overlap:
 * for a person's closure, any of its services held by that person; for the business's, any
 * service.
 * Overlap is half-open, in the tenant's zone: a booking that ends exactly when the closed time
 * starts, or starts exactly when it ends, is outside it. Every date of the run is closed, the
 * window applied on each, or the whole day with no window.
 *
 * **Why the locks.** A new booking locks the staff-days its blocks touch and only then reads the
 * closures in its way, so a closure that holds the same locks while it writes and reads either
 * goes first, and the booking then sees it and is refused, or goes second, and then reads the
 * booking. The person's days for a person's closure; every staff member's days for the
 * business's.
 *
 * **What was seen.** The admin either sends nothing, having seen no list, or the bookings its
 * list showed, each by its id and its status. Whenever the bookings found are not exactly those,
 * the write is rolled back and BookingsInside carries what was found; so a closure over bookings
 * is written only once somebody has answered for each of them.
 *
 * The transaction is the connection's own rather than the tenant transaction runner's, because
 * the runner refuses to start inside an open transaction and a closure has always been written
 * inside whatever transaction is open: the path and the timeouts it needs are set locally and
 * put back before it returns, as ExclusiveStep does. Taking the locks bumps each staff-day's
 * revision, as a booking's locks do.
 *
 * **What the runner would have given it.** Three things, done here by hand. The isolation level
 * and the schema the path landed on are asserted before anything is locked. A lock not granted
 * in time, a deadlock, a serialization failure and a statement cancelled past its time each end
 * the add, rolled back, as the typed failure the runner surfaces for that state. And that
 * failure is written to the log on the runner's own line, at the level the state sets, so busy
 * is counted and a blown budget alerts whichever write met it. Nothing is tried again, where the
 * runner would try the two retried states again: inside somebody else's transaction a second
 * attempt is not a fresh one, and the admin who is told busy can press again.
 */
final readonly class LockedClosures
{
    /** Reached once the locks are held and before anything is written. */
    public const string LOCKED = 'closure.locked';

    /**
     * The states that end an add with nothing wrong in what was asked: it waited too long for a
     * lock, lost to a concurrent transaction, or ran past its time.
     */
    private const array CONTENDED = [
        SqlState::LockNotAvailable,
        SqlState::DeadlockDetected,
        SqlState::SerializationFailure,
        SqlState::QueryCanceled,
    ];

    public function __construct(
        private DatabaseManager $db,
        private LockManager $locks,
        private BookingRecords $records,
        private LoggerInterface $log,
    ) {}

    /**
     * Writes the closure on every date from $date to $until inclusive and answers the bookings
     * inside it, soonest first; or writes nothing and throws BookingsInside when the bookings
     * inside differ from those seen, null meaning no list was seen at all.
     *
     * @param  list<array{uuid: string, status: string}>|null  $seen
     * @return list<BookingRecord>
     *
     * @throws BookingsInside
     * @throws Busy when a lock was not granted in time
     * @throws RetriesExhausted when the add lost to a concurrent transaction, by a deadlock or a serialization failure
     * @throws BudgetExceeded when a statement was cancelled past its time
     * @throws PreconditionFailed when the transaction is not at read committed, or the path did not land on the tenant's schema
     */
    public function add(Tenant $tenant, ?int $staffId, string $date, ?string $until, ?string $start, ?string $end, ?string $reason, ?array $seen): array
    {
        $dates = BlackoutRecords::dates($date, $until);

        try {
            return $this->db->connection()->transaction(function (Connection $connection) use ($tenant, $staffId, $dates, $start, $end, $reason, $seen): array {
                $before = $this->settings($connection);
                $this->enter($connection, $tenant);

                $keys = [];

                foreach ($this->staffOf($connection, $tenant, $staffId) as $member) {
                    foreach ($dates as $day) {
                        $keys[] = new LockKey($member, $day);
                    }
                }

                if ($keys !== []) {
                    $this->locks->lock($connection, LockKeySet::of(...$keys));
                }

                Checkpoint::reach(self::LOCKED);

                $connection->table($tenant->schema_name.'.blackouts')->insert(array_map(fn (string $day): array => [
                    'staff_id' => $staffId,
                    'date' => $day,
                    'start_time' => $start,
                    'end_time' => $end,
                    'reason' => $reason,
                ], $dates));

                $found = $this->inside($connection, $tenant, $staffId, $dates, $start, $end);

                if ($found !== [] && ($seen === null || ! self::same($found, $seen))) {
                    throw new BookingsInside($this->hydrate($tenant, $found), $seen !== null);
                }

                $records = $this->hydrate($tenant, $found);

                foreach ($before as $name => $value) {
                    $connection->statement('select set_config(?, ?, true)', [$name, $value]);
                }

                return $records;
            });
        } catch (PDOException $failure) {
            throw $this->failure($failure, $tenant);
        }
    }

    /**
     * What a rolled-back add ends as: for a contended state the typed failure of that state,
     * written to the log as the runner writes one, after the one attempt there was; any other
     * error as it came.
     */
    private function failure(PDOException $cause, Tenant $tenant): Throwable
    {
        $state = SqlState::of($cause);

        if ($state === null || ! in_array($state, self::CONTENDED, true)) {
            return $cause;
        }

        $failure = $state->failure($cause, $tenant->slug, 1);

        $this->log->log($state->logLevel(), $failure->getMessage(), [
            'tenant' => $tenant->slug,
            'sqlstate' => $state->value,
            'failure' => $failure::class,
            'attempts' => 1,
        ]);

        return $failure;
    }

    /**
     * The settings this write changes, as they stand before it.
     *
     * @return array<string, string>
     */
    private function settings(Connection $connection): array
    {
        $before = [];

        foreach (['search_path', 'lock_timeout', 'statement_timeout'] as $name) {
            $value = $connection->scalar('select current_setting(?)', [$name]);
            $before[$name] = is_string($value) ? $value : throw new LogicException('The connection reported no '.$name.'.');
        }

        return $before;
    }

    /**
     * The tenant's path, which the lock statement names its table by, and the timeouts every
     * tenant write runs under; then the two things every tenant write is held to before it
     * touches a row: that it runs at read committed, since the locks protect nothing at a level
     * that reads an older snapshot, and that the path landed on the tenant's own schema and not,
     * that schema missing, on the central one.
     *
     * @throws PreconditionFailed
     */
    private function enter(Connection $connection, Tenant $tenant): void
    {
        $connection->statement('set local search_path to '.SearchPath::forSchema($tenant->schema_name));
        $connection->statement(sprintf("set local lock_timeout = '%s'", TransactionRunner::LOCK_TIMEOUT));
        $connection->statement(sprintf("set local statement_timeout = '%s'", TransactionRunner::STATEMENT_TIMEOUT));

        $isolation = $connection->scalar("select current_setting('transaction_isolation')");

        if ($isolation !== TransactionRunner::ISOLATION) {
            throw PreconditionFailed::isolation($isolation);
        }

        $schema = $connection->scalar('select current_schema()');

        if ($schema !== $tenant->schema_name) {
            throw PreconditionFailed::schema($tenant->schema_name, $schema);
        }
    }

    /**
     * The staff members whose days the closure locks: the one for a person's closure, every one
     * for the business's.
     *
     * @return list<int>
     */
    private function staffOf(Connection $connection, Tenant $tenant, ?int $staffId): array
    {
        if ($staffId !== null) {
            return [$staffId];
        }

        $shape = RowShape::of('staff', $tenant);

        return array_values(array_map(
            fn (mixed $row): int => $shape->int($shape->row($row)->id ?? null),
            $connection->select(sprintf('select id from %s.staff order by id', $tenant->schema_name)),
        ));
    }

    /**
     * The bookings inside the closure, each by its id and its status, soonest first.
     *
     * @param  list<string>  $dates
     * @return list<array{uuid: string, status: string}>
     */
    private function inside(Connection $connection, Tenant $tenant, ?int $staffId, array $dates, ?string $start, ?string $end): array
    {
        $schema = $tenant->schema_name;
        [$starts, $ends] = self::windows($dates, $start, $end);
        $waiting = implode(', ', array_map(SqlText::literal(...), [BookingStatus::AwaitingApproval, BookingStatus::AwaitingPayment]));
        $zone = $tenant->zone()->getName();
        $bindings = [$starts, $ends, $zone, $zone];
        $person = '';

        if ($staffId !== null) {
            $person = ' and item.staff_id = ?';
            $bindings[] = $staffId;
        }

        $sql = 'select booking.uuid::text as uuid, booking.status::text as status'
            .' from '.$schema.'.bookings booking'
            .' where (booking.status = '.SqlText::literal(BookingStatus::Confirmed)
            .' or (booking.status in ('.$waiting.') and '.SqlText::holdIsLive('booking.hold_expires_at').')'
            .' or (booking.status = '.SqlText::literal(BookingStatus::AwaitingApproval)
            .' and booking.on_approval_timeout = '.SqlText::literal(ApprovalTimeout::AutoApprove).'))'
            .' and exists (select 1 from '.$schema.'.booking_items item'
            .' join unnest(?::timestamp[], ?::timestamp[]) as closed(starts, ends)'
            .' on item.start_utc < (closed.ends at time zone ?) and item.end_utc > (closed.starts at time zone ?)'
            .' where item.booking_id = booking.id'.$person.')'
            .' order by (select min(first.start_utc) from '.$schema.'.booking_items first where first.booking_id = booking.id), booking.id';
        $shape = RowShape::of('bookings', $tenant);
        $found = [];

        foreach ($connection->select($sql, $bindings) as $row) {
            $row = $shape->row($row);
            $found[] = ['uuid' => $shape->string($row->uuid ?? null), 'status' => $shape->string($row->status ?? null)];
        }

        return $found;
    }

    /**
     * The closed time of each date as two arrays of wall-clock instants, the starts and the
     * ends, spelled as the database reads a timestamp array: the window on each date, or the
     * whole date with none, an end of 24:00 being the next date's midnight.
     *
     * @param  list<string>  $dates
     * @return array{string, string}
     */
    private static function windows(array $dates, ?string $start, ?string $end): array
    {
        // Wall-clock times with no zone of their own; the statement puts them in the tenant's.
        $naive = new DateTimeZone('UTC');
        $starts = [];
        $ends = [];

        foreach ($dates as $day) {
            $midnight = new DateTimeImmutable($day.' 00:00:00', $naive);
            $starts[] = $start === null ? $midnight : new DateTimeImmutable($day.' '.$start.':00', $naive);
            $ends[] = $end === null || $end === '24:00' ? $midnight->add(new DateInterval('P1D')) : new DateTimeImmutable($day.' '.$end.':00', $naive);
        }

        return [self::timestamps($starts), self::timestamps($ends)];
    }

    /**
     * @param  list<DateTimeImmutable>  $instants
     */
    private static function timestamps(array $instants): string
    {
        return '{'.implode(',', array_map(fn (DateTimeImmutable $instant): string => '"'.$instant->format('Y-m-d H:i:s').'"', $instants)).'}';
    }

    /**
     * Whether what was found is what was seen: the same bookings, each in the same status, in
     * whatever order.
     *
     * @param  list<array{uuid: string, status: string}>  $found
     * @param  list<array{uuid: string, status: string}>  $seen
     */
    private static function same(array $found, array $seen): bool
    {
        if (count($found) !== count($seen)) {
            return false;
        }

        $found = array_column($found, 'status', 'uuid');
        $seen = array_column($seen, 'status', 'uuid');
        ksort($found);
        ksort($seen);

        return $found === $seen;
    }

    /**
     * The found bookings as records, in the order found.
     *
     * @param  list<array{uuid: string, status: string}>  $found
     * @return list<BookingRecord>
     */
    private function hydrate(Tenant $tenant, array $found): array
    {
        $uuids = array_column($found, 'uuid');
        $records = $this->records->byUuids($tenant, $uuids);

        return array_map(
            fn (string $uuid): BookingRecord => $records[$uuid] ?? throw new LogicException('A booking read inside a closure was not there to read again.'),
            $uuids,
        );
    }
}
