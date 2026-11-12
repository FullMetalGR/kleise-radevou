<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\ParallelTesting;
use LogicException;
use Tests\Support\WorkerDatabase;

abstract class TestCase extends BaseTestCase
{
    /**
     * The keys this test asks for although no language holds them, named through allowMissingKey.
     *
     * @var list<string>
     */
    private array $missingKeysAllowed = [];

    /**
     * Every application a test creates carries the worker-database hooks beside the framework's,
     * which the framework calls only under the parallel run: the switch once the test's
     * application stands, the release as the test tears down; see WorkerDatabase.
     *
     * @return Application
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        ParallelTesting::setUpTestCase(WorkerDatabase::switch(...));
        ParallelTesting::tearDownTestCase(WorkerDatabase::release(...));

        return $app;
    }

    /**
     * The strict missing-key hook: a file key no language holds, the fallback included, fails the
     * test that asked for it, since the translator calls the hook only once every locale has
     * missed. A sentence used as its own key, the framework's error pages' among them, holds a
     * space and is no file key, so it passes, and so do the validator's validation.* lookups, which
     * read an absent key as none on purpose.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Lang::handleMissingKeysUsing(function (string $key, array $replace, ?string $locale): string {
            if (preg_match('/\s/', $key) === 1 || ! str_contains($key, '.') || str_starts_with($key, 'validation.') || in_array($key, $this->missingKeysAllowed, true)) {
                return $key;
            }

            throw new LogicException(sprintf('No language holds the key %s (asked in %s).', $key, $locale ?? 'the default locale'));
        });
    }

    /**
     * Lets this test ask for each key no language holds, a reader answering the key itself.
     */
    protected function allowMissingKey(string ...$keys): void
    {
        array_push($this->missingKeysAllowed, ...$keys);
    }
}
