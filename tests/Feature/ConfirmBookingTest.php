<?php

use App\Application\Checkpoint;
use App\Application\Clock;
use App\Application\ConfirmBooking;
use App\Application\Dto\Customer;
use App\Application\Dto\HoldRequest;
use App\Application\Dto\Intake;
use App\Application\Dto\SegmentChoice;
use App\Application\Event\BookingConfirmed;
use App\Application\GuardedWrite;
use App\Application\TransitionRefused;
use App\Domain\Audit\Actor;
use App\Domain\Booking\TransitionRefusal;
use App\Domain\Enum\BookingSource;
use App\Domain\Enum\BookingStatus;
use App\Infrastructure\BookingTransitionFactory;
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

pest()->use(DatabaseTruncation::class);

/*
 * ConfirmBooking on luna's real rows through the factory: what a confirm lands, what it writes,
 * what it refuses and what it leaves behind when it does.
 *
 * Not wrapped in a transaction: every transition enters the transaction runner, which refuses to
 * open inside another.
 *
 * luna is in Europe/Athens, UTC+2 in November. 2026-11-03 is a Tuesday, when Elena works 09:00
 * to 13:00 and 14:00 to 18:00. The clock stands on the Monday before. The customer of the holds
 * taken here is given a name, an address and a phone nothing else in the fixtures shares, so
 * the privacy assertion has something distinctive to look for.
 */

const CONFIRM_CUSTOMER_NAME = 'Perpetua Vandersnoot';

const CONFIRM_CUSTOMER_EMAIL = 'perpetua.vandersnoot@example.test';

const CONFIRM_CUSTOMER_PHONE = '+30 210 1234567';

beforeEach(function () {
    dropDemoSchemas();
    seedDemo();
    app()->instance(Clock::class, FixedClock::at('2026-11-02 06:00:00'));
});

afterEach(function () {
    Checkpoint::observe(null);
    DB::purge('confirm_probe');
    dropDemoSchemas();
    DB::table('users')->delete();
    DB::table('tenants')->delete();
});

function confirmTenant(): Tenant
{
    return Tenant::query()->where('slug', 'luna')->sole();
}

function confirmUseCase(): ConfirmBooking
{
    return app(BookingTransitionFactory::class)->confirm(confirmTenant());
}

function confirmLunaId(string $table, string $name): int
{
    $id = DB::table('tenant_luna.'.$table)->where('name', $name)->value('id');

    assert(is_int($id));

    return $id;
}

/**
 * A hold taken the way the booking page takes one: the named service on the named member at an
 * Athens wall-clock time, for the one distinctive customer this file books as.
 *
 * @return string the uuid of the booking it left
 */
function confirmHeld(string $service = 'Haircut', string $staff = 'Elena Papadaki', string $start = FixtureDay::START): string
{
    return app(HoldBookingFactory::class)->for(confirmTenant(), Pool::Whole)->hold(
        new HoldRequest(
            new Customer(CONFIRM_CUSTOMER_NAME, CONFIRM_CUSTOMER_EMAIL, CONFIRM_CUSTOMER_PHONE),
            [new SegmentChoice(confirmLunaId('services', $service), confirmLunaId('staff', $staff))],
            new DateTimeImmutable($start, new DateTimeZone('Europe/Athens')),
            new Intake(BookingSource::Embed),
        ),
        Actor::customer(),
    )->uuid;
}

/**
 * A chain held the same way: the services in order on the one member, at the Athens time given.
 *
 * @param  non-empty-list<string>  $services
 * @return string the uuid of the booking it left
 */
function confirmHeldChain(array $services, string $staff = 'Elena Papadaki', string $start = FixtureDay::DATE.' 09:00'): string
{
    return app(HoldBookingFactory::class)->for(confirmTenant(), Pool::Whole)->hold(
        new HoldRequest(
            new Customer(CONFIRM_CUSTOMER_NAME, CONFIRM_CUSTOMER_EMAIL, CONFIRM_CUSTOMER_PHONE),
            array_map(fn (string $service): SegmentChoice => new SegmentChoice(confirmLunaId('services', $service), confirmLunaId('staff', $staff)), $services),
            new DateTimeImmutable($start, new DateTimeZone('Europe/Athens')),
            new Intake(BookingSource::Embed),
        ),
        Actor::customer(),
    )->uuid;
}

