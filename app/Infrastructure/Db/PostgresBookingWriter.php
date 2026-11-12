<?php

declare(strict_types=1);

namespace App\Infrastructure\Db;

use App\Application\Dto\HoldItem;
use App\Application\Dto\InsertedHold;
use App\Application\Dto\Intake;
use App\Application\Dto\LockedBooking;
use App\Application\Dto\LockedItem;
use App\Application\Dto\NewHold;
use App\Application\Dto\ReapedHold;
use App\Application\Repository\AvailabilityRepository;
use App\Application\Repository\BookingWriter;
use App\Domain\Audit\AuditEntry;
use App\Domain\Availability\CalendarDay;
use App\Domain\Booking\LockKey;
use App\Domain\Booking\LockKeySet;
use App\Domain\Booking\Revisions;
use App\Domain\Booking\ServiceWindows;
use App\Domain\Booking\StaffBlock;
use App\Domain\Booking\TransitionColumn;
use App\Domain\Enum\BookingStatus;
use App\Domain\Enum\HoldClass;
use App\Domain\Enum\PaymentMode;
use App\Domain\Money\Money;
use App\Infrastructure\Cache\StaffDayMaskCache;
use App\Tenancy\Tenant;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use LogicException;
use stdClass;
use UnexpectedValueException;

/**
 * Steps 3 to 6 of the write protocol as SQL, on the connection the transaction runner handed
 * its closure, inside that transaction, on the tenant schema the runner has already set and
 * asserted. Opens no connection and no transaction of its own, and lets every driver error
 * through unwrapped, because the runner's retry and its error map read the raw exception.
 *
 * Every instant crosses to the server as text with an explicit offset, spelled in SqlText and
 * nowhere else. The framework would format a date object without its zone, and the server would
 * then read it in the session's time zone, which is whatever the cluster was installed with.
 *
 * The reap and the overlap check both name the blocking flag, so both are served by the partial
 * GiST index the exclusion constraint created, and step 5 agrees with the constraint step 6
 * relies on by construction: the two read the same column. The time comparison of the blocking
 * predicate is applied by the reap immediately before, on the same staff and window, so within
 * the transaction the flag and the predicate agree for every row step 5 can see. The reap
 * leaves an auto-approving request past its window alone, as the blocking predicate counts it
 * as blocking until the sweeper confirms it or the owner rejects it, and step 5 then refuses
 * the new item against it, which is the safe direction.
 *
 * The availability reads the re-validation step and the cache warm need run on this same
 * connection through LockedAvailability. The masks kept after the insert are stored by the
 * repository once the runner has committed; this writer only reads them and holds them, and
 * never touches the store.
 */
final class PostgresBookingWriter implements BookingWriter
{
    private ?LockedAvailability $availability = null;

    /**
     * The transaction's own clock reading, taken by whichever write this transaction ran: the
     * insert's created_at or the move's updated_at, both of them now() inside this transaction.
     * It is what a kept mask's lifetime is counted from, and it is the same instant every expiry
     * the transaction read was compared against.
     */
    private ?DateTimeImmutable $clockReading = null;

    /** The booking row this transaction holds the lock on. */
    private ?int $locked = null;

