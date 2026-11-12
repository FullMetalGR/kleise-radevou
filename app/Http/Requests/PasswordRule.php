<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The one rule for a password a person chooses: at least eight characters, and no more than the 72
 * bytes bcrypt reads, since a longer one would be cut short without a word and every password
 * sharing those bytes would sign in. The floor counts characters and the ceiling bytes, so the
 * long sentence says so without saying bytes. The signup form uses it with the two sentences of
 * its language file handed in, and the set-your-own-password link with the shell's two refusals
 * handed in the same way by SetPasswordRequest; the two operator commands, console:add-operator
 * and console:set-operator-password, alone keep the rule's own English; tenant:provision and
 * tenant:add-user keep their own plain rules. No length limit is set on the input, since bytes are
 * not characters.
 */
final class PasswordRule implements ValidationRule
{
    public const int FLOOR_CHARACTERS = 8;

    public const int CEILING_BYTES = 72;

    public const string TOO_SHORT = 'Choose a password of at least 8 characters.';

    public const string TOO_LONG = 'Choose a shorter password: at most 72 characters, fewer if it uses letters outside the Latin alphabet.';

    /**
     * The floor and the ceiling as the sentences that name them fill them, :floor and :ceiling.
     *
     * @return array{floor: string, ceiling: string}
     */
    public static function fills(): array
    {
        return ['floor' => (string) self::FLOOR_CHARACTERS, 'ceiling' => (string) self::CEILING_BYTES];
    }

    public function __construct(
        private readonly string $tooShort = self::TOO_SHORT,
        private readonly string $tooLong = self::TOO_LONG,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || mb_strlen($value) < self::FLOOR_CHARACTERS) {
            $fail($this->tooShort);
        } elseif (strlen($value) > self::CEILING_BYTES) {
            $fail($this->tooLong);
        }
    }
}
