<?php

use App\Application\Clock;
use App\Http\BookingActions;
use App\Http\Controllers\Admin\BookingActionController;
use App\Http\ErrorEnvelope;
use App\Http\Principal;
use App\Http\Requests\RejectRequest;
use App\Infrastructure\Db\BookingRecords;
use App\Models\User;
use App\Site\SiteLanguage;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FixedClock;
use Tests\Support\FixtureDay;
use Tests\Support\LunaFixture;
use Tests\TestCase;

/*
 * The booking dialog's five actions and the list of the actions allowed now, under /admin: each
 * posts to its use case as the signed-in user and answers a redirect back with a flash, approve
 * and reject run the staff scope on every press, and none of them is gated by the subscription.
 *
 * The use cases enter the transaction runner, so this file truncates rather than wrapping each
 * test in a transaction, and seeds the demo afresh.
 *
 * luna is in Europe/Athens. The fixture's bookings start on Tuesday 2026-11-03, in the future of
 * the database's clock, and the Clock port is fixed an hour after that start where a test needs
 * an appointment that has begun.
 */

pest()->use(DatabaseTruncation::class);

beforeEach(function () {
    $this->withoutVite();
    dropDemoSchemas();
    seedDemo();
});

afterEach(function () {
    dropDemoSchemas();
    DB::table('users')->delete();
    DB::table('tenants')->delete();
});

function actionsOwner(): User
{
    return User::query()->where('email', 'owner@luna.example')->sole();
}

function actionsUser(int $id): User
{
    return User::query()->findOrFail($id);
}

/** A request lodged with the business on Elena's row, its window still open. */
function actionsRequest(string $expiry = 'future', string $start = FixtureDay::START): string
{
    return LunaFixture::booking('awaiting_approval', 'approval', $expiry, start: $start, service: 'Bridal trial', requiresApproval: true);
}

/** The appointment has begun: the Clock port an hour past the fixture's start. */
function actionsAfterTheStart(): void
{
    app()->instance(Clock::class, FixedClock::at('2026-11-03 09:00:00'));
}

/**
 * @param  array<string, string>  $body
 * @return TestResponse<Response>
 */
function actionsPress(TestCase $test, User $user, string $uuid, string $action, array $body = []): TestResponse
{
    $dialog = tenantUrl('luna', '/admin/bookings/'.$uuid.'?status=awaiting_approval');

    return $test->actingAs($user)->from($dialog)->post(tenantUrl('luna', '/admin/bookings/'.$uuid.'/'.$action), $body);
}

/**
 * The flash a press answered with, after asserting it went back to the dialog that asked.
 *
 * @param  TestResponse<Response>  $response
 * @return array{kind: string, message: string}
 */
function actionsFlash(TestResponse $response, string $uuid): array
{
    $response->assertRedirect(tenantUrl('luna', '/admin/bookings/'.$uuid.'?status=awaiting_approval'));
    $flash = session('flash');
    assert(is_array($flash) && is_string($flash['kind'] ?? null) && is_string($flash['message'] ?? null));

    return ['kind' => $flash['kind'], 'message' => $flash['message']];
}

/**
 * The actions the dialog's detail carries for a viewer.
 *
 * @return list<string>
 */
function actionsShown(TestCase $test, User $user, string $uuid): array
{
    $actions = data_get($test->actingAs($user)->get(tenantUrl('luna', '/admin/bookings/'.$uuid))->assertOk()->viewData('page'), 'props.detail.actions');
    assert(is_array($actions));

    return array_values(array_filter($actions, is_string(...)));
}

it('approves a live request for the owner, back to the dialog that asked with a success flash, one trail row naming the user', function () {
    $uuid = actionsRequest();

    $flash = actionsFlash(actionsPress($this, actionsOwner(), $uuid, 'approve'), $uuid);

    expect($flash)->toBe(['kind' => 'success', 'message' => __(BookingActionController::APPROVED)])
        ->and(LunaFixture::row($uuid)['status'])->toBe('confirmed')
        ->and(array_map(fn (array $row): mixed => $row['actor'], LunaFixture::trail()))->toBe(['user:'.actionsOwner()->id]);
});

