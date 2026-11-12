<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/**
 * The temporary files a test's fixtures write: each made here and registered as it is made, and
 * every one removed after the test by the hook the suites' extends call in tests/Pest.php, so a
 * run leaves the temporary directory as it found it. A file a test moved away is already gone and
 * is passed over.
 *
 * The registry is the process's, so it holds a file the browser plugin's in-process server writes
 * for an upload (MultipartBodies) as it holds a test's own. A fixture is made inside the test that
 * uses it, never while a file's datasets are collected, since a file made then would be removed
 * after the first test of the run and missing from its own.
 */
final class TemporaryFiles
{
    /** @var list<string> */
    private static array $made = [];

    /**
     * A new empty file under the system's temporary directory, its name led by the prefix and
     * ended by the suffix, as tempnam() names one. Where a suffix is given the name tempnam()
     * reserved is registered beside it, since tempnam() creates that file too.
     */
    public static function make(string $prefix, string $suffix = ''): string
    {
        $reserved = tempnam(sys_get_temp_dir(), $prefix);

        if ($reserved === false) {
            throw new RuntimeException('No temporary file could be made.');
        }

        self::$made[] = $reserved;

        if ($suffix === '') {
            return $reserved;
        }

        self::$made[] = $reserved.$suffix;

        return $reserved.$suffix;
    }

    /**
     * Removes every file made since the last removal, the ones already gone passed over.
     */
    public static function removeAll(): void
    {
        foreach (self::$made as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        self::$made = [];
    }
}
