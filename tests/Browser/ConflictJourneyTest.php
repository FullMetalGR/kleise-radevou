<?php

use App\Http\ErrorEnvelope;
use App\Tenancy\Slug;
use App\Tenancy\TenantUrls;
use Database\Seeders\DemoTenantsSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Browser\Support\Flow;
use Tests\Browser\Support\FlowScripts;
use Tests\Browser\Support\ForeignSite;

/*
 * Two paths on the embed at an origin that is not ours: the judge-once preselection check, and
 * the hold 409 against the real server, a 409 keeping the typed details.
 *
 * The conflicting hold is planted from the test through the application's own HTTP client on
 * the tenant host, after the customer has typed their details and before they press Continue.
 * That is somebody else taking the slot in the one moment it matters, with no second browser.
 * The customer pinned the member, so the server finds nobody free at that start and answers the
 * 409 whose code is unavailable rather than overlap; the app renders either sentence verbatim and
 * branches on neither (4.2), and the one it shows is asserted to be the one the wire carried.
 *
 * The hold requests are read off the page's own fetch, as ManageJourneyTest reads the token:
 * the recorder keeps each body as sent and each answer's status and detail, so the wire is what
 * is asserted and not the app's idea of it.
 *
 * The bundle is the one npm run build wrote, which is why the build gate precedes this one.
 */

/**
 * Keeps every hold request the page sends, its body as sent and its answer's status and detail.
 * The response is cloned, so the app reads its body exactly as it would have.
 */
const CONFLICT_RECORDER = <<<'JS'
    (() => {
        const original = window.fetch;
        window.__krHolds = [];
        window.fetch = (input, init) => {
            if (!String(input).includes('/api/v1/holds')) {
                return original(input, init);
            }

            const entry = { body: String(init && init.body), status: null, detail: null };
            window.__krHolds.push(entry);

            return original(input, init).then((response) => {
                entry.status = response.status;
                response.clone().json().then((body) => {
                    entry.detail = body && body.data && typeof body.data.detail === 'string' ? body.data.detail : null;
                }, () => undefined);

                return response;
            });
        };

        return true;
    })()
    JS;

const CONFLICT_HOLDS = 'JSON.stringify(window.__krHolds)';

/**
 * Whether anything on the page is an alert. The 409 path must have none anywhere.
 */
const CONFLICT_NO_ALERT = "document.querySelectorAll('[role=\"alert\"]').length === 0";

/**
 * Whether the frame offers no Back, the crumb's round control, which is what a bare mount's
 * service step offers.
 */
const CONFLICT_NO_BACK = "document.querySelector('.kr-booking__frame .kr-booking__back') === null";

/**
 * Everything every notice and live region on the page says, which a void must leave empty.
 */
const CONFLICT_SAID = "Array.from(document.querySelectorAll('.kr-booking__notice, [role=\"status\"], [role=\"alert\"]')).map((region) => region.textContent).join('')";

/**
 * Resolves true once the slot grid holds some times and none of them is the given start, which
 * is the refetch the 409 triggered having landed; false after five seconds.
 */
const CONFLICT_GRID_WITHOUT = <<<'JS'
    new Promise((resolve) => {
        const started = Date.now();
        const check = () => {
            const values = Array.from(document.querySelectorAll('.kr-booking__slot')).map((slot) => slot.value);
            if (values.length > 0 && !values.includes('%s')) {
                resolve(true);
            } else if (Date.now() - started > 5000) {
                resolve(false);
            } else {
                setTimeout(check, 25);
            }
        };
        check();
    })
    JS;

/**
 * A page of somebody else's holding luna's snippet, its mount element carrying the given
 * attributes as a tenant would write them into their own template.
 */
