<?php

use App\Http\Controllers\Admin\BookingActionController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\NewBookingController;
use App\Site\SiteLanguage;
use Database\Seeders\DemoTenantsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Browser\Support\Admin;
use Tests\Browser\Support\Look;
use Tests\Browser\Support\PhoneScripts;

/*
 * The Customers screen in a real browser: the owner opens Customers from the rail, searches by
 * part of an address, opens the customer, opens one of their bookings in the dialog over the
 * customer's page and cancels it, the answer arriving through the server's redirect and flash with
 * the page's address unchanged, and a reload opening no dialog. The settledness gate's waiting
 * states are proven by the component tests alone: a browser cannot be made to hold a read
 * honestly. Then the erasure from a customer's page: the Danger zone's control, its dialog
 * refusing while a booking is live, the booking cancelled, the confirm naming the count, and the
 * list afterwards without the address. Then the page's tiles and Book now: the owner reads the
 * five tiles, presses Book now, finds the customer filled in on the wizard's last step, creates
 * the booking and sees Upcoming grow. Then the Profile card: the owner saves tags, a birthday and
 * a language, the head shows the tags, and the list's name cell shows them as chips. Then the
 * list's Download CSV: pressed under a search, the request carries the search.
 *
 * Last the list's layout, in Greek, with a customer of a sixty-character name, a sixty-character
 * email with no break and two phones: at 1440 pixels the head row stays at the top of the window
 * while the page scrolls through a long list; beside the open sidebar the card holds rows, each
 * visit on two lines at most, up to the first window where it holds the grid with the visits
 * apart, and there the table fits its card and the email and the phones keep their floors, a short
 * address and each short phone on one line, one customer's last visit a date that has passed, the
 * widest a Last visit cell gets at its narrowest; and on a phone each customer is a block of the
 * name with the booking count at its right, then the email, the phones and the visits beneath, the
 * email kept inside its row, and a search with no match keeps the toolbar in the card. A phone of
 * two numbers, one of forty characters and one of forty with no break stay inside the card in the
 * narrowest grid and on a phone, and so does a line of other names holding one long unbroken name.
 *
 * The bookings are written straight to luna's rows, every start whole days from today, because
 * the browser and the application both read the real clock here.
 */

beforeEach(function () {
    dropDemoSchemas();
    $this->seed(DemoTenantsSeeder::class);
});

afterEach(fn () => dropDemoSchemas());

// The rail's own entry, reached by its target: the screen's heading reads "Customers" too.
const CUSTOMERS_WALK_ENTRY = '.admin-rail a[href="/admin/customers"]';

/**
 * A confirmed Haircut on Elena's row under an address, starting at the instant given, answering
 * the booking's uuid.
 */
function customersWalkBooking(string $name, string $email, DateTimeImmutable $start): string
{
    $utc = fn (DateTimeImmutable $at): string => $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:sP');
    $uuid = (string) Str::uuid();
    $id = DB::table('tenant_luna.bookings')->insertGetId([
        'source' => 'embed',
        'uuid' => $uuid, 'status' => 'confirmed', 'customer_name' => $name, 'customer_email' => $email,
        'customer_phone' => '+30 210 7654321', 'total_minor' => 3500, 'currency' => 'EUR',
        'payment_mode' => 'onsite', 'requires_approval' => false, 'on_approval_timeout' => 'expire',
    ]);
    DB::table('tenant_luna.booking_items')->insert([
        'booking_id' => $id, 'sort' => 0,
        'service_id' => DB::table('tenant_luna.services')->where('name', 'Haircut')->value('id'),
        'staff_id' => DB::table('tenant_luna.staff')->where('name', 'Elena Papadaki')->value('id'),
        'start_utc' => $utc($start), 'end_utc' => $utc($start->add(new DateInterval('PT45M'))),
        'block_start_utc' => $utc($start), 'block_end_utc' => $utc($start->add(new DateInterval('PT45M'))),
        'price_minor' => 3500, 'blocks' => false,
    ]);

    return $uuid;
}

it('opens Customers from the rail, searches, opens a customer, opens a booking over the customer\'s page and cancels it', function () {
    customersWalkBooking('Casey C.', 'Casey@Example.com', athensDaysOn(61));
    $latest = customersWalkBooking('Casey Customer', 'casey@example.com', athensDaysOn(63));
    customersWalkBooking('Ada North', 'ada@example.test', athensDaysOn(62));

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'));
    $page->click(CUSTOMERS_WALK_ENTRY)
        ->waitForText(adminLine('admin/customers.table.count', ['count' => '2', 'total' => '2']))
        ->assertPathIs('/admin/customers')
        ->assertAttribute('[aria-current="page"]', 'href', '/admin/customers')
        ->assertSeeIn('.admin-customers', 'Ada North')
        ->assertSeeIn('.admin-customers', 'Casey Customer')
        ->assertSeeIn('.admin-customers', adminLine('admin/customers.table.also_booked_as', ['names' => 'Casey C.']));

    $page->assertAttribute('#customers-search', 'placeholder', adminLine('admin/customers.page.hint_contact'))
        ->type('#customers-search', 'CASEY@')
        ->waitForText(adminLine('admin/customers.table.count', ['count' => '1', 'total' => '1']))
        ->assertQueryStringHas('search', 'CASEY@')
        ->assertDontSeeIn('.admin-customers', 'Ada North')
        ->assertSeeIn('.admin-customers__email', 'casey@example.com');

    $page->click('.admin-customers .admin-customers__name')
        ->waitForText('casey@example.com')
        ->assertPathIs('/admin/customers/casey%40example.com')
        ->assertSeeIn('.admin-customer-head h1', 'Casey Customer')
        ->assertSeeIn('.admin-customer-head__email', 'casey@example.com')
        ->assertSeeIn('.admin-bookings__count', adminLine('admin/bookings.table.count', ['count' => '2', 'total' => '2']))
        ->assertAttribute('.admin-rail [aria-current="page"]', 'href', '/admin/customers');

    $page->click('.admin-bookings__row:first-child .admin-row-select')
        ->waitForText(adminLine('admin/bookings.dialog.items.title'))
        ->assertSeeIn('.admin-modal__title', 'Casey Customer')
        ->assertPathIs('/admin/customers/casey%40example.com')
        ->assertQueryStringMissing('booking')
        ->assertSeeIn('.admin-booking-status .admin-chip', adminLine('admin/shell.kit.statuses.confirmed'))
        ->click('.admin-booking-action--cancel')
        ->waitForText(adminLine('admin/bookings.cancel.warning'))
        ->click('.admin-cancel-prompt__confirm')
        ->waitForText(adminLine(BookingActionController::CANCELLED))
        ->assertSeeIn('.admin-booking-status .admin-chip', adminLine('admin/shell.kit.statuses.cancelled'))
        ->assertCount('.admin-booking-actions', 0)
        ->assertPathIs('/admin/customers/casey%40example.com')
        ->assertQueryStringMissing('booking');

    $page->click('.admin-booking-close')
        ->assertCount('.admin-modal__frame', 0)
        ->assertSeeIn('.admin-bookings__row .admin-chip', adminLine('admin/shell.kit.statuses.cancelled'));

    // The dialog was the page's own state: a reload lands on the customer's page with none open.
    $page->refresh()
        ->waitForText(adminLine('admin/bookings.table.count', ['count' => '2', 'total' => '2']))
        ->assertPathIs('/admin/customers/casey%40example.com')
        ->assertCount('.admin-modal__frame', 0)
        ->assertSeeIn('.admin-bookings__row .admin-chip', adminLine('admin/shell.kit.statuses.cancelled'))
        ->assertNoJavaScriptErrors();

    expect(DB::table('tenant_luna.bookings')->where('uuid', $latest)->value('status'))->toBe('cancelled');
});

