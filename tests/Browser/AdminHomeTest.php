<?php

use App\Site\DemoSalon;
use App\Site\SiteLanguage;
use Database\Seeders\DemoTenantsSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Browser\Support\Admin;
use Tests\Browser\Support\Look;
use Tests\Browser\Support\PhoneScripts;
use Tests\Support\LunaFixture;

/*
 * Home in a real browser: luna's owner signs in and lands on Home at /admin, sees this week's
 * tiles and neither the setup wizard nor its banner, luna's setup being finished, and presses a
 * day of the next seven, landing on the Calendar's Day of
 * that date from the URL, the query then leaving the address so a reload opens on Day and today.
 * On the system clock, so the booking is dated relative to today: early today, inside this week
 * and the seven days whatever weekday the suite runs on.
 *
 * Then the feeds on the sample salon demo:reset builds: its owner sees the salon's one request
 * under Awaiting approval, opens it, approves it from the dialog, and the row leaves Awaiting
 * approval for the top of Recent activity, spelled as the owner's approval. The salon's owner has
 * no password anybody signs in with, so the walk gives it the demo one.
 *
 * Then the range card: Home opens on this week, This month puts the range in the address and
 * counts the month's bookings in the Bookings tile, and a reload keeps it.
 *
 * Then the bottom half: Recent activity lists five rows of seven, the request's row chipped, All
 * bookings opening the Bookings list; the seven days sum the week with its awaiting share, and
 * the day of the request opens the Calendar's Day on that date.
 *
 * Then To mark: yesterday's two ended bookings selected by their day's checkbox and marked
 * completed after one confirm, the day leaving the card and the day before it staying.
 *
 * Then the layout as the browser draws it, the first two in English and in Greek: at the desktop
 * width Awaiting approval and Next up share a row at five and seven twelfths and the days and the
 * two Top tables the next at five, four and three, each Top table inside its card; on a phone
 * every block in one column, the four ranges two by two with each label inside its button, and
 * nothing scrolling sideways; and the setup banner as a slim strip with its count and Continue, at
 * both widths, Continue taking the focus back when the wizard it opened closes.
 *
 * Then three things only the browser can say. The six tiles each take a line of their own below
 * the desktop width, Bookings standing beside New customers again only from the width at which
 * half a line holds the longest Greek comparison on three lines. The keyboard's outline is seen
 * whole on a row of Next up and of Recent activity, whose cards cut at their rounded edges, with
 * the house ring on All bookings and around a row's text in To mark. And the pointer's fill on
 * the last row of Next up is square, above the foot of a card stretched beside a taller Awaiting
 * approval and at the foot, where the card cuts it.
 */

/**
 * The two rows at the desktop width: whether Awaiting approval and Next up sit side by side on one
 * line, their widths in twelfths of the pair, then the same for the days and the two Top tables.
 */
const HOME_ROWS = <<<'JS'
    document.fonts.ready.then(() => {
        const boxes = (selectors) => selectors.map((selector) => document.querySelector(selector)?.getBoundingClientRect() ?? null);
        const row = (parts) => parts.every((part) => Math.abs(part.top - parts[0].top) <= 1) && parts.every((part, index) => index === 0 || part.left >= parts[index - 1].right - 1);
        const twelfths = (parts) => {
            const total = parts.reduce((sum, part) => sum + part.width, 0);
            return parts.map((part) => Math.round((part.width / total) * 12));
        };
        const first = boxes(['.admin-home-awaiting', '.admin-home-next']);
        const second = boxes(['.admin-home-days', '.admin-home-top--services', '.admin-home-top--staff']);
        if ([...first, ...second].includes(null)) {
            return null;
        }
        return [row(first), twelfths(first), row(second), twelfths(second)];
    })
    JS;

/** True when each Top table, read as a grid or as rows, is no wider than its card's box. */
const HOME_TOP_TABLES_INSIDE = <<<'JS'
    document.fonts.ready.then(() => {
        const boxes = [...document.querySelectorAll('.admin-home-top .admin-table-scroll')];
        return boxes.length === 2 && boxes.every((box) => box.scrollWidth <= box.clientWidth + 1);
    })
    JS;

/**
 * True when Home's blocks stand in one column on a phone: each starts at the same left edge, is
 * as wide as the first and starts below the one before it; and the six tiles inside the figures'
 * block do the same from the top one down, each as wide as the block.
 */
