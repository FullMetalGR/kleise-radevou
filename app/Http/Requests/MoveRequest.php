<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Application\Dto\MoveChoices;
use App\Http\ApiLines;
use DateTimeImmutable;
use LogicException;

/**
 * POST /admin/bookings/{uuid}/move: the start the booking is to take, an RFC 3339 instant as the
 * manual booking's start is; the member override by segment position, MoveFields;
 * warning_accepted, false where absent, true to land over a member's hours or a closure;
 * tell_customer, true where absent, false to send the customer nothing. A refused field is the
 * 400 envelope with its sentence, as every admin write answers one.
 */
final class MoveRequest extends AdminWriteRequest
{
    public const string WARNING_SENTENCE = 'api.validation.move.warning_accepted';

    public const string TELL_SENTENCE = 'api.validation.move.tell_customer';

    protected function prepareForValidation(): void
    {
        $start = $this->input('start');

        if (is_string($start)) {
            $this->merge(['start' => trim($start)]);
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'start' => ['required', Instant::rule()],
            'staff' => ['nullable', MoveFields::staffRule()],
            'warning_accepted' => ['sometimes', MoveFields::flagRule(self::WARNING_SENTENCE)],
            'tell_customer' => ['sometimes', MoveFields::flagRule(self::TELL_SENTENCE)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['start.required' => ApiLines::line(Instant::SENTENCE)];
    }

    public function start(): DateTimeImmutable
    {
        return Instant::read($this->validated()['start'] ?? null);
    }

    public function choices(): MoveChoices
    {
        $staff = MoveFields::staffOf($this->input('staff')) ?? throw new LogicException('The staff override was validated.');

        return new MoveChoices($staff, $this->boolean('warning_accepted'), $this->boolean('tell_customer', true));
    }
}
