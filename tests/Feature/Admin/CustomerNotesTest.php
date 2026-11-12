<?php

use App\Application\Clock;
use App\Http\Controllers\Admin\CustomerNoteController;
use App\Http\Requests\CustomerNoteRequest;
use App\Models\User;
use App\Site\SiteLanguage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FixedClock;
use Tests\Support\FixtureDay;
use Tests\Support\LunaFixture;
use Tests\TestCase;

pest()->use(RefreshDatabase::class);

/*
 * A customer's notes: the staff's notes on an address, added from the page's Notes card by POST
 * /admin/customers/{email}/notes and removed by DELETE /admin/customers/{email}/notes/{id}, both
 * under manageBookings and outside the subscription gate, as the profile's save is. A note is
 * stamped with the signed-in user as the trail names an actor and with the name they wrote it
 * under, and the Clock's instant; the page reads them newest first as its notes prop. A blank
 * note or one past 2000 characters is refused with one sentence; an address no booking was made
 * under, and a note of another address, are not-found.
 *
 * The bookings are written straight to the rows at literal dates, so the clock is pinned first.
 */

beforeEach(function () {
    $this->withoutVite();
    seedDemo();
    FixtureDay::pinClock();
});

/**
 * A booking of luna under an address at an Athens start, one Haircut item on Elena.
 */
function notesBooking(string $email, string $start = '2026-11-20 10:00'): void
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

function notesOwner(): User
{
    return User::query()->where('email', 'owner@luna.example')->sole();
}

/**
 * The Notes card's Add note, as the page sends it.
 *
 * @param  array<string, mixed>  $body
 * @return TestResponse<Response>
 */
function notesAdd(TestCase $test, string $segment, array $body, ?User $user = null): TestResponse
{
    $page = tenantUrl('luna', '/admin/customers/'.$segment);

    return $test->actingAs($user ?? notesOwner())->from($page)->json('POST', $page.'/notes', $body, ['X-Inertia' => 'true']);
}

/**
 * A note's Remove, as the page sends it.
 *
 * @return TestResponse<Response>
 */
function notesRemove(TestCase $test, string $segment, int|string $id, ?User $user = null): TestResponse
{
    $page = tenantUrl('luna', '/admin/customers/'.$segment);

    return $test->actingAs($user ?? notesOwner())->from($page)->json('DELETE', $page.'/notes/'.$id, [], ['X-Inertia' => 'true']);
}

/**
 * The notes of luna as the database holds them, oldest first.
 *
 * @return list<array<string, mixed>>
 */
function notesRows(): array
{
    return array_values(array_map(fn (object $row): array => ['email' => $row->email ?? null, 'body' => $row->body ?? null, 'author_actor' => $row->author_actor ?? null, 'author_name' => $row->author_name ?? null], DB::table('tenant_luna.customer_notes')->orderBy('id')->get(['email', 'body', 'author_actor', 'author_name'])->all()));
}

it('adds a note under the address in lower case, stamped with the signed-in user and the Clock, and lands back on the page with its flash', function () {
    notesBooking('Casey@Example.com');
    $owner = notesOwner();
    $page = tenantUrl('luna', '/admin/customers/casey%40example.com');

    notesAdd($this, 'casey%40example.com', ['body' => 'Allergic to ammonia colour, use the gentle line.'])->assertRedirect($page);

    expect(notesRows())->toBe([['email' => 'casey@example.com', 'body' => 'Allergic to ammonia colour, use the gentle line.', 'author_actor' => 'user:'.$owner->id, 'author_name' => $owner->name]]);

    $at = app(Clock::class)->now()->setTimezone(new DateTimeZone('UTC'))->format(DateTimeImmutable::RFC3339);
    $id = DB::table('tenant_luna.customer_notes')->value('id');

    $this->get($page)->assertInertia(fn (Assert $props) => $props->component('Customer')
        ->where('flash', ['kind' => 'success', 'message' => adminLine(CustomerNoteController::ADDED)])
        ->where('notes', [['id' => $id, 'body' => 'Allergic to ammonia colour, use the gentle line.', 'author' => $owner->name, 'created_at' => $at]]));
});

