<?php

use App\Application\Clock;
use App\Http\Controllers\Admin\CustomerBlockController;
use App\Http\Requests\CustomerBlockRequest;
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
 * A customer's block on online booking: PUT /admin/customers/{email}/block blocks the address
 * with an optional reason for the staff, stamping blocked_at from the Clock, and DELETE on the
 * same route unblocks it, clearing both; both under manageBookings and outside the subscription
 * gate, as the profile's save is. The page reads the block as its block prop, the instant and the
 * reason, and the list's row and the page's customer as blocked. A block leaves the profile's
 * tags, birthday and language as they stand, and the profile's save leaves the block.
 */

beforeEach(function () {
    $this->withoutVite();
    seedDemo();
    FixtureDay::pinClock();
});

/**
 * A booking of luna under an address at an Athens start, one Haircut item on Elena.
 */
function blockBooking(string $email, string $start = '2026-11-20 10:00'): void
{
    $id = DB::table('tenant_luna.bookings')->insertGetId([
        'source' => 'embed', 'uuid' => (string) Str::uuid(), 'status' => 'confirmed', 'customer_name' => 'Casey Customer', 'customer_email' => $email,
        'customer_phone' => null, 'total_minor' => 3500, 'currency' => 'EUR',
        'payment_mode' => 'onsite', 'requires_approval' => false, 'on_approval_timeout' => 'expire',
    ]);
    $from = LunaFixture::at($start);
    $to = $from->add(new DateInterval('PT45M'));
    DB::table('tenant_luna.booking_items')->insert([
        'booking_id' => $id, 'sort' => 0,
        'service_id' => LunaFixture::id('services', 'Haircut'), 'staff_id' => LunaFixture::id('staff', 'Elena Papadaki'),
        'start_utc' => LunaFixture::instant($from), 'end_utc' => LunaFixture::instant($to),
        'block_start_utc' => LunaFixture::instant($from), 'block_end_utc' => LunaFixture::instant($to),
        'price_minor' => 3500, 'blocks' => false,
    ]);
}

function blockOwner(): User
{
    return User::query()->where('email', 'owner@luna.example')->sole();
}

/**
 * The Online booking card's Block or Unblock, as the page sends it.
 *
 * @param  array<string, mixed>  $body
 * @return TestResponse<Response>
 */
function blockSend(TestCase $test, string $method, string $segment, array $body = [], ?User $user = null): TestResponse
{
    $page = tenantUrl('luna', '/admin/customers/'.$segment);

    return $test->actingAs($user ?? blockOwner())->from($page)->json($method, $page.'/block', $body, ['X-Inertia' => 'true']);
}

/**
 * The block of an address as the database holds it.
 *
 * @return array<string, mixed>|null
 */
function blockRowOf(string $email): ?array
{
    $row = DB::table('tenant_luna.customer_profiles')->where('email', $email)->first(['email', 'tags', 'blocked_reason']);

    return $row === null ? null : ['email' => $row->email ?? null, 'tags' => $row->tags ?? null, 'blocked_reason' => $row->blocked_reason ?? null, 'blocked' => DB::table('tenant_luna.customer_profiles')->where('email', $email)->whereNotNull('blocked_at')->exists()];
}

function blockNow(): string
{
    return app(Clock::class)->now()->setTimezone(new DateTimeZone('UTC'))->format(DateTimeImmutable::RFC3339);
}

it('reads an address with no block as none on the page', function () {
    blockBooking('casey@example.com');

    $this->actingAs(blockOwner())->get(tenantUrl('luna', '/admin/customers/casey%40example.com'))
        ->assertInertia(fn (Assert $page) => $page->component('Customer')->where('block', ['blocked_at' => null, 'reason' => null])->where('customer.blocked', false));
});