/**
 * An instant as the server writes one: UTC text with the offset written out, never a bound date
 * object, which the framework formats without its zone.
 */
function confirmInstant(string $athens): string
{
    return (new DateTimeImmutable($athens, new DateTimeZone('Europe/Athens')))
        ->setTimezone(new DateTimeZone('UTC'))
        ->format('Y-m-d H:i:s.uP');
}

/**
 * A booking of luna written straight to the rows, in the state the test names, with one Haircut
 * item on Elena over the hour given. Some of these states are reachable no other way, and a
 * guarded transition reads no catalog and no mask, so a row in the state is the whole fixture.
 *
 * The expiry is set against the database's own clock and not the test's, because whether a hold
 * is still live is the server's comparison inside the re-read.
 *
 * @param  string  $expiry  past, future, or none
 * @return string the uuid
 */
function confirmSeeded(string $status, ?string $holdClass, string $expiry, string $hour, bool $blocks, string $paymentMode = 'onsite'): string
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
        'customer_name' => 'Casey Customer',
        'customer_email' => 'casey@example.com',
        'customer_phone' => '+30 210 0000000',
        'total_minor' => 3500,
        'currency' => 'EUR',
        'payment_mode' => $paymentMode,
        'requires_approval' => false,
        'on_approval_timeout' => 'expire',
        'manage_token_hash' => str_repeat('a', 64),
    ]);

    DB::table('tenant_luna.booking_items')->insert([
        'booking_id' => $bookingId,
        'sort' => 0,
        'service_id' => confirmLunaId('services', 'Haircut'),
        'staff_id' => confirmLunaId('staff', 'Elena Papadaki'),
        'start_utc' => confirmInstant('2026-11-03 '.$hour.':00'),
        'end_utc' => confirmInstant('2026-11-03 '.$hour.':45'),
        'block_start_utc' => confirmInstant('2026-11-03 '.$hour.':00'),
        'block_end_utc' => confirmInstant('2026-11-03 '.$hour.':45'),
        'price_minor' => 3500,
        'blocks' => $blocks,
    ]);

    return $uuid;
}

/**
 * The booking and its item as the rows stand, with every instant in UTC text.
 *
 * @return array<string, mixed>
 */
function confirmRow(string $uuid): array
{
    $row = DB::selectOne(
        'select b.status, b.hold_class, b.hold_expires_at::text as hold_expires_at, b.total_minor, b.payment_mode,'
        .' i.blocks, i.start_utc::text as start_utc'
        .' from tenant_luna.bookings b join tenant_luna.booking_items i on i.booking_id = b.id'
        .' where b.uuid = ?::uuid',
        [$uuid],
    );

    assert($row instanceof stdClass);

    return [
        'status' => $row->status,
        'hold_class' => $row->hold_class,
        'hold_expires_at' => $row->hold_expires_at,
        'total_minor' => $row->total_minor,
        'payment_mode' => $row->payment_mode,
        'blocks' => $row->blocks,
        'start_utc' => $row->start_utc,
    ];
}

/**
 * Every statement a closure ran and every transaction boundary it crossed, in the order they
 * happened. The commit is an event rather than a statement, so a query log alone cannot show
 * that nothing followed it.
 *
 * @return list<string>
 */
function confirmTrace(Closure $run): array
{
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
    Event::listen(TransactionRolledBack::class, function () use (&$trace): void {
        $trace[] = 'ROLLBACK';
    });

    $run();

    return $trace;
}

/**
 * What the closure threw as a refusal. Anything else, or nothing, fails the test.
 */
