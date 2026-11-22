<?php

use App\Site\SiteLanguage;
use Database\Seeders\DemoTenantsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Browser\Support\Admin;
use Tests\Browser\Support\Look;
use Tests\Browser\Support\PhoneScripts;

/*
 * The Bookings screen and the booking dialog in a real browser: filter to one member, page
 * forward, open a row, reload on the dialog's own address and land on the same dialog, close it
 * and keep the filters, then search for nobody and land on the empty panel. The dialog's footer
 * stays in view above a trail long enough to scroll, the modal's first proof in a screen. The
 * error notice is proven by the component tests alone: a browser cannot be made to fail the read
 * honestly.
 *
 * The layout: at 1440 pixels the list's head row stays at the top of the window while the page
 * scrolls through a long list; in Greek the filters stand as many to a row as the card holds,
 * no select cutting the value it shows; in Greek beside the open sidebar, between the narrowest
 * desktop window and a wide one, the list stays inside its card with a customer's name of sixty
 * characters wrapped in its cell; and on a phone, in Greek, each booking is a block of its name
 * and total, its services, its staff, then its date and status, a request carrying the amber bar
 * at its left edge, a service name of forty characters with no break kept inside its row, a long
 * customer's name wrapped clear of the total, the filters folded behind a button that counts
 * them, and the pager's page line on one line between two round arrows. A customer's page draws
 * its bookings the same way.
 */

/**
 * True when the page has scrolled the list's top out of the window and its head row rests at the
 * window's top, nothing drawn over its middle.
 */
const BOOKINGS_HEAD_HELD = <<<'JS'
    document.fonts.ready.then(() => new Promise((resolve) => {
        const table = document.querySelector('.admin-bookings table');
        const head = table?.querySelector('thead');
        if (!table || !head) {
            resolve(false);
            return;
        }
        window.scrollTo(0, table.getBoundingClientRect().top + window.scrollY + 400);
        requestAnimationFrame(() => requestAnimationFrame(() => {
            const box = head.getBoundingClientRect();
            const list = table.getBoundingClientRect();
            const hit = document.elementFromPoint(box.left + box.width / 2, box.top + box.height / 2);
            resolve(list.top < -300 && list.bottom > box.bottom && Math.abs(box.top) <= 1 && box.height > 20 && hit !== null && head.contains(hit));
        }));
    }))
    JS;

/** How many of the filter card's fields stand on each of its rows, top to bottom. */
const BOOKINGS_FIELDS_PER_ROW = <<<'JS'
    document.fonts.ready.then(() => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(() => {
        const rows = [];
        for (const field of document.querySelectorAll('.admin-filter-bar__fields > *')) {
            const top = Math.round(field.getBoundingClientRect().top);
            const last = rows.at(-1);
            if (last !== undefined && Math.abs(last.top - top) <= 1) {
                last.count++;
            } else {
                rows.push({ top, count: 1 });
            }
        }
        resolve(rows.map((row) => row.count));
    }))))
    JS;

/**
 * Each select of the filter card whose chosen value is wider than the room its frame leaves it,
 * as its id with the two widths, so a failure says how far it is cut.
 */
const BOOKINGS_SELECTS_CUT = <<<'JS'
    document.fonts.ready.then(() => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(() => {
        const probe = document.createElement('span');
        probe.style.position = 'absolute';
        probe.style.visibility = 'hidden';
        probe.style.whiteSpace = 'pre';
        document.body.append(probe);
        const cut = [];
        for (const select of document.querySelectorAll('.admin-filter-bar select')) {
            const style = getComputedStyle(select);
            probe.style.fontFamily = style.fontFamily;
            probe.style.fontSize = style.fontSize;
            probe.style.fontWeight = style.fontWeight;
            probe.style.letterSpacing = style.letterSpacing;
            probe.textContent = select.selectedOptions[0]?.textContent ?? '';
            const need = probe.getBoundingClientRect().width;
            const room = select.clientWidth - parseFloat(style.paddingLeft) - parseFloat(style.paddingRight);
            if (need > room - 2) {
                cut.push(`${select.id}: ${Math.ceil(need)} > ${Math.floor(room)}`);
            }
        }
        probe.remove();
        resolve(cut);
    }))))
    JS;

