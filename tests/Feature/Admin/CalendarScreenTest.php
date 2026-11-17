<?php

use App\Application\Clock;
use App\Application\Dto\CalendarWindow;
use App\Http\BookingPayload;
use App\Http\OpeningWeek;
use App\Infrastructure\Db\BookingRecords;
use App\Infrastructure\Db\StaffRecords;
use App\Models\User;
use App\Site\SiteLanguage;
use App\Tenancy\Tenant;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FixedClock;
use Tests\Support\LunaFixture;
use Tests\Support\WeeklyHours;
use Tests\TestCase;

pest()->use(RefreshDatabase::class);

/*
 * The Calendar, on the server: the window as the visible range with an exclusive end and Who as
 * its one parameter (`who` or a `staff` list), the members Who resolves to with their hours and
 * closures per date, every booking in every status through the whitelist with the occupied ranges
 * the grid draws, the dialog's detail as client state asked for by a query parameter, and a viewer
 * without viewBookings pinned to their own staff row.
 *
 * luna's hours (DemoHoursSeeder): Elena Monday to Friday split around lunch, Nikos Tuesday to
 * Saturday, nobody on Sunday; Nikos closed 14:00 to 16:00 on 2026-12-04, the business on
 * 2026-12-25.
 *
 * luna is in Europe/Athens. The Clock port is fixed so the tenant's today is not the machine's:
 * 22:30 UTC on Wednesday 2026-11-04 is 00:30 on Thursday the fifth in Athens.
 */

beforeEach(function () {
    $this->withoutVite();
    seedDemo();
    app()->instance(Clock::class, FixedClock::at('2026-11-04 22:30:00'));
});

/**
 * A booking of luna written straight to the rows: one item per segment, each [service, staff,
 * Athens start, minutes], with the occupied range widened by a buffer either side and, where a
 * gap is given, the processing end that many minutes past the end.
 *
 * @param  list<array{0: string, 1: string, 2: string, 3: int}>  $segments
 */
function calendared(array $segments, string $name = 'Casey Customer', string $status = 'confirmed', int $buffer = 5, int $gap = 0): string
{
    $uuid = (string) Str::uuid();
    $request = $status === 'awaiting_approval';
    $id = DB::table('tenant_luna.bookings')->insertGetId([
        'source' => 'embed',
        'uuid' => $uuid, 'status' => $status, 'customer_name' => $name, 'customer_email' => Str::slug($name).'@example.test',
        'customer_phone' => '+30 210 7654321', 'total_minor' => 3500, 'currency' => 'EUR',
        'hold_class' => $request ? 'approval' : null,
        'hold_expires_at' => $request ? DB::raw("now() + interval '2 days'") : null,
        'payment_mode' => 'onsite', 'requires_approval' => $request, 'on_approval_timeout' => 'expire',
    ]);

    foreach ($segments as $sort => [$service, $staff, $start, $minutes]) {
        $from = LunaFixture::at($start);
        $to = $from->add(new DateInterval(sprintf('PT%dM', $minutes)));
        DB::table('tenant_luna.booking_items')->insert([
            'booking_id' => $id, 'sort' => $sort,
            'service_id' => LunaFixture::id('services', $service), 'staff_id' => LunaFixture::id('staff', $staff),
            'start_utc' => LunaFixture::instant($from), 'end_utc' => LunaFixture::instant($to),
            'block_start_utc' => LunaFixture::instant($from->sub(new DateInterval(sprintf('PT%dM', $buffer)))),
            'block_end_utc' => LunaFixture::instant($to->add(new DateInterval(sprintf('PT%dM', $buffer)))),
            'processing_ends_utc' => $gap > 0 ? LunaFixture::instant($to->add(new DateInterval(sprintf('PT%dM', $gap)))) : null,
            'price_minor' => 3500, 'blocks' => false,
        ]);
    }

    return $uuid;
}

function calendarOwner(): User
{
    return User::query()->where('email', 'owner@luna.example')->sole();
}

/**
 * The window prop the Calendar answers for a query, as the viewer given.
 *
 * @param  array<string, string|int>  $query
 * @return array{from: string, to: string, who: string, staff: list<int>, members: list<array{id: int, name: string}>, hours: array<mixed>, next_working: array<mixed>, bookings: list<array<string, mixed>>}
 */
function calendarWindow(TestCase $test, User $viewer, array $query = []): array
{
    $window = data_get($test->actingAs($viewer)->get(tenantUrl('luna', '/admin/calendar'.($query === [] ? '' : '?'.http_build_query($query))))->assertOk()->viewData('page'), 'props.window');
    assert(is_array($window) && is_string($window['from']) && is_string($window['to']) && is_string($window['who']) && is_array($window['staff']) && is_array($window['members']) && is_array($window['hours']) && is_array($window['next_working']) && is_array($window['bookings']));

    $staff = array_values(array_filter($window['staff'], is_int(...)));
    $members = [];

    foreach ($window['members'] as $member) {
        assert(is_array($member) && is_int($member['id']) && is_string($member['name']));
        $members[] = ['id' => $member['id'], 'name' => $member['name']];
    }

    return ['from' => $window['from'], 'to' => $window['to'], 'who' => $window['who'], 'staff' => $staff, 'members' => $members, 'hours' => $window['hours'], 'next_working' => $window['next_working'], 'bookings' => calendarRows($window['bookings'])];
}

/**
 * The names of the members a window answers, in order.
 *
 * @param  array{members: list<array{id: int, name: string}>}  $window
 * @return list<string>
 */
function calendarMembers(array $window): array
{
    return array_column($window['members'], 'name');
}

/**
 * @param  array<mixed>  $rows
 * @return list<array<string, mixed>>
 */
function calendarRows(array $rows): array
{
    $typed = [];

    foreach ($rows as $row) {
        assert(is_array($row));
        $keyed = [];

        foreach ($row as $key => $value) {
            $keyed[(string) $key] = $value;
        }

        $typed[] = $keyed;
    }

    return $typed;
}

/**
 * The window's bookings keyed by uuid.
 *
 * @param  list<array<string, mixed>>  $bookings
 * @return array<string, array<string, mixed>>
 */
function calendarByUuid(array $bookings): array
{
    $byUuid = [];

    foreach ($bookings as $booking) {
        assert(is_string($booking['uuid'] ?? null));
        $byUuid[$booking['uuid']] = $booking;
    }

    return $byUuid;
}

/**
 * A booking's items as the wire carries them.
 *
 * @param  array<string, mixed>  $booking
 * @return list<array<string, mixed>>
 */
function calendarItems(array $booking): array
{
    assert(is_array($booking['items'] ?? null));

    return calendarRows($booking['items']);
}

/** Whether a catalog row of the given name is active in the options prop, which the page assertion hands over as a collection, or null where no row carries the name. */
function calendarActive(mixed $services, string $name): ?bool
{
    assert(is_iterable($services));

    foreach ($services as $service) {
        if (is_array($service) && ($service['name'] ?? null) === $name) {
            return $service['active'] === true;
        }
    }

    return null;
}

/**
 * @param  list<array<string, mixed>>  $bookings
 * @return list<string>
 */
function calendarUuids(array $bookings): array
{
    return array_map(fn (array $booking): string => is_string($booking['uuid'] ?? null) ? $booking['uuid'] : '', $bookings);
}