function confirmRefusal(Closure $run): TransitionRefused
{
    try {
        $run();
    } catch (TransitionRefused $refused) {
        return $refused;
    }

    Assert::fail('No TransitionRefused was thrown.');
}

/**
 * The trail of luna, oldest first, each row with the booking it names and its payload decoded.
 *
 * @return list<array<mixed>>
 */
function confirmTrail(): array
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
 * A connection of its own to the same database, for moving a booking while a transition stands
 * at a checkpoint, and for asking what another connection has committed.
 */
function confirmProbe(): Connection
{
    /** @var array<string, mixed> $pgsql */
    $pgsql = config('database.connections.pgsql');
    config(['database.connections.confirm_probe' => $pgsql]);

    return DB::connection('confirm_probe');
}

it('confirms a live pending hold: the status lands, the hold is cleared, every item still blocks, the total is untouched, the trail says pending to confirmed by the customer, and the event goes out after the commit', function () {
    $seen = [];
    Event::listen(BookingConfirmed::class, function (BookingConfirmed $event) use (&$seen): void {
        $seen[] = [
            'uuid' => $event->uuid,
            'from' => $event->from?->value,
            'to' => $event->to->value,
            'hold_class' => $event->holdClass?->value,
            'actor' => $event->actor->value,
            'level' => DB::transactionLevel(),
            'committed' => confirmProbe()->scalar('select status from tenant_luna.bookings where uuid = ?::uuid', [$event->uuid]),
        ];
    });

    $uuid = confirmHeld();
    $before = confirmRow($uuid);

    confirmUseCase()->confirm($uuid, Actor::customer());

    $after = confirmRow($uuid);
    $trail = confirmTrail();

    expect($before)->toMatchArray(['status' => 'pending', 'hold_class' => 'checkout', 'blocks' => true])
        ->and($before['hold_expires_at'])->toBeString()
        ->and($after)->toMatchArray([
            'status' => 'confirmed',
            'hold_class' => null,
            'hold_expires_at' => null,
            'blocks' => true,
            'total_minor' => $before['total_minor'],
            'start_utc' => $before['start_utc'],
        ])
        ->and($trail)->toHaveCount(2)
        ->and($trail[1])->toMatchArray(['uuid' => $uuid, 'action' => 'confirmed', 'actor' => 'customer'])
        ->and($trail[1]['payload'])->toBe(['hold_class' => 'checkout', 'status_from' => 'pending', 'status_to' => 'confirmed'])
        ->and($seen)->toBe([[
            'uuid' => $uuid,
            'from' => 'pending',
            'to' => 'confirmed',
            'hold_class' => 'checkout',
            'actor' => 'customer',
            'level' => 0,
            'committed' => 'confirmed',
        ]]);
});

it('leaves every item of a chain blocking and locks every staff-day the chain touches, not only the first', function () {
    $uuid = confirmHeldChain(['Haircut', 'Colour']);
    $revision = DB::table('tenant_luna.staff_days')->where('day', '2026-11-03')->value('rev');

    assert(is_int($revision));

    confirmUseCase()->confirm($uuid, Actor::customer());

    $blocking = [];

    foreach (DB::select(
        'select i.sort, i.blocks from tenant_luna.bookings b join tenant_luna.booking_items i on i.booking_id = b.id'
        .' where b.uuid = ?::uuid order by i.sort',
        [$uuid],
    ) as $row) {
        assert($row instanceof stdClass && is_bool($row->blocks));

        $blocking[] = $row->blocks;
    }

    expect($blocking)->toBe([true, true])
        ->and(DB::table('tenant_luna.bookings')->where('uuid', $uuid)->value('status'))->toBe('confirmed')
        // The mutex row of the staff-day the chain occupies was taken and bumped by the confirm
        // as well as by the hold, which is what makes the two serialise.
        ->and(DB::table('tenant_luna.staff_days')->where('day', '2026-11-03')->value('rev'))->toBe($revision + 1);
});

