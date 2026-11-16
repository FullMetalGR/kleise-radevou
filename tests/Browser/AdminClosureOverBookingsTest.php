<?php

use App\Http\ClosureOverBookings;
use Database\Seeders\DemoTenantsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Browser\Support\Admin;
use Tests\Support\LunaFixture;

/*
 * A new closure over time that already holds bookings, in a real browser. On the Staff screen
 * the closures card is given a closure of the whole business over two bookings: the card shows
 * the server's sentence and both bookings in place of its form, that cancelling cannot be undone
 * and that the email carries the reason typed, and Add and cancel 2 bookings writes the closure
 * and cancels both, the flash saying so and the closure listed in the card.
 *
 * On the Calendar's Day a member's column opens the closure dialog over the one booking she has
 * that day: Add and move it writes the closure, marks the booking's block inside it, and opens the
 * list of bookings to move, whose Move is the usual Move form; the booking moves to the other
 * member at another time, its row is ticked Moved, and Done closes the list.
 *
 * The bookings are written straight to luna's rows on the next Wednesday in Athens, a day Elena
 * works 09:00-13:00 and 14:00-18:00 and Nikos 10:00-19:00, whole days from today, since the
 * browser and the application both read the real clock here.
 */

beforeEach(function () {
    dropDemoSchemas();
    $this->seed(DemoTenantsSeeder::class);
});

afterEach(fn () => dropDemoSchemas());

/** The next Wednesday after the tenant's today, in Athens, as a date. */
function overBookingsWednesday(): string
{
    $today = athensToday();
    $days = (3 - (int) $today->format('N') + 7) % 7 ?: 7;

    return $today->add(new DateInterval('P'.$days.'D'))->format('Y-m-d');
}

/** An Athens wall time on a day as the Move form's read spells the start. */
function overBookingsStart(string $day, string $time): string
{
    return (new DateTimeImmutable($day.' '.$time, new DateTimeZone('Europe/Athens')))->setTimezone(new DateTimeZone('UTC'))->format(DateTimeImmutable::RFC3339);
}

/** A confirmed Haircut of luna with the member named at the Athens time given on the day given; its uuid. */
function overBookingsBooking(string $customer, string $day, string $time, string $staff): string
{
    $uuid = (string) Str::uuid();
    $start = new DateTimeImmutable($day.' '.$time, new DateTimeZone('Europe/Athens'));
    $end = $start->add(new DateInterval('PT45M'));
    $utc = fn (DateTimeImmutable $at): string => $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:sP');
    $id = DB::table('tenant_luna.bookings')->insertGetId([
        'source' => 'embed',
        'uuid' => $uuid, 'status' => 'confirmed', 'customer_name' => $customer,
        'customer_email' => Str::slug($customer).'@example.test', 'total_minor' => 3500, 'currency' => 'EUR',
        'payment_mode' => 'onsite', 'requires_approval' => false, 'on_approval_timeout' => 'expire',
    ]);
    DB::table('tenant_luna.booking_items')->insert([
        'booking_id' => $id, 'sort' => 0,
        'service_id' => LunaFixture::id('services', 'Haircut'),
        'staff_id' => LunaFixture::id('staff', $staff),
        'start_utc' => $utc($start), 'end_utc' => $utc($end),
        'block_start_utc' => $utc($start), 'block_end_utc' => $utc($end),
        'price_minor' => 3500, 'blocks' => true,
    ]);

    return $uuid;
}

/** The booking's status as the database holds it. */
function overBookingsStatusOf(string $uuid): mixed
{
    return DB::table('tenant_luna.bookings')->where('uuid', $uuid)->value('status');
}

it('adds a closure of the whole business from the Staff screen over two bookings and cancels both, the flash saying so and the closure listed', function () {
    $day = overBookingsWednesday();
    $ada = overBookingsBooking('Ada Inside', $day, '10:00', 'Elena Papadaki');
    $bea = overBookingsBooking('Bea Inside', $day, '15:00', 'Nikos Georgiou');
    $date = new DateTimeImmutable($day, new DateTimeZone('Europe/Athens'));

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'));
    $page->navigate(Admin::url('luna', '/staff'))
        ->waitForText(adminLine('admin/staff.table.hours'))
        ->click('.admin-blackouts__date');
    // The grid opens on the tenant's current month; the Wednesday may sit in the next.
    if ($date->format('Y-m') !== athensToday()->format('Y-m')) {
        $page->click(sprintf('.admin-day-calendar button[aria-label="%s"]', adminLine('admin/shell.kit.month_grid.next')));
    }
    $page->click(sprintf('button[data-date="%s"]', $day))
        ->fill('#blackout-reason', 'Burst pipe')
        ->click('.admin-blackouts__add')
        ->waitForText(adminChoice(ClosureOverBookings::INSIDE, 2))
        ->assertMissing('.admin-blackouts__form')
        ->assertCount('.admin-inside__row', 2)
        ->assertSeeIn('.admin-inside__list', 'Ada Inside')
        ->assertSeeIn('.admin-inside__list', 'Bea Inside')
        ->assertSeeIn('.admin-inside__list', 'Elena Papadaki')
        ->assertSeeIn('.admin-inside__list', 'Nikos Georgiou')
        ->assertSeeIn('.admin-inside__list', $date->format('d/m/Y').' 10:00')
        ->assertSeeIn('.admin-inside__final', adminLine('admin/shell.shared.closure.final'))
        ->assertSeeIn('.admin-inside__reason-sent', adminLine('admin/shell.shared.closure.reason_sent'))
        ->assertSeeIn('.admin-inside__keep', adminChoice('admin/shell.shared.closure.keep', 2))
        ->assertSeeIn('.admin-inside__move', adminChoice('admin/shell.shared.closure.move', 2))
        ->assertSeeIn('.admin-inside__cancel', adminChoice('admin/shell.shared.closure.cancel', 2))
        ->click('.admin-inside__cancel')
        ->waitForText(adminChoice(ClosureOverBookings::CANCELLED, 2))
        ->assertMissing('.admin-inside')
        ->assertSeeIn('.admin-blackouts', $date->format('d/m/Y'))
        ->assertSeeIn('.admin-blackouts', 'Burst pipe')
        ->assertNoJavaScriptErrors();

    expect(DB::table('tenant_luna.blackouts')->whereNull('staff_id')->where('date', $day)->value('reason'))->toBe('Burst pipe')
        ->and(overBookingsStatusOf($ada))->toBe('cancelled')
        ->and(overBookingsStatusOf($bea))->toBe('cancelled');
});

