<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Http\Request;

/**
 * A screen's query read tolerantly at the boundary, one spelling of each reader: the admin's
 * Home, its Bookings list, its Customers list and a customer's page, the Calendar's window, the
 * Reports screen's range, and the console's tenant list. A value of the wrong shape reads as
 * unset rather than a 422, because these are reads and a stale link must still land on the
 * screen.
 *
 * Text is trimmed, and is no value where it is blank, not a string, or bytes no column can
 * compare, text that is not UTF-8 or holds a NUL, which the server would refuse outright. A date
 * is a calendar date written YYYY-MM-DD. An id is digits spelling a whole number above nought, so
 * 007 reads as 7; a list of ids is ids joined by commas, read whole or not at all, at most a
 * hundred of them, the Calendar's picked members. A page counts from one, and a number past nine
 * digits, which would overflow the offset, reads as the first.
 */
final class TolerantQuery
{
    private const string DAY = '/^\d{4}-\d{2}-\d{2}$/';

    private const string PAGE = '/^[1-9]\d{0,8}$/';

    public static function text(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0")) {
            return null;
        }

        return trim($value) !== '' ? trim($value) : null;
    }

    public static function day(Request $request, string $key): ?string
    {
        $value = self::text($request, $key);

        if ($value === null || preg_match(self::DAY, $value) !== 1) {
            return null;
        }

        [$year, $month, $day] = array_map(intval(...), explode('-', $value));

        return checkdate($month, $day, $year) ? $value : null;
    }

    public static function id(Request $request, string $key): ?int
    {
        $value = self::text($request, $key);

        return $value === null ? null : self::idOf($value);
    }

    /**
     * An id spelled in digits, at most ID_DIGITS of them: eighteen digits always fit a 64-bit
     * integer, so the cast never overflows, and no row id this application writes comes near.
     */
    private static function idOf(string $value): ?int
    {
        return ctype_digit($value) && strlen($value) <= self::ID_DIGITS && (int) $value > 0 ? (int) $value : null;
    }

    /** The most digits an id is read with, the most that always fit a 64-bit integer. */
    public const int ID_DIGITS = 18;

    /**
     * A path id's route constraint, the same digits: a longer one answers 404 at the router
     * rather than overflowing the controller's integer parameter into a 500.
     */
    public const string ID_PATTERN = '[0-9]{1,'.self::ID_DIGITS.'}';

    /** The most ids one list carries: more than any roster the picker offers. */
    private const int IDS = 100;

    /**
     * A comma-joined list of ids, sorted and without repeats, or null where any part is no id,
     * the list is empty or it runs past the cap.
     *
     * @return list<int>|null
     */
    public static function ids(Request $request, string $key): ?array
    {
        $value = self::text($request, $key);

        if ($value === null) {
            return null;
        }

        $parts = explode(',', $value);

        if (count($parts) > self::IDS) {
            return null;
        }

        $ids = [];

        foreach ($parts as $part) {
            $id = self::idOf(trim($part));

            if ($id === null) {
                return null;
            }

            $ids[] = $id;
        }

        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }

    public static function page(Request $request): int
    {
        $value = self::text($request, 'page');

        return $value !== null && preg_match(self::PAGE, $value) === 1 ? (int) $value : 1;
    }
}
