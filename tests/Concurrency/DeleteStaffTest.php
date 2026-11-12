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
use App\Infrastructure\Db\StaffRecords;
use App\Infrastructure\Db\StaffReferenced;
use App\Infrastructure\HoldBookingFactory;
use App\Tenancy\DefaultWorkingWeek;
use App\Tenancy\Tenant;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concurrency\Harness\ConcurrentRun;
use Tests\Support\FixedClock;
use Tests\Support\WeeklyHours;

/*
 * The staff delete guard against a hold, in the shape DeleteServiceTest proves for the
 * catalog: a member any booking item names is never deleted, whichever order a delete and a
 * hold interleave in, and no item ever names a member who is gone. The guard is the foreign
 * key on booking_items.staff_id, migration 000013: an item insert takes a key share lock on
 * the staff row for its transaction's life, so a delete standing behind an uncommitted item
 * waits and is refused once the item commits, and a delete that lands first refuses the item
 * instead. On acme, provisioned for real and dropped afterwards, with one Cut on a second
 * member who works the default week, Monday to Friday 09:00 to 17:00 in Athens; 2026-10-01 is
 * a Thursday, and the clock stands on the day before.
 *
 * Worker closures run in another process and call only autoloaded code and the framework.
 */

beforeEach(function () {
    DB::statement('drop schema if exists tenant_acme cascade');
    provisionTenant('acme');
    $this->cut = DB::table('tenant_acme.services')->insertGetId(serviceRow('Cut', ['duration_min' => 60, 'price_minor' => 2500]));
    $this->member = DB::table('tenant_acme.staff')->insertGetId(['name' => 'Second Person', 'status' => 'active', 'sort' => 1]);
    DB::table('tenant_acme.service_staff')->insert(['service_id' => $this->cut, 'staff_id' => $this->member]);
    WeeklyHours::week('tenant_acme', $this->member, array_map(fn (array $window): array => [$window['weekday'], $window['start'], $window['end']], DefaultWorkingWeek::windows()));
    app()->instance(Clock::class, FixedClock::at('2026-09-30 12:00:00'));
});

afterEach(function () {
    ConcurrentRun::abandonAll();
    DB::statement('drop schema if exists tenant_acme cascade');
});

/**
 * A worker that inserts a pending booking and its item naming the member, and stands on the
 * uncommitted item until the test lets it commit.
 */
function standingStaffItemWorker(int $serviceId, int $staffId): Closure
{
    return function () use ($serviceId, $staffId): array {
        DB::beginTransaction();
        $bookingId = DB::table('tenant_acme.bookings')->insertGetId([
            'source' => 'embed',
            'uuid' => (string) Str::uuid(), 'status' => 'pending', 'customer_name' => 'Casey Customer', 'customer_email' => 'casey@example.com',
            'total_minor' => 2500, 'currency' => 'EUR', 'payment_mode' => 'onsite', 'requires_approval' => false, 'manage_token_hash' => 'hash',
        ]);
        DB::table('tenant_acme.booking_items')->insert([
            'booking_id' => $bookingId, 'sort' => 0, 'service_id' => $serviceId, 'staff_id' => $staffId,
            'start_utc' => '2026-10-01 09:00:00+00', 'end_utc' => '2026-10-01 10:00:00+00',
            'block_start_utc' => '2026-10-01 09:00:00+00', 'block_end_utc' => '2026-10-01 10:00:00+00', 'price_minor' => 2500,
        ]);
        Checkpoint::reach('item.inserted');
        DB::commit();

        return ['booking' => $bookingId];
    };
}

/**
 * A worker that holds the service on the member through the use case, so the item it inserts
 * is the write protocol's own and the key refusing it is the one a real hold meets.
 */
function holdOfMemberWorker(int $serviceId, int $staffId, string $start): Closure
{
    return function () use ($serviceId, $staffId, $start): array {
        app()->instance(Clock::class, FixedClock::at('2026-09-30 12:00:00'));
        $tenant = Tenant::query()->where('slug', 'acme')->sole();
        $held = app(HoldBookingFactory::class)->for($tenant, Pool::Whole)->hold(new HoldRequest(
            new Customer('Casey Customer', 'casey@example.com'),
            [new SegmentChoice($serviceId, $staffId)],
            new DateTimeImmutable($start, new DateTimeZone('UTC')),
            new Intake(BookingSource::Embed),
        ), Actor::customer());

        return ['held' => $held->uuid];
    };
}

