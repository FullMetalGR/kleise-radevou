<?php

use App\Tenancy\Slug;
use App\Tenancy\TenantUrls;
use Database\Seeders\DemoTenantsSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Browser\Support\Flow;
use Tests\Browser\Support\FlowScripts;
use Tests\Browser\Support\ForeignSite;

/*
 * A customer holds a slot from the tenant's website. On luna's real rows, a real browser picks
 * the service both people perform, asks for no preference, picks a day and a time, types a name
 * and an email, presses the button that continues (estimate.continue), and lands on review over
 * a summary the server wrote, with one pending booking in the tenant's schema on one of the two
 * people. The same journey runs on the embed on a page that is not ours, which is the surface
 * the promise is actually about. The service the owner approves by hand ends on done with the
 * request-sent sentence, since an approval hold is its own outcome.
 *
 * The bundle is the one npm run build wrote, which is why the build gate precedes this one.
 *
 * What this file cannot prove, and must not be read as proving: the cross-origin CSRF path.
 * The token middleware stands down under the test runner, so neither a browser nor a request
 * test exercises the exception list; the feature suite moves the environment off testing for
 * that one assertion instead.
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
 * A page of somebody else's holding the snippet the provisioning command prints, as pasted.
 */
function gateEmbedPage(string $slug): string
{
    $snippet = app(TenantUrls::class)->embedSnippet(Slug::of($slug));

    return ForeignSite::current()->page('gate-'.$slug.'.html', <<<HTML
        <!doctype html>
        <html lang="en">
        <head>
            <meta charset="utf-8">
            <title>Somebody else's site</title>
        </head>
        <body>
            <h1>A page that is not ours</h1>
            {$snippet}
        </body>
        </html>
        HTML);
}

/**
 * A wall-clock reading on a day at luna, as the wire spells a start: RFC 3339 in UTC, which is
 * the spelling the availability answer carries and the hold body must return byte for byte.
 */
function gateStart(DateTimeImmutable $day, int $hour): string
{
    return $day->setTime($hour, 0)->setTimezone(new DateTimeZone('UTC'))->format(DateTimeImmutable::RFC3339);
}

/**
 * A timestamptz as the driver handed it over, spelled the way this API spells an instant. The
 * connection renders the column in whatever zone it is set to, so the comparison is between
 * two instants and never between two strings. The rendering carries its own offset, which
 * wins; the zone named here is the explicit fallback for a rendering that ever stops carrying
 * one, so the reading can never fall back to PHP's default zone, and UTC is what this API
 * stores and what these columns are named for.
 */
function gateInstant(mixed $value): string
{
    assert(is_string($value));

    $utc = new DateTimeZone('UTC');

    return (new DateTimeImmutable($value, $utc))->setTimezone($utc)->format(DateTimeImmutable::RFC3339);
}

function gateDaySelector(DateTimeImmutable $day): string
{
    return sprintf('.kr-booking__day[value="%s"]', $day->format('Y-m-d'));
}

function gateSlotSelector(string $start): string
{
    return sprintf('.kr-booking__slot[value="%s"]', $start);
}

/**
 * The one booking of a tenant: its status, and its items' staff member and start in chain
 * order, read from the rows the browser's journey wrote.
 *
 * @return array{status: string, staff: list<int>, starts: list<string>}
 */
function gateBooking(string $schema = 'tenant_luna'): array
{
    $booking = DB::table($schema.'.bookings')->sole();

    assert(is_string($booking->status) && is_int($booking->id));

    $staff = [];
    $starts = [];

    foreach (DB::table($schema.'.booking_items')->where('booking_id', $booking->id)->orderBy('sort')->get() as $item) {
        assert(is_int($item->staff_id));

        $staff[] = $item->staff_id;
        $starts[] = gateInstant($item->start_utc);
    }

    return ['status' => $booking->status, 'staff' => $staff, 'starts' => $starts];
}

/**
 * The identifier of the one member who performs the service the owner approves by hand.
 */
function gateElenaId(): int
{
    $id = DB::table('tenant_luna.staff')->where('name', 'Elena Papadaki')->value('id');

    assert(is_int($id));

    return $id;
}

/**
 * The identifiers of luna's two named staff members, either of whom may take a haircut asked
 * for with no preference: which one is the planner's to decide.
 *
 * @return list<int>
 */
function gateNamedStaff(): array
{
    /** @var list<int> $ids */
    $ids = DB::table('tenant_luna.staff')->where('is_implicit', false)->orderBy('id')->pluck('id')->all();

    return $ids;
}

