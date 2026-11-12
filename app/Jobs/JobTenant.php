<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Tenancy\Tenant;
use App\Tenancy\TenantStatus;
use Psr\Log\LoggerInterface;

/**
 * The tenant a queued job acts for, read again by its id where the job runs, in one home for every
 * job under app/Jobs. Every job re-reads before it acts, and the tenant first: a job is dispatched
 * optimistically, and the tenant it names may since have been closed and dropped by the retention
 * sweep's last line, or its row given back. A closed row is found and its schema is gone, so a job
 * reading on would throw on the first statement in it; a job queued for a dropped tenant's future
 * booking, its reminder among them, meets exactly that.
 *
 * So a tenant closed or gone ends the job here, quietly: nothing is sent, nothing is retried and
 * nothing is an error, since a drop racing a queued message is an ordinary day rather than a fault.
 * It says so once, at info, naming the job and the tenant's id, the one handle a closed row keeps
 * that names it, and nothing of a row.
 */
final readonly class JobTenant
{
    public const string CLOSED = 'A queued job found its tenant closed and ended without acting';

    public const string GONE = 'A queued job found no row of its tenant and ended without acting';

    /**
     * The tenant of the id given, or null where its row is closed or gone, the line written before
     * the answer.
     *
     * @param  class-string  $job  the job asking, as the line names it
     */
    public static function find(int $tenantId, string $job, LoggerInterface $log): ?Tenant
    {
        $tenant = Tenant::query()->find($tenantId);

        if ($tenant instanceof Tenant && $tenant->status !== TenantStatus::Closed) {
            return $tenant;
        }

        $log->info($tenant instanceof Tenant ? self::CLOSED : self::GONE, ['tenant' => $tenantId, 'job' => $job]);

        return null;
    }
}
