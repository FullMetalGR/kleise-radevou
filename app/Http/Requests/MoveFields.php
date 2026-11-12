<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\ApiLines;
use Closure;

/**
 * The fields the admin's move of a booking shares between its write and its read: the member
 * override, `staff`, a map from a segment's position in the chain, counted from zero, to the id
 * of the member it is given to, absent or empty for none. Position and never item id, since the
 * items are deleted and inserted again on every move. Spelled as a form spells it, staff[0]=12,
 * or as a JSON object, {"0": 12}; a key or a value that is not a whole number is refused with
 * the field's sentence.
 */
final class MoveFields
{
    public const string STAFF_SENTENCE = 'api.validation.move.staff';

    public static function staffRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (self::staffOf($value) === null) {
                $fail(ApiLines::line(self::STAFF_SENTENCE));
            }
        };
    }

    /**
     * A boolean field's rule, its sentence by key: true or false as a form or JSON spells them.
     */
    public static function flagRule(string $sentence): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($sentence): void {
            if (! in_array($value, [true, false, 0, 1, '0', '1', 'true', 'false'], true)) {
                $fail(ApiLines::line($sentence));
            }
        };
    }

    /**
     * The override as the use case takes it, or null where the value is no such map.
     *
     * @return array<int, int>|null
     */
    public static function staffOf(mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (! is_array($value)) {
            return null;
        }

        $staff = [];

        foreach ($value as $segment => $member) {
            $position = self::whole($segment);
            $id = self::whole($member);

            if ($position === null || $id === null || $id < 1) {
                return null;
            }

            $staff[$position] = $id;
        }

        ksort($staff);

        return $staff;
    }

    private static function whole(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        return is_string($value) && preg_match('/^\d{1,9}$/', $value) === 1 ? (int) $value : null;
    }
}
