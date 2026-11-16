<?php

use App\Application\Checkpoint;
use App\Application\Clock;
use App\Application\Dto\MoveChoices;
use App\Application\GuardedWrite;
use App\Domain\Audit\Actor;
use App\Http\ClosureCancellations;
use App\Http\ClosureChoice;
use App\Http\ClosureChoices;
use App\Http\ClosureOverBookings;
use App\Http\ErrorEnvelope;
use App\Http\Principal;
use App\Http\Requests\BlackoutRequest;
use App\Infrastructure\BookingTransitionFactory;
use App\Infrastructure\Cache\StaffDayMaskCache;
use App\Infrastructure\Db\BudgetExceeded;
use App\Infrastructure\Db\Busy;
use App\Infrastructure\Db\LockedClosures;
use App\Infrastructure\Db\PreconditionFailed;
use App\Infrastructure\Db\RetriesExhausted;
use App\Infrastructure\Db\SettingsReader;
use App\Models\User;
use App\Notifications\TenantClock;
use App\Notifications\TenantTime;
use App\Site\SiteLanguage;
use App\Tenancy\Slug;
use App\Tenancy\Tenant;
use App\Tenancy\TenantUrls;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\Support\FixedClock;
use Tests\Support\LunaFixture;
use Tests\TestCase;

/*
 * Adding a closure over time that already holds bookings, on both add routes, over luna's real
 * rows: an add with no choice over bookings writes nothing and answers 409 bookings_inside with
 * the list and the choices; an add with a choice names the bookings it was shown and is written
 * only when those are still exactly the bookings inside; keep and move leave the bookings, and
 * cancel cancels or rejects each in its own write, the customer told by the closure's own email
 * in place of the usual one.
 *
 * The cancel enters the transaction runner, so the file truncates rather than wrapping each test
 * in a transaction, and seeds the demo afresh. luna is in Europe/Athens, UTC+2 in November.
 * 2026-11-03 is a Tuesday; the fixture's bookings are Elena's, 45 minutes from the hour named.
 * The clock stands on the Monday before at 08:00 Athens.
 */

pest()->use(DatabaseTruncation::class);

beforeEach(function () {
    $this->withoutVite();
    dropDemoSchemas();
    seedDemo();
    app()->instance(StaffDayMaskCache::class, coldMaskCache());
    app()->instance(Clock::class, FixedClock::at('2026-11-02 06:00:00'));
    settingsOf('luna')->update(['reminder_lead_hours' => 0]);
    seamTransport()->flush();
});

afterEach(function () {
    Checkpoint::observe(null);
    dropDemoSchemas();
    DB::table('support_sessions')->delete();
    DB::table('users')->delete();
    DB::table('tenants')->delete();
});

const COB_OTHER_EMAIL = 'ophelia.other@example.test';

function cobOwner(): User
{
    return User::query()->where('email', 'owner@luna.example')->sole();
}

/**
 * Posts a closure as the admin's forms do, for the business or for the member named.
 *
 * @param  array<string, mixed>  $body
 * @return TestResponse<Response>
 */
function cobAdd(TestCase $test, array $body, ?string $staff = null, ?User $user = null): TestResponse
{
    $path = $staff === null ? '/admin/blackouts' : '/admin/staff/'.LunaFixture::id('staff', $staff).'/blackouts';

    return $test->actingAs($user ?? cobOwner())
        ->from(tenantUrl('luna', '/admin/staff'))
        ->json('POST', tenantUrl('luna', $path), $body, ['X-Inertia' => 'true']);
}

/**
 * The bookings a refusal listed, each as the next post names it back.
 *
 * @param  TestResponse<Response>  $response
 * @return list<array{uuid: string, status: string}>
 */
function cobSeen(TestResponse $response): array
{
    $bookings = $response->json('data.bookings');

    assert(is_array($bookings));

    return array_values(array_map(function (mixed $booking): array {
        assert(is_array($booking) && is_string($booking['uuid']) && is_string($booking['status']));

        return ['uuid' => $booking['uuid'], 'status' => $booking['status']];
    }, $bookings));
}

/**
 * The ids a refusal listed, in its order.
 *
 * @param  TestResponse<Response>  $response
 * @return list<string>
 */
function cobListed(TestResponse $response): array
{
    return array_column(cobSeen($response), 'uuid');
}

/**
 * The failure an add of 2026-11-03 for the tenant stops at before it locks or writes anything:
 * one of the things the add asserts of its own transaction did not hold.
 */
function cobPrecondition(Tenant $tenant): PreconditionFailed
{
    try {
        app(LockedClosures::class)->add($tenant, null, '2026-11-03', null, null, null, 'Works', null);
    } catch (PreconditionFailed $failure) {
        return $failure;
    }

    Assert::fail('The add went through.');
}

/** How many closure rows luna holds on the date, the business's and every member's. */
function cobClosures(string $date): int
{
    return DB::table('tenant_luna.blackouts')->where('date', $date)->count();
}

function cobStatus(string $uuid): string
{
    $status = DB::table('tenant_luna.bookings')->where('uuid', $uuid)->value('status');

    assert(is_string($status));

    return $status;
}

/** A booking of a second customer, so each customer's mail can be told apart. */
function cobOtherCustomer(string $uuid): void
{
    DB::table('tenant_luna.bookings')->where('uuid', $uuid)->update(['customer_email' => COB_OTHER_EMAIL, 'customer_name' => 'Ophelia Other']);
}

/** A second service on the booking, on Nikos, starting where the first one ends. */
function cobNikosAfter(string $uuid, string $from, string $to): void
{
    $booking = DB::table('tenant_luna.bookings')->where('uuid', $uuid)->value('id');

    DB::table('tenant_luna.booking_items')->insert([
        'booking_id' => $booking,
        'sort' => 1,
        'service_id' => LunaFixture::id('services', 'Haircut'),
        'staff_id' => LunaFixture::id('staff', 'Nikos Georgiou'),
        'start_utc' => LunaFixture::instant(LunaFixture::at($from)),
        'end_utc' => LunaFixture::instant(LunaFixture::at($to)),
        'block_start_utc' => LunaFixture::instant(LunaFixture::at($from)),
        'block_end_utc' => LunaFixture::instant(LunaFixture::at($to)),
        'price_minor' => 3500,
        'blocks' => true,
    ]);
}

function cobRequest(string $start = '2026-11-03 15:00'): string
{
    return LunaFixture::booking('awaiting_approval', 'approval', 'future', start: $start, service: 'Bridal trial', requiresApproval: true);
}