const HOME_ONE_COLUMN = <<<'JS'
    document.fonts.ready.then(() => {
        const selectors = ['.admin-home-awaiting', '.admin-home-next', '.admin-home-to-mark', '.admin-home-range', '.admin-home-figures', '.admin-home-days', '.admin-home-top--services', '.admin-home-top--staff', '.admin-home-activity'];
        const parts = selectors.map((selector) => document.querySelector(selector)?.getBoundingClientRect() ?? null);
        const tiles = [...document.querySelectorAll('.admin-home-figures > .admin-figure-tile')].map((tile) => tile.getBoundingClientRect()).sort((one, other) => one.top - other.top);
        if (parts.includes(null) || tiles.length !== 6) {
            return false;
        }
        const column = (boxes) => boxes.every((box, index) => Math.abs(box.left - boxes[0].left) <= 1 && Math.abs(box.width - boxes[0].width) <= 1 && (index === 0 || box.top >= boxes[index - 1].bottom - 1));
        return column(parts) && column(tiles) && Math.abs(tiles[0].width - parts[0].width) <= 1;
    })
    JS;

/**
 * The range group on a phone: how many lines its four buttons stand on, how many stand on each,
 * and whether every label fits inside its button.
 */
const HOME_RANGES = <<<'JS'
    document.fonts.ready.then(() => {
        const buttons = [...document.querySelectorAll('.admin-home-range button')];
        const lines = new Map();
        buttons.forEach((button) => {
            const top = Math.round(button.getBoundingClientRect().top);
            lines.set(top, (lines.get(top) ?? 0) + 1);
        });
        return [[...lines.values()], buttons.every((button) => button.scrollWidth <= button.clientWidth + 1)];
    })
    JS;

/**
 * The revenue figure once Home has settled: its text, and the animation and the transition its
 * style names. It reads the style alone: a figure counted up by script would have reached its
 * end by the time this is read.
 */
const HOME_REVENUE_STILL = <<<'JS'
    (() => {
        const value = document.querySelector('.admin-figure-tile--revenue .admin-figure-tile__value');
        if (value === null) {
            return null;
        }
        const style = getComputedStyle(value);
        return [value.textContent, style.animationName, style.transitionDuration];
    })()
    JS;

/**
 * The setup banner as a strip: whether its badge, its sentence and Continue sit inside the
 * banner's box, and whether they share one line.
 */
const HOME_BANNER = <<<'JS'
    document.fonts.ready.then(() => {
        const banner = document.querySelector('.admin-setup-banner')?.getBoundingClientRect();
        const parts = ['.admin-setup-banner__count', '.admin-setup-banner__progress', '.admin-setup-banner__continue'].map((selector) => document.querySelector(selector)?.getBoundingClientRect() ?? null);
        if (banner === undefined || parts.includes(null)) {
            return null;
        }
        const inside = parts.every((part) => part.left >= banner.left - 1 && part.right <= banner.right + 1);
        const oneLine = parts.every((part) => part.top < parts[0].bottom && part.bottom > parts[0].top);
        return [inside, oneLine];
    })
    JS;

/**
 * The figures as drawn: how many tiles stand on each line of their block, from the top down, and
 * whether no tile's comparison chip runs past three lines, read off the boxes of its words.
 */
const HOME_TILE_LINES = <<<'JS'
    document.fonts.ready.then(() => {
        const rows = new Map();
        [...document.querySelectorAll('.admin-home-figures > .admin-figure-tile')]
            .map((tile) => Math.round(tile.getBoundingClientRect().top))
            .sort((one, other) => one - other)
            .forEach((top) => rows.set(top, (rows.get(top) ?? 0) + 1));
        const range = document.createRange();
        const lines = (chip) => {
            const tops = [...chip.childNodes].filter((node) => node.nodeType === Node.TEXT_NODE).flatMap((node) => {
                range.selectNodeContents(node);
                return [...range.getClientRects()].map((box) => Math.round(box.top));
            });
            return new Set(tops).size;
        };
        const chips = [...document.querySelectorAll('.admin-home-figures .admin-figure-tile__change')];
        return [[...rows.values()], chips.length > 0 && chips.every((chip) => lines(chip) <= 3)];
    })
    JS;

/** How many columns Recent activity's rows stand in: the left edges they start at. */
const HOME_ACTIVITY_COLUMNS = <<<'JS'
    document.fonts.ready.then(() => new Set([...document.querySelectorAll('.admin-home-activity__row')].map((row) => Math.round(row.getBoundingClientRect().left))).size)
    JS;

