<?php

use App\Site\SiteLanguage;

/*
 * The site's two languages: English at the root and Greek under /el, the same path segments in
 * both, the Greek route named with the prefix el. Pure: a path and a name in, a path and a name
 * out, and nothing read from a request or the router.
 */

it('spells a page path in each language: the English one as it is, the Greek one under /el, {uri}', function (string $uri, string $greek) {
    expect(SiteLanguage::English->uri($uri))->toBe($uri)
        ->and(SiteLanguage::Greek->uri($uri))->toBe($greek);
})->with([
    'the home' => ['/', '/el'],
    'a page' => ['/pricing', '/el/pricing'],
    'a page with a parameter' => ['/for/{vertical}', '/el/for/{vertical}'],
]);

it('names a route in each language: the English name as it is, the Greek one with the prefix el.', function () {
    expect(SiteLanguage::English->routeName('site.home'))->toBe('site.home')
        ->and(SiteLanguage::Greek->routeName('site.home'))->toBe('el.site.home')
        ->and(SiteLanguage::Greek->routeName('signup'))->toBe('el.signup');
});

it('reads the language back from a route name and strips it to the page\'s own name', function (string $name, SiteLanguage $language, string $page) {
    expect(SiteLanguage::ofRouteName($name))->toBe($language)
        ->and(SiteLanguage::pageOf($name))->toBe($page);
})->with([
    'the English home' => ['site.home', SiteLanguage::English, 'site.home'],
    'the Greek home' => ['el.site.home', SiteLanguage::Greek, 'site.home'],
    'the Greek signup' => ['el.signup', SiteLanguage::Greek, 'signup'],
    'a name merely starting with el' => ['element', SiteLanguage::English, 'element'],
]);

it('reads the language from a request path by its first segment alone, {path}', function (string $path, SiteLanguage $language) {
    expect(SiteLanguage::ofPath($path))->toBe($language);
})->with([
    'the root' => ['/', SiteLanguage::English],
    'an English path' => ['pricing/nowhere', SiteLanguage::English],
    'the Greek home' => ['el', SiteLanguage::Greek],
    'a Greek path' => ['el/nowhere', SiteLanguage::Greek],
    'a segment merely starting with el' => ['elsewhere', SiteLanguage::English],
    'el further down' => ['for/el', SiteLanguage::English],
]);

it('names the other language, the one the switch links to', function () {
    expect(SiteLanguage::English->other())->toBe(SiteLanguage::Greek)
        ->and(SiteLanguage::Greek->other())->toBe(SiteLanguage::English);
});