/** An instant as the wire spells it, read on the Athens clock. */
function calendarAthens(mixed $wire): string
{
    assert(is_string($wire));

    return (new DateTimeImmutable($wire, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/Athens'))->format('Y-m-d H:i');
}

it('opens on the tenant\'s today as one day, the day the screen opens on, not the machine\'s date, with the options, no detail and the Calendar entry current', function () {
    $this->actingAs(calendarOwner())->get(tenantUrl('luna', '/admin/calendar'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Calendar')
            ->where('window.from', '2026-11-05')
            ->where('window.to', '2026-11-06')
            ->where('window.who', 'scheduled')
            ->where('window.staff', [])
            ->where('window.members', [
                ['id' => LunaFixture::id('staff', 'Elena Papadaki'), 'name' => 'Elena Papadaki'],
                ['id' => LunaFixture::id('staff', 'Nikos Georgiou'), 'name' => 'Nikos Georgiou'],
            ])
            ->where('window.bookings', [])
            ->where('own', null)
            ->where('pinned', false)
            ->where('detail', null)
            ->where('options.statuses', ['pending', 'awaiting_approval', 'awaiting_payment', 'confirmed', 'completed', 'no_show', 'cancelled', 'rejected', 'expired'])
            ->where('options.staff', [
                ['id' => LunaFixture::id('staff', 'Elena Papadaki'), 'name' => 'Elena Papadaki'],
                ['id' => LunaFixture::id('staff', 'Nikos Georgiou'), 'name' => 'Nikos Georgiou'],
            ])
            ->where('options.members', DB::table('tenant_luna.staff')->count())
            ->has('options.services', DB::table('tenant_luna.services')->count())
            ->where('nav.0.label', navLabel('home'))
            ->where('nav.0.current', false)
            ->where('nav.1', ['label' => navLabel('calendar'), 'url' => '/admin/calendar', 'group' => navLabel('groups.schedule'), 'pending' => null, 'current' => true]));
});

it('reads the window a link opens on: the day its date names, the week of it under view=week from the tenant\'s week start, the six-week grid of its month under view=month, and today\'s week under view=week alone', function () {
    expect(array_intersect_key(calendarWindow($this, calendarOwner(), ['date' => '2026-11-12']), ['from' => 1, 'to' => 1]))->toBe(['from' => '2026-11-12', 'to' => '2026-11-13'])
        ->and(array_intersect_key(calendarWindow($this, calendarOwner(), ['view' => 'day', 'date' => '2026-11-12']), ['from' => 1, 'to' => 1]))->toBe(['from' => '2026-11-12', 'to' => '2026-11-13'])
        ->and(array_intersect_key(calendarWindow($this, calendarOwner(), ['view' => 'week', 'date' => '2026-11-12']), ['from' => 1, 'to' => 1]))->toBe(['from' => '2026-11-09', 'to' => '2026-11-16'])
        ->and(array_intersect_key(calendarWindow($this, calendarOwner(), ['view' => 'week']), ['from' => 1, 'to' => 1]))->toBe(['from' => '2026-11-02', 'to' => '2026-11-09'])
        ->and(array_intersect_key(calendarWindow($this, calendarOwner(), ['view' => 'month', 'date' => '2026-11-12']), ['from' => 1, 'to' => 1]))->toBe(['from' => '2026-10-26', 'to' => '2026-12-07'])
        ->and(array_intersect_key(calendarWindow($this, calendarOwner(), ['view' => 'nonsense', 'date' => 'not-a-day']), ['from' => 1, 'to' => 1]))->toBe(['from' => '2026-11-05', 'to' => '2026-11-06']);
});

it('offers active members alone and the full catalog with inactive rows marked, and drops a malformed or overlong window to the day it opens on', function () {
    DB::table('tenant_luna.staff')->where('name', 'Nikos Georgiou')->update(['status' => 'inactive']);
    DB::table('tenant_luna.services')->where('name', 'Colour')->update(['status' => 'inactive']);

    $this->actingAs(calendarOwner())->get(tenantUrl('luna', '/admin/calendar?from=2026-11-03&to=not-a-day&staff=abc'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('window.from', '2026-11-05')
            ->where('window.to', '2026-11-06')
            ->where('window.who', 'scheduled')
            ->where('window.staff', [])
            ->where('options.staff', [['id' => LunaFixture::id('staff', 'Elena Papadaki'), 'name' => 'Elena Papadaki']])
            ->where('options.services', fn (mixed $services): bool => calendarActive($services, 'Colour') === false && calendarActive($services, 'Haircut') === true));

    expect(calendarWindow($this, calendarOwner(), ['from' => '2026-11-03', 'to' => '2026-12-31'])['to'])->toBe('2026-11-06')
        ->and(calendarWindow($this, calendarOwner(), ['from' => '2026-11-10', 'to' => '2026-11-03'])['from'])->toBe('2026-11-05')
        ->and(CalendarWindow::weekOf('2026-11-08', 7)->from)->toBe('2026-11-08')
        ->and(CalendarWindow::weekOf('2026-11-07', 7)->from)->toBe('2026-11-01');

    // A date no calendar has is malformed and reads as the day the screen opens on; the calendar's
    // own first day is a date like any other, its midnight in Athens before the first instant a
    // bound can name, so the window starts there and answers what precedes nothing: no booking.
    expect(calendarWindow($this, calendarOwner(), ['from' => '0000-01-01', 'to' => '0000-01-08'])['from'])->toBe('2026-11-05')
        ->and(calendarWindow($this, calendarOwner(), ['from' => '2026-11-03', 'to' => '2026-11-31'])['from'])->toBe('2026-11-05')
        ->and(calendarWindow($this, calendarOwner(), ['from' => '0001-01-01', 'to' => '0001-01-08'])['from'])->toBe('0001-01-01')
        ->and(calendarWindow($this, calendarOwner(), ['from' => '0001-01-01', 'to' => '0001-01-08'])['bookings'])->toBe([]);
});

it('reads the six-week grid of a month whole in one read at the cap of 42 days, and drops a pair a day longer to the day it opens on', function () {
    // November 2026 opens on a Sunday, so the grid from luna's Monday week start runs from
    // Monday 26 October to Monday 7 December, exclusive: 42 days.
    $first = calendared([['Haircut', 'Elena Papadaki', '2026-10-26 00:15', 30]], 'Ana First', buffer: 0);
    $last = calendared([['Haircut', 'Elena Papadaki', '2026-12-06 23:00', 30]], 'Lu Last', buffer: 0);
    $after = calendared([['Haircut', 'Elena Papadaki', '2026-12-07 00:00', 30]], 'Al After', buffer: 0);

    $grid = calendarWindow($this, calendarOwner(), ['from' => '2026-10-26', 'to' => '2026-12-07']);
    $longer = calendarWindow($this, calendarOwner(), ['from' => '2026-10-26', 'to' => '2026-12-08']);

    expect(CalendarWindow::MAX_DAYS)->toBe(42)
        ->and(CalendarWindow::accepts('2026-10-26', '2026-12-07'))->toBeTrue()
        ->and(CalendarWindow::accepts('2026-10-26', '2026-12-08'))->toBeFalse()
        ->and([$grid['from'], $grid['to']])->toBe(['2026-10-26', '2026-12-07'])
        ->and(calendarUuids($grid['bookings']))->toBe([$first, $last])
        ->and([$longer['from'], $longer['to']])->toBe(['2026-11-05', '2026-11-06'])
        ->and(calendarUuids($longer['bookings']))->not->toContain($first, $last, $after);
});

it('reads the window asked for as the visible range with an exclusive end, every status, narrowed by Who alone', function () {
    $tuesday = calendared([['Haircut', 'Elena Papadaki', '2026-11-03 10:00', 45]], 'Ada North');
    $wednesday = calendared([['Beard trim', 'Nikos Georgiou', '2026-11-04 10:00', 30]], 'Bo South', 'cancelled');
    $lastMinute = calendared([['Haircut', 'Elena Papadaki', '2026-11-08 23:30', 30]], 'Di West', buffer: 0);
    $atTheEnd = calendared([['Haircut', 'Elena Papadaki', '2026-11-09 00:00', 30]], 'Ed Next', buffer: 0);
    $before = calendared([['Haircut', 'Elena Papadaki', '2026-11-01 23:30', 30]], 'Fay Past', buffer: 0);
    $crossing = calendared([['Haircut', 'Elena Papadaki', '2026-11-01 23:45', 30]], 'Gus Cross', buffer: 0);

    $week = calendarWindow($this, calendarOwner(), ['from' => '2026-11-02', 'to' => '2026-11-09']);
    $elena = calendarWindow($this, calendarOwner(), ['from' => '2026-11-02', 'to' => '2026-11-09', 'staff' => (string) LunaFixture::id('staff', 'Elena Papadaki')]);
    $day = calendarWindow($this, calendarOwner(), ['from' => '2026-11-04', 'to' => '2026-11-05']);

    expect($week['from'])->toBe('2026-11-02')
        ->and($week['to'])->toBe('2026-11-09')
        ->and(calendarUuids($week['bookings']))->toBe([$tuesday, $wednesday, $lastMinute, $crossing])
        ->and(calendarUuids($week['bookings']))->not->toContain($atTheEnd, $before)
        ->and($elena['who'])->toBe('picked')
        ->and($elena['staff'])->toBe([LunaFixture::id('staff', 'Elena Papadaki')])
        ->and(calendarMembers($elena))->toBe(['Elena Papadaki'])
        ->and(calendarUuids($elena['bookings']))->toBe([$tuesday, $lastMinute, $crossing])
        ->and(calendarUuids($day['bookings']))->toBe([$wednesday]);
});

it('keeps an item in the window when its occupied range alone crosses the start, the after-buffer outlasting the gap, and when the gap alone does, the gap outlasting the buffer', function () {
    // Each ends at 23:40 on Sunday the eighth; the window opens at midnight on Monday the ninth.
    $bufferOutlasts = calendared([['Haircut', 'Elena Papadaki', '2026-11-08 23:10', 30]], 'Hal Buffer', buffer: 30, gap: 15);
    $gapOutlasts = calendared([['Haircut', 'Elena Papadaki', '2026-11-08 23:10', 30]], 'Gia Gap', buffer: 5, gap: 30);
    $neither = calendared([['Haircut', 'Elena Papadaki', '2026-11-08 23:10', 30]], 'Ned Neither', buffer: 15, gap: 10);

    $next = calendarWindow($this, calendarOwner(), ['from' => '2026-11-09', 'to' => '2026-11-16']);
    $week = calendarWindow($this, calendarOwner(), ['from' => '2026-11-02', 'to' => '2026-11-09']);

    expect(calendarUuids($next['bookings']))->toBe([$bufferOutlasts, $gapOutlasts])
        ->and(calendarUuids($week['bookings']))->toBe([$bufferOutlasts, $gapOutlasts, $neither]);
});

it('carries both items of a chain with their occupied ranges and the processing end where a gap follows, and a booking near midnight as the instant it is', function () {
    $chain = calendared([['Haircut', 'Elena Papadaki', '2026-11-03 10:00', 45], ['Colour', 'Nikos Georgiou', '2026-11-03 11:00', 60]], 'Flo Chain', gap: 30);
    $plain = calendared([['Haircut', 'Elena Papadaki', '2026-11-03 00:15', 45]], 'Mid Night', buffer: 0);

    $bookings = calendarByUuid(calendarWindow($this, calendarOwner(), ['from' => '2026-11-02', 'to' => '2026-11-09'])['bookings']);
    $items = calendarItems($bookings[$chain] ?? []);
    $first = calendarItems($bookings[$plain] ?? [])[0] ?? [];

    expect($items)->toHaveCount(2)
        ->and(calendarAthens($items[0]['block_start_utc']))->toBe('2026-11-03 09:55')
        ->and(calendarAthens($items[0]['block_end_utc']))->toBe('2026-11-03 10:50')
        ->and(calendarAthens($items[0]['processing_ends_utc']))->toBe('2026-11-03 11:15')
        ->and($items[0]['staff_id'])->toBe(LunaFixture::id('staff', 'Elena Papadaki'))
        ->and($items[1]['staff_id'])->toBe(LunaFixture::id('staff', 'Nikos Georgiou'))
        ->and(calendarAthens($items[1]['start']))->toBe('2026-11-03 11:00')
        ->and(calendarAthens($items[1]['processing_ends_utc']))->toBe('2026-11-03 12:30')
        ->and($bookings[$chain]['customer_email'] ?? null)->toBe('flo-chain@example.test')
        ->and($first['start'])->toBe('2026-11-02T22:15:00+00:00')
        ->and(calendarAthens($first['start']))->toBe('2026-11-03 00:15');
});

it('answers the bookings of a daylight-saving day in Athens on both sides of the change, each at its own wall-clock hour', function () {
    $before = calendared([['Haircut', 'Elena Papadaki', '2026-10-25 02:30', 30]], 'Early Bird', buffer: 0);
    $after = calendared([['Haircut', 'Elena Papadaki', '2026-10-25 04:30', 30]], 'Late Riser', buffer: 0);

    // A Sunday, when nobody at luna works: All staff draws what Scheduled staff would leave out.
    $bookings = calendarByUuid(calendarWindow($this, calendarOwner(), ['from' => '2026-10-25', 'to' => '2026-10-26', 'who' => 'all'])['bookings']);
    $early = calendarItems($bookings[$before] ?? [])[0] ?? [];
    $late = calendarItems($bookings[$after] ?? [])[0] ?? [];

    expect(array_keys($bookings))->toBe([$before, $after])
        ->and($early['start'] ?? null)->toBe('2026-10-24T23:30:00+00:00')
        ->and(calendarAthens($early['start'] ?? null))->toBe('2026-10-25 02:30')
        ->and($late['start'] ?? null)->toBe('2026-10-25T02:30:00+00:00')
        ->and(calendarAthens($late['start'] ?? null))->toBe('2026-10-25 04:30');
});

it('answers the dialog\'s detail for the booking named in the query and missing for one that is not, the page and its URL the calendar\'s', function () {
    $uuid = calendared([['Bridal trial', 'Elena Papadaki', '2026-11-03 10:00', 60]], 'Rita Request', 'awaiting_approval');

    $this->actingAs(calendarOwner())->get(tenantUrl('luna', '/admin/calendar?booking='.$uuid))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Calendar')
            ->where('detail.state', 'found')
            ->where('detail.booking.uuid', $uuid)
            ->where('detail.booking.customer_email', 'rita-request@example.test')
            ->where('detail.trail', [])
            ->where('detail.actions', ['approve', 'reject', 'cancel']));

    $this->actingAs(calendarOwner())->get(tenantUrl('luna', '/admin/calendar?booking=nope'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('detail', ['state' => 'missing', 'uuid' => 'nope']));
});

it('carries no setup checklist, which moved to Home, to any viewer', function () {
    $manager = User::query()->findOrFail(addTenantUser('mira@luna.example', 'manager'));

    foreach ([calendarOwner(), $manager] as $viewer) {
        $this->actingAs($viewer)->get(tenantUrl('luna', '/admin/calendar'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Calendar')->missing('setup'));
    }
});

it('reads the view and the date a link names once, through the tolerant reader: each as read where it is one the screen knows, else null, and the window the range of what it opens on', function () {
    $opening = fn (string $query): mixed => data_get($this->actingAs(calendarOwner())->get(tenantUrl('luna', '/admin/calendar'.$query))->assertOk()->viewData('page'), 'props.opening');

    expect($opening('?view=day&date=2026-11-20'))->toBe(['view' => 'day', 'date' => '2026-11-20'])
        ->and($opening('?view=month'))->toBe(['view' => 'month', 'date' => null])
        ->and($opening('?view=week&date=2026-12-01'))->toBe(['view' => 'week', 'date' => '2026-12-01'])
        ->and($opening(''))->toBe(['view' => null, 'date' => null])
        ->and($opening('?view=year&date=2026-02-30'))->toBe(['view' => null, 'date' => null])
        ->and($opening('?view=%20day%20&date=20-11-2026'))->toBe(['view' => 'day', 'date' => null])
        ->and($opening('?view[]=day&date[]=2026-11-20'))->toBe(['view' => null, 'date' => null]);

    // The window is the range the screen opens on, so its first read is the only one.
    expect(array_intersect_key(calendarWindow($this, calendarOwner(), ['view' => 'day', 'date' => '2026-11-20']), ['from' => 1, 'to' => 1]))->toBe(['from' => '2026-11-20', 'to' => '2026-11-21']);
});

it('pins a viewer without viewBookings to their own row whatever the query: her items carry the occupied range, another member\'s do not, no email or phone reaches her, and only her bookings do', function () {
    $elena = User::query()->findOrFail(addTenantUser('elena@luna.example', 'staff', staff: 'Elena Papadaki'));
    $own = ['id' => LunaFixture::id('staff', 'Elena Papadaki'), 'name' => 'Elena Papadaki'];
    $chain = calendared([['Haircut', 'Elena Papadaki', '2026-11-03 10:00', 45], ['Colour', 'Nikos Georgiou', '2026-11-03 11:00', 60]], 'Flo Chain', gap: 30);
    $request = calendared([['Bridal trial', 'Elena Papadaki', '2026-11-04 10:00', 60]], 'Rita Request', 'awaiting_approval');
    $nikos = calendared([['Beard trim', 'Nikos Georgiou', '2026-11-04 12:00', 30]], 'Bo South');

    $this->actingAs($elena)->get(tenantUrl('luna', '/admin/calendar?staff='.LunaFixture::id('staff', 'Nikos Georgiou')))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Calendar')
            ->where('own', $own)
            ->where('pinned', true)
            ->where('options.staff', [$own])
            ->where('options.members', DB::table('tenant_luna.staff')->count())
            ->where('window.who', 'me')
            ->where('window.staff', [])
            ->where('window.members', [$own])
            ->where('nav.0.label', navLabel('calendar'))
            ->where('nav.0.current', true)
            ->where('catalog', null)
            ->missing('setup'));

    foreach ([[], ['who' => 'all'], ['staff' => (string) LunaFixture::id('staff', 'Nikos Georgiou')]] as $query) {
        $window = calendarWindow($this, $elena, ['from' => '2026-11-02', 'to' => '2026-11-09', ...$query]);
        $bookings = calendarByUuid($window['bookings']);
        $items = calendarItems($bookings[$chain] ?? []);

        expect($window['who'])->toBe('me')
            ->and($window['members'])->toBe([$own])
            ->and(array_keys($window['hours']))->toBe([$own['id']])
            ->and(array_keys($bookings))->toBe([$chain, $request])
            ->and(array_keys($bookings))->not->toContain($nikos)
            ->and($bookings[$chain] ?? [])->not->toHaveKeys(['customer_email', 'customer_phone', 'payment_mode'])
            ->and($items[0] ?? [])->toHaveKeys(['block_start_utc', 'block_end_utc', 'processing_ends_utc'])
            ->and($items[1] ?? [])->not->toHaveKeys(['block_start_utc', 'block_end_utc', 'processing_ends_utc'])
            ->and(calendarAthens($items[0]['processing_ends_utc'] ?? null))->toBe('2026-11-03 11:15');
    }

    $this->actingAs($elena)->get(tenantUrl('luna', '/admin/calendar?booking='.$request))
        ->assertInertia(fn (Assert $page) => $page
            ->where('detail.state', 'found')
            ->where('detail.actions', ['approve', 'reject'])
            ->missing('detail.booking.customer_email'));

    $this->actingAs($elena)->get(tenantUrl('luna', '/admin/calendar?booking='.$nikos))
        ->assertInertia(fn (Assert $page) => $page->where('detail', ['state' => 'missing', 'uuid' => $nikos]));
});

it('hands a pinned viewer with no linked row a null row, no members, an empty window and no detail', function () {
    $sam = User::query()->findOrFail(addTenantUser('sam@luna.example', 'staff'));
    $uuid = calendared([['Haircut', 'Elena Papadaki', '2026-11-03 10:00', 45]]);

    $this->actingAs($sam)->get(tenantUrl('luna', '/admin/calendar?who=all&booking='.$uuid))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Calendar')
            ->where('own', null)
            ->where('pinned', true)
            ->where('options.staff', [])
            ->where('window.from', '2026-11-05')
            ->where('window.to', '2026-11-06')
            ->where('window.who', 'me')
            ->where('window.members', [])
            ->where('window.hours', [])
            ->where('window.bookings', [])
            ->where('detail', null));
});

it('redirects the retired My calendar address to the Calendar by its route name for every principal', function () {
    $elena = User::query()->findOrFail(addTenantUser('elena@luna.example', 'staff', staff: 'Elena Papadaki'));

    $this->actingAs($elena)->get(tenantUrl('luna', '/admin/my-calendar'))->assertRedirect(tenantUrl('luna', '/admin/calendar'));
    $this->actingAs(calendarOwner())->get(tenantUrl('luna', '/admin/my-calendar'))->assertRedirect(tenantUrl('luna', '/admin/calendar'));
    $this->actingAs($elena)->get(tenantUrl('luna', '/admin/calendar'))->assertOk();
});

it('resolves Scheduled staff on the server from the hours in force: Week everyone with hours on a day of the range, Day everyone with hours that day, nobody on a day nobody works, and a closure adds nobody', function () {
    $monday = calendared([['Haircut', 'Elena Papadaki', '2026-11-02 10:00', 45]], 'Mo Monday');
    $nikosMonday = calendared([['Beard trim', 'Nikos Georgiou', '2026-11-02 12:00', 30]], 'Ni Off');
    $saturday = calendared([['Beard trim', 'Nikos Georgiou', '2026-11-07 12:00', 30]], 'Sa Turday');

    $week = calendarWindow($this, calendarOwner(), ['from' => '2026-11-02', 'to' => '2026-11-09']);
    $mon = calendarWindow($this, calendarOwner(), ['from' => '2026-11-02', 'to' => '2026-11-03']);
    $sat = calendarWindow($this, calendarOwner(), ['from' => '2026-11-07', 'to' => '2026-11-08', 'who' => 'scheduled']);
    $sun = calendarWindow($this, calendarOwner(), ['from' => '2026-11-08', 'to' => '2026-11-09', 'who' => 'nonsense']);

    // A closure on a Sunday for Elena alone brings her onto no Sunday.
    DB::table('tenant_luna.blackouts')->insert(['staff_id' => LunaFixture::id('staff', 'Elena Papadaki'), 'date' => '2026-11-08', 'start_time' => '10:00', 'end_time' => '11:00', 'reason' => 'Errand']);
    $closed = calendarWindow($this, calendarOwner(), ['from' => '2026-11-08', 'to' => '2026-11-09']);

    expect(calendarMembers($week))->toBe(['Elena Papadaki', 'Nikos Georgiou'])
        ->and(calendarUuids($week['bookings']))->toBe([$monday, $nikosMonday, $saturday])
        ->and(calendarMembers($mon))->toBe(['Elena Papadaki'])
        // Nikos holds no hours on Monday, so his booking that day is not drawn under Scheduled
        // staff.
        ->and(calendarUuids($mon['bookings']))->toBe([$monday])
        ->and(calendarMembers($sat))->toBe(['Nikos Georgiou'])
        ->and(calendarUuids($sat['bookings']))->toBe([$saturday])
        ->and($sun['who'])->toBe('scheduled')
        ->and($sun['members'])->toBe([])
        ->and($sun['hours'])->toBe([])
        ->and($sun['bookings'])->toBe([])
        ->and($closed['members'])->toBe([]);
});

it('answers All staff as every active member and every booking, Me as the own row, and a picked list as the ids that exist, inactive members included and the implicit row never', function () {
    $owner = User::query()->findOrFail(addTenantUser('ana@luna.example', 'owner', staff: 'Nikos Georgiou'));
    $elena = LunaFixture::id('staff', 'Elena Papadaki');
    $nikos = LunaFixture::id('staff', 'Nikos Georgiou');
    $implicit = DB::table('tenant_luna.staff')->where('is_implicit', true)->value('id');
    assert(is_int($implicit));
    $sunday = calendared([['Haircut', 'Elena Papadaki', '2026-11-08 10:00', 45]], 'Su Nday');
    $tuesday = calendared([['Beard trim', 'Nikos Georgiou', '2026-11-03 12:00', 30]], 'Tu Esday');

    $all = calendarWindow($this, calendarOwner(), ['from' => '2026-11-08', 'to' => '2026-11-09', 'who' => 'all']);
    $me = calendarWindow($this, $owner, ['from' => '2026-11-02', 'to' => '2026-11-09', 'who' => 'me']);
    $nobody = calendarWindow($this, calendarOwner(), ['from' => '2026-11-02', 'to' => '2026-11-09', 'who' => 'me']);
    DB::table('tenant_luna.staff')->where('id', $nikos)->update(['status' => 'inactive']);
    $picked = calendarWindow($this, calendarOwner(), ['from' => '2026-11-02', 'to' => '2026-11-09', 'staff' => $nikos.',999999,'.$elena.','.$implicit.','.$nikos]);
    $malformed = calendarWindow($this, calendarOwner(), ['from' => '2026-11-02', 'to' => '2026-11-09', 'staff' => $nikos.',x', 'who' => 'all']);
    $asked = [$elena, $nikos, $implicit, 999999];
    sort($asked);

    expect($all['who'])->toBe('all')
        ->and(calendarMembers($all))->toBe(['Elena Papadaki', 'Nikos Georgiou'])
        ->and(calendarUuids($all['bookings']))->toBe([$sunday])
        ->and($me['who'])->toBe('me')
        ->and(calendarMembers($me))->toBe(['Nikos Georgiou'])
        ->and(calendarUuids($me['bookings']))->toBe([$tuesday])
        ->and($nobody['members'])->toBe([])
        ->and($nobody['bookings'])->toBe([])
        ->and($picked['who'])->toBe('picked')
        // The list echoed is the one asked, sorted and without repeats, so the screen's own
        // compares.
        ->and($picked['staff'])->toBe($asked)
        ->and(calendarMembers($picked))->toBe(['Elena Papadaki', 'Nikos Georgiou'])
        ->and(calendarUuids($picked['bookings']))->toBe([$sunday, $tuesday])
        ->and($malformed['who'])->toBe('all')
        ->and($malformed['staff'])->toBe([]);
});

it('draws the implicit row\'s bookings under Scheduled staff and All staff though it is never a member listed, and leaves them out under Me and a picked set', function () {
    $owner = User::query()->findOrFail(addTenantUser('ana@luna.example', 'owner', staff: 'Nikos Georgiou'));
    $implicit = DB::table('tenant_luna.staff')->where('is_implicit', true)->value('id');
    assert(is_int($implicit));
    $uuid = calendared([['Haircut', 'Elena Papadaki', '2026-11-03 10:00', 45]], 'Bea Business');
    DB::table('tenant_luna.booking_items')->where('booking_id', DB::table('tenant_luna.bookings')->where('uuid', $uuid)->value('id'))->update(['staff_id' => $implicit]);
    $week = ['from' => '2026-11-02', 'to' => '2026-11-09'];

    $scheduled = calendarWindow($this, $owner, $week);
    $all = calendarWindow($this, $owner, [...$week, 'who' => 'all']);
    $me = calendarWindow($this, $owner, [...$week, 'who' => 'me']);
    $picked = calendarWindow($this, $owner, [...$week, 'staff' => (string) LunaFixture::id('staff', 'Elena Papadaki')]);
    $implicitOf = fn (TestCase $test, array $query): mixed => data_get($test->actingAs($owner)->get(tenantUrl('luna', '/admin/calendar?'.http_build_query($query)))->viewData('page'), 'props.window.implicit');

    expect(calendarUuids($scheduled['bookings']))->toBe([$uuid])
        ->and(calendarMembers($scheduled))->toBe(['Elena Papadaki', 'Nikos Georgiou'])
        ->and($implicitOf($this, $week))->toBe($implicit)
        ->and(calendarUuids($all['bookings']))->toBe([$uuid])
        ->and($implicitOf($this, [...$week, 'who' => 'all']))->toBe($implicit)
        ->and($me['bookings'])->toBe([])
        ->and($implicitOf($this, [...$week, 'who' => 'me']))->toBeNull()
        ->and($picked['bookings'])->toBe([]);
});

it('carries the closures of a row whose bookings are drawn without its hours, the business\'s and its own, and none for a row the hours cover', function () {
    $owner = User::query()->findOrFail(addTenantUser('ana@luna.example', 'owner', staff: 'Nikos Georgiou'));
    $implicit = DB::table('tenant_luna.staff')->where('is_implicit', true)->value('id');
    assert(is_int($implicit));
    $uuid = calendared([['Haircut', 'Elena Papadaki', '2026-11-03 10:00', 45]], 'Bea Business');
    DB::table('tenant_luna.booking_items')->where('booking_id', DB::table('tenant_luna.bookings')->where('uuid', $uuid)->value('id'))->update(['staff_id' => $implicit]);
    DB::table('tenant_luna.blackouts')->insert(['staff_id' => null, 'date' => '2026-11-03', 'start_time' => '10:00', 'end_time' => '11:00', 'reason' => 'Inspection']);
    DB::table('tenant_luna.blackouts')->insert(['staff_id' => $implicit, 'date' => '2026-11-04', 'start_time' => null, 'end_time' => null, 'reason' => null]);
    $closuresOf = fn (array $query): mixed => data_get($this->actingAs($owner)->get(tenantUrl('luna', '/admin/calendar?'.http_build_query($query)))->viewData('page'), 'props.window.closures');

    $none = [];

    // The implicit row alone, every date of the week, the business's closure on the Tuesday and
    // its own on the Wednesday.
    expect($closuresOf(['from' => '2026-11-02', 'to' => '2026-11-09']))->toBe([
        $implicit => [
            '2026-11-02' => $none,
            '2026-11-03' => [['start' => '10:00', 'end' => '11:00', 'reason' => 'Inspection', 'business' => true]],
            '2026-11-04' => [['start' => null, 'end' => null, 'reason' => null, 'business' => false]],
            '2026-11-05' => $none,
            '2026-11-06' => $none,
            '2026-11-07' => $none,
            '2026-11-08' => $none,
        ],
    ])
        ->and($closuresOf(['from' => '2026-11-09', 'to' => '2026-11-16']))->toBe([]);
});

it('carries no closures of a row the screen does not draw: a colleague\'s service in a chain booking brings none of their closures or reasons to a pinned viewer, under a picked set or under Scheduled staff, and brings them under All staff', function () {
    $pinned = User::query()->findOrFail(addTenantUser('elena@luna.example', 'staff', staff: 'Elena Papadaki'));
    $elena = LunaFixture::id('staff', 'Elena Papadaki');
    $nikos = LunaFixture::id('staff', 'Nikos Georgiou');
    // Monday: Elena works and Nikos does not, so Scheduled staff of that day shows her alone.
    calendared([['Haircut', 'Elena Papadaki', '2026-11-02 10:00', 45], ['Colour', 'Nikos Georgiou', '2026-11-02 11:00', 60]], 'Flo Chain');
    DB::table('tenant_luna.blackouts')->insert(['staff_id' => $nikos, 'date' => '2026-11-02', 'start_time' => '11:00', 'end_time' => '12:00', 'reason' => 'Dentist']);
    $closuresOf = fn (User $viewer, array $query): mixed => data_get($this->actingAs($viewer)->get(tenantUrl('luna', '/admin/calendar?'.http_build_query(['from' => '2026-11-02', 'to' => '2026-11-03', ...$query])))->assertOk()->viewData('page'), 'props.window.closures');

    expect($closuresOf($pinned, []))->toBe([])
        ->and($closuresOf($pinned, ['who' => 'all']))->toBe([])
        ->and($closuresOf(calendarOwner(), ['staff' => $elena]))->toBe([])
        ->and($closuresOf(calendarOwner(), ['who' => 'scheduled']))->toBe([]);

    // All staff draws every row, a member no longer active among them, whose hours it does not
    // carry: theirs are the closures sent.
    DB::table('tenant_luna.staff')->where('id', $nikos)->update(['status' => 'inactive']);

    expect($closuresOf(calendarOwner(), ['who' => 'all']))->toBe([$nikos => ['2026-11-02' => [['start' => '11:00', 'end' => '12:00', 'reason' => 'Dentist', 'business' => false]]]]);
});

it('carries each shown member\'s hours per date of the window: the windows in force through the resolver seam and the closures that reach them, the business\'s and their own, with reasons', function () {
    $elena = LunaFixture::id('staff', 'Elena Papadaki');
    $nikos = LunaFixture::id('staff', 'Nikos Georgiou');
    WeeklyHours::add('tenant_luna', $nikos, 6, '20:00', '24:00');

    // Thursday the third to Saturday the fifth of December 2026: Nikos's dentist on the Friday.
    $window = calendarWindow($this, calendarOwner(), ['from' => '2026-12-03', 'to' => '2026-12-06', 'who' => 'all']);
    $christmas = calendarWindow($this, calendarOwner(), ['from' => '2026-12-25', 'to' => '2026-12-26', 'who' => 'all']);
    $split = [['start' => '09:00', 'end' => '13:00'], ['start' => '14:00', 'end' => '18:00']];
    $through = [['start' => '10:00', 'end' => '19:00']];
    $none = [];
    $closedAllDay = [['start' => null, 'end' => null, 'reason' => 'Christmas Day', 'business' => true]];

    $saturday = [...$through, ['start' => '20:00', 'end' => '24:00']];

    expect($window['hours'])->toBe([
        $elena => [
            '2026-12-03' => ['windows' => $split, 'closures' => $none, 'source' => 'pattern', 'pattern' => $split],
            '2026-12-04' => ['windows' => $split, 'closures' => $none, 'source' => 'pattern', 'pattern' => $split],
            '2026-12-05' => ['windows' => $none, 'closures' => $none, 'source' => 'none', 'pattern' => $none],
        ],
        $nikos => [
            '2026-12-03' => ['windows' => $through, 'closures' => $none, 'source' => 'pattern', 'pattern' => $through],
            '2026-12-04' => ['windows' => $through, 'closures' => [['start' => '14:00', 'end' => '16:00', 'reason' => 'Dentist', 'business' => false]], 'source' => 'pattern', 'pattern' => $through],
            '2026-12-05' => ['windows' => $saturday, 'closures' => $none, 'source' => 'pattern', 'pattern' => $saturday],
        ],
    ])
        // Both work a day of the window, so neither has a next working day to name.
        ->and($window['next_working'])->toBe([])
        ->and($christmas['hours'])->toBe([
            $elena => ['2026-12-25' => ['windows' => $split, 'closures' => $closedAllDay, 'source' => 'pattern', 'pattern' => $split]],
            $nikos => ['2026-12-25' => ['windows' => $through, 'closures' => $closedAllDay, 'source' => 'pattern', 'pattern' => $through]],
        ]);
});

it('carries each day\'s source and the windows its pattern gives that date for the day editor, an override\'s date its own windows over the pattern\'s', function () {
    $elena = LunaFixture::id('staff', 'Elena Papadaki');
    DB::table('tenant_luna.shift_overrides')->insert(['staff_id' => $elena, 'date' => '2026-12-03', 'windows' => '[{"start": "11:00", "end": "15:00"}]']);
    DB::table('tenant_luna.shift_overrides')->insert(['staff_id' => $elena, 'date' => '2026-12-04', 'windows' => '[]']);

    $window = calendarWindow($this, calendarOwner(), ['from' => '2026-12-03', 'to' => '2026-12-05', 'staff' => (string) $elena]);
    $split = [['start' => '09:00', 'end' => '13:00'], ['start' => '14:00', 'end' => '18:00']];

    expect($window['hours'])->toBe([
        $elena => [
            '2026-12-03' => ['windows' => [['start' => '11:00', 'end' => '15:00']], 'closures' => [], 'source' => 'override', 'pattern' => $split],
            '2026-12-04' => ['windows' => [], 'closures' => [], 'source' => 'override', 'pattern' => $split],
        ],
    ]);
});

it('names the next working day of a member with no hours in the window, through the resolver seam up to eight weeks ahead: its date and windows, a day closed whole passed over, null past the eight weeks, and nothing for a member who works in the window', function () {
    $elena = LunaFixture::id('staff', 'Elena Papadaki');
    $nikos = LunaFixture::id('staff', 'Nikos Georgiou');
    $split = [['start' => '09:00', 'end' => '13:00'], ['start' => '14:00', 'end' => '18:00']];
    $through = [['start' => '10:00', 'end' => '19:00']];

    // Sunday the eighth: nobody works; Elena's Monday, Nikos's Tuesday.
    $sunday = calendarWindow($this, calendarOwner(), ['from' => '2026-11-08', 'to' => '2026-11-09', 'who' => 'all']);
    // Monday the ninth: Elena works, Nikos does not.
    $monday = calendarWindow($this, calendarOwner(), ['from' => '2026-11-09', 'to' => '2026-11-10', 'who' => 'all']);

    // Elena closed all day on the Monday, a closure of an hour on the Tuesday leaving her the day.
    DB::table('tenant_luna.blackouts')->insert(['staff_id' => $elena, 'date' => '2026-11-09', 'start_time' => null, 'end_time' => null, 'reason' => 'Leave']);
    DB::table('tenant_luna.blackouts')->insert(['staff_id' => $elena, 'date' => '2026-11-10', 'start_time' => '10:00', 'end_time' => '11:00', 'reason' => 'Errand']);
    $closed = calendarWindow($this, calendarOwner(), ['from' => '2026-11-08', 'to' => '2026-11-09', 'who' => 'all']);

    // Nikos's pattern gone: nothing in the eight weeks.
    WeeklyHours::clear('tenant_luna', $nikos);
    $gone = calendarWindow($this, calendarOwner(), ['from' => '2026-11-08', 'to' => '2026-11-09', 'who' => 'all']);

    // A Shifts override eight weeks after the Sunday shown, the scan's last day (to + 55), gives
    // him a day the scan reaches; eight weeks and a day does not.
    DB::table('tenant_luna.shift_overrides')->insert(['staff_id' => $nikos, 'date' => '2027-01-03', 'windows' => '[{"start": "12:00", "end": "16:00"}]']);
    $override = calendarWindow($this, calendarOwner(), ['from' => '2026-11-08', 'to' => '2026-11-09', 'staff' => (string) $nikos]);
    DB::table('tenant_luna.shift_overrides')->where('staff_id', $nikos)->update(['date' => '2027-01-04']);
    $past = calendarWindow($this, calendarOwner(), ['from' => '2026-11-08', 'to' => '2026-11-09', 'staff' => (string) $nikos]);

    expect($sunday['next_working'])->toBe([
        $elena => ['date' => '2026-11-09', 'windows' => $split],
        $nikos => ['date' => '2026-11-10', 'windows' => $through],
    ])
        ->and($monday['next_working'])->toBe([$nikos => ['date' => '2026-11-10', 'windows' => $through]])
        ->and($closed['next_working'][$elena])->toBe(['date' => '2026-11-10', 'windows' => $split])
        ->and($gone['next_working'][$nikos])->toBeNull()
        ->and($override['next_working'])->toBe([$nikos => ['date' => '2027-01-03', 'windows' => [['start' => '12:00', 'end' => '16:00']]]])
        ->and($past['next_working'])->toBe([$nikos => null]);
});

it('carries the shifts section of the admin\'s words, which Day\'s column menu mounts the day editor with', function () {
    $this->actingAs(calendarOwner())->get(tenantUrl('luna', '/admin/calendar'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('strings.shifts', __('admin/shifts', [], 'en')));
});

it('carries its own calendar section and the bookings section the dialog reads beside the shell\'s, each in the request\'s language', function () {
    $this->actingAs(calendarOwner())->get(tenantUrl('luna', '/admin/calendar'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('strings.calendar', __('admin/calendar', [], 'en'))
            ->where('strings.bookings', __('admin/bookings', [], 'en'))
            ->where('strings.shell', __('admin/shell', [], 'en')));
});

it('decides the own-row clause in the whitelist itself: the item-manage columns on the viewer\'s row alone, the contact withheld, and the walk of every bucket unchanged', function () {
    $tenant = Tenant::query()->where('slug', 'luna')->sole();
    $uuid = calendared([['Haircut', 'Elena Papadaki', '2026-11-03 10:00', 45], ['Colour', 'Nikos Georgiou', '2026-11-03 11:00', 60]], 'Flo Chain', gap: 30);
    $record = app(BookingRecords::class)->one($tenant, $uuid);
    assert($record !== null);
    $elena = LunaFixture::id('staff', 'Elena Papadaki');

    $own = BookingPayload::admin($record, false, $elena);
    $none = BookingPayload::admin($record, false, null);
    $manage = BookingPayload::admin($record, true, $elena);

    $guestKeys = array_keys(BookingPayload::guest($record->snapshot)['items'][0] ?? []);

    expect(array_keys(calendarItems($own)[0] ?? []))->toBe([...$guestKeys, ...BookingPayload::ITEM_MANAGE_ONLY])
        ->and(array_keys(calendarItems($own)[1] ?? []))->toBe($guestKeys)
        ->and($own)->not->toHaveKeys(BookingPayload::CONTACT)
        ->and($own)->not->toHaveKeys(BookingPayload::MANAGE_ONLY)
        ->and(array_keys(calendarItems($none)[0] ?? []))->toBe($guestKeys)
        ->and(array_keys(calendarItems($manage)[1] ?? []))->toBe([...$guestKeys, ...BookingPayload::ITEM_MANAGE_ONLY]);
});

it('classifies every live column for the own-row audience too: an item on the viewer\'s row names every live item column but the withheld ones, and the booking names no contact and no manage-only column', function () {
    $tenant = Tenant::query()->where('slug', 'luna')->sole();
    $uuid = calendared([['Haircut', 'Elena Papadaki', '2026-11-03 10:00', 45]], 'Ola Own');
    $record = app(BookingRecords::class)->one($tenant, $uuid);
    assert($record !== null);

    $own = BookingPayload::admin($record, false, LunaFixture::id('staff', 'Elena Papadaki'));
    $item = calendarItems($own)[0] ?? [];
    $bookingKeys = array_values(array_diff(array_keys($own), ['items']));
    $itemKeys = array_values(array_diff(array_keys($item), ['service_name', 'staff_name', 'start', 'end']));
    $liveBookings = array_values(array_diff(liveColumns('tenant_luna', 'bookings'), BookingPayload::WITHHELD, BookingPayload::CONTACT, BookingPayload::MANAGE_ONLY));
    $liveItems = array_values(array_diff(liveColumns('tenant_luna', 'booking_items'), BookingPayload::ITEM_WITHHELD, ['start_utc', 'end_utc']));
    sort($bookingKeys);
    sort($itemKeys);
    sort($liveBookings);
    sort($liveItems);

    expect($bookingKeys)->toBe($liveBookings)->and($itemKeys)->toBe($liveItems);
});

it('carries each item\'s service category for Colour by, null where the service has none, and offers the catalogue\'s distinct categories sorted', function () {
    DB::table('tenant_luna.services')->where('name', 'Haircut')->update(['category' => 'Hair']);
    DB::table('tenant_luna.services')->where('name', 'Colour')->update(['category' => 'Colouring']);
    DB::table('tenant_luna.services')->where('name', 'Beard trim')->update(['category' => 'Hair']);
    $uuid = calendared([['Haircut', 'Elena Papadaki', '2026-11-03 10:00', 45], ['Colour', 'Elena Papadaki', '2026-11-03 11:00', 60]]);
    $plain = calendared([['Bridal trial', 'Elena Papadaki', '2026-11-04 10:00', 60]], 'Pia Plain');

    $window = calendarWindow($this, calendarOwner(), ['who' => 'all', 'view' => 'week']);
    $byUuid = array_column($window['bookings'], null, 'uuid');

    expect(array_column(calendarItems($byUuid[$uuid] ?? []), 'category'))->toBe(['Hair', 'Colouring'])
        ->and(array_column(calendarItems($byUuid[$plain] ?? []), 'category'))->toBe([null]);

    $this->actingAs(calendarOwner())->get(tenantUrl('luna', '/admin/calendar'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('options.categories', ['Colouring', 'Hair']));
});

/**
 * Last week's window under Scheduled staff, Monday 2026-10-26 to Monday 2026-11-02, with Elena's
 * visit on its Tuesday, read as the owner.
 *
 * @return array{from: string, to: string, who: string, staff: list<int>, members: list<array{id: int, name: string}>, hours: array<mixed>, bookings: list<array<string, mixed>>}
 */
function calendarLastWeek(TestCase $test): array
{
    return calendarWindow($test, calendarOwner(), ['from' => '2026-10-26', 'to' => '2026-11-02', 'who' => 'scheduled']);
}

it('keeps last week as it was drawn after a weekly pattern of the same windows is saved on Shifts, whatever start the body names: the member scheduled, their visit shown and their hours the same, the pattern on the week of 2000-01-01', function () {
    $elena = LunaFixture::id('staff', 'Elena Papadaki');
    $uuid = calendared([['Haircut', 'Elena Papadaki', '2026-10-27 10:00', 45]]);
    $before = calendarLastWeek($this);
    $week = DB::table('tenant_luna.shift_patterns')->where('staff_id', $elena)->value('windows');
    assert(is_string($week));

    $this->actingAs(calendarOwner())->from(tenantUrl('luna', '/admin/shifts'))
        ->json('PUT', tenantUrl('luna', '/admin/shifts/'.$elena.'/pattern'), ['cadence_weeks' => 1, 'starts_on' => '2026-11-30', 'ends_on' => null, 'windows' => json_decode($week, true)], ['X-Inertia' => 'true'])
        ->assertRedirect(tenantUrl('luna', '/admin/shifts'));

    $after = calendarLastWeek($this);

    expect(calendarMembers($before))->toContain('Elena Papadaki')
        ->and(calendarUuids($before['bookings']))->toBe([$uuid])
        ->and(DB::table('tenant_luna.shift_patterns')->where('staff_id', $elena)->value('starts_on'))->toBe('1999-12-27')
        ->and(calendarMembers($after))->toBe(calendarMembers($before))
        ->and(calendarUuids($after['bookings']))->toBe([$uuid])
        ->and($after['hours'])->toBe($before['hours']);
});

it('reads Who\'s summary of a picked set by how many others, one reaching the first case and two the second, in Greek and in English, the name filled where it stands', function () {
    $key = 'admin/calendar.who.picked';
    $walked = 0;

    foreach ([SiteLanguage::Greek, SiteLanguage::English] as $language) {
        $raw = __($key, [], $language->value);
        assert(is_string($raw));
        $cases = explode('|', $raw);

        expect($cases)->toHaveCount(2)
            ->and($cases[0])->toStartWith('{1} ')
            ->and($cases[1])->toStartWith('[2,*] ');

        foreach ([1 => 0, 2 => 1] as $count => $case) {
            $filled = strtr(substr($cases[$case], strpos($cases[$case], ' ') + 1), [':count' => (string) $count, ':staff' => 'name-token']);
            expect(adminChoice($key, $count, ['staff' => 'name-token'], $language))->toBe($filled)
                ->and($filled)->toContain('name-token');
            $walked++;
        }
    }

    expect($walked)->toBe(4)
        ->and(adminChoice($key, 2, ['staff' => 'name-token'], SiteLanguage::Greek))->not->toBe(adminChoice($key, 2, ['staff' => 'name-token']));
});

it('marks each booking the customer wrote a note on with noted, one flag and never the words, for the tenant and for a viewer pinned to her row', function () {
    $elena = User::query()->findOrFail(addTenantUser('elena@luna.example', 'staff', staff: 'Elena Papadaki'));
    $noted = calendared([['Haircut', 'Elena Papadaki', '2026-11-03 10:00', 45]], 'Nora Noted');
    $plain = calendared([['Haircut', 'Elena Papadaki', '2026-11-03 12:00', 45]], 'Pia Plain');
    $nikos = calendared([['Beard trim', 'Nikos Georgiou', '2026-11-04 12:00', 30]], 'Bo South');

    foreach ([$noted, $nikos] as $uuid) {
        DB::table('tenant_luna.booking_meta')->insert(['booking_id' => DB::table('tenant_luna.bookings')->where('uuid', $uuid)->value('id'), 'key' => 'note', 'value' => 'Please use the side door']);
    }

    $flags = fn (User $viewer): array => array_map(fn (array $booking): mixed => $booking['noted'] ?? 'absent', calendarByUuid(calendarWindow($this, $viewer, ['from' => '2026-11-02', 'to' => '2026-11-09', 'who' => 'all'])['bookings']));
    $page = fn (User $viewer): string => (string) $this->actingAs($viewer)->get(tenantUrl('luna', '/admin/calendar?from=2026-11-02&to=2026-11-09&who=all'))->getContent();

    expect($flags(calendarOwner()))->toBe([$noted => true, $plain => false, $nikos => true])
        ->and($page(calendarOwner()))->not->toContain('side door')
        ->and($flags($elena))->toBe([$noted => true, $plain => false])
        ->and($page($elena))->not->toContain('side door');
});

it('names the dates of the window the business is closed all day: a weekday no opening hours reach and a date a business closure takes whole, and never a part-day closure or a member\'s own', function () {
    $closedDays = fn (array $query): mixed => data_get($this->actingAs(calendarOwner())->get(tenantUrl('luna', '/admin/calendar?'.http_build_query($query)))->viewData('page'), 'props.window.closed_days');
    DB::table('tenant_luna.blackouts')->insert([
        ['staff_id' => null, 'date' => '2026-12-22', 'start_time' => '10:00', 'end_time' => '11:00', 'reason' => 'Inspection'],
        ['staff_id' => LunaFixture::id('staff', 'Nikos Georgiou'), 'date' => '2026-12-23', 'start_time' => null, 'end_time' => null, 'reason' => null],
        ['staff_id' => null, 'date' => '2026-12-24', 'start_time' => '00:00', 'end_time' => '23:59', 'reason' => 'Stocktake'],
    ]);

    // Sunday is the day no member works, and the business is closed on Christmas Day; the
    // stocktake spans every opening hour of the Thursday, so it closes that day too.
    expect($closedDays(['from' => '2026-12-21', 'to' => '2026-12-28']))->toBe(['2026-12-24', '2026-12-25', '2026-12-27'])
        ->and($closedDays(['from' => '2026-12-21', 'to' => '2026-12-28', 'who' => 'all']))->toBe(['2026-12-24', '2026-12-25', '2026-12-27'])
        ->and($closedDays(['from' => '2026-11-03', 'to' => '2026-11-04']))->toBe([])
        ->and($closedDays(['view' => 'month', 'date' => '2026-11-10']))->toBe(['2026-11-01', '2026-11-08', '2026-11-15', '2026-11-22', '2026-11-29', '2026-12-06']);
});

it('counts the business closures of one date together where they overlap or touch: a day is closed once no opening minute is left outside them, and open while a minute or one of its two windows is', function () {
    $elena = LunaFixture::id('staff', 'Elena Papadaki');
    $closure = fn (string $date, string $start, string $end, ?int $staffId = null): array => ['staff_id' => $staffId, 'date' => $date, 'start_time' => $start, 'end_time' => $end, 'reason' => null];
    // The business opens Monday in two windows around lunch, Tuesday to Saturday in one.
    WeeklyHours::clear('tenant_luna');
    WeeklyHours::week('tenant_luna', $elena, [[1, '09:00', '13:00'], [1, '14:00', '18:00'], ...array_map(fn (int $weekday): array => [$weekday, '09:00', '17:00'], [2, 3, 4, 5, 6])]);
    DB::table('tenant_luna.blackouts')->insert([
        // Monday's morning alone is taken, the afternoon window left.
        $closure('2026-12-07', '09:00', '13:00'),
        // Two closures that meet at 13:00 take Tuesday.
        $closure('2026-12-08', '13:00', '17:00'), $closure('2026-12-08', '09:00', '13:00'),
        // Two that overlap and reach past both edges take Wednesday.
        $closure('2026-12-09', '12:00', '20:00'), $closure('2026-12-09', '08:00', '15:00'),
        // Half an hour is left between Thursday's two.
        $closure('2026-12-10', '09:00', '13:00'), $closure('2026-12-10', '13:30', '17:00'),
        // Friday's two leave 12:00 to 15:00, which a member's own closure does not fill.
        $closure('2026-12-11', '09:00', '12:00'), $closure('2026-12-11', '15:00', '17:00'), $closure('2026-12-11', '12:00', '15:00', $elena),
        // One closure on exactly the window's two edges takes Saturday.
        $closure('2026-12-12', '09:00', '17:00'),
        // The next Monday each of the two windows has a closure of its own, on its edges.
        $closure('2026-12-14', '14:00', '18:00'), $closure('2026-12-14', '09:00', '13:00'),
        // A minute is left at the start of that Tuesday and at the end of that Wednesday.
        $closure('2026-12-15', '09:01', '17:00'),
        $closure('2026-12-16', '09:00', '16:59'),
        // Three that touch and overlap in a row take that Thursday.
        $closure('2026-12-17', '13:00', '17:00'), $closure('2026-12-17', '09:00', '11:00'), $closure('2026-12-17', '11:00', '14:00'),
    ]);

    // The two Sundays are the day with no opening hours.
    expect(data_get($this->actingAs(calendarOwner())->get(tenantUrl('luna', '/admin/calendar?from=2026-12-07&to=2026-12-21'))->viewData('page'), 'props.window.closed_days'))
        ->toBe(['2026-12-08', '2026-12-09', '2026-12-12', '2026-12-13', '2026-12-14', '2026-12-17', '2026-12-20']);
});

it('reads the opening week in two statements, each member\'s status and flag and the stored patterns, so a window read names the service links once and no closure outside its dates, and asks for the note marks with one array', function () {
    $tenant = Tenant::query()->where('slug', 'luna')->sole();
    $wide = app(OpeningWeek::class)->of($tenant, app(StaffRecords::class)->all($tenant), '2026-11-05');
    calendared([['Haircut', 'Elena Papadaki', '2026-11-03 10:00', 45]]);
    calendared([['Beard trim', 'Nikos Georgiou', '2026-11-03 12:00', 30]]);
    $statements = [];
    DB::listen(function (QueryExecuted $query) use (&$statements): void {
        $statements[] = ['sql' => $query->sql, 'bound' => count($query->bindings)];
    });

    $week = app(OpeningWeek::class)->read($tenant, '2026-11-05');
    $own = array_column($statements, 'sql');
    calendarWindow($this, calendarOwner(), ['from' => '2026-11-03', 'to' => '2026-11-04']);
    $window = array_slice($statements, count($own));
    $naming = fn (string $text): array => array_values(array_filter($window, fn (array $statement): bool => str_contains($statement['sql'], $text)));

    // The week read alone, the same week the whole roster gives; then everything a window of
    // two bookings runs, the two statements of the week among them.
    expect($own)->toHaveCount(2)
        ->and($own[0] ?? '')->toContain('"tenant_luna"."shift_patterns"')
        ->and($own[1] ?? '')->toContain('select "id", "status", "is_implicit" from "tenant_luna"."staff"')
        ->and($week)->toBe($wide)
        ->and(array_keys($week))->toBe([1, 2, 3, 4, 5, 6])
        ->and($naming('"service_staff"'))->toHaveCount(1)
        ->and($naming('"blackouts"'))->toHaveCount(2)->toBe($naming('"blackouts" where "date" between'))
        ->and(array_column($naming('booking_meta'), 'bound'))->toBe([2]);
});
