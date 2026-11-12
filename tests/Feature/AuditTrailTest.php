<?php

use App\Application\Clock;
use App\Application\Dto\Customer;
use App\Application\Dto\HoldItem;
use App\Application\Dto\HoldPlan;
use App\Application\Dto\HoldRequest;
use App\Application\Dto\Intake;
use App\Application\Dto\SegmentChoice;
use App\Application\Event\BookingExpired;
use App\Application\Event\BookingHeld;
use App\Application\Event\TransitionEvent;
use App\Application\HoldBooking;
use App\Domain\Audit\Actor;
use App\Domain\Booking\SegmentConflict;
use App\Domain\Enum\ApprovalTimeout;
use App\Domain\Enum\BookingSource;
use App\Domain\Enum\BookingStatus;
use App\Domain\Enum\HoldClass;
use App\Domain\Enum\PaymentMode;
use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use App\Infrastructure\Db\Pool;
use App\Infrastructure\HoldBookingFactory;
use App\Tenancy\Tenant;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Tests\Support\FixedClock;
use Tests\Support\FixtureDay;

pest()->use(DatabaseTruncation::class);

/*
 * The trail a hold writes and the event it publishes, on luna's real rows through the factory.
 *
 * Not wrapped in a transaction: every hold enters the transaction runner, which refuses to
 * open inside another.
 *
 * luna is in Europe/Athens, UTC+2 in November. 2026-11-03 is a Tuesday, when Elena works 09:00
 * to 13:00 and 14:00 to 18:00 and Nikos 10:00 to 19:00. The clock stands on the Monday before.
 * The customer is given a name, an address and a phone nothing else in the fixtures shares, so
 * the privacy assertion has something distinctive to look for.
 */

const AUDIT_CUSTOMER_NAME = 'Zorika Quillfeather';

const AUDIT_CUSTOMER_EMAIL = 'zorika.quillfeather@example.test';

const AUDIT_CUSTOMER_PHONE = '+30 210 7654321';

final class AuditRecordingLogger extends AbstractLogger
{
    /** @var list<array{string, string}> the level and the message of every line written */
    public array $lines = [];

    /**
     * @param  array<mixed>  $context
     */
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $this->lines[] = [is_string($level) ? $level : get_debug_type($level), (string) $message];
    }
}

beforeEach(function () {
    dropDemoSchemas();
    seedDemo();
    app()->instance(Clock::class, FixedClock::at('2026-11-02 06:00:00'));
});

afterEach(function () {
    DB::purge('audit_probe');
    dropDemoSchemas();
    DB::table('users')->delete();
    DB::table('tenants')->delete();
});

function auditTenant(): Tenant
{
    return Tenant::query()->where('slug', 'luna')->sole();
}

function auditUseCase(): HoldBooking
{
    return app(HoldBookingFactory::class)->for(auditTenant(), Pool::Whole);
}

function auditLunaId(string $table, string $name): int
{
    $id = DB::table('tenant_luna.'.$table)->where('name', $name)->value('id');

    assert(is_int($id));

    return $id;
}

/**
 * A hold for one named service on one named member at an Athens wall-clock time, for the one
 * distinctive customer this file books as.
 */
function auditRequest(string $service, string $staff, string $start): HoldRequest
{
    return new HoldRequest(
        new Customer(AUDIT_CUSTOMER_NAME, AUDIT_CUSTOMER_EMAIL, AUDIT_CUSTOMER_PHONE),
        [new SegmentChoice(auditLunaId('services', $service), auditLunaId('staff', $staff))],
        new DateTimeImmutable($start, new DateTimeZone('Europe/Athens')),
        new Intake(BookingSource::Embed),
    );
}

/**
 * A plan built by hand, as the planner would have made it from stale rows: one Haircut item on
 * the member, so a test can reach the re-validation under lock with a start the advisory read
 * would have refused.
 */