it('issues, for one confirm, the three settings and two assertions, the staff-day lock, the booking row lock, the re-read, one compare-and-set naming the status the re-read found, the trail, and the commit, with no unblock and nothing of its own after the commit', function () {
    $uuid = confirmHeld();
    // Built before the trace starts, so what it records is the transition and not the tenant
    // read every caller does before it has a use case at all.
    $confirms = confirmUseCase();
    $trace = confirmTrace(fn () => $confirms->confirm($uuid, Actor::customer()));
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
    // What runs after that commit is the listeners, which re-read the booking for themselves
    // before they send: the customer's confirmation, the business's notice, and the reminder's
    // scheduling, which reads the settings row, the booking and its revision to place the
    // reminder. They are why this does not end at the commit, and what they may do is stated
    // as what they are rather than as a list of what they are not. The transition is over, and
    // a listener is a reader of what it left.
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
        AuditRow::sql(),
        'COMMIT',
    ])
        ->and(array_filter($trace, fn (string $statement): bool => $statement === BlockingItems::unblockSql()))->toBe([])
        ->and(array_filter($trace, fn (string $statement): bool => str_contains($statement, 'reap') || str_contains($statement, 'blocks = false')))->toBe([])
        // Every statement after the commit is a read, which admits the listeners' re-reads and
        // nothing else. Naming the write verbs instead would let through a CTE that writes, a
        // truncate, a merge, or a function call doing any of them, and a BEGIN is not a select
        // either, so a second transaction cannot hide in here.
        ->and(array_values(array_filter($after, fn (string $statement): bool => preg_match('/\A\s*select\b/i', $statement) !== 1)))->toBe([])
        // And there is something there to have filtered. QueuedMessages swallows a listener
        // that does nothing, so a listener that stopped reading, or one whose failure was
        // swallowed before it read, would satisfy the line above by leaving nothing behind.
        ->and($after)->not->toBe([]);
});

it('reads the key set before the transaction and the booking only under the lock, so the pre-read touches one table and carries no status', function () {
    $uuid = confirmHeld();
    $confirms = confirmUseCase();
    $trace = confirmTrace(fn () => $confirms->confirm($uuid, Actor::customer()));
    $before = array_slice($trace, 0, (int) array_search('BEGIN', $trace, true));

    expect($before)->toHaveCount(1)
        ->and($before[0])->toContain('block_start_utc')
        ->and($before[0])->toContain('"tenant_luna"."booking_items"')
        ->and($before[0])->not->toContain('status')
        ->and($before[0])->not->toContain('customer_email');
});

it('refuses every state that is not a live pending hold, leaving the row as it was with no trail and nothing published', function (string $status, ?string $holdClass, string $expiry, string $hour, bool $blocks, TransitionRefusal $reason) {
    $published = [];
    Event::listen(BookingConfirmed::class, function () use (&$published): void {
        $published[] = 'confirmed';
    });

    $uuid = confirmSeeded($status, $holdClass, $expiry, $hour, $blocks);
    $before = confirmRow($uuid);

    $refused = confirmRefusal(fn () => confirmUseCase()->confirm($uuid, Actor::customer()));

    expect($refused->reason)->toBe($reason)
        ->and($refused->status->value)->toBe($status)
        ->and(confirmRow($uuid))->toBe($before)
        ->and(confirmTrail())->toBe([])
        ->and($published)->toBe([]);
})->with([
    'a hold whose expiry has passed' => ['pending', 'checkout', 'past', '09', true, TransitionRefusal::HoldExpired],
    'a hold with no expiry at all' => ['pending', 'checkout', 'none', '09', true, TransitionRefusal::HoldExpired],
    'a booking the sweeper already expired' => ['expired', null, 'past', '10', false, TransitionRefusal::HoldExpired],
    'a request waiting on the owner' => ['awaiting_approval', 'approval', 'future', '11', true, TransitionRefusal::NotConfirmable],
    'an approved booking waiting to be paid' => ['awaiting_payment', 'payment', 'future', '12', true, TransitionRefusal::NotConfirmable],
    'a booking already confirmed' => ['confirmed', null, 'none', '14', true, TransitionRefusal::NotConfirmable],
    'a booking that has happened' => ['completed', null, 'none', '15', false, TransitionRefusal::NotConfirmable],
    'a booking nobody turned up for' => ['no_show', null, 'none', '16', false, TransitionRefusal::NotConfirmable],
    'a booking the customer cancelled' => ['cancelled', null, 'none', '17', false, TransitionRefusal::NotConfirmable],
    'a request the owner rejected' => ['rejected', null, 'none', '09', false, TransitionRefusal::NotConfirmable],
]);

