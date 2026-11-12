<?php

use App\Http\Controllers\Admin\CustomerProfileController;
use App\Http\Requests\CustomerProfileRequest;
use App\Models\User;
use App\Site\SiteLanguage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FixtureDay;
use Tests\Support\LunaFixture;
use Tests\TestCase;

pest()->use(RefreshDatabase::class);

/*
 * A customer's profile: the row customer_profiles keeps per address, written whole from the
 * page's Profile card by PUT /admin/customers/{email}/profile under manageBookings and outside
 * the subscription gate, the tags a list of trimmed, distinct words, at most ten of at most
 * twenty-four characters, the birthday a past or present date or none, the preferred language one
 * of the two or none; read on the page as its profile prop and on every row of the list and the
 * page's customer as its tags and whether it is blocked; and the page's Spent line, what the
 * address's visits came to in each currency.
 *
 * The bookings are written straight to the rows at literal dates, so the clock is pinned first,
 * 2026-11-02 08:00 in Athens.
 */

beforeEach(function () {
    $this->withoutVite();
    seedDemo();
    FixtureDay::pinClock();
});

/**
 * A booking of luna under an address at an Athens start, one Haircut item on Elena.
 */
function profileBooking(string $email, string $start, string $status = 'confirmed', int $total = 3500, string $currency = 'EUR', string $name = 'Casey Customer'): void
{
    $id = DB::table('tenant_luna.bookings')->insertGetId([
        'source' => 'embed', 'uuid' => (string) Str::uuid(), 'status' => $status, 'customer_name' => $name, 'customer_email' => $email,
        'customer_phone' => null, 'total_minor' => $total, 'currency' => $currency,
        'payment_mode' => 'onsite', 'requires_approval' => false, 'on_approval_timeout' => 'expire',
    ]);
    $from = LunaFixture::at($start);
    $to = $from->add(new DateInterval('PT45M'));
    DB::table('tenant_luna.booking_items')->insert([
        'booking_id' => $id, 'sort' => 0,
        'service_id' => LunaFixture::id('services', 'Haircut'), 'staff_id' => LunaFixture::id('staff', 'Elena Papadaki'),
        'start_utc' => LunaFixture::instant($from), 'end_utc' => LunaFixture::instant($to),
        'block_start_utc' => LunaFixture::instant($from), 'block_end_utc' => LunaFixture::instant($to),
        'price_minor' => $total, 'blocks' => false,
    ]);
}

function profileOwner(): User
{
    return User::query()->where('email', 'owner@luna.example')->sole();
}

/**
 * The Profile card's save, as the page sends it.
 *
 * @param  array<string, mixed>  $body
 * @return TestResponse<Response>
 */
function profileSave(TestCase $test, string $segment, array $body, ?User $user = null): TestResponse
{
    $page = tenantUrl('luna', '/admin/customers/'.$segment);

    return $test->actingAs($user ?? profileOwner())->from($page)->json('PUT', $page.'/profile', $body, ['X-Inertia' => 'true']);
}

/**
 * The profile row of an address as the database holds it.
 *
 * @return array<string, mixed>|null
 */
function profileRowOf(string $email): ?array
{
    $row = DB::table('tenant_luna.customer_profiles')->where('email', $email)->first(['email', 'tags', 'birthday', 'language']);

    return $row === null ? null : ['email' => $row->email ?? null, 'tags' => $row->tags ?? null, 'birthday' => $row->birthday ?? null, 'language' => $row->language ?? null];
}

/**
 * A refusal of the Profile card as its constants make it: the tags' read as a choice by MOST_TAGS
 * with :n TAG_LENGTH, the birthday's naming EARLIEST's year, the language's as written; in
 * English unless a language is named.
 */
function profileRefusal(string $key, SiteLanguage $language = SiteLanguage::English): string
{
    return match ($key) {
        CustomerProfileRequest::TAGS => adminChoice($key, CustomerProfileRequest::MOST_TAGS, ['n' => (string) CustomerProfileRequest::TAG_LENGTH], $language),
        CustomerProfileRequest::BIRTHDAY => adminLine($key, ['year' => substr(CustomerProfileRequest::EARLIEST, 0, 4)], $language),
        default => adminLine($key, [], $language),
    };
}