it('rejects with the reason given, and rejects with an empty box too, because an empty reason never blocks a rejection', function () {
    $withReason = actionsRequest();
    $withNone = actionsRequest(start: '2026-11-03 11:00');

    $first = actionsFlash(actionsPress($this, actionsOwner(), $withReason, 'reject', ['reason' => 'Fully booked that week']), $withReason);
    $second = actionsFlash(actionsPress($this, actionsOwner(), $withNone, 'reject', ['reason' => '']), $withNone);

    expect($first)->toBe(['kind' => 'success', 'message' => __(BookingActionController::REJECTED)])
        ->and($second)->toBe(['kind' => 'success', 'message' => __(BookingActionController::REJECTED)])
        ->and(LunaFixture::row($withReason))->toMatchArray(['status' => 'rejected', 'rejection_reason' => 'Fully booked that week'])
        ->and(LunaFixture::row($withNone)['status'])->toBe('rejected');
});

it('refuses a reason sent as anything but text with the 400 envelope and the field\'s sentence, never a redirect back with the errors bag, and leaves the request as it was', function () {
    $uuid = actionsRequest();

    $this->actingAs(actionsOwner())->from(tenantUrl('luna', '/admin/bookings/'.$uuid.'?status=awaiting_approval'))
        ->post(tenantUrl('luna', '/admin/bookings/'.$uuid.'/reject'), ['reason' => ['Fully booked that week']])
        ->assertStatus(400)
        ->assertJsonPath('code', 'invalid_request')
        ->assertJsonPath('data.detail', __(RejectRequest::NOT_TEXT))
        ->assertSessionMissing('errors')
        ->assertSessionMissing('flash');

    expect(LunaFixture::row($uuid)['status'])->toBe('awaiting_approval')
        ->and(LunaFixture::trail())->toBe([]);
});

it('cancels a confirmed booking, and marks a begun one completed and another a no-show', function () {
    actionsAfterTheStart();
    $cancelled = LunaFixture::booking('confirmed', start: '2026-11-03 08:00', minutes: 30);
    $completed = LunaFixture::booking('confirmed', start: '2026-11-03 09:00', minutes: 30);
    $missed = LunaFixture::booking('confirmed', start: '2026-11-03 10:00', minutes: 30);

    expect(actionsFlash(actionsPress($this, actionsOwner(), $cancelled, 'cancel'), $cancelled)['message'])->toBe(__(BookingActionController::CANCELLED))
        ->and(actionsFlash(actionsPress($this, actionsOwner(), $completed, 'complete'), $completed)['message'])->toBe(__(BookingActionController::COMPLETED))
        ->and(actionsFlash(actionsPress($this, actionsOwner(), $missed, 'no-show'), $missed)['message'])->toBe(__(BookingActionController::NO_SHOW))
        ->and([LunaFixture::row($cancelled)['status'], LunaFixture::row($completed)['status'], LunaFixture::row($missed)['status']])
        ->toBe(['cancelled', 'completed', 'no_show']);
});