    /** @var list<array{string, NotBookedMask}> the cache key and the entry kept for after the commit */
    private array $kept = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly LockManager $locks,
        private readonly Tenant $tenant,
    ) {}

    public function lock(LockKeySet $keys): Revisions
    {
        return $this->locks->lock($this->connection, $keys);
    }

    public function reapExpiredHolds(array $staffIds, DateTimeImmutable $windowStart, DateTimeImmutable $windowEnd): array
    {
        $expired = $this->connection->select(self::reapBookingsSql(), [
            self::bigintArray($staffIds),
            self::instant($windowStart),
            self::instant($windowEnd),
        ], false);

        $ids = [];
        $reaped = [];

        foreach ($expired as $row) {
            [$id, $hold] = self::reapedOf($row);
            $ids[] = $id;
            $reaped[] = $hold;
        }

        BlockingItems::unblock($this->connection, $ids);

        return $reaped;
    }

    public function overlapsBlockingItem(StaffBlock $block): bool
    {
        $overlaps = $this->connection->scalar(self::overlapSql(), [
            $block->staffId,
            self::instant($block->start),
            self::instant($block->end),
        ], false);

        if (! is_bool($overlaps)) {
            throw new UnexpectedValueException(sprintf('The overlap query returned a %s where a boolean was expected.', get_debug_type($overlaps)));
        }

        return $overlaps;
    }

    public function availability(): AvailabilityRepository
    {
        return $this->locked();
    }

    public function insertHold(NewHold $hold): InsertedHold
    {
        $rows = $this->connection->select(self::insertBookingSql(), [
            $hold->status->value,
            $hold->holdClass?->value,
            $hold->ttlMinutes,
            $hold->customer->name,
            $hold->customer->email,
            $hold->customer->phone,
            $hold->total->minor,
            $hold->total->currency->code,
            $hold->paymentMode->value,
            $hold->requiresApproval,
            $hold->onApprovalTimeout->value,
            $hold->manageTokenHash,
            $hold->intake->source->value,
            $hold->intake->channel?->value,
            $hold->intake->language,
        ], false);

        [$id, $uuid, $expiresAt, $createdAt] = self::bookingOf($rows);

        $this->insertEach($id, $hold->items);

        if ($hold->intake->note !== null) {
            $this->connection->insert(self::insertMetaSql(), [$id, Intake::NOTE_KEY, $hold->intake->note]);
        }

        $this->clockReading = $createdAt;

        return new InsertedHold($uuid, $expiresAt);
    }

    public function lockBooking(string $uuid): bool
    {
        $id = $this->connection->scalar(self::lockBookingSql(), [$uuid], false);

        if ($id === null) {
            return false;
        }

        if (! is_int($id)) {
            throw new UnexpectedValueException(sprintf('The booking row lock returned a %s where an id was expected.', get_debug_type($id)));
        }

        $this->locked = $id;

        return true;
    }

    public function readLocked(): LockedBooking
    {
        $rows = $this->connection->select(self::readLockedSql(), [$this->lockedId()], false);

        return self::lockedOf($rows);
    }

    public function compareAndSet(BookingStatus $found, BookingStatus $target, array $columns): void
    {
        $written = WrittenColumns::of($columns);

        $changed = $this->connection->affectingStatement(
            self::compareAndSetSql($written->columns, $written->fromServerNow),
            [$target->value, ...$written->bindings, $this->lockedId(), $found->value],
        );

        if ($changed !== 1) {
            throw new UnexpectedValueException(sprintf(
                'The compare-and-set from %s to %s changed %d rows; the row it names is locked by this transaction.',
                $found->value,
                $target->value,
                $changed,
            ));
        }
    }

    public function unblockItems(): void
    {
        BlockingItems::unblock($this->connection, [$this->lockedId()]);
    }

    public function deleteItems(): void
    {
        $this->connection->affectingStatement(self::deleteItemsSql(), [$this->lockedId()]);
    }

    public function insertItems(array $items, Money $total): void
    {
        $id = $this->lockedId();
        $this->insertEach($id, $items);
        $rows = $this->connection->select(self::setTotalSql(), [$total->minor, $id], false);
        $this->clockReading = self::movedOf($rows);
    }

    public function clearSession(): void
    {
        $this->connection->affectingStatement(self::clearSessionSql(), [$this->lockedId()]);
    }

    public function writeAudit(AuditEntry $entry): void
    {
        AuditRow::write($this->connection, $entry);
    }

    public function warmAfterCommit(LockKeySet $keys, Revisions $revisions): void
    {
        if ($this->clockReading === null) {
            throw new LogicException('The masks are kept after the write; nothing has been written on this transaction.');
        }

        $days = array_map(fn (LockKey $key): string => $key->day, $keys->all());
        $firstDay = min($days);
        $staffIds = array_values(array_unique(array_map(fn (LockKey $key): int => $key->staffId, $keys->all())));

        $window = $this->locked()->window($firstDay, CalendarDay::daysBetween($firstDay, max($days)) + 1);
        $masks = MasksFromRows::onSearchPath($this->connection)->notBookedMasks($window, $staffIds);

        foreach ($keys as $key) {
            $this->kept[] = [
                StaffDayMaskCache::key($this->tenant->schema_name, $key->staffId, $key->day, $window->granularity, $revisions->of($key)),
                $masks[$key->staffId][CalendarDay::daysBetween($firstDay, $key->day)],
            ];
        }
    }

    /**
     * For the repository, after the commit: stores every entry kept, each for the lifetime it
     * earns from the transaction's clock reading, the one every expiry it counted was set
     * against. Nothing to store after a rollback, since the writer is discarded with it.
     */
    public function storeKept(StaffDayMaskCache $cache): void
    {
        foreach ($this->kept as [$key, $entry]) {
            $cache->store($key, $entry, $this->clockReading ?? throw new LogicException('Masks were kept before anything was written.'));
        }

        $this->kept = [];
    }

    /**
     * The first reap statement: every hold on the locked staff members with a blocking item
     * overlapping the window, whose expiry has passed and whose timeout policy does not grant
     * it, set to expired. The subquery names the blocking flag so the partial GiST index
     * serves it.
     *
     * The conditions are spelled twice, and both spellings are load bearing. The CTE chooses the
     * rows and locks them, so a transaction already confirming one of them is waited for rather
     * than read past, and the row the CTE returns is the row that transaction left. The update
     * then carries every condition itself, because at read committed a statement re-checks nothing
     * but its own qualification against the version it finds under the lock: an update qualified
     * on the id alone still matches a booking somebody confirmed while it waited, and expires it.
     * The conditions are spelled on the UPDATE for that reason and they stay there.
     *
     * The update ends the hold as well as the status, because every transition out of a held state
     * nulls the class and the expiry, and the sealed manage token with them, which lives only
     * while a hold waits for its payment. It can do that without losing either, because what it
     * hands back it reads from the locked row the CTE is holding, which is the row as it stood
     * before this statement touched it.
     *
     * What comes back is what the update changed, read from its own RETURNING rather than
     * from the choice the select made, and each booking as it stood before it: the id for the
     * unblock, and the uuid, the status it was in and the class it held for the trail and for
     * the event. A plain UPDATE ... RETURNING would hand back the new values, and the status
     * it left is exactly what neither the audit row nor the acknowledgement rule can do
     * without; the update reads those three from the locked row the CTE holds. The rows are
     * locked in id order, which is the order every reap takes them in.
     */
    public static function reapBookingsSql(): string
    {
        return 'with doomed as (select id, uuid::text as uuid, status, hold_class from bookings'
            .' where id in (select booking_id from booking_items'
            ." where staff_id = any(?::bigint[]) and blocks and block_span && tstzrange(?::timestamptz, ?::timestamptz, '[)'))"
            .' and '.self::lapsedSql('').' order by id for update)'
            .', expired as (update bookings set status = '.SqlText::literal(BookingStatus::Expired)
            .', hold_class = null, hold_expires_at = null, manage_token_sealed = null, updated_at = now()'
            .' from doomed where bookings.id = doomed.id and '.self::lapsedSql('bookings.')
            .' returning bookings.id, doomed.uuid, doomed.status, doomed.hold_class)'
            .' select id, uuid, status, hold_class from expired order by id';
    }

    /**
     * What makes a hold one the reap may let go, of the relation named: held, past its expiry,
     * and not an auto-approving request, which the blocking predicate counts as blocking until
     * the sweeper confirms it or the owner rejects it. Spelled once so the choice and the
     * update cannot come to mean different things.
     */
    private static function lapsedSql(string $of): string
    {
        return $of.'status in ('.implode(', ', array_map(SqlText::literal(...), BookingStatus::held())).')'
            .' and '.$of.'hold_expires_at <= now()'
            .' and not ('.$of.'status = '.SqlText::literal(BookingStatus::AwaitingApproval).' and '.$of."on_approval_timeout = 'auto_approve')";
    }

    /**
     * Step 5 for one item: whether a blocking item of the staff member overlaps the range. A
     * plain SELECT, no FOR UPDATE: with no gap locks it would prevent nothing.
     */
    public static function overlapSql(): string
    {
        return 'select exists (select 1 from booking_items'
            ." where staff_id = ? and blocks and block_span && tstzrange(?::timestamptz, ?::timestamptz, '[)')) as overlaps";
    }

    /**
     * The booking row: the uuid from the server's generator, the expiry from the server's clock
     * so it is the TTL after created_at exactly, and both handed back with the id and the clock
     * reading itself. The language is the intake's, written here once and by no other
     * statement, a move and a cancellation included.
     */
    public static function insertBookingSql(): string
    {
        return 'insert into bookings (uuid, status, hold_class, hold_expires_at, customer_name, customer_email, customer_phone,'
            .' total_minor, currency, payment_mode, requires_approval, on_approval_timeout, manage_token_hash, source, channel, language)'
            .' values (gen_random_uuid(), ?, ?, now() + make_interval(mins => ?), ?, ?, ?, ?, ?, ?, ?::boolean, ?, ?, ?, ?, ?)'
            .' returning id, uuid::text, hold_expires_at::text, created_at::text';
    }

    /**
     * One intake field of the booking just written, booking_meta: the customer's note under its
     * key, in the transaction that wrote the row, so a hold that rolls back takes its note with
     * it.
     */
    public static function insertMetaSql(): string
    {
        return 'insert into booking_meta (booking_id, key, value) values (?, ?, ?)';
    }

    /**
     * One item, blocking, its processing end set only where a gap follows.
     */
    public static function insertItemSql(): string
    {
        return 'insert into booking_items (booking_id, sort, service_id, staff_id, start_utc, end_utc, block_start_utc, block_end_utc, processing_ends_utc, price_minor, blocks)'
            .' values (?, ?, ?, ?, ?::timestamptz, ?::timestamptz, ?::timestamptz, ?::timestamptz, ?::timestamptz, ?, true)';
    }

    /**
     * The first half of a move: every item of the locked booking, gone. The exclusion
     * constraint sees the deletion at once inside this transaction, which is what lets the new
     * items take a range overlapping the old ones on the same member.
     */
    public static function deleteItemsSql(): string
    {
        return 'delete from booking_items where booking_id = ?';
    }

    /**
     * The second half: the container's total, re-summed from the items just written. The status,
     * the hold class and the expiry are deliberately absent from the SET list, so a move cannot
     * settle a booking by accident whatever a caller passes.
     *
     * It hands back the timestamp it wrote, which is now() inside this transaction and therefore
     * the reading every expiry this transaction compared was compared against. That is what the
     * masks kept for the cache take their lifetime from, so a move warms the store on the same
     * terms a hold does.
     */
    public static function setTotalSql(): string
    {
        return 'update bookings set total_minor = ?, updated_at = now() where id = ? returning updated_at::text';
    }

    /**
     * A move's clearing of the session a booking waiting for its payment carried: payment_ref
     * alone, on the row the lock holds. Nothing else is in the SET list, so the hold, its class,
     * its expiry and the seal stand.
     */
    public static function clearSessionSql(): string
    {
        return 'update bookings set payment_ref = null, updated_at = now() where id = ?';
    }

    /**
     * Step 4: the booking's own row, locked. Only the id is selected, because what the row says
     * is read by the statement below and a lock taken on a version of the row is a lock on the
     * row.
     */
    public static function lockBookingSql(): string
    {
        return 'select id from bookings where uuid = ?::uuid for update';
    }

    /**
     * Step 5: the booking under that lock, with one row per item in chain order.
     *
     * The expiry crosses back as the server's own verdict rather than as an instant, so a guard
     * compares it the way the blocking predicate does, against now() on the database's clock
     * inside the statement, and no clock anywhere else decides whether a hold is still alive. A
     * booking with no expiry at all answers false rather than null, so the column is a boolean
     * in every row it can return. The spelling is SqlText's, shared with the read the decision
     * pages make, so the guard under a decision and the page that offers it cannot answer one
     * question two ways. It is two of the predicate's three limbs; the third, an auto-approving
     * request, is spelled in SQL by the reap's exclusion and by the availability read, and no
     * guard asks it, so this statement does not carry the policy column for anybody.
     *
     * The contended ranges come with it because step 5 recomputes the key set from them, and
     * the catalog is joined because a policy window is read at decision time rather than from a
     * copy taken when the booking was made. The join is outer and a missing service imposes no
     * window: a service cannot be deleted while a booking references it, which the referenced
     * conflict enforces, and the direction to fail in otherwise is the one that lets a customer
     * cancel rather than the one that traps them.
     */
    public static function readLockedSql(): string
    {
        return 'select b.uuid::text as uuid, b.status, b.hold_class, b.payment_mode, b.manage_token_sealed, b.payment_ref,'
            .' '.SqlText::holdIsLive('b.hold_expires_at').' as hold_is_live,'
            .' i.staff_id, i.start_utc::text as start_utc,'
            .' i.block_start_utc::text as block_start_utc, i.block_end_utc::text as block_end_utc,'
            .' coalesce(s.cancel_window_hours, 0) as cancel_window_hours,'
            .' coalesce(s.reschedule_window_hours, 0) as reschedule_window_hours'
            .' from bookings b join booking_items i on i.booking_id = b.id'
            .' left join services s on s.id = i.service_id'
            .' where b.id = ? order by i.sort';
    }

    /**
     * Step 6: the compare-and-set, naming the status the re-read found in its predicate. The
     * columns are cases of the enum and never strings a caller chose, so nothing a request
     * carried can reach the statement's text.
     *
     * Every compare-and-set clears the sealed manage token, whatever it lands on: the token is
     * sealed only onto a held booking waiting for its payment, and every transition moves the
     * booking out of that, the paid one included, which has read it under the lock first and
     * carries it on to the queue's encrypted payload.
     *
     * @param  list<TransitionColumn>  $columns  what is written beside the status, in the order given
     * @param  list<TransitionColumn>  $fromServerNow  the columns among them written as the server's now() plus a binding of hours
     */
    public static function compareAndSetSql(array $columns, array $fromServerNow = []): string
    {
        return 'update bookings set status = ?, updated_at = now(), manage_token_sealed = null'
            .WrittenColumns::assignments($columns, $fromServerNow)
            .' where id = ? and status = ?';
    }

    /**
     * An instant as the server reads it unambiguously: UTC, with its offset written out. The
     * spelling is SqlText's, shared with the availability read.
     */
    public static function instant(DateTimeImmutable $instant): string
    {
        return SqlText::instant($instant);
    }

    /**
     * Every item of a booking, blocking, in chain order, written by the one statement both the
     * hold's insert and the move's re-insert use: two spellings of an item row would be two
     * chances for one of them to forget the blocking flag or a buffer.
     *
     * @param  non-empty-list<HoldItem>  $items  in chain order
     */
    private function insertEach(int $bookingId, array $items): void
    {
        foreach ($items as $sort => $item) {
            $this->connection->insert(self::insertItemSql(), [
                $bookingId,
                $sort,
                $item->serviceId,
                $item->staffId,
                self::instant($item->start),
                self::instant($item->end),
                self::instant($item->blockStart),
                self::instant($item->blockEnd),
                $item->processingEndsAt === null ? null : self::instant($item->processingEndsAt),
                $item->price->minor,
            ]);
        }
    }

    private function locked(): LockedAvailability
    {
        return $this->availability ??= new LockedAvailability($this->connection, $this->tenant);
    }

    /**
     * @param  list<int>  $ids
     */
    private static function bigintArray(array $ids): string
    {
        return SqlText::bigintArray($ids);
    }

    /**
     * One row the reap returned: the id the unblock needs, and the booking as it stood before
     * the update, which is what the trail and the event carry.
     *
     * @return array{int, ReapedHold}
     */
    private static function reapedOf(mixed $row): array
    {
        $id = $row instanceof stdClass ? $row->id ?? null : null;
        $uuid = $row instanceof stdClass ? $row->uuid ?? null : null;
        $status = $row instanceof stdClass ? $row->status ?? null : null;
        $holdClass = $row instanceof stdClass ? $row->hold_class ?? null : null;

        if (! is_int($id) || ! is_string($uuid) || ! is_string($status) || ($holdClass !== null && ! is_string($holdClass))) {
            throw new UnexpectedValueException('The reap statement returned a row that is not shaped as the schema declares.');
        }

        return [$id, new ReapedHold($uuid, BookingStatus::from($status), $holdClass === null ? null : HoldClass::from($holdClass))];
    }

    /**
     * The booking row this transaction locked. Reading or writing it before it is locked is a
     * caller running the steps out of order, which is a programming error rather than a race.
     */
    private function lockedId(): int
    {
        return $this->locked ?? throw new LogicException('No booking row is locked on this transaction; the lock comes before the steps that read and write the row.');
    }

    /**
     * The re-read as the object a guard judges. Every row repeats the booking's own columns and
     * carries one item, which is one statement instead of two for something read under a lock.
     *
     * The instants are parsed with their zone named as well as read in it: the text carries an
     * offset today, and a cast that ever stopped writing one would otherwise fall back to the
     * process default.
     *
     * @param  array<mixed>  $rows
     */
    private static function lockedOf(array $rows): LockedBooking
    {
        /** @var array{string, BookingStatus, HoldClass|null, bool, PaymentMode, string|null, string|null}|null $booking */
        $booking = null;
        $items = [];

        foreach ($rows as $row) {
            $uuid = $row instanceof stdClass ? $row->uuid ?? null : null;
            $status = $row instanceof stdClass ? $row->status ?? null : null;
            $mode = $row instanceof stdClass ? $row->payment_mode ?? null : null;
            $holdClass = $row instanceof stdClass ? $row->hold_class ?? null : null;
            $sealed = $row instanceof stdClass ? $row->manage_token_sealed ?? null : null;
            $ref = $row instanceof stdClass ? $row->payment_ref ?? null : null;
            $live = $row instanceof stdClass ? $row->hold_is_live ?? null : null;
            $staffId = $row instanceof stdClass ? $row->staff_id ?? null : null;
            $start = $row instanceof stdClass ? $row->start_utc ?? null : null;
            $from = $row instanceof stdClass ? $row->block_start_utc ?? null : null;
            $to = $row instanceof stdClass ? $row->block_end_utc ?? null : null;
            $cancelHours = $row instanceof stdClass ? $row->cancel_window_hours ?? null : null;
            $rescheduleHours = $row instanceof stdClass ? $row->reschedule_window_hours ?? null : null;

            if (! is_string($uuid) || ! is_string($status) || ! is_string($mode) || ! is_bool($live) || ! is_int($staffId)
                || ! is_string($start) || ! is_string($from) || ! is_string($to) || ! is_int($cancelHours) || ! is_int($rescheduleHours)
                || ($holdClass !== null && ! is_string($holdClass)) || ($sealed !== null && ! is_string($sealed)) || ($ref !== null && ! is_string($ref))) {
                throw new UnexpectedValueException('The re-read under the booking row lock returned a row that is not shaped as the schema declares.');
            }

            $booking ??= [
                $uuid,
                BookingStatus::from($status),
                $holdClass === null ? null : HoldClass::from($holdClass),
                $live,
                PaymentMode::from($mode),
                $sealed,
                $ref,
            ];

            $items[] = new LockedItem(
                new StaffBlock(
                    $staffId,
                    SqlText::readInstant($from),
                    SqlText::readInstant($to),
                ),
                SqlText::readInstant($start),
                new ServiceWindows($cancelHours, $rescheduleHours),
            );
        }

        if ($booking === null) {
            throw new UnexpectedValueException('The re-read under the booking row lock returned no rows; the row it names is locked by this transaction.');
        }

        return new LockedBooking($booking[0], $booking[1], $booking[2], $booking[3], $booking[4], $items, $booking[5], $booking[6]);
    }

    /**
     * The clock reading the move's own update wrote, cast to text in the statement so it arrives
     * with its offset written out whatever the driver would make of a timestamp, and parsed with
     * its zone named as well as read in it.
     *
     * @param  array<mixed>  $rows
     */
    private static function movedOf(array $rows): DateTimeImmutable
    {
        $row = $rows[0] ?? null;
        $updatedAt = $row instanceof stdClass ? $row->updated_at ?? null : null;

        if (count($rows) !== 1 || ! is_string($updatedAt)) {
            throw new UnexpectedValueException(sprintf(
                'The total of the moved booking reached %d rows; the row it names is locked by this transaction.',
                count($rows),
            ));
        }

        return SqlText::readInstant($updatedAt);
    }

    /**
     * The booking the insert returned. The driver returns bigint as int; the uuid and the two
     * instants were cast to text in the statement so they arrive as strings whatever the
     * driver would make of them, each instant with the session's offset written out. The
     * expiry is null where the lifetime was: a settled landing wrote none.
     *
     * @param  array<mixed>  $rows
     * @return array{int, string, DateTimeImmutable|null, DateTimeImmutable}
     */
    private static function bookingOf(array $rows): array
    {
        $row = $rows[0] ?? null;

        if (count($rows) !== 1 || ! $row instanceof stdClass) {
            throw new UnexpectedValueException(sprintf('The booking insert returned %d rows where one was expected.', count($rows)));
        }

        $id = $row->id ?? null;
        $uuid = $row->uuid ?? null;
        $expiresAt = $row->hold_expires_at ?? null;
        $createdAt = $row->created_at ?? null;

        if (! is_int($id) || ! is_string($uuid) || ($expiresAt !== null && ! is_string($expiresAt)) || ! is_string($createdAt)) {
            throw new UnexpectedValueException(sprintf(
                'The booking insert returned id %s, uuid %s, hold_expires_at %s and created_at %s; expected int, two strings, a string or null, and a string.',
                get_debug_type($id),
                get_debug_type($uuid),
                get_debug_type($expiresAt),
                get_debug_type($createdAt),
            ));
        }

        // Read back with the parsing zone named as well as the reading one, SqlText::readInstant:
        // reading is covered too.
        return [$id, $uuid, $expiresAt === null ? null : SqlText::readInstant($expiresAt), SqlText::readInstant($createdAt)];
    }
}
