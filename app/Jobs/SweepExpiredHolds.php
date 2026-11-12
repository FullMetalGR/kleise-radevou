<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Infrastructure\ExpireHoldsFactory;
use App\Tenancy\Tenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * One tenant's sweep, on the queue. Queues, not cron loops: the schedule dispatches and the worker
 * runs, so a slow tenant delays nobody and the minute's dispatch never waits on a database.
 *
 * The tenant is carried as its central id and read again here, because every job re-reads before
 * it acts. A tenant dropped or gone between the dispatch and the run is not an error; JobTenant,
 * every job's one reading of its tenant, ends the job quietly with one line at info. A suspended
 * or closing tenant is swept, its schema standing.
 *
 * **A failure on one tenant is logged and swallowed.** Nothing is retried: the schedule dispatches
 * this again in sixty seconds, and the rows it did not reach are free by time in the meantime, so
 * a retry would only put a second sweep of the same tenant on the queue for the advisory lock to
 * turn away. The failure stops no other tenant, because every tenant has a job of its own.
 *
 * **The number of jobs a minute grows with the tenant count.** One dispatch per tenant per minute
 * is a thousand jobs a minute at a thousand tenants, most of them finding nothing. That is not a
 * v1 problem and is deliberately not solved here; the shapes that solve it, sweeping only the
 * tenants whose next expiry has passed or sharding tenants across the minute, both need a
 * per-tenant timer this build has no use for yet.
 */
final class SweepExpiredHolds implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $tenantId) {}

    public function handle(ExpireHoldsFactory $sweeps, LoggerInterface $log): void
    {
        $tenant = JobTenant::find($this->tenantId, self::class, $log);

        if (! $tenant instanceof Tenant) {
            return;
        }

        try {
            $swept = $sweeps->for($tenant)->sweep();
        } catch (Throwable $failure) {
            // The trace goes in it. Nothing retries this and nothing reaches failed_jobs, so
            // this line is the only account of the failure there will ever be, and a class and
            // a message alone would say that a sweep failed without saying where.
            $log->error('The hold sweep failed', [
                'tenant' => $tenant->slug,
                'exception' => $failure::class,
                'message' => $failure->getMessage(),
                'trace' => $failure->getTraceAsString(),
            ]);

            return;
        }

        // A sweep that found nothing says nothing: this runs every minute for every tenant and
        // the quiet answer is the usual one. A sweep that stood down is worth a line, because a
        // tenant whose sweeps always stand down has one that never finishes.
        if (! $swept->ran) {
            $log->info('The hold sweep stood down', ['tenant' => $tenant->slug]);

            return;
        }

        if ($swept->touched() > 0) {
            $log->info('Holds swept', [
                'tenant' => $tenant->slug,
                'expired' => $swept->expired,
                'confirmed' => $swept->confirmed,
                'batches' => $swept->batches,
            ]);
        }
    }
}
