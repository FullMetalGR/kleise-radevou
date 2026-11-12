<?php

declare(strict_types=1);

namespace App\Domain\Enum;

/**
 * The two things an owner can say about a request lodged with them: approve, or reject.
 *
 * It sits here, beside the status and the hold class, because three parts of the build name one
 * of the two and none of them is the web: the use cases that carry the decision out, the
 * message that offers a person both of them, and the signed link that carries one. A queued job
 * mints links with no request anywhere near it, so a decision that lived in the HTTP layer
 * would have the queue depending on the web to say "approve".
 *
 * It is not a status and does not become one. A decision is what somebody said; where the
 * booking lands afterwards is BookingStatus, confirmed or awaiting_payment by the booking's
 * payment mode and the tenant's connection, which moved without touching a word of this.
 *
 * The words a page puts on a button for each of them are the page's, DecisionWords' keys of
 * decision.php in the page's language, the way BookingStatus carries no sentences either: an
 * enum three layers read should not be where words accumulate.
 */
enum Decision: string
{
    case Approve = 'approve';

    case Reject = 'reject';

    /**
     * The two words as a list, for anything matching a string against them. The route that
     * carries a decision in its path constrains its parameter to exactly these, so a path
     * naming neither is not a route at all rather than a controller's problem.
     *
     * @return list<string>
     */
    public static function spellings(): array
    {
        return array_map(fn (self $decision): string => $decision->value, self::cases());
    }

    /**
     * Whether this decision carries a reason with it. Only a rejection does, and the reason is
     * optional there, the empty string included: it is the owner's note about why they turned
     * a request down, which RejectBooking stores on the booking and ApproveBooking has nothing
     * to take.
     */
    public function takesAReason(): bool
    {
        return match ($this) {
            self::Approve => false,
            self::Reject => true,
        };
    }
}
