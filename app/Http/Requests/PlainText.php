<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Closure;

/**
 * Plain text as a column can hold it, decided in one place: a string that is valid UTF-8 and holds
 * no NUL. Anything else the database refuses outright, so the boundary refuses it first, as a
 * value the field cannot take rather than a failed write.
 *
 * A request that takes free text puts rule() among the field's rules, after the one that says the
 * value is a string, with the sentence the field is refused by.
 */
final class PlainText
{
    public static function holds(string $value): bool
    {
        return mb_check_encoding($value, 'UTF-8') && ! str_contains($value, "\0");
    }

    /**
     * The rule that refuses a string that is not plain text with the sentence given. A value that
     * is no string is left to the rule that says so.
     *
     * @return Closure(string, mixed, Closure): void
     */
    public static function rule(string $sentence): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($sentence): void {
            if (is_string($value) && ! self::holds($value)) {
                $fail($sentence);
            }
        };
    }
}