/** Where a booking's first service stands: its member, and its start on the Athens clock. */
function cobStands(string $uuid): string
{
    $row = DB::selectOne(
        "select member.name as staff, to_char(item.start_utc at time zone 'Europe/Athens', 'YYYY-MM-DD HH24:MI') as start"
        .' from tenant_luna.bookings booking'
        .' join tenant_luna.booking_items item on item.booking_id = booking.id'
        .' join tenant_luna.staff member on member.id = item.staff_id'
        .' where booking.uuid = ?::uuid order by item.sort limit 1',
        [$uuid],
    );

    assert($row instanceof stdClass && is_string($row->staff) && is_string($row->start));

    return $row->staff.' '.$row->start;
}

/** A booking as the flash names one left as it was: its customer and its start on luna's clock. */
function cobNamed(string $customer, string $start): string
{
    $tenant = LunaFixture::tenant();
    $clock = TenantClock::of($tenant, app(SettingsReader::class)->read($tenant));

    return $customer.', '.TenantTime::instant(LunaFixture::at($start), $clock, SiteLanguage::English);
}

/**
 * The flash of a cancel that left bookings as they were: how many went, then those left, named.
 *
 * @param  list<string>  $left
 * @return array{kind: string, message: string}
 */
function cobLeft(int $cancelled, array $left): array
{
    $first = $cancelled === 0 ? adminLine(ClosureOverBookings::ADDED) : trans_choice(ClosureOverBookings::CANCELLED, $cancelled);

    return ['kind' => 'error', 'message' => $first.' '.trans_choice(ClosureOverBookings::SKIPPED, count($left), ['bookings' => implode('; ', $left)])];
}

/**
 * A member's staff-day held on a connection of its own, in a transaction left open for the caller
 * to roll back, so a write that locks the day waits out the lock timeout.
 */
function cobDayHeld(string $member, string $day): Connection
{
    $connection = LunaFixture::probe('closure_probe');
    $connection->beginTransaction();
    $connection->statement(
        'insert into tenant_luna.staff_days (staff_id, day, rev) values (?, ?, 0) on conflict (staff_id, day) do update set rev = staff_days.rev + 1',
        [LunaFixture::id('staff', $member), $day],
    );

    return $connection;
}

/**
 * The body of an add, validated as the route validates one, for a call on the class itself.
 *
 * @param  array<string, mixed>  $body
 */
function cobBody(array $body): BlackoutRequest
{
    $request = BlackoutRequest::create(tenantUrl('luna', '/admin/blackouts'), 'POST', $body);
    $request->setContainer(app())->setRedirector(app(Redirector::class));
    $request->validateResolved();

    return $request;
}

/** A member of luna's staff signed in, with the role of staff and that member's own row. */
function cobStaff(string $email, string $member): Principal
{
    return Principal::user(User::query()->findOrFail(addTenantUser($email, 'staff', $member)));
}

/**
 * The busy answer, word for word.
 *
 * @return array{code: string, message: string, data: array{status: int, detail: string}}
 */
function cobBusy(): array
{
    return ['code' => 'busy', 'message' => 'busy', 'data' => ['status' => 503, 'detail' => apiSentence(ErrorEnvelope::BUSY_DETAIL)]];
}

it('adds a closure over time that holds no counted booking as it always did, with no choice and nothing more asked', function () {
    LunaFixture::booking('confirmed', start: '2026-11-04 10:00');

    cobAdd($this, ['date' => '2026-11-03', 'reason' => 'Stocktake'])->assertRedirect(tenantUrl('luna', '/admin/staff'));

    expect(session('flash'))->toBe(['kind' => 'success', 'message' => adminLine(ClosureOverBookings::ADDED)])
        ->and(cobClosures('2026-11-03'))->toBe(1);
});

it('writes nothing over a booking and answers 409 bookings_inside with the list, the count and the choices the owner is offered', function () {
    $uuid = LunaFixture::booking('confirmed', start: '2026-11-03 10:00');

    $response = cobAdd($this, ['date' => '2026-11-03', 'reason' => 'Stocktake'])->assertStatus(409);

    $response->assertExactJson([
        'code' => 'bookings_inside',
        'message' => 'bookings_inside',
        'data' => [
            'status' => 409,
            'detail' => trans_choice(ClosureOverBookings::INSIDE, 1),
            'count' => 1,
            'changed' => false,
            'bookings' => [[
                'uuid' => $uuid,
                'status' => 'confirmed',
                'start' => '2026-11-03T08:00:00+00:00',
                'end' => '2026-11-03T08:45:00+00:00',
                'customer_name' => LunaFixture::CUSTOMER_NAME,
                'items' => [[
                    'sort' => 0,
                    'service_id' => LunaFixture::id('services', 'Haircut'),
                    'service_name' => 'Haircut',
                    'staff_id' => LunaFixture::id('staff', 'Elena Papadaki'),
                    'staff_name' => 'Elena Papadaki',
                    'start' => '2026-11-03T08:00:00+00:00',
                    'end' => '2026-11-03T08:45:00+00:00',
                ]],
            ]],
            'choices' => ['keep', 'move', 'cancel'],
        ],
    ]);

    expect(trans_choice(ClosureOverBookings::INSIDE, 1))->toBe('1 booking falls inside this closure.')
        ->and(trans_choice(ClosureOverBookings::INSIDE, 3))->toBe('3 bookings fall inside this closure.')
        ->and(cobClosures('2026-11-03'))->toBe(0)
        ->and(cobStatus($uuid))->toBe('confirmed');
});

it('counts a booking whose time overlaps the window, and not one that ends as the window starts or starts as it ends', function (string $start, string $end, bool $counted) {
    // The booking is 10:00 to 10:45 in Athens.
    LunaFixture::booking('confirmed', start: '2026-11-03 10:00');

    $response = cobAdd($this, ['date' => '2026-11-03', 'start_time' => $start, 'end_time' => $end]);

    if ($counted) {
        $response->assertStatus(409)->assertJsonPath('data.count', 1);
        expect(cobClosures('2026-11-03'))->toBe(0);
    } else {
        $response->assertRedirect();
        expect(cobClosures('2026-11-03'))->toBe(1);
    }
})->with([
    'the window ending as it starts' => ['09:00', '10:00', false],
    'the window starting as it ends' => ['10:45', '12:00', false],
    'the window over its end' => ['10:40', '11:00', true],
    'the window over its start' => ['09:30', '10:05', true],
    'the window inside it' => ['10:10', '10:20', true],
    'the window round it' => ['09:00', '12:00', true],
]);

it('checks every date of a range with the window on each, and lists the bookings soonest first', function () {
    $later = LunaFixture::booking('confirmed', start: '2026-11-05 10:30');
    $sooner = LunaFixture::booking('confirmed', start: '2026-11-03 10:15');
    LunaFixture::booking('confirmed', start: '2026-11-04 15:00');

    $response = cobAdd($this, ['date' => '2026-11-03', 'until' => '2026-11-05', 'start_time' => '10:00', 'end_time' => '11:00'])->assertStatus(409);

    expect(cobListed($response))->toBe([$sooner, $later])
        ->and($response->json('data.detail'))->toBe(trans_choice(ClosureOverBookings::INSIDE, 2))
        ->and(DB::table('tenant_luna.blackouts')->whereBetween('date', ['2026-11-03', '2026-11-05'])->count())->toBe(0);

    cobAdd($this, ['date' => '2026-11-06', 'until' => '2026-11-08'])->assertRedirect();
});

