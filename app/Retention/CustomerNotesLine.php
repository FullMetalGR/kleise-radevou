<?php

declare(strict_types=1);

namespace App\Retention;

use App\Tenancy\Tenant;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;

/**
 * A customer's notes: kept while a booking still carries the address and deleted once none does,
 * as the address's profile is (CustomerProfilesLine), whether or not a profile row was ever
 * written. Run after the bookings line, so the notes go in the run that takes the last booking
 * keeping the address. A note may hold health data, which is why it is kept no longer than the
 * person on the bookings.
 */
final readonly class CustomerNotesLine implements TenantLine
{
    public function __construct(private DatabaseManager $db) {}

    public function table(): string
    {
        return 'customer_notes';
    }

    public function apply(Tenant $tenant): int
    {
        return $this->db->connection()->transaction(fn (Connection $db): int => $db->delete(sprintf(
            'delete from %1$s.customer_notes note where not exists (select 1 from %1$s.bookings booking where booking.customer_email = note.email)',
            $tenant->schema_name,
        )));
    }
}
