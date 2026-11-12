<?php

use App\Application\Clock;
use App\Http\AdminSession;
use App\Http\Controllers\Admin\NewBookingController;
use App\Http\ErrorEnvelope;
use App\Infrastructure\Db\SqlText;
use App\Models\User;
use App\Platform\Operator;
use App\Site\SiteLanguage;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FixedClock;
use Tests\Support\FixtureDay;
use Tests\Support\LunaFixture;
use Tests\TestCase;

/*
 * POST /admin/bookings: the manual booking on luna's real rows, planned from the catalog and
 * written under the protocol, landing confirmed with no hold, the trail the operator's act, the
 * confirmation carrying a manage link that works and the business told, and every refusal as the
 * error envelope with the wizard's answers left to the client.
 *
 * The write enters the transaction runner, so this file truncates rather than wrapping each test
 * in a transaction, and seeds the demo afresh. luna is in Europe/Athens, UTC+2 in November;
 * 2026-11-03 is a Tuesday, when Elena works 09:00 to 13:00 and 14:00 to 18:00. The clock the
 * planner reads stands on the Monday before. Haircut is 45 minutes at 35 EUR and Colour 60
 * minutes with a 30 minute processing tail at 80 EUR, both on Elena.
 */

pest()->use(DatabaseTruncation::class);

beforeEach(function () {
    $this->withoutVite();
    dropDemoSchemas();
    seedDemo();
    app()->instance(Clock::class, FixedClock::at('2026-11-02 06:00:00'));
    seamTransport()->flush();
    // A confirmed booking schedules the reminder too, which the sync queue would run inside the
    // create and count here as a third message; a lead of zero is how a tenant sends none.
    settingsOf('luna')->update(['reminder_lead_hours' => 0]);
});

afterEach(function () {
    dropDemoSchemas();
    DB::table('support_sessions')->delete();
    DB::table('users')->delete();
    DB::table('tenants')->delete();
});

function manualOwner(): User
{
    return User::query()->where('email', 'owner@luna.example')->sole();
}

/**
 * The wizard's body: the customer and the ordered chain on Elena, the start as the availability
 * answer spells one, RFC 3339 with its offset.
 *
 * @param  list<string>  $services
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function manualBody(array $services = ['Haircut', 'Colour'], string $start = FixtureDay::START, array $overrides = []): array
{
    return [
        'customer_name' => LunaFixture::CUSTOMER_NAME,
        'customer_email' => LunaFixture::CUSTOMER_EMAIL,
        'customer_phone' => LunaFixture::CUSTOMER_PHONE,
        'items' => array_map(fn (string $service): array => ['service_id' => LunaFixture::id('services', $service), 'staff_id' => LunaFixture::id('staff', 'Elena Papadaki')], $services),
        'start' => LunaFixture::at($start)->format(DateTimeImmutable::RFC3339),
        ...$overrides,
    ];
}

/**
 * A support session of an operator on luna, open for the hour from the clock's now, carried by
 * the test's session as the console's open leaves it.
 */
function manualSupport(TestCase $test): Operator
{
    $now = app(Clock::class)->now();
    $operator = addOperator();
    $session = DB::table('support_sessions')->insertGetId([
        'operator_id' => $operator->id,
        'tenant_id' => LunaFixture::tenant()->id,
        'opened_at' => SqlText::instant($now),
        'expires_at' => SqlText::instant($now->add(new DateInterval('PT60M'))),
        'created_at' => SqlText::instant($now),
    ]);
    $test->withSession([AdminSession::SUPPORT_KEY => $session]);

    return $operator;
}

/**
 * @param  array<string, mixed>  $body
 * @return TestResponse<Response>
 */
function manualPost(TestCase $test, User $user, array $body): TestResponse
{
    return $test->actingAs($user)->from(tenantUrl('luna', '/admin/bookings'))->postJson(tenantUrl('luna', '/admin/bookings'), $body);
}

/**
 * The one booking luna holds, with its items in order.
 *
 * @return array{booking: array<mixed>, items: list<array<mixed>>}
 */
function manualOnly(): array
{
    $bookings = DB::table('tenant_luna.bookings')->get()->all();
    expect($bookings)->toHaveCount(1);
    $booking = get_object_vars($bookings[0] ?? new stdClass);
    $items = [];

    foreach (DB::table('tenant_luna.booking_items')->where('booking_id', $booking['id'] ?? null)->orderBy('sort')->get()->all() as $item) {
        $items[] = get_object_vars($item);
    }

    return ['booking' => $booking, 'items' => $items];
}

/**
 * The manage link in the customer's confirmation, and the token it carries.
 *
 * @return array{link: string, token: string}
 */