it('reads the window by each date\'s own wall clock across a day the clocks change on, the offset from UTC one thing before it and another after', function (string $before, string $day, string $after) {
    $dates = [$before, $day, $after];
    $ending = [];
    $starting = [];

    // On each date a booking that ends as the window starts, 08:15 to 09:00 in Athens, and one
    // that starts as it ends, 10:00 to 10:45.
    foreach ($dates as $date) {
        $ending[] = LunaFixture::booking('confirmed', start: $date.' 08:15');
        $starting[] = LunaFixture::booking('confirmed', start: $date.' 10:00');
    }

    $offsets = array_map(fn (string $date): int => LunaFixture::at($date.' 09:00')->getOffset(), $dates);

    expect($offsets[0])->not->toBe($offsets[2]);

    // A minute over either edge counts the one booking of each date on that edge, and no other.
    expect(cobListed(cobAdd($this, ['date' => $before, 'until' => $after, 'start_time' => '08:00', 'end_time' => '08:16'])->assertStatus(409)))->toBe($ending)
        ->and(cobListed(cobAdd($this, ['date' => $before, 'until' => $after, 'start_time' => '10:44', 'end_time' => '11:00'])->assertStatus(409)))->toBe($starting);

    cobAdd($this, ['date' => $before, 'until' => $after, 'start_time' => '09:00', 'end_time' => '10:00'])->assertRedirect();

    expect(array_map(cobClosures(...), $dates))->toBe([1, 1, 1]);
})->with('clock changes');

it('closes a day the clocks change on from its midnight to the next, an hour short or an hour long, and a window over the changed hour by the clock on the wall', function (string $before, string $day, string $after) {
    // Outside the day by its two edges: one that ends at its midnight, one that starts at the next.
    LunaFixture::booking('confirmed', start: $before.' 23:15');
    LunaFixture::booking('confirmed', start: $after.' 00:00');
    $first = LunaFixture::booking('confirmed', start: $day.' 00:00');
    // Round the hour that goes missing or comes twice, between 03:00 and 04:00: one that ends at
    // 02:00, one from 04:15 to 05:00, one that starts at 05:00.
    $ends = LunaFixture::booking('confirmed', start: $day.' 01:15');
    $inside = LunaFixture::booking('confirmed', start: $day.' 04:15');
    $starts = LunaFixture::booking('confirmed', start: $day.' 05:00');
    $last = LunaFixture::booking('confirmed', start: $day.' 23:15');

    $hours = intdiv(LunaFixture::at($after.' 00:00')->getTimestamp() - LunaFixture::at($day.' 00:00')->getTimestamp(), 3600);

    expect($hours)->not->toBe(24)
        ->and(cobListed(cobAdd($this, ['date' => $day])->assertStatus(409)))->toBe([$first, $ends, $inside, $starts, $last])
        ->and(cobListed(cobAdd($this, ['date' => $day, 'start_time' => '02:00', 'end_time' => '05:00'])->assertStatus(409)))->toBe([$inside])
        ->and(cobClosures($day))->toBe(0);
})->with('clock changes');

dataset('clock changes', [
    'the spring day, an hour short' => ['2027-03-27', '2027-03-28', '2027-03-29'],
    'the autumn day, an hour long' => ['2027-10-30', '2027-10-31', '2027-11-01'],
]);

it('counts a person\'s closure over that person\'s bookings only, and the business\'s over everyone\'s', function () {
    $uuid = LunaFixture::booking('confirmed', start: '2026-11-03 10:00');

    cobAdd($this, ['date' => '2026-11-03'], 'Nikos Georgiou')->assertRedirect();
    cobAdd($this, ['date' => '2026-11-03'], 'Elena Papadaki')->assertStatus(409)->assertJsonPath('data.bookings.0.uuid', $uuid);
    cobAdd($this, ['date' => '2026-11-03'])->assertStatus(409)->assertJsonPath('data.bookings.0.uuid', $uuid);

    expect(DB::table('tenant_luna.blackouts')->where('date', '2026-11-03')->pluck('staff_id')->all())->toBe([LunaFixture::id('staff', 'Nikos Georgiou')]);
});

it('counts a booking of several services once, and for a person when any service they hold overlaps', function () {
    // Elena 10:00 to 10:45, then Nikos 10:45 to 11:30.
    $uuid = LunaFixture::booking('confirmed', start: '2026-11-03 10:00');
    cobNikosAfter($uuid, '2026-11-03 10:45', '2026-11-03 11:30');

    $business = cobAdd($this, ['date' => '2026-11-03', 'start_time' => '10:00', 'end_time' => '12:00'])->assertStatus(409);
    $nikos = cobAdd($this, ['date' => '2026-11-03', 'start_time' => '11:00', 'end_time' => '12:00'], 'Nikos Georgiou')->assertStatus(409);

    expect(cobListed($business))->toBe([$uuid])
        ->and($business->json('data.count'))->toBe(1)
        ->and($business->json('data.bookings.0.items'))->toHaveCount(2)
        ->and($business->json('data.bookings.0.end'))->toBe('2026-11-03T09:30:00+00:00')
        ->and(cobListed($nikos))->toBe([$uuid]);

    // Elena's own service is over before her window starts, and Nikos's is not hers.
    cobAdd($this, ['date' => '2026-11-03', 'start_time' => '11:00', 'end_time' => '12:00'], 'Elena Papadaki')->assertRedirect();
});

it('counts a booking by its status, one awaiting its payment only while its hold is live, and a request while its hold is live or for good where a lapsed one approves itself', function (string $status, ?string $holdClass, string $expiry, bool $counted, string $onTimeout = 'expire') {
    LunaFixture::booking($status, $holdClass, $expiry, start: '2026-11-03 10:00', onApprovalTimeout: $onTimeout);

    $response = cobAdd($this, ['date' => '2026-11-03']);

    if ($counted) {
        $response->assertStatus(409)->assertJsonPath('data.bookings.0.status', $status);
    } else {
        $response->assertRedirect();
    }
})->with([
    'confirmed' => ['confirmed', null, 'none', true],
    'a live request' => ['awaiting_approval', 'approval', 'future', true],
    'a live wait for payment' => ['awaiting_payment', 'payment', 'future', true],
    'a lapsed request' => ['awaiting_approval', 'approval', 'past', false],
    'a lapsed request that approves itself' => ['awaiting_approval', 'approval', 'past', true, 'auto_approve'],
    'a lapsed wait for payment' => ['awaiting_payment', 'payment', 'past', false],
    'a lapsed wait for payment under the same policy' => ['awaiting_payment', 'payment', 'past', false, 'auto_approve'],
    'a checkout hold' => ['pending', 'checkout', 'future', false],
    'completed' => ['completed', null, 'none', false],
    'a no-show' => ['no_show', null, 'none', false],
    'cancelled' => ['cancelled', null, 'none', false],
    'rejected' => ['rejected', null, 'none', false],
    'expired' => ['expired', null, 'none', false],
]);

