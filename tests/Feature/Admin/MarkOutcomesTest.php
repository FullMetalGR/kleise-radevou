<?php

use App\Application\Checkpoint;
use App\Application\Clock;
use App\Application\GuardedWrite;
use App\Application\Notification\MessageKey;
use App\Http\Controllers\Admin\MarkOutcomesController;
use App\Http\Requests\MarkOutcomesRequest;
use App\Infrastructure\Db\Busy;
use App\Models\User;
use App\Site\SiteLanguage;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FixedClock;
use Tests\Support\LunaFixture;
use Tests\TestCase;

/*
 * Marking from Home: a selection of bookings marked completed or a no-show in one post, each
 * through the same transition as the booking dialog's two buttons, in the order sent, the ones
 * that do not go left as they are while the rest go on, and one flash that counts both; and a
 * row's own button, the dialog's route, which answers back to Home where the row is gone.
 *
 * The marks enter the transaction runner, so this file truncates rather than wrapping each test
 * in a transaction, and seeds the demo afresh. luna is in Europe/Athens, and the Clock port stands
 * at 11:00 there on Tuesday 2026-11-03, after the morning's bookings have begun and ended.
 */

pest()->use(DatabaseTruncation::class);

beforeEach(function () {
    $this->withoutVite();
    dropDemoSchemas();
    seedDemo();
    app()->instance(Clock::class, FixedClock::at('2026-11-03 09:00:00'));
    seamTransport()->flush();
});

afterEach(function () {
    Checkpoint::observe(null);
    DB::purge('marks_probe');
    dropDemoSchemas();
    DB::table('users')->delete();
    DB::table('tenants')->delete();
});

function marksOwner(): User
{
    return User::query()->where('email', 'owner@luna.example')->sole();
}

/** A confirmed booking on Elena's row at the Athens start given, half an hour long. */
function marksBooking(string $start, string $status = 'confirmed'): string
{
    return LunaFixture::booking($status, start: $start, minutes: 30);
}

/**
 * The bulk mark posted from Home by the user given.
 *
 * @param  array<string, mixed>  $body
 * @return TestResponse<Response>
 */
function marksPost(TestCase $test, array $body, ?User $user = null): TestResponse
{
    return $test->actingAs($user ?? marksOwner())->from(tenantUrl('luna', '/admin'))->post(tenantUrl('luna', '/admin/bookings/mark'), $body);
}

/**
 * The flash a bulk mark answered with, having sent the user back to Home.
 *
 * @param  TestResponse<Response>  $response
 * @return array{kind: string, message: string}
 */
function marksFlash(TestResponse $response): array
{
    $response->assertRedirect(tenantUrl('luna', '/admin'));
    $flash = session('flash');
    assert(is_array($flash) && is_string($flash['kind'] ?? null) && is_string($flash['message'] ?? null));

    return ['kind' => $flash['kind'], 'message' => $flash['message']];
}

function marksStatus(string $uuid): mixed
{
    return LunaFixture::row($uuid)['status'];
}

/**
 * Each trail row as the booking it names and what it records.
 *
 * @return list<array{mixed, mixed, mixed}>
 */
function marksTrail(): array
{
    return array_map(fn (array $row): array => [$row['uuid'], $row['action'], $row['actor']], LunaFixture::trail());
}

it('marks every booking sent completed, in the order sent, each its own trail row by the user, and thanks each customer once', function () {
    $early = marksBooking('2026-11-03 08:00');
    $late = marksBooking('2026-11-03 09:00');
    $actor = 'user:'.marksOwner()->id;

    $flash = marksFlash(marksPost($this, ['outcome' => 'completed', 'bookings' => [$late, $early]]));

    expect($flash)->toBe(['kind' => 'success', 'message' => '2 bookings marked completed.'])
        ->and([marksStatus($early), marksStatus($late)])->toBe(['completed', 'completed'])
        ->and(marksTrail())->toBe([[$late, 'completed', $actor], [$early, 'completed', $actor]])
        ->and(seamAddresses())->toBe([LunaFixture::CUSTOMER_EMAIL, LunaFixture::CUSTOMER_EMAIL]);
});

it('marks a no-show with the no-show\'s own flash and sends nobody anything', function () {
    $one = marksBooking('2026-11-03 08:00');
    $two = marksBooking('2026-11-03 09:00');
    $three = marksBooking('2026-11-03 10:00');

    expect(marksFlash(marksPost($this, ['outcome' => 'no_show', 'bookings' => [$one]])))->toBe(['kind' => 'success', 'message' => '1 booking marked as a no-show.'])
        ->and(marksFlash(marksPost($this, ['outcome' => 'no_show', 'bookings' => [$two, $three]])))->toBe(['kind' => 'success', 'message' => '2 bookings marked as no-shows.'])
        ->and(array_map(marksStatus(...), [$one, $two, $three]))->toBe(['no_show', 'no_show', 'no_show'])
        ->and(array_column(LunaFixture::trail(), 'action'))->toBe(['no_show', 'no_show', 'no_show'])
        ->and(seamSent())->toBe([]);
});