it('reads the notes newest first, only the address\'s own, and none where none is kept', function () {
    notesBooking('casey@example.com');
    notesBooking('ada@example.test');
    $clock = FixtureDay::pinClock();
    notesAdd($this, 'casey%40example.com', ['body' => 'First'])->assertRedirect();
    app()->instance(Clock::class, FixedClock::at($clock->now()->add(new DateInterval('PT1H'))->format('Y-m-d H:i:s')));
    notesAdd($this, 'casey%40example.com', ['body' => 'Second'])->assertRedirect();
    notesAdd($this, 'ada%40example.test', ['body' => 'Ada\'s'])->assertRedirect();

    $this->get(tenantUrl('luna', '/admin/customers/casey%40example.com'))
        ->assertInertia(fn (Assert $props) => $props->where('notes.0.body', 'Second')->where('notes.1.body', 'First')->count('notes', 2));

    DB::table('tenant_luna.customer_notes')->delete();

    $this->get(tenantUrl('luna', '/admin/customers/casey%40example.com'))->assertInertia(fn (Assert $props) => $props->where('notes', []));
});

it('refuses {what} with the one sentence, writing nothing', function (string $what, mixed $body) {
    notesBooking('casey@example.com');

    notesAdd($this, 'casey%40example.com', ['body' => $body])
        ->assertStatus(400)
        ->assertJsonPath('data.detail', adminChoice(CustomerNoteRequest::BODY, CustomerNoteRequest::MOST));

    expect(notesRows())->toBe([]);
})->with([
    ['an empty note', ''],
    ['a note of spaces alone', "   \n  "],
    ['a note past 2000 characters', str_repeat('x', 2001)],
    ['a note that is no text', 42],
]);

it('admits a note of 2000 characters, counted as characters and not bytes', function () {
    notesBooking('casey@example.com');

    notesAdd($this, 'casey%40example.com', ['body' => str_repeat('e', 1999).'e'])->assertRedirect();
    notesAdd($this, 'casey%40example.com', ['body' => str_repeat("\u{03b1}", 2000)])->assertRedirect();

    expect(DB::table('tenant_luna.customer_notes')->count())->toBe(2);
});

it('removes a note of the address with its flash, and answers a note of another address, an unknown one and an address no booking was made under with not-found', function () {
    notesBooking('casey@example.com');
    notesBooking('ada@example.test');
    notesAdd($this, 'casey%40example.com', ['body' => 'Casey\'s'])->assertRedirect();
    notesAdd($this, 'ada%40example.test', ['body' => 'Ada\'s'])->assertRedirect();
    $casey = DB::table('tenant_luna.customer_notes')->where('email', 'casey@example.com')->value('id');
    $ada = DB::table('tenant_luna.customer_notes')->where('email', 'ada@example.test')->value('id');
    assert(is_int($casey) && is_int($ada));

    notesRemove($this, 'casey%40example.com', $ada)->assertNotFound();
    notesRemove($this, 'casey%40example.com', 999999)->assertNotFound();
    notesRemove($this, 'nobody%40example.com', $casey)->assertNotFound();
    notesAdd($this, 'nobody%40example.com', ['body' => 'Nobody'])->assertNotFound();

    expect(DB::table('tenant_luna.customer_notes')->count())->toBe(2);

    notesRemove($this, 'CASEY%40EXAMPLE.COM', $casey)->assertRedirect(tenantUrl('luna', '/admin/customers/CASEY%40EXAMPLE.COM'));

    expect(DB::table('tenant_luna.customer_notes')->pluck('body')->all())->toBe(['Ada\'s']);

    $this->get(tenantUrl('luna', '/admin/customers/casey%40example.com'))
        ->assertInertia(fn (Assert $props) => $props->where('flash', ['kind' => 'success', 'message' => adminLine(CustomerNoteController::REMOVED)])->where('notes', []));
});

