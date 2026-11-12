<?php

declare(strict_types=1);

namespace App\Infrastructure\Db;

use App\Application\Checkpoint;
use App\Application\ConfirmPaidBooking;
use App\Application\GuardedWrite;
use App\Application\KeySetMoved;
use App\Application\Payment\CheckoutRecords;
use App\Application\Payment\CheckoutState;
use App\Application\Payment\ManageTokenSeal;
use App\Domain\Audit\Actor;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditKey;
use App\Domain\Booking\LockKeySet;
use App\Domain\Booking\StaffBlock;
use App\Domain\Enum\BookingStatus;
use App\Domain\Enum\PaymentMode;
use App\Tenancy\Tenant;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use LogicException;
use SensitiveParameter;
use stdClass;

/**
 * The booking row as the pay hop reads and writes it, PostgreSQL's. Every statement names the
 * tenant's schema, as the readers behind the public routes do.
 *
 * The read is one statement outside any transaction. The write moves a live hold's end, and a
 * cached availability mask that counted the hold was built against the old end, so the write
 * runs inside the write protocol and never beside it: the key set of the booking's items read
 * first, outside the transaction; then, inside one, step 3's statement, which locks the
 * staff-days sorted and bumps their revisions, so an entry built before the write is keyed under
 * a revision no read asks for again; then the items read again under that lock, the transaction
 * rolled back and the whole run again once where a move committed in between left them on other
 * staff-days, as a guarded transition's step 5 does; then the booking row, by the guarded
 * update. Staff-days before the booking, the order every guarded transition keeps. Nothing in
 * here asks the provider: the hop asked it before this opened.
 *
 * A write that loses a race answers false and writes nothing, for the hop to read the row again:
 * a guard the row no longer meets, a move under the lock on every attempt, and the busy rows of
 * the runner's error map, a staff-day held past the lock timeout (Busy) and retries exhausted,
 * which the runner rolled back and counted with its failure line. Every other row of that map is
 * a fault, not a race, and reaches the handler to be reported as it is everywhere else.
 *
 * The write's guard is its where clause. An update takes the row's lock and evaluates the clause
 * again against the row as committed when it gets it, so a sweep, a release or a cancel that
 * committed at any moment before it, while the provider was being asked or under the lock, is
 * seen, and the hold it ended is not revived. Liveness is now() against the expiry, the
 * database's clock, which is the clock the sweeper and the blocking predicate read.
 */
