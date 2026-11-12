<?php

use App\Application\ChainUnavailable;
use App\Application\Clock;
use App\Domain\Audit\Actor;
use App\Http\AdminSession;
use App\Http\Controllers\Admin\BookingActionController;
use App\Infrastructure\BookingTransitionFactory;
use App\Infrastructure\Cache\StaffDayMaskCache;
use App\Infrastructure\Db\SqlText;
use App\Models\User;
use App\Site\SiteLanguage;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FixedClock;
use Tests\Support\LunaFixture;
use Tests\TestCase;

/*
 * The admin's endpoints for moving a booking: POST /admin/bookings/{uuid}/move beside cancel,
 * and GET /admin/api/bookings/{uuid}/move, the read the Move form and the calendar's drag ask
 * first, over luna's real rows.
 *
 * The writes enter the transaction runner, so the file truncates rather than wrapping each test
 * in a transaction, and seeds the demo afresh. The mask cache is the null store.
 *
 * luna is in Europe/Athens, UTC+2 in November, on a five-minute grid. 2026-11-03 is a Tuesday;
 * Elena works 09:00 to 13:00 and 14:00 to 18:00, Nikos 10:00 to 19:00. Haircut is 45 minutes and
 * both perform it, Colour an hour and both perform it, Bridal trial Elena's alone. The clock
 * stands on the Monday before at 08:00 Athens. Where a start must be one the planner refuses, it
 * is one already passed.
 */

pest()->use(DatabaseTruncation::class);

beforeEach(function () {
    $this->withoutVite();
    dropDemoSchemas();
    seedDemo();
    app()->instance(StaffDayMaskCache::class, coldMaskCache());
    app()->instance(Clock::class, FixedClock::at('2026-11-02 06:00:00'));
    settingsOf('luna')->update(['reminder_lead_hours' => 0]);
});

afterEach(function () {
    dropDemoSchemas();
    DB::table('support_sessions')->delete();
    DB::table('users')->delete();
    DB::table('tenants')->delete();
});

function adminMoveOwner(): User
{
    return User::query()->where('email', 'owner@luna.example')->sole();
}

/**
 * A booking taken the way the booking page takes one and confirmed, with whatever it sent cleared.
 *
 * @param  non-empty-list<string>  $services
 */
function adminMoveConfirmed(array $services = ['Haircut'], string $start = '2026-11-03 10:00', string $staff = 'Elena Papadaki'): string
{
    $uuid = LunaFixture::hold($services, $start, $staff);
    app(BookingTransitionFactory::class)->confirm(LunaFixture::tenant())->confirm($uuid, Actor::customer());
    seamTransport()->flush();

    return $uuid;
}

/** An Athens wall clock as the wire spells an instant. */
function adminMoveAt(string $athens): string
{
    return LunaFixture::at($athens)->setTimezone(new DateTimeZone('UTC'))->format(DateTimeInterface::RFC3339);
}

/**
 * @param  array<string, mixed>  $body
 * @return TestResponse<Response>
 */
function adminMovePress(TestCase $test, ?User $user, string $uuid, array $body): TestResponse
{
    $dialog = tenantUrl('luna', '/admin/bookings/'.$uuid);
    $request = $user === null ? $test : $test->actingAs($user);

    return $request->from($dialog)->post(tenantUrl('luna', '/admin/bookings/'.$uuid.'/move'), $body);
}

/**
 * @param  array<string, mixed>  $query
 * @return TestResponse<Response>
 */
function adminMoveRead(TestCase $test, ?User $user, string $uuid, array $query): TestResponse
{
    $request = $user === null ? $test : $test->actingAs($user);

    return $request->getJson(tenantUrl('luna', '/admin/api/bookings/'.$uuid.'/move?'.http_build_query($query)));
}

/**
 * The booking's items in chain order, each as the member, the service and the Athens range.
 *
 * @return list<string>
 */
function adminMoveItems(string $uuid): array
{
    $athens = new DateTimeZone('Europe/Athens');
    $utc = new DateTimeZone('UTC');
    $items = [];

    foreach (DB::select(
        'select m.name as staff, s.name as service, i.start_utc::text as start_utc, i.end_utc::text as end_utc'
        .' from tenant_luna.bookings b join tenant_luna.booking_items i on i.booking_id = b.id'
        .' join tenant_luna.staff m on m.id = i.staff_id join tenant_luna.services s on s.id = i.service_id'
        .' where b.uuid = ?::uuid order by i.sort',
        [$uuid],
    ) as $row) {
        assert($row instanceof stdClass && is_string($row->staff) && is_string($row->service) && is_string($row->start_utc) && is_string($row->end_utc));
        $wall = fn (string $text): string => (new DateTimeImmutable($text, $utc))->setTimezone($athens)->format('H:i');
        $items[] = sprintf('%s %s %s-%s', $row->staff, $row->service, $wall($row->start_utc), $wall($row->end_utc));
    }

    return $items;
}

