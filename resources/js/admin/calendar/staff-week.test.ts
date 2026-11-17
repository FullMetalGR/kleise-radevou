import { describe, expect, it } from 'vitest';
import { keyedShifts } from '@/testing/keyed';
import type { CalendarBooking, CalendarItem, Closure, DayHours, HoursWindow, Window } from './model';
import { staffWeekShown, weekDaysOf, weekRowsOf } from './staff-week';

/*
 * The grid of people by days as data: when Week is that grid, its seven days, a row per member in
 * the answer's order and the other bookings' row where the week has one, a chip per booking per
 * row and day standing for that row's items, the hatch of a day off or a day closed over its
 * hours, and a closure with a time as a line at its start.
 */

const ATHENS = 'Europe/Athens';

const CLOCK = { timezone: ATHENS, dateFormat: 'd/m/Y', timeFormat: 'H:i' };

const SHIFTS = keyedShifts();

const ELENA = { id: 2, name: 'Elena Papadaki' };

const NIKOS = { id: 4, name: 'Nikos Georgiou' };

/** Monday the second to Sunday the eighth of November 2026. */
const DAYS = weekDaysOf('2026-11-04', 1);

const NINE_TO_FIVE: HoursWindow[] = [{ start: '09:00', end: '17:00' }];

function day(windows: HoursWindow[], closures: Closure[] = []): DayHours {
    return { windows, closures, source: 'pattern', pattern: windows };
}

function closure(start: string | null, end: string | null, reason: string | null = null): Closure {
    return { start, end, reason, business: false };
}

/** Every day of the week nine to five for both, but where a test says otherwise. */
function hours(overrides: Record<string, Record<string, DayHours>> = {}): Record<string, Record<string, DayHours>> {
    const week = Object.fromEntries(DAYS.map((date) => [date, day(NINE_TO_FIVE)]));

    return { '2': { ...week, ...overrides['2'] }, '4': { ...week, ...overrides['4'] } };
}

type Shown = Pick<Window, 'from' | 'to' | 'who' | 'members' | 'implicit' | 'hours' | 'closures'>;

const WINDOW: Shown = { from: '2026-11-02', to: '2026-11-09', who: 'scheduled', members: [ELENA, NIKOS], implicit: null, hours: hours(), closures: [] };

function item(sort: number, staff: number, start: string, end: string): CalendarItem {
    return { sort, service_id: 1, service_name: 'Haircut', staff_id: staff, staff_name: 'Someone', start, end, price_minor: 1 };
}

function booking(uuid: string, items: CalendarItem[], overrides: Partial<CalendarBooking> = {}): CalendarBooking {
    return { uuid, status: 'confirmed', customer_name: 'Ada North', total_minor: 1, currency: 'EUR', items, ...overrides };
}

function rowsOf(bookings: CalendarBooking[], window: Shown = WINDOW) {
    return weekRowsOf(window, bookings, DAYS, ATHENS, CLOCK, SHIFTS);
}

/** Each row's chips by day, as uuids, the days with none left out. */
function chipsOf(rows: ReturnType<typeof rowsOf>) {
    return Object.fromEntries(rows.map((row) => [row.key, Object.fromEntries(row.cells.filter((cell) => cell.chips.length > 0).map((cell) => [cell.date, cell.chips.map((chip) => chip.uuid)]))]));
}

describe('when Week is the grid of people', () => {
    it('is Week for a viewer not pinned, with the staff choice real and two members or more answered', () => {
        expect(staffWeekShown('week', false, true, [ELENA, NIKOS])).toBe(true);
        expect(staffWeekShown('day', false, true, [ELENA, NIKOS])).toBe(false);
        expect(staffWeekShown('month', false, true, [ELENA, NIKOS])).toBe(false);
        expect(staffWeekShown('week', true, true, [ELENA, NIKOS])).toBe(false);
        expect(staffWeekShown('week', false, false, [ELENA, NIKOS])).toBe(false);
        expect(staffWeekShown('week', false, true, [ELENA])).toBe(false);
    });

    it('draws the seven days of the week from the tenant week start', () => {
        expect(DAYS).toEqual(['2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05', '2026-11-06', '2026-11-07', '2026-11-08']);
        expect(weekDaysOf('2026-11-04', 7)).toEqual(['2026-11-01', '2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05', '2026-11-06', '2026-11-07']);
    });
});

