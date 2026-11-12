<?php

declare(strict_types=1);

namespace App\Infrastructure\Db;

use Illuminate\Database\Connection;

/**
 * The one statement that takes a booking's items out of the way, in the one place that spells
 * it.
 *
 * Three unrelated writes run it, and they have nothing else in common: step 7 of a guarded
 * transition, for the one booking it locked; the reap of step 4, for whatever holds it let go on
 * its way to taking a slot; and the sweeper of 4.8, for a batch of up to five hundred. What they
 * share is the flag, which is the predicate of the exclusion constraint and the column every
 * availability read joins on, so a second spelling of this is a second chance to leave a
 * cancelled booking occupying a chair.
 *
 * Its own class rather than a static reached for across two adapters. The sweeper and the
 * booking writer are kept apart on purpose, because one of them may use SKIP LOCKED and the
 * other may never; a shared statement belongs beside neither of them.
 */
final readonly class BlockingItems
{
    public static function unblockSql(): string
    {
        return 'update booking_items set blocks = false, updated_at = now() where booking_id = any(?::bigint[])';
    }

    /**
     * Run on the connection the transaction runner handed its closure, inside that transaction.
     * An empty list is not an error and touches nothing: the reap calls it with whatever it
     * expired, which is often nothing at all.
     *
     * @param  list<int>  $bookingIds
     */
    public static function unblock(Connection $connection, array $bookingIds): void
    {
        $connection->affectingStatement(self::unblockSql(), [SqlText::bigintArray($bookingIds)]);
    }
}
