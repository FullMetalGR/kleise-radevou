<?php

use Database\Seeders\DemoTenantsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Pest\Browser\Api\AwaitableWebpage;
use Pest\Browser\Api\Webpage;
use Tests\Browser\Support\Admin;
use Tests\Support\LunaFixture;

/*
 * The Reports screen in a real browser: the owner opens Reports from the rail on this week, the
 * range restated in luna's date format and the confirmed revenue line reading today's booking;
 * chooses Last month, which changes the address, the range's line and the Bookings tile; chooses
 * a member, which changes the Bookings tile again; reloads and finds both kept; opens a custom
 * range by the Range; and a custom range reached by its address reads a second currency on its
 * own line; Occupancy by staff follows the member chosen and Download CSV asks the page's own
 * query. The waiting and failing states are proven by the component tests alone: a browser cannot
 * be made to hold a read honestly.
 *
 * The bookings are written straight to luna's rows, every start whole days from today, because
 * the browser and the application both read the real clock here.
 */

beforeEach(function () {
    dropDemoSchemas();
    $this->seed(DemoTenantsSeeder::class);
});

afterEach(fn () => dropDemoSchemas());

// The rail's own entry, reached by its target: the screen's heading reads "Reports" too.
const REPORTS_WALK_ENTRY = '.admin-rail a[href="/admin/reports"]';

/**
 * Whether the confirmed revenue line shows an amount of minor units in a currency, as the page's
 * own Intl spells it at that currency's scale: the line is in the browser's locale, and a figure
 * spelled here would be this machine's guess at that locale rather than an assertion about the
 * amount, as FlowScripts::summaryWhen compares a day.
 */
function reportsWalkShows(string $currency, int $minor): string
{
    return sprintf(
        <<<'JS'
            (() => {
                const format = new Intl.NumberFormat(undefined, { style: 'currency', currency: '%1$s' });
                const amount = format.format(%2$d / 10 ** format.resolvedOptions().maximumFractionDigits);
                const shown = [...document.querySelectorAll('.admin-reports-revenue__confirmed .admin-reports__amount')];

                return shown.some((line) => line.textContent === amount);
            })()
            JS,
        $currency,
        $minor,
    );
}

/**
 * Waits until the address names the member given and the Bookings tile reads the count given: a
 * filter's read lands on its own, with no sentence of its own to wait for.
 */
function reportsWalkUntil(AwaitableWebpage|Webpage $page, string $staff, string $bookings): void
{
    // The awaitable page retries an assertion until it holds or the plugin's timeout passes; the
    // Playwright page's own waitForFunction sends nothing in this plugin.
    $page->assertScript(<<<JS
        () => new URL(window.location.href).searchParams.get('staff') === '{$staff}'
            && document.querySelector('.admin-figure-tile--bookings .admin-figure-tile__value')?.textContent === '{$bookings}'
        JS, true);
}

/**
 * A confirmed Haircut on a member's row, Elena's unless named, at the instant given, in the
 * currency given.
 */
function reportsWalkBooking(DateTimeImmutable $start, string $currency, int $price, string $member = 'Elena Papadaki'): void
{
    $utc = fn (DateTimeImmutable $at): string => $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:sP');
    $id = DB::table('tenant_luna.bookings')->insertGetId([
        'source' => 'hosted', 'channel' => 'instagram',
        'uuid' => (string) Str::uuid(), 'status' => 'confirmed', 'customer_name' => 'Casey Customer', 'customer_email' => 'casey@example.com',
        'total_minor' => $price, 'currency' => $currency,
        'payment_mode' => 'onsite', 'requires_approval' => false, 'on_approval_timeout' => 'expire',
    ]);
    DB::table('tenant_luna.booking_items')->insert([
        'booking_id' => $id, 'sort' => 0,
        'service_id' => DB::table('tenant_luna.services')->where('name', 'Haircut')->value('id'),
        'staff_id' => DB::table('tenant_luna.staff')->where('name', $member)->value('id'),
        'start_utc' => $utc($start), 'end_utc' => $utc($start->add(new DateInterval('PT45M'))),
        'block_start_utc' => $utc($start), 'block_end_utc' => $utc($start->add(new DateInterval('PT45M'))),
        'price_minor' => $price, 'blocks' => false,
    ]);
}