/**
 * True when every booking of the list reads as a block inside its card: its name and its total on
 * one line, the total after the name, then its services, then its staff, then its date and its
 * status on one line, the status after the date, every cell inside the row and none labelled, the
 * head row out of sight and the table no wider than its card.
 */
const BOOKINGS_ROWS_READ = <<<'JS'
    document.fonts.ready.then(() => {
        const card = document.querySelector('.admin-bookings');
        const table = card?.querySelector('table');
        const rows = [...document.querySelectorAll('.admin-bookings__row')];
        if (!card || !table || rows.length === 0) {
            return false;
        }
        const line = (a, b) => a.top < b.bottom && b.top < a.bottom;
        const head = card.querySelector('thead').getBoundingClientRect();
        const reads = rows.every((row) => {
            const box = row.getBoundingClientRect();
            const [name, services, staff, when, status, total] = [...row.children].map((cell) => cell.getBoundingClientRect());
            return getComputedStyle(row).display === 'grid'
                && [name, services, staff, when, status, total].every((cell) => cell.left >= box.left - 1 && cell.right <= box.right + 1)
                && line(name, total) && total.left >= name.right - 1
                && services.top >= Math.max(name.bottom, total.bottom) - 1
                && staff.top >= services.bottom - 1
                && when.top >= staff.bottom - 1 && line(when, status) && status.left >= when.right - 1
                && row.querySelector('.admin-table__label') === null;
        });
        return reads && head.width <= 1 && head.height <= 1 && table.scrollWidth <= table.clientWidth + 1 && card.scrollWidth <= card.clientWidth + 1;
    })
    JS;

/**
 * True when the row of the service named in the page's own text keeps that name inside the row,
 * the name broken where it has to be, and the request's row wears a four-pixel inset at its left
 * edge in the attention colour, its chip the awaiting approval one.
 */
const BOOKINGS_LONG_NAME_AND_BAR = <<<'JS'
    document.fonts.ready.then(() => {
        const services = [...document.querySelectorAll('.admin-bookings__services')].find((cell) => (cell.textContent ?? '').length >= 40);
        const request = document.querySelector('.admin-row--attention');
        if (!services || !request) {
            return false;
        }
        const row = services.closest('tr').getBoundingClientRect();
        const cell = services.getBoundingClientRect();
        const probe = document.createElement('span');
        probe.style.color = 'var(--attention)';
        document.body.append(probe);
        const amber = getComputedStyle(probe).color;
        probe.remove();
        const bar = getComputedStyle(request).boxShadow;
        return services.scrollWidth <= services.clientWidth + 1 && cell.left >= row.left - 1 && cell.right <= row.right + 1
            && bar.includes(amber) && bar.includes('4px 0px 0px 0px inset')
            && request.querySelector('.admin-chip')?.getAttribute('data-status') === 'awaiting_approval';
    })
    JS;

/**
 * What keeps the bookings table from fitting its card, as words with the widths, so a failure says
 * how far: the table drawn past the card's right edge, or a table that needs more than the card's
 * width less two pixels at its narrowest, its words wrapped wherever they may.
 */
const BOOKINGS_TABLE_FITS = <<<'JS'
    document.fonts.ready.then(() => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(() => {
        const card = document.querySelector('.admin-bookings');
        const table = card?.querySelector('table');
        if (!card || !table) {
            resolve(['no table']);
            return;
        }
        const edge = card.getBoundingClientRect().left + card.clientLeft + card.clientWidth;
        const drawn = table.getBoundingClientRect().right;
        table.style.width = 'min-content';
        const need = table.getBoundingClientRect().width;
        table.style.width = '';
        const wrong = [];
        if (drawn > edge + 0.5) {
            wrong.push(`drawn ${Math.ceil(drawn - edge)}px past the card`);
        }
        if (need > card.clientWidth - 2) {
            wrong.push(`needs ${Math.ceil(need)}px of ${card.clientWidth}px`);
        }
        resolve(wrong);
    }))))
    JS;

