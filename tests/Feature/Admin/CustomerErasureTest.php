<?php

use App\Application\Clock;
use App\Domain\Audit\Actor;
use App\Http\Controllers\Admin\CustomerController;
use App\Infrastructure\BookingTransitionFactory;
use App\Infrastructure\Db\SqlText;
use App\Infrastructure\SystemClock;
use App\Models\User;
use App\Retention\CustomerLive;
use App\Site\SiteLanguage;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FixedClock;
use Tests\Support\FixtureDay;
use Tests\Support\LunaFixture;
use Tests\TestCase;

pest()->use(DatabaseTruncation::class);

/*
 * The erasure of one customer from their page, through POST /admin/customers/{email}/erase: in one
 * transaction, erasure's primitive over every booking of the address as citext compares it, the
 * address's My bookings links and sessions deleted, and one trail row per booking, action
 * customer_erased, the user as the actor and the payload empty; refused as a 409 naming the count
 * while any booking of the address is live, a second erasure a 404, the route under manageBookings
 * and outside the subscription gate.
 *
 * The erasure enters the transaction runner, so this file truncates rather than wrapping each
 * test in a transaction, and seeds the demo afresh. The rows are written straight to luna's
 * tables, each instant on the clock the liveness reads it by. A segment's are counted from the
 * pinned clock, which the item-end limb reads, and the walk's holds start on the fixture's
 * literal date, so the clock is pinned first. A hold's expiry is counted from the database's
 * now(), which the held limb reads through SqlText::holdIsLive as every reader of a hold does;
 * the pinned clock is a fixture date nowhere near the server's now, and the two cases that read a
 * checkout hold's expiry alone run on the system clock and on a clock a day either side of it, so
 * a limb reading the hold by the application's clock fails one of them whichever way the two
 * clocks differ.
 *
 * Every booking's last segment is a Colour followed by its thirty-minute processing gap, with a
 * minute of buffer after it, as the booking path writes a service with a gap, last in its chain or
 * booked alone (HoldPlanner::itemsOf): a limb counting a confirmed booking live from the block's
 * end or the gap's, rather than from end_utc, would refuse the chain whose last segment ended at
 * now, which the boundary case erases.
 */

beforeEach(function () {
    $this->withoutVite();
    dropDemoSchemas();
    seedDemo();
    FixtureDay::pinClock();
});

afterEach(function () {
    dropDemoSchemas();
    DB::table('users')->delete();
    DB::table('tenants')->delete();
});

const CUSTOMER_ERASURE_HOUR = 3600;

/** The address the erased customer booked under, as its page links it. */
const CUSTOMER_ERASURE_EMAIL = 'casey@example.com';

/** A neighbour whose bookings, links and sessions an erasure of the first must leave standing. */
const CUSTOMER_ERASURE_NEIGHBOUR = 'robin@example.test';

/**
 * An instant moved by the seconds given.
 */
function customerErasureMoved(DateTimeImmutable $instant, int $seconds): DateTimeImmutable
{
    return $seconds >= 0 ? $instant->add(new DateInterval('PT'.$seconds.'S')) : $instant->sub(new DateInterval('PT'.(-$seconds).'S'));
}

/**
 * The bound clock's now moved by the seconds given, as the server binds an instant.
 */
function customerErasureAt(int $seconds): string
{
    return SqlText::instant(customerErasureMoved(app(Clock::class)->now(), $seconds));
}

/**
 * The database's now() moved by the seconds given, as the server writes an instant out: the clock
 * a hold's expiry is written by and read by, SqlText::holdIsLive.
 */
function customerErasureFromTheDatabase(int $seconds): string
{
    $at = DB::scalar('select '.SqlText::instantColumn('(now() + make_interval(secs => ?))'), [$seconds]);

    return is_string($at) ? $at : throw new LogicException('The server answered no instant.');
}

/**
 * Binds the application's clock for a case that reads a hold's expiry alone: the system clock
 * where no skew is given, the one the database's now() reads on this host, otherwise a clock
 * standing the seconds given from it, so a statement comparing the expiry with the application's
 * clock answers otherwise than one comparing it with now().
 */
function customerErasureClock(?int $skew): void
{
    app()->instance(Clock::class, $skew === null ? new SystemClock : new FixedClock(customerErasureMoved((new SystemClock)->now(), $skew)));
}

/**
 * A booking of luna under an address, written straight to the rows in the state given: one item
 * on Elena per segment, each segment its start and end in seconds from the bound clock's now, a
 * Haircut but for the last, which is a Colour followed by its thirty-minute processing gap with a
 * minute of buffer after it; a hold's class and its expiry in seconds from the database's now()
 * where the state holds one; the manage token's hash and a sealed copy, and an intake row, so an
 * erasure has each to take.
 *
 * @param  non-empty-list<array{int, int}>  $segments
 * @return array{id: int, uuid: string}
 */
