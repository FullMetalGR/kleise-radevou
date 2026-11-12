<?php

declare(strict_types=1);

namespace App\Infrastructure\Db;

use App\Application\Dto\LapsedHold;
use App\Application\Repository\SweepBatch;
use App\Domain\Audit\AuditEntry;
use App\Domain\Booking\TransitionColumn;
use App\Domain\Enum\ApprovalTimeout;
use App\Domain\Enum\BookingStatus;
use App\Domain\Enum\HoldClass;
use App\Domain\Enum\PaymentMode;
use Illuminate\Database\Connection;
use LogicException;
use stdClass;
use UnexpectedValueException;

/**
 * The sweeper as SQL, on the connection the transaction runner handed its closure, inside that
 * transaction, on the tenant schema the runner has already set and asserted. Opens no connection
 * and no transaction of its own, and lets every driver error through unwrapped, because the
 * runner's retry and its error map read the raw exception.
 *
 * **SKIP LOCKED belongs here and nowhere else in this system.** In the booking transaction it
 * would mean proceeding without a mutex, which is a correctness hole wearing an optimisation's
 * clothes. Here it is exactly right: a row a live booking transaction is holding is that
 * transaction's to settle, and the next pass picks up whatever this one read past, which costs
 * nothing because the sweeper is no part of the guarantee.
 *
 * **This job's advisory lock is no replacement for a mutex row.** It carries no revision, a
 * hashed composite key can collide, and it is scoped per database rather than per schema, so a
 * key that omitted the tenant would silently serialise unrelated tenants against each other.
 * The key is therefore two plain integers, a fixed class beside the tenant's central id, so
 * nothing is hashed at all and no two tenants can share one. It is taken transaction-scoped and
 * never session-scoped: a session-level lock leaks across pooled connections and outlives the job
 * that took it.
 *
 * No statement here touches staff_days. The sweep writes down what the clock already decided,
 * so there is no mutex to take and no revision to bump.
 */
final class PostgresSweepBatch implements SweepBatch
{
    /**
     * The class half of the sweeper's advisory key, chosen so nothing arrives at it by counting
     * from one, and nowhere near the concurrency harness's own classes. The other half is the
     * tenant's central id.
     */
    public const int LOCK_CLASS = 4800;

