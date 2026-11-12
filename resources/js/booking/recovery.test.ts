import { describe, expect, it, vi } from 'vitest';
import type { Api } from './api';
import type { Fetch } from './config';
import { bookingPath } from './manage';
import { reread, SETTLED_STATUSES } from './recovery';

/*
 * The re-read over GET /bookings/{uuid}: the credentials in the query string, sent once and
 * never retried, a settled booking answered and anything else, a failure included, answered as
 * null so the original refusal stands.
 */

const UUID = '7b7f0e5a-2222-4000-8000-00000000000a';
const TOKEN = 'f'.repeat(64);

const GUEST_VIEW = {
    uuid: UUID,
    status: 'confirmed',
    hold_class: null,
    hold_expires_at: null,
    customer_name: 'Casey Customer',
    customer_email: 'casey@example.com',
    customer_phone: null,
    total_minor: 4200,
    currency: 'EUR',
    items: [{ sort: 0, service_id: 5, service_name: 'Head spa', staff_id: 9, staff_name: 'Aiko', start: '2026-04-06T07:40:00+00:00', end: '2026-04-06T08:10:00+00:00', price_minor: 4200 }],
};

function api(fetchFn: Fetch): Api {
    return { origin: 'https://luna.kleiseradevou.gr', fetch: fetchFn };
}

function answering(body: unknown, status = 200): Fetch {
    return vi.fn<Fetch>(() => Promise.resolve(new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })));
}

describe('the re-read', () => {
    it('reads the one booking once with the token in the query string, and answers it when it is settled', async () => {
        for (const status of SETTLED_STATUSES) {
            const fetchFn = answering({ ...GUEST_VIEW, status });
            const settled = await reread(api(fetchFn), UUID, TOKEN);

            expect(settled?.status).toBe(status);
            expect(fetchFn).toHaveBeenCalledTimes(1);
            expect(vi.mocked(fetchFn).mock.calls[0]?.[0]).toBe(`https://luna.kleiseradevou.gr${bookingPath(UUID, TOKEN)}`);
            expect(vi.mocked(fetchFn).mock.calls[0]?.[1].method ?? 'GET').toBe('GET');
        }
    });

    it('counts exactly confirmed, awaiting_approval and awaiting_payment as settled', () => {
        expect([...SETTLED_STATUSES].sort()).toEqual(['awaiting_approval', 'awaiting_payment', 'confirmed']);
    });

    it('answers null for a booking that reads unsettled', async () => {
        for (const status of ['pending', 'cancelled', 'expired', 'rejected']) {
            expect(await reread(api(answering({ ...GUEST_VIEW, status })), UUID, TOKEN)).toBeNull();
        }
    });

    it('swallows a refused, a broken and a dropped read, each sent once, answering null', async () => {
        const refused = answering({ code: 'not_found', message: 'not_found', data: { status: 404, detail: 'Not found.' } }, 404);
        const broken = answering({ code: 'server_error', message: 'server_error', data: { status: 500, detail: 'Oops.' } }, 500);
        const dropped = vi.fn<Fetch>(() => Promise.reject(new TypeError('Failed to fetch')));
        const garbled = answering({ nothing: 'here' });

        for (const fetchFn of [refused, broken, dropped, garbled]) {
            expect(await reread(api(fetchFn), UUID, TOKEN)).toBeNull();
            expect(fetchFn).toHaveBeenCalledTimes(1);
        }
    });
});
