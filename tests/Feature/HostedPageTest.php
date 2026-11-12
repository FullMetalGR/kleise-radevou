<?php

use App\Tenancy\Slug;
use App\Tenancy\Tenant;
use App\Tenancy\TenantStatus;
use App\Tenancy\TenantUrls;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

pest()->use(RefreshDatabase::class);

/*
 * The hosted page on the wire, through the real router, the real middleware and a real tenant
 * schema. The page reads the settings row with a schema-qualified query and opens no
 * transaction, so it can be tested here, inside the transaction this suite wraps each test in,
 * where the probe could not be. The page is read as a document rather than as a string: the
 * mount contract is about elements and children, and a substring check would pass a page
 * that put the noscript sentence beside the mount rather than inside it.
 */

it('renders the tenant name in the title and on the page chrome', function () {
    provisionTenant('acme', ['name' => 'Acme Salon']);

    $document = documentOf($this->get(hostedPage('acme'))->assertOk()->assertHeader('Content-Type', 'text/html; charset=UTF-8'));

    expect($document->getElementsByTagName('title')->item(0)?->textContent)->toBe('Acme Salon')
        ->and($document->getElementsByTagName('h1')->item(0)?->textContent)->toBe('Acme Salon');
});

it('carries the theme as data-theme on the body and on the mount element, and its stylesheet gives the theme its primary', function (string $theme, string $primary) {
    provisionTenant('acme');
    settingsOf('acme')->update(['theme' => $theme]);

    $document = documentOf($this->get(hostedPage('acme'))->assertOk());
    $style = $document->getElementsByTagName('style')->item(0)->textContent ?? '';

    expect($document->getElementsByTagName('body')->item(0)?->getAttribute('data-theme'))->toBe($theme)
        ->and(mountOf($document)->getAttribute('data-theme'))->toBe($theme)
        ->and($style)->toMatch(sprintf('~%s \\{[^}]*--kr-primary: %s;~', $theme === 'neutral' ? 'body' : preg_quote(sprintf('[data-theme="%s"]', $theme), '~'), $primary));
})->with([
    'neutral' => ['neutral', '#171717'],
    'blue' => ['blue', '#2563eb'],
    'green' => ['green', '#15803d'],
    'orange' => ['orange', '#c2410c'],
    'red' => ['red', '#dc2626'],
    'rose' => ['rose', '#e11d48'],
    'violet' => ['violet', '#7c3aed'],
]);

it('writes no inline style on the body or the mount, so the theme alone colours the page', function () {
    provisionTenant('acme');
    settingsOf('acme')->update(['theme' => 'violet']);

    $document = documentOf($this->get(hostedPage('acme'))->assertOk());

    expect(mountOf($document)->hasAttribute('style'))->toBeFalse()
        ->and($document->getElementsByTagName('body')->item(0)?->hasAttribute('style'))->toBeFalse();
});

it('loads the one booking bundle by the URL the snippet names, as a classic async script after the mount element', function () {
    provisionTenant('acme');

    $document = documentOf($this->get(hostedPage('acme'))->assertOk());
    $scripts = (new DOMXPath($document))->query('//script[@src]');

    expect($scripts)->toBeInstanceOf(DOMNodeList::class)->toHaveCount(1);

    $script = $scripts[0] ?? null;

    assert($script instanceof DOMElement);

    expect($script->getAttribute('src'))->toBe(app(TenantUrls::class)->embedScript(Slug::of('acme')))
        ->and($script->getAttribute('src'))->toBe(tenantUrl('acme', '/embed.js'))
        ->and($script->hasAttribute('async'))->toBeTrue()
        ->and($script->hasAttribute('type'))->toBeFalse()
        ->and($script->hasAttribute('defer'))->toBeFalse()
        ->and(mountOf($document)->nextElementSibling)->toBe($script);
});

it('holds the noscript fallback as the mount element\'s only child', function () {
    provisionTenant('acme');

    $mount = mountOf(documentOf($this->get(hostedPage('acme'))->assertOk()));
    $child = $mount->childNodes->item(0);

    expect($mount->childNodes)->toHaveCount(1)
        ->and($child)->toBeInstanceOf(DOMElement::class)
        ->and($child?->nodeName)->toBe('noscript')
        ->and($child?->textContent)->toBe(pageSentence('pages.noscript'));
});

it('inlines the boot config, exactly the seven keys, so the first render needs no round trip', function () {
    provisionTenant('acme', ['name' => 'Acme Salon', 'timezone' => 'Europe/Athens', 'currency' => 'EUR']);

    expect(configOf(documentOf($this->get(hostedPage('acme'))->assertOk())))->toBe([
        'tenant_name' => 'Acme Salon',
        'timezone' => 'Europe/Athens',
        'currency' => 'EUR',
        'granularity_min' => 5,
        'important_info' => null,
        'default_language' => 'en',
        'language' => 'en',
    ]);
});

it('takes the important info in the inlined config from that tenant\'s settings row, kept as data', function () {
    provisionTenant('acme');
    settingsOf('acme')->update(['important_info' => 'Bring cash </script><script>alert(1)</script>']);

    $response = $this->get(hostedPage('acme'))->assertOk();

    $response->assertDontSee('<script>alert(1)</script>', escape: false);

    expect(configOf(documentOf($response)))->toHaveKey('important_info', 'Bring cash </script><script>alert(1)</script>');
});

