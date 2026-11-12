<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\AdminLines;
use App\Infrastructure\Db\BlackoutRecords;
use Illuminate\Contracts\Validation\Validator;

/**
 * The weekly windows as two bodies carry them, the implicit member's week under Opening hours and
 * a member's repeating pattern on Shifts: a list of windows, each a weekday and a start before an
 * end as wall-clock times with no instant. Spelled once so the two routes cannot drift in what a
 * window is or how it is refused.
 *
 * A window's end may be 24:00, the end-of-day spelling the domain reads as the next midnight;
 * nothing else past 23:59 is a time of day.
 *
 * The four refusals are keys of lang/{en,el}/admin/shell.php under validation.weekly, the words
 * two screens share, read in the request's language, the user's.
 */
final readonly class WeeklyWindows
{
    public const string TIME = '/\A([01]\d|2[0-3]):[0-5]\d\z/';

    public const string END_TIME = '/\A(([01]\d|2[0-3]):[0-5]\d|24:00)\z/';

    public const string LIST = 'admin/shell.validation.weekly.list';

    public const string WEEKDAY = 'admin/shell.validation.weekly.weekday';

    public const string TIMES = 'admin/shell.validation.weekly.times';

    public const string ORDER = 'admin/shell.validation.weekly.order';

    /**
     * @return array<string, list<string>>
     */
    public static function rules(string $field, string $presence): array
    {
        return [
            $field => [$presence, 'array'],
            $field.'.*.weekday' => ['required', 'integer', 'between:1,7'],
            $field.'.*.start' => ['required', 'string', 'regex:'.self::TIME],
            $field.'.*.end' => ['required', 'string', 'regex:'.self::END_TIME],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(string $field): array
    {
        return array_map(AdminLines::line(...), [
            $field.'.present' => self::LIST,
            $field.'.array' => self::LIST,
            $field.'.*.weekday.required' => self::WEEKDAY,
            $field.'.*.weekday.integer' => self::WEEKDAY,
            $field.'.*.weekday.between' => self::WEEKDAY,
            $field.'.*.start.required' => self::TIMES,
            $field.'.*.start.string' => self::TIMES,
            $field.'.*.start.regex' => self::TIMES,
            $field.'.*.end.required' => self::TIMES,
            $field.'.*.end.string' => self::TIMES,
            $field.'.*.end.regex' => self::TIMES,
        ]);
    }

    /**
     * The windows a validated body named, the whole list, or null when it named none.
     *
     * @param  array<string, mixed>  $validated
     * @return list<array{weekday: int, start: string, end: string}>|null
     */
    public static function windows(array $validated, string $field): ?array
    {
        if (! array_key_exists($field, $validated)) {
            return null;
        }

        $rules = $validated[$field];
        assert(is_array($rules));
        $windows = [];

        foreach ($rules as $rule) {
            if (! is_array($rule)) {
                continue;
            }

            $windows[] = ['weekday' => BlackoutRecords::int($rule['weekday'] ?? null), 'start' => BlackoutRecords::string($rule['start'] ?? null), 'end' => BlackoutRecords::string($rule['end'] ?? null)];
        }

        return $windows;
    }

    /**
     * Refuses the first window that does not start before it ends; the after-hook of both
     * requests. Answers whether every window was in order.
     *
     * @param  list<array{weekday: int, start: string, end: string}>|null  $windows
     */
    public static function ordered(Validator $validator, ?array $windows, string $field): bool
    {
        foreach ($windows ?? [] as $index => $window) {
            if (strcmp($window['start'], $window['end']) >= 0) {
                $validator->errors()->add($field.'.'.$index, AdminLines::line(self::ORDER));

                return false;
            }
        }

        return true;
    }
}
