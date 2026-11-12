<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A console write that takes the operator's reason, the owners being sent it: the suspension and
 * the close. The reason is required, up to 500 characters, trimmed by the framework before it is
 * read, and plain text, which a string that is not UTF-8 or holds a NUL is not, since the column
 * and the audit payload would refuse it outright. A refusal comes back on the field through the
 * errors bag the console's adapter shares, each with its sentence, the missing reason's naming
 * the write.
 *
 * Nothing here decides whether the write may be made: that is the row's status, read again under
 * its lock by the write itself, whose refusal is the controller's flash.
 */
abstract class OperatorReasonRequest extends FormRequest
{
    public const string FIELD = 'reason';

    /** The longest reason taken, in characters. */
    public const int LONGEST = 500;

    public const string TOO_LONG = 'Keep the reason to 500 characters or fewer.';

    public const string NOT_TEXT = 'The reason must be plain text.';

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [self::FIELD => ['bail', 'required', 'string', self::plainText(...), 'max:'.self::LONGEST]];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            self::FIELD.'.required' => $this->required(),
            self::FIELD.'.string' => self::NOT_TEXT,
            self::FIELD.'.max' => self::TOO_LONG,
        ];
    }

    /** The operator's reason as the framework trimmed it. */
    public function reason(): string
    {
        return $this->string(self::FIELD)->toString();
    }

    /** The sentence a missing or blank reason is refused with, naming the write. */
    abstract protected function required(): string;

    private static function plainText(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && (! mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0"))) {
            $fail(self::NOT_TEXT);
        }
    }
}