it('erases a customer from their page: refused while a booking is live, the booking cancelled, the confirm naming the count, and the list without the address', function () {
    $past = customersWalkBooking('Casey Customer', 'casey@example.com', athensDaysOn(-3));
    $live = customersWalkBooking('Casey Customer', 'casey@example.com', athensDaysOn(63));
    customersWalkBooking('Ada North', 'ada@example.test', athensDaysOn(62));

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'));
    $page->click(CUSTOMERS_WALK_ENTRY)
        ->waitForText(adminLine('admin/customers.table.count', ['count' => '2', 'total' => '2']))
        ->click('.admin-customers__row:first-child .admin-customers__name')
        ->waitForText(adminLine('admin/customers.erasure.title'))
        ->assertPathIs('/admin/customers/casey%40example.com')
        ->assertSeeIn('.admin-customer-danger__what', adminLine('admin/customers.erasure.what'));

    // A booking still to come: the dialog names it and says to cancel first, and offers no erasure.
    $page->click('.admin-customer-erase')
        ->waitForText(adminLine('admin/customers.erasure.refused_title'))
        ->assertSeeIn('.admin-erase-refused__sentence', adminChoice(CustomerController::LIVE, 1))
        ->assertCount('.admin-erase-prompt__confirm', 0)
        ->click('.admin-erase-refused__close')
        ->assertCount('.admin-modal__frame', 0);

    $page->click('.admin-bookings__row:first-child .admin-row-select')
        ->waitForText(adminLine('admin/bookings.dialog.items.title'))
        ->click('.admin-booking-action--cancel')
        ->waitForText(adminLine('admin/bookings.cancel.warning'))
        ->click('.admin-cancel-prompt__confirm')
        ->waitForText(adminLine(BookingActionController::CANCELLED))
        ->click('.admin-booking-close')
        ->assertCount('.admin-modal__frame', 0);

    $page->click('.admin-customer-erase')
        ->waitForText(adminLine('admin/customers.erasure.confirm_title'))
        ->assertSeeIn('.admin-erase-prompt__count', adminChoice('admin/customers.erasure.count', 2))
        ->click('.admin-erase-prompt__confirm')
        ->waitForText(adminLine(CustomerController::ERASED))
        ->assertPathIs('/admin/customers')
        ->waitForText(adminLine('admin/customers.table.count', ['count' => '1', 'total' => '1']))
        ->assertSeeIn('.admin-customers', 'Ada North')
        ->assertDontSeeIn('.admin-customers', 'Casey Customer')
        ->assertNoJavaScriptErrors();

    expect(DB::table('tenant_luna.bookings')->whereIn('uuid', [$past, $live])->pluck('customer_email')->all())->toBe([null, null])
        ->and(DB::table('tenant_luna.bookings')->where('uuid', $live)->value('status'))->toBe('cancelled');
});

