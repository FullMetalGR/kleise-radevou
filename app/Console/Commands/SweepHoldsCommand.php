<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SweepExpiredHolds;
use App\Tenancy\Tenant;
use Illuminate\Console\Command;

/**
 * The minute's dispatch: one queued sweep per tenant that has a schema to sweep.
 *
 * It dispatches and does not sweep. The schedule runs in one process and a sweep that ran here
 * would put every tenant behind every other tenant, and a tenant with a bad night behind all of
 * them; on the queue they are independent.
 *
 * Every status but provisioning has a schema: a provisioning row is a signup in flight holding
 * its slug, and its schema is created only on the first successful payment. A suspended tenant is
 * swept although it serves nobody, and a closing one is swept until its schema is dropped,
 * because both may still hold live bookings and a hold that never expires goes on blocking a slot
 * in a diary that may yet reopen. A closed row is the drop's, its schema gone, and is read past
 * with a provisioning one: the set is Tenant::built(), the one every tenant loop reads.
 *
 * A lapsed subscription changes nothing here. The freeze stops configuration writes and never the
 * booking lifecycle, and the reason it is written that way is this job: a frozen sweeper would
 * let held bookings expire unswept and turn paying customers away over an unpaid invoice.
 */
final class SweepHoldsCommand extends Command
{
    protected $signature = 'holds:sweep';

    protected $description = 'Queue one expired-hold sweep for every tenant whose schema exists. Scheduled every minute.';

    public function handle(): int
    {
        $queued = 0;

        foreach (Tenant::built()->cursor() as $tenant) {
            SweepExpiredHolds::dispatch($tenant->id);
            $queued++;
        }

        $this->info(sprintf('Queued %d sweep%s.', $queued, $queued === 1 ? '' : 's'));

        return self::SUCCESS;
    }
}
