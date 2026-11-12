<?php

use App\Site\SiteLanguage;
use App\Tenancy\Slug;
use App\Tenancy\TenantUrls;
use App\Tenancy\Theme;

it('puts the tenant on a subdomain of the application URL, {appUrl}', function (string $appUrl, string $origin) {
    expect((new TenantUrls($appUrl))->origin(Slug::of('acme')))->toBe($origin);
})->with([
    ['http://localhost:8000', 'http://acme.localhost:8000'],
    ['https://kleiseradevou.gr', 'https://acme.kleiseradevou.gr'],
    ['https://kleiseradevou.gr/', 'https://acme.kleiseradevou.gr'],
    ['http://localhost', 'http://acme.localhost'],
]);

it('names the central host the tenant resolver strips', function () {
    expect((new TenantUrls('https://kleiseradevou.gr'))->centralHost())->toBe('kleiseradevou.gr')
        ->and((new TenantUrls('http://localhost:8000'))->centralHost())->toBe('localhost');
});

it('builds the hosted page and embed script URLs from the origin', function () {
    $urls = new TenantUrls('https://kleiseradevou.gr');
    $slug = Slug::of('salon-x');

    expect($urls->hostedPage($slug))->toBe('https://salon-x.kleiseradevou.gr/')
        ->and($urls->embedScript($slug))->toBe('https://salon-x.kleiseradevou.gr/embed.js');
});

it('renders the snippet as the mount element and the one script tag', function () {
    expect((new TenantUrls('https://kleiseradevou.gr'))->embedSnippet(Slug::of('salon-x')))->toBe(
        "<div class=\"kr-booking\" data-theme=\"neutral\"></div>\n"
        .'<script src="https://salon-x.kleiseradevou.gr/embed.js" async></script>',
    );
});

it('refuses an application URL that names no host', function () {
    expect(fn () => new TenantUrls('kleiseradevou.gr'))->toThrow(InvalidArgumentException::class);
});

it('reads the tenant label off a host directly under the central host, {host}', function (string $host, ?string $label) {
    expect((new TenantUrls('http://localhost:8000'))->labelOf($host))->toBe($label);
})->with([
    'a tenant host with the port' => ['salon.localhost:8000', 'salon'],
    'a tenant host without the port' => ['salon.localhost', 'salon'],
    'the central host with the port' => ['localhost:8000', null],
    'the central host alone' => ['localhost', null],
    'a host two labels deep' => ['a.b.localhost', null],
    'a host under another domain' => ['salon.example.com', null],
    'a host that merely ends in the central host' => ['salonlocalhost', null],
    'an upper-case host' => ['SALON.LOCALHOST:8000', 'salon'],
    'an empty label' => ['.localhost', null],
]);

it('reads the tenant label off a production host', function () {
    $urls = new TenantUrls('https://kleiseradevou.gr');

    expect($urls->labelOf('acme.kleiseradevou.gr'))->toBe('acme')
        ->and($urls->labelOf('kleiseradevou.gr'))->toBeNull()
        ->and($urls->labelOf('acme.kleiseradevou.gr.example.com'))->toBeNull();
});

it('spells the manage link: the booking on the tenant origin with the token in the query string', function () {
    $urls = new TenantUrls('https://kleiseradevou.gr');
    $slug = Slug::of('salon-x');
    $uuid = '7b7f0e5a-0000-4000-8000-000000000001';

    expect($urls->manageLink($slug, $uuid, 'the-plaintext-token'))
        ->toBe('https://salon-x.kleiseradevou.gr/manage/'.$uuid.'?token=the-plaintext-token')
        ->and($urls->manageLink($slug, $uuid))
        ->toBe('https://salon-x.kleiseradevou.gr/manage/'.$uuid);
});

it('escapes what it puts in the link, so neither half can become a second path or a second parameter', function () {
    $urls = new TenantUrls('http://localhost:8000');

    expect($urls->manageLink(Slug::of('acme'), '../../admin', 'a b&c=d'))
        ->toBe('http://acme.localhost:8000/manage/..%2F..%2Fadmin?token=a+b%26c%3Dd');
});

it('carries the theme on the snippet\'s mount element as data-theme, and nothing inline', function () {
    $urls = new TenantUrls('https://kleiseradevou.gr');

    expect($urls->embedSnippet(Slug::of('salon-x'), Theme::Violet))->toBe(
        "<div class=\"kr-booking\" data-theme=\"violet\"></div>\n"
        .'<script src="https://salon-x.kleiseradevou.gr/embed.js" async></script>',
    )->and($urls->embedSnippet(Slug::of('salon-x'), Theme::Neutral))->toStartWith('<div class="kr-booking" data-theme="neutral"></div>');
});

it('spells the logo URL on the tenant\'s own origin under the file name, escaped as a path segment', function () {
    expect((new TenantUrls('https://kleiseradevou.gr'))->logo(Slug::of('salon-x'), 'logo-0123456789abcdef.png'))->toBe('https://salon-x.kleiseradevou.gr/logo/logo-0123456789abcdef.png')
        ->and((new TenantUrls('http://localhost:8000'))->logo(Slug::of('luna'), 'a b'))->toBe('http://luna.localhost:8000/logo/a%20b');
});