it('reads the customer\'s tiles, books them from Book now with their fields filled in on the wizard\'s last step, and sees Upcoming grow', function () {
    $past = customersWalkBooking('Casey Customer', 'casey@example.com', athensDaysOn(-3));
    DB::table('tenant_luna.bookings')->where('uuid', $past)->update(['status' => 'completed']);
    customersWalkBooking('Casey Customer', 'casey@example.com', athensDaysOn(62));
    $cancelled = customersWalkBooking('Casey Customer', 'casey@example.com', athensDaysOn(-2));
    DB::table('tenant_luna.bookings')->where('uuid', $cancelled)->update(['status' => 'cancelled']);

    // The Tuesday of the second availability window, Elena's 09:00 offered whatever the date.
    $today = athensToday();
    $day = firstWindowDay(2);
    $months = ((int) $day->format('Y') - (int) $today->format('Y')) * 12 + (int) $day->format('n') - (int) $today->format('n');
    $nine = $day->setTime(9, 0)->setTimezone(new DateTimeZone('UTC'))->format(DateTimeImmutable::RFC3339);
    $tile = fn (string $id): string => '.admin-customer-glance__figure--'.$id.' strong';

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'));
    $page->navigate(Admin::url('luna', '/customers/casey%40example.com'))
        ->waitForText(adminLine('admin/customers.tiles.title'))
        ->assertSeeIn($tile('upcoming'), '1')
        ->assertSeeIn($tile('completed'), '1')
        ->assertSeeIn($tile('no-shows'), '0')
        ->assertSeeIn($tile('cancelled'), '1')
        ->assertSeeIn($tile('last-visit'), athensDaysOn(-3)->format('d/m/Y'));

    $page->click('.admin-customer-book')
        ->waitForText(adminLine('admin/customers.wizard.steps.when'))
        ->assertPresent('.admin-wizard')
        ->assertSeeIn('.admin-wizard-strip li:nth-child(3)', 'Casey Customer')
        ->click('.admin-wizard-continue')
        ->waitForText(adminLine('admin/customers.wizard.zone', ['zone' => 'Europe/Athens']));

    for ($month = 0; $month < $months; $month++) {
        $page->click(sprintf('.admin-day-calendar button[aria-label="%s"]', adminLine('admin/shell.kit.month_grid.next')));
    }

    $page->click('button[data-date="'.$day->format('Y-m-d').'"]')
        ->waitForText(adminLine('admin/customers.wizard.periods.morning'))
        ->click('.admin-wizard-slot[value="'.$nine.'"]')
        ->click('.admin-wizard-continue')
        ->waitForText(adminLine('admin/customers.wizard.fields.name'))
        ->assertValue('#wizard-customer-name', 'Casey Customer')
        ->assertValue('#wizard-customer-email', 'casey@example.com')
        ->assertValue('#wizard-customer-phone', '+30 210 7654321')
        ->click('.admin-wizard-create')
        ->waitForText(adminLine(NewBookingController::CREATED))
        ->assertCount('.admin-wizard', 0)
        ->assertPathIs('/admin/customers/casey%40example.com')
        ->assertSeeIn($tile('upcoming'), '2')
        ->assertSeeIn('.admin-bookings__count', adminLine('admin/bookings.table.count', ['count' => '4', 'total' => '4']))
        ->assertNoJavaScriptErrors();

    expect(DB::table('tenant_luna.bookings')->where('customer_email', 'casey@example.com')->where('status', 'confirmed')->count())->toBe(2);
});

it('saves the customer\'s profile from the Profile card, the head showing the tags, and the list\'s name cell the same chips', function () {
    customersWalkBooking('Casey Customer', 'casey@example.com', athensDaysOn(5));

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'));
    $page->navigate(Admin::url('luna', '/customers/casey%40example.com'))
        ->waitForText(adminLine('admin/customers.profile.title'))
        ->assertCount('.admin-customer-head__chips', 0)
        ->type('#customer-tags', 'VIP, colour, vip')
        ->select('#customer-language', 'el')
        ->click('.admin-customer-profile__save')
        ->waitForText(adminLine('admin/customers.flashes.profile_saved'))
        ->assertPathIs('/admin/customers/casey%40example.com')
        ->assertValue('#customer-tags', 'VIP, colour')
        ->assertValue('#customer-language', 'el')
        ->assertSeeIn('.admin-customer-head__chips', 'VIP')
        ->assertSeeIn('.admin-customer-head__chips', 'colour')
        ->assertNoJavaScriptErrors();

    $page->click(CUSTOMERS_WALK_ENTRY)
        ->waitForText('casey@example.com')
        ->assertSeeIn('.admin-customers__row:first-child .admin-customers__chips', 'VIP')
        ->assertSeeIn('.admin-customers__row:first-child .admin-customers__chips', 'colour')
        ->assertNoJavaScriptErrors();

    expect(DB::table('tenant_luna.customer_profiles')->where('email', 'casey@example.com')->value('tags'))->toBe('{VIP,colour}');
});

it('adds a note from the Notes card and sees it at the top of the list with the owner\'s name, then removes it through the confirm', function () {
    customersWalkBooking('Casey Customer', 'casey@example.com', athensDaysOn(5));
    $owner = DB::table('users')->where('email', Admin::owner('luna'))->value('name');
    assert(is_string($owner));

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'));
    $page->navigate(Admin::url('luna', '/customers/casey%40example.com'))
        ->waitForText(adminLine('admin/customers.notes.title'))
        ->assertCount('.admin-customer-note', 0)
        ->type('#customer-note', 'Prefers mornings.')
        ->click('.admin-customer-notes__add')
        ->waitForText(adminLine('admin/customers.flashes.note_added'))
        ->assertValue('#customer-note', '')
        ->assertSeeIn('.admin-customer-note:first-child .admin-customer-note__body', 'Prefers mornings.')
        ->assertSeeIn('.admin-customer-note:first-child .admin-customer-note__byline', $owner)
        ->click('.admin-customer-note:first-child .admin-customer-note__remove')
        ->click('.admin-note-remove__confirm')
        ->waitForText(adminLine('admin/customers.flashes.note_removed'))
        ->assertCount('.admin-customer-note', 0)
        ->assertNoJavaScriptErrors();

    expect(DB::table('tenant_luna.customer_notes')->count())->toBe(0);
});

it('blocks the address from the Online booking card through the confirm, the list\'s name cell then carrying Blocked, and unblocks it', function () {
    customersWalkBooking('Casey Customer', 'casey@example.com', athensDaysOn(5));

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'));
    $page->navigate(Admin::url('luna', '/customers/casey%40example.com'))
        ->waitForText(adminLine('admin/customers.block.open'))
        ->type('#customer-block-reason', 'Three no-shows')
        ->click('.admin-customer-block__block')
        ->click('.admin-block-prompt__confirm')
        ->waitForText(adminLine('admin/customers.flashes.blocked'))
        ->assertSeeIn('.admin-customer-block__reason', 'Three no-shows')
        ->assertNoJavaScriptErrors();

    $page->click(CUSTOMERS_WALK_ENTRY)
        ->waitForText('casey@example.com')
        ->assertSeeIn('.admin-customers__row:first-child .admin-customers__blocked', adminLine('admin/customers.table.blocked'))
        ->assertNoJavaScriptErrors();

    $page->navigate(Admin::url('luna', '/customers/casey%40example.com'))
        ->waitForText(adminLine('admin/customers.block.unblock'))
        ->click('.admin-customer-block__unblock')
        ->waitForText(adminLine('admin/customers.flashes.unblocked'))
        ->assertSee(adminLine('admin/customers.block.open'))
        ->assertNoJavaScriptErrors();

    expect(DB::table('tenant_luna.customer_profiles')->where('email', 'casey@example.com')->value('blocked_at'))->toBeNull();
});

