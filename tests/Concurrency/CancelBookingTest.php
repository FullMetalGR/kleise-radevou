<?php

use App\Application\CancelBooking;
use App\Application\Checkpoint;
use App\Application\Clock;
use App\Application\Dto\Customer;
use App\Application\Dto\HoldItem;
use App\Application\Dto\HoldPlan;
use App\Application\Dto\HoldRequest;
use App\Application\Dto\Intake;
use App\Application\Dto\SegmentChoice;
use App\Application\GuardedWrite;
use App\Application\HoldBooking;
use App\Application\TransitionRefused;
use App\Domain\Audit\Actor;
use App\Domain\Booking\SegmentConflict;
use App\Domain\Enum\ApprovalTimeout;
use App\Domain\Enum\BookingSource;
use App\Domain\Enum\BookingStatus;
use App\Domain\Enum\HoldClass;
use App\Domain\Enum\PaymentMode;
use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use App\Infrastructure\BookingTransitionFactory;
use App\Infrastructure\Db\LockManager;
use App\Infrastructure\Db\Pool;
use App\Infrastructure\HoldBookingFactory;
use App\Tenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Concurrency\Harness\ConcurrentRun;
use Tests\Support\FixedClock;

/*
 * A cancel of a confirmed booking against a competing hold on the same slot, in two processes
 * on two connections. What is on trial is that the two serialise on the staff-day mutex row and
 * end consistent whichever commits first: the cancel always lands, the hold either conflicts or
 * books depending on which side of the cancel it read, and no two blocking items overlap
 * afterwards.
 *
 * The cancel is the owner's, actor user:1. A customer's cancel would be refused by the window
 * here, the booking being twenty-one hours away and the service's window twenty-four, and the
 * window is not what this file is about; the transaction is the same one either way.
 *
 * acme gets a Cut of sixty minutes on its implicit staff member, who works Monday to Friday
 * 09:00 to 17:00 in Athens from provisioning. 2026-10-01 is a Thursday, so 09:00 UTC is 12:00
 * in Athens, inside the hours. The clock stands on the day before.
 *
 * Worker closures run in another process and call only autoloaded code and the framework; every
 * instant they take is UTC text, and the clock they stand on is spelled inside each closure,
 * since nothing declared in this file exists there.
 */

beforeEach(function () {
    DB::statement('drop schema if exists tenant_acme cascade');
    provisionTenant('acme');
    $this->cut = DB::table('tenant_acme.services')->insertGetId(serviceRow('Cut', ['duration_min' => 60, 'price_minor' => 2500]));
    DB::table('tenant_acme.service_staff')->insert(['service_id' => $this->cut, 'staff_id' => 1]);
    app()->instance(Clock::class, FixedClock::at('2026-09-30 12:00:00'));
});

afterEach(function () {
    ConcurrentRun::abandonAll();
    DB::statement('drop schema if exists tenant_acme cascade');
});

function givingBackTenant(): Tenant
{
    return Tenant::query()->where('slug', 'acme')->sole();
}

/**
 * A confirmed booking on the slot, taken and settled in the parent's own process.
 */
function givingBackConfirmed(int $serviceId): string
{
    $uuid = app(HoldBookingFactory::class)->for(givingBackTenant(), Pool::Whole)->hold(
        new HoldRequest(
            new Customer('Casey Customer', 'casey@example.com'),
            [new SegmentChoice($serviceId, 1)],
            new DateTimeImmutable('2026-10-01 09:00', new DateTimeZone('UTC')),
            new Intake(BookingSource::Embed),
        ),
        Actor::customer(),
    )->uuid;

    app(BookingTransitionFactory::class)->confirm(givingBackTenant())->confirm($uuid, Actor::customer());

    return $uuid;
}

/**
 * A worker cancelling one booking of acme through the factory, reporting how it ended.
 */
