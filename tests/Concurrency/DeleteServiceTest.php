<?php

use App\Application\Checkpoint;
use App\Application\Clock;
use App\Application\Dto\Customer;
use App\Application\Dto\HoldRequest;
use App\Application\Dto\Intake;
use App\Application\Dto\SegmentChoice;
use App\Application\HoldBooking;
use App\Domain\Audit\Actor;
use App\Domain\Enum\BookingSource;
use App\Infrastructure\Db\Pool;
use App\Infrastructure\Db\ServiceRecords;
use App\Infrastructure\Db\ServiceReferenced;
use App\Infrastructure\HoldBookingFactory;
use App\Tenancy\Tenant;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concurrency\Harness\ConcurrentRun;
use Tests\Support\FixedClock;

/*
 * The delete guard of the catalog against a hold: a service any booking item names, in any
 * status, is never deleted, whichever order a delete and a hold interleave in, and no item ever
 * names a service that is gone. The guard is the foreign key on booking_items.service_id: an
 * item insert takes a key-share lock on the services row for its transaction's life, so a delete
 * standing behind an uncommitted item waits and is refused once the item commits, and a delete
 * that lands first refuses the item instead. On acme, provisioned for real and dropped
 * afterwards, with one Cut on the implicit member, who works Monday to Friday 09:00 to 17:00 in
 * Athens; 2026-10-01 is a Thursday, and the clock stands on the day before.
 *
 * Worker closures run in another process and call only autoloaded code and the framework.
 */

beforeEach(function () {
    DB::statement('drop schema if exists tenant_acme cascade');
    provisionTenant('acme');
    $this->cut = DB::table('tenant_acme.services')->insertGetId(serviceRow('Cut', ['duration_min' => 60, 'price_minor' => 2500]));
    DB::table('tenant_acme.service_staff')->insert(['service_id' => $this->cut, 'staff_id' => 1]);
    app()->instance(Clock::class, FixedClock::at('2026-09-30 12:00:00'));
});

afterEach(function () {
    // A worker still standing on a row lock would make the drop wait forever; the base case
    // abandons runs only after these hooks, so abandon here first.
    ConcurrentRun::abandonAll();
    DB::statement('drop schema if exists tenant_acme cascade');
});

/**
 * A worker that inserts what the write protocol inserts for one hold, a pending booking and its
 * item naming the service, and stands on the uncommitted item until the test lets it commit.
 * Raw SQL rather than the use case, because the use case has no seam between its insert and
 * its commit, and what is on trial is the delete against an item another transaction has
 * written and not yet committed; the lock the insert takes is the statement's, not the use
 * case's.
 */
function standingItemWorker(int $serviceId): Closure
{
    return function () use ($serviceId): array {
        DB::beginTransaction();
        $bookingId = DB::table('tenant_acme.bookings')->insertGetId([
            'source' => 'embed',
            'uuid' => (string) Str::uuid(),
            'status' => 'pending',
            'customer_name' => 'Casey Customer',
            'customer_email' => 'casey@example.com',
            'total_minor' => 2500,
            'currency' => 'EUR',
            'payment_mode' => 'onsite',
            'requires_approval' => false,
            'manage_token_hash' => 'hash',
        ]);
        DB::table('tenant_acme.booking_items')->insert([
            'booking_id' => $bookingId,
            'sort' => 0,
            'service_id' => $serviceId,
            'staff_id' => 1,
            'start_utc' => '2026-10-01 09:00:00+00',
            'end_utc' => '2026-10-01 10:00:00+00',
            'block_start_utc' => '2026-10-01 09:00:00+00',
            'block_end_utc' => '2026-10-01 10:00:00+00',
            'price_minor' => 2500,
        ]);
        Checkpoint::reach('item.inserted');
        DB::commit();

        return ['booking' => $bookingId];
    };
}

/**
 * A worker that holds the service on the implicit member through the use case, so the item it
 * inserts is the write protocol's own.
 */
