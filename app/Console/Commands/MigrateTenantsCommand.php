<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Tenancy\SchemaSwitch;
use App\Tenancy\Tenant;
use App\Tenancy\TenantMigrator;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Throwable;

/**
 * How a built schema takes a tenant migration that landed after its build: the tenant migrator, run
 * inside each schema as the build runs it, over every tenant whose row is neither provisioning nor
 * closed, or over the one --slug names.
 *
 * A row still provisioning is skipped because it has no schema yet, or has one only inside a build
 * that has not committed, and its build runs every migration there is when it does. A closed row is
 * skipped with it, since the drop removed its schema: the set is Tenant::built(), the one every
 * tenant loop reads. Suspended and closing tenants are migrated like active ones: their schemas are
 * live data that a reopening or a lifted suspension serves again, and a schema left behind the set
 * is one the next migration meets in a shape it was not written for.
 *
 * One schema at a time, each in its own transaction, the search path switched inside it by
 * SchemaSwitch, the one switch the build runs its migrations through too: PostgreSQL DDL is
 * transactional, so a migration that fails rolls its schema back whole, every migration it ran in
 * this run with it, and the run stops there with that schema named, the schemas before it migrated
 * and the ones after it untouched. A migration taking ACCESS EXCLUSIVE fails fast under
 * ExclusiveStep's lock timeout rather than wait behind a long reader, and stops the run the same
 * way. Running it twice is harmless: a schema whose ran list holds every migration runs nothing
 * and reports itself up to date.
 *
 * Run by hand, at a deploy, and scheduled nowhere: a migration is a deliberate act, and one
 * touching booking_items needs a written plan before it runs.
 */
final class MigrateTenantsCommand extends Command
{
    protected $signature = 'tenant:migrate
        {--slug= : Migrate the one tenant this slug names rather than every built schema}';

    protected $description = 'Run every pending tenant migration in each built tenant schema, the tenants whose row is neither provisioning nor closed, one schema at a time and each in its own transaction. Prints one line per schema, naming what ran or that it was up to date, and stops at the first failure with that schema rolled back. Safe to run twice.';

    public function handle(TenantMigrator $migrator, DatabaseManager $db): int
    {
        $slug = $this->option('slug');
        $query = Tenant::built();

        if (is_string($slug) && $slug !== '') {
            $query->where('slug', strtolower($slug));
        }

        $tenants = $query->get();

        if (is_string($slug) && $slug !== '' && $tenants->isEmpty()) {
            $this->error(sprintf('No built tenant has the slug "%s"; a row still provisioning takes every migration when it is built.', $slug));

            return self::INVALID;
        }

        foreach ($tenants as $tenant) {
            $schema = $tenant->schema_name;

            try {
                $ran = $this->migrate($db->connection(), $migrator, $schema);
            } catch (Throwable $failure) {
                $this->error(sprintf('%s: failed, rolled back, and the run stopped here: %s', $schema, $failure->getMessage()));

                return self::FAILURE;
            }

            $this->line($ran === []
                ? sprintf('%s: up to date', $schema)
                : sprintf('%s: ran %s', $schema, implode(', ', $ran)));
        }

        return self::SUCCESS;
    }

    /**
     * The pending migrations of one schema in one transaction, by name, in the order they ran.
     *
     * @return list<string>
     */
    private function migrate(Connection $connection, TenantMigrator $migrator, string $schema): array
    {
        return $connection->transaction(fn (Connection $connection): array => SchemaSwitch::within(
            $connection,
            $schema,
            fn (): array => array_map(fn (string $path): string => basename($path, '.php'), $migrator->migrate($schema)),
        ));
    }
}
