import { queryOptions, type QueryClient } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { credentialsOf, getJson, type Api } from './api';
import { asRecord, HELD_STATUSES, isBookingStatus, parseBooking, type Booking } from './booking';
import { MY_BOOKINGS_KEY } from './myBookings';
import { parseInstant } from './time';

/*
 * Manage mode's one read and the two guards that decide what may be done with what it answers.
 *
 * The read is GET /bookings/{uuid}, keyed on the booking and its identifier and enabled only
 * where both credentials are in hand, so a mount naming one of them asks the server nothing at
 * all. The token travels in the query string, which is where this API reads it on a GET: the
 * method carries no body, and the magic link already holds the token in a URL, so the query
 * form adds nothing to what a log or a referrer already sees.
 *
 * Freshness is zero because a booking's status changes without the customer touching anything:
 * an owner approves, a hold expires, a payment lands. A manage tab left open on "waiting for
 * approval" has to tell the truth when its owner comes back to it (3.1).
 *
 * The retry predicate is the query client's and is not restated here: no 4xx is ever retried,
 * so the 404 that a wrong token, a lapsed token and an unknown booking all answer with lands
 * immediately and becomes one neutral panel rather than three attempts at one (3.2 and 6.1).
 *
 * The guards mirror the server's, CancellationPolicy::mayCancel and mayReschedule, because a
 * rendered control must never be one that is guaranteed to fail. They mirror its status rule
 * and not its cancellation window: the windows are a service setting and the guest view does
 * not carry them, so the window's refusal arrives as a worded 403 on the booking the customer
 * is looking at (6.4) and is not something to hide a button over.
 */

export const BOOKINGS_PATH = '/api/v1/bookings';

/**
 * The manage page's second JSON script beside the config, written only where a redirect flashed a
 * sentence: the pay hop's, saying the payment is on its way, that a settlement lost its race, or
 * that the customer pays at the venue. The sentence and nothing else; the credentials stay on the
 * mount.
 */
export const INLINED_NOTICE_ID = 'kr-notice';

/**
 * The sentence the manage page inlined, or null where the document carries none that parses. The
 * boot asks only where the page is on the script's own origin, as it asks for the inlined config.
 */
export function inlinedNotice(doc: Document): string | null {
    const el = doc.getElementById(INLINED_NOTICE_ID);

    if (el === null || el.tagName !== 'SCRIPT' || el.getAttribute('type') !== 'application/json') {
        return null;
    }

    try {
        const notice = asRecord(JSON.parse(el.textContent ?? ''))?.['notice'];

        return typeof notice === 'string' && notice !== '' ? notice : null;
    } catch {
        return null;
    }
}

/**
 * What the token is called in both of its transports.
 */
export const TOKEN = 'token';

/**
 * The cache key of one booking: the word and the identifier, and never the credential. Two
 * customers on one page are two identifiers, and a key carrying a secret would put that secret
 * into every devtools frame that reads a cache.
 */
export function bookingKey(uuid: string | null): readonly unknown[] {
    return ['booking', uuid];
}

/**
 * Whether a request about a booking can carry a credential: the token where one is in hand, as on
 * the manage page, or, where none is, the My bookings session cookie the browser sends from the
 * list mode, whose requests include credentials. Neither is read here: the token is the caller's,
 * and the cookie is the browser's.
 */
export function holdsCredential(api: Api, token: string | null): boolean {
    return token !== null || credentialsOf(api) === 'include';
}

/**
 * What a landed cancel or move invalidates, in the hook and nowhere else: the booking's own read
 * on the manage page and My bookings' list, whichever of the two the mount holds, so what appears
 * next is the server's answer about the row.
 */
export function invalidateShown(client: QueryClient, uuid: string): void {
    void client.invalidateQueries({ queryKey: bookingKey(uuid) });
    void client.invalidateQueries({ queryKey: MY_BOOKINGS_KEY });
}

/**
 * One booking's URL under a base, with the token as its one query parameter: the shape of both
 * requests that carry the credential in a query string, the manage read and the release. Both
 * halves are escaped: the identifier reaches this app from a page attribute or a server answer
 * and must not be able to become two path segments, and the token must not be able to become a
 * second parameter.
 */
export function tokenPath(base: string, uuid: string, token: string): string {
    return `${base}/${encodeURIComponent(uuid)}?${TOKEN}=${encodeURIComponent(token)}`;
}

/**
 * The read's URL.
 */
export function bookingPath(uuid: string, token: string): string {
    return tokenPath(BOOKINGS_PATH, uuid, token);
}

export async function fetchBooking(api: Api, uuid: string, token: string): Promise<Booking> {
    const booking = parseBooking(await getJson(api, bookingPath(uuid, token)));

    if (booking === null) {
        throw new Error(`GET ${BOOKINGS_PATH}/${uuid} answered something other than a booking.`);
    }

    return booking;
}

export function bookingQuery(api: Api, uuid: string | null, token: string | null) {
    return queryOptions({
        queryKey: bookingKey(uuid),
        queryFn: () => {
            if (uuid === null || token === null) {
                throw new Error('The booking query ran without both credentials.');
            }

            return fetchBooking(api, uuid, token);
        },
        enabled: uuid !== null && token !== null,
        staleTime: 0,
    });
}

/**
 * Cancellable when the status is confirmed or any held status. The five states that have
 * let the slot go are the five the server refuses a cancel from.
 */
export function cancellable(status: string): boolean {
    return status === 'confirmed' || (isBookingStatus(status) && HELD_STATUSES.includes(status));
}

/**
 * Reschedulable when the status is confirmed, or a held status whose expiry is non-null and in
 * the future, compared the way the server compares it: strictly after now, so a booking whose
 * hold lapses on the tick is not offered a move the write would refuse.
 *
 * The consequence to reproduce exactly: a pending booking whose hold has lapsed offers cancel
 * and not this; a live awaiting_approval booking offers both.
 */
export function reschedulable(status: string, holdExpiresAt: string | null, now: number): boolean {
    if (status === 'confirmed') {
        return true;
    }

    if (!isBookingStatus(status) || !HELD_STATUSES.includes(status) || holdExpiresAt === null) {
        return false;
    }

    return parseInstant(holdExpiresAt) > now;
}

/**
 * The longest one wait for a lapse runs before the guard is looked at again. A timeout is
 * measured on a clock that can stand still while a machine sleeps, and one longer than about
 * twenty-four days fires at once, so a far expiry is approached in steps rather than one jump.
 */
const LONGEST_WAIT_MS = 60_000;

/**
 * The reschedule guard of 6.3, kept true to the clock while it is on screen. Evaluated once per
 * render it would leave a control the server refuses standing until something else re-rendered,
 * so while the answer is yes and could turn to no, a held booking with a future expiry, one
 * timeout waits for the expiry and the render it causes asks again, strictly after now as the
 * server asks. Once nothing can lapse, a confirmed booking or a hold already gone, no timer
 * runs, and none survives the unmount.
 */
export function useReschedulable(status: string, holdExpiresAt: string | null, now: () => number): boolean {
    const [looks, setLooks] = useState(0);
    const may = reschedulable(status, holdExpiresAt, now());
    const lapsesAt = may && status !== 'confirmed' && holdExpiresAt !== null ? parseInstant(holdExpiresAt) : null;

    useEffect(() => {
        if (lapsesAt === null) {
            return undefined;
        }

        const wait = Math.min(Math.max(lapsesAt - now(), 0), LONGEST_WAIT_MS);
        const timer = setTimeout(() => setLooks(looks + 1), wait);

        return () => clearTimeout(timer);
    }, [lapsesAt, looks, now]);

    return may;
}