/**
 * The last row of Next up with the pointer on it: whether the pointer is on its button, whether
 * the row stands above the foot of its card, the lower corners of the row and of its button, left
 * then right, and whether the card cuts what is drawn past its box.
 */
const HOME_NEXT_LAST_ROW_HOVERED = <<<'JS'
    (() => {
        const row = document.querySelector('.admin-home-next__row:last-child');
        const open = row?.querySelector('.admin-home-next__row-open');
        const card = row?.closest('section');
        if (row == null || open == null || card == null) {
            return 'no such row in a card';
        }
        const lower = (of) => [getComputedStyle(of).borderBottomLeftRadius, getComputedStyle(of).borderBottomRightRadius];
        const cut = getComputedStyle(card);
        return [
            open.matches(':hover'),
            card.getBoundingClientRect().bottom - row.getBoundingClientRect().bottom > 8,
            lower(row),
            lower(open),
            cut.overflowX === 'clip' && cut.overflowY === 'clip',
        ];
    })()
    JS;

/**
 * The keyboard's outline on the control the selector names, read with focus put on it after the
 * keyboard was last used: its style, its width, how far it stands off the control, whether it is
 * drawn in the theme colour, and whether its whole outer edge, rounded where the control is, lies
 * inside the card around it, rounded as the card is. The card cuts whatever is drawn past its
 * box, so an outline that is not inside it is not seen whole. The card is the nearest section
 * around the control unless it is named, as it is where a card holds sections of its own.
 */
function homeFocusOutline(string $selector, string $card = 'section'): string
{
    $selector = json_encode($selector, JSON_THROW_ON_ERROR);
    $card = json_encode($card, JSON_THROW_ON_ERROR);

    return <<<JS
        document.fonts.ready.then(() => {
            const control = document.querySelector({$selector});
            const card = control?.closest({$card});
            if (control == null || card == null) {
                return 'no such control in a card';
            }
            control.focus();
            if (!control.matches(':focus-visible')) {
                return 'the control does not show the keyboard focus';
            }
            const style = getComputedStyle(control);
            const ink = document.body.appendChild(document.createElement('span'));
            ink.style.color = 'var(--primary)';
            const primary = getComputedStyle(ink).color;
            ink.remove();
            const reach = parseFloat(style.outlineOffset) + parseFloat(style.outlineWidth);
            const box = control.getBoundingClientRect();
            const around = card.getBoundingClientRect();
            const rounding = getComputedStyle(card);
            const corners = [['TopLeft', -1, -1], ['TopRight', 1, -1], ['BottomRight', 1, 1], ['BottomLeft', -1, 1]];
            const radius = (of, corner) => parseFloat(of['border' + corner + 'Radius']);
            const at = (rect, grow, x, y) => [x < 0 ? rect.left - grow : rect.right + grow, y < 0 ? rect.top - grow : rect.bottom + grow];
            const held = ([px, py]) =>
                px >= around.left - 0.5 && px <= around.right + 0.5 && py >= around.top - 0.5 && py <= around.bottom + 0.5 &&
                corners.every(([corner, x, y]) => {
                    const round = radius(rounding, corner);
                    const [cx, cy] = at(around, -round, x, y);
                    return (px - cx) * x <= 0 || (py - cy) * y <= 0 || Math.hypot(px - cx, py - cy) <= round + 0.5;
                });
            const inside = corners.every(([corner, x, y]) => {
                const own = radius(style, corner);
                const round = own > 0 ? Math.max(0, own + reach) : 0;
                const [cx, cy] = at(box, reach - round, x, y);
                return [0, 15, 30, 45, 60, 75, 90].every((degrees) => held([cx + x * round * Math.cos((degrees * Math.PI) / 180), cy + y * round * Math.sin((degrees * Math.PI) / 180)]));
            });
            return [style.outlineStyle, style.outlineWidth, style.outlineOffset, style.outlineColor === primary, inside];
        })
        JS;
}

beforeEach(function () {
    dropDemoSchemas();
    $this->seed(DemoTenantsSeeder::class);
});

afterEach(function () {
    dropDemoSchemas();
    dropSiteDemo();
});

/**
 * A Haircut on Elena at the Athens start given, written straight to the rows, confirmed or a
 * request awaiting approval on a hold two days long. Answers its id.
 */