/**
 * The payload of the one rescheduled row of the trail, or null where nothing moved.
 *
 * @return array<mixed>|null
 */
function adminMoveFacts(): ?array
{
    $moves = array_values(array_filter(LunaFixture::trail(), fn (array $row): bool => $row['action'] === 'rescheduled'));
    $payload = $moves[0]['payload'] ?? null;

    return is_array($payload) ? $payload : null;
}

/** A closure of luna on a date: the business's where no member is named. */
function adminMoveClosure(?string $staff, string $date, ?string $start = null, ?string $end = null): void
{
    DB::table('tenant_luna.blackouts')->insert([
        'staff_id' => $staff === null ? null : LunaFixture::id('staff', $staff),
        'date' => $date,
        'start_time' => $start,
        'end_time' => $end,
        'reason' => 'Closed',
    ]);
}

/**
 * The Athens wall clocks of the day's candidates, each with its crossings as kind letters.
 *
 * @param  TestResponse<Response>  $response
 * @return array<string, list<string>>
 */
function adminMoveCandidates(TestResponse $response): array
{
    $starts = $response->assertOk()->json('starts');
    assert(is_array($starts));
    $athens = new DateTimeZone('Europe/Athens');
    $out = [];

    foreach ($starts as $candidate) {
        assert(is_array($candidate) && is_string($candidate['start']) && is_array($candidate['crossings']));
        $out[(new DateTimeImmutable($candidate['start'], new DateTimeZone('UTC')))->setTimezone($athens)->format('H:i')] = array_values(array_map(fn (mixed $crossing): string => is_array($crossing) && is_string($crossing['kind']) ? $crossing['kind'] : '?', $candidate['crossings']));
    }

    return $out;
}

it('moves a booking to a new time, answering as cancel answers: back to the page that asked with a success flash in words', function () {
    $uuid = adminMoveConfirmed();

    adminMovePress($this, adminMoveOwner(), $uuid, ['start' => adminMoveAt('2026-11-03 11:00')])
        ->assertRedirect(tenantUrl('luna', '/admin/bookings/'.$uuid))
        ->assertSessionHas('flash', ['kind' => 'success', 'message' => __(BookingActionController::MOVED)]);

    expect(adminMoveItems($uuid))->toBe(['Elena Papadaki Haircut 11:00-11:45'])
        ->and(LunaFixture::row($uuid)['status'])->toBe('confirmed')
        ->and(adminMoveFacts())->toMatchArray(['customer_told' => true]);
});

it('words the moved flash in the user\'s language, a user at el reading it in Greek through the translator', function () {
    DB::table('users')->where('email', 'owner@luna.example')->update(['locale' => SiteLanguage::Greek->value]);
    $uuid = adminMoveConfirmed();

    adminMovePress($this, adminMoveOwner(), $uuid, ['start' => adminMoveAt('2026-11-03 11:00')])
        ->assertSessionHas('flash', ['kind' => 'success', 'message' => adminLine(BookingActionController::MOVED, [], SiteLanguage::Greek)]);

    expect(adminLine(BookingActionController::MOVED, [], SiteLanguage::Greek))->not->toBe(adminLine(BookingActionController::MOVED));
});

it('gives a segment to another member by its position at the same time, and moves the time and a member at once', function () {
    $single = adminMoveConfirmed();
    $chain = adminMoveConfirmed(['Haircut', 'Colour'], '2026-11-04 10:00');
    $nikos = LunaFixture::id('staff', 'Nikos Georgiou');

    adminMovePress($this, adminMoveOwner(), $single, ['start' => adminMoveAt('2026-11-03 10:00'), 'staff' => [0 => $nikos]])->assertRedirect();
    adminMovePress($this, adminMoveOwner(), $chain, ['start' => adminMoveAt('2026-11-04 11:00'), 'staff' => ['1' => $nikos]])->assertRedirect();

    expect(adminMoveItems($single))->toBe(['Nikos Georgiou Haircut 10:00-10:45'])
        ->and(adminMoveItems($chain))->toBe(['Elena Papadaki Haircut 11:00-11:45', 'Nikos Georgiou Colour 11:45-12:45']);
});