it('words the five flashes and the reason\'s refusal in the request\'s language: a user at el reads each in Greek, through the translator', function () {
    actionsAfterTheStart();
    DB::table('users')->where('email', 'owner@luna.example')->update(['locale' => 'el']);
    $greek = fn (string $key): string => adminLine($key, [], SiteLanguage::Greek);
    $approved = actionsRequest('future', '2026-11-03 12:00');
    $rejected = actionsRequest('future', '2026-11-03 13:00');
    $refused = actionsRequest('future', '2026-11-03 14:00');
    $cancelled = LunaFixture::booking('confirmed', start: '2026-11-03 08:00', minutes: 30);
    $completed = LunaFixture::booking('confirmed', start: '2026-11-03 09:00', minutes: 30);
    $missed = LunaFixture::booking('confirmed', start: '2026-11-03 10:00', minutes: 30);
    $keys = [BookingActionController::APPROVED, BookingActionController::REJECTED, BookingActionController::CANCELLED, BookingActionController::COMPLETED, BookingActionController::NO_SHOW, RejectRequest::NOT_TEXT];

    expect(actionsFlash(actionsPress($this, actionsOwner(), $approved, 'approve'), $approved)['message'])->toBe($greek(BookingActionController::APPROVED))
        ->and(actionsFlash(actionsPress($this, actionsOwner(), $rejected, 'reject'), $rejected)['message'])->toBe($greek(BookingActionController::REJECTED))
        ->and(actionsFlash(actionsPress($this, actionsOwner(), $cancelled, 'cancel'), $cancelled)['message'])->toBe($greek(BookingActionController::CANCELLED))
        ->and(actionsFlash(actionsPress($this, actionsOwner(), $completed, 'complete'), $completed)['message'])->toBe($greek(BookingActionController::COMPLETED))
        ->and(actionsFlash(actionsPress($this, actionsOwner(), $missed, 'no-show'), $missed)['message'])->toBe($greek(BookingActionController::NO_SHOW))
        ->and(array_map(fn (string $key): bool => $greek($key) !== adminLine($key), $keys))->toBe(array_fill(0, 6, true));

    $this->actingAs(actionsOwner())->from(tenantUrl('luna', '/admin/bookings/'.$refused.'?status=awaiting_approval'))
        ->post(tenantUrl('luna', '/admin/bookings/'.$refused.'/reject'), ['reason' => ['Fully booked that week']])
        ->assertStatus(400)
        ->assertJsonPath('data.detail', $greek(RejectRequest::NOT_TEXT));
});

it('answers a manager pressing on the wrong state with the guard\'s sentence as an error flash and leaves the booking as it was', function () {
    app()->instance(Clock::class, FixedClock::at('2026-11-02 06:00:00'));
    $manager = actionsUser(addTenantUser('marina@luna.example', 'manager'));
    $confirmed = LunaFixture::booking('confirmed');
    $cancelled = LunaFixture::booking('cancelled', blocks: false, start: '2026-11-03 12:00');
    $lapsed = actionsRequest('past', '2026-11-03 11:00');
    $before = [LunaFixture::row($confirmed), LunaFixture::row($cancelled), LunaFixture::row($lapsed)];

    expect(actionsFlash(actionsPress($this, $manager, $confirmed, 'approve'), $confirmed))->toBe(['kind' => 'error', 'message' => apiSentence(ErrorEnvelope::NOT_APPROVABLE_DETAIL)])
        ->and(actionsFlash(actionsPress($this, $manager, $cancelled, 'cancel'), $cancelled))->toBe(['kind' => 'error', 'message' => apiSentence(ErrorEnvelope::NOT_CANCELLABLE_DETAIL)])
        ->and(actionsFlash(actionsPress($this, $manager, $confirmed, 'complete'), $confirmed))->toBe(['kind' => 'error', 'message' => apiSentence(ErrorEnvelope::TOO_EARLY_DETAIL)])
        // The window binds the buttons as it binds the decision pages.
        ->and(actionsFlash(actionsPress($this, $manager, $lapsed, 'reject'), $lapsed))->toBe(['kind' => 'error', 'message' => apiSentence(ErrorEnvelope::NOT_REJECTABLE_DETAIL)])
        ->and([LunaFixture::row($confirmed), LunaFixture::row($cancelled), LunaFixture::row($lapsed)])->toBe($before);
});

