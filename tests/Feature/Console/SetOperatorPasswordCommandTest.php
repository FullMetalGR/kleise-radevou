<?php

use App\Console\Commands\SetOperatorPasswordCommand;
use App\Http\Middleware\RequiresOperator;
use App\Http\Requests\PasswordRule;
use App\Platform\Operator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;

pest()->use(RefreshDatabase::class);

/*
 * The console command that changes an operator's password: the sign-in notice tells an operator
 * who did not sign in to change the password at once, and this is the way. The password is
 * judged by PasswordRule as console:add-operator judges it, an email no operator holds is
 * refused in one line, and the password is never printed.
 */

/**
 * Runs the command with the options given and answers its exit code and everything it printed.
 *
 * @param  array<string, string>  $options
 * @return array{int, string}
 */
function setOperatorPassword(array $options): array
{
    $code = Artisan::call('console:set-operator-password', $options);

    return [$code, Artisan::output()];
}

it('sets the password of the operator the email names under the rule and says so in one line, the password printed nowhere', function () {
    $operator = addOperator();
    $other = addOperator('sam@inbox.example', 'the other password', 'Sam Operator');

    [$code, $output] = setOperatorPassword(['--email' => 'pat@inbox.example', '--password' => 'a fresh long password']);

    $operator->refresh();
    $other->refresh();

    expect($code)->toBe(0)
        ->and($output)->toBe("Set a new password for pat@inbox.example.\n")
        ->and($output)->not->toContain('a fresh long password')
        ->and(Hash::check('a fresh long password', $operator->password))->toBeTrue()
        ->and(Hash::check('operator password', $operator->password))->toBeFalse()
        ->and(password_get_info($operator->password)['algoName'])->toBe('bcrypt')
        ->and(Hash::check('the other password', $other->password))->toBeTrue();
});

it('ends that operator\'s console session at its next request and no other operator\'s', function () {
    addOperator();
    addOperator('sam@inbox.example', 'the other password', 'Sam Operator');

    $this->post(consoleUrl('/login'), ['email' => 'sam@inbox.example', 'password' => 'the other password'])->assertRedirect(consoleUrl('/'));
    setOperatorPassword(['--email' => 'pat@inbox.example', '--password' => 'a fresh long password']);

    $this->get(consoleUrl('/'))->assertOk();

    $this->post(consoleUrl('/logout'));
    $this->post(consoleUrl('/login'), ['email' => 'pat@inbox.example', 'password' => 'a fresh long password'])->assertRedirect(consoleUrl('/'));
    $this->get(consoleUrl('/'))->assertOk();

    setOperatorPassword(['--email' => 'pat@inbox.example', '--password' => 'another fresh password']);
    freshGuards();

    $this->get(consoleUrl('/'))
        ->assertRedirect(consoleUrl('/login'))
        ->assertSessionHas('flash', ['kind' => 'error', 'message' => RequiresOperator::PASSWORD_CHANGED]);
    $this->assertGuest('console');
});

it('lets the new password through the door and refuses the old one', function () {
    addOperator();
    setOperatorPassword(['--email' => 'pat@inbox.example', '--password' => 'a fresh long password']);

    $this->post(consoleUrl('/login'), ['email' => 'pat@inbox.example', 'password' => 'operator password'])
        ->assertSessionHasErrors(['email']);
    $this->assertGuest('console');

    $this->post(consoleUrl('/login'), ['email' => 'pat@inbox.example', 'password' => 'a fresh long password'])
        ->assertRedirect(consoleUrl('/'));
    $this->assertAuthenticated('console');
});

it('finds the operator whatever the email\'s case, as the column compares it', function () {
    $operator = addOperator();

    [$code] = setOperatorPassword(['--email' => 'Pat@Inbox.EXAMPLE', '--password' => 'a fresh long password']);

    expect($code)->toBe(0)
        ->and(Hash::check('a fresh long password', $operator->refresh()->password))->toBeTrue();
});

it('refuses an email no operator holds in one line and changes nothing', function () {
    $operator = addOperator();
    $before = $operator->password;

    [$code, $output] = setOperatorPassword(['--email' => 'nobody@inbox.example', '--password' => 'a fresh long password']);

    expect($code)->toBe(2)
        ->and($output)->toBe(SetOperatorPasswordCommand::UNKNOWN."\n")
        ->and(SetOperatorPasswordCommand::UNKNOWN)->toBe('No operator holds that email.')
        ->and($operator->refresh()->password)->toBe($before);
});

it('refuses a password the rule refuses with the rule\'s sentence and changes nothing: {case}', function (string $password, string $sentence) {
    $operator = addOperator();
    $before = $operator->password;

    [$code, $output] = setOperatorPassword(['--email' => 'pat@inbox.example', '--password' => $password]);

    expect($code)->toBe(2)
        ->and($output)->toBe($sentence."\n")
        ->and($operator->refresh()->password)->toBe($before);
})->with([
    'seven characters' => ['seven c', PasswordRule::TOO_SHORT],
    '73 bytes' => [str_repeat('a', 73), PasswordRule::TOO_LONG],
    '40 characters in 80 bytes' => [str_repeat("\u{e9}", 40), PasswordRule::TOO_LONG],
]);

it('takes a password of exactly 8 characters and one of exactly 72 bytes', function (string $password) {
    $operator = addOperator();

    [$code] = setOperatorPassword(['--email' => 'pat@inbox.example', '--password' => $password]);

    expect($code)->toBe(0)
        ->and(Hash::check($password, $operator->refresh()->password))->toBeTrue();
})->with([
    'eight characters' => ['eight ch'],
    '72 bytes' => [str_repeat('a', 72)],
]);

it('refuses an incomplete or malformed input and changes nothing: {case}', function (?string $email, ?string $password) {
    $operator = addOperator();
    $before = $operator->password;

    [$code] = setOperatorPassword(array_filter(['--email' => $email, '--password' => $password], fn (?string $value): bool => $value !== null));

    expect($code)->toBe(2)
        ->and($operator->refresh()->password)->toBe($before);
})->with([
    'a missing email' => [null, 'a fresh long password'],
    'an email that is no address' => ['not an address', 'a fresh long password'],
    'a missing password' => ['pat@inbox.example', null],
]);
