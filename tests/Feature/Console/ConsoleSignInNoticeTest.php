<?php

use App\Application\Clock;
use App\Application\Notification\PlatformMessage;
use App\Application\Notification\PlatformMessageKind;
use App\Application\Notification\PlatformMessenger;
use App\Http\ConsoleSession;
use App\Http\Controllers\Console\LoginController;
use App\Notifications\OperatorSignedIn;
use App\Platform\ConsoleTime;
use App\Providers\AppServiceProvider;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\Support\FixedClock;
use Tests\Support\RecordingPlatformMessages;
use Tests\Support\RefusingTransport;
use Tests\Support\SeamLog;

pest()->use(RefreshDatabase::class);

/*
 * The sign-in notice: every successful sign-in at the console mails the operator at their own
 * address, in the platform's name through the platform's seam, naming the instant in the
 * console's zone and the address the request came from, so a stolen password shows itself the
 * first time somebody uses it. A refused sign-in, and one past the door's limit, mails nothing.
 * The notice is about no tenant, so its message names none. The seam is the recorder here, but
 * for the case that proves a transport's failure leaves the sign-in standing.
 */

beforeEach(function () {
    $this->withoutVite();
    app()->instance(Clock::class, FixedClock::at('2026-10-14 09:00'));
});

/**
 * The platform's seam as the recorder, bound for the rest of the test.
 */
function operatorNoticeSeam(): RecordingPlatformMessages
{
    $seam = new RecordingPlatformMessages;
    app()->instance(PlatformMessenger::class, $seam);

    return $seam;
}

it('mails the operator one notice of a sign-in, at their own address, naming the instant in the console\'s zone and the address the request came from', function () {
    $seam = operatorNoticeSeam();
    addOperator();

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
        ->post(consoleUrl('/login'), ['email' => 'Pat@Inbox.EXAMPLE', 'password' => 'operator password'])
        ->assertRedirect(consoleUrl('/'));

    $notices = $seam->sent;

    expect($notices)->toHaveCount(1);

    $notice = $notices[0];

    expect($notice->kind)->toBe(PlatformMessageKind::OperatorSignIn)
        ->and($notice->tenantId)->toBeNull()
        ->and($notice->toAddress)->toBe('pat@inbox.example')
        ->and($notice->toName)->toBe('Pat Operator')
        ->and($notice->subject)->toBe('A sign-in to the '.siteName().' console')
        ->and($notice->html)->toBeNull()
        ->and($notice->replyTo)->toBeNull()
        ->and($notice->body)->toBe(implode("\n", [
            'Hello Pat Operator,',
            '',
            'You signed in to the '.siteName().' console on Wednesday 14 October 2026 at 12:00 (Europe/Athens), from the address 203.0.113.7.',
            '',
            OperatorSignedIn::NOT_YOU,
        ]))
        ->and(OperatorSignedIn::NOT_YOU)->toBe('If this was not you, someone else knows your password: change it at once with the console:set-operator-password command.');
});

it('names the console in the public name the configuration holds, in the subject and in the body', function () {
    config(['site.name' => 'Example Bookings']);
    $seam = operatorNoticeSeam();
    addOperator();

    $this->post(consoleUrl('/login'), ['email' => 'pat@inbox.example', 'password' => 'operator password'])
        ->assertRedirect(consoleUrl('/'));

    expect($seam->sent)->toHaveCount(1)
        ->and($seam->sent[0]->subject)->toBe('A sign-in to the Example Bookings console')
        ->and($seam->sent[0]->body)->toContain('You signed in to the Example Bookings console on ');
});

it('mails one notice for each sign-in, a refused attempt between them adding none', function () {
    $seam = operatorNoticeSeam();
    addOperator();

    $this->post(consoleUrl('/login'), ['email' => 'pat@inbox.example', 'password' => 'operator password'])->assertRedirect(consoleUrl('/'));
    $this->post(consoleUrl('/logout'))->assertRedirect(consoleUrl('/login'));
    $this->post(consoleUrl('/login'), ['email' => 'pat@inbox.example', 'password' => 'not the password'])->assertSessionHasErrors('email');
    $this->post(consoleUrl('/login'), ['email' => 'pat@inbox.example', 'password' => 'operator password'])->assertRedirect(consoleUrl('/'));

    expect(array_map(fn (PlatformMessage $notice): string => $notice->kind->value.' '.$notice->toAddress, $seam->sent))
        ->toBe(['operator_sign_in pat@inbox.example', 'operator_sign_in pat@inbox.example']);
});

