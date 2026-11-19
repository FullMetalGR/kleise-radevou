<?php

use App\Site\SiteLanguage;
use Database\Seeders\DemoTenantsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Browser\Support\Admin;
use Tests\Browser\Support\Look;
use Tests\Browser\Support\PhoneScripts;
use Tests\Support\LunaFixture;

/*
 * Every screen the rail reaches, at a tablet's width and a desktop's, in English and in Greek:
 * each one drawn under the one shared page header holding its one main heading, in the owner's
 * language and free of script errors, and no wider than the window at the widths the layout fits,
 * which Look::UNFITTED leaves the tablet's out of. A line longer in one language widens a screen
 * in that language alone, so the owner reads every screen in each. A
 * screen is loaded afresh at each width, so its first paint is the layout that width is given.
 * One more case opens the staff form in Greek at the narrowest width at which its fields stand
 * two abreast beside the rail, where a field is at its narrowest, and holds Clear, beside a
 * member's linked sign-in, inside its field and its card.
 *
 * With LOOK_SHOTS naming a run the walk also stops at a phone's width and saves each screen at
 * each stop in each theme of Look::themes() and each mode, as
 * <screen>-<width>-<theme>-<mode>-<language>; LOOK_SCREENS narrows it to the screens it names.
 * The bookings are written straight to luna's rows, dated whole days from the tenant's today in
 * Athens on the real clock, which the browser and the application both read, so the Calendar,
 * Bookings and Home have something to draw: one confirmed today and a request waiting tomorrow,
 * which the rail counts.
 */

/*
 * What a probe added to the page with a second-long fade and a spinning animation of its own is
 * left with: its transition's length and its animation's name, which the suite's motion switch
 * brings to nothing.
 */
const LOOK_MOTION = <<<'JS'
(() => {
    const probe = document.createElement('div');
    probe.style.transition = 'opacity 1s';
    probe.style.animation = 'spin 1s linear infinite';
    document.body.append(probe);
    const style = getComputedStyle(probe);
    const left = style.transitionDuration + ' ' + style.animationName;
    probe.remove();
    return left;
})()
JS;

/*
 * Whether, once the page's fonts have settled, the text face at the body's size and weight is
 * ready to draw, each of the two faces has a loaded file covering Greek, and every font file the
 * page fetched came from where its stylesheet did, at least one of them.
 */