/**
 * True when the button of the customer named in sixty characters or more keeps its name inside its
 * cell, the name wrapped within the button and the button's text inside the cell's box, and where
 * it shares a line with the row's total it ends at least two pixels before the total begins.
 */
const BOOKINGS_LONG_NAME_INSIDE = <<<'JS'
    document.fonts.ready.then(() => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(() => {
        const button = [...document.querySelectorAll('.admin-bookings .admin-row-select')].find((name) => (name.textContent ?? '').length >= 60);
        const cell = button?.closest('td');
        const total = cell?.closest('tr')?.querySelector('.admin-bookings__total');
        if (!button || !cell || !total) {
            resolve(false);
            return;
        }
        const box = button.getBoundingClientRect();
        const style = getComputedStyle(button);
        const room = cell.getBoundingClientRect();
        const sum = total.getBoundingClientRect();
        const text = { left: box.left + parseFloat(style.paddingLeft), right: box.right - parseFloat(style.paddingRight) };
        const shared = box.top < sum.bottom && sum.top < box.bottom;
        resolve(button.scrollWidth <= button.clientWidth + 1 && text.left >= room.left - 1 && text.right <= room.right + 1 && (!shared || box.right <= sum.left - 2));
    }))))
    JS;

/** A customer's name of sixty characters, in words, two of them joined by a hyphen. */
const BOOKINGS_LONG_CUSTOMER = 'Konstantina-Maria Papadopoulou-Chatzigeorgiou Theodoropoulou';

/** True when the pager's page line stands on one line between two round arrows of 44 pixels. */
const BOOKINGS_PAGER_ON_ONE_LINE = <<<'JS'
    document.fonts.ready.then(() => {
        const page = document.querySelector('.admin-pagination__page');
        const arrows = ['.admin-pagination__previous', '.admin-pagination__next'].map((selector) => document.querySelector(selector)?.getBoundingClientRect());
        if (!page || arrows.some((arrow) => arrow === undefined)) {
            return false;
        }
        const range = document.createRange();
        range.selectNodeContents(page);
        const lines = new Set([...range.getClientRects()].map((rect) => Math.round(rect.top))).size;
        const box = page.getBoundingClientRect();
        return lines === 1 && arrows.every((arrow) => arrow.width >= 44 && arrow.height >= 44 && Math.abs(arrow.width - arrow.height) <= 1) && arrows[0].right <= box.left && box.right <= arrows[1].left;
    })
    JS;

beforeEach(function () {
    dropDemoSchemas();
    $this->seed(DemoTenantsSeeder::class);
});

afterEach(fn () => dropDemoSchemas());

/**
 * A confirmed Haircut on one of luna's members, starting at the instant given, answering the
 * booking's serial id. Every caller dates it whole days from today: the browser and the
 * application both read the real clock here.
 */
function screenBooking(string $customer, string $staff, DateTimeImmutable $start): int
{
    $utc = fn (DateTimeImmutable $at): string => $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:sP');
    $id = DB::table('tenant_luna.bookings')->insertGetId([
        'source' => 'embed',
        'uuid' => (string) Str::uuid(), 'status' => 'confirmed', 'customer_name' => $customer,
        'customer_email' => Str::slug($customer).'@example.test', 'total_minor' => 3500, 'currency' => 'EUR',
        'payment_mode' => 'onsite', 'requires_approval' => false, 'on_approval_timeout' => 'expire',
    ]);
    DB::table('tenant_luna.booking_items')->insert([
        'booking_id' => $id, 'sort' => 0,
        'service_id' => DB::table('tenant_luna.services')->where('name', 'Haircut')->value('id'),
        'staff_id' => DB::table('tenant_luna.staff')->where('name', $staff)->value('id'),
        'start_utc' => $utc($start), 'end_utc' => $utc($start->add(new DateInterval('PT45M'))),
        'block_start_utc' => $utc($start), 'block_end_utc' => $utc($start->add(new DateInterval('PT45M'))),
        'price_minor' => 3500, 'blocks' => false,
    ]);

    return $id;
}