it('passes the choice to tell the customer to the engine: told by default, nothing sent when the tick is off', function () {
    $told = adminMoveConfirmed();
    $untold = adminMoveConfirmed(start: '2026-11-03 15:00');

    adminMovePress($this, adminMoveOwner(), $told, ['start' => adminMoveAt('2026-11-03 11:00')])->assertRedirect();
    $addressedFirst = seamAddresses();
    seamTransport()->flush();
    adminMovePress($this, adminMoveOwner(), $untold, ['start' => adminMoveAt('2026-11-03 16:00'), 'tell_customer' => false])->assertRedirect();

    expect($addressedFirst)->toContain(LunaFixture::CUSTOMER_EMAIL)
        ->and(seamAddresses())->toBe(['owner@luna.example']);
});

it('refuses a move over the member\'s hours naming the segment, the member and hours, and lands it once the warning is accepted', function () {
    $uuid = adminMoveConfirmed();
    $elena = LunaFixture::id('staff', 'Elena Papadaki');

    adminMovePress($this, adminMoveOwner(), $uuid, ['start' => adminMoveAt('2026-11-03 13:00')])
        ->assertStatus(409)
        ->assertJsonPath('code', 'unaccepted_warning')
        ->assertJsonPath('data.detail', apiSentence('api.envelope.unaccepted_warning'))
        ->assertJsonPath('data.crossings', [['segment' => 0, 'staff_id' => $elena, 'kind' => 'hours']])
        ->assertSessionMissing('flash');

    expect(adminMoveItems($uuid))->toBe(['Elena Papadaki Haircut 10:00-10:45']);

    adminMovePress($this, adminMoveOwner(), $uuid, ['start' => adminMoveAt('2026-11-03 13:00'), 'warning_accepted' => true])->assertRedirect();

    expect(adminMoveItems($uuid))->toBe(['Elena Papadaki Haircut 13:00-13:45']);
});

it('answers each refusal with its code, its status and what it names, and moves nothing', function () {
    $uuid = adminMoveConfirmed();
    LunaFixture::booking('confirmed', start: '2026-11-03 15:00');
    $nikos = LunaFixture::id('staff', 'Nikos Georgiou');
    $bridal = LunaFixture::booking('confirmed', start: '2026-11-04 10:00', service: 'Bridal trial');
    $cancelled = LunaFixture::booking('cancelled', blocks: false, start: '2026-11-03 16:30');
    $lapsed = LunaFixture::booking('awaiting_approval', 'approval', 'past', start: '2026-11-03 17:00', service: 'Bridal trial', requiresApproval: true);
    $owner = adminMoveOwner();

    adminMovePress($this, $owner, (string) Str::uuid(), ['start' => adminMoveAt('2026-11-03 11:00')])->assertStatus(404)->assertJsonPath('code', 'not_found');
    adminMovePress($this, $owner, $uuid, ['start' => adminMoveAt('2026-11-02 07:00')])
        ->assertStatus(409)->assertJsonPath('code', 'unavailable')->assertJsonPath('data.rule', ChainUnavailable::PAST)->assertJsonPath('data.segment', 0)
        ->assertJsonPath('data.detail', apiSentence('api.move.past'));
    adminMovePress($this, $owner, $uuid, ['start' => adminMoveAt('2026-11-03 11:02')])->assertStatus(409)->assertJsonPath('data.rule', ChainUnavailable::OFF_GRID);
    adminMovePress($this, $owner, $bridal, ['start' => adminMoveAt('2026-11-04 10:00'), 'staff' => [0 => $nikos]])
        ->assertStatus(400)->assertJsonPath('code', 'staff_not_eligible')->assertJsonPath('data.segment', 0)->assertJsonPath('data.staff_id', $nikos);
    adminMovePress($this, $owner, $uuid, ['start' => adminMoveAt('2026-11-03 15:00'), 'warning_accepted' => true])->assertStatus(409)->assertJsonPath('code', 'overlap')->assertJsonPath('data.segment', 0);
    adminMovePress($this, $owner, $cancelled, ['start' => adminMoveAt('2026-11-03 11:00')])->assertStatus(409)->assertJsonPath('code', 'not_reschedulable');
    adminMovePress($this, $owner, $lapsed, ['start' => adminMoveAt('2026-11-04 15:00')])->assertStatus(409)->assertJsonPath('code', 'hold_lapsed');
    adminMovePress($this, $owner, $uuid, ['start' => adminMoveAt('2026-11-03 11:00'), 'staff' => [3 => $nikos]])->assertStatus(400)->assertJsonPath('code', 'invalid_request')
        ->assertJsonPath('data.detail', apiSentence('api.move.no_such_segment'));
    adminMovePress($this, $owner, $uuid, ['start' => '11:00'])->assertStatus(400)->assertJsonPath('code', 'invalid_request');
    adminMovePress($this, $owner, $uuid, ['start' => adminMoveAt('2026-11-03 11:00'), 'staff' => ['x' => $nikos]])->assertStatus(400)->assertJsonPath('data.detail', apiSentence('api.validation.move.staff'));

    expect(adminMoveItems($uuid))->toBe(['Elena Papadaki Haircut 10:00-10:45'])
        ->and(adminMoveFacts())->toBeNull();
});

