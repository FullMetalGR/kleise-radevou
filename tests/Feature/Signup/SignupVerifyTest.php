<?php

use App\Application\Clock;
use App\Application\Notification\PlatformMessageKind;
use App\Application\Notification\PlatformMessenger;
use App\Billing\Entitlements;
use App\Domain\Lifetime;
use App\Http\Controllers\Central\SignupVerifyController;
use App\Http\Middleware\KeepLinkTokensOutOfTheSession;
use App\Http\SignupHolder;
use App\Site\SignupLinks;
use App\Site\SiteLanguage;
use App\Tenancy\SignupVerification;
use App\Tenancy\TenantProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\Support\FixedClock;
use Tests\Support\RecordingPlatformMessages;
use Tests\Support\RefusingTransport;
use Tests\Support\SeamLog;
use Tests\TestCase;

pest()->use(RefreshDatabase::class);

/*
 * The verified signup: the POST reserves the row with the owner and the hash of a link, the link
 * goes out through the platform's seam once the reservation commits, and the click inside 24
 * hours builds the schema as a trial and stamps the owner verified. No billing provider is asked
 * anything at any step.
 *
 * The welcome email: the click that builds sends the owner one welcome through the platform's
 * seam after the build has committed, at once and never queued, a branded mail in the row's
 * language naming the business, its two addresses, the trial's end in the tenant's clock, three
 * first steps and the set-up offer with the configured contact address; a click that builds
 * nothing and a resend send none, and a transport that fails is the seam's logged error, the
 * click's answer unchanged.
 */

/**
 * One line of the welcome email as lang/en/mail.php holds it, its placeholders filled.
 *
 * @param  array<string, string>  $replace
 */
function welcomeLine(string $key, array $replace = []): string
{
    return siteSentence('mail.welcome.'.$key, SiteLanguage::English, $replace);
}

function verifyMessages(): RecordingPlatformMessages
{
    $seam = new RecordingPlatformMessages;
    app()->instance(PlatformMessenger::class, $seam);

    return $seam;
}

/**
 * Signs up as luna at the pinned instant and answers the clear token the link carried.
 */
function verifySignedUp(TestCase $test, RecordingPlatformMessages $seam, string $at = '2026-10-09 09:00:00'): string
{
    app()->instance(Clock::class, FixedClock::at($at));
    $test->post(centralUrl('/signup'), signupForm())->assertRedirect(centralUrl('/signup/luna/pending'));

    return substr($seam->lastLink(), -64);
}

/**
 * The row and its owner as the click leaves them.
 *
 * @return array<mixed>
 */
function verifyRow(): array
{
    return (array) DB::selectOne(
        "select t.status, t.subscription_state, t.entitlements::text as entitlements, t.category,
                to_char(t.trial_ends_at at time zone 'UTC', 'YYYY-MM-DD HH24:MI:SS') as trial_ends_at,
                to_char(u.email_verified_at at time zone 'UTC', 'YYYY-MM-DD HH24:MI:SS') as verified_at,
                u.verification_token_hash as hash,
                (select count(*) from pg_namespace where nspname = 'tenant_luna') as schemas
         from tenants t join users u on u.tenant_id = t.id where t.slug = 'luna'",
    );
}

