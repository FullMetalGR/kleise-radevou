<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Application\Dto\DayWindow;
use App\Domain\Availability\CalendarDay;
use App\Http\ApiLines;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use LogicException;

/**
 * The from and to of an availability read at the boundary: calendar dates with to after from and
 * the span at most the cap. Two routes take them, GET /availability beside its chain and GET
 * /bookings/{uuid}/availability alone, and they take them by one rule with one cap and one set of
 * sentences, so they are written here once, as ChainItems is for the chain. Every refusal is 400
 * invalid_request with a sentence naming the field, a key of api.php under validation read in the
 * request's language, one whole sentence per field and the cap filled from DayWindow::MAX_DAYS as
 * a choice by it.
 */
final class DayWindowFields
{
    private const string DAY = '/^(\d{4})-(\d{2})-(\d{2})$/';

    /** The sentence each field is refused with where it is no day, by its key of api.php. */
    private const array DAY_SENTENCES = ['from' => 'api.validation.from.day', 'to' => 'api.validation.to.day'];

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(FormRequest $request): array
    {
        return [
            'from' => ['required', self::calendarDay()],
            'to' => ['required', self::calendarDay(), self::window($request)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'from.required' => ApiLines::line(self::DAY_SENTENCES['from']),
            'to.required' => ApiLines::line(self::DAY_SENTENCES['to']),
        ];
    }

    /**
     * The validated window as a use case takes it.
     *
     * @param  array<array-key, mixed>  $validated
     */
    public static function of(array $validated): DayWindow
    {
        $from = $validated['from'] ?? null;
        $to = $validated['to'] ?? null;

        if (! is_string($from) || ! is_string($to)) {
            throw new LogicException('The dates were validated and are strings.');
        }

        return new DayWindow($from, $to);
    }

    /**
     * A calendar date spelled YYYY-MM-DD and no other way: the framework's date_format takes
     * a month or day of one digit, and the days downstream do not.
     */
    private static function calendarDay(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || ! self::isDay($value)) {
                $fail(ApiLines::line(self::DAY_SENTENCES[$attribute] ?? throw new LogicException(sprintf('No day field is called %s.', $attribute))));
            }
        };
    }

    /**
     * The window to draws with from: after it, and at most the cap of days later. Judged only
     * once both are days; a misspelled from is its own refusal.
     */
    private static function window(FormRequest $request): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($request): void {
            $from = $request->input('from');

            if (! is_string($from) || ! self::isDay($from) || ! is_string($value) || ! self::isDay($value)) {
                return;
            }

            $days = CalendarDay::daysBetween($from, $value);

            if ($days < 1) {
                $fail(ApiLines::line('api.validation.to.after'));
            } elseif ($days > DayWindow::MAX_DAYS) {
                $fail(ApiLines::choice('api.validation.to.max', DayWindow::MAX_DAYS));
            }
        };
    }

    public static function isDay(string $value): bool
    {
        return preg_match(self::DAY, $value, $parts) === 1 && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }
}
