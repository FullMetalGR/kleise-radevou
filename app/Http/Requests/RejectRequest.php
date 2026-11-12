<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * The body the booking dialog's Reject posts, a reason or nothing at all, as RejectionReason
 * spells it for this route and the decision page alike.
 *
 * A reason sent as anything but text, which the dialog never sends, is refused as every admin
 * write refuses a field, the 400 envelope carrying the sentence below, never the framework's
 * redirect back with the errors bag. The rejection's own refusals, the guard's, are the
 * controller's flash.
 *
 * Nothing here decides who may post: the route declares approveBookings, and the controller
 * holds a user without manageBookings to their own staff row on every press.
 */
final class RejectRequest extends AdminWriteRequest
{
    /** The refusal of a reason sent as anything but text, a key of bookings.php's validation. */
    public const string NOT_TEXT = 'admin/bookings.validation.reason_text';

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return RejectionReason::rules();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $line = __(self::NOT_TEXT);

        return [RejectionReason::FIELD.'.string' => is_string($line) ? $line : self::NOT_TEXT];
    }

    /** The owner's note, or null where they gave none. */
    public function reason(): ?string
    {
        return RejectionReason::of($this->input(RejectionReason::FIELD));
    }
}