it('adds a member\'s closure from her Day column over her one booking and moves it from the list of bookings to move to the other member, ticked Moved, Done closing the list', function () {
    $day = overBookingsWednesday();
    $uuid = overBookingsBooking('Cora Inside', $day, '10:00', 'Elena Papadaki');
    $elena = LunaFixture::id('staff', 'Elena Papadaki');
    $nikos = LunaFixture::id('staff', 'Nikos Georgiou');
    $block = '.fc-event[data-block-id="'.$uuid.':0"]';
    $target = overBookingsStart($day, '11:00');
    $free = json_encode(adminLine('admin/bookings.move.group_free'));
    $groupOfTarget = "() => document.querySelector('#move-time option[value=\"{$target}\"]')?.closest('optgroup')?.label === {$free}";

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'))
        ->navigate(Admin::url('luna', Admin::CALENDAR.'?view=day&date='.$day))
        ->waitForText(adminLine('admin/calendar.grid.block', ['customer' => 'Cora Inside', 'service' => 'Haircut']))
        ->assertMissing($block.'.admin-calendar-block--inside');

    $page->click('.admin-calendar-column[data-column="member-'.$elena.'"] .admin-calendar-head__menu')
        ->click('.admin-calendar-column__close')
        ->waitForText(adminLine('admin/calendar.closure.title'))
        ->assertValue('#closure-who', (string) $elena)
        ->click('.admin-closure__add')
        ->waitForText(adminChoice(ClosureOverBookings::INSIDE, 1))
        ->assertCount('.admin-inside__row', 1)
        ->assertSeeIn('.admin-inside__list', 'Cora Inside')
        ->assertMissing('.admin-inside__reason-sent')
        ->assertSeeIn('.admin-inside__move', adminChoice('admin/shell.shared.closure.move', 1))
        ->click('.admin-inside__move')
        ->waitForText(adminLine('admin/shell.shared.closure.to_move'))
        ->assertMissing('.admin-closure')
        ->assertPresent($block.'.admin-calendar-block--inside')
        ->assertSeeIn('.admin-to-move__row', 'Cora Inside')
        ->click('.admin-to-move__move')
        ->waitForText(adminLine('admin/bookings.move.time_help'))
        ->select('#move-staff-0', (string) $nikos)
        // The times read again for Nikos, whose Wednesday no closure holds.
        ->assertScript($groupOfTarget, true)
        ->select('#move-time', $target)
        ->click('.admin-move__save')
        ->waitForText(adminLine('admin/bookings.move.confirm.title'))
        ->assertNotPresent('.admin-move-confirm__warning')
        ->click('.admin-move-confirm__confirm')
        ->waitForText(adminLine('admin/bookings.flashes.moved'))
        ->assertSeeIn('.admin-to-move__row .admin-to-move__moved', adminLine('admin/shell.shared.closure.moved'))
        ->assertMissing('.admin-to-move__move')
        ->click('.admin-to-move__done')
        ->assertMissing('.admin-to-move')
        // The calendar reads its window again after the move: the block is drawn again first, so
        // that the marker is looked for on a block that is there.
        ->assertPresent($block)
        ->assertMissing($block.'.admin-calendar-block--inside')
        ->assertNoJavaScriptErrors();

    $item = DB::table('tenant_luna.booking_items')
        ->join('tenant_luna.bookings', 'bookings.id', '=', 'booking_items.booking_id')
        ->where('bookings.uuid', $uuid)->first(['booking_items.staff_id', 'booking_items.start_utc', 'bookings.status']);
    assert(is_object($item) && is_string($item->start_utc));

    expect(DB::table('tenant_luna.blackouts')->where('staff_id', $elena)->where('date', $day)->exists())->toBeTrue()
        ->and($item->staff_id)->toBe($nikos)
        ->and($item->status)->toBe('confirmed')
        ->and((new DateTimeImmutable($item->start_utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/Athens'))->format('Y-m-d H:i'))->toBe($day.' 11:00');
});
