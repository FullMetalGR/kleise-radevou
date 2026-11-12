<?php

use App\Tenancy\Slug;
use App\Tenancy\TenantUrls;
use Database\Seeders\DemoTenantsSeeder;
use Illuminate\Support\Facades\DB;
use Pest\Browser\Api\Webpage;
use Tests\Browser\BrowserTestCase;
use Tests\Browser\Support\Flow;
use Tests\Browser\Support\FlowScripts;
use Tests\Browser\Support\ForeignSite;

/*
 * Leaving the review step, in a real browser: the page going away gives the hold back early, on the
 * hosted page and from the embed on a page that is not ours, where the kept-alive DELETE is a
 * preflighted cross-origin request; a tab left on done gives nothing back; and the payment branch
 * is absent on the wire, which is the payment degrade rule working rather than a gap in the test.
 * luna has no payment connection here, so an online service's hold carries no checkout_url key at
 * all and its review offers the confirm button (review.confirm); CheckoutJourneyTest walks the
 * branch with one.
 *
 * The release is best effort and its answer never read, so what proves it went is the row it
 * left: cancelled, with the trail ending in released. The release goes as the page hides, so
 * the row is polled for rather than read once.
 *
 * The bundle is the one npm run build wrote, which is why the build gate precedes this one.
 */

beforeAll(fn () => ForeignSite::start());
afterAll(fn () => ForeignSite::stop());

beforeEach(function () {
    expect(is_file(public_path('build/embed.js')))->toBeTrue('public/build/embed.js is missing; run npm run build before the browser gate.');

    dropDemoSchemas();
    $this->seed(DemoTenantsSeeder::class);
});

afterEach(fn () => dropDemoSchemas());

/**
 * A page of somebody else's holding luna's snippet as a tenant pasted it.
 */
function leavingEmbedPage(): string
{
    return ForeignSite::current()->page('leaving.html', sprintf(<<<'HTML'
        <!doctype html>
        <html lang="en">
        <head><meta charset="utf-8"><title>Somebody else's site</title></head>
        <body>
            <h1>A page that is not ours</h1>
            %s
        </body>
        </html>
        HTML, app(TenantUrls::class)->embedSnippet(Slug::of('luna'))));
}

/**
 * A page holding luna's booking app walked to the details form: one service chosen, no
 * preference, the nine o'clock slot on the Monday of the week row's second week, and the
 * customer typed in. The page comes back as the plugin's awaitable wrapper, which stands in for
 * the webpage it wraps, so the type is said in the docblock alone.
 *
 * @return Webpage
 */
function leavingToDetails(BrowserTestCase $test, string $url, string $service)
{
    $monday = Flow::day('Europe/Athens', 1);

    $page = $test->visit($url)
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
 * A page that is not the booking page, for the tab to navigate away to.
 */
function leavingElsewhere(): string
{
    return ForeignSite::current()->page('elsewhere.html', <<<'HTML'
        <!doctype html>
        <html lang="en">
        <head><meta charset="utf-8"><title>Somewhere else</title></head>
        <body><h1>Somewhere else</h1></body>
        </html>
        HTML);
}

/**
 * luna's only booking as the row stands and its trail in order: the status and the actions.
 *
 * @return array{status: string, trail: list<string>}
 */
function leavingBooking(): array
{
    $schema = Slug::of('luna')->schemaName();
    $status = DB::table($schema.'.bookings')->value('status');
    $trail = DB::table($schema.'.audit_log')->orderBy('id')->pluck('action')->all();

    assert(is_string($status));

    return ['status' => $status, 'trail' => array_values(array_filter($trail, is_string(...)))];
}

/**
 * Polls luna's booking until it reads cancelled or five seconds pass, and answers it as it
 * last stood.
 *
 * @return array{status: string, trail: list<string>}
 */
function leavingAwaitRelease(): array
{
    $deadline = microtime(true) + 5;

    do {
        $booking = leavingBooking();

        if ($booking['status'] === 'cancelled') {
            return $booking;
        }

        usleep(50_000);
    } while (microtime(true) < $deadline);

    return $booking;
}

it('answers a hold on luna\'s online service with no checkout_url key on the wire, and reviews it with the confirm button', function () {
    expect(DB::table(Slug::of('luna')->schemaName().'.services')->where('name', 'Colour')->value('payment_mode'))->toBe('online');

    $page = leavingToDetails($this, hostedPage('luna'), 'Colour')
        ->assertScript(FlowScripts::RECORD_HOLD_ANSWERS, true)
        ->click(Flow::said('estimate.continue'))
        ->assertScript(FlowScripts::stepReached('review'), true)
        ->assertScript(FlowScripts::ACTION_LABEL, Flow::said('review.confirm'))
        ->assertDontSee(Flow::said('review.pay'))
        ->assertNoJavaScriptErrors();

    $answers = $page->script(FlowScripts::HOLD_ANSWERS);

    assert(is_string($answers));

    $texts = json_decode($answers, true, flags: JSON_THROW_ON_ERROR);

    assert(is_array($texts) && count($texts) === 1 && is_string($texts[0]));

    $hold = json_decode($texts[0], true, flags: JSON_THROW_ON_ERROR);

    assert(is_array($hold));

    expect($hold)->toHaveKey('manage_token')
        ->and($hold['status'] ?? null)->toBe('pending')
        ->and($hold)->not->toHaveKey('checkout_url');
});

it('gives the hold back when the tab navigates away from review: the row cancelled and its trail ending in released', function () {
    $page = leavingToDetails($this, hostedPage('luna'), 'Haircut')
        ->click(Flow::said('estimate.continue'))
        ->assertScript(FlowScripts::stepReached('review'), true)
        ->assertScript(FlowScripts::COUNTDOWN_FRESH, true)
        ->assertNoJavaScriptErrors();

    expect(leavingBooking()['status'])->toBe('pending');

    $page->navigate(leavingElsewhere())
        ->assertSee('Somewhere else');

    $booking = leavingAwaitRelease();

    expect($booking['status'])->toBe('cancelled')
        ->and(end($booking['trail']))->toBe('released');
});

it('gives the hold back from the embed on somebody else\'s page too, the kept-alive DELETE crossing origins', function () {
    $page = leavingToDetails($this, leavingEmbedPage(), 'Haircut')
        ->click(Flow::said('estimate.continue'))
        ->assertScript(FlowScripts::stepReached('review'), true)
        ->assertScript('location.origin', ForeignSite::current()->origin)
        ->assertNoJavaScriptErrors();

    expect(leavingBooking()['status'])->toBe('pending');

    $page->navigate(leavingElsewhere())
        ->assertSee('Somewhere else');

    $booking = leavingAwaitRelease();

    expect($booking['status'])->toBe('cancelled')
        ->and(end($booking['trail']))->toBe('released');
});

it('gives nothing back when the tab navigates away from done', function () {
    $page = leavingToDetails($this, hostedPage('luna'), 'Haircut')
        ->click(Flow::said('estimate.continue'))
        ->assertScript(FlowScripts::stepReached('review'), true)
        ->click(Flow::said('review.confirm'))
        ->assertScript(FlowScripts::stepReached('done'), true)
        ->assertScript(FlowScripts::saidPolitely(Flow::said(Flow::CONFIRMED)), true)
        ->assertNoJavaScriptErrors();

    $page->navigate(leavingElsewhere())
        ->assertSee('Somewhere else');

    // The wait a release would have needed to land, for one that must not.
    $booking = leavingAwaitRelease();

    expect($booking['status'])->toBe('confirmed')
        ->and($booking['trail'])->not->toContain('released');
});
