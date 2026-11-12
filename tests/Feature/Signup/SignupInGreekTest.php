<?php

use App\Application\Clock;
use App\Application\Notification\PlatformMessageKind;
use App\Application\Notification\PlatformMessenger;
use App\Domain\Lifetime;
use App\Http\Requests\SignupRequest;
use App\Http\SignupHolder;
use App\Site\SiteLanguage;
use App\Site\TermsVersion;
use App\Tenancy\Signup;
use App\Tenancy\SignupVerification;
use App\Tenancy\TenantProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FixedClock;
use Tests\Support\RecordingPlatformMessages;
use Tests\TestCase;

pest()->use(RefreshDatabase::class);

/*
 * The signup in Greek as a feature walk: POST /el/signup reserves a row with locale el, the
 * recorded verification message carries the link under /el, its click builds the trial and sends
 * on to the pending page under /el and then to the sign-in page, and each page on the way answers
 * lang/el's strings, read through the translator with the locale named, never spelled here; the
 * English path unchanged beside it. Around the walk: a Greek refusal, a dead link under /el, a
 * resend across the two languages, the one signup count the four POSTs draw on, and the frame's
 * language and switch on every signup page. The row's language is the route's, and the link, the
 * click and the resend follow the row; a 410 and a 429 answer in their path's language.
 *
 * An assertion on a sentence of lang/el/signup.php reads it through the translator for el,
 * whatever the file holds; SignupTest's override case proves the Greek routes read lang/el and
 * not lang/en.
 *
 * The router builds a route's controller once per test and keeps it, the clock it was built
 * with included, so a case that moves the clock does so before a route's first use, and a case
 * that clicks on two clocks uses each verify route on one clock alone.
 */

beforeEach(fn () => $this->withoutVite());

/**
 * The platform's seam, recording, bound for the test, and the clock pinned at the walk's start.
 */
function greekSeam(string $at = '2026-10-09 09:00:00'): RecordingPlatformMessages
{
    $seam = new RecordingPlatformMessages;
    app()->instance(PlatformMessenger::class, $seam);
    app()->instance(Clock::class, FixedClock::at($at));

    return $seam;
}

/**
 * Signs luna up in a language and answers the clear token the link carried, asserting the link
 * is under that language's path.
 */
function greekSignedUp(TestCase $test, RecordingPlatformMessages $seam, SiteLanguage $language): string
{
    $test->post(centralUrl($language->uri('/signup')), signupForm())->assertRedirect(centralUrl($language->uri('/signup/luna/pending')));

    return greekToken($seam, $language);
}

/**
 * The token of the last link the seam recorded, which is the click's route in the language given.
 */
function greekToken(RecordingPlatformMessages $seam, SiteLanguage $language): string
{
    $link = $seam->lastLink();

    expect(str_starts_with($link, centralUrl($language->uri('/signup/verify/'))))->toBeTrue($link);

    return substr($link, -64);
}

/**
 * The signup page's frame as served: the document's language, the switch's address and label, and
 * the nav's first label. The response is what a case's closure answered.
 *
 * @return array{lang: string|null, switch: string|null, label: string, nav: string}
 */
function greekFrame(mixed $response): array
{
    if (! $response instanceof TestResponse) {
        throw new LogicException('The case answered no response.');
    }

    $document = siteDocument($response);
    $switch = $document->querySelector('body > header .site-switch');

    return [
        'lang' => $document->documentElement?->getAttribute('lang'),
        'switch' => $switch?->getAttribute('href'),
        'label' => trim((string) $switch?->textContent),
        'nav' => trim((string) $document->querySelector('body > header .site-nav li a')?->textContent),
    ];
}

