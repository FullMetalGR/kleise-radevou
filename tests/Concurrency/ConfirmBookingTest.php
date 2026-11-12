<?php

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
use Illuminate\Support\Str;
use Tests\Concurrency\Harness\ConcurrentRun;
use Tests\Support\FixedClock;

/*
 * A confirm against a competing hold on the same slot, in two processes on two connections.
 * What is on trial is that the two serialise on the staff-day mutex row rather than racing:
 * a guarded transition and a hold take the same rows in the same order, so one waits for the
 * other and the outcome does not depend on how the scheduler felt.
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
    // A worker still holding a row lock would make the drop wait forever; the base case abandons
    // runs only after these hooks, so abandon here first.
    ConcurrentRun::abandonAll();
    DB::statement('drop schema if exists tenant_acme cascade');
});

function settlingTenant(): Tenant
{
    return Tenant::query()->where('slug', 'acme')->sole();
}

/**
 * A hold the parent takes in its own process, on the Cut at the UTC instant given.
 */
function settlingHold(int $serviceId, string $start = '2026-10-01 09:00'): string
{
    return app(HoldBookingFactory::class)->for(settlingTenant(), Pool::Whole)->hold(
        new HoldRequest(
            new Customer('Casey Customer', 'casey@example.com'),
            [new SegmentChoice($serviceId, 1)],
            new DateTimeImmutable($start, new DateTimeZone('UTC')),
            new Intake(BookingSource::Embed),
        ),
        Actor::customer(),
    )->uuid;
}

/**
 * A pending hold on the slot whose expiry has already passed by the database's clock, its item
 * still blocking because no sweeper has run. The hold path writes nothing lapsed, so the row is
 * written directly.
 */
function settlingLapsedHold(): string
{
    $uuid = (string) Str::uuid();

    $bookingId = DB::table('tenant_acme.bookings')->insertGetId([
        ...bookingRow(),
        'uuid' => $uuid,
        'status' => 'pending',
        'hold_class' => 'checkout',
        'hold_expires_at' => DB::raw("now() - interval '1 minute'"),
    ]);

    DB::table('tenant_acme.booking_items')->insert(itemRow($bookingId, 1, '2026-10-01 09:00:00+00', '2026-10-01 10:00:00+00'));

    return $uuid;
}

/**
 * A worker confirming one booking of acme through the factory, reporting how it ended.
 */
function settlingConfirmWorker(string $uuid, ?string $gate = null): Closure
{
    return function () use ($uuid, $gate): array {
        if ($gate !== null) {
            Checkpoint::reach($gate);
        }

        app()->instance(Clock::class, FixedClock::at('2026-09-30 12:00:00'));
        $tenant = Tenant::query()->where('slug', 'acme')->sole();
        $confirms = app(BookingTransitionFactory::class)->confirm($tenant);

        try {
            $confirms->confirm($uuid, Actor::customer());

            return ['confirmed' => true];
        } catch (TransitionRefused $refused) {
            return ['refused' => $refused->reason->value, 'found' => $refused->status->value];
        }
    };
}

/**
 * A worker holding the same slot for acme through the factory, reporting how it ended.
 */
function settlingHoldWorker(int $serviceId, ?string $gate = null): Closure
{
    return function () use ($serviceId, $gate): array {
        if ($gate !== null) {
            Checkpoint::reach($gate);
        }

        app()->instance(Clock::class, FixedClock::at('2026-09-30 12:00:00'));
        $tenant = Tenant::query()->where('slug', 'acme')->sole();
        $holds = app(HoldBookingFactory::class)->for($tenant, Pool::Whole);
        $request = new HoldRequest(
            new Customer('Robin Rival', 'robin@example.com'),
            [new SegmentChoice($serviceId, 1)],
            new DateTimeImmutable('2026-10-01 09:00', new DateTimeZone('UTC')),
            new Intake(BookingSource::Embed),
        );

        try {
            return ['held' => $holds->hold($request, Actor::customer())->uuid];
        } catch (SegmentConflict $conflict) {
            return ['conflict' => $conflict->segment];
        } catch (Throwable $failure) {
            return ['failed' => $failure::class];
        }
    };
}

/**
 * A worker holding the same slot for acme from a plan built by hand, as the planner would have
 * made it from rows read a moment earlier, so the attempt reaches the lock rather than being
 * refused by the advisory masks on the booking it is racing. The re-validation under the lock
 * is what is on trial, and it is the only thing that can tell the customer the truth.
 */
function settlingPlanWorker(int $serviceId, ?string $gate = null): Closure
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
function settlingRows(): array
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
 * The text a backend blocked on the mutex row is waiting inside, as the activity view spells
 * it: the lock statement up to its first binding.
 */
function settlingLockPrefix(): string
{
    $prefix = strstr(LockManager::sql(1), '(?', true);

    assert(is_string($prefix));

    return $prefix;
}

