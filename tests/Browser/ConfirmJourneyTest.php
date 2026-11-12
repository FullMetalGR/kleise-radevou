<?php

use App\Tenancy\Slug;
use App\Tenancy\TenantUrls;
use Database\Seeders\DemoTenantsSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Browser\Support\Flow;
use Tests\Browser\Support\FlowScripts;
use Tests\Browser\Support\ForeignSite;

/*
 * The customer's journey: a booking made end to end in a real browser and settled, on the
 * hosted page, on the embed at an origin that is not ours, and on the second demo tenant,
 * whose services sit on its implicit member.
 *
 * Nothing here carries the manage token: the app holds it in memory from the hold answer to
 * the confirm body, and the test asserts the row it left rather than the credential it used.
 * The bundle is the one npm run build wrote, which is why the build gate precedes this one.
 */

function journeySnippet(string $slug): string
{
    return app(TenantUrls::class)->embedSnippet(Slug::of($slug));
}

/**
 * A page of somebody else's holding the snippet as a tenant pasted it.
 */
function journeyPage(string $name, string $slug): string
{
    return ForeignSite::current()->page($name, sprintf(<<<'HTML'
        <!doctype html>
        <html lang="en">
        <head><meta charset="utf-8"><title>Somebody else's site</title></head>
        <body>
            <h1>A page that is not ours</h1>
            %s
        </body>
        </html>
        HTML, journeySnippet($slug)));
}

/**
 * How many bookings one tenant holds, which is how a tenant nobody booked is told apart from
 * one somebody did.
 */
function journeyCount(string $slug): int
{
    return DB::table(Slug::of($slug)->schemaName().'.bookings')->count();
}

/**
 * The first booking of a tenant as the row stands, with its instants in UTC text. The journey
 * writes one, and the count beside it is what says so.
 *
 * @return array<string, mixed>
 */
function journeyBooking(string $slug): array
{
    $row = DB::selectOne(sprintf(
        'select status, hold_class, hold_expires_at::text as hold_expires_at, customer_name, customer_email, total_minor, currency'
        .' from %s.bookings order by id limit 1',
        Slug::of($slug)->schemaName(),
    ));

    assert($row instanceof stdClass);

    return [
        'status' => $row->status,
        'hold_class' => $row->hold_class,
        'hold_expires_at' => $row->hold_expires_at,
        'customer_name' => $row->customer_name,
        'customer_email' => $row->customer_email,
        'total_minor' => $row->total_minor,
        'currency' => $row->currency,
    ];
}

/**
 * The trail of one tenant, in the order it was written.
 *
 * @return list<string>
 */
function journeyTrail(string $slug): array
{
    /** @var list<string> $actions */
    $actions = DB::table(Slug::of($slug)->schemaName().'.audit_log')->orderBy('id')->pluck('action')->all();

    return $actions;
}

beforeAll(fn () => ForeignSite::start());
afterAll(fn () => ForeignSite::stop());

beforeEach(function () {
    expect(is_file(public_path('build/embed.js')))->toBeTrue('public/build/embed.js is missing; run npm run build before the browser gate.');

    dropDemoSchemas();
    $this->seed(DemoTenantsSeeder::class);
});

afterEach(fn () => dropDemoSchemas());

it('books and confirms a pay-on-site service end to end on the hosted page', function () {
    $monday = Flow::day('Europe/Athens', 1);
    $start = Flow::start($monday, 14);
    settingsOf('luna')->update(['important_info' => 'Please arrive five minutes early.']);

    $page = $this->visit(hostedPage('luna'))
        ->assertScript(FlowScripts::SETTLED, true)
        ->click('Haircut')
        ->click(Flow::said('estimate.continue'))
        ->assertScript(FlowScripts::STEP, 'staff')
        ->click(Flow::said('staff.any'))
        ->click(Flow::said('estimate.continue'))
        ->assertScript(FlowScripts::STEP, 'when');

    Flow::weekOf($page, $monday)
        ->click(sprintf('.kr-booking__day[value="%s"]', $monday->format('Y-m-d')))
        ->assertScript(FlowScripts::SETTLED, true)
        ->click(Flow::said('when.period.afternoon'))
        ->assertScript(sprintf("document.querySelector('.kr-booking__slot[value=\"%s\"]') !== null", $start), true)
        ->click(sprintf('.kr-booking__slot[value="%s"]', $start))
        ->assertScript(FlowScripts::STEP, 'details')
        ->type('customer_name', 'Casey Customer')
        ->type('customer_email', 'casey@example.com')
        ->click(Flow::said('estimate.continue'))
        ->assertScript(FlowScripts::stepReached('review'), true)
        ->assertSeeIn('.kr-booking__summary-service', 'Haircut')
        ->assertSeeIn('.kr-booking__summary-staff', 'Elena Papadaki')
        ->assertSeeIn('.kr-booking__total', '35.00')
        ->assertSeeIn('.kr-booking__important', Flow::said('review.important_info'))
        ->assertSeeIn('.kr-booking__important-text', 'Please arrive five minutes early.')
        ->assertSee(Flow::said('review.confirm'))
        ->assertDontSee(Flow::said('review.pay'))
        ->click(Flow::said('review.confirm'))
        ->assertScript(FlowScripts::stepReached('done'), true)
        ->assertScript(FlowScripts::saidPolitely(Flow::said(Flow::CONFIRMED)), true)
        ->assertSee(Flow::said(Flow::CONFIRMED))
        ->assertDontSee(Flow::said('done.headings.awaiting_approval'))
        ->assertScript("document.querySelectorAll('.kr-booking__frame button').length", 0)
        ->assertScript(FlowScripts::LOOKS_LIKE_A_TOKEN, false)
        ->assertNoJavaScriptErrors();

    expect(journeyCount('luna'))->toBe(1)
        ->and(journeyBooking('luna'))->toEqual([
            'status' => 'confirmed',
            'hold_class' => null,
            'hold_expires_at' => null,
            'customer_name' => 'Casey Customer',
            'customer_email' => 'casey@example.com',
            'total_minor' => 3500,
            'currency' => 'EUR',
        ])
        ->and(journeyTrail('luna'))->toBe(['held', 'confirmed']);
});