function deleteStaffWorker(int $staffId, string $gate): Closure
{
    return function () use ($staffId, $gate): array {
        Checkpoint::reach($gate);
        $tenant = Tenant::query()->where('slug', 'acme')->sole();

        return ['deleted' => app(StaffRecords::class)->delete($tenant, $staffId)];
    };
}

function danglingStaffItems(): int
{
    return DB::table('tenant_acme.booking_items as i')
        ->whereNotExists(fn (Builder $staff) => $staff->from('tenant_acme.staff as s')->whereColumn('s.id', 'i.staff_id'))
        ->count();
}

it('makes a delete wait behind an item a hold has inserted and not yet committed, and refuses it with StaffReferenced once the item commits: the member still there, the item still naming them', function () {
    $member = $this->member;
    $run = ConcurrentRun::of([
        'hold' => standingStaffItemWorker($this->cut, $member),
        'delete' => deleteStaffWorker($member, 'delete.start'),
    ])->holding('item.inserted', 'delete.start')->start();
    $run->release();
    $run->awaitCheckpoint('hold', 'item.inserted');
    $run->releaseCheckpoint('delete.start');
    $waiting = lockWaiter('delete from');
    $deleteFinishedWhileHoldStood = $run->hasOutcome('delete');
    $presentWhileHoldStood = DB::table('tenant_acme.staff')->where('id', $member)->exists();
    $run->releaseCheckpoint('item.inserted');
    $outcomes = $run->wait();

    expect($waiting['wait_event'])->toBeIn(['transactionid', 'tuple'])
        ->and($deleteFinishedWhileHoldStood)->toBeFalse()
        ->and($presentWhileHoldStood)->toBeTrue()
        ->and($outcomes['delete']->threw())->toBeTrue()
        ->and($outcomes['delete']->exceptionClass)->toBe(StaffReferenced::class)
        ->and(DB::table('tenant_acme.staff')->where('id', $member)->exists())->toBeTrue()
        ->and(DB::table('tenant_acme.booking_items')->where('staff_id', $member)->count())->toBe(1)
        ->and(danglingStaffItems())->toBe(0);
});

it('refuses, at the hold\'s own item insert, a member a delete landed on while the hold stood validated, and rolls the hold back whole: no booking, no item, and nothing naming the missing member', function () {
    $member = $this->member;
    $run = ConcurrentRun::of([
        'hold' => holdOfMemberWorker($this->cut, $member, '2026-10-01 09:00'),
        'delete' => deleteStaffWorker($member, 'delete.start'),
    ])->holding('delete.start', HoldBooking::AFTER_VALIDATION)->start();
    $run->release();
    $run->awaitCheckpoint('hold', HoldBooking::AFTER_VALIDATION);
    $run->releaseCheckpoint('delete.start');
    $deleted = returnedArray($run->outcomeOf('delete'));
    $goneWhileHoldStood = ! DB::table('tenant_acme.staff')->where('id', $member)->exists();
    $run->releaseCheckpoint(HoldBooking::AFTER_VALIDATION);
    $outcomes = $run->wait();

    expect($deleted)->toBe(['deleted' => true])
        ->and($goneWhileHoldStood)->toBeTrue()
        ->and($outcomes['hold']->threw())->toBeTrue()
        ->and($outcomes['hold']->sqlstate)->toBe('23503')
        ->and(DB::table('tenant_acme.bookings')->count())->toBe(0)
        ->and(DB::table('tenant_acme.booking_items')->count())->toBe(0)
        ->and(danglingStaffItems())->toBe(0);
});

it('deletes an unbooked member outright, their service links following the row out through the cascading key', function () {
    $tenant = Tenant::query()->where('slug', 'acme')->sole();

    expect(app(StaffRecords::class)->delete($tenant, $this->member))->toBeTrue()
        ->and(DB::table('tenant_acme.staff')->where('id', $this->member)->exists())->toBeFalse()
        ->and(DB::table('tenant_acme.service_staff')->where('staff_id', $this->member)->exists())->toBeFalse();
});
