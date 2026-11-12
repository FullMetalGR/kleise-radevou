<?php

use App\Application\Clock;
use App\Http\ConsoleSession;
use App\Http\Middleware\RequiresOperator;
use App\Platform\Operator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FixedClock;
use Tests\TestCase;

pest()->use(RefreshDatabase::class);

/*
 * The console session's two timeouts: the door writes the sign-in instant and the last-seen
 * instant into the session it opens, from the Clock port, and the operator check reads both on
 * every request it admits. A request more than thirty minutes after the last admitted one, or more
 * than eight hours after the sign-in, ends the session and shows the door with that case's
 * sentence; any other writes the last-seen instant anew. Both are proven here on a moved clock,
 * the sign-in at 09:00 UTC on a fixed day. The framework's own session lifetime is not the guard
 * and is not read.
 */

const CONSOLE_SIGN_IN_AT = '2026-10-14 09:00';

beforeEach(function () {
    $this->withoutVite();
    app()->instance(Clock::class, FixedClock::at(CONSOLE_SIGN_IN_AT));
});

/**
 * Moves the bound clock to the sign-in instant plus the seconds given.
 */
function consoleClockAfter(int $seconds): void
{
    $signIn = new DateTimeImmutable(CONSOLE_SIGN_IN_AT, new DateTimeZone('UTC'));

    app()->instance(Clock::class, new FixedClock($signIn->add(new DateInterval('PT'.$seconds.'S'))));
}

/**
 * Signs the operator in through the console's door at the bound clock's now.
 */
function consoleDoorSignIn(TestCase $test): void
{
    addOperator();

    $test->post(consoleUrl('/login'), ['email' => 'pat@inbox.example', 'password' => 'operator password'])
        ->assertRedirect(consoleUrl('/'));
}

/**
 * Asks for a path behind the door and reads that the operator check admitted the request: the
 * home, or the console's own 404 page for a path it does not name.
 */
function consoleAdmits(TestCase $test, string $path = '/'): void
{
    $test->get(consoleUrl($path))->assertStatus($path === '/' ? 200 : 404);
    $test->assertAuthenticated(ConsoleSession::GUARD);
}

/**
 * Asks for a path behind the door and reads the lapse: the door, the sentence as an error flash, and
 * the operator signed out.
 */
function consoleRefuses(TestCase $test, string $sentence, string $path = '/'): void
{
    $test->get(consoleUrl($path))
        ->assertRedirect(consoleUrl('/login'))
        ->assertSessionHas('flash', ['kind' => 'error', 'message' => $sentence]);
    $test->assertGuest(ConsoleSession::GUARD);
}

it('writes the sign-in and the last-seen instants into the session the door opens, from the clock, as UTC text with the offset', function () {
    app()->instance(Clock::class, FixedClock::at('2026-10-14 12:00', 'Europe/Athens'));

    consoleDoorSignIn($this);

    expect(session()->get(ConsoleSession::SIGNED_IN_AT))->toBe('2026-10-14 09:00:00.000000+00:00')
        ->and(session()->get(ConsoleSession::LAST_SEEN_AT))->toBe('2026-10-14 09:00:00.000000+00:00');
});

it('writes into the session the door opens an HMAC of the operator\'s password hash under the application key, never the hash and never the password', function () {
    consoleDoorSignIn($this);
    $operator = Operator::query()->sole();
    $key = config('app.key');
    assert(is_string($key) && $key !== '');
    $fingerprint = session()->get(ConsoleSession::PASSWORD);

    expect($fingerprint)->toBe(hash_hmac('sha256', $operator->password, $key))
        ->and($fingerprint)->not->toBe($operator->password)
        ->and(json_encode(session()->all(), JSON_THROW_ON_ERROR))->not->toContain('operator password')
        ->and(json_encode(session()->all(), JSON_THROW_ON_ERROR))->not->toContain(json_encode($operator->password, JSON_THROW_ON_ERROR));
});

