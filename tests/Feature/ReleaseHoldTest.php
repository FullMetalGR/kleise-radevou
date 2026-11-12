<?php

use App\Application\Clock;
use App\Application\Dto\Customer;
use App\Application\Dto\HoldRequest;
use App\Application\Dto\Intake;
use App\Application\Dto\SegmentChoice;
use App\Application\Event\BookingCancelled;
use App\Application\Event\BookingReleased;
use App\Application\ReleaseHold;
use App\Application\TransitionRefused;
use App\Domain\Audit\Actor;
use App\Domain\Booking\TransitionRefusal;
use App\Domain\Enum\BookingSource;
use App\Infrastructure\BookingTransitionFactory;
use App\Infrastructure\Db\Pool;
use App\Infrastructure\HoldBookingFactory;
use App\Tenancy\Tenant;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;
use Tests\Support\FixedClock;
use Tests\Support\FixtureDay;

pest()->use(DatabaseTruncation::class);

/*
 * ReleaseHold on luna's real rows: the customer handing a checkout hold back on page hide or on
 * "Pick another time".
 *
 * Not wrapped in a transaction: every transition enters the transaction runner, which refuses
 * to open inside another.
 *
 * luna is in Europe/Athens, UTC+2 in November. 2026-11-03 is a Tuesday, when Elena works 09:00
 * to 13:00. The clock stands on the Monday before; no window is in play here, which is half of
 * what this file is about.
 */

const RELEASE_CUSTOMER_NAME = 'Marigold Featherstonehaugh';

const RELEASE_CUSTOMER_EMAIL = 'marigold.f@example.test';

const RELEASE_CUSTOMER_PHONE = '+30 210 2223334';

beforeEach(function () {
    dropDemoSchemas();
    seedDemo();
    app()->instance(Clock::class, FixedClock::at('2026-11-02 06:00:00'));
});

afterEach(function () {
    DB::purge('release_probe');
    dropDemoSchemas();
    DB::table('users')->delete();
    DB::table('tenants')->delete();
});

function releaseTenant(): Tenant
{
    return Tenant::query()->where('slug', 'luna')->sole();
}

function releaseUseCase(): ReleaseHold
{
    return app(BookingTransitionFactory::class)->release(releaseTenant());
}

function releaseLunaId(string $table, string $name): int
{
    $id = DB::table('tenant_luna.'.$table)->where('name', $name)->value('id');

    assert(is_int($id));

    return $id;
}

/**
 * A checkout hold taken the way the booking page takes one.
 */
function releaseHeld(string $service = 'Haircut', string $start = FixtureDay::START): string
{
    return app(HoldBookingFactory::class)->for(releaseTenant(), Pool::Whole)->hold(
        new HoldRequest(
            new Customer(RELEASE_CUSTOMER_NAME, RELEASE_CUSTOMER_EMAIL, RELEASE_CUSTOMER_PHONE),
            [new SegmentChoice(releaseLunaId('services', $service), releaseLunaId('staff', 'Elena Papadaki'))],
            new DateTimeImmutable($start, new DateTimeZone('Europe/Athens')),
            new Intake(BookingSource::Embed),
        ),
        Actor::customer(),
    )->uuid;
}

function releaseInstant(string $athens): string
{
    return (new DateTimeImmutable($athens, new DateTimeZone('Europe/Athens')))
        ->setTimezone(new DateTimeZone('UTC'))
        ->format('Y-m-d H:i:s.uP');
}

/**
 * A booking of luna in the state the test names, written straight to the rows.
 *
 * @param  string  $expiry  past, future, or none
 */
