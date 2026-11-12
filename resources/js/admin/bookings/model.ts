import type { Shape } from '@/lib/session-memory';
import type { ShellKey, ShellWords } from '@/lib/strings';
import type { BookingsKey, BookingsWords } from './words';

/*
 * The Bookings screen's data as the server sends it, BookingListController, and the filter bar's
 * rules, 6.1 and 6.2. A booking is what BookingPayload::admin makes of it for this viewer: a key
 * the viewer may not see is absent, never null.
 */

export type BookingItem = {
    sort: number;
    service_id: number;
    service_name: string;
    staff_id: number;
    staff_name: string | null;
    start: string;
    end: string;
    price_minor: number;
};

export type Booking = {
    uuid: string;
    status: string;
    /** Null once the customer is erased, the address and the phone with it. */
    customer_name: string | null;
    customer_email?: string | null;
    customer_phone?: string | null;
    total_minor: number;
    currency: string;
    rejection_reason?: string | null;
    /** The surface the booking came through, stamped by the server: hosted, embed or admin. */
    source?: string;
    /** The tag of the hosted-page link it came through, one of the fixed five, or null. */
    channel?: string | null;
    /** Whether this viewer may move the booking now, BookingActions::movable: on the calendar's bookings and the dialog's, absent from a list row. */
    movable?: boolean;
    items: BookingItem[];
};

export type TrailEntry = {
    action: string;
    actor: string;
    kind: string;
    name: string | null;
    at: string;
    facts: Record<string, unknown>;
};

/**
 * actions: what this viewer may do to the booking now, BookingActions on the server, in the order the bar shows them.
 * note: the customer's "Anything we should know?", read beside the booking and never inside it, null where they wrote none.
 */
export type Detail = { state: 'found'; booking: Booking; note: string | null; trail: TrailEntry[]; actions: string[] } | { state: 'missing'; uuid: string };

/**
 * The dialog's words for a booking's source, keys of the shell's section, which Reports reads
 * too; a value outside them shows verbatim.
 */
export const SOURCE_WORDS: Readonly<Record<string, ShellKey>> = {
    hosted: 'shared.sources.hosted',
    embed: 'shared.sources.embed',
    admin: 'shared.sources.admin',
};

/** The dialog's words for a link's channel, the fixed five, keys of the same; a value outside them shows verbatim. */
export const CHANNEL_WORDS: Readonly<Record<string, ShellKey>> = {
    instagram: 'shared.channels.instagram',
    facebook: 'shared.channels.facebook',
    google: 'shared.channels.google',
    qr: 'shared.channels.qr',
    other: 'shared.channels.other',
};

/** A value in a table of words, read in the shell's language, verbatim where the table holds none. */
export function inWords(words: Readonly<Record<string, ShellKey>>, value: string, shell: ShellWords): string {
    const key = Object.hasOwn(words, value) ? words[value] : undefined;

    return key === undefined ? value : shell.text(key);
}

export type Choice = { id: number; name: string; active: boolean };

/** searchable: the customer columns the list's search reads for this viewer, the server's whitelist decision. */
export type Options = { statuses: string[]; staff: Choice[]; services: Choice[]; members: number; searchable: string[] };

export type ListPage = { rows: Booking[]; total: number; page: number; page_size: number };

export type Filters = {
    from: string;
    to: string;
    status: string;
    staff: number | null;
    service: number | null;
    search: string;
};

export const NO_FILTERS: Filters = { from: '', to: '', status: '', staff: null, service: null, search: '' };

export const MEMORY_KEY = 'kr.admin.bookings.filters';

export const AWAITING_APPROVAL = 'awaiting_approval';

const STATUSES = ['pending', 'awaiting_approval', 'awaiting_payment', 'confirmed', 'cancelled', 'rejected', 'expired', 'completed', 'no_show'];

const isDay = (value: unknown): value is string => typeof value === 'string' && (value === '' || /^\d{4}-\d{2}-\d{2}$/.test(value));

const isId = (value: unknown): value is number | null => value === null || (typeof value === 'number' && Number.isInteger(value) && value > 0);

/** The bar's shape check: the page is not a field, so a saved page is never read back. */
export const FILTER_SHAPE: Shape<Filters> = {
    from: isDay,
    to: isDay,
    status: (value): value is string => value === '' || (typeof value === 'string' && STATUSES.includes(value)),
    staff: isId,
    service: isId,
    search: (value): value is string => typeof value === 'string',
};

/**
 * Whether the staff choice is real: the feature entitled, or more than one staff member, or the
 * control holding a value, which never hides.
 */
export function staffShown(entitlements: readonly string[], members: number, staff: number | null): boolean {
    return entitlements.includes('staff') || members > 1 || staff !== null;
}

/**
 * A remembered bar made applicable, 6.2: a staff value whose control would be hidden without it
 * is dropped rather than narrowing the list with nothing on screen able to show or clear it.
 */
export function applicable(remembered: Filters, entitlements: readonly string[], members: number): Filters {
    return staffShown(entitlements, members, null) ? remembered : { ...remembered, staff: null };
}

export function isUnfiltered(filters: Filters): boolean {
    return (Object.keys(NO_FILTERS) as (keyof Filters)[]).every((field) => filters[field] === NO_FILTERS[field]);
}

/** The server's parameters for a bar and a page, the empty fields and page one left out. */
export function queryOf(filters: Filters, page: number): Record<string, string> {
    const query: Record<string, string> = {};

    for (const [field, value] of Object.entries(filters)) {
        if (value !== '' && value !== null) {
            query[field] = String(value);
        }
    }

    if (page > 1) {
        query['page'] = String(page);
    }

    return query;
}

/**
 * What a booking whose customer was erased reads in place of the name: erasure nulls the name,
 * the address and the phone together, so a viewer of every role, the one shown no address among
 * them, reads the erasure off the name.
 */
export const CUSTOMER_ERASED: BookingsKey = 'customer_erased';

/** Who a booking is for on the list and in the dialog: the customer's name, or the erased reading in the bookings section's words. */
export function customerName(booking: Pick<Booking, 'customer_name'>, words: BookingsWords): string {
    return booking.customer_name ?? words.text(CUSTOMER_ERASED);
}

/** The booking's own start: its earliest item's. */
export function startOf(booking: Booking): string {
    return booking.items.reduce((earliest, item) => (Date.parse(item.start) < Date.parse(earliest) ? item.start : earliest), booking.items[0]?.start ?? '');
}

/** Each distinct value once, in item order. */
export function distinct(values: readonly (string | null)[]): string[] {
    return [...new Set(values.filter((value): value is string => value !== null))];
}

export function isVoid(status: string): boolean {
    return status === 'cancelled' || status === 'rejected';
}