it('marks a single booking completed with the singular flash, and sends no thank-you where the tenant has it switched off', function () {
    seamSwitchOff([MessageKey::ThankYou->value]);
    $one = marksBooking('2026-11-03 08:00');

    expect(marksFlash(marksPost($this, ['outcome' => 'completed', 'bookings' => [$one]])))->toBe(['kind' => 'success', 'message' => '1 booking marked completed.'])
        ->and(marksStatus($one))->toBe('completed')
        ->and(seamSent())->toBe([]);
});

it('leaves a booking already marked, one not yet begun and one nobody has, marks the rest, and says how many went and how many did not', function () {
    $marked = marksBooking('2026-11-03 07:00', 'completed');
    $ahead = marksBooking('2026-11-03 14:00');
    $unknown = (string) Str::uuid();
    $done = marksBooking('2026-11-03 08:00');

    $flash = marksFlash(marksPost($this, ['outcome' => 'completed', 'bookings' => [$marked, $ahead, $unknown, $done]]));

    expect($flash)->toBe(['kind' => 'error', 'message' => '1 booking marked completed. 3 were not marked: 3 changed meanwhile.'])
        ->and([marksStatus($marked), marksStatus($ahead), marksStatus($done)])->toBe(['completed', 'confirmed', 'completed'])
        ->and(array_column(LunaFixture::trail(), 'uuid'))->toBe([$done])
        ->and(seamAddresses())->toBe([LunaFixture::CUSTOMER_EMAIL]);
});

it('says no booking was marked when not one went', function () {
    $ahead = marksBooking('2026-11-03 14:00');

    expect(marksFlash(marksPost($this, ['outcome' => 'no_show', 'bookings' => [$ahead]])))->toBe(['kind' => 'error', 'message' => 'No booking was marked. 1 was not marked: 1 changed meanwhile.'])
        ->and(marksStatus($ahead))->toBe('confirmed')
        ->and(LunaFixture::trail())->toBe([]);
});

it('counts each reason a booking was left in one order, a broken write handed to the handler, and marks the one that could go', function () {
    Exceptions::fake();
    $log = runnerLog();
    $broken = marksBooking('2026-11-03 07:00');
    $held = marksBooking('2026-11-03 08:00');
    $done = marksBooking('2026-11-03 09:00');
    $probe = LunaFixture::probe('marks_probe');
    $writes = 0;

    // The first booking's write breaks under its locks, as none of the failures a write names.
    Checkpoint::observe(function (string $name) use (&$writes): void {
        if ($name === GuardedWrite::AFTER_LOCK && ++$writes === 1) {
            throw new RuntimeException('The first write broke.');
        }
    });
    // Somebody else's write holds the second booking's row past the lock timeout.
    $probe->beginTransaction();
    $probe->select('select id from tenant_luna.bookings where uuid = ?::uuid for update', [$held]);

    try {
        $response = marksPost($this, ['outcome' => 'completed', 'bookings' => [$broken, $held, (string) Str::uuid(), $done]]);
    } finally {
        $probe->rollBack();
    }

    expect(marksFlash($response))->toBe(['kind' => 'error', 'message' => '1 booking marked completed. 3 were not marked: 1 changed meanwhile, 1 busy, try again, 1 could not be marked.'])
        ->and([marksStatus($broken), marksStatus($held), marksStatus($done)])->toBe(['confirmed', 'confirmed', 'completed'])
        ->and(array_column(LunaFixture::trail(), 'uuid'))->toBe([$done])
        ->and(array_map(fn ($line): mixed => $line->context['failure'] ?? null, $log->getRecords()))->toBe([Busy::class]);

    Exceptions::assertReportedCount(1);
    Exceptions::assertReported(fn (RuntimeException $failure): bool => $failure->getMessage() === 'The first write broke.');
});

it('words the flash in the user\'s language: a user at el reads each sentence and reason in Greek', function () {
    DB::table('users')->where('email', 'owner@luna.example')->update(['locale' => 'el']);
    $ahead = marksBooking('2026-11-03 14:00');
    $done = marksBooking('2026-11-03 08:00');
    $missed = marksBooking('2026-11-03 09:00');
    $greek = fn (string $key): string => adminChoice($key, 1, [], SiteLanguage::Greek);
    $left = adminChoice(MarkOutcomesController::LEFT, 1, ['reasons' => $greek(MarkOutcomesController::REASONS['changed'])], SiteLanguage::Greek);

    expect(marksFlash(marksPost($this, ['outcome' => 'completed', 'bookings' => [$ahead, $done]])))->toBe(['kind' => 'error', 'message' => $greek(MarkOutcomesController::COMPLETED).' '.$left])
        ->and(marksFlash(marksPost($this, ['outcome' => 'no_show', 'bookings' => [$missed]])))->toBe(['kind' => 'success', 'message' => $greek(MarkOutcomesController::NO_SHOW)])
        ->and(marksFlash(marksPost($this, ['outcome' => 'no_show', 'bookings' => [$ahead]])))->toBe(['kind' => 'error', 'message' => adminLine(MarkOutcomesController::NONE, [], SiteLanguage::Greek).' '.$left])
        ->and(adminLine(MarkOutcomesController::NONE, [], SiteLanguage::Greek))->not->toBe(adminLine(MarkOutcomesController::NONE));
});