it('spells an image URL on the tenant\'s own origin under /images, escaped as a path segment', function () {
    expect((new TenantUrls('https://kleiseradevou.gr'))->image(Slug::of('salon-x'), 'cover-0123456789abcdef.jpg'))->toBe('https://salon-x.kleiseradevou.gr/images/cover-0123456789abcdef.jpg')
        ->and((new TenantUrls('http://localhost:8000'))->image(Slug::of('luna'), 'a b'))->toBe('http://luna.localhost:8000/images/a%20b');
});

it('spells a booking link as the hosted page with its query, the page alone with none', function () {
    $urls = new TenantUrls('https://kleiseradevou.gr');
    $slug = Slug::of('salon-x');

    expect($urls->bookingLink($slug))->toBe('https://salon-x.kleiseradevou.gr/')
        ->and($urls->bookingLink($slug))->toBe($urls->hostedPage($slug))
        ->and($urls->bookingLink($slug, ['service' => 12]))->toBe('https://salon-x.kleiseradevou.gr/?service=12')
        ->and($urls->bookingLink($slug, ['staff' => 4]))->toBe('https://salon-x.kleiseradevou.gr/?staff=4')
        ->and($urls->bookingLink($slug, ['service' => 12, 'ch' => 'qr']))->toBe('https://salon-x.kleiseradevou.gr/?service=12&ch=qr')
        ->and($urls->bookingLink($slug, ['ch' => 'qr']))->toBe('https://salon-x.kleiseradevou.gr/?ch=qr');
});

it('spells My bookings and its one-time link on the tenant origin, the token a path segment escaped as one', function () {
    $urls = new TenantUrls('https://kleiseradevou.gr');
    $slug = Slug::of('salon-x');

    expect($urls->myBookings($slug))->toBe('https://salon-x.kleiseradevou.gr/my-bookings')
        ->and($urls->myBookingsLink($slug, str_repeat('ab', 32)))->toBe('https://salon-x.kleiseradevou.gr/my-bookings/'.str_repeat('ab', 32))
        ->and($urls->myBookingsLink($slug, '../x y'))->toBe('https://salon-x.kleiseradevou.gr/my-bookings/..%2Fx%20y');
});

it('spells the console host as console. under the central host', function (string $appUrl, string $host) {
    expect((new TenantUrls($appUrl))->consoleHost())->toBe($host);
})->with([
    ['https://kleiseradevou.gr', 'console.kleiseradevou.gr'],
    ['http://localhost:8000', 'console.localhost'],
    ['http://localhost', 'console.localhost'],
]);

it('spells a console path on the console origin, the scheme and the port the application URL carries, {appUrl}', function (string $appUrl, string $url) {
    expect((new TenantUrls($appUrl))->console('/login'))->toBe($url);
})->with([
    ['https://kleiseradevou.gr', 'https://console.kleiseradevou.gr/login'],
    ['https://kleiseradevou.gr/', 'https://console.kleiseradevou.gr/login'],
    ['http://localhost:8000', 'http://console.localhost:8000/login'],
    ['http://localhost', 'http://console.localhost/login'],
]);

it('reads the console host as the console label, the one the tenant resolver would be handed', function () {
    $urls = new TenantUrls('http://localhost:8000');

    expect($urls->labelOf($urls->consoleHost()))->toBe('console')
        ->and($urls->labelOf('console.localhost:8000'))->toBe('console');
});

it('spells every page link bare where no language is named and under the language\'s prefix where one is, the business page with no trailing slash', function () {
    $urls = new TenantUrls('https://kleiseradevou.gr');
    $slug = Slug::of('salon-x');
    $origin = 'https://salon-x.kleiseradevou.gr';
    $uuid = '7b7f0e5a-0000-4000-8000-000000000001';

    expect($urls->hostedPage($slug))->toBe($origin.'/')
        ->and($urls->hostedPage($slug, SiteLanguage::Greek))->toBe($origin.'/el')
        ->and($urls->hostedPage($slug, SiteLanguage::English))->toBe($origin.'/en')
        ->and($urls->bookingLink($slug, ['service' => 12], SiteLanguage::Greek))->toBe($origin.'/el?service=12')
        ->and($urls->bookingLink($slug, ['service' => 12]))->toBe($origin.'/?service=12')
        ->and($urls->manageLink($slug, $uuid, 'tok', SiteLanguage::Greek))->toBe($origin.'/el/manage/'.$uuid.'?token=tok')
        ->and($urls->manageLink($slug, $uuid, null, SiteLanguage::English))->toBe($origin.'/en/manage/'.$uuid)
        ->and($urls->manageLink($slug, $uuid, 'tok'))->toBe($origin.'/manage/'.$uuid.'?token=tok')
        ->and($urls->myBookings($slug, SiteLanguage::Greek))->toBe($origin.'/el/my-bookings')
        ->and($urls->myBookings($slug))->toBe($origin.'/my-bookings')
        ->and($urls->myBookingsLink($slug, 'abc', SiteLanguage::Greek))->toBe($origin.'/el/my-bookings/abc')
        ->and($urls->myBookingsLink($slug, 'abc'))->toBe($origin.'/my-bookings/abc')
        ->and($urls->payLink($slug, $uuid, 'tok', SiteLanguage::Greek))->toBe($origin.'/el/pay/'.$uuid.'?token=tok')
        ->and($urls->payLink($slug, $uuid, 'tok'))->toBe($origin.'/pay/'.$uuid.'?token=tok');
});