it('downloads the list as CSV under the search on screen: the request carries the search and not the page', function () {
    customersWalkBooking('Casey Customer', 'casey@example.com', athensDaysOn(61));
    customersWalkBooking('Ada North', 'ada@example.test', athensDaysOn(62));

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'));
    $page->click(CUSTOMERS_WALK_ENTRY)
        ->waitForText(adminLine('admin/customers.table.count', ['count' => '2', 'total' => '2']))
        ->type('#customers-search', 'CASEY@')
        // The search's read has landed once the address carries it and the list's own count reads
        // one of one; a bare wait on the count's words would hold at once on the pager's "Page 1 of
        // 1" while the list still shows both, the button still holding the empty search.
        ->assertScript(sprintf(<<<'JS'
            () => new URL(window.location.href).searchParams.get('search') === 'CASEY@'
                && document.querySelector('.admin-customers__count')?.textContent === %s
            JS, json_encode(adminLine('admin/customers.table.count', ['count' => '1', 'total' => '1']))), true)
        ->assertSeeIn('.admin-customers-bar .admin-customers-download', adminLine('admin/customers.csv.download'));

    // The page's fetch is watched, not stood in for: the file is still asked of the server.
    $page->script(<<<'JS'
        (() => {
            window.__customersCsv = [];
            const send = window.fetch.bind(window);
            window.fetch = (url, init) => { window.__customersCsv.push(String(url)); return send(url, init); };
        })()
        JS);
    $page->click('.admin-customers-download')
        ->wait(1)
        ->assertNoJavaScriptErrors();

    expect($page->script('window.__customersCsv'))->toBe(['/admin/customers/csv?search=CASEY%40'])
        ->and($page->script("document.querySelector('.admin-customers-download').getAttribute('aria-busy')"))->toBeNull();
});

/** A customer's name of sixty characters, in words, two of them joined by a hyphen. */
const CUSTOMERS_LIST_LONG_NAME = 'Konstantina-Maria Papadopoulou-Chatzigeorgiou Theodoropoulou';

/**
 * Books the customer of the long name under an email of sixty characters with no break, twice, a
 * day apart from the day given and under two phones, so the list's row carries both phones.
 */
function customersListLongCustomer(int $days): void
{
    $email = str_repeat('a', 47).'@luna.example';
    expect([strlen(CUSTOMERS_LIST_LONG_NAME), strlen($email)])->toBe([60, 60]);
    customersWalkBooking(CUSTOMERS_LIST_LONG_NAME, $email, athensDaysOn($days));
    $second = customersWalkBooking(CUSTOMERS_LIST_LONG_NAME, $email, athensDaysOn($days + 1));
    DB::table('tenant_luna.bookings')->where('uuid', $second)->update(['customer_phone' => '+30 210 1234567']);
}

/**
 * A fault script's answer as the faults it names, each a sentence; anything else fails here.
 *
 * @return list<string>
 */
function customersFaults(mixed $answer): array
{
    assert(is_array($answer));
    $faults = [];
    foreach ($answer as $fault) {
        assert(is_string($fault));
        $faults[] = $fault;
    }

    return $faults;
}

/**
 * True when the page has scrolled the list's top out of the window and its head row rests at the
 * window's top, nothing drawn over its middle.
 */
