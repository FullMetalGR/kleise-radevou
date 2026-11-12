<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Billing\TrialDeadlines;
use App\Billing\TrialNotice;
use App\Notifications\TrialEnding;
use Illuminate\Console\Command;

/**
 * The trial's two notices: every tenant in trial is told by the platform, through its own seam,
 * when three days or less are left and again on its last day, each once. The notices are
 * claimed in their order, the three days first, so a trial already shorter than three days when
 * this first sees it is sent both, in that order, in one run. Each claim stamps the tenant row
 * in the statement that decides it, and the messages go after it has committed.
 */
final class NotifyTrialsCommand extends Command
{
    protected $signature = 'trial:notify';

    protected $description = 'Send the trial\'s three days left and ends today notices to the owners of every tenant in trial they are due for, each once. Scheduled every hour.';

    public function handle(TrialDeadlines $deadlines, TrialEnding $notices): int
    {
        foreach (TrialNotice::cases() as $notice) {
            $told = $deadlines->notice($notice);

            foreach ($told as $account) {
                $notices->send($account, $notice);
            }

            $this->info(sprintf('Sent the %s notice to %d tenant%s.', $notice->value, count($told), count($told) === 1 ? '' : 's'));
        }

        return self::SUCCESS;
    }
}