function manualLink(): array
{
    $links = array_values(array_filter(seamLinksIn(seamTextAt(LunaFixture::CUSTOMER_EMAIL)), fn (string $link): bool => str_contains($link, '/manage/')));
    expect($links)->toHaveCount(1);
    parse_str((string) parse_url($links[0], PHP_URL_QUERY), $query);
    $token = $query['token'] ?? null;
    assert(is_string($token));

    return ['link' => $links[0], 'token' => $token];
}

it('lands a two-segment chain confirmed with no hold, its items under the protocol, the total re-summed, a token hash on the row, one trail row naming the user, and no more', function () {
    $owner = manualOwner();

    manualPost($this, $owner, manualBody())
        ->assertRedirect(tenantUrl('luna', '/admin/bookings'));

    expect(session('flash'))->toBe(['kind' => 'success', 'message' => adminLine(NewBookingController::CREATED)]);

    ['booking' => $booking, 'items' => $items] = manualOnly();
    $utc = fn (string $athens): string => LunaFixture::instant(LunaFixture::at($athens));

    expect($booking)->toMatchArray(['status' => 'confirmed', 'hold_class' => null, 'hold_expires_at' => null, 'total_minor' => 11500, 'currency' => 'EUR', 'customer_name' => LunaFixture::CUSTOMER_NAME, 'payment_mode' => 'online', 'requires_approval' => false])
        ->and($booking['manage_token_hash'])->toBeString()->not->toBe('')
        ->and(count($items))->toBe(2)
        ->and(array_map(fn (array $item): array => [$item['sort'], $item['service_id'], $item['staff_id'], $item['price_minor'], $item['blocks']], $items))
        ->toBe([[0, LunaFixture::id('services', 'Haircut'), LunaFixture::id('staff', 'Elena Papadaki'), 3500, true], [1, LunaFixture::id('services', 'Colour'), LunaFixture::id('staff', 'Elena Papadaki'), 8000, true]])
        ->and(DB::selectOne("select to_char(start_utc at time zone 'UTC', 'YYYY-MM-DD HH24:MI') as s, to_char(end_utc at time zone 'UTC', 'YYYY-MM-DD HH24:MI') as e, to_char(processing_ends_utc at time zone 'UTC', 'YYYY-MM-DD HH24:MI') as p from tenant_luna.booking_items where sort = 1"))
        ->toEqual((object) ['s' => '2026-11-03 08:45', 'e' => '2026-11-03 09:45', 'p' => '2026-11-03 10:15'])
        ->and(array_map(fn (array $row): array => [$row['action'], $row['actor']], LunaFixture::trail()))->toBe([['confirmed', 'user:'.$owner->id]])
        ->and($utc('2026-11-03 10:00'))->toContain('08:00');
});

it('queues the confirmation alone, carrying a manage link that opens the manage view and reschedules: no acknowledgement, and no notice to the business that did the typing', function () {
    manualPost($this, manualOwner(), manualBody())->assertRedirect();

    ['booking' => $booking] = manualOnly();
    $uuid = $booking['uuid'];
    assert(is_string($uuid));
    ['link' => $link, 'token' => $token] = manualLink();
    $customer = seamAt(LunaFixture::CUSTOMER_EMAIL);

    // One message and one only: the confirmation, which is what an admin-created booking gets in
    // place of an acknowledgement. The business is not told, because AnnounceNewBooking
    // announces a checkout hold's settlement alone: an admin entered this one, and telling the
    // business would be mailing somebody their own typing.
    expect(seamAddressSet())->toBe([LunaFixture::CUSTOMER_EMAIL])
        ->and((string) $customer->getSubject())->toBe(customerMailLine('customer-mail.confirmed.subject', ['business' => 'Luna Hair Studio']))
        ->and(seamTextAt(LunaFixture::CUSTOMER_EMAIL))->toContain('Haircut')->toContain('Colour')->toContain('3 Nov 2026, 10:00')->toContain($link)
        ->and(DB::table('tenant_luna.bookings')->where('uuid', $uuid)->value('manage_token_hash'))->not->toBe($token);

    $this->get($link)->assertOk();
    $this->getJson(tenantUrl('luna', '/api/v1/bookings/'.$uuid.'?token='.$token))->assertOk()->assertJsonPath('status', 'confirmed');
    $this->postJson(tenantUrl('luna', '/api/v1/bookings/'.$uuid.'/reschedule'), ['token' => $token, 'start' => LunaFixture::at('2026-11-10 10:00')->format(DateTimeImmutable::RFC3339)])->assertOk();

    expect(DB::selectOne("select to_char(min(start_utc) at time zone 'UTC', 'YYYY-MM-DD HH24:MI') as s from tenant_luna.booking_items"))->toEqual((object) ['s' => '2026-11-10 08:00']);
});

