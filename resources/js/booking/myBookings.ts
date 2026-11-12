import { queryOptions } from '@tanstack/react-query';
import { getJson, type Api } from './api';
import { asRecord, parseBooking, type Booking } from './booking';
import { pathUnder, type Language } from './language';
import type { LineKey } from './words';

/*
 * My bookings: the list the hosted page inlines beside the config on GET /my-bookings, in a
 * second JSON script. The session's bookings in the guest view's shape, upcoming and past, or
 * null where no session is open and the app renders the form; the sentence a redirect flashed;
 * and the form's cross-site token, which the two forms post back.
 *
 * Neither the link's token nor the session's secret is anywhere in it, and this code never holds
 * either: the link's token is spent by the server on the way to the page, and the session's
 * secret rides an HttpOnly cookie no script reads. So nothing here can render, store or log one
 * (5.5's rules for the token).
 *
 * The list is held in a query seeded from that script and read again only when a cancel or a
 * move from a card invalidates it: GET /my-bookings asked for JSON answers the same shape behind
 * the same session, the one JSON read the list mode makes. Never stale by time, so the page's
 * own answer stands until an action says otherwise.
 */

export const INLINED_LIST_ID = 'kr-my-bookings';

/** The page, and the JSON read of it the list makes after an action, on the hosted origin. */
export const MY_BOOKINGS_PATH = '/my-bookings';

/** The list's cache key, which a landed cancel or move invalidates. */
export const MY_BOOKINGS_KEY = ['my-bookings'] as const;

/** Where the form asks for a link, and where the sign-out goes, on the hosted origin. */
export const LINK_PATH = '/my-bookings/link';

export const SIGN_OUT_PATH = '/my-bookings/sign-out';

/** The field the server reads the form's cross-site token from. */
export const TOKEN_FIELD = '_token';

export interface ListedBookings {
    readonly upcoming: readonly Booking[];
    readonly past: readonly Booking[];
}

export interface MyBookingsPage {
    /** The session's bookings, or null where no session is open. */
    readonly bookings: ListedBookings | null;
    /** The sentence a redirect flashed, or the limiter's refusal. */
    readonly notice: string | null;
    /** The form's cross-site token. */
    readonly csrf: string;
}

/**
 * The page's list key by key, every booking a guest view, or null for anything else.
 */
export function parseMyBookings(value: unknown): MyBookingsPage | null {
    const record = asRecord(value);

    if (record === null) {
        return null;
    }

    const { bookings, notice, csrf } = record;

    if (typeof csrf !== 'string' || (notice !== null && typeof notice !== 'string')) {
        return null;
    }

    if (bookings === null) {
        return { bookings: null, notice, csrf };
    }

    const listed = asRecord(bookings);
    const upcoming = parseList(listed?.['upcoming']);
    const past = parseList(listed?.['past']);

    return upcoming === null || past === null ? null : { bookings: { upcoming, past }, notice, csrf };
}

function parseList(value: unknown): Booking[] | null {
    if (!Array.isArray(value)) {
        return null;
    }

    const parsed = value.map(parseBooking);

    return parsed.every((booking) => booking !== null) ? (parsed as Booking[]) : null;
}

/**
 * The list the page inlined, or null where the document carries none that parses. The boot asks
 * only where the page is on the script's own origin, as it asks for the inlined config.
 */
export function inlinedMyBookings(doc: Document): MyBookingsPage | null {
    const el = doc.getElementById(INLINED_LIST_ID);

    if (el === null || el.tagName !== 'SCRIPT' || el.getAttribute('type') !== 'application/json') {
        return null;
    }

    try {
        return parseMyBookings(JSON.parse(el.textContent ?? ''));
    } catch {
        return null;
    }
}

/**
 * The list as a query: the page's inlined answer first, and GET /my-bookings as JSON each time an
 * action invalidates it, under the mount's credentials, which include the cookie in list mode.
 */
export function myBookingsQuery(api: Api, page: MyBookingsPage) {
    return queryOptions({
        queryKey: MY_BOOKINGS_KEY,
        queryFn: async (): Promise<MyBookingsPage> => {
            const read = parseMyBookings(await getJson(api, MY_BOOKINGS_PATH));

            if (read === null) {
                throw new Error(`GET ${MY_BOOKINGS_PATH} answered something other than the list.`);
            }

            return read;
        },
        initialData: page,
        staleTime: Infinity,
    });
}

/**
 * The key of Book again's words, on a card of My bookings (6.6) and on the manage page's ready view
 * (6.2), kept beside the link so the two surfaces cannot come to call it two things.
 */
export const BOOK_AGAIN_LABEL: LineKey = 'booking.book_again';

/**
 * The hosted page's two query keys, the service and the staff member a link preselects: the
 * client's one spelling of them, as LinkKind's two cases are the server's
 * (app/Http/LinkKind.php), and SharedHomesTest holds the two homes to the same values.
 */
export const SERVICE_QUERY = 'service';

export const STAFF_QUERY = 'staff';

/**
 * Book again: the hosted page with the booking's first item preselected, its service and its staff
 * member, the staff left out where the item runs on the implicit member, whom the guest view names
 * as nobody. The same link the server spells for the admin and for the confirmation, the reminder
 * and the thank-you (BookAgainLine, on the two query keys LinkKind spells, here SERVICE_QUERY and
 * STAFF_QUERY), built on the origin the app talks to, which is the tenant's own: the hosted
 * page's, and the embed's script origin, so a manage page mounted on the tenant's own site still
 * opens the tenant's hosted page. On the hosted page it keeps the prefix the page was opened
 * under, so a customer reading in one language books again in it; bare for none, and always in the
 * embed.
 */
export function bookAgainLink(origin: string, booking: Booking, prefix: Language | null = null): string {
    const first = booking.items[0];
    const query = new URLSearchParams();

    if (first !== undefined) {
        query.set(SERVICE_QUERY, String(first.serviceId));

        if (first.staffName !== null) {
            query.set(STAFF_QUERY, String(first.staffId));
        }
    }

    const spelled = query.toString();

    return `${origin}${pathUnder('/', prefix)}${spelled === '' ? '' : `?${spelled}`}`;
}