function homeWalkBooking(string $customer, DateTimeImmutable $start, string $status = 'confirmed'): int
{
    $utc = fn (DateTimeImmutable $at): string => $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:sP');
    $end = $start->add(new DateInterval('PT45M'));
    $id = DB::table('tenant_luna.bookings')->insertGetId([
        'source' => 'embed', 'uuid' => (string) Str::uuid(), 'status' => $status, 'customer_name' => $customer,
        'customer_email' => Str::slug($customer).'@example.test', 'total_minor' => 3500, 'currency' => 'EUR',
        'hold_class' => $status === 'awaiting_approval' ? 'approval' : null,
        'hold_expires_at' => $status === 'awaiting_approval' ? $utc(new DateTimeImmutable('+2 days', new DateTimeZone('UTC'))) : null,
        'payment_mode' => 'onsite', 'requires_approval' => $status === 'awaiting_approval', 'on_approval_timeout' => 'expire',
    ]);
    DB::table('tenant_luna.booking_items')->insert([
        'booking_id' => $id, 'sort' => 0,
        'service_id' => LunaFixture::id('services', 'Haircut'), 'staff_id' => LunaFixture::id('staff', 'Elena Papadaki'),
        'start_utc' => $utc($start), 'end_utc' => $utc($end), 'block_start_utc' => $utc($start), 'block_end_utc' => $utc($end),
        'price_minor' => 3500, 'blocks' => true,
    ]);

    return $id;
}

it('lands the owner on Home with the tiles and no setup, and opens the Calendar\'s Day on a day pressed', function () {
    $today = athensDaysOn(0, 0, 30);
    $date = $today->format('Y-m-d');
    homeWalkBooking('Cora Cut', $today);

    // Home drawn is the proof the page has settled: the wizard and its banner are drawn with it
    // where they show at all.
    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'))
        ->assertPathIs('/admin')
        ->assertVisible(Admin::HOME)
        ->assertSeeIn(Admin::HOME.' h1', adminLine('admin/home.title'))
        ->assertMissing('.admin-setup')
        ->assertMissing('.admin-setup-banner')
        ->assertSeeIn('.admin-figure-tile--bookings .admin-figure-tile__value', '1')
        ->assertSeeIn('.admin-figure-tile--cancelled .admin-figure-tile__value', '0')
        // The revenue figure is the server's amount, drawn with no CSS animation and no transition.
        ->assertScript(HOME_REVENUE_STILL, ["\u{20AC}35.00", 'none', '0s'])
        ->assertCount('.admin-home-day', 7)
        ->assertAttribute('.admin-home-day:first-child', 'data-date', $date)
        ->assertAttribute('.admin-home-day:first-child', 'data-confirmed', '1')
        ->assertMissing('.admin-home-reports');

    $page->click('.admin-home-day:first-child a')
        ->waitForText($today->format('d/m/Y'))
        ->assertSeeIn('.admin-calendar-view [aria-pressed="true"]', adminLine('admin/calendar.toolbar.views.day'))
        ->assertSeeIn('.admin-calendar-title', $today->format('d/m/Y'))
        ->assertAttribute('[aria-current="page"]', 'href', '/admin/calendar')
        ->assertUrlIs(Admin::url('luna', Admin::CALENDAR));

    // All staff, so the day shows Elena's booking whatever weekday the suite runs on.
    $page->click('#calendar-who')
        ->click('.admin-calendar-who__preset[data-who="all"]')
        ->waitForText(adminLine('admin/calendar.grid.block', ['customer' => 'Cora Cut', 'service' => 'Haircut']))
        ->assertNoJavaScriptErrors();

    // The link's query left the address once read, so a reload remembers nothing: Day and
    // today.
    $page->refresh()
        ->assertVisible('.admin-calendar')
        ->assertSeeIn('.admin-calendar-view [aria-pressed="true"]', adminLine('admin/calendar.toolbar.views.day'))
        ->assertSeeIn('.admin-calendar-title', $today->format('d/m/Y'))
        ->assertPathIs('/admin/calendar');
});