function customerErasureBooking(string $email, string $status, array $segments = [[-26 * CUSTOMER_ERASURE_HOUR, -26 * CUSTOMER_ERASURE_HOUR + 2700]], ?string $holdClass = null, ?int $expiresIn = null, string $onApprovalTimeout = 'expire', string $name = LunaFixture::CUSTOMER_NAME): array
{
    $uuid = (string) Str::uuid();
    $id = DB::table('tenant_luna.bookings')->insertGetId([
        'source' => 'embed', 'uuid' => $uuid, 'status' => $status,
        'hold_class' => $holdClass, 'hold_expires_at' => $expiresIn === null ? null : customerErasureFromTheDatabase($expiresIn),
        'customer_name' => $name, 'customer_email' => $email, 'customer_phone' => LunaFixture::CUSTOMER_PHONE,
        'total_minor' => 3500 * (count($segments) - 1) + 8000, 'currency' => 'EUR', 'payment_mode' => 'onsite',
        'requires_approval' => $holdClass === 'approval', 'on_approval_timeout' => $onApprovalTimeout,
        'manage_token_hash' => hash('sha256', $uuid), 'manage_token_sealed' => 'sealed.'.$uuid,
    ]);

    foreach ($segments as $sort => [$from, $to]) {
        $last = $sort === count($segments) - 1;
        DB::table('tenant_luna.booking_items')->insert([
            'booking_id' => $id, 'sort' => $sort,
            'service_id' => LunaFixture::id('services', $last ? 'Colour' : 'Haircut'), 'staff_id' => LunaFixture::id('staff', 'Elena Papadaki'),
            'start_utc' => customerErasureAt($from), 'end_utc' => customerErasureAt($to),
            'block_start_utc' => customerErasureAt($from), 'block_end_utc' => customerErasureAt($last ? $to + 60 : $to),
            'processing_ends_utc' => $last ? customerErasureAt($to + 1800) : null,
            'price_minor' => $last ? 8000 : 3500, 'blocks' => false,
        ]);
    }

    DB::table('tenant_luna.booking_meta')->insert(['booking_id' => $id, 'key' => 'note', 'value' => 'Please call before the appointment.']);

    return ['id' => $id, 'uuid' => $uuid];
}

/**
 * A dataset's segments read back as the pairs of seconds they are.
 *
 * @param  array<mixed>  $segments
 * @return non-empty-list<array{int, int}>
 */
function customerErasureSegments(array $segments): array
{
    $pairs = [];

    foreach ($segments as $segment) {
        if (! is_array($segment) || ! isset($segment[0], $segment[1]) || ! is_int($segment[0]) || ! is_int($segment[1])) {
            throw new LogicException('A segment is a start and an end in seconds from now.');
        }

        $pairs[] = [$segment[0], $segment[1]];
    }

    return $pairs === [] ? throw new LogicException('A booking has one segment or more.') : $pairs;
}

/**
 * A My bookings link and a session for an address, each live, as a request and an opening leave
 * them.
 */
function customerErasureAccess(string $email): void
{
    DB::table('tenant_luna.customer_links')->insert(['email' => $email, 'token_hash' => bin2hex(random_bytes(32)), 'created_at' => customerErasureAt(-600), 'expires_at' => customerErasureAt(300)]);
    DB::table('tenant_luna.customer_sessions')->insert(['email' => $email, 'session_hash' => bin2hex(random_bytes(32)), 'created_at' => customerErasureAt(-600), 'expires_at' => customerErasureAt(30 * 24 * CUSTOMER_ERASURE_HOUR)]);
}

/**
 * A profile and a note for an address, keyed by it in lower case, as the page's Profile card and
 * the Notes card leave them.
 */
function customerErasureProfile(string $email): void
{
    DB::table('tenant_luna.customer_profiles')->insert(['email' => strtolower($email), 'tags' => '{VIP}', 'birthday' => '1988-04-12', 'language' => 'el', 'created_at' => customerErasureAt(-600), 'updated_at' => customerErasureAt(-600)]);
    DB::table('tenant_luna.customer_notes')->insert(['email' => strtolower($email), 'body' => 'Allergic to ammonia colour.', 'author_actor' => 'user:1', 'author_name' => 'Luna Owner', 'created_at' => customerErasureAt(-600)]);
}

/**
 * The rows a statement answers, each as its columns.
 *
 * @param  list<string>  $bindings
 * @return list<array<mixed>>
 */
function customerErasureRows(string $sql, array $bindings = []): array
{
    $rows = [];

    foreach (DB::select($sql, $bindings) as $row) {
        if (! $row instanceof stdClass) {
            throw new LogicException('A row came back as no object.');
        }

        $rows[] = get_object_vars($row);
    }

    return $rows;
}

