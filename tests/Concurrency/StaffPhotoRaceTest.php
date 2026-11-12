<?php

use App\Application\Checkpoint;
use App\Infrastructure\Db\SqlText;
use App\Infrastructure\Db\StaffRecords;
use App\Infrastructure\Db\StaffReferenced;
use App\Infrastructure\Storage\TenantImages;
use App\Tenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\File\File as StoredFile;
use Tests\Concurrency\Harness\ConcurrentRun;
use Tests\Concurrency\Harness\Outcome;

/*
 * The staff photo's two races, closed by StaffRecords: every staff photo write locks the member's
 * row and then the tenant's staff photo lock, and under both stores its file, writes its row,
 * decides whether the file it let go is named by any row and deletes it if not. Two members given
 * the same photo hold one file under one name, so a removal or a delete of one member and an
 * upload of the same photo for another meet on that file; an upload and a delete of one member
 * meet on the row.
 *
 * On acme, provisioned for real and dropped afterwards, with two named members. The disk is a
 * directory of the test's own, which every worker points the public disk at before it writes, so
 * parent and workers read one disk; it is removed after each test. Each race case runs both
 * orders, the first write standing at PHOTO_SETTLED, its writes done and not committed, while the
 * second starts and is seen waiting on a lock, then the first committing. Two cases follow them:
 * a hold's item on a member lands while that member's delete waits on the photo lock, which the
 * delete takes before its own delete statement, so no hold waits on a photo write; and the photo
 * lock lasts the transaction that took it and no longer, as the sweeper's is pinned to (the
 * feature suite's ExpireHoldsTest), read in this file since this suite wraps no test in a
 * transaction.
 *
 * Worker closures run in another process and call only autoloaded code and the framework.
 */

beforeEach(function () {
    DB::statement('drop schema if exists tenant_acme cascade');
    provisionTenant('acme');
    $this->elena = DB::table('tenant_acme.staff')->insertGetId(['name' => 'Elena Papadaki', 'status' => 'active', 'sort' => 1]);
    $this->nikos = DB::table('tenant_acme.staff')->insertGetId(['name' => 'Nikos Georgiou', 'status' => 'active', 'sort' => 2]);
    $this->root = storage_path('framework/testing/staff-photo-race-'.getmypid());
    File::ensureDirectoryExists($this->root);
    racePhotoDisk($this->root);
});

afterEach(function () {
    ConcurrentRun::abandonAll();
    File::deleteDirectory($this->root);
    DB::statement('drop schema if exists tenant_acme cascade');
});

/**
 * Points this process's public disk at the test's directory, as each worker does for itself.
 */
function racePhotoDisk(string $root): void
{
    config()->set('filesystems.disks.'.TenantImages::DISK.'.root', $root);
    Storage::forgetDisk(TenantImages::DISK);
}

function racePhotoTenant(): Tenant
{
    return Tenant::query()->where('slug', 'acme')->sole();
}

/**
 * A worker that stores the photo at the source path for a member, or removes theirs where the
 * source is null, after the gate; it answers whether the member was found.
 */
function racePhotoSwapWorker(string $root, int $staffId, ?string $source, string $gate): Closure
{
    return function () use ($root, $staffId, $source, $gate): array {
        config()->set('filesystems.disks.'.TenantImages::DISK.'.root', $root);
        Storage::forgetDisk(TenantImages::DISK);
        Checkpoint::reach($gate);
        $tenant = Tenant::query()->where('slug', 'acme')->sole();

        return ['found' => app(StaffRecords::class)->swapPhoto($tenant, $staffId, $source === null ? null : new StoredFile($source))];
    };
}

/**
 * A worker that deletes a member, photo and all, after the gate.
 */
function racePhotoDeleteWorker(string $root, int $staffId, string $gate): Closure
{
    return function () use ($root, $staffId, $gate): array {
        config()->set('filesystems.disks.'.TenantImages::DISK.'.root', $root);
        Storage::forgetDisk(TenantImages::DISK);
        Checkpoint::reach($gate);
        $tenant = Tenant::query()->where('slug', 'acme')->sole();

        return ['found' => app(StaffRecords::class)->delete($tenant, $staffId)];
    };
}

/**
 * A worker that, after the gate, inserts a pending booking and its item naming the member and
 * commits at once: the item's foreign-key check takes the key share on the member's row that a
 * hold's item insert takes. The instants are the test's, dated from today.
 */
function racePhotoItemWorker(int $serviceId, int $staffId, string $start, string $end, string $gate): Closure
{
    return function () use ($serviceId, $staffId, $start, $end, $gate): array {
        Checkpoint::reach($gate);

        return DB::transaction(function () use ($serviceId, $staffId, $start, $end): array {
            $bookingId = DB::table('tenant_acme.bookings')->insertGetId([
                'source' => 'embed',
                'uuid' => (string) Str::uuid(), 'status' => 'pending', 'customer_name' => 'Casey Customer', 'customer_email' => 'casey@example.com',
                'total_minor' => 2500, 'currency' => 'EUR', 'payment_mode' => 'onsite', 'requires_approval' => false, 'manage_token_hash' => 'hash',
            ]);
            DB::table('tenant_acme.booking_items')->insert([
                'booking_id' => $bookingId, 'sort' => 0, 'service_id' => $serviceId, 'staff_id' => $staffId,
                'start_utc' => $start, 'end_utc' => $end, 'block_start_utc' => $start, 'block_end_utc' => $end, 'price_minor' => 2500,
            ]);

            return ['booking' => $bookingId];
        });
    };
}