it('approves the sample salon\'s request from Home\'s Awaiting approval, the row moving to the top of Recent activity', function () {
    expect(Artisan::call('demo:reset'))->toBe(0);
    $demo = siteDemoSlug();
    // The sample salon is Greek; its owner chose English here, the language this walk reads.
    DB::table('users')->where('email', DemoSalon::OWNER_EMAIL)->update(['password' => Hash::make(Admin::DEMO_PASSWORD), 'locale' => 'en']);
    $customer = DB::table($demo->schemaName().'.bookings')->where('status', 'awaiting_approval')->sole()->customer_name;
    assert(is_string($customer));

    $page = Admin::signedIn($this, $demo->value, DemoSalon::OWNER_EMAIL)
        ->assertPathIs('/admin')
        ->assertCount('.admin-home-awaiting__row', 1)
        ->assertSeeIn('.admin-home-awaiting__customer', $customer)
        ->assertSeeIn('.admin-home-awaiting__count', adminChoice('admin/shell.kit.counted.awaiting_approval', 1))
        ->assertAttribute('.admin-home-awaiting__all', 'href', '/admin/bookings?status=awaiting_approval');

    $page->click('.admin-home-awaiting__row-open')
        ->assertVisible('.admin-booking-action--approve')
        ->assertSeeIn('.admin-modal__title', $customer)
        ->click('.admin-booking-action--approve')
        ->waitForText(adminLine('admin/home.awaiting.empty'))
        ->assertSeeIn('.admin-home-activity__row:first-child .admin-home-activity__sentence', adminLine('admin/home.activity.approved.user', ['staff' => DemoSalon::OWNER_NAME, 'customer' => $customer]))
        ->assertMissing('.admin-home-awaiting__count')
        ->assertNoJavaScriptErrors();
});

it('opens Home on this week, counts the month on This month with the range in the address, and keeps it on a reload', function () {
    // One booking today, inside this week and this month; one in this month a week or more away,
    // outside this week whatever weekday the suite runs on.
    $today = athensDaysOn(0, 0, 30);
    $day = (int) $today->format('j');
    homeWalkBooking('Cora Cut', $today);
    homeWalkBooking('Mona Month', $day > 7 ? athensDaysOn(1 - $day, 10) : athensDaysOn(7, 10));

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'))
        ->assertPathIs('/admin')
        ->assertSeeIn('.admin-home-range [aria-pressed="true"]', adminLine('admin/home.range.week'))
        ->assertSeeIn('.admin-figure-tile--bookings .admin-figure-tile__value', '1');

    $page->click('.admin-home-range button:nth-child(4)')
        ->waitForText(adminLine('admin/home.range.line.month', ['from' => $today->format('01/m/Y'), 'to' => $today->format('t/m/Y')]))
        ->assertQueryStringHas('range', 'month')
        ->assertSeeIn('.admin-home-range [aria-pressed="true"]', adminLine('admin/home.range.month'))
        ->assertSeeIn('.admin-figure-tile--bookings .admin-figure-tile__value', '2')
        ->assertNoJavaScriptErrors();

    $page->refresh()
        ->assertVisible(Admin::HOME)
        ->assertQueryStringHas('range', 'month')
        ->assertSeeIn('.admin-home-range [aria-pressed="true"]', adminLine('admin/home.range.month'))
        ->assertSeeIn('.admin-figure-tile--bookings .admin-figure-tile__value', '2');
});

it('lists five activity rows with All bookings, sums the seven days, and opens the Calendar on the day pressed', function () {
    DB::table('tenant_luna.audit_log')->delete();
    $soon = athensDaysOn(2, 11);
    $request = homeWalkBooking('Rhea Request', $soon, 'awaiting_approval');
    homeWalkBooking('Cora Cut', athensDaysOn(0, 23, 30));
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

    // Seven rows of the request's trail, the newest a minute ago: Home lists the newest five.
    for ($minute = 7; $minute >= 1; $minute--) {
        DB::table('tenant_luna.audit_log')->insert([
            'booking_id' => $request, 'actor' => 'customer', 'action' => 'held',
            'payload' => json_encode(['status_to' => 'awaiting_approval'], JSON_THROW_ON_ERROR),
            'created_at' => $now->sub(new DateInterval(sprintf('PT%dM', $minute)))->format('Y-m-d H:i:sP'),
        ]);
    }

    $date = $soon->format('Y-m-d');
    $day = '.admin-home-day[data-date="'.$date.'"]';

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'))
        ->assertPathIs('/admin')
        ->assertCount('.admin-home-activity__row', 5)
        ->assertSeeIn('.admin-home-activity__row:first-child .admin-home-activity__sentence', adminLine('admin/home.activity.held.request', ['customer' => 'Rhea Request']))
        ->assertVisible('.admin-home-activity__row:first-child .admin-chip--attention')
        ->assertAttribute('.admin-home-activity__all', 'href', '/admin/bookings')
        ->assertSeeIn('.admin-home-days__summary', adminChoice('admin/home.days.summary.one_awaiting', 2, ['awaiting' => '1']))
        ->assertAttribute($day, 'data-awaiting', '1')
        ->assertAttribute($day, 'data-confirmed', '0');

    $page->click($day.' a')
        ->waitForText($soon->format('d/m/Y'))
        ->assertSeeIn('.admin-calendar-view [aria-pressed="true"]', adminLine('admin/calendar.toolbar.views.day'))
        ->assertSeeIn('.admin-calendar-title', $soon->format('d/m/Y'))
        ->assertPathIs('/admin/calendar')
        ->assertNoJavaScriptErrors();

    $page->navigate(Admin::url('luna'))
        ->click('.admin-home-activity__all')
        ->assertPathIs('/admin/bookings')
        ->assertNoJavaScriptErrors();
});