it('fills the card\'s refusals and the note\'s and the reason\'s from their constants, the file spelling no bound and no option', function () {
    foreach ([CustomerProfileRequest::TAGS, CustomerProfileRequest::BIRTHDAY, CustomerProfileRequest::LANGUAGE, 'admin/customers.validation.note', 'admin/customers.validation.reason'] as $key) {
        // A choice's conditions, {1} and [2,*], are the syntax and no bound.
        expect(preg_replace('/[{\[][-\d,*]*[}\]]/', '', (string) __($key, [], 'en')))->toBeString()->not->toMatch('/\\d/')->not->toContain(':most')->not->toContain(':length');
    }

    expect(profileRefusal(CustomerProfileRequest::BIRTHDAY))->toContain(substr(CustomerProfileRequest::EARLIEST, 0, 4))
        ->and(profileRefusal(CustomerProfileRequest::TAGS))->toContain((string) CustomerProfileRequest::MOST_TAGS)->toContain((string) CustomerProfileRequest::TAG_LENGTH)
        ->and(adminLine(CustomerProfileRequest::LANGUAGE))->not->toContain(adminLine('admin/customers.profile.greek'))->not->toContain(adminLine('admin/customers.profile.not_recorded'))
        ->and(adminChoice('admin/customers.validation.note', 1))->not->toBe(adminChoice('admin/customers.validation.note', 2));
});