it('adds the closure and leaves the bookings where they are on keep and on move, telling nobody', function (string $choice) {
    $uuid = LunaFixture::booking('confirmed', start: '2026-11-03 10:00');
    $seen = cobSeen(cobAdd($this, ['date' => '2026-11-03'], 'Elena Papadaki')->assertStatus(409));

    cobAdd($this, ['date' => '2026-11-03', 'choice' => $choice, 'seen' => $seen], 'Elena Papadaki')->assertRedirect(tenantUrl('luna', '/admin/staff'));

    expect(session('flash'))->toBe(['kind' => 'success', 'message' => adminLine(ClosureOverBookings::ADDED)])
        ->and(cobClosures('2026-11-03'))->toBe(1)
        ->and(cobStatus($uuid))->toBe('confirmed')
        ->and(seamSent())->toBe([]);
})->with(['keep', 'move']);

it('adds the closure, cancels a booking and rejects a request inside it, and tells each customer by the closure email alone', function () {
    $booking = LunaFixture::booking('confirmed', start: '2026-11-03 10:00');
    $request = cobRequest();
    cobOtherCustomer($request);
    $seen = cobSeen(cobAdd($this, ['date' => '2026-11-03', 'reason' => 'Staff training'])->assertStatus(409));

    cobAdd($this, ['date' => '2026-11-03', 'reason' => 'Staff training', 'choice' => 'cancel', 'seen' => $seen])->assertRedirect(tenantUrl('luna', '/admin/staff'));

    $business = ['business' => 'Luna Hair Studio'];
    $cancelled = seamAt(LunaFixture::CUSTOMER_EMAIL);
    $rejected = seamAt(COB_OTHER_EMAIL);
    $cancelledText = (string) $cancelled->getTextBody();
    $rejectedText = (string) $rejected->getTextBody();
    $again = app(TenantUrls::class)->bookingLink(Slug::of('luna'), ['service' => LunaFixture::id('services', 'Haircut'), 'staff' => LunaFixture::id('staff', 'Elena Papadaki')], SiteLanguage::English);
    $askAgain = app(TenantUrls::class)->bookingLink(Slug::of('luna'), ['service' => LunaFixture::id('services', 'Bridal trial'), 'staff' => LunaFixture::id('staff', 'Elena Papadaki')], SiteLanguage::English);

    expect(session('flash'))->toBe(['kind' => 'success', 'message' => trans_choice(ClosureOverBookings::CANCELLED, 2)])
        ->and(trans_choice(ClosureOverBookings::CANCELLED, 2))->toBe('Closure added, and 2 bookings inside it cancelled.')
        ->and(cobClosures('2026-11-03'))->toBe(1)
        ->and(cobStatus($booking))->toBe('cancelled')
        ->and(cobStatus($request))->toBe('rejected')
        ->and(DB::table('tenant_luna.bookings')->where('uuid', $request)->value('rejection_reason'))->toBe('Staff training')
        ->and($cancelled->getSubject())->toBe(customerMailLine('customer-mail.closure.booking.subject', $business))
        ->and($cancelledText)->toContain(customerMailLine('customer-mail.closure.booking.cancelled', $business))
        ->and($cancelledText)->toContain('Haircut')
        ->and($cancelledText)->toContain(customerMailLine('customer-mail.closure.reason', ['reason' => 'Staff training']))
        ->and($cancelledText)->toContain(customerMailLine('customer-mail.closure.booking.after'))
        ->and($cancelledText)->toContain(customerMailLine('customer-mail.closure.book', ['link' => $again]))
        ->and($rejected->getSubject())->toBe(customerMailLine('customer-mail.closure.request.subject', $business))
        ->and($rejectedText)->toContain(customerMailLine('customer-mail.closure.request.cancelled', $business))
        ->and($rejectedText)->toContain('Bridal trial')
        ->and($rejectedText)->toContain(customerMailLine('customer-mail.closure.reason', ['reason' => 'Staff training']))
        ->and($rejectedText)->toContain(customerMailLine('customer-mail.closure.request.after'))
        // Each is offered the way back to what they had: the request's link opens its own service.
        ->and($askAgain)->not->toBe($again)
        ->and($rejectedText)->toContain(customerMailLine('customer-mail.closure.book', ['link' => $askAgain]))
        ->and($rejectedText)->not->toContain($again)
        ->and($cancelledText)->not->toContain($askAgain)
        // The business hears of the cancellation as it always has, and of nothing else.
        ->and(seamAt('owner@luna.example')->getSubject())->toBe(businessMailLine('business-mail.cancellation_notice.booking.subject', $business))
        ->and(seamAddresses())->toHaveCount(3);
});

it('leaves the reason line out of the closure email when no reason was typed', function () {
    $uuid = LunaFixture::booking('confirmed', start: '2026-11-03 10:00');
    $seen = cobSeen(cobAdd($this, ['date' => '2026-11-03'])->assertStatus(409));

    cobAdd($this, ['date' => '2026-11-03', 'choice' => 'cancel', 'seen' => $seen])->assertRedirect();

    $text = (string) seamAt(LunaFixture::CUSTOMER_EMAIL)->getTextBody();

    expect(cobStatus($uuid))->toBe('cancelled')
        ->and($text)->toContain(customerMailLine('customer-mail.closure.booking.cancelled', ['business' => 'Luna Hair Studio']))
        ->and($text)->not->toContain('The reason given');
});

it('writes the closure email in the customer\'s own language', function () {
    $uuid = LunaFixture::booking('confirmed', start: '2026-11-03 10:00');
    DB::table('tenant_luna.bookings')->where('uuid', $uuid)->update(['language' => 'el']);
    $seen = cobSeen(cobAdd($this, ['date' => '2026-11-03', 'reason' => 'Staff training'])->assertStatus(409));

    cobAdd($this, ['date' => '2026-11-03', 'reason' => 'Staff training', 'choice' => 'cancel', 'seen' => $seen])->assertRedirect();

    $message = seamAt(LunaFixture::CUSTOMER_EMAIL);
    $text = (string) $message->getTextBody();

    expect($message->getSubject())->toBe(customerMailLine('customer-mail.closure.booking.subject', ['business' => 'Luna Hair Studio'], SiteLanguage::Greek))
        ->and($text)->toContain(customerMailLine('customer-mail.closure.reason', ['reason' => 'Staff training'], SiteLanguage::Greek))
        ->and($text)->toContain(customerMailLine('customer-mail.closure.booking.after', [], SiteLanguage::Greek));
});

