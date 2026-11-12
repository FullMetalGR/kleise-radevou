<?php

declare(strict_types=1);

namespace App\Http;

use App\Domain\Enum\ApprovalTimeout;
use App\Domain\Enum\BookingStatus;

/**
 * What the outcome page says a booking is now, in the owner's words rather than the status
 * machine's.
 *
 * It is the page a decision link answers with whenever there is nothing left to decide: a link
 * opened after its window closed, a link on a booking somebody has already settled, a link
 * pressed twice, and the answer after a decision this page itself made. It answers all of
 * these because from the owner's side pressing the same link twice is not a mistake worth a
 * stack trace; and answering the same page in all four cases is what keeps a replay from being
 * distinguishable from the decision it replays.
 *
 * The sentences are the nine booking states, spelled as a match rather than a list so that a
 * tenth state cannot be added without somebody deciding what an owner is told about it. Each
 * is a display key of decision.php, one whole sentence per state, which DecisionPages reads in
 * the page's language.
 */
final readonly class OutcomeWords
{
    /**
     * What the owner is told, one sentence's key per line.
     *
     * @param  ApprovalWindow  $window  where the request stands against its own window
     * @return non-empty-list<string>
     */
    public static function for(BookingStatus $status, ApprovalWindow $window): array
    {
        $words = [match ($status) {
            BookingStatus::Pending => 'decision.outcome.pending',
            BookingStatus::AwaitingApproval => 'decision.outcome.awaiting_approval',
            BookingStatus::AwaitingPayment => 'decision.outcome.awaiting_payment',
            BookingStatus::Confirmed => 'decision.outcome.confirmed',
            BookingStatus::Completed => 'decision.outcome.completed',
            BookingStatus::NoShow => 'decision.outcome.no_show',
            BookingStatus::Cancelled => 'decision.outcome.cancelled',
            BookingStatus::Rejected => 'decision.outcome.rejected',
            BookingStatus::Expired => 'decision.outcome.expired',
        }];

        // The second sentence exists because a request whose window has closed still reads as one
        // waiting for a decision, and the first sentence alone would leave an owner wondering why
        // the buttons are gone. What happens to it next is the policy the service carried at hold
        // time, which the sweeper will apply.
        //
        // That policy is on_approval_timeout, a column the API keeps behind manageBookings and
        // BookingPayload::MANAGE_ONLY names as such, and this sentence is that column in words,
        // so a reader who may not be shown it is told the window closed and nothing more. The
        // same answer covers a stored policy this build does not know: naming the wrong one would
        // be worse than naming none.
        if ($window->hasClosed) {
            $words[] = match ($window->afterTheWindow) {
                ApprovalTimeout::Expire => 'decision.window.expire',
                ApprovalTimeout::AutoApprove => 'decision.window.auto_approve',
                null => 'decision.window.closed',
            };
        }

        return $words;
    }
}
