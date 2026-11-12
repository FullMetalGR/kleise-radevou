<?php

use App\Http\ErrorEnvelope;
use Database\Seeders\DemoTenantsSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Browser\Support\Flow;
use Tests\Browser\Support\FlowScripts;

/*
 * An expired hold, on the hosted page, along the path a real browser can walk: the server's clock
 * closes the hold, the confirm answers 410, and the button that picks another time (review.restart)
 * is the way out. The countdown's own zero is proven under fake timers in the component tests,
 * because reaching it here would mean waiting the TTL out.
 *
 * The hold's window is closed by the server rather than by the test's clock: hold_expires_at is
 * written back with the database's now(), which is the clock the confirm's guard reads, so the
 * 410 is the server's own answer and not an agreement the test arranged between two clocks.
 *
 * The bundle is the one npm run build wrote, which is why the build gate precedes this one.
 */

/**
 * Closes every live hold of luna's as of the database's own clock.
 */
function expiredCloseHolds(): void
{
    DB::update("update tenant_luna.bookings set hold_expires_at = now() - interval '1 minute' where status = 'pending'");
}

beforeEach(function () {
    expect(is_file(public_path('build/embed.js')))->toBeTrue('public/build/embed.js is missing; run npm run build before the browser gate.');

    dropDemoSchemas();
    $this->seed(DemoTenantsSeeder::class);
});

afterEach(fn () => dropDemoSchemas());

it('walks a hold the server closed: the 410 said politely with the confirm button disabled, the restart back to when, and a fresh hold with a fresh countdown', function () {
    $monday = Flow::day('Europe/Athens', 1);
    $first = Flow::start($monday, 9);
    $second = Flow::start($monday, 10);

    $page = $this->visit(hostedPage('luna'))
        ->assertScript(FlowScripts::SETTLED, true)
        ->click('Haircut')
        ->click(Flow::said('estimate.continue'))
        ->assertScript(FlowScripts::stepReached('staff'), true)
        ->click(Flow::said('staff.any'))
        ->click(Flow::said('estimate.continue'))
        ->assertScript(FlowScripts::stepReached('when'), true);

    Flow::weekOf($page, $monday)
        ->click(sprintf('.kr-booking__day[value="%s"]', $monday->format('Y-m-d')))
        ->assertScript(FlowScripts::SETTLED, true)
        ->click(sprintf('.kr-booking__slot[value="%s"]', $first))
        ->assertScript(FlowScripts::stepReached('details'), true)
        ->type('customer_name', 'Casey Customer')
        ->type('customer_email', 'casey@example.com')
        ->click(Flow::said('estimate.continue'))
        ->assertScript(FlowScripts::stepReached('review'), true)
        ->assertScript(FlowScripts::COUNTDOWN_FRESH, true)
        ->assertDontSee(Flow::said('review.restart'));

    expiredCloseHolds();

    $page->click(Flow::said('review.confirm'))
        ->assertScript(FlowScripts::saidPolitely(apiSentence(ErrorEnvelope::HOLD_EXPIRED_DETAIL)), true)
        ->assertScript(FlowScripts::stepReached('review'), true)
        ->assertScript(FlowScripts::CONFIRM_DISABLED, true)
        ->assertScript(FlowScripts::NO_ALERT, true)
        ->assertSee(Flow::said('review.restart'))
        ->click(Flow::said('review.restart'))
        ->assertScript(FlowScripts::stepReached('when'), true)
        ->assertScript(FlowScripts::FOCUSED_FRAME, true)
        ->assertScript(FlowScripts::dayRendered($monday), true)
        ->assertScript(FlowScripts::SETTLED, true)
        ->assertScript(FlowScripts::PRESSED_DAYS, $monday->format('Y-m-d'))
        ->assertScript(FlowScripts::NO_ALERT, true)
        ->assertDontSee(apiSentence(ErrorEnvelope::HOLD_EXPIRED_DETAIL))
        ->click(sprintf('.kr-booking__slot[value="%s"]', $second))
        ->assertScript(FlowScripts::stepReached('details'), true)
        ->assertValue('customer_name', 'Casey Customer')
        ->assertValue('customer_email', 'casey@example.com')
        ->click(Flow::said('estimate.continue'))
        ->assertScript(FlowScripts::stepReached('review'), true)
        ->assertScript(FlowScripts::COUNTDOWN_FRESH, true)
        ->assertScript(FlowScripts::CONFIRM_DISABLED, false)
        ->assertDontSee(Flow::said('review.restart'))
        ->assertScript(FlowScripts::NO_ALERT, true)
        ->assertScript(FlowScripts::TOKEN_IN_MARKUP, false)
        ->assertNoJavaScriptErrors();

    // The 410 was re-read once before it was shown, and the first hold's own countdown still
    // read time left when the restart was pressed, so its release went: the two URLs
    // carrying the token, each as its query parameter.
    $released = $page->script(FlowScripts::TOKEN_URLS);

    assert(is_string($released));

    $uuid = DB::table('tenant_luna.bookings')->orderBy('id')->value('uuid');

    assert(is_string($uuid));

    expect(json_decode($released, true, flags: JSON_THROW_ON_ERROR))->toBe(['/api/v1/bookings/'.$uuid, '/api/v1/holds/'.$uuid])
        ->and(DB::table('tenant_luna.bookings')->where('status', 'pending')->whereRaw('hold_expires_at > now()')->count())->toBe(1);
});
