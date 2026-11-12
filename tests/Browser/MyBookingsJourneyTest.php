<?php

use App\Http\BusinessPage;
use App\Http\Controllers\MyBookingsController;
use App\Http\MyBookingsCookie;
use App\Site\SiteLanguage;
use App\Tenancy\Slug;
use App\Tenancy\TenantUrls;
use Database\Seeders\DemoTenantsSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Browser\BrowserTestCase;
use Tests\Browser\Support\Flow;
use Tests\Browser\Support\FlowScripts;

/*
 * My bookings in a real browser: a customer with two bookings at luna follows the header's link,
 * asks for a link with their address, opens it from the message the array mailer kept, presses
 * the link page's one button (P9F-L16-a; the page itself spends nothing), reads both bookings,
 * signs out, and reads the form.
 *
 * The bookings are rows, one ahead and one behind, dated relative to today on the system clock as
 * every journey is, and a third under another address that the list must not show. The messages
 * are read from the test process, which serves the application: the array transport phpunit.xml
 * names is the same object on both sides of the request. The session lives across the browser's
 * requests in the database driver the Browser suite sets, and the cookie is the browser's own.
 *
 * The second walk: the list acts. Two bookings of casey's taken over the wire as the booking app
 * takes them, on one Monday a week or two on; the list reschedules the first through the card's
 * dialog and cancels the second through its two-click row, each with no token on the wire, the
 * session cookie the browser's own; and Book again opens the flow with the name, the email and
 * the phone of the most recent booking already in the details step, the cancelled one, made last,
 * read past.
 *
 * The third walk: a customer who opened the page under /en or /el stays under it through the link
 * form and the server's way back, the link's button, Book again and the sign-out, every sentence
 * read through the translator in the prefix's language as the page answers it, the Greek of
 * booking.php (the prefix names its language while the exposure key is false, Languages).
 *
 * The bundle is the one npm run build wrote, which is why the build gate precedes this one.
 */

/** The frame's mode, the list mode's echo beside its view. */
const MINE_MODE = "document.querySelector('.kr-booking__frame').getAttribute('data-kr-mode')";

/** The cards' first service names, in the order the page shows them. */
const MINE_CARDS = "Array.from(document.querySelectorAll('.kr-booking__card .kr-booking__summary-service')).map((name) => name.textContent).join('|')";

/** The cards' Book again targets, in the order the page shows them. */
const MINE_AGAIN = "Array.from(document.querySelectorAll('.kr-booking__again')).map((link) => link.getAttribute('href')).join('|')";

beforeEach(function () {
    expect(is_file(public_path('build/embed.js')))->toBeTrue('public/build/embed.js is missing; run npm run build before the browser gate.');

    dropDemoSchemas();
    $this->seed(DemoTenantsSeeder::class);
    seamTransport()->flush();
});

afterEach(fn () => dropDemoSchemas());

/**
 * A UTC instant of a day relative to Athens's today, as mybBooking takes it.
 */
