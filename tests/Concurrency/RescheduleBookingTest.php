<?php

use App\Application\ChainUnavailable;
use App\Application\Checkpoint;
use App\Application\Clock;
use App\Application\Dto\Customer;
use App\Application\Dto\HoldRequest;
use App\Application\Dto\Intake;
use App\Application\Dto\MoveChoices;
use App\Application\Dto\SegmentChoice;
use App\Application\HoldBooking;
use App\Application\RescheduleBooking;
use App\Application\TransitionRefused;
use App\Application\WriteContended;
use App\Domain\Audit\Actor;
use App\Domain\Booking\SegmentConflict;
use App\Domain\Enum\BookingSource;
use App\Infrastructure\BookingTransitionFactory;
use App\Infrastructure\Db\LockManager;
use App\Infrastructure\Db\Pool;
use App\Infrastructure\HoldBookingFactory;
use App\Tenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Concurrency\Harness\ConcurrentRun;
use Tests\Support\FixedClock;

/*
 * A move against the two writes that can race it, in two processes on two connections: a cancel
 * of the same booking, and a hold on the slot it is moving to.
 *
 * What is on trial is the union key set. A move locks both where the booking is and where it is
 * going, so a competitor on either side queues behind it rather than reading past it; and a
 * guarded transition whose booking moved out from under its lock re-runs from step 1 once and
 * lands on the booking where it now is.
 *
 * acme gets a Cut of sixty minutes on its implicit staff member, who works Monday to Friday
 * 09:00 to 17:00 in Athens from provisioning. 2026-10-01 is a Thursday and 2026-10-02 a Friday,
 * so 09:00 UTC is 12:00 in Athens on either, inside the hours. The clock stands on the Wednesday
 * before, outside every window.
 *
 * Worker closures run in another process and call only autoloaded code and the framework; every
 * instant they take is UTC text, and the clock they stand on is spelled inside each closure,
 * since nothing declared in this file exists there.
 */

beforeEach(function () {
    DB::statement('drop schema if exists tenant_acme cascade');
    provisionTenant('acme');
    // The move here is the customer's own, so the reschedule window binds it. The booking under
    // test is twenty-one hours away and the column defaults to twenty-four, which is a policy
    // refusal and not what any of this is about; the window is pinned open so the writes reach
    // the lock, which is where these tests look.
    $this->cut = DB::table('tenant_acme.services')->insertGetId(serviceRow('Cut', [
        'duration_min' => 60,
        'price_minor' => 2500,
        'reschedule_window_hours' => 0,
    ]));
    DB::table('tenant_acme.service_staff')->insert(['service_id' => $this->cut, 'staff_id' => 1]);
    app()->instance(Clock::class, FixedClock::at('2026-09-30 12:00:00'));
});

afterEach(function () {
    ConcurrentRun::abandonAll();
    DB::statement('drop schema if exists tenant_acme cascade');
});

function racingTenant(): Tenant
{
    return Tenant::query()->where('slug', 'acme')->sole();
}

/**
 * A confirmed booking on the Thursday slot, taken and settled in the parent's own process.
 */
function racingConfirmed(int $serviceId): string
{
    $uuid = app(HoldBookingFactory::class)->for(racingTenant(), Pool::Whole)->hold(
        new HoldRequest(
            new Customer('Casey Customer', 'casey@example.com'),
            [new SegmentChoice($serviceId, 1)],
            new DateTimeImmutable('2026-10-01 09:00', new DateTimeZone('UTC')),
            new Intake(BookingSource::Embed),
        ),
        Actor::customer(),
    )->uuid;

    app(BookingTransitionFactory::class)->confirm(racingTenant())->confirm($uuid, Actor::customer());

    return $uuid;
}

/**
 * A confirmed booking of acme on staff member 1 at the UTC instant given, taken and settled in
 * the parent's own process.
 */
function racingConfirmedAt(int $serviceId, string $start): string
{
    $uuid = app(HoldBookingFactory::class)->for(racingTenant(), Pool::Whole)->hold(
        new HoldRequest(
            new Customer('Casey Customer', 'casey@example.com'),
            [new SegmentChoice($serviceId, 1)],
            new DateTimeImmutable($start, new DateTimeZone('UTC')),
            new Intake(BookingSource::Embed),
        ),
        Actor::customer(),
    )->uuid;

    app(BookingTransitionFactory::class)->confirm(racingTenant())->confirm($uuid, Actor::customer());

    return $uuid;
}

/**
 * A worker moving one booking of acme for a signed-in user to the instant given and onto the
 * member given, the warning accepted, reporting how it ended.
 */