it('opens Reports from the rail on this week, chooses Last month and a member, keeps both through a reload, opens a custom range, and reads each currency on its own revenue line', function () {
    $today = athensToday();
    // Two bookings in last month, ten and twelve days before this month's first: one on Elena, one
    // on Nikos.
    $firstOfMonth = $today->setDate((int) $today->format('Y'), (int) $today->format('n'), 1);
    $lastMonth = $firstOfMonth->sub(new DateInterval('P1D'));
    reportsWalkBooking(athensDaysOn(0), 'EUR', 3500);
    reportsWalkBooking(athensDaysOn(70), 'USD', 6000);
    reportsWalkBooking($firstOfMonth->sub(new DateInterval('P10D'))->setTime(10, 0), 'EUR', 3500);
    reportsWalkBooking($firstOfMonth->sub(new DateInterval('P12D'))->setTime(10, 0), 'EUR', 3500, 'Nikos Georgiou');
    // Luna's week starts on Monday, ISO day 1: back to it from today, then six days on.
    $monday = $today->sub(new DateInterval(sprintf('P%dD', (int) $today->format('N') - 1)));
    $sunday = $monday->add(new DateInterval('P6D'));
    $later = athensDaysOn(70);
    $nikos = (string) LunaFixture::id('staff', 'Nikos Georgiou');
    $stated = fn (string $word, DateTimeImmutable $from, DateTimeImmutable $to): string => adminLine('admin/reports.filters.stated.'.$word, [
        'from' => $from->format('d/m/Y'), 'to' => $to->format('d/m/Y'),
    ]);
    $lastMonthLine = $stated('last_month', $lastMonth->setDate((int) $lastMonth->format('Y'), (int) $lastMonth->format('n'), 1), $lastMonth);

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'));
    $page->click(REPORTS_WALK_ENTRY)
        ->waitForText(adminLine('admin/reports.tables.statuses'))
        ->assertPathIs('/admin/reports')
        ->assertAttribute('[aria-current="page"]', 'href', '/admin/reports')
        ->assertSeeIn('.admin-reports-filters__stated', $stated('week', $monday, $sunday))
        ->assertSelected('#reports-range', 'week')
        ->assertSeeIn('.admin-figure-tile--bookings .admin-figure-tile__value', '1')
        ->assertScript(reportsWalkShows('EUR', 3500), true)
        ->assertScript(reportsWalkShows('USD', 6000), false)
        ->assertSeeIn('.admin-reports-channels', adminLine('admin/shell.shared.channels.instagram'));

    $page->select('#reports-range', 'last_month')
        ->waitForText($lastMonthLine)
        ->assertQueryStringHas('range', 'last_month')
        ->assertSeeIn('.admin-figure-tile--bookings .admin-figure-tile__value', '2');

    $page->select('#reports-staff', $nikos);
    reportsWalkUntil($page, $nikos, '1');
    $page->assertQueryStringHas('staff', $nikos)
        ->assertSeeIn('.admin-figure-tile--bookings .admin-figure-tile__value', '1')
        ->refresh()
        ->waitForText($lastMonthLine)
        ->assertQueryStringHas('range', 'last_month')
        ->assertQueryStringHas('staff', $nikos)
        ->assertSelected('#reports-range', 'last_month')
        ->assertSelected('#reports-staff', $nikos)
        ->assertSeeIn('.admin-figure-tile--bookings .admin-figure-tile__value', '1');

    $page->select('#reports-range', 'custom')
        ->assertVisible('.admin-reports-custom')
        ->assertAttribute('#reports-from', 'value', $lastMonth->format('Y-m-01'))
        ->assertAttribute('#reports-to', 'value', $lastMonth->format('Y-m-d'))
        ->click('.admin-reports-apply')
        ->waitForText($stated('custom', $lastMonth->setDate((int) $lastMonth->format('Y'), (int) $lastMonth->format('n'), 1), $lastMonth))
        ->assertQueryStringHas('range', 'custom')
        ->assertQueryStringHas('from', $lastMonth->format('Y-m-01'))
        ->assertQueryStringHas('to', $lastMonth->format('Y-m-d'));

    $this->visit(Admin::url('luna', sprintf('/reports?range=custom&from=%s&to=%s', $today->format('Y-m-d'), $later->format('Y-m-d'))))
        ->waitForText(adminLine('admin/reports.tables.statuses'))
        ->assertSeeIn('.admin-reports-filters__stated', $stated('custom', $today, $later))
        ->assertSelected('#reports-range', 'custom')
        ->assertScript(reportsWalkShows('EUR', 3500), true)
        ->assertScript(reportsWalkShows('USD', 6000), true)
        ->assertCount('.admin-reports-revenue__confirmed .admin-reports__amount', 2)
        ->assertNoJavaScriptErrors();
});