function mineAt(int $days, int $hour): string
{
    return athensDaysOn($days, $hour)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

it('asks for a link for an address with two bookings, opens it from the mailer, reads both bookings, signs out and reads the form', function () {
    mybBooking(MYB_CASEY, mineAt(3, 10));
    mybBooking(MYB_CASEY, mineAt(-4, 11), 'completed', 'Colour');
    mybBooking('someone.else@example.test', mineAt(5, 12), 'confirmed', 'Beard trim');
    $origin = app(TenantUrls::class)->origin(Slug::of('luna'));

    $page = $this->visit(hostedPage('luna'))
        ->assertScript(FlowScripts::SETTLED, true)
        ->assertSeeIn('.hosted-mine', pageSentence(BusinessPage::MY_BOOKINGS))
        ->click('.hosted-mine')
        ->waitForText(Flow::said('my_bookings.send'))
        ->assertScript(MINE_MODE, 'list')
        ->assertScript(FlowScripts::VIEW, 'form')
        ->fill('email', MYB_CASEY)
        ->click(Flow::said('my_bookings.send'))
        ->waitForText(pageSentence(MyBookingsController::SENT))
        ->assertScript(FlowScripts::VIEW, 'form')
        ->assertNoJavaScriptErrors();

    $token = mybTokenIn((string) seamAt(MYB_CASEY)->getTextBody());
    $link = app(TenantUrls::class)->myBookingsLink(Slug::of('luna'), $token);

    // The link's page: one button, and the link still live after the fetch, as a mail scanner's
    // would leave it; the press is what opens the session.
    $page->navigate($link)
        ->waitForText(pageSentence(MyBookingsController::OPEN_LABEL))
        ->assertSee(mybOpenSentence())
        ->assertSourceMissing($token);

    expect(DB::table('tenant_luna.customer_links')->whereNotNull('used_at')->count())->toBe(0)
        ->and(DB::table('tenant_luna.customer_sessions')->count())->toBe(0);

    $page->click(pageSentence(MyBookingsController::OPEN_LABEL))
        ->waitForText(Flow::said('my_bookings.upcoming'))
        ->assertScript(MINE_MODE, 'list')
        ->assertScript(FlowScripts::VIEW, 'list')
        ->assertScript(MINE_CARDS, 'Haircut|Colour')
        ->assertScript(MINE_AGAIN, implode('|', [$origin.'/?service='.seamId('services', 'Haircut').'&staff='.seamId('staff', 'Elena Papadaki'), $origin.'/?service='.seamId('services', 'Colour').'&staff='.seamId('staff', 'Elena Papadaki')]))
        ->assertSee(Flow::said('my_bookings.past'))
        ->assertSee(Flow::said('manage.statuses.completed'))
        ->assertDontSee('Beard trim')
        // Neither the link's token nor the session's secret is anywhere in the page.
        ->assertScript(FlowScripts::TOKEN_IN_MARKUP, false)
        ->assertScript("document.cookie.includes('".MyBookingsCookie::NAME."')", false)
        ->assertNoJavaScriptErrors();

    // The framework's session, a row of the database driver here, never kept the link's URL.
    foreach (DB::table('sessions')->pluck('payload') as $payload) {
        expect($payload)->toBeString();
        assert(is_string($payload));
        expect((string) base64_decode($payload, true))->not->toContain($token);
    }

    $page->click(Flow::said('my_bookings.sign_out'))
        ->waitForText(pageSentence(MyBookingsController::SIGNED_OUT))
        ->assertScript(FlowScripts::VIEW, 'form')
        ->assertDontSee('Haircut')
        ->assertNoJavaScriptErrors();

    // The link opened once: a second open is the form with its sentence.
    $page->navigate($link)
        ->waitForText(pageSentence(MyBookingsController::INVALID))
        ->assertScript(FlowScripts::VIEW, 'form')
        ->assertNoJavaScriptErrors();
});

it('keeps the prefix it was opened under, /{0}, through the link form and the way back, the link\'s button, Book again and the sign-out', function (string $language) {
    mybBooking(MYB_CASEY, mineAt(3, 10));
    $prefix = SiteLanguage::from($language);
    $urls = app(TenantUrls::class);
    $page = '/'.$language.'/my-bookings';

    $visit = $this->visit($urls->myBookings(Slug::of('luna'), $prefix))
        ->waitForText(Flow::saidIn('my_bookings.send', $prefix))
        ->fill('email', MYB_CASEY)
        ->click(Flow::saidIn('my_bookings.send', $prefix))
        ->waitForText(pageSentence(MyBookingsController::SENT, [], $prefix))
        ->assertScript('window.location.pathname', $page)
        ->assertScript(FlowScripts::VIEW, 'form')
        ->assertNoJavaScriptErrors();

    $visit->navigate($urls->myBookingsLink(Slug::of('luna'), mybTokenIn((string) seamAt(MYB_CASEY)->getTextBody()), $prefix))
        ->waitForText(pageSentence(MyBookingsController::OPEN_LABEL, [], $prefix))
        ->click(pageSentence(MyBookingsController::OPEN_LABEL, [], $prefix))
        ->waitForText(Flow::saidIn('my_bookings.upcoming', $prefix))
        ->assertScript('window.location.pathname', $page)
        ->assertScript(FlowScripts::VIEW, 'list')
        ->assertScript(MINE_AGAIN, $urls->origin(Slug::of('luna')).'/'.$language.'?service='.seamId('services', 'Haircut').'&staff='.seamId('staff', 'Elena Papadaki'))
        ->click(Flow::saidIn('my_bookings.sign_out', $prefix))
        ->waitForText(pageSentence(MyBookingsController::SIGNED_OUT, [], $prefix))
        ->assertScript('window.location.pathname', $page)
        ->assertScript(FlowScripts::VIEW, 'form')
        ->assertNoJavaScriptErrors();
})->with(['en', 'el']);

/** The card at a position in the list, as a selector its controls are reached under. */
function mineCard(int $position): string
{
    return sprintf('.kr-booking__card:nth-child(%d)', $position);
}

/** The value a field of the details step holds. */
function mineField(string $name): string
{
    return sprintf("document.querySelector('input[name=\"%s\"]').value", $name);
}

/**
 * A Haircut with Elena held and confirmed over the wire under casey's address, at an hour of the
 * day given, the way the booking app takes one. Answers its uuid.
 */
function mineHeld(BrowserTestCase $test, DateTimeImmutable $day, int $hour, ?string $phone = null): string
{
    $origin = app(TenantUrls::class)->origin(Slug::of('luna'));
    $held = $test->postJson($origin.'/api/v1/holds', array_filter([
        'customer_name' => 'Casey Customer',
        'customer_email' => MYB_CASEY,
        'customer_phone' => $phone,
        'items' => [['service_id' => seamId('services', 'Haircut'), 'staff_id' => seamId('staff', 'Elena Papadaki')]],
        'start' => Flow::start($day, $hour),
    ], fn (mixed $value): bool => $value !== null))->assertCreated()->json();

    assert(is_array($held) && is_string($held['uuid']) && is_string($held['manage_token']));

    $test->postJson($origin.'/api/v1/bookings/'.$held['uuid'].'/confirm', ['token' => $held['manage_token']])
        ->assertOk()
        ->assertJsonPath('status', 'confirmed');

    return $held['uuid'];
}

it('reschedules one booking and cancels another from the list with no token on the wire, then books again with the name and the email already filled', function () {
    $monday = Flow::day('Europe/Athens', 1);
    $moving = mineHeld($this, $monday, 9, '+30 210 1111111');
    $cancelling = mineHeld($this, $monday, 11, '+30 210 0000000');
    // The two confirmations went to casey as well; the link's message is the one read below.
    seamTransport()->flush();

    $page = $this->visit(app(TenantUrls::class)->myBookings(Slug::of('luna')))
        ->waitForText(Flow::said('my_bookings.send'))
        ->fill('email', MYB_CASEY)
        ->click(Flow::said('my_bookings.send'))
        ->waitForText(pageSentence(MyBookingsController::SENT));

    $page->navigate(app(TenantUrls::class)->myBookingsLink(Slug::of('luna'), mybTokenIn((string) seamAt(MYB_CASEY)->getTextBody())))
        ->waitForText(pageSentence(MyBookingsController::OPEN_LABEL))
        ->click(pageSentence(MyBookingsController::OPEN_LABEL))
        ->waitForText(Flow::said('my_bookings.upcoming'))
        ->assertScript(FlowScripts::SETTLED, true)
        ->assertScript(FlowScripts::VIEW, 'list')
        ->assertScript(FlowScripts::BUTTONS, Flow::buttons('manage.cancel', 'move.reschedule', 'manage.cancel', 'move.reschedule', 'my_bookings.sign_out'))
        ->assertScript(FlowScripts::summaryWhen($monday, '9:00'), true)
        ->click(mineCard(1).' .kr-booking__reschedule')
        ->assertScript(FlowScripts::DIALOG_OPEN, true)
        ->assertScript(FlowScripts::FOCUSED_DIALOG, true)
        ->assertSee(Flow::said('move.pick'));

    // 09:30 lies inside the booking's own range, which the per-booking read leaves out, so the
    // dialog offers it; the move takes it, and the card reads it once the list is read again.
    Flow::weekOf($page, $monday)
        ->click(sprintf('.kr-booking__day[value="%s"]', $monday->format('Y-m-d')))
        ->assertScript(FlowScripts::SETTLED, true)
        ->click(sprintf('.kr-booking__slot[value="%s"]', Flow::start($monday, 9, 30)))
        ->assertScript(FlowScripts::saidPolitely(Flow::said('move.moved')), true)
        ->assertScript(FlowScripts::DIALOG_OPEN, false)
        ->assertScript(FlowScripts::SETTLED, true)
        ->assertScript(FlowScripts::summaryWhen($monday, '9:30'), true)
        ->click(mineCard(2).' .kr-booking__cancel')
        ->assertSee(Flow::said('manage.question'))
        ->click(mineCard(2).' .kr-booking__yes')
        ->assertScript(FlowScripts::saidPolitely(Flow::said('my_bookings.cancelled')), true)
        ->assertScript(FlowScripts::SETTLED, true)
        ->assertSeeIn(mineCard(2).' .kr-booking__status', Flow::said('manage.statuses.cancelled'))
        ->assertScript(FlowScripts::BUTTONS, Flow::buttons('manage.cancel', 'move.reschedule', 'my_bookings.sign_out'))
        ->assertScript(FlowScripts::NO_ALERT, true)
        // No token reached the wire or the page: the session cookie was the credential.
        ->assertScript(FlowScripts::LOOKS_LIKE_A_TOKEN, false)
        ->assertNoJavaScriptErrors();

    $statuses = DB::table('tenant_luna.bookings')->whereIn('uuid', [$moving, $cancelling])->orderBy('id')->pluck('status')->all();
    $start = DB::table('tenant_luna.booking_items as i')
        ->join('tenant_luna.bookings as b', 'b.id', '=', 'i.booking_id')
        ->where('b.uuid', $moving)
        ->selectRaw("to_char(i.start_utc at time zone 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS\"+00:00\"') as start")
        ->value('start');
    $actors = DB::table('tenant_luna.audit_log')->whereIn('action', ['rescheduled', 'cancelled'])->orderBy('id')->selectRaw("actor || ' ' || action as line")->pluck('line')->all();

    expect($statuses)->toBe(['confirmed', 'cancelled'])
        ->and($start)->toBe(Flow::start($monday, 9, 30))
        ->and($actors)->toBe(['customer rescheduled', 'customer cancelled']);

    // Book again: the hosted page with the card's service and member, and the details step with
    // the name, email and phone of the most recent booking that did not come to nothing: the one
    // just cancelled was made last and is read past, so the phone is the moved booking's
    // (P9F-L17-d). 11:00 on the Monday is the time the cancel just gave back.
    $page->click(mineCard(1).' .kr-booking__again')
        ->assertScript(FlowScripts::SETTLED, true)
        ->assertScript(FlowScripts::STEP, 'when');

    Flow::weekOf($page, $monday)
        ->click(sprintf('.kr-booking__day[value="%s"]', $monday->format('Y-m-d')))
        ->assertScript(FlowScripts::SETTLED, true)
        ->click(sprintf('.kr-booking__slot[value="%s"]', Flow::start($monday, 11)))
        ->assertScript(FlowScripts::STEP, 'details')
        ->assertScript(mineField('customer_name'), 'Casey Customer')
        ->assertScript(mineField('customer_email'), MYB_CASEY)
        ->assertScript(mineField('customer_phone'), '+30 210 1111111')
        ->click(Flow::said('estimate.continue'))
        ->assertScript(FlowScripts::stepReached('review'), true)
        ->click(Flow::said('review.confirm'))
        ->assertScript(FlowScripts::stepReached('done'), true)
        ->assertSee(Flow::said(Flow::CONFIRMED))
        ->assertNoJavaScriptErrors();

    expect(DB::table('tenant_luna.bookings')->whereNotIn('uuid', [$moving, $cancelling])->get(['customer_name', 'customer_email', 'customer_phone', 'status'])->map(fn (object $row): array => (array) $row)->all())
        ->toBe([['customer_name' => 'Casey Customer', 'customer_email' => MYB_CASEY, 'customer_phone' => '+30 210 1111111', 'status' => 'confirmed']]);
});
