<?php

use App\Application\Notification\PlatformMessenger;
use App\Application\Turnstile\TurnstileCheck;
use App\Http\AdminSession;
use App\Site\TermsVersion;
use App\Tenancy\Slug;
use App\Tenancy\TenantUrls;
use Illuminate\Support\Facades\DB;
use Tests\Browser\Support\Admin;
use Tests\Browser\Support\PhoneScripts;
use Tests\Support\FakeTurnstileCheck;
use Tests\Support\RecordingPlatformMessages;

/*
 * The verified signup walked in a real browser: from the central host, the form filled with a
 * kind of business and no plan, the pending page asking the holder to check the address the link
 * went to, its Resend control pressed and answering its one sentence with a new link replacing
 * the first, that link opened as the owner's mail would open it, the schema built as a trial, the
 * pending page sending on to the subdomain's sign-in page, and the owner signed in to the full
 * rail the trial carries, the setup wizard come up by itself over Home on its first step, the
 * business holding nothing yet. The currency is typed in lower case and the control upper-cases it, so
 * the row stores USD. The platform's seam is the container's instance, so the application the
 * browser talks to hands this test the link it sent; the session lives across the browser's
 * requests in the database, as BrowserTestCase sets it.
 *
 * The walk runs with Turnstile's keys unset, as the test environment leaves them. A second walk
 * sets them and binds the fake check; with the keys set the page renders the widget's script tag,
 * so that walk points the script's address, read from configuration beside the keys, at a stub
 * the test writes as a data URL, and the browser loads nothing from Cloudflare. The stub stands
 * in for Cloudflare's explicit-render API alone: each render hands the widget's callback a token
 * the test chose, so the token the fake was asked about is the field the form posted.
 *
 * The terms' box: the first walk posts once without it, refused on the box with nothing written
 * and the input kept, reads the box's two links, then ticks it and goes on; the row records the
 * acceptance in the version the terms file names.
 *
 * One more walk reads the form and the pending page at a phone's width and a desktop's: each in
 * its form card on the site's white page, no wider than the window, the help lines readable on
 * the card's own surface. A last walk reads the widget on the narrowest phones: a stub that draws
 * Cloudflare's 300px box, inside the card and centred in it at 360, 375 and 390px, and then the
 * pending page of a business whose name is one long word, before the click and after it, its
 * heading inside the card at 390px.
 */

/** Cloudflare's explicit-render API as far as the form uses it, each render a numbered token. */
const SIGNUP_TURNSTILE_STUB = "window.turnstile = { renders: 0, render(box, options) { this.renders += 1; box.dataset.sitekey = options.sitekey; options.callback('browser-token-' + this.renders); return 'widget-' + this.renders; }, remove() {} };";

/** The same API drawing the box Cloudflare's widget draws, 300 by 65 pixels, inside the form's own. */
const SIGNUP_TURNSTILE_BOX_STUB = "window.turnstile = { render(box, options) { const widget = document.createElement('div'); widget.style.width = '300px'; widget.style.height = '65px'; box.append(widget); options.callback('browser-token'); return 'widget'; }, remove() {} };";

/** The widget's drawn box against the signup's card: its width, whether it lies inside, and how far off centre. */
const SIGNUP_WIDGET_IN_CARD = <<<'JS'
(() => {
    const card = document.querySelector('.admin-signup > section').getBoundingClientRect();
    const widget = document.querySelector('.admin-signup-turnstile > div').getBoundingClientRect();
    return {
        width: Math.round(widget.width),
        inside: widget.left >= card.left - 0.5 && widget.right <= card.right + 0.5,
        off: Math.round(Math.abs((widget.left + widget.right) / 2 - (card.left + card.right) / 2)),
    };
})()
JS;

/** Whether the pending page's heading holds its words inside its own box. */
const SIGNUP_PENDING_HEADING_FITS = "(() => { const heading = document.querySelector('.admin-signup-pending h1'); return heading !== null && heading.scrollWidth <= heading.clientWidth + 1; })()";

