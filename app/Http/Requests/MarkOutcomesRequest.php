<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Enum\BookingOutcome;
use App\Http\AdminLines;
use App\Http\Controllers\Admin\HomeController;

/**
 * The body of POST /admin/bookings/mark, Home's To mark marking a selection of its rows at once:
 * the outcome, completed or no_show, and the bookings, their uuids alone, one to as many as the
 * card lists, none twice, in the order they are to be marked. No status rides along: each mark's
 * own guard, under the booking's lock, is what decides whether the booking may still be marked.
 *
 * Every refusal is a key of lang/{en,el}/admin/home.php under to_mark.validation, read in the
 * request's language.
 */
final class MarkOutcomesRequest extends AdminWriteRequest
{
    public const string OUTCOME = 'admin/home.to_mark.validation.outcome';

    public const string BOOKINGS = 'admin/home.to_mark.validation.bookings';

    /** Every refusal's key by the field and the rule it answers. */
    public const array MESSAGES = [
        'outcome.required' => self::OUTCOME,
        'outcome.string' => self::OUTCOME,
        'outcome.in' => self::OUTCOME,
        'bookings.required' => self::BOOKINGS,
        'bookings.array' => self::BOOKINGS,
        'bookings.list' => self::BOOKINGS,
        'bookings.min' => self::BOOKINGS,
        'bookings.max' => self::BOOKINGS,
        'bookings.*.required' => self::BOOKINGS,
        'bookings.*.string' => self::BOOKINGS,
        'bookings.*.uuid' => self::BOOKINGS,
        'bookings.*.distinct' => self::BOOKINGS,
    ];

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'outcome' => ['required', 'string', 'in:'.implode(',', array_map(fn (BookingOutcome $outcome): string => $outcome->value, BookingOutcome::cases()))],
            'bookings' => ['required', 'array', 'list', 'min:1', 'max:'.HomeController::TO_MARK],
            'bookings.*' => ['required', 'string', 'uuid', 'distinct:ignore_case'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_map(AdminLines::line(...), self::MESSAGES);
    }

    public function outcome(): BookingOutcome
    {
        return BookingOutcome::from($this->string('outcome')->toString());
    }

    /**
     * The uuids in the order sent.
     *
     * @return list<string>
     */
    public function bookings(): array
    {
        $bookings = $this->validated('bookings');

        return is_array($bookings) ? array_values(array_filter($bookings, is_string(...))) : [];
    }
}
