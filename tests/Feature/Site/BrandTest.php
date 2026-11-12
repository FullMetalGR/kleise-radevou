<?php

use App\Http\Middleware\HandleCentralInertiaRequests;
use App\Http\Middleware\HandleConsoleInertiaRequests;
use App\Http\Middleware\HandleInertiaRequests;
use App\Site\Brand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;

pest()->use(RefreshDatabase::class);

/*
 * The provisional brand: one tokens file, one source of the wordmark's mark, which is the
 * favicon itself, and the public name read from configuration wherever the platform names
 * itself. The site's header carries the wordmark, and so does the signup's frame, which renders
 * the site's header and footer from the same partials around the Inertia root. The central,
 * admin and console root views and every site page link the favicon and title themselves with
 * the public name, so a changed site.name changes every one of them.
 */

beforeEach(fn () => $this->withoutVite());

/**
 * The value a custom property of the brand's tokens file holds, as the file spells it.
 */
function brandToken(string $property): string
{
    $tokens = File::get(resource_path('js/brand/brand.css'));

    if (preg_match('/^\s*--'.preg_quote($property, '/').':\s*([^;]+);/m', $tokens, $match) !== 1) {
        throw new LogicException(sprintf('The brand\'s tokens file declares no --%s.', $property));
    }

    return trim($match[1]);
}

/**
 * The favicon's markup as the file holds it, which is the mark every wordmark inlines.
 */
function brandMark(): string
{
    return trim(File::get(public_path(Brand::MARK)));
}

it('keeps the mark as one SVG under public/, the favicon itself, drawn in the tokens\' primary, accent and contrast', function () {
    $mark = brandMark();

    expect(Brand::MARK)->toBe('favicon.svg')
        ->and($mark)->toStartWith('<svg xmlns="http://www.w3.org/2000/svg"')
        ->and($mark)->toEndWith('</svg>')
        ->and(preg_match('/[^\x00-\x7F]/', $mark))->toBe(0)
        ->and($mark)->toContain('viewBox="0 0 32 32"')
        ->and($mark)->toContain('fill="'.brandToken('brand-primary').'"')
        ->and($mark)->toContain('fill="'.brandToken('brand-accent').'"')
        ->and($mark)->toContain('stroke="'.brandToken('brand-primary-contrast').'"');
});

it('hands the platform\'s mails every token of the tokens file by its name, the value the file spells, for the one layout to inline', function () {
    $tokens = app(Brand::class)->tokens();

    expect(array_keys($tokens))->toBe(['primary', 'primary-hover', 'primary-contrast', 'accent', 'ink', 'muted', 'line', 'paper', 'surface', 'font'])
        ->and($tokens['primary'])->toBe(brandToken('brand-primary'))
        ->and($tokens['paper'])->toBe(brandToken('brand-paper'))
        ->and($tokens['font'])->toBe(brandToken('brand-font'))
        ->and(Brand::TOKENS)->toBe('js/brand/brand.css');
});

it('sets the brand\'s type in a system font stack with no web font, and gives the primary its hover and its contrast', function () {
    expect(brandToken('brand-font'))->toStartWith('system-ui,')
        ->and(File::get(resource_path('js/brand/brand.css')))->not->toContain('@font-face')
        ->and(File::get(resource_path('js/brand/brand.css')))->not->toContain('url(')
        ->and(brandToken('brand-primary-hover'))->toMatch('/^#[0-9a-f]{6}$/')
        ->and(brandToken('brand-primary-contrast'))->toMatch('/^#[0-9a-f]{6}$/');
});

it('is the one tokens file the site\'s stylesheet and the admin\'s both import', function () {
    expect(File::get(resource_path('js/site/site.css')))->toContain('@import "../brand/brand.css";')
        ->and(File::get(resource_path('js/admin/app.css')))->toContain('@import "../brand/brand.css";');
});

