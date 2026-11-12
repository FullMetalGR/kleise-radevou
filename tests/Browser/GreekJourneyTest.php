<?php

use App\Http\Controllers\MyBookingsController;
use App\Site\SiteLanguage;
use App\Tenancy\Slug;
use App\Tenancy\TenantUrls;
use Database\Seeders\DemoTenantsSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Browser\Support\Flow;
use Tests\Browser\Support\FlowScripts;

/*
 * The booking app in Greek in a real browser: a customer on a phone's width, 390 by 700 as
 * FlowPolishTest reads it, books on luna's hosted page under /el through every step to done,
 * opens the booking's manage page under /el and its reschedule dialog, and asks My bookings under
 * /el for a link, opens it and reads the list. On every screen the mount's text and labels hold
 * no English line of lang/en/booking.php but the shared ones, read from the files through the
 * translator on this side, and no :word unfilled; the root carries lang el.
 *
 * Greek runs longer than English, so at that width no slot's text overflows its box, on a
 * twelve-hour machine, whose day-period marks lengthen it, and on a twenty-four-hour one (the
 * bundle's hour cycle is the machine's), and the crumbs scroll inside their row rather than push
 * the page wider. The walk books on a Tuesday a week or two on, when Elena and Nikos both work
 * (DemoHoursSeeder), so it holds whatever weekday the gate runs on.
 *
 * The bundle is the one npm run build wrote, which is why the build gate precedes this one.
 */

beforeEach(function () {
    expect(is_file(public_path('build/embed.js')))->toBeTrue('public/build/embed.js is missing; run npm run build before the browser gate.');

    dropDemoSchemas();
    $this->seed(DemoTenantsSeeder::class);
    seamTransport()->flush();
});

afterEach(fn () => dropDemoSchemas());

/**
 * The text luna's owner and the customer typed, which the app shows as typed in both
 * languages: the services' names, descriptions and categories, the members' names, titles and
 * bios, the About and Important info, and the customer's name, longest first.
 *
 * @return list<string>
 */
function greekWalkTenantText(): array
{
    $typed = [MYB_CASEY, 'Casey Customer'];

    foreach (['services' => ['name', 'description', 'category'], 'staff' => ['name', 'title', 'bio'], 'settings' => ['about', 'important_info']] as $table => $columns) {
        foreach (DB::table('tenant_luna.'.$table)->get($columns) as $row) {
            foreach ($columns as $column) {
                $value = $row->{$column} ?? null;

                if (is_string($value) && trim($value) !== '') {
                    $typed[] = trim($value);
                }
            }
        }
    }

    $typed = array_values(array_unique($typed));
    usort($typed, fn (string $a, string $b): int => strlen($b) <=> strlen($a));

    return $typed;
}

/**
 * The script that answers what the booking mount leaves in English or unfilled, joined by bars:
 * the key of each English line of booking.php whose Greek is its own (a shared value is left
 * out), each choice form matched apart with its :words free, found as the whole text of an
 * element or a label, or, for a form of three words or more, anywhere in the mount's text, the
 * tenant's and the customer's own text (greekWalkTenantText) taken out first, so a service's
 * English description is not read as a line of the app's; and each :word left. The switch's
 * label, marked lang="en", is left out. Empty for a mount wholly in Greek.
 */