function releaseSeeded(string $status, ?string $holdClass, string $expiry, string $hour, bool $blocks): string
{
    $uuid = (string) Str::uuid();

    $bookingId = DB::table('tenant_luna.bookings')->insertGetId([
        'source' => 'embed',
        'uuid' => $uuid,
        'status' => $status,
        'hold_class' => $holdClass,
        'hold_expires_at' => match ($expiry) {
            'past' => DB::raw("now() - interval '1 minute'"),
            'future' => DB::raw("now() + interval '2 days'"),
            'none' => null,
            default => throw new LogicException(sprintf('An expiry is past, future or none, not "%s".', $expiry)),
        },
        'customer_name' => RELEASE_CUSTOMER_NAME,
        'customer_email' => RELEASE_CUSTOMER_EMAIL,
        'customer_phone' => RELEASE_CUSTOMER_PHONE,
        'total_minor' => 3500,
        'currency' => 'EUR',
        'payment_mode' => 'onsite',
        'requires_approval' => false,
        'on_approval_timeout' => 'expire',
        'manage_token_hash' => str_repeat('c', 64),
    ]);

    DB::table('tenant_luna.booking_items')->insert([
        'booking_id' => $bookingId,
        'sort' => 0,
        'service_id' => releaseLunaId('services', 'Haircut'),
        'staff_id' => releaseLunaId('staff', 'Elena Papadaki'),
        'start_utc' => releaseInstant('2026-11-03 '.$hour.':00'),
        'end_utc' => releaseInstant('2026-11-03 '.$hour.':45'),
        'block_start_utc' => releaseInstant('2026-11-03 '.$hour.':00'),
        'block_end_utc' => releaseInstant('2026-11-03 '.$hour.':45'),
        'price_minor' => 3500,
        'blocks' => $blocks,
    ]);

    return $uuid;
}

/**
 * @return array<string, mixed>
 */
function releaseRow(string $uuid): array
{
    $row = DB::selectOne(
        'select b.status, b.hold_class, b.hold_expires_at::text as hold_expires_at, i.blocks'
        .' from tenant_luna.bookings b join tenant_luna.booking_items i on i.booking_id = b.id'
        .' where b.uuid = ?::uuid',
        [$uuid],
    );

    assert($row instanceof stdClass);

    /** @var array<string, mixed> $values */
    $values = get_object_vars($row);

    return $values;
}

/**
 * @return list<array<mixed>>
 */
function releaseTrail(): array
{
    $rows = [];

    foreach (DB::select(
        'select a.actor, a.action, a.payload::text as payload, b.uuid::text as uuid'
        .' from tenant_luna.audit_log a join tenant_luna.bookings b on b.id = a.booking_id order by a.id'
    ) as $row) {
        assert($row instanceof stdClass && is_string($row->payload));

        /** @var array<string, mixed> $payload */
        $payload = json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR);
        ksort($payload);

        $values = get_object_vars($row);
        $values['payload'] = $payload;

        $rows[] = $values;
    }

    return $rows;
}

function releaseRefusal(Closure $run): TransitionRefused
{
    try {
        $run();
    } catch (TransitionRefused $refused) {
        return $refused;
    }

    Assert::fail('No TransitionRefused was thrown.');
}

function releaseProbe(): Connection
{
    /** @var array<string, mixed> $pgsql */
    $pgsql = config('database.connections.pgsql');
    config(['database.connections.release_probe' => $pgsql]);

    return DB::connection('release_probe');
}

