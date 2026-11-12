<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The set-password page's form: one password, judged by PasswordRule, the one rule for a password
 * a person chooses, a missing or non-text field reading as too short as the signup form has it. A
 * plain form request and no admin write: the page is public, its credential the link, and a
 * refusal comes back on the field through the errors bag as the sign-in's does, to the link's own
 * page, read from this request's own URL rather than from a referrer, which the page sends none
 * of.
 *
 * Nothing here decides whether the link is live: that is the statement's, which reads the row.
 *
 * The rule is handed the shell's two refusals as SignupRequest hands it signup.php's, worded in
 * the request's language, which with nobody signed in is the tenant default; the rule's own
 * English stays the operator's commands'.
 */
final class SetPasswordRequest extends FormRequest
{
    public const string FIELD = 'password';

    /**
     * Holds a key: the refusal of a password under the floor, shell.php's validation.set_password.
     */
    public const string TOO_SHORT = 'admin/shell.validation.set_password.too_short';

    /**
     * Holds a key: the refusal of a password over the ceiling, shell.php's validation.set_password.
     */
    public const string TOO_LONG = 'admin/shell.validation.set_password.too_long';

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [self::FIELD => ['required', 'string', new PasswordRule(self::refusal(self::TOO_SHORT), self::refusal(self::TOO_LONG))]];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            self::FIELD.'.required' => self::refusal(self::TOO_SHORT),
            self::FIELD.'.string' => self::refusal(self::TOO_SHORT),
        ];
    }

    /**
     * A refusal in the request's language, PasswordRule's floor and ceiling filled from its
     * constants as :floor and :ceiling, signup.php's names for the same two.
     */
    private static function refusal(string $key): string
    {
        $line = __($key, PasswordRule::fills());

        return is_string($line) ? $line : $key;
    }

    public function password(): string
    {
        return $this->string(self::FIELD)->toString();
    }

    /**
     * Back to the link's page: the POST's own path is the page's.
     */
    protected function getRedirectUrl(): string
    {
        return $this->url();
    }
}