it('marks a day in bulk from To mark: the day selected by its checkbox, marked completed after one confirm, its rows gone and the older day left', function () {
    $yesterday = athensDaysOn(-1, 10);
    $first = homeWalkBooking('Ada Ended', $yesterday);
    $second = homeWalkBooking('Bo Ended', athensDaysOn(-1, 11));
    $older = homeWalkBooking('Cy Older', athensDaysOn(-2, 10));
    $day = '.admin-home-to-mark__day[data-date="'.$yesterday->format('Y-m-d').'"]';
    $before = '.admin-home-to-mark__day[data-date="'.athensDaysOn(-2, 10)->format('Y-m-d').'"]';

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'))
        ->assertPathIs('/admin')
        ->assertSeeIn('#home-to-mark-title', adminLine('admin/home.to_mark.title'))
        ->assertSeeIn($day.' .admin-home-to-mark__heading', adminLine('admin/home.to_mark.day', ['weekday' => $yesterday->format('l'), 'date' => $yesterday->format('d/m/Y')]))
        ->assertCount($day.' .admin-home-to-mark__row', 2)
        ->assertSeeIn($before, 'Cy Older')
        ->assertMissing('.admin-home-to-mark__mark-completed');

    $page->click($day.' .admin-home-to-mark__day-tick')
        ->assertSeeIn('.admin-home-to-mark__selected', adminChoice('admin/home.to_mark.selected', 2))
        ->click('.admin-home-to-mark__mark-completed')
        ->assertSeeIn('.admin-modal__title', adminChoice('admin/home.to_mark.confirm.completed', 2))
        ->assertSeeIn('.admin-home-to-mark-confirm__undone', adminLine('admin/home.to_mark.confirm.undone'))
        ->assertSeeIn('.admin-home-to-mark-confirm__thanks', adminChoice('admin/home.to_mark.confirm.thanks', 2))
        ->click('.admin-home-to-mark-confirm__confirm')
        ->waitForText(adminChoice('admin/home.to_mark.flashes.completed', 2))
        ->assertMissing($day)
        ->assertMissing('.admin-home-to-mark-confirm')
        ->assertMissing('.admin-home-to-mark__selected')
        ->assertSeeIn($before, 'Cy Older')
        ->assertNoJavaScriptErrors();

    $statuses = DB::table('tenant_luna.bookings')->whereIn('id', [$first, $second, $older])->orderBy('id')->pluck('status')->all();

    expect($statuses)->toBe(['completed', 'completed', 'confirmed']);
});

it('lays Home out at the desktop width: Awaiting approval and Next up in one row at five and seven twelfths, the days and the two Top tables in the next at five, four and three, nothing scrolling sideways', function (SiteLanguage $language) {
    homeWalkBooking('Rhea Request', athensDaysOn(2, 11), 'awaiting_approval');
    homeWalkBooking('Cora Cut', athensDaysOn(0, 0, 30));
    DB::table('users')->where('email', Admin::owner('luna'))->update(['locale' => $language->value]);

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'))
        ->resize(1440, 900)
        ->assertVisible(Admin::HOME)
        ->assertVisible('.admin-home-awaiting__row')
        ->assertCount('.admin-home-top table', 2)
        ->assertScript(PhoneScripts::PAGE_LANGUAGE, $language->value)
        ->assertScript(HOME_ROWS, [true, [5, 7], true, [5, 4, 3]])
        ->assertScript(HOME_TOP_TABLES_INSIDE, true)
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true)
        ->assertNoJavaScriptErrors();
    Look::shot($page, 'home-1440-'.$language->value);
})->with([SiteLanguage::English, SiteLanguage::Greek]);

