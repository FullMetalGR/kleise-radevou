<?php

use App\Http\Requests\CustomerProfileRequest;
use App\Site\SiteLanguage;
use Database\Seeders\DemoTenantsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Browser\Support\Admin;
use Tests\Browser\Support\ContrastScripts;
use Tests\Browser\Support\Look;
use Tests\Browser\Support\PhoneScripts;

/*
 * A customer's page laid out in a real browser, in English and in Greek, for three customers: one
 * with bookings in every outcome, tags, notes and other names, the most recent of them sixty
 * characters long, and a tag as long as a profile takes, in its widest letter; one whose online
 * booking is blocked; and one with a single booking and nothing else. The name is the page's one
 * heading and the hero card under it never repeats it. At 1440 and 1920 pixels the cards stand on
 * two columns, the side one 400 pixels wide at the right of the main one, and At a glance draws
 * its four counts on one line. Narrower, the main column holds the four on one line in Greek only
 * from one window width, pinned with the pixel under it, and two by two below it. On a phone every
 * card stands on one column, the side's under the main's, the four counts two to a line, the
 * hero's chips wrap inside it, and the page is never wider than the screen. The hero's words and
 * marks hold their contrast on its fill in every theme and mode.
 *
 * The bookings are written straight to luna's rows, every start whole days from today, because
 * the browser and the application both read the real clock here.
 */

beforeEach(function () {
    dropDemoSchemas();
    $this->seed(DemoTenantsSeeder::class);
});

afterEach(fn () => dropDemoSchemas());

/**
 * How many top-level headings the page holds, counted by the page itself: the plugin reads a bare
 * tag name as text to find.
 */
const CUSTOMER_PAGE_HEADINGS = "document.querySelectorAll('h1').length";

/** The most recent name of the customer with everything, sixty characters long. */
const CUSTOMER_PAGE_LONG_NAME = 'Alexandria-Evangelia Konstantinidou Papadopoulou Theodorakis';

/**
 * A tag as long as a profile takes, every letter the widest capital, with no space to break at.
 */
const CUSTOMER_PAGE_LONG_TAG = 'WWWWWWWWWWWWWWWWWWWWWWWW';

/**
 * The narrowest window at which At a glance draws its four counts on one line in Greek, for the
 * owner with the sidebar open: the main column's card is then 626 pixels inside, room for the four
 * counts with two pixels spare beside Greek's longest label in each.
 */
const CUSTOMER_PAGE_FOUR_FROM = 1412;

/**
 * Where the side column stands against the main one: "beside" at its right with their tops
 * level, "under" below it at the same left and width, or "other".
 */
const CUSTOMER_PAGE_COLUMNS = "document.fonts.ready.then(() => { const main = document.querySelector('.admin-customer-main')?.getBoundingClientRect(); const side = document.querySelector('.admin-customer-side')?.getBoundingClientRect(); if (main === undefined || side === undefined) { return 'missing'; } if (side.left >= main.right && Math.abs(side.top - main.top) <= 1) { return 'beside'; } if (side.top >= main.bottom && Math.abs(side.left - main.left) <= 1 && Math.abs(side.width - main.width) <= 1) { return 'under'; } return 'other'; })";

/** The side column's width in whole pixels. */
const CUSTOMER_PAGE_SIDE_WIDTH = "document.fonts.ready.then(() => Math.round(document.querySelector('.admin-customer-side').getBoundingClientRect().width))";

/** How many of At a glance's four counts stand on each line, top to bottom. */
const CUSTOMER_PAGE_COUNTS_PER_LINE = "document.fonts.ready.then(() => { const lines = []; for (const count of document.querySelectorAll('.admin-customer-glance__counts > *')) { const top = Math.round(count.getBoundingClientRect().top); const line = lines.find((each) => Math.abs(each.top - top) <= 1); if (line === undefined) { lines.push({ top, counts: 1 }); } else { line.counts += 1; } } return lines.map((line) => line.counts); })";

/** Where the last visit's panel stands against the counts: "beside" them, "under" them, or "other". */
const CUSTOMER_PAGE_LAST_VISIT = "document.fonts.ready.then(() => { const counts = document.querySelector('.admin-customer-glance__counts')?.getBoundingClientRect(); const last = document.querySelector('.admin-customer-glance__figure--last-visit')?.getBoundingClientRect(); if (counts === undefined || last === undefined) { return 'missing'; } if (last.left >= counts.right && Math.abs(last.top - counts.top) <= 1) { return 'beside'; } if (last.top >= counts.bottom) { return 'under'; } return 'other'; })";