it('reads an address with no profile as no tags, no birthday, no language and not blocked, on the page and on the list', function () {
    profileBooking('casey@example.com', '2026-11-20 10:00');

    $this->actingAs(profileOwner())->get(tenantUrl('luna', '/admin/customers/casey%40example.com'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Customer')
            ->where('profile', ['tags' => [], 'birthday' => null, 'language' => null])
            ->where('earliest_birthday', CustomerProfileRequest::EARLIEST)
            ->where('customer.tags', [])
            ->where('customer.blocked', false)
            ->where('spent', []));

    $this->get(tenantUrl('luna', '/admin/customers'))
        ->assertInertia(fn (Assert $page) => $page->where('customers.rows.0.tags', [])->where('customers.rows.0.blocked', false));
});

it('saves the whole profile in one request, the tags trimmed and each kept once at its first spelling, the address in lower case, and lands back on the page with its flash', function () {
    profileBooking('Casey@Example.com', '2026-11-20 10:00');
    $page = tenantUrl('luna', '/admin/customers/CASEY%40EXAMPLE.COM');

    profileSave($this, 'CASEY%40EXAMPLE.COM', ['tags' => ' VIP, colour ,vip,, regular ', 'birthday' => '1988-04-12', 'language' => 'el'])
        ->assertRedirect($page);

    expect(profileRowOf('casey@example.com'))->toBe(['email' => 'casey@example.com', 'tags' => '{VIP,colour,regular}', 'birthday' => '1988-04-12', 'language' => 'el']);

    $this->get($page)
        ->assertInertia(fn (Assert $props) => $props
            ->where('flash', ['kind' => 'success', 'message' => adminLine(CustomerProfileController::SAVED)])
            ->where('profile', ['tags' => ['VIP', 'colour', 'regular'], 'birthday' => '1988-04-12', 'language' => 'el'])
            ->where('customer.tags', ['VIP', 'colour', 'regular']));

    $this->get(tenantUrl('luna', '/admin/customers'))
        ->assertInertia(fn (Assert $props) => $props->where('customers.rows.0.tags', ['VIP', 'colour', 'regular']));
});

it('clears every field by a save of none, the row kept with nothing in it', function () {
    profileBooking('casey@example.com', '2026-11-20 10:00');
    profileSave($this, 'casey%40example.com', ['tags' => 'VIP', 'birthday' => '1988-04-12', 'language' => 'en'])->assertRedirect();

    profileSave($this, 'casey%40example.com', ['tags' => ' , ', 'birthday' => null, 'language' => null])->assertRedirect();

    expect(profileRowOf('casey@example.com'))->toBe(['email' => 'casey@example.com', 'tags' => '{}', 'birthday' => null, 'language' => null]);

    profileSave($this, 'casey%40example.com', ['tags' => '', 'birthday' => '', 'language' => ''])->assertRedirect();

    expect(profileRowOf('casey@example.com'))->toBe(['email' => 'casey@example.com', 'tags' => '{}', 'birthday' => null, 'language' => null]);
});

it('admits ten tags of twenty-four characters and a birthday on the tenant\'s today', function () {
    profileBooking('casey@example.com', '2026-11-20 10:00');
    $tags = array_map(fn (int $n): string => str_pad('tag'.$n, 24, 'x'), range(1, 10));

    profileSave($this, 'casey%40example.com', ['tags' => implode(', ', $tags), 'birthday' => '2026-11-02', 'language' => null])->assertRedirect();

    expect(DB::table('tenant_luna.customer_profiles')->value('birthday'))->toBe('2026-11-02');
});

it('refuses {what} with its sentence, writing nothing', function (string $what, string $tags, string $birthday, string $language, string $key) {
    profileBooking('casey@example.com', '2026-11-20 10:00');

    profileSave($this, 'casey%40example.com', ['tags' => $tags, 'birthday' => $birthday === '-' ? null : $birthday, 'language' => $language === '-' ? null : $language])
        ->assertStatus(400)
        ->assertJsonPath('data.detail', profileRefusal($key));

    expect(DB::table('tenant_luna.customer_profiles')->count())->toBe(0);
})->with([
    ['eleven tags', 'a, b, c, d, e, f, g, h, i, j, k', '-', '-', CustomerProfileRequest::TAGS],
    ['a tag of twenty-five characters', str_repeat('x', 25), '-', '-', CustomerProfileRequest::TAGS],
    ['a birthday that is no date', '', 'April', '-', CustomerProfileRequest::BIRTHDAY],
    ['a birthday the calendar has not', '', '1990-02-30', '-', CustomerProfileRequest::BIRTHDAY],
    ['a birthday after the tenant\'s today', '', '2026-11-03', '-', CustomerProfileRequest::BIRTHDAY],
    ['a birthday before 1900', '', '1899-12-31', '-', CustomerProfileRequest::BIRTHDAY],
    ['a language neither of the two', '', '-', 'fr', CustomerProfileRequest::LANGUAGE],
]);

it('refuses a body with no tags key, or tags that are not text, as the tags\' refusal', function () {
    profileBooking('casey@example.com', '2026-11-20 10:00');

    profileSave($this, 'casey%40example.com', ['birthday' => null, 'language' => null])->assertStatus(400)->assertJsonPath('data.detail', profileRefusal(CustomerProfileRequest::TAGS));
    profileSave($this, 'casey%40example.com', ['tags' => ['VIP'], 'birthday' => null, 'language' => null])->assertStatus(400)->assertJsonPath('data.detail', profileRefusal(CustomerProfileRequest::TAGS));
});

it('answers an address no booking was made under with not-found, refuses a staff user, and never gates the save by the subscription', function () {
    profileBooking('casey@example.com', '2026-11-20 10:00');
    addTenantUser('staff@luna.example', 'staff');

    profileSave($this, 'nobody%40example.com', ['tags' => 'VIP', 'birthday' => null, 'language' => null])->assertNotFound();
    profileSave($this, 'casey%40example.com', ['tags' => 'VIP', 'birthday' => null, 'language' => null], User::query()->where('email', 'staff@luna.example')->sole())->assertForbidden();

    expect(DB::table('tenant_luna.customer_profiles')->count())->toBe(0);

    DB::table('tenants')->where('slug', 'luna')->update(['subscription_state' => 'canceled']);
    profileSave($this, 'casey%40example.com', ['tags' => 'VIP', 'birthday' => null, 'language' => null])->assertRedirect();

    expect(profileRowOf('casey@example.com')['tags'] ?? null)->toBe('{VIP}');
});

it('marks a row of the list and the page\'s customer blocked where the profile carries a block', function () {
    profileBooking('casey@example.com', '2026-11-20 10:00');
    profileBooking('ada@example.test', '2026-11-21 10:00', name: 'Ada North');
    DB::table('tenant_luna.customer_profiles')->insert([
        'email' => 'casey@example.com', 'tags' => '{VIP}', 'blocked_at' => '2026-11-01 09:00:00+00:00', 'blocked_reason' => 'Three no-shows',
        'created_at' => '2026-11-01 09:00:00+00:00', 'updated_at' => '2026-11-01 09:00:00+00:00',
    ]);

    $this->actingAs(profileOwner())->get(tenantUrl('luna', '/admin/customers'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('customers.rows.0.email', 'ada@example.test')->where('customers.rows.0.blocked', false)->where('customers.rows.0.tags', [])
            ->where('customers.rows.1.email', 'casey@example.com')->where('customers.rows.1.blocked', true)->where('customers.rows.1.tags', ['VIP']));

    $this->get(tenantUrl('luna', '/admin/customers/casey%40example.com'))
        ->assertInertia(fn (Assert $page) => $page->where('customer.blocked', true));
});

it('sums the visits the address made in each currency for the Spent line: confirmed or completed with the start passed, nothing ahead and nothing cancelled or missed', function () {
    profileBooking('casey@example.com', '2026-10-01 10:00', 'completed', 3500);
    profileBooking('casey@example.com', '2026-10-15 10:00', 'confirmed', 8000);
    profileBooking('casey@example.com', '2026-10-20 10:00', 'completed', 1200, 'USD');
    profileBooking('casey@example.com', '2026-10-21 10:00', 'cancelled', 9900);
    profileBooking('casey@example.com', '2026-10-22 10:00', 'no_show', 9900);
    profileBooking('casey@example.com', '2026-11-20 10:00', 'confirmed', 9900);
    profileBooking('ada@example.test', '2026-10-01 10:00', 'completed', 4400, name: 'Ada North');

    $this->actingAs(profileOwner())->get(tenantUrl('luna', '/admin/customers/casey%40example.com'))
        ->assertInertia(fn (Assert $page) => $page->where('spent', [['amount_minor' => 11500, 'currency' => 'EUR'], ['amount_minor' => 1200, 'currency' => 'USD']]));
});

it('words the save\'s flash and the three refusals in the user\'s language: a user at el reads customers.php\'s Greek, a colleague at en its English', function () {
    DB::table('users')->where('email', 'owner@luna.example')->update(['locale' => SiteLanguage::Greek->value]);
    $colleague = User::query()->findOrFail(addTenantUser('manager@luna.example', 'manager'));
    $colleague->forceFill(['locale' => SiteLanguage::English->value])->save();
    profileBooking('casey@example.com', '2026-11-20 10:00');
    $refused = ['tags' => 'a, b, c, d, e, f, g, h, i, j, k', 'birthday' => null, 'language' => null];

    // validation.tags keeps the [2,*] form alone, which only a bound above one reaches.
    expect(CustomerProfileRequest::MOST_TAGS)->toBeGreaterThan(1);

    foreach ([
        CustomerProfileRequest::TAGS => $refused,
        CustomerProfileRequest::BIRTHDAY => ['tags' => '', 'birthday' => '1899-12-31', 'language' => null],
        CustomerProfileRequest::LANGUAGE => ['tags' => '', 'birthday' => null, 'language' => 'fr'],
    ] as $key => $body) {
        profileSave($this, 'casey%40example.com', $body)->assertStatus(400)->assertJsonPath('data.detail', profileRefusal($key, SiteLanguage::Greek));
    }

    profileSave($this, 'casey%40example.com', $refused, $colleague)->assertStatus(400)->assertJsonPath('data.detail', profileRefusal(CustomerProfileRequest::TAGS));
    profileSave($this, 'casey%40example.com', ['tags' => 'VIP', 'birthday' => null, 'language' => null])->assertRedirect();

    expect(session('flash'))->toBe(['kind' => 'success', 'message' => adminLine(CustomerProfileController::SAVED, [], SiteLanguage::Greek)])
        ->and(profileRefusal(CustomerProfileRequest::TAGS, SiteLanguage::Greek))->not->toBe(profileRefusal(CustomerProfileRequest::TAGS));
});
