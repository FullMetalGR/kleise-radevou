<?php

use App\Site\SiteLanguage;
use App\Site\SitePages;
use App\Site\Vertical;
use App\Tenancy\TenantUrls;
use Illuminate\Routing\Route as RegisteredRoute;
use Illuminate\Support\Facades\Route;

/*
 * The six vertical pages, as served and read with no script run: one template at /for/{vertical}
 * and /el/for/{vertical}, the parameter constrained to the six keys, each page verticals.php's
 * slots under its key in the page's language: the headline over an intro, three benefits each a
 * title over a sentence, a list of example services under its heading, and a closing line over
 * the signup action, which links the form the one helper names for the page's language. Any
 * other value of the parameter, an uppercase key among them, answers the site's 404 in the
 * path's language. The page's head and its h1 against its slots are CentralSurfaceTest's walk,
 * which lists the twelve pages.
 *
 * A Greek sentence is read through the translator with the locale named, never spelled here.
 */

beforeEach(fn () => $this->withoutVite());

/**
 * Every vertical in each language, by its key and the language's name.
 *
 * @return array<string, array{Vertical, SiteLanguage}>
 */
function verticalPagesInBothLanguages(): array
{
    $cases = [];

    foreach (Vertical::cases() as $vertical) {
        foreach (SiteLanguage::cases() as $language) {
            $cases[$vertical->value.' in '.$language->name] = [$vertical, $language];
        }
    }

    return $cases;
}

it('keys the six verticals hair-salons, barbers, nails, beauty, massage and physiotherapy, in that order', function () {
    expect(array_map(fn (Vertical $vertical): string => $vertical->value, Vertical::cases()))
        ->toBe(['hair-salons', 'barbers', 'nails', 'beauty', 'massage', 'physiotherapy']);
});

it('registers site.vertical at /for/{vertical} and el.site.vertical at /el/for/{vertical}, the parameter constrained to the six keys', function () {
    $english = Route::getRoutes()->getByName(SitePages::VERTICAL);
    $greek = Route::getRoutes()->getByName('el.'.SitePages::VERTICAL);

    assert($english instanceof RegisteredRoute && $greek instanceof RegisteredRoute);

    $keys = implode('|', array_map(fn (Vertical $vertical): string => $vertical->value, Vertical::cases()));

    expect($english->uri())->toBe('for/{vertical}')
        ->and($greek->uri())->toBe('el/for/{vertical}')
        ->and($english->wheres)->toBe(['vertical' => $keys])
        ->and($greek->wheres)->toBe(['vertical' => $keys])
        ->and(app(SitePages::class)->instances(SitePages::VERTICAL))->toBe(array_map(fn (Vertical $vertical): array => ['vertical' => $vertical->value], Vertical::cases()));
});

it('renders each vertical from the one template over its slots in verticals.php, in the page\'s language', function (Vertical $vertical, SiteLanguage $language) {
    $response = $this->get(app(TenantUrls::class)->central($language->uri('/for/'.$vertical->value)));

    $response->assertOk()->assertViewIs('site.vertical');

    $document = siteDocument($response);
    $copy = 'verticals.'.$vertical->value;
    $benefits = siteCopyKeys($copy.'.benefits', $language);
    $action = $document->querySelector('main .site-vertical-closing a.site-vertical-signup');

    expect($document->documentElement?->getAttribute('lang'))->toBe($language->value)
        ->and(siteTexts($document, 'main h1'))->toBe([siteSentence($copy.'.heading', $language)])
        ->and(siteTexts($document, 'main .site-vertical-intro'))->toBe([siteSentence($copy.'.intro', $language)])
        ->and($benefits)->toHaveCount(3)
        ->and(siteTexts($document, 'main .site-vertical-benefits > li > h2'))->toBe(array_map(fn (string $benefit): string => siteSentence($copy.'.benefits.'.$benefit.'.title', $language), $benefits))
        ->and(siteTexts($document, 'main .site-vertical-benefits > li > p'))->toBe(array_map(fn (string $benefit): string => siteSentence($copy.'.benefits.'.$benefit.'.body', $language), $benefits))
        ->and(siteTexts($document, 'main .site-vertical-services h2'))->toBe([siteSentence($copy.'.services.heading', $language)])
        ->and(siteSentences($copy.'.services.items', $language))->not->toBeEmpty()
        ->and(siteTexts($document, 'main .site-vertical-services li'))->toBe(siteSentences($copy.'.services.items', $language))
        ->and(siteTexts($document, 'main .site-vertical-closing .site-vertical-closing-line'))->toBe([siteSentence($copy.'.closing.line', $language)])
        ->and(trim((string) $action?->textContent))->toBe(siteSentence($copy.'.closing.action', $language))
        ->and($action?->getAttribute('href'))->toBe(app(SitePages::class)->signup($language));
})->with(verticalPagesInBothLanguages());

