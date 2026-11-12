<?php

use App\Application\Clock;
use App\Http\ErrorEnvelope;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FixedClock;
use Tests\Support\LunaFixture;
use Tests\TestCase;

/*
 * The public road's pool is the catalogue's answer, the services and members offered online, so
 * a hold with "No preference" never lands on a member who is not and a hold naming one is
 * refused as not performing; the admin's wizard keeps the whole roster and the whole catalogue.
 * Nothing in the mask algebra moves: the flag narrows who the engine is asked about on the
 * public road, not how it answers.
 *
 * The holds enter the transaction runner, so this file truncates rather than wrapping each test
 * in a transaction, and seeds the demo afresh. luna is in Europe/Athens, UTC+2 in November;
 * 2026-11-03 is a Tuesday, when Elena works 09:00 to 13:00 and 14:00 to 18:00 and Nikos 10:00
 * to 19:00, and both perform Haircut. The clock the planner reads stands on the Monday before.
 */

pest()->use(DatabaseTruncation::class);

beforeEach(function () {
    $this->withoutVite();
    dropDemoSchemas();
    seedDemo();
    app()->instance(Clock::class, FixedClock::at('2026-11-02 06:00:00'));
});

afterEach(function () {
    dropDemoSchemas();
    DB::table('users')->delete();
    DB::table('tenants')->delete();
});

function onlinePoolStart(string $athens): string
{
    return LunaFixture::at($athens)->format(DateTimeImmutable::RFC3339);
}

/**
 * A hold's or the wizard's body on luna: the customer and one Haircut, on the member named or on
 * no preference.
 *
 * @return array<string, mixed>
 */
function onlinePoolBody(?string $staff, string $athens = '2026-11-03 10:00', string $service = 'Haircut'): array
{
    return [
        'customer_name' => LunaFixture::CUSTOMER_NAME,
        'customer_email' => LunaFixture::CUSTOMER_EMAIL,
        'customer_phone' => LunaFixture::CUSTOMER_PHONE,
        'items' => [['service_id' => LunaFixture::id('services', $service), 'staff_id' => $staff === null ? null : LunaFixture::id('staff', $staff)]],
        'start' => onlinePoolStart($athens),
    ];
}

/**
 * @return TestResponse<Response>
 */
function onlinePoolHold(TestCase $test, ?string $staff, string $service = 'Haircut'): TestResponse
{
    return $test->postJson(tenantUrl('luna', '/api/v1/holds'), onlinePoolBody($staff, service: $service));
}

function onlinePoolQuery(?string $staff, string $service = 'Haircut'): string
{
    return http_build_query([
        'items' => (string) json_encode([['service_id' => LunaFixture::id('services', $service), 'staff_id' => $staff === null ? null : LunaFixture::id('staff', $staff)]]),
        'from' => '2026-11-03',
        'to' => '2026-11-04',
    ]);
}

function onlinePoolOwner(): User
{
    return User::query()->where('email', 'owner@luna.example')->sole();
}

function onlinePoolOffline(string $table, string $name): void
{
    DB::table('tenant_luna.'.$table)->where('id', LunaFixture::id($table, $name))->update(['online' => false]);
}

it('refuses a public hold with no preference whose one online performer is busy, rather than landing it on the member not offered online', function () {
    onlinePoolHold($this, 'Elena Papadaki')->assertCreated();
    onlinePoolOffline('staff', 'Nikos Georgiou');

    onlinePoolHold($this, null)->assertStatus(409)->assertJsonPath('code', 'unavailable')->assertJsonPath('data.segment', 0);
    expect(DB::table('tenant_luna.booking_items')->where('staff_id', LunaFixture::id('staff', 'Nikos Georgiou'))->exists())->toBeFalse();

    // The same press with Nikos offered online lands on him: the flag, not the hour, refused it.
    DB::table('tenant_luna.staff')->where('id', LunaFixture::id('staff', 'Nikos Georgiou'))->update(['online' => true]);
    onlinePoolHold($this, null)->assertCreated()->assertJsonPath('items.0.staff_name', 'Nikos Georgiou');
});

it('refuses a public hold and a public availability read naming the member not offered online as not performing the service', function () {
    onlinePoolOffline('staff', 'Nikos Georgiou');

    onlinePoolHold($this, 'Nikos Georgiou')->assertStatus(400)->assertExactJson([
        'code' => 'invalid_request',
        'message' => 'invalid_request',
        'data' => ['status' => 400, 'detail' => apiSentence(ErrorEnvelope::STAFF_NOT_ELIGIBLE_DETAIL)],
    ]);
    $this->getJson(tenantUrl('luna', '/api/v1/availability?'.onlinePoolQuery('Nikos Georgiou')))->assertStatus(400)
        ->assertJsonPath('data.detail', apiSentence('api.validation.item.staff_unqualified', ['n' => '1']));

    expect(DB::table('tenant_luna.bookings')->count())->toBe(0);
});

it('answers a public hold on a service not offered online 404 as an inactive one, and the public read names no start for it', function () {
    onlinePoolOffline('services', 'Haircut');

    onlinePoolHold($this, null)->assertNotFound()->assertJsonPath('data.detail', apiSentence(ErrorEnvelope::SERVICE_GONE_DETAIL));
    $this->getJson(tenantUrl('luna', '/api/v1/availability?'.onlinePoolQuery(null)))->assertStatus(400)
        ->assertJsonPath('data.detail', apiSentence('api.validation.item.service_unavailable', ['n' => '1']));
});

it('books the member and the service not offered online from the admin\'s wizard, whose availability read still names them', function () {
    onlinePoolOffline('staff', 'Nikos Georgiou');
    onlinePoolOffline('services', 'Haircut');
    $owner = onlinePoolOwner();

    $starts = $this->actingAs($owner)->getJson(tenantUrl('luna', '/admin/api/availability?'.onlinePoolQuery('Nikos Georgiou')))->assertOk()->json('2026-11-03');
    expect($starts)->toBeArray()->not->toBeEmpty();

    $this->actingAs($owner)->from(tenantUrl('luna', '/admin/bookings'))
        ->postJson(tenantUrl('luna', '/admin/bookings'), onlinePoolBody('Nikos Georgiou'))
        ->assertRedirect(tenantUrl('luna', '/admin/bookings'));

    expect(DB::table('tenant_luna.booking_items')->pluck('staff_id')->all())->toBe([LunaFixture::id('staff', 'Nikos Georgiou')])
        ->and(DB::table('tenant_luna.bookings')->value('status'))->toBe('confirmed');
});
