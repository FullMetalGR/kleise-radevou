import { useQuery } from '@tanstack/react-query';
import { useEffect, useMemo, useRef, useState, type ReactNode, type RefObject } from 'react';
import { classify, isRefusal, type Api, type Notice } from './api';
import type { MountOptions } from './attributes';
import { isBookingStatus, type Booking, type BookingStatus } from './booking';
import { useCancel } from './cancel';
import type { BootConfig } from './config';
import { Frame } from './Frame';
import type { Language } from './language';
import { bookingQuery, cancellable, holdsCredential, useReschedulable } from './manage';
import { MOVED_SENTENCE, RESCHEDULE_LABEL, useReschedule, type Reschedule } from './move';
import { BOOK_AGAIN_LABEL, bookAgainLink } from './myBookings';
import { NoticeRegion } from './NoticeRegion';
import { RescheduleDialog } from './RescheduleDialog';
import { BOOKING_HEADING, Summary, TotalLine, ZoneLine } from './Summary';
import { todayIn } from './time';
import { useWords, type LineKey } from './words';

/*
 * Manage mode: where the magic link lands, and what the customer can do there. The same bundle and
 * the same components as the flow, on a mount element that named a mode and two credentials
 * instead of a service.
 *
 * The credentials live in props and state alone (1.7), read once into state and one ref and
 * copied nowhere: no other DOM attribute, no storage, no URL this code builds, no log line. A
 * reload loses the token irrecoverably and the customer's email is the only other copy, which
 * is a better trade than writing a credential into browser storage on a page the tenant does
 * not control.
 *
 * Four views, decided in this order and in no other: a missing credential is the neutral panel and
 * no request; data present is ready whatever a background refetch is doing; no data and no error
 * is loading; no data with an error is the neutral panel for any 4xx and the retry panel for
 * anything else.
 *
 * The neutral panel is one fixed sentence with no booking fields, no server wording and no
 * buttons, so a wrong token, a lapsed token and an unknown booking, which arrive as one 404,
 * render byte-identically. Rendering any 4xx differently from another would make this app the
 * existence oracle the API refuses to be. The retry panel is for the failures that never
 * reached an authorization decision and therefore say nothing about the link: a transport drop
 * and a 5xx. Telling somebody on a flaky train that their link is dead would be a lie.
 *
 * Nothing here moves focus but the reschedule dialog, which takes it on the press that opens it,
 * and its close, which gives it back, and Keep this booking, whose press unmounts the row it sits
 * in and so lands on the container. Every other transition on this screen is an answer from the
 * network, a refetched booking or a hold lapsing under the clock, and focus follows the customer
 * and never the network (7.4): a focus() scrolls by default, and a below-the-fold embed that
 * jerk-scrolled the tenant's page when a booking loaded would swallow keystrokes meant for
 * something else entirely.
 *
 * The move of 6.5 is useReschedule's, owned here so that it outlives the dialog: the open state,
 * the close and the success all live in that hook. A success closes the dialog and says so in
 * the action region below the booking; the new time is whatever the invalidated read answers.
 * The landings of the focus are owned here as well, through one hook over the view's container:
 * Escape, Close and a success each arm the dialog's return, consumed once the dialog has gone and
 * the trigger has re-enabled, landing on the trigger, or on the container when a lapse has taken
 * the trigger away; Keep arms the row's, which lands on the container once the row has gone.
 *
 * The action area's two actions are exclusive (6.4 against 6.5): while the dialog is open every
 * button of the area but the dialog's own is disabled, and Reschedule is disabled while the cancel
 * row is armed or a cancel flies, so no two verdicts of the area are ever pending at once and the
 * moved sentence is never overwritten by an action the customer did not take after the move.
 *
 * The actions are one component, BookingActions, which My bookings renders on each card of its
 * list (6.6) with no token: there the session cookie the browser sends under the list mode's
 * credentials is the credential, through the same routes and the same guard. Where a card differs
 * it says so on the component's props.
 *
 * Every sentence here is a key of booking.php read through the words, each constant holding its
 * key under the name it had, so the page reads in the language the app holds; Book again keeps the
 * prefix the hosted page was opened under.
 */