it('judges the booking the lock read and never the one the pre-read saw: a cancel committed from a second connection while the transition waits after its staff-day lock is refused on what the lock found', function () {
    $uuid = confirmHeld();
    $cancelled = [];

    Checkpoint::observe(function (string $name) use ($uuid, &$cancelled): void {
        if ($name !== GuardedWrite::AFTER_LOCK || $cancelled !== []) {
            return;
        }

        // A second connection, so the update is another transaction's and commits on its own.
        // The booking row is not locked yet at this point, which is what makes the window real
        // rather than staged: the mutex rows are held and the booking is not.
        $cancelled[] = confirmProbe()->update(
            "update tenant_luna.bookings set status = 'cancelled', hold_class = null, hold_expires_at = null where uuid = ?::uuid",
            [$uuid],
        );
    });

    $refused = confirmRefusal(fn () => confirmUseCase()->confirm($uuid, Actor::customer()));

    expect($cancelled)->toBe([1])
        ->and($refused->reason)->toBe(TransitionRefusal::NotConfirmable)
        ->and($refused->status)->toBe(BookingStatus::Cancelled)
        ->and(confirmRow($uuid))->toMatchArray(['status' => 'cancelled'])
        ->and(confirmTrail())->toHaveCount(1)
        ->and(confirmTrail()[0]['action'] ?? null)->toBe('held');
});

it('confirms an online-mode booking exactly as a pay-on-site one, which is the degrade rule working rather than a gap: luna has no payment connection, so its provider is the null one and the answer to the one question is yes', function () {
    $uuid = confirmHeld('Colour');

    confirmUseCase()->confirm($uuid, Actor::customer());

    expect(confirmRow($uuid))->toMatchArray(['status' => 'confirmed', 'payment_mode' => 'online', 'hold_class' => null, 'blocks' => true])
        ->and(DB::table('tenant_luna.payment_connections')->count())->toBe(0);
});

it('refuses with payment_required when the one seam answers no, which it does for an online booking while luna\'s provider is a working one, and writes nothing', function () {
    $uuid = confirmHeld('Colour');
    connectPaymentFake();

    $refused = confirmRefusal(fn () => confirmUseCase()->confirm($uuid, Actor::customer()));

    expect($refused->reason)->toBe(TransitionRefusal::PaymentRequired)
        ->and($refused->status)->toBe(BookingStatus::Pending)
        ->and(confirmRow($uuid))->toMatchArray(['status' => 'pending', 'hold_class' => 'checkout'])
        ->and(confirmTrail())->toHaveCount(1);
});

it('holds the privacy assertion over a confirm: no actor and no payload of the trail names the customer who booked', function () {
    $uuid = confirmHeld();

    confirmUseCase()->confirm($uuid, Actor::customer());

    $trail = DB::scalar("select string_agg(actor || ' ' || action || ' ' || payload::text, ' ') from tenant_luna.audit_log");

    expect(auditOffences())->toBe([])
        ->and($trail)->toBeString()
        ->and($trail)->not->toContain(CONFIRM_CUSTOMER_NAME)
        ->and($trail)->not->toContain(CONFIRM_CUSTOMER_EMAIL)
        ->and($trail)->not->toContain(CONFIRM_CUSTOMER_PHONE)
        ->and(DB::table('tenant_luna.bookings')->where('uuid', $uuid)->value('customer_email'))->toBe(CONFIRM_CUSTOMER_EMAIL);
});
