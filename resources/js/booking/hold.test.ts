import { describe, expect, it, vi } from 'vitest';
import { ApiError, type Api } from './api';
import type { Fetch } from './config';
import { BLOCKED_SENTENCE, EMPTY_DRAFT, holdBody, HOLDS_PATH, holdRefusal, parseHeld, postHold, type CustomerDraft } from './hold';

/*
 * The hold on the wire, over POST /holds: the body trimmed with an empty phone omitted rather
 * than sent, the chain's items carried whole with their staff_id explicit, the start
 * byte-identical to the availability value, and the answer read key by key, the manage token
 * beside the booking.
 */

const ITEMS = [
    { service_id: 1, staff_id: null },
    { service_id: 4, staff_id: 3 },
];

const START = '2026-11-03T07:00:00+00:00';

const ANSWER = {
    uuid: '7b7f0e5a-1111-4000-8000-000000000001',
    status: 'pending',
    hold_class: 'checkout',
    hold_expires_at: '2026-11-02T06:15:00+00:00',
    customer_name: 'Casey Customer',
    customer_email: 'casey@example.com',
    customer_phone: null,
    total_minor: 5000,
    currency: 'EUR',
    items: [
        { sort: 0, service_id: 1, service_name: 'Haircut', staff_id: 7, staff_name: 'Elena', start: START, end: '2026-11-03T07:45:00+00:00', price_minor: 3500 },
        { sort: 1, service_id: 4, service_name: 'Beard trim', staff_id: 7, staff_name: null, start: '2026-11-03T07:45:00+00:00', end: '2026-11-03T08:05:00+00:00', price_minor: 1500 },
    ],
    manage_token: 'a'.repeat(64),
};

function draft(overrides: Partial<CustomerDraft> = {}): CustomerDraft {
    return { name: 'Casey Customer', email: 'casey@example.com', phone: '', note: '', ...overrides };
}

function answering(body: unknown, status = 201): Fetch {
    return () => Promise.resolve(new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }));
}

function apiOf(fetchFn: Fetch): Api {
    return { origin: 'https://luna.kleiseradevou.gr', fetch: fetchFn };
}

describe('the body', () => {
    it('trims the three fields and carries the items and the start as they stand', () => {
        const body = holdBody(draft({ name: '  Casey Customer  ', email: ' casey@example.com ', phone: ' +30 210 0000000 ' }), ITEMS, START, null);

        expect(body).toEqual({
            customer_name: 'Casey Customer',
            customer_email: 'casey@example.com',
            customer_phone: '+30 210 0000000',
            items: ITEMS,
            start: START,
        });
        expect(JSON.parse(JSON.stringify(body)).start).toBe(START);
    });

    it('omits the phone key entirely when it is empty or nothing but spaces, rather than sending an empty string', () => {
        expect(Object.keys(holdBody(draft(), ITEMS, START, null))).toEqual(['customer_name', 'customer_email', 'items', 'start']);
        expect(Object.keys(holdBody(draft({ phone: '   ' }), ITEMS, START, null))).not.toContain('customer_phone');
        expect(Object.keys(holdBody(EMPTY_DRAFT, ITEMS, START, null))).not.toContain('customer_phone');
    });

    it('spells no preference as an explicit null staff_id, never as an absent key', () => {
        expect(JSON.stringify(holdBody(draft(), ITEMS, START, null).items)).toBe('[{"service_id":1,"staff_id":null},{"service_id":4,"staff_id":3}]');
    });
});

describe('the note and the channel', () => {
    it('carries the note trimmed when one was written, and omits the key when it is blank', () => {
        expect(holdBody(draft({ note: '  Allergic to lavender.\nQuiet room.  ' }), ITEMS, START, null).note).toBe('Allergic to lavender.\nQuiet room.');
        expect(holdBody(draft({ note: ' \n ' }), ITEMS, START, null)).not.toHaveProperty('note');
        expect(holdBody(draft(), ITEMS, START, null)).not.toHaveProperty('note');
    });

    it('carries the mount\'s channel when it names one of the five, and omits the key when it names none', () => {
        expect(holdBody(draft(), ITEMS, START, 'instagram').channel).toBe('instagram');
        expect(holdBody(draft(), ITEMS, START, null)).not.toHaveProperty('channel');
    });

    it('names no source: the server stamps it from the request\'s origin, and the body has no key for it', () => {
        const body = holdBody(draft({ phone: '+30 210 0000000', note: 'Hi.' }), ITEMS, START, 'qr');

        expect(Object.keys(body)).toEqual(['customer_name', 'customer_email', 'customer_phone', 'items', 'start', 'note', 'channel']);
        expect(JSON.stringify(body)).not.toContain('source');
    });

    it('starts the draft with an empty note', () => {
        expect(EMPTY_DRAFT).toEqual({ name: '', email: '', phone: '', note: '' });
    });
});