it('lets a past_due tenant\'s manager approve, reject, cancel and complete, because the booking lifecycle is never gated', function () {
    DB::table('tenants')->where('slug', 'luna')->update(['subscription_state' => 'past_due']);
    actionsAfterTheStart();
    $manager = actionsUser(addTenantUser('marina@luna.example', 'manager'));
    $approved = actionsRequest(start: '2026-11-03 12:00');
    $rejected = actionsRequest(start: '2026-11-03 14:00');
    $cancelled = LunaFixture::booking('confirmed', start: '2026-11-03 15:00', minutes: 30);
    $completed = LunaFixture::booking('confirmed', start: '2026-11-03 09:00', minutes: 30);

    foreach ([[$approved, 'approve'], [$rejected, 'reject'], [$cancelled, 'cancel'], [$completed, 'complete']] as [$uuid, $action]) {
        expect(actionsFlash(actionsPress($this, $manager, $uuid, $action), $uuid)['kind'])->toBe('success');
    }

    expect([LunaFixture::row($approved)['status'], LunaFixture::row($rejected)['status'], LunaFixture::row($cancelled)['status'], LunaFixture::row($completed)['status']])
        ->toBe(['confirmed', 'rejected', 'cancelled', 'completed']);
});

it('lets a staff user approve a request with an item on their own row and answers 403 for one without, and for one whose row was unlinked since the render', function () {
    $elena = actionsUser(addTenantUser('elena@luna.example', 'staff', staff: 'Elena Papadaki'));
    $nikos = actionsUser(addTenantUser('nikos@luna.example', 'staff', staff: 'Nikos Georgiou'));
    $hers = actionsRequest();
    $unlinked = actionsRequest(start: '2026-11-03 11:00');

    actionsPress($this, $nikos, $hers, 'approve')->assertForbidden();
    actionsPress($this, $nikos, $hers, 'reject')->assertForbidden();
    expect(LunaFixture::row($hers)['status'])->toBe('awaiting_approval');

    expect(actionsFlash(actionsPress($this, $elena, $hers, 'approve'), $hers)['kind'])->toBe('success')
        ->and(LunaFixture::row($hers)['status'])->toBe('confirmed');

    // Her row reassigned between the page render and the press: the scope is asked again.
    DB::table('tenant_luna.staff')->where('name', 'Elena Papadaki')->update(['user_id' => null]);
    actionsPress($this, $elena, $unlinked, 'approve')->assertForbidden();
    expect(LunaFixture::row($unlinked)['status'])->toBe('awaiting_approval');
});

it('keeps a staff user off cancel, no-show and complete, even on a booking on their own row', function () {
    actionsAfterTheStart();
    $elena = actionsUser(addTenantUser('elena@luna.example', 'staff', staff: 'Elena Papadaki'));
    $hers = LunaFixture::booking('confirmed', start: '2026-11-03 09:00', minutes: 30);

    foreach (['cancel', 'no-show', 'complete'] as $action) {
        actionsPress($this, $elena, $hers, $action)->assertForbidden();
    }

    expect(LunaFixture::row($hers)['status'])->toBe('confirmed');
});

it('reaches no action for a path that is not a uuid: the only admin route matching it is the 404 page, which answers GET alone', function () {
    $this->actingAs(actionsOwner())->post(tenantUrl('luna', '/admin/bookings/not-a-uuid/approve'))->assertStatus(405);
    expect(LunaFixture::trail())->toBe([]);
});

it('lists the actions allowed now from the facts the guards read, per booking and per viewer', function () {
    actionsAfterTheStart();
    $owner = actionsOwner();
    $live = actionsRequest();
    $lapsed = actionsRequest('past', '2026-11-03 11:00');
    $begun = LunaFixture::booking('confirmed', start: '2026-11-03 09:00', minutes: 30);
    $ahead = LunaFixture::booking('confirmed', start: '2026-11-03 14:00', minutes: 30);
    $completed = LunaFixture::booking('completed', blocks: false, start: '2026-11-03 15:00');

    expect(actionsShown($this, $owner, $live))->toBe(['approve', 'reject', 'cancel'])
        ->and(actionsShown($this, $owner, $lapsed))->toBe(['cancel'])
        ->and(actionsShown($this, $owner, $begun))->toBe(['cancel', 'complete', 'no-show'])
        ->and(actionsShown($this, $owner, $ahead))->toBe(['cancel'])
        ->and(actionsShown($this, $owner, $completed))->toBe([]);
});