function racingBusinessMoveWorker(string $uuid, string $start, int $staffId, ?string $gate = null): Closure
{
    return function () use ($uuid, $start, $staffId, $gate): array {
        if ($gate !== null) {
            Checkpoint::reach($gate);
        }

        app()->instance(Clock::class, FixedClock::at('2026-09-30 12:00:00'));
        $tenant = Tenant::query()->where('slug', 'acme')->sole();

        /** @var RescheduleBooking $moves */
        $moves = app(BookingTransitionFactory::class)->reschedule($tenant);

        try {
            $moves->reschedule($uuid, Actor::user(7), new DateTimeImmutable($start, new DateTimeZone('UTC')), new MoveChoices(staff: [0 => $staffId], warningAccepted: true));

            return ['moved' => true];
        } catch (SegmentConflict $conflict) {
            return ['conflict' => $conflict->segment];
        } catch (ChainUnavailable $unavailable) {
            return ['unavailable' => $unavailable->segment];
        }
    };
}

/**
 * A worker moving one booking of acme to the instant given, reporting how it ended.
 */
function racingMoveWorker(string $uuid, string $start, ?string $gate = null): Closure
{
    return function () use ($uuid, $start, $gate): array {
        if ($gate !== null) {
            Checkpoint::reach($gate);
        }

        app()->instance(Clock::class, FixedClock::at('2026-09-30 12:00:00'));
        $tenant = Tenant::query()->where('slug', 'acme')->sole();

        /** @var RescheduleBooking $moves */
        $moves = app(BookingTransitionFactory::class)->reschedule($tenant);

        try {
            $moves->reschedule($uuid, Actor::customer(), new DateTimeImmutable($start, new DateTimeZone('UTC')));

            return ['moved' => true];
        } catch (SegmentConflict $conflict) {
            return ['conflict' => $conflict->segment];
        } catch (ChainUnavailable $unavailable) {
            return ['unavailable' => $unavailable->segment];
        } catch (TransitionRefused $refused) {
            return ['refused' => $refused->reason->value, 'found' => $refused->status->value];
        } catch (WriteContended) {
            return ['busy' => true];
        }
    };
}

/**
 * A worker cancelling one booking of acme through the factory, reporting how it ended.
 */
function racingCancelWorker(string $uuid, ?string $gate = null): Closure
{
    return function () use ($uuid, $gate): array {
        if ($gate !== null) {
            Checkpoint::reach($gate);
        }

        app()->instance(Clock::class, FixedClock::at('2026-09-30 12:00:00'));
        $tenant = Tenant::query()->where('slug', 'acme')->sole();

        try {
            app(BookingTransitionFactory::class)->cancel($tenant)->cancel($uuid, Actor::user(1));

            return ['cancelled' => true];
        } catch (TransitionRefused $refused) {
            return ['refused' => $refused->reason->value, 'found' => $refused->status->value];
        } catch (WriteContended) {
            return ['busy' => true];
        }
    };
}

/**
 * A worker holding the Friday slot for acme from a plan the planner makes, so the attempt
 * reaches the lock rather than being refused by the advisory masks on the booking it is racing.
 */
function racingHoldWorker(int $serviceId, string $start, ?string $gate = null): Closure
{
    return function () use ($serviceId, $start, $gate): array {
        if ($gate !== null) {
            Checkpoint::reach($gate);
        }

        app()->instance(Clock::class, FixedClock::at('2026-09-30 12:00:00'));
        $tenant = Tenant::query()->where('slug', 'acme')->sole();

        try {
            return ['held' => app(HoldBookingFactory::class)->for($tenant, Pool::Whole)->hold(
                new HoldRequest(
                    new Customer('Robin Rival', 'robin@example.com'),
                    [new SegmentChoice($serviceId, 1)],
                    new DateTimeImmutable($start, new DateTimeZone('UTC')),
                    new Intake(BookingSource::Embed),
                ),
                Actor::customer(),
            )->uuid];
        } catch (SegmentConflict $conflict) {
            return ['conflict' => $conflict->segment];
        } catch (ChainUnavailable $unavailable) {
            return ['unavailable' => $unavailable->segment];
        } catch (Throwable $failure) {
            return ['failed' => $failure::class];
        }
    };
}

/**
 * The bookings of acme with the status of each and the range and flag of each item, oldest
 * first.
 *
 * @return list<array<mixed>>
 */