const CUSTOMERS_LIST_HEAD_HELD = <<<'JS'
    document.fonts.ready.then(() => new Promise((resolve) => {
        const table = document.querySelector('.admin-customers table');
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

/**
 * True when every customer of the list reads as a block inside its card: the name's cell with the
 * booking count on its line at its right, then the email, the phones and the visits each beneath
 * the one before, starting where the name starts; the two visits apart drawn nowhere, no cell
 * labelled, the head row out of sight and the table no wider than its card.
 */
const CUSTOMERS_LIST_ROWS_READ = <<<'JS'
    document.fonts.ready.then(() => {
        const card = document.querySelector('.admin-customers');
        const table = card?.querySelector('table');
        const rows = [...document.querySelectorAll('.admin-customers__row')];
        if (!card || !table || rows.length === 0) {
            return false;
        }
        const line = (a, b) => a.top < b.bottom && b.top < a.bottom;
        const start = (element) => element.getBoundingClientRect().left + parseFloat(getComputedStyle(element).paddingLeft);
        const head = card.querySelector('thead').getBoundingClientRect();
        const reads = rows.every((row) => {
            const box = row.getBoundingClientRect();
            const name = row.querySelector('.admin-customers__name').getBoundingClientRect();
            const beneath = ['.admin-customers__email', '.admin-customers__phones', '.admin-customers__visits'].map((hook) => row.querySelector(hook));
            const [email, phones, visits] = beneath.map((cell) => cell.getBoundingClientRect());
            const who = row.firstElementChild.getBoundingClientRect();
            const count = row.querySelector('.admin-customers__bookings').getBoundingClientRect();
            return getComputedStyle(row).display === 'grid'
                && [who, count, email, phones, visits].every((cell) => cell.left >= box.left - 1 && cell.right <= box.right + 1)
                && line(who, count) && count.left >= who.right - 1
                && email.top >= Math.max(who.bottom, count.bottom) - 1 && phones.top >= email.bottom - 1 && visits.top >= phones.bottom - 1
                && beneath.every((cell) => Math.abs(start(cell) - name.left) <= 1)
                && ['.admin-customers__first', '.admin-customers__last'].every((hook) => getComputedStyle(row.querySelector(hook)).display === 'none')
                && row.querySelector('.admin-table__label') === null;
        });
        return reads && head.width <= 1 && head.height <= 1 && table.scrollWidth <= table.clientWidth + 1 && card.scrollWidth <= card.clientWidth + 1;
    })
    JS;

/**
 * True when the email of sixty characters keeps inside its cell, its text no wider than the cell's
 * room, the cell inside its row and the row inside the card.
 */
const CUSTOMERS_LIST_LONG_EMAIL_INSIDE = <<<'JS'
    document.fonts.ready.then(() => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(() => {
        const email = [...document.querySelectorAll('.admin-customers__email')].find((cell) => (cell.textContent ?? '').length >= 60);
        const card = document.querySelector('.admin-customers');
        if (!email || !card) {
            resolve(false);
            return;
        }
        const cell = email.getBoundingClientRect();
        const row = email.closest('tr').getBoundingClientRect();
        const edge = card.getBoundingClientRect();
        resolve(email.scrollWidth <= email.clientWidth + 1 && cell.left >= row.left - 1 && cell.right <= row.right + 1 && row.left >= edge.left - 1 && row.right <= edge.right + 1);
    }))))
    JS;

/**
 * What keeps the customers table from fitting its card, as words with the widths, so a failure says
 * how far: the table drawn past the card's right edge, or a table that needs more than the card's
 * width less two pixels at its narrowest, its words wrapped wherever they may.
 */
const CUSTOMERS_LIST_TABLE_FITS = <<<'JS'
    document.fonts.ready.then(() => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(() => {
        const card = document.querySelector('.admin-customers');
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

/** Which form of the visits the first row draws: "shared", "apart", or "neither" for any other mix. */
const CUSTOMERS_LIST_VISITS_FORM = <<<'JS'
    (() => {
        const row = document.querySelector('.admin-customers__row');
        const shown = (hook) => row !== null && getComputedStyle(row.querySelector(hook)).display !== 'none';
        const apart = shown('.admin-customers__first') && shown('.admin-customers__last');
        const shared = shown('.admin-customers__visits');
        return shared && !apart ? 'shared' : apart && !shared ? 'apart' : 'neither';
    })()
    JS;

/**
 * True when the toolbar stands the search across its whole width, and the count and the download
 * beneath it, the download ending at the toolbar's right.
 */
const CUSTOMERS_LIST_BAR_STACKED = <<<'JS'
    document.fonts.ready.then(() => {
        const bar = document.querySelector('.admin-customers-bar');
        const parts = [document.querySelector('#customers-search'), document.querySelector('.admin-customers__count'), document.querySelector('.admin-customers-download')];
        if (!bar || parts.includes(null)) {
            return false;
        }
        const box = bar.getBoundingClientRect();
        const style = getComputedStyle(bar);
        const room = { left: box.left + parseFloat(style.paddingLeft), right: box.right - parseFloat(style.paddingRight) };
        const [field, count, download] = parts.map((part) => part.getBoundingClientRect());
        return Math.abs(field.left - room.left) <= 1 && Math.abs(field.right - room.right) <= 1
            && count.top >= field.bottom - 1 && download.top >= field.bottom - 1
            && Math.abs(download.right - room.right) <= 1 && count.left >= room.left - 1 && count.right <= download.left;
    })
    JS;

/** The width of the list's card inside its edges, in pixels. */
const CUSTOMERS_LIST_CARD_WIDTH = "document.querySelector('.admin-customers').clientWidth";

/**
 * What keeps the email and phone columns under their floors, as words with the widths: an email
 * cell narrower than 13rem, a phones cell narrower than 10.5rem, the short address
 * "ada@example.test" drawn on more than one line, or a phone of sixteen characters or fewer broken.
 */
const CUSTOMERS_LIST_FLOORS = <<<'JS'
    document.fonts.ready.then(() => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(() => {
        const rem = parseFloat(getComputedStyle(document.documentElement).fontSize);
        const emails = [...document.querySelectorAll('.admin-customers__email')];
        const phones = [...document.querySelectorAll('.admin-customers__phones')];
        const short = emails.find((cell) => cell.textContent === 'ada@example.test');
        if (emails.length === 0 || phones.length === 0 || !short) {
            resolve(['no email or phone cells']);
            return;
        }
        const lines = (element) => {
            const text = document.createRange();
            text.selectNodeContents(element);
            return Math.round(text.getBoundingClientRect().height / parseFloat(getComputedStyle(element).lineHeight));
        };
        const wrong = [];
        for (const [cells, floor, what] of [[emails, 13 * rem, 'an email'], [phones, 10.5 * rem, 'a phones']]) {
            for (const cell of cells) {
                const width = cell.getBoundingClientRect().width;
                if (width < floor - 0.5) {
                    wrong.push(`${what} cell ${Math.floor(width)}px under the floor of ${floor}px`);
                }
            }
        }
        if (lines(short) !== 1) {
            wrong.push(`ada@example.test on ${lines(short)} lines`);
        }
        for (const phone of document.querySelectorAll('.admin-customers__phones > *')) {
            if ((phone.textContent ?? '').length <= 16 && lines(phone) !== 1) {
                wrong.push(`${phone.textContent} on ${lines(phone)} lines`);
            }
        }
        resolve(wrong);
    }))))
    JS;

/**
 * What the list draws past its card's inside right edge, as words with the widths: the table's
 * own scroll width wider than the card, or a cell, a phone or a line of the other names ending
 * past the edge.
 */
const CUSTOMERS_LIST_INSIDE_CARD = <<<'JS'
    document.fonts.ready.then(() => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(() => {
        const card = document.querySelector('.admin-customers');
        const table = card?.querySelector('table');
        if (!card || !table) {
            resolve(['no table']);
            return;
        }
        const edge = card.getBoundingClientRect().left + card.clientLeft + card.clientWidth;
        const wrong = [];
        if (table.scrollWidth > card.clientWidth) {
            wrong.push(`the table scrolls ${table.scrollWidth}px in a card of ${card.clientWidth}px`);
        }
        for (const part of card.querySelectorAll('tbody td, .admin-customers__phones > *, .admin-customers__also')) {
            const right = part.getBoundingClientRect().right;
            if (right > edge + 0.5) {
                wrong.push(`"${(part.textContent ?? '').trim().slice(0, 40)}" ends ${Math.ceil(right - edge)}px past the card`);
            }
        }
        resolve(wrong);
    }))))
    JS;