it('refuses a form that names no outcome it knows or no bookings to mark, with the field and the sentence, and marks nothing', function (array $body, string $field, string $key) {
    /** @var array<string, mixed> $body */
    $uuid = marksBooking('2026-11-03 08:00');
    // A dataset is written before any booking is, so it says BOOKING where the booking's uuid goes.
    $named = array_map(fn (mixed $value): mixed => is_array($value) ? array_map(fn (mixed $one): mixed => $one === 'BOOKING' ? $uuid : $one, $value) : $value, $body);

    marksPost($this, $named)
        ->assertStatus(400)
        ->assertJsonPath('data.field', $field)
        ->assertJsonPath('data.detail', adminLine($key));

    expect(marksStatus($uuid))->toBe('confirmed')
        ->and(LunaFixture::trail())->toBe([]);
})->with([
    'no outcome' => [['bookings' => ['BOOKING']], 'outcome', MarkOutcomesRequest::OUTCOME],
    'an outcome that is no outcome' => [['outcome' => 'cancelled', 'bookings' => ['BOOKING']], 'outcome', MarkOutcomesRequest::OUTCOME],
    'no bookings' => [['outcome' => 'completed'], 'bookings', MarkOutcomesRequest::BOOKINGS],
    'an empty list' => [['outcome' => 'completed', 'bookings' => []], 'bookings', MarkOutcomesRequest::BOOKINGS],
    'bookings keyed by name' => [['outcome' => 'completed', 'bookings' => ['first' => 'BOOKING']], 'bookings', MarkOutcomesRequest::BOOKINGS],
    'a booking that is no uuid' => [['outcome' => 'completed', 'bookings' => ['BOOKING', 'not-a-uuid']], 'bookings.1', MarkOutcomesRequest::BOOKINGS],
    'a booking sent twice' => [['outcome' => 'completed', 'bookings' => ['BOOKING', 'BOOKING']], 'bookings.0', MarkOutcomesRequest::BOOKINGS],
]);

it('refuses more bookings than Home lists at once', function () {
    $uuid = marksBooking('2026-11-03 08:00');
    $many = [$uuid, ...array_map(fn (): string => (string) Str::uuid(), range(1, 100))];

    marksPost($this, ['outcome' => 'completed', 'bookings' => $many])
        ->assertStatus(400)
        ->assertJsonPath('data.field', 'bookings');

    expect(marksStatus($uuid))->toBe('confirmed');
});

it('keeps a staff user off the bulk mark, even for a booking on their own row', function () {
    $elena = User::query()->findOrFail(addTenantUser('elena@luna.example', 'staff', staff: 'Elena Papadaki'));
    $hers = marksBooking('2026-11-03 08:00');

    marksPost($this, ['outcome' => 'completed', 'bookings' => [$hers]], $elena)->assertForbidden();

    expect(marksStatus($hers))->toBe('confirmed')
        ->and(LunaFixture::trail())->toBe([]);
});

it('lets a manager mark from Home, the manager holding manageBookings as the owner does', function () {
    $manager = User::query()->findOrFail(addTenantUser('marina@luna.example', 'manager'));
    $one = marksBooking('2026-11-03 08:00');

    expect(marksFlash(marksPost($this, ['outcome' => 'no_show', 'bookings' => [$one]], $manager))['kind'])->toBe('success')
        ->and(marksTrail())->toBe([[$one, 'no_show', 'user:'.$manager->id]]);
});

it('takes a row\'s own Completed through the dialog\'s route back to Home, where the row has left the card', function () {
    $one = marksBooking('2026-11-03 08:00');
    $two = marksBooking('2026-11-03 09:00');
    $rows = 'to_mark.days.0.bookings';

    $this->actingAs(marksOwner())->get(tenantUrl('luna', '/admin'))
        ->assertInertia(fn (Assert $page) => $page->has($rows, 2)->where($rows.'.0.uuid', $one)->where($rows.'.1.uuid', $two));

    $this->actingAs(marksOwner())->from(tenantUrl('luna', '/admin'))
        ->post(tenantUrl('luna', '/admin/bookings/'.$one.'/complete'))
        ->assertRedirect(tenantUrl('luna', '/admin'));

    expect(marksStatus($one))->toBe('completed');
    $this->actingAs(marksOwner())->get(tenantUrl('luna', '/admin'))
        ->assertInertia(fn (Assert $page) => $page->where('to_mark.count', 1)->where('to_mark.days.0.today', true)->has($rows, 1)->where($rows.'.0.uuid', $two));
});