it('completes a signup in Greek: the row reserved with locale el, the link under /el, its click building the trial and sending on to the Greek pending page and then to sign-in, each page in lang/el\'s strings', function () {
    $seam = greekSeam();

    $this->get(centralUrl('/el/signup'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('signup/Signup')
            ->where('language', 'el')
            ->where('strings', centralStrings(SiteLanguage::Greek))
            ->where('action', '/el/signup')
            ->where('refusal', null)
            ->where('brand', ['name' => siteName()]));

    $this->post(centralUrl('/el/signup'), signupForm())
        ->assertRedirect(centralUrl('/el/signup/luna/pending'))
        ->assertCookie(SignupHolder::COOKIE, 'luna');

    $row = (array) DB::selectOne("select status, locale, category from tenants where slug = 'luna'");
    $message = $seam->sent[0] ?? null;
    assert($message !== null);

    expect($row)->toBe(['status' => 'provisioning', 'locale' => 'el', 'category' => 'hair_salon'])
        ->and($seam->sent)->toHaveCount(1)
        ->and($message->toAddress)->toBe('owner@luna.example')
        ->and($message->body)->toContain(centralUrl('/el/signup/verify/'))
        ->and($message->body)->not->toContain(centralUrl('/signup/verify/'));

    $token = greekToken($seam, SiteLanguage::Greek);

    $this->withCookie(SignupHolder::COOKIE, 'luna')->get(centralUrl('/el/signup/luna/pending'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('signup/Pending')
            ->where('language', 'el')
            ->where('strings', centralStrings(SiteLanguage::Greek))
            ->where('verified', false)
            ->where('business', 'Luna Studio')
            ->where('email', 'owner@luna.example')
            ->where('resend', '/el/signup/luna/resend')
            ->where('status', 'provisioning'));

    app()->instance(Clock::class, FixedClock::at('2026-10-09 10:00:00'));

    $this->get(centralUrl('/el/signup/verify/'.$token))->assertRedirect(centralUrl('/el/signup/luna/pending'));

    $built = (array) DB::selectOne("select status, subscription_state, locale from tenants where slug = 'luna'");

    expect($built)->toBe(['status' => 'active', 'subscription_state' => 'trial', 'locale' => 'el'])
        ->and(DB::table('pg_namespace')->where('nspname', 'tenant_luna')->exists())->toBeTrue()
        ->and(DB::table('users')->where('email', 'owner@luna.example')->whereNotNull('email_verified_at')->count())->toBe(1)
        ->and(billingFake()->asked)->toBe([]);

    $this->get(centralUrl('/el/signup/luna/pending'))->assertRedirect(tenantUrl('luna', '/admin/login'));
    $this->get(centralUrl('/el/signup/verify/'.$token))->assertRedirect(tenantUrl('luna', '/admin/login'));
});

it('welcomes a Greek row\'s owner in Greek: the welcome\'s words from lang/el through the translator in both parts, the trial\'s end spelled by the formatter in Greek in the tenant\'s clock, the offer naming the configured address, the document in Greek', function () {
    config(['site.contact_address' => 'help@kleiseradevou.test']);
    $seam = greekSeam();
    // Greek lines are laid over the loaded lang/el/mail.php to tell the test's lines from the
    // file's whatever the file holds; the date itself is the formatter's, joined to the time by
    // the file's own words, and never spelled.
    __('mail.greeting', [], 'el');
    app('translator')->addLines([
        'mail.welcome.subject' => 'GREEK WELCOME :business',
        'mail.welcome.ready' => 'GREEK :business UNTIL :end',
        'mail.welcome.offer' => 'GREEK OFFER :contact',
        'mail.welcome.zone' => 'GREEK ZONE :zone',
    ], 'el');
    $token = greekSignedUp($this, $seam, SiteLanguage::Greek);
    $formatter = new IntlDateFormatter('el', IntlDateFormatter::FULL, IntlDateFormatter::NONE, 'Europe/Athens', IntlDateFormatter::GREGORIAN, 'EEEE d MMMM y');
    $end = siteSentence('mail.time.instant', SiteLanguage::Greek, ['date' => (string) $formatter->format(new DateTimeImmutable('2026-10-16 09:00:00', new DateTimeZone('UTC'))), 'time' => '12:00']);

    $this->get(centralUrl('/el/signup/verify/'.$token))->assertRedirect(centralUrl('/el/signup/luna/pending'));

    $welcome = $seam->sent[1] ?? null;
    assert($welcome !== null);

    expect($seam->sent)->toHaveCount(2)
        ->and($welcome->kind)->toBe(PlatformMessageKind::Welcome)
        ->and($welcome->toAddress)->toBe('owner@luna.example')
        ->and($welcome->subject)->toBe('GREEK WELCOME Luna Studio')
        ->and($welcome->body)->toContain("\n\nGREEK Luna Studio UNTIL ".$end."\n\n")
        ->and($welcome->body)->toContain("\n\n".tenantUrl('luna', '/admin/login')."\n\n")
        ->and($welcome->body)->toContain("\n\n".tenantUrl('luna')."\n\n")
        ->and($welcome->body)->toContain("\n\nGREEK OFFER help@kleiseradevou.test\n\n")
        ->and($welcome->body)->toEndWith("\n\nGREEK ZONE Europe/Athens")
        ->and($welcome->body)->not->toContain('Friday')
        ->and(preg_match('/[^\x00-\x7F]/', $welcome->body))->toBe(1)
        ->and($welcome->html)->toContain('<html lang="el">')
        ->and($welcome->html)->toContain('<title>GREEK WELCOME Luna Studio</title>')
        ->and($welcome->html)->toContain('GREEK Luna Studio UNTIL '.$end)
        ->and($welcome->html)->toContain('GREEK OFFER help@kleiseradevou.test')
        ->and($welcome->html)->toContain(siteSentence('mail.welcome.reason', SiteLanguage::Greek, ['business' => 'Luna Studio']));
});

it('leaves the English signup as it was beside the Greek one: locale en, the link under /signup, the pages in lang/en\'s strings', function () {
    $seam = greekSeam();

    $this->get(centralUrl('/signup'))
        ->assertInertia(fn (Assert $page) => $page->component('signup/Signup')->where('language', 'en')->where('strings', centralStrings(SiteLanguage::English))->where('action', '/signup'));

    $token = greekSignedUp($this, $seam, SiteLanguage::English);

    expect(DB::table('tenants')->where('slug', 'luna')->value('locale'))->toBe('en')
        ->and($seam->lastLink())->toBe(centralUrl('/signup/verify/'.$token));

    $this->get(centralUrl('/signup/luna/pending'))
        ->assertInertia(fn (Assert $page) => $page->component('signup/Pending')->where('language', 'en')->where('strings', centralStrings(SiteLanguage::English))->where('resend', '/signup/luna/resend'));

    $this->get(centralUrl('/signup/verify/'.$token))->assertRedirect(centralUrl('/signup/luna/pending'));

    expect(DB::table('tenants')->where('slug', 'luna')->value('status'))->toBe('active');
});

it('writes the signup\'s language on the row from the provisioner\'s two paths, English where the signup names none', function () {
    $greek = new Signup('luna', 'Luna Studio', new DateTimeZone('Europe/Athens'), 'EUR', 'Alex Owner', 'owner@luna.example', 'correct horse battery staple', category: 'hair_salon', locale: SiteLanguage::Greek);

    app(TenantProvisioner::class)->reserve($greek);
    app(TenantProvisioner::class)->reserve(signupFor('sol'));
    app(TenantProvisioner::class)->provision(new Signup('mira', 'Mira', new DateTimeZone('Europe/Athens'), 'EUR', 'Alex Owner', 'owner@mira.example', 'correct horse battery staple', locale: SiteLanguage::Greek));

    expect(DB::table('tenants')->orderBy('slug')->pluck('locale', 'slug')->all())->toBe(['luna' => 'el', 'mira' => 'el', 'sol' => 'en']);
});

it('refuses a Greek form on its field with the Greek file\'s sentence and sends the person back to the Greek form, {case}', function (string $slug, string $name, string $field, string $key) {
    $this->from(centralUrl('/el/signup'))->post(centralUrl('/el/signup'), signupForm(['slug' => $slug, 'name' => $name]))
        ->assertRedirect(centralUrl('/el/signup'))
        ->assertSessionHasErrors([$field => signupSentence($key, SiteLanguage::Greek)]);

    expect(DB::table('tenants')->count())->toBe(0);
})->with([
    'no business name' => ['sol', '', 'name', 'errors.name'],
    'a reserved slug' => ['api', 'Luna Studio', 'slug', 'errors.slug.reserved'],
]);

it('answers a dead link under /el with the form at 410 in Greek, building nothing: {case}', function (string $path) {
    $this->get(centralUrl($path))
        ->assertStatus(410)
        ->assertInertia(fn (Assert $page) => $page->component('signup/Signup')
            ->where('language', 'el')
            ->where('refusal', signupSentence('expired', SiteLanguage::Greek))
            ->where('strings', centralStrings(SiteLanguage::Greek))
            ->where('action', '/el/signup')
            ->where('brand', ['name' => siteName()]));

    expect(DB::table('tenants')->count())->toBe(0);
})->with([
    'an unknown token' => ['/el/signup/verify/'.str_repeat('ab', 32)],
    'a malformed token' => ['/el/signup/verify/not-a-token'],
]);

it('sends a live Greek link clicked under the English path on to the Greek pending page, the row\'s language', function () {
    $seam = greekSeam();
    $token = greekSignedUp($this, $seam, SiteLanguage::Greek);

    $this->get(centralUrl('/signup/verify/'.$token))->assertRedirect(centralUrl('/el/signup/luna/pending'));

    expect(DB::table('tenants')->where('slug', 'luna')->value('status'))->toBe('active');
});

it('answers a Greek signup\'s expired link in the path\'s language, Greek under /el and English under /signup, building nothing', function () {
    $seam = greekSeam();
    $expired = greekSignedUp($this, $seam, SiteLanguage::Greek);
    app()->instance(Clock::class, FixedClock::at('2026-10-10 09:00:00'));

    $this->get(centralUrl('/el/signup/verify/'.$expired))->assertStatus(410)
        ->assertInertia(fn (Assert $page) => $page->where('language', 'el')->where('refusal', signupSentence('expired', SiteLanguage::Greek))->where('action', '/el/signup'));
    $this->get(centralUrl('/signup/verify/'.$expired))->assertStatus(410)
        ->assertInertia(fn (Assert $page) => $page->where('language', 'en')->where('refusal', signupSentence('expired', SiteLanguage::English))->where('action', '/signup'));

    expect(DB::table('tenants')->where('slug', 'luna')->value('status'))->toBe('provisioning')
        ->and(DB::table('pg_namespace')->where('nspname', 'tenant_luna')->exists())->toBeFalse();
});

it('mails a resend in the row\'s language and sends the person on to the pending page in it, whichever language\'s page posted: {case}', function (SiteLanguage $row, SiteLanguage $posted) {
    $seam = greekSeam();
    $old = greekSignedUp($this, $seam, $row);
    app()->instance(Clock::class, FixedClock::at('2026-10-09 10:00:00'));

    $this->post(centralUrl($posted->uri('/signup/luna/resend')))
        ->assertRedirect(centralUrl($row->uri('/signup/luna/pending')))
        ->assertSessionHas('flash', ['kind' => 'success', 'message' => signupSentence('resent', $posted)]);

    $new = greekToken($seam, $row);

    expect($seam->sent)->toHaveCount(2)
        ->and($new)->not->toBe($old)
        ->and(DB::table('users')->where('verification_token_hash', hash('sha256', $new))->count())->toBe(1);

    $this->get(centralUrl($posted->uri('/signup/verify/'.$old)))->assertStatus(410);
    $this->get(centralUrl($row->uri('/signup/verify/'.$new)))->assertRedirect(centralUrl($row->uri('/signup/luna/pending')));
})->with([
    'a Greek row from the English page' => [SiteLanguage::Greek, SiteLanguage::English],
    'an English row from the Greek page' => [SiteLanguage::English, SiteLanguage::Greek],
    'a Greek row from the Greek page' => [SiteLanguage::Greek, SiteLanguage::Greek],
]);

it('sends a resend that found no waiting row on to the pending page in the path\'s language, sending nothing: {case}', function (Closure $arrange, string $slug) {
    $seam = greekSeam();
    $arrange($this, $seam);
    $sent = count($seam->sent);

    $this->post(centralUrl('/el/signup/'.$slug.'/resend'))
        ->assertRedirect(centralUrl('/el/signup/'.$slug.'/pending'))
        ->assertSessionHas('flash', ['kind' => 'success', 'message' => signupSentence('resent', SiteLanguage::Greek)]);

    expect($seam->sent)->toHaveCount($sent);
})->with([
    'a slug no signup holds' => [fn () => null, 'nobody'],
    'a malformed slug' => [fn () => null, 'bad_slug'],
    'an English row already built' => [function (TestCase $test, RecordingPlatformMessages $seam): void {
        $test->get(centralUrl('/signup/verify/'.greekSignedUp($test, $seam, SiteLanguage::English)))->assertRedirect();
    }, 'luna'],
]);

it('counts both languages\' POSTs on the one signup limit, the eleventh refused at 429 with the form in the POST\'s own language, the brand and the strings', function () {
    greekSeam();

    for ($i = 0; $i < 5; $i++) {
        $this->post(centralUrl('/signup'), signupForm(['slug' => 'luna-en-'.$i, 'owner_email' => 'en'.$i.'@luna.example']))->assertRedirect();
        $this->post(centralUrl('/el/signup'), signupForm(['slug' => 'luna-el-'.$i, 'owner_email' => 'el'.$i.'@luna.example']))->assertRedirect();
    }

    $this->post(centralUrl('/el/signup'), signupForm())
        ->assertStatus(429)
        ->assertInertia(fn (Assert $page) => $page->component('signup/Signup')
            ->where('language', 'el')
            ->where('refusal', signupSentence('too_many', SiteLanguage::Greek))
            ->where('strings', centralStrings(SiteLanguage::Greek))
            ->where('action', '/el/signup')
            ->where('brand', ['name' => siteName()]));
    $this->post(centralUrl('/signup'), signupForm())
        ->assertStatus(429)
        ->assertInertia(fn (Assert $page) => $page->where('language', 'en')->where('refusal', signupSentence('too_many', SiteLanguage::English))->where('action', '/signup'));
    $this->post(centralUrl('/el/signup/luna-el-0/resend'))
        ->assertStatus(429)
        ->assertInertia(fn (Assert $page) => $page->where('language', 'el')->where('refusal', signupSentence('too_many', SiteLanguage::Greek)));

    expect(DB::table('tenants')->where('slug', 'luna')->exists())->toBeFalse()
        ->and(DB::table('tenants')->where('locale', 'el')->count())->toBe(5)
        ->and(DB::table('tenants')->where('locale', 'en')->count())->toBe(5);
});

it('frames every signup page in the site\'s header and footer in the path\'s language, the switch leading to the same page in the other language, the link\'s refusals\' to the same path and the POSTs\' to the form: {case}', function (Closure $request, string $lang, string $switch) {
    greekSeam();
    $frame = greekFrame($request($this));
    $other = SiteLanguage::from($lang)->other();

    expect($frame['lang'])->toBe($lang)
        ->and($frame['switch'])->toBe(centralUrl($switch))
        ->and($frame['label'])->toBe(__('site.language', [], $other->value))
        ->and($frame['nav'])->toBe(siteSentence('site.pages.features', SiteLanguage::from($lang)));
})->with([
    'the Greek form' => [fn (TestCase $test) => $test->get(centralUrl('/el/signup'))->assertOk(), 'el', '/signup'],
    'the English form' => [fn (TestCase $test) => $test->get(centralUrl('/signup'))->assertOk(), 'en', '/el/signup'],
    'the Greek pending page, its slug kept' => [function (TestCase $test) {
        $test->post(centralUrl('/el/signup'), signupForm())->assertRedirect();

        return $test->get(centralUrl('/el/signup/luna/pending'))->assertOk();
    }, 'el', '/signup/luna/pending'],
    'the English pending page of a Greek row' => [function (TestCase $test) {
        $test->post(centralUrl('/el/signup'), signupForm())->assertRedirect();

        return $test->get(centralUrl('/signup/luna/pending'))->assertOk();
    }, 'en', '/el/signup/luna/pending'],
    'a dead link under /el' => [fn (TestCase $test) => $test->get(centralUrl('/el/signup/verify/'.str_repeat('ab', 32)))->assertStatus(410), 'el', '/signup/verify/'.str_repeat('ab', 32)],
    'a dead link in English' => [fn (TestCase $test) => $test->get(centralUrl('/signup/verify/'.str_repeat('cd', 32)))->assertStatus(410), 'en', '/el/signup/verify/'.str_repeat('cd', 32)],
    'a malformed link in English' => [fn (TestCase $test) => $test->get(centralUrl('/signup/verify/not-a-token'))->assertStatus(410), 'en', '/el/signup/verify/not-a-token'],
    'a malformed link under /el' => [fn (TestCase $test) => $test->get(centralUrl('/el/signup/verify/not-a-token'))->assertStatus(410), 'el', '/signup/verify/not-a-token'],
    'the Greek form past the limit' => [function (TestCase $test) {
        for ($i = 0; $i < 10; $i++) {
            $test->post(centralUrl('/signup'), signupForm(['slug' => 'luna-'.$i, 'owner_email' => $i.'@luna.example']))->assertRedirect();
        }

        return $test->post(centralUrl('/el/signup'), signupForm())->assertStatus(429);
    }, 'el', '/signup'],
    'the English form past the limit' => [function (TestCase $test) {
        for ($i = 0; $i < 10; $i++) {
            $test->post(centralUrl('/el/signup'), signupForm(['slug' => 'luna-'.$i, 'owner_email' => $i.'@luna.example']))->assertRedirect();
        }

        return $test->post(centralUrl('/signup'), signupForm())->assertStatus(429);
    }, 'en', '/el/signup'],
    'a Greek resend past the limit' => [function (TestCase $test) {
        for ($i = 0; $i < 10; $i++) {
            $test->post(centralUrl('/signup'), signupForm(['slug' => 'luna-'.$i, 'owner_email' => $i.'@luna.example']))->assertRedirect();
        }

        return $test->post(centralUrl('/el/signup/luna-0/resend'))->assertStatus(429);
    }, 'el', '/signup'],
]);

it('mails the verification link in the row\'s language: a Greek row\'s words from lang/el through the translator in both parts, the document in Greek, and the English row\'s from lang/en beside it', function () {
    $seam = greekSeam();
    // Greek lines are laid over the loaded lang/el/mail.php to tell the test's lines from the
    // file's whatever the file holds.
    __('mail.greeting', [], 'el');
    app('translator')->addLines(['mail.verification.confirm' => 'GREEK CONFIRM :business AT :address:', 'mail.verification.subject' => 'GREEK SUBJECT'], 'el');

    $token = greekSignedUp($this, $seam, SiteLanguage::Greek);
    $greek = $seam->sent[0] ?? null;
    assert($greek !== null);

    expect($greek->subject)->toBe('GREEK SUBJECT')
        ->and($greek->body)->toStartWith(siteSentence('mail.greeting', SiteLanguage::Greek, ['owner' => 'Alex Owner'])."\n\nGREEK CONFIRM Luna Studio AT ".tenantUrl('luna').":\n\n".centralUrl('/el/signup/verify/'.$token)."\n\n")
        ->and($greek->body)->toContain("\n\n".mailChoice('mail.verification.window', Lifetime::hours(SignupVerification::LINK_LIFETIME), SiteLanguage::Greek)."\n\n")
        ->and($greek->body)->toContain("\n\n".mailChoice('mail.verification.trial', TenantProvisioner::trialDays(), SiteLanguage::Greek)."\n\n")
        ->and($greek->html)->toContain(e(mailChoice('mail.verification.trial', TenantProvisioner::trialDays(), SiteLanguage::Greek)))
        ->and($greek->html)->toContain('<html lang="el">')
        ->and($greek->html)->toContain('<title>GREEK SUBJECT</title>')
        ->and($greek->html)->toContain('GREEK CONFIRM Luna Studio AT '.tenantUrl('luna').':')
        ->and($greek->html)->toContain('<a href="'.centralUrl('/el/signup/verify/'.$token).'"')
        ->and($greek->html)->toContain(siteSentence('mail.verification.reason', SiteLanguage::Greek))
        ->and($greek->html)->not->toContain('<img');

    $this->post(centralUrl('/signup'), signupForm(['slug' => 'pier', 'name' => 'Pier Barbers', 'owner_email' => 'owner@pier.example']))->assertRedirect(centralUrl('/signup/pier/pending'));
    $english = $seam->sent[1] ?? null;
    assert($english !== null);

    expect($english->subject)->toBe(siteSentence('mail.verification.subject', SiteLanguage::English))
        ->and($english->body)->toContain('Confirm your email to set up Pier Barbers at '.tenantUrl('pier').'. Open this link:')
        ->and($english->body)->not->toContain('GREEK')
        ->and($english->html)->toContain('<html lang="en">')
        ->and($english->html)->not->toContain('GREEK');
});

it('hands the Greek form the terms box\'s sentence from lang/el through the translator and its two links under /el', function () {
    $this->get(centralUrl('/el/signup'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('signup/Signup')
            ->where('language', 'el')
            ->where('strings.form.terms', __('signup.form.terms', [], 'el'))
            ->missing('strings.errors')
            ->where('legal', ['terms' => centralUrl('/el/terms'), 'privacy' => centralUrl('/el/privacy')]));

    expect(__('signup.form.terms', [], 'el'))->not->toBe(__('signup.form.terms', [], 'en'))
        ->and(__('signup.errors.terms', [], 'el'))->not->toBe(__('signup.errors.terms', [], 'en'));
});

it('refuses a Greek form without the terms box on the box\'s field in Greek, writing nothing and sending nothing', function () {
    $seam = greekSeam();

    $this->from(centralUrl('/el/signup'))->post(centralUrl('/el/signup'), signupForm([SignupRequest::TERMS => '']))
        ->assertRedirect(centralUrl('/el/signup'))
        ->assertSessionHasErrors([SignupRequest::TERMS => signupSentence('errors.terms', SiteLanguage::Greek)]);

    expect(DB::table('tenants')->count())->toBe(0)
        ->and($seam->sent)->toBe([]);
});

it('records a Greek signup\'s acceptance with the same version as an English one, the day the English terms file names', function () {
    greekSeam();

    $this->post(centralUrl('/el/signup'), signupForm())->assertRedirect(centralUrl('/el/signup/luna/pending'));
    $this->post(centralUrl('/signup'), signupForm(['slug' => 'sol', 'owner_email' => 'owner@sol.example']))->assertRedirect(centralUrl('/signup/sol/pending'));

    $versions = DB::table('tenants')->orderBy('slug')->pluck('terms_version', 'slug')->all();

    expect($versions)->toBe(['luna' => app(TermsVersion::class)->day(), 'sol' => app(TermsVersion::class)->day()])
        ->and(DB::table('tenants')->where('slug', 'luna')->value('locale'))->toBe('el');
});