it('ends a live session whose fingerprint no longer matches the operator\'s password at that request, with a sentence of its own', function () {
    consoleDoorSignIn($this);
    consoleAdmits($this);

    Operator::query()->sole()->forceFill(['password' => 'changed elsewhere'])->save();
    freshGuards();

    consoleRefuses($this, RequiresOperator::PASSWORD_CHANGED);
    expect(RequiresOperator::PASSWORD_CHANGED)->toBe('You were signed out because your password was changed. Sign in with the new one.');
});

it('reads the lapse first where a session has run out and its password has changed besides', function () {
    consoleDoorSignIn($this);
    Operator::query()->sole()->forceFill(['password' => 'changed elsewhere'])->save();
    consoleClockAfter(30 * 60 + 1);

    consoleRefuses($this, RequiresOperator::IDLE);
});

it('admits a request thirty minutes after the last admitted one and counts the next thirty from it', function () {
    consoleDoorSignIn($this);

    consoleClockAfter(30 * 60);
    consoleAdmits($this);

    expect(session()->get(ConsoleSession::LAST_SEEN_AT))->toBe('2026-10-14 09:30:00.000000+00:00')
        ->and(session()->get(ConsoleSession::SIGNED_IN_AT))->toBe('2026-10-14 09:00:00.000000+00:00');

    consoleClockAfter(60 * 60);
    consoleAdmits($this);

    consoleClockAfter(90 * 60);
    consoleAdmits($this);
});

it('ends the session a request thirty minutes and one second after the last admitted one, showing the door with the idle sentence', function () {
    consoleDoorSignIn($this);

    consoleClockAfter(30 * 60);
    consoleAdmits($this);

    consoleClockAfter(60 * 60 + 1);
    consoleRefuses($this, RequiresOperator::IDLE);
});

it('judges an unknown path behind the door as it judges the home, the catch-all carrying the same check', function () {
    consoleDoorSignIn($this);

    consoleClockAfter(30 * 60 + 1);
    consoleRefuses($this, RequiresOperator::IDLE, '/nothing-here');
});

it('admits requests every twenty minutes up to eight hours after the sign-in and refuses the first past it with the ceiling\'s sentence, whatever the activity', function () {
    consoleDoorSignIn($this);

    foreach (range(1, 24) as $step) {
        consoleClockAfter($step * 20 * 60);
        consoleAdmits($this, $step % 2 === 0 ? '/' : '/nothing-here-either');
    }

    expect(session()->get(ConsoleSession::LAST_SEEN_AT))->toBe('2026-10-14 17:00:00.000000+00:00');

    consoleClockAfter(8 * 60 * 60 + 1);
    consoleRefuses($this, RequiresOperator::CEILING);
});

it('names the ceiling whenever eight hours have passed since the sign-in, whatever the idle, and the idle sentence inside them alone: {case}', function (int $lastAdmitted, int $asked, string $sentence) {
    consoleDoorSignIn($this);

    if ($lastAdmitted > 0) {
        // A chain of admitted requests up to the last one, so the idle count never runs out on the
        // way.
        foreach (range(20 * 60, $lastAdmitted, 20 * 60) as $at) {
            consoleClockAfter($at);
            consoleAdmits($this);
        }
    }

    consoleClockAfter($asked);
    consoleRefuses($this, $sentence);
})->with([
    'nine hours away after the sign-in, the idle limit reached first' => [0, 9 * 60 * 60, RequiresOperator::CEILING],
    'eight hours and a second away after the sign-in' => [0, 8 * 60 * 60 + 1, RequiresOperator::CEILING],
    'an admitted request at 7:40 and the next at 8:25, the ceiling first' => [7 * 60 * 60 + 40 * 60, 8 * 60 * 60 + 25 * 60, RequiresOperator::CEILING],
    'seven hours and a half away after the sign-in, inside the eight' => [0, 7 * 60 * 60 + 30 * 60, RequiresOperator::IDLE],
    'an admitted request at 7:20 and the next at 7:59, inside the eight' => [7 * 60 * 60 + 20 * 60, 7 * 60 * 60 + 59 * 60, RequiresOperator::IDLE],
]);