function racingRows(): array
{
    $rows = [];

    foreach (DB::select(
        "select b.uuid::text as uuid, b.status, to_char(i.start_utc at time zone 'UTC', 'YYYY-MM-DD HH24:MI') as start_utc, i.blocks"
        .' from tenant_acme.bookings b join tenant_acme.booking_items i on i.booking_id = b.id order by b.id, i.sort'
    ) as $row) {
        assert($row instanceof stdClass);

        $rows[] = get_object_vars($row);
    }

    return $rows;
}

/**
 * How many pairs of blocking items of one staff member overlap in time. The exclusion
 * constraint makes it impossible and this asks the rows anyway: the invariant is what the whole
 * protocol is for.
 */
function racingOverlaps(): int
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
 * The revision of one staff-day, or zero where the mutex row does not exist yet. Every lock
 * bumps it, so the difference across a run counts the attempts that reached step 3.
 */
function racingRevisionOf(string $day): int
{
    $rev = DB::scalar('select rev from tenant_acme.staff_days where staff_id = 1 and day = ?::date', [$day]);

    return is_int($rev) ? $rev : 0;
}

/**
 * The text a backend blocked on the mutex row is waiting inside, as the activity view spells
 * it: the lock statement up to its first binding.
 */
function racingLockPrefix(): string
{
    $prefix = strstr(LockManager::sql(1), '(?', true);

    assert(is_string($prefix));

    return $prefix;
}

it('holds both the staff-day it is leaving and the staff-day it is taking, so a cancel whose booking moved re-runs from step 1 once and lands on the booking where it now is', function () {
    $uuid = racingConfirmed($this->cut);
    $thursday = racingRevisionOf('2026-10-01');
    $friday = racingRevisionOf('2026-10-02');

    $run = ConcurrentRun::of([
        'move' => racingMoveWorker($uuid, '2026-10-02 09:00'),
        'cancel' => racingCancelWorker($uuid, 'cancel.start'),
    ])->holding(RescheduleBooking::AFTER_LOCK, 'cancel.start')->start();
    $run->release();
    // The move holds both staff-days and has not touched the booking row yet.
    $run->awaitCheckpoint('move', RescheduleBooking::AFTER_LOCK);
    // The cancel now takes its pre-read, which is outside any transaction and reads the items
    // where they still are, and then blocks on the staff-day the move is holding.
    $run->releaseCheckpoint('cancel.start');
    $waiting = lockWaiter(racingLockPrefix());
    $run->releaseCheckpoint(RescheduleBooking::AFTER_LOCK);
    $outcomes = $run->wait();

    $rows = racingRows();
    $trail = DB::table('tenant_acme.audit_log')->orderBy('id')->pluck('action')->all();

    expect($waiting['wait_event'])->toBeIn(['transactionid', 'tuple'])
        ->and(returnedArray($outcomes['move']))->toBe(['moved' => true])
        ->and(returnedArray($outcomes['cancel']))->toBe(['cancelled' => true])
        ->and($rows)->toHaveCount(1)
        // It cancelled the booking at its new time, which is the only booking there is.
        ->and($rows[0])->toMatchArray(['uuid' => $uuid, 'status' => 'cancelled', 'blocks' => false])
        ->and($rows[0]['start_utc'])->toBe('2026-10-02 09:00')
        ->and($trail)->toBe(['held', 'confirmed', 'rescheduled', 'cancelled'])
        // The re-run, read off the mutex rows. The move created the Friday row at revision
        // zero, and the cancel's first attempt locked the Thursday alone, since its pre-read
        // ran while the move was still uncommitted; that attempt rolled back and took its own
        // bump with it, which is why the Thursday moved by one and not by two. The Friday
        // standing at one is the second attempt, taken after the key-set difference sent the
        // first back to step 1: without it the cancel would have answered busy and the Friday
        // would still read zero.
        ->and($thursday)->toBe(1)
        ->and($friday)->toBe(0)
        ->and(racingRevisionOf('2026-10-01'))->toBe(2)
        ->and(racingRevisionOf('2026-10-02'))->toBe(1)
        ->and(racingOverlaps())->toBe(0);
});