it('carries the wordmark in the site\'s header, the mark from the one source beside the public name, linking the home in the page\'s language, {path}', function (string $path, string $home) {
    $response = $this->get(centralUrl($path));
    $wordmark = siteDocument($response)->querySelector('header a.site-name .brand-wordmark');

    $response->assertSee(brandMark(), false);

    expect($wordmark)->not->toBeNull()
        ->and($wordmark?->querySelector('.brand-wordmark__mark > svg.brand-mark'))->not->toBeNull()
        ->and(trim((string) $wordmark?->querySelector('.brand-wordmark__name')?->textContent))->toBe(siteName())
        ->and(siteDocument($response)->querySelector('header a.site-name')?->getAttribute('href'))->toBe(centralUrl($home));
})->with([
    'the English home' => ['/', '/'],
    'the Greek home' => ['/el', '/el'],
    'the 404' => ['/nowhere', '/'],
    'the Greek 404' => ['/el/nowhere', '/el'],
]);

it('links the favicon from every site page, the 404 included, {path}', function (string $path) {
    $icon = siteDocument($this->get(centralUrl($path)))->querySelector('head link[rel="icon"]');

    expect($icon?->getAttribute('href'))->toBe('/favicon.svg')
        ->and($icon?->getAttribute('type'))->toBe('image/svg+xml');
})->with(['/', '/el', '/nowhere']);

it('links the favicon from the central, admin and console root views and titles each with the public name', function () {
    provisionTenant('luna');

    $roots = [
        [centralUrl('/signup'), HandleCentralInertiaRequests::ROOT_VIEW, siteName()],
        [tenantUrl('luna', '/admin/login'), HandleInertiaRequests::ROOT_VIEW, siteName()],
        [consoleUrl('/login'), HandleConsoleInertiaRequests::ROOT_VIEW, siteName().' console'],
    ];

    foreach ($roots as [$url, $view, $title]) {
        $response = $this->get($url)->assertOk()->assertViewIs($view);
        $document = siteDocument($response);

        expect($document->querySelector('head link[rel="icon"]')?->getAttribute('href'))->toBe('/favicon.svg')
            ->and($document->querySelector('head link[rel="icon"]')?->getAttribute('type'))->toBe('image/svg+xml')
            ->and($document->title)->toBe($title);
    }
});

it('frames the signup in the site\'s header and footer, from the same partials, around the Inertia root', function () {
    $response = $this->get(centralUrl('/signup'))->assertOk();
    $document = siteDocument($response);

    $response->assertSee(brandMark(), false);

    expect($document->documentElement?->getAttribute('lang'))->toBe('en')
        ->and($document->querySelector('body > header .site-name .brand-wordmark .brand-wordmark__name')?->textContent)->toBe(siteName())
        ->and($document->querySelector('body > header .site-switch')?->getAttribute('href'))->toBe(centralUrl('/el/signup'))
        ->and($document->querySelector('body > .site-frame-page > #app'))->not->toBeNull()
        ->and($document->querySelector('body > footer')?->textContent)->toContain(siteName());
});

it('follows a changed site.name in every title, the wordmark and the brand prop', function () {
    provisionTenant('luna');
    config(['site.name' => 'Example Bookings']);

    $home = siteDocument($this->get(centralUrl('/')));
    $signup = $this->get(centralUrl('/signup'));

    expect($home->title)->toBe(__('home.title', ['name' => 'Example Bookings'], 'en'))
        ->and(trim((string) $home->querySelector('header .brand-wordmark__name')?->textContent))->toBe('Example Bookings')
        ->and(siteDocument($signup)->title)->toBe('Example Bookings')
        ->and(trim((string) siteDocument($signup)->querySelector('header .brand-wordmark__name')?->textContent))->toBe('Example Bookings')
        ->and(siteDocument($this->get(tenantUrl('luna', '/admin/login')))->title)->toBe('Example Bookings')
        ->and(siteDocument($this->get(consoleUrl('/login')))->title)->toBe('Example Bookings console');

    $signup->assertInertia(fn (Assert $page) => $page->where('brand', ['name' => 'Example Bookings']));
    $this->get(tenantUrl('luna', '/admin/login'))->assertInertia(fn (Assert $page) => $page->where('brand', ['name' => 'Example Bookings']));
    $this->get(consoleUrl('/login'))->assertInertia(fn (Assert $page) => $page->where('brand', ['name' => 'Example Bookings']));
});
