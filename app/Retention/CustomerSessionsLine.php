<?php

declare(strict_types=1);

namespace App\Retention;

use App\Application\Clock;
use App\Infrastructure\Db\SqlText;
use App\Tenancy\Tenant;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;

/**
 * My bookings' sessions: an address each row, kept until the session's expires_at, or its
 * revoked_at where sooner, and then deleted. A session is live while it is unrevoked and its
 * expiry is ahead of the clock's now, as the session reads itself
 * (App\Infrastructure\Db\CustomerAccess::sessionAddress), and the line deletes every session
 * that is not: one signed out or opened over by a later link, whatever its expiry, and one whose
 * expiry is the clock's now or before it.
 */
final readonly class CustomerSessionsLine implements TenantLine
{
    public function __construct(private DatabaseManager $db, private Clock $clock) {}

    public function table(): string
    {
        return 'customer_sessions';
    }

    public function apply(Tenant $tenant): int
    {
        return $this->db->connection()->transaction(fn (Connection $db): int => $db->delete(
            sprintf('delete from %s.customer_sessions where revoked_at is not null or expires_at <= ?::timestamptz', $tenant->schema_name),
            [SqlText::instant($this->clock->now())],
        ));
    }
}
