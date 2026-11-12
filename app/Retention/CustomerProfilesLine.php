<?php

declare(strict_types=1);

namespace App\Retention;

use App\Tenancy\Tenant;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;

/**
 * A customer's profile: kept while a booking still carries the address and deleted once none does,
 * so it follows the bookings' period. The bookings line takes the person off a booking 24 months
 * after its last segment ends, and runs before this line, so the profile goes in the run that
 * takes the last booking keeping the address; a profile whose address no booking carries for
 * another reason, an erasure that left it or a row written for no booking, goes in the same
 * statement. The address is compared as citext compares bookings.customer_email.
 */
final readonly class CustomerProfilesLine implements TenantLine
{
    public function __construct(private DatabaseManager $db) {}

    public function table(): string
    {
        return 'customer_profiles';
    }

    public function apply(Tenant $tenant): int
    {
        return $this->db->connection()->transaction(fn (Connection $db): int => $db->delete(sprintf(
            'delete from %1$s.customer_profiles profile where not exists (select 1 from %1$s.bookings booking where booking.customer_email = profile.email)',
            $tenant->schema_name,
        )));
    }
}