it('serialises a confirm and a competing hold on the mutex row: the hold waits for the staff-day the confirm holds, the confirm lands, and the hold is refused against the item that now blocks for good', function () {
    $uuid = settlingHold($this->cut);

    $run = ConcurrentRun::of([
        'confirm' => settlingConfirmWorker($uuid),
        'hold' => settlingPlanWorker($this->cut, 'hold.start'),
    ])->holding(GuardedWrite::AFTER_LOCK, 'hold.start')->start();
    $run->release();
    // The confirm holds the staff-day and has not touched the booking row yet.
    $run->awaitCheckpoint('confirm', GuardedWrite::AFTER_LOCK);
    $run->releaseCheckpoint('hold.start');
    // Observed rather than inferred from timing: the hold is inside the lock statement, waiting.
    $waiting = lockWaiter(settlingLockPrefix());
    $run->releaseCheckpoint(GuardedWrite::AFTER_LOCK);
    $outcomes = $run->wait();

    $rows = settlingRows();
    $trail = DB::table('tenant_acme.audit_log')->orderBy('id')->pluck('action')->all();

    expect($waiting['wait_event'])->toBeIn(['transactionid', 'tuple'])
        ->and(returnedArray($outcomes['confirm']))->toBe(['confirmed' => true])
        ->and(returnedArray($outcomes['hold'])['conflict'] ?? null)->toBe(0)
        ->and($rows)->toHaveCount(1)
        ->and($rows[0])->toMatchArray(['uuid' => $uuid, 'status' => 'confirmed', 'hold_class' => null, 'blocks' => true])
        ->and($trail)->toBe(['held', 'confirmed']);
});

it('answers the confirm of a lapsed hold hold_expired and lets the competitor book, when the confirm takes the staff-day first', function () {
    $uuid = settlingLapsedHold();

    $run = ConcurrentRun::of([
        'confirm' => settlingConfirmWorker($uuid),
        'hold' => settlingHoldWorker($this->cut, 'hold.start'),
    ])->holding(GuardedWrite::AFTER_LOCK, 'hold.start')->start();
    $run->release();
    $run->awaitCheckpoint('confirm', GuardedWrite::AFTER_LOCK);
    $run->releaseCheckpoint('hold.start');
    $waiting = lockWaiter(settlingLockPrefix());
    $run->releaseCheckpoint(GuardedWrite::AFTER_LOCK);
    $outcomes = $run->wait();

    $rows = settlingRows();

    expect($waiting['wait_event'])->toBeIn(['transactionid', 'tuple'])
        ->and(returnedArray($outcomes['confirm']))->toBe(['refused' => 'hold_expired', 'found' => 'pending'])
        ->and(returnedArray($outcomes['hold'])['held'] ?? null)->toBeString()
        ->and($rows)->toHaveCount(2)
        // The confirm refused it and left it alone; the hold's own reap is what expired it.
        ->and($rows[0])->toMatchArray(['uuid' => $uuid, 'status' => 'expired', 'blocks' => false])
        ->and($rows[1])->toMatchArray(['status' => 'pending', 'hold_class' => 'checkout', 'blocks' => true]);
});

it('answers the confirm of a lapsed hold hold_expired whichever ran first: with the hold ahead of it, the reap has already expired the booking and the confirm finds it that way', function () {
    $uuid = settlingLapsedHold();

    $run = ConcurrentRun::of([
        'hold' => settlingHoldWorker($this->cut),
        'confirm' => settlingConfirmWorker($uuid, 'confirm.start'),
    ])->holding(HoldBooking::AFTER_LOCK, 'confirm.start')->start();
    $run->release();
    // The hold holds the staff-day, before its reap has run.
    $run->awaitCheckpoint('hold', HoldBooking::AFTER_LOCK);
    $run->releaseCheckpoint('confirm.start');
    $waiting = lockWaiter(settlingLockPrefix());
    $run->releaseCheckpoint(HoldBooking::AFTER_LOCK);
    $outcomes = $run->wait();

    $rows = settlingRows();
    $trail = DB::table('tenant_acme.audit_log')->orderBy('id')->pluck('action')->all();

    expect($waiting['wait_event'])->toBeIn(['transactionid', 'tuple'])
        ->and(returnedArray($outcomes['hold'])['held'] ?? null)->toBeString()
        ->and(returnedArray($outcomes['confirm']))->toBe(['refused' => 'hold_expired', 'found' => 'expired'])
        ->and($rows)->toHaveCount(2)
        ->and($rows[0])->toMatchArray(['uuid' => $uuid, 'status' => 'expired', 'blocks' => false])
        ->and($rows[1])->toMatchArray(['status' => 'pending', 'hold_class' => 'checkout', 'blocks' => true])
        ->and($trail)->toBe(['expired', 'held']);
});

it('serialises two confirms of one booking: the second waits from the staff-day through the re-read and the write, and finds the booking settled rather than settling it twice', function () {
    $uuid = settlingHold($this->cut);

    $run = ConcurrentRun::of([
        'first' => settlingConfirmWorker($uuid),
        'second' => settlingConfirmWorker($uuid, 'second.start'),
    ])->holding(GuardedWrite::AFTER_REREAD, 'second.start')->start();
    $run->release();
    // The first holds the staff-day and the booking row, and has read the booking back.
    $run->awaitCheckpoint('first', GuardedWrite::AFTER_REREAD);
    $run->releaseCheckpoint('second.start');
    $waiting = lockWaiter(settlingLockPrefix());
    $run->releaseCheckpoint(GuardedWrite::AFTER_REREAD);
    $outcomes = $run->wait();

    $trail = DB::table('tenant_acme.audit_log')->orderBy('id')->pluck('action')->all();

    expect($waiting['wait_event'])->toBeIn(['transactionid', 'tuple'])
        ->and(returnedArray($outcomes['first']))->toBe(['confirmed' => true])
        ->and(returnedArray($outcomes['second']))->toBe(['refused' => 'not_confirmable', 'found' => 'confirmed'])
        ->and(settlingRows())->toHaveCount(1)
        ->and($trail)->toBe(['held', 'confirmed']);
});
