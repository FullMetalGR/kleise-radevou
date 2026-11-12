<?php

declare(strict_types=1);

namespace App\Domain\Booking;

/**
 * Why a booking did not move, as the machine reason that reaches the wire. The code is the
 * machine reason and one code names one thing, so a case here is added when there is a new thing
 * to say and never as a second spelling of something already said.
 *
 * One vocabulary, because there is one idea. The cancellation policy answers a question about a
 * booking and a guard answers the same question at the moment of writing; they were two enums
 * for a while, the policy's closed set of reasons and the guard's, and two names for one idea is
 * how a wire code comes to mean two things. It lives here rather than beside the guards because
 * the policy is domain code and domain code may not look upwards, and because a refusal is a
 * fact about a booking before it is an HTTP answer. TransitionColumn is here for the same
 * reason: a vocabulary the layers above share is owned by the layer none of them is.
 *
 * The status a refusal applies to is carried beside the reason, by the decision or the
 * exception, and never folded into it: the customer is told one sentence either way, and a
 * reason per state would be nine codes for one answer.
 *
 * What each is answered with is the HTTP layer's decision and lives in the envelope, because a
 * status code and a sentence written to be shown are both things the wire owns.
 */
enum TransitionRefusal: string
{
    /**
     * The hold a confirm was about is gone: its expiry has passed by the server's clock, or the
     * booking already expired. It is the one refusal answered 410 rather than 409, the single
     * exception, which the booking app reads as its signal to force its own expiry flag
     * whatever the customer's clock says. No other transition answers it: a lapsed hold that
     * refuses some other act is HoldLapsed below.
     */
    case HoldExpired = 'hold_expired';

    /** The booking is not waiting to be confirmed, whatever else it is. */
    case NotConfirmable = 'not_confirmable';

    /** The booking must be paid for first. Nothing answers this until a tenant can take money. */
    case PaymentRequired = 'payment_required';

    /** The booking is in a state a cancel does not reach: it is over, or it is already off the books. */
    case NotCancellable = 'not_cancellable';

    /** The booking is in a state a reschedule does not reach. */
    case NotReschedulable = 'not_reschedulable';

    /**
     * The booking is held no longer: it is in a held state and the server found its expiry
     * behind now, so there is no held time left to move. It is a guard refusing a transition
     * and answers 409 with the rest of them, not the 410 above.
     *
     * It is the cancellation policy's own answer and the reschedule is its one caller. It keeps
     * its own case rather than collapsing into NotReschedulable. Nothing anywhere branches on
     * the distinction. The use case never reads the reason, the envelope answers both 409 and
     * differs only in the sentence, and the booking app branches on status rather than on code,
     * so it renders the sentence verbatim either way. The sentence is therefore the whole value
     * of the case, and there the two are not interchangeable: NotReschedulable asserts a
     * standing property of the booking, which is false of one that was movable a minute ago and
     * can still be cancelled. Collapsing would save one case and cost a true sentence.
     */
    case HoldLapsed = 'hold_lapsed';

    /**
     * The booking is not a checkout hold the customer may hand back. Every state but pending
     * answers this, an approval request included, which is what lets a request lodged with the
     * owner survive the tab closing.
     */
    case NotReleasable = 'not_releasable';

    /**
     * The customer is inside the per-service window, measured back from the first segment's
     * start, and is too late to do this themselves. The business is not: only the customer is
     * bound by a window.
     */
    case WindowClosed = 'window_closed';

    /**
     * The booking is not a request waiting on the owner, so there is nothing to approve. An
     * expired request answers this and not HoldExpired above: the 410 belongs to the customer's
     * confirm alone, and an owner pressing Approve on a request the clock already took is
     * refused the same way they are refused on a booking that moved on for any other reason. So
     * is a request whose window has closed and that the sweeper has not reached yet: the window
     * binds the owner's buttons as it binds their decision links.
     */
    case NotApprovable = 'not_approvable';

    /**
     * The booking is not a request waiting on the owner, so there is nothing to reject. A request
     * whose window has closed answers this too, as it answers NotApprovable: the sweeper settles
     * it, and the owner is told it is no longer theirs to decide.
     */
    case NotRejectable = 'not_rejectable';

    /**
     * The booking is not confirmed, so it cannot be marked as having happened or not. It is its
     * own reason rather than NotConfirmable above, which says the opposite thing: that one
     * refuses a booking that is not waiting to become confirmed, and this one refuses a booking
     * that never became confirmed at all.
     */
    case NotConfirmed = 'not_confirmed';

    /**
     * The booking has not started yet, so whether anybody turned up is not yet a fact. The
     * status allows the act and the clock does not, and the answer changes by itself when the
     * appointment's start passes.
     */
    case TooEarly = 'too_early';
}
