import { queryOptions } from '@tanstack/react-query';
import { getJson, type Api } from './api';
import { serviceById, type Catalog } from './catalog';
import type { Segment } from './flow';
import { addDays, daysBetween, parseDay, parseInstant } from './time';

/*
 * The availability query, over GET /availability. The query describes the whole chain: every
 * item carries its staff_id explicitly, null spelled as null for "any", never as an absent
 * key, in the cache key and in the request alike, since both stringify and mixing the two
 * forms splits one server answer across two cache entries and two request URLs. Fresh for no
 * time at all: availability is advisory, and a stale offer costs a 409 round trip, so every
 * fresh subscriber, refocus and reconnect refetches.
 *
 * The window is seven tenant-local days, the first beginning on the tenant's today, and the
 * week row is built from the keys the answer carries and the grid from one key's list: the
 * client never computes a date itself, so the row and the window are one number by
 * construction.
 *
 * An empty window's answer carries one key more, next_available_from: the first later date
 * holding a start, or null. It is read beside the days, never as one, and the window holding
 * it is a whole number of windows on from the tenant's today, the index the Next available
 * date button moves to.
 */

export const AVAILABILITY_PATH = '/api/v1/availability';

/**
 * The first element of every availability key, which is also the whole key a mutation
 * invalidates with: one prefix, so a hold's 409 reaches every window of every chain.
 */
export const AVAILABILITY_KEY = 'availability';

export const WINDOW_DAYS = 7;

/** The key an empty window's answer names its next day under, beside the days. */
export const NEXT_AVAILABLE_KEY = 'next_available_from';

/**
 * One item of the query as the wire spells it. The key order is the wire order.
 */
export interface AvailabilityItem {
    readonly service_id: number;
    readonly staff_id: number | null;
}

export interface AvailabilityDay {
    /** The tenant-local calendar date the server keyed the day by. */
    readonly date: string;
    /** The starts in order, RFC 3339 instants in UTC, kept as the wire spelled them. */
    readonly starts: readonly string[];
}

export interface Availability {
    readonly days: readonly AvailabilityDay[];
    /**
     * The first later tenant-local date holding a start, or null for none, where every day of
     * the window is empty; absent where the answer carries no such key.
     */
    readonly nextAvailableFrom?: string | null;
}

export function itemsOf(segments: readonly Segment[]): AvailabilityItem[] {
    return segments.map((segment) => ({ service_id: segment.serviceId, staff_id: segment.staffId }));
}

/**
 * Whether the query may run: the chain is non-empty and every item names a service the
 * catalog answered.
 */
export function chainReady(segments: readonly Segment[], catalog: Catalog | undefined): boolean {
    return segments.length > 0 && catalog !== undefined && segments.every((segment) => serviceById(catalog, segment.serviceId) !== undefined);
}

/**
 * The window some whole windows on from the tenant's today, to exclusive.
 */
export function windowOf(today: string, index: number): { readonly from: string; readonly to: string } {
    const from = addDays(today, index * WINDOW_DAYS);

    return { from, to: addDays(from, WINDOW_DAYS) };
}

/**
 * The index of the window holding a day, in whole windows on from the tenant's today; never
 * before today.
 */
export function windowIndexOf(today: string, day: string): number {
    return Math.max(0, Math.floor(daysBetween(today, day) / WINDOW_DAYS));
}

export function availabilityKey(items: readonly AvailabilityItem[], from: string, to: string) {
    return [AVAILABILITY_KEY, items, from, to] as const;
}

export function availabilityUrl(items: readonly AvailabilityItem[], from: string, to: string): string {
    const params = new URLSearchParams({ items: JSON.stringify(items), from, to });

    return `${AVAILABILITY_PATH}?${params.toString()}`;
}

export function availabilityQuery(api: Api, items: readonly AvailabilityItem[], from: string, to: string) {
    return queryOptions({
        queryKey: availabilityKey(items, from, to),
        queryFn: () => fetchAvailability(api, items, from, to),
        staleTime: 0,
    });
}

export function fetchAvailability(api: Api, items: readonly AvailabilityItem[], from: string, to: string): Promise<Availability> {
    return fetchAvailabilityAt(api, availabilityUrl(items, from, to));
}

/**
 * One availability read at a URL, parsed: the public read and the reschedule dialog's
 * per-booking read answer the same shape, so they are parsed by one function.
 */
export async function fetchAvailabilityAt(api: Api, url: string): Promise<Availability> {
    const availability = parseAvailability(await getJson(api, url));

    if (availability === null) {
        throw new Error(`GET ${url.split('?')[0] ?? url} answered something other than availability.`);
    }

    return availability;
}

/**
 * The answer key by key, in the order sent: every key a calendar date, every value a list
 * of instants the calendar holds, and next_available_from, where it is sent, a calendar date
 * or null. Anything else is not availability.
 */
export function parseAvailability(value: unknown): Availability | null {
    if (typeof value !== 'object' || value === null || Array.isArray(value)) {
        return null;
    }

    const days: AvailabilityDay[] = [];
    let next: string | null | undefined;

    for (const [date, starts] of Object.entries(value)) {
        if (date === NEXT_AVAILABLE_KEY) {
            if (starts !== null && (typeof starts !== 'string' || !isDay(starts))) {
                return null;
            }

            next = starts;
        } else if (!Array.isArray(starts) || !isDay(date) || !starts.every(isInstant)) {
            return null;
        } else {
            days.push({ date, starts: starts as string[] });
        }
    }

    return next === undefined ? { days } : { days, nextAvailableFrom: next };
}

function isDay(value: string): boolean {
    try {
        parseDay(value);

        return true;
    } catch {
        return false;
    }
}

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

/**
 * The day the answer carries under a key, or undefined when the window does not carry it.
 */
export function dayOf(availability: Availability | undefined, date: string): AvailabilityDay | undefined {
    return availability?.days.find((day) => day.date === date);
}

/**
 * The day the list shows, 2.3: the selected day while the answer carries it, whether or not it
 * holds starts, else the first day holding starts, else undefined, which is an answer whose
 * every day is empty, or no answer at all.
 */
export function dayShown(availability: Availability | undefined, selectedDay: string | null): AvailabilityDay | undefined {
    const selected = selectedDay === null ? undefined : dayOf(availability, selectedDay);

    return selected ?? availability?.days.find((day) => day.starts.length > 0);
}