it('reserves the row with its category and the owner, keeps the hash of the link alone, mails the link once through the platform seam, marks the holder and asks no provider', function () {
    $seam = verifyMessages();
    $token = verifySignedUp($this, $seam);

    $message = $seam->sent[0] ?? null;
    assert($message !== null);
    expect($seam->sent)->toHaveCount(1)
        ->and($message->kind)->toBe(PlatformMessageKind::Verification)
        ->and($message->toAddress)->toBe('owner@luna.example')
        ->and($message->toName)->toBe('Alex Owner')
        ->and($message->subject)->toBe('Confirm your email to set up your booking page')
        ->and($message->subject)->toBe(siteSentence('mail.verification.subject', SiteLanguage::English))
        ->and($message->body)->toBe(implode("\n", [
            'Hello Alex Owner,',
            '',
            'Confirm your email to set up Luna Studio at '.tenantUrl('luna').'. Open this link:',
            '',
            centralUrl('/signup/verify/'.$token),
            '',
            mailChoice('mail.verification.window', Lifetime::hours(SignupVerification::LINK_LIFETIME)),
            '',
            mailChoice('mail.verification.trial', TenantProvisioner::trialDays()),
            '',
            'If you did not sign up, ignore this email and nothing is set up.',
        ]))
        ->and($message->body)->not->toContain('correct horse battery staple')
        ->and($message->html)->toContain('<html lang="en">')
        ->and($message->html)->toContain('<title>Confirm your email to set up your booking page</title>')
        ->and($message->html)->toContain('<a href="'.centralUrl('/signup/verify/'.$token).'"')
        ->and($message->html)->toContain(e(mailChoice('mail.verification.window', Lifetime::hours(SignupVerification::LINK_LIFETIME))))
        ->and($message->html)->toContain(siteSentence('mail.verification.reason', SiteLanguage::English))
        ->and($message->html)->toContain(siteSentence('mail.layout.sent_by', SiteLanguage::English))
        ->and($message->html)->not->toContain('<img')
        ->and($message->html)->not->toContain('correct horse battery staple')
        ->and($message->replyTo)->toBeNull()
        ->and(verifyRow())->toMatchArray([
            'status' => 'provisioning',
            'subscription_state' => 'incomplete',
            'entitlements' => '{}',
            'category' => 'hair_salon',
            'trial_ends_at' => null,
            'verified_at' => null,
            'hash' => hash('sha256', $token),
            'schemas' => 0,
        ])
        ->and(DB::table('users')->where('verification_token_hash', $token)->exists())->toBeFalse()
        ->and((array) DB::selectOne('select name, role from users'))->toBe(['name' => 'Alex Owner', 'role' => 'owner'])
        ->and(DB::table('subscriptions')->count())->toBe(0)
        ->and(billingFake()->asked)->toBe([]);

    $this->get(centralUrl('/signup/luna/pending'))
        ->assertInertia(fn (Assert $page) => $page->component('signup/Pending')->where('verified', false)->where('email', null)->where('business', null));
    $this->withCookie(SignupHolder::COOKIE, 'luna')->get(centralUrl('/signup/luna/pending'))
        ->assertInertia(fn (Assert $page) => $page->component('signup/Pending')->where('verified', false)->where('email', 'owner@luna.example'));
});

it('answers an Inertia visit posting the form with a plain redirect to the pending page, never an external location', function () {
    verifyMessages();
    app()->instance(Clock::class, FixedClock::at('2026-10-09 09:00:00'));

    $this->from(centralUrl('/signup'))
        ->withHeaders(['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'])
        ->post(centralUrl('/signup'), signupForm())
        ->assertStatus(302)
        ->assertRedirect(centralUrl('/signup/luna/pending'))
        ->assertHeaderMissing('X-Inertia-Location')
        ->assertCookie(SignupHolder::COOKIE);
});

it('builds the schema as a trial on the click inside 24 hours, stamps the owner verified, keeps the hash as the used link, and lands on the pending page, which sends on to sign-in', function () {
    $seam = verifyMessages();
    $token = verifySignedUp($this, $seam);
    app()->instance(Clock::class, FixedClock::at('2026-10-10 08:59:59'));

    $this->get(centralUrl('/signup/verify/'.$token))->assertRedirect(centralUrl('/signup/luna/pending'));

    expect(verifyRow())->toMatchArray([
        'status' => 'active',
        'subscription_state' => 'trial',
        'entitlements' => '{'.implode(',', Entitlements::all()->toSlugs()).'}',
        'trial_ends_at' => '2026-10-17 08:59:59',
        'verified_at' => '2026-10-10 08:59:59',
        'hash' => hash('sha256', $token),
        'schemas' => 1,
    ])
        ->and(DB::table('tenant_luna.migrations')->count())->toBe(38)
        ->and(DB::table('tenant_luna.settings')->count())->toBe(1)
        ->and(DB::table('tenant_luna.staff')->where('is_implicit', true)->count())->toBe(1)
        ->and(DB::table('subscriptions')->count())->toBe(0)
        ->and(billingFake()->asked)->toBe([]);

    $this->get(centralUrl('/signup/luna/pending'))->assertRedirect(tenantUrl('luna', '/admin/login'));
});