function nikosId(): string
{
    $id = DB::table('tenant_luna.staff')->where('name', 'Nikos Georgiou')->value('id');
    assert(is_int($id));

    return (string) $id;
}

it('filters to one member, pages forward, opens a row, reloads onto the same dialog, and closes back to the filtered list', function () {
    for ($n = 0; $n < 29; $n++) {
        screenBooking(sprintf('Nikos Customer %02d', $n), 'Nikos Georgiou', athensDaysOn(62 + $n));
    }

    $longTrail = screenBooking('Tessa Trail', 'Nikos Georgiou', athensDaysOn(61, 9));
    screenBooking('Elena Customer', 'Elena Papadaki', athensDaysOn(96, 11));
    $utc = fn (DateTimeImmutable $at): string => $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.uP');
    DB::table('tenant_luna.audit_log')->insert(array_map(fn (int $n): array => [
        'booking_id' => $longTrail, 'actor' => 'customer', 'action' => 'rescheduled',
        'payload' => json_encode(['moved_from' => $utc(athensDaysOn(31))], JSON_THROW_ON_ERROR),
        'created_at' => $utc(athensDaysOn($n - 30)),
    ], range(0, 29)));
    $uuid = DB::table('tenant_luna.bookings')->where('id', $longTrail)->value('uuid');
    assert(is_string($uuid));
    $nikos = nikosId();

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'));
    $page->click(navLabel('bookings'))
        ->waitForText(adminLine('admin/bookings.table.count', ['count' => '25', 'total' => '31']))
        ->assertPathIs('/admin/bookings')
        ->assertSeeIn('.admin-bookings', 'Elena Customer');

    $page->select('#bookings-staff', $nikos)
        ->waitForText(adminLine('admin/bookings.table.count', ['count' => '25', 'total' => '30']))
        ->assertDontSeeIn('.admin-bookings', 'Elena Customer')
        ->assertQueryStringHas('staff', $nikos);

    $page->click('.admin-pagination__next')
        ->waitForText(adminLine('admin/bookings.table.count', ['count' => '5', 'total' => '30']))
        ->assertSeeIn('.admin-bookings', 'Tessa Trail');

    $page->click('Tessa Trail')
        ->waitForText(adminLine('admin/bookings.dialog.items.title'))
        ->assertPathIs('/admin/bookings/'.$uuid)
        ->assertSeeIn('.admin-modal__title', 'Tessa Trail')
        ->assertScript("(() => { const body = document.querySelector('.admin-modal__body'); const close = document.querySelector('.admin-booking-close').getBoundingClientRect(); return body.scrollHeight > body.clientHeight && close.top >= 0 && close.bottom <= window.innerHeight; })()", true);

    $page->refresh()
        ->waitForText(adminLine('admin/bookings.dialog.items.title'))
        ->assertPathIs('/admin/bookings/'.$uuid)
        ->assertSeeIn('.admin-modal__title', 'Tessa Trail')
        ->assertValue('#bookings-staff', $nikos);

    $page->click('.admin-booking-close')
        ->waitForText(adminLine('admin/bookings.table.count', ['count' => '5', 'total' => '30']))
        ->assertPathIs('/admin/bookings')
        ->assertQueryStringHas('staff', $nikos)
        ->assertValue('#bookings-staff', $nikos)
        ->assertCount('.admin-modal__frame', 0);

    $page->click('.admin-filter-clear')
        ->waitForText(adminLine('admin/bookings.table.count', ['count' => '25', 'total' => '31']))
        ->assertPathIs('/admin/bookings')
        ->assertQueryStringMissing('staff')
        ->assertValue('#bookings-staff', '')
        ->assertSeeIn('.admin-bookings', 'Elena Customer');

    $page->assertAttribute('#bookings-search', 'placeholder', adminLine('admin/bookings.filters.search_name_email'))
        ->type('#bookings-search', 'nobody-by-this-name')
        ->waitForText(adminLine('admin/bookings.page.empty'))
        ->assertQueryStringHas('search', 'nobody-by-this-name')
        ->assertSeeIn('.admin-bookings-empty', adminLine('admin/bookings.page.empty'))
        ->assertCount('.admin-bookings', 0)
        ->assertCount('.admin-list-error', 0)
        ->assertNoJavaScriptErrors();
});

