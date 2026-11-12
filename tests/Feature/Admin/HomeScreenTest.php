<?php

use App\Http\HomeActivity;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use App\Site\SiteLanguage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FixtureDay;
use Tests\Support\LunaFixture;
use Tests\TestCase;

pest()->use(RefreshDatabase::class);

/*
 * Home: the landing at /admin for a viewBookings holder, a viewer without it sent on to the
 * Calendar; its props by permission, the range's figures read alone on a partial reload (their
 * counting is HomeFiguresTest's), and the seven days' counts by first start in the tenant's
 * zone.
 *
 * The bookings are written straight to the rows at literal dates, so the clock is pinned first:
 * FixtureDay's clock stands on Monday 2026-11-02 at 08:00 in Athens, so this week, from luna's
 * Monday week start, is the 2nd to the 8th, and the seven days are the same seven.
 */

beforeEach(function () {
    $this->withoutVite();
    seedDemo();
    FixtureDay::pinClock();
});

function homeOwner(): User
{
    return User::query()->where('email', 'owner@luna.example')->sole();
}

/**
 * A booking of luna written straight to the rows: one Haircut on Elena at the Athens start given,
 * at the price and in the currency given, for the customer named; a request's hold ends the hours
 * given from the database's now, negative for one already lapsed. Answers its id and its uuid.
 *
 * @return array{id: int, uuid: string}
 */
