<?php

declare(strict_types=1);

namespace App\Retention;

use App\Application\Clock;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;

/**
 * The tenant payments' notification record: kept ninety days from created_at, when it arrived,
 * then deleted, verified or refused. No payload is stored, so nothing personal goes with it.
 */
final readonly class PaymentEventsLine implements RetentionLine
{
    /** How long an event is kept from its created_at. */
    public const string KEPT = 'P90D';

    public function __construct(private DatabaseManager $db, private Clock $clock) {}

    public function table(): string
    {
        return 'payment_events';
    }

    public function apply(): int
    {
        return $this->db->connection()->transaction(fn (Connection $db): int => $db->delete(
            'delete from payment_events where created_at < ?::timestamptz',
            [Cutoff::before($this->clock, self::KEPT)],
        ));
    }
}