it('shows a booking awaiting its payment on the calendar, the list and the dialog with cancel offered and approve and reject absent, and refuses a decision pressed on it with the guard\'s sentence', function () {
    $owner = actionsOwner();
    $uuid = LunaFixture::booking('awaiting_payment', 'payment', 'future', service: 'Bridal trial', paymentMode: 'online', requiresApproval: true);
    $before = LunaFixture::row($uuid);

    $list = $this->actingAs($owner)->get(tenantUrl('luna', '/admin/bookings?status=awaiting_payment'))->assertOk()->viewData('page');
    $calendar = $this->actingAs($owner)->get(tenantUrl('luna', '/admin/calendar?from=2026-11-02&to=2026-11-09&booking='.$uuid))->assertOk()->viewData('page');
    $rows = data_get($list, 'props.bookings.rows');
    $blocks = data_get($calendar, 'props.window.bookings');
    assert(is_array($rows) && is_array($blocks));

    expect(array_map(fn (mixed $row): array => [data_get($row, 'uuid'), data_get($row, 'status')], $rows))->toBe([[$uuid, 'awaiting_payment']])
        ->and(array_values(array_unique(array_filter(array_map(fn (mixed $block): mixed => data_get($block, 'status'), array_filter($blocks, fn (mixed $block): bool => data_get($block, 'uuid') === $uuid)), is_string(...)))))->toBe(['awaiting_payment'])
        ->and(actionsShown($this, $owner, $uuid))->toBe(['cancel'])
        ->and(data_get($calendar, 'props.detail.actions'))->toBe(['cancel'])
        ->and(actionsFlash(actionsPress($this, $owner, $uuid, 'approve'), $uuid))->toBe(['kind' => 'error', 'message' => apiSentence(ErrorEnvelope::NOT_APPROVABLE_DETAIL)])
        ->and(actionsFlash(actionsPress($this, $owner, $uuid, 'reject'), $uuid))->toBe(['kind' => 'error', 'message' => apiSentence(ErrorEnvelope::NOT_REJECTABLE_DETAIL)])
        ->and(LunaFixture::row($uuid))->toBe($before);
});

it('lists a staff user the two decisions on their own request and nothing on another\'s', function () {
    $elena = actionsUser(addTenantUser('elena@luna.example', 'staff', staff: 'Elena Papadaki'));
    $nikos = actionsUser(addTenantUser('nikos@luna.example', 'staff', staff: 'Nikos Georgiou'));
    $hers = actionsRequest();
    $tenant = LunaFixture::tenant();
    $record = app(BookingRecords::class)->one($tenant, $hers);
    assert($record !== null);

    // A staff user has no viewBookings, so the bookings list never opens for them; the list is
    // asked directly, as the calendar's dialog will ask it.
    expect(app(BookingActions::class)->for($tenant, $record, Principal::user($elena)))->toBe(['approve', 'reject'])
        ->and(app(BookingActions::class)->for($tenant, $record, Principal::user($nikos)))->toBe([]);
});

it('counts on the rail\'s Bookings entry the requests whose hold is live, omitting lapsed ones and showing nothing at zero', function () {
    $pending = function (): mixed {
        $nav = data_get($this->actingAs(actionsOwner())->get(tenantUrl('luna', '/admin/bookings'))->assertOk()->viewData('page'), 'props.nav');
        assert(is_array($nav));

        foreach ($nav as $entry) {
            if (data_get($entry, 'label') === navLabel('bookings')) {
                return data_get($entry, 'pending');
            }
        }

        return 'no Bookings entry';
    };

    expect($pending())->toBeNull();

    actionsRequest();
    actionsRequest(start: '2026-11-03 11:00');
    actionsRequest('past', '2026-11-03 12:00');
    LunaFixture::booking('confirmed', start: '2026-11-03 14:00');

    expect($pending())->toBe(2);
});

it('answers the rail\'s count on every page of the shell, not the bookings screen alone', function () {
    actionsRequest();

    $this->actingAs(actionsOwner())->get(tenantUrl('luna', '/admin'))
        ->assertInertia(fn (Assert $page) => $page->where('nav.2.label', navLabel('bookings'))->where('nav.2.pending', 1));
});