describe('the answer', () => {
    it('reads the booking and the token off a well-formed answer', () => {
        const held = parseHeld(ANSWER);

        expect(held?.manageToken).toBe('a'.repeat(64));
        expect(held?.booking).toEqual({
            uuid: ANSWER.uuid,
            status: 'pending',
            holdClass: 'checkout',
            holdExpiresAt: '2026-11-02T06:15:00+00:00',
            customerName: 'Casey Customer',
            customerEmail: 'casey@example.com',
            customerPhone: null,
            totalMinor: 5000,
            currency: 'EUR',
            items: [
                { sort: 0, serviceId: 1, serviceName: 'Haircut', staffId: 7, staffName: 'Elena', start: START, end: '2026-11-03T07:45:00+00:00', priceMinor: 3500 },
                { sort: 1, serviceId: 4, serviceName: 'Beard trim', staffId: 7, staffName: null, start: '2026-11-03T07:45:00+00:00', end: '2026-11-03T08:05:00+00:00', priceMinor: 1500 },
            ],
        });
    });

    it('reads a hold with no expiry and a booking with no checkout_url', () => {
        const held = parseHeld({ ...ANSWER, hold_class: null, hold_expires_at: null });

        expect(held?.booking.holdClass).toBeNull();
        expect(held?.booking.holdExpiresAt).toBeNull();
        expect(held?.booking).not.toHaveProperty('checkoutUrl');
        expect(held?.booking).not.toHaveProperty('paysAtVenue');
    });

    it('reads pays_at_venue where the answer says it, and nothing where it is anything but true', () => {
        expect(parseHeld({ ...ANSWER, pays_at_venue: true })?.booking.paysAtVenue).toBe(true);
        expect(parseHeld({ ...ANSWER, pays_at_venue: 'yes' })?.booking).not.toHaveProperty('paysAtVenue');
    });

    it('is not an answer when a key is missing, of the wrong type, or an item is malformed', () => {
        expect(parseHeld(null)).toBeNull();
        expect(parseHeld([ANSWER])).toBeNull();
        expect(parseHeld({ ...ANSWER, manage_token: undefined })).toBeNull();
        expect(parseHeld({ ...ANSWER, uuid: 7 })).toBeNull();
        expect(parseHeld({ ...ANSWER, total_minor: '5000' })).toBeNull();
        expect(parseHeld({ ...ANSWER, items: [] })).toBeNull();
        expect(parseHeld({ ...ANSWER, items: [{ ...ANSWER.items[0], staff_id: null }] })).toBeNull();
        expect(parseHeld({ ...ANSWER, items: [{ ...ANSWER.items[0], start: '2026-11-03 07:00:00' }] })).toBeNull();
    });
});

describe('the request', () => {
    it('posts the body as JSON to the holds path on the API origin, with credentials omitted', async () => {
        const fetchFn = vi.fn<Fetch>(answering(ANSWER));
        const body = holdBody(draft(), ITEMS, START, null);

        await postHold(apiOf(fetchFn), body);

        const [url, init] = fetchFn.mock.calls[0] as [string, RequestInit];

        expect(url).toBe('https://luna.kleiseradevou.gr' + HOLDS_PATH);
        expect(init.method).toBe('POST');
        expect(init.credentials).toBe('omit');
        expect(init.body).toBe(JSON.stringify(body));
        expect(new Headers(init.headers).get('Content-Type')).toBe('application/json');
    });

    it('raises the server sentence as an ApiError for a 409, a 400 and a 429', async () => {
        const conflict = { code: 'overlap', message: 'overlap', data: { status: 409, detail: 'That time was just taken. Please pick another.', segment: 0 } };

        await expect(postHold(apiOf(answering(conflict, 409)), holdBody(draft(), ITEMS, START, null))).rejects.toMatchObject({
            status: 409,
            detail: 'That time was just taken. Please pick another.',
            code: 'overlap',
        });

        const refusal = postHold(apiOf(answering({ code: 'invalid_request', message: 'invalid_request', data: { status: 400, detail: 'A name is required.' } }, 400)), holdBody(draft(), ITEMS, START, null));

        await expect(refusal).rejects.toBeInstanceOf(ApiError);
        await expect(postHold(apiOf(answering({ code: 'rate_limited', message: 'rate_limited', data: { status: 429, detail: 'Too many requests.' } }, 429)), holdBody(draft(), ITEMS, START, null))).rejects.toMatchObject({ status: 429 });
    });

    it('raises an ordinary error, which classifies as the fixed sentence, for an answer that is not a hold', async () => {
        await expect(postHold(apiOf(answering({ uuid: 'only-that' })), holdBody(draft(), ITEMS, START, null))).rejects.not.toBeInstanceOf(ApiError);
    });
});

describe('a refused hold', () => {
    it('says the client\'s own sentence for an address the business blocked, in the language the app holds, never the envelope\'s English', () => {
        expect(holdRefusal(new ApiError(403, 'Booking online is not available.', 'blocked'))).toEqual({ key: BLOCKED_SENTENCE, tone: 'polite' });
        expect(BLOCKED_SENTENCE).toBe('details.blocked');
    });

    it('says the server\'s sentence for any other refusal, a 403 of another code among them, and the fixed one for a dropped answer', () => {
        expect(holdRefusal(new ApiError(403, 'Not allowed.', 'forbidden'))).toEqual({ text: 'Not allowed.', tone: 'polite' });
        expect(holdRefusal(new ApiError(400, 'A name is required.', 'invalid_request'))).toEqual({ text: 'A name is required.', tone: 'polite' });
        expect(holdRefusal(new TypeError('Failed to fetch'))).toEqual({ key: 'wire.transport', tone: 'alert' });
    });
});
