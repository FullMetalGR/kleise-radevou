<?php

use App\Http\Controllers\EmbedScriptController;
use App\Tenancy\Slug;
use App\Tenancy\Tenant;
use App\Tenancy\TenantStatus;
use App\Tenancy\TenantUrls;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

pest()->use(RefreshDatabase::class);

/*
 * GET /embed.js on the wire: the fixed URL the snippet names, the headers the route sends, a
 * short lifetime and a validator, the 304 they buy, and the 404 of a tree that was never
 * built. The bundle is stood in for by a file of the test's own under a public path of the
 * test's own, so what is asserted is the route and not whatever npm run build last wrote.
 */

function embedUrl(string $slug): string
{
    return tenantUrl($slug, '/embed.js');
}

function publicPathWith(?string $bundle): string
{
    $root = sys_get_temp_dir().'/kr-embed-'.bin2hex(random_bytes(6));

    File::makeDirectory($root.'/build', recursive: true);

    if ($bundle !== null) {
        File::put($root.'/'.EmbedScriptController::BUNDLE, $bundle);
    }

    app()->usePublicPath($root);

    return $root;
}

afterEach(function () {
    $root = public_path();

    if (str_starts_with($root, sys_get_temp_dir().'/kr-embed-')) {
        File::deleteDirectory($root);
    }
});

it('serves the built bundle as JavaScript with a short public lifetime, an ETag and a Last-Modified', function () {
    provisionTenant('acme');
    publicPathWith('(function(){"use strict";})();');

    $response = $this->get(embedUrl('acme'))
        ->assertOk()
        ->assertHeader('Content-Type', 'text/javascript; charset=utf-8')
        ->assertHeader('Cache-Control', 'max-age=300, public');

    $etag = $response->headers->get('ETag');
    $lastModified = $response->headers->get('Last-Modified');

    expect($response->getContent())->toBe('(function(){"use strict";})();')
        ->and($etag)->toMatch('/\A"[0-9a-f]{32}"\z/')
        ->and($lastModified)->not->toBeNull()
        ->and(strtotime((string) $lastModified))->toBeGreaterThan(time() - 60);
});

it('answers 304 to a matching ETag and to an If-Modified-Since at or after the file, and 200 to a stale one', function () {
    provisionTenant('acme');
    publicPathWith('(function(){})();');

    $first = $this->get(embedUrl('acme'))->assertOk();
    $etag = (string) $first->headers->get('ETag');
    $lastModified = (string) $first->headers->get('Last-Modified');

    $this->get(embedUrl('acme'), ['If-None-Match' => $etag])->assertStatus(304);
    $this->get(embedUrl('acme'), ['If-Modified-Since' => $lastModified])->assertStatus(304);
    $this->get(embedUrl('acme'), ['If-None-Match' => '"0123456789abcdef0123456789abcdef"'])->assertOk();

    $stale = gmdate('D, d M Y H:i:s', time() - 86400).' GMT';

    $this->get(embedUrl('acme'), ['If-Modified-Since' => $stale])->assertOk();

    expect($this->get(embedUrl('acme'), ['If-None-Match' => $etag])->getContent())->toBe('');
});

it('changes the ETag when the built bytes change', function () {
    provisionTenant('acme');
    $root = publicPathWith('(function(){})();');

    $before = $this->get(embedUrl('acme'))->assertOk()->headers->get('ETag');

    File::put($root.'/'.EmbedScriptController::BUNDLE, '(function(){"v2"})();');

    $after = $this->get(embedUrl('acme'))->assertOk()->headers->get('ETag');

    expect($after)->not->toBe($before)
        ->and($this->get(embedUrl('acme'), ['If-None-Match' => (string) $before])->getStatusCode())->toBe(200);
});

it('answers 404 and logs the missing bundle when the tree was never built', function () {
    provisionTenant('acme');
    publicPathWith(null);

    Log::shouldReceive('error')->once()->withArgs(fn (string $message): bool => str_contains($message, 'npm run build'));

    $this->get(embedUrl('acme'))->assertNotFound();
});

it('answers 404 at an unknown subdomain', function () {
    publicPathWith('(function(){})();');

    $this->get(embedUrl('nobody'))->assertNotFound();
});

it('serves a suspended tenant the static page, script request or not', function () {
    schemaBehind(Tenant::factory()->create(['slug' => 'paused', 'status' => TenantStatus::Suspended]));
    publicPathWith('(function(){})();');

    $this->get(embedUrl('paused'))->assertStatus(503)->assertSee(pageSentence('pages.suspended.sentence'));
});

it('is the URL the snippet names', function () {
    provisionTenant('acme');
    publicPathWith('(function(){})();');

    $snippet = app(TenantUrls::class)->embedSnippet(Slug::of('acme'));

    preg_match('/src="([^"]+)"/', $snippet, $matches);

    expect($matches[1] ?? null)->toBe(embedUrl('acme'));

    $this->get($matches[1] ?? '')->assertOk();
});

it('leaves the connection free of any search path change', function () {
    provisionTenant('acme');
    publicPathWith('(function(){})();');

    $this->get(embedUrl('acme'))->assertOk();

    expect(DB::scalar("select current_setting('search_path')"))->toBe('public')
        ->and(DB::scalar('select current_schema()'))->toBe('public');
});
