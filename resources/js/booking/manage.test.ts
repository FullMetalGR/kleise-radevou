import { describe, expect, it, vi } from 'vitest';
import type { Api } from './api';
import { BOOKING_STATUSES } from './booking';
import { bookingKey, bookingPath, bookingQuery, cancellable, fetchBooking, INLINED_NOTICE_ID, inlinedNotice, reschedulable } from './manage';
import { cancelPath, postCancel } from './cancel';

/*
 * Manage mode's read and its two guards, and the cancel's request of 6.4. What a rendered
 * screen does with any of it is ManageView's own test.
 */

const UUID = '7b7f0e5a-3333-4000-8000-00000000000b';

const TOKEN = 'f'.repeat(64);

const WIRE = {
    uuid: UUID,
    status: 'confirmed',
    hold_class: null,
    hold_expires_at: null,
    customer_name: 'Casey Customer',
    customer_email: 'casey@example.com',
    customer_phone: null,
    total_minor: 3500,
    currency: 'EUR',
    items: [{ sort: 0, service_id: 1, service_name: 'Haircut', staff_id: 7, staff_name: 'Elena', start: '2026-04-06T07:00:00+00:00', end: '2026-04-06T07:45:00+00:00', price_minor: 3500 }],
};

function answering(body: unknown, status = 200): Api {
    return {
        origin: 'https://luna.kleiseradevou.gr',
        fetch: () => Promise.resolve(new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })),
    };
}

it('keys the booking on the word and the identifier, and never on the credential', () => {
    expect(bookingKey(UUID)).toEqual(['booking', UUID]);
    expect(JSON.stringify(bookingKey(UUID))).not.toContain(TOKEN);
});

it('reads the booking with the token in the query string and the identifier in the path', () => {
    expect(bookingPath(UUID, TOKEN)).toBe(`/api/v1/bookings/${UUID}?token=${TOKEN}`);
});

it('escapes both halves, so neither can become a second path segment or a second parameter', () => {
    expect(bookingPath('a/b', 'x&y=z')).toBe('/api/v1/bookings/a%2Fb?token=x%26y%3Dz');
});

it('sends one GET carrying the token and parses the booking off the answer', async () => {
    const api = answering(WIRE);
    const fetch = vi.spyOn(api, 'fetch');

    const booking = await fetchBooking(api, UUID, TOKEN);

    expect(fetch).toHaveBeenCalledTimes(1);
    expect(fetch.mock.calls[0]?.[0]).toBe(`https://luna.kleiseradevou.gr/api/v1/bookings/${UUID}?token=${TOKEN}`);
    expect(booking.uuid).toBe(UUID);
    expect(booking.items).toHaveLength(1);
});

it('raises on an answer that is not a booking, rather than rendering half of one', async () => {
    await expect(fetchBooking(answering({ uuid: UUID }), UUID, TOKEN)).rejects.toThrow('answered something other than a booking');
});

describe('the query', () => {
    it('runs only with both credentials, and is keyed on the booking whatever the credential', () => {
        const api = answering(WIRE);

        expect(bookingQuery(api, UUID, TOKEN).enabled).toBe(true);
        expect(bookingQuery(api, UUID, null).enabled).toBe(false);
        expect(bookingQuery(api, null, TOKEN).enabled).toBe(false);
        expect(bookingQuery(api, null, null).enabled).toBe(false);
        expect(bookingQuery(api, UUID, 'another-token').queryKey).toEqual(bookingKey(UUID));
    });

    it('holds no freshness at all, because a booking changes without the customer acting', () => {
        expect(bookingQuery(answering(WIRE), UUID, TOKEN).staleTime).toBe(0);
    });
});

describe('cancellable', () => {
    it.each(['confirmed', 'pending', 'awaiting_approval', 'awaiting_payment'])('offers a cancel on %s', (status) => {
        expect(cancellable(status)).toBe(true);
    });

    it.each(['completed', 'no_show', 'cancelled', 'rejected', 'expired'])('offers no cancel on %s', (status) => {
        expect(cancellable(status)).toBe(false);
    });

    it('answers for every status the wire can carry and refuses one it cannot', () => {
        expect(BOOKING_STATUSES.filter(cancellable)).toEqual(['pending', 'awaiting_approval', 'awaiting_payment', 'confirmed']);
        expect(cancellable('confirmed_by_somebody')).toBe(false);
    });
});

