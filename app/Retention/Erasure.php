<?php

declare(strict_types=1);

namespace App\Retention;

use App\Infrastructure\Db\SqlText;
use App\Tenancy\Tenant;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use LogicException;

/**
 * Erasure's primitive: the one statement that loses the person and keeps the booking. Over the
 * bookings it is given, in one transaction, it nulls customer_name, customer_email and
 * customer_phone together, as the CHECK bookings_customer_check holds them (tenant migration
 * 000028); it deletes the bookings' booking_meta rows, the intake fields being personal data by
 * default; and it nulls manage_token_hash and manage_token_sealed, which ends the magic link and
 * drops the one credential a booking still carried. The row stands, its status, its total and its
 * instants as they were; no booking_items row and no audit_log row is touched, the trail having
 * carried no personal data from its first migration, so it needs no erasure step. It writes
 * nothing else, updated_at included, and sends nothing.
 *
 * Two callers and one statement. The retention line of the 24 months, BookingCustomersLine, runs
 * it over the bookings whose last segment ended before the line's cutoff; the admin's erasure of
 * a customer, runs it over the bookings it names by id. Either way the bookings are chosen by the
 * statement's first part, and the update and the delete read that choice, data-modifying parts of
 * one statement that the server runs to completion whether or not the answer reads them, so the
 * person goes from the row and from its intake rows together or not at all.
 *
 * What it answers is how many bookings it erased, and nothing of a row leaves it. Every table is
 * named by the tenant's schema and nothing is set on the connection, as the booking reader does
 * it.
 */
final readonly class Erasure
{
    public function __construct(private DatabaseManager $db) {}

    /**
     * Erases the bookings of the tenant those ids name, the erasure of one customer, and
     * answers how many there were. An id naming no booking of the schema erases nothing.
     *
     * @param  list<int>  $bookingIds
     */
    public function bookings(Tenant $tenant, array $bookingIds): int
    {
        return $this->erase(
            $tenant,
            sprintf('select id from %s.bookings where id = any(?::bigint[])', $tenant->schema_name),
            [SqlText::bigintArray($bookingIds)],
        );
    }

    /**
     * Erases every booking of the tenant whose last segment ended before the cutoff and whose
     * customer is not erased already, and answers how many there were. A booking erased before is
     * not chosen again, so a second run in the same instant erases nothing; its intake rows, if
     * any were written after it, are the booking_meta line's.
     *
     * @param  string  $cutoff  the instant the period began, as Cutoff spells one
     */
    public function endedBefore(Tenant $tenant, string $cutoff): int
    {
        return $this->erase(
            $tenant,
            sprintf(
                'select booking.id from %1$s.bookings booking join (%2$s) ended on ended.booking_id = booking.id'
                .' where ended.ends_at < ?::timestamptz and booking.customer_email is not null',
                $tenant->schema_name,
                SqlText::lastEnds($tenant->schema_name),
            ),
            [$cutoff],
        );
    }

    /**
     * @param  string  $chosen  a select answering the ids of the bookings to erase
     * @param  list<string>  $bindings  what it binds
     */
    private function erase(Tenant $tenant, string $chosen, array $bindings): int
    {
        $sql = sprintf(
            'with chosen as (%2$s),'
            .' erased as (update %1$s.bookings set customer_name = null, customer_email = null, customer_phone = null,'
            .' manage_token_hash = null, manage_token_sealed = null where id in (select id from chosen) returning id),'
            .' intake as (delete from %1$s.booking_meta where booking_id in (select id from chosen))'
            .' select count(*) from erased',
            $tenant->schema_name,
            $chosen,
        );

        $erased = $this->db->connection()->transaction(fn (Connection $db): mixed => $db->scalar($sql, $bindings));

        return is_int($erased) ? $erased : throw new LogicException(sprintf('The erasure of tenant "%s" answered no count.', $tenant->slug));
    }
}
