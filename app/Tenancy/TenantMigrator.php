<?php

declare(strict_types=1);

namespace App\Tenancy;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Migrations\DatabaseMigrationRepository;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Runs the tenant migration set, database/migrations/tenant, inside one tenant's schema.
 *
 * Each schema keeps its own migrations table, so a tenant provisioned after a migration lands and
 * a tenant migrated up to it read the same, and a later "migrate every tenant" command is this
 * class in a loop. The caller has already pointed the connection's search path at the schema;
 * the repository table is named by schema here because the framework's table-exists check reads
 * the configured search path rather than the live one, and would otherwise find the central
 * migrations table and never create the tenant's.
 */
final readonly class TenantMigrator
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private Filesystem $files,
        private Dispatcher $events,
    ) {}

    /**
     * @return list<string> the migrations that ran, in order; empty when the schema was current
     */
    public function migrate(string $schemaName, ?OutputInterface $output = null): array
    {
        $repository = new DatabaseMigrationRepository($this->connections, $schemaName.'.migrations');
        $migrator = new Migrator($repository, $this->connections, $this->files, $this->events);

        if ($output !== null) {
            $migrator->setOutput($output);
        }

        if (! $repository->repositoryExists()) {
            $repository->createRepository();
        }

        return array_values($migrator->run([self::path()]));
    }

    public static function path(): string
    {
        return database_path('migrations/tenant');
    }
}