it('sends the owner one welcome email after the building click, at once and never queued, naming the business, its sign-in and booking page addresses, the trial\'s end in the tenant\'s clock with the zone named, three first steps and the set-up offer with the configured contact address, with the same words as HTML in the layout', function () {
    config(['site.contact_address' => 'help@kleiseradevou.test']);
    // The jobs table is central, and a file that truncates rather than rolls back may have left a
    // row there before this one ran, so the queue is read as what this case added to it.
    $queued = DB::table('jobs')->count();
    $seam = verifyMessages();
    $token = verifySignedUp($this, $seam);
    app()->instance(Clock::class, FixedClock::at('2026-10-10 08:59:59'));

    $this->get(centralUrl('/signup/verify/'.$token))->assertRedirect(centralUrl('/signup/luna/pending'));

    $tenantId = DB::table('tenants')->where('slug', 'luna')->value('id');
    $end = 'Saturday 17 October 2026 at 11:59';
    $welcome = $seam->sent[1] ?? null;
    assert($welcome !== null);

    expect($seam->sent)->toHaveCount(2)
        ->and($welcome->kind)->toBe(PlatformMessageKind::Welcome)
        ->and($welcome->tenantId)->toBe($tenantId)
        ->and($welcome->toAddress)->toBe('owner@luna.example')
        ->and($welcome->toName)->toBe('Alex Owner')
        ->and($welcome->replyTo)->toBeNull()
        ->and($welcome->subject)->toBe(welcomeLine('subject', ['business' => 'Luna Studio']))
        ->and($welcome->subject)->toContain('Luna Studio')
        ->and($welcome->body)->toBe(implode("\n", [
            siteSentence('mail.greeting', SiteLanguage::English, ['owner' => 'Alex Owner']),
            '',
            welcomeLine('ready', ['business' => 'Luna Studio', 'end' => $end]),
            '',
            welcomeLine('sign_in'),
            '',
            tenantUrl('luna', '/admin/login'),
            '',
            welcomeLine('page'),
            '',
            tenantUrl('luna'),
            '',
            welcomeLine('steps'),
            '',
            welcomeLine('step_services'),
            '',
            welcomeLine('step_hours'),
            '',
            welcomeLine('step_share'),
            '',
            welcomeLine('offer', ['contact' => 'help@kleiseradevou.test']),
            '',
            welcomeLine('zone', ['zone' => 'Europe/Athens']),
        ]))
        ->and($welcome->body)->toContain('Luna Studio')
        ->and($welcome->body)->toContain($end)
        ->and($welcome->body)->toContain('help@kleiseradevou.test')
        ->and($welcome->body)->toContain('Europe/Athens')
        ->and($welcome->body)->not->toContain('correct horse battery staple')
        ->and($welcome->body)->not->toContain('mail.welcome')
        ->and($welcome->html)->toContain('<html lang="en">')
        ->and($welcome->html)->toContain('<title>'.$welcome->subject.'</title>')
        ->and($welcome->html)->toContain('<a href="'.tenantUrl('luna', '/admin/login').'"')
        ->and($welcome->html)->toContain('<a href="'.tenantUrl('luna').'"')
        ->and($welcome->html)->toContain(e(welcomeLine('ready', ['business' => 'Luna Studio', 'end' => $end])))
        ->and($welcome->html)->toContain(e(welcomeLine('offer', ['contact' => 'help@kleiseradevou.test'])))
        ->and($welcome->html)->toContain(welcomeLine('zone', ['zone' => 'Europe/Athens']))
        ->and($welcome->html)->toContain(e(welcomeLine('reason', ['business' => 'Luna Studio'])))
        ->and($welcome->html)->toContain(siteSentence('mail.layout.sent_by', SiteLanguage::English))
        ->and($welcome->html)->not->toContain('<img')
        ->and($welcome->html)->not->toContain('correct horse battery staple')
        ->and(DB::table('jobs')->count())->toBe($queued);
});

