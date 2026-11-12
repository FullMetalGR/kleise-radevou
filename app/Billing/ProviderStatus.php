<?php

declare(strict_types=1);

namespace App\Billing;

/**
 * The billing provider's subscription statuses, spelled once, and the state each one writes on
 * the tenant row. The provider's string is stored verbatim on the subscriptions row; this is the
 * only place that reads it.
 *
 * Exhaustive by construction: a status this build does not name has no case, and the sync logs
 * it and keeps the stored state rather than guessing.
 *
 * past_due answers null, leaving the stored state where it is. It is the provider's word for a
 * charge that failed, and the grace window that failure opens belongs to the failed-charge event
 * and is closed by the succeeded charge or the lapse, never by a snapshot. unpaid and paused are
 * the provider giving up on collecting, so they write past_due, the lapse; incomplete_expired is
 * a first payment that never arrived and ends as canceled.
 *
 * trialing has no case, and no status maps to the tenant row's trial. The trial is the platform's
 * own clock, written by provisioning alone with no provider object behind it, so a subscription
 * reported as trialing is a misconfiguration on the provider's side, and mapping it to active
 * would hand paid-up writes to it; the sync logs it and keeps the stored state.
 */
enum ProviderStatus: string
{
    case Active = 'active';
    case PastDue = 'past_due';
    case Unpaid = 'unpaid';
    case Paused = 'paused';
    case Incomplete = 'incomplete';
    case IncompleteExpired = 'incomplete_expired';
    case Canceled = 'canceled';

    public function state(): ?SubscriptionState
    {
        return match ($this) {
            self::Active => SubscriptionState::Active,
            self::PastDue => null,
            self::Unpaid, self::Paused => SubscriptionState::PastDue,
            self::Incomplete => SubscriptionState::Incomplete,
            self::IncompleteExpired, self::Canceled => SubscriptionState::Canceled,
        };
    }
}
