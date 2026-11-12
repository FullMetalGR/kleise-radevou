<?php

use App\Application\CancelBooking;
use App\Application\Clock;
use App\Application\Dto\Customer;
use App\Application\Dto\HoldRequest;
use App\Application\Dto\Intake;
use App\Application\Dto\SegmentChoice;
use App\Application\Event\BookingCancelled;
use App\Application\TransitionRefused;
use App\Domain\Audit\Actor;
use App\Domain\Booking\TransitionRefusal;
use App\Domain\Enum\BookingSource;
use App\Domain\Enum\BookingStatus;
use App\Infrastructure\BookingTransitionFactory;
use App\Infrastructure\Cache\StaffDayMaskCache;
use App\Infrastructure\Db\AuditRow;
use App\Infrastructure\Db\BlockingItems;
use App\Infrastructure\Db\Pool;
use App\Infrastructure\Db\PostgresBookingWriter;
use App\Infrastructure\HoldBookingFactory;
use App\Tenancy\Tenant;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;
use Tests\Support\FixedClock;
use Tests\Support\FixtureDay;
use Tests\TestCase;

pest()->use(DatabaseTruncation::class);

/*
 * CancelBooking on luna's real rows through the factory: what a cancel lands, who it lets do
 * it, when it is too late, and what it gives back to the diary.
 *
 * Not wrapped in a transaction: every transition enters the transaction runner, which refuses
 * to open inside another. The mask cache is the null store, so the availability read at the end
 * of the first test comes from rows alone.
 *
 * luna is in Europe/Athens, UTC+2 in November. 2026-11-03 is a Tuesday, when Elena works 09:00
 * to 13:00 and 14:00 to 18:00. The clock stands on the Monday before unless a test moves it,
 * which is outside every window here. The customer of these holds is given a name, an address
 * and a phone nothing else in the fixtures shares, so the privacy assertion has something
 * distinctive to look for.
 */

const CANCEL_CUSTOMER_NAME = 'Thaddeus Quillsworth';

const CANCEL_CUSTOMER_EMAIL = 'thaddeus.quillsworth@example.test';

const CANCEL_CUSTOMER_PHONE = '+30 210 7654321';

beforeEach(function () {
    dropDemoSchemas();
    seedDemo();
    app()->instance(StaffDayMaskCache::class, coldMaskCache());
    cancelClockAt('2026-11-02 06:00:00');
});

afterEach(function () {
    DB::purge('cancel_probe');
    dropDemoSchemas();
    DB::table('users')->delete();
    DB::table('tenants')->delete();
});

/**
 * The instant every use case built after this call reads as now. The factory is resolved fresh
 * each time, so moving the clock moves what the next transition compares a window against.
 */
function cancelClockAt(string $utc): void
{
    app()->instance(Clock::class, FixedClock::at($utc));
}

function cancelTenant(): Tenant
{
    return Tenant::query()->where('slug', 'luna')->sole();
}

function cancelUseCase(): CancelBooking
{
    return app(BookingTransitionFactory::class)->cancel(cancelTenant());
}

function cancelLunaId(string $table, string $name): int
{
    $id = DB::table('tenant_luna.'.$table)->where('name', $name)->value('id');

    assert(is_int($id));

    return $id;
}

/**
 * The cancel window a service imposes on the customer, in hours, set on the catalog row. The
 * policy reads it at cancel time, so a test sets it before it cancels and not before it books.
 */
function cancelWindowOf(string $service, int $hours): void
{
    DB::table('tenant_luna.services')->where('name', $service)->update(['cancel_window_hours' => $hours]);
}

/**
 * A chain held the way the booking page takes one: the services in order on Elena, at the
 * Athens wall-clock time given.
 *
 * @param  non-empty-list<string>  $services
 * @return string the uuid of the booking it left
 */
