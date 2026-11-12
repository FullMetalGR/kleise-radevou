<?php

use App\Tenancy\Slug;
use App\Tenancy\TenantUrls;
use Database\Seeders\DemoTenantsSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Browser\Support\Flow;
use Tests\Browser\Support\FlowScripts;
use Tests\Browser\Support\ForeignSite;

/*
 * A customer books from the tenant's website and then cancels it through the manage link.
 * The link travels in an email, so the test carries the token itself, taken off the hold
 * answer on the wire; the done screen renders no link and must not.
 *
 * The booking half runs on the embed at an origin that is not ours, because that is the
 * surface the promise is about, and the manage half runs on the hosted manage page, which is
 * where an email lands. One bundle serves both.
 *
 * The token is read by patching the page's own fetch before the journey starts and keeping
 * what the hold answered. That is the test standing where the browser lets it stand: the app
 * holds the token in memory and hands it to nothing, so the only copy on this machine is the
 * one the wire carried.
 *
 * The bundle is the one npm run build wrote, which is why the build gate precedes this one.
 */

/**
 * Keeps the manage token the hold answers with, off the page's own fetch. The response is
 * cloned, so the app reads its body exactly as it would have.
 */
const MANAGE_RECORDER = <<<'JS'
    (() => {
        const original = window.fetch;
        window.__krHoldToken = null;
        window.fetch = (input, init) => original(input, init).then((response) => {
            if (String(input).includes('/api/v1/holds')) {
                response.clone().json().then((body) => {
                    window.__krHoldToken = typeof body.manage_token === 'string' ? body.manage_token : null;
                }, () => undefined);
            }

            return response;
        });

        return true;
    })()
    JS;

const MANAGE_TOKEN_SCRIPT = 'window.__krHoldToken';

/**
 * The destructive control, reached by its class rather than by its label. The plugin reads any
 * string holding a comma as a CSS selector list, and this one's label is "Yes, cancel it", so
 * clicking it by text would ask the browser for an element named Yes.
 */
const YES_CONTROL = '.kr-booking__yes';

/**
 * A page of somebody else's holding luna's snippet as a tenant pasted it.
 */
function manageEmbedPage(): string
{
    $snippet = app(TenantUrls::class)->embedSnippet(Slug::of('luna'));

    return ForeignSite::current()->page('manage-journey.html', <<<HTML
        <!doctype html>
        <html lang="en">
        <head><meta charset="utf-8"><title>Somebody else's site</title></head>
        <body>
            <h1>A page that is not ours</h1>
            {$snippet}
        </body>
        </html>
        HTML);
}

/**
 * The booking luna holds, as the row stands.
 *
 * @return array<string, mixed>
 */
function manageBooking(): array
{
    $row = DB::selectOne('select uuid, status, hold_class, hold_expires_at from tenant_luna.bookings order by id limit 1');

    assert($row instanceof stdClass);

    return ['uuid' => $row->uuid, 'status' => $row->status, 'hold_class' => $row->hold_class, 'hold_expires_at' => $row->hold_expires_at];
}

/**
 * @return list<bool>
 */
function manageBlocks(): array
{
    /** @var list<bool> $blocks */
    $blocks = DB::table('tenant_luna.booking_items')->orderBy('sort')->pluck('blocks')->all();

    return $blocks;
}

/**
 * @return list<string>
 */