/** Whether every count's label stays inside its count, inside the padding, its words whole. */
const CUSTOMER_PAGE_LABELS_INSIDE = "document.fonts.ready.then(() => [...document.querySelectorAll('.admin-customer-glance__counts > *')].every((count) => { const label = count.querySelector('span'); const edge = count.getBoundingClientRect(); const pad = parseFloat(getComputedStyle(count).paddingRight); const own = label.getBoundingClientRect(); return label.scrollWidth <= label.clientWidth + 1 && own.right <= edge.right - pad + 1; }))";

/** Whether everything the hero card holds stays inside its edges, nothing scrolling inside it. */
const CUSTOMER_PAGE_HERO_INSIDE = "document.fonts.ready.then(() => { const hero = document.querySelector('.admin-customer-hero'); if (hero === null) { return false; } const edge = hero.getBoundingClientRect(); return hero.scrollWidth <= hero.clientWidth + 1 && [...hero.querySelectorAll('*')].every((part) => { const own = part.getBoundingClientRect(); return own.width === 0 || (own.left >= edge.left - 1 && own.right <= edge.right + 1); }); })";

/** How the Bookings table draws its rows: "table" as a grid, "block" as a block per booking. */
const CUSTOMER_PAGE_TABLE_DISPLAY = "getComputedStyle(document.querySelector('.admin-bookings table')).display";

/**
 * The colours the hero card and the cards around it set side by side: the address on the
 * accent fill, the chips on the card's surface, the initials on the strong tint, the last visit's
 * panel on the muted fill and the green line of the Online booking card.
 *
 * @var list<array{string, string, float}>
 */
const CUSTOMER_PAGE_PAIRS = [
    ['--muted-foreground', '--accent', 4.5],
    ['--foreground', '--card', 4.5],
    ['--muted-foreground', '--card', 4.5],
    ['--primary-deep', '--tint-strong', 4.5],
    ['--foreground', '--muted', 4.5],
    ['--muted-foreground', '--muted', 4.5],
    ['--positive-ink', '--positive-soft', 4.5],
];

/** A Haircut on Elena's row under the address and name given, in the status given. */
function customerPageBooking(string $name, string $email, DateTimeImmutable $start, string $status): void
{
    $utc = fn (DateTimeImmutable $at): string => $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:sP');
    $end = $start->add(new DateInterval('PT45M'));
    $id = DB::table('tenant_luna.bookings')->insertGetId([
        'source' => 'embed',
        'uuid' => (string) Str::uuid(), 'status' => $status, 'customer_name' => $name, 'customer_email' => $email,
        'customer_phone' => '+30 210 7654321', 'total_minor' => 3500, 'currency' => 'EUR',
        'payment_mode' => 'onsite', 'requires_approval' => false, 'on_approval_timeout' => 'expire',
    ]);
    DB::table('tenant_luna.booking_items')->insert([
        'booking_id' => $id, 'sort' => 0,
        'service_id' => DB::table('tenant_luna.services')->where('name', 'Haircut')->value('id'),
        'staff_id' => DB::table('tenant_luna.staff')->where('name', 'Elena Papadaki')->value('id'),
        'start_utc' => $utc($start), 'end_utc' => $utc($end),
        'block_start_utc' => $utc($start), 'block_end_utc' => $utc($end),
        'price_minor' => 3500, 'blocks' => false,
    ]);
}

/**
 * The three customers the walk reads, answering each one's page under the prefix: "full" with
 * bookings in every outcome, tags, the longest of them as long as a profile takes, two notes and
 * three other names; "blocked" with one visit and its online booking blocked; "bare" with one
 * booking ahead and nothing else.
 *
 * @return array{full: string, blocked: string, bare: string}
 */
function customerPageSeed(): array
{
    $full = 'alexandria@example.test';
    customerPageBooking('Mara K.', $full, athensDaysOn(-40, 10), 'no_show');
    customerPageBooking('Maria Konstantinidou-Papadopoulou', $full, athensDaysOn(-30, 10), 'cancelled');
    customerPageBooking('Marianna Theodoropoulou-Alexandrou', $full, athensDaysOn(-20, 10), 'completed');
    customerPageBooking('Maria Konstantinidou-Papadopoulou', $full, athensDaysOn(-10, 10), 'completed');
    customerPageBooking(CUSTOMER_PAGE_LONG_NAME, $full, athensDaysOn(12, 11), 'confirmed');

    $owner = DB::table('users')->where('email', Admin::owner('luna'))->value('id');
    assert(is_int($owner));
    DB::table('tenant_luna.customer_profiles')->insert([
        'email' => $full, 'tags' => '{VIP,colour,regular,'.CUSTOMER_PAGE_LONG_TAG.'}', 'language' => 'el',
        'created_at' => DB::raw('now()'), 'updated_at' => DB::raw('now()'),
    ]);
    foreach (['Prefers Elena and a morning chair by the window.', 'Allergic to ammonia colour; patch test before any new dye.'] as $body) {
        DB::table('tenant_luna.customer_notes')->insert([
            'email' => $full, 'body' => $body, 'author_actor' => 'user:'.$owner, 'author_name' => 'Luna Owner',
            'created_at' => DB::raw('now()'),
        ]);
    }

    $blocked = 'bea@example.test';
    customerPageBooking('Bea Blocked', $blocked, athensDaysOn(-15, 9), 'completed');
    DB::table('tenant_luna.customer_profiles')->insert([
        'email' => $blocked, 'blocked_at' => DB::raw('now()'), 'blocked_reason' => 'Three no-shows in a row.',
        'created_at' => DB::raw('now()'), 'updated_at' => DB::raw('now()'),
    ]);

    $bare = 'nia@example.test';
    customerPageBooking('Nia Nothing', $bare, athensDaysOn(9, 12), 'confirmed');

    $page = fn (string $email): string => Admin::url('luna', '/customers/'.rawurlencode($email));

    return ['full' => $page($full), 'blocked' => $page($blocked), 'bare' => $page($bare)];
}