function cancelHeld(array $services = ['Haircut'], string $start = FixtureDay::START): string
{
    return app(HoldBookingFactory::class)->for(cancelTenant(), Pool::Whole)->hold(
        new HoldRequest(
            new Customer(CANCEL_CUSTOMER_NAME, CANCEL_CUSTOMER_EMAIL, CANCEL_CUSTOMER_PHONE),
            array_map(fn (string $service): SegmentChoice => new SegmentChoice(cancelLunaId('services', $service), cancelLunaId('staff', 'Elena Papadaki')), $services),
            new DateTimeImmutable($start, new DateTimeZone('Europe/Athens')),
            new Intake(BookingSource::Embed),
        ),
        Actor::customer(),
    )->uuid;
}

/**
 * A held booking settled, so the test has a confirmed one to cancel.
 *
 * @param  non-empty-list<string>  $services
 */
function cancelConfirmed(array $services = ['Haircut'], string $start = FixtureDay::START): string
{
    $uuid = cancelHeld($services, $start);
    app(BookingTransitionFactory::class)->confirm(cancelTenant())->confirm($uuid, Actor::customer());

    return $uuid;
}

/**
 * An instant as the server writes one: UTC text with the offset written out.
 */
function cancelInstant(string $athens): string
{
    return (new DateTimeImmutable($athens, new DateTimeZone('Europe/Athens')))
        ->setTimezone(new DateTimeZone('UTC'))
        ->format('Y-m-d H:i:s.uP');
}

/**
 * A booking of luna written straight to the rows, in the state the test names, with one Haircut
 * item on Elena over the Athens hour given. Some of these states are reachable no other way,
 * and a guarded transition reads no mask, so a row in the state is the whole fixture.
 *
 * @param  string  $expiry  past, future, or none
 * @return string the uuid
 */
function cancelSeeded(string $status, ?string $holdClass, string $expiry, string $hour, bool $blocks): string
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
        'customer_name' => CANCEL_CUSTOMER_NAME,
        'customer_email' => CANCEL_CUSTOMER_EMAIL,
        'customer_phone' => CANCEL_CUSTOMER_PHONE,
        'total_minor' => 3500,
        'currency' => 'EUR',
        'payment_mode' => 'onsite',
        'requires_approval' => false,
        'on_approval_timeout' => 'expire',
        'manage_token_hash' => str_repeat('b', 64),
    ]);

    DB::table('tenant_luna.booking_items')->insert([
        'booking_id' => $bookingId,
        'sort' => 0,
        'service_id' => cancelLunaId('services', 'Haircut'),
        'staff_id' => cancelLunaId('staff', 'Elena Papadaki'),
        'start_utc' => cancelInstant('2026-11-03 '.$hour.':00'),
        'end_utc' => cancelInstant('2026-11-03 '.$hour.':45'),
        'block_start_utc' => cancelInstant('2026-11-03 '.$hour.':00'),
        'block_end_utc' => cancelInstant('2026-11-03 '.$hour.':45'),
        'price_minor' => 3500,
        'blocks' => $blocks,
    ]);

    return $uuid;
}

/**
 * The booking as the rows stand, with the blocking flag of every item in chain order.
 *
 * @return array<string, mixed>
 */
function cancelRow(string $uuid): array
{
    $row = DB::selectOne(
        'select status, hold_class, hold_expires_at::text as hold_expires_at, total_minor, payment_mode'
        .' from tenant_luna.bookings where uuid = ?::uuid',
        [$uuid],
    );

    assert($row instanceof stdClass);

    $blocking = [];

    foreach (DB::select(
        'select i.blocks from tenant_luna.bookings b join tenant_luna.booking_items i on i.booking_id = b.id'
        .' where b.uuid = ?::uuid order by i.sort',
        [$uuid],
    ) as $item) {
        assert($item instanceof stdClass && is_bool($item->blocks));

        $blocking[] = $item->blocks;
    }

    /** @var array<string, mixed> $booking */
    $booking = [...get_object_vars($row), 'blocks' => $blocking];

    return $booking;
}