function greekWalkScript(): string
{
    $english = greekWalkLines((array) trans('booking', [], 'en'));
    $greek = greekWalkLines((array) trans('booking', [], 'el'));
    $patterns = [];

    foreach ($english as $key => $line) {
        if (($greek[$key] ?? null) === $line) {
            continue;
        }

        foreach (explode('|', $line) as $form) {
            $fixed = preg_split('/:[A-Za-z][A-Za-z0-9_]*/', trim((string) preg_replace('/^\s*[{\[][^}\]]*[}\]]/', '', $form))) ?: [];
            $words = str_word_count(implode(' ', $fixed));
            $patterns[] = [$key, implode('.+?', array_map(fn (string $part): string => preg_quote($part, '/'), $fixed)), $words >= 3];
        }
    }

    $json = json_encode($patterns, JSON_THROW_ON_ERROR);
    $tenant = json_encode(greekWalkTenantText(), JSON_THROW_ON_ERROR);

    return <<<JS
        (() => {
            const root = document.querySelector('.kr-booking').cloneNode(true);
            root.querySelectorAll('[lang="en"]').forEach((node) => node.remove());
            const nodes = [root, ...root.querySelectorAll('*')];
            const labels = nodes.flatMap((node) => ['aria-label', 'title', 'placeholder', 'alt'].map((name) => node.getAttribute(name) || '')).filter((text) => text !== '');
            const own = (text) => {$tenant}.reduce((rest, typed) => rest.split(typed).join(' '), text);
            const lines = [...nodes.map((node) => own(node.textContent || '').trim()), ...labels];
            const left = new Set();
            for (const [key, source, loose] of {$json}) {
                const exact = new RegExp('^' + source + '$');
                const within = new RegExp(source);
                if (lines.some((text) => exact.test(text)) || (loose && [own(root.textContent || ''), ...labels].some((text) => within.test(text)))) {
                    left.add(key);
                }
            }
            for (const text of [own(root.textContent || ''), ...labels]) {
                for (const match of text.matchAll(/(?<![A-Za-z0-9_:\\/]):[A-Za-z][A-Za-z0-9_]*/g)) {
                    left.add(match[0]);
                }
            }
            return [...left].sort().join('|');
        })()
        JS;
}

/** The mount's language and the page's. */
const GREEK_WALK_LANGS = "document.documentElement.getAttribute('lang') + '|' + document.querySelector('.kr-booking').getAttribute('lang')";

/** Whether every slot's text fits its box, at least one slot showing. */
const GREEK_WALK_SLOTS_FIT = "(() => { const slots = Array.from(document.querySelectorAll('.kr-booking__slot')); return slots.length > 0 && slots.every((slot) => slot.scrollWidth <= slot.clientWidth); })()";

/** Whether the crumbs scroll inside their row and the page is no wider than the screen. */
const GREEK_WALK_CRUMBS_SCROLL = "(() => { const crumbs = document.querySelector('.kr-booking__crumbs'); return crumbs !== null && getComputedStyle(crumbs).overflowX === 'auto' && getComputedStyle(crumbs).flexWrap === 'nowrap' && crumbs.getBoundingClientRect().right <= window.innerWidth + 1 && document.documentElement.scrollWidth <= window.innerWidth; })()";

/**
 * Keeps the manage token the hold answers with, off the page's own fetch, as ManageJourneyTest
 * does: the response is cloned, so the app reads its body exactly as it would have.
 */
const GREEK_WALK_RECORDER = <<<'JS'
    (() => {
        const original = window.fetch;
        window.__krGreekToken = null;
        window.fetch = (input, init) => original(input, init).then((response) => {
            if (String(input).includes('/api/v1/holds')) {
                response.clone().json().then((body) => {
                    window.__krGreekToken = typeof body.manage_token === 'string' ? body.manage_token : null;
                }, () => undefined);
            }

            return response;
        });

        return true;
    })()
    JS;

