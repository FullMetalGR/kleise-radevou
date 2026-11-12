<?php

declare(strict_types=1);

namespace App\Retention;

use App\Application\Clock;
use App\Domain\Audit\Actor;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Enum\BookingStatus;
use App\Infrastructure\Db\AuditRow;
use App\Infrastructure\Db\SqlText;
use App\Infrastructure\Db\TransactionRunner;
use App\Tenancy\Tenant;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use LogicException;

/**
 * The admin's erasure of one customer: the business, the controller of its customers' data, erases
 * the person a customer's page is named by, and the bookings stay as records with their services
 * and times.
 *
 * A customer is the bookings of one address, compared as citext compares bookings.customer_email,
 * as the page's route matches it (CustomerRecords::one). In one transaction, the transaction
 * runner's: the address's bookings locked in id order; refused, writing nothing, while any of them
 * is live; then erasure's primitive over every one of them, Erasure, whose own transaction is a
 * savepoint inside this one; then the address's My bookings links and sessions deleted, which carry
 * the address and nothing else, as the same citext compares it; then the address's profile and its
 * notes deleted the same way, the tags, the birthday, the language, the block and every note gone
 * with the person; then one trail row per booking, action customer_erased, the principal's actor,
 * an empty payload. Nothing is mailed and no audit row is touched: the trail never held the person.
 *
 * The lock is what makes the refusal true at the write. A transition settling or moving one of
 * these bookings takes its row lock as well (GuardedWrite's step 4, a reschedule's), so the
 * liveness read after the lock reads what that transition committed, and a booking cannot turn
 * live between the read and the erasure. A booking made under the address after the lock is a
 * new one and keeps its customer. Only booking rows are locked here, never a staff-day, so the
 * write protocol's order, the mutex rows before the booking row, is never taken backwards.
 *
 * What is live, in four clauses, on two clocks. A hold of any class whose expiry is ahead, read by
 * SqlText::holdIsLive against the database's now(), the one reading of a hold's liveness: now()
 * wrote the expiry, the sweep and the reap select against it, and a confirm re-reads it so under
 * the booking's own row lock. The application's clock, read after the BEGIN that froze now(),
 * stands ahead of it by milliseconds, and apart from it by any skew between the hosts, so a hold
 * judged by it could be erased while still live, and a confirm waiting on the erasure's row lock
 * would then find it live and confirm a booking with no customer and no manage token. A request
 * awaiting a decision and a booking awaiting payment, whatever their hold's expiry, since a request
 * past its window on auto_approve still blocks its slot and is granted by the sweeper and a lapsed
 * hold is the sweeper's to expire within the minute. And a confirmed booking with a segment, a
 * booking item, whose end_utc is ahead of the Clock port's now bound as UTC text, as the retention
 * lines bind it: the end the customer was sold, never the block's end past a buffer nor a
 * processing gap's. Everything else erases at once: settled, cancelled, rejected and expired
 * bookings, a checkout hold the database's clock has let go, and a confirmed booking whose every
 * segment has ended.
 *
 * Nothing of a row leaves this class but counts. Every table is named by the tenant's schema; the
 * trail's writer names its tables as the runner's search path finds them.
 */
final readonly class CustomerErasure
{
    public function __construct(
        private TransactionRunner $runner,
        private DatabaseManager $db,
        private Erasure $erasure,
        private Clock $clock,
    ) {}

    /**
     * How many bookings of the address are live now, zero where none is: the count an erasure
     * would be refused with, which a customer's page words in its dialog before any press.
     */
    public function liveCount(Tenant $tenant, string $email): int
    {
        $live = $this->db->connection()->scalar(
            sprintf('select count(*) from %s.bookings booking where booking.customer_email = ?::citext and %s', $tenant->schema_name, self::live($tenant->schema_name)),
            [$email, SqlText::instant($this->clock->now())],
        );

        return self::count($live);
    }

    /**
     * Erases the customer of the address and answers how many bookings lost the person.
     *
     * @throws CustomerUnknown where no booking of the tenant carries the address
     * @throws CustomerLive where a booking of the address is live, nothing written
     */
    public function erase(Tenant $tenant, string $email, Actor $actor): int
    {
        return $this->runner->run($tenant, function (Connection $db) use ($tenant, $email, $actor): int {
            $schema = $tenant->schema_name;
            $now = SqlText::instant($this->clock->now());
            $bookings = $this->locked($db, $tenant, $email);

            if ($bookings === []) {
                throw new CustomerUnknown($tenant->slug);
            }

            $ids = array_keys($bookings);
            $live = self::count($db->scalar(
                sprintf('select count(*) from %s.bookings booking where booking.id = any(?::bigint[]) and %s', $schema, self::live($schema)),
                [SqlText::bigintArray($ids), $now],
            ));

            if ($live > 0) {
                throw new CustomerLive($live);
            }

            $erased = $this->erasure->bookings($tenant, $ids);
            $db->delete(sprintf('delete from %s.customer_links where email = ?::citext', $schema), [$email]);
            $db->delete(sprintf('delete from %s.customer_sessions where email = ?::citext', $schema), [$email]);
            $db->delete(sprintf('delete from %s.customer_profiles where email = ?::citext', $schema), [$email]);
            $db->delete(sprintf('delete from %s.customer_notes where email = ?::citext', $schema), [$email]);

            $rows = [];

            foreach ($bookings as $id => $uuid) {
                $rows[] = [$id, new AuditEntry($uuid, AuditAction::CustomerErased, $actor)];
            }

            AuditRow::writeAll($db, $rows);

            return $erased;
        });
    }

    /**
     * The address's bookings, locked in id order, each id with its uuid.
     *
     * @return array<int, string>
     */
    private function locked(Connection $db, Tenant $tenant, string $email): array
    {
        $bookings = [];

        foreach ($db->select(sprintf('select id, uuid::text as uuid from %s.bookings where customer_email = ?::citext order by id for update', $tenant->schema_name), [$email]) as $row) {
            if (! is_object($row) || ! isset($row->id, $row->uuid) || ! is_int($row->id) || ! is_string($row->uuid)) {
                throw new LogicException(sprintf('A booking of tenant "%s" came back without its id and uuid.', $tenant->slug));
            }

            $bookings[$row->id] = $row->uuid;
        }

        return $bookings;
    }

    /**
     * Whether the booking aliased booking is live: the hold's expiry against the database's now()
     * through SqlText::holdIsLive, and a segment's end_utc against the Clock port's now, the one
     * instant it binds.
     */
    private static function live(string $schema): string
    {
        $held = implode(', ', array_map(SqlText::literal(...), BookingStatus::held()));
        $waiting = implode(', ', array_map(SqlText::literal(...), [BookingStatus::AwaitingApproval, BookingStatus::AwaitingPayment]));

        return '((booking.status in ('.$held.') and '.SqlText::holdIsLive('booking.hold_expires_at').')'
            .' or booking.status in ('.$waiting.')'
            .' or (booking.status = '.SqlText::literal(BookingStatus::Confirmed)
            .' and exists (select 1 from '.$schema.'.booking_items item where item.booking_id = booking.id and item.end_utc > ?::timestamptz)))';
    }

    private static function count(mixed $count): int
    {
        return is_int($count) ? $count : throw new LogicException('A count of live bookings came back as no integer.');
    }
}