it('stacks Home in one column on a phone, the four ranges two by two with each label inside its button, nothing scrolling sideways', function (SiteLanguage $language) {
    // A request, a booking early today and one yesterday, so Awaiting approval draws its count,
    // the Top tables their rows and To mark both ended bookings. The language is the owner's
    // before the sign-in.
    homeWalkBooking('Rhea Request', athensDaysOn(2, 11), 'awaiting_approval');
    homeWalkBooking('Cora Cut', athensDaysOn(0, 0, 30));
    homeWalkBooking('Ada Ended', athensDaysOn(-1, 10));
    DB::table('users')->where('email', Admin::owner('luna'))->update(['locale' => $language->value]);

    $page = Admin::signInOnPhone($this, 'luna', Admin::owner('luna'))
        ->assertVisible(Admin::HOME)
        ->assertVisible('.admin-home-awaiting__count')
        ->assertCount('.admin-home-to-mark__row', 2)
        ->assertScript(PhoneScripts::PAGE_LANGUAGE, $language->value);
    Look::shot($page, 'home-390-'.$language->value);
    $page->assertScript(HOME_ONE_COLUMN, true)
        ->assertScript(HOME_RANGES, [[2, 2], true])
        ->assertScript(HOME_TOP_TABLES_INSIDE, true)
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true)
        ->assertNoJavaScriptErrors();
})->with([SiteLanguage::English, SiteLanguage::Greek]);

it('shows the keyboard\'s outline whole on a row of Next up and of Recent activity, inside the card that cuts at its rounded edge, the rows in two columns and in one, and the house ring whole on All bookings and around a row\'s text in To mark', function () {
    // Two bookings ahead for Next up, and a request whose trail gives Recent activity four rows,
    // each opening it: in two columns the third and the fourth stand at the card's foot.
    DB::table('tenant_luna.audit_log')->delete();
    homeWalkBooking('Cora Cut', athensDaysOn(1, 10));
    homeWalkBooking('Dora Dye', athensDaysOn(1, 12));
    $request = homeWalkBooking('Rhea Request', athensDaysOn(2, 11), 'awaiting_approval');
    $held = fn (int $minutes) => DB::table('tenant_luna.audit_log')->insert([
        'booking_id' => $request, 'actor' => 'customer', 'action' => 'held',
        'payload' => json_encode(['status_to' => 'awaiting_approval'], JSON_THROW_ON_ERROR),
        'created_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->sub(new DateInterval(sprintf('PT%dM', $minutes)))->format('Y-m-d H:i:sP'),
    ]);
    array_map($held, [5, 4, 3, 2]);

    // A booking that ended yesterday, for To mark: its text is a button in the room of its row,
    // inside a card that holds a section for each day, so the card is named.
    homeWalkBooking('Ada Ended', athensDaysOn(-1, 10));

    $next = fn (string $which): string => sprintf('.admin-home-next__row:%s-child .admin-home-next__row-open', $which);
    $activity = fn (int $nth): string => sprintf('.admin-home-activity__row:nth-child(%d) .admin-home-activity__row-open', $nth);
    $toMark = homeFocusOutline('.admin-home-to-mark__open', '.admin-home-to-mark');
    $inside = ['solid', '3px', '-3px', true, true];
    $ring = ['solid', '3px', '2px', true, true];

    // The keyboard is used once on each page, so focus put on a control shows the keyboard's
    // outline.
    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'))
        ->resize(1440, 900)
        ->assertVisible(Admin::HOME)
        ->assertCount('.admin-home-activity__row', 4)
        ->assertScript(HOME_ACTIVITY_COLUMNS, 2)
        ->keys($next('first'), 'Shift+Tab')
        ->assertScript(homeFocusOutline($next('first')), $inside)
        ->assertScript(homeFocusOutline($next('last')), $inside)
        ->assertScript(homeFocusOutline($activity(1)), $inside)
        ->assertScript(homeFocusOutline($activity(3)), $inside)
        ->assertScript(homeFocusOutline('.admin-home-activity__all'), $ring)
        ->assertScript($toMark, $ring)
        ->assertScript(homeFocusOutline($activity(4)), $inside);
    Look::shot($page, 'home-focus-1440');

    // A fifth row stands alone at the foot, in the left column.
    $held(1);
    $page->refresh()
        ->assertCount('.admin-home-activity__row', 5)
        ->keys($next('first'), 'Shift+Tab')
        ->assertScript(homeFocusOutline($activity(4)), $inside)
        ->assertScript(homeFocusOutline($activity(5)), $inside);

    // One column on a phone: the last row spans the foot and takes both of its corners.
    $page->resize(390, 844)
        ->assertScript(HOME_ACTIVITY_COLUMNS, 1)
        ->assertScript(homeFocusOutline($activity(5)), $inside)
        ->assertScript(homeFocusOutline($next('last')), $inside)
        ->assertScript($toMark, $ring)
        ->assertNoJavaScriptErrors();
});