const LOOK_FACES = <<<'JS'
(async () => {
    await document.fonts.ready;
    const covers = (face, code) => face.unicodeRange.split(',').some((range) => {
        const [from, to = from] = range.trim().replace(/^u\+/i, '').split('-').map((hex) => parseInt(hex, 16));
        return code >= from && code <= to;
    });
    const greek = (family) => [...document.fonts].some((face) => face.family.replace(/"/g, '') === family && face.status === 'loaded' && covers(face, 0x3b1));
    const sheet = new URL(document.querySelector('link[rel="stylesheet"]').href).origin;
    const files = performance.getEntriesByType('resource').filter((entry) => new URL(entry.name).pathname.endsWith('.woff2'));
    return document.fonts.check('500 15px "Inter Variable"')
        && greek('Inter Variable')
        && greek('Geologica Variable')
        && files.length > 0
        && files.every((entry) => new URL(entry.name).origin === sheet);
})()
JS;

/** Whether the screen draws exactly one shared page header and one main heading, inside it. */
const LOOK_ONE_HEADER = "document.querySelectorAll('.admin-page-header').length === 1 && document.querySelectorAll('h1').length === 1 && document.querySelector('.admin-page-header h1') !== null";

/** How many columns the section holding the staff form's linked sign-in lays its fields in. */
const LOOK_SIGN_IN_COLUMNS = "getComputedStyle(document.querySelector('#staff-user').closest('.admin-field').parentElement).gridTemplateColumns.split(' ').length";

beforeEach(function () {
    dropDemoSchemas();
    $this->seed(DemoTenantsSeeder::class);
});

afterEach(fn () => dropDemoSchemas());

/**
 * A Haircut on Elena starting at the instant given, confirmed, or a request on an open approval
 * window.
 */
function lookBooking(string $customer, DateTimeImmutable $start, bool $request = false): void
{
    $utc = fn (DateTimeImmutable $at): string => $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:sP');
    $end = $start->add(new DateInterval('PT45M'));
    $id = DB::table('tenant_luna.bookings')->insertGetId([
        'source' => 'embed',
        'uuid' => (string) Str::uuid(), 'status' => $request ? 'awaiting_approval' : 'confirmed', 'customer_name' => $customer,
        'customer_email' => Str::slug($customer).'@example.test', 'customer_phone' => '+30 210 5550101', 'total_minor' => 3500, 'currency' => 'EUR',
        'hold_class' => $request ? 'approval' : null,
        'hold_expires_at' => $request ? DB::raw("now() + interval '2 days'") : null,
        'payment_mode' => 'onsite', 'requires_approval' => $request, 'on_approval_timeout' => 'expire',
    ]);
    DB::table('tenant_luna.booking_items')->insert([
        'booking_id' => $id, 'sort' => 0,
        'service_id' => LunaFixture::id('services', 'Haircut'),
        'staff_id' => LunaFixture::id('staff', 'Elena Papadaki'),
        'start_utc' => $utc($start), 'end_utc' => $utc($end),
        'block_start_utc' => $utc($start), 'block_end_utc' => $utc($end),
        'price_minor' => 3500, 'blocks' => true,
    ]);
}

it('draws every screen of the admin at a tablet\'s width and a desktop\'s in the owner\'s language with no script error, and with no sideways scroll where the layout fits the window', function (SiteLanguage $language) {
    lookBooking('Cora Cut', athensDaysOn(0, 10));
    lookBooking('Rita Request', athensDaysOn(1, 11), request: true);
    // The language is the owner's before the sign-in: the server the walk drives keeps the
    // signed-in user between requests.
    DB::table('users')->where('email', Admin::owner('luna'))->update(['locale' => $language->value]);
    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'))->assertVisible(Admin::HOME);

    foreach (Look::widths() as $width) {
        $page->resize($width, 900);

        foreach (Look::screens() as $screen => ['path' => $path, 'hook' => $hook]) {
            $page->navigate(Admin::url('luna', $path))
                ->assertVisible($hook)
                ->assertScript(LOOK_ONE_HEADER, true)
                ->assertScript(PhoneScripts::PAGE_LANGUAGE, $language->value)
                ->assertNoJavaScriptErrors();

            if (Look::fits($width)) {
                $page->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true);
            }

            if (! Look::shooting()) {
                continue;
            }

            foreach (Look::themes() as $theme) {
                foreach (['light' => false, 'dark' => true] as $mode => $dark) {
                    $page->assertScript(Look::wear($theme, $dark), $theme);
                    Look::shot($page, sprintf('%s-%d-%s-%s-%s', $screen, $width, $theme, $mode, $language->value));
                }
            }
        }
    }
})->with([SiteLanguage::English, SiteLanguage::Greek]);

it('serves the admin with its motion off, its Greek text drawn in its own two faces, fetched from its own build', function () {
    DB::table('users')->where('email', Admin::owner('luna'))->update(['locale' => SiteLanguage::Greek->value]);

    Admin::signedIn($this, 'luna', Admin::owner('luna'))
        ->assertVisible(Admin::HOME)
        ->assertScript(PhoneScripts::PAGE_LANGUAGE, SiteLanguage::Greek->value)
        ->assertScript(LOOK_MOTION, '0s none')
        ->assertScript(LOOK_FACES, true)
        ->assertNoJavaScriptErrors();
});

it('keeps Clear, beside a member\'s linked sign-in on the staff form, inside its field and its card in Greek at the narrowest width at which the form\'s fields stand two abreast beside the rail', function () {
    // Clear shows only while a sign-in is linked, and its label is longer in Greek. A section
    // stands its fields two abreast once its own card is 576px wide, which beside the rail is a
    // window of 848px. This one is 4px wider, so the card is 580 and a field 258, its narrowest,
    // and an input that will not shrink there pushes Clear out of the end of its field. The
    // member is linked to the owner's own sign-in, a user of the tenant the form's list offers.
    $owner = DB::table('users')->where('email', Admin::owner('luna'));
    $member = LunaFixture::id('staff', 'Elena Papadaki');
    DB::table('tenant_luna.staff')->where('id', $member)->update(['user_id' => $owner->value('id')]);
    $owner->update(['locale' => SiteLanguage::Greek->value]);

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'))->assertVisible(Admin::HOME);
    $page->resize(852, 900);
    $page->navigate(Admin::url('luna', '/staff/'.$member))
        ->assertVisible('.admin-staff-user .admin-combobox__clear')
        ->assertSeeIn('.admin-staff-user .admin-combobox__clear', adminLine('admin/shell.kit.combobox.clear', [], SiteLanguage::Greek))
        ->assertScript(PhoneScripts::PAGE_LANGUAGE, SiteLanguage::Greek->value)
        ->assertScript(PhoneScripts::VIEWPORT_WIDTH, 852)
        ->assertScript(LOOK_SIGN_IN_COLUMNS, 2)
        ->assertScript(PhoneScripts::COMBOBOX_CLEAR_INSIDE, true)
        ->assertNoJavaScriptErrors();
});
