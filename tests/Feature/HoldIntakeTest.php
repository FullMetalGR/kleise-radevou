<?php

use App\Application\Clock;
use App\Application\Dto\Intake;
use App\Domain\Enum\BookingChannel;
use App\Http\Requests\NewHoldRequest;
use App\Models\User;
use App\Tenancy\Slug;
use App\Tenancy\TenantUrls;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FixedClock;
use Tests\Support\FixtureDay;
use Tests\Support\LunaFixture;
use Tests\TestCase;

pest()->use(DatabaseTruncation::class);

/*
 * What a booking carries from the surface it came through, over luna's real rows and the real
 * routes: the source the server stamps from the request's origin, the channel a hosted-page link
 * was tagged with, and the customer's note in booking_meta; the admin's create stamped admin;
 * and where each of them reaches and where none of them does, the trail and the guest's answer
 * among the second.
 *
 * Not wrapped in a transaction: the hold enters the transaction runner, which refuses to open
 * inside another. luna is in Europe/Athens; FixtureDay's Tuesday is a day Elena works, and the
 * clock stands on the Monday before.
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

/** luna's own origin, as the server compares a request's against it. */
function intakeOrigin(): string
{
    return app(TenantUrls::class)->origin(Slug::of('luna'));
}

/**
 * A hold of Haircut on Elena at the fixture's Tuesday, as the booking app sends it, with the
 * body's extra keys and the request's headers.
 *
 * @param  array<mixed>  $extra
 * @param  array<string, string>  $headers
 * @return TestResponse<Response>
 */
function intakeHold(TestCase $test, array $extra = [], array $headers = [], string $service = 'Haircut'): TestResponse
{
    [$url, $body] = seamHold($service, FixtureDay::START);

    return $test->withHeaders($headers)->postJson($url, [...$body, ...$extra]);
}

/**
 * The one booking row luna holds, as the map of columns it is.
 *
 * @return array<mixed>
 */
function intakeRow(): array
{
    $rows = DB::table('tenant_luna.bookings')->get()->all();

    expect($rows)->toHaveCount(1);

    return get_object_vars($rows[0] ?? new stdClass);
}

/**
 * Every booking_meta row of luna, as key and value pairs.
 *
 * @return list<array{string, string}>
 */
function intakeMeta(): array
{
    $meta = [];

    foreach (DB::table('tenant_luna.booking_meta')->orderBy('key')->get()->all() as $row) {
        assert(is_string($row->key) && is_string($row->value));

        $meta[] = [$row->key, $row->value];
    }

    return $meta;
}

it('stamps hosted on a hold whose Origin is the tenant\'s own origin', function () {
    intakeHold($this, headers: ['Origin' => intakeOrigin()])->assertCreated();

    expect(intakeRow()['source'])->toBe('hosted');
});

it('stamps embed on a hold from another origin, the tenant\'s own site', function () {
    intakeHold($this, headers: ['Origin' => 'https://salon-x.example'])->assertCreated();

    expect(intakeRow()['source'])->toBe('embed');
});

it('stamps embed on a hold carrying neither an Origin nor a Referer', function () {
    intakeHold($this)->assertCreated();

    expect(intakeRow()['source'])->toBe('embed');
});

it('reads the Referer\'s origin where no Origin is sent: {referer} is {source}', function (string $referer, string $source) {
    intakeHold($this, headers: ['Referer' => str_replace('{origin}', intakeOrigin(), $referer)])->assertCreated();

    expect(intakeRow()['source'])->toBe($source);
})->with([
    ['{origin}/?service=1&ch=qr', 'hosted'],
    ['{origin}/', 'hosted'],
    ['https://salon-x.example/book', 'embed'],
    ['not a url', 'embed'],
]);

it('lets an Origin decide over a Referer that disagrees, and calls an opaque origin not the tenant\'s', function (string $origin) {
    intakeHold($this, headers: ['Origin' => $origin, 'Referer' => intakeOrigin().'/'])->assertCreated();

    expect(intakeRow()['source'])->toBe('embed');
})->with(['https://salon-x.example', 'null']);

it('stamps admin on the admin\'s manual booking', function () {
    $owner = User::query()->where('email', 'owner@luna.example')->sole();

    $this->actingAs($owner)->from(tenantUrl('luna', '/admin/bookings'))->postJson(tenantUrl('luna', '/admin/bookings'), [
        'customer_name' => LunaFixture::CUSTOMER_NAME,
        'customer_email' => LunaFixture::CUSTOMER_EMAIL,
        'items' => [['service_id' => LunaFixture::id('services', 'Haircut'), 'staff_id' => LunaFixture::id('staff', 'Elena Papadaki')]],
        'start' => LunaFixture::at(FixtureDay::START)->format(DateTimeImmutable::RFC3339),
        // The wizard sends neither; a body that did is not the hosted page's and is not read.
        'channel' => 'instagram',
        'note' => 'From the admin.',
    ])->assertRedirect();

    expect(intakeRow())->toMatchArray(['source' => 'admin', 'channel' => null])
        ->and(intakeMeta())->toBe([]);
});

it('stores the channel {channel} from the hosted page', function (BookingChannel $channel) {
    intakeHold($this, ['channel' => $channel->value], ['Origin' => intakeOrigin()])->assertCreated();

    expect(intakeRow())->toMatchArray(['source' => 'hosted', 'channel' => $channel->value]);
})->with(BookingChannel::cases());

it('stores no channel for {value}, and refuses nothing', function (mixed $value) {
    intakeHold($this, ['channel' => $value], ['Origin' => intakeOrigin()])->assertCreated();

    expect(intakeRow())->toMatchArray(['source' => 'hosted', 'channel' => null]);
})->with([
    'a tag outside the five' => ['tiktok'],
    'a capitalised tag' => ['Instagram'],
    'an empty string' => [''],
    'a number' => [7],
    'a list' => [['instagram']],
    'null' => [null],
]);

