import { useQuery } from '@tanstack/react-query';
import { useCallback, useEffect, useId, useMemo, useRef, useState, type FormEvent, type ReactNode } from 'react';
import type { Api, Notice } from './api';
import type { MountOptions } from './attributes';
import type { Booking } from './booking';
import type { BootConfig } from './config';
import { Frame } from './Frame';
import { pathUnder, type Language } from './language';
import { holdsCredential } from './manage';
import { BookingActions, statusSentence, type OnACard } from './ManageView';
import { BOOK_AGAIN_LABEL, bookAgainLink, LINK_PATH, myBookingsQuery, SIGN_OUT_PATH, TOKEN_FIELD, type ListedBookings, type MyBookingsPage } from './myBookings';
import { NoticeRegion } from './NoticeRegion';
import { Summary, TotalLine, ZoneLine } from './Summary';
import { todayIn } from './time';
import { useWords, type LineKey } from './words';

/*
 * My bookings: the third mode, on the hosted page alone. Two views, read off what the page
 * inlined: the session's bookings where a session is open, and the form asking for a link where
 * none is. The sentence a redirect flashed sits above either, in the one region.
 *
 * The list is the server's, upcoming first and then past, and nothing here sorts or filters it.
 * Each card is a booking as the manage page reads one: its items in the tenant's clock with the
 * staff where named, the total in the booking's own currency, the status sentence of 6.2, the
 * actions of 6.3 from its status, and Book again.
 *
 * The actions are the manage page's, BookingActions, with no token: the cancel and the reschedule
 * dialog call the same routes, and the credential is the session cookie the browser sends under
 * the list mode's credentials, which include them on this origin alone. Nothing is optimistic: the
 * list is a query seeded from the page and read again as JSON from GET /my-bookings when an action
 * lands, and what the cards show next is that answer. Where a card differs from the manage page: a
 * landed cancel says so in the card's action region, since a card's status line is no region; Keep
 * and a closed dialog land on the card; and a dialog open on one card disables every other card's
 * actions too, the by-mouse half of its modality over the list, its heading the sentence saying
 * why.
 *
 * Both forms are the page's own, posted by the browser to the tenant's origin with the token the
 * page inlined: the answer is a page, the form again with its sentence or the list, never JSON. A
 * submit shows its busy label and a second press of the same form sends nothing, a latch and not a
 * disabled control; a page the browser brings back from its history starts unlatched. Both forms
 * and every Book again keep the prefix the page was opened under, bare for none, so a customer
 * reading in one language stays in it through the round trip, the server's way back included.
 *
 * Every sentence here is a key of booking.php read through the words, each constant holding its
 * key under the name it had; the sentence a redirect flashed is the server's, shown as it came.
 */

export const MY_BOOKINGS_TITLE: LineKey = 'my_bookings.title';

export const FORM_INTRO: LineKey = 'my_bookings.intro';

export const EMAIL_LABEL: LineKey = 'my_bookings.email';

export const SEND_LABEL: LineKey = 'my_bookings.send';

/** The send's label while the browser carries the form away: the busy state, inside the button. */
export const SENDING_LABEL: LineKey = 'my_bookings.sending';

export const UPCOMING_HEADING: LineKey = 'my_bookings.upcoming';

export const PAST_HEADING: LineKey = 'my_bookings.past';

export const NO_BOOKINGS_SENTENCE: LineKey = 'my_bookings.none';

export const SIGN_OUT_LABEL: LineKey = 'my_bookings.sign_out';

export const SIGNING_OUT_LABEL: LineKey = 'my_bookings.signing_out';

/**
 * What a card's action region says once its cancel has landed: the card's status line is a plain
 * line and no region, so this is what a screen reader hears of the server's answer.
 */
export const CANCELLED_SENTENCE: LineKey = 'my_bookings.cancelled';

const CANCELLED_NOTICE: Notice = { key: CANCELLED_SENTENCE, tone: 'polite' };

export type MyBookingsViewName = 'list' | 'form';

interface MyBookingsViewProps {
    readonly config: BootConfig;
    readonly options: MountOptions;
    readonly page: MyBookingsPage;
    readonly api: Api;
    /** The browser clock, read to establish the current instant and for nothing else. */
    readonly now?: () => number;
    /**
     * The language the page's path names by its prefix, which the forms and Book again keep; null
     * for a bare path.
     */
    readonly prefix?: Language | null;
}