function givingBackCancelWorker(string $uuid, ?string $gate = null): Closure
{
    return function () use ($uuid, $gate): array {
        if ($gate !== null) {
            Checkpoint::reach($gate);
        }

        app()->instance(Clock::class, FixedClock::at('2026-09-30 12:00:00'));
        $tenant = Tenant::query()->where('slug', 'acme')->sole();

        /** @var CancelBooking $cancels */
        $cancels = app(BookingTransitionFactory::class)->cancel($tenant);

        try {
            $cancels->cancel($uuid, Actor::user(1));

            return ['cancelled' => true];
        } catch (TransitionRefused $refused) {
            return ['refused' => $refused->reason->value, 'found' => $refused->status->value];
        }
    };
}

/**
 * A worker holding the same slot for acme from a plan built by hand, as the planner would have
 * made it from rows read a moment earlier, so the attempt reaches the lock rather than being
 * refused by the advisory masks on the booking it is racing.
 */
function givingBackHoldWorker(int $serviceId, ?string $gate = null): Closure
{
    return function () use ($serviceId, $gate): array {
        if ($gate !== null) {
            Checkpoint::reach($gate);
        }

        app()->instance(Clock::class, FixedClock::at('2026-09-30 12:00:00'));
        $tenant = Tenant::query()->where('slug', 'acme')->sole();
        $holds = app(HoldBookingFactory::class)->for($tenant, Pool::Whole);
        $utc = new DateTimeZone('UTC');
        $from = new DateTimeImmutable('2026-10-01 09:00', $utc);
        $to = new DateTimeImmutable('2026-10-01 10:00', $utc);
        $plan = new HoldPlan(
            new Customer('Robin Rival', 'robin@example.com'),
            [new HoldItem(1, $serviceId, $from, $to, $from, $to, null, new Money(2500, Currency::of('EUR')))],
            BookingStatus::Pending,
            HoldClass::Checkout,
            15,
            PaymentMode::Onsite,
            false,
            ApprovalTimeout::Expire,
            new Intake(BookingSource::Embed),
        );

        try {
            return ['held' => $holds->execute($plan, Actor::customer())->uuid];
        } catch (SegmentConflict $conflict) {
            return ['conflict' => $conflict->segment];
        } catch (Throwable $failure) {
            return ['failed' => $failure::class];
        }
    };
}

/**
 * The bookings of acme with the status and blocking flag of each, oldest first.
 *
 * @return list<array<mixed>>
 */
function givingBackRows(): array
{
    $rows = [];

    foreach (DB::select(
        'select b.uuid::text as uuid, b.status, b.hold_class, i.blocks'
        .' from tenant_acme.bookings b join tenant_acme.booking_items i on i.booking_id = b.id order by b.id'
    ) as $row) {
        assert($row instanceof stdClass);

        $rows[] = get_object_vars($row);
    }

    return $rows;
}

/**
 * How many pairs of blocking items of one staff member overlap in time. The exclusion
 * constraint makes it impossible and this asks the rows anyway: the invariant is what the whole
 * protocol is for, and a test that only reads statuses would pass on a table that holds two
 * customers in one chair.
 */
function givingBackOverlaps(): int
{
    $overlaps = DB::scalar(
        'select count(*) from tenant_acme.booking_items a join tenant_acme.booking_items b'
        .' on b.id > a.id and b.staff_id = a.staff_id and b.block_span && a.block_span'
        .' where a.blocks and b.blocks'
    );

    assert(is_int($overlaps));

    return $overlaps;
}

/**
 * The text a backend blocked on the mutex row is waiting inside, as the activity view spells
 * it: the lock statement up to its first binding.
 */
function givingBackLockPrefix(): string
{
    $prefix = strstr(LockManager::sql(1), '(?', true);

    assert(is_string($prefix));

    return $prefix;
}

