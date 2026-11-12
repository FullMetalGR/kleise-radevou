<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Billing\SubscriptionSync;
use App\Billing\TrialDeadlines;
use App\Notifications\AccountClosing;
use Illuminate\Console\Command;

/**
 * Grace running out: every tenant in grace whose window has ended is lapsed to past_due by the
 * subscription sync, the one writer of that column. The gate then reads one column and never
 * compares a date, so a window ends when this runs and not before. Beside it, in a statement of
 * its own, every trial whose end has passed lands canceled, frozen from then on as any canceled
 * tenant. In a third statement every active tenant whose trial ended unpaid thirty days or more
 * ago is closed, and its owners are sent the closing email through the platform's seam once that
 * statement has committed.
 *
 * A lapsed tenant loses configuration writes and nothing else, and a closing one keeps answering
 * as it did. Nothing here touches a booking, and nothing here drops anything.
 */
final class LapseGraceCommand extends Command
{
    protected $signature = 'billing:lapse';

    protected $description = 'Lapse every tenant whose grace window has run out and end every trial whose end has passed, freezing their configuration writes, and close every account thirty days past a trial nobody paid for. Scheduled every hour.';

    public function handle(SubscriptionSync $sync, TrialDeadlines $deadlines, AccountClosing $closing): int
    {
        $lapsed = $sync->lapseExpired();

        $this->info(sprintf('Lapsed %d tenant%s.', $lapsed, $lapsed === 1 ? '' : 's'));

        $ended = $sync->lapseTrials();

        $this->info(sprintf('Ended %d trial%s.', $ended, $ended === 1 ? '' : 's'));

        $closed = $deadlines->close();

        foreach ($closed as $account) {
            $closing->send($account);
        }

        $this->info(sprintf('Closed %d account%s.', count($closed), count($closed) === 1 ? '' : 's'));

        return self::SUCCESS;
    }
}