export function MyBookingsView({ config, options, page, api, now = Date.now, prefix = null }: MyBookingsViewProps): ReactNode {
    const { text } = useWords();
    // The page's answer, then the server's each time an action invalidates it; the sentence a
    // redirect flashed is the page's own and no re-read replaces it.
    const list = useQuery(myBookingsQuery(api, page));
    const current = list.data;
    const notice = useMemo<Notice | null>(() => (page.notice === null ? null : { text: page.notice, tone: 'polite' }), [page.notice]);
    const [today] = useState(() => todayIn(config.timezone, now()));
    const view: MyBookingsViewName = current.bookings === null ? 'form' : 'list';

    return (
        <Frame mode={options.mode} state="ready" view={view}>
            <h2 className="kr-booking__title">{config.tenantName}</h2>
            <h3 className="kr-booking__subtitle">{text(MY_BOOKINGS_TITLE)}</h3>
            <NoticeRegion notice={notice} />
            {current.bookings === null ? (
                <LinkForm origin={api.origin} prefix={prefix} csrf={current.csrf} />
            ) : (
                <BookingsList bookings={current.bookings} timezone={config.timezone} origin={api.origin} prefix={prefix} csrf={current.csrf} api={api} today={today} now={now} fetching={list.isFetching} />
            )}
        </Frame>
    );
}

interface Latch {
    readonly busy: boolean;
    readonly onSubmit: (event: FormEvent<HTMLFormElement>) => void;
}

/**
 * One submit per form. The first lets the browser carry the form away and shows the busy label;
 * a second sends nothing. A page restored from the browser's history is a page whose submit never
 * landed, so it starts unlatched.
 */
function useSubmitLatch(): Latch {
    const sent = useRef(false);
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        const restored = (event: PageTransitionEvent): void => {
            if (event.persisted) {
                sent.current = false;
                setBusy(false);
            }
        };

        window.addEventListener('pageshow', restored);

        return () => window.removeEventListener('pageshow', restored);
    }, []);

    return {
        busy,
        onSubmit: (event) => {
            if (sent.current) {
                event.preventDefault();

                return;
            }

            sent.current = true;
            setBusy(true);
        },
    };
}

interface FormProps {
    readonly origin: string;
    /** The page's prefix, which the form posts under; null for none. */
    readonly prefix: Language | null;
    readonly csrf: string;
}

/**
 * The form asking for a link, in a card: one address, posted to the tenant's origin. The answer
 * is the same sentence whatever the address, so nothing here judges it beyond the browser's own
 * check.
 */
