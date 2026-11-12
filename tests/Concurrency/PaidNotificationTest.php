<?php

use App\Application\Checkpoint;
use App\Application\Clock;
use App\Application\Dto\Customer;
use App\Application\Dto\HoldRequest;
use App\Application\Dto\Intake;
use App\Application\Dto\SegmentChoice;
use App\Application\GuardedWrite;
use App\Application\Payment\ConnectionMode;
use App\Application\Payment\ConnectionState;
use App\Application\Payment\NewConnection;
use App\Application\TransitionRefused;
use App\Domain\Audit\Actor;
use App\Domain\Enum\BookingSource;
use App\Infrastructure\BookingTransitionFactory;
use App\Infrastructure\Db\LockManager;
use App\Infrastructure\Db\Pool;
use App\Infrastructure\HoldBookingFactory;
use App\Infrastructure\Payment\PaymentWebhook;
use App\Infrastructure\Payment\VerifiedNotice;
use App\Infrastructure\PaymentFactory;
use App\Tenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Concurrency\Harness\ConcurrentRun;
use Tests\Support\FakePaymentAdapter;
use Tests\Support\FixedClock;

/*
 * The paid transition beside the customer's release of the same hold: both are guarded writes
 * on one booking, so the staff-day lock serialises them and whichever comes second finds the
 * booking moved on under its re-read. Exactly one lands. The release is the cancel a held
 * booking takes, "any held state -> cancelled"; an admin's cancel of a booking already
 * confirmed is a later act and not a race, so it is not the competitor here.
 *
 * The notification goes through the webhook's own two steps in the worker, verify and receive,
 * so the signature check, the record, the paid transition and the mark all run there. acme is
 * connected to the fake in own mode, its Cut is paid online, and the hold's liveness is the
 * database's now() plus fifteen minutes.
 */

beforeEach(function () {
    DB::statement('drop schema if exists tenant_acme cascade');
    provisionTenant('acme');
    $this->cut = DB::table('tenant_acme.services')->insertGetId(serviceRow('Cut', ['duration_min' => 60, 'price_minor' => 2500, 'payment_mode' => 'online']));
    DB::table('tenant_acme.service_staff')->insert(['service_id' => $this->cut, 'staff_id' => 1]);
    app()->instance(Clock::class, FixedClock::at('2026-09-30 12:00:00'));
    app(PaymentFactory::class)->connections(paidRaceTenant())->write(new NewConnection(FakePaymentAdapter::SLUG, ConnectionMode::Own, ConnectionState::Active, paymentFakeCredentials(), null, 'hook-token-acme'));
});

afterEach(function () {
    ConcurrentRun::abandonAll();
    DB::statement('drop schema if exists tenant_acme cascade');
    DB::table('payment_events')->delete();
    DB::table('payment_routes')->delete();
});

function paidRaceTenant(): Tenant
{
    return Tenant::query()->where('slug', 'acme')->sole();
}

function paidRaceHold(int $serviceId): string
{
    return app(HoldBookingFactory::class)->for(paidRaceTenant(), Pool::Whole)->hold(
        new HoldRequest(
            new Customer('Casey Customer', 'casey@example.com'),
            [new SegmentChoice($serviceId, 1)],
            new DateTimeImmutable('2026-10-01 09:00', new DateTimeZone('UTC')),
            new Intake(BookingSource::Embed),
        ),
        Actor::customer(),
    )->uuid;
}

/**
 * The notification's delivery as a worker: verified with acme's signing phrase and received,
 * answering whether the check passed. What it did to the booking is read from the rows.
 */
function paidRaceNotice(string $uuid, ?string $gate = null): Closure
{
    $body = json_encode(['id' => 'evt_race', 'type' => FakePaymentAdapter::SETTLED, 'booking' => $uuid, 'payment' => 'pd_1'], JSON_THROW_ON_ERROR);
    $seal = FakePaymentAdapter::seal($body, paymentFakeCredentials()['signing_phrase']);

    return function () use ($body, $seal, $gate): array {
        if ($gate !== null) {
            Checkpoint::reach($gate);
        }

        app()->instance(Clock::class, FixedClock::at('2026-09-30 12:00:00'));
        $webhook = app(PaymentWebhook::class);
        $verified = $webhook->verify(FakePaymentAdapter::SLUG, 'hook-token-acme', $body, fn (string $name): string => $name === 'Paydesk-Seal' ? $seal : '');

        if (! $verified instanceof VerifiedNotice) {
            return ['refused' => true];
        }

        $webhook->receive($verified);

        return ['received' => true];
    };
}

