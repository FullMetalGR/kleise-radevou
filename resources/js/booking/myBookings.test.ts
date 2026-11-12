import { describe, expect, it } from 'vitest';
import { parseBooking, type Booking } from './booking';
import { bookAgainLink, INLINED_LIST_ID, inlinedMyBookings, parseMyBookings, SERVICE_QUERY, STAFF_QUERY } from './myBookings';

/*
 * My bookings' inlined list: the session's bookings in the guest view's shape or null, the
 * flashed sentence and the form's token, read key by key; and Book again, the hosted page with
 * the first item's service and its staff where the staff is a person.
 */

const GUEST = {
    uuid: '7b7f0e5a-2222-4000-8000-00000000000a',
    status: 'confirmed',
    hold_class: null,
    hold_expires_at: null,
    customer_name: 'Casey Customer',
    customer_email: 'casey@example.com',
    customer_phone: null,
    total_minor: 3500,
    currency: 'EUR',
    items: [{ sort: 0, service_id: 12, service_name: 'Haircut', staff_id: 4, staff_name: 'Elena', start: '2026-11-03T08:00:00+00:00', end: '2026-11-03T08:30:00+00:00', price_minor: 3500 }],
};

function booking(overrides: Record<string, unknown> = {}): Booking {
    const parsed = parseBooking({ ...GUEST, ...overrides });

    if (parsed === null) {
        throw new Error('The fixture is no booking.');
    }

    return parsed;
}

describe('parseMyBookings', () => {
    it('reads the form: no bookings, the sentence flashed or none, and the token', () => {
        expect(parseMyBookings({ bookings: null, notice: null, csrf: 'abc' })).toEqual({ bookings: null, notice: null, csrf: 'abc' });
        expect(parseMyBookings({ bookings: null, notice: 'That link is no longer valid. Ask for a new one.', csrf: 'abc' })?.notice).toBe('That link is no longer valid. Ask for a new one.');
    });

    it('reads the list: upcoming and past, each a guest view', () => {
        const page = parseMyBookings({ bookings: { upcoming: [GUEST], past: [{ ...GUEST, uuid: 'past-one', status: 'completed' }] }, notice: null, csrf: 'abc' });

        expect(page?.bookings?.upcoming.map((one) => one.uuid)).toEqual([GUEST.uuid]);
        expect(page?.bookings?.past.map((one) => one.status)).toEqual(['completed']);
    });

    it.each([
        ['no token', { bookings: null, notice: null }],
        ['a token that is no string', { bookings: null, notice: null, csrf: 7 }],
        ['a sentence that is no string', { bookings: null, notice: 3, csrf: 'abc' }],
        ['a list missing its past', { bookings: { upcoming: [GUEST] }, notice: null, csrf: 'abc' }],
        ['a list holding something that is no booking', { bookings: { upcoming: [{ uuid: 'x' }], past: [] }, notice: null, csrf: 'abc' }],
        ['no object at all', 'bookings'],
    ])('reads %s as nothing', (_name, value) => {
        expect(parseMyBookings(value)).toBeNull();
    });
});

describe('inlinedMyBookings', () => {
    it('reads the JSON script by its id, and nothing from an element that is no JSON script', () => {
        document.body.innerHTML = `<script type="application/json" id="${INLINED_LIST_ID}">${JSON.stringify({ bookings: null, notice: null, csrf: 'abc' })}</script>`;

        expect(inlinedMyBookings(document)?.csrf).toBe('abc');

        document.body.innerHTML = `<div id="${INLINED_LIST_ID}">${JSON.stringify({ bookings: null, notice: null, csrf: 'abc' })}</div>`;

        expect(inlinedMyBookings(document)).toBeNull();

        document.body.innerHTML = `<script type="application/json" id="${INLINED_LIST_ID}">not json</script>`;

        expect(inlinedMyBookings(document)).toBeNull();
    });
});

describe('bookAgainLink', () => {
    it('writes the hosted page\'s two query keys from their one home, LinkKind\'s values on the server', () => {
        const query = new URL(bookAgainLink('https://luna.kleiseradevou.gr', booking({ items: GUEST.items }))).searchParams;

        expect([SERVICE_QUERY, STAFF_QUERY]).toEqual(['service', 'staff']);
        expect([...query.keys()]).toEqual([SERVICE_QUERY, STAFF_QUERY]);
    });

    it('preselects the first item\'s service and its staff member on the hosted page', () => {
        const chain = booking({ items: [...GUEST.items, { ...GUEST.items[0], sort: 1, service_id: 13, staff_id: 5 }] });

        expect(bookAgainLink('https://luna.kleiseradevou.gr', chain)).toBe('https://luna.kleiseradevou.gr/?service=12&staff=4');
    });

    it('leaves the staff out where the item runs on the implicit member, whom the guest view names as nobody', () => {
        const implicit = booking({ items: [{ ...GUEST.items[0], staff_name: null, staff_id: 1 }] });

        expect(bookAgainLink('https://luna.kleiseradevou.gr', implicit)).toBe('https://luna.kleiseradevou.gr/?service=12');
    });

    it('keeps the prefix the page was opened under, the business page under it as /en or /el, and is bare for none', () => {
        const chain = booking();

        expect(bookAgainLink('https://luna.kleiseradevou.gr', chain, 'el')).toBe('https://luna.kleiseradevou.gr/el?service=12&staff=4');
        expect(bookAgainLink('https://luna.kleiseradevou.gr', chain, 'en')).toBe('https://luna.kleiseradevou.gr/en?service=12&staff=4');
        expect(bookAgainLink('https://luna.kleiseradevou.gr', chain, null)).toBe('https://luna.kleiseradevou.gr/?service=12&staff=4');
    });
});