it('names the instant in the zone the configuration names', function () {
    $seam = operatorNoticeSeam();
    config(['console.timezone' => 'America/New_York']);
    addOperator();

    $this->post(consoleUrl('/login'), ['email' => 'pat@inbox.example', 'password' => 'operator password'])->assertRedirect(consoleUrl('/'));

    expect($seam->sent)->toHaveCount(1)
        ->and($seam->sent[0]->body)->toContain('Wednesday 14 October 2026 at 05:00 (America/New_York)');
});

it('holds the console\'s zone in config/console.php, Europe/Athens by default, and reads it through the one helper', function () {
    expect(config('console.timezone'))->toBe('Europe/Athens')
        ->and(app(ConsoleTime::class)->zone()->getName())->toBe('Europe/Athens');
});

it('refuses a zone the configuration misspells when the application boots, so the deploy fails rather than the door\'s first request (03f)', function () {
    config(['console.timezone' => 'Europe/Atlantis']);
    $provider = app()->getProvider(AppServiceProvider::class);
    assert($provider instanceof AppServiceProvider);

    expect(fn () => $provider->boot())->toThrow(DateInvalidTimeZoneException::class);
});

it('mails nothing on a refused sign-in: {case}', function (array $form) {
    $seam = operatorNoticeSeam();
    addOperator();
    $tenant = Tenant::factory()->create(['slug' => 'acme']);
    DB::table('users')->insert(['tenant_id' => $tenant->id, 'name' => 'Alex Owner', 'email' => 'owner@acme.example', 'password' => bcrypt('owner password'), 'role' => 'owner']);

    $this->post(consoleUrl('/login'), $form)->assertSessionHasErrors('email');

    $this->assertGuest(ConsoleSession::GUARD);

    expect($seam->sent)->toBe([]);
})->with([
    'a wrong password' => [['email' => 'pat@inbox.example', 'password' => 'not the password']],
    'an email no operator holds' => [['email' => 'nobody@inbox.example', 'password' => 'operator password']],
    'a tenant user\'s own pair' => [['email' => 'owner@acme.example', 'password' => 'owner password']],
    'an empty form' => [[]],
]);

it('mails nothing past the door\'s limit, the right pair included', function () {
    $seam = operatorNoticeSeam();
    addOperator();

    foreach (range(1, 10) as $attempt) {
        $this->post(consoleUrl('/login'), ['email' => 'pat@inbox.example', 'password' => 'not the password'])->assertRedirect();
    }

    $this->post(consoleUrl('/login'), ['email' => 'pat@inbox.example', 'password' => 'operator password'])
        ->assertStatus(429)
        ->assertSee(LoginController::TOO_MANY);

    $this->assertGuest(ConsoleSession::GUARD);

    expect($seam->sent)->toBe([]);
});

it('signs the operator in whatever the transport does, a failed notice logged against the kind alone, the tenant null and the address never', function () {
    config(['mail.from.address' => 'hello@kleiseradevou.test', 'mail.from.name' => siteName()]);
    $log = new SeamLog;
    Log::swap($log);
    seamMailer()->setSymfonyTransport(new RefusingTransport);
    $operator = addOperator();

    $this->post(consoleUrl('/login'), ['email' => 'pat@inbox.example', 'password' => 'operator password'])
        ->assertRedirect(consoleUrl('/'));

    $this->assertAuthenticatedAs($operator, ConsoleSession::GUARD);

    expect($log->errors())->toHaveCount(1)
        ->and($log->errors()[0]['context'])->toBe(['tenant' => null, 'kind' => 'operator_sign_in', 'exception' => TransportException::class])
        ->and($log->text())->not->toContain('pat@inbox.example');
});