it('never lets the business move a pending checkout hold, while its customer still moves it', function () {
    $pending = LunaFixture::hold(['Haircut'], '2026-11-03 10:00');

    adminMovePress($this, adminMoveOwner(), $pending, ['start' => adminMoveAt('2026-11-03 11:00')])->assertStatus(409)->assertJsonPath('code', 'not_reschedulable');
    expect(adminMoveItems($pending))->toBe(['Elena Papadaki Haircut 10:00-10:45']);

    app(BookingTransitionFactory::class)->reschedule(LunaFixture::tenant())->reschedule($pending, Actor::customer(), LunaFixture::at('2026-11-03 11:00'));
    expect(adminMoveItems($pending))->toBe(['Elena Papadaki Haircut 11:00-11:45']);
});

it('admits whoever cancel admits: a guest is sent as cancel sends one, a staff viewer is refused on their own booking, another\'s and a member change, a manager moves', function () {
    $elena = User::query()->findOrFail(addTenantUser('elena@luna.example', 'staff', staff: 'Elena Papadaki'));
    $manager = User::query()->findOrFail(addTenantUser('marina@luna.example', 'manager'));
    $hers = adminMoveConfirmed();
    $his = adminMoveConfirmed(start: '2026-11-03 15:00', staff: 'Nikos Georgiou');
    $body = ['start' => adminMoveAt('2026-11-03 11:00')];

    $guestCancel = $this->from(tenantUrl('luna', '/admin/bookings/'.$hers))->post(tenantUrl('luna', '/admin/bookings/'.$hers.'/cancel'));
    $guestMove = adminMovePress($this, null, $hers, $body);
    expect($guestMove->getStatusCode())->toBe($guestCancel->getStatusCode())
        ->and($guestMove->headers->get('Location'))->toBe($guestCancel->headers->get('Location'));

    adminMovePress($this, $elena, $hers, $body)->assertForbidden();
    adminMovePress($this, $elena, $his, $body)->assertForbidden();
    adminMovePress($this, $elena, $hers, [...$body, 'staff' => [0 => LunaFixture::id('staff', 'Nikos Georgiou')]])->assertForbidden();
    adminMoveRead($this, $elena, $hers, ['date' => '2026-11-03'])->assertForbidden();
    $this->actingAs($elena)->from(tenantUrl('luna', '/admin/calendar'))->post(tenantUrl('luna', '/admin/bookings/'.$hers.'/cancel'))->assertForbidden();

    expect(adminMoveItems($hers))->toBe(['Elena Papadaki Haircut 10:00-10:45']);

    adminMovePress($this, $manager, $hers, $body)->assertRedirect();
    expect(adminMoveItems($hers))->toBe(['Elena Papadaki Haircut 11:00-11:45']);
});