it('releases a pending hold: the row lands cancelled with its hold cleared and its item free, the trail says released by the customer, and the released event goes out after the commit', function () {
    $seen = [];
    Event::listen(BookingReleased::class, function (BookingReleased $event) use (&$seen): void {
        $seen[] = [
            'uuid' => $event->uuid,
            'from' => $event->from?->value,
            'to' => $event->to->value,
            'hold_class' => $event->holdClass?->value,
            'actor' => $event->actor->value,
            'level' => DB::transactionLevel(),
            'committed' => releaseProbe()->scalar('select status from tenant_luna.bookings where uuid = ?::uuid', [$event->uuid]),
        ];
    });
    $cancelled = [];
    Event::listen(BookingCancelled::class, function () use (&$cancelled): void {
        $cancelled[] = 'cancelled';
    });

    $uuid = releaseHeld();

    releaseUseCase()->release($uuid);

    $trail = releaseTrail();

    expect(releaseRow($uuid))->toMatchArray([
        'status' => 'cancelled',
        'hold_class' => null,
        'hold_expires_at' => null,
        'blocks' => false,
    ])
        ->and($trail)->toHaveCount(2)
        ->and($trail[1])->toMatchArray(['uuid' => $uuid, 'action' => 'released', 'actor' => 'customer'])
        ->and($trail[1]['payload'])->toBe(['hold_class' => 'checkout', 'status_from' => 'pending', 'status_to' => 'cancelled'])
        ->and($seen)->toBe([[
            'uuid' => $uuid,
            'from' => 'pending',
            'to' => 'cancelled',
            'hold_class' => 'checkout',
            'actor' => 'customer',
            'level' => 0,
            'committed' => 'cancelled',
        ]])
        // The act is announced, not the state: a listener tells an abandoned checkout
        // from a cancelled appointment by type, because by then both rows read
        // cancelled and the class that would have said which is nulled with them.
        ->and($cancelled)->toBe([]);
});

it('refuses to release {status}, so a request lodged with the owner survives the tab closing, leaving the row as it was with no trail and nothing published', function (string $status, ?string $holdClass, string $expiry, string $hour, bool $blocks) {
    $published = [];
    Event::listen(BookingReleased::class, function () use (&$published): void {
        $published[] = 'released';
    });

    $uuid = releaseSeeded($status, $holdClass, $expiry, $hour, $blocks);
    $before = releaseRow($uuid);

    $refused = releaseRefusal(fn () => releaseUseCase()->release($uuid));

    expect($refused->reason)->toBe(TransitionRefusal::NotReleasable)
        ->and($refused->status->value)->toBe($status)
        ->and(releaseRow($uuid))->toBe($before)
        ->and(releaseTrail())->toBe([])
        ->and($published)->toBe([]);
})->with([
    'a request waiting on the owner' => ['awaiting_approval', 'approval', 'future', '09', true],
    'an approved booking waiting to be paid' => ['awaiting_payment', 'payment', 'future', '10', true],
    'a booking already confirmed' => ['confirmed', null, 'none', '11', true],
    'a booking that has happened' => ['completed', null, 'none', '12', false],
    'a booking nobody turned up for' => ['no_show', null, 'none', '14', false],
    'a booking already cancelled' => ['cancelled', null, 'none', '15', false],
    'a request the owner rejected' => ['rejected', null, 'none', '16', false],
    'a hold the sweeper expired' => ['expired', null, 'past', '17', false],
]);

it('releases a pending hold whose expiry has already passed, because the slot is free by the clock either way and the trail is truer for saying who gave it back', function () {
    $uuid = releaseSeeded('pending', 'checkout', 'past', '09', true);

    releaseUseCase()->release($uuid);

    expect(releaseRow($uuid))->toMatchArray(['status' => 'cancelled', 'blocks' => false])
        ->and(releaseTrail()[0]['action'] ?? null)->toBe('released');
});

it('holds the privacy assertion over a release: no actor and no payload of the trail names the customer who booked', function () {
    $uuid = releaseHeld();

    releaseUseCase()->release($uuid);

    $trail = DB::scalar("select string_agg(actor || ' ' || action || ' ' || payload::text, ' ') from tenant_luna.audit_log");

    expect(auditOffences())->toBe([])
        ->and($trail)->toBeString()
        ->and($trail)->not->toContain(RELEASE_CUSTOMER_NAME)
        ->and($trail)->not->toContain(RELEASE_CUSTOMER_EMAIL)
        ->and($trail)->not->toContain(RELEASE_CUSTOMER_PHONE)
        ->and(DB::table('tenant_luna.bookings')->where('uuid', $uuid)->value('customer_email'))->toBe(RELEASE_CUSTOMER_EMAIL);
});
