import { describe, expect, it } from 'vitest';
import { applicable, distinct, NO_FILTERS, queryOf, staffShown, startOf, type Booking } from './model';

/*
 * The Bookings bar's rules, and the parameters it sends, 3.2.
 */

describe('the staff axis', () => {
    it('hides on the empty entitlement set with one member, shows with the set or with two members', () => {
        expect(staffShown([], 1, null)).toBe(false);
        expect(staffShown(['staff'], 1, null)).toBe(true);
        expect(staffShown([], 2, null)).toBe(true);
    });

    it('never hides a control holding a value', () => {
        expect(staffShown([], 1, 3)).toBe(true);
    });

    it('drops a remembered staff value whose control would be hidden, and keeps it where the control shows', () => {
        const remembered = { ...NO_FILTERS, staff: 3, status: 'confirmed' };

        expect(applicable(remembered, [], 1)).toEqual({ ...remembered, staff: null });
        expect(applicable(remembered, [], 3)).toEqual(remembered);
    });
});

describe('the query', () => {
    it('sends the fields set and page two onward, and leaves the empty ones and page one out', () => {
        expect(queryOf({ ...NO_FILTERS, staff: 4, to: '2026-11-30' }, 1)).toEqual({ staff: '4', to: '2026-11-30' });
        expect(queryOf(NO_FILTERS, 3)).toEqual({ page: '3' });
    });
});

describe('the columns', () => {
    const booking = {
        items: [
            { start: '2026-11-03T08:45:00+00:00', service_name: 'Haircut', staff_name: 'Elena' },
            { start: '2026-11-03T08:00:00+00:00', service_name: 'Haircut', staff_name: 'Nikos' },
        ],
    } as unknown as Booking;

    it('starts the booking at its earliest item and lists each distinct value once in item order', () => {
        expect(startOf(booking)).toBe('2026-11-03T08:00:00+00:00');
        expect(distinct(['Haircut', 'Colour', 'Haircut', null])).toEqual(['Haircut', 'Colour']);
    });
});
