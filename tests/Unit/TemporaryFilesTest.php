<?php

use Tests\Support\TemporaryFiles;

/*
 * The temporary files a fixture makes: each registered as it is made and all removed together,
 * which the extends in tests/Pest.php do after every test of the suites that boot the
 * application.
 */

it('makes a file under the temporary directory led by the prefix, and removes it with the name tempnam reserved beside a suffix', function () {
    $plain = TemporaryFiles::make('kr-unit');
    $suffixed = TemporaryFiles::make('kr-unit', '.png');
    file_put_contents($suffixed, 'png');
    $made = [$plain, $suffixed, substr($suffixed, 0, -4)];

    expect(array_map(is_file(...), $made))->toBe([true, true, true])
        ->and(realpath(dirname($plain)))->toBe(realpath(sys_get_temp_dir()))
        ->and(basename($plain))->toStartWith('kr-unit')
        ->and($suffixed)->toEndWith('.png');

    TemporaryFiles::removeAll();

    expect(array_map(is_file(...), $made))->toBe([false, false, false]);
});

it('passes over a file the test moved away, and forgets what it removed', function () {
    $moved = TemporaryFiles::make('kr-unit');
    $kept = TemporaryFiles::make('kr-unit');
    unlink($moved);

    TemporaryFiles::removeAll();
    file_put_contents($kept, 'made again by someone else');
    TemporaryFiles::removeAll();

    expect(is_file($kept))->toBeTrue();

    unlink($kept);
});