it('lets support move in a session, the trail naming the operator, and a paused tenant move, since the booking lifecycle is never gated', function () {
    $uuid = adminMoveConfirmed();
    DB::table('tenants')->where('slug', 'luna')->update(['subscription_state' => 'canceled']);

    adminMovePress($this, adminMoveOwner(), $uuid, ['start' => adminMoveAt('2026-11-03 11:00')])->assertRedirect()->assertSessionHas('flash.kind', 'success');
    expect(adminMoveItems($uuid))->toBe(['Elena Papadaki Haircut 11:00-11:45']);

    auth()->logout();
    $now = app(Clock::class)->now();
    $operator = addOperator();
    $session = DB::table('support_sessions')->insertGetId([
        'operator_id' => $operator->id,
        'tenant_id' => LunaFixture::tenant()->id,
        'opened_at' => SqlText::instant($now),
        'expires_at' => SqlText::instant($now->add(new DateInterval('PT60M'))),
        'created_at' => SqlText::instant($now),
    ]);

    $this->withSession([AdminSession::SUPPORT_KEY => $session])
        ->from(tenantUrl('luna', '/admin/bookings/'.$uuid))
        ->post(tenantUrl('luna', '/admin/bookings/'.$uuid.'/move'), ['start' => adminMoveAt('2026-11-03 12:00')])
        ->assertRedirect(tenantUrl('luna', '/admin/bookings/'.$uuid))
        ->assertSessionHas('flash.kind', 'success');

    $actors = array_column(array_values(array_filter(LunaFixture::trail(), fn (array $row): bool => $row['action'] === 'rescheduled')), 'actor');
    expect(adminMoveItems($uuid))->toBe(['Elena Papadaki Haircut 12:00-12:45'])
        ->and($actors)->toBe(['user:'.adminMoveOwner()->id, 'support:'.$operator->id]);
});

it('lets the business read and move a booking inside the lead time and past the horizon, which bind customers alone, while the customer\'s own read hides those starts and their reschedule refuses them', function () {
    // 08:00 in Athens on the Monday: five hours of lead time reach 13:00, and three days of
    // horizon end on the Thursday. Elena works the Monday 09:00 to 13:00 and 14:00 to 18:00.
    $held = $this->postJson(tenantUrl('luna', '/api/v1/holds'), [
        'customer_name' => LunaFixture::CUSTOMER_NAME,
        'customer_email' => LunaFixture::CUSTOMER_EMAIL,
        'customer_phone' => LunaFixture::CUSTOMER_PHONE,
        'items' => [['service_id' => LunaFixture::id('services', 'Haircut'), 'staff_id' => LunaFixture::id('staff', 'Elena Papadaki')]],
        'start' => adminMoveAt('2026-11-03 10:00'),
    ])->assertCreated()->json();
    assert(is_array($held) && is_string($held['uuid']) && is_string($held['manage_token']));
    ['uuid' => $uuid, 'manage_token' => $token] = $held;
    $this->postJson(tenantUrl('luna', '/api/v1/bookings/'.$uuid.'/confirm'), ['token' => $token])->assertOk();
    DB::table('tenant_luna.services')->where('name', 'Haircut')->update(['lead_time_min' => 300, 'horizon_days' => 3]);
    $owner = adminMoveOwner();

    $today = adminMoveCandidates(adminMoveRead($this, $owner, $uuid, ['date' => '2026-11-02']));
    $later = adminMoveCandidates(adminMoveRead($this, $owner, $uuid, ['date' => '2026-11-10']));
    $verdict = adminMoveRead($this, $owner, $uuid, ['start' => adminMoveAt('2026-11-02 10:00')])->assertOk()->json('verdict');
    auth()->logout();
    $customer = $this->getJson(tenantUrl('luna', '/api/v1/bookings/'.$uuid.'/availability?'.http_build_query(['token' => $token, 'from' => '2026-11-02', 'to' => '2026-11-11'])))->assertOk();

    expect($today['10:00'])->toBe([])
        ->and($today)->not->toHaveKey('07:55')
        ->and($later['10:00'])->toBe([])
        ->and($verdict)->toBe(['placeable' => true, 'crossings' => []])
        ->and($customer->json('2026-11-02'))->not->toContain(adminMoveAt('2026-11-02 10:00'))
        ->and($customer->json('2026-11-02.0'))->toBe(adminMoveAt('2026-11-02 14:00'))
        ->and($customer->json('2026-11-10'))->toBe([]);

    $this->postJson(tenantUrl('luna', '/api/v1/bookings/'.$uuid.'/reschedule'), ['token' => $token, 'start' => adminMoveAt('2026-11-02 10:00')])->assertStatus(409)->assertJsonPath('code', 'unavailable');
    $this->postJson(tenantUrl('luna', '/api/v1/bookings/'.$uuid.'/reschedule'), ['token' => $token, 'start' => adminMoveAt('2026-11-10 10:00')])->assertStatus(409)->assertJsonPath('code', 'unavailable');
    expect(adminMoveItems($uuid))->toBe(['Elena Papadaki Haircut 10:00-10:45'])
        ->and(adminMoveFacts())->toBeNull();

    adminMovePress($this, $owner, $uuid, ['start' => adminMoveAt('2026-11-02 10:00')])->assertRedirect()->assertSessionHas('flash.kind', 'success');
    expect(adminMoveItems($uuid))->toBe(['Elena Papadaki Haircut 10:00-10:45']);
    expect(DB::selectOne("select to_char(min(start_utc) at time zone 'UTC', 'YYYY-MM-DD HH24:MI') as s from tenant_luna.booking_items"))->toEqual((object) ['s' => '2026-11-02 08:00']);

    adminMovePress($this, $owner, $uuid, ['start' => adminMoveAt('2026-11-10 10:00')])->assertRedirect()->assertSessionHas('flash.kind', 'success');
    expect(DB::selectOne("select to_char(min(start_utc) at time zone 'UTC', 'YYYY-MM-DD HH24:MI') as s from tenant_luna.booking_items"))->toEqual((object) ['s' => '2026-11-10 08:00']);
});