it('takes the granularity in the inlined config from that tenant\'s settings row', function () {
    provisionTenant('acme');
    // The start interval moves with it: the CHECK holds it to a multiple of the granularity, and
    // the default of 15 is not one of 10.
    settingsOf('acme')->update(['granularity_min' => 10, 'start_interval_min' => 30]);

    expect(configOf(documentOf($this->get(hostedPage('acme'))->assertOk())))->toHaveKey('granularity_min', 10);
});

it('keeps a tenant name that closes a tag as data, in the chrome and in the inlined config', function () {
    provisionTenant('acme', ['name' => 'Acme </script><script>alert(1)</script>']);

    $response = $this->get(hostedPage('acme'))->assertOk();

    $response->assertDontSee('<script>alert(1)</script>', escape: false);

    expect(configOf(documentOf($response)))->toHaveKey('tenant_name', 'Acme </script><script>alert(1)</script>');
});

it('keeps two tenants\' brands apart: changing one theme leaves the other\'s page as it was', function () {
    provisionTenant('acme', ['name' => 'Acme Salon']);
    provisionTenant('bravo', ['name' => 'Bravo Studio', 'ownerEmail' => 'bravo@example.com']);
    settingsOf('acme')->update(['theme' => 'violet']);
    settingsOf('bravo')->update(['theme' => 'green']);

    $bravoBefore = $this->get(hostedPage('bravo'))->assertOk()->getContent();

    settingsOf('acme')->update(['theme' => 'red']);

    $acme = documentOf($this->get(hostedPage('acme'))->assertOk());
    $bravo = documentOf($this->get(hostedPage('bravo'))->assertOk());

    expect(mountOf($acme)->getAttribute('data-theme'))->toBe('red')
        ->and(configOf($acme))->toHaveKey('tenant_name', 'Acme Salon')
        ->and(mountOf($bravo)->getAttribute('data-theme'))->toBe('green')
        ->and(configOf($bravo))->toHaveKey('tenant_name', 'Bravo Studio')
        ->and($bravo->saveHTML())->toBe(documentOf($this->get(hostedPage('bravo')))->saveHTML())
        ->and($this->get(hostedPage('bravo'))->getContent())->toBe($bravoBefore);
});

it('serves a suspended tenant the static page of the middleware, not this one', function () {
    schemaBehind(Tenant::factory()->create(['slug' => 'paused', 'name' => 'Paused Salon', 'status' => TenantStatus::Suspended]));

    $this->get(hostedPage('paused'))
        ->assertStatus(503)
        ->assertSee(pageSentence('pages.suspended.sentence'))
        ->assertDontSee('kr-booking')
        ->assertDontSee('Paused Salon');
});

it('answers 404 at an unknown subdomain', function () {
    $this->get(hostedPage('nobody'))->assertNotFound();
});

it('leaves the connection free of any search path change', function () {
    provisionTenant('acme');

    $this->get(hostedPage('acme'))->assertOk();

    expect(DB::scalar("select current_setting('search_path')"))->toBe('public')
        ->and(DB::scalar('select current_schema()'))->toBe('public');
});

it('writes service, staff and ch from the query string onto the mount as the embed\'s attributes', function () {
    provisionTenant('acme');

    $mount = mountOf(documentOf($this->get(hostedPage('acme').'?service=12&staff=4&ch=instagram')->assertOk()));

    expect($mount->getAttribute('data-service'))->toBe('12')
        ->and($mount->getAttribute('data-staff'))->toBe('4')
        ->and($mount->getAttribute('data-channel'))->toBe('instagram')
        ->and($mount->hasAttribute('data-mode'))->toBeFalse();
});

it('writes nothing for a parameter that is not a positive whole number or not one of the five channels: {query}', function (string $query) {
    provisionTenant('acme');

    $mount = mountOf(documentOf($this->get(hostedPage('acme').'?'.$query)->assertOk()));

    expect($mount->hasAttribute('data-service'))->toBeFalse()
        ->and($mount->hasAttribute('data-staff'))->toBeFalse()
        ->and($mount->hasAttribute('data-channel'))->toBeFalse();
})->with([
    'none' => [''],
    'zero' => ['service=0&staff=0'],
    'negative' => ['service=-3&staff=-1'],
    'words' => ['service=abc&staff=12abc'],
    'decimals' => ['service=1.5&staff=2e3'],
    'too large for the client to read' => ['service=9007199254740992'],
    'lists' => ['service[]=1&staff[]=2&ch[]=qr'],
    'empty' => ['service=&staff=&ch='],
    'another channel' => ['ch=tiktok'],
    'a capitalised channel' => ['ch=Instagram'],
]);

it('keeps the channel {channel} and a staff member alone, the service left to the customer', function (string $channel) {
    provisionTenant('acme');

    $mount = mountOf(documentOf($this->get(hostedPage('acme').'?staff=7&ch='.$channel)->assertOk()));

    expect($mount->hasAttribute('data-service'))->toBeFalse()
        ->and($mount->getAttribute('data-staff'))->toBe('7')
        ->and($mount->getAttribute('data-channel'))->toBe($channel);
})->with(['instagram', 'facebook', 'google', 'qr', 'other']);

it('reads a parameter trimmed, as the mount readers do', function () {
    provisionTenant('acme');

    $mount = mountOf(documentOf($this->get(hostedPage('acme').'?service=%2012%20&ch=%20qr%20')->assertOk()));

    expect($mount->getAttribute('data-service'))->toBe('12')
        ->and($mount->getAttribute('data-channel'))->toBe('qr');
});
