<?php

use App\Http\AdminProps;
use App\Http\Controllers\Admin\BookingActionController;
use App\Http\Controllers\Admin\LoginController;
use Database\Seeders\DemoTenantsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Browser\Support\Admin;
use Tests\Browser\Support\Console;
use Tests\Browser\Support\PhoneScripts;

/*
 * A support session walked in a real browser: an operator signs in to the console, opens luna's
 * detail, where no support session is open, and presses Open as support; the browser follows the
 * answer to luna's own host and lands on its calendar as support, the banner naming the operator
 * above the page, no Billing on the rail and no Me under the Calendar's Who, support having no
 * staff row. On Bookings the operator cancels a confirmed booking from its dialog through the
 * confirm to the toast, and the dialog's history names Support and the operator. End session
 * lands on luna's sign-in page. Back on the console, which kept its own session on its own host,
 * luna's detail lists no open session and the audit lists the session's opening and its end.
 *
 * The booking is written straight to luna's rows, two months on, because the browser and the
 * application both read the real clock here, as the session's sixty minutes do.
 *
 * Then the console's header on a business's detail: its back link to the list stands as far under
 * the page's top as a title does, and the line naming the console's zone ends at the row's end
 * beside the title on a desktop and starts at the row's start where it drops under the title on a
 * phone, on the list too, where the list's own button comes first.
 */

beforeEach(function () {
    dropDemoSchemas();
    $this->seed(DemoTenantsSeeder::class);
});

afterEach(fn () => dropDemoSchemas());

/**
 * One confirmed booking of luna on Elena's row for a haircut, starting at the instant given.
 */
function supportWalkBooking(string $customer, DateTimeImmutable $start): string
{
    $end = $start->add(new DateInterval('PT45M'));
    $utc = fn (DateTimeImmutable $at): string => $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:sP');
    $uuid = (string) Str::uuid();
    $id = DB::table('tenant_luna.bookings')->insertGetId([
        'source' => 'embed',
        'uuid' => $uuid, 'status' => 'confirmed', 'customer_name' => $customer,
        'customer_email' => Str::slug($customer).'@example.test', 'total_minor' => 3500, 'currency' => 'EUR',
        'hold_class' => null, 'hold_expires_at' => null,
        'payment_mode' => 'onsite', 'requires_approval' => false, 'on_approval_timeout' => 'expire',
    ]);
    DB::table('tenant_luna.booking_items')->insert([
        'booking_id' => $id, 'sort' => 0,
        'service_id' => DB::table('tenant_luna.services')->where('name', 'Haircut')->value('id'),
        'staff_id' => DB::table('tenant_luna.staff')->where('name', 'Elena Papadaki')->value('id'),
        'start_utc' => $utc($start), 'end_utc' => $utc($end),
        'block_start_utc' => $utc($start), 'block_end_utc' => $utc($end),
        'price_minor' => 3500, 'blocks' => true,
    ]);

    return $uuid;
}

