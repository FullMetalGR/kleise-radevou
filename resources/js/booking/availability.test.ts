import { hashKey } from '@tanstack/react-query';
import { describe, expect, it, vi } from 'vitest';
import type { Api } from './api';
import { AVAILABILITY_PATH, availabilityKey, availabilityQuery, availabilityUrl, chainReady, dayOf, dayShown, fetchAvailability, itemsOf, NEXT_AVAILABLE_KEY, parseAvailability, WINDOW_DAYS, windowIndexOf, windowOf } from './availability';
import type { Catalog } from './catalog';
import type { Fetch } from './config';

/*
 * The availability query over GET /availability: the key and the request both carry every
 * item's staff_id explicitly, null spelled as null; the window is seven tenant-local days
 * from the tenant's today, one week row; the answer is read key by key, in the order the
 * server sent them, and never has a date computed for it here; and the day shown is the
 * selected one while the answer carries it, else the first holding starts.
 */

const CATALOG: Catalog = {
    services: [
        { id: 1, name: 'Haircut', description: '', durationMin: 45, priceMinor: 3500, currency: 'EUR', category: null, staff: [{ id: 2, name: 'Elena', photoUrl: null, title: null, bio: null }, { id: 3, name: 'Nikos', photoUrl: null, title: null, bio: null }] },
        { id: 5, name: 'Head spa', description: '', durationMin: 30, priceMinor: 5000, currency: 'JPY', category: null, staff: [] },
    ],
    maxChainLength: 5,
};

const WIRE = {
    '2026-04-06': ['2026-04-06T07:35:00+00:00', '2026-04-06T07:40:00+00:00'],
    '2026-04-07': [],
    '2026-04-08': ['2026-04-08T06:00:00+00:00'],
};

function answering(body: unknown, status = 200): Fetch {
    return () => Promise.resolve(new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }));
}

function api(fetchFn: Fetch): Api {
    return { origin: 'https://luna.kleiseradevou.gr', fetch: fetchFn };
}

describe('itemsOf', () => {
    it('spells every item with service_id and an explicit staff_id, null for no preference', () => {
        const items = itemsOf([{ serviceId: 1, staffId: null }, { serviceId: 4, staffId: 3 }]);

        expect(items).toEqual([{ service_id: 1, staff_id: null }, { service_id: 4, staff_id: 3 }]);
        expect(JSON.stringify(items)).toBe('[{"service_id":1,"staff_id":null},{"service_id":4,"staff_id":3}]');
        expect(Object.keys(items[0] as object)).toEqual(['service_id', 'staff_id']);
    });
});

describe('chainReady', () => {
    it('is true only for a non-empty chain whose every service the catalog listed', () => {
        expect(chainReady([], CATALOG)).toBe(false);
        expect(chainReady([{ serviceId: 1, staffId: null }], undefined)).toBe(false);
        expect(chainReady([{ serviceId: 1, staffId: null }], CATALOG)).toBe(true);
        expect(chainReady([{ serviceId: 1, staffId: null }, { serviceId: 5, staffId: null }], CATALOG)).toBe(true);
        expect(chainReady([{ serviceId: 1, staffId: null }, { serviceId: 99, staffId: null }], CATALOG)).toBe(false);
    });
});

describe('windowOf', () => {
    it('is seven days from the tenant today, one week row, moving in whole weeks', () => {
        expect(WINDOW_DAYS).toBe(7);
        expect(windowOf('2026-04-06', 0)).toEqual({ from: '2026-04-06', to: '2026-04-13' });
        expect(windowOf('2026-04-06', 1)).toEqual({ from: '2026-04-13', to: '2026-04-20' });
        expect(windowOf('2026-12-25', 2)).toEqual({ from: '2027-01-08', to: '2027-01-15' });
    });
});