interface ManageViewProps {
    readonly config: BootConfig;
    readonly options: MountOptions;
    readonly api: Api;
    /** The browser clock, read to establish the current instant and for nothing else. */
    readonly now?: () => number;
    /**
     * The sentence a redirect flashed, which the hosted manage page inlines beside the
     * config: read once by the page, so a reload brings none; null everywhere else.
     */
    readonly notice?: string | null;
    /**
     * The language the hosted page's path names by its prefix, which Book again keeps; null for
     * none and in the embed.
     */
    readonly prefix?: Language | null;
}

export const NEUTRAL_SENTENCE: LineKey = 'manage.neutral';

export const LOADING_BOOKING_SENTENCE: LineKey = 'manage.loading';

export const CANCELLING_SENTENCE: LineKey = 'manage.cancelling';

export const CONFIRM_QUESTION: LineKey = 'manage.question';

export const CANCEL_LABEL: LineKey = 'manage.cancel';

export const YES_LABEL: LineKey = 'manage.yes';

export const KEEP_LABEL: LineKey = 'manage.keep';

export const TRY_AGAIN_LABEL: LineKey = 'manage.try_again';

/**
 * Try again's label while its refetch flies: the reason the control is disabled, inside
 * it.
 */
export const TRYING_LABEL: LineKey = 'manage.trying';

/**
 * Why Reschedule is disabled while the cancel row is armed, said beside the row's question, which
 * asks about the cancel and not about the move.
 */
export const KEEP_TO_RESCHEDULE_SENTENCE: LineKey = 'manage.keep_to_reschedule';

/**
 * Who booked, one whole line whose :customer is the name, the address and the phone the booking
 * carries, never a word set before a list.
 */
export const BOOKED_BY: LineKey = 'manage.booked_by';

// The loading line goes through the one region (7.3), so it mounts empty and takes its sentence
// one effect later.
const LOADING_NOTICE: Notice = { key: LOADING_BOOKING_SENTENCE, tone: 'polite' };

/**
 * One sentence per status, and null for confirmed alone: a confirmed booking is the state that
 * needs no explanation. The map is exhaustive over the nine by type, so a tenth status is a
 * compile error here rather than a silently blank region. A status outside those nine cannot
 * come off this API, and if one ever did it would say nothing rather than making the booking
 * unreadable, which is the same trade the parse makes.
 */
const STATUS_SENTENCES: Readonly<Record<BookingStatus, LineKey | null>> = {
    pending: 'manage.statuses.pending',
    awaiting_approval: 'manage.statuses.awaiting_approval',
    awaiting_payment: 'manage.statuses.awaiting_payment',
    confirmed: null,
    completed: 'manage.statuses.completed',
    no_show: 'manage.statuses.no_show',
    cancelled: 'manage.statuses.cancelled',
    rejected: 'manage.statuses.rejected',
    expired: 'manage.statuses.expired',
};

/** The key of a status's sentence, null for confirmed and for a status outside the nine. */
export function statusSentence(status: string): LineKey | null {
    return isBookingStatus(status) ? STATUS_SENTENCES[status] : null;
}

export function ManageView({ config, options, api, now = Date.now, notice = null, prefix = null }: ManageViewProps): ReactNode {
    // The credentials, 5.5 and 6: state, and the one ref the actions below keep of the token. The
    // read is rendered from the state; the cancel and the move fire from handlers outside a
    // render and read the ref.
    const [credentials] = useState(() => ({ uuid: options.uuid, token: options.token }));
    const booking = useQuery(bookingQuery(api, credentials.uuid, credentials.token));

    const failure = useHeldFailure(booking.data, booking.error);

    const [today] = useState(() => todayIn(config.timezone, now()));

    // The view's container, where a landing goes when the control that would take it has gone.
    const frame = useRef<HTMLDivElement>(null);

    // Which of the four is on screen is decided once, by the function the frame echoes, so the
    // order is written in one place and rendered from it rather than restated here.
    const view = viewOf(credentials.uuid, credentials.token, booking.data, failure);

    // The flashed sentence, as My bookings shows its own (6.6): one polite region under the
    // title, above whatever view is on screen, and never above the neutral panel, which carries
    // no server wording (6.1).
    const flashed = useMemo<Notice | null>(() => (notice === null ? null : { text: notice, tone: 'polite' }), [notice]);

    function panel(): ReactNode {
        switch (view) {
            case 'neutral':
                return <NeutralPanel />;
            case 'loading':
                return <LoadingPanel />;
            case 'retry':
                return <RetryPanel error={failure} fetching={booking.isFetching} onRetry={() => void booking.refetch()} />;
            case 'ready':
                return booking.data === undefined ? null : (
                    <ReadyPanel booking={booking.data} timezone={config.timezone} origin={api.origin} prefix={prefix} fetching={booking.isFetching}>
                        <BookingActions api={api} booking={booking.data} token={credentials.token} timezone={config.timezone} today={today} now={now} container={frame} />
                    </ReadyPanel>
                );
        }
    }

    return (
        <Frame mode={options.mode} state="ready" view={view} ref={frame}>
            <h2 className="kr-booking__title">{config.tenantName}</h2>
            {flashed !== null && view !== 'neutral' && <NoticeRegion notice={flashed} />}
            {panel()}
        </Frame>
    );
}

