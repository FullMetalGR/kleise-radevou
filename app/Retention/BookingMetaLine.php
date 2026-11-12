<?php

declare(strict_types=1);

namespace App\Retention;

use App\Application\Clock;
use App\Infrastructure\Db\SqlText;
use App\Tenancy\Tenant;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;

/**
 * A booking's intake fields: personal data by default, kept 24 months after the booking's last
 * segment ends and then deleted outright, whatever the booking's status and whether or not its
 * customer is already erased. The bookings line before it deletes them with the customer it
 * erases, so this line catches only a row that line missed; a note written after an erasure
 * cannot exist, since nothing writes one but the hold, so the count is a check, and a count above
 * zero is worth a look.
 */
final readonly class BookingMetaLine implements TenantLine
{
    /** How long a booking's intake rows are kept after its last segment ends. */
    public const string KEPT = 'P24M';

    public function __construct(private DatabaseManager $db, private Clock $clock) {}

    public function table(): string
    {
        return 'booking_meta';
    }

    public function apply(Tenant $tenant): int
    {
        return $this->db->connection()->transaction(fn (Connection $db): int => $db->delete(
            sprintf(
                'delete from %1$s.booking_meta meta using (%2$s) ended where ended.booking_id = meta.booking_id and ended.ends_at < ?::timestamptz',
                $tenant->schema_name,
                SqlText::lastEnds($tenant->schema_name),
            ),
            [Cutoff::before($this->clock, self::KEPT)],
        ));
    }
}