describe('windowIndexOf', () => {
    it('is the whole windows on from the tenant today to the one holding a day, and never before today', () => {
        expect(windowIndexOf('2026-04-06', '2026-04-06')).toBe(0);
        expect(windowIndexOf('2026-04-06', '2026-04-12')).toBe(0);
        expect(windowIndexOf('2026-04-06', '2026-04-13')).toBe(1);
        expect(windowIndexOf('2026-04-06', '2026-04-22')).toBe(2);
        expect(windowIndexOf('2026-12-25', '2027-01-08')).toBe(2);
        expect(windowIndexOf('2026-04-06', '2026-04-01')).toBe(0);
    });

    it('lands a day inside the window windowOf builds for its index', () => {
        for (const day of ['2026-04-06', '2026-04-12', '2026-04-13', '2026-04-19', '2026-04-20', '2026-06-05']) {
            const { from, to } = windowOf('2026-04-06', windowIndexOf('2026-04-06', day));

            expect(from <= day && day < to, day).toBe(true);
        }
    });
});

describe('the key and the request', () => {
    const items = itemsOf([{ serviceId: 1, staffId: null }]);

    it('keys on availability, the items, from and to, with the null spelled in the key', () => {
        const key = availabilityKey(items, '2026-04-06', '2026-04-13');

        expect(key).toEqual(['availability', [{ service_id: 1, staff_id: null }], '2026-04-06', '2026-04-13']);
        expect(hashKey(key)).toContain('"staff_id":null');
        expect(hashKey(key)).toBe(hashKey(availabilityKey(itemsOf([{ serviceId: 1, staffId: null }]), '2026-04-06', '2026-04-13')));
        expect(hashKey(key)).not.toBe(hashKey(availabilityKey(itemsOf([{ serviceId: 1, staffId: 2 }]), '2026-04-06', '2026-04-13')));
    });

    it('spells the request with the items as one JSON list and the null explicit', () => {
        const url = availabilityUrl(items, '2026-04-06', '2026-04-13');

        expect(url.startsWith(`${AVAILABILITY_PATH}?`)).toBe(true);

        const params = new URLSearchParams(url.slice(AVAILABILITY_PATH.length + 1));

        expect(params.get('items')).toBe('[{"service_id":1,"staff_id":null}]');
        expect(params.get('from')).toBe('2026-04-06');
        expect(params.get('to')).toBe('2026-04-13');
        expect(params.has('same_staff')).toBe(false);
    });

    it('is fresh for no time at all', () => {
        expect(availabilityQuery(api(answering(WIRE)), items, '2026-04-06', '2026-04-13').staleTime).toBe(0);
    });
});

describe('parseAvailability', () => {
    it('reads one day per key in the order sent, empty days as empty lists', () => {
        expect(parseAvailability(WIRE)).toEqual({
            days: [
                { date: '2026-04-06', starts: ['2026-04-06T07:35:00+00:00', '2026-04-06T07:40:00+00:00'] },
                { date: '2026-04-07', starts: [] },
                { date: '2026-04-08', starts: ['2026-04-08T06:00:00+00:00'] },
            ],
        });
    });

    it('accepts an answer with no day at all', () => {
        expect(parseAvailability({})).toEqual({ days: [] });
    });

    it('reads next_available_from beside an empty window\'s days, a date or null, and leaves it out where the answer carries none', () => {
        expect(NEXT_AVAILABLE_KEY).toBe('next_available_from');
        expect(parseAvailability({ '2026-04-12': [], next_available_from: '2026-04-13' })).toEqual({ days: [{ date: '2026-04-12', starts: [] }], nextAvailableFrom: '2026-04-13' });
        expect(parseAvailability({ '2026-04-12': [], next_available_from: null })).toEqual({ days: [{ date: '2026-04-12', starts: [] }], nextAvailableFrom: null });
        expect(parseAvailability(WIRE)?.nextAvailableFrom).toBeUndefined();
        expect(parseAvailability(WIRE)).not.toHaveProperty('nextAvailableFrom');
    });

    it.each([
        ['null', null],
        ['a list', [['2026-04-06', []]]],
        ['a key that is not a date', { 'Monday': [] }],
        ['a key in the instant form', { '2026-04-06T00:00:00+00:00': [] }],
        ['a day that is not a list', { '2026-04-06': { at: '2026-04-06T07:35:00+00:00' } }],
        ['a start that is not a string', { '2026-04-06': [1775461200] }],
        ['a start in the space-separated form', { '2026-04-06': ['2026-04-06 07:35:00+00:00'] }],
        ['a start the calendar does not hold', { '2026-04-06': ['2026-02-30T07:35:00+00:00'] }],
        ['a next day that is not a date', { '2026-04-06': [], next_available_from: 'Monday' }],
        ['a next day in the instant form', { '2026-04-06': [], next_available_from: '2026-04-13T00:00:00+00:00' }],
        ['a next day the calendar does not hold', { '2026-04-06': [], next_available_from: '2026-02-30' }],
        ['a next day that is a number', { '2026-04-06': [], next_available_from: 20260413 }],
    ])('refuses %s', (_name, wire) => {
        expect(parseAvailability(wire)).toBeNull();
    });
});