/**
 * True when every line of the other names keeps inside its row's name cell, the cell ending before
 * the booking count begins.
 */
const CUSTOMERS_LIST_ALSO_INSIDE = <<<'JS'
    document.fonts.ready.then(() => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(() => {
        const lines = [...document.querySelectorAll('.admin-customers__also')];
        resolve(lines.length > 0 && lines.every((line) => {
            const row = line.closest('tr');
            const who = row.firstElementChild.getBoundingClientRect();
            const count = row.querySelector('.admin-customers__bookings').getBoundingClientRect();
            const text = document.createRange();
            text.selectNodeContents(line);
            const right = Math.max(line.getBoundingClientRect().right, text.getBoundingClientRect().right);
            return right <= who.right + 0.5 && who.right <= count.left + 0.5;
        }));
    }))))
    JS;

/** Books a customer under a phone of their own, a day the given number of days from today. */
function customersListPhoneCustomer(string $name, string $email, string $phone, int $days): void
{
    $uuid = customersWalkBooking($name, $email, athensDaysOn($days));
    DB::table('tenant_luna.bookings')->where('uuid', $uuid)->update(['customer_phone' => $phone]);
}

/** A phone of two numbers typed into the one field, as a customer may. */
const CUSTOMERS_LIST_TWO_NUMBERS = '+30 210 1234567 / 6912 345678';

/** A phone of forty characters, the longest a booking takes, broken by spaces. */
const CUSTOMERS_LIST_FORTY_PHONE = '+30 210 1234567 / +30 6912 345678 (home)';

/** A phone of forty characters with no space or other place to break. */
const CUSTOMERS_LIST_UNBROKEN_PHONE = '+302101234567691234567869123456786912345';

it('keeps the list\'s head row at the top of the window at 1440 pixels in Greek while the page scrolls through a long list, the first and last visit apart and an email of sixty characters inside its cell', function () {
    $el = SiteLanguage::Greek;
    DB::table('users')->where('email', Admin::owner('luna'))->update(['locale' => $el->value]);

    for ($n = 0; $n < 25; $n++) {
        customersWalkBooking(sprintf('Ada North %02d', $n), sprintf('ada%02d@example.test', $n), athensDaysOn(61 + $n));
    }

    customersListLongCustomer(90);

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'))->resize(1440, 900);
    $page->navigate(Admin::url('luna', '/customers'))
        ->waitForText(adminLine('admin/customers.table.count', ['count' => '25', 'total' => '26'], $el))
        ->assertScript(PhoneScripts::PAGE_LANGUAGE, $el->value)
        ->assertSeeIn('.admin-customers', CUSTOMERS_LIST_LONG_NAME)
        ->assertCount('.admin-customers .admin-table-scroll', 0)
        ->assertScript("getComputedStyle(document.querySelector('.admin-customers table')).display", 'table')
        ->assertScript(CUSTOMERS_LIST_VISITS_FORM, 'apart')
        ->assertScript(CUSTOMERS_LIST_TABLE_FITS, [])
        ->assertScript(CUSTOMERS_LIST_LONG_EMAIL_INSIDE, true)
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true);
    Look::shot($page, 'customers-1440-el');

    $page->assertScript(CUSTOMERS_LIST_HEAD_HELD, true)
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true)
        ->assertNoJavaScriptErrors();
});

it('keeps the list inside its card in Greek beside the open sidebar as rows at a card of 824px and at the first window whose card draws the grid, the visits apart there, the email and the phones at their floors or wider with a short address and each phone on one line and an email of sixty characters and a phone of forty unbroken characters inside their cells, and draws the rows one pixel narrower', function () {
    $el = SiteLanguage::Greek;
    DB::table('users')->where('email', Admin::owner('luna'))->update(['locale' => $el->value]);
    customersListLongCustomer(61);
    customersWalkBooking('Ada North', 'ada@example.test', athensDaysOn(-3));
    customersWalkBooking('Ada North', 'ada@example.test', athensDaysOn(63));
    customersListPhoneCustomer('Unbroken Phone', 'unbroken.phone@example.test', CUSTOMERS_LIST_UNBROKEN_PHONE, 64);

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'))->resize(1140, 800);
    $page->navigate(Admin::url('luna', '/customers'))
        ->waitForText(adminLine('admin/customers.table.count', ['count' => '3', 'total' => '3'], $el))
        ->assertScript(PhoneScripts::PAGE_LANGUAGE, $el->value)
        ->assertScript(CUSTOMERS_LIST_ROWS_READ, true)
        ->assertScript(CUSTOMERS_LIST_VISITS_COMPACT, [])
        ->assertScript(CUSTOMERS_LIST_LONG_EMAIL_INSIDE, true)
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true);
    $rows = $page->script(CUSTOMERS_LIST_CARD_WIDTH);
    assert(is_int($rows));
    expect($rows)->toBeGreaterThanOrEqual(824)->toBeLessThan(832);
    Look::shot($page, 'customers-1140-el');

    $page->resize(1252, 800)
        ->assertScript("getComputedStyle(document.querySelector('.admin-customers table')).display", 'table')
        ->assertScript(CUSTOMERS_LIST_VISITS_FORM, 'apart')
        ->assertScript(CUSTOMERS_LIST_TABLE_FITS, [])
        ->assertScript(CUSTOMERS_LIST_FLOORS, [])
        ->assertScript(CUSTOMERS_LIST_LONG_EMAIL_INSIDE, true)
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true);
    $apart = $page->script(CUSTOMERS_LIST_CARD_WIDTH);
    assert(is_int($apart));
    expect($apart)->toBeGreaterThanOrEqual(936)->toBeLessThan(944);
    Look::shot($page, 'customers-1252-el');

    $page->resize(1252 - ($apart - 935), 800)
        ->assertScript(CUSTOMERS_LIST_ROWS_READ, true)
        ->assertScript(CUSTOMERS_LIST_VISITS_COMPACT, [])
        ->assertScript(CUSTOMERS_LIST_LONG_EMAIL_INSIDE, true)
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true)
        ->assertNoJavaScriptErrors();
});

