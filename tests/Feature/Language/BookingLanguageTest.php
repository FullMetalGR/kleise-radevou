<?php

use App\Application\Clock;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FixedClock;
use Tests\Support\LunaFixture;
use Tests\TestCase;

pest()->use(DatabaseTruncation::class);

/*
 * The language on the API and the booking's language, over luna's real rows: every /api/v1 route
 * reads lang, the config naming the tenant default and the request's language; the hold records
 * the hold request's language and nothing updates it afterwards, a move and a cancellation
 * included; the admin's manual booking records the tenant default at its create.
 *
 * Not wrapped in a transaction: the holds enter the transaction runner. luna is in
 * Europe/Athens; 2026-11-03 is a Tuesday, when Elena works 09:00 to 13:00 and 14:00 to 18:00, and
 * the clock stands on the Monday before, outside the cancellation and reschedule windows.
 */

beforeEach(function () {
    $this->withoutVite();
    dropDemoSchemas();
    seedDemo();
    app()->instance(Clock::class, FixedClock::at('2026-11-02 06:00:00'));
    seamTransport()->flush();
});

afterEach(function () {
    dropDemoSchemas();
    DB::table('users')->delete();
    DB::table('tenants')->delete();
});

/**
 * A hold's body as the booking app sends one: a haircut with Elena at the Athens time given.
 *
 * @return array<string, mixed>
 */
function languageHoldBody(string $athens): array
{
    return [
        'customer_name' => 'Casey Customer',
        'customer_email' => 'casey@example.com',
        'customer_phone' => '+30 210 0000000',
        'items' => [['service_id' => LunaFixture::id('services', 'Haircut'), 'staff_id' => LunaFixture::id('staff', 'Elena Papadaki')]],
        'start' => LunaFixture::at($athens)->format(DateTimeImmutable::RFC3339),
    ];
}

/**
 * POST /api/v1/holds with the lang given, none for none, and the uuid and the token it answered.
 *
 * @return array{uuid: string, token: string}
 */
function languageHold(TestCase $test, ?string $lang, string $athens = '2026-11-03 10:00'): array
{
    $held = $test->postJson(tenantUrl('luna', '/api/v1/holds').($lang === null ? '' : '?lang='.$lang), languageHoldBody($athens))->assertCreated()->json();

    assert(is_array($held) && is_string($held['uuid']) && is_string($held['manage_token']));

    return ['uuid' => $held['uuid'], 'token' => $held['manage_token']];
}

function languageOfBooking(string $uuid): mixed
{
    return DB::table('tenant_luna.bookings')->where('uuid', $uuid)->value('language');
}

/**
 * GET /api/v1/config with the lang given, none for none.
 *
 * @return TestResponse<Response>
 */
function languageConfig(TestCase $test, ?string $lang): TestResponse
{
    return $test->getJson(tenantUrl('luna', '/api/v1/config').($lang === null ? '' : '?lang='.$lang))->assertOk();
}

it('names in the config the request\'s language beside the tenant default, the lang asked where it names a language and the default otherwise', function (?string $chosen, ?string $lang, string $default, string $language) {
    settingsOf('luna')->update(['language' => $chosen]);

    expect(languageConfig($this, $lang)->json())->toMatchArray(['default_language' => $default, 'language' => $language]);
})->with([
    'a Greek page asking an English tenant' => [null, 'el', 'en', 'el'],
    'an English page asking a Greek tenant' => ['el', 'en', 'el', 'en'],
    'nothing asked of a Greek tenant' => ['el', null, 'el', 'el'],
    'a language neither asked' => ['el', 'fr', 'el', 'el'],
]);

it('records on the hold the hold request\'s language, el from a Greek request and en from a bare one', function () {
    $greek = languageHold($this, 'el', '2026-11-03 10:00');
    $english = languageHold($this, 'en', '2026-11-03 11:00');
    $bare = languageHold($this, null, '2026-11-03 12:00');

    expect(languageOfBooking($greek['uuid']))->toBe('el')
        ->and(languageOfBooking($english['uuid']))->toBe('en')
        ->and(languageOfBooking($bare['uuid']))->toBe('en');
});

it('records the tenant default on a bare hold, the Greek a tenant chose', function () {
    settingsOf('luna')->update(['language' => 'el']);

    expect(languageOfBooking(languageHold($this, null)['uuid']))->toBe('el');
});

it('never updates the booking\'s language: a move and a cancellation asked in the other language leave it as the hold wrote it', function () {
    ['uuid' => $uuid, 'token' => $token] = languageHold($this, 'el');

    $this->postJson(tenantUrl('luna', '/api/v1/bookings/'.$uuid.'/reschedule?lang=en'), ['token' => $token, 'start' => LunaFixture::at('2026-11-04 15:00')->format(DateTimeImmutable::RFC3339)])->assertOk();

    expect(languageOfBooking($uuid))->toBe('el');

    $this->postJson(tenantUrl('luna', '/api/v1/bookings/'.$uuid.'/cancel?lang=en'), ['token' => $token])->assertOk();

    expect(languageOfBooking($uuid))->toBe('el')
        ->and(DB::table('tenant_luna.bookings')->where('uuid', $uuid)->value('status'))->toBe('cancelled');
});

it('records on a manual booking the tenant default at its create, never the language the admin\'s request is answered in', function (?string $chosen, string $recorded) {
    settingsOf('luna')->update(['language' => $chosen, 'reminder_lead_hours' => 0]);
    $owner = User::query()->where('email', 'owner@luna.example')->sole();

    $this->actingAs($owner)->from(tenantUrl('luna', '/admin/bookings'))->postJson(tenantUrl('luna', '/admin/bookings').'?lang=en', languageHoldBody('2026-11-03 10:00'))->assertRedirect();

    expect(DB::table('tenant_luna.bookings')->pluck('language')->all())->toBe([$recorded])
        ->and(DB::table('tenant_luna.bookings')->value('source'))->toBe('admin');
})->with([
    'a Greek tenant' => ['el', 'el'],
    'an English tenant' => ['en', 'en'],
]);
