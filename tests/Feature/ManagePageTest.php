<?php

use App\Http\BookingPage;
use App\Http\CustomerNotice;
use App\Http\PayHopAnswer;
use App\Tenancy\Slug;
use App\Tenancy\Tenant;
use App\Tenancy\TenantStatus;
use App\Tenancy\TenantUrls;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

pest()->use(RefreshDatabase::class);

/*
 * Where the magic link lands, on the wire: the booking page with the mount element in manage
 * mode, and two headers the URL's credential earns it.
 *
 * The page is read as a document, as the booking page's own tests are, because the mount
 * contract is about attributes on one element and a substring check would pass a page that put
 * them anywhere.
 *
 * Nothing here provisions a booking. The page reads none: it renders what the link named and
 * the API decides whether that names anything, which is the whole point of the route.
 */

const MANAGE_UUID = '7b7f0e5a-0000-4000-8000-000000000001';

const MANAGE_TOKEN = 'a3f1c9de4b2708651fd0ac9e3b77140529d6e8ab41c0f5327896bd4e1a0c7f62';

function manageLink(string $slug, ?string $token = MANAGE_TOKEN, string $uuid = MANAGE_UUID): string
{
    return app(TenantUrls::class)->manageLink(Slug::of($slug), $uuid, $token);
}

it('renders the booking page with the mount in manage mode carrying the uuid and the token', function () {
    provisionTenant('acme', ['name' => 'Acme Salon']);

    $document = documentOf($this->get(manageLink('acme'))->assertOk());
    $mount = mountOf($document);

    expect($mount->getAttribute('data-mode'))->toBe('manage')
        ->and($mount->getAttribute('data-uuid'))->toBe(MANAGE_UUID)
        ->and($mount->getAttribute('data-token'))->toBe(MANAGE_TOKEN)
        ->and($document->getElementsByTagName('title')->item(0)?->textContent)->toBe('Acme Salon');
});

it('inlines the boot config and the theme and loads the one bundle, exactly as the booking page does', function () {
    provisionTenant('acme', ['name' => 'Acme Salon', 'timezone' => 'Europe/Athens', 'currency' => 'EUR']);
    settingsOf('acme')->update(['theme' => 'violet']);

    $document = documentOf($this->get(manageLink('acme'))->assertOk());
    $scripts = (new DOMXPath($document))->query('//script[@src]');

    assert($scripts instanceof DOMNodeList);
    $script = $scripts->item(0);
    assert($script instanceof DOMElement);

    expect(configOf($document))->toBe([
        'tenant_name' => 'Acme Salon',
        'timezone' => 'Europe/Athens',
        'currency' => 'EUR',
        'granularity_min' => 5,
        'important_info' => null,
        'default_language' => 'en',
        'language' => 'en',
    ])
        ->and(mountOf($document)->getAttribute('data-theme'))->toBe('violet')
        ->and($script->getAttribute('src'))->toBe(app(TenantUrls::class)->embedScript(Slug::of('acme')));
});

it('renders without a token, with the attribute absent rather than empty', function (?string $token) {
    provisionTenant('acme');

    $mount = mountOf(documentOf($this->get(manageLink('acme', $token))->assertOk()));

    expect($mount->hasAttribute('data-token'))->toBeFalse()
        ->and($mount->getAttribute('data-mode'))->toBe('manage')
        ->and($mount->getAttribute('data-uuid'))->toBe(MANAGE_UUID);
})->with([
    'no token parameter at all' => [null],
    'an empty token parameter' => [''],
    'a token parameter of spaces' => ['   '],
]);

it('answers no-store and no-referrer, because the URL carries a credential', function () {
    provisionTenant('acme');

    $response = $this->get(manageLink('acme'))->assertOk();

    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->get('Referrer-Policy'))->toBe('no-referrer');
});

it('carries no attribute a tenant name or a token could close the mount element with', function () {
    provisionTenant('acme', ['name' => 'Acme Salon']);

    $response = $this->get(manageLink('acme', '"><script>alert(1)</script>'))->assertOk();

    $response->assertDontSee('<script>alert(1)</script>', escape: false);

    expect(mountOf(documentOf($response))->getAttribute('data-token'))->toBe('"><script>alert(1)</script>');
});