it('lets support read and move a booking inside the lead time and past the horizon as the signed-in user does', function () {
    $uuid = adminMoveConfirmed();
    DB::table('tenant_luna.services')->where('name', 'Haircut')->update(['lead_time_min' => 300, 'horizon_days' => 3]);
    $now = app(Clock::class)->now();
    $session = DB::table('support_sessions')->insertGetId([
        'operator_id' => addOperator()->id,
        'tenant_id' => LunaFixture::tenant()->id,
        'opened_at' => SqlText::instant($now),
        'expires_at' => SqlText::instant($now->add(new DateInterval('PT60M'))),
        'created_at' => SqlText::instant($now),
    ]);
    $this->withSession([AdminSession::SUPPORT_KEY => $session]);

    $today = adminMoveCandidates(adminMoveRead($this, null, $uuid, ['date' => '2026-11-02']));
    $this->from(tenantUrl('luna', '/admin/bookings/'.$uuid))->post(tenantUrl('luna', '/admin/bookings/'.$uuid.'/move'), ['start' => adminMoveAt('2026-11-02 11:00')])->assertSessionHas('flash.kind', 'success');
    $atLead = DB::selectOne("select to_char(min(start_utc) at time zone 'UTC', 'YYYY-MM-DD HH24:MI') as s from tenant_luna.booking_items");
    $this->from(tenantUrl('luna', '/admin/bookings/'.$uuid))->post(tenantUrl('luna', '/admin/bookings/'.$uuid.'/move'), ['start' => adminMoveAt('2026-11-10 11:00')])->assertSessionHas('flash.kind', 'success');

    expect($today['11:00'])->toBe([])
        ->and($atLead)->toEqual((object) ['s' => '2026-11-02 09:00'])
        ->and(DB::selectOne("select to_char(min(start_utc) at time zone 'UTC', 'YYYY-MM-DD HH24:MI') as s from tenant_luna.booking_items"))->toEqual((object) ['s' => '2026-11-10 09:00']);
});

it('reads a day\'s candidates: another booking removes the starts it is in the way of, the booking\'s own start is left out, and Elena\'s lunch and evening mark starts as hours', function () {
    $uuid = adminMoveConfirmed();
    LunaFixture::booking('confirmed', start: '2026-11-03 15:00');

    $response = adminMoveRead($this, adminMoveOwner(), $uuid, ['date' => '2026-11-03'])->assertHeader('Cache-Control', 'no-store, private');
    $candidates = adminMoveCandidates($response);

    expect($response->json('date'))->toBe('2026-11-03')
        ->and($candidates)->not->toHaveKey('10:00')
        ->and($candidates['10:05'])->toBe([])
        ->and($candidates['12:15'])->toBe([])
        ->and($candidates['12:20'])->toBe(['hours'])
        ->and($candidates['13:00'])->toBe(['hours'])
        ->and($candidates['14:00'])->toBe([])
        ->and($candidates)->not->toHaveKey('14:20')
        ->and($candidates)->not->toHaveKey('15:00')
        ->and($candidates['15:45'])->toBe([])
        ->and($candidates['17:30'])->toBe(['hours'])
        ->and($candidates['08:00'])->toBe(['hours']);
});