function homeBooking(string $status, string $start, int $price = 3500, string $currency = 'EUR', ?string $customer = 'Casey Customer', int $holdHours = 48): array
{
    $request = $status === 'awaiting_approval';
    $uuid = (string) Str::uuid();
    $id = DB::table('tenant_luna.bookings')->insertGetId([
        'source' => 'embed', 'uuid' => $uuid, 'status' => $status,
        'customer_name' => $customer, 'customer_email' => $customer === null ? null : 'casey@example.com',
        'total_minor' => $price, 'currency' => $currency,
        'hold_class' => $request ? 'approval' : null,
        'hold_expires_at' => $request ? LunaFixture::instant((new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify(sprintf('%+d hours', $holdHours))) : null,
        'payment_mode' => 'onsite', 'requires_approval' => $request, 'on_approval_timeout' => 'expire',
    ]);
    $from = LunaFixture::at($start);
    $to = $from->add(new DateInterval('PT45M'));
    DB::table('tenant_luna.booking_items')->insert([
        'booking_id' => $id, 'sort' => 0,
        'service_id' => LunaFixture::id('services', 'Haircut'), 'staff_id' => LunaFixture::id('staff', 'Elena Papadaki'),
        'start_utc' => LunaFixture::instant($from), 'end_utc' => LunaFixture::instant($to),
        'block_start_utc' => LunaFixture::instant($from), 'block_end_utc' => LunaFixture::instant($to),
        'price_minor' => $price, 'blocks' => false,
    ]);

    return ['id' => $id, 'uuid' => $uuid];
}

/**
 * A trail row of luna written straight to audit_log at the instant given, UTC with its offset.
 *
 * @param  array<string, mixed>  $payload
 */
function homeTrail(?int $booking, string $action, string $actor, array $payload, string $at): void
{
    DB::table('tenant_luna.audit_log')->insert([
        'booking_id' => $booking, 'actor' => $actor, 'action' => $action,
        'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'created_at' => $at,
    ]);
}

/**
 * One of Home's feeds as the owner reads it: Next up's, Awaiting approval's or Recent activity's
 * rows, or the detail where no booking is open.
 *
 * @return list<array<string, mixed>>
 */
function homeFeed(TestCase $test, string $prop): array
{
    $rows = data_get($test->actingAs(homeOwner())->get(tenantUrl('luna', '/admin'))->assertOk()->viewData('page'), 'props.'.$prop);
    assert(is_array($rows) && array_is_list($rows));

    $feed = [];

    foreach ($rows as $row) {
        assert(is_array($row));
        $named = [];

        foreach ($row as $key => $value) {
            $named[(string) $key] = $value;
        }

        $feed[] = $named;
    }

    return $feed;
}

/**
 * The seven days from Monday the 2nd with the counts given, each a confirmed and an awaiting
 * count, nought for every other.
 *
 * @param  array<string, array{int, int}>  $counts
 * @return list<array{date: string, confirmed: int, awaiting: int}>
 */
function homeDays(array $counts = []): array
{
    return array_map(
        fn (string $date): array => ['date' => $date, 'confirmed' => $counts[$date][0] ?? 0, 'awaiting' => $counts[$date][1] ?? 0],
        ['2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05', '2026-11-06', '2026-11-07', '2026-11-08'],
    );
}

it('answers Home at /admin to a viewBookings holder with the home section beside the shell\'s, the rail lighting Home', function () {
    $this->actingAs(homeOwner())->get(tenantUrl('luna', '/admin'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Home')
            ->missing('today')
            ->where('strings.home', __('admin/home', [], 'en'))
            ->where('strings.bookings', __('admin/bookings', [], 'en'))
            ->where('nav.0', ['label' => navLabel('home'), 'url' => '/admin', 'group' => navLabel('groups.schedule'), 'pending' => null, 'current' => true])
            ->where('nav.1', ['label' => navLabel('calendar'), 'url' => '/admin/calendar', 'group' => navLabel('groups.schedule'), 'pending' => null, 'current' => false]));
});

it('sends a viewer without viewBookings asking for /admin to the Calendar, which answers them there', function () {
    $staff = User::query()->findOrFail(addTenantUser('elena@luna.example', 'staff', staff: 'Elena Papadaki'));

    $this->actingAs($staff)->get(tenantUrl('luna', '/admin'))->assertRedirect(tenantUrl('luna', '/admin/calendar'));
    $this->actingAs($staff)->get(tenantUrl('luna', '/admin/calendar'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Calendar')->where('pinned', true)->missing('setup'));
});

it('carries the setup checklist to a manageSettings holder: whether a service exists, both markers, whether a booking exists in any status, and the hosted page, each read from its own rows', function () {
    $setup = fn (User $viewer): mixed => data_get($this->actingAs($viewer)->get(tenantUrl('luna', '/admin'))->assertOk()->viewData('page'), 'props.setup');
    $manager = User::query()->findOrFail(addTenantUser('mira@luna.example', 'manager'));

    // A catalogue emptied inside a savepoint answers no service, then comes back for the bookings
    // below.
    DB::beginTransaction();
    DB::table('tenant_luna.services')->delete();
    expect($setup(homeOwner()))->toBe(['service' => false, 'hours' => false, 'link' => false, 'booking' => false, 'hosted_url' => hostedPage('luna')]);
    DB::rollBack();

    expect($setup(homeOwner()))->toBe(['service' => true, 'hours' => false, 'link' => false, 'booking' => false, 'hosted_url' => hostedPage('luna')]);

    settingsOf('luna')->update(['hours_confirmed_at' => DB::raw('now()')]);
    expect($setup(homeOwner()))->toMatchArray(['hours' => true, 'link' => false]);

    settingsOf('luna')->update(['link_copied_at' => DB::raw('now()')]);
    expect($setup(homeOwner()))->toMatchArray(['hours' => true, 'link' => true, 'booking' => false]);

    homeBooking('cancelled', '2026-10-20 10:00');
    expect($setup(homeOwner()))->toBe(['service' => true, 'hours' => true, 'link' => true, 'booking' => true, 'hosted_url' => hostedPage('luna')])
        ->and($setup($manager))->toBe(['service' => true, 'hours' => true, 'link' => true, 'booking' => true, 'hosted_url' => hostedPage('luna')]);
});

it('reads an empty seven days as nought a day', function () {
    $this->actingAs(homeOwner())->get(tenantUrl('luna', '/admin'))
        ->assertInertia(fn (Assert $page) => $page->where('days', homeDays()));
});

it('counts the next seven days from today by first start in the tenant\'s zone, the confirmed (awaiting payment with them) apart from the awaiting approval, no other status', function () {
    homeBooking('confirmed', '2026-11-02 09:00');
    homeBooking('confirmed', '2026-11-02 17:00');
    homeBooking('awaiting_approval', '2026-11-04 10:00');
    homeBooking('awaiting_payment', '2026-11-04 11:00');
    homeBooking('awaiting_approval', '2026-11-06 23:30');
    // Just after Athens' midnight: still the 7th in UTC, the 8th in the tenant's zone.
    homeBooking('confirmed', '2026-11-08 00:30');
    // Counted by none: the other statuses, and the days either side.
    foreach (['completed', 'no_show', 'cancelled', 'rejected', 'expired', 'pending'] as $status) {
        homeBooking($status, '2026-11-03 10:00');
    }
    homeBooking('confirmed', '2026-11-01 23:30');
    homeBooking('confirmed', '2026-11-09 00:00');

    $this->actingAs(homeOwner())->get(tenantUrl('luna', '/admin'))
        ->assertInertia(fn (Assert $page) => $page->where('days', homeDays(['2026-11-02' => [2, 0], '2026-11-04' => [1, 1], '2026-11-06' => [0, 1], '2026-11-08' => [1, 0]])));
});

it('reads the props on a partial reload alone, the range\'s figures without the days, as the range card asks for them', function () {
    homeBooking('confirmed', '2026-11-03 10:00');

    $this->actingAs(homeOwner())->get(tenantUrl('luna', '/admin?range=week'), [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
        'X-Inertia-Partial-Component' => 'Home',
        'X-Inertia-Partial-Data' => 'range_figures',
    ])->assertOk()->assertJsonPath('props.range_figures.tiles.bookings.value', 1)->assertJsonMissingPath('props.days')->assertJsonMissingPath('props.figures');
});

it('lists Next up: the next five bookings from now by first start in confirmed, awaiting approval and awaiting payment, each with the customer, the services, the named member and the start', function () {
    // The clock stands at 08:00 in Athens on the 2nd: the booking at 07:30 has started.
    homeBooking('confirmed', '2026-11-02 07:30', customer: 'Early Bird');
    $first = homeBooking('awaiting_payment', '2026-11-02 08:00', customer: 'Ada First');
    homeBooking('confirmed', '2026-11-04 10:00', customer: 'Cleo Third');
    homeBooking('awaiting_approval', '2026-11-03 09:00', customer: 'Bea Second');
    homeBooking('confirmed', '2026-11-05 10:00', customer: null);
    homeBooking('confirmed', '2026-11-20 10:00', customer: 'Eve Fifth');
    homeBooking('confirmed', '2026-11-21 10:00', customer: 'Fay Sixth');
    // Listed by none: the other statuses, however soon.
    foreach (['pending', 'completed', 'no_show', 'cancelled', 'rejected', 'expired'] as $status) {
        homeBooking($status, '2026-11-02 09:00', customer: 'Not Listed');
    }

    $next = homeFeed($this, 'next_up');

    expect(array_column($next, 'customer'))->toBe(['Ada First', 'Bea Second', 'Cleo Third', null, 'Eve Fifth'])
        ->and($next[0])->toBe([
            'uuid' => $first['uuid'],
            'status' => 'awaiting_payment',
            'customer' => 'Ada First',
            'services' => ['Haircut'],
            'staff' => ['Elena Papadaki'],
            'starts_at' => '2026-11-02T06:00:00+00:00',
            'hold_expires_at' => null,
        ]);
});

it('lists Awaiting approval: the requests on a live hold, the soonest to end first, up to five, and the rail\'s pending number counts the same rows', function () {
    $hours = [30, 6, 72, 12, 48, 96];
    $uuids = [];

    foreach ($hours as $index => $hold) {
        $uuids[$hold] = homeBooking('awaiting_approval', sprintf('2026-11-%02d 10:00', 3 + $index), customer: 'Request '.$hold, holdHours: $hold)['uuid'];
    }

    // A request whose hold has run out is no longer the owner's to decide.
    homeBooking('awaiting_approval', '2026-11-03 12:00', customer: 'Lapsed', holdHours: -1);

    $awaiting = homeFeed($this, 'awaiting');

    expect(array_column($awaiting, 'uuid'))->toBe([$uuids[6], $uuids[12], $uuids[30], $uuids[48], $uuids[72]])
        ->and($awaiting[0]['hold_expires_at'] ?? null)->toBeString();
    $this->actingAs(homeOwner())->get(tenantUrl('luna', '/admin'))
        ->assertInertia(fn (Assert $page) => $page->where('nav.2.url', '/admin/bookings')->where('nav.2.pending', 6));
});

it('answers empty feeds where nothing is ahead, waiting or recorded', function () {
    DB::table('tenant_luna.audit_log')->delete();
    expect(homeFeed($this, 'next_up'))->toBe([])->and(homeFeed($this, 'awaiting'))->toBe([])->and(homeFeed($this, 'activity'))->toBe([]);
});

it('lists the last five trail rows newest first, each joined to its booking for the uuid, the customer, the first start, the status, the services and the named members', function () {
    DB::table('tenant_luna.audit_log')->delete();
    $booking = homeBooking('awaiting_approval', '2026-11-04 10:00', customer: 'Maria Rossi');

    for ($minute = 0; $minute < 25; $minute++) {
        homeTrail($booking['id'], 'held', 'customer', ['status_to' => 'pending'], sprintf('2026-11-01 09:%02d:00+00', $minute));
    }

    homeTrail(987654, 'cancelled', 'customer', [], '2026-11-01 08:00:00+00');

    $activity = homeFeed($this, 'activity');

    expect(HomeActivity::ROWS)->toBe(5)
        ->and($activity)->toHaveCount(5)
        ->and($activity[0])->toBe([
            'sentence' => 'activity.held.online',
            'customer' => 'Maria Rossi',
            'staff' => null,
            'at' => '2026-11-01T09:24:00+00:00',
            'booking' => $booking['uuid'],
            'starts_at' => '2026-11-04T08:00:00+00:00',
            'status' => 'awaiting_approval',
            'services' => ['Haircut'],
            'members' => ['Elena Papadaki'],
        ])
        ->and($activity[4]['at'])->toBe('2026-11-01T09:20:00+00:00');
});

it('answers a trail row whose booking is gone with no booking and no customer, its sentence the one that names none', function () {
    DB::table('tenant_luna.audit_log')->delete();
    homeTrail(987654, 'cancelled', 'customer', [], '2026-11-01 08:00:00+00');

    expect(homeFeed($this, 'activity'))->toBe([[
        'sentence' => 'activity.erased.cancelled.customer', 'customer' => null, 'staff' => null,
        'at' => '2026-11-01T08:00:00+00:00', 'booking' => null, 'starts_at' => null,
        'status' => null, 'services' => [], 'members' => [],
    ]]);
});

it('spells every action and actor case as one whole sentence of admin/home.php, the paid row a payment, no amount in any row, the erasure and the export naming nobody', function () {
    DB::table('tenant_luna.audit_log')->delete();
    $booking = homeBooking('confirmed', '2026-11-04 10:00', customer: 'Maria Rossi')['id'];
    $owner = homeOwner();
    $owner->update(['name' => 'Nikos Owner']);
    $operator = addOperator();
    $gone = addOperator('gone@inbox.example', name: 'Gone Operator');
    $goneId = $gone->id;
    $gone->delete();
    $paid = ['status_from' => 'pending', 'status_to' => 'confirmed', 'paid_minor' => 4500, 'paid_currency' => 'EUR', 'payment_named' => true];

    // Each case: the action, the actor, the payload, the sentence's key and the acting name it
    // fills.
    $cases = [
        ['held', 'customer', ['status_to' => 'pending'], 'activity.held.online', null],
        ['held', 'customer', ['status_to' => 'awaiting_approval'], 'activity.held.request', null],
        ['confirmed', 'customer', ['status_to' => 'confirmed'], 'activity.held.online', null],
        ['held', 'user:'.$owner->id, ['status_to' => 'pending'], 'activity.added.user', 'Nikos Owner'],
        ['confirmed', 'user:'.$owner->id, ['status_to' => 'confirmed'], 'activity.added.user', 'Nikos Owner'],
        ['confirmed', 'user:999999', ['status_to' => 'confirmed'], 'activity.added.removed', null],
        ['confirmed', 'support:'.$operator->id, ['status_to' => 'confirmed'], 'activity.added.support', 'Pat Operator'],
        ['confirmed', 'support:'.$goneId, ['status_to' => 'confirmed'], 'activity.added.support_removed', null],
        ['confirmed', 'customer', ['status_from' => 'pending', 'status_to' => 'confirmed'], 'activity.confirmed.customer', null],
        ['confirmed', 'job:payment', $paid, 'activity.confirmed.paid', null],
        ['confirmed', 'system', ['status_from' => 'pending', 'status_to' => 'confirmed'], 'activity.confirmed.system', null],
        ['approved', 'user:'.$owner->id, ['status_from' => 'awaiting_approval'], 'activity.approved.user', 'Nikos Owner'],
        ['approved', 'user:999999', [], 'activity.approved.removed', null],
        ['approved', 'support:'.$operator->id, [], 'activity.approved.support', 'Pat Operator'],
        ['approved', 'support:'.$goneId, [], 'activity.approved.support_removed', null],
        ['approved', 'job:sweeper', [], 'activity.approved.system', null],
        ['rejected', 'user:'.$owner->id, [], 'activity.rejected.user', 'Nikos Owner'],
        ['rejected', 'user:999999', [], 'activity.rejected.removed', null],
        ['rejected', 'support:'.$operator->id, [], 'activity.rejected.support', 'Pat Operator'],
        ['rejected', 'support:'.$goneId, [], 'activity.rejected.support_removed', null],
        ['rejected', 'system', [], 'activity.rejected.system', null],
        ['cancelled', 'customer', [], 'activity.cancelled.customer', null],
        ['cancelled', 'user:'.$owner->id, [], 'activity.cancelled.user', 'Nikos Owner'],
        ['cancelled', 'user:999999', [], 'activity.cancelled.removed', null],
        ['cancelled', 'support:'.$operator->id, [], 'activity.cancelled.support', 'Pat Operator'],
        ['cancelled', 'support:'.$goneId, [], 'activity.cancelled.support_removed', null],
        ['cancelled', 'system', [], 'activity.cancelled.system', null],
        ['released', 'customer', [], 'activity.released.customer', null],
        ['released', 'system', [], 'activity.released.system', null],
        ['rescheduled', 'customer', ['moved_from' => '2026-11-03T08:00:00Z', 'starts_at' => '2026-11-04T08:00:00Z'], 'activity.rescheduled.customer', null],
        ['rescheduled', 'user:'.$owner->id, ['customer_told' => true], 'activity.rescheduled.user', 'Nikos Owner'],
        ['rescheduled', 'user:999999', [], 'activity.rescheduled.removed', null],
        ['rescheduled', 'support:'.$operator->id, [], 'activity.rescheduled.support', 'Pat Operator'],
        ['rescheduled', 'support:'.$goneId, [], 'activity.rescheduled.support_removed', null],
        ['rescheduled', 'job:payment', ['session_cleared' => true], 'activity.rescheduled.system', null],
        ['completed', 'user:'.$owner->id, [], 'activity.completed.user', 'Nikos Owner'],
        ['completed', 'user:999999', [], 'activity.completed.removed', null],
        ['completed', 'support:'.$operator->id, [], 'activity.completed.support', 'Pat Operator'],
        ['completed', 'support:'.$goneId, [], 'activity.completed.support_removed', null],
        ['no_show', 'user:'.$owner->id, [], 'activity.no_show.user', 'Nikos Owner'],
        ['no_show', 'user:999999', [], 'activity.no_show.removed', null],
        ['no_show', 'support:'.$operator->id, [], 'activity.no_show.support', 'Pat Operator'],
        ['no_show', 'support:'.$goneId, [], 'activity.no_show.support_removed', null],
        ['expired', 'job:sweeper', ['status_from' => 'awaiting_approval', 'hold_class' => 'approval'], 'activity.expired.approval', null],
        ['expired', 'system', ['hold_class' => 'checkout'], 'activity.expired.checkout', null],
        ['expired', 'job:sweeper', ['hold_class' => 'payment'], 'activity.expired.payment', null],
        ['expired', 'system', [], 'activity.expired.other', null],
        ['customer_erased', 'user:'.$owner->id, [], 'activity.customer_erased', 'Nikos Owner'],
        ['customer_exported', 'support:'.$operator->id, [], 'activity.customer_exported', 'Pat Operator'],
        ['completed', 'customer', [], 'activity.unknown', null],
    ];

    $read = [];

    foreach (array_chunk($cases, HomeActivity::ROWS) as $chunk) {
        DB::table('tenant_luna.audit_log')->delete();

        foreach ($chunk as $index => [$action, $actor, $payload]) {
            homeTrail($booking, $action, $actor, $payload, sprintf('2026-11-01 09:%02d:00+00', 59 - $index));
        }

        foreach (homeFeed($this, 'activity') as $row) {
            expect(array_keys($row))->toBe(['sentence', 'customer', 'staff', 'at', 'booking', 'starts_at', 'status', 'services', 'members']);
            assert(is_string($row['sentence']));
            $read[] = [$row['sentence'], $row['customer'], $row['staff']];
        }
    }

    // A sentence that names no customer carries no customer's name either.
    expect($read)->toBe(array_map(fn (array $case): array => [$case[3], in_array($case[0], ['customer_erased', 'customer_exported'], true) ? null : 'Maria Rossi', $case[4]], $cases));

    // Each key a row names is a whole sentence of the file, filled through the translator as the
    // page fills it, the acting name in its place where the sentence names one and no placeholder
    // left.
    foreach ($cases as [, , , $key, $staff]) {
        $line = adminLine('admin/home.'.$key, array_filter(['customer' => 'Maria Rossi', 'staff' => $staff], is_string(...)));
        expect($line)->not->toContain(':');

        if ($staff !== null && str_contains(adminLine('admin/home.'.$key), ':staff')) {
            expect($line)->toContain($staff);
        }
    }
});

it('spells an erased customer\'s rows by whole sentences of their own under erased, naming no customer, the erasure and the export as they are', function () {
    DB::table('tenant_luna.audit_log')->delete();
    $booking = homeBooking('cancelled', '2026-11-04 10:00', customer: null)['id'];
    $owner = homeOwner();
    $owner->update(['name' => 'Nikos Owner']);

    // Newest first: each row's key, then its acting name.
    $cases = [
        ['held', 'customer', ['status_to' => 'awaiting_approval'], 'activity.erased.held.request', null],
        ['approved', 'user:'.$owner->id, [], 'activity.erased.approved.user', 'Nikos Owner'],
        ['cancelled', 'system', [], 'activity.erased.cancelled.system', null],
        ['customer_erased', 'user:'.$owner->id, [], 'activity.customer_erased', 'Nikos Owner'],
        ['completed', 'customer', [], 'activity.erased.unknown', null],
    ];

    foreach ($cases as $index => [$action, $actor, $payload]) {
        homeTrail($booking, $action, $actor, $payload, sprintf('2026-11-01 09:%02d:00+00', 59 - $index));
    }

    $rows = homeFeed($this, 'activity');

    expect(array_map(fn (array $row): array => [$row['sentence'], $row['customer'], $row['staff']], $rows))
        ->toBe(array_map(fn (array $case): array => [$case[3], null, $case[4]], $cases));

    foreach ($cases as [, , , $key]) {
        expect(__('admin/home.'.$key, [], 'en'))->toBeString()->not->toContain(':customer');
    }
});

it('holds admin/home.php\'s activity group to the sentences a row can be spelled by, each one key the file holds', function () {
    $group = __('admin/home.activity', [], 'en');
    $leaves = [];

    foreach (Arr::dot($group) as $key => $line) {
        $leaves[] = 'activity.'.$key;
    }

    $sentences = HomeActivity::sentences();
    sort($sentences);
    $held = array_values(array_diff($leaves, ['activity.title', 'activity.empty', 'activity.line', 'activity.all']));
    sort($held);

    expect($sentences)->toBe($held);
});

it('reads every sentence a Recent activity row can be spelled by in Greek through the translator, each other than its English', function () {
    $read = 0;

    foreach (HomeActivity::sentences() as $key) {
        $greek = adminLine('admin/home.'.$key, [], SiteLanguage::Greek);

        expect($greek)->not->toBe(adminLine('admin/home.'.$key), $key);
        $read++;
    }

    // The table's own count: 94 sentences, the activity group's 98 leaves less its title, its
    // empty line, its link and its row's layout, each read once.
    expect($read)->toBe(count(HomeActivity::sentences()))
        ->and($read)->toBe(count(Arr::dot((array) __('admin/home.activity', [], 'el'))) - 4)
        ->and($read)->toBe(94);
});

it('shows a user at el each shape of actor\'s row and an erased customer\'s in Greek, the row\'s key filled through the translator, the page carrying the Greek home section', function () {
    DB::table('tenant_luna.audit_log')->delete();
    $named = homeBooking('confirmed', '2026-11-04 10:00', customer: 'customer-token')['id'];
    $erased = homeBooking('cancelled', '2026-11-04 11:00', customer: null)['id'];
    $owner = homeOwner();
    $owner->update(['name' => 'staff-token']);
    DB::table('users')->where('id', $owner->id)->update(['locale' => SiteLanguage::Greek->value]);
    $operator = addOperator(name: 'operator-token');
    $gone = addOperator('gone@inbox.example', name: 'Gone Operator');
    $goneId = $gone->id;
    $gone->delete();
    $paid = ['status_from' => 'pending', 'status_to' => 'confirmed', 'paid_minor' => 4500, 'paid_currency' => 'EUR', 'payment_named' => true];

    // Each case: the booking, the action, the actor, the payload, the sentence's key, and the
    // customer and the acting name the row carries. One action per shape of actor, then an
    // erased customer's twin.
    $cases = [
        [$named, 'held', 'customer', ['status_to' => 'pending'], 'activity.held.online', 'customer-token', null],
        [$named, 'approved', 'user:'.$owner->id, [], 'activity.approved.user', 'customer-token', 'staff-token'],
        [$named, 'cancelled', 'user:999999', [], 'activity.cancelled.removed', 'customer-token', null],
        [$named, 'rescheduled', 'support:'.$operator->id, [], 'activity.rescheduled.support', 'customer-token', 'operator-token'],
        [$named, 'no_show', 'support:'.$goneId, [], 'activity.no_show.support_removed', 'customer-token', null],
        [$named, 'confirmed', 'system', ['status_from' => 'pending', 'status_to' => 'confirmed'], 'activity.confirmed.system', 'customer-token', null],
        [$named, 'confirmed', 'job:payment', $paid, 'activity.confirmed.paid', 'customer-token', null],
        [$erased, 'approved', 'user:'.$owner->id, [], 'activity.erased.approved.user', null, 'staff-token'],
    ];

    $this->actingAs(homeOwner())->get(tenantUrl('luna', '/admin'))->assertOk()
        ->assertSee('<html lang="el">', false)
        ->assertInertia(fn (Assert $page) => $page->where('strings.home', __('admin/home', [], 'el')));
    expect(__('admin/home', [], 'el'))->not->toBe(__('admin/home', [], 'en'));

    $shown = [];

    foreach (array_chunk($cases, HomeActivity::ROWS) as $chunk) {
        DB::table('tenant_luna.audit_log')->delete();

        foreach ($chunk as $index => [$booking, $action, $actor, $payload]) {
            homeTrail($booking, $action, $actor, $payload, sprintf('2026-11-01 09:%02d:00+00', 59 - $index));
        }

        foreach (homeFeed($this, 'activity') as $row) {
            assert(is_string($row['sentence']));
            $names = array_filter(['customer' => $row['customer'], 'staff' => $row['staff']], is_string(...));
            $greek = adminLine('admin/home.'.$row['sentence'], $names, SiteLanguage::Greek);

            // What the client shows: the row's key filled with its names, whole, in Greek.
            expect($greek)->not->toContain(':')
                ->and($greek)->not->toBe(adminLine('admin/home.'.$row['sentence'], $names));

            foreach ($names as $name) {
                expect($greek)->toContain($name);
            }

            $shown[] = [$row['sentence'], $row['customer'], $row['staff']];
        }
    }

    expect($shown)->toBe(array_map(fn (array $case): array => [$case[4], $case[5], $case[6]], $cases));
});

it('reaches each form of Home\'s four counted lines in Greek at the bounds the English has, the figure agreeing with its noun', function () {
    // Each case: the key, the count, the form of the Greek value it reads, and what else it
    // fills. days.link at one says the figure in words, so its form names no :count.
    $cases = [
        ['tiles.returning', 0, 1, []],
        ['tiles.returning', 1, 0, []],
        ['tiles.returning', 2, 1, []],
        ['days.bookings', 0, 0, []],
        ['days.bookings', 1, 1, []],
        ['days.bookings', 2, 2, []],
        ['days.summary.one_awaiting', 1, 0, ['awaiting' => '1']],
        ['days.summary.one_awaiting', 2, 1, ['awaiting' => '1']],
        ['days.link', 0, 0, ['day' => 'day-token']],
        ['days.link', 1, 1, ['day' => 'day-token']],
        ['days.link', 2, 2, ['day' => 'day-token']],
    ];

    foreach ($cases as [$key, $count, $form, $replace]) {
        $forms = explode('|', adminLine('admin/home.'.$key, [], SiteLanguage::Greek));
        $value = (string) preg_replace('/^\s*[{\[][^}\]]*[}\]]\s*/', '', $forms[$form] ?? '');
        $fill = [];

        foreach (['count' => (string) $count, ...$replace] as $name => $with) {
            $fill[':'.$name] = $with;
        }

        expect(adminLine('admin/home.'.$key, [], SiteLanguage::Greek))->not->toBe(adminLine('admin/home.'.$key), $key)
            ->and(count($forms))->toBe(count(explode('|', adminLine('admin/home.'.$key))), $key)
            ->and(adminChoice('admin/home.'.$key, $count, $replace, SiteLanguage::Greek))->toBe(strtr($value, $fill), $key.' at '.$count)
            ->and($value)->not->toBe('', $key);
    }

    // days.link at one names no figure; at two it does.
    expect(adminChoice('admin/home.days.link', 1, ['day' => 'day-token'], SiteLanguage::Greek))->not->toContain('1')
        ->and(adminChoice('admin/home.days.link', 2, ['day' => 'day-token'], SiteLanguage::Greek))->toContain('2');
});

it('reads the dialog\'s detail on Home for the booking a partial reload names, with no scope, the actions the Bookings page gives', function () {
    $request = homeBooking('awaiting_approval', '2026-11-05 10:00', customer: 'Maria Rossi');

    // The approval itself enters the transaction runner, which this file's wrapping transaction
    // refuses; the browser walk approves from Home (AdminHomeTest).
    $this->actingAs(homeOwner())->get(tenantUrl('luna', '/admin?booking='.$request['uuid']), [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
        'X-Inertia-Partial-Component' => 'Home',
        'X-Inertia-Partial-Data' => 'detail',
    ])->assertOk()
        ->assertJsonPath('props.detail.state', 'found')
        ->assertJsonPath('props.detail.booking.uuid', $request['uuid'])
        ->assertJsonPath('props.detail.booking.customer_email', 'casey@example.com')
        ->assertJsonPath('props.detail.actions', ['approve', 'reject', 'cancel'])
        ->assertJsonMissingPath('props.activity');

    $this->actingAs(homeOwner())->get(tenantUrl('luna', '/admin?booking='.Str::uuid()), [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
        'X-Inertia-Partial-Component' => 'Home',
        'X-Inertia-Partial-Data' => 'detail',
    ])->assertOk()->assertJsonPath('props.detail.state', 'missing');

    $this->actingAs(homeOwner())->get(tenantUrl('luna', '/admin'))->assertInertia(fn (Assert $page) => $page->where('detail', null));
});
