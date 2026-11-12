<?php

use App\Console\Commands\AddOperatorCommand;
use App\Http\Requests\PasswordRule;
use App\Platform\Operator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

pest()->use(RefreshDatabase::class);

/*
 * The console command that adds an operator: nobody registers, so this is the one way a row
 * reaches the operators table. The password is judged by PasswordRule as the signup's is, and an
 * email already held is refused.
 */

/**
 * @param  array<string, string>  $input
 */
function addOperatorCommand(TestCase $test, array $input): PendingCommand
{
    $command = $test->artisan('console:add-operator', $input);
    assert($command instanceof PendingCommand);

    return $command;
}

it('adds an operator with a name, an email and a password, and says so in one line', function () {
    addOperatorCommand($this, ['--name' => 'Pat Operator', '--email' => 'pat@inbox.example', '--password' => 'a long password'])
        ->expectsOutput('Added pat@inbox.example as an operator.')
        ->assertExitCode(0);

    $operator = Operator::query()->sole();

    expect($operator->name)->toBe('Pat Operator')
        ->and($operator->email)->toBe('pat@inbox.example')
        ->and($operator->password)->not->toBe('a long password')
        ->and(Hash::check('a long password', $operator->password))->toBeTrue()
        ->and($operator->toArray())->not->toHaveKey('password');
});

it('refuses an email an operator already holds, whatever its case, with one sentence', function () {
    addOperatorCommand($this, ['--name' => 'Pat Operator', '--email' => 'pat@inbox.example', '--password' => 'a long password'])->assertExitCode(0);

    addOperatorCommand($this, ['--name' => 'Twin', '--email' => 'Pat@Inbox.example', '--password' => 'another long one'])
        ->expectsOutput(AddOperatorCommand::HELD)
        ->assertExitCode(2);

    expect(Operator::query()->count())->toBe(1);
});

it('refuses a password the rule refuses with the rule\'s sentence and adds nobody', function (string $password, string $sentence) {
    addOperatorCommand($this, ['--name' => 'Pat Operator', '--email' => 'pat@inbox.example', '--password' => $password])
        ->expectsOutput($sentence)
        ->assertExitCode(2);

    expect(Operator::query()->exists())->toBeFalse();
})->with([
    'seven characters' => ['seven c', PasswordRule::TOO_SHORT],
    '73 bytes' => [str_repeat('a', 73), PasswordRule::TOO_LONG],
    '40 characters in 80 bytes' => [str_repeat("\u{e9}", 40), PasswordRule::TOO_LONG],
]);

it('takes a password of exactly 8 characters and one of exactly 72 bytes', function (string $password) {
    addOperatorCommand($this, ['--name' => 'Pat Operator', '--email' => 'pat@inbox.example', '--password' => $password])->assertExitCode(0);

    expect(Hash::check($password, Operator::query()->sole()->password))->toBeTrue();
})->with([
    'eight characters' => ['eight ch'],
    '72 bytes' => [str_repeat('a', 72)],
]);

it('refuses an incomplete or malformed input and adds nobody', function (?string $name, ?string $email, ?string $password) {
    $input = array_filter(['--name' => $name, '--email' => $email, '--password' => $password], fn (?string $value): bool => $value !== null);

    addOperatorCommand($this, $input)->assertExitCode(2);

    expect(Operator::query()->exists())->toBeFalse();
})->with([
    'a missing name' => [null, 'pat@inbox.example', 'a long password'],
    'a missing email' => ['Pat Operator', null, 'a long password'],
    'an email that is no address' => ['Pat Operator', 'not an address', 'a long password'],
    'a missing password' => ['Pat Operator', 'pat@inbox.example', null],
]);
