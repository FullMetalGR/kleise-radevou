<?php

use App\Tenancy\Slug;
use App\Tenancy\TenantUrls;
use Database\Seeders\DemoTenantsSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Browser\BrowserTestCase;
use Tests\Browser\Support\Flow;
use Tests\Browser\Support\FlowScripts;

/*
 * The move from the customer's side, on the hosted manage page where the magic link lands.
 *
 * The booking is taken and confirmed over the wire before the page opens, the way the booking
 * app takes one, because what is under test is the dialog and not the flow: Haircut with a
 * named member at 09:00 on a Monday. Haircut is forty-five minutes, so 09:30 lies inside the
 * booking's own range and the public read, which counts the booking as busy, could never offer
 * it. The per-booking read leaves the booking out, so the dialog offers it, and the move takes
 * it. That is the reason the per-booking read exists, proven where the customer stands.
 *
 * The bundle is the one npm run build wrote, which is why the build gate precedes this one.
 */

function rescheduleId(string $table, string $name): int
{
    $id = DB::table('tenant_luna.'.$table)->where('name', $name)->value('id');

    assert(is_int($id));

    return $id;
}

/**
 * @return list<string>
 */
function rescheduleTrail(): array
{
    /** @var list<string> $actions */
    $actions = DB::table('tenant_luna.audit_log')->orderBy('id')->pluck('action')->all();

    return $actions;
}

/**
 * The booking's item starts as the rows hold them, spelled by the database in UTC the way the
 * wire spells a start, so no instant is parsed on this side.
 *
 * @return list<string>
 */
function rescheduleStarts(): array
{
    /** @var list<string> $starts */
    $starts = DB::table('tenant_luna.booking_items')
        ->selectRaw("to_char(start_utc at time zone 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS\"+00:00\"') as start")
        ->orderBy('sort')
        ->pluck('start')
        ->all();

    return $starts;
}

/**
 * A Haircut with a named member at 09:00 on the given day, held and confirmed over the wire, and
 * the magic link that manages it.
 *
 * @return array{uuid: string, link: string}
 */
function rescheduleConfirmed(BrowserTestCase $test, DateTimeImmutable $day): array
{
    $origin = app(TenantUrls::class)->origin(Slug::of('luna'));

    $held = $test->postJson($origin.'/api/v1/holds', [
        'customer_name' => 'Casey Customer',
        'customer_email' => 'casey@example.com',
        'items' => [['service_id' => rescheduleId('services', 'Haircut'), 'staff_id' => rescheduleId('staff', 'Elena Papadaki')]],
        'start' => Flow::start($day, 9),
    ])->assertCreated()->json();

    assert(is_array($held) && is_string($held['uuid']) && is_string($held['manage_token']));

    $test->postJson($origin.'/api/v1/bookings/'.$held['uuid'].'/confirm', ['token' => $held['manage_token']])
        ->assertOk()
        ->assertJsonPath('status', 'confirmed');

    return ['uuid' => $held['uuid'], 'link' => app(TenantUrls::class)->manageLink(Slug::of('luna'), $held['uuid'], $held['manage_token'])];
}

beforeEach(function () {
    expect(is_file(public_path('build/embed.js')))->toBeTrue('public/build/embed.js is missing; run npm run build before the browser gate.');

    dropDemoSchemas();
    $this->seed(DemoTenantsSeeder::class);
});

afterEach(fn () => dropDemoSchemas());

it('moves a confirmed Monday 09:00 Haircut to 09:30 from the manage link, a start only the per-booking read can offer', function () {
    $monday = Flow::day('Europe/Athens', 1);
    $held = rescheduleConfirmed($this, $monday);

    $page = $this->visit($held['link']);

    $page
        ->assertScript(FlowScripts::SETTLED, true)
        ->assertScript(FlowScripts::VIEW, 'ready')
        ->assertScript(FlowScripts::summaryWhen($monday, '9:00'), true)
        ->assertScript(FlowScripts::BUTTONS, Flow::buttons('manage.cancel', 'move.reschedule'))
        ->click(Flow::said('move.reschedule'))
        ->assertScript(FlowScripts::DIALOG_OPEN, true)
        ->assertScript(FlowScripts::FOCUSED_DIALOG, true)
        ->assertSee(Flow::said('move.pick'));

    Flow::weekOf($page, $monday)
        ->click(sprintf('.kr-booking__day[value="%s"]', $monday->format('Y-m-d')))
        ->assertScript(FlowScripts::SETTLED, true)
        ->click(Flow::said('when.period.afternoon'))
        ->assertScript(sprintf("document.querySelector('.kr-booking__slot[value=\"%s\"]') === null", Flow::start($monday, 9, 30)), true)
        ->assertScript(sprintf("document.querySelector('.kr-booking__slot[value=\"%s\"]') !== null", Flow::start($monday, 14)), true)
        ->click(Flow::said('when.period.morning'))
        ->assertScript(sprintf("document.querySelector('.kr-booking__slot[value=\"%s\"]') !== null", Flow::start($monday, 9, 30)), true)
        ->click(sprintf('.kr-booking__slot[value="%s"]', Flow::start($monday, 9, 30)))
        ->assertScript(FlowScripts::saidPolitely(Flow::said('move.moved')), true)
        ->assertScript(FlowScripts::DIALOG_OPEN, false)
        ->assertScript(FlowScripts::summaryWhen($monday, '9:30'), true)
        ->assertScript(FlowScripts::NO_ALERT, true)
        ->assertNoJavaScriptErrors();

    // The token reached the wire in a query string on the two reads alone, the GET transport;
    // the move carried it in its body, so no URL of the POST names it.
    $tokenUrls = $page->script(FlowScripts::TOKEN_URLS);

    assert(is_string($tokenUrls));

    /** @var list<string> $urls */
    $urls = json_decode($tokenUrls, true, flags: JSON_THROW_ON_ERROR);

    $paths = array_values(array_unique($urls));

    expect($paths)->toBe(['/api/v1/bookings/'.$held['uuid'], '/api/v1/bookings/'.$held['uuid'].'/availability']);

    expect(rescheduleStarts())->toBe([Flow::start($monday, 9, 30)])
        ->and(DB::table('tenant_luna.bookings')->value('status'))->toBe('confirmed')
        ->and(rescheduleTrail())->toBe(['held', 'confirmed', 'rescheduled']);
});

it('closes the dialog on Escape and gives the focus back to the Reschedule button that opened it', function () {
    $held = rescheduleConfirmed($this, Flow::day('Europe/Athens', 1));

    $this->visit($held['link'])
        ->assertScript(FlowScripts::SETTLED, true)
        ->assertScript(FlowScripts::VIEW, 'ready')
        ->click(Flow::said('move.reschedule'))
        ->assertScript(FlowScripts::DIALOG_OPEN, true)
        ->assertScript(FlowScripts::FOCUSED_DIALOG, true)
        ->keys('.kr-booking__dialog', 'Escape')
        ->assertScript(FlowScripts::DIALOG_OPEN, false)
        ->assertScript(FlowScripts::FOCUSED_TRIGGER, true)
        ->assertScript(FlowScripts::NO_ALERT, true)
        ->assertNoJavaScriptErrors();
});
