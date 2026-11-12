<?php

declare(strict_types=1);

namespace App\Billing;

/**
 * The trial's two notices: "three days left" once when 72 hours or less of the trial remain, and
 * "ends today" once when 24 hours or less do. Each is marked by a stamp of its own on the tenant
 * row, written in the statement that decides it is due.
 *
 * The cases are in the order a tenant receives them, so a trial already shorter than three days
 * when the first run sees it is told both, the three days first. "Ends today" still reaches a
 * trial whose end has passed before the hourly lapse moved it (P9F-51a), its body worded for an
 * end already past; "three days left" never does.
 */
enum TrialNotice: string
{
    case ThreeDaysLeft = 'three days left';
    case EndsToday = 'ends today';

    /**
     * The column marking the notice sent, written into the statement and never bound.
     *
     * @return literal-string
     */
    public function column(): string
    {
        return match ($this) {
            self::ThreeDaysLeft => 'trial_three_days_sent_at',
            self::EndsToday => 'trial_ends_today_sent_at',
        };
    }

    /**
     * Whether the notice still goes to a tenant in trial whose end is at or behind the clock's now,
     * the lapse not having moved it yet: "ends today" does, once, since its owners would otherwise
     * hear nothing before the freeze; "three days left" never, since nothing it says is true then.
     */
    public function reachesAPastEnd(): bool
    {
        return $this === self::EndsToday;
    }

    /**
     * How long before the trial's end the notice falls due, as an interval of elapsed time.
     */
    public function lead(): string
    {
        return match ($this) {
            self::ThreeDaysLeft => 'PT72H',
            self::EndsToday => 'PT24H',
        };
    }
}