it('sends no closure email where the tenant has switched the cancellation off, as the usual one would not go either', function () {
    $uuid = LunaFixture::booking('confirmed', start: '2026-11-03 10:00');
    seamSwitchOff(['cancelled']);
    $seen = cobSeen(cobAdd($this, ['date' => '2026-11-03'])->assertStatus(409));

    cobAdd($this, ['date' => '2026-11-03', 'choice' => 'cancel', 'seen' => $seen])->assertRedirect();

    expect(cobStatus($uuid))->toBe('cancelled')
        ->and(seamAddresses())->not->toContain(LunaFixture::CUSTOMER_EMAIL);
});

it('writes nothing and answers the fresh list, changed, when a booking landed inside meanwhile', function () {
    $first = LunaFixture::booking('confirmed', start: '2026-11-03 10:00');
    $seen = cobSeen(cobAdd($this, ['date' => '2026-11-03'])->assertStatus(409));
    $second = LunaFixture::booking('confirmed', start: '2026-11-03 15:00');

    $response = cobAdd($this, ['date' => '2026-11-03', 'choice' => 'cancel', 'seen' => $seen])->assertStatus(409);

    expect($response->json('data.changed'))->toBeTrue()
        ->and($response->json('data.detail'))->toBe(adminLine(ClosureOverBookings::CHANGED))
        ->and($response->json('data.count'))->toBe(2)
        ->and(cobListed($response))->toBe([$first, $second])
        ->and($response->json('data.choices'))->toBe(['keep', 'move', 'cancel'])
        ->and(cobClosures('2026-11-03'))->toBe(0)
        ->and(cobStatus($first))->toBe('confirmed')
        ->and(seamSent())->toBe([]);
});

it('writes nothing and answers the fresh list, changed, when a booking on the list changed its status meanwhile', function () {
    $request = cobRequest();
    $seen = cobSeen(cobAdd($this, ['date' => '2026-11-03'])->assertStatus(409));
    app(BookingTransitionFactory::class)->approve(LunaFixture::tenant())->approve($request, Actor::user(cobOwner()->id));
    seamTransport()->flush();

    $response = cobAdd($this, ['date' => '2026-11-03', 'choice' => 'keep', 'seen' => $seen])->assertStatus(409);

    expect($response->json('data.changed'))->toBeTrue()
        ->and(cobSeen($response))->toBe([['uuid' => $request, 'status' => 'confirmed']])
        ->and(cobClosures('2026-11-03'))->toBe(0);
});

it('adds the closure over a list that emptied meanwhile, since nobody is left inside it to answer for', function () {
    $uuid = LunaFixture::booking('confirmed', start: '2026-11-03 10:00');
    $seen = cobSeen(cobAdd($this, ['date' => '2026-11-03'])->assertStatus(409));
    app(BookingTransitionFactory::class)->cancel(LunaFixture::tenant())->cancel($uuid, Actor::customer());
    seamTransport()->flush();

    cobAdd($this, ['date' => '2026-11-03', 'choice' => 'cancel', 'seen' => $seen])->assertRedirect();

    expect(session('flash'))->toBe(['kind' => 'success', 'message' => adminLine(ClosureOverBookings::ADDED)])
        ->and(cobClosures('2026-11-03'))->toBe(1)
        ->and(seamSent())->toBe([]);
});

it('answers an add that met a staff-day held past the lock timeout as busy, at 503 with a Retry-After, writes nothing and leaves the one log line a tenant write leaves', function () {
    $log = runnerLog();
    $probe = cobDayHeld('Nikos Georgiou', '2026-11-03');

    try {
        $busy = cobAdd($this, ['date' => '2026-11-03', 'reason' => 'Works']);
    } finally {
        $probe->rollBack();
    }

    $busy->assertStatus(503)->assertHeader('Retry-After', '1')->assertExactJson(cobBusy());
    $lines = $log->getRecords();

    expect(cobClosures('2026-11-03'))->toBe(0)
        ->and($lines)->toHaveCount(1)
        ->and($lines[0]->level->toPsrLogLevel())->toBe('notice')
        ->and($lines[0]->context)->toBe(['tenant' => 'luna', 'sqlstate' => '55P03', 'failure' => Busy::class, 'attempts' => 1]);

    // The day let go, the same add goes through.
    cobAdd($this, ['date' => '2026-11-03', 'reason' => 'Works'])->assertRedirect();

    expect(cobClosures('2026-11-03'))->toBe(1);
});

it('answers an add that lost to another transaction, or ran out of time, as busy too: nothing written, and the failure on the log under its own name', function (string $sqlstate, string $failure, string $level) {
    $log = runnerLog();

    Checkpoint::observe(function (string $name) use ($sqlstate): void {
        if ($name === LockedClosures::LOCKED) {
            throw sqlError($sqlstate);
        }
    });

    cobAdd($this, ['date' => '2026-11-03', 'reason' => 'Works'])
        ->assertStatus(503)->assertHeader('Retry-After', '1')->assertExactJson(cobBusy());
    $lines = $log->getRecords();

    expect(cobClosures('2026-11-03'))->toBe(0)
        ->and($lines)->toHaveCount(1)
        ->and($lines[0]->level->toPsrLogLevel())->toBe($level)
        ->and($lines[0]->context)->toBe(['tenant' => 'luna', 'sqlstate' => $sqlstate, 'failure' => $failure, 'attempts' => 1]);
})->with([
    'a deadlock' => ['40P01', RetriesExhausted::class, 'warning'],
    'a serialization failure' => ['40001', RetriesExhausted::class, 'warning'],
    'a statement cancelled past its time' => ['57014', BudgetExceeded::class, 'critical'],
]);

it('lets a failure of the database that is none of those reach the handler as it came', function () {
    Exceptions::fake();
    $log = runnerLog();

    Checkpoint::observe(function (string $name): void {
        if ($name === LockedClosures::LOCKED) {
            throw sqlError('42P01');
        }
    });

    cobAdd($this, ['date' => '2026-11-03', 'reason' => 'Works'])->assertStatus(500);

    expect(cobClosures('2026-11-03'))->toBe(0)
        ->and($log->getRecords())->toBe([]);

    Exceptions::assertReportedCount(1);
    Exceptions::assertReported(fn (QueryException $failure): bool => ($failure->errorInfo[0] ?? null) === '42P01');
});

it('writes no closure under an isolation level other than read committed', function () {
    DB::unprepared("set default_transaction_isolation to 'repeatable read'");

    try {
        $failure = cobPrecondition(LunaFixture::tenant());
    } finally {
        DB::unprepared('reset default_transaction_isolation');
    }

    expect($failure->precondition)->toBe('P4')
        ->and($failure->getMessage())->toContain('"repeatable read"')
        ->and(cobClosures('2026-11-03'))->toBe(0)
        ->and(DB::transactionLevel())->toBe(0);
});