function holdOfServiceWorker(int $serviceId, string $start): Closure
{
    return function () use ($serviceId, $start): array {
        app()->instance(Clock::class, FixedClock::at('2026-09-30 12:00:00'));
        $tenant = Tenant::query()->where('slug', 'acme')->sole();
        $held = app(HoldBookingFactory::class)->for($tenant, Pool::Whole)->hold(new HoldRequest(
            new Customer('Casey Customer', 'casey@example.com'),
            [new SegmentChoice($serviceId, 1)],
            new DateTimeImmutable($start, new DateTimeZone('UTC')),
            new Intake(BookingSource::Embed),
        ), Actor::customer());

        return ['held' => $held->uuid];
    };
}

/**
 * A worker that deletes the service through the catalog's repository once the test lets it
 * start, and reports whether the row existed; a refusal comes back as the exception it threw.
 */
function deleteServiceWorker(int $serviceId, string $gate): Closure
{
    return function () use ($serviceId, $gate): array {
        Checkpoint::reach($gate);
        $tenant = Tenant::query()->where('slug', 'acme')->sole();

        return ['deleted' => app(ServiceRecords::class)->delete($tenant, $serviceId)];
    };
}

/**
 * How many items of acme name a service that is not in its catalog.
 */
function danglingItems(): int
{
    return DB::table('tenant_acme.booking_items as i')
        ->whereNotExists(fn (Builder $services) => $services->from('tenant_acme.services as s')->whereColumn('s.id', 'i.service_id'))
        ->count();
}

it('makes a delete wait behind an item a hold has inserted and not yet committed, seen in the activity view, and refuses it with ServiceReferenced once the item commits: the service still there, the item still naming it', function () {
    $cut = $this->cut;
    $run = ConcurrentRun::of([
        'hold' => standingItemWorker($cut),
        'delete' => deleteServiceWorker($cut, 'delete.start'),
    ])->holding('item.inserted', 'delete.start')->start();
    $run->release();
    $run->awaitCheckpoint('hold', 'item.inserted');
    $run->releaseCheckpoint('delete.start');
    $waiting = lockWaiter('delete from');
    $deleteFinishedWhileHoldStood = $run->hasOutcome('delete');
    $presentWhileHoldStood = DB::table('tenant_acme.services')->where('id', $cut)->exists();
    $run->releaseCheckpoint('item.inserted');
    $outcomes = $run->wait();

    $hold = returnedArray($outcomes['hold']);

    expect($waiting['wait_event'])->toBeIn(['transactionid', 'tuple'])
        ->and($deleteFinishedWhileHoldStood)->toBeFalse()
        ->and($presentWhileHoldStood)->toBeTrue()
        ->and($outcomes['delete']->threw())->toBeTrue()
        ->and($outcomes['delete']->exceptionClass)->toBe(ServiceReferenced::class)
        ->and($hold['booking'] ?? null)->toBeInt()
        ->and(DB::table('tenant_acme.services')->where('id', $cut)->exists())->toBeTrue()
        ->and(DB::table('tenant_acme.booking_items')->where('service_id', $cut)->count())->toBe(1)
        ->and(danglingItems())->toBe(0);
});

it('refuses, at the hold\'s own item insert, a service a delete landed on while the hold stood validated, and rolls the hold back whole: no booking, no item, and nothing naming the missing service', function () {
    $cut = $this->cut;
    $run = ConcurrentRun::of([
        'hold' => holdOfServiceWorker($cut, '2026-10-01 09:00'),
        'delete' => deleteServiceWorker($cut, 'delete.start'),
    ])->holding('delete.start', HoldBooking::AFTER_VALIDATION)->start();
    $run->release();
    $run->awaitCheckpoint('hold', HoldBooking::AFTER_VALIDATION);
    $run->releaseCheckpoint('delete.start');
    $deleted = returnedArray($run->outcomeOf('delete'));
    $goneWhileHoldStood = ! DB::table('tenant_acme.services')->where('id', $cut)->exists();
    $run->releaseCheckpoint(HoldBooking::AFTER_VALIDATION);
    $outcomes = $run->wait();

    expect($deleted)->toBe(['deleted' => true])
        ->and($goneWhileHoldStood)->toBeTrue()
        ->and($outcomes['hold']->threw())->toBeTrue()
        ->and($outcomes['hold']->sqlstate)->toBe('23503')
        ->and(DB::table('tenant_acme.bookings')->count())->toBe(0)
        ->and(DB::table('tenant_acme.booking_items')->count())->toBe(0)
        ->and(danglingItems())->toBe(0);
});