it('refuses a staff user both ways and never gates either by the subscription', function () {
    notesBooking('casey@example.com');
    addTenantUser('staff@luna.example', 'staff');
    $staff = User::query()->where('email', 'staff@luna.example')->sole();

    notesAdd($this, 'casey%40example.com', ['body' => 'Staff'], $staff)->assertForbidden();
    expect(DB::table('tenant_luna.customer_notes')->count())->toBe(0);

    DB::table('tenants')->where('slug', 'luna')->update(['subscription_state' => 'canceled']);
    notesAdd($this, 'casey%40example.com', ['body' => 'Lapsed'])->assertRedirect();
    $id = DB::table('tenant_luna.customer_notes')->value('id');
    assert(is_int($id));

    notesRemove($this, 'casey%40example.com', $id, $staff)->assertForbidden();
    notesRemove($this, 'casey%40example.com', $id)->assertRedirect();

    expect(DB::table('tenant_luna.customer_notes')->count())->toBe(0);
});

it('withholds the notes from no viewer the page reaches, every one holding manageBookings', function () {
    notesBooking('casey@example.com');
    addTenantUser('manager@luna.example', 'manager');
    notesAdd($this, 'casey%40example.com', ['body' => 'Owner\'s'])->assertRedirect();

    $this->actingAs(User::query()->where('email', 'manager@luna.example')->sole())->get(tenantUrl('luna', '/admin/customers/casey%40example.com'))
        ->assertInertia(fn (Assert $props) => $props->where('notes.0.body', 'Owner\'s'));
});

it('words the two flashes and the note\'s refusal in the user\'s language: a user at el reads customers.php\'s Greek, a colleague at en its English', function () {
    DB::table('users')->where('email', 'owner@luna.example')->update(['locale' => SiteLanguage::Greek->value]);
    $colleague = User::query()->findOrFail(addTenantUser('manager@luna.example', 'manager'));
    $colleague->forceFill(['locale' => SiteLanguage::English->value])->save();
    notesBooking('casey@example.com');
    $flash = fn (string $key, SiteLanguage $language) => expect(session('flash'))->toBe(['kind' => 'success', 'message' => adminLine($key, [], $language)]);

    // validation.note keeps the [2,*] form alone, which only a bound above one reaches.
    expect(CustomerNoteRequest::MOST)->toBeGreaterThan(1);

    notesAdd($this, 'casey%40example.com', ['body' => ''])
        ->assertStatus(400)->assertJsonPath('data.detail', adminChoice(CustomerNoteRequest::BODY, CustomerNoteRequest::MOST, [], SiteLanguage::Greek));
    notesAdd($this, 'casey%40example.com', ['body' => ''], $colleague)
        ->assertStatus(400)->assertJsonPath('data.detail', adminChoice(CustomerNoteRequest::BODY, CustomerNoteRequest::MOST));

    notesAdd($this, 'casey%40example.com', ['body' => 'note-token'])->assertRedirect();
    $flash(CustomerNoteController::ADDED, SiteLanguage::Greek);
    $id = DB::table('tenant_luna.customer_notes')->value('id');
    assert(is_int($id));
    notesRemove($this, 'casey%40example.com', $id)->assertRedirect();
    $flash(CustomerNoteController::REMOVED, SiteLanguage::Greek);
    notesAdd($this, 'casey%40example.com', ['body' => 'note-token'], $colleague)->assertRedirect();
    $flash(CustomerNoteController::ADDED, SiteLanguage::English);

    expect(adminLine(CustomerNoteController::ADDED, [], SiteLanguage::Greek))->not->toBe(adminLine(CustomerNoteController::ADDED));
});
