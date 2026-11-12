<?php

declare(strict_types=1);

namespace App\Infrastructure\Db;

use PDOException;

/**
 * 40001 or 40P01 on the last attempt. Each attempt re-ran the whole closure and lost to a
 * concurrent transaction again, and the runner wrote an info line per retry and its failure
 * line at the end. The pay hop's write and its on-site settlement answer it as a lost race, as
 * they answer a Busy; the hop's write does since it holds the staff-day lock across a session
 * the provider already opened. Every other route lets it reach the handler, which reports it,
 * the JSON routes included (ErrorEnvelope maps Busy, WriteContended and KeySetMoved to 503, not
 * this). Two callers catch it with every other failure. The hold sweep (SweepExpiredHolds),
 * which no route runs, writes its own error line and returns. A provider's notification
 * (PaymentWebhook, the service behind POST /webhooks/{provider}/{token}) logs it and rethrows
 * it as the previous of a NotificationUnhandled, which reaches the handler and is answered 500,
 * the event left unmarked, so the provider delivers the notification again.
 */
final class RetriesExhausted extends TransactionFailure
{
    private function __construct(
        public readonly int $attempts,
        SqlState $sqlstate,
        string $tenant,
        string $message,
        PDOException $cause,
    ) {
        parent::__construct($sqlstate, $tenant, $message, $cause);
    }

    public static function after(int $attempts, SqlState $sqlstate, string $tenant, PDOException $cause): self
    {
        return new self($attempts, $sqlstate, $tenant, sprintf(
            'Tenant "%s": the transaction lost to a concurrent one on every one of %d attempts (SQLSTATE %s).',
            $tenant,
            $attempts,
            $sqlstate->value,
        ), $cause);
    }
}