function paidRaceRelease(string $uuid, ?string $gate = null): Closure
{
    return function () use ($uuid, $gate): array {
        if ($gate !== null) {
            Checkpoint::reach($gate);
        }

        app()->instance(Clock::class, FixedClock::at('2026-09-30 12:00:00'));

        try {
            app(BookingTransitionFactory::class)->release(Tenant::query()->where('slug', 'acme')->sole())->release($uuid);

            return ['released' => true];
        } catch (TransitionRefused $refused) {
            return ['refused' => $refused->reason->value, 'found' => $refused->status->value];
        }
    };
}

/**
 * @return array{status: mixed, trail: array<mixed>, processed: bool}
 */
function paidRaceEnd(string $uuid): array
{
    return [
        'status' => DB::table('tenant_acme.bookings')->where('uuid', $uuid)->value('status'),
        'trail' => DB::table('tenant_acme.audit_log')->orderBy('id')->pluck('action')->all(),
        'processed' => DB::table('payment_events')->where('event_id', 'evt_race')->value('processed_at') !== null,
    ];
}

it('lands the payment and refuses the release when the notification takes the staff-day first', function () {
    $uuid = paidRaceHold($this->cut);

    $run = ConcurrentRun::of([
        'paid' => paidRaceNotice($uuid),
        'release' => paidRaceRelease($uuid, 'release.start'),
    ])->holding(GuardedWrite::AFTER_LOCK, 'release.start')->start();
    $run->release();
    $run->awaitCheckpoint('paid', GuardedWrite::AFTER_LOCK);
    $run->releaseCheckpoint('release.start');
    $waiting = lockWaiter(paidRaceLockPrefix());
    $run->releaseCheckpoint(GuardedWrite::AFTER_LOCK);
    $outcomes = $run->wait();

    expect($waiting['wait_event'])->toBeIn(['transactionid', 'tuple'])
        ->and(returnedArray($outcomes['paid']))->toBe(['received' => true])
        ->and(returnedArray($outcomes['release']))->toBe(['refused' => 'not_releasable', 'found' => 'confirmed'])
        ->and(paidRaceEnd($uuid))->toBe(['status' => 'confirmed', 'trail' => ['held', 'confirmed'], 'processed' => true]);
});

it('lands the release and confirms nothing when the release takes the staff-day first, the event still marked', function () {
    $uuid = paidRaceHold($this->cut);

    $run = ConcurrentRun::of([
        'release' => paidRaceRelease($uuid),
        'paid' => paidRaceNotice($uuid, 'paid.start'),
    ])->holding(GuardedWrite::AFTER_LOCK, 'paid.start')->start();
    $run->release();
    $run->awaitCheckpoint('release', GuardedWrite::AFTER_LOCK);
    $run->releaseCheckpoint('paid.start');
    $waiting = lockWaiter(paidRaceLockPrefix());
    $run->releaseCheckpoint(GuardedWrite::AFTER_LOCK);
    $outcomes = $run->wait();

    expect($waiting['wait_event'])->toBeIn(['transactionid', 'tuple'])
        ->and(returnedArray($outcomes['release']))->toBe(['released' => true])
        ->and(returnedArray($outcomes['paid']))->toBe(['received' => true])
        ->and(paidRaceEnd($uuid))->toBe(['status' => 'cancelled', 'trail' => ['held', 'released'], 'processed' => true]);
});

it('ends with exactly one of the two landed when the scheduler decides the order', function () {
    $uuid = paidRaceHold($this->cut);

    $outcomes = ConcurrentRun::of([
        'paid' => paidRaceNotice($uuid),
        'release' => paidRaceRelease($uuid),
    ])->run();
    $end = paidRaceEnd($uuid);

    expect(returnedArray($outcomes['paid']))->toBe(['received' => true])
        ->and($end['processed'])->toBeTrue()
        ->and([$end['status'], $end['trail']])->toBeIn([
            ['confirmed', ['held', 'confirmed']],
            ['cancelled', ['held', 'released']],
        ])
        ->and(returnedArray($outcomes['release']))->toBe($end['status'] === 'confirmed' ? ['refused' => 'not_releasable', 'found' => 'confirmed'] : ['released' => true]);
});

function paidRaceLockPrefix(): string
{
    $prefix = strstr(LockManager::sql(1), '(?', true);

    assert(is_string($prefix));

    return $prefix;
}
