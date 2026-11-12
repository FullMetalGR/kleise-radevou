<?php

declare(strict_types=1);

namespace App\Retention;

use App\Application\Clock;
use App\Infrastructure\Db\SqlText;
use App\Tenancy\Tenant;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;

/**
 * My bookings' one-time links: an address each row, kept until the link's expires_at and then
 * deleted, used or not. Passed is as the link itself reads it
 * (App\Infrastructure\Db\CustomerAccess::linkIsLive): a link is live while its expiry is ahead of
 * the clock's now, so one whose expiry is the clock's now is lapsed and deleted, as a lapsed
 * set-password link is nulled at its own instant (PasswordLinksLine).
 */
final readonly class CustomerLinksLine implements TenantLine
{
    public function __construct(private DatabaseManager $db, private Clock $clock) {}

    public function table(): string
    {
        return 'customer_links';
    }

    public function apply(Tenant $tenant): int
    {
        return $this->db->connection()->transaction(fn (Connection $db): int => $db->delete(
            sprintf('delete from %s.customer_links where expires_at <= ?::timestamptz', $tenant->schema_name),
            [SqlText::instant($this->clock->now())],
        ));
    }
}
