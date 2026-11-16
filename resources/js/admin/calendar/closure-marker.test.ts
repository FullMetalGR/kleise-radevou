import { describe, expect, it } from 'vitest';
import { keyedCalendar } from '@/testing/keyed';
import { blocksOf, type CalendarBooking, type CalendarItem, type Closed, type Closure, type DayHours, insideClosure } from './model';

/*
 * The marker on a booking's block whose time a closure holds: worked out from the closures the
 * window already carries, each service by its own range against its own member's closures and the
 * business's, half-open on the tenant's wall clock.
 */

const CAL = keyedCalendar();

const ATHENS = 'Europe/Athens';

/** 10:00-10:45 in Athens, with buffers either side (09:55-10:50). */
const ITEM: CalendarItem = {
    sort: 0,
    service_id: 1,
    service_name: 'Haircut',
    staff_id: 2,
    staff_name: 'Elena Papadaki',
    start: '2026-11-03T08:00:00+00:00',
    end: '2026-11-03T08:45:00+00:00',
    price_minor: 3500,
    block_start_utc: '2026-11-03T07:55:00+00:00',
    block_end_utc: '2026-11-03T08:50:00+00:00',
    processing_ends_utc: null,
};

function booking(overrides: Partial<CalendarBooking> = {}): CalendarBooking {
    return {
        uuid: 'b1',
        status: 'confirmed',
        customer_name: 'Ada North',
        total_minor: 3500,
        currency: 'EUR',
        items: [ITEM],
        ...overrides,
    };
}

function closure(start: string | null, end: string | null, business = false): Closure {
    return { start, end, reason: null, business };
}

function day(closures: Closure[]): DayHours {
    return { windows: [], closures, source: 'none', pattern: [] };
}

/** The hours of member 2 on 3 November holding the closures given. */
function closedFor(closures: Closure[], staffId = 2): Closed {
    return {
        hours: { [String(staffId)]: { '2026-11-03': day(closures) } },
        closures: [],
    };
}