/**
 * The rows a statement answers, each as its columns, every value text: what a comparison of
 * spelled values or a search inside them reads.
 *
 * @return list<array<string>>
 */
function customerErasureTexts(string $sql): array
{
    return array_map(fn (array $row): array => array_map(fn (mixed $value): string => is_string($value) ? $value : throw new LogicException('A column came back as no text.'), $row), customerErasureRows($sql));
}

/**
 * Every row of the tables an erasure may write, in luna's schema, as plain arrays.
 *
 * @return array<string, list<array<mixed>>>
 */
function customerErasureTables(): array
{
    $tables = [];

    foreach (['bookings' => 'id', 'booking_items' => 'id', 'booking_meta' => 'booking_id, key', 'customer_links' => 'id', 'customer_sessions' => 'id', 'customer_profiles' => 'email', 'customer_notes' => 'id', 'audit_log' => 'id'] as $table => $order) {
        $tables[$table] = customerErasureRows(sprintf('select * from tenant_luna.%s order by %s', $table, $order));
    }

    return $tables;
}

/**
 * The addresses of luna's links or sessions, each as stored.
 *
 * @return list<string>
 */
function customerErasureAddresses(string $table): array
{
    /** @var list<string> $emails */
    $emails = DB::table('tenant_luna.'.$table)->orderBy('id')->selectRaw('email::text as email')->pluck('email')->all();

    return $emails;
}

/**
 * The customer columns and both token columns of a booking, as the row stands.
 *
 * @return array<mixed>
 */
function customerErasurePerson(string $uuid): array
{
    return customerErasureRows('select customer_name, customer_email, customer_phone, manage_token_hash, manage_token_sealed from tenant_luna.bookings where uuid = ?::uuid', [$uuid])[0]
        ?? throw new LogicException('luna has no such booking.');
}

/**
 * How far a booking's last segment runs past its end_utc, in seconds: its block's end past the
 * buffer, and its processing gap's end.
 *
 * @return array<mixed>
 */
function customerErasurePastTheEnd(int $id): array
{
    return customerErasureRows('select extract(epoch from block_end_utc - end_utc)::int as buffer, extract(epoch from processing_ends_utc - end_utc)::int as gap from tenant_luna.booking_items where booking_id = ? order by sort desc limit 1', [(string) $id])[0]
        ?? throw new LogicException('luna has no such booking.');
}

function customerErasureOwner(): User
{
    return User::query()->where('email', 'owner@luna.example')->sole();
}

/**
 * The erasure's POST for an address, from the customer's page, as the user given or the owner.
 *
 * @return TestResponse<Response>
 */
function customerErasurePost(TestCase $test, string $email, ?User $as = null): TestResponse
{
    $page = tenantUrl('luna', '/admin/customers/'.rawurlencode($email));

    return $test->actingAs($as ?? customerErasureOwner())->from($page)->post($page.'/erase');
}

/**
 * The trail rows of luna an erasure wrote, oldest first.
 *
 * @return list<array<mixed>>
 */
function customerErasureTrail(): array
{
    return array_values(array_filter(LunaFixture::trail(), fn (array $row): bool => $row['action'] === 'customer_erased'));
}