    /**
     * The batch the selection locked, its public identifier against the serial id the writes
     * name. The id never leaves here: nothing above the repository holds one, so the use case
     * hands back uuids and this maps them to the rows it is already holding.
     *
     * @var array<string, int>
     */
    private array $selected = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly int $tenantId,
    ) {}

    public function claim(): bool
    {
        $taken = $this->connection->scalar(self::claimSql(), [self::LOCK_CLASS, $this->tenantId], false);

        if (! is_bool($taken)) {
            throw new UnexpectedValueException(sprintf('The singleton guard returned a %s where a boolean was expected.', get_debug_type($taken)));
        }

        return $taken;
    }

    public function lapsed(int $limit): array
    {
        $this->selected = [];
        $holds = [];

        foreach ($this->connection->select(self::lapsedSql($limit), [], false) as $row) {
            [$id, $hold] = self::lapsedOf($row);
            $this->selected[$hold->uuid] = $id;
            $holds[] = $hold;
        }

        return $holds;
    }

    public function expire(array $uuids): void
    {
        $ids = $this->idsOf($uuids);

        $this->assertReachedAll(self::expireSql(), [SqlText::bigintArray($ids)], count($ids), BookingStatus::Expired);
        BlockingItems::unblock($this->connection, $ids);
    }

    public function confirm(array $uuids, array $columns): void
    {
        $ids = $this->idsOf($uuids);
        $written = WrittenColumns::of($columns);

        $this->assertReachedAll(
            self::confirmSql($written->columns),
            [...$written->bindings, SqlText::bigintArray($ids)],
            count($ids),
            BookingStatus::Confirmed,
        );
    }

    public function awaitPayment(array $uuids, array $columns): void
    {
        $ids = $this->idsOf($uuids);
        $written = WrittenColumns::of($columns);

        $this->assertReachedAll(
            self::awaitPaymentSql($written->columns, $written->fromServerNow),
            [...$written->bindings, SqlText::bigintArray($ids)],
            count($ids),
            BookingStatus::AwaitingPayment,
        );
    }

    public function writeTrail(array $entries): void
    {
        AuditRow::writeAll($this->connection, array_map(
            fn (AuditEntry $entry): array => [$this->idOf($entry->bookingUuid), $entry],
            $entries,
        ));
    }

    /**
     * The singleton guard. It answers false rather than raising and rather than waiting, which
     * is what makes a second sweep of one tenant stand down instead of queueing behind the
     * first.
     */
    public static function claimSql(): string
    {
        return 'select pg_try_advisory_xact_lock(?, ?) as taken';
    }

    /**
     * The selection of 4.8: the held bookings whose expiry has passed, oldest id first, locked
     * and skipping whatever another transaction holds.
     *
     * The limit is written into the text rather than bound, because it is a constant of the job
     * and a query plan for "the next five hundred by id" is worth having the planner see. The
     * ORDER BY is the id, which is the order every reap takes its rows in too, so a sweep and a
     * reap meeting on the same rows queue rather than cross.
     *
     * The auto-approving request is deliberately not excluded, which is the one way this differs
     * from the reap's choice of the same rows: the reap may only free slots and must leave such
     * a request alone, and this is the only thing that decides one.
     *
     * The policy column comes back because the split is the use case's to make. Nothing in SQL
     * decides which side a row falls on, so the rule is written once, in one language, where
     * the audit row and the event that follow from it are written.
     *
     * **Why this is a selection and not 4.8's own statement.** The sample there chooses and
     * expires in one CTE. Two things it cannot carry: the rows it chose fall on two sides, one
     * expired and one confirmed, and one of those sides writes four more columns with an instant
     * among them. What it could carry, and the reason not to reach for, is the status each
     * booking was in before the update; the reap does exactly that with its own doomed CTE, so
     * that is no argument at all and is recorded here so nobody makes it.
     */
    public static function lapsedSql(int $limit): string
    {
        return 'select id, uuid::text as uuid, status, hold_class, on_approval_timeout, payment_mode from bookings'
            .' where status in ('.implode(', ', array_map(SqlText::literal(...), BookingStatus::held())).')'
            .' and hold_expires_at <= now()'
            .' order by id for update skip locked'
            .sprintf(' limit %d', $limit);
    }

    /**
     * The holds the clock let go, and the end of the hold each of them was on. Every transition
     * out of a held state nulls the class and the expiry, and an expiry is one: a row left
     * carrying them answers GET /bookings/{uuid} with a hold class and a past expiry where a
     * cancelled booking answers nulls, and the booking page's countdown reads that field.
     *
     * The conditions the reap repeats on its own update are not repeated here and are not
     * needed: the reap waits for a row another transaction holds and must therefore re-check
     * what it finds when the wait ends, while this skipped every such row and holds a lock on
     * every row it names.
     */
    public static function expireSql(): string
    {
        return 'update bookings set status = '.SqlText::literal(BookingStatus::Expired)
            .', hold_class = null, hold_expires_at = null, manage_token_sealed = null, updated_at = now()'
            .' where id = any(?::bigint[])';
    }

    /**
     * The requests the clock granted, with the columns of an approval beside the status, bound
     * the way the compare-and-set binds them.
     *
     * @param  list<TransitionColumn>  $columns  what is written beside the status, in the order given
     */
    public static function confirmSql(array $columns): string
    {
        return 'update bookings set status = '.SqlText::literal(BookingStatus::Confirmed).', updated_at = now()'
            .WrittenColumns::assignments($columns)
            .' where id = any(?::bigint[])';
    }

    /**
     * The requests the clock granted that are to be paid for online through a working
     * connection: awaiting_payment under a payment hold, its expiry the server's now() plus the
     * settings row's payment hold, written beside the columns of an approval.
     *
     * @param  list<TransitionColumn>  $columns  what is written beside the status, in the order given
     * @param  list<TransitionColumn>  $fromServerNow  the columns among them written as the server's now() plus a binding of hours
     */
    public static function awaitPaymentSql(array $columns, array $fromServerNow): string
    {
        return 'update bookings set status = '.SqlText::literal(BookingStatus::AwaitingPayment).', updated_at = now()'
            .WrittenColumns::assignments($columns, $fromServerNow)
            .' where id = any(?::bigint[])';
    }

    /**
     * One statement over the rows this batch is holding, asserting it reached every one of
     * them. The rows are locked by the selection, so a short count is not a race: it means the
     * statement is not the statement this thinks it is.
     *
     * @param  list<mixed>  $bindings
     */
    private function assertReachedAll(string $sql, array $bindings, int $expected, BookingStatus $target): void
    {
        $changed = $this->connection->affectingStatement($sql, $bindings);

        if ($changed !== $expected) {
            throw new UnexpectedValueException(sprintf(
                'The sweep set %d rows to %s where %d were locked by this batch.',
                $changed,
                $target->value,
                $expected,
            ));
        }
    }

    /**
     * The ids of the rows the caller names, from the selection that locked them.
     *
     * @param  non-empty-list<string>  $uuids
     * @return non-empty-list<int>
     */
    private function idsOf(array $uuids): array
    {
        return array_map($this->idOf(...), $uuids);
    }

    private function idOf(string $uuid): int
    {
        return $this->selected[$uuid]
            ?? throw new LogicException(sprintf('Booking %s is not one this batch selected; the sweep settles the rows it locked.', $uuid));
    }

    /**
     * One row the selection returned: the id the writes need, and the booking as the trail, the
     * acknowledgement rule and the split all read it.
     *
     * @return array{int, LapsedHold}
     */
    private static function lapsedOf(mixed $row): array
    {
        $id = $row instanceof stdClass ? $row->id ?? null : null;
        $uuid = $row instanceof stdClass ? $row->uuid ?? null : null;
        $status = $row instanceof stdClass ? $row->status ?? null : null;
        $holdClass = $row instanceof stdClass ? $row->hold_class ?? null : null;
        $timeout = $row instanceof stdClass ? $row->on_approval_timeout ?? null : null;
        $mode = $row instanceof stdClass ? $row->payment_mode ?? null : null;

        if (! is_int($id) || ! is_string($uuid) || ! is_string($status) || ! is_string($timeout) || ! is_string($mode) || ($holdClass !== null && ! is_string($holdClass))) {
            throw new UnexpectedValueException('The sweeper selection returned a row that is not shaped as the schema declares.');
        }

        return [$id, new LapsedHold(
            $uuid,
            BookingStatus::from($status),
            $holdClass === null ? null : HoldClass::from($holdClass),
            ApprovalTimeout::from($timeout),
            PaymentMode::from($mode),
        )];
    }
}