/**
 * Whether Over time's row of the day given reads the bookings given and the By staff table's row
 * of each member the count given beside it.
 *
 * @param  array<string, string>  $staff  a member's name to the bookings its row reads
 */
function reportsWalkNarrowed(string $day, string $bookings, array $staff): string
{
    return sprintf(
        <<<'JS'
            (() => {
                const row = [...document.querySelectorAll('.admin-reports-over-time tbody tr')].find((tr) => tr.cells[0]?.textContent === '%1$s');
                const staff = %3$s;
                const members = [...document.querySelectorAll('.admin-reports-staff tbody tr')];

                return row?.cells[2]?.textContent === '%2$s'
                    && Object.entries(staff).every(([name, count]) => members.some((tr) => tr.cells[0]?.textContent === name && tr.cells[1]?.textContent === count));
            })()
            JS,
        $day,
        $bookings,
        json_encode($staff, JSON_THROW_ON_ERROR),
    );
}

it('narrows Over time\'s table and the By staff table to the member chosen', function () {
    $today = athensToday();
    reportsWalkBooking(athensDaysOn(0), 'EUR', 3500);
    reportsWalkBooking(athensDaysOn(0)->add(new DateInterval('PT2H')), 'EUR', 4500, 'Nikos Georgiou');
    $nikos = (string) LunaFixture::id('staff', 'Nikos Georgiou');

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'));
    $page->click(REPORTS_WALK_ENTRY)
        ->waitForText(adminLine('admin/reports.over_time.title'))
        ->assertSeeIn('.admin-reports-over-time figcaption', adminLine('admin/reports.over_time.bookings.day'))
        ->assertScript(reportsWalkNarrowed($today->format('d/m/Y'), '2', ['Elena Papadaki' => '1', 'Nikos Georgiou' => '1']), true);

    $page->select('#reports-staff', $nikos);
    reportsWalkUntil($page, $nikos, '1');
    $page->assertScript(reportsWalkNarrowed($today->format('d/m/Y'), '1', ['Elena Papadaki' => '0', 'Nikos Georgiou' => '1']), true)
        ->assertNoJavaScriptErrors();
});

it('shows Occupancy by staff for the member chosen alone, and asks the CSV with the page\'s own query', function () {
    reportsWalkBooking(athensDaysOn(0), 'EUR', 3500);
    reportsWalkBooking(athensDaysOn(0)->add(new DateInterval('PT2H')), 'EUR', 4500, 'Nikos Georgiou');
    $nikos = (string) LunaFixture::id('staff', 'Nikos Georgiou');

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'));
    $page->click(REPORTS_WALK_ENTRY)
        ->waitForText(adminLine('admin/reports.occupancy.title'))
        ->assertSeeIn('.admin-reports-occupancy', 'Elena Papadaki')
        ->assertSeeIn('.admin-reports-occupancy', 'Nikos Georgiou');

    $today = athensToday();
    $page->select('#reports-range', 'last_7_days')
        ->waitForText(adminLine('admin/reports.filters.stated.last_7_days', ['from' => $today->sub(new DateInterval('P6D'))->format('d/m/Y'), 'to' => $today->format('d/m/Y')]));
    $page->select('#reports-staff', $nikos);
    reportsWalkUntil($page, $nikos, '1');
    $page->assertCount('.admin-reports-occupancy tbody tr', 1)
        ->assertSeeIn('.admin-reports-occupancy tbody tr', 'Nikos Georgiou')
        ->assertDontSeeIn('.admin-reports-occupancy', 'Elena Papadaki')
        ->assertSeeIn('.admin-reports-download', adminLine('admin/reports.filters.download'));

    // The press fetches the file; the address it asks is recorded before the real fetch runs.
    $page->script(<<<'JS'
        (() => {
            const fetchFirst = window.fetch.bind(window);
            window.reportsAsked = [];
            window.fetch = (url, init) => { window.reportsAsked.push(String(url)); return fetchFirst(url, init); };
        })()
        JS);
    $page->click('.admin-reports-download')
        ->assertScript("window.reportsAsked.includes('/admin/reports/csv?range=last_7_days&staff={$nikos}')", true)
        ->assertNoJavaScriptErrors();
});