it('writes no closure for a tenant whose schema is not there, where the path would land on the central one', function () {
    $failure = cobPrecondition(new Tenant(['slug' => 'ghost', 'name' => 'Ghost', 'timezone' => 'UTC', 'currency' => 'EUR']));

    expect($failure->precondition)->toBe('P5')
        ->and($failure->getMessage())->toContain('"tenant_ghost"')->toContain('"public"')
        ->and(DB::transactionLevel())->toBe(0)
        ->and(DB::scalar('select current_schema()'))->not->toBe('tenant_ghost');
});

it('skips a booking somebody changed between the closure\'s write and its own cancel, cancels the rest and names the one skipped', function () {
    $kept = LunaFixture::booking('confirmed', start: '2026-11-03 10:00');
    $gone = LunaFixture::booking('confirmed', start: '2026-11-03 15:00');
    cobOtherCustomer($gone);
    $seen = cobSeen(cobAdd($this, ['date' => '2026-11-03', 'reason' => 'Works'])->assertStatus(409));

    // The customer cancels the second booking themselves once the closure is written and before
    // its cancels start.
    Checkpoint::observe(function (string $name) use ($gone): void {
        if ($name === ClosureCancellations::BEGIN) {
            app(BookingTransitionFactory::class)->cancel(LunaFixture::tenant())->cancel($gone, Actor::customer());
        }
    });

    cobAdd($this, ['date' => '2026-11-03', 'reason' => 'Works', 'choice' => 'cancel', 'seen' => $seen])->assertRedirect();

    $tenant = LunaFixture::tenant();
    $clock = TenantClock::of($tenant, app(SettingsReader::class)->read($tenant));
    $when = TenantTime::instant(LunaFixture::at('2026-11-03 15:00'), $clock, SiteLanguage::English);
    $flash = session('flash');

    expect($flash)->toBe([
        'kind' => 'error',
        'message' => trans_choice(ClosureOverBookings::CANCELLED, 1).' '.trans_choice(ClosureOverBookings::SKIPPED, 1, ['bookings' => 'Ophelia Other, '.$when]),
    ])
        ->and($when)->toContain('15:00')
        ->and(trans_choice(ClosureOverBookings::SKIPPED, 1, ['bookings' => 'X']))->toBe('1 booking changed meanwhile and was left as it is: X.')
        ->and(cobClosures('2026-11-03'))->toBe(1)
        ->and(cobStatus($kept))->toBe('cancelled')
        ->and(cobStatus($gone))->toBe('cancelled')
        ->and(seamAt(LunaFixture::CUSTOMER_EMAIL)->getSubject())->toBe(customerMailLine('customer-mail.closure.booking.subject', ['business' => 'Luna Hair Studio']))
        // The second customer heard of their own cancellation, and nothing of the closure.
        ->and(seamAt(COB_OTHER_EMAIL)->getSubject())->not->toBe(customerMailLine('customer-mail.closure.booking.subject', ['business' => 'Luna Hair Studio']));
});

it('leaves a lapsed request that approves itself as it is on a cancel and names it, since no one can reject a hold that has lapsed', function () {
    $booking = LunaFixture::booking('confirmed', start: '2026-11-03 10:00');
    $request = LunaFixture::booking('awaiting_approval', 'approval', 'past', start: '2026-11-03 15:00', service: 'Bridal trial', requiresApproval: true, onApprovalTimeout: 'auto_approve');
    cobOtherCustomer($request);
    $listed = cobAdd($this, ['date' => '2026-11-03', 'reason' => 'Works'])->assertStatus(409);

    expect(cobListed($listed))->toBe([$booking, $request]);

    cobAdd($this, ['date' => '2026-11-03', 'reason' => 'Works', 'choice' => 'cancel', 'seen' => cobSeen($listed)])->assertRedirect();

    expect(session('flash'))->toBe(cobLeft(1, [cobNamed('Ophelia Other', '2026-11-03 15:00')]))
        ->and(cobClosures('2026-11-03'))->toBe(1)
        ->and(cobStatus($booking))->toBe('cancelled')
        ->and(cobStatus($request))->toBe('awaiting_approval')
        ->and(seamAddresses())->not->toContain(COB_OTHER_EMAIL);
});

it('leaves a booking moved before its turn where it now stands, names it and tells its customer nothing', function (?string $closed, string $to, ?string $onto, string $stands) {
    $kept = LunaFixture::booking('confirmed', start: '2026-11-03 10:00');
    $moved = LunaFixture::booking('confirmed', start: '2026-11-03 15:00');
    cobOtherCustomer($moved);
    $seen = cobSeen(cobAdd($this, ['date' => '2026-11-03', 'reason' => 'Works'], $closed)->assertStatus(409));

    // The business moves the second booking, telling nobody, once the closure is written and
    // before its cancels start. A move leaves the status as the list showed it.
    Checkpoint::observe(function (string $name) use ($moved, $to, $onto): void {
        if ($name === ClosureCancellations::BEGIN) {
            app(BookingTransitionFactory::class)->reschedule(LunaFixture::tenant())->reschedule(
                $moved,
                Actor::user(tenantUserId()),
                LunaFixture::at($to),
                new MoveChoices(staff: $onto === null ? [] : [LunaFixture::id('staff', $onto)], tellCustomer: false),
            );
        }
    });

    cobAdd($this, ['date' => '2026-11-03', 'reason' => 'Works', 'choice' => 'cancel', 'seen' => $seen], $closed)->assertRedirect();

    expect(session('flash'))->toBe(cobLeft(1, [cobNamed('Ophelia Other', '2026-11-03 15:00')]))
        ->and(cobClosures('2026-11-03'))->toBe(1)
        ->and(cobStatus($kept))->toBe('cancelled')
        ->and(cobStatus($moved))->toBe('confirmed')
        ->and(cobStands($moved))->toBe($stands)
        ->and(seamAddresses())->not->toContain(COB_OTHER_EMAIL);
})->with([
    'onto another day' => [null, '2026-11-04 15:00', null, 'Elena Papadaki 2026-11-04 15:00'],
    'onto another member at the same time' => ['Elena Papadaki', '2026-11-03 15:00', 'Nikos Georgiou', 'Nikos Georgiou 2026-11-03 15:00'],
]);