/**
 * What a card of My bookings (6.6) brings to the actions beside what the manage page does; the
 * manage page holds one booking and passes none of it.
 */
export interface OnACard {
    /**
     * Another card's dialog is open: every action here is disabled while it is, the by-mouse half
     * of that dialog's modality over the list (6.5).
     */
    readonly blocked: boolean;
    /**
     * What a landed cancel says in the card's action region. The manage page says nothing there,
     * its status region saying the refetched booking is cancelled; a card's status line is no
     * region, so the card says it here.
     */
    readonly cancelled: Notice;
    /** Told when the dialog opens and when it goes, for the list's modality. */
    readonly onDialog: (open: boolean) => void;
}

interface BookingActionsProps {
    readonly api: Api;
    readonly booking: Booking;
    /**
     * The token in hand on the manage page, or null on a card of My bookings, where the session
     * cookie the browser sends under the list mode's credentials is the credential.
     */
    readonly token: string | null;
    readonly timezone: string;
    /** The tenant's today, the dialog's first day. */
    readonly today: string;
    /** The browser clock, read to establish the current instant and for nothing else. */
    readonly now: () => number;
    /** Where a landing goes when the control that would take it has gone: the view's container, or the card. */
    readonly container: RefObject<HTMLElement | null>;
    /** The card's part, on My bookings alone. */
    readonly card?: OnACard;
}

// The cancel's progress line, one object for as long as the bundle runs, so a region is never
// handed a new one for the same words.
const CANCELLING_NOTICE: Notice = { key: CANCELLING_SENTENCE, tone: 'polite' };

/**
 * The actions of 6.3 over one booking, the cancel of 6.4 and the reschedule dialog of 6.5, and the
 * action region above them: the manage page's ready view and each card of My bookings render this.
 *
 * The token is kept in one ref, read when a press happens and copied nowhere (5.5). Nothing is
 * optimistic: a cancel that lands closes the confirmation row and the invalidated read is what
 * says the booking is cancelled; a refusal keeps the booking and the row on screen, so the retry
 * is still there and a closed window is a sentence beside the booking rather than the dead-link
 * panel (6.4). The move is useReschedule's, owned here so that it outlives the dialog.
 *
 * The landings of the focus are owned here too, through one hook over the container: Escape,
 * Close and a success each arm the dialog's return, consumed once the dialog has gone and the
 * trigger has re-enabled, landing on the trigger, or on the container when a lapse has taken the
 * trigger away; Keep arms the row's, which lands on the container once the row has gone.
 */
export function BookingActions({ api, booking, token, timezone, today, now, container, card }: BookingActionsProps): ReactNode {
    const secret = useRef(token);
    const uuid = booking.uuid;
    const mayReschedule = useReschedulable(booking.status, booking.holdExpiresAt, now);

    const [armed, setArmed] = useState(false);
    // What the action region last said: a cancel's refusal or its landing, or a move's success,
    // whichever came last, as one notice so an older one never resurfaces when a newer one is
    // cleared.
    const [said, setSaid] = useState<Notice | null>(null);

    const cancel = useCancel(api, {
        onCancelled: () => {
            setArmed(false);
            setSaid(card?.cancelled ?? null);
        },
        onRefused: setSaid,
    });

    // A fresh object per success, so a second move is announced as the first was.
    const move = useMoveDialog(api, uuid, secret, container, card?.onDialog, () => setSaid({ key: MOVED_SENTENCE, tone: 'polite' }));
    const landFromRow = useLandingOnClose(armed, container);

    // The first press sends nothing. The wire count until "Yes" is zero (6.4).
    function ask(): void {
        setArmed(true);
    }

    function send(): void {
        if (holdsCredential(api, secret.current)) {
            cancel.send(uuid, secret.current);
        }
    }

    // The way out is always reachable: it frees the latch, so one hung cancel cannot deaden
    // the next, and it retires the ticket, so a verdict that lands afterwards renders nothing.
    function keep(): void {
        landFromRow();
        cancel.release();
        setArmed(false);
        setSaid(null);
    }

    return (
        <>
            <NoticeRegion notice={cancel.busy ? CANCELLING_NOTICE : said} />
            <ActionArea
                mayCancel={cancellable(booking.status)}
                mayReschedule={mayReschedule}
                armed={armed}
                busy={cancel.busy}
                dialogOpen={move.reschedule.open}
                modal={move.reschedule.open || card?.blocked === true}
                trigger={move.trigger}
                onAsk={ask}
                onSend={send}
                onKeep={keep}
                onReschedule={move.reschedule.begin}
            />
            {move.reschedule.open && (
                <RescheduleDialog
                    api={api}
                    uuid={uuid}
                    token={token}
                    timezone={timezone}
                    today={today}
                    moving={move.reschedule.moving}
                    refusal={move.reschedule.refusal}
                    onPick={move.reschedule.move}
                    onClose={move.close}
                />
            )}
        </>
    );
}

