<?php

declare(strict_types=1);

namespace App\Retention;

use App\Application\Clock;
use App\Billing\Resubscribe;
use App\Infrastructure\Db\SqlText;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;

/**
 * A checkout record past its lifetime: the session the Billing screen's checkout recorded on the
 * subscriptions row, its id, its URL and the instant it opened, which the completion clears once
 * the sync's write for the pair has landed. A completion that never lands, SignupCompletion's
 * NOT_LANDED line among them, leaves the record behind with nothing to end it; this line ends it.
 * Past is as the checkout itself reads the record (Resubscribe::openCheckout): open while it opened
 * less than CHECKOUT_LIFETIME ago, so one opened 24 hours ago or longer is gone and cleared. The
 * three columns are nulled together in one statement, the CHECK subscriptions_checkout_whole
 * requiring it and the sync's forgetCheckout spelling it so; the subscription, its customer, its
 * billing columns and its revision stay, and updated_at takes the clock's now, the instant the row
 * changed.
 */
final readonly class CheckoutRecordsLine implements RetentionLine
{
    public function __construct(private DatabaseManager $db, private Clock $clock) {}

    public function table(): string
    {
        return 'subscriptions';
    }

    public function apply(): int
    {
        return $this->db->connection()->transaction(fn (Connection $db): int => $db->update(
            'update subscriptions set checkout_session_id = null, checkout_url = null, checkout_opened_at = null, updated_at = ?::timestamptz
             where checkout_opened_at <= ?::timestamptz',
            [SqlText::instant($this->clock->now()), Cutoff::before($this->clock, Resubscribe::CHECKOUT_LIFETIME)],
        ));
    }
}