it('restores the bar from the tab\'s memory on a plain visit, the page number never', function () {
    screenBooking('Elena Customer', 'Elena Papadaki', athensDaysOn(65, 11));
    screenBooking('Nikos Customer', 'Nikos Georgiou', athensDaysOn(66, 11));
    $nikos = nikosId();

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'));
    $page->click(navLabel('bookings'))->waitForText(adminLine('admin/bookings.table.count', ['count' => '2', 'total' => '2']));
    $page->select('#bookings-staff', $nikos)->waitForText(adminLine('admin/bookings.table.count', ['count' => '1', 'total' => '1']));
    $page->script("sessionStorage.setItem('kr.admin.bookings.filters', JSON.stringify({staff: {$nikos}, status: 42, page: 3}))");

    $page->navigate(Admin::url('luna', '/bookings'))
        ->waitForText(adminLine('admin/bookings.table.count', ['count' => '1', 'total' => '1']))
        ->assertValue('#bookings-staff', $nikos)
        ->assertValue('#bookings-status', '')
        ->assertQueryStringHas('staff', $nikos)
        ->assertSeeIn('.admin-bookings', 'Nikos Customer');
});

/** Makes the booking of the id given a request on an open approval window. */
function screenRequest(int $id): void
{
    DB::table('tenant_luna.bookings')->where('id', $id)->update([
        'status' => 'awaiting_approval', 'hold_class' => 'approval',
        'hold_expires_at' => DB::raw("now() + interval '2 days'"), 'requires_approval' => true,
    ]);
}

it('keeps the list\'s head row at the top of the window at 1440 pixels while the page scrolls through a long list, a request\'s row wearing its amber bar', function () {
    for ($n = 0; $n < 24; $n++) {
        screenBooking(sprintf('Nikos Customer %02d', $n), 'Nikos Georgiou', athensDaysOn(62 + $n));
    }

    screenRequest(screenBooking('Rhea Request', 'Elena Papadaki', athensDaysOn(61, 11)));

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'))->resize(1440, 900);
    $page->navigate(Admin::url('luna', '/bookings'))
        ->waitForText(adminLine('admin/bookings.table.count', ['count' => '25', 'total' => '25']))
        ->assertCount('.admin-bookings .admin-table-scroll', 0)
        ->assertCount('.admin-row--attention', 1)
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true);
    Look::shot($page, 'bookings-1440');

    $page->assertScript(BOOKINGS_HEAD_HELD, true)
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true)
        ->assertNoJavaScriptErrors();
});

it('stands the filters as many to a row as the card holds in Greek, six at 1920 and 1912 pixels, three at 1440 and 1120 and two at 800, no select cutting the value it shows', function () {
    $el = SiteLanguage::Greek;
    DB::table('users')->where('email', Admin::owner('luna'))->update(['locale' => $el->value]);
    screenBooking('Elena Customer', 'Elena Papadaki', athensDaysOn(65, 11));

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'))->resize(1920, 1000);
    $page->navigate(Admin::url('luna', '/bookings'))
        ->waitForText(adminLine('admin/bookings.table.count', ['count' => '1', 'total' => '1'], $el))
        ->assertScript(PhoneScripts::PAGE_LANGUAGE, $el->value)
        ->assertScript(BOOKINGS_FIELDS_PER_ROW, [6])
        ->assertScript(BOOKINGS_SELECTS_CUT, [])
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true);
    Look::shot($page, 'bookings-1920-el');

    $page->resize(1912, 1000)
        ->assertScript(BOOKINGS_FIELDS_PER_ROW, [6])
        ->assertScript(BOOKINGS_SELECTS_CUT, [])
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true);

    $page->resize(1440, 900)
        ->assertScript(BOOKINGS_FIELDS_PER_ROW, [3, 3])
        ->assertScript(BOOKINGS_SELECTS_CUT, [])
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true);
    Look::shot($page, 'bookings-1440-el');

    $page->resize(1120, 900)
        ->assertScript(BOOKINGS_FIELDS_PER_ROW, [3, 3])
        ->assertScript(BOOKINGS_SELECTS_CUT, [])
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true);

    $page->resize(800, 900)
        ->assertScript(BOOKINGS_FIELDS_PER_ROW, [2, 2, 2])
        ->assertScript(BOOKINGS_SELECTS_CUT, [])
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true)
        ->assertNoJavaScriptErrors();
    Look::shot($page, 'bookings-800-el');
});

