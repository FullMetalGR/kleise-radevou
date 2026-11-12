<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The body a decision page posts, which is a reason or nothing at all, as RejectionReason spells
 * it for this page and the booking dialog's Reject alike.
 *
 * It stays on the framework's own form request, not the admin's: these are pages for a person
 * who clicked a link in their mail, and the admin's error envelope is no answer for them,
 * DecideController. The admin's Reject takes RejectRequest, which answers a refused field the
 * way every admin write does.
 *
 * Nothing here decides who may post. The route declares its authorization check and the
 * middleware runs it: the signature plus three facts it checks. A second opinion in this class
 * could differ from the first, and a credential with two judges has none.
 */
final class DecisionRequest extends FormRequest
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
        return RejectionReason::rules();
    }

    /** The owner's note, or null where they gave none. */
    public function reason(): ?string
    {
        return RejectionReason::of($this->input(RejectionReason::FIELD));
    }
}
