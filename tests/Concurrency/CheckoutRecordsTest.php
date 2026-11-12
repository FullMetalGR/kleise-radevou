<?php

use App\Application\Checkpoint;
use App\Application\Clock;
use App\Application\Dto\Customer;
use App\Application\Dto\Held;
use App\Application\Dto\HoldRequest;
use App\Application\Dto\Intake;
use App\Application\Dto\SegmentChoice;
use App\Application\GuardedWrite;
use App\Application\Payment\ConnectionMode;
use App\Application\Payment\ConnectionState;
use App\Application\Payment\NewConnection;
use App\Domain\Audit\Actor;
use App\Domain\Enum\BookingSource;
use App\Infrastructure\Db\Pool;
use App\Infrastructure\Db\PostgresCheckoutRecords;
use App\Infrastructure\HoldBookingFactory;
use App\Infrastructure\PaymentFactory;
use App\Tenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakePaymentAdapter;
use Tests\Support\FixedClock;
use Tests\Support\LunaFixture;

/*
 * The pay hop's write against a move, as PostgresCheckoutRecords spells it outside the shared
 * implementation: the key set read with no transaction open, then one transaction locking and
 * bumping those staff-days, the items read again under the lock, and the guarded update of the
 * booking row.
 *
 * The move lands at the checkpoint between the key set's read and the transaction, on a
 * connection of its own that commits before the hop's lock is asked for: the one moment a move
 * leaves the hop holding the wrong staff-days. It is written as a move's own transaction writes
 * it, both staff-days bumped by the lock statement and the items shifted a day, so the revisions
 * read afterwards say which attempt of the hop's committed. No worker process is needed, since
 * nothing waits on a lock: the move has committed before the hop takes one.
 *
 * acme gets a Cut of sixty minutes paid online on its implicit staff member, and the payment fake
 * connected and active. 2026-10-01 is a Thursday, 09:00 UTC is 12:00 in Athens, inside the hours,
 * and the clock stands on the Wednesday before. The hold's expiry is the database's now() plus the
 * checkout TTL, the clock the hop's liveness reads.
 */

beforeEach(function () {
    DB::statement('drop schema if exists tenant_acme cascade');
    provisionTenant('acme');
    $this->cut = DB::table('tenant_acme.services')->insertGetId(serviceRow('Cut', ['duration_min' => 60, 'price_minor' => 2500, 'payment_mode' => 'online']));
    DB::table('tenant_acme.service_staff')->insert(['service_id' => $this->cut, 'staff_id' => 1]);
    app()->instance(Clock::class, FixedClock::at('2026-09-30 12:00:00'));
    app(PaymentFactory::class)->connections(hopRaceTenant())->write(new NewConnection(FakePaymentAdapter::SLUG, ConnectionMode::Own, ConnectionState::Active, paymentFakeCredentials(), null, 'hook-token-acme'));
});

afterEach(function () {
    Checkpoint::observe(null);
    DB::purge('hop_race_probe');
    DB::statement('drop schema if exists tenant_acme cascade');
    DB::table('payment_routes')->delete();
});

function hopRaceTenant(): Tenant
{
    return Tenant::query()->where('slug', 'acme')->sole();
}

function hopRaceHold(int $serviceId): Held
{
    return app(HoldBookingFactory::class)->for(hopRaceTenant(), Pool::Whole)->hold(
        new HoldRequest(
            new Customer('Casey Customer', 'casey@example.com'),
            [new SegmentChoice($serviceId, 1)],
            new DateTimeImmutable('2026-10-01 09:00', new DateTimeZone('UTC')),
            new Intake(BookingSource::Embed),
        ),
        Actor::customer(),
    );
}

/**
 * A move of the booking a day later, committed on a connection of its own as a move's
 * transaction writes it: the lock statement over the day it leaves and the day it takes, then the
 * items shifted. Made at the checkpoint between the hop's key set read and its transaction, at
 * the first attempt alone or at every one.
 */