it('logs a welcome the transport refused as the seam logs every kind, the kind and the tenant\'s id and never the address, the click\'s answer and the build unchanged', function () {
    $seam = verifyMessages();
    $token = verifySignedUp($this, $seam);
    app()->instance(Clock::class, FixedClock::at('2026-10-10 08:00:00'));
    // The welcome goes through the real seam onto a transport that refuses every send; the
    // recorder bound for the link is forgotten, so the seam's binding resolves again.
    app()->forgetInstance(PlatformMessenger::class);
    $transport = new RefusingTransport;
    seamMailer()->setSymfonyTransport($transport);
    $log = new SeamLog;
    Log::swap($log);

    $this->get(centralUrl('/signup/verify/'.$token))->assertRedirect(centralUrl('/signup/luna/pending'));

    expect($transport->refused)->toHaveCount(1)
        ->and(verifyRow())->toMatchArray(['status' => 'active', 'subscription_state' => 'trial', 'verified_at' => '2026-10-10 08:00:00', 'schemas' => 1])
        ->and($log->errors())->toHaveCount(1)
        ->and($log->errors()[0]['context'])->toBe([
            'tenant' => DB::table('tenants')->where('slug', 'luna')->value('id'),
            'kind' => 'welcome',
            'exception' => TransportException::class,
        ])
        ->and($log->text())->not->toContain('owner@luna.example')
        ->and($log->text())->not->toContain('Alex Owner');
});

it('answers the signup form with the expired sentence and builds nothing for {case}', function (Closure $arrange) {
    $seam = verifyMessages();
    $token = $arrange(verifySignedUp($this, $seam));
    assert(is_string($token));

    $this->get(centralUrl('/signup/verify/'.$token))
        ->assertStatus(410)
        ->assertInertia(fn (Assert $page) => $page->component('signup/Signup')->where('refusal', signupSentence('expired')));

    expect(DB::table('pg_namespace')->where('nspname', 'tenant_luna')->exists())->toBeFalse()
        ->and(DB::table('tenants')->where('slug', 'luna')->value('status'))->not->toBe('active');
})->with([
    'a link 24 hours old' => [function (string $token): string {
        app()->instance(Clock::class, FixedClock::at('2026-10-10 09:00:00'));

        return $token;
    }],
    'a swept row' => [function (string $token): string {
        app()->instance(Clock::class, FixedClock::at('2026-10-10 09:00:01'));
        Artisan::call('signup:sweep');

        return $token;
    }],
    'an unknown token' => [fn (): string => str_repeat('ab', 32)],
    'a tampered token' => [fn (string $token): string => substr($token, 0, 63).($token[63] === 'a' ? 'b' : 'a')],
    'a token of the wrong shape' => [fn (string $token): string => strtoupper($token)],
    'a token too short' => [fn (string $token): string => substr($token, 0, 63)],
    'a token too long' => [fn (string $token): string => $token.'a'],
]);

it('sends every later click on a built row\'s link to the sign-in page, a week on as much as at once, building nothing more and sending no second welcome', function (string $later) {
    $seam = verifyMessages();
    $token = verifySignedUp($this, $seam);
    $this->get(centralUrl('/signup/verify/'.$token))->assertRedirect(centralUrl('/signup/luna/pending'));
    app()->instance(Clock::class, FixedClock::at($later));

    $this->get(centralUrl('/signup/verify/'.$token))->assertRedirect(tenantUrl('luna', '/admin/login'));

    expect(DB::table('tenant_luna.migrations')->count())->toBe(38)
        ->and(verifyRow())->toMatchArray(['status' => 'active', 'verified_at' => '2026-10-09 09:00:00', 'hash' => hash('sha256', $token)])
        ->and(array_map(fn ($message): PlatformMessageKind => $message->kind, $seam->sent))->toBe([PlatformMessageKind::Verification, PlatformMessageKind::Welcome]);
})->with(['at once' => '2026-10-09 09:00:00', 'a week on' => '2026-10-16 09:00:00']);

it('constrains the link\'s token on the route to 64 hex characters, at the HTTP boundary', function () {
    $route = Route::getRoutes()->getByName('signup.verify');
    assert($route !== null);

    expect($route->wheres)->toBe(['token' => SignupVerifyController::TOKEN]);
});

/**
 * Posts the pending page's Resend for the slug and asserts the one answer every case reads.
 */
