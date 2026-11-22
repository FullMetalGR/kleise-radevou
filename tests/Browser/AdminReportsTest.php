<?php

use App\Site\SiteLanguage;
use Database\Seeders\DemoTenantsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Pest\Browser\Api\AwaitableWebpage;
use Pest\Browser\Api\Webpage;
use Tests\Browser\Support\Admin;
use Tests\Browser\Support\PhoneScripts;
use Tests\Support\LunaFixture;

/*
 * The Reports screen in a real browser: the owner opens Reports from the rail on this week, the
 * range restated in luna's date format and the confirmed revenue line reading today's booking;
 * chooses Last month, which changes the address, the range's line and the Bookings tile; chooses
 * a member, which changes the Bookings tile again; reloads and finds both kept; opens a custom
 * range by the Range; and a custom range reached by its address reads a second currency on its
 * own line; Occupancy by staff follows the member chosen and Download CSV asks the page's own
 * query; and a By table is a grid at a desktop width, its head row on screen. The waiting and
 * failing states are proven by the component tests alone: a browser cannot be made to hold a read
 * honestly.
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
 * Whether the By staff table draws as a grid: the table, its head and its first body row display
 * as a table's own parts, the head row has its height on screen, and no cell shows its column
 * name beside its value.
 */
const REPORTS_WALK_BY_TABLE_IS_GRID = <<<'JS'
    (() => {
        const table = document.querySelector('.admin-reports-staff table');
        const head = table?.querySelector('thead');
        const row = table?.querySelector('tbody tr');
        const shown = (element) => (element ? getComputedStyle(element).display : null);

        return shown(table) === 'table'
            && shown(head) === 'table-header-group'
            && head.getBoundingClientRect().height > 1
            && shown(row) === 'table-row'
            && [...row.cells].every((cell) => shown(cell) === 'table-cell')
            && [...table.querySelectorAll('.admin-table__label')].every((label) => shown(label) === 'none');
    })()
    JS;

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
                const value = (tr, index) => tr?.cells[index]?.querySelector('.admin-table__value')?.textContent;
                const row = [...document.querySelectorAll('.admin-reports-over-time tbody tr')].find((tr) => value(tr, 0) === '%1$s');
                const staff = %3$s;
                const members = [...document.querySelectorAll('.admin-reports-staff tbody tr')];

                return value(row, 2) === '%2$s'
                    && Object.entries(staff).every(([name, count]) => members.some((tr) => value(tr, 0) === name && value(tr, 1) === count));
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

it('keeps a By table a grid with its head row on screen at a desktop width, in a card half as wide as the page', function () {
    reportsWalkBooking(athensDaysOn(0), 'EUR', 3500);

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'))->resize(1280, 800);
    $page->click(REPORTS_WALK_ENTRY)
        ->waitForText(adminLine('admin/reports.tables.statuses'))
        ->assertScript(REPORTS_WALK_BY_TABLE_IS_GRID, true)
        ->assertNoJavaScriptErrors();
});

/**
 * How many tiles stand on each line of the six, top line first.
 */
const REPORTS_WALK_TILES_PER_LINE = <<<'JS'
    document.fonts.ready.then(() => {
        const tops = [...document.querySelectorAll('.admin-reports-tiles > .admin-figure-tile')].map((tile) => Math.round(tile.getBoundingClientRect().top));

        return [...new Set(tops)].map((top) => tops.filter((at) => at === top).length);
    })
    JS;

/**
 * How the Revenue figure stands in its tile: the number of lines its characters are set on, by
 * the line boxes they fall in, and whether every one of those boxes lies inside the room the
 * tile's padding leaves. A figure too wide for its tile breaks inside the number, so it is the
 * count of lines that tells a figure that fits from one that does not.
 */
const REPORTS_WALK_REVENUE_FIGURE = <<<'JS'
    document.fonts.ready.then(() => {
        const tile = document.querySelector('.admin-figure-tile--revenue');
        const characters = document.createRange();
        characters.selectNodeContents(tile.querySelector('.admin-figure-tile__value'));
        const boxes = [...characters.getClientRects()];
        const edge = tile.getBoundingClientRect();
        const style = getComputedStyle(tile);
        const left = edge.left + parseFloat(style.paddingLeft) - 0.5;
        const right = edge.right - parseFloat(style.paddingRight) + 0.5;

        return [new Set(boxes.map((box) => Math.round(box.top))).size, boxes.every((box) => box.left >= left && box.right <= right)];
    })
    JS;

/** The screen's own width, which its tiles are laid out by. */
const REPORTS_WALK_SCREEN = "document.querySelector('.admin-reports-screen').clientWidth";

// The amount the tiles' layouts are drawn for, in minor units: six figures and its cents.
const REPORTS_WALK_DESIGN_REVENUE = 12345678;

// The screen's widths, 37, 56 and 113rem, from which the tiles stand in two plain columns, in
// three and in six.
const REPORTS_WALK_TWO_FROM = 592;
const REPORTS_WALK_THREE_FROM = 896;
const REPORTS_WALK_SIX_FROM = 1808;

/**
 * Whether the Revenue tile reads the amount given, in euros, as the page's own Intl spells it in
 * the page's language: its grouping, its cents and the currency's sign.
 */
function reportsWalkRevenueReads(int $minor): string
{
    return sprintf(
        <<<'JS'
            (() => {
                const format = new Intl.NumberFormat(document.documentElement.lang, { style: 'currency', currency: 'EUR' });

                return document.querySelector('.admin-figure-tile--revenue .admin-figure-tile__value')?.textContent === format.format(%d / 100);
            })()
            JS,
        $minor,
    );
}