describe('the rows and their chips', () => {
    it('draws a row per member in the answer\'s order, a booking once per row on the day its first item on that row starts, standing for that row\'s items', () => {
        // Tuesday: a haircut with Elena at 10:00 Athens, then colour with Nikos at 10:45, then a
        // blow-dry with Elena at 11:30; Thursday a booking with Nikos alone.
        const chain = booking('chain', [item(0, 2, '2026-11-03T08:00:00+00:00', '2026-11-03T08:45:00+00:00'), item(1, 4, '2026-11-03T08:45:00+00:00', '2026-11-03T09:30:00+00:00'), item(2, 2, '2026-11-03T09:30:00+00:00', '2026-11-03T10:00:00+00:00')]);
        const rows = rowsOf([chain, booking('thu', [item(0, 4, '2026-11-05T12:00:00+00:00', '2026-11-05T13:00:00+00:00')])], { ...WINDOW, members: [NIKOS, ELENA] });

        expect(rows.map((row) => row.key)).toEqual(['member-4', 'member-2']);
        expect(rows.map((row) => row.cells.map((cell) => cell.date))).toEqual([DAYS, DAYS]);
        expect(chipsOf(rows)).toEqual({ 'member-4': { '2026-11-03': ['chain'], '2026-11-05': ['thu'] }, 'member-2': { '2026-11-03': ['chain'] } });

        const elena = rows[1]?.cells[1]?.chips[0];
        const nikos = rows[0]?.cells[1]?.chips[0];

        expect([elena?.start, elena?.staffId, elena?.segments]).toEqual(['2026-11-03T10:00:00', 2, [0, 2]]);
        expect([nikos?.start, nikos?.staffId, nikos?.segments]).toEqual(['2026-11-03T10:45:00', 4, [1]]);
    });

    it('puts a booking on the tenant day its row\'s first item starts, past midnight, and orders a day by start and then by uuid', () => {
        // 21:30 UTC on Tuesday is 23:30 in Athens; the second item runs past midnight.
        const late = booking('late', [item(0, 2, '2026-11-03T21:30:00+00:00', '2026-11-03T22:15:00+00:00'), item(1, 2, '2026-11-03T22:15:00+00:00', '2026-11-03T23:00:00+00:00')]);
        const rows = rowsOf([booking('b', [item(0, 2, '2026-11-03T08:00:00+00:00', '2026-11-03T09:00:00+00:00')]), late, booking('a', [item(0, 2, '2026-11-03T08:00:00+00:00', '2026-11-03T09:00:00+00:00')])]);

        expect(chipsOf(rows)).toEqual({ 'member-2': { '2026-11-03': ['a', 'b', 'late'] }, 'member-4': {} });
    });

    it('adds the other bookings\' row after the members where the week has a booking on a drawn row with no row of its own, and only then', () => {
        const implicit = booking('implicit', [item(0, 9, '2026-11-04T08:00:00+00:00', '2026-11-04T09:00:00+00:00')]);
        const hidden = booking('hidden', [item(0, 7, '2026-11-04T08:00:00+00:00', '2026-11-04T09:00:00+00:00')]);

        expect(rowsOf([implicit, hidden]).map((row) => row.key)).toEqual(['member-2', 'member-4']);

        const rows = rowsOf([implicit, hidden], { ...WINDOW, implicit: 9 });
        const other = rows.at(-1);

        expect(rows.map((row) => row.key)).toEqual(['member-2', 'member-4', 'other']);
        expect([other?.member, chipsOf(rows)['other']]).toEqual([null, { '2026-11-04': ['implicit'] }]);
        expect(other?.cells.every((cell) => !cell.hatched && cell.lines.length === 0)).toBe(true);
        expect(chipsOf(rowsOf([implicit, hidden], { ...WINDOW, who: 'all' }))['other']).toEqual({ '2026-11-04': ['hidden', 'implicit'] });
    });

    it('leaves the other bookings\' row out where its only chip falls outside the days drawn', () => {
        const before = booking('before', [item(0, 9, '2026-11-01T08:00:00+00:00', '2026-11-01T09:00:00+00:00')]);

        expect(rowsOf([before], { ...WINDOW, implicit: 9 }).map((row) => row.key)).toEqual(['member-2', 'member-4']);
    });
});

describe('the hatch and the closure lines', () => {
    const cellOf = (window: Shown, member: number, date: string) => rowsOf([], window).find((row) => row.key === `member-${member}`)?.cells.find((cell) => cell.date === date);

    it('hatches a day with no working window, and one whose closures together cover every window, the whole-day closure drawing no line', () => {
        const window = {
            ...WINDOW,
            hours: hours({
                '2': { '2026-11-03': day([]), '2026-11-04': day(NINE_TO_FIVE, [closure(null, null, 'Holiday')]) },
                '4': { '2026-11-03': day([{ start: '09:00', end: '13:00' }, { start: '14:00', end: '18:00' }], [closure('12:00', '15:00'), closure('08:00', '12:30'), closure('15:00', '24:00')]) },
            }),
        };

        expect([cellOf(window, 2, '2026-11-03')?.hatched, cellOf(window, 2, '2026-11-04')?.hatched, cellOf(window, 2, '2026-11-04')?.lines]).toEqual([true, true, []]);
        expect(cellOf(window, 4, '2026-11-03')?.hatched).toBe(true);
        expect(cellOf(window, 4, '2026-11-04')?.hatched).toBe(false);
    });

    it('leaves a day whose closures miss part of its windows unhatched, each closure with a time a line at its start, its range and its reason', () => {
        const window = { ...WINDOW, hours: hours({ '2': { '2026-11-05': day(NINE_TO_FIVE, [closure('13:00', '15:00', 'Dentist'), closure('09:00', '10:00')]) } }) };
        const cell = cellOf(window, 2, '2026-11-05');

        expect(cell?.hatched).toBe(false);
        expect(cell?.lines).toEqual([
            { key: 'member-2:2026-11-05:closure:0', start: '2026-11-05T13:00:00', range: '13:00 - 15:00', reason: 'Dentist', title: SHIFTS.text('page.closed_reason', { start: '13:00', end: '15:00', reason: 'Dentist' }) },
            { key: 'member-2:2026-11-05:closure:1', start: '2026-11-05T09:00:00', range: '09:00 - 10:00', reason: null, title: SHIFTS.text('page.closed', { start: '09:00', end: '10:00' }) },
        ]);
    });

    it('draws neither on a day the window has not read', () => {
        const window = { ...WINDOW, from: '2026-11-04', hours: hours({ '2': { '2026-11-03': day([], [closure('13:00', '15:00')]) } }) };

        expect([cellOf(window, 2, '2026-11-03')?.hatched, cellOf(window, 2, '2026-11-03')?.lines]).toEqual([false, []]);
    });
});