function verifyResend(TestCase $test, string $slug): void
{
    $test->post(centralUrl('/signup/'.$slug.'/resend'))
        ->assertRedirect(centralUrl('/signup/'.$slug.'/pending'))
        ->assertSessionHas('flash', ['kind' => 'success', 'message' => signupSentence('resent')]);
}

it('replaces the link of a row still waiting on its click and mails the new one, the old link expired from then on', function () {
    $seam = verifyMessages();
    $old = verifySignedUp($this, $seam);
    app()->instance(Clock::class, FixedClock::at('2026-10-10 08:00:00'));

    verifyResend($this, 'luna');

    $new = substr($seam->lastLink(), -64);
    expect($seam->sent)->toHaveCount(2)
        ->and($seam->sent[1]->toAddress ?? null)->toBe('owner@luna.example')
        ->and($new)->not->toBe($old)
        ->and(verifyRow())->toMatchArray(['status' => 'provisioning', 'verified_at' => null, 'hash' => hash('sha256', $new)])
        ->and(signupSentence('resent'))->toBe('If that signup is still waiting, a new link is on its way.');

    $this->get(centralUrl('/signup/verify/'.$old))->assertStatus(410);
    $this->get(centralUrl('/signup/verify/'.$new))->assertRedirect(centralUrl('/signup/luna/pending'));
    expect(verifyRow())->toMatchArray(['status' => 'active', 'hash' => hash('sha256', $new)]);
});

it('answers the same sentence and sends nothing for {case}', function (Closure $arrange, string $slug) {
    $seam = verifyMessages();
    $token = verifySignedUp($this, $seam);
    $arrange($this, $token);
    $before = verifyRow();
    $sent = count($seam->sent);

    verifyResend($this, $slug);

    expect($seam->sent)->toHaveCount($sent)
        ->and(DB::table('users')->count())->toBe(1)
        ->and(verifyRow())->toBe($before);
})->with([
    'a built row' => [fn (TestCase $test, string $token) => $test->get(centralUrl('/signup/verify/'.$token))->assertRedirect(), 'luna'],
    'a row past its 24 hours' => [fn () => app()->instance(Clock::class, FixedClock::at('2026-10-10 09:00:00')), 'luna'],
    'a slug no signup holds' => [fn () => null, 'nobody'],
    'a malformed slug' => [fn () => null, 'bad_slug'],
]);

it('counts the resend with the signup POST under the one limiter, the eleventh in a minute refused at 429', function () {
    verifyMessages();
    app()->instance(Clock::class, FixedClock::at('2026-10-09 09:00:00'));

    for ($i = 0; $i < 5; $i++) {
        $this->post(centralUrl('/signup/luna/resend'))->assertRedirect();
        $this->post(centralUrl('/signup'), signupForm(['slug' => 'luna_'.$i]))->assertRedirect();
    }

    $this->post(centralUrl('/signup/luna/resend'))
        ->assertStatus(429)
        ->assertInertia(fn (Assert $page) => $page->component('signup/Signup')->where('refusal', signupSentence('too_many')));
    $this->post(centralUrl('/signup'), signupForm())->assertStatus(429);
});

it('keeps the verification link\'s URL out of the session\'s previous-URL slot, on the link, on its malformed twin and under /el alike', function () {
    $token = str_repeat('ab', 32);
    $this->get(centralUrl('/signup'))->assertOk();
    $before = session()->previousUrl();

    $this->get(centralUrl('/signup/verify/'.$token))->assertStatus(410);
    $this->get(centralUrl('/signup/verify/'.strtoupper($token)))->assertStatus(410);
    $this->get(centralUrl('/el/signup/verify/'.$token))->assertStatus(410);
    $this->get(centralUrl('/el/signup/verify/'.strtoupper($token)))->assertStatus(410);

    expect($before)->toBe(centralUrl('/signup'))
        ->and(session()->previousUrl())->toBe(centralUrl('/signup'))
        ->and(json_encode(session()->all(), JSON_THROW_ON_ERROR))->not->toContain($token)->not->toContain(strtoupper($token))
        ->and(KeepLinkTokensOutOfTheSession::ROUTES)->toContain(SignupLinks::VERIFY, SignupLinks::VERIFY_MALFORMED);
});
