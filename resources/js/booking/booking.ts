import { parseInstant } from './time';

/*
 * The booking as this API spells it: one shape and not two. A hold answers it with the manage
 * token beside it and a confirm answers it alone, so the parse lives here and each of those
 * reads it rather than describing the same ten keys again.
 *
 * Every number the review and done steps show comes from here and never from the app's own
 * selections: the server re-validated and re-priced the chain under lock, and echoing local
 * state could show a price the engine never agreed to.
 *
 * checkout_url is absent rather than null on the wire, because the app branches on its
 * presence and a null would be a third state to reason about, so the parsed booking carries
 * the key only where the answer did. pays_at_venue is the hold's other side: true, and present
 * only then, where the booking is paid for online but nothing can take the money, so the done
 * step tells the customer they pay at the venue.
 */

/**
 * The nine statuses of the machine, as the bookings.status column spells them. A parsed
 * booking keeps its status as a plain string on purpose: a tenth status must make a booking
 * render, not make it unreadable. Code that has to answer for every status narrows through
 * this list instead, so adding one here is a compile error in each of those places and a
 * silent blank in none of them.
 */
export const BOOKING_STATUSES = [
    'pending',
    'awaiting_approval',
    'awaiting_payment',
    'confirmed',
    'completed',
    'no_show',
    'cancelled',
    'rejected',
    'expired',
] as const;

export type BookingStatus = (typeof BOOKING_STATUSES)[number];

export function isBookingStatus(value: string): value is BookingStatus {
    return (BOOKING_STATUSES as readonly string[]).includes(value);
}

/**
 * The three statuses in which a booking is a hold: it occupies its slot on a TTL and the clock
 * frees it when the expiry passes. The same three the server's BookingStatus::held() answers,
 * because the affordances mirror the guards that would refuse the write.
 */
export const HELD_STATUSES: readonly BookingStatus[] = ['pending', 'awaiting_approval', 'awaiting_payment'];

export interface BookingItem {
    readonly sort: number;
    readonly serviceId: number;
    readonly serviceName: string;
    readonly staffId: number;
    /** Null where the service runs on the tenant's implicit member, who is the business. */
    readonly staffName: string | null;
    readonly start: string;
    readonly end: string;
    readonly priceMinor: number;
}

export interface Booking {
    readonly uuid: string;
    readonly status: string;
    readonly holdClass: string | null;
    readonly holdExpiresAt: string | null;
    readonly customerName: string;
    readonly customerEmail: string;
    readonly customerPhone: string | null;
    readonly totalMinor: number;
    readonly currency: string;
    readonly items: readonly BookingItem[];
    /** Present only on a hold that cannot be confirmed until it is paid. */
    readonly checkoutUrl?: string;
    /** Present, and true, only on an online hold that is paid at the venue. */
    readonly paysAtVenue?: true;
}

/**
 * The answer key by key: the booking as a guest is shown it, with at least one item. Anything
 * else is not a booking, which the caller raises rather than rendering a half-read one.
 */
export function parseBooking(value: unknown): Booking | null {
    const record = asRecord(value);
    const rows = record?.['items'];

    if (record === null || !Array.isArray(rows) || rows.length === 0) {
        return null;
    }

    const { uuid, status, hold_class: holdClass, hold_expires_at: holdExpiresAt } = record;
    const { customer_name: name, customer_email: email, customer_phone: phone } = record;
    const { total_minor: totalMinor, currency, checkout_url: checkoutUrl, pays_at_venue: atVenue } = record;

    if (typeof uuid !== 'string' || typeof status !== 'string' || !isText(holdClass) || !isInstantOrNull(holdExpiresAt)) {
        return null;
    }

    if (typeof name !== 'string' || typeof email !== 'string' || !isText(phone) || !Number.isInteger(totalMinor) || typeof currency !== 'string') {
        return null;
    }

    const items = rows.map(parseItem);

    if (items.some((item) => item === null)) {
        return null;
    }

    return {
        uuid,
        status,
        holdClass,
        holdExpiresAt,
        customerName: name,
        customerEmail: email,
        customerPhone: phone,
        totalMinor: totalMinor as number,
        currency,
        items: items as BookingItem[],
        ...(typeof checkoutUrl === 'string' && checkoutUrl !== '' ? { checkoutUrl } : {}),
        ...(atVenue === true ? { paysAtVenue: atVenue } : {}),
    };
}

function parseItem(value: unknown): BookingItem | null {
    const record = asRecord(value);

    if (record === null || !hasWholeNumbers(record) || !hasNames(record) || !hasRange(record)) {
        return null;
    }

    return {
        sort: record['sort'] as number,
        serviceId: record['service_id'] as number,
        serviceName: record['service_name'] as string,
        staffId: record['staff_id'] as number,
        staffName: record['staff_name'] as string | null,
        start: record['start'] as string,
        end: record['end'] as string,
        priceMinor: record['price_minor'] as number,
    };
}

/**
 * The four counts an item carries: where it sits in the chain, what it is, who performs it,
 * and what it cost, every one of them a whole number.
 */
function hasWholeNumbers(record: Record<string, unknown>): boolean {
    return ['sort', 'service_id', 'staff_id', 'price_minor'].every((key) => Number.isInteger(record[key]));
}

/**
 * The service always names itself; the staff member names themselves only where they are a
 * person the customer could have picked.
 */
function hasNames(record: Record<string, unknown>): boolean {
    return typeof record['service_name'] === 'string' && isText(record['staff_name']);
}

function hasRange(record: Record<string, unknown>): boolean {
    return isInstant(record['start']) && isInstant(record['end']);
}

export function asRecord(value: unknown): Record<string, unknown> | null {
    return typeof value === 'object' && value !== null && !Array.isArray(value) ? (value as Record<string, unknown>) : null;
}

function isText(value: unknown): value is string | null {
    return value === null || typeof value === 'string';
}

/**
 * A wire instant is one this app can parse into a real moment. The bare Date constructor is
 * never used on a wire value (7.1), so an unparseable one is caught here rather than guessed.
 */
function isInstant(value: unknown): boolean {
    if (typeof value !== 'string') {
        return false;
    }

    try {
        parseInstant(value);

        return true;
    } catch {
        return false;
    }
}

function isInstantOrNull(value: unknown): value is string | null {
    return value === null || isInstant(value);
}