function conflictEmbedPage(string $name, string $attributes = ''): string
{
    $snippet = str_replace(
        '<div class="kr-booking">',
        sprintf('<div class="kr-booking"%s>', $attributes),
        app(TenantUrls::class)->embedSnippet(Slug::of('luna')),
    );

    return ForeignSite::current()->page($name, <<<HTML
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
 * The id of the luna row of the given table carrying the given name.
 */
function conflictIdOf(string $table, string $name): int
{
    $id = DB::table('tenant_luna.'.$table)->where('name', $name)->value('id');

    assert(is_int($id));

    return $id;
}

/**
 * The hold requests the page sent, as the recorder kept them.
 *
 * @return list<array{body: array<string, mixed>, status: int, detail: string|null}>
 */
function conflictHolds(mixed $recorded): array
{
    assert(is_string($recorded));

    /** @var list<array{body: string, status: int, detail: string|null}> $holds */
    $holds = json_decode($recorded, true, flags: JSON_THROW_ON_ERROR);

    return array_map(function (array $hold): array {
        /** @var array<string, mixed> $body */
        $body = json_decode($hold['body'], true, flags: JSON_THROW_ON_ERROR);

        return ['body' => $body, 'status' => $hold['status'], 'detail' => $hold['detail']];
    }, $holds);
}

beforeAll(fn () => ForeignSite::start());
afterAll(fn () => ForeignSite::stop());

beforeEach(function () {
    expect(is_file(public_path('build/embed.js')))->toBeTrue('public/build/embed.js is missing; run npm run build before the browser gate.');

    dropDemoSchemas();
    $this->seed(DemoTenantsSeeder::class);
});

afterEach(fn () => dropDemoSchemas());

it('voids a data-service naming an id luna never had: the picker with no sentence, then Back from staff to service', function () {
    $this->visit(conflictEmbedPage('void.html', ' data-service="987654"'))
        ->assertScript('location.origin', ForeignSite::current()->origin)
        ->assertScript(FlowScripts::SETTLED, true)
        ->assertScript(FlowScripts::stepReached('service'), true)
        ->assertScript(FlowScripts::SERVICE_NAMES, 'Haircut|Beard trim|Colour|Bridal trial')
        ->assertCount('.kr-booking__estimate-item', 0)
        ->assertScript(CONFLICT_SAID, '')
        ->assertScript(CONFLICT_NO_BACK, true)
        ->click('Haircut')
        ->click(Flow::said('estimate.continue'))
        ->assertScript(FlowScripts::stepReached('staff'), true)
        ->click('.kr-booking__back')
        ->assertScript(FlowScripts::stepReached('service'), true)
        ->assertSeeIn('.kr-booking__estimate-name', 'Haircut')
        ->assertScript(CONFLICT_SAID, '')
        ->assertNoJavaScriptErrors();
});

it('keeps the typed details through a 409 on the real server: the sentence politely, a refreshed grid, and a second hold differing only in start', function () {
    $monday = Flow::day('Europe/Athens', 1);
    $taken = Flow::start($monday, 9);
    $other = Flow::start($monday, 10);
    $page = $this->visit(conflictEmbedPage('conflict.html'));

    $page->assertScript(FlowScripts::SETTLED, true)
        ->assertScript(CONFLICT_RECORDER, true)
        ->click('Haircut')
        ->click(Flow::said('estimate.continue'))
        ->assertScript(FlowScripts::stepReached('staff'), true)
        ->click('Elena Papadaki')
        ->click(Flow::said('estimate.continue'));

    Flow::weekOf($page, $monday)
        ->click(sprintf('.kr-booking__day[value="%s"]', $monday->format('Y-m-d')))
        ->assertScript(FlowScripts::SETTLED, true)
        ->click(sprintf('.kr-booking__slot[value="%s"]', $taken))
        ->assertScript(FlowScripts::stepReached('details'), true)
        ->type('customer_name', 'Casey Customer')
        ->type('customer_email', 'casey@example.com');

    // Somebody else takes the same start on the same member, on the tenant host, before the
    // customer presses the button that continues (estimate.continue).
    $this->postJson(app(TenantUrls::class)->origin(Slug::of('luna')).'/api/v1/holds', [
        'customer_name' => 'Somebody Else',
        'customer_email' => 'somebody@example.com',
        'items' => [['service_id' => conflictIdOf('services', 'Haircut'), 'staff_id' => conflictIdOf('staff', 'Elena Papadaki')]],
        'start' => $taken,
    ])->assertCreated();

    $page->click(Flow::said('estimate.continue'))
        ->assertScript(FlowScripts::stepReached('when'), true)
        ->assertScript(FlowScripts::saidPolitely(apiSentence(ErrorEnvelope::UNAVAILABLE_DETAIL)), true)
        ->assertScript(CONFLICT_NO_ALERT, true)
        ->assertScript(FlowScripts::FOCUSED_FRAME, true)
        ->assertScript(sprintf(CONFLICT_GRID_WITHOUT, $taken), true)
        ->assertScript(FlowScripts::SETTLED, true)
        ->assertScript(FlowScripts::PRESSED_DAYS, $monday->format('Y-m-d'))
        ->assertScript(CONFLICT_NO_ALERT, true)
        ->click(sprintf('.kr-booking__slot[value="%s"]', $other))
        ->assertScript(FlowScripts::stepReached('details'), true)
        ->assertValue('customer_name', 'Casey Customer')
        ->assertValue('customer_email', 'casey@example.com')
        ->click(Flow::said('estimate.continue'))
        ->assertScript(FlowScripts::stepReached('review'), true)
        ->assertScript(CONFLICT_NO_ALERT, true)
        ->assertNoJavaScriptErrors();

    $holds = conflictHolds($page->script(CONFLICT_HOLDS));

    expect($holds)->toHaveCount(2)
        ->and(array_column($holds, 'status'))->toBe([409, 201])
        ->and($holds[0]['detail'] ?? null)->toBe(apiSentence(ErrorEnvelope::UNAVAILABLE_DETAIL))
        ->and($holds[0]['body']['start'] ?? null)->toBe($taken)
        ->and($holds[1]['body']['start'] ?? null)->toBe($other);

    $first = $holds[0]['body'] ?? [];
    $second = $holds[1]['body'] ?? [];
    unset($first['start'], $second['start']);

    expect($second)->toBe($first)
        ->and($first)->toBe([
            'customer_name' => 'Casey Customer',
            'customer_email' => 'casey@example.com',
            'items' => [['service_id' => conflictIdOf('services', 'Haircut'), 'staff_id' => conflictIdOf('staff', 'Elena Papadaki')]],
        ]);
});

it('refuses the hold of an address the business blocked with the one sentence, politely, on the details step, the typed details kept and no booking written', function () {
    DB::table('tenant_luna.customer_profiles')->insert([
        'email' => 'casey@example.com', 'blocked_at' => DB::raw('now()'), 'blocked_reason' => 'Three no-shows',
        'created_at' => DB::raw('now()'), 'updated_at' => DB::raw('now()'),
    ]);
    $monday = Flow::day('Europe/Athens', 1);
    $blocked = Flow::said('details.blocked');
    $page = $this->visit(conflictEmbedPage('blocked.html'));

    $page->assertScript(FlowScripts::SETTLED, true)
        ->click('Haircut')
        ->click(Flow::said('estimate.continue'))
        ->assertScript(FlowScripts::stepReached('staff'), true)
        ->click('Elena Papadaki')
        ->click(Flow::said('estimate.continue'));

    Flow::weekOf($page, $monday)
        ->click(sprintf('.kr-booking__day[value="%s"]', $monday->format('Y-m-d')))
        ->assertScript(FlowScripts::SETTLED, true)
        ->click(sprintf('.kr-booking__slot[value="%s"]', Flow::start($monday, 9)))
        ->assertScript(FlowScripts::stepReached('details'), true)
        ->type('customer_name', 'Casey Customer')
        ->type('customer_email', 'Casey@Example.com')
        ->click(Flow::said('estimate.continue'))
        // The page says the client's own sentence for a blocked address, in the visitor's language,
        // not the envelope's (lang booking.details.blocked).
        ->assertScript(FlowScripts::saidPolitely($blocked), true)
        ->assertScript(FlowScripts::stepReached('details'), true)
        ->assertValue('customer_email', 'Casey@Example.com')
        ->assertDontSee('Three no-shows')
        ->assertNoJavaScriptErrors();

    expect(DB::table('tenant_luna.bookings')->count())->toBe(0);
});