it('stands the cards on two columns at 1440 and 1920 pixels, the side one 400 pixels wide at the right of the main one, the four counts on one line, the name the page\'s one heading and never in the hero, for a customer with everything, a blocked one and one with nothing', function (SiteLanguage $language) {
    expect(strlen(CUSTOMER_PAGE_LONG_NAME))->toBe(60);
    $pages = customerPageSeed();
    DB::table('users')->where('email', Admin::owner('luna'))->update(['locale' => $language->value]);

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'))->resize(1440, 900);
    $page->navigate($pages['full'])
        ->assertVisible('.admin-customer-hero')
        ->assertScript(PhoneScripts::PAGE_LANGUAGE, $language->value)
        ->assertScript(CUSTOMER_PAGE_HEADINGS, 1)
        ->assertSeeIn('.admin-page-header h1', CUSTOMER_PAGE_LONG_NAME)
        ->assertDontSeeIn('.admin-customer-hero', CUSTOMER_PAGE_LONG_NAME)
        ->assertSeeIn('.admin-customer-hero .admin-customer-head__chips', 'regular')
        ->assertCount('.admin-customer-note', 2)
        ->assertScript(CUSTOMER_PAGE_COLUMNS, 'beside')
        ->assertScript(CUSTOMER_PAGE_SIDE_WIDTH, 400)
        ->assertScript(CUSTOMER_PAGE_COUNTS_PER_LINE, [4])
        ->assertScript(CUSTOMER_PAGE_LABELS_INSIDE, true)
        ->assertScript(CUSTOMER_PAGE_LAST_VISIT, 'under')
        ->assertScript(CUSTOMER_PAGE_HERO_INSIDE, true)
        // The main column is narrower than the table's grid here, so each booking is a block.
        ->assertScript(CUSTOMER_PAGE_TABLE_DISPLAY, 'block')
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true);
    Look::shot($page, 'customer-1440-'.$language->value);

    if ($language === SiteLanguage::English) {
        expect($page->script(ContrastScripts::failures(CUSTOMER_PAGE_PAIRS)))->toBe([]);
    }

    $page->resize(1920, 1000)
        ->assertScript(CUSTOMER_PAGE_COLUMNS, 'beside')
        ->assertScript(CUSTOMER_PAGE_SIDE_WIDTH, 400)
        ->assertScript(CUSTOMER_PAGE_COUNTS_PER_LINE, [4])
        ->assertScript(CUSTOMER_PAGE_LABELS_INSIDE, true)
        ->assertScript(CUSTOMER_PAGE_LAST_VISIT, 'beside')
        ->assertScript(CUSTOMER_PAGE_TABLE_DISPLAY, 'table')
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true);
    Look::shot($page, 'customer-1920-'.$language->value);

    $page->resize(1440, 900)
        ->navigate($pages['blocked'])
        ->assertVisible('.admin-customer-block__unblock')
        ->assertSeeIn('.admin-customer-block__reason', 'Three no-shows in a row.')
        ->assertDontSeeIn('.admin-customer-hero', 'Bea Blocked')
        ->assertScript(CUSTOMER_PAGE_COLUMNS, 'beside')
        ->assertScript(CUSTOMER_PAGE_COUNTS_PER_LINE, [4])
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true);
    Look::shot($page, 'customer-1440-blocked-'.$language->value);

    $page->navigate($pages['bare'])
        ->assertVisible('.admin-customer-hero')
        ->assertCount('.admin-customer-head__chips', 0)
        ->assertCount('.admin-customer-note', 0)
        ->assertSeeIn('.admin-customer-hero .admin-customer-head__email', 'nia@example.test')
        ->assertScript(CUSTOMER_PAGE_COLUMNS, 'beside')
        ->assertScript(CUSTOMER_PAGE_COUNTS_PER_LINE, [4])
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true)
        ->assertNoJavaScriptErrors();
    Look::shot($page, 'customer-1440-bare-'.$language->value);
})->with([SiteLanguage::English, SiteLanguage::Greek]);