it('verifies nothing: an identifier no booking has renders the same page', function () {
    provisionTenant('acme');

    $mount = mountOf(documentOf($this->get(manageLink('acme', MANAGE_TOKEN, 'not-a-uuid-at-all'))->assertOk()));

    expect($mount->getAttribute('data-uuid'))->toBe('not-a-uuid-at-all')
        ->and($mount->getAttribute('data-mode'))->toBe('manage');
});

it('is the URL TenantUrls spells, and that is the URL the route answers on', function () {
    provisionTenant('acme');

    $link = app(TenantUrls::class)->manageLink(Slug::of('acme'), MANAGE_UUID, MANAGE_TOKEN);

    expect($link)->toBe(tenantUrl('acme', '/manage/'.MANAGE_UUID.'?token='.MANAGE_TOKEN))
        ->and(Route::getRoutes()->getByName('manage')?->uri())->toBe('manage/{uuid}');

    $this->get($link)->assertOk();
});

it('leaves the booking page mount naming no mode and no credentials', function () {
    provisionTenant('acme');

    $mount = mountOf(documentOf($this->get(hostedPage('acme'))->assertOk()));

    expect($mount->hasAttribute('data-mode'))->toBeFalse()
        ->and($mount->hasAttribute('data-uuid'))->toBeFalse()
        ->and($mount->hasAttribute('data-token'))->toBeFalse();
});

it('serves a suspended tenant the static page, not this one', function () {
    schemaBehind(Tenant::factory()->create(['slug' => 'paused', 'name' => 'Paused Salon', 'status' => TenantStatus::Suspended]));

    $this->get(manageLink('paused'))
        ->assertStatus(503)
        ->assertDontSee('kr-booking')
        ->assertDontSee(MANAGE_TOKEN);
});

it('answers 404 at an unknown subdomain', function () {
    $this->get(manageLink('nobody'))->assertNotFound();
});

it('leaves the connection free of any search path change', function () {
    provisionTenant('acme');

    $this->get(manageLink('acme'))->assertOk();

    expect(DB::scalar("select current_setting('search_path')"))->toBe('public')
        ->and(DB::scalar('select current_schema()'))->toBe('public');
});

/**
 * The manage page's second JSON script, the sentence a redirect flashed, or null where the page
 * carries none.
 *
 * @return array<mixed>|null
 */
function manageNoticeOf(DOMDocument $document): ?array
{
    $script = $document->getElementById(BookingPage::NOTICE_ID);

    if ($script === null) {
        return null;
    }

    expect($script->tagName)->toBe('script')
        ->and($script->getAttribute('type'))->toBe('application/json');

    $notice = json_decode((string) $script->textContent, true, flags: JSON_THROW_ON_ERROR);

    assert(is_array($notice));

    return $notice;
}

it('inlines the sentence a redirect flashed beside the config, read from the key it was flashed as, in a JSON script of its own holding the sentence and nothing else, and no such script where nothing was flashed', function () {
    provisionTenant('acme', ['name' => 'Acme Salon']);
    // The flashed key's line, fabricated for the case so it carries what the script must escape;
    // the file is read first, so the line added replaces one line and not the whole file.
    $sentence = 'A sentence the server wrote, with a "quoted" <b>word</b> & more.';
    __('pages.noscript', [], 'en');
    app('translator')->addLines([PayHopAnswer::PAYMENT_ON_ITS_WAY => $sentence], 'en');

    $flashed = $this->withSession([CustomerNotice::KEY => ['kind' => 'info', 'message' => PayHopAnswer::PAYMENT_ON_ITS_WAY]])->get(manageLink('acme'))->assertOk();
    $document = documentOf($flashed);

    expect(manageNoticeOf($document))->toBe(['notice' => $sentence])
        ->and((string) $flashed->getContent())->not->toContain('<b>word</b>')
        ->and(mountOf($document)->getAttribute('data-token'))->toBe(MANAGE_TOKEN)
        ->and((string) $document->getElementById(BookingPage::NOTICE_ID)?->textContent)->not->toContain(MANAGE_TOKEN);

    $this->flushSession();

    expect(manageNoticeOf(documentOf($this->get(manageLink('acme'))->assertOk())))->toBeNull();
});