/**
 * The trail of luna, oldest first, each row with the booking it names and its payload decoded.
 *
 * @return list<array<mixed>>
 */
function cancelTrail(): array
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

/**
 * What the closure threw as a refusal. Anything else, or nothing, fails the test.
 */
function cancelRefusal(Closure $run): TransitionRefused
{
    try {
        $run();
    } catch (TransitionRefused $refused) {
        return $refused;
    }

    Assert::fail('No TransitionRefused was thrown.');
}

/**
 * The start times luna offers for one Haircut with Elena on the Tuesday, in the tenant's zone,
 * as the public read answers them. Read over the wire, because what a customer is offered next
 * is the question, and the mask cache is cold here so the answer comes from the rows.
 *
 * @return list<string>
 */
function cancelOffered(TestCase $test): array
{
    $starts = $test->getJson(tenantUrl('luna', '/api/v1/availability?'.http_build_query([
        'items' => (string) json_encode([['service_id' => cancelLunaId('services', 'Haircut'), 'staff_id' => cancelLunaId('staff', 'Elena Papadaki')]]),
        'from' => '2026-11-03',
        'to' => '2026-11-04',
    ])))->assertOk()->json('2026-11-03');

    assert(is_array($starts));

    return array_values(array_map(function (mixed $start): string {
        assert(is_string($start));

        return (new DateTimeImmutable($start, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('Europe/Athens'))
            ->format('H:i');
    }, $starts));
}

/**
 * A connection of its own to the same database, for asking what another connection has
 * committed while a listener runs.
 */
function cancelProbe(): Connection
{
    /** @var array<string, mixed> $pgsql */
    $pgsql = config('database.connections.pgsql');
    config(['database.connections.cancel_probe' => $pgsql]);

    return DB::connection('cancel_probe');
}

it('cancels a confirmed booking: the status lands, the hold fields are null, every item stops blocking, the trail says confirmed to cancelled by the customer, the event goes out after the commit, and the slot is offered again', function () {
    $seen = [];
    Event::listen(BookingCancelled::class, function (BookingCancelled $event) use (&$seen): void {
        $seen[] = [
            'uuid' => $event->uuid,
            'from' => $event->from?->value,
            'to' => $event->to->value,
            'hold_class' => $event->holdClass?->value,
            'actor' => $event->actor->value,
            'level' => DB::transactionLevel(),
            'committed' => cancelProbe()->scalar('select status from tenant_luna.bookings where uuid = ?::uuid', [$event->uuid]),
        ];
    });

    $uuid = cancelConfirmed();
    $before = cancelRow($uuid);
    $taken = cancelOffered($this);

    cancelUseCase()->cancel($uuid, Actor::customer());

    $trail = cancelTrail();

    expect($before)->toMatchArray(['status' => 'confirmed', 'hold_class' => null, 'blocks' => [true]])
        ->and($taken)->not->toContain('10:00')
        ->and(cancelRow($uuid))->toMatchArray([
            'status' => 'cancelled',
            'hold_class' => null,
            'hold_expires_at' => null,
            'blocks' => [false],
            'total_minor' => $before['total_minor'],
        ])
        ->and($trail)->toHaveCount(3)
        ->and($trail[2])->toMatchArray(['uuid' => $uuid, 'action' => 'cancelled', 'actor' => 'customer'])
        // A confirmed booking is on no hold, so the trail records none rather than a null.
        ->and($trail[2]['payload'])->toBe(['status_from' => 'confirmed', 'status_to' => 'cancelled'])
        ->and($seen)->toBe([[
            'uuid' => $uuid,
            'from' => 'confirmed',
            'to' => 'cancelled',
            'hold_class' => null,
            'actor' => 'customer',
            'level' => 0,
            'committed' => 'cancelled',
        ]])
        ->and(cancelOffered($this))->toContain('10:00');
});

it('measures the customer\'s window back from the first segment\'s start with the largest cancel window in the chain: one second past it is refused and the limit itself is allowed', function () {
    // Colour is the second segment and carries the longer window, so the chain's limit is 48
    // hours before the Haircut starts and not 24. The windows are set before the cancel and
    // after the booking, because the policy reads the catalog as it stands at cancel time.
    cancelWindowOf('Haircut', 24);
    cancelWindowOf('Colour', 48);
    $uuid = cancelConfirmed(['Haircut', 'Colour'], '2026-11-03 09:00');

    // 09:00 in Athens in November is 07:00 UTC; 48 elapsed hours earlier is 07:00 UTC on the
    // first. A chain bound by the first service alone would still be outside its window here.
    cancelClockAt('2026-11-01 07:00:01');
    $refused = cancelRefusal(fn () => cancelUseCase()->cancel($uuid, Actor::customer()));
    $untouched = cancelRow($uuid);
    $trailAfterRefusal = cancelTrail();

    cancelClockAt('2026-11-01 07:00:00');
    cancelUseCase()->cancel($uuid, Actor::customer());

    expect($refused->reason)->toBe(TransitionRefusal::WindowClosed)
        ->and($refused->status)->toBe(BookingStatus::Confirmed)
        ->and($untouched)->toMatchArray(['status' => 'confirmed', 'blocks' => [true, true]])
        // The refusal rolled back: the hold and the confirm are the whole trail at that point.
        ->and(array_column($trailAfterRefusal, 'action'))->toBe(['held', 'confirmed'])
        ->and(cancelRow($uuid))->toMatchArray(['status' => 'cancelled', 'blocks' => [false, false]])
        ->and(array_column(cancelTrail(), 'action'))->toBe(['held', 'confirmed', 'cancelled']);
});

it('reads the window from the catalog at cancel time, so an owner who shortens it means it for the bookings already in the diary', function () {
    cancelWindowOf('Haircut', 48);
    $uuid = cancelConfirmed();

    // 10:00 Athens is 08:00 UTC; inside a 48-hour window and outside a 2-hour one.
    cancelClockAt('2026-11-02 08:00:01');
    $refused = cancelRefusal(fn () => cancelUseCase()->cancel($uuid, Actor::customer()));

    cancelWindowOf('Haircut', 2);
    cancelUseCase()->cancel($uuid, Actor::customer());

    expect($refused->reason)->toBe(TransitionRefusal::WindowClosed)
        ->and(cancelRow($uuid))->toMatchArray(['status' => 'cancelled', 'blocks' => [false]]);
});

it('binds a user by the status rule alone: a cancel inside the window lands and the trail names user:1, where the customer is refused at the same instant', function () {
    $uuid = cancelConfirmed();
    $mine = cancelConfirmed(['Haircut'], '2026-11-03 12:00');

    // One hour before the booking starts, well inside the 24-hour window.
    cancelClockAt('2026-11-03 07:00:00');
    $refused = cancelRefusal(fn () => cancelUseCase()->cancel($uuid, Actor::customer()));

    cancelUseCase()->cancel($uuid, Actor::user(1));
    cancelUseCase()->cancel($mine, Actor::system());

    $trail = cancelTrail();
    $cancels = array_values(array_filter($trail, fn (array $row): bool => $row['action'] === 'cancelled'));

    expect($refused->reason)->toBe(TransitionRefusal::WindowClosed)
        ->and(cancelRow($uuid))->toMatchArray(['status' => 'cancelled', 'blocks' => [false]])
        ->and($cancels)->toHaveCount(2)
        ->and($cancels[0])->toMatchArray(['uuid' => $uuid, 'actor' => 'user:1'])
        // The engine acting on its own is the business too: only the guest is bound by a window.
        ->and($cancels[1])->toMatchArray(['uuid' => $mine, 'actor' => 'system']);
});

it('refuses a cancel of {status} as not_cancellable, leaving the row as it was with no trail and nothing published', function (string $status, ?string $holdClass, string $expiry, string $hour, bool $blocks) {
    $published = [];
    Event::listen(BookingCancelled::class, function () use (&$published): void {
        $published[] = 'cancelled';
    });

    $uuid = cancelSeeded($status, $holdClass, $expiry, $hour, $blocks);
    $before = cancelRow($uuid);

    $byCustomer = cancelRefusal(fn () => cancelUseCase()->cancel($uuid, Actor::customer()));
    $byUser = cancelRefusal(fn () => cancelUseCase()->cancel($uuid, Actor::user(1)));

    expect($byCustomer->reason)->toBe(TransitionRefusal::NotCancellable)
        ->and($byCustomer->status->value)->toBe($status)
        // The status rule answers before the window, so the customer and the business get the
        // same refusal on a booking that is over.
        ->and($byUser->reason)->toBe(TransitionRefusal::NotCancellable)
        ->and(cancelRow($uuid))->toBe($before)
        ->and(cancelTrail())->toBe([])
        ->and($published)->toBe([]);
})->with([
    'a booking that has happened' => ['completed', null, 'none', '09', false],
    'a booking nobody turned up for' => ['no_show', null, 'none', '10', false],
    'a booking already cancelled' => ['cancelled', null, 'none', '11', false],
    'a request the owner rejected' => ['rejected', null, 'none', '12', false],
    'a hold the sweeper expired' => ['expired', null, 'past', '14', false],
]);

it('cancels {status} for the business and for a customer outside the window, clearing the hold and the blocking flag', function (string $status, string $holdClass, string $expiry) {
    $byUser = cancelSeeded($status, $holdClass, $expiry, '09', true);
    $byCustomer = cancelSeeded($status, $holdClass, $expiry, '11', true);

    cancelUseCase()->cancel($byUser, Actor::user(1));
    cancelUseCase()->cancel($byCustomer, Actor::customer());

    $trail = cancelTrail();

    expect(cancelRow($byUser))->toMatchArray(['status' => 'cancelled', 'hold_class' => null, 'hold_expires_at' => null, 'blocks' => [false]])
        ->and(cancelRow($byCustomer))->toMatchArray(['status' => 'cancelled', 'hold_class' => null, 'hold_expires_at' => null, 'blocks' => [false]])
        ->and($trail)->toHaveCount(2)
        ->and($trail[0])->toMatchArray(['uuid' => $byUser, 'action' => 'cancelled', 'actor' => 'user:1'])
        ->and($trail[0]['payload'])->toBe(['hold_class' => $holdClass, 'status_from' => $status, 'status_to' => 'cancelled'])
        ->and($trail[1])->toMatchArray(['uuid' => $byCustomer, 'action' => 'cancelled', 'actor' => 'customer']);
})->with([
    'a checkout hold' => ['pending', 'checkout', 'future'],
    'a request waiting on the owner' => ['awaiting_approval', 'approval', 'future'],
    'an approved booking waiting to be paid' => ['awaiting_payment', 'payment', 'future'],
]);

it('cancels a held booking whose hold has already lapsed, because the status rule is what a cancel turns on', function () {
    $uuid = cancelSeeded('pending', 'checkout', 'past', '09', true);

    cancelUseCase()->cancel($uuid, Actor::customer());

    expect(cancelRow($uuid))->toMatchArray(['status' => 'cancelled', 'blocks' => [false]])
        ->and(cancelTrail()[0]['action'] ?? null)->toBe('cancelled');
});

it('issues, for one cancel, the unblock between the compare-and-set and the audit insert, and nothing of its own after the commit', function () {
    $uuid = cancelConfirmed();
    $cancels = cancelUseCase();
    $trace = [];
    DB::listen(function (QueryExecuted $query) use (&$trace): void {
        $trace[] = $query->sql;
    });
    Event::listen(TransactionBeginning::class, function () use (&$trace): void {
        $trace[] = 'BEGIN';
    });
    Event::listen(TransactionCommitted::class, function () use (&$trace): void {
        $trace[] = 'COMMIT';
    });
    // Listened for so that the no-rollback assertion below is a claim about this transition and
    // not about this listener list. Nothing else can write ROLLBACK into the trace, and an
    // assertion that nothing can falsify proves nothing about the code it is written under.
    Event::listen(TransactionRolledBack::class, function () use (&$trace): void {
        $trace[] = 'ROLLBACK';
    });

    $cancels->cancel($uuid, Actor::customer());

    $begin = array_search('BEGIN', $trace, true);
    $commit = array_search('COMMIT', $trace, true);

    // One transaction block, asserted before either anchor is used as an offset. array_search
    // answers false for a needle that is not there and false used as an offset is zero, so a
    // trace that never committed would otherwise be sliced from the top and reported as a wrong
    // first statement rather than as the missing commit it is.
    expect(array_keys($trace, 'BEGIN', true))->toHaveCount(1)
        ->and(array_keys($trace, 'COMMIT', true))->toHaveCount(1)
        ->and(array_keys($trace, 'ROLLBACK', true))->toBe([])
        ->and($begin)->toBeInt()
        ->and($commit)->toBeInt();

    $transaction = array_slice($trace, (int) $begin, (int) $commit - (int) $begin + 1);
    // What runs after that commit is the two listeners on the cancel, the customer's message
    // and the business's notice, which under the suite's sync queue re-read the booking inside
    // the dispatch. So the slice below is their reads, and it is empty if both stop running.
    // Which listeners fire on a record, and what each of them sends, is
    // CancellationsAndMovesSeamTest's subject and not this file's; what is asserted here is
    // that the transition is over by then and that nothing after it writes.
    $after = array_slice($trace, (int) $commit + 1);

    expect($transaction)->toBe([
        'BEGIN',
        'set local search_path to "tenant_luna", "public"',
        "set local lock_timeout = '3s'",
        "set local statement_timeout = '10s'",
        "select current_setting('transaction_isolation')",
        'select current_schema()',
        'insert into staff_days (staff_id, day, rev) values (?, ?, 0) on conflict (staff_id, day) do update set rev = staff_days.rev + 1 returning staff_id, day, rev',
        PostgresBookingWriter::lockBookingSql(),
        PostgresBookingWriter::readLockedSql(),
        'update bookings set status = ?, updated_at = now(), manage_token_sealed = null, hold_class = ?, hold_expires_at = ?::timestamptz where id = ? and status = ?',
        BlockingItems::unblockSql(),
        AuditRow::sql(),
        'COMMIT',
    ])
        // Every statement after the commit is a read, which admits the listeners' re-reads and
        // nothing else. Naming the write verbs instead would let through a CTE that writes, a
        // truncate, a merge, or a function call doing any of them, and a BEGIN is not a select
        // either, so a second transaction cannot hide in here.
        ->and(array_values(array_filter($after, fn (string $statement): bool => preg_match('/\A\s*select\b/i', $statement) !== 1)))->toBe([])
        // And there is something there to have filtered. A listener that stopped reading, or
        // one whose failure was swallowed before it read, would satisfy the line above by
        // leaving nothing behind at all.
        ->and($after)->not->toBe([]);
});

it('holds the privacy assertion over a cancel: no actor and no payload of the trail names the customer who booked', function () {
    $uuid = cancelConfirmed();

    cancelUseCase()->cancel($uuid, Actor::customer());

    $trail = DB::scalar("select string_agg(actor || ' ' || action || ' ' || payload::text, ' ') from tenant_luna.audit_log");

    expect(auditOffences())->toBe([])
        ->and($trail)->toBeString()
        ->and($trail)->not->toContain(CANCEL_CUSTOMER_NAME)
        ->and($trail)->not->toContain(CANCEL_CUSTOMER_EMAIL)
        ->and($trail)->not->toContain(CANCEL_CUSTOMER_PHONE)
        ->and(DB::table('tenant_luna.bookings')->where('uuid', $uuid)->value('customer_email'))->toBe(CANCEL_CUSTOMER_EMAIL);
});