describe('reschedulable', () => {
    const NOW = Date.UTC(2026, 3, 6, 7, 0);
    const LATER = '2026-04-06T07:15:00+00:00';
    const EARLIER = '2026-04-06T06:45:00+00:00';
    const ON_THE_TICK = '2026-04-06T07:00:00+00:00';

    it('is true for confirmed whatever the expiry says', () => {
        expect(reschedulable('confirmed', null, NOW)).toBe(true);
        expect(reschedulable('confirmed', EARLIER, NOW)).toBe(true);
    });

    it.each(['pending', 'awaiting_approval', 'awaiting_payment'])('is true for %s while its hold is live', (status) => {
        expect(reschedulable(status, LATER, NOW)).toBe(true);
    });

    it.each(['pending', 'awaiting_approval', 'awaiting_payment'])('is false for %s once its hold has lapsed, cancel or no cancel', (status) => {
        expect(reschedulable(status, EARLIER, NOW)).toBe(false);
        expect(cancellable(status)).toBe(true);
    });

    it('compares the expiry the way the server does, strictly after now', () => {
        expect(reschedulable('pending', ON_THE_TICK, NOW)).toBe(false);
    });

    it('is false for a held status carrying no expiry at all', () => {
        expect(reschedulable('pending', null, NOW)).toBe(false);
    });

    it.each(['completed', 'no_show', 'cancelled', 'rejected', 'expired'])('is false for %s', (status) => {
        expect(reschedulable(status, LATER, NOW)).toBe(false);
    });
});

describe('the cancel request', () => {
    it('names the booking in the path and puts the token in the body and nowhere else', async () => {
        const api = answering(WIRE);
        const fetch = vi.spyOn(api, 'fetch');

        await postCancel(api, UUID, TOKEN);

        const [url, init] = fetch.mock.calls[0] ?? [];

        expect(cancelPath(UUID)).toBe(`/api/v1/bookings/${UUID}/cancel`);
        expect(url).toBe(`https://luna.kleiseradevou.gr/api/v1/bookings/${UUID}/cancel`);
        expect(url).not.toContain(TOKEN);
        expect(init?.method).toBe('POST');
        expect(JSON.parse(String(init?.body))).toEqual({ token: TOKEN });
    });

    it('raises the refusal rather than swallowing it, so the view can render the sentence', async () => {
        const refused = answering({ code: 'window_closed', message: 'window_closed', data: { status: 403, detail: 'It is too late to cancel online.' } }, 403);

        await expect(postCancel(refused, UUID, TOKEN)).rejects.toMatchObject({ status: 403, detail: 'It is too late to cancel online.' });
    });
});

describe('inlinedNotice', () => {
    it('reads the sentence a redirect flashed from the manage page\'s JSON script by its id', () => {
        document.body.innerHTML = `<script type="application/json" id="${INLINED_NOTICE_ID}">${JSON.stringify({ notice: 'Your payment is on its way to us.' })}</script>`;

        expect(inlinedNotice(document)).toBe('Your payment is on its way to us.');
    });

    it('reads nothing where the page carries no such script, an element that is no JSON script, malformed JSON, or anything but a sentence', () => {
        const pages = [
            '',
            `<div id="${INLINED_NOTICE_ID}">${JSON.stringify({ notice: 'A sentence.' })}</div>`,
            `<script type="text/plain" id="${INLINED_NOTICE_ID}">${JSON.stringify({ notice: 'A sentence.' })}</script>`,
            `<script type="application/json" id="${INLINED_NOTICE_ID}">not json</script>`,
            `<script type="application/json" id="${INLINED_NOTICE_ID}">${JSON.stringify({ notice: 3 })}</script>`,
            `<script type="application/json" id="${INLINED_NOTICE_ID}">${JSON.stringify({ notice: '' })}</script>`,
            `<script type="application/json" id="${INLINED_NOTICE_ID}">${JSON.stringify('A sentence.')}</script>`,
        ];

        for (const page of pages) {
            document.body.innerHTML = page;

            expect(inlinedNotice(document)).toBeNull();
        }
    });
});