final readonly class PostgresCheckoutRecords implements CheckoutRecords
{
    /**
     * The checkpoint between the key set's read and the transaction: a test standing here has
     * read where the booking's items are and holds nothing yet, which is where a move can still
     * land before the lock. Reached once per attempt whose read finds the booking's items; an
     * attempt that finds none answers false before it.
     */
    public const string KEYED = 'checkout.keyed';

    public function __construct(
        private DatabaseManager $db,
        private Tenant $tenant,
        private ManageTokenSeal $seal,
        private TransactionRunner $runner,
        private LockManager $locks,
    ) {}

    public function state(string $uuid): ?CheckoutState
    {
        if (! SqlText::isUuid($uuid)) {
            return null;
        }

        $row = $this->db->connection()->selectOne(
            'select b.status, b.payment_mode, b.payment_ref, '.SqlText::instantColumn('b.hold_expires_at').' as hold_expires_at, '
            .SqlText::holdIsLive('b.hold_expires_at').' as hold_is_live,'
            .' exists (select 1 from '.$this->tenant->schema_name.'.audit_log a where a.booking_id = b.id and a.action = ? and a.actor = ?) as paid,'
            .' exists (select 1 from '.$this->tenant->schema_name.'.audit_log a where a.booking_id = b.id and a.action = ? and a.actor = ?'
            .' and a.payload->>? = \'true\') as payment_named,'
            .' (select count(*) from '.$this->tenant->schema_name.'.audit_log a where a.booking_id = b.id and a.action = ?'
            .' and a.payload->>? = \'true\')::int as sessions_cleared'
            .' from '.$this->tenant->schema_name.'.bookings b where b.uuid = ?::uuid',
            [
                AuditAction::Confirmed->value, Actor::job(ConfirmPaidBooking::ACTOR)->value,
                AuditAction::Confirmed->value, Actor::job(ConfirmPaidBooking::ACTOR)->value, AuditKey::PaymentNamed->value,
                AuditAction::Rescheduled->value, AuditKey::SessionCleared->value,
                $uuid,
            ],
        );

        if (! $row instanceof stdClass) {
            return null;
        }

        if (! is_string($row->status) || ! is_string($row->payment_mode) || ! is_bool($row->hold_is_live) || ! is_bool($row->paid) || ! is_bool($row->payment_named)
            || ! is_int($row->sessions_cleared)
            || ($row->payment_ref !== null && ! is_string($row->payment_ref))
            || ($row->hold_expires_at !== null && ! is_string($row->hold_expires_at))) {
            throw new LogicException(sprintf('A bookings row of tenant "%s" is not shaped as the schema declares.', $this->tenant->slug));
        }

        return new CheckoutState(
            BookingStatus::from($row->status),
            PaymentMode::from($row->payment_mode),
            $row->payment_ref,
            $row->hold_is_live,
            $row->hold_expires_at === null ? null : SqlText::readInstant($row->hold_expires_at),
            $row->paid,
            $row->payment_named,
            $row->sessions_cleared,
        );
    }

    public function record(string $uuid, string $paymentRef, DateTimeImmutable $endsAt, #[SensitiveParameter] ?string $manageToken): bool
    {
        if (! SqlText::isUuid($uuid)) {
            return false;
        }

        $sealed = $manageToken === null ? null : $this->seal->seal($manageToken);

        for ($attempt = 1; $attempt <= GuardedWrite::ATTEMPTS; $attempt++) {
            $keys = $this->keysOf($this->db->connection(), $uuid);

            if ($keys === null) {
                return false;
            }

            Checkpoint::reach(self::KEYED);

            try {
                return $this->runner->run($this->tenant, function (Connection $connection) use ($keys, $uuid, $paymentRef, $endsAt, $sealed): bool {
                    $this->locks->lock($connection, $keys);
                    $locked = $this->keysOf($connection, $uuid);

                    if ($locked === null || ! $keys->equals($locked)) {
                        throw KeySetMoved::under($uuid);
                    }

                    return $connection->update(self::recordSql($this->tenant->schema_name), [$paymentRef, SqlText::instant($endsAt), $sealed, $uuid]) === 1;
                });
            } catch (KeySetMoved) {
                continue;
            } catch (Busy|RetriesExhausted) {
                // The busy rows of the runner's error map: the runner rolled back and wrote its
                // failure line, which counts it, so nothing is written and nothing is logged again.
                // A lost race: the hop reads the row again and answers it by its first read's
                // mapping.
                return false;
            }
        }

        // Moved under the lock on every attempt: nothing written, and the hop reads the row
        // again, as a write that lost to a sweep does.
        return false;
    }

    /**
     * The guarded update: payment_ref, the hold's end and the seal, on a hold of a payable
     * status that is still live and carries no session yet. A visit on the signed road seals
     * nothing and keeps the seal a token visit left: a move of a booking waiting for its payment
     * clears the session, so the row can be recorded twice, and the confirmation's manage link
     * must survive a second visit made on the road that holds no token.
     */
    private static function recordSql(string $schema): string
    {
        $payable = implode(', ', array_map(SqlText::literal(...), [BookingStatus::Pending, BookingStatus::AwaitingPayment]));

        return 'update '.$schema.'.bookings set payment_ref = ?, hold_expires_at = ?::timestamptz, manage_token_sealed = coalesce(?, manage_token_sealed), updated_at = now()'
            .' where uuid = ?::uuid and status in ('.$payable.') and payment_ref is null and '.SqlText::holdIsLive('hold_expires_at');
    }

    /**
     * The staff-days the booking's items touch in the tenant's zone, sorted, or null for a
     * booking with no items, read on the connection given: before the transaction, the key set
     * to lock; under the lock, the check that it is still the one.
     */
    private function keysOf(Connection $connection, string $uuid): ?LockKeySet
    {
        $rows = $connection->select(
            'select i.staff_id, '.SqlText::instantColumn('i.block_start_utc').' as block_start, '.SqlText::instantColumn('i.block_end_utc').' as block_end'
            .' from '.$this->tenant->schema_name.'.booking_items i join '.$this->tenant->schema_name.'.bookings b on b.id = i.booking_id'
            .' where b.uuid = ?::uuid order by i.sort',
            [$uuid],
        );

        if ($rows === []) {
            return null;
        }

        $blocks = [];

        foreach ($rows as $row) {
            if (! $row instanceof stdClass || ! is_int($row->staff_id) || ! is_string($row->block_start) || ! is_string($row->block_end)) {
                throw new LogicException(sprintf('A booking_items row of tenant "%s" is not shaped as the schema declares.', $this->tenant->slug));
            }

            $blocks[] = new StaffBlock(
                $row->staff_id,
                SqlText::readInstant($row->block_start),
                SqlText::readInstant($row->block_end),
            );
        }

        return LockKeySet::forBlocks($blocks, $this->tenant->zone());
    }
}