function auditPlanOf(string $staff, string $start, string $end): HoldPlan
{
    $zone = new DateTimeZone('Europe/Athens');
    $from = new DateTimeImmutable($start, $zone);
    $to = new DateTimeImmutable($end, $zone);

    return new HoldPlan(
        new Customer(AUDIT_CUSTOMER_NAME, AUDIT_CUSTOMER_EMAIL, AUDIT_CUSTOMER_PHONE),
        [new HoldItem(auditLunaId('staff', $staff), auditLunaId('services', 'Haircut'), $from, $to, $from, $to, null, new Money(3500, Currency::of('EUR')))],
        BookingStatus::Pending,
        HoldClass::Checkout,
        15,
        PaymentMode::Onsite,
        false,
        ApprovalTimeout::Expire,
        new Intake(BookingSource::Embed),
    );
}

/**
 * The trail of luna, oldest first, each row with the booking it names by uuid and its payload
 * decoded and sorted by key. jsonb keeps an object's keys in its own order, by length and then
 * by bytes, so the order a payload was written in is not the order it reads back; sorting here
 * is what lets a test name the keys it expects without transcribing that rule.
 *
 * @return list<array<mixed>>
 */
function auditRowsOfLuna(): array
{
    $rows = [];

    foreach (DB::select(
        'select a.id, a.actor, a.action, a.payload::text as payload, b.uuid::text as uuid,'
        .' a.created_at = b.created_at as created_with_the_booking'
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
 * An expired-in-all-but-name approval request on the member, 10:00 to 12:00 Athens on the
 * Tuesday, a minute past its window by the database's clock and still blocking because no
 * sweeper has run. What the reap of step 4 is there to find.
 */
function auditStaleRequest(string $staff): string
{
    $uuid = (string) Str::uuid();

    $bookingId = DB::table('tenant_luna.bookings')->insertGetId([
        ...bookingRow(),
        'uuid' => $uuid,
        'customer_name' => 'Other Customer',
        'customer_email' => 'other@example.test',
        'status' => 'awaiting_approval',
        'hold_class' => 'approval',
        'hold_expires_at' => DB::raw("now() - interval '1 minute'"),
        'on_approval_timeout' => 'expire',
    ]);

    $start = FixtureDay::start()->setTimezone(new DateTimeZone('UTC'));
    DB::table('tenant_luna.booking_items')->insert(itemRow($bookingId, auditLunaId('staff', $staff), $start->format('Y-m-d H:i:sP'), $start->add(new DateInterval('PT2H'))->format('Y-m-d H:i:sP')));

    return $uuid;
}

/**
 * The statements a closure ran, by their text.
 *
 * @return list<string>
 */
function auditStatements(Closure $run): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        $run();
    } finally {
        $log = DB::getQueryLog();
        DB::disableQueryLog();
        DB::flushQueryLog();
    }

    return array_values(array_map(fn (array $entry): string => $entry['query'], $log));
}

/**
 * The index of the last statement containing the text. Nothing matching fails the test.
 *
 * @param  list<string>  $statements
 */
function auditIndexOfLast(array $statements, string $text): int
{
    $found = null;

    foreach ($statements as $index => $statement) {
        if (str_contains($statement, $text)) {
            $found = $index;
        }
    }

    return $found ?? Assert::fail(sprintf('No statement contained "%s".', $text));
}

/**
 * A connection of its own to the same database, for asking what another connection has
 * committed. A row it can see is a row the transaction let go of.
 */
function auditProbe(): Connection
{
    /** @var array<string, mixed> $pgsql */
    $pgsql = config('database.connections.pgsql');
    config(['database.connections.audit_probe' => $pgsql]);

    return DB::connection('audit_probe');
}

it('writes one held row for a hold on luna: the action, the customer as the actor, a payload naming only the status it landed in, the class, the expiry and the item count, and a created_at equal to the booking\'s', function () {
    $held = auditUseCase()->hold(auditRequest('Haircut', 'Elena Papadaki', '2026-11-03 10:00'), Actor::customer());
    $rows = auditRowsOfLuna();
    $expiry = DB::scalar("select to_char(hold_expires_at at time zone 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS.US\"+00:00\"') from tenant_luna.bookings");

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['action'])->toBe('held')
        ->and($rows[0]['actor'])->toBe('customer')
        ->and($rows[0]['uuid'])->toBe($held->uuid)
        ->and($rows[0]['created_with_the_booking'])->toBeTrue()
        ->and($rows[0]['payload'])->toBe([
            'hold_class' => 'checkout',
            'hold_expires_at' => $expiry,
            'item_count' => 1,
            'status_to' => 'pending',
        ]);
});

