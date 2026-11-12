<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Application\Dto\HoldRequest;
use App\Application\Dto\Intake;
use App\Domain\Enum\BookingSource;
use App\Site\SiteLanguage;

/**
 * The body of POST /admin/bookings: the customer and the ordered segments the wizard sends,
 * with the start as one of the availability answer's own machine values, verbatim. It is
 * HoldBody, the same vocabulary the public hold takes, so a start the admin's availability
 * read offered is one this route accepts.
 *
 * A refusal is the 400 envelope AdminWriteRequest answers with, the first field's own sentence
 * as its detail, which the wizard shows as an error toast with every answer intact. There is
 * no client-side email format check: this sentence is the one the operator reads for a bad
 * address.
 *
 * Nothing here decides who may write. The route declares manageBookings, and no subscription
 * gate stands beside it: the booking lifecycle is never frozen.
 */
final class NewBookingRequest extends AdminWriteRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(HoldBody::prepared(...$this->only(['customer_name', 'customer_email', 'customer_phone', 'start'])));
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return HoldBody::rules();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return HoldBody::messages();
    }

    /**
     * The manual booking's request, its source the admin's and its language the tenant default at
     * its create, and nothing else of the intake: the wizard asks for no note and follows no
     * tagged link. The language is never the staff member's own, which the admin's request
     * carries, since the customer's choice is unknown and the mails go to the customer.
     *
     * @param  SiteLanguage  $tenantDefault  the tenant default, Languages::tenantDefault
     */
    public function toHoldRequest(SiteLanguage $tenantDefault): HoldRequest
    {
        return HoldBody::toHoldRequest($this->validated(), $this->boolean('same_staff'), new Intake(BookingSource::Admin, language: $tenantDefault->value));
    }
}