it('keeps the list inside its card in Greek beside the open sidebar at 1024, 1084 and 1200 pixels, a customer\'s name of sixty characters wrapping inside its cell beside the long status chip', function () {
    expect(strlen(BOOKINGS_LONG_CUSTOMER))->toBe(60);
    $el = SiteLanguage::Greek;
    DB::table('users')->where('email', Admin::owner('luna'))->update(['locale' => $el->value]);
    screenRequest(screenBooking(BOOKINGS_LONG_CUSTOMER, 'Elena Papadaki', athensDaysOn(61, 11)));
    screenBooking('Tessa Trail', 'Elena Papadaki', athensDaysOn(62, 9));

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'))->resize(1024, 800);
    $page->navigate(Admin::url('luna', '/bookings'))
        ->waitForText(adminLine('admin/bookings.table.count', ['count' => '2', 'total' => '2'], $el))
        ->assertScript(PhoneScripts::PAGE_LANGUAGE, $el->value)
        ->assertScript(BOOKINGS_TABLE_FITS, [])
        ->assertScript(BOOKINGS_LONG_NAME_INSIDE, true)
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true);
    Look::shot($page, 'bookings-1024-el');

    $page->resize(1084, 800)
        ->assertScript("getComputedStyle(document.querySelector('.admin-bookings table')).display", 'table')
        ->assertScript(BOOKINGS_TABLE_FITS, [])
        ->assertScript(BOOKINGS_LONG_NAME_INSIDE, true)
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true);
    Look::shot($page, 'bookings-1084-el');

    $page->resize(1200, 800)
        ->assertScript(BOOKINGS_TABLE_FITS, [])
        ->assertScript(BOOKINGS_LONG_NAME_INSIDE, true)
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true)
        ->assertNoJavaScriptErrors();
    Look::shot($page, 'bookings-1200-el');
});