it('books under /el at a phone\'s width on a {0} machine, then reads the manage page and My bookings, with no English line of booking.php left and no slot overflowing', function (string $clock, string $locale) {
    $tuesday = Flow::day('Europe/Athens', 2);
    $urls = app(TenantUrls::class);
    $walk = greekWalkScript();

    $page = $this->visit($urls->hostedPage(Slug::of('luna'), SiteLanguage::Greek))->withLocale($locale)
        ->resize(390, 700)
        ->assertScript(FlowScripts::SETTLED, true)
        ->assertScript(GREEK_WALK_LANGS, 'el|el')
        ->assertScript($walk, '')
        ->assertScript(GREEK_WALK_RECORDER, true)
        ->click('Haircut')
        ->assertScript($walk, '')
        ->click('.kr-booking__continue')
        ->assertScript(FlowScripts::STEP, 'staff')
        ->assertScript($walk, '')
        ->assertScript(GREEK_WALK_CRUMBS_SCROLL, true)
        ->click('.kr-booking__continue')
        ->assertScript(FlowScripts::STEP, 'when');

    Flow::weekOf($page, $tuesday)
        ->click(sprintf('.kr-booking__day[value="%s"]', $tuesday->format('Y-m-d')))
        ->assertScript(FlowScripts::SETTLED, true)
        ->assertScript($walk, '')
        ->assertScript(GREEK_WALK_SLOTS_FIT, true)
        ->assertScript(GREEK_WALK_CRUMBS_SCROLL, true)
        ->click(sprintf('.kr-booking__slot[value="%s"]', Flow::start($tuesday, 10)))
        ->assertScript(FlowScripts::STEP, 'details')
        ->type('customer_name', 'Casey Customer')
        ->type('customer_email', MYB_CASEY)
        ->assertScript($walk, '')
        ->assertScript(GREEK_WALK_CRUMBS_SCROLL, true)
        ->click('.kr-booking__continue')
        ->assertScript(FlowScripts::stepReached('review'), true)
        ->assertScript($walk, '')
        ->click('.kr-booking__confirm')
        ->assertScript(FlowScripts::stepReached('done'), true)
        ->assertSee(__('booking.done.sentences.confirmed', [], 'el'))
        ->assertScript($walk, '')
        ->assertNoJavaScriptErrors();

    $token = $page->script('window.__krGreekToken');
    $uuid = DB::table('tenant_luna.bookings')->value('uuid');

    expect($token)->toBeString()->toHaveLength(64)
        ->and(DB::table('tenant_luna.bookings')->value('language'))->toBe('el');
    assert(is_string($token) && is_string($uuid));

    $page->navigate($urls->manageLink(Slug::of('luna'), $uuid, $token, SiteLanguage::Greek))
        ->assertScript(FlowScripts::VIEW, 'ready')
        ->assertScript(GREEK_WALK_LANGS, 'el|el')
        ->assertScript($walk, '')
        ->click('.kr-booking__reschedule')
        ->assertScript(FlowScripts::DIALOG_OPEN, true)
        ->assertScript(FlowScripts::SETTLED, true)
        ->assertScript($walk, '')
        ->assertNoJavaScriptErrors();

    // The booking's own messages to casey go, so the one left is the bookings link's.
    seamTransport()->flush();

    $page->navigate($urls->myBookings(Slug::of('luna'), SiteLanguage::Greek))
        ->waitForText(__('booking.my_bookings.send', [], 'el'))
        ->assertScript(FlowScripts::VIEW, 'form')
        ->assertScript(GREEK_WALK_LANGS, 'el|el')
        ->assertScript($walk, '')
        ->fill('email', MYB_CASEY)
        ->click(__('booking.my_bookings.send', [], 'el'))
        ->waitForText(pageSentence(MyBookingsController::SENT, [], SiteLanguage::Greek))
        ->assertScript($walk, '')
        ->navigate($urls->myBookingsLink(Slug::of('luna'), mybTokenIn((string) seamAt(MYB_CASEY)->getTextBody()), SiteLanguage::Greek))
        ->waitForText(pageSentence(MyBookingsController::OPEN_LABEL, [], SiteLanguage::Greek))
        ->click(pageSentence(MyBookingsController::OPEN_LABEL, [], SiteLanguage::Greek))
        ->waitForText(__('booking.my_bookings.upcoming', [], 'el'))
        ->assertScript(FlowScripts::VIEW, 'list')
        ->assertScript(GREEK_WALK_LANGS, 'el|el')
        ->assertScript($walk, '')
        ->assertNoJavaScriptErrors();
})->with([
    'twelve-hour' => ['twelve-hour', 'en-US'],
    'twenty-four-hour' => ['twenty-four-hour', 'en-GB'],
]);