/**
 * The lowest contrast of the signup's help lines against the card each sits on, or nought where a
 * line sits on no card. Each colour is painted onto a canvas pixel and read back as the sRGB the
 * screen shows, since the stylesheet mixes both from the brand's primary.
 */
const SIGNUP_HELP_CONTRAST = <<<'JS'
(() => {
    const canvas = document.createElement('canvas');
    canvas.width = 1;
    canvas.height = 1;
    const context = canvas.getContext('2d', { willReadFrequently: true });
    const luminance = (colour) => {
        context.clearRect(0, 0, 1, 1);
        context.fillStyle = colour;
        context.fillRect(0, 0, 1, 1);
        const [r, g, b] = [...context.getImageData(0, 0, 1, 1).data].slice(0, 3).map((value) => {
            const share = value / 255;
            return share <= 0.04045 ? share / 12.92 : ((share + 0.055) / 1.055) ** 2.4;
        });
        return 0.2126 * r + 0.7152 * g + 0.0722 * b;
    };
    const ratios = [...document.querySelectorAll('.admin-signup .admin-field__help')].map((help) => {
        const card = help.closest('.admin-signup > section');
        if (card === null) {
            return 0;
        }
        const [light, dark] = [luminance(getComputedStyle(help).color), luminance(getComputedStyle(card).backgroundColor)].sort((a, b) => b - a);
        return Math.floor(((light + 0.05) / (dark + 0.05)) * 100) / 100;
    });
    return ratios.length === 0 ? 0 : Math.min(...ratios);
})()
JS;

beforeEach(fn () => dropDemoSchemas());

afterEach(fn () => dropDemoSchemas());

it('walks a verified signup from the form through a resent link to the owner signed in on a trial with Staff on the rail', function () {
    $urls = app(TenantUrls::class);
    $seam = new RecordingPlatformMessages;
    app()->instance(PlatformMessenger::class, $seam);

    $page = $this->visit($urls->central('/signup'))
        ->fill('slug', 'luna')
        ->fill('name', 'Luna Hair Studio')
        ->select('category', 'hair_salon')
        ->fill('currency', 'usd')
        ->fill('owner_name', 'Alex Owner')
        ->fill('owner_email', 'alex@luna.example')
        ->fill('password', 'correct horse battery staple')
        ->assertDontSee('Plan')
        ->click('.admin-signup-submit')
        ->waitForText(signupSentence('errors.terms'))
        ->assertSeeIn('#signup-terms-error', signupSentence('errors.terms'))
        ->assertPathIs('/signup')
        ->assertValue('slug', 'luna')
        ->assertNoJavaScriptErrors();

    expect(DB::table('tenants')->where('slug', 'luna')->exists())->toBeFalse()
        ->and($seam->sent)->toBe([])
        ->and($page->script('document.activeElement?.id ?? null'))->toBe('signup-terms')
        ->and($page->script('[...document.querySelectorAll("label[for=signup-terms] a")].map((link) => [link.getAttribute("href"), link.getAttribute("target")])'))
        ->toBe([[$urls->central('/terms'), '_blank'], [$urls->central('/privacy'), '_blank']]);

    $page->click('#signup-terms')
        ->fill('password', 'correct horse battery staple')
        ->click('.admin-signup-submit')
        ->waitForText('Check your email')
        ->assertSee('We sent a link to alex@luna.example.')
        ->assertPathIs('/signup/luna/pending')
        ->assertNoJavaScriptErrors();

    $row = (array) DB::selectOne("select status, currency, timezone, category, terms_version::text as terms_version, terms_accepted_at is not null as accepted from tenants where slug = 'luna'");

    expect($row)->toBe(['status' => 'provisioning', 'currency' => 'USD', 'timezone' => 'Europe/Athens', 'category' => 'hair_salon', 'terms_version' => app(TermsVersion::class)->day(), 'accepted' => true])
        ->and(DB::table('subscriptions')->count())->toBe(0)
        ->and(billingPortReads())->toBe([])
        ->and($seam->sent)->toHaveCount(1)
        ->and(str_starts_with($seam->lastLink(), $urls->central('/signup/verify/')))->toBeTrue();

    $first = $seam->lastLink();

    $page->click('.admin-signup-resend')
        ->waitForText(signupSentence('resent'))
        ->assertSeeIn('.admin-signup-resent', signupSentence('resent'))
        ->assertSee('Check your email')
        ->assertPathIs('/signup/luna/pending')
        ->assertNoJavaScriptErrors();

    expect($seam->sent)->toHaveCount(2)
        ->and($seam->lastLink())->not->toBe($first)
        ->and(str_starts_with($seam->lastLink(), $urls->central('/signup/verify/')))->toBeTrue();

    $page->navigate($seam->lastLink())
        ->waitForText(adminLine('admin/shell.login.submit'))
        ->assertUrlIs($urls->signIn(Slug::of('luna')))
        ->fill('email', 'alex@luna.example')
        ->fill('password', 'correct horse battery staple')
        ->click('.admin-login-submit')
        ->assertVisible(Admin::RAIL)
        ->assertPathIs(AdminSession::PREFIX)
        ->assertSeeIn('.admin-business', 'Luna Hair Studio')
        ->assertSeeIn('.admin-rail', navLabel('staff'))
        ->assertSeeIn('.admin-setup .admin-modal__title', adminLine('admin/setup.title'))
        ->assertVisible('.admin-setup__step[data-step="profile"][aria-current="step"]')
        ->assertNoJavaScriptErrors();

    expect(billingColumns())->toMatchArray(['entitlements' => '{staff}', 'subscription_state' => 'trial']);
});

