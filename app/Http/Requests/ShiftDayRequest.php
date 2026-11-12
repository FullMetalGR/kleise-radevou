<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\AdminLines;
use Illuminate\Contracts\Validation\Validator;

/**
 * The body of PUT /admin/shifts/{id}/days/{date}: that date's windows whole, replace-all, a
 * present list of {start, end} as wall-clock times with no instant, the end up to 24:00, each
 * starting before it ends and no two overlapping; an empty list is not working that day. The
 * screen offers two windows in the granularity's steps; the server takes any list so shaped.
 *
 * The refusals are keys of lang/en/admin/shifts.php under validation (its Greek twin
 * lang/el/admin/shifts.php), in the request's language, the user's.
 */
final class ShiftDayRequest extends AdminWriteRequest
{
    public const string FIELD = 'windows';

    public const string LIST = 'admin/shifts.validation.day_list';

    public const string TIMES = 'admin/shifts.validation.day_times';

    public const string ORDER = 'admin/shifts.validation.day_order';

    public const string OVERLAP = 'admin/shifts.validation.day_overlap';

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            self::FIELD => ['present', 'array', 'list'],
            self::FIELD.'.*.start' => ['required', 'string', 'regex:'.WeeklyWindows::TIME],
            self::FIELD.'.*.end' => ['required', 'string', 'regex:'.WeeklyWindows::END_TIME],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_map(self::line(...), [
            self::FIELD.'.present' => self::LIST,
            self::FIELD.'.array' => self::LIST,
            self::FIELD.'.list' => self::LIST,
            self::FIELD.'.*.start.required' => self::TIMES,
            self::FIELD.'.*.start.string' => self::TIMES,
            self::FIELD.'.*.start.regex' => self::TIMES,
            self::FIELD.'.*.end.required' => self::TIMES,
            self::FIELD.'.*.end.string' => self::TIMES,
            self::FIELD.'.*.end.regex' => self::TIMES,
        ]);
    }

    /**
     * Refuses the first window that does not start before it ends, then the first that starts
     * before the one ahead of it in start order has ended.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $validator->errors()->isEmpty()) {
                return;
            }

            $windows = $this->windows();

            foreach ($windows as $index => $window) {
                if (strcmp($window['start'], $window['end']) >= 0) {
                    $validator->errors()->add(self::FIELD.'.'.$index, self::line(self::ORDER));

                    return;
                }
            }

            $ordered = $windows;
            usort($ordered, fn (array $left, array $right): int => strcmp($left['start'], $right['start']));

            for ($index = 1; $index < count($ordered); $index++) {
                if (strcmp($ordered[$index]['start'], $ordered[$index - 1]['end']) < 0) {
                    $validator->errors()->add(self::FIELD, self::line(self::OVERLAP));

                    return;
                }
            }
        });
    }

    /**
     * @return list<array{start: string, end: string}>
     */
    public function windows(): array
    {
        $listed = $this->validated(self::FIELD);
        $windows = [];

        foreach (is_array($listed) ? $listed : [] as $window) {
            if (is_array($window) && is_string($window['start'] ?? null) && is_string($window['end'] ?? null)) {
                $windows[] = ['start' => $window['start'], 'end' => $window['end']];
            }
        }

        return $windows;
    }

    /**
     * One refusal in the request's language: the admin's one reader, AdminLines.
     */
    public static function line(string $key): string
    {
        return AdminLines::line($key);
    }
}