describe('the marker of a booking inside a closure', () => {
    it('marks a confirmed booking whose service a closure of its member overlaps', () => {
        const [block] = blocksOf([booking()], null, ATHENS, CAL, closedFor([closure('10:30', '12:00')]));

        expect(block?.inside).toBe(true);
    });

    it('leaves a booking unmarked that ends as the closure starts or starts as it ends', () => {
        expect(insideClosure(closedFor([closure('10:45', '12:00')]), ITEM, ATHENS)).toBe(false);
        expect(insideClosure(closedFor([closure('08:00', '10:00')]), ITEM, ATHENS)).toBe(false);
        expect(insideClosure(closedFor([closure('10:44', '12:00')]), ITEM, ATHENS)).toBe(true);
    });

    it('reads the service itself and not its buffers', () => {
        expect(insideClosure(closedFor([closure('09:00', '10:00')]), ITEM, ATHENS)).toBe(false);
        expect(insideClosure(closedFor([closure('10:45', '11:00')]), ITEM, ATHENS)).toBe(false);
    });

    it('marks a booking inside a closure of the whole business and inside a whole-day closure', () => {
        expect(insideClosure(closedFor([closure('09:00', '11:00', true)]), ITEM, ATHENS)).toBe(true);
        expect(insideClosure(closedFor([closure(null, null)]), ITEM, ATHENS)).toBe(true);
        expect(insideClosure(closedFor([closure('10:15', '24:00')]), ITEM, ATHENS)).toBe(true);
    });

    it('leaves a booking unmarked on a day with no closure, or where only another member is closed', () => {
        expect(insideClosure(closedFor([]), ITEM, ATHENS)).toBe(false);
        expect(insideClosure(closedFor([closure(null, null)], 4), ITEM, ATHENS)).toBe(false);
        expect(blocksOf([booking()], null, ATHENS, CAL).map((block) => block.inside)).toEqual([false]);
    });

    it('marks only the three statuses that still keep their time', () => {
        const closed = closedFor([closure(null, null)]);
        const statuses = ['confirmed', 'awaiting_approval', 'awaiting_payment', 'pending', 'completed', 'no_show', 'cancelled', 'rejected', 'expired'];

        expect(statuses.map((status) => blocksOf([booking({ status })], null, ATHENS, CAL, closed)[0]?.inside)).toEqual([true, true, true, false, false, false, false, false, false]);
    });

    it('judges each service of a chain by its own range against its own member, and never marks a gap', () => {
        const second: CalendarItem = {
            ...ITEM,
            sort: 1,
            staff_id: 4,
            start: '2026-11-03T08:45:00+00:00',
            end: '2026-11-03T09:30:00+00:00',
            block_start_utc: null,
            block_end_utc: null,
            processing_ends_utc: '2026-11-03T09:45:00+00:00',
        };
        const closed: Closed = {
            hours: {
                '2': { '2026-11-03': day([closure('11:00', '12:00')]) },
                '4': { '2026-11-03': day([closure('11:00', '12:00')]) },
            },
            closures: [],
        };

        expect(blocksOf([booking({ items: [ITEM, second] })], null, ATHENS, CAL, closed).map((block) => [block.id, block.inside])).toEqual([
            ['b1:0', false],
            ['b1:1', true],
            ['b1:1:gap', false],
        ]);
    });

    it('reads the closures sent for a row drawn without hours', () => {
        const closed: Closed = {
            hours: [],
            closures: {
                '2': { '2026-11-03': [closure('10:00', '10:30', true)] },
            },
        };

        expect(insideClosure(closed, ITEM, ATHENS)).toBe(true);
        expect(insideClosure({ hours: [], closures: [] }, ITEM, ATHENS)).toBe(false);
        expect(insideClosure({ hours: [] }, ITEM, ATHENS)).toBe(false);
    });

    it('marks a booking on every day of a closure that runs several days, each day by that day\'s hours, and none on the day after it', () => {
        const days = ['2026-11-03', '2026-11-04', '2026-11-05'];
        const after = '2026-11-06';
        /** The same service on the date given, from and to on the clock in UTC. */
        const on = (date: string, from = '08:00', to = '08:45'): CalendarItem => ({ ...ITEM, start: `${date}T${from}:00+00:00`, end: `${date}T${to}:00+00:00`, block_start_utc: null, block_end_utc: null });
        const run = (closures: Closure[]): Closed => ({
            hours: { '2': { ...Object.fromEntries(days.map((date) => [date, day(closures)])), [after]: day([]) } },
            closures: [],
        });
        const whole = run([closure(null, null)]);
        const mornings = run([closure('10:30', '12:00')]);

        expect([...days, after].map((date) => insideClosure(whole, on(date), ATHENS))).toEqual([true, true, true, false]);
        expect([...days, after].map((date) => insideClosure(mornings, on(date), ATHENS))).toEqual([true, true, true, false]);
        expect(days.map((date) => insideClosure(mornings, on(date, '10:00', '10:45'), ATHENS))).toEqual([false, false, false]);
        expect(blocksOf([...days, after].map((date, index) => booking({ uuid: `b${index}`, items: [on(date)] })), null, ATHENS, CAL, whole).map((block) => [block.id, block.inside])).toEqual([
            ['b0:0', true],
            ['b1:0', true],
            ['b2:0', true],
            ['b3:0', false],
        ]);
    });

    it('checks every date a service touches', () => {
        const overnight: CalendarItem = {
            ...ITEM,
            start: '2026-11-03T21:00:00+00:00',
            end: '2026-11-03T23:00:00+00:00',
        };
        const closed: Closed = {
            hours: {
                '2': {
                    '2026-11-03': day([]),
                    '2026-11-04': day([closure('00:00', '00:30')]),
                },
            },
            closures: [],
        };

        expect(insideClosure(closed, overnight, ATHENS)).toBe(true);
    });
});
