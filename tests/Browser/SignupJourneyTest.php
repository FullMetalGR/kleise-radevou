<?php

use App\Application\Notification\PlatformMessenger;
use App\Application\Turnstile\TurnstileCheck;
use App\Http\AdminSession;
use App\Site\TermsVersion;
use App\Tenancy\Slug;
use App\Tenancy\TenantUrls;
use Illuminate\Support\Facades\DB;
use Tests\Browser\Support\Admin;
use Tests\Support\FakeTurnstileCheck;
use Tests\Support\RecordingPlatformMessages;

/*
 * The verified signup walked in a real browser: from the central host, the form filled with a
 * kind of business and no plan, the pending page asking the holder to check the address the link
 * went to, its Resend control pressed and answering its one sentence with a new link replacing
 * the first, that link opened as the owner's mail would open it, the schema built as a trial, the
 * pending page sending on to the subdomain's sign-in page, and the owner signed in to the full
 * rail the trial carries. The currency is typed in lower case and the control upper-cases it, so
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
 */

/** Cloudflare's explicit-render API as far as the form uses it, each render a numbered token. */
const SIGNUP_TURNSTILE_STUB = "window.turnstile = { renders: 0, render(box, options) { this.renders += 1; box.dataset.sitekey = options.sitekey; options.callback('browser-token-' + this.renders); return 'widget-' + this.renders; }, remove() {} };";

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