it('writes a second row on the old booking when the reap lets its hold go: action expired, the system as the actor, the status it left and the class it held in the payload', function () {
    $stale = auditStaleRequest('Elena Papadaki');

    $held = auditUseCase()->hold(auditRequest('Haircut', 'Elena Papadaki', '2026-11-03 11:00'), Actor::customer());
    $rows = auditRowsOfLuna();

    expect($rows)->toHaveCount(2)
        ->and($rows[0])->toMatchArray(['uuid' => $stale, 'action' => 'expired', 'actor' => 'system'])
        ->and($rows[0]['payload'])->toBe(['hold_class' => 'approval', 'status_from' => 'awaiting_approval', 'status_to' => 'expired'])
        ->and($rows[1])->toMatchArray(['uuid' => $held->uuid, 'action' => 'held', 'actor' => 'customer'])
        ->and(DB::table('tenant_luna.bookings')->where('uuid', $stale)->value('status'))->toBe('expired');
});

it('runs the audit insert after the last item insert and inside the transaction, and publishes nothing until the transaction has returned', function () {
    $levels = [];
    Event::listen(BookingHeld::class, function () use (&$levels): void {
        $levels[] = DB::transactionLevel();
    });

    $statements = auditStatements(fn () => auditUseCase()->hold(auditRequest('Haircut', 'Elena Papadaki', '2026-11-03 10:00'), Actor::customer()));
    $items = auditIndexOfLast($statements, 'insert into booking_items');
    $audit = auditIndexOfLast($statements, 'insert into audit_log');
    $search = auditIndexOfLast($statements, 'set local search_path');

    expect($audit)->toBeGreaterThan($items)
        ->and($audit)->toBeGreaterThan($search)
        ->and($levels)->toBe([0]);
});

it('reaches a listener subscribed to the marker as well as one subscribed to the record, because the marker is an interface and the dispatcher resolves those; a shape shared any other way would be a subscription that matched nothing', function () {
    $byMarker = [];
    Event::listen(TransitionEvent::class, function (TransitionEvent $event) use (&$byMarker): void {
        $byMarker[] = $event::class;
    });
    $byRecord = [];
    Event::listen(BookingHeld::class, function () use (&$byRecord): void {
        $byRecord[] = BookingHeld::class;
    });

    auditUseCase()->hold(auditRequest('Haircut', 'Elena Papadaki', '2026-11-03 10:00'), Actor::customer());

    expect($byMarker)->toBe([BookingHeld::class])
        ->and($byRecord)->toBe([BookingHeld::class]);
});

it('reaches the framework dispatcher only once the booking is committed, carrying the uuid, no status before, the status it landed in, the hold class and the actor', function () {
    $seen = [];
    Event::listen(BookingHeld::class, function (BookingHeld $event) use (&$seen): void {
        $seen[] = [
            'uuid' => $event->uuid,
            'from' => $event->from,
            'to' => $event->to->value,
            'hold_class' => $event->holdClass?->value,
            'actor' => $event->actor->value,
            'level' => DB::transactionLevel(),
            'committed' => auditProbe()->scalar('select status from tenant_luna.bookings where uuid = ?::uuid', [$event->uuid]),
        ];
    });

    $held = auditUseCase()->hold(auditRequest('Haircut', 'Elena Papadaki', '2026-11-03 10:00'), Actor::customer());

    expect($seen)->toBe([[
        'uuid' => $held->uuid,
        'from' => null,
        'to' => 'pending',
        'hold_class' => 'checkout',
        'actor' => 'customer',
        'level' => 0,
        'committed' => 'pending',
    ]]);
});

it('publishes one expired event per hold the reap let go, before the held one, each naming the class it held', function () {
    $stale = auditStaleRequest('Elena Papadaki');
    $seen = [];
    $record = function (object $event) use (&$seen): void {
        assert($event instanceof BookingHeld || $event instanceof BookingExpired);
        $seen[] = [$event::class, $event->uuid, $event->from?->value, $event->to->value, $event->holdClass?->value, $event->actor->value];
    };
    Event::listen(BookingExpired::class, $record);
    Event::listen(BookingHeld::class, $record);

    $held = auditUseCase()->hold(auditRequest('Haircut', 'Elena Papadaki', '2026-11-03 11:00'), Actor::customer());

    expect($seen)->toBe([
        [BookingExpired::class, $stale, 'awaiting_approval', 'expired', 'approval', 'system'],
        [BookingHeld::class, $held->uuid, null, 'pending', 'checkout', 'customer'],
    ]);
});