it('marks a closure as a closure and reads the day for another member with the override', function () {
    $uuid = adminMoveConfirmed();
    adminMoveClosure(null, '2026-11-03', '16:00', '17:00');
    $nikos = LunaFixture::id('staff', 'Nikos Georgiou');

    $elenas = adminMoveCandidates(adminMoveRead($this, adminMoveOwner(), $uuid, ['date' => '2026-11-03']));
    $nikoss = adminMoveCandidates(adminMoveRead($this, adminMoveOwner(), $uuid, ['date' => '2026-11-03', 'staff' => [0 => $nikos]]));

    expect($elenas['16:00'])->toBe(['closure'])
        ->and($elenas['17:30'])->toBe(['hours'])
        ->and($nikoss['10:00'])->toBe([])
        ->and($nikoss['13:00'])->toBe([])
        ->and($nikoss['16:00'])->toBe(['closure'])
        ->and($nikoss['18:15'])->toBe([])
        ->and($nikoss['09:00'])->toBe(['hours']);
});

it('lists per segment the service, the member it is with and the members it may be given to', function () {
    $uuid = LunaFixture::hold(['Haircut', 'Bridal trial'], '2026-11-04 10:00');
    $elena = LunaFixture::id('staff', 'Elena Papadaki');
    $nikos = LunaFixture::id('staff', 'Nikos Georgiou');

    $segments = adminMoveRead($this, adminMoveOwner(), $uuid, ['date' => '2026-11-04'])->assertOk()->json('segments');

    expect($segments)->toBe([
        ['segment' => 0, 'service_id' => LunaFixture::id('services', 'Haircut'), 'service_name' => 'Haircut', 'staff_id' => $elena, 'staff_name' => 'Elena Papadaki', 'members' => [['id' => $elena, 'name' => 'Elena Papadaki'], ['id' => $nikos, 'name' => 'Nikos Georgiou']]],
        ['segment' => 1, 'service_id' => LunaFixture::id('services', 'Bridal trial'), 'service_name' => 'Bridal trial', 'staff_id' => $elena, 'staff_name' => 'Elena Papadaki', 'members' => [['id' => $elena, 'name' => 'Elena Papadaki']]],
    ]);
});

it('answers the verdict on one start with the code the write gives for the same request at that moment, and placeable with its crossings where the write lands', function () {
    $uuid = adminMoveConfirmed();
    LunaFixture::booking('confirmed', start: '2026-11-03 15:00');
    $bridal = LunaFixture::booking('confirmed', start: '2026-11-04 10:00', service: 'Bridal trial');
    $cancelled = LunaFixture::booking('cancelled', blocks: false, start: '2026-11-03 16:30');
    $nikos = LunaFixture::id('staff', 'Nikos Georgiou');
    $elena = LunaFixture::id('staff', 'Elena Papadaki');
    $owner = adminMoveOwner();

    $cases = [
        [$uuid, ['start' => adminMoveAt('2026-11-02 07:00')]],
        [$uuid, ['start' => adminMoveAt('2026-11-03 11:02')]],
        [$uuid, ['start' => adminMoveAt('2026-11-03 15:00')]],
        [$bridal, ['start' => adminMoveAt('2026-11-04 10:00'), 'staff' => [0 => $nikos]]],
        [$cancelled, ['start' => adminMoveAt('2026-11-03 11:00')]],
        [(string) Str::uuid(), ['start' => adminMoveAt('2026-11-03 11:00')]],
    ];

    foreach ($cases as [$booking, $body]) {
        $verdict = adminMoveRead($this, $owner, $booking, $body)->json('verdict');
        $written = adminMovePress($this, $owner, $booking, [...$body, 'warning_accepted' => true]);

        if ($written->getStatusCode() === 404) {
            adminMoveRead($this, $owner, $booking, $body)->assertStatus(404)->assertJsonPath('code', 'not_found');

            continue;
        }

        expect($verdict)->toMatchArray(['placeable' => false, 'code' => $written->json('code'), 'status' => $written->getStatusCode(), 'detail' => $written->json('data.detail')]);
    }

    adminMoveRead($this, $owner, $uuid, ['start' => adminMoveAt('2026-11-03 13:00')])
        ->assertOk()
        ->assertJsonPath('start', LunaFixture::at('2026-11-03 13:00')->setTimezone(new DateTimeZone('UTC'))->format(DateTimeInterface::RFC3339))
        ->assertJsonPath('verdict', ['placeable' => true, 'crossings' => [['segment' => 0, 'staff_id' => $elena, 'kind' => 'hours']]]);
    adminMovePress($this, $owner, $uuid, ['start' => adminMoveAt('2026-11-03 13:00')])->assertStatus(409)->assertJsonPath('data.crossings', [['segment' => 0, 'staff_id' => $elena, 'kind' => 'hours']]);

    adminMoveRead($this, $owner, $uuid, ['start' => adminMoveAt('2026-11-03 11:00')])->assertJsonPath('verdict', ['placeable' => true, 'crossings' => []]);
    adminMovePress($this, $owner, $uuid, ['start' => adminMoveAt('2026-11-03 11:00')])->assertRedirect();
    expect(adminMoveItems($uuid))->toBe(['Elena Papadaki Haircut 11:00-11:45']);
});

