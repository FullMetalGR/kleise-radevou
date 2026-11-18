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
 * each one drawn, in the owner's language and free of script errors, and no wider than the window
 * at the widths the layout fits, which Look::UNFITTED leaves the tablet's out of. A line longer in
 * one language widens a screen in that language alone, so the owner reads every screen in each. A
 * screen is loaded afresh at each width, so its first paint is the layout that width is given.
 *
 * With LOOK_SHOTS naming a run the walk also stops at a phone's width and saves each screen at
 * each stop in each theme of Look::themes() and each mode, as
 * <screen>-<width>-<theme>-<mode>-<language>; LOOK_SCREENS narrows it to the screens it names.
 * The bookings are written straight to luna's rows, dated whole days from the tenant's today in
 * Athens on the real clock, which the browser and the application both read, so the Calendar,
 * Bookings and Home have something to draw: one confirmed today and a request waiting tomorrow,
 * which the rail counts.
 */

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
