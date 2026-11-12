<?php

namespace Tests\Concurrency;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\Concurrency\Harness\ConcurrentRun;
use Tests\TestCase;

/**
 * Migrates once per process and truncates every table between tests.
 *
 * Never wraps a test in a transaction. A wrapped test runs the code under test one
 * transaction deep, which disables the framework's deadlock retry and hides the very
 * behaviour this suite exists to observe. The two traits that would wrap are refused
 * before the application boots, so a file that opts into one fails on its first test.
 */
abstract class ConcurrencyTestCase extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        foreach ([RefreshDatabase::class, DatabaseTransactions::class] as $trait) {
            if (in_array($trait, class_uses_recursive($this), true)) {
                throw new LogicException(sprintf('%s wraps each test in a transaction; the Concurrency suite truncates instead.', $trait));
            }
        }

        parent::setUp();
    }

    /**
     * No worker outlives its test. A run abandoned by a failing assertion would otherwise leave
     * processes blocked on locks this process holds until it exits.
     */
    protected function tearDown(): void
    {
        ConcurrentRun::abandonAll();

        parent::tearDown();
    }
}
