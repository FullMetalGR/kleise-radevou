<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\ApiLines;
use Closure;
use DateTimeImmutable;
use LogicException;

/**
 * GET /admin/api/bookings/{uuid}/move, in one of its two shapes: `date`, a tenant-local
 * YYYY-MM-DD, for the day's candidate starts, or `start`, an RFC 3339 instant, for the verdict
 * on that one; exactly one of the two. Beside either, the member override by segment position,
 * MoveFields. A refused field is the 400 envelope with its sentence, as the admin's other JSON
 * read answers one.
 */
final class MoveReadRequest extends AdminWriteRequest
{
    public const string DATE_SENTENCE = 'api.validation.move.date';

    public const string ONE_SENTENCE = 'api.validation.move.one';

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'date' => ['required_without:start', self::calendarDay(), $this->alone()],
            'start' => ['required_without:date', Instant::rule()],
            'staff' => ['nullable', MoveFields::staffRule()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['date.required_without' => ApiLines::line(self::ONE_SENTENCE), 'start.required_without' => ApiLines::line(self::ONE_SENTENCE)];
    }

    /** The day asked for, or null where the request asks for a verdict on a start. */
    public function day(): ?string
    {
        $date = $this->validated()['date'] ?? null;

        return is_string($date) ? $date : null;
    }

    public function start(): DateTimeImmutable
    {
        return Instant::read($this->validated()['start'] ?? null);
    }

    /**
     * @return array<int, int>
     */
    public function staff(): array
    {
        return MoveFields::staffOf($this->input('staff')) ?? throw new LogicException('The staff override was validated.');
    }

    private static function calendarDay(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || ! DayWindowFields::isDay($value)) {
                $fail(ApiLines::line(self::DATE_SENTENCE));
            }
        };
    }

    private function alone(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if ($this->has('start')) {
                $fail(ApiLines::line(self::ONE_SENTENCE));
            }
        };
    }
}
