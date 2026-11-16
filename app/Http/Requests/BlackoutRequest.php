<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Enum\BookingStatus;
use App\Http\ClosureChoice;
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
 * An add over bookings carries two fields more once the admin has seen them: the choice, keep,
 * move or cancel, and seen, the bookings the admin's list showed, each its uuid and its status,
 * which the add compares with what it finds. A choice that is null or empty is no choice. A
 * choice with no seen is refused; seen with no choice is ignored, whatever it holds, as is
 * either on a removal.
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

    /** The choice over the bookings inside the closure, and the list of them that was seen. */
    public const string CHOICE = 'admin/staff.validation.closure_choice';

    public const string SEEN = 'admin/staff.validation.closure_seen';

    /** The statuses a booking inside a closure can be seen in. */
    public const array SEEN_STATUSES = [BookingStatus::Confirmed, BookingStatus::AwaitingApproval, BookingStatus::AwaitingPayment];

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
        'choice.string' => self::CHOICE,
        'choice.in' => self::CHOICE,
        'seen.present_with' => self::SEEN,
        'seen.array' => self::SEEN,
        'seen.*.array' => self::SEEN,
        'seen.*.uuid.required' => self::SEEN,
        'seen.*.uuid.string' => self::SEEN,
        'seen.*.uuid.uuid' => self::SEEN,
        'seen.*.status.required' => self::SEEN,
        'seen.*.status.string' => self::SEEN,
        'seen.*.status.in' => self::SEEN,
    ];

    /**
     * The choice is ruled on an add alone, and seen only beside a choice that is filled: a body
     * with no choice, or with one that is null or empty, is the plain add, and whatever it holds
     * under seen is neither asked for nor read.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        $rules = [
            'date' => ['required', 'date_format:Y-m-d'],
            'start_time' => ['nullable', 'required_with:end_time', 'string', 'regex:'.WeeklyWindows::TIME],
            'end_time' => ['nullable', 'required_with:start_time', 'string', 'regex:'.WeeklyWindows::END_TIME],
            'reason' => ['sometimes', 'nullable', 'string'],
            'until' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ];

        if (! $this->isMethod('POST')) {
            return $rules;
        }

        $rules['choice'] = ['sometimes', 'nullable', 'string', 'in:'.implode(',', array_map(fn (ClosureChoice $choice): string => $choice->value, ClosureChoice::cases()))];

        if (! $this->filled('choice')) {
            return $rules;
        }

        return [
            ...$rules,
            'seen' => ['present_with:choice', 'array'],
            'seen.*' => ['array'],
            'seen.*.uuid' => ['required', 'string', 'uuid'],
            'seen.*.status' => ['required', 'string', 'in:'.implode(',', array_map(fn (BookingStatus $status): string => $status->value, self::SEEN_STATUSES))],
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

    /** The choice over the bookings inside the closure, or null where none was made. */
    public function choice(): ?ClosureChoice
    {
        $choice = $this->validated('choice');

        return is_string($choice) ? ClosureChoice::from($choice) : null;
    }

    /**
     * The bookings the admin's list showed, each by its uuid and its status; none where the body
     * carries no list, or no choice to read one beside. A uuid is answered in lower case, as the
     * database spells one, so a list that names a booking in upper case still names it.
     *
     * @return list<array{uuid: string, status: string}>
     */
    public function seen(): array
    {
        $seen = $this->validated('seen');
        $bookings = [];

        foreach (is_array($seen) ? $seen : [] as $booking) {
            $uuid = is_array($booking) ? $booking['uuid'] ?? null : null;
            $status = is_array($booking) ? $booking['status'] ?? null : null;

            if (is_string($uuid) && is_string($status)) {
                $bookings[] = ['uuid' => strtolower($uuid), 'status' => $status];
            }
        }

        return $bookings;
    }

    public function reason(): ?string
    {
        $reason = $this->validated('reason');

        return is_string($reason) && trim($reason) !== '' ? $reason : null;
    }
}
