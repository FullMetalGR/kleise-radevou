<?php

declare(strict_types=1);

namespace App\Billing;

/**
 * The tenant row's cached subscription state, mirroring the check on tenants.subscription_state.
 *
 * isActive() is true for exactly active, grace and trial. Every other state freezes configuration
 * writes, and a seventh state cannot silently break a hand-assembled list because there is no
 * list: the match is exhaustive, so a new case is a decision somebody writes. trial is the
 * platform's own clock, written by provisioning alone and never by the sync, and it holds while
 * trial_ends_at lies ahead: the hourly lapse writes canceled at its end, so no gate compares a
 * date.
 */
enum SubscriptionState: string
{
    case Active = 'active';
    case Grace = 'grace';
    case PastDue = 'past_due';
    case Canceled = 'canceled';
    case Incomplete = 'incomplete';
    case Trial = 'trial';

    public function isActive(): bool
    {
        return match ($this) {
            self::Active, self::Grace, self::Trial => true,
            self::PastDue, self::Canceled, self::Incomplete => false,
        };
    }
}
