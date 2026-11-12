<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Infrastructure\Db\BlackoutRecords;
use Illuminate\Contracts\Validation\Validator;

/**
 * The body of POST and DELETE on /admin/blackouts and /admin/staff/{id}/blackouts: a date, an
 * optional window as two times of day, and on a POST an optional reason, and an optional Until,
 * the closure's last date, on or after the date and, on an add, at most MOST_DAYS dates in all; a
 * removal's Until is not capped, since two adds side by side fold into one run longer than either.
 * The same body removes what it adds, because the card shows dates and the server matches a
 * removal by the dates and the window given, never by a row id.
 *
 * Every refusal is a key of lang/{en,el}/admin/staff.php under validation, read in the request's
 * language; the Calendar's closure dialog sends this body too.
 */
final class BlackoutRequest extends AdminWriteRequest
{
    public const string WINDOW_ORDER = 'admin/staff.validation.closure_order';

    /** Until: a date, on or after Date, and on an add at most MOST_DAYS days of closure. */
    public const string UNTIL_DATE = 'admin/staff.validation.closure_until_date';

    public const string UNTIL_ORDER = 'admin/staff.validation.closure_until_order';

    public const string UNTIL_SPAN = 'admin/staff.validation.closure_until_span';

    /** The most dates one add writes, Date and Until counted: about two months. */
    public const int MOST_DAYS = 62;

    /** Every refusal's key by the field and the rule it answers, one sentence for the window. */
    public const array MESSAGES = [
        'date.required' => 'admin/staff.validation.closure_date',
        'date.date_format' => 'admin/staff.validation.closure_date',
        'start_time.required_with' => 'admin/staff.validation.closure_window',
        'start_time.string' => 'admin/staff.validation.closure_window',
        'start_time.regex' => 'admin/staff.validation.closure_window',
        'end_time.required_with' => 'admin/staff.validation.closure_window',
        'end_time.string' => 'admin/staff.validation.closure_window',
        'end_time.regex' => 'admin/staff.validation.closure_window',
        'reason.string' => 'admin/staff.validation.closure_reason',
        'until.date_format' => self::UNTIL_DATE,
    ];

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'start_time' => ['nullable', 'required_with:end_time', 'string', 'regex:'.WeeklyWindows::TIME],
            'end_time' => ['nullable', 'required_with:start_time', 'string', 'regex:'.WeeklyWindows::END_TIME],
            'reason' => ['sometimes', 'nullable', 'string'],
            'until' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_map(StaffRequest::line(...), self::MESSAGES);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            [$start, $end] = $this->window();

            if ($start !== null && $end !== null && strcmp($start, $end) >= 0) {
                $validator->errors()->add('start_time', StaffRequest::line(self::WINDOW_ORDER));

                return;
            }

            $until = $this->until();

            if ($until === null) {
                return;
            }

            if (strcmp($until, $this->day()) < 0) {
                $validator->errors()->add('until', StaffRequest::line(self::UNTIL_ORDER));
            } elseif ($this->isMethod('POST') && count(BlackoutRecords::dates($this->day(), $until)) > self::MOST_DAYS) {
                $validator->errors()->add('until', StaffRequest::line(self::UNTIL_SPAN, ['n' => (string) self::MOST_DAYS]));
            }
        });
    }

    public function day(): string
    {
        $date = $this->validated('date');
        assert(is_string($date));

        return $date;
    }

    /**
     * The window, both times or neither.
     *
     * @return array{string|null, string|null}
     */
    public function window(): array
    {
        $start = $this->validated('start_time');
        $end = $this->validated('end_time');

        return is_string($start) && is_string($end) ? [$start, $end] : [null, null];
    }

    /** The closure's last date, or null for a closure of the one date. */
    public function until(): ?string
    {
        $until = $this->validated('until');

        return is_string($until) ? $until : null;
    }

    public function reason(): ?string
    {
        $reason = $this->validated('reason');

        return is_string($reason) && trim($reason) !== '' ? $reason : null;
    }
}