describe('dayOf', () => {
    it('finds a day by its key and answers undefined for one the window does not carry', () => {
        const availability = parseAvailability(WIRE) ?? undefined;

        expect(dayOf(availability, '2026-04-07')).toEqual({ date: '2026-04-07', starts: [] });
        expect(dayOf(availability, '2026-04-09')).toBeUndefined();
        expect(dayOf(undefined, '2026-04-07')).toBeUndefined();
    });
});

describe('dayShown', () => {
    const availability = parseAvailability({ '2026-04-06': [], '2026-04-07': [], '2026-04-08': ['2026-04-08T06:00:00+00:00'], '2026-04-09': ['2026-04-09T06:00:00+00:00'] }) ?? undefined;

    it('is the selected day while the answer carries it, holding starts or none', () => {
        expect(dayShown(availability, '2026-04-09')?.date).toBe('2026-04-09');
        expect(dayShown(availability, '2026-04-07')).toEqual({ date: '2026-04-07', starts: [] });
    });

    it('is the first day holding starts when nothing is selected or the selected day is outside the answer', () => {
        expect(dayShown(availability, null)?.date).toBe('2026-04-08');
        expect(dayShown(availability, '2026-04-20')?.date).toBe('2026-04-08');
    });

    it('is nothing for a window whose every day is empty, for an answer with no day, and without an answer', () => {
        expect(dayShown(parseAvailability({ '2026-04-06': [], '2026-04-07': [] }) ?? undefined, null)).toBeUndefined();
        expect(dayShown(parseAvailability({ '2026-04-06': [], '2026-04-07': [] }) ?? undefined, '2026-04-20')).toBeUndefined();
        expect(dayShown({ days: [] }, null)).toBeUndefined();
        expect(dayShown(undefined, '2026-04-08')).toBeUndefined();
    });
});

describe('fetchAvailability', () => {
    const items = itemsOf([{ serviceId: 1, staffId: null }]);

    it('GETs the request on the API origin, asking for JSON and sending no credentials', async () => {
        const fetchFn = vi.fn(answering(WIRE));

        await expect(fetchAvailability(api(fetchFn), items, '2026-04-06', '2026-04-13')).resolves.toEqual(parseAvailability(WIRE));

        expect(fetchFn).toHaveBeenCalledTimes(1);
        expect(fetchFn).toHaveBeenCalledWith(`https://luna.kleiseradevou.gr${availabilityUrl(items, '2026-04-06', '2026-04-13')}`, { headers: { Accept: 'application/json' }, credentials: 'omit' });
    });

    it('throws when the answer is not availability', async () => {
        await expect(fetchAvailability(api(answering({ '2026-04-06': ['2026-04-06 07:35:00+00:00'] })), items, '2026-04-06', '2026-04-13')).rejects.toThrow(AVAILABILITY_PATH);
    });
});