it('blocks an address with a reason, the row made where none is and stamped from the Clock, and lands back on the page with its flash, the list\'s chip following', function () {
    blockBooking('Casey@Example.com');
    $page = tenantUrl('luna', '/admin/customers/CASEY%40EXAMPLE.COM');

    blockSend($this, 'PUT', 'CASEY%40EXAMPLE.COM', ['reason' => 'Three no-shows in a row'])->assertRedirect($page);

    expect(blockRowOf('casey@example.com'))->toBe(['email' => 'casey@example.com', 'tags' => '{}', 'blocked_reason' => 'Three no-shows in a row', 'blocked' => true]);

    $this->get($page)->assertInertia(fn (Assert $props) => $props
        ->where('flash', ['kind' => 'success', 'message' => adminLine(CustomerBlockController::BLOCKED)])
        ->where('block', ['blocked_at' => blockNow(), 'reason' => 'Three no-shows in a row'])
        ->where('customer.blocked', true));

    $this->get(tenantUrl('luna', '/admin/customers'))->assertInertia(fn (Assert $props) => $props->where('customers.rows.0.blocked', true));
});

it('blocks with no reason as null, an empty reason or one of spaces alike', function () {
    blockBooking('casey@example.com');

    blockSend($this, 'PUT', 'casey%40example.com', ['reason' => null])->assertRedirect();
    expect(blockRowOf('casey@example.com'))->toMatchArray(['blocked_reason' => null, 'blocked' => true]);

    blockSend($this, 'PUT', 'casey%40example.com', ['reason' => '   '])->assertRedirect();
    expect(blockRowOf('casey@example.com'))->toMatchArray(['blocked_reason' => null, 'blocked' => true]);

    blockSend($this, 'PUT', 'casey%40example.com', [])->assertRedirect();
    expect(blockRowOf('casey@example.com'))->toMatchArray(['blocked_reason' => null, 'blocked' => true]);
});

it('keeps the profile\'s fields under a block and the block under a profile save', function () {
    blockBooking('casey@example.com');
    $page = tenantUrl('luna', '/admin/customers/casey%40example.com');
    $this->actingAs(blockOwner())->from($page)->json('PUT', $page.'/profile', ['tags' => 'VIP', 'birthday' => '1988-04-12', 'language' => 'el'], ['X-Inertia' => 'true'])->assertRedirect();

    blockSend($this, 'PUT', 'casey%40example.com', ['reason' => 'Rude'])->assertRedirect();
    $this->actingAs(blockOwner())->from($page)->json('PUT', $page.'/profile', ['tags' => 'VIP, regular', 'birthday' => '1988-04-12', 'language' => 'el'], ['X-Inertia' => 'true'])->assertRedirect();

    expect(blockRowOf('casey@example.com'))->toBe(['email' => 'casey@example.com', 'tags' => '{VIP,regular}', 'blocked_reason' => 'Rude', 'blocked' => true]);
});

it('unblocks an address, clearing the instant and the reason and keeping the row, and answers an address never blocked the same way', function () {
    blockBooking('casey@example.com');
    blockBooking('ada@example.test');
    blockSend($this, 'PUT', 'casey%40example.com', ['reason' => 'Rude'])->assertRedirect();

    blockSend($this, 'DELETE', 'casey%40example.com')->assertRedirect(tenantUrl('luna', '/admin/customers/casey%40example.com'));

    expect(blockRowOf('casey@example.com'))->toBe(['email' => 'casey@example.com', 'tags' => '{}', 'blocked_reason' => null, 'blocked' => false]);

    $this->get(tenantUrl('luna', '/admin/customers/casey%40example.com'))->assertInertia(fn (Assert $props) => $props
        ->where('flash', ['kind' => 'success', 'message' => adminLine(CustomerBlockController::UNBLOCKED)])
        ->where('block', ['blocked_at' => null, 'reason' => null])
        ->where('customer.blocked', false));

    blockSend($this, 'DELETE', 'ada%40example.test')->assertRedirect();
    expect(blockRowOf('ada@example.test'))->toBeNull();
});