/**
 * The photo path a member's row holds, null for none or for no such row.
 */
function racePhotoOf(int $staffId): ?string
{
    $path = DB::table('tenant_acme.staff')->where('id', $staffId)->value('photo_path');

    return is_string($path) ? $path : null;
}

/**
 * The paths the rows name that the disk lacks. The stat cache is cleared first: this process may
 * have stat'ed a path a worker has since deleted.
 *
 * @return list<string>
 */
function racePhotoDangling(string $root): array
{
    clearstatcache();
    $paths = array_filter(DB::table('tenant_acme.staff')->whereNotNull('photo_path')->pluck('photo_path')->all(), is_string(...));

    return array_values(array_filter($paths, fn (string $path): bool => ! is_file($root.'/'.$path)));
}

/**
 * Whether this session holds the tenant's staff photo lock, as the lock view spells a two-integer
 * advisory key: the class in classid, the tenant's id in objid, objsubid 2.
 */
function racePhotoLockHeld(Tenant $tenant): bool
{
    $held = DB::scalar(
        "select count(*) from pg_locks where locktype = 'advisory' and pid = pg_backend_pid() and classid = ? and objid = ? and objsubid = 2",
        [StaffRecords::PHOTO_LOCK_CLASS, $tenant->id],
    );

    assert(is_int($held));

    return $held > 0;
}

/**
 * The files on the disk, relative to its root.
 *
 * @return list<string>
 */
function racePhotoFiles(string $root): array
{
    return array_values(array_map(fn (SplFileInfo $file): string => $file->getRelativePathname(), File::allFiles($root)));
}

/**
 * Runs two named workers in the order given: the first released and awaited at PHOTO_SETTLED,
 * its writes done and uncommitted, then the second released from its gate and seen waiting on a
 * lock in a statement beginning with the prefix, then the first let go. Answers every outcome,
 * the wait seen, and whether the second finished while the first stood.
 *
 * @param  array<string, Closure>  $workers  each gated on its own name plus ".start"
 * @return array{outcomes: array<string, Outcome>, waiting: array{pid: int, wait_event: string}, finishedEarly: bool}
 */
function racePhotoInOrder(array $workers, string $first, string $second, string $waitingOn): array
{
    $run = ConcurrentRun::of($workers)->holding(StaffRecords::PHOTO_SETTLED, $first.'.start', $second.'.start')->start();
    $run->release();
    $run->releaseCheckpoint($first.'.start');
    $run->awaitCheckpoint($first, StaffRecords::PHOTO_SETTLED);
    $run->releaseCheckpoint($second.'.start');
    $waiting = lockWaiter($waitingOn);
    $finishedEarly = $run->hasOutcome($second);
    $run->releaseCheckpoint(StaffRecords::PHOTO_SETTLED);

    return ['outcomes' => $run->wait(), 'waiting' => $waiting, 'finishedEarly' => $finishedEarly];
}

it('leaves no row naming a missing file when an upload of a photo another member holds meets that member\'s removal or delete, whichever lands first', function (string $forget, string $first) {
    $tenant = racePhotoTenant();
    $source = drawnPng(40, 40, 200, 40, 40);
    expect(app(StaffRecords::class)->swapPhoto($tenant, $this->nikos, new StoredFile($source)))->toBeTrue();
    $shared = racePhotoOf($this->nikos);
    assert(is_string($shared));

    $result = racePhotoInOrder([
        'upload' => racePhotoSwapWorker($this->root, $this->elena, $source, 'upload.start'),
        'forget' => $forget === 'removal'
            ? racePhotoSwapWorker($this->root, $this->nikos, null, 'forget.start')
            : racePhotoDeleteWorker($this->root, $this->nikos, 'forget.start'),
    ], $first, $first === 'upload' ? 'forget' : 'upload', 'select pg_advisory_xact_lock');

    expect($result['waiting']['wait_event'])->toBe('advisory')
        ->and($result['finishedEarly'])->toBeFalse()
        ->and(returnedArray($result['outcomes']['upload']))->toBe(['found' => true])
        ->and(returnedArray($result['outcomes']['forget']))->toBe(['found' => true])
        ->and(racePhotoOf($this->elena))->toBe($shared)
        ->and(racePhotoDangling($this->root))->toBe([])
        ->and(racePhotoFiles($this->root))->toBe([$shared]);

    if ($forget === 'removal') {
        expect(racePhotoOf($this->nikos))->toBeNull();
    } else {
        expect(DB::table('tenant_acme.staff')->where('id', $this->nikos)->exists())->toBeFalse();
    }
})->with(['removal', 'delete'])->with(['upload', 'forget']);

