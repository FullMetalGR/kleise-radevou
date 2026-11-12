<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Availability\CalendarDay;
use App\Domain\Availability\ShiftPattern;
use App\Infrastructure\Db\ShiftRecords;
use App\Tenancy\CurrentTenant;
use Closure;
use Illuminate\Contracts\Validation\Validator;
use InvalidArgumentException;

/**
 * The body of PUT /admin/shifts/{id}/pattern: the member's repeating week whole, replace-all.
 * cadence_weeks is one to four, starts_on a date, ends_on a date or null for never, each a
 * calendar date as CalendarDay spells one (the format alone takes a year zero no calendar
 * holds), and windows the weekly windows as WeeklyWindows spells and orders them, present so
 * that a body naming no week is not read as one with none; an empty list leaves the member no
 * pattern (ShiftRecords::replacePattern deletes it).
 *
 * At a cadence of one starts_on is checked for its shape and then unread: every weekly pattern
 * starts on the one start (WeeklyPattern::fromTheStart), which the editor does not show. So
 * ends_on is judged against the start the save will store, the one start at a cadence of one and
 * starts_on above it, never against a date the screen did not show. The request asks the write's
 * own home for that start (ShiftRecords::storedStart, WeeklyPattern::storedStart, which put()
 * writes), so the check follows the one start wherever its rule goes.
 *
 * The refusals are keys of lang/en/admin/shifts.php under validation (its Greek twin
 * lang/el/admin/shifts.php), the windows' under admin/shell.php's validation.weekly as the two
 * other weekly writes read them, each in the request's language, the user's.
 */
final class ShiftPatternRequest extends AdminWriteRequest
{
    public const string FIELD = 'windows';

    public const string CADENCE = 'admin/shifts.validation.cadence';

    public const string STARTS_ON = 'admin/shifts.validation.starts_on';

    public const string ENDS_ON = 'admin/shifts.validation.ends_on';

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'cadence_weeks' => ['required', 'integer', 'between:1,'.ShiftPattern::MAX_CADENCE],
            'starts_on' => ['bail', 'required', 'date_format:Y-m-d', self::calendarDay(self::STARTS_ON)],
            'ends_on' => ['bail', 'present', 'nullable', 'date_format:Y-m-d', self::calendarDay(self::ENDS_ON)],
            ...WeeklyWindows::rules(self::FIELD, 'present'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...array_map(ShiftDayRequest::line(...), [
                'cadence_weeks.required' => self::CADENCE,
                'cadence_weeks.integer' => self::CADENCE,
                'cadence_weeks.between' => self::CADENCE,
                'starts_on.required' => self::STARTS_ON,
                'starts_on.date_format' => self::STARTS_ON,
                'ends_on.present' => self::ENDS_ON,
                'ends_on.date_format' => self::ENDS_ON,
            ]),
            ...WeeklyWindows::messages(self::FIELD),
        ];
    }

    /**
     * A date the calendar holds, refused with the field's sentence where the format took it and
     * CalendarDay, which every write below the boundary spells its days through, would not.
     *
     * @return Closure(string, mixed, Closure): void
     */
    private static function calendarDay(string $key): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($key): void {
            try {
                CalendarDay::spelled(is_string($value) ? $value : '');
            } catch (InvalidArgumentException) {
                $fail(ShiftDayRequest::line($key));
            }
        };
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $endsOn = $this->endsOn();

            if ($endsOn !== null && $endsOn < $this->storedStart()) {
                $validator->errors()->add('ends_on', ShiftDayRequest::line(self::ENDS_ON));

                return;
            }

            WeeklyWindows::ordered($validator, $this->windows(), self::FIELD);
        });
    }

    /**
     * The start the save will store, read from the write's home: the one start at a cadence of
     * one whatever the body names, starts_on above it (ShiftRecords::storedStart).
     */
    private function storedStart(): string
    {
        $tenant = $this->container->make(CurrentTenant::class)->get();

        return $this->container->make(ShiftRecords::class)->storedStart($tenant, $this->cadenceWeeks(), $this->startsOn());
    }

    public function cadenceWeeks(): int
    {
        $cadence = $this->validated('cadence_weeks');

        return is_int($cadence) ? $cadence : (int) (is_string($cadence) ? $cadence : 1);
    }

    public function startsOn(): string
    {
        $startsOn = $this->validated('starts_on');
        assert(is_string($startsOn));

        return $startsOn;
    }

    public function endsOn(): ?string
    {
        $endsOn = $this->validated('ends_on');

        return is_string($endsOn) ? $endsOn : null;
    }

    /**
     * @return list<array{weekday: int, start: string, end: string}>
     */
    public function windows(): array
    {
        return WeeklyWindows::windows($this->validated(), self::FIELD) ?? [];
    }
}