it('reads each booking as a block on a phone in Greek, a long service name inside its row, a customer\'s name of sixty characters wrapping clear of the total and a request\'s amber bar, folds the filters behind a button that counts them, and draws the customers list\'s pager and a customer\'s bookings the same way', function () {
    $service = 'HaircutWashLongLayersBlowDryAndHandWaveX';
    expect(strlen($service))->toBe(40);
    $el = SiteLanguage::Greek;
    screenBooking('Tessa Trail', 'Elena Papadaki', athensDaysOn(61, 9));
    screenRequest(screenBooking('Rhea Request', 'Elena Papadaki', athensDaysOn(62, 10)));
    screenBooking(BOOKINGS_LONG_CUSTOMER, 'Elena Papadaki', athensDaysOn(63, 11));
    DB::table('tenant_luna.services')->where('name', 'Haircut')->update(['name' => $service]);
    DB::table('users')->where('email', Admin::owner('luna'))->update(['locale' => $el->value]);
    $elena = DB::table('tenant_luna.staff')->where('name', 'Elena Papadaki')->value('id');
    assert(is_int($elena));
    $all = adminLine('admin/bookings.table.count', ['count' => '3', 'total' => '3'], $el);
    $one = adminLine('admin/bookings.table.count', ['count' => '1', 'total' => '1'], $el);

    $page = Admin::signInOnPhone($this, 'luna', Admin::owner('luna'))->assertVisible(Admin::HOME);
    $page->navigate(Admin::url('luna', '/bookings'))
        ->waitForText($all)
        ->assertScript(PhoneScripts::PAGE_LANGUAGE, $el->value)
        ->assertScript(PhoneScripts::VIEWPORT_WIDTH, 390)
        ->assertScript(BOOKINGS_ROWS_READ, true)
        ->assertScript(BOOKINGS_LONG_NAME_INSIDE, true)
        ->assertScript(BOOKINGS_LONG_NAME_AND_BAR, true)
        ->assertScript(BOOKINGS_PAGER_ON_ONE_LINE, true)
        ->assertAttribute('.admin-approval-inbox', 'aria-pressed', 'false')
        ->assertAttribute('.admin-filters-toggle', 'aria-expanded', 'false')
        ->assertAttribute('.admin-filters-toggle', 'aria-controls', 'bookings-filters')
        ->assertScript("document.getElementById('bookings-filters').hidden", true)
        ->assertNotPresent('.admin-filters-toggle__count')
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true);
    Look::shot($page, 'bookings-390-el-none');

    $page->click('.admin-filters-toggle')
        ->assertAttribute('.admin-filters-toggle', 'aria-expanded', 'true')
        ->assertVisible('#bookings-search')
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true);
    Look::shot($page, 'bookings-390-el-open');

    // Three set: the inbox, which is the status filter, a member and a search.
    $page->click('.admin-approval-inbox')
        ->waitForText($one)
        ->assertAttribute('.admin-approval-inbox', 'aria-pressed', 'true')
        ->assertSeeIn('.admin-filters-toggle__count', '1')
        ->select('#bookings-staff', (string) $elena)
        ->assertSeeIn('.admin-filters-toggle__count', '2')
        ->type('#bookings-search', 'Rhea')
        ->waitForText($one)
        ->assertSeeIn('.admin-filters-toggle__count', '3')
        ->assertScript("document.querySelector('.admin-filters-toggle .sr-only').textContent", ', '.adminChoice('admin/bookings.filters.active', 3, [], $el))
        ->assertSeeIn('.admin-bookings', 'Rhea Request')
        ->assertScript(BOOKINGS_ROWS_READ, true)
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true);
    Look::shot($page, 'bookings-390-el-three');

    $page->click('.admin-filters-toggle')
        ->assertAttribute('.admin-filters-toggle', 'aria-expanded', 'false')
        ->assertScript("document.getElementById('bookings-filters').hidden", true)
        ->assertSeeIn('.admin-filters-toggle__count', '3')
        ->assertAttribute('.admin-approval-inbox', 'aria-pressed', 'true');

    $page->click('.admin-filters-toggle')
        ->type('#bookings-search', 'nobody-by-this-name')
        ->waitForText(adminLine('admin/bookings.page.empty', [], $el))
        ->assertCount('.admin-bookings', 0)
        ->assertCount('.admin-bookings-empty button', 0)
        ->assertCount('.admin-filter-clear', 1)
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true);
    Look::shot($page, 'bookings-390-el-empty');

    $page->click('.admin-filter-clear')
        ->waitForText($all)
        ->assertNotPresent('.admin-filters-toggle__count')
        ->assertAttribute('.admin-approval-inbox', 'aria-pressed', 'false');

    // The pager is shared: the customers list draws the same round arrows around its page line.
    $page->navigate(Admin::url('luna', '/customers'))
        ->assertVisible('.admin-customers-screen .admin-pagination')
        ->assertScript(BOOKINGS_PAGER_ON_ONE_LINE, true)
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true);
    Look::shot($page, 'customers-390-el');

    $page->click('Tessa Trail')
        ->assertVisible('.admin-customer-screen')
        ->assertVisible('.admin-bookings__row')
        ->assertScript(BOOKINGS_ROWS_READ, true)
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true)
        ->assertNoJavaScriptErrors();
    Look::shot($page, 'customer-390-el');
});