function manageTrail(): array
{
    /** @var list<string> $actions */
    $actions = DB::table('tenant_luna.audit_log')->orderBy('id')->pluck('action')->all();

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

it('books on the embed at a foreign origin, then cancels that booking from the hosted manage page, taking the token off the hold answer on the wire', function () {
    $monday = Flow::day('Europe/Athens', 1);
    $page = $this->visit(manageEmbedPage());

    $page->assertScript('location.origin', ForeignSite::current()->origin)
        ->assertScript(FlowScripts::SETTLED, true)
        ->assertScript(MANAGE_RECORDER, true)
        ->click('Haircut')
        ->click(Flow::said('estimate.continue'))
        ->assertScript(FlowScripts::STEP, 'staff')
        ->click(Flow::said('staff.any'))
        ->click(Flow::said('estimate.continue'));

    Flow::weekOf($page, $monday)
        ->click(sprintf('.kr-booking__day[value="%s"]', $monday->format('Y-m-d')))
        ->assertScript(FlowScripts::SETTLED, true)
        ->click(sprintf('.kr-booking__slot[value="%s"]', Flow::start($monday, 9)))
        ->assertScript(FlowScripts::STEP, 'details')
        ->type('customer_name', 'Casey Customer')
        ->type('customer_email', 'casey@example.com')
        ->click(Flow::said('estimate.continue'))
        ->assertScript(FlowScripts::stepReached('review'), true)
        ->click(Flow::said('review.confirm'))
        ->assertScript(FlowScripts::stepReached('done'), true)
        ->assertSee(Flow::said(Flow::CONFIRMED))
        ->assertNoJavaScriptErrors();

    $token = $page->script(MANAGE_TOKEN_SCRIPT);
    $booking = manageBooking();

    expect($token)->toBeString()->toHaveLength(64)
        ->and($booking['status'])->toBe('confirmed')
        ->and(manageTrail())->toBe(['held', 'confirmed']);

    assert(is_string($token) && is_string($booking['uuid']));

    $link = app(TenantUrls::class)->manageLink(Slug::of('luna'), $booking['uuid'], $token);

    $this->visit($link)
        ->assertScript(FlowScripts::SETTLED, true)
        ->assertScript(FlowScripts::VIEW, 'ready')
        ->assertSeeIn('.kr-booking__summary-service', 'Haircut')
        ->assertSeeIn('.kr-booking__total', '35.00')
        ->assertSeeIn('.kr-booking__customer', 'casey@example.com')
        ->assertDontSee(Flow::said('manage.statuses.cancelled'))
        ->assertScript(FlowScripts::BUTTONS, Flow::buttons('manage.cancel', 'move.reschedule'))
        ->click(Flow::said('manage.cancel'))
        ->assertSee(Flow::said('manage.question'))
        ->click(YES_CONTROL)
        ->assertScript(FlowScripts::saidPolitely(Flow::said('manage.statuses.cancelled')), true)
        ->assertScript(FlowScripts::BUTTONS, '')
        ->assertScript(FlowScripts::VIEW, 'ready')
        ->assertNoJavaScriptErrors();

    expect(manageBooking())->toBe(['uuid' => $booking['uuid'], 'status' => 'cancelled', 'hold_class' => null, 'hold_expires_at' => null])
        ->and(manageBlocks())->toBe([false])
        ->and(manageTrail())->toBe(['held', 'confirmed', 'cancelled']);
});

it('shows the neutral panel with no buttons for a link whose token is wrong', function () {
    $monday = Flow::day('Europe/Athens', 2);
    $page = $this->visit(manageEmbedPage());

    $page->assertScript(FlowScripts::SETTLED, true)
        ->assertScript(MANAGE_RECORDER, true)
        ->click('Haircut')
        ->click(Flow::said('estimate.continue'))
        ->click(Flow::said('staff.any'))
        ->click(Flow::said('estimate.continue'));

    Flow::weekOf($page, $monday)
        ->click(sprintf('.kr-booking__day[value="%s"]', $monday->format('Y-m-d')))
        ->assertScript(FlowScripts::SETTLED, true)
        ->click(sprintf('.kr-booking__slot[value="%s"]', Flow::start($monday, 10)))
        ->type('customer_name', 'Robin Regular')
        ->type('customer_email', 'robin@example.com')
        ->click(Flow::said('estimate.continue'))
        ->assertScript(FlowScripts::stepReached('review'), true)
        ->click(Flow::said('review.confirm'))
        ->assertScript(FlowScripts::stepReached('done'), true);

    $booking = manageBooking();

    assert(is_string($booking['uuid']));

    $this->visit(app(TenantUrls::class)->manageLink(Slug::of('luna'), $booking['uuid'], str_repeat('0', 64)))
        ->assertScript(FlowScripts::SETTLED, true)
        ->assertScript(FlowScripts::VIEW, 'neutral')
        ->assertSee('This link is no longer valid.')
        ->assertScript(FlowScripts::BUTTONS, '')
        ->assertDontSee('Haircut')
        ->assertDontSee('casey@example.com')
        ->assertNoJavaScriptErrors();

    expect(manageBooking()['status'])->toBe('confirmed')
        ->and(manageTrail())->toBe(['held', 'confirmed']);
});