it('erases a customer whose bookings are all settled: every booking of the address as citext compares it, its links and sessions, its profile and its notes, one empty trail row per booking naming the user, and the list no longer shows the address', function () {
    $completed = customerErasureBooking('Casey@Example.com', 'completed', [[-50 * CUSTOMER_ERASURE_HOUR, -49 * CUSTOMER_ERASURE_HOUR], [-49 * CUSTOMER_ERASURE_HOUR, -48 * CUSTOMER_ERASURE_HOUR]]);
    $cancelled = customerErasureBooking('casey@example.com', 'cancelled', [[2 * CUSTOMER_ERASURE_HOUR, 3 * CUSTOMER_ERASURE_HOUR]]);
    $expired = customerErasureBooking('CASEY@example.com', 'expired', [[5 * CUSTOMER_ERASURE_HOUR, 6 * CUSTOMER_ERASURE_HOUR]]);
    $neighbour = customerErasureBooking(CUSTOMER_ERASURE_NEIGHBOUR, 'confirmed', [[2 * CUSTOMER_ERASURE_HOUR, 3 * CUSTOMER_ERASURE_HOUR]], name: 'Robin Regular');
    customerErasureAccess('casey@EXAMPLE.com');
    customerErasureAccess(CUSTOMER_ERASURE_EMAIL);
    customerErasureAccess(CUSTOMER_ERASURE_NEIGHBOUR);
    customerErasureProfile(CUSTOMER_ERASURE_EMAIL);
    customerErasureProfile(CUSTOMER_ERASURE_NEIGHBOUR);
    $owner = customerErasureOwner();

    customerErasurePost($this, CUSTOMER_ERASURE_EMAIL)->assertRedirect(tenantUrl('luna', '/admin/customers'));

    $this->get(tenantUrl('luna', '/admin/customers'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Customers')
            ->where('flash', ['kind' => 'success', 'message' => adminLine(CustomerController::ERASED)])
            ->where('customers.total', 1)
            ->where('customers.rows.0.email', CUSTOMER_ERASURE_NEIGHBOUR));

    $gone = array_fill_keys(['customer_name', 'customer_email', 'customer_phone', 'manage_token_hash', 'manage_token_sealed'], null);

    expect(array_map(fn (array $booking): array => customerErasurePerson($booking['uuid']), [$completed, $cancelled, $expired]))->toBe([$gone, $gone, $gone])
        ->and(customerErasurePerson($neighbour['uuid'])['customer_email'])->toBe(CUSTOMER_ERASURE_NEIGHBOUR)
        ->and(customerErasureAddresses('customer_links'))->toBe([CUSTOMER_ERASURE_NEIGHBOUR])
        ->and(customerErasureAddresses('customer_sessions'))->toBe([CUSTOMER_ERASURE_NEIGHBOUR])
        ->and(customerErasureAddresses('customer_notes'))->toBe([CUSTOMER_ERASURE_NEIGHBOUR])
        ->and(DB::table('tenant_luna.customer_profiles')->selectRaw('email::text as email')->pluck('email')->all())->toBe([CUSTOMER_ERASURE_NEIGHBOUR])
        ->and(customerErasureTrail())->toBe(array_map(fn (array $booking): array => [
            'actor' => 'user:'.$owner->id,
            'action' => 'customer_erased',
            'payload' => [],
            'uuid' => $booking['uuid'],
        ], [$completed, $cancelled, $expired]));
});

it('passes the erasure\'s first assertion through the route: the booking rows stand with the customer fields nulled and the intake rows gone, every other column, every item and every trail row as it was', function () {
    $erased = [
        customerErasureBooking(CUSTOMER_ERASURE_EMAIL, 'completed', [[-50 * CUSTOMER_ERASURE_HOUR, -49 * CUSTOMER_ERASURE_HOUR], [-49 * CUSTOMER_ERASURE_HOUR, -48 * CUSTOMER_ERASURE_HOUR]]),
        customerErasureBooking(CUSTOMER_ERASURE_EMAIL, 'no_show'),
    ];
    $kept = customerErasureBooking(CUSTOMER_ERASURE_NEIGHBOUR, 'completed', name: 'Robin Regular');

    foreach ([...$erased, $kept] as $booking) {
        DB::table('tenant_luna.audit_log')->insert(['booking_id' => $booking['id'], 'actor' => 'customer', 'action' => 'held', 'payload' => '{"status_to":"pending","item_count":1}']);
    }

    $before = customerErasureTables();

    customerErasurePost($this, CUSTOMER_ERASURE_EMAIL)->assertRedirect(tenantUrl('luna', '/admin/customers'));

    $after = customerErasureTables();
    $ids = array_column($erased, 'id');
    $person = array_flip(['customer_name', 'customer_email', 'customer_phone', 'manage_token_hash', 'manage_token_sealed']);
    $nulled = array_map(fn (array $row): array => in_array($row['id'], $ids, true) ? [...$row, ...array_map(fn (): mixed => null, $person)] : $row, $before['bookings']);
    $written = count($before['audit_log']);

    expect($after['bookings'])->toBe($nulled)
        ->and(array_column($after['bookings'], 'id'))->toBe(array_column($before['bookings'], 'id'))
        ->and($after['booking_items'])->toBe($before['booking_items'])
        ->and($after['booking_meta'])->toBe(array_values(array_filter($before['booking_meta'], fn (array $row): bool => ! in_array($row['booking_id'], $ids, true))))
        ->and(array_column($after['booking_meta'], 'booking_id'))->toBe([$kept['id']])
        ->and(array_slice($after['audit_log'], 0, $written))->toBe($before['audit_log'])
        ->and(array_column(array_slice($after['audit_log'], $written), 'booking_id'))->toBe($ids);
});

it('passes the erasure\'s second assertion: a booking walked through its transitions and its customer erased, no trail row of the tenant ever held the name, the address or the phone, and the erasure updated no trail row', function () {
    $tenant = LunaFixture::tenant();
    $transitions = app(BookingTransitionFactory::class);
    $owner = customerErasureOwner();

    // Held, confirmed and moved by the customer, then cancelled by the business.
    $moved = LunaFixture::hold(['Haircut'], FixtureDay::DATE.' 10:00');
    $transitions->confirm($tenant)->confirm($moved, Actor::customer());
    $transitions->reschedule($tenant)->reschedule($moved, Actor::customer(), LunaFixture::at(FixtureDay::DATE.' 11:00'));
    $this->actingAs($owner)->post(tenantUrl('luna', '/admin/bookings/'.$moved.'/cancel'))->assertRedirect();

    // A request rejected, and a request approved and then cancelled, both by the business.
    $rejected = LunaFixture::hold(['Bridal trial'], FixtureDay::DATE.' 14:00');
    $this->post(tenantUrl('luna', '/admin/bookings/'.$rejected.'/reject'), ['reason' => 'The afternoon is fully booked.'])->assertRedirect();
    $approved = LunaFixture::hold(['Bridal trial'], FixtureDay::DATE.' 16:00');
    $this->post(tenantUrl('luna', '/admin/bookings/'.$approved.'/approve'))->assertRedirect();
    $this->post(tenantUrl('luna', '/admin/bookings/'.$approved.'/cancel'))->assertRedirect();

    // A checkout hold handed back by the customer.
    $released = LunaFixture::hold(['Haircut'], FixtureDay::DATE.' 12:00');
    $transitions->release($tenant)->release($released);

    expect(array_map(fn (string $uuid): mixed => LunaFixture::row($uuid)['status'], [$moved, $rejected, $approved, $released]))->toBe(['cancelled', 'rejected', 'cancelled', 'cancelled'])
        ->and(array_column(LunaFixture::trail(), 'action'))->toContain('held', 'confirmed', 'rescheduled', 'cancelled', 'rejected', 'approved', 'released');

    // Every version of every trail row as it stands, ctid and xmin among it: an update writes a
    // new version, so a row the erasure touched, even to write the same values, reads otherwise.
    $versions = fn (): array => customerErasureTexts('select entry.ctid::text as ctid, entry.xmin::text as xmin, entry.id::text as id, entry.booking_id::text as booking_id, entry.actor, entry.action, entry.payload::text as payload, entry.created_at::text as created_at from tenant_luna.audit_log entry order by entry.id');
    $before = $versions();

    customerErasurePost($this, LunaFixture::CUSTOMER_EMAIL)->assertRedirect(tenantUrl('luna', '/admin/customers'));

    $after = $versions();
    $person = [LunaFixture::CUSTOMER_NAME, LunaFixture::CUSTOMER_EMAIL, LunaFixture::CUSTOMER_PHONE];
    $naming = array_values(array_filter($after, fn (array $row): bool => array_filter($person, fn (string $value): bool => stripos(implode(' ', [$row['actor'] ?? '', $row['action'] ?? '', $row['payload'] ?? '']), $value) !== false) !== []));
    $erasedRows = array_slice($after, count($before));

    expect(count($before))->toBeGreaterThan(8)
        ->and(array_slice($after, 0, count($before)))->toBe($before)
        ->and($naming)->toBe([])
        ->and(array_map(fn (array $row): array => [$row['actor'], $row['action'], $row['payload']], $erasedRows))->toBe(array_fill(0, 4, ['user:'.$owner->id, 'customer_erased', '{}']))
        ->and(DB::table('tenant_luna.bookings')->whereNotNull('customer_email')->count())->toBe(0);
});

it('refuses the erasure while a booking of the address is live, as a 409 naming the count with the sentence to cancel first, and writes nothing', function (string $status, array $segments, ?string $holdClass, ?int $expiresIn, string $onApprovalTimeout) {
    customerErasureBooking(CUSTOMER_ERASURE_EMAIL, 'completed');
    customerErasureBooking(CUSTOMER_ERASURE_EMAIL, $status, customerErasureSegments($segments), $holdClass, $expiresIn, $onApprovalTimeout);
    customerErasureAccess(CUSTOMER_ERASURE_EMAIL);
    $before = customerErasureTables();

    customerErasurePost($this, CUSTOMER_ERASURE_EMAIL)
        ->assertStatus(409)
        ->assertExactJson(['code' => CustomerLive::CODE, 'message' => CustomerLive::CODE, 'data' => ['status' => 409, 'detail' => adminChoice(CustomerController::LIVE, 1)]]);

    expect(customerErasureTables())->toBe($before);
})->with([
    'a request awaiting a decision inside its window' => ['awaiting_approval', [[2 * CUSTOMER_ERASURE_HOUR, 3 * CUSTOMER_ERASURE_HOUR]], 'approval', 48 * CUSTOMER_ERASURE_HOUR, 'expire'],
    'a request past its window that auto-approves, still awaiting the sweeper\'s grant' => ['awaiting_approval', [[2 * CUSTOMER_ERASURE_HOUR, 3 * CUSTOMER_ERASURE_HOUR]], 'approval', -60, 'auto_approve'],
    'a request past its window that expires, not yet swept' => ['awaiting_approval', [[2 * CUSTOMER_ERASURE_HOUR, 3 * CUSTOMER_ERASURE_HOUR]], 'approval', -60, 'expire'],
    'a booking awaiting payment' => ['awaiting_payment', [[2 * CUSTOMER_ERASURE_HOUR, 3 * CUSTOMER_ERASURE_HOUR]], 'payment', 24 * CUSTOMER_ERASURE_HOUR, 'expire'],
    'a booking awaiting payment past its hold, not yet swept' => ['awaiting_payment', [[2 * CUSTOMER_ERASURE_HOUR, 3 * CUSTOMER_ERASURE_HOUR]], 'payment', -60, 'expire'],
    'a confirmed appointment still to come' => ['confirmed', [[2 * CUSTOMER_ERASURE_HOUR, 3 * CUSTOMER_ERASURE_HOUR]], null, null, 'expire'],
    'a confirmed chain whose last segment ends a second after now' => ['confirmed', [[-2 * CUSTOMER_ERASURE_HOUR, -CUSTOMER_ERASURE_HOUR], [-1800, 1]], null, null, 'expire'],
]);

it('erases a customer whose one booking is settled, by its status or by the clock', function (string $status, array $segments) {
    $booking = customerErasureBooking(CUSTOMER_ERASURE_EMAIL, $status, customerErasureSegments($segments));

    customerErasurePost($this, CUSTOMER_ERASURE_EMAIL)->assertRedirect(tenantUrl('luna', '/admin/customers'));

    expect(customerErasurePerson($booking['uuid'])['customer_email'])->toBeNull()
        ->and(customerErasureTrail())->toHaveCount(1)
        ->and(customerErasurePastTheEnd($booking['id']))->toBe(['buffer' => 60, 'gap' => 1800]);
})->with([
    'a confirmed chain whose last segment ended at now, its buffer and its processing gap still running' => ['confirmed', [[-2 * CUSTOMER_ERASURE_HOUR, -CUSTOMER_ERASURE_HOUR], [-1800, 0]]],
    'completed' => ['completed', [[-3 * CUSTOMER_ERASURE_HOUR, -2 * CUSTOMER_ERASURE_HOUR]]],
    'a no-show' => ['no_show', [[-3 * CUSTOMER_ERASURE_HOUR, -2 * CUSTOMER_ERASURE_HOUR]]],
    'cancelled with its time still to come' => ['cancelled', [[2 * CUSTOMER_ERASURE_HOUR, 3 * CUSTOMER_ERASURE_HOUR]]],
    'rejected with its time still to come' => ['rejected', [[2 * CUSTOMER_ERASURE_HOUR, 3 * CUSTOMER_ERASURE_HOUR]]],
    'expired with its time still to come' => ['expired', [[2 * CUSTOMER_ERASURE_HOUR, 3 * CUSTOMER_ERASURE_HOUR]]],
]);

dataset('the application clocks against the database\'s', [
    'on the system clock' => [null],
    'on an application clock a day ahead of the database\'s' => [24 * CUSTOMER_ERASURE_HOUR],
    'on an application clock a day behind the database\'s' => [-24 * CUSTOMER_ERASURE_HOUR],
]);

it('refuses the erasure of a checkout hold the database\'s clock still holds, a minute from its expiry, whatever the application\'s clock reads, and writes nothing', function (?int $skew) {
    customerErasureClock($skew);
    customerErasureBooking(CUSTOMER_ERASURE_EMAIL, 'pending', [[2 * CUSTOMER_ERASURE_HOUR, 3 * CUSTOMER_ERASURE_HOUR]], 'checkout', 60);
    $before = customerErasureTables();

    customerErasurePost($this, CUSTOMER_ERASURE_EMAIL)
        ->assertStatus(409)
        ->assertJsonPath('data.detail', adminChoice(CustomerController::LIVE, 1));

    expect(customerErasureTables())->toBe($before);
})->with('the application clocks against the database\'s');

it('erases a checkout hold the database\'s clock let go a second ago, whatever the application\'s clock reads', function (?int $skew) {
    customerErasureClock($skew);
    $booking = customerErasureBooking(CUSTOMER_ERASURE_EMAIL, 'pending', [[2 * CUSTOMER_ERASURE_HOUR, 3 * CUSTOMER_ERASURE_HOUR]], 'checkout', -1);

    customerErasurePost($this, CUSTOMER_ERASURE_EMAIL)->assertRedirect(tenantUrl('luna', '/admin/customers'));

    expect(customerErasurePerson($booking['uuid'])['customer_email'])->toBeNull()
        ->and(customerErasureTrail())->toHaveCount(1);
})->with('the application clocks against the database\'s');

it('names the count of every live booking, on the customer\'s page before any press as on the POST, and nothing while none is', function () {
    customerErasureBooking(CUSTOMER_ERASURE_EMAIL, 'completed');
    customerErasureBooking(CUSTOMER_ERASURE_EMAIL, 'confirmed', [[2 * CUSTOMER_ERASURE_HOUR, 3 * CUSTOMER_ERASURE_HOUR]]);
    customerErasureBooking('Casey@Example.com', 'pending', [[4 * CUSTOMER_ERASURE_HOUR, 5 * CUSTOMER_ERASURE_HOUR]], 'checkout', 600);
    customerErasureBooking(CUSTOMER_ERASURE_NEIGHBOUR, 'completed', name: 'Robin Regular');
    $sentence = adminChoice(CustomerController::LIVE, 2);

    $this->actingAs(customerErasureOwner())->get(tenantUrl('luna', '/admin/customers/'.rawurlencode(CUSTOMER_ERASURE_EMAIL)))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Customer')->where('erasure', ['refusal' => $sentence])->where('customer.bookings', 3));

    customerErasurePost($this, CUSTOMER_ERASURE_EMAIL)->assertStatus(409)->assertJsonPath('data.detail', $sentence);

    $this->get(tenantUrl('luna', '/admin/customers/'.rawurlencode(CUSTOMER_ERASURE_NEIGHBOUR)))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Customer')->where('erasure', ['refusal' => null]));
});

it('words the refusal in the request\'s language, a choice by the count at one and at more than one, and the erasure\'s flash: a user at el reads customers.php\'s Greek, a colleague at en its English', function () {
    DB::table('users')->where('email', 'owner@luna.example')->update(['locale' => SiteLanguage::Greek->value]);
    $colleague = User::query()->findOrFail(addTenantUser('manager@luna.example', 'manager'));
    $colleague->forceFill(['locale' => SiteLanguage::English->value])->save();
    customerErasureBooking(CUSTOMER_ERASURE_EMAIL, 'confirmed', [[2 * CUSTOMER_ERASURE_HOUR, 3 * CUSTOMER_ERASURE_HOUR]]);
    customerErasureBooking(CUSTOMER_ERASURE_NEIGHBOUR, 'confirmed', [[2 * CUSTOMER_ERASURE_HOUR, 3 * CUSTOMER_ERASURE_HOUR]], name: 'Robin Regular');
    customerErasureBooking(CUSTOMER_ERASURE_NEIGHBOUR, 'confirmed', [[4 * CUSTOMER_ERASURE_HOUR, 5 * CUSTOMER_ERASURE_HOUR]], name: 'Robin Regular');
    customerErasureBooking('done@example.test', 'completed', name: 'Dana Done');
    $one = adminChoice(CustomerController::LIVE, 1, [], SiteLanguage::Greek);
    $two = adminChoice(CustomerController::LIVE, 2, [], SiteLanguage::Greek);
    $page = fn (string $email, ?User $as = null) => $this->actingAs($as ?? customerErasureOwner())->get(tenantUrl('luna', '/admin/customers/'.rawurlencode($email)))->assertOk();

    $page(CUSTOMER_ERASURE_EMAIL)->assertInertia(fn (Assert $page) => $page->where('erasure', ['refusal' => $one]));
    $page(CUSTOMER_ERASURE_NEIGHBOUR)->assertInertia(fn (Assert $page) => $page->where('erasure', ['refusal' => $two]));
    customerErasurePost($this, CUSTOMER_ERASURE_EMAIL)->assertStatus(409)->assertJsonPath('data.detail', $one);
    customerErasurePost($this, CUSTOMER_ERASURE_NEIGHBOUR)->assertStatus(409)->assertJsonPath('data.detail', $two);

    $page(CUSTOMER_ERASURE_EMAIL, $colleague)->assertInertia(fn (Assert $page) => $page->where('erasure', ['refusal' => adminChoice(CustomerController::LIVE, 1)]));
    customerErasurePost($this, CUSTOMER_ERASURE_NEIGHBOUR, $colleague)->assertStatus(409)->assertJsonPath('data.detail', adminChoice(CustomerController::LIVE, 2));

    customerErasurePost($this, 'done@example.test')->assertRedirect(tenantUrl('luna', '/admin/customers'));
    expect(session('flash'))->toBe(['kind' => 'success', 'message' => adminLine(CustomerController::ERASED, [], SiteLanguage::Greek)])
        ->and($one)->not->toBe($two)->not->toBe(adminChoice(CustomerController::LIVE, 1))
        ->and($two)->toContain('2');
});

it('answers a second erasure of the same address 404, no booking carrying it any more, and writes nothing', function () {
    customerErasureBooking(CUSTOMER_ERASURE_EMAIL, 'completed');
    customerErasurePost($this, CUSTOMER_ERASURE_EMAIL)->assertRedirect(tenantUrl('luna', '/admin/customers'));
    customerErasureAccess(CUSTOMER_ERASURE_EMAIL);
    $before = customerErasureTables();

    customerErasurePost($this, CUSTOMER_ERASURE_EMAIL)->assertNotFound();
    customerErasurePost($this, 'nobody@example.test')->assertNotFound();

    expect(customerErasureTables())->toBe($before)
        ->and(customerErasureTrail())->toHaveCount(1);
});

it('refuses a viewer without manageBookings the customer\'s page and the erasure, 403 each, and writes nothing', function () {
    customerErasureBooking(CUSTOMER_ERASURE_EMAIL, 'completed');
    $staff = User::query()->findOrFail(addTenantUser('staff@luna.example', 'staff'));
    $before = customerErasureTables();

    $this->actingAs($staff)->get(tenantUrl('luna', '/admin/customers/'.rawurlencode(CUSTOMER_ERASURE_EMAIL)))->assertForbidden();
    customerErasurePost($this, CUSTOMER_ERASURE_EMAIL, $staff)->assertForbidden();

    expect(customerErasureTables())->toBe($before);
});

it('erases the customer of a tenant whose subscription has lapsed, the erasure being outside the subscription gate', function () {
    $booking = customerErasureBooking(CUSTOMER_ERASURE_EMAIL, 'completed');
    billingSet(['subscription_state' => 'canceled', 'grace_ends_at' => null]);

    customerErasurePost($this, CUSTOMER_ERASURE_EMAIL)->assertRedirect(tenantUrl('luna', '/admin/customers'));

    expect(customerErasurePerson($booking['uuid'])['customer_email'])->toBeNull();
});

it('runs the primitive, the deletes of the links, the sessions, the profile and the notes and the trail in one transaction', function () {
    customerErasureBooking(CUSTOMER_ERASURE_EMAIL, 'completed');
    customerErasureAccess(CUSTOMER_ERASURE_EMAIL);
    customerErasureProfile(CUSTOMER_ERASURE_EMAIL);

    // Each statement that writes one of the seven tables records the transaction it ran in, the
    // top-level one, which a savepoint shares and a transaction of its own does not.
    DB::unprepared(<<<'SQL'
        create table tenant_luna.erasure_probe (seq bigserial primary key, tbl text not null, op text not null, txid bigint not null);
        create function tenant_luna.erasure_probe() returns trigger language plpgsql as $$
        begin
            insert into tenant_luna.erasure_probe (tbl, op, txid) values (tg_table_name, tg_op, txid_current());
            return null;
        end
        $$;
        create trigger erasure_probe after insert or update or delete on tenant_luna.bookings for each statement execute function tenant_luna.erasure_probe();
        create trigger erasure_probe after insert or update or delete on tenant_luna.booking_meta for each statement execute function tenant_luna.erasure_probe();
        create trigger erasure_probe after insert or update or delete on tenant_luna.customer_links for each statement execute function tenant_luna.erasure_probe();
        create trigger erasure_probe after insert or update or delete on tenant_luna.customer_sessions for each statement execute function tenant_luna.erasure_probe();
        create trigger erasure_probe after insert or update or delete on tenant_luna.customer_profiles for each statement execute function tenant_luna.erasure_probe();
        create trigger erasure_probe after insert or update or delete on tenant_luna.customer_notes for each statement execute function tenant_luna.erasure_probe();
        create trigger erasure_probe after insert or update or delete on tenant_luna.audit_log for each statement execute function tenant_luna.erasure_probe();
        SQL);

    customerErasurePost($this, CUSTOMER_ERASURE_EMAIL)->assertRedirect(tenantUrl('luna', '/admin/customers'));

    $probed = customerErasureTexts("select tbl || ' ' || op as statement, txid::text as txid from tenant_luna.erasure_probe order by tbl, op");

    expect(array_column($probed, 'statement'))->toBe(['audit_log INSERT', 'booking_meta DELETE', 'bookings UPDATE', 'customer_links DELETE', 'customer_notes DELETE', 'customer_profiles DELETE', 'customer_sessions DELETE'])
        ->and(array_unique(array_column($probed, 'txid')))->toHaveCount(1);
});

it('writes nothing when the trail cannot be written, the primitive and the deletes rolling back with it', function () {
    customerErasureBooking(CUSTOMER_ERASURE_EMAIL, 'completed');
    customerErasureAccess(CUSTOMER_ERASURE_EMAIL);
    customerErasureProfile(CUSTOMER_ERASURE_EMAIL);
    $before = customerErasureTables();

    DB::unprepared(<<<'SQL'
        create function tenant_luna.erasure_refuse() returns trigger language plpgsql as $$
        begin
            raise exception 'the trail refuses this row';
        end
        $$;
        create trigger erasure_refuse before insert on tenant_luna.audit_log for each row execute function tenant_luna.erasure_refuse();
        SQL);

    customerErasurePost($this, CUSTOMER_ERASURE_EMAIL)->assertStatus(500);

    expect(customerErasureTables())->toBe($before);
});