it('opens support on luna from the console, cancels a booking as support under the banner with no Billing, ends the session to the sign-in page, and reads the opening and the end in the console\'s audit', function () {
    $uuid = supportWalkBooking('Cora Cancelled', athensDaysOn(63));
    $operator = addOperator();

    $page = Console::signIn($this, 'pat@inbox.example', 'operator password')
        ->waitForText('2 of 2');

    $page->navigate(Console::url('/tenants/luna'))
        ->waitForText('Support sessions')
        ->assertSeeIn('.admin-tenant-sessions__none', 'No support session is open at this business.')
        ->click('.admin-tenant-support__open')
        ->waitForText(trim(Str::before(adminLine('admin/shell.banners.support.line', ['operator' => 'Pat Operator']), ':end')))
        ->assertUrlIs(Admin::url('luna'))
        ->assertSeeIn('.admin-business', 'Luna Hair Studio')
        ->assertSeeIn('.admin-support-banner', 'Pat Operator')
        ->assertSeeIn('.admin-support-banner', '(Europe/Athens)')
        ->assertSeeIn('.admin-support-banner__end', adminLine('admin/shell.banners.support.end'))
        ->assertSeeIn('.admin-topbar', adminLine(AdminProps::SUPPORT_NAME, ['operator' => 'Pat Operator']))
        ->assertSeeIn('.admin-rail', navLabel('bookings'))
        ->assertDontSeeIn('.admin-rail', navLabel('billing'))
        ->assertSeeIn('.admin-rail', navLabel('calendar'))
        ->assertVisible(Admin::HOME)
        ->navigate(Admin::url('luna', Admin::CALENDAR))
        ->assertPathIs('/admin/calendar')
        ->click('#calendar-who')
        ->assertPresent('.admin-calendar-who__preset[data-who="all"]')
        ->assertMissing('.admin-calendar-who__preset[data-who="me"]')
        ->click('#calendar-who')
        ->assertMissing('.admin-calendar-who__popover')
        ->assertNoJavaScriptErrors();

    $page->click(navLabel('bookings'))
        ->waitForText(adminLine('admin/bookings.table.count', ['count' => '1', 'total' => '1']))
        ->assertPresent('.admin-support-banner')
        ->click('Cora Cancelled')
        ->waitForText(adminLine('admin/bookings.dialog.items.title'))
        ->click('.admin-booking-action--cancel')
        ->waitForText(adminLine('admin/bookings.cancel.warning'))
        ->click('.admin-cancel-prompt__confirm')
        ->waitForText(adminLine(BookingActionController::CANCELLED))
        ->assertSeeIn('.admin-booking-status .admin-chip', adminLine('admin/shell.kit.statuses.cancelled'))
        ->assertCount('.admin-booking-trail__entry', 1)
        ->assertSeeIn('.admin-booking-trail__actor', adminLine(AdminProps::SUPPORT_NAME, ['operator' => 'Pat Operator']))
        ->assertNoJavaScriptErrors();

    $page->click('.admin-booking-close')
        ->waitForText(adminLine('admin/bookings.table.count', ['count' => '1', 'total' => '1']))
        ->click('.admin-support-banner__end')
        ->waitForText(adminLine(LoginController::SIGNED_OUT))
        ->assertUrlIs(Admin::url('luna', '/login'))
        ->assertPresent('.admin-login-submit')
        ->assertMissing('.admin-support-banner')
        ->assertNoJavaScriptErrors();

    $page->navigate(Admin::url('luna', '/bookings'))
        ->waitForText(adminLine('admin/shell.login.submit'))
        ->assertUrlIs(Admin::url('luna', '/login'));

    $page->navigate(Console::url('/tenants/luna'))
        ->waitForText('Support sessions')
        ->assertSeeIn('.admin-tenant-sessions__none', 'No support session is open at this business.')
        ->assertMissing('.admin-tenant-sessions__end')
        ->assertNoJavaScriptErrors();

    $page->navigate(Console::url('/audit'))
        ->waitForText('Ended a support session')
        ->assertSee('Opened a support session')
        ->assertNoJavaScriptErrors();

    $luna = DB::table('tenants')->where('slug', 'luna')->value('id');

    expect(DB::table('platform_audit_log')->orderBy('id')->get(['operator_id', 'action', 'tenant_id'])->map(fn (object $row): array => (array) $row)->all())->toBe([
        ['operator_id' => $operator->id, 'action' => 'sign_in', 'tenant_id' => null],
        ['operator_id' => $operator->id, 'action' => 'support_opened', 'tenant_id' => $luna],
        ['operator_id' => $operator->id, 'action' => 'support_ended', 'tenant_id' => $luna],
    ])
        ->and(DB::table('support_sessions')->whereNull('opened_at')->orWhereNull('ended_at')->count())->toBe(0)
        ->and(DB::table('tenant_luna.bookings')->where('uuid', $uuid)->value('status'))->toBe('cancelled')
        ->and(DB::table('tenant_luna.audit_log')->pluck('actor')->all())->toBe(['support:'.$operator->id]);
});

/**
 * The room in CSS pixels between the top of the page's main region and the top of the header's
 * first part, the breadcrumb where the screen has one.
 */
const CONSOLE_HEAD_ROOM = <<<'JS'
    document.fonts.ready.then(() => {
        const first = document.querySelector('.admin-page-header')?.firstElementChild?.firstElementChild;
        const main = document.querySelector('main');

        return first === null || first === undefined || main === null ? -1 : Math.round(first.getBoundingClientRect().top - main.getBoundingClientRect().top);
    })
    JS;

/**
 * Where the console's zone line stands in the header's row: "far end" where it ends at the row's
 * end on the title's line, "start" where it sits on a line under the title and starts at the
 * row's start, "neither" otherwise.
 */
const CONSOLE_ZONE_PLACE = <<<'JS'
    document.fonts.ready.then(() => {
        const zone = document.querySelector('.admin-console-zone')?.getBoundingClientRect();
        const row = document.querySelector('.admin-page-header__row')?.getBoundingClientRect();
        const title = document.querySelector('.admin-page-header__row h1')?.getBoundingClientRect();

        if (zone === undefined || row === undefined || title === undefined) {
            return 'missing';
        }

        if (zone.top < title.bottom && zone.bottom > title.top && Math.abs(zone.right - row.right) <= 1) {
            return 'far end';
        }

        return zone.top >= title.bottom && Math.abs(zone.left - row.left) <= 1 ? 'start' : 'neither';
    })
    JS;

it('gives a business\'s back link to the list the room the header\'s row has above it, and starts the zone line at the row\'s start wherever it drops under the title', function () {
    addOperator();

    $page = Console::signIn($this, 'pat@inbox.example', 'operator password')->waitForText('2 of 2');
    $page->navigate(Console::url('/tenants/luna'))->waitForText('Support sessions');

    foreach ([1440 => 'far end', 390 => 'start', 320 => 'start'] as $width => $place) {
        $page->resize($width, 844)->assertScript(CONSOLE_ZONE_PLACE, $place);
        // The back link stands as far under the page's top as the title would without it.
        expect($page->script(CONSOLE_HEAD_ROOM))->toBeGreaterThanOrEqual(20);
    }

    // The list's own button holds the zone line's first line on a narrow phone, so the zone
    // line drops under both, starting at the row's start rather than under the button's end.
    $page->navigate(Console::url('/'))->waitForText('2 of 2');
    $page->resize(320, 844)
        ->assertScript(CONSOLE_ZONE_PLACE, 'start')
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true)
        ->assertNoJavaScriptErrors();
});
