<?php

declare(strict_types=1);

namespace App\Platform;

use App\Application\Clock;
use App\Billing\SubscriptionState;
use App\Notifications\AccountClosing;
use App\Retention\Cutoff;
use App\Tenancy\Tenant;
use App\Tenancy\TenantStatus;
use DateTimeImmutable;
use Illuminate\Database\Connection;

/**
 * The retention sweep closing a lapsed subscriber, as the Closer AccountClose writes the close
 * for: one close of one row the sweep's line read as due, built for that close and remembering,
 * once the row is admitted under the lock, the period end its mail names and whether its first
 * payment never went through, which the mail tells in its own sentence naming no period.
 *
 * A lapsed subscriber is an active row whose subscription state is canceled and whose trial end is
 * null: it once subscribed, since the subscription sync clears trial_ends_at on the first
 * subscription it lands, so the hourly lapse, which closes a trial ended unpaid by that column,
 * never reaches it. Every former subscriber whose subscription ended is one, the tenant whose first
 * charge expired at incomplete and was mapped canceled among them. It is due thirty days after its
 * subscriptions row's current_period_end, the end of the last period a snapshot recorded, or thirty
 * days after the tenant row's updated_at where no period was ever recorded; due at exactly thirty
 * days, as the lapse and the drop are, so a row whose instant is the window's start is closed. A
 * suspended row is left alone, as the lapse leaves it, and so is a row in trial or with its trial
 * end set, which is the lapse's, and a closing one, which the writer refuses as already closing.
 *
 * The rule is spelled twice and the two sit here together, so a change to one is made to the
 * other: DUE, the predicate the sweep's line reads the due rows by, and refusal(), the same
 * question asked of the row the writer read under its lock. Between the two a subscription may
 * land, the way back's checkout completed; the row is then active again or holds a later period,
 * and the close is refused, nothing written, nothing cancelled at the provider and nobody told.
 *
 * It records nothing, as the lapse writes no record: closing_at and the mail are the record. A
 * canceled row cancels nothing at the provider, the writer cancelling in every state but canceled,
 * and is never the free shape, so the way back is a plan on the Billing screen. It tells the owners
 * with the closing email's lapsed subscriber's opening.
 */
final class LapsedClose implements Closer
{
    /**
     * How long after its last period's end, or its last update where no period was recorded, a
     * lapsed subscriber is closed.
     */
    public const string CLOSES_AFTER = 'P30D';

    /**
     * The predicate a due row answers, over the tenants row aliased t left joined to its
     * subscriptions row aliased s, bound by dueBindings(); refusal() asks the same of a locked row.
     */
    public const string DUE = 't.status = ? and t.subscription_state = ? and t.closing_at is null and t.trial_ends_at is null
        and coalesce(s.current_period_end, t.updated_at) <= ?::timestamptz';

    private ?DateTimeImmutable $periodEnd = null;

    private bool $neverPaid = false;

    public function __construct(
        private readonly AccountClosing $closing,
        private readonly Clock $clock,
    ) {}

    /**
     * What DUE binds, in its order, on the clock given.
     *
     * @return list<string>
     */
    public static function dueBindings(Clock $clock): array
    {
        return [TenantStatus::Active->value, SubscriptionState::Canceled->value, Cutoff::before($clock, self::CLOSES_AFTER)];
    }

    public function refusal(LockedAccount $account): ?CloseRefused
    {
        $since = $account->periodEnd ?? $account->updatedAt;
        $due = $account->status === TenantStatus::Active
            && $account->state === SubscriptionState::Canceled
            && ! $account->trialEnd
            && $since !== null
            && $since <= Cutoff::at($this->clock, self::CLOSES_AFTER);

        if (! $due) {
            return CloseRefused::notDue();
        }

        $this->periodEnd = $account->periodEnd;
        $this->neverPaid = $account->neverPaid;

        return null;
    }

    public function record(Connection $db, Tenant $tenant): void {}

    public function tell(AccountOwners $account, DateTimeImmutable $closedAt, bool $free): void
    {
        $this->closing->lapsedClose($account, $closedAt, $free, $this->periodEnd, $this->neverPaid);
    }
}