it('books and confirms the same way from the embed on a page that is not ours', function () {
    $tuesday = Flow::day('Europe/Athens', 2);
    settingsOf('luna')->update(['important_info' => 'We take cash only.']);
    $url = journeyPage('journey.html', 'luna');

    $page = $this->visit($url)
        ->assertScript('location.origin', ForeignSite::current()->origin)
        ->assertScript(FlowScripts::SETTLED, true)
        ->click('Beard trim')
        ->click(Flow::said('estimate.continue'))
        ->assertScript(FlowScripts::STEP, 'staff')
        ->click('Nikos Georgiou')
        ->click(Flow::said('estimate.continue'))
        ->assertScript(FlowScripts::STEP, 'when');

    Flow::weekOf($page, $tuesday)
        ->click(sprintf('.kr-booking__day[value="%s"]', $tuesday->format('Y-m-d')))
        ->assertScript(FlowScripts::SETTLED, true)
        ->click(sprintf('.kr-booking__slot[value="%s"]', Flow::start($tuesday, 10)))
        ->assertScript(FlowScripts::STEP, 'details')
        ->type('customer_name', 'Robin Regular')
        ->type('customer_email', 'robin@example.com')
        ->type('customer_phone', '+30 210 0000000')
        ->click(Flow::said('estimate.continue'))
        ->assertScript(FlowScripts::stepReached('review'), true)
        ->assertSeeIn('.kr-booking__summary-service', 'Beard trim')
        ->assertSeeIn('.kr-booking__important', Flow::said('review.important_info'))
        ->assertSeeIn('.kr-booking__important-text', 'We take cash only.')
        ->assertSee(Flow::said('review.confirm'))
        ->click(Flow::said('review.confirm'))
        ->assertScript(FlowScripts::stepReached('done'), true)
        ->assertScript(FlowScripts::saidPolitely(Flow::said(Flow::CONFIRMED)), true)
        ->assertScript(FlowScripts::LOOKS_LIKE_A_TOKEN, false)
        ->assertNoJavaScriptErrors();

    expect(journeyCount('luna'))->toBe(1)
        ->and(journeyBooking('luna'))->toEqual([
            'status' => 'confirmed',
            'hold_class' => null,
            'hold_expires_at' => null,
            'customer_name' => 'Robin Regular',
            'customer_email' => 'robin@example.com',
            'total_minor' => 1500,
            'currency' => 'EUR',
        ])
        ->and(journeyTrail('luna'))->toBe(['held', 'confirmed']);
});

it('books and confirms on the second tenant, whose staff step skips itself and whose summary names nobody', function () {
    $monday = Flow::day('Europe/London', 1);
    $start = Flow::start($monday, 9);

    $page = $this->visit(hostedPage('pier'))
        ->assertScript(FlowScripts::SETTLED, true)
        ->assertSeeIn('.kr-booking', 'Pier Physiotherapy')
        ->click('Initial assessment')
        ->click(Flow::said('estimate.continue'))
        ->assertScript(FlowScripts::STEP, 'when');

    Flow::weekOf($page, $monday)
        ->click(sprintf('.kr-booking__day[value="%s"]', $monday->format('Y-m-d')))
        ->assertScript(FlowScripts::SETTLED, true)
        ->click(sprintf('.kr-booking__slot[value="%s"]', $start))
        ->assertScript(FlowScripts::STEP, 'details')
        ->type('customer_name', 'Sam Swimmer')
        ->type('customer_email', 'sam@example.com')
        ->click(Flow::said('estimate.continue'))
        ->assertScript(FlowScripts::stepReached('review'), true)
        ->assertSeeIn('.kr-booking__summary-service', 'Initial assessment')
        ->assertScript(FlowScripts::SUMMARY_STAFF_LINES, 0)
        ->assertSeeIn('.kr-booking__total', '65.00')
        ->assertSeeIn('.kr-booking__timezone', 'Europe/London')
        ->assertScript("document.querySelector('.kr-booking__important') === null", true)
        ->click(Flow::said('review.confirm'))
        ->assertScript(FlowScripts::stepReached('done'), true)
        ->assertScript(FlowScripts::saidPolitely(Flow::said(Flow::CONFIRMED)), true)
        ->assertNoJavaScriptErrors();

    expect(journeyCount('pier'))->toBe(1)
        ->and(journeyBooking('pier'))->toEqual([
            'status' => 'confirmed',
            'hold_class' => null,
            'hold_expires_at' => null,
            'customer_name' => 'Sam Swimmer',
            'customer_email' => 'sam@example.com',
            'total_minor' => 6500,
            'currency' => 'GBP',
        ])
        ->and(journeyTrail('pier'))->toBe(['held', 'confirmed'])
        ->and(journeyCount('luna'))->toBe(0)
        ->and(journeyTrail('luna'))->toBe([]);
});