it('refuses a read naming neither a date nor a start, or both, with the 400 envelope and its sentence', function () {
    $uuid = adminMoveConfirmed();

    adminMoveRead($this, adminMoveOwner(), $uuid, [])->assertStatus(400)->assertJsonPath('data.detail', apiSentence('api.validation.move.one'));
    adminMoveRead($this, adminMoveOwner(), $uuid, ['date' => '2026-11-03', 'start' => adminMoveAt('2026-11-03 11:00')])->assertStatus(400)->assertJsonPath('data.detail', apiSentence('api.validation.move.one'));
    adminMoveRead($this, adminMoveOwner(), $uuid, ['date' => '3 Nov'])->assertStatus(400)->assertJsonPath('data.detail', apiSentence('api.validation.move.date'));
});

it('says on the calendar\'s blocks and the dialog\'s booking whether this viewer may move it now, across statuses', function () {
    $owner = adminMoveOwner();
    $elena = User::query()->findOrFail(addTenantUser('elena@luna.example', 'staff', staff: 'Elena Papadaki'));
    $confirmed = adminMoveConfirmed();
    $pending = LunaFixture::hold(['Haircut'], '2026-11-03 11:00');
    $request = LunaFixture::booking('awaiting_approval', 'approval', 'future', start: '2026-11-03 12:00', service: 'Bridal trial', requiresApproval: true);
    $lapsed = LunaFixture::booking('awaiting_approval', 'approval', 'past', start: '2026-11-03 14:00', service: 'Bridal trial', requiresApproval: true);
    $payment = LunaFixture::booking('awaiting_payment', 'payment', 'future', start: '2026-11-03 15:00', service: 'Bridal trial', paymentMode: 'online', requiresApproval: true);
    $cancelled = LunaFixture::booking('cancelled', blocks: false, start: '2026-11-03 16:00');

    /** @return array<string, bool> */
    $movable = function (User $viewer): array {
        $page = $this->actingAs($viewer)->get(tenantUrl('luna', '/admin/calendar?view=week&date=2026-11-03&who=all'))->assertOk()->viewData('page');
        $bookings = data_get($page, 'props.window.bookings');
        assert(is_array($bookings));
        $out = [];

        foreach ($bookings as $booking) {
            $uuid = data_get($booking, 'uuid');
            assert(is_string($uuid));
            $out[$uuid] = data_get($booking, 'movable') === true;
        }

        return $out;
    };

    expect($movable($owner))->toMatchArray([$confirmed => true, $pending => false, $request => true, $lapsed => false, $payment => true, $cancelled => false])
        ->and(array_values(array_unique($movable($elena))))->toBe([false]);

    $dialog = fn (User $viewer, string $uuid, string $screen): mixed => data_get($this->actingAs($viewer)->get(tenantUrl('luna', $screen.'?booking='.$uuid))->assertOk()->viewData('page'), 'props.detail.booking.movable');

    expect($dialog($owner, $confirmed, '/admin/calendar'))->toBeTrue()
        ->and($dialog($owner, $pending, '/admin/calendar'))->toBeFalse()
        ->and($dialog($elena, $confirmed, '/admin/calendar'))->toBeFalse()
        ->and(data_get($this->actingAs($owner)->get(tenantUrl('luna', '/admin/bookings/'.$cancelled))->assertOk()->viewData('page'), 'props.detail.booking.movable'))->toBeFalse()
        ->and(data_get($this->actingAs($owner)->get(tenantUrl('luna', '/admin/bookings/'.$request))->assertOk()->viewData('page'), 'props.detail.booking.movable'))->toBeTrue();
});
