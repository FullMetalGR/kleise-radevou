<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Tenancy\TenantProvisioner;
use Illuminate\Console\Command;

/**
 * Abandoned signups swept: every row still provisioning a day after reserve() created it, its
 * owner unverified, is deleted with its users, which frees the slug. Its verification link
 * answers as expired from then on.
 */
final class SweepSignupsCommand extends Command
{
    protected $signature = 'signup:sweep';

    protected $description = 'Delete every signup row still provisioning and unverified a day after it was reserved, freeing its slug. Scheduled every hour.';

    public function handle(TenantProvisioner $provisioner): int
    {
        $swept = $provisioner->sweepAbandoned();

        $this->info(sprintf('Swept %d abandoned signup%s.', $swept, $swept === 1 ? '' : 's'));

        return self::SUCCESS;
    }
}