it('leaves exactly one of a move and a hold holding the slot they both want, released together with the scheduler deciding the order', function () {
    $uuid = racingConfirmed($this->cut);

    $run = ConcurrentRun::of([
        'move' => racingMoveWorker($uuid, '2026-10-02 09:00'),
        'hold' => racingHoldWorker($this->cut, '2026-10-02 09:00'),
    ])->start();
    $run->release();
    $outcomes = $run->wait();

    $move = returnedArray($outcomes['move']);
    $hold = returnedArray($outcomes['hold']);
    $rows = racingRows();
    $onFriday = array_values(array_filter($rows, fn (array $row): bool => $row['start_utc'] === '2026-10-02 09:00' && $row['blocks'] === true));

    expect(array_key_first($move))->toBeIn(['moved', 'conflict'])
        ->and(array_key_first($hold))->toBeIn(['held', 'conflict'])
        // Exactly one of them ended up in the Friday slot, whichever committed first, and the
        // one that did not is refused rather than half written.
        ->and(isset($move['moved']) ? ! isset($hold['held']) : isset($hold['held']))->toBeTrue()
        ->and($onFriday)->toHaveCount(1)
        ->and(racingOverlaps())->toBe(0)
        // The booking that did not move is still where it was, still blocking.
        ->and(array_values(array_filter($rows, fn (array $row): bool => $row['uuid'] === $uuid)))->toHaveCount(1);
});

it('refuses the move when the hold takes the target staff-day first, and leaves the booking exactly where it was', function () {
    $uuid = racingConfirmed($this->cut);

    $run = ConcurrentRun::of([
        'hold' => racingHoldWorker($this->cut, '2026-10-02 09:00'),
        'move' => racingMoveWorker($uuid, '2026-10-02 09:00', 'move.start'),
    ])->holding(HoldBooking::AFTER_LOCK, 'move.start')->start();
    $run->release();
    $run->awaitCheckpoint('hold', HoldBooking::AFTER_LOCK);
    $run->releaseCheckpoint('move.start');
    $waiting = lockWaiter(racingLockPrefix());
    $run->releaseCheckpoint(HoldBooking::AFTER_LOCK);
    $outcomes = $run->wait();

    $rows = racingRows();
    $trail = DB::table('tenant_acme.audit_log')->orderBy('id')->pluck('action')->all();

    expect($waiting['wait_event'])->toBeIn(['transactionid', 'tuple'])
        ->and(returnedArray($outcomes['hold'])['held'] ?? null)->toBeString()
        ->and(returnedArray($outcomes['move']))->toBe(['conflict' => 0])
        ->and($rows)->toHaveCount(2)
        // Nothing of the move survives: the booking still holds the Thursday it was sold for.
        ->and($rows[0])->toMatchArray(['uuid' => $uuid, 'status' => 'confirmed', 'blocks' => true])
        ->and($rows[0]['start_utc'])->toBe('2026-10-01 09:00')
        ->and($trail)->toBe(['held', 'confirmed', 'held'])
        ->and(racingOverlaps())->toBe(0);
});

it('holds the staff-day of a member a business move gives the booking to, so two moves of different bookings onto that member and time queue and one of them is refused, never both landing', function () {
    // A second member who performs the Cut and has no hours: the moves accept the warning, so
    // the only thing between them is each other.
    $ana = DB::table('tenant_acme.staff')->insertGetId(['name' => 'Ana', 'status' => 'active', 'sort' => 1]);
    DB::table('tenant_acme.service_staff')->insert(['service_id' => $this->cut, 'staff_id' => $ana]);
    // On staff member 1, on a Thursday and on the Monday after: the two moves share no staff-day
    // they are leaving, so the one they can queue on is Ana's Friday, where both are going.
    $thursday = racingConfirmedAt($this->cut, '2026-10-01 09:00');
    $monday = racingConfirmedAt($this->cut, '2026-10-05 09:00');

    $run = ConcurrentRun::of([
        'first' => racingBusinessMoveWorker($thursday, '2026-10-02 09:00', $ana),
        'second' => racingBusinessMoveWorker($monday, '2026-10-02 09:00', $ana, 'second.start'),
    ])->holding(RescheduleBooking::AFTER_LOCK, 'second.start')->start();
    $run->release();
    $run->awaitCheckpoint('first', RescheduleBooking::AFTER_LOCK);
    $run->releaseCheckpoint('second.start');
    $waiting = lockWaiter(racingLockPrefix());
    $run->releaseCheckpoint(RescheduleBooking::AFTER_LOCK);
    $outcomes = $run->wait();

    $rows = racingRows();

    expect($waiting['wait_event'])->toBeIn(['transactionid', 'tuple'])
        ->and(returnedArray($outcomes['first']))->toBe(['moved' => true])
        ->and(returnedArray($outcomes['second']))->toBe(['conflict' => 0])
        ->and(array_column($rows, 'start_utc'))->toBe(['2026-10-02 09:00', '2026-10-05 09:00'])
        ->and(DB::table('tenant_acme.booking_items')->where('staff_id', $ana)->count())->toBe(1)
        ->and(racingOverlaps())->toBe(0);
});