it('leaves a booking paid for before its turn confirmed, names it and tells its customer nothing of the closure', function () {
    $kept = LunaFixture::booking('confirmed', start: '2026-11-03 10:00');
    $paid = LunaFixture::booking('awaiting_payment', 'payment', 'future', start: '2026-11-03 15:00', paymentMode: 'online');
    cobOtherCustomer($paid);
    $seen = cobSeen(cobAdd($this, ['date' => '2026-11-03', 'reason' => 'Works'])->assertStatus(409));

    // The payment lands once the closure is written and before its cancels start. What its own
    // confirmation sent is cleared, so whatever is read afterwards the cancels sent.
    Checkpoint::observe(function (string $name) use ($paid): void {
        if ($name === ClosureCancellations::BEGIN) {
            app(BookingTransitionFactory::class)->paid(LunaFixture::tenant())->confirm($paid, 'pi_paid_meanwhile');
            seamTransport()->flush();
        }
    });

    cobAdd($this, ['date' => '2026-11-03', 'reason' => 'Works', 'choice' => 'cancel', 'seen' => $seen])->assertRedirect();

    expect(session('flash'))->toBe(cobLeft(1, [cobNamed('Ophelia Other', '2026-11-03 15:00')]))
        ->and(cobClosures('2026-11-03'))->toBe(1)
        ->and(cobStatus($kept))->toBe('cancelled')
        ->and(cobStatus($paid))->toBe('confirmed')
        ->and(seamAddresses())->not->toContain(COB_OTHER_EMAIL);
});

it('leaves a booking whose write broke in a way nobody foresaw as it is, reports the failure, cancels the rest and names the one left', function () {
    Exceptions::fake();
    $broken = LunaFixture::booking('confirmed', start: '2026-11-03 10:00');
    $done = LunaFixture::booking('confirmed', start: '2026-11-03 15:00');
    cobOtherCustomer($broken);
    $seen = cobSeen(cobAdd($this, ['date' => '2026-11-03', 'reason' => 'Works'])->assertStatus(409));
    $writes = 0;

    // The first booking's write breaks under its locks, as none of the failures a write names.
    Checkpoint::observe(function (string $name) use (&$writes): void {
        if ($name === GuardedWrite::AFTER_LOCK && ++$writes === 1) {
            throw new RuntimeException('The first write broke.');
        }
    });

    cobAdd($this, ['date' => '2026-11-03', 'reason' => 'Works', 'choice' => 'cancel', 'seen' => $seen])->assertRedirect(tenantUrl('luna', '/admin/staff'));

    expect(session('flash'))->toBe(cobLeft(1, [cobNamed('Ophelia Other', '2026-11-03 10:00')]))
        ->and($writes)->toBe(2)
        ->and(cobClosures('2026-11-03'))->toBe(1)
        ->and(cobStatus($broken))->toBe('confirmed')
        ->and(cobStatus($done))->toBe('cancelled')
        ->and(seamAddresses())->not->toContain(COB_OTHER_EMAIL);

    Exceptions::assertReportedCount(1);
    Exceptions::assertReported(fn (RuntimeException $failure): bool => $failure->getMessage() === 'The first write broke.');
});

it('leaves a booking whose row another write holds past the lock timeout as it is, cancels the rest and names the one left', function () {
    // Before the first post: the route keeps the controller that post builds, logger and all.
    $log = runnerLog();
    $held = LunaFixture::booking('confirmed', start: '2026-11-03 10:00');
    $done = LunaFixture::booking('confirmed', start: '2026-11-03 15:00');
    cobOtherCustomer($held);
    $seen = cobSeen(cobAdd($this, ['date' => '2026-11-03', 'reason' => 'Works'])->assertStatus(409));
    $probe = LunaFixture::probe('closure_probe');

    // Somebody else's write takes the first booking's row once the closure is written, and is
    // still at it when the cancels come by. Nothing about the booking changes.
    Checkpoint::observe(function (string $name) use ($probe, $held): void {
        if ($name === ClosureCancellations::BEGIN) {
            $probe->beginTransaction();
            $probe->select('select id from tenant_luna.bookings where uuid = ?::uuid for update', [$held]);
        }
    });

    try {
        $response = cobAdd($this, ['date' => '2026-11-03', 'reason' => 'Works', 'choice' => 'cancel', 'seen' => $seen]);
    } finally {
        $probe->rollBack();
    }

    $response->assertRedirect(tenantUrl('luna', '/admin/staff'));
    $lines = $log->getRecords();

    expect(session('flash'))->toBe(cobLeft(1, [cobNamed('Ophelia Other', '2026-11-03 10:00')]))
        ->and(cobClosures('2026-11-03'))->toBe(1)
        ->and(cobStatus($held))->toBe('confirmed')
        ->and(cobStatus($done))->toBe('cancelled')
        ->and(seamAddresses())->not->toContain(COB_OTHER_EMAIL)
        // Contention and not a refusal: the cancel's own transaction wrote the busy line.
        ->and($lines)->toHaveCount(1)
        ->and($lines[0]->context['failure'] ?? null)->toBe(Busy::class)
        ->and($lines[0]->context['sqlstate'] ?? null)->toBe('55P03');
});

it('refuses a choice outside keep, move and cancel, a choice with no list, and a list naming a status that never counts', function (array $body, string $field) {
    /** @var array<string, mixed> $body */
    LunaFixture::booking('confirmed', start: '2026-11-03 10:00');

    cobAdd($this, ['date' => '2026-11-03', ...$body])->assertStatus(400)->assertJsonPath('data.field', $field);

    expect(cobClosures('2026-11-03'))->toBe(0);
})->with([
    'a choice of its own' => [['choice' => 'delete', 'seen' => []], 'choice'],
    'a choice with no list' => [['choice' => 'keep'], 'seen'],
    'a list that is no list' => [['choice' => 'keep', 'seen' => 'all'], 'seen'],
    'a booking with no id' => [['choice' => 'keep', 'seen' => [['status' => 'confirmed']]], 'seen.0.uuid'],
    'a status that never counts' => [['choice' => 'keep', 'seen' => [['uuid' => '6f1c1a8e-31f4-4b8c-9a55-0c4b8f6d2a10', 'status' => 'pending']]], 'seen.0.status'],
]);

it('reads a null or an empty choice as no choice, and without a choice asks for no list and reads none it is sent', function (array $body) {
    /** @var array<string, mixed> $body */
    cobAdd($this, ['date' => '2026-11-03', ...$body])->assertRedirect(tenantUrl('luna', '/admin/staff'));

    expect(session('flash'))->toBe(['kind' => 'success', 'message' => adminLine(ClosureOverBookings::ADDED)])
        ->and(cobClosures('2026-11-03'))->toBe(1);
})->with([
    'a null choice' => [['choice' => null]],
    'an empty choice' => [['choice' => '']],
    'a null choice beside a list' => [['choice' => null, 'seen' => []]],
    'a list that is no list' => [['seen' => 'all']],
    'a null choice beside a booking with no id' => [['choice' => null, 'seen' => [['status' => 'pending']]]],
]);

it('lists the bookings as never seen when a list comes with no choice, however the list reads', function () {
    $uuid = LunaFixture::booking('confirmed', start: '2026-11-03 10:00');

    $response = cobAdd($this, ['date' => '2026-11-03', 'choice' => null, 'seen' => [['uuid' => 'not-one', 'status' => 'confirmed']]])->assertStatus(409);

    expect($response->json('data.changed'))->toBeFalse()
        ->and(cobListed($response))->toBe([$uuid])
        ->and(cobClosures('2026-11-03'))->toBe(0);
});

