<?php

use App\Site\SiteLanguage;
use App\Tenancy\TenantPages;
use Illuminate\Http\Request;

/*
 * The addresses of a tenant host's pages: a page at its bare path in the tenant default and under
 * /en and /el, its twins named with the prefix, the language a path's prefix names, and for a
 * request the switch's target, the hreflang pair and the canonical, which nothing renders yet.
 */

it('names a page\'s twins with their language\'s prefix and reads a twin\'s name back as its page', function () {
    expect(TenantPages::routeName('manage', null))->toBe('manage')
        ->and(TenantPages::routeName('pay.link', SiteLanguage::Greek))->toBe('el.pay.link')
        ->and(TenantPages::routeName('hosted', SiteLanguage::English))->toBe('en.hosted')
        ->and(TenantPages::names('pay'))->toBe(['pay', 'en.pay', 'el.pay'])
        ->and(TenantPages::pageOf('el.my-bookings.open.act'))->toBe('my-bookings.open.act')
        ->and(TenantPages::pageOf('en.manage'))->toBe('manage')
        ->and(TenantPages::pageOf('manage'))->toBe('manage')
        ->and(TenantPages::pageOf('api.booking'))->toBe('api.booking')
        ->and(TenantPages::pageOf('else.thing'))->toBe('else.thing');
});

it('reads the language a path\'s first segment names, and none for a bare path, {path}', function (string $path, ?SiteLanguage $language) {
    expect(TenantPages::prefixOf($path))->toBe($language);
})->with([
    'the Greek home' => ['el', SiteLanguage::Greek],
    'a Greek page' => ['el/manage/x', SiteLanguage::Greek],
    'the English home' => ['en', SiteLanguage::English],
    'a token path in English' => ['en/my-bookings/abc', SiteLanguage::English],
    'the root' => ['/', null],
    'a bare page' => ['manage/x', null],
    'the API' => ['api/v1/config', null],
    'a segment that only starts with the letters' => ['else', null],
    'an upper-cased prefix' => ['EL/manage/x', null],
]);

it('spells a page\'s path under a prefix, the root as the prefix alone', function () {
    expect(TenantPages::uri('/', null))->toBe('/')
        ->and(TenantPages::uri('/', SiteLanguage::Greek))->toBe('/el')
        ->and(TenantPages::uri('/manage/x', SiteLanguage::English))->toBe('/en/manage/x')
        ->and(TenantPages::uri('my-bookings', SiteLanguage::Greek))->toBe('/el/my-bookings')
        ->and(TenantPages::uri('/pay/x', null))->toBe('/pay/x');
});

it('leads the switch to the same path under the other language\'s prefix, the default\'s explicit prefix included, the query and a token kept; names the default\'s page bare in the pair and the other by its prefix; and takes the canonical from the pair', function (string $url, SiteLanguage $default, SiteLanguage $language, string $switch, array $alternates, string $canonical) {
    $addresses = TenantPages::addresses(Request::create($url), $default);

    expect($addresses->language)->toBe($language)
        ->and($addresses->switchHref)->toBe($switch)
        ->and($addresses->alternates)->toBe($alternates)
        ->and($addresses->canonical)->toBe($canonical);
})->with([
    'the bare home in an English default' => ['https://luna.kleiseradevou.gr/', SiteLanguage::English, SiteLanguage::English, 'https://luna.kleiseradevou.gr/el', ['en' => 'https://luna.kleiseradevou.gr/', 'el' => 'https://luna.kleiseradevou.gr/el'], 'https://luna.kleiseradevou.gr/'],
    'the Greek home in an English default' => ['https://luna.kleiseradevou.gr/el', SiteLanguage::English, SiteLanguage::Greek, 'https://luna.kleiseradevou.gr/en', ['en' => 'https://luna.kleiseradevou.gr/', 'el' => 'https://luna.kleiseradevou.gr/el'], 'https://luna.kleiseradevou.gr/el'],
    'the default\'s explicit prefix' => ['https://luna.kleiseradevou.gr/en/my-bookings', SiteLanguage::English, SiteLanguage::English, 'https://luna.kleiseradevou.gr/el/my-bookings', ['en' => 'https://luna.kleiseradevou.gr/my-bookings', 'el' => 'https://luna.kleiseradevou.gr/el/my-bookings'], 'https://luna.kleiseradevou.gr/my-bookings'],
    'a bare page in a Greek default' => ['https://luna.kleiseradevou.gr/my-bookings', SiteLanguage::Greek, SiteLanguage::Greek, 'https://luna.kleiseradevou.gr/en/my-bookings', ['en' => 'https://luna.kleiseradevou.gr/en/my-bookings', 'el' => 'https://luna.kleiseradevou.gr/my-bookings'], 'https://luna.kleiseradevou.gr/my-bookings'],
    'a query-borne token, kept in the switch alone' => ['https://luna.kleiseradevou.gr/manage/7b7f?token=secret&b=1', SiteLanguage::English, SiteLanguage::English, 'https://luna.kleiseradevou.gr/el/manage/7b7f?token=secret&b=1', ['en' => 'https://luna.kleiseradevou.gr/manage/7b7f', 'el' => 'https://luna.kleiseradevou.gr/el/manage/7b7f'], 'https://luna.kleiseradevou.gr/manage/7b7f'],
    'a token path under the Greek prefix' => ['http://luna.localhost:8000/el/my-bookings/abc123', SiteLanguage::English, SiteLanguage::Greek, 'http://luna.localhost:8000/en/my-bookings/abc123', ['en' => 'http://luna.localhost:8000/my-bookings/abc123', 'el' => 'http://luna.localhost:8000/el/my-bookings/abc123'], 'http://luna.localhost:8000/el/my-bookings/abc123'],
    'a link\'s tags in their order' => ['https://luna.kleiseradevou.gr/?ch=qr&service=12', SiteLanguage::English, SiteLanguage::English, 'https://luna.kleiseradevou.gr/el?ch=qr&service=12', ['en' => 'https://luna.kleiseradevou.gr/', 'el' => 'https://luna.kleiseradevou.gr/el'], 'https://luna.kleiseradevou.gr/'],
]);