it('posts the widget\'s token with the keys set, keeps the person on the form with their input when the check fails it, and reserves on a fresh token the check passes', function () {
    $urls = app(TenantUrls::class);
    $seam = new RecordingPlatformMessages;
    app()->instance(PlatformMessenger::class, $seam);
    $check = new FakeTurnstileCheck(false);
    app()->instance(TurnstileCheck::class, $check);
    $stub = 'data:text/javascript,'.rawurlencode(SIGNUP_TURNSTILE_STUB);
    config([
        'services.turnstile.site_key' => '0x4AAAAAAABrowserSiteKey',
        'services.turnstile.secret_key' => '0x4AAAAAAABrowserSecretKey',
        'services.turnstile.script' => $stub,
    ]);

    $page = $this->visit($urls->central('/signup'))
        ->fill('slug', 'luna')
        ->fill('name', 'Luna Hair Studio')
        ->select('category', 'hair_salon')
        ->fill('owner_name', 'Alex Owner')
        ->fill('owner_email', 'alex@luna.example')
        ->fill('password', 'correct horse battery staple')
        ->click('#signup-terms');

    expect($page->script('[...document.scripts].map((tag) => tag.getAttribute("src")).filter((src) => src !== null && (src.startsWith("data:") || src.includes("cloudflare")))'))->toBe([$stub])
        ->and($page->script('document.querySelector(".admin-signup-turnstile")?.dataset.sitekey ?? null'))->toBe('0x4AAAAAAABrowserSiteKey');

    $page->click('.admin-signup-submit')
        ->waitForText(signupSentence('errors.check'))
        ->assertSeeIn('#signup-turnstile_token-error', signupSentence('errors.check'))
        ->assertPathIs('/signup')
        ->assertValue('slug', 'luna')
        ->assertValue('name', 'Luna Hair Studio')
        ->assertValue('owner_email', 'alex@luna.example')
        ->assertNoJavaScriptErrors();

    expect(array_column($check->asked, 'token'))->toBe(['browser-token-1'])
        ->and(DB::table('tenants')->where('slug', 'luna')->exists())->toBeFalse()
        ->and(DB::table('users')->where('email', 'alex@luna.example')->exists())->toBeFalse()
        ->and($seam->sent)->toBe([]);

    $check->passing = true;

    $page->fill('password', 'correct horse battery staple')
        ->click('.admin-signup-submit')
        ->waitForText('Check your email')
        ->assertPathIs('/signup/luna/pending')
        ->assertNoJavaScriptErrors();

    expect(array_column($check->asked, 'token'))->toBe(['browser-token-1', 'browser-token-2'])
        ->and(DB::table('tenants')->where('slug', 'luna')->value('status'))->toBe('provisioning')
        ->and($seam->sent)->toHaveCount(1);
});