interface MoveDialog {
    readonly reschedule: Reschedule;
    /** The Reschedule trigger, where the dialog's way out lands while it is on screen and enabled. */
    readonly trigger: RefObject<HTMLButtonElement | null>;
    /** Escape and Close alike: arm the return, then close. */
    readonly close: () => void;
}

/**
 * The reschedule of 6.5 with its landing and its report: a success closes the dialog, so it arms
 * the return as the other two ways out do, running from a verdict long after the render that
 * declares the return; and the list hears of the dialog for as long as it is open.
 */
function useMoveDialog(api: Api, uuid: string, secret: RefObject<string | null>, container: RefObject<HTMLElement | null>, onDialog: ((open: boolean) => void) | undefined, onMoved: () => void): MoveDialog {
    const reschedule = useReschedule(api, uuid, secret, () => {
        returnFromDialog();
        onMoved();
    });
    const trigger = useRef<HTMLButtonElement>(null);
    const returnFromDialog = useLandingOnClose(reschedule.open, container, trigger);

    useReportedDialog(reschedule.open, onDialog);

    return {
        reschedule,
        trigger,
        close: () => {
            returnFromDialog();
            reschedule.close();
        },
    };
}

/**
 * The list's modality hears of a dialog for as long as it is open, and of its going however it
 * goes, the card's unmount included (6.6). Nothing is told where nobody listens.
 */
function useReportedDialog(open: boolean, onDialog: ((open: boolean) => void) | undefined): void {
    useEffect(() => {
        if (!open || onDialog === undefined) {
            return undefined;
        }

        onDialog(true);

        return () => onDialog(false);
    }, [open, onDialog]);
}

/**
 * The read's failure, held until an answer replaces it. The query client clears a query's
 * error the moment a refetch starts, so reading it directly would unmount the retry panel
 * under the finger that pressed Try again and flash the loading line in its place. Held
 * here, the panel stays put with its button disabled while the request flies (6.1). This is
 * the state-adjusted-during-render pattern and not an effect, because an effect would paint
 * that flash before correcting it.
 */
function useHeldFailure(data: Booking | undefined, error: unknown): unknown {
    const [failure, setFailure] = useState<unknown>(null);

    const held = data !== undefined ? null : (error ?? failure);

    if (held !== failure) {
        setFailure(held);
    }

    return failure;
}

/**
 * A landing of the focus for a customer's way out of something on screen: the dialog's close
 * (6.5) and Keep this booking withdrawing the cancel row (6.4). The press arms it and an effect
 * consumes it once `shown` has gone false, because at the press the control that would take the
 * focus is still disabled, or the control that holds it is still mounted. It lands on `preferred`
 * when that is on screen and enabled, and on the view's container otherwise, never leaving the
 * focus on the page body. Only a press arms it, so this is focus following the customer (7.4): a
 * verdict or a lapse that makes `shown` false with nothing armed moves nothing.
 */
function useLandingOnClose(shown: boolean, frame: RefObject<HTMLElement | null>, preferred?: RefObject<HTMLButtonElement | null>): () => void {
    const pending = useRef(false);

    useEffect(() => {
        if (shown || !pending.current) {
            return;
        }

        pending.current = false;

        const target = preferred?.current ?? null;

        (target !== null && !target.disabled ? target : frame.current)?.focus();
    }, [shown, frame, preferred]);

    return () => {
        pending.current = true;
    };
}