function LinkForm({ origin, prefix, csrf }: FormProps): ReactNode {
    const { text } = useWords();
    const latch = useSubmitLatch();
    const id = useId();

    return (
        <div className="kr-booking__step kr-booking__mine">
            <div className="kr-booking__mine-form">
                <p className="kr-booking__description">{text(FORM_INTRO)}</p>
                <form className="kr-booking__form" method="post" action={origin + pathUnder(LINK_PATH, prefix)} aria-busy={latch.busy} onSubmit={latch.onSubmit}>
                    <input type="hidden" name={TOKEN_FIELD} value={csrf} />
                    <div className="kr-booking__field">
                        <label className="kr-booking__label" htmlFor={id}>
                            {text(EMAIL_LABEL)}
                        </label>
                        <input className="kr-booking__input" id={id} name="email" type="email" autoComplete="email" required />
                    </div>
                    <div className="kr-booking__actions">
                        <button type="submit" className="kr-booking__button kr-booking__button--primary kr-booking__send">
                            {text(latch.busy ? SENDING_LABEL : SEND_LABEL)}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
}

interface ListProps extends FormProps {
    readonly bookings: ListedBookings;
    readonly timezone: string;
    readonly api: Api;
    /** The tenant's today, a dialog's first day. */
    readonly today: string;
    readonly now: () => number;
    /** Whether the list is being read again after an action. */
    readonly fetching: boolean;
}

/**
 * The session's bookings, upcoming then past as the server ordered them, and the sign-out. The
 * list knows which card's dialog is open, if any, so every other card's actions wait on it.
 */
function BookingsList({ bookings, timezone, origin, prefix, csrf, api, today, now, fetching }: ListProps): ReactNode {
    const { text } = useWords();
    const none = bookings.upcoming.length === 0 && bookings.past.length === 0;
    const [open, setOpen] = useState<string | null>(null);
    const onDialog = useCallback((uuid: string, opened: boolean) => {
        setOpen((current) => (opened ? uuid : current === uuid ? null : current));
    }, []);
    const shared = { timezone, origin, prefix, api, today, now, open, onDialog };

    return (
        <div className="kr-booking__step kr-booking__mine" aria-busy={fetching}>
            <ZoneLine timezone={timezone} />
            {none && <p className="kr-booking__empty">{text(NO_BOOKINGS_SENTENCE)}</p>}
            <Cards heading={UPCOMING_HEADING} bookings={bookings.upcoming} {...shared} />
            <Cards heading={PAST_HEADING} bookings={bookings.past} {...shared} />
            <SignOut origin={origin} prefix={prefix} csrf={csrf} />
        </div>
    );
}

interface CardsProps {
    readonly heading: LineKey;
    readonly bookings: readonly Booking[];
    readonly timezone: string;
    readonly origin: string;
    readonly prefix: Language | null;
    readonly api: Api;
    readonly today: string;
    readonly now: () => number;
    /** The booking whose dialog is open on the page, or null. */
    readonly open: string | null;
    readonly onDialog: (uuid: string, opened: boolean) => void;
}

function Cards({ heading, bookings, ...card }: CardsProps): ReactNode {
    const { text } = useWords();

    if (bookings.length === 0) {
        return null;
    }

    return (
        <section className="kr-booking__group">
            <h4 className="kr-booking__subtitle">{text(heading)}</h4>
            <ul className="kr-booking__list">
                {bookings.map((booking) => (
                    <Card key={booking.uuid} booking={booking} {...card} />
                ))}
            </ul>
        </section>
    );
}

interface CardProps extends Omit<CardsProps, 'heading' | 'bookings'> {
    readonly booking: Booking;
}

/**
 * One booking: the summary, the total, the status line, the actions of 6.3 and Book again. The
 * card is where a landing goes once the control that held the focus has gone, so it takes the
 * focus without entering the tab order. The actions render only where the mount's requests carry
 * the cookie, which in list mode they always do, so no control is offered that could only fail.
 */
function Card({ booking, timezone, origin, prefix, api, today, now, open, onDialog }: CardProps): ReactNode {
    const { text } = useWords();
    const card = useRef<HTMLLIElement>(null);
    const uuid = booking.uuid;
    const report = useCallback((opened: boolean) => onDialog(uuid, opened), [onDialog, uuid]);
    const blocked = open !== null && open !== uuid;
    const part = useMemo<OnACard>(() => ({ blocked, cancelled: CANCELLED_NOTICE, onDialog: report }), [blocked, report]);

    return (
        <li ref={card} className="kr-booking__card" tabIndex={-1}>
            <Summary items={booking.items} currency={booking.currency} timezone={timezone} />
            <TotalLine minor={booking.totalMinor} currency={booking.currency} />
            <StatusLine status={booking.status} />
            {holdsCredential(api, null) && (
                <BookingActions api={api} booking={booking} token={null} timezone={timezone} today={today} now={now} container={card} card={part} />
            )}
            <a className="kr-booking__again" href={bookAgainLink(origin, booking, prefix)}>
                {text(BOOK_AGAIN_LABEL)}
            </a>
        </li>
    );
}

/**
 * The status sentence of 6.2, and nothing for a confirmed booking, which needs none. A plain line
 * and not a region: it is part of the card, not news.
 */
function StatusLine({ status }: { readonly status: string }): ReactNode {
    const { text } = useWords();
    const sentence = statusSentence(status);

    return sentence === null ? null : <p className="kr-booking__status">{text(sentence)}</p>;
}

function SignOut({ origin, prefix, csrf }: FormProps): ReactNode {
    const { text } = useWords();
    const latch = useSubmitLatch();

    return (
        <form className="kr-booking__form" method="post" action={origin + pathUnder(SIGN_OUT_PATH, prefix)} aria-busy={latch.busy} onSubmit={latch.onSubmit}>
            <input type="hidden" name={TOKEN_FIELD} value={csrf} />
            <div className="kr-booking__actions">
                <button type="submit" className="kr-booking__button kr-booking__sign-out">
                    {text(latch.busy ? SIGNING_OUT_LABEL : SIGN_OUT_LABEL)}
                </button>
            </div>
        </form>
    );
}
