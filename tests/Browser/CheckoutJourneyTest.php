<?php

use App\Tenancy\Slug;
use Database\Seeders\DemoTenantsSeeder;
use Illuminate\Support\Facades\DB;
use Pest\Browser\Api\Webpage;
use Tests\Browser\BrowserTestCase;
use Tests\Browser\Support\Flow;
use Tests\Browser\Support\FlowScripts;
use Tests\Support\FakePaymentAdapter;

/*
 * The checkout_url branch in a real browser: luna connected to the fake provider, an online
 * service held, the review step's button continuing to payment (review.pay), and its press
 * handing the hold off and going through the pay hop to the provider's page. The fake's page is
 * the return itself, the manage page with the token and the session's id added, where the
 * booking is still held and the session recorded on it. Then the provider's notification,
 * posted to the central host, confirms the booking with nobody coming back, and the manage page
 * read again shows it confirmed.
 *
 * On the system clock, the hold's expiry the database's now() plus fifteen minutes and the slot
 * dated from today. The bundle is the one npm run build wrote.
 */

beforeEach(function () {
    expect(is_file(public_path('build/embed.js')))->toBeTrue('public/build/embed.js is missing; run npm run build before the browser gate.');

    dropDemoSchemas();
    $this->seed(DemoTenantsSeeder::class);
    connectPaymentFake();
});

afterEach(fn () => dropDemoSchemas());

/**
 * luna's booking app walked to the details form for the service given: no preference, the
 * nine o'clock slot on the Monday of the week row's second week, and the customer typed in.
 *
 * @return Webpage
 */
function checkoutToDetails(BrowserTestCase $test, string $service)
{
    $monday = Flow::day('Europe/Athens', 1);

    $page = $test->visit(hostedPage('luna'))
        ->assertScript(FlowScripts::SETTLED, true)
        ->click($service)
        ->click(Flow::said('estimate.continue'))
        ->assertScript(FlowScripts::stepReached('staff'), true)
        ->click(Flow::said('staff.any'))
        ->click(Flow::said('estimate.continue'))
        ->assertScript(FlowScripts::stepReached('when'), true);

    return Flow::weekOf($page, $monday)
        ->click(sprintf('.kr-booking__day[value="%s"]', $monday->format('Y-m-d')))
        ->assertScript(FlowScripts::SETTLED, true)
        ->click(sprintf('.kr-booking__slot[value="%s"]', Flow::start($monday, 9)))
        ->assertScript(FlowScripts::stepReached('details'), true)
        ->type('customer_name', 'Casey Customer')
        ->type('customer_email', 'casey@example.com');
}

/**
 * luna's one booking once the hop has recorded a session on it, polled for because the press
 * navigates and nothing in the page answers when the hop has run.
 *
 * @return array{uuid: string, status: string, payment_ref: string}
 */
function checkoutRecorded(): array
{
    $deadline = microtime(true) + 10.0;

    do {
        $row = (array) DB::table(Slug::of('luna')->schemaName().'.bookings')->whereNotNull('payment_ref')->first(['uuid', 'status', 'payment_ref']);

        if (isset($row['uuid'], $row['status'], $row['payment_ref']) && is_string($row['uuid']) && is_string($row['status']) && is_string($row['payment_ref'])) {
            return ['uuid' => $row['uuid'], 'status' => $row['status'], 'payment_ref' => $row['payment_ref']];
        }

        usleep(100_000);
    } while (microtime(true) < $deadline);

    throw new RuntimeException('The pay hop recorded no session on luna\'s booking within ten seconds.');
}

it('takes the checkout_url branch from the review step through the pay hop to the manage page, and the notification alone confirms the booking the page then reads', function () {
    $page = checkoutToDetails($this, 'Colour')
        ->click(Flow::said('estimate.continue'))
        ->assertScript(FlowScripts::stepReached('review'), true)
        ->assertScript(FlowScripts::ACTION_LABEL, Flow::said('review.pay'))
        ->assertNoJavaScriptErrors()
        ->click(Flow::said('review.pay'));

    $booking = checkoutRecorded();

    // The app names its language on the hold, so the return the hold minted carries its prefix.
    $page->wait(1)
        ->assertPathIs('/en/manage/'.$booking['uuid'])
        ->assertQueryStringHas('paydesk_session', 'pd_1')
        ->assertQueryStringHas('token');

    expect($booking['status'])->toBe('pending')
        ->and($booking['payment_ref'])->toBe('pd_1')
        ->and(paymentFake()->created())->toBe(1);

    $page->assertSee(Flow::said('manage.statuses.pending'));

    // The customer never comes back: the provider's notification alone confirms the booking,
    // posted to the central host as Paydesk posts it, and the manage page read again says so.
    $body = json_encode(['id' => 'evt_walk', 'type' => FakePaymentAdapter::SETTLED, 'booking' => $booking['uuid'], 'payment' => 'pd_1'], JSON_THROW_ON_ERROR);
    $this->call('POST', route('webhooks.payments', ['provider' => FakePaymentAdapter::SLUG, 'token' => 'hook-token-luna']), [], [], [], [
        'HTTP_PAYDESK_SEAL' => FakePaymentAdapter::seal($body, paymentFakeCredentials()['signing_phrase']),
        'CONTENT_TYPE' => 'application/json',
    ], $body)->assertOk();

    $page->refresh()
        ->assertSee(Flow::said('manage.cancel'))
        ->assertDontSee(Flow::said('manage.statuses.pending'))
        ->assertNoJavaScriptErrors();

    expect(DB::table(Slug::of('luna')->schemaName().'.bookings')->where('uuid', $booking['uuid'])->value('status'))->toBe('confirmed');
});