function hopRaceMovedBeforeTheLock(string $uuid, bool $everyAttempt, int &$moves): void
{
    Checkpoint::observe(function (string $name) use ($uuid, $everyAttempt, &$moves): void {
        if ($name !== PostgresCheckoutRecords::KEYED || ($moves > 0 && ! $everyAttempt)) {
            return;
        }

        $moves++;
        $probe = LunaFixture::probe('hop_race_probe');
        $probe->transaction(function () use ($probe, $uuid): void {
            $day = $probe->scalar("select to_char((i.start_utc at time zone 'Europe/Athens')::date, 'YYYY-MM-DD') from tenant_acme.booking_items i join tenant_acme.bookings b on b.id = i.booking_id where b.uuid = ?::uuid", [$uuid]);
            assert(is_string($day));
            $next = (new DateTimeImmutable($day, new DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d');

            $probe->select('insert into tenant_acme.staff_days (staff_id, day, rev) values (1, ?, 0), (1, ?, 0) on conflict (staff_id, day) do update set rev = staff_days.rev + 1 returning rev', [$day, $next]);
            $probe->update(
                "update tenant_acme.booking_items set start_utc = start_utc + interval '1 day',"
                ." end_utc = end_utc + interval '1 day', block_start_utc = block_start_utc + interval '1 day',"
                ." block_end_utc = block_end_utc + interval '1 day', processing_ends_utc = processing_ends_utc + interval '1 day'"
                .' from tenant_acme.bookings b where b.id = booking_id and b.uuid = ?::uuid',
                [$uuid],
            );
        });
    });
}

function hopRaceRevisionOf(string $day): ?int
{
    $rev = DB::scalar('select rev from tenant_acme.staff_days where staff_id = 1 and day = ?::date', [$day]);

    return is_int($rev) ? $rev : null;
}

/**
 * @return array{payment_ref: string|null, hold_expires_at: string|null, sealed: bool}
 */
function hopRaceRow(string $uuid): array
{
    $row = (array) DB::selectOne(
        "select payment_ref, to_char(hold_expires_at at time zone 'UTC', 'YYYY-MM-DD HH24:MI:SS.US') as hold_expires_at,"
        .' manage_token_sealed is not null as sealed from tenant_acme.bookings where uuid = ?::uuid',
        [$uuid],
    );

    return [
        'payment_ref' => is_string($row['payment_ref'] ?? null) ? $row['payment_ref'] : null,
        'hold_expires_at' => is_string($row['hold_expires_at'] ?? null) ? $row['hold_expires_at'] : null,
        'sealed' => ($row['sealed'] ?? false) === true,
    ];
}

it('runs the hop\'s write again from the key set\'s read when a move landed between that read and the lock, and records the session on the staff-day the booking moved to, bumping it, with the provider asked once', function () {
    $held = hopRaceHold($this->cut);
    $moves = 0;
    hopRaceMovedBeforeTheLock($held->uuid, false, $moves);
    $thursday = hopRaceRevisionOf('2026-10-01');

    $this->get(tenantUrl('acme', '/pay/'.$held->uuid.'?token='.$held->manageToken))->assertStatus(303);
    $row = hopRaceRow($held->uuid);

    expect($moves)->toBe(1)
        ->and($thursday)->toBeInt()
        // The move bumped both days once. The hop's first attempt locked the Thursday, found the
        // items gone from it under the lock and rolled back with its bump; the second locked
        // the Friday, the day the booking is on now, and committed its bump with the write.
        ->and(hopRaceRevisionOf('2026-10-01'))->toBe(($thursday ?? 0) + 1)
        ->and(hopRaceRevisionOf('2026-10-02'))->toBe(1)
        ->and($row['payment_ref'])->toBe('pd_1')
        ->and($row['sealed'])->toBeTrue()
        ->and(paymentFake()->created())->toBe(1)
        ->and(paymentFake()->asked)->toHaveCount(1);
});

it('answers false after GuardedWrite::ATTEMPTS runs when a move lands before the lock every time, the hop reading the row again and answering the unopened page\'s words at 503 with a Retry-After as a lost race, payment_ref untouched, the provider asked once and nothing logged', function () {
    $held = hopRaceHold($this->cut);
    $before = hopRaceRow($held->uuid);
    $moves = 0;
    hopRaceMovedBeforeTheLock($held->uuid, true, $moves);
    $thursday = hopRaceRevisionOf('2026-10-01');
    $log = runnerLog();

    // Lock contention, the house's 503 busy, not the 502 a provider's failure answers. A key set
    // that moved is not among the failures the runner logs, so the runner writes no line for it,
    // and nothing else does either.
    $this->get(tenantUrl('acme', '/pay/'.$held->uuid.'?token='.$held->manageToken))
        ->assertStatus(503)
        ->assertHeader('Retry-After', '1')
        ->assertSee(pageSentence('pages.pay.unopened.title'));

    expect($log->getRecords())->toBe([])
        ->and($moves)->toBe(GuardedWrite::ATTEMPTS)
        ->and(hopRaceRow($held->uuid))->toBe($before)
        ->and($before['payment_ref'])->toBeNull()
        // Each move bumped the day it left and the day it took; each of the hop's attempts
        // rolled its own bump back, so the revisions are the moves' alone.
        ->and(hopRaceRevisionOf('2026-10-01'))->toBe(($thursday ?? 0) + 1)
        ->and(hopRaceRevisionOf('2026-10-02'))->toBe(1)
        ->and(hopRaceRevisionOf('2026-10-03'))->toBe(0)
        ->and(paymentFake()->created())->toBe(1)
        ->and(paymentFake()->asked)->toHaveCount(1);
});