it('lands a start inside the lead time, which binds customers alone, and answers a range a booking already holds as 409 unavailable naming its segment before the lock is ever taken, writing nothing', function () {
    DB::table('tenant_luna.services')->where('name', 'Haircut')->update(['lead_time_min' => 180]);
    // 09:30 in Athens on the Tuesday: the lead time of three hours reaches 12:30, past the
    // 10:00 start the wizard sends.
    app()->instance(Clock::class, FixedClock::at('2026-11-03 07:30:00'));

    manualPost($this, manualOwner(), manualBody())->assertRedirect(tenantUrl('luna', '/admin/bookings'));

    expect(DB::table('tenant_luna.bookings')->where('status', 'confirmed')->count())->toBe(1);

    app()->instance(Clock::class, FixedClock::at('2026-11-02 06:00:00'));
    manualPost($this, manualOwner(), manualBody(['Haircut'], '2026-11-03 14:00'))->assertRedirect();
    manualPost($this, manualOwner(), manualBody(['Beard trim', 'Haircut'], '2026-11-03 13:50', ['items' => [
        ['service_id' => LunaFixture::id('services', 'Beard trim'), 'staff_id' => LunaFixture::id('staff', 'Nikos Georgiou')],
        ['service_id' => LunaFixture::id('services', 'Haircut'), 'staff_id' => LunaFixture::id('staff', 'Elena Papadaki')],
    ]]))
        // The planner's advisory masks already show Elena taken at 14:00, so the second
        // booking is refused as the tenant not being open to the range, on the near side of
        // the lock; the overlap found under the lock takes a race, and the concurrency suite
        // proves it, tests/Concurrency/NewBookingTest.php.
        ->assertStatus(409)
        ->assertJson(['code' => 'unavailable', 'data' => ['status' => 409, 'detail' => apiSentence(ErrorEnvelope::UNAVAILABLE_DETAIL), 'segment' => 1]]);

    expect(DB::table('tenant_luna.bookings')->count())->toBe(2)
        ->and(LunaFixture::trail())->toHaveCount(2);
});

it('lands a start on a date past the horizon, which binds customers alone, while the public hold refuses the same start', function () {
    DB::table('tenant_luna.services')->where('name', 'Haircut')->update(['horizon_days' => 3]);

    // Today is the Monday 2026-11-02, so the horizon of three days ends on the Thursday; the
    // Tuesday a week on is past it.
    manualPost($this, manualOwner(), manualBody(['Haircut'], '2026-11-10 10:00'))->assertRedirect(tenantUrl('luna', '/admin/bookings'));

    expect(DB::selectOne("select to_char(min(start_utc) at time zone 'UTC', 'YYYY-MM-DD HH24:MI') as s from tenant_luna.booking_items"))->toEqual((object) ['s' => '2026-11-10 08:00']);

    $this->postJson(tenantUrl('luna', '/api/v1/holds'), [...manualBody(['Haircut'], '2026-11-11 10:00')])
        ->assertStatus(409)
        ->assertJsonPath('code', 'unavailable');

    expect(DB::table('tenant_luna.bookings')->count())->toBe(1);
});

it('refuses a start already passed with its own sentence and its rule, for the signed-in user and for support alike, writing nothing', function () {
    $past = manualBody(['Haircut'], '2026-11-02 07:00');

    manualPost($this, manualOwner(), $past)
        ->assertStatus(409)
        ->assertJson(['code' => 'unavailable', 'data' => ['status' => 409, 'detail' => apiSentence('api.move.past'), 'segment' => 0, 'rule' => 'past']]);

    auth()->logout();

    manualSupport($this);
    $this->postJson(tenantUrl('luna', '/admin/bookings'), $past)
        ->assertStatus(409)
        ->assertJson(['code' => 'unavailable', 'data' => ['detail' => apiSentence('api.move.past'), 'rule' => 'past']]);

    expect(DB::table('tenant_luna.bookings')->count())->toBe(0);
});

it('lets support book inside the lead time and past the horizon as the signed-in user does, the trail naming the operator', function () {
    DB::table('tenant_luna.services')->where('name', 'Haircut')->update(['lead_time_min' => 180, 'horizon_days' => 3]);
    app()->instance(Clock::class, FixedClock::at('2026-11-03 07:30:00'));

    $operator = manualSupport($this);
    $this->postJson(tenantUrl('luna', '/admin/bookings'), manualBody(['Haircut'], '2026-11-03 10:00'))->assertRedirect();
    $this->postJson(tenantUrl('luna', '/admin/bookings'), manualBody(['Haircut'], '2026-11-10 10:00'))->assertRedirect();

    expect(DB::table('tenant_luna.bookings')->where('status', 'confirmed')->count())->toBe(2)
        ->and(array_column(LunaFixture::trail(), 'actor'))->toBe(['support:'.$operator->id, 'support:'.$operator->id]);
});