it('keeps the signup form and its pending page inside the window at a phone\'s width and a desktop\'s, each in its form card, the help lines readable on the card', function () {
    app()->instance(PlatformMessenger::class, new RecordingPlatformMessages);

    $page = $this->visit(app(TenantUrls::class)->central('/signup'))->waitForText('Start your booking page');

    foreach ([390, 1440] as $width) {
        $page->resize($width, 900)
            ->assertPresent('.admin-signup > section > h1')
            ->assertPresent('.admin-signup > section > form')
            ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true);

        $contrast = $page->script(SIGNUP_HELP_CONTRAST);
        assert(is_int($contrast) || is_float($contrast));
        expect($contrast)->toBeGreaterThanOrEqual(4.5);
    }

    $page->fill('slug', 'luna')
        ->fill('name', 'Luna Hair Studio')
        ->select('category', 'hair_salon')
        ->fill('owner_name', 'Alex Owner')
        ->fill('owner_email', 'alex@luna.example')
        ->fill('password', 'correct horse battery staple')
        ->click('#signup-terms')
        ->click('.admin-signup-submit')
        ->waitForText('Check your email')
        ->assertPathIs('/signup/luna/pending');

    foreach ([390, 1440] as $width) {
        $page->resize($width, 900)
            ->assertPresent('main > section > .admin-signup-pending h1')
            ->assertPresent('main > section .admin-signup-resend')
            ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true);
    }

    $page->assertNoJavaScriptErrors();
});

it('keeps the anti-spam widget inside the form card and centred down to the narrowest phones, and a business named in one long word inside the pending page\'s card', function () {
    $urls = app(TenantUrls::class);
    app()->instance(PlatformMessenger::class, new RecordingPlatformMessages);
    app()->instance(TurnstileCheck::class, new FakeTurnstileCheck(true));
    config([
        'services.turnstile.site_key' => '0x4AAAAAAABrowserSiteKey',
        'services.turnstile.secret_key' => '0x4AAAAAAABrowserSecretKey',
        'services.turnstile.script' => 'data:text/javascript,'.rawurlencode(SIGNUP_TURNSTILE_BOX_STUB),
    ]);

    $page = $this->visit($urls->central('/signup'))->waitForText('Start your booking page');

    foreach ([360, 375, 390] as $width) {
        $page->resize($width, 900)->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true);

        $widget = $page->script(SIGNUP_WIDGET_IN_CARD);
        assert(is_array($widget) && is_int($widget['off']));

        expect($widget['width'])->toBe(300)
            ->and($widget['inside'])->toBeTrue()
            ->and($widget['off'])->toBeLessThanOrEqual(2);
    }

    $page->fill('slug', 'luna')
        ->fill('name', 'BeautyLoungeKifisias')
        ->select('category', 'hair_salon')
        ->fill('owner_name', 'Alex Owner')
        ->fill('owner_email', 'alex@luna.example')
        ->fill('password', 'correct horse battery staple')
        ->click('#signup-terms')
        ->click('.admin-signup-submit')
        ->waitForText('Check your email')
        ->assertPathIs('/signup/luna/pending')
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true)
        ->assertScript(SIGNUP_PENDING_HEADING_FITS, true);

    DB::table('users')->where('email', 'alex@luna.example')->update(['email_verified_at' => now()]);

    $page->navigate($urls->central('/signup/luna/pending'))
        ->waitForText('Setting up')
        ->assertSee('BeautyLoungeKifisias')
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true)
        ->assertScript(SIGNUP_PENDING_HEADING_FITS, true)
        ->assertNoJavaScriptErrors();
});
