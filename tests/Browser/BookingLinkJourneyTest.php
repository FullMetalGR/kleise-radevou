<?php

use Database\Seeders\DemoTenantsSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Browser\Support\Admin;
use Tests\Browser\Support\Flow;
use Tests\Browser\Support\FlowScripts;

/*
 * A booking link in a real browser: the hosted page opened from a link that names a service, a
 * member and a channel opens the flow on the when step with the pair, the customer leaves a
 * note, and the booking lands stamped hosted with the channel, the business reading all three
 * in the booking dialog. Then the links the admin hands out: a service's Copy booking link,
 * and the Links card listing it with its QR code's path; the code the path answers is the
 * route's own feature test, LinkQrTest.
 *
 * On the system clock, as every journey is: the day is Flow::day()'s, whole days on.
 */

beforeEach(function () {
    expect(is_file(public_path('build/embed.js')))->toBeTrue('public/build/embed.js is missing; run npm run build before the browser gate.');

    dropDemoSchemas();
    $this->seed(DemoTenantsSeeder::class);
});

afterEach(fn () => dropDemoSchemas());

/** A row of luna's catalogue by its name. */
function linkJourneyId(string $table, string $name): int
{
    $id = DB::table('tenant_luna.'.$table)->where('name', $name)->value('id');
    assert(is_int($id));

    return $id;
}

it('books through a link naming the service, the member and the channel, and the dialog says where it came from and what the customer wrote', function () {
    $haircut = linkJourneyId('services', 'Haircut');
    $elena = linkJourneyId('staff', 'Elena Papadaki');
    $monday = Flow::day('Europe/Athens', 1);
    $start = Flow::start($monday, 14);

    $page = $this->visit(hostedPage('luna').'?service='.$haircut.'&staff='.$elena.'&ch=instagram')
        ->assertScript("document.querySelector('.kr-booking').dataset.service", (string) $haircut)
        ->assertScript("document.querySelector('.kr-booking').dataset.staff", (string) $elena)
        ->assertScript("document.querySelector('.kr-booking').dataset.channel", 'instagram')
        ->assertScript(FlowScripts::SETTLED, true)
        ->assertScript(FlowScripts::STEP, 'when');

    Flow::weekOf($page, $monday)
        ->click(sprintf('.kr-booking__day[value="%s"]', $monday->format('Y-m-d')))
        ->assertScript(FlowScripts::SETTLED, true)
        ->click(Flow::said('when.period.afternoon'))
        ->assertScript(sprintf("document.querySelector('.kr-booking__slot[value=\"%s\"]') !== null", $start), true)
        ->click(sprintf('.kr-booking__slot[value="%s"]', $start))
        ->assertScript(FlowScripts::STEP, 'details')
        ->assertSee(Flow::said('details.note'))
        ->type('customer_name', 'Casey Customer')
        ->type('customer_email', 'casey@example.com')
        ->type('note', 'Allergic to lavender.')
        ->assertSeeIn('.kr-booking__count', Flow::said('details.count', ['count' => '21', 'n' => '500']))
        ->click(Flow::said('estimate.continue'))
        ->assertScript(FlowScripts::stepReached('review'), true)
        ->assertSeeIn('.kr-booking__summary-staff', 'Elena Papadaki')
        ->click(Flow::said('review.confirm'))
        ->assertScript(FlowScripts::stepReached('done'), true)
        ->assertSee(Flow::said(Flow::CONFIRMED))
        ->assertNoJavaScriptErrors();

    $booking = DB::table('tenant_luna.bookings')->sole();
    assert(is_string($booking->uuid) && is_int($booking->id));

    expect($booking->source)->toBe('hosted')
        ->and($booking->channel)->toBe('instagram')
        ->and(DB::table('tenant_luna.booking_meta')->where('booking_id', $booking->id)->pluck('value', 'key')->all())->toBe(['note' => 'Allergic to lavender.']);

    Admin::signedIn($this, 'luna', Admin::owner('luna'))
        ->navigate(Admin::url('luna', '/bookings/'.$booking->uuid))
        ->waitForText(adminLine('admin/bookings.dialog.items.title'))
        ->assertSeeIn('.admin-booking-facts__source', adminLine('admin/shell.shared.sources.hosted'))
        ->assertSeeIn('.admin-booking-facts__channel', adminLine('admin/shell.shared.channels.instagram'))
        ->assertSeeIn('.admin-booking-facts__note', 'Allergic to lavender.')
        ->assertNoJavaScriptErrors();
});

it('copies a service\'s booking link from its page, recording the press, and lists it in the Links card with its QR code', function () {
    $haircut = linkJourneyId('services', 'Haircut');
    $link = hostedPage('luna').'?service='.$haircut;

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'))
        ->navigate(Admin::url('luna', '/services/'.$haircut))
        ->waitForText(adminLine('admin/shell.copy.booking_link'));

    $page->script('(() => { window.copiedText = []; navigator.clipboard.writeText = (text) => { window.copiedText.push(String(text)); return Promise.resolve(); }; return true; })()');
    $page->click('.admin-booking-link__copy')
        ->waitForText(adminLine('admin/shell.copy.copied'))
        ->waitForEvent('networkidle')
        ->assertScript('window.copiedText.join("|")', $link);

    expect(settingsOf('luna')->value('link_copied_at'))->not->toBeNull();

    $page->navigate(Admin::url('luna', '/settings'))
        ->waitForText(adminLine('admin/settings.links.download_qr'))
        ->assertSeeIn('.admin-settings-links__service', 'Haircut')
        ->assertScript(sprintf("document.querySelector('.admin-settings-links__service a.admin-settings-link__url[href=\"%s\"]') !== null", $link), true)
        ->assertScript(sprintf("document.querySelector('.admin-settings-links__service a.admin-settings-link__qr[href=\"/admin/settings/links/service/%d.svg\"]') !== null", $haircut), true)
        ->assertNoJavaScriptErrors();
});
