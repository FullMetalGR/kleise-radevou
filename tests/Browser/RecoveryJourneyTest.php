<?php

use App\Http\ErrorEnvelope;
use App\Tenancy\Slug;
use App\Tenancy\TenantUrls;
use Database\Seeders\DemoTenantsSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Browser\Support\Flow;
use Tests\Browser\Support\FlowScripts;

/*
 * A confirm refusal whose re-read finds a settled booking, on the hosted page: the customer's
 * first confirm committed and its answer was lost.
 *
 * A browser cannot lose an answer on cue, so the test stands in for the lost one: it confirms the
 * booking from the server, through the application's own HTTP client with the token the hold
 * answered on the wire, before the customer presses Confirm. The customer's press is then a
 * second confirm of a booking already confirmed, which the server refuses 409; the page re-reads
 * the booking, finds it confirmed, and says so. Showing the refusal would be the lie 4.4 names.
 *
 * The token is read the way ManageJourneyTest reads it: the page's own fetch is wrapped before
 * the details submit and the hold's answer is kept as it arrived. The app hands the token to
 * nothing, so the wire is the only copy on this machine.
 *
 * The bundle is the one npm run build wrote, which is why the build gate precedes this one.
 */

/**
 * The manage token the page's one hold answered with, off the wire.
 */
function recoveryToken(mixed $answers): string
{
    assert(is_string($answers));

    $kept = json_decode($answers, true, flags: JSON_THROW_ON_ERROR);

    assert(is_array($kept) && is_string($kept[0] ?? null));

    $held = json_decode($kept[0], true, flags: JSON_THROW_ON_ERROR);

    assert(is_array($held) && is_string($held['manage_token'] ?? null));

    return $held['manage_token'];
}

/**
 * @return list<string>
 */
function recoveryTrail(): array
{
    /** @var list<string> $actions */
    $actions = DB::table('tenant_luna.audit_log')->orderBy('id')->pluck('action')->all();

    return $actions;
}

beforeEach(function () {
    expect(is_file(public_path('build/embed.js')))->toBeTrue('public/build/embed.js is missing; run npm run build before the browser gate.');

    dropDemoSchemas();
    $this->seed(DemoTenantsSeeder::class);
});

afterEach(fn () => dropDemoSchemas());

it('recovers a refused confirm whose booking the server already confirmed: done says so, with no alert and the refusal nowhere, and the trail is held then confirmed once', function () {
    $wednesday = Flow::day('Europe/Athens', 3);

    $page = $this->visit(hostedPage('luna'))
        ->assertScript(FlowScripts::SETTLED, true)
        ->assertScript(FlowScripts::RECORD_HOLD_ANSWERS, true)
        ->click('Haircut')
        ->click(Flow::said('estimate.continue'))
        ->assertScript(FlowScripts::stepReached('staff'), true)
        ->click(Flow::said('staff.any'))
        ->click(Flow::said('estimate.continue'))
        ->assertScript(FlowScripts::stepReached('when'), true);

    Flow::weekOf($page, $wednesday)
        ->click(sprintf('.kr-booking__day[value="%s"]', $wednesday->format('Y-m-d')))
        ->assertScript(FlowScripts::SETTLED, true)
        ->click(sprintf('.kr-booking__slot[value="%s"]', Flow::start($wednesday, 11)))
        ->assertScript(FlowScripts::stepReached('details'), true)
        ->type('customer_name', 'Casey Customer')
        ->type('customer_email', 'casey@example.com')
        ->click(Flow::said('estimate.continue'))
        ->assertScript(FlowScripts::stepReached('review'), true)
        ->assertSee(Flow::said('review.confirm'));

    $token = recoveryToken($page->script(FlowScripts::HOLD_ANSWERS));
    $uuid = DB::table('tenant_luna.bookings')->value('uuid');

    assert(is_string($uuid));

    // The first confirm, whose answer the customer never saw, sent on the tenant host.
    $this->postJson(app(TenantUrls::class)->origin(Slug::of('luna')).'/api/v1/bookings/'.$uuid.'/confirm', ['token' => $token])
        ->assertOk()
        ->assertJsonPath('status', 'confirmed');

    $page->click(Flow::said('review.confirm'))
        ->assertScript(FlowScripts::stepReached('done'), true)
        ->assertScript(FlowScripts::saidPolitely(Flow::said(Flow::CONFIRMED)), true)
        ->assertScript(FlowScripts::FOCUSED_FRAME, true)
        ->assertScript(FlowScripts::NO_ALERT, true)
        ->assertDontSee(apiSentence(ErrorEnvelope::NOT_CONFIRMABLE_DETAIL))
        ->assertScript(FlowScripts::TOKEN_IN_MARKUP, false)
        ->assertNoJavaScriptErrors();

    // The refusal was re-read once, with the token in the query string; the hold was never
    // released, the confirm having settled it.
    $tokenUrls = $page->script(FlowScripts::TOKEN_URLS);

    assert(is_string($tokenUrls));

    expect(json_decode($tokenUrls, true, flags: JSON_THROW_ON_ERROR))->toBe(['/api/v1/bookings/'.$uuid])
        ->and(DB::table('tenant_luna.bookings')->count())->toBe(1)
        ->and(DB::table('tenant_luna.bookings')->value('status'))->toBe('confirmed')
        ->and(recoveryTrail())->toBe(['held', 'confirmed']);
});