it('serialises a cancel and a competing hold on the mutex row: the hold waits for the staff-day the cancel holds, the cancel lands, and the slot the cancel gave back is the one the hold takes', function () {
    $uuid = givingBackConfirmed($this->cut);

    $run = ConcurrentRun::of([
        'cancel' => givingBackCancelWorker($uuid),
        'hold' => givingBackHoldWorker($this->cut, 'hold.start'),
    ])->holding(GuardedWrite::AFTER_LOCK, 'hold.start')->start();
    $run->release();
    // The cancel holds the staff-day and has not touched the booking row yet.
    $run->awaitCheckpoint('cancel', GuardedWrite::AFTER_LOCK);
    $run->releaseCheckpoint('hold.start');
    // Observed rather than inferred from timing: the hold is inside the lock statement, waiting.
    $waiting = lockWaiter(givingBackLockPrefix());
    $run->releaseCheckpoint(GuardedWrite::AFTER_LOCK);
    $outcomes = $run->wait();

    $rows = givingBackRows();
    $trail = DB::table('tenant_acme.audit_log')->orderBy('id')->pluck('action')->all();

    expect($waiting['wait_event'])->toBeIn(['transactionid', 'tuple'])
        ->and(returnedArray($outcomes['cancel']))->toBe(['cancelled' => true])
        ->and(returnedArray($outcomes['hold'])['held'] ?? null)->toBeString()
        ->and($rows)->toHaveCount(2)
        ->and($rows[0])->toMatchArray(['uuid' => $uuid, 'status' => 'cancelled', 'hold_class' => null, 'blocks' => false])
        ->and($rows[1])->toMatchArray(['status' => 'pending', 'hold_class' => 'checkout', 'blocks' => true])
        ->and(givingBackOverlaps())->toBe(0)
        ->and($trail)->toBe(['held', 'confirmed', 'cancelled', 'held']);
});

it('refuses the competing hold when it takes the staff-day first, and the cancel still lands behind it', function () {
    $uuid = givingBackConfirmed($this->cut);

    $run = ConcurrentRun::of([
        'hold' => givingBackHoldWorker($this->cut),
        'cancel' => givingBackCancelWorker($uuid, 'cancel.start'),
    ])->holding(HoldBooking::AFTER_LOCK, 'cancel.start')->start();
    $run->release();
    // The hold holds the staff-day, before its re-validation has run.
    $run->awaitCheckpoint('hold', HoldBooking::AFTER_LOCK);
    $run->releaseCheckpoint('cancel.start');
    $waiting = lockWaiter(givingBackLockPrefix());
    $run->releaseCheckpoint(HoldBooking::AFTER_LOCK);
    $outcomes = $run->wait();

    $rows = givingBackRows();
    $trail = DB::table('tenant_acme.audit_log')->orderBy('id')->pluck('action')->all();

    expect($waiting['wait_event'])->toBeIn(['transactionid', 'tuple'])
        // The hold read the confirmed item under its own lock and refused the segment.
        ->and(returnedArray($outcomes['hold'])['conflict'] ?? null)->toBe(0)
        ->and(returnedArray($outcomes['cancel']))->toBe(['cancelled' => true])
        ->and($rows)->toHaveCount(1)
        ->and($rows[0])->toMatchArray(['uuid' => $uuid, 'status' => 'cancelled', 'blocks' => false])
        ->and(givingBackOverlaps())->toBe(0)
        ->and($trail)->toBe(['held', 'confirmed', 'cancelled']);
});

it('ends consistent with no checkpoint at all, the two released together and the scheduler deciding the order', function () {
    $uuid = givingBackConfirmed($this->cut);

    $run = ConcurrentRun::of([
        'cancel' => givingBackCancelWorker($uuid),
        'hold' => givingBackHoldWorker($this->cut),
    ])->start();
    $run->release();
    $outcomes = $run->wait();

    $cancel = returnedArray($outcomes['cancel']);
    $hold = returnedArray($outcomes['hold']);
    $rows = givingBackRows();

    expect($cancel)->toBe(['cancelled' => true])
        ->and(array_key_first($hold))->toBeIn(['held', 'conflict'])
        ->and($rows[0])->toMatchArray(['uuid' => $uuid, 'status' => 'cancelled', 'blocks' => false])
        // Whichever order they committed in: the hold that booked took the slot the cancel gave
        // back, and the hold that conflicted took nothing.
        ->and($rows)->toHaveCount(isset($hold['held']) ? 2 : 1)
        ->and(givingBackOverlaps())->toBe(0);
});