it('refuses a reason past 500 characters or one that is no text with its sentence, writing nothing', function (mixed $reason) {
    blockBooking('casey@example.com');

    blockSend($this, 'PUT', 'casey%40example.com', ['reason' => $reason])
        ->assertStatus(400)
        ->assertJsonPath('data.detail', adminChoice(CustomerBlockRequest::REASON, CustomerBlockRequest::MOST));

    expect(DB::table('tenant_luna.customer_profiles')->count())->toBe(0);
})->with([
    'past 500 characters' => [str_repeat('x', 501)],
    'no text' => [42],
]);

it('answers an address no booking was made under with not-found, refuses a staff user, and never gates either way by the subscription', function () {
    blockBooking('casey@example.com');
    addTenantUser('staff@luna.example', 'staff');
    $staff = User::query()->where('email', 'staff@luna.example')->sole();

    blockSend($this, 'PUT', 'nobody%40example.com', ['reason' => null])->assertNotFound();
    blockSend($this, 'DELETE', 'nobody%40example.com')->assertNotFound();
    blockSend($this, 'PUT', 'casey%40example.com', ['reason' => null], $staff)->assertForbidden();

    expect(DB::table('tenant_luna.customer_profiles')->count())->toBe(0);

    DB::table('tenants')->where('slug', 'luna')->update(['subscription_state' => 'canceled']);
    blockSend($this, 'PUT', 'casey%40example.com', ['reason' => null])->assertRedirect();
    blockSend($this, 'DELETE', 'casey%40example.com', [], $staff)->assertForbidden();

    expect(blockRowOf('casey@example.com')['blocked'] ?? null)->toBeTrue();

    blockSend($this, 'DELETE', 'casey%40example.com')->assertRedirect();

    expect(blockRowOf('casey@example.com')['blocked'] ?? null)->toBeFalse();
});

it('words the block\'s two flashes and the reason\'s refusal in the user\'s language: a user at el reads customers.php\'s Greek, a colleague at en its English', function () {
    DB::table('users')->where('email', 'owner@luna.example')->update(['locale' => SiteLanguage::Greek->value]);
    $colleague = User::query()->findOrFail(addTenantUser('manager@luna.example', 'manager'));
    $colleague->forceFill(['locale' => SiteLanguage::English->value])->save();
    blockBooking('casey@example.com');
    $flash = fn (string $key, SiteLanguage $language) => expect(session('flash'))->toBe(['kind' => 'success', 'message' => adminLine($key, [], $language)]);

    // validation.reason keeps the [2,*] form alone, which only a bound above one reaches.
    expect(CustomerBlockRequest::MOST)->toBeGreaterThan(1);

    blockSend($this, 'PUT', 'casey%40example.com', ['reason' => 42])
        ->assertStatus(400)->assertJsonPath('data.detail', adminChoice(CustomerBlockRequest::REASON, CustomerBlockRequest::MOST, [], SiteLanguage::Greek));
    blockSend($this, 'PUT', 'casey%40example.com', ['reason' => 42], $colleague)
        ->assertStatus(400)->assertJsonPath('data.detail', adminChoice(CustomerBlockRequest::REASON, CustomerBlockRequest::MOST));

    blockSend($this, 'PUT', 'casey%40example.com', ['reason' => 'reason-token'])->assertRedirect();
    $flash(CustomerBlockController::BLOCKED, SiteLanguage::Greek);
    blockSend($this, 'DELETE', 'casey%40example.com')->assertRedirect();
    $flash(CustomerBlockController::UNBLOCKED, SiteLanguage::Greek);
    blockSend($this, 'PUT', 'casey%40example.com', [], $colleague)->assertRedirect();
    $flash(CustomerBlockController::BLOCKED, SiteLanguage::English);

    expect(adminLine(CustomerBlockController::BLOCKED, [], SiteLanguage::Greek))->not->toBe(adminLine(CustomerBlockController::BLOCKED));
});