/**
 * A week whose revenue is the amount the layouts are drawn for, read by luna's owner in the
 * language given. The language is set before the sign-in: the server keeps the signed-in user.
 */
function reportsWalkDesignWeek(SiteLanguage $language): void
{
    reportsWalkBooking(athensDaysOn(0), 'EUR', REPORTS_WALK_DESIGN_REVENUE);
    DB::table('users')->where('email', Admin::owner('luna'))->update(['locale' => $language->value]);
}

/**
 * Sets the window so that the screen is as wide as asked, trying the window given first. What
 * stands around the screen, the sidebar and the page's own room, is not the same at every
 * window, so the screen is read and the window set again by what it was short of.
 */
function reportsWalkScreenOf(AwaitableWebpage|Webpage $page, int $width, int $window): void
{
    for ($pass = 0; $pass < 2; $pass++) {
        $screen = $page->resize($window, 900)->script(REPORTS_WALK_SCREEN);
        assert(is_int($screen));
        $window += $width - $screen;
    }

    $page->resize($window, 900)->assertScript(REPORTS_WALK_SCREEN, $width);
}

it('gives Bookings and Revenue each a line of its own on a phone and stands the other four tiles two to a line, a six-figure revenue on one line and nothing scrolling sideways, in English and in Greek', function (SiteLanguage $language) {
    reportsWalkDesignWeek($language);

    $page = Admin::signInOnPhone($this, 'luna', Admin::owner('luna'));
    $page->navigate(Admin::url('luna', '/reports'))
        ->assertVisible('.admin-reports-screen')
        ->assertScript(PhoneScripts::PAGE_LANGUAGE, $language->value)
        ->assertScript(reportsWalkRevenueReads(REPORTS_WALK_DESIGN_REVENUE), true)
        ->assertScript(REPORTS_WALK_REVENUE_FIGURE, [1, true])
        ->assertScript(REPORTS_WALK_TILES_PER_LINE, [1, 1, 2, 2])
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true)
        ->assertNoJavaScriptErrors();
})->with([SiteLanguage::English, SiteLanguage::Greek]);

it('steps the tiles to two plain columns, to three and to six at the narrowest screen, in whole rems, that keeps a six-figure revenue in Greek on one line in its tile with room to spare, and holds the layout before each one pixel under it', function () {
    reportsWalkDesignWeek(SiteLanguage::Greek);

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'));
    $page->navigate(Admin::url('luna', '/reports'))
        ->assertVisible('.admin-reports-screen')
        ->assertScript(PhoneScripts::PAGE_LANGUAGE, SiteLanguage::Greek->value)
        ->assertScript(reportsWalkRevenueReads(REPORTS_WALK_DESIGN_REVENUE), true);

    // Each step: the screen's width it starts at, the window tried first, and the tiles per line
    // from it and under it. Two windows give the first step's screen: a narrow one with nothing
    // beside the screen, and one with the sidebar beside it, where a tile also keeps more room
    // around its figure and so less for it. The window tried first for a screen beside the
    // sidebar allows more than the sidebar takes.
    $steps = [
        [REPORTS_WALK_TWO_FROM, REPORTS_WALK_TWO_FROM, [2, 2, 2], [1, 1, 2, 2]],
        [REPORTS_WALK_TWO_FROM, REPORTS_WALK_TWO_FROM + 400, [2, 2, 2], [1, 1, 2, 2]],
        [REPORTS_WALK_THREE_FROM, REPORTS_WALK_THREE_FROM + 400, [3, 3], [2, 2, 2]],
        [REPORTS_WALK_SIX_FROM, REPORTS_WALK_SIX_FROM + 400, [6], [3, 3]],
    ];

    foreach ($steps as [$from, $window, $at, $under]) {
        foreach ([[$from, $at], [$from - 1, $under]] as [$width, $perLine]) {
            reportsWalkScreenOf($page, $width, $window);
            $page->assertScript(REPORTS_WALK_REVENUE_FIGURE, [1, true])
                ->assertScript(REPORTS_WALK_TILES_PER_LINE, $perLine)
                ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true);
        }
    }

    $page->assertNoJavaScriptErrors();
});

it('stands the tiles by the screen\'s width at a phone\'s window, a tablet\'s and four desktop ones, a six-figure revenue in Greek on one line at each', function () {
    reportsWalkDesignWeek(SiteLanguage::Greek);

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'));
    $page->navigate(Admin::url('luna', '/reports'))
        ->assertVisible('.admin-reports-screen')
        ->assertScript(PhoneScripts::PAGE_LANGUAGE, SiteLanguage::Greek->value)
        ->assertScript(reportsWalkRevenueReads(REPORTS_WALK_DESIGN_REVENUE), true);

    // A phone's screen is too narrow for two tiles side by side to hold the amount. The tablet's
    // window, which has no sidebar yet, and the first desktop one, which has, leave a screen for
    // two; the wider three leave one for three, and none of them one for six.
    foreach ([390 => [1, 1, 2, 2], 768 => [2, 2, 2], 1024 => [2, 2, 2], 1280 => [3, 3], 1440 => [3, 3], 1920 => [3, 3]] as $window => $perLine) {
        $page->resize($window, 900)
            ->assertScript(REPORTS_WALK_REVENUE_FIGURE, [1, true])
            ->assertScript(REPORTS_WALK_TILES_PER_LINE, $perLine)
            ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true);
    }

    $page->assertNoJavaScriptErrors();
});