it('fills the last row of Next up square under the pointer: above the foot of a card stretched beside a taller Awaiting approval, and at the foot on a phone, where the card cuts the fill', function () {
    // One booking ahead and one request: Next up lists two rows, and Awaiting approval, with its
    // count, its ticket and its link, stands taller, so the row they share stretches Next up.
    homeWalkBooking('Cora Cut', athensDaysOn(1, 10));
    homeWalkBooking('Rhea Request', athensDaysOn(2, 11), 'awaiting_approval');
    $last = '.admin-home-next__row:last-child .admin-home-next__row-open';
    $square = ['0px', '0px'];

    Admin::signedIn($this, 'luna', Admin::owner('luna'))
        ->resize(1440, 900)
        ->assertVisible(Admin::HOME)
        ->assertCount('.admin-home-next__row', 2)
        ->hover($last)
        ->assertScript(HOME_NEXT_LAST_ROW_HOVERED, [true, true, $square, $square, true])
        ->resize(390, 844)
        ->hover($last)
        ->assertScript(HOME_NEXT_LAST_ROW_HOVERED, [true, false, $square, $square, true])
        ->assertNoJavaScriptErrors();
});

it('gives every tile its own line below the desktop width, Bookings beside New customers again only from the width at which half a line holds the longest Greek comparison on three lines', function () {
    // A booking today and none the week before, so Bookings says a rise from nought against the
    // week before: the longest comparison Home has, and it carries an arrow.
    homeWalkBooking('Cora Cut', athensDaysOn(0, 0, 30));
    DB::table('users')->where('email', Admin::owner('luna'))->update(['locale' => SiteLanguage::Greek->value]);

    // The screen's box is the window less 32px here: one short of the pairing width, then on it.
    Admin::signedIn($this, 'luna', Admin::owner('luna'))
        ->resize(390, 844)
        ->assertVisible(Admin::HOME)
        ->assertSeeIn('.admin-figure-tile--bookings .admin-figure-tile__change', adminLine('admin/home.change.week.from_zero', [], SiteLanguage::Greek))
        ->assertScript(HOME_TILE_LINES, [[1, 1, 1, 1, 1, 1], true])
        ->resize(543, 844)
        ->assertScript(HOME_TILE_LINES, [[1, 1, 1, 1, 1, 1], true])
        ->resize(544, 844)
        ->assertScript(HOME_TILE_LINES, [[1, 2, 1, 1, 1], true])
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true)
        ->assertNoJavaScriptErrors();
});

it('draws the setup banner as a strip above the title with its count and Continue, on one line at the desktop width and inside the screen on a phone, Continue taking the focus back from the wizard it opens', function () {
    // luna's setup open again with three of its six steps done, the wizard already up once, so
    // the sign-in lands on Home with the banner alone.
    DB::table('tenant_luna.settings')->where('id', 1)->update([
        'setup_opened_at' => DB::raw('now()'), 'setup_finished_at' => null, 'setup_profile_saved_at' => DB::raw('now()'), 'hours_confirmed_at' => null,
        'link_copied_at' => null, 'setup_link_finished_at' => null, 'setup_team_skipped_at' => null, 'setup_payments_skipped_at' => null,
    ]);

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'))
        ->resize(1440, 900)
        ->assertVisible('.admin-setup-banner')
        ->assertMissing('.admin-setup')
        ->assertSeeIn('.admin-setup-banner__progress', adminLine('admin/setup.banner.progress', ['done' => '3', 'total' => '6']))
        ->assertSeeIn('.admin-setup-banner__continue', adminLine('admin/setup.banner.continue'))
        ->assertSeeIn('.admin-setup-banner__count', '3/6')
        ->assertScript(HOME_BANNER, [true, true])
        ->resize(390, 844)
        ->assertScript(HOME_BANNER.'.then((facts) => facts?.[0])', true)
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true);

    $page->click('.admin-setup-banner__continue')
        ->assertVisible('.admin-setup')
        ->click('.admin-setup__close')
        ->assertMissing('.admin-setup')
        ->assertScript(Admin::focusIsOn('.admin-setup-banner__continue'), true)
        ->assertNoJavaScriptErrors();
});
