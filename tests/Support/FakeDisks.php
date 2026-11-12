<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\Filesystem as Files;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * The suite's fake disks: Storage::fake() writes under storage/framework/testing/disks, one
 * directory per disk and per parallel worker, and removes nothing after a test, so a run left the
 * directory behind whichever faking file ran last. Every fake is made here and registered as it
 * is made, and the hook the suites' extends call in tests/Pest.php removes each after the test
 * and then the disks directory once it is empty, so no run leaves it, serial or parallel.
 *
 * The parallel workers share that one disks directory, and a worker's removing it while another's
 * fake creates its own directory under it would fail that fake with no directory to write to. So
 * a fake and a removal each run under one exclusive lock on the testing directory's .gitignore, a
 * file every checkout carries; the lock is held for a directory's creation or removal alone. A
 * test therefore fakes through fake() and never through Storage::fake() itself, which
 * tests/Feature/FakeDisksTest.php holds over the suite.
 */
final class FakeDisks
{
    /** @var list<string> */
    private static array $roots = [];

    /** Fakes the disk as Storage::fake() does, its root registered for removal after the test. */
    public static function fake(string $disk): Filesystem
    {
        return self::locked(function () use ($disk): Filesystem {
            $fake = Storage::fake($disk);
            self::$roots[] = rtrim($fake->path(''), '/');

            return $fake;
        });
    }

    /**
     * Removes the root of every disk faked since the last removal, then the disks directory where
     * no root stands under it any more, another worker's included.
     */
    public static function removeAll(): void
    {
        if (self::$roots === []) {
            return;
        }

        self::locked(function (): void {
            $files = new Files;

            foreach (array_unique(self::$roots) as $root) {
                $files->deleteDirectory($root);
            }

            foreach (array_unique(array_map(dirname(...), self::$roots)) as $parent) {
                if (is_dir($parent) && (scandir($parent) ?: []) === ['.', '..']) {
                    rmdir($parent);
                }
            }

            self::$roots = [];
        });
    }

    /**
     * Runs the step under the exclusive lock the suite's workers share, so the disks directory
     * stands still while it runs: tests/Feature/FakeDisksTest.php reads the directory under it.
     *
     * @template T
     *
     * @param  callable(): T  $step
     * @return T
     */
    public static function locked(callable $step): mixed
    {
        $lock = fopen(storage_path('framework/testing/.gitignore'), 'r');

        if ($lock === false || ! flock($lock, LOCK_EX)) {
            throw new RuntimeException('The fake disks\' lock could not be taken.');
        }

        try {
            return $step();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
