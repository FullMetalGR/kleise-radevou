<?php

declare(strict_types=1);

namespace App\Infrastructure\Db;

use App\Domain\Audit\AuditEntry;
use Illuminate\Database\Connection;
use InvalidArgumentException;
use UnexpectedValueException;

/**
 * The trail as SQL, in the one place that spells it, in the two shapes the two writers of it
 * need.
 *
 * The guarded transitions write one row and name the booking by its public identifier, because
 * the row they write is not always the booking they locked: a move's reap expires other people's
 * holds and writes a row for each. The sweeper writes a whole batch and names the bookings by
 * the ids its own selection locked, because it is the one job in this system that exists to work
 * in batches and it is already holding them. One statement per batch, never one per row.
 *
 * Both spell the column list once, here, so a column added to the trail cannot reach one writer
 * and miss the other.
 */
final readonly class AuditRow
{
    private const string INSERT = 'insert into audit_log (booking_id, actor, action, payload)';

    /**
     * One row, resolving the booking from the public identifier the entry names, so no serial id
     * crosses out of the repository. created_at is left to the column's default, which is the
     * transaction's clock reading and therefore the same instant the booking row carries.
     */
    public static function sql(): string
    {
        return self::INSERT.' select id, ?, ?, ?::jsonb from bookings where uuid = ?::uuid';
    }

    /**
     * The same rows for a caller that already holds the ids: one VALUES row per entry, in the
     * order given, which is the order they are read back in.
     *
     * By id and not by uuid, because the only caller is the sweeper and its selection locked
     * these rows by id a statement ago. Re-resolving each uuid would be a join per row to find
     * out what the caller is already holding.
     */
    public static function batchSql(int $count): string
    {
        if ($count < 1) {
            throw new InvalidArgumentException('The trail is written for at least one booking.');
        }

        return self::INSERT.' values '.implode(', ', array_fill(0, $count, '(?, ?, ?, ?::jsonb)'));
    }

    /**
     * Written on the connection the transaction runner handed its closure, inside that
     * transaction. The row count is asserted: the select resolves the booking by uuid, which is
     * unique, so anything but one row means the booking this names is not the row the caller
     * just wrote.
     */
    public static function write(Connection $connection, AuditEntry $entry): void
    {
        $written = $connection->affectingStatement(self::sql(), [
            $entry->actor->value,
            $entry->action->value,
            self::payload($entry),
            $entry->bookingUuid,
        ]);

        if ($written !== 1) {
            throw new UnexpectedValueException(sprintf(
                'The audit row for booking %s reached %d rows; the booking it names is written in this transaction.',
                $entry->bookingUuid,
                $written,
            ));
        }
    }

    /**
     * A batch of rows in one statement, each against the id given beside it.
     *
     * @param  non-empty-list<array{int, AuditEntry}>  $rows  the booking's id and what the trail says of it
     */
    public static function writeAll(Connection $connection, array $rows): void
    {
        $bindings = [];

        foreach ($rows as [$bookingId, $entry]) {
            $bindings[] = $bookingId;
            $bindings[] = $entry->actor->value;
            $bindings[] = $entry->action->value;
            $bindings[] = self::payload($entry);
        }

        $written = $connection->affectingStatement(self::batchSql(count($rows)), $bindings);

        if ($written !== count($rows)) {
            throw new UnexpectedValueException(sprintf(
                'The trail of this batch reached %d rows where %d were written; the ids it names are locked by this transaction.',
                $written,
                count($rows),
            ));
        }
    }

    /**
     * The payload as a JSON object, an empty one included: an empty PHP array would encode as a
     * JSON array, which is not the object the column's default and every other row hold. The
     * object is the payload's own and not forced on what it holds, so a move's list of facts per
     * segment (AuditEntry) stays a JSON array of objects.
     */
    private static function payload(AuditEntry $entry): string
    {
        return json_encode((object) $entry->payload, JSON_THROW_ON_ERROR);
    }
}
