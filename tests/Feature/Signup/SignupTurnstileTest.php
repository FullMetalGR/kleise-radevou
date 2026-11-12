<?php

use App\Application\Notification\PlatformMessenger;
use App\Application\Turnstile\TurnstileCheck;
use App\Http\Requests\SignupRequest;
use App\Http\TurnstileForm;
use App\Http\TurnstileGate;
use App\Models\User;
use App\Tenancy\Tenant;
use App\Tenancy\TenantStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakeTurnstileCheck;
use Tests\Support\RecordingPlatformMessages;

pest()->use(RefreshDatabase::class);

/*
 * Cloudflare Turnstile on the signup form: with both keys set the signup page alone carries the
 * widget's site key and script, and the POST asks the check about the token's field once every
 * other field has passed and before the reservation, inside the signup limiter's count; a
 * failed or missing token is the one sentence on that field with nothing written and nothing
 * sent. With either key unset the page carries no widget and the POST asks nothing. The check
 * is the fake, bound here, so no case opens a connection; the resend is behind the limiter
 * alone.
 */

const SIGNUP_TURNSTILE_SITE = '0x4AAAAAAASignupSiteKey';
const SIGNUP_TURNSTILE_SCRIPT = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';

/**
 * Sets both keys, or the ones given, and binds the fake check scripted as asked.
 */