it('leaves no file behind when an upload for a member meets their delete, whichever lands first: the delete forgets the photo the upload stored, or the upload finds no member and stores nothing', function (string $first) {
    $tenant = racePhotoTenant();
    expect(app(StaffRecords::class)->swapPhoto($tenant, $this->nikos, new StoredFile(drawnPng(40, 40, 200, 40, 40))))->toBeTrue();
    $held = racePhotoOf($this->nikos);
    assert(is_string($held));

    $result = racePhotoInOrder([
        'upload' => racePhotoSwapWorker($this->root, $this->nikos, drawnPng(40, 40, 40, 40, 200), 'upload.start'),
        'delete' => racePhotoDeleteWorker($this->root, $this->nikos, 'delete.start'),
    ], $first, $first === 'upload' ? 'delete' : 'upload', $first === 'upload' ? 'select "is_implicit", "photo_path"' : 'select "photo_path"');

    expect($result['waiting']['wait_event'])->toBeIn(['transactionid', 'tuple'])
        ->and($result['finishedEarly'])->toBeFalse()
        ->and(returnedArray($result['outcomes']['upload']))->toBe(['found' => $first === 'upload'])
        ->and(returnedArray($result['outcomes']['delete']))->toBe(['found' => true])
        ->and(DB::table('tenant_acme.staff')->where('id', $this->nikos)->exists())->toBeFalse()
        ->and(racePhotoFiles($this->root))->toBe([]);
})->with(['upload', 'delete']);

it('lets a hold\'s item on a member land while that member\'s delete waits on the staff photo lock behind another member\'s upload, the delete taking the lock before its own delete statement, and refuses the delete once the item has committed', function () {
    $tenant = racePhotoTenant();
    $cut = DB::table('tenant_acme.services')->insertGetId(serviceRow('Cut', ['duration_min' => 60, 'price_minor' => 2500]));
    expect(app(StaffRecords::class)->swapPhoto($tenant, $this->nikos, new StoredFile(drawnPng(40, 40, 200, 40, 40))))->toBeTrue();
    $held = racePhotoOf($this->nikos);
    assert(is_string($held));
    $start = SqlText::instant(athensDaysOn(1, 9));
    $end = SqlText::instant(athensDaysOn(1, 10));

    $run = ConcurrentRun::of([
        'upload' => racePhotoSwapWorker($this->root, $this->elena, drawnPng(40, 40, 40, 40, 200), 'upload.start'),
        'delete' => racePhotoDeleteWorker($this->root, $this->nikos, 'delete.start'),
        'item' => racePhotoItemWorker($cut, $this->nikos, $start, $end, 'item.start'),
    ])->holding(StaffRecords::PHOTO_SETTLED, 'upload.start', 'delete.start', 'item.start')->start();
    $run->release();
    $run->releaseCheckpoint('upload.start');
    $run->awaitCheckpoint('upload', StaffRecords::PHOTO_SETTLED);
    $run->releaseCheckpoint('delete.start');
    $waiting = lockWaiter('select pg_advisory_xact_lock');
    $run->releaseCheckpoint('item.start');
    $item = returnedArray($run->outcomeOf('item'));
    $deleteFinishedEarly = $run->hasOutcome('delete');
    $run->releaseCheckpoint(StaffRecords::PHOTO_SETTLED);
    $outcomes = $run->wait();

    expect($waiting['wait_event'])->toBe('advisory')
        ->and($item)->toHaveKey('booking')
        ->and($deleteFinishedEarly)->toBeFalse()
        ->and(returnedArray($outcomes['upload']))->toBe(['found' => true])
        ->and($outcomes['delete']->exceptionClass)->toBe(StaffReferenced::class)
        ->and(racePhotoOf($this->nikos))->toBe($held)
        ->and(DB::table('tenant_acme.booking_items')->where('staff_id', $this->nikos)->count())->toBe(1)
        ->and(racePhotoDangling($this->root))->toBe([]);
});

it('takes the staff photo lock for the transaction alone: held while a transaction around a photo write stands, and by no session once an upload, a removal or a delete has returned', function () {
    $tenant = racePhotoTenant();
    $records = app(StaffRecords::class);

    $heldInside = DB::transaction(function () use ($records, $tenant): bool {
        $records->swapPhoto($tenant, $this->elena, new StoredFile(drawnPng(40, 40, 200, 40, 40)));

        return racePhotoLockHeld($tenant);
    });

    expect($heldInside)->toBeTrue()
        ->and(racePhotoLockHeld($tenant))->toBeFalse();

    $records->swapPhoto($tenant, $this->nikos, new StoredFile(drawnPng(40, 40, 40, 40, 200)));
    expect(racePhotoLockHeld($tenant))->toBeFalse();

    $records->swapPhoto($tenant, $this->nikos, null);
    expect(racePhotoLockHeld($tenant))->toBeFalse();

    expect($records->delete($tenant, $this->elena))->toBeTrue()
        ->and(racePhotoLockHeld($tenant))->toBeFalse();
});
