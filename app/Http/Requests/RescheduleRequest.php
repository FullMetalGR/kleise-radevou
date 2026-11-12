<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\ApiLines;
use DateTimeImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The body of POST /bookings/{uuid}/reschedule: the instant the booking is to move to, and the
 * token, which the guard in front of the route reads for itself.
 *
 * One field, because everything else about the move is the booking's own. The services, their
 * order, the staff each was sold to and what each cost are read from the rows, never taken from
 * the request: a body that could restate them would be a body that could move somebody else's
 * appointment onto a cheaper service. The client's side does the same: the reschedule dialog
 * asks GET /bookings/{uuid}/availability, which names no items either, and sends back nothing
 * but the start it picked.
 *
 * The token is not listed here. The guard runs as middleware, before this request is resolved,
 * and answers a missing one as a 400 and a wrong one as the same 404 an unknown booking gets;
 * validating it again here would be a second opinion on a credential, and the two could differ.
 *
 * Authorized because the middleware is the authorization check, RequiresManageToken, and the
 * route declares it. Nothing this class could ask would add to that: the customer holding the
 * one credential the booking has is exactly who may move it.
 */
final class RescheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * A start arrives trimmed, the way it does on the hold: a customer's client that sent a
     * trailing newline has still named the instant it meant.
     */
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
        return ['start' => ['required', Instant::rule()]];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['start.required' => ApiLines::line(Instant::SENTENCE)];
    }

    /**
     * The validated start as the use case takes it.
     */
    public function start(): DateTimeImmutable
    {
        return Instant::read($this->validated()['start'] ?? null);
    }
}