it('treats a session naming an operator but {case} as expired', function (array $instants) {
    $this->actingAs(addOperator(), ConsoleSession::GUARD)->withSession($instants);

    consoleRefuses($this, RequiresOperator::IDLE);
})->with([
    'carrying neither instant' => [[]],
    'carrying the sign-in instant alone' => [[ConsoleSession::SIGNED_IN_AT => '2026-10-14 09:00:00.000000+00:00']],
    'carrying the last-seen instant alone' => [[ConsoleSession::LAST_SEEN_AT => '2026-10-14 09:00:00.000000+00:00']],
    'carrying an instant it cannot read' => [[ConsoleSession::SIGNED_IN_AT => 'yesterday', ConsoleSession::LAST_SEEN_AT => '2026-10-14 09:00:00.000000+00:00']],
    'carrying both instants fresh and no password fingerprint' => [[ConsoleSession::SIGNED_IN_AT => '2026-10-14 09:00:00.000000+00:00', ConsoleSession::LAST_SEEN_AT => '2026-10-14 09:00:00.000000+00:00']],
]);

it('names the ceiling for a session carrying a readable sign-in instant eight hours old and no last-seen instant it can read', function () {
    $this->actingAs(addOperator(), ConsoleSession::GUARD)->withSession([ConsoleSession::SIGNED_IN_AT => '2026-10-14 00:59:59.000000+00:00', ConsoleSession::LAST_SEEN_AT => 'yesterday']);

    consoleRefuses($this, RequiresOperator::CEILING);
});

it('brings a lapsed session back to the page it asked for once the operator signs in again', function () {
    consoleDoorSignIn($this);
    consoleClockAfter(30 * 60 + 1);

    consoleRefuses($this, RequiresOperator::IDLE, '/audit?tenant=luna');
    $this->get(consoleUrl('/login'))->assertOk();

    $this->post(consoleUrl('/login'), ['email' => 'pat@inbox.example', 'password' => 'operator password'])
        ->assertRedirect(consoleUrl('/audit?tenant=luna'));
});

it('lands the sign-in after a write a lapsed session posted on the home, a write being no page', function () {
    consoleDoorSignIn($this);
    consoleClockAfter(30 * 60 + 1);

    $this->post(consoleUrl('/tenants/luna/resume'))
        ->assertRedirect(consoleUrl('/login'))
        ->assertSessionHas('flash', ['kind' => 'error', 'message' => RequiresOperator::IDLE]);

    $this->post(consoleUrl('/login'), ['email' => 'pat@inbox.example', 'password' => 'operator password'])
        ->assertRedirect(consoleUrl('/'));
});

it('admits a session the helper opens at the clock\'s now, as the door opens one', function () {
    $this->actingAs(addOperator(), ConsoleSession::GUARD)->withSession(freshConsoleSession());

    consoleAdmits($this);
});

it('ends a lapsed session outright: the guard signed out, the session and its token renewed, the door shown with the sentence as an error toast', function () {
    consoleDoorSignIn($this);
    $id = session()->getId();
    $token = session()->token();

    consoleClockAfter(30 * 60 + 1);

    $this->followingRedirects()
        ->get(consoleUrl('/'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('console/Login')
            ->where('operator', null)
            ->where('flash', ['kind' => 'error', 'message' => RequiresOperator::IDLE]));

    $this->assertGuest(ConsoleSession::GUARD);

    expect(session()->getId())->not->toBe($id)
        ->and(session()->token())->not->toBe($token)
        ->and(session()->has(ConsoleSession::SIGNED_IN_AT))->toBeFalse()
        ->and(session()->has(ConsoleSession::LAST_SEEN_AT))->toBeFalse();

    // Back at the home, the visitor is a guest and the door says nothing more.
    $this->get(consoleUrl('/'))
        ->assertRedirect(consoleUrl('/login'))
        ->assertSessionMissing('flash');
});

it('sends a visitor with no operator to the door with no sentence, whatever the instants their session carries', function () {
    $this->withSession([ConsoleSession::SIGNED_IN_AT => '2026-10-13 09:00:00.000000+00:00', ConsoleSession::LAST_SEEN_AT => '2026-10-13 09:00:00.000000+00:00'])
        ->get(consoleUrl('/'))
        ->assertRedirect(consoleUrl('/login'))
        ->assertSessionMissing('flash');
});