function turnstileOn(bool $passing = true, ?string $site = SIGNUP_TURNSTILE_SITE, #[SensitiveParameter] ?string $secret = '0x4AAAAAAASignupSecretKey'): FakeTurnstileCheck
{
    config(['services.turnstile.site_key' => $site, 'services.turnstile.secret_key' => $secret]);
    $check = new FakeTurnstileCheck($passing);
    app()->instance(TurnstileCheck::class, $check);

    return $check;
}

function turnstileMessages(): RecordingPlatformMessages
{
    $seam = new RecordingPlatformMessages;
    app()->instance(PlatformMessenger::class, $seam);

    return $seam;
}

it('words the refusal, spells the script beside the keys, and is off in the test environment, which sets neither key', function () {
    expect(signupSentence('errors.check'))->toBe('The anti-spam check did not pass. Please try again.')
        ->and(TurnstileGate::FIELD)->toBe('turnstile_token')
        ->and(config('services.turnstile.script'))->toBe(SIGNUP_TURNSTILE_SCRIPT)
        ->and(config('services.turnstile.page_script'))->toBe('https://challenges.cloudflare.com/turnstile/v0/api.js')
        ->and(app(TurnstileGate::class)->on())->toBeFalse()
        ->and(app(TurnstileGate::class)->widget(TurnstileForm::Signup))->toBeNull()
        ->and(app(TurnstileGate::class)->widget(TurnstileForm::Contact))->toBeNull();
});

it('hands the signup form the site key and the script with both keys set, on the form and on the expired link\'s form', function () {
    turnstileOn();
    $widget = ['siteKey' => SIGNUP_TURNSTILE_SITE, 'script' => SIGNUP_TURNSTILE_SCRIPT];

    $this->get(centralUrl('/signup'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('signup/Signup')->where('turnstile', $widget));
    $this->get(centralUrl('/signup/verify/'.str_repeat('a', 64)))
        ->assertStatus(410)
        ->assertInertia(fn (Assert $page) => $page->component('signup/Signup')->where('turnstile', $widget));
});

it('names the widget on no other page, central or tenant, with both keys set', function () {
    turnstileOn();
    turnstileMessages();
    provisionTenant('pier');
    $this->post(centralUrl('/signup'), signupForm([TurnstileGate::FIELD => 'XXXX.DUMMY.TOKEN']))->assertRedirect(centralUrl('/signup/luna/pending'));
    $owner = User::query()->where('email', 'owner@example.com')->firstOrFail();

    $pages = [
        'the pending page' => $this->get(centralUrl('/signup/luna/pending')),
        'the hosted booking page' => $this->get(tenantUrl('pier', '/')),
        'the admin sign-in page' => $this->get(tenantUrl('pier', '/admin/login')),
        'the admin calendar' => $this->actingAs($owner)->get(tenantUrl('pier', '/admin')),
    ];

    foreach ($pages as $name => $response) {
        $response->assertOk();
        expect($response->getContent())->not->toContain('challenges.cloudflare.com', $name)
            ->and($response->getContent())->not->toContain(SIGNUP_TURNSTILE_SITE, $name);
    }
});

it('renders no widget and asks nothing while {case}, the signup reserving as before', function (?string $site, ?string $secret) {
    $check = turnstileOn(false, $site, $secret);
    $seam = turnstileMessages();

    $this->get(centralUrl('/signup'))->assertInertia(fn (Assert $page) => $page->where('turnstile', null));
    $this->post(centralUrl('/signup'), signupForm())->assertRedirect(centralUrl('/signup/luna/pending'));

    expect($check->asked)->toBe([])
        ->and(Tenant::query()->where('slug', 'luna')->value('status'))->toBe(TenantStatus::Provisioning)
        ->and($seam->sent)->toHaveCount(1);
})->with([
    'both keys are unset' => [null, null],
    'the site key alone is unset' => [null, '0x4AAAAAAASignupSecretKey'],
    'the secret key alone is unset' => [SIGNUP_TURNSTILE_SITE, null],
    'both keys are blank' => ['', ''],
]);

it('reserves the row as the verified signup does when the check passes the token, asking once about the token and the posting address', function () {
    $check = turnstileOn();
    $seam = turnstileMessages();

    $this->post(centralUrl('/signup'), signupForm([TurnstileGate::FIELD => 'XXXX.DUMMY.TOKEN']))
        ->assertRedirect(centralUrl('/signup/luna/pending'))
        ->assertSessionHasNoErrors();

    expect($check->asked)->toBe([['token' => 'XXXX.DUMMY.TOKEN', 'remoteAddress' => '127.0.0.1']])
        ->and(Tenant::query()->where('slug', 'luna')->value('status'))->toBe(TenantStatus::Provisioning)
        ->and(User::query()->where('email', 'owner@luna.example')->exists())->toBeTrue()
        ->and($seam->sent)->toHaveCount(1);
});

it('refuses the form with the one sentence on the token\'s field for {case}, writing nothing, sending nothing and flashing no token back', function (?string $token, int $asked) {
    $check = turnstileOn(false);
    $seam = turnstileMessages();

    $this->from(centralUrl('/signup'))->post(centralUrl('/signup'), signupForm($token === null ? [] : [TurnstileGate::FIELD => $token]))
        ->assertRedirect(centralUrl('/signup'))
        ->assertSessionHasErrors([TurnstileGate::FIELD => signupSentence('errors.check')])
        ->assertSessionHasInput('slug', 'luna');

    expect($check->asked)->toHaveCount($asked)
        ->and(session()->getOldInput(TurnstileGate::FIELD))->toBeNull()
        ->and(Tenant::query()->count())->toBe(0)
        ->and(User::query()->count())->toBe(0)
        ->and($seam->sent)->toBe([]);
})->with([
    'a token the check fails' => ['XXXX.DUMMY.TOKEN', 1],
    'no token' => [null, 0],
    'an empty token' => ['', 0],
    'a token longer than any Cloudflare issues' => [str_repeat('a', 2049), 0],
]);

it('asks nothing while another field is refused, the token\'s field unmarked', function () {
    $check = turnstileOn(false);

    $this->from(centralUrl('/signup'))->post(centralUrl('/signup'), signupForm(['name' => '', TurnstileGate::FIELD => 'XXXX.DUMMY.TOKEN']))
        ->assertRedirect(centralUrl('/signup'))
        ->assertSessionHasErrors(['name' => signupSentence('errors.name')])
        ->assertSessionDoesntHaveErrors([TurnstileGate::FIELD]);

    expect($check->asked)->toBe([]);
});

it('asks nothing while the terms box is unticked, every other field passing, the box among the fields the check waits on', function () {
    $check = turnstileOn(false);

    $this->from(centralUrl('/signup'))->post(centralUrl('/signup'), signupForm([SignupRequest::TERMS => '', TurnstileGate::FIELD => 'XXXX.DUMMY.TOKEN']))
        ->assertRedirect(centralUrl('/signup'))
        ->assertSessionHasErrors([SignupRequest::TERMS => signupSentence('errors.terms')])
        ->assertSessionDoesntHaveErrors([TurnstileGate::FIELD]);

    expect($check->asked)->toBe([]);
});

it('counts a refused check under the signup limiter, the eleventh POST in a minute refused at 429 before the check is asked', function () {
    $check = turnstileOn(false);

    for ($i = 0; $i < 10; $i++) {
        $this->post(centralUrl('/signup'), signupForm([TurnstileGate::FIELD => 'XXXX.DUMMY.TOKEN']))
            ->assertSessionHasErrors([TurnstileGate::FIELD => signupSentence('errors.check')]);
    }

    $this->post(centralUrl('/signup'), signupForm([TurnstileGate::FIELD => 'XXXX.DUMMY.TOKEN']))
        ->assertStatus(429)
        ->assertInertia(fn (Assert $page) => $page->component('signup/Signup')->where('refusal', signupSentence('too_many'))->where('turnstile', ['siteKey' => SIGNUP_TURNSTILE_SITE, 'script' => SIGNUP_TURNSTILE_SCRIPT]));

    expect($check->asked)->toHaveCount(10)
        ->and(Tenant::query()->count())->toBe(0);
});

it('runs the resend behind the limiter alone, asking the check nothing', function () {
    $check = turnstileOn();
    $seam = turnstileMessages();
    $this->post(centralUrl('/signup'), signupForm([TurnstileGate::FIELD => 'XXXX.DUMMY.TOKEN']))->assertRedirect(centralUrl('/signup/luna/pending'));
    $check->passing = false;

    $this->post(centralUrl('/signup/luna/resend'))
        ->assertRedirect(centralUrl('/signup/luna/pending'))
        ->assertSessionHas('flash', ['kind' => 'success', 'message' => signupSentence('resent')]);

    expect($check->asked)->toHaveCount(1)
        ->and($seam->sent)->toHaveCount(2);
});