it('reads each customer as a block on a phone in Greek, the name with the booking count at its right and the email, the phones and the visits beneath, an email of sixty characters inside its row, and keeps the toolbar in the card on a search with no match', function () {
    $el = SiteLanguage::Greek;
    DB::table('users')->where('email', Admin::owner('luna'))->update(['locale' => $el->value]);
    customersListLongCustomer(61);
    customersWalkBooking('Ada North', 'ada@example.test', athensDaysOn(63));

    $page = Admin::signInOnPhone($this, 'luna', Admin::owner('luna'))->assertVisible(Admin::HOME);
    $page->navigate(Admin::url('luna', '/customers'))
        ->waitForText(adminLine('admin/customers.table.count', ['count' => '2', 'total' => '2'], $el))
        ->assertScript(PhoneScripts::PAGE_LANGUAGE, $el->value)
        ->assertScript(PhoneScripts::VIEWPORT_WIDTH, 390)
        ->assertScript(CUSTOMERS_LIST_BAR_STACKED, true)
        ->assertScript(CUSTOMERS_LIST_ROWS_READ, true)
        ->assertScript(CUSTOMERS_LIST_LONG_EMAIL_INSIDE, true)
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true);
    Look::shot($page, 'customers-390-el-list');

    $page->type('#customers-search', 'nobody-by-this-name')
        ->waitForText(adminLine('admin/customers.page.no_match', [], $el))
        ->assertQueryStringHas('search', 'nobody-by-this-name')
        ->assertVisible('.admin-customers .admin-customers-bar #customers-search')
        ->assertSeeIn('.admin-customers .admin-customers-empty', adminLine('admin/customers.page.no_match', [], $el))
        ->assertCount('.admin-customers table', 0)
        ->assertScript("document.querySelector('.admin-customers__count').textContent", '')
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true)
        ->assertNoJavaScriptErrors();
    Look::shot($page, 'customers-390-el-none');
});

it('keeps a phone of two numbers, one of forty characters and one of forty unbroken characters inside the list\'s card in Greek at the first window whose card draws the grid, beside a customer of a sixty-character name and email', function () {
    $el = SiteLanguage::Greek;
    DB::table('users')->where('email', Admin::owner('luna'))->update(['locale' => $el->value]);
    customersListLongCustomer(61);
    customersListPhoneCustomer('Two Numbers', 'two.numbers@example.test', CUSTOMERS_LIST_TWO_NUMBERS, 63);
    customersListPhoneCustomer('Forty Phone', 'forty.phone@example.test', CUSTOMERS_LIST_FORTY_PHONE, 64);
    customersListPhoneCustomer('Unbroken Phone', 'unbroken.phone@example.test', CUSTOMERS_LIST_UNBROKEN_PHONE, 65);
    expect([strlen(CUSTOMERS_LIST_TWO_NUMBERS), strlen(CUSTOMERS_LIST_FORTY_PHONE), strlen(CUSTOMERS_LIST_UNBROKEN_PHONE)])->toBe([29, 40, 40]);

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'))->resize(1252, 800);
    $page->navigate(Admin::url('luna', '/customers'))
        ->waitForText(adminLine('admin/customers.table.count', ['count' => '4', 'total' => '4'], $el))
        ->assertScript(PhoneScripts::PAGE_LANGUAGE, $el->value)
        ->assertSeeIn('.admin-customers', CUSTOMERS_LIST_FORTY_PHONE)
        ->assertSeeIn('.admin-customers', CUSTOMERS_LIST_UNBROKEN_PHONE)
        ->assertScript("getComputedStyle(document.querySelector('.admin-customers table')).display", 'table')
        ->assertScript(CUSTOMERS_LIST_INSIDE_CARD, [])
        ->assertScript(CUSTOMERS_LIST_TABLE_FITS, [])
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true)
        ->assertNoJavaScriptErrors();
    $card = $page->script(CUSTOMERS_LIST_CARD_WIDTH);
    assert(is_int($card));
    expect($card)->toBeGreaterThanOrEqual(936)->toBeLessThan(944);
    Look::shot($page, 'customers-1252-el-phones');
});

it('keeps a phone of two numbers, one of forty characters, one of forty unbroken characters and a line of other names holding a long unbroken name inside each customer\'s block on a phone in Greek', function () {
    $el = SiteLanguage::Greek;
    DB::table('users')->where('email', Admin::owner('luna'))->update(['locale' => $el->value]);
    customersListPhoneCustomer('Two Numbers', 'two.numbers@example.test', CUSTOMERS_LIST_TWO_NUMBERS, 61);
    customersListPhoneCustomer('Forty Phone', 'forty.phone@example.test', CUSTOMERS_LIST_FORTY_PHONE, 62);
    customersListPhoneCustomer('Unbroken Phone', 'unbroken.phone@example.test', CUSTOMERS_LIST_UNBROKEN_PHONE, 65);
    // The earlier booking's name is the one the line of other names reads.
    customersWalkBooking('Chatzikonstantinoupolitissapapadopoulou', 'also@example.test', athensDaysOn(63));
    customersWalkBooking('Eleni Also', 'also@example.test', athensDaysOn(64));

    $page = Admin::signInOnPhone($this, 'luna', Admin::owner('luna'))->assertVisible(Admin::HOME);
    $page->navigate(Admin::url('luna', '/customers'))
        ->waitForText(adminLine('admin/customers.table.count', ['count' => '4', 'total' => '4'], $el))
        ->assertScript(PhoneScripts::PAGE_LANGUAGE, $el->value)
        ->assertScript(PhoneScripts::VIEWPORT_WIDTH, 390)
        ->assertSeeIn('.admin-customers .admin-customers__also', 'Chatzikonstantinoupolitissapapadopoulou')
        ->assertScript(CUSTOMERS_LIST_ROWS_READ, true)
        ->assertScript(CUSTOMERS_LIST_INSIDE_CARD, [])
        ->assertScript(CUSTOMERS_LIST_ALSO_INSIDE, true)
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true)
        ->assertNoJavaScriptErrors();
    Look::shot($page, 'customers-390-el-phones');
});

