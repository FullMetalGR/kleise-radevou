<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Infrastructure\Db\SqlState;
use App\Platform\TenantDrop;
use App\Retention\RetentionLine;
use App\Retention\RetentionSweep;
use App\Tenancy\Tenant;
use Closure;
use Illuminate\Console\Command;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The retention sweep: every line of the retention table, in RetentionSweep's order, each one
 * statement in its own transaction on the application's clock. The central lines run once; then,
 * for every tenant whose schema stands built, Tenant::built(), in id order, each tenant line runs
 * over that tenant's schema. A provisioning row has no schema yet, or one inside a build that has
 * not committed, and a closed row's schema was dropped; a suspended or closing tenant is swept like
 * an active one until its schema is dropped, since its schema holds the same people, as
 * MigrateTenantsCommand and the hold sweep read the set. Then the stranded rows' lines run once
 * each: the lapsed subscribers closed through the one writer of a close, a row each in a
 * transaction of its own, and the checkout records past their 24 hours cleared. Last runs the drop,
 * App\Platform\TenantDrop: every tenant thirty days past its closing, each in a transaction of its
 * own, in id order.
 *
 * Each line that commits is logged by its table and its count, a tenant line by the tenant's slug
 * too, the handle the hold sweep's lines and the messaging seam's use, and nothing of a row. A
 * line that throws is logged once, by its table, the tenant where it is a tenant line, the
 * exception's class and its SQLSTATE where it carries one, never its message, which spells the
 * statement and may spell a row; the run stops there, the earlier lines' work standing, since
 * each committed on its own, and the command fails. A tenant dropped is logged by its id, the
 * schema dropped and the count of each central table's rows deleted with it, its slug being
 * released; a drop that throws is logged by the tenant's id as a line that throws is, its
 * transaction rolled back whole and the tenants dropped before it standing. The next day's run starts again from the
 * first line, which finds nothing a second time, and so picks up where this one left.
 */
final class RetentionSweepCommand extends Command
{
    /** The table the drop's line is logged by: the one row of the tenant it keeps, written closed. */
    public const string DROPPED = 'tenants';

    protected $signature = 'retention:sweep';

    protected $description = 'Apply the retention table: delete or null every row past its period, the central lines once and the tenant lines in every built schema, one statement per line in its own transaction, then close every lapsed subscriber thirty days past its last period and clear every checkout record past its 24 hours, then drop every tenant thirty days past its closing, logging each table with its count. Scheduled daily at 04:30 Europe/Athens.';

    public function handle(RetentionSweep $sweep, TenantDrop $drop, LoggerInterface $log): int
    {
        if (! $this->sweptOnce($log, $sweep->lines())) {
            return self::FAILURE;
        }

        $tenants = Tenant::built()->get();

        foreach ($tenants as $tenant) {
            foreach ($sweep->tenantLines() as $line) {
                $swept = $this->swept(
                    $log,
                    ['tenant' => $tenant->slug],
                    $line->table(),
                    $tenant->schema_name.'.'.$line->table(),
                    fn (): int => $line->apply($tenant),
                );

                if (! $swept) {
                    return self::FAILURE;
                }
            }
        }

        if (! $this->sweptOnce($log, $sweep->strandedLines())) {
            return self::FAILURE;
        }

        foreach ($drop->due() as $tenantId) {
            if (! $this->dropped($log, $drop, $tenantId)) {
                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }

    /**
     * Runs each line given once, in order, and answers whether the run goes on past them.
     *
     * @param  list<RetentionLine>  $lines
     */
    private function sweptOnce(LoggerInterface $log, array $lines): bool
    {
        foreach ($lines as $line) {
            if (! $this->swept($log, [], $line->table(), $line->table(), $line->apply(...))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Drops one tenant, logs and prints what went, and answers whether the run goes on. A row no
     * longer due once locked, reopened in between, drops nothing and says nothing.
     */
    private function dropped(LoggerInterface $log, TenantDrop $drop, int $tenantId): bool
    {
        try {
            $dropped = $drop->drop($tenantId);
        } catch (Throwable $failure) {
            $log->error('The retention sweep stopped', [
                'tenant' => $tenantId,
                'table' => self::DROPPED,
                'exception' => $failure::class,
                'sqlstate' => SqlState::codeOf($failure),
            ]);
            $this->error(sprintf('Tenant %d: the drop failed, and the sweep stopped.', $tenantId));

            return false;
        }

        if ($dropped === null) {
            return true;
        }

        $log->info('Retention sweep', ['tenant' => $tenantId, 'table' => self::DROPPED, 'schema' => $dropped->schema, ...$dropped->counts()]);
        $this->info(sprintf(
            '%s: dropped, tenant %d closed; %d users, %d payment routes, %d support sessions and %d webhook events deleted.',
            $dropped->schema,
            $tenantId,
            $dropped->users,
            $dropped->paymentRoutes,
            $dropped->supportSessions,
            $dropped->webhookEvents,
        ));

        return true;
    }

    /**
     * Runs one line, logs and prints what it did, and answers whether the run goes on.
     *
     * @param  array<string, string>  $where  the tenant a tenant line ran over, nothing for a central line
     * @param  string  $printed  the table as the output names it, qualified by its schema for a tenant line
     * @param  Closure(): int  $apply
     */
    private function swept(LoggerInterface $log, array $where, string $table, string $printed, Closure $apply): bool
    {
        try {
            $count = $apply();
        } catch (Throwable $failure) {
            $log->error('The retention sweep stopped', [
                ...$where,
                'table' => $table,
                'exception' => $failure::class,
                'sqlstate' => SqlState::codeOf($failure),
            ]);
            $this->error(sprintf('%s: the line failed, and the sweep stopped.', $printed));

            return false;
        }

        $log->info('Retention sweep', [...$where, 'table' => $table, 'count' => $count]);
        $this->info(sprintf('%s: %d %s swept.', $printed, $count, $count === 1 ? 'row' : 'rows'));

        return true;
    }
}
