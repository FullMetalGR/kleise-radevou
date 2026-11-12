<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The My bookings form: one address. It never refuses. A malformed address is judged here, at
 * the boundary, and reads as no address, so the POST sends nothing for it and answers the one
 * sentence every other request reads; a refusal worded for a malformed address would be the
 * only answer the form gave that differed from another.
 *
 * An address is judged as the hold judges a customer's, the one rule for an address a booking
 * can carry, so an address the hold took is one this form takes.
 */
final class MyBookingsLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * The address as typed, trimmed by the framework, where it is one a booking could carry, and
     * null for anything else, absent, blank, a list or not an address at all.
     */
    public function address(): ?string
    {
        $email = $this->input('email');

        if (! is_string($email)) {
            return null;
        }

        $judged = $this->container->make(ValidationFactory::class)->make(
            ['email' => $email],
            ['email' => ['required', 'string', 'email', 'max:'.HoldBody::EMAIL_MAX]],
        );

        return $judged->passes() ? $email : null;
    }
}