export type ManageViewName = 'neutral' | 'ready' | 'loading' | 'retry';

/**
 * Which of the four is on screen, echoed on the frame beside the mode so that it is externally
 * observable in a test and in support, exactly as the flow echoes its step. The order is 6.1's
 * and the panel above reads the same four answers in the same order.
 */
export function viewOf(uuid: string | null, token: string | null, data: Booking | undefined, error: unknown): ManageViewName {
    if (uuid === null || token === null) {
        return 'neutral';
    }

    if (data !== undefined) {
        return 'ready';
    }

    if (error === null || error === undefined) {
        return 'loading';
    }

    return isRefusal(error) ? 'neutral' : 'retry';
}

function NeutralPanel(): ReactNode {
    const { text } = useWords();

    return (
        <div className="kr-booking__step kr-booking__manage">
            <p className="kr-booking__notice">{text(NEUTRAL_SENTENCE)}</p>
        </div>
    );
}

function LoadingPanel(): ReactNode {
    return (
        <div className="kr-booking__step kr-booking__manage" aria-busy={true}>
            <NoticeRegion notice={LOADING_NOTICE} />
        </div>
    );
}

interface RetryPanelProps {
    readonly error: unknown;
    readonly fetching: boolean;
    readonly onRetry: () => void;
}

/**
 * The failure that never reached a decision: the server's own sentence for a 5xx and the fixed
 * one for a transport drop, both as alerts, which is the classifier of 4.1 and not a second
 * reading of it. The way forward is a real button, disabled only while it is working.
 */
function RetryPanel({ error, fetching, onRetry }: RetryPanelProps): ReactNode {
    const { text } = useWords();
    const notice = useMemo<Notice>(() => ({ ...classify(error), tone: 'alert' }), [error]);

    return (
        <div className="kr-booking__step kr-booking__manage" aria-busy={fetching}>
            <NoticeRegion notice={notice} />
            <div className="kr-booking__actions">
                <button type="button" className="kr-booking__button kr-booking__retry" disabled={fetching} onClick={onRetry}>
                    {text(fetching ? TRYING_LABEL : TRY_AGAIN_LABEL)}
                </button>
            </div>
        </div>
    );
}

interface ReadyPanelProps {
    readonly booking: Booking;
    readonly timezone: string;
    /**
     * The origin the app talks to, which is the tenant's own: the hosted page's, or the embed
     * script's on a tenant's site. Book again opens the hosted page there.
     */
    readonly origin: string;
    /** The prefix Book again keeps, the hosted page's; null for none. */
    readonly prefix: Language | null;
    readonly fetching: boolean;
    /** The actions, the action region above them and the reschedule dialog when it is open (6.2). */
    readonly children: ReactNode;
}

/**
 * The ready view: the title, the items, the total, the customer line, the two notice regions,
 * then the actions, then Book again, in that order; the booking's own lines, its time zone line
 * and its status region in one card, the actions pills. The details render for every status,
 * because the customer came to see their booking; only the controls and the sentence change.
 * The reschedule dialog renders with the actions, after them, when it is open, and stays
 * whatever the guard says meanwhile.
 *
 * Book again is a plain link to the hosted page with the booking's first item preselected, the
 * staff left out where it is the implicit member (bookAgainLink, the link a card of My bookings
 * carries), for every status: it carries no credential, asks the server nothing and disables
 * nothing, so no guard decides it.
 */
function ReadyPanel({ booking, timezone, origin, prefix, fetching, children }: ReadyPanelProps): ReactNode {
    const { text } = useWords();
    const status = useMemo<Notice | null>(() => {
        const key = statusSentence(booking.status);

        return key === null ? null : { key, tone: 'polite' };
    }, [booking.status]);
    const customer = [booking.customerName, booking.customerEmail, booking.customerPhone].filter((line) => line !== null).join(', ');

    return (
        <div className="kr-booking__step kr-booking__manage" aria-busy={fetching}>
            <div className="kr-booking__card kr-booking__booking">
                <h3 className="kr-booking__subtitle">{text(BOOKING_HEADING)}</h3>
                <Summary items={booking.items} currency={booking.currency} timezone={timezone} />
                <TotalLine minor={booking.totalMinor} currency={booking.currency} />
                <p className="kr-booking__customer">{text(BOOKED_BY, { customer })}</p>
                <ZoneLine timezone={timezone} />
                <NoticeRegion notice={status} />
            </div>
            {children}
            <a className="kr-booking__again" href={bookAgainLink(origin, booking, prefix)}>
                {text(BOOK_AGAIN_LABEL)}
            </a>
        </div>
    );
}