it('leaves the booking committed and logs the fault when a listener throws, because by then there is nothing left to roll back', function () {
    $log = new AuditRecordingLogger;
    app()->instance(LoggerInterface::class, $log);
    Event::listen(BookingHeld::class, function (): void {
        throw new RuntimeException('the listener fell over');
    });

    $held = auditUseCase()->hold(auditRequest('Haircut', 'Elena Papadaki', '2026-11-03 10:00'), Actor::customer());

    expect(DB::table('tenant_luna.bookings')->where('uuid', $held->uuid)->value('status'))->toBe('pending')
        ->and(DB::table('tenant_luna.audit_log')->count())->toBe(1)
        ->and(array_filter($log->lines, fn (array $line): bool => $line === ['error', 'A transition listener failed']))->not->toBeEmpty();
});

it('writes no audit row and publishes nothing when the re-validation under lock refuses the item', function () {
    $booked = auditUseCase()->hold(auditRequest('Haircut', 'Elena Papadaki', '2026-11-03 10:00'), Actor::customer());
    DB::table('tenant_luna.audit_log')->delete();
    $seen = [];
    Event::listen(BookingHeld::class, function () use (&$seen): void {
        $seen[] = 'held';
    });

    $refused = null;

    try {
        auditUseCase()->execute(auditPlanOf('Elena Papadaki', '2026-11-03 10:15', '2026-11-03 11:00'), Actor::customer());
    } catch (SegmentConflict $refused) {
    }

    expect($refused)->toBeInstanceOf(SegmentConflict::class)
        ->and(DB::table('tenant_luna.audit_log')->count())->toBe(0)
        ->and($seen)->toBe([])
        ->and(DB::table('tenant_luna.bookings')->count())->toBe(1)
        ->and(DB::table('tenant_luna.bookings')->value('uuid'))->toBe($booked->uuid);
});

it('holds the privacy assertion over a hold by a customer with a distinctive name, address and phone: no actor and no payload names any of the three', function () {
    auditUseCase()->hold(auditRequest('Haircut', 'Elena Papadaki', '2026-11-03 10:00'), Actor::customer());
    $trail = DB::scalar("select string_agg(actor || ' ' || action || ' ' || payload::text, ' ') from tenant_luna.audit_log");

    expect(auditOffences())->toBe([])
        ->and($trail)->toBeString()
        ->and($trail)->not->toContain(AUDIT_CUSTOMER_NAME)
        ->and($trail)->not->toContain(AUDIT_CUSTOMER_EMAIL)
        ->and($trail)->not->toContain(AUDIT_CUSTOMER_PHONE);
});

it('fails the privacy assertion when a row carries the customer\'s address, and the key assertion when a row carries a key outside the declared list', function () {
    $held = auditUseCase()->hold(auditRequest('Haircut', 'Elena Papadaki', '2026-11-03 10:00'), Actor::customer());
    $bookingId = DB::table('tenant_luna.bookings')->where('uuid', $held->uuid)->value('id');

    DB::table('tenant_luna.audit_log')->insert([
        'booking_id' => $bookingId,
        'actor' => 'system',
        'action' => 'held',
        'payload' => (string) json_encode(['customer_email' => AUDIT_CUSTOMER_EMAIL]),
    ]);

    $offences = auditOffences();

    // Removed before the net over every test of this suite reads it, since the row was written
    // to prove the net catches it and not to be left lying there.
    DB::table('tenant_luna.audit_log')->where('actor', 'system')->delete();

    expect($offences)->toHaveCount(2)
        ->and($offences[0] ?? '')->toContain('the payload key "customer_email" is outside the declared list')
        ->and($offences[1] ?? '')->toContain('the trail names the customer')
        ->and(auditOffences())->toBe([]);
});
