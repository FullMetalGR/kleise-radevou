<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Application\Dto\DayWindow;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The query string of GET /bookings/{uuid}/availability: from and to, taken as GET /availability
 * takes them, by the same rule, the same cap and the same sentences, and nothing else. There are
 * no items and no same_staff, because the chain is the booking's own and is read from its rows
 * exactly as the move reads it; a query that could name a chain could offer starts the move
 * would never plan.
 *
 * The token is not listed here. It travels as the token query parameter, and the guard in front
 * of the route reads it for itself before this request is resolved: a missing one is its 400 and
 * a failed one its 404, so a failed credential is answered before a malformed window is, in the
 * same order the reschedule route answers them.
 *
 * Authorized because the middleware is the authorization check, RequiresManageToken, and the
 * route declares it.
 */
final class MoveAvailabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return DayWindowFields::rules($this);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return DayWindowFields::messages();
    }

    /**
     * The validated window as the use case takes it.
     */
    public function window(): DayWindow
    {
        return DayWindowFields::of($this->validated());
    }
}