/**
 * What makes a row run tall on its shared visits, as words with the figures: a visit, its head and
 * its date, drawn on more than two lines, or a grid row more than one line taller than its tallest
 * other cell makes it. The visits apart are not read.
 */
const CUSTOMERS_LIST_VISITS_COMPACT = <<<'JS'
    document.fonts.ready.then(() => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(() => {
        const table = document.querySelector('.admin-customers table');
        const grid = table !== null && getComputedStyle(table).display === 'table';
        const lines = (element) => {
            const walker = document.createTreeWalker(element, NodeFilter.SHOW_TEXT);
            const found = [];
            while (walker.nextNode()) {
                const range = document.createRange();
                range.selectNodeContents(walker.currentNode);
                for (const box of range.getClientRects()) {
                    const middle = (box.top + box.bottom) / 2;
                    if (box.width > 0 && !found.some((line) => middle > line.top && middle < line.bottom)) {
                        found.push({ top: box.top, bottom: box.bottom });
                    }
                }
            }
            return found.length;
        };
        const natural = (cell) => {
            const range = document.createRange();
            range.selectNodeContents(cell);
            const style = getComputedStyle(cell);
            return range.getBoundingClientRect().height + parseFloat(style.paddingTop) + parseFloat(style.paddingBottom);
        };
        const wrong = new Set();
        document.querySelectorAll('.admin-customers__row').forEach((row) => {
            const cell = row.querySelector('.admin-customers__visits');
            if (getComputedStyle(cell).display === 'none') {
                return;
            }
            const visits = [...cell.firstElementChild.children];
            const drawn = visits.map(lines);
            if (drawn.some((count) => count > 2)) {
                wrong.add(`a visit on ${Math.max(...drawn)} lines`);
            }
            if (grid) {
                const line = parseFloat(getComputedStyle(visits[0].lastElementChild).lineHeight);
                const others = [...row.children].filter((other) => other !== cell && getComputedStyle(other).display !== 'none');
                const tallest = Math.max(...others.map(natural));
                const height = row.getBoundingClientRect().height;
                if (height > tallest + line + 0.5) {
                    wrong.add(`a row of ${Math.round(height)}px for a tallest other cell of ${Math.round(tallest)}px`);
                }
            }
        });
        resolve([...wrong]);
    }))))
    JS;

it('reads the list in Greek at every card width from 808px up to the first that draws the grid as rows that read or a grid that fits with two pixels to spare and shares the visits, each visit on two lines at most and no row taller for its visits, a sixty-character name and email, the long phones and a short address among the customers', function () {
    $el = SiteLanguage::Greek;
    DB::table('users')->where('email', Admin::owner('luna'))->update(['locale' => $el->value]);
    customersListLongCustomer(61);
    customersWalkBooking('Ada North', 'ada@example.test', athensDaysOn(-3));
    customersWalkBooking('Ada North', 'ada@example.test', athensDaysOn(63));
    customersListPhoneCustomer('Two Numbers', 'two.numbers@example.test', CUSTOMERS_LIST_TWO_NUMBERS, 63);
    customersListPhoneCustomer('Forty Phone', 'forty.phone@example.test', CUSTOMERS_LIST_FORTY_PHONE, 64);
    customersListPhoneCustomer('Unbroken Phone', 'unbroken.phone@example.test', CUSTOMERS_LIST_UNBROKEN_PHONE, 65);

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'))->resize(1132, 800);
    $page->navigate(Admin::url('luna', '/customers'))
        ->waitForText(adminLine('admin/customers.table.count', ['count' => '5', 'total' => '5'], $el))
        ->assertScript(PhoneScripts::PAGE_LANGUAGE, $el->value);
    $card = $page->script(CUSTOMERS_LIST_CARD_WIDTH);
    assert(is_int($card));
    // The card grows with the window one pixel for one, beside the open sidebar.
    $window = fn (int $width): int => 1132 + $width - $card;

    // Every pixel from 808 to 832, then every seventh up to the step into the grid, which draws
    // the visits apart.
    $wrong = [];
    foreach ([...range(808, 832), ...range(839, 935, 7), 935] as $width) {
        $page->resize($window($width), 800);
        $drawn = $page->script(CUSTOMERS_LIST_CARD_WIDTH);
        assert(is_int($drawn) || is_float($drawn));
        $grid = $page->script("getComputedStyle(document.querySelector('.admin-customers table')).display") === 'table';
        $faults = $grid
            ? [...customersFaults($page->script(CUSTOMERS_LIST_TABLE_FITS)), ...customersFaults($page->script(CUSTOMERS_LIST_INSIDE_CARD)), ...customersFaults($page->script(CUSTOMERS_LIST_FLOORS))]
            : ($page->script(CUSTOMERS_LIST_ROWS_READ) === true ? [] : ['the rows do not read']);
        if ($grid && $page->script(CUSTOMERS_LIST_VISITS_FORM) !== 'shared') {
            $faults[] = 'the visits not shared';
        }
        $faults = [...$faults, ...customersFaults($page->script(CUSTOMERS_LIST_VISITS_COMPACT))];
        if ($page->script(CUSTOMERS_LIST_LONG_EMAIL_INSIDE) !== true) {
            $faults[] = 'the long email past its cell';
        }
        if ($drawn !== $width) {
            $faults[] = "the card {$drawn}px";
        }
        if ($faults !== []) {
            $wrong[$width] = implode('; ', $faults);
        }
    }
    expect($wrong)->toBe([]);
    $page->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true)->assertNoJavaScriptErrors();
});