it('writes each vertical\'s own copy: no two verticals share a headline, an intro or a list of services', function () {
    $slots = fn (string $slot): array => array_map(fn (Vertical $vertical): mixed => __('verticals.'.$vertical->value.'.'.$slot, [], 'en'), Vertical::cases());

    foreach (['title', 'description', 'heading', 'intro', 'services.items', 'closing.line'] as $slot) {
        expect(array_unique($slots($slot), SORT_REGULAR))->toHaveCount(6, $slot);
    }
});

it('links the signup action to the form in the page\'s language through the one helper, the Greek form under /el now its route exists', function () {
    $urls = app(TenantUrls::class);
    $action = fn (SiteLanguage $language): ?string => siteDocument($this->get($urls->central($language->uri('/for/barbers'))))
        ->querySelector('main a.site-vertical-signup')?->getAttribute('href');

    expect(Route::has('el.signup'))->toBeTrue()
        ->and($action(SiteLanguage::English))->toBe($urls->central('/signup'))
        ->and($action(SiteLanguage::Greek))->toBe($urls->central('/el/signup'));
});

it('answers the site\'s 404 in the path\'s language for any other value, an uppercase key included, {path}', function (string $path, SiteLanguage $language) {
    $response = $this->get(centralUrl($path));

    $response->assertNotFound();
    $document = siteDocument($response);

    expect($document->documentElement?->getAttribute('lang'))->toBe($language->value)
        ->and(trim((string) $document->querySelector('h1')?->textContent))->toBe(__('site.not_found.heading', [], $language->value))
        ->and($document->querySelector('meta[name="robots"]')?->getAttribute('content'))->toBe('noindex');
})->with([
    'an unknown key' => ['/for/dentists', SiteLanguage::English],
    'an uppercase key' => ['/for/BARBERS', SiteLanguage::English],
    'a capitalised key' => ['/for/Barbers', SiteLanguage::English],
    'a key with a suffix' => ['/for/barbers-shop', SiteLanguage::English],
    'a key with a segment after it' => ['/for/barbers/more', SiteLanguage::English],
    'no key' => ['/for', SiteLanguage::English],
    'an unknown key in Greek' => ['/el/for/dentists', SiteLanguage::Greek],
    'an uppercase key in Greek' => ['/el/for/HAIR-SALONS', SiteLanguage::Greek],
]);

it('carries no form and no script', function (Vertical $vertical, SiteLanguage $language) {
    $response = $this->get(app(TenantUrls::class)->central($language->uri('/for/'.$vertical->value)));

    $response->assertOk();

    $document = siteDocument($response);

    expect($document->querySelectorAll('form'))->toHaveCount(0)
        ->and($document->querySelectorAll('script'))->toHaveCount(0);
})->with(verticalPagesInBothLanguages());

it('links the six verticals from the footer of every page, each in the page\'s language and labelled from site.php', function (string $path, SiteLanguage $language) {
    $urls = app(TenantUrls::class);
    $links = [];

    foreach (siteDocument($this->get($urls->central($path)))->querySelectorAll('footer .site-footer-nav a') as $anchor) {
        $links[(string) $anchor->getAttribute('href')] = trim((string) $anchor->textContent);
    }

    foreach (Vertical::cases() as $vertical) {
        $href = $urls->central($language->uri('/for/'.$vertical->value));

        expect($links)->toHaveKey($href)
            ->and($links[$href])->toBe(siteSentence('site.pages.vertical.'.$vertical->value, $language));
    }

    expect(array_filter(array_keys($links), fn (string $href): bool => str_contains($href, '/for/')))->toHaveCount(6);
})->with([
    'the English home' => ['/', SiteLanguage::English],
    'the Greek home' => ['/el', SiteLanguage::Greek],
    'an English vertical' => ['/for/massage', SiteLanguage::English],
    'a Greek vertical' => ['/el/for/massage', SiteLanguage::Greek],
    'the English pricing page' => ['/pricing', SiteLanguage::English],
    'the Greek 404' => ['/el/nowhere', SiteLanguage::Greek],
]);

it('switches a vertical to the same vertical in the other language', function (string $path, string $other) {
    $urls = app(TenantUrls::class);

    expect(siteDocument($this->get($urls->central($path)))->querySelector('header .site-switch')?->getAttribute('href'))->toBe($urls->central($other));
})->with([
    'from English to Greek' => ['/for/nails', '/el/for/nails'],
    'from Greek to English' => ['/el/for/nails', '/for/nails'],
]);
