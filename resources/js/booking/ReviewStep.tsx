import type { ReactNode } from 'react';
import type { Notice } from './api';
import type { Booking } from './booking';
import { Countdown } from './Countdown';
import { NoticeRegion } from './NoticeRegion';
import { BOOKING_HEADING, Summary, TotalLine, ZoneLine } from './Summary';
import { useWords, type LineKey } from './words';

/*
 * Step review: the countdown, the summary, the tenant's Important info where set, the action
 * button, the notice region, the restart control, in that order. The summary, the total and the
 * time zone line sit in one card, the Important info in a soft panel, the action button a
 * full-width primary pill and the restart control a secondary one; the stylesheet alone draws
 * the countdown reading between the panel and the button, the DOM keeping this order and the
 * regions their places.
 *
 * The countdown renders only when the server returned an expiry, and a null expiry renders none
 * and never blocks the confirm (5.3). It is keyed on the booking, so a fresh hold is a fresh
 * countdown whose once-only expiry has not fired. There is no sentence restating the deadline:
 * the countdown is that sentence, and 2.5 lists six things. The one line naming the time zone
 * stays, because every time on the step is the tenant's (7.1).
 *
 * The summary is read entirely off the returned booking and never off the app's own
 * selections, because the server re-validated and re-priced the chain under lock and echoing
 * local state could show a price the engine never agreed to. It is rendered by the shared
 * component the manage view reads the same booking through, so the one shape a customer is
 * shown a booking in is one component and not two.
 *
 * The Important info is the owner's one text, from the boot config, rendered as a paragraph
 * under its own heading just above the action button, so it is read before the press that
 * commits to it. Where the owner left it empty the config carries null and the step renders
 * neither the heading nor the paragraph: no label over nothing. It is text and stays text.
 *
 * The action button's label is the branch. With a checkout_url it reads "Continue to payment"
 * and its press asks the flow to hand the hold off and navigate; no confirm is ever sent on
 * that branch. Without one it reads "Confirm booking" and asks the flow to post the confirm.
 * Either way the press asks for something, so the button is never an enabled control that
 * does nothing. The branch is on the presence of the field, never on the payment mode, and
 * there is never both. The url itself is the flow's to follow and is rendered nowhere: it
 * carries the manage token.
 *
 * The button is disabled while a confirm or the recovery of its refusal is in flight, as the
 * flow's busy says, and once the hold is known to have expired. That is the visible affordance
 * and only that: the guard that refuses a second press before disabled reaches the DOM is the
 * flow's synchronous latch (4.5).
 *
 * The notice region is the shared one, so a refusal is announced politely and an alert
 * announces on appearance, both with the summary still on screen: a customer looking at a
 * refusal is still looking at what they are being refused.
 *
 * The restart control, "Pick another time", renders last and only when the flow offers it,
 * which is the flow's one expression and not this step's to restate. What taking it does is the
 * flow's too: this step only says it was taken.
 */

interface ReviewStepProps {
    readonly booking: Booking;
    readonly timezone: string;
    readonly notice: Notice | null;
    readonly busy: boolean;
    readonly expired: boolean;
    /** Whether "Pick another time" is offered, as the flow decides it. */
    readonly restartable: boolean;
    /** The tenant's Important info off the boot config, null where the owner set none. */
    readonly importantInfo: string | null;
    /** The browser clock, read by the countdown and by nothing else on this step. */
    readonly now: () => number;
    readonly onConfirm: () => void;
    /** The payment branch's press: the hand-off and the navigation, both the flow's. */
    readonly onPay: () => void;
    readonly onExpire: () => void;
    readonly onRestart: () => void;
}

export const CONFIRM_LABEL: LineKey = 'review.confirm';

export const PAYMENT_LABEL: LineKey = 'review.pay';

export const RESTART_LABEL: LineKey = 'review.restart';

/** The heading over the tenant's Important info, the confirmation's own heading for it too. */
export const IMPORTANT_INFO_HEADING: LineKey = 'review.important_info';

/**
 * The confirm button's label while a confirm or the recovery of its refusal is out: the reason
 * the control is disabled, inside it, as the details step's busy label is.
 */
export const CONFIRMING_LABEL: LineKey = 'review.confirming';

export function ReviewStep({ booking, timezone, notice, busy, expired, restartable, importantInfo, now, onConfirm, onPay, onExpire, onRestart }: ReviewStepProps): ReactNode {
    const { text } = useWords();
    const pays = booking.checkoutUrl !== undefined;

    return (
        <div className="kr-booking__step">
            {booking.holdExpiresAt !== null && <Countdown key={booking.uuid} deadline={booking.holdExpiresAt} now={now} expired={expired} onExpire={onExpire} />}
            <div className="kr-booking__card kr-booking__review">
                <h3 className="kr-booking__subtitle">{text(BOOKING_HEADING)}</h3>
                <Summary items={booking.items} currency={booking.currency} timezone={timezone} />
                <TotalLine minor={booking.totalMinor} currency={booking.currency} />
                <ZoneLine timezone={timezone} />
            </div>
            {importantInfo !== null && (
                <div className="kr-booking__important">
                    <h3 className="kr-booking__subtitle">{text(IMPORTANT_INFO_HEADING)}</h3>
                    <p className="kr-booking__important-text">{importantInfo}</p>
                </div>
            )}
            <div className="kr-booking__actions">
                <button
                    type="button"
                    className="kr-booking__button kr-booking__button--primary kr-booking__confirm"
                    disabled={busy || expired}
                    onClick={pays ? onPay : onConfirm}
                >
                    {text(actionLabel(pays, busy))}
                </button>
            </div>
            <NoticeRegion notice={notice} />
            {restartable && (
                <div className="kr-booking__actions">
                    <button type="button" className="kr-booking__button kr-booking__restart" onClick={onRestart}>
                        {text(RESTART_LABEL)}
                    </button>
                </div>
            )}
        </div>
    );
}

/**
 * The branch of 2.5, and on the confirm branch the busy label. The payment branch keeps its
 * label: the only thing that disables it short of expiry is the hand-off, whose sentence in the
 * notice region is the reason.
 */
function actionLabel(pays: boolean, busy: boolean): LineKey {
    if (pays) {
        return PAYMENT_LABEL;
    }

    return busy ? CONFIRMING_LABEL : CONFIRM_LABEL;
}