it('draws At a glance\'s four counts on one line in Greek only from the window where the main column holds them with every label inside, two by two at the pixel under it and at 1280 pixels', function () {
    $pages = customerPageSeed();
    DB::table('users')->where('email', Admin::owner('luna'))->update(['locale' => SiteLanguage::Greek->value]);

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'))->resize(1280, 900);
    $page->navigate($pages['full'])
        ->assertVisible('.admin-customer-hero')
        ->assertScript(PhoneScripts::PAGE_LANGUAGE, SiteLanguage::Greek->value)
        ->assertScript(CUSTOMER_PAGE_COLUMNS, 'beside')
        ->assertScript(CUSTOMER_PAGE_COUNTS_PER_LINE, [2, 2])
        ->assertScript(CUSTOMER_PAGE_LABELS_INSIDE, true)
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true);
    Look::shot($page, 'customer-1280-el');

    $page->resize(CUSTOMER_PAGE_FOUR_FROM, 900)
        ->assertScript(CUSTOMER_PAGE_COLUMNS, 'beside')
        ->assertScript(CUSTOMER_PAGE_COUNTS_PER_LINE, [4])
        ->assertScript(CUSTOMER_PAGE_LABELS_INSIDE, true);
    Look::shot($page, 'customer-'.CUSTOMER_PAGE_FOUR_FROM.'-el');

    $page->resize(CUSTOMER_PAGE_FOUR_FROM - 1, 900)
        ->assertScript(CUSTOMER_PAGE_COUNTS_PER_LINE, [2, 2])
        ->assertScript(CUSTOMER_PAGE_LABELS_INSIDE, true)
        ->assertNoJavaScriptErrors();
});

it('stands every card on one column on a phone, the side\'s under the main\'s, the four counts two to a line and the hero\'s chips inside it, the longest tag a profile takes included, the page no wider than the screen, for a customer with everything, a blocked one and one with nothing', function (SiteLanguage $language) {
    expect(strlen(CUSTOMER_PAGE_LONG_TAG))->toBe(CustomerProfileRequest::TAG_LENGTH);
    $pages = customerPageSeed();
    DB::table('users')->where('email', Admin::owner('luna'))->update(['locale' => $language->value]);

    $page = Admin::signInOnPhone($this, 'luna', Admin::owner('luna'))->assertVisible(Admin::HOME);
    $page->navigate($pages['full'])
        ->assertVisible('.admin-customer-hero')
        ->assertScript(PhoneScripts::PAGE_LANGUAGE, $language->value)
        ->assertScript(PhoneScripts::VIEWPORT_WIDTH, 390)
        ->assertScript(CUSTOMER_PAGE_HEADINGS, 1)
        ->assertDontSeeIn('.admin-customer-hero', CUSTOMER_PAGE_LONG_NAME)
        ->assertSeeIn('.admin-customer-hero .admin-customer-head__chips', CUSTOMER_PAGE_LONG_TAG)
        ->assertScript(CUSTOMER_PAGE_COLUMNS, 'under')
        ->assertScript(CUSTOMER_PAGE_COUNTS_PER_LINE, [2, 2])
        ->assertScript(CUSTOMER_PAGE_LABELS_INSIDE, true)
        ->assertScript(CUSTOMER_PAGE_LAST_VISIT, 'under')
        ->assertScript(CUSTOMER_PAGE_HERO_INSIDE, true)
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true);
    Look::shot($page, 'customer-390-'.$language->value);

    $page->navigate($pages['blocked'])
        ->assertVisible('.admin-customer-block__unblock')
        ->assertScript(CUSTOMER_PAGE_COLUMNS, 'under')
        ->assertScript(CUSTOMER_PAGE_COUNTS_PER_LINE, [2, 2])
        ->assertScript(CUSTOMER_PAGE_HERO_INSIDE, true)
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true);
    Look::shot($page, 'customer-390-blocked-'.$language->value);

    $page->navigate($pages['bare'])
        ->assertVisible('.admin-customer-hero')
        ->assertCount('.admin-customer-head__chips', 0)
        ->assertScript(CUSTOMER_PAGE_COLUMNS, 'under')
        ->assertScript(CUSTOMER_PAGE_COUNTS_PER_LINE, [2, 2])
        ->assertScript(CUSTOMER_PAGE_HERO_INSIDE, true)
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true)
        ->assertNoJavaScriptErrors();
    Look::shot($page, 'customer-390-bare-'.$language->value);
})->with([SiteLanguage::English, SiteLanguage::Greek]);
