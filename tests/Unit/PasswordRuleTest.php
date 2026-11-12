<?php

use App\Http\Requests\PasswordRule;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Validator;

/*
 * The one password rule: at least eight characters, and no more than the 72 bytes bcrypt reads,
 * each refused with a sentence of its own. The floor counts characters and the ceiling bytes, so a
 * password in letters outside the Latin alphabet reaches the ceiling in fewer characters.
 */

/**
 * The sentences the rule fails a value with, as the framework's validator reports them, none when
 * it passes. An empty password is the required rule's to refuse, before this rule runs.
 *
 * @return array<mixed>
 */
function passwordRefusals(mixed $value, ?PasswordRule $rule = null): array
{
    $validator = new Validator(new Translator(new ArrayLoader, 'en'), ['password' => $value], ['password' => [$rule ?? new PasswordRule]]);

    return $validator->errors()->get('password');
}

it('words the floor and the ceiling each with a sentence of its own', function () {
    expect(PasswordRule::TOO_SHORT)->toBe('Choose a password of at least 8 characters.')
        ->and(PasswordRule::TOO_LONG)->toBe('Choose a shorter password: at most 72 characters, fewer if it uses letters outside the Latin alphabet.');
});

it('fails with the two sentences handed to it in place of its own, the signup\'s from its language file, the floor and the ceiling unchanged', function () {
    $rule = new PasswordRule(tooShort: 'Too short, as handed.', tooLong: 'Too long, as handed.');

    expect(passwordRefusals(str_repeat('a', 7), $rule))->toBe(['Too short, as handed.'])
        ->and(passwordRefusals(str_repeat('a', 73), $rule))->toBe(['Too long, as handed.'])
        ->and(passwordRefusals(str_repeat('a', 8), $rule))->toBe([])
        ->and(passwordRefusals(str_repeat('a', 72), $rule))->toBe([]);
});

it('passes a password from eight characters to 72 bytes', function (string $password) {
    expect(passwordRefusals($password))->toBe([]);
})->with([
    'eight characters' => [str_repeat('a', 8)],
    'eight two-byte letters, sixteen bytes' => [str_repeat("\u{03b1}", 8)],
    '72 bytes' => [str_repeat('a', 72)],
    '36 two-byte letters, 72 bytes' => [str_repeat("\u{03b1}", 36)],
    '24 three-byte letters, 72 bytes' => [str_repeat("\u{4e00}", 24)],
]);

it('refuses a password under eight characters with the short sentence alone', function (mixed $password) {
    expect(passwordRefusals($password))->toBe([PasswordRule::TOO_SHORT]);
})->with([
    'seven characters' => [str_repeat('a', 7)],
    'seven two-byte letters, fourteen bytes' => [str_repeat("\u{03b1}", 7)],
    'a value that is not text' => [12345678],
]);

it('refuses a password past 72 bytes with the long sentence alone, however few its characters', function (string $password) {
    expect(passwordRefusals($password))->toBe([PasswordRule::TOO_LONG]);
})->with([
    '73 bytes' => [str_repeat('a', 73)],
    '37 two-byte letters, 74 bytes' => [str_repeat("\u{03b1}", 37)],
    '71 bytes and one two-byte letter' => [str_repeat('a', 71)."\u{03b1}"],
    '25 three-byte letters, 75 bytes' => [str_repeat("\u{4e00}", 25)],
]);
