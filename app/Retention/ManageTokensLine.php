<?php

declare(strict_types=1);

namespace App\Retention;

use App\Application\Clock;
use App\Infrastructure\Db\SqlText;
use App\Tenancy\Tenant;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;

/**
 * The manage token: its hash is kept until 30 days after the booking's last segment ends and then
 * nulled, which ends the magic link while the booking itself is kept, and the sealed copy the pay
 * hop may have left beside it is nulled with it, so no credential of the booking outlives the
 * link. Thirty days are elapsed time, thirty times 24 hours, counted from the latest item's end
 * whatever the booking's status. A booking whose two columns are both null already is not written
 * again, so a second run in the same instant finds nothing. Nothing else of the row is written,
 * updated_at included, as erasure's primitive writes nothing else.
 */
final readonly class ManageTokensLine implements TenantLine
{
    /** How long the manage token lives after the booking's last segment ends. */
    public const string KEPT = 'P30D';

    public function __construct(private DatabaseManager $db, private Clock $clock) {}

    public function table(): string
    {
        return 'bookings';
    }

    public function apply(Tenant $tenant): int
    {
        return $this->db->connection()->transaction(fn (Connection $db): int => $db->update(
            sprintf(
                'update %1$s.bookings booking set manage_token_hash = null, manage_token_sealed = null from (%2$s) ended'
                .' where ended.booking_id = booking.id and ended.ends_at < ?::timestamptz'
                .' and (booking.manage_token_hash is not null or booking.manage_token_sealed is not null)',
                $tenant->schema_name,
                SqlText::lastEnds($tenant->schema_name),
            ),
            [Cutoff::before($this->clock, self::KEPT)],
        ));
    }
}
