<?php

declare(strict_types=1);

namespace App\Retention;

use App\Application\Clock;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;

/**
 * Billing's webhook events: kept ninety days from created_at, the instant the verified
 * notification was stored, then deleted, processed or not. The payload carries the platform
 * customer's personal data, which goes with its row.
 */
final readonly class WebhookEventsLine implements RetentionLine
{
    /** How long an event is kept from its created_at. */
    public const string KEPT = 'P90D';

    public function __construct(private DatabaseManager $db, private Clock $clock) {}

    public function table(): string
    {
        return 'webhook_events';
    }

    public function apply(): int
    {
        return $this->db->connection()->transaction(fn (Connection $db): int => $db->delete(
            'delete from webhook_events where created_at < ?::timestamptz',
            [Cutoff::before($this->clock, self::KEPT)],
        ));
    }
}
