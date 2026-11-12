<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeDisks;

/*
 * The suite's fake disks end with their test: every fake is made through FakeDisks, which the
 * suites' after hook empties, so no run leaves storage/framework/testing/disks behind whichever
 * faking file ran last. The parallel workers share that directory, so the removal case reads it
 * under FakeDisks' own lock, where no other worker's fake or removal can change it, and reads
 * nothing else of theirs: its own root carries its worker's token.
 */

it('fakes a disk through FakeDisks alone, never through the facade itself, anywhere under tests/', function () {
    $faking = [];

    foreach (File::allFiles(base_path('tests')) as $file) {
        if ($file->getExtension() === 'php' && $file->getRelativePathname() !== 'Support/FakeDisks.php' && preg_match('/Storage::(?:fake|persistentFake)\(/', $file->getContents()) === 1) {
            $faking[] = $file->getRelativePathname();
        }
    }

    expect($faking)->toBe([]);
});

it('removes the root of the disk it faked and the disks directory with it once no other root stands there', function () {
    $fake = FakeDisks::fake('fake-disks-probe');
    $fake->put('probe.txt', 'written');
    $root = rtrim($fake->path(''), '/');
    $parent = storage_path('framework/testing/disks');

    expect(is_file($root.'/probe.txt'))->toBeTrue()
        ->and(dirname($root))->toBe($parent)
        ->and(basename($root))->toBe(ParallelTesting::token() === false ? 'fake-disks-probe' : 'fake-disks-probe_test_'.ParallelTesting::token());

    FakeDisks::removeAll();
    [$standing, $others] = FakeDisks::locked(fn (): array => is_dir($parent)
        ? [true, array_values(array_diff(scandir($parent) ?: [], ['.', '..']))]
        : [false, []]);

    expect(is_dir($root))->toBeFalse()
        ->and($standing)->toBe($others !== [])
        ->and(in_array(basename($root), $others, true))->toBeFalse();

    Storage::forgetDisk('fake-disks-probe');
});