it('answers a bad address with its own sentence as a 400 envelope, never a redirect with a bag, and sends nothing', function () {
    manualPost($this, manualOwner(), manualBody(overrides: ['customer_email' => 'not-an-email']))
        ->assertStatus(400)
        ->assertJson(['code' => 'invalid_request', 'data' => ['status' => 400, 'detail' => apiSentence('api.validation.customer_email.email')]]);

    expect(DB::table('tenant_luna.bookings')->count())->toBe(0)
        ->and(seamSent())->toBe([]);
});

it('is manageBookings alone and never the subscription: a staff user is refused, a guest sent to sign in, and a past_due tenant still books', function () {
    addTenantUser('staff@luna.example', 'staff');

    // The guest first: actingAs holds for the rest of the test.
    $this->from(tenantUrl('luna', '/admin/bookings'))->post(tenantUrl('luna', '/admin/bookings'), manualBody())->assertRedirect(tenantUrl('luna', '/admin/login'));
    manualPost($this, User::query()->where('email', 'staff@luna.example')->sole(), manualBody())->assertForbidden();

    DB::table('tenants')->where('slug', 'luna')->update(['subscription_state' => 'past_due']);

    manualPost($this, manualOwner(), manualBody())->assertRedirect();

    expect(DB::table('tenant_luna.bookings')->where('status', 'confirmed')->count())->toBe(1);
});

it('words the wizard\'s flash in customers.php\'s Greek and its refusals in api.php\'s for a user at el, and in English for a colleague at en', function () {
    DB::table('users')->where('email', 'owner@luna.example')->update(['locale' => SiteLanguage::Greek->value]);
    $colleague = User::query()->findOrFail(addTenantUser('manager@luna.example', 'manager'));
    $colleague->forceFill(['locale' => SiteLanguage::English->value])->save();
    $past = manualBody(['Haircut'], '2026-11-02 07:00');
    $badAddress = manualBody(overrides: ['customer_email' => 'not-an-email']);

    manualPost($this, manualOwner(), $past)
        ->assertStatus(409)
        ->assertJson(['code' => 'unavailable', 'data' => ['detail' => apiSentence('api.move.past', [], SiteLanguage::Greek), 'rule' => 'past']]);
    manualPost($this, manualOwner(), $badAddress)
        ->assertStatus(400)
        ->assertJson(['code' => 'invalid_request', 'data' => ['detail' => apiSentence('api.validation.customer_email.email', [], SiteLanguage::Greek)]]);
    manualPost($this, $colleague, $past)
        ->assertStatus(409)
        ->assertJson(['data' => ['detail' => apiSentence('api.move.past')]]);

    manualPost($this, manualOwner(), manualBody())->assertRedirect(tenantUrl('luna', '/admin/bookings'));
    expect(session('flash'))->toBe(['kind' => 'success', 'message' => adminLine(NewBookingController::CREATED, [], SiteLanguage::Greek)])
        ->and(adminLine(NewBookingController::CREATED, [], SiteLanguage::Greek))->not->toBe(adminLine(NewBookingController::CREATED))
        ->and(apiSentence('api.move.past', [], SiteLanguage::Greek))->not->toBe(apiSentence('api.move.past'));
});

it('words the wizard\'s availability read\'s refusal in api.php\'s Greek for a user at el, and in English for a colleague at en', function () {
    DB::table('users')->where('email', 'owner@luna.example')->update(['locale' => SiteLanguage::Greek->value]);
    $colleague = User::query()->findOrFail(addTenantUser('manager@luna.example', 'manager'));
    $colleague->forceFill(['locale' => SiteLanguage::English->value])->save();
    // No items: the first rule of AvailabilityRequest refuses it, worded by api.php.
    $read = fn (User $user): TestResponse => $this->actingAs($user)->getJson(tenantUrl('luna', '/admin/api/availability'))->assertStatus(400);

    expect($read(manualOwner())->json('data.detail'))->toBe(apiSentence('api.validation.items.required', [], SiteLanguage::Greek))
        ->and($read($colleague)->json('data.detail'))->toBe(apiSentence('api.validation.items.required'))
        ->and(apiSentence('api.validation.items.required', [], SiteLanguage::Greek))->not->toBe(apiSentence('api.validation.items.required'));
});
