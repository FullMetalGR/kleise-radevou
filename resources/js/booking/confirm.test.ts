import { describe, expect, it, vi } from 'vitest';
import { ApiError, TRANSPORT_SENTENCE, type Api } from './api';
import { confirmPath, postConfirm, refusalOf } from './confirm';
import type { Fetch } from './config';

/*
 * The confirm on the wire, over POST /bookings/{uuid}/confirm: the token travels in the JSON
 * body and nowhere else, the answer is the guest view of the booking the transition left,
 * and every refusal is the server's sentence, which the classifier turns into a notice with
 * a politeness and nothing else.
 *
 * The one status this app reads rather than renders is 410 (4.2): the server saying the hold
 * ran out is what keeps the button disabled whatever a skewed browser clock thinks.
 */

const UUID = '7b7f0e5a-1111-4000-8000-000000000001';

const TOKEN = 'a'.repeat(64);

const ANSWER = {
    uuid: UUID,
    status: 'confirmed',
    hold_class: null,
    hold_expires_at: null,
    customer_name: 'Casey Customer',
    customer_email: 'casey@example.com',
    customer_phone: null,
    total_minor: 3500,
    currency: 'EUR',
    items: [
        { sort: 0, service_id: 1, service_name: 'Haircut', staff_id: 7, staff_name: 'Elena', start: '2026-11-03T07:00:00+00:00', end: '2026-11-03T07:45:00+00:00', price_minor: 3500 },
    ],
};

function answering(body: unknown, status = 200): Fetch {
    return () => Promise.resolve(new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }));
}

function apiOf(fetchFn: Fetch): Api {
    return { origin: 'https://luna.kleiseradevou.gr', fetch: fetchFn };
}

function refusal(code: string, status: number, detail: string): unknown {
    return { code, message: code, data: { status, detail } };
}

describe('the request', () => {
    it('posts the token as the whole body, to the path of that booking, with credentials omitted', async () => {
        const fetchFn = vi.fn<Fetch>(answering(ANSWER));

        await postConfirm(apiOf(fetchFn), UUID, TOKEN);

        const [url, init] = fetchFn.mock.calls[0] as [string, RequestInit];

        expect(url).toBe('https://luna.kleiseradevou.gr' + confirmPath(UUID));
        expect(url).not.toContain(TOKEN);
        expect(init.method).toBe('POST');
        expect(init.credentials).toBe('omit');
        expect(init.body).toBe(JSON.stringify({ token: TOKEN }));
        expect(new Headers(init.headers).get('Content-Type')).toBe('application/json');
    });

    it('names the booking in the path, escaped, so nothing a mount handed the app can shape a second path segment', () => {
        expect(confirmPath(UUID)).toBe(`/api/v1/bookings/${UUID}/confirm`);
        expect(confirmPath('../holds')).toBe('/api/v1/bookings/..%2Fholds/confirm');
    });

    it('reads the settled booking off the answer, which carries no hold any more', async () => {
        const booking = await postConfirm(apiOf(answering(ANSWER)), UUID, TOKEN);

        expect(booking.status).toBe('confirmed');
        expect(booking.holdClass).toBeNull();
        expect(booking.holdExpiresAt).toBeNull();
        expect(booking.items).toHaveLength(1);
        expect(booking.totalMinor).toBe(3500);
    });

    it('raises the server sentence as an ApiError for every refusal this route answers', async () => {
        const cases: [string, number, string][] = [
            ['not_confirmable', 409, 'This booking can no longer be confirmed.'],
            ['hold_expired', 410, 'The time we were holding for you has been released.'],
            ['payment_required', 402, 'This booking must be paid for before it is confirmed.'],
            ['busy', 503, 'We could not reach your booking just now. Please try again.'],
        ];

        for (const [code, status, detail] of cases) {
            await expect(postConfirm(apiOf(answering(refusal(code, status, detail), status)), UUID, TOKEN)).rejects.toMatchObject({ status, detail, code });
        }
    });

    it('raises an ordinary error, which classifies as the fixed sentence, for an answer that is not a booking', async () => {
        await expect(postConfirm(apiOf(answering({ uuid: UUID })), UUID, TOKEN)).rejects.not.toBeInstanceOf(ApiError);
    });
});

describe('the refusal', () => {
    it('carries the server sentence politely for a 4xx and marks nothing expired', () => {
        expect(refusalOf(new ApiError(409, 'This booking can no longer be confirmed.', 'not_confirmable'))).toEqual({
            notice: { text: 'This booking can no longer be confirmed.', tone: 'polite' },
            expired: false,
        });
        expect(refusalOf(new ApiError(402, 'Pay first.', 'payment_required')).expired).toBe(false);
    });

    it('marks the hold expired on a 410 alone, which is the one status this app reads', () => {
        expect(refusalOf(new ApiError(410, 'The time we were holding for you has been released.', 'hold_expired'))).toEqual({
            notice: { text: 'The time we were holding for you has been released.', tone: 'polite' },
            expired: true,
        });
    });

    it('alerts for a 5xx and for a transport failure, whose own message is never shown', () => {
        expect(refusalOf(new ApiError(503, 'We could not reach your booking just now.', 'busy'))).toEqual({
            notice: { text: 'We could not reach your booking just now.', tone: 'alert' },
            expired: false,
        });
        expect(refusalOf(new TypeError('Failed to fetch'))).toEqual({
            notice: { key: TRANSPORT_SENTENCE, tone: 'alert' },
            expired: false,
        });
    });
});
