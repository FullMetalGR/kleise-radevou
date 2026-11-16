<?php

use App\Application\ChainUnavailable;
use App\Application\Checkpoint;
use App\Application\Clock;
use App\Application\Dto\BookingRecord;
use App\Application\Dto\Customer;
use App\Application\Dto\HoldRequest;
use App\Application\Dto\Intake;
use App\Application\Dto\SegmentChoice;
use App\Application\HoldBooking;
use App\Domain\Audit\Actor;
use App\Domain\Enum\BookingSource;
use App\Infrastructure\Db\BookingsInside;
use App\Infrastructure\Db\LockedClosures;
use App\Infrastructure\Db\Pool;
use App\Infrastructure\HoldBookingFactory;
use App\Tenancy\DefaultWorkingWeek;
use App\Tenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Concurrency\Harness\ConcurrentRun;
use Tests\Support\FixedClock;
use Tests\Support\WeeklyHours;

/*
 * A closure added against a booking made at the same moment over the same time. The closure
 * takes the staff-day locks a booking takes before it writes and reads the bookings inside, so
 * the two go one after the other whichever starts first: a closure behind a booking reads that
 * booking and refuses to be written unanswered, and a booking behind a closure meets it under
 * the lock and is refused. The booking is the admin's own, which lands confirmed and so counts
 * the moment it commits. On acme, provisioned for real and dropped afterwards, with one Cut on
 * a second member who works the default week, Monday to Friday 09:00 to 17:00 in Athens;
 * 2026-10-01 is a Thursday, and the clock stands on the day before.
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
 * A worker that books the Cut on the member by hand, as the admin's New booking does, after the
 * gate named.
 */
function closureRaceBookWorker(int $serviceId, int $staffId, string $gate): Closure
{
    return function () use ($serviceId, $staffId, $gate): array {
        Checkpoint::reach($gate);
        app()->instance(Clock::class, FixedClock::at('2026-09-30 12:00:00'));
        $tenant = Tenant::query()->where('slug', 'acme')->sole();
        $owner = DB::table('users')->where('tenant_id', $tenant->id)->value('id');
        $booked = app(HoldBookingFactory::class)->for($tenant, Pool::Whole)->book(new HoldRequest(
            new Customer('Casey Customer', 'casey@example.com'),
            [new SegmentChoice($serviceId, $staffId)],
            new DateTimeImmutable('2026-10-01 09:00', new DateTimeZone('UTC')),
            new Intake(BookingSource::Admin),
        ), Actor::user(is_int($owner) ? $owner : 0));

        return ['booked' => $booked->uuid];
    };
}

/**
 * A worker that adds a whole-day closure on 2026-10-01, for the member or for the business, as
 * an admin who has seen no list does, after the gate named.
 */
function closureRaceAddWorker(?int $staffId, string $gate): Closure
{
    return function () use ($staffId, $gate): array {
        Checkpoint::reach($gate);
        $tenant = Tenant::query()->where('slug', 'acme')->sole();

        try {
            app(LockedClosures::class)->add($tenant, $staffId, '2026-10-01', null, null, null, 'Works', null);

            return ['added' => true, 'inside' => []];
        } catch (BookingsInside $refused) {
            return ['added' => false, 'inside' => array_map(fn (BookingRecord $record): string => $record->snapshot->uuid, $refused->bookings)];
        }
    };
}

it('makes a business closure wait behind a booking standing validated under its locks, then read that booking and refuse to be written', function () {
    $run = ConcurrentRun::of([
        'book' => closureRaceBookWorker($this->cut, $this->member, 'book.start'),
        'close' => closureRaceAddWorker(null, 'close.start'),
    ])->holding(HoldBooking::AFTER_VALIDATION, 'close.start')->start();
    $run->release();
    $run->awaitCheckpoint('book', HoldBooking::AFTER_VALIDATION);
    $run->releaseCheckpoint('close.start');
    $waiting = lockWaiter('insert into staff_days');
    $closedWhileBookingStood = $run->hasOutcome('close');
    $run->releaseCheckpoint(HoldBooking::AFTER_VALIDATION);
    $outcomes = $run->wait();
    $booked = returnedArray($outcomes['book']);

    expect($waiting['wait_event'])->toBeIn(['transactionid', 'tuple'])
        ->and($closedWhileBookingStood)->toBeFalse()
        ->and(returnedArray($outcomes['close']))->toBe(['added' => false, 'inside' => [$booked['booked']]])
        ->and(DB::table('tenant_acme.blackouts')->count())->toBe(0)
        ->and(DB::table('tenant_acme.bookings')->where('uuid', $booked['booked'])->value('status'))->toBe('confirmed');
});

it('makes a booking wait behind a person\'s closure holding the same day, then meet the closure under the lock and be refused whole', function () {
    $run = ConcurrentRun::of([
        'close' => closureRaceAddWorker($this->member, 'close.start'),
        'book' => closureRaceBookWorker($this->cut, $this->member, 'book.start'),
    ])->holding(LockedClosures::LOCKED, 'book.start')->start();
    $run->release();
    $run->awaitCheckpoint('close', LockedClosures::LOCKED);
    $run->releaseCheckpoint('book.start');
    $waiting = lockWaiter('insert into staff_days');
    $bookedWhileClosureStood = $run->hasOutcome('book');
    $run->releaseCheckpoint(LockedClosures::LOCKED);
    $outcomes = $run->wait();

    expect($waiting['wait_event'])->toBeIn(['transactionid', 'tuple'])
        ->and($bookedWhileClosureStood)->toBeFalse()
        ->and(returnedArray($outcomes['close']))->toBe(['added' => true, 'inside' => []])
        ->and($outcomes['book']->threw())->toBeTrue()
        ->and($outcomes['book']->exceptionClass)->toBe(ChainUnavailable::class)
        ->and(DB::table('tenant_acme.blackouts')->where('staff_id', $this->member)->count())->toBe(1)
        ->and(DB::table('tenant_acme.bookings')->count())->toBe(0);
});