it('stores no channel on a hold from the embed, the tag being read on the hosted page alone', function () {
    intakeHold($this, ['channel' => 'instagram'], ['Origin' => 'https://salon-x.example'])->assertCreated();

    expect(intakeRow())->toMatchArray(['source' => 'embed', 'channel' => null]);
});

it('stores the note trimmed under booking_meta\'s key note, from {surface}', function (string $surface) {
    intakeHold($this, ['note' => "  A quiet chair, please.\nI am left-handed.  "], $surface === 'hosted' ? ['Origin' => intakeOrigin()] : [])->assertCreated();

    expect(intakeRow()['source'])->toBe($surface)
        ->and(intakeMeta())->toBe([['note', "A quiet chair, please.\nI am left-handed."]]);
})->with(['hosted', 'embed']);

it('stores a note of exactly five hundred characters', function () {
    intakeHold($this, ['note' => str_repeat('a', 499).'e'])->assertCreated();

    expect(intakeMeta())->toBe([['note', str_repeat('a', 499).'e']]);
});

it('stores no row for a blank note or none', function (array $extra) {
    intakeHold($this, $extra)->assertCreated();

    expect(intakeMeta())->toBe([]);
})->with([
    'blank' => [['note' => "   \n "]],
    'empty' => [['note' => '']],
    'null' => [['note' => null]],
    'absent' => [[]],
]);

it('refuses a note over five hundred characters with its sentence and writes nothing', function () {
    $response = intakeHold($this, ['note' => str_repeat('e', 501)]);

    $response->assertStatus(400)->assertJson(['code' => 'invalid_request', 'data' => ['detail' => apiChoice(NewHoldRequest::NOTE_TOO_LONG, Intake::NOTE_MAX)]]);

    expect(apiChoice(NewHoldRequest::NOTE_TOO_LONG, Intake::NOTE_MAX))->toContain((string) Intake::NOTE_MAX)
        ->and(DB::table('tenant_luna.bookings')->count())->toBe(0)
        ->and(intakeMeta())->toBe([]);
});

it('refuses a note that is not text with its sentence', function () {
    intakeHold($this, ['note' => ['a', 'b']])->assertStatus(400)->assertJson(['code' => 'invalid_request', 'data' => ['detail' => apiSentence(NewHoldRequest::NOTE_NOT_TEXT)]]);

    expect(DB::table('tenant_luna.bookings')->count())->toBe(0);
});

it('carries none of the intake in the trail or in the guest\'s answers', function () {
    $note = 'Allergic to lavender.';
    $response = intakeHold($this, ['note' => $note, 'channel' => 'instagram'], ['Origin' => intakeOrigin()])->assertCreated();
    $uuid = $response->json('uuid');
    $token = $response->json('manage_token');
    assert(is_string($uuid) && is_string($token));

    $read = $this->getJson(tenantUrl('luna', '/api/v1/bookings/'.$uuid.'?token='.$token))->assertOk();
    $trail = DB::table('tenant_luna.audit_log')->pluck('payload')->all();

    expect(intakeRow())->toMatchArray(['source' => 'hosted', 'channel' => 'instagram'])
        ->and($trail)->not->toBe([])
        ->and(implode("\n", array_filter($trail, is_string(...))))
        ->not->toContain('lavender')->not->toContain('instagram')->not->toContain('hosted')->not->toContain('note')->not->toContain('source')->not->toContain('channel');

    foreach ([$response, $read] as $answer) {
        expect($answer->getContent())->not->toContain('lavender')->not->toContain('instagram')->not->toContain('hosted')
            ->and($answer->json())->not->toHaveKeys(['source', 'channel', 'note']);
    }
});

it('shows the source, the channel and the note in the dialog\'s detail to every viewer of it, the note beside the booking and never inside it', function () {
    $response = intakeHold($this, ['note' => 'Allergic to lavender.', 'channel' => 'qr'], ['Origin' => intakeOrigin()])->assertCreated();
    $uuid = $response->json('uuid');
    assert(is_string($uuid));
    // Elena's own user, a staff user without viewBookings or manageBookings, who opens the
    // dialog over the Calendar pinned to her row: the booking is on it.
    $elena = User::query()->findOrFail(addTenantUser('elena@luna.example', 'staff', 'Elena Papadaki'));
    $found = fn (Assert $page) => $page->where('detail.state', 'found')
        ->where('detail.booking.source', 'hosted')
        ->where('detail.booking.channel', 'qr')
        ->missing('detail.booking.note')
        ->where('detail.note', 'Allergic to lavender.');

    $this->actingAs(User::query()->where('email', 'owner@luna.example')->sole())->get(tenantUrl('luna', '/admin/bookings/'.$uuid))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $found($page->component('Bookings')));
    $this->actingAs(User::query()->where('email', 'owner@luna.example')->sole())->get(tenantUrl('luna', '/admin/calendar?booking='.$uuid))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $found($page->component('Calendar')));
    $this->actingAs($elena)->get(tenantUrl('luna', '/admin/calendar?booking='.$uuid))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $found($page->missing('detail.booking.customer_email')));
});

it('answers a null note and a null channel in the detail of a booking with neither', function () {
    $response = intakeHold($this)->assertCreated();
    $uuid = $response->json('uuid');
    assert(is_string($uuid));

    $this->actingAs(User::query()->where('email', 'owner@luna.example')->sole())->get(tenantUrl('luna', '/admin/bookings/'.$uuid))
        ->assertInertia(fn (Assert $page) => $page->where('detail.booking.source', 'embed')
            ->where('detail.booking.channel', null)
            ->where('detail.note', null));
});
