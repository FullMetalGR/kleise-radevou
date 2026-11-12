<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\Concerns\TestDatabases;

/**
 * Under the parallel run every test of a worker runs on that worker's database, whatever traits
 * its file uses. The framework switches a test onto "{DB_DATABASE}_test_{token}" only when the
 * test uses one of the four database traits, so a file using none would stay on the base
 * database while every other test of its worker wrote to the worker's: the after-each nets in
 * tests/Pest.php would read a database nobody wrote, and a case reading the central tables
 * would meet whatever an earlier run left there. switch() moves such a test to the worker's
 * database through the framework's own naming, creation and switch, so the two can never name
 * different databases.
 *
 * release() closes every connection a test opened once the test is done with them. A finished
 * test's application keeps its connections open until PHP's cycle collector frees it, a handful
 * at a time per process, which one serial process never notices and twenty workers turn into
 * more clients than the server admits: the next connection is refused, and the framework reads
 * that refusal as a missing worker database and tries to drop and recreate one in use.
 *
 * Tests\TestCase registers both on every application it creates: switch() after the framework's
 * own set-up hook, release() among the tear-down hooks, which run after the traits' own
 * clean-up, a rolled-back transaction among them, and before the application is flushed. The
 * framework calls them only while the run is parallel; a serial run never reaches either.
 */
final class WorkerDatabase
{
    use TestDatabases;

    private const array TRAITS = [
        DatabaseMigrations::class,
        DatabaseTransactions::class,
        DatabaseTruncation::class,
        RefreshDatabase::class,
    ];

    public static function switch(object $testCase): void
    {
        if (array_intersect(self::TRAITS, class_uses_recursive($testCase)) !== []) {
            return;
        }

        $worker = new self;

        $worker->whenNotUsingInMemoryDatabase(function (string $database) use ($worker): void {
            $worker->ensureTestDatabaseExists($database);

            $worker->switchToDatabase($worker->testDatabase($database));
        });
    }

    public static function release(DatabaseManager $db): void
    {
        foreach (array_keys($db->getConnections()) as $name) {
            $db->disconnect($name);
        }
    }
}