it('holds a slot from the tenant\'s own hosted page: service, no preference, a day, a time, a name and an email', function () {
    $tuesday = firstWindowDay(2);
    $start = gateStart($tuesday, 10);

    $page = $this->visit(hostedPage('luna'))
        ->assertScript(FlowScripts::SETTLED, true)
        ->click('Haircut')
        ->click(Flow::said('estimate.continue'))
        ->assertScript(FlowScripts::STEP, 'staff')
        ->click(Flow::said('staff.any'))
        ->click(Flow::said('estimate.continue'))
        ->assertScript(FlowScripts::STEP, 'when');

    Flow::weekOf($page, $tuesday)
        ->assertScript(FlowScripts::SETTLED, true)
        ->click(gateDaySelector($tuesday))
        ->click(gateSlotSelector($start))
        ->assertScript(FlowScripts::STEP, 'details')
        ->type('customer_name', 'Casey Customer')
        ->type('customer_email', 'casey@example.com')
        ->click(Flow::said('estimate.continue'))
        ->assertScript(FlowScripts::stepReached('review'), true)
        ->assertSeeIn('.kr-booking__summary', 'Haircut')
        ->assertSeeIn('.kr-booking__summary', '10:00 AM')
        ->assertSeeIn('.kr-booking__total', '35.00')
        ->assertMissing('.kr-booking__form')
        ->assertScript(FlowScripts::LOOKS_LIKE_A_TOKEN, false)
        ->assertNoJavaScriptErrors();

    $booking = gateBooking();

    expect($booking['status'])->toBe('pending')
        ->and($booking['staff'])->toHaveCount(1)
        ->and(gateNamedStaff())->toContain($booking['staff'][0])
        ->and($booking['starts'])->toBe([$start]);
});

it('holds the same slot from the embed on a page that is not ours', function () {
    $tuesday = firstWindowDay(2);
    $start = gateStart($tuesday, 10);

    $page = $this->visit(gateEmbedPage('luna'))
        ->assertScript('location.origin', ForeignSite::current()->origin)
        ->assertScript(FlowScripts::SETTLED, true)
        ->click('Haircut')
        ->click(Flow::said('estimate.continue'))
        ->click(Flow::said('staff.any'))
        ->click(Flow::said('estimate.continue'))
        ->assertScript(FlowScripts::STEP, 'when');

    Flow::weekOf($page, $tuesday)
        ->assertScript(FlowScripts::SETTLED, true)
        ->click(gateDaySelector($tuesday))
        ->click(gateSlotSelector($start))
        ->assertScript(FlowScripts::STEP, 'details')
        ->type('customer_name', 'Casey Customer')
        ->type('customer_email', 'casey@example.com')
        ->click(Flow::said('estimate.continue'))
        ->assertScript(FlowScripts::stepReached('review'), true)
        ->assertSeeIn('.kr-booking__summary', 'Haircut')
        ->assertSeeIn('.kr-booking__summary', '10:00 AM')
        ->assertScript(FlowScripts::LOOKS_LIKE_A_TOKEN, false)
        ->assertNoJavaScriptErrors();

    $booking = gateBooking();

    expect($booking['status'])->toBe('pending')
        ->and($booking['staff'])->toHaveCount(1)
        ->and(gateNamedStaff())->toContain($booking['staff'][0]);
});

it('ends on done with the request-sent sentence for the service the owner approves by hand', function () {
    $tuesday = firstWindowDay(2);
    $start = gateStart($tuesday, 9);

    $page = $this->visit(hostedPage('luna'))
        ->assertScript(FlowScripts::SETTLED, true)
        ->click('Bridal trial')
        ->click(Flow::said('estimate.continue'))
        ->assertScript(FlowScripts::STEP, 'staff')
        ->click(Flow::said('staff.any'))
        ->click(Flow::said('estimate.continue'));

    Flow::weekOf($page, $tuesday)
        ->assertScript(FlowScripts::SETTLED, true)
        ->click(gateDaySelector($tuesday))
        ->click(gateSlotSelector($start))
        ->assertScript(FlowScripts::STEP, 'details')
        ->type('customer_name', 'Casey Customer')
        ->type('customer_email', 'casey@example.com')
        ->click(Flow::said('estimate.continue'))
        ->assertScript(FlowScripts::stepReached('done'), true)
        ->assertScript(FlowScripts::saidPolitely(Flow::said('done.sentences.awaiting_approval')), true)
        ->assertDontSee(Flow::said(Flow::CONFIRMED))
        ->assertMissing('.kr-booking__summary')
        ->assertCount('.kr-booking__button', 0)
        ->assertScript(FlowScripts::LOOKS_LIKE_A_TOKEN, false)
        ->assertNoJavaScriptErrors();

    $booking = gateBooking();

    expect($booking['status'])->toBe('awaiting_approval')
        ->and($booking['staff'])->toBe([gateElenaId()]);
});