it('matches a booking the list names in upper case', function () {
    $uuid = LunaFixture::booking('confirmed', start: '2026-11-03 10:00');

    cobAdd($this, ['date' => '2026-11-03', 'choice' => 'keep', 'seen' => [['uuid' => strtoupper($uuid), 'status' => 'confirmed']]])->assertRedirect(tenantUrl('luna', '/admin/staff'));

    expect(cobClosures('2026-11-03'))->toBe(1)
        ->and(cobStatus($uuid))->toBe('confirmed');
});

it('reads neither a choice nor a list on a removal', function () {
    cobAdd($this, ['date' => '2026-11-03'])->assertRedirect();

    $this->actingAs(cobOwner())
        ->from(tenantUrl('luna', '/admin/staff'))
        ->json('DELETE', tenantUrl('luna', '/admin/blackouts'), ['date' => '2026-11-03', 'choice' => 'keep', 'seen' => 'all'], ['X-Inertia' => 'true'])
        ->assertRedirect(tenantUrl('luna', '/admin/staff'));

    expect(cobClosures('2026-11-03'))->toBe(0);
});

it('spells the refusals of a choice and of a list in both languages', function () {
    expect(adminLine(BlackoutRequest::CHOICE))->toBe('The choice is keep, move or cancel, or none at all.')
        ->and(adminLine(BlackoutRequest::SEEN, [], SiteLanguage::Greek))->not->toBe(adminLine(BlackoutRequest::SEEN));
});

it('turns away a member of staff at the door of both add routes, since adding a closure is the business\'s settings', function () {
    $staff = User::query()->findOrFail(addTenantUser('elena@luna.example', 'staff', 'Elena Papadaki'));
    LunaFixture::booking('confirmed', start: '2026-11-03 10:00');

    cobAdd($this, ['date' => '2026-11-03'], null, $staff)->assertForbidden();
    cobAdd($this, ['date' => '2026-11-03', 'choice' => 'cancel', 'seen' => []], 'Elena Papadaki', $staff)->assertForbidden();

    expect(cobClosures('2026-11-03'))->toBe(0);
});

it('offers cancel only to a principal who could cancel or reject every booking on the list on its own', function () {
    $tenant = LunaFixture::tenant();
    $confirmed = ['uuid' => LunaFixture::booking('confirmed', start: '2026-11-03 10:00'), 'status' => 'confirmed'];
    $request = ['uuid' => cobRequest(), 'status' => 'awaiting_approval'];
    $elena = Principal::user(User::query()->findOrFail(addTenantUser('elena@luna.example', 'staff', 'Elena Papadaki')));
    $nikos = Principal::user(User::query()->findOrFail(addTenantUser('nikos@luna.example', 'staff', 'Nikos Georgiou')));
    $manager = Principal::user(User::query()->findOrFail(addTenantUser('manager@luna.example', 'manager')));
    $choices = app(ClosureChoices::class);

    expect($choices->offered($tenant, Principal::user(cobOwner()), [$confirmed, $request]))->toBe([ClosureChoice::Keep, ClosureChoice::Move, ClosureChoice::Cancel])
        ->and($choices->offered($tenant, $manager, [$confirmed, $request]))->toBe([ClosureChoice::Keep, ClosureChoice::Move, ClosureChoice::Cancel])
        // Staff decide the requests on their own row, and cancel and move nothing.
        ->and($choices->offered($tenant, $elena, [$request]))->toBe([ClosureChoice::Keep, ClosureChoice::Cancel])
        ->and($choices->offered($tenant, $elena, [$confirmed, $request]))->toBe([ClosureChoice::Keep])
        ->and($choices->offered($tenant, $nikos, [$request]))->toBe([ClosureChoice::Keep]);
});

it('answers a principal who does not see bookings the list as ids and statuses alone, with the choices that principal is offered', function () {
    $tenant = LunaFixture::tenant();
    $confirmed = LunaFixture::booking('confirmed', start: '2026-11-03 10:00');
    $request = cobRequest();
    $elena = cobStaff('elena@luna.example', 'Elena Papadaki');

    $refusal = app(ClosureOverBookings::class)->add($tenant, $elena, cobBody(['date' => '2026-11-03', 'reason' => 'Works']), null);

    expect($refusal)->toBeInstanceOf(JsonResponse::class)
        ->and($refusal instanceof JsonResponse ? $refusal->getStatusCode() : null)->toBe(409)
        ->and($refusal instanceof JsonResponse ? $refusal->getData(true) : null)->toBe([
            'code' => 'bookings_inside',
            'message' => 'bookings_inside',
            'data' => [
                'status' => 409,
                'detail' => trans_choice(ClosureOverBookings::INSIDE, 2),
                'count' => 2,
                'changed' => false,
                // No customer, no time and no service: what the next post names back, and no more.
                'bookings' => [['uuid' => $confirmed, 'status' => 'confirmed'], ['uuid' => $request, 'status' => 'awaiting_approval']],
                'choices' => ['keep'],
            ],
        ])
        ->and(cobClosures('2026-11-03'))->toBe(0);
});

it('refuses a choice the principal is not offered for the bookings shown with a 403, before anything is written, and lets through the one it is', function (string $choice) {
    $tenant = LunaFixture::tenant();
    $confirmed = LunaFixture::booking('confirmed', start: '2026-11-03 10:00');
    $request = cobRequest();
    $elena = cobStaff('elena@luna.example', 'Elena Papadaki');
    $seen = [['uuid' => $confirmed, 'status' => 'confirmed'], ['uuid' => $request, 'status' => 'awaiting_approval']];
    $closures = app(ClosureOverBookings::class);

    expect(fn () => $closures->add($tenant, $elena, cobBody(['date' => '2026-11-03', 'reason' => 'Works', 'choice' => $choice, 'seen' => $seen]), null))
        ->toThrow(AccessDeniedHttpException::class);

    expect(cobClosures('2026-11-03'))->toBe(0)
        ->and(cobStatus($confirmed))->toBe('confirmed')
        ->and(cobStatus($request))->toBe('awaiting_approval')
        ->and(seamAddresses())->toBe([]);

    expect($closures->add($tenant, $elena, cobBody(['date' => '2026-11-03', 'reason' => 'Works', 'choice' => 'keep', 'seen' => $seen]), null))
        ->toBe(['kind' => 'success', 'message' => adminLine(ClosureOverBookings::ADDED)])
        ->and(cobClosures('2026-11-03'))->toBe(1)
        ->and(cobStatus($confirmed))->toBe('confirmed')
        ->and(cobStatus($request))->toBe('awaiting_approval');
})->with(['cancel', 'move']);