interface AreaControls {
    readonly armed: boolean;
    readonly busy: boolean;
    /** Whether the reschedule dialog is open: the background actions are disabled, not unmounted, while it is (6.5). */
    readonly dialogOpen: boolean;
    /**
     * Whether a dialog is open on the page, this booking's or, on the list of 6.6, another card's:
     * every control here is disabled while one is.
     */
    readonly modal: boolean;
    /** The Reschedule button, for the return of focus when the dialog goes. */
    readonly trigger: RefObject<HTMLButtonElement | null>;
    readonly onAsk: () => void;
    readonly onSend: () => void;
    readonly onKeep: () => void;
    readonly onReschedule: () => void;
}

interface ActionAreaProps extends AreaControls {
    readonly mayCancel: boolean;
    readonly mayReschedule: boolean;
}

/**
 * The actions of 6.3 and the cancel's confirmation row of 6.4, or nothing at all when the status
 * offers neither. The two actions are exclusive. While the reschedule dialog is open every
 * control here is disabled, "Keep this booking" included, and stays mounted, so the trigger is
 * the same element for the dialog's whole lifetime, even across a lapse: the trigger goes once
 * the dialog has closed. Reschedule is disabled while the cancel row is armed, which covers a
 * cancel in flight: the row stays armed until the verdict lands or Keep withdraws the cancel,
 * and a withdrawn cancel is no longer busy. The reason for each is text within the step: the
 * open dialog for everything it disables, the row's question for Cancel, the row's own sentence
 * for Reschedule, and the progress line for "Yes, cancel it". On a card of My bookings another
 * card's open dialog disables every control here as this card's own would, its heading the
 * sentence that says why (6.6).
 */
function ActionArea({ mayCancel, mayReschedule, armed, busy, dialogOpen, modal, trigger, onAsk, onSend, onKeep, onReschedule }: ActionAreaProps): ReactNode {
    const { text } = useWords();
    const showTrigger = mayReschedule || dialogOpen;

    if (!mayCancel && !showTrigger) {
        return null;
    }

    return (
        <>
            <div className="kr-booking__actions">
                {mayCancel && (
                    <button type="button" className="kr-booking__button kr-booking__cancel" disabled={armed || modal} onClick={onAsk}>
                        {text(CANCEL_LABEL)}
                    </button>
                )}
                {showTrigger && (
                    <button type="button" ref={trigger} className="kr-booking__button kr-booking__reschedule" disabled={modal || armed} onClick={onReschedule}>
                        {text(RESCHEDULE_LABEL)}
                    </button>
                )}
            </div>
            {armed && <ConfirmRow busy={busy} dialogOpen={modal} blocksReschedule={showTrigger} onSend={onSend} onKeep={onKeep} />}
        </>
    );
}

interface ConfirmRowProps extends Pick<AreaControls, 'busy' | 'dialogOpen' | 'onSend' | 'onKeep'> {
    /** Whether Reschedule is on screen, disabled by this row, so the row says why. */
    readonly blocksReschedule: boolean;
}

/**
 * The cancel's confirmation row of 6.4: the question, the destructive "Yes, cancel it", and the
 * way out, "Keep this booking", never disabled while the row is the live interaction and
 * disabled, as every background button is, only while the reschedule dialog is open.
 */
function ConfirmRow({ busy, dialogOpen, blocksReschedule, onSend, onKeep }: ConfirmRowProps): ReactNode {
    const { text } = useWords();

    return (
        <div className="kr-booking__confirm-row">
            <p className="kr-booking__question">{text(CONFIRM_QUESTION)}</p>
            {blocksReschedule && <p className="kr-booking__hint">{text(KEEP_TO_RESCHEDULE_SENTENCE)}</p>}
            <div className="kr-booking__actions">
                <button type="button" className="kr-booking__button kr-booking__button--danger kr-booking__yes" disabled={busy || dialogOpen} onClick={onSend}>
                    {text(YES_LABEL)}
                </button>
                <button type="button" className="kr-booking__button kr-booking__keep" disabled={dialogOpen} onClick={onKeep}>
                    {text(KEEP_LABEL)}
                </button>
            </div>
        </div>
    );
}
