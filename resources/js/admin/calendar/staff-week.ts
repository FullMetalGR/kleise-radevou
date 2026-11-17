import { formatTimeOfDay, type TenantClock } from '@/lib/format';
import type { ShiftWords } from '@/shifts/words';
import { addDays } from '../../booking/time';
import { type CalendarBooking, type Chip, chipsByDayOf, type Closure, closureTitleOf, type DayHours, type HoursWindow, hoursOn, type Member, rangeOf, shownRows, type View, type Window } from './model';

/*
 * Week for a team, as a grid of people by days: a row per member the window answers, in its order,
 * then a row of the other bookings, those drawn on a row with no row of its own, where the week
 * has one; a column per day of the week. A row's day lists one chip per booking with an item on
 * that row, on the day the first of those items starts, on the tenant's wall clock, standing for
 * them all. A member's day with no working window, or with its windows covered by closures, is
 * hatched whole and still lists its chips; a closure with a time is a line among the chips at its
 * start, its range and its reason. The other bookings' row has neither, its member none.
 */

/** Whether Week is the grid of people: Week, a viewer not pinned to their own row, the staff choice real, and two members or more in the answer. */
export function staffWeekShown(view: View, pinned: boolean, staffed: boolean, members: readonly Member[]): boolean {
    return view === 'week' && !pinned && staffed && members.length >= 2;
}

/** The seven days of the week holding a date, from the tenant's week start. */
export function weekDaysOf(date: string, weekStart: number): string[] {
    const { from } = rangeOf('week', date, weekStart);

    return Array.from({ length: 7 }, (_, at) => addDays(from, at));
}

/** A closure with a time on a member's day: its wall clock on that day, what the line prints, its reason, and its sentence for readers. */
export type ClosureLine = { key: string; start: string; range: string; reason: string | null; title: string };

/** A row's day: its chips in their order, the closure lines drawn among them, and whether the day is hatched whole. */
export type WeekCell = { date: string; chips: Chip[]; lines: ClosureLine[]; hatched: boolean };

/** A row of the grid: a member's, or the other bookings' with no member. */
export type WeekRow = { key: string; member: Member | null; cells: WeekCell[] };

/** A closure's range on its day as two readings HH:MM, a whole-day closure the whole day. */
function spanOf(closure: Closure): HoursWindow {
    return closure.start !== null && closure.end !== null ? { start: closure.start, end: closure.end } : { start: '00:00', end: '24:00' };
}

/** Whether closures together cover a window: read from its start, each closure reaching on from where the last left off, with no gap before its end. */
function covered(window: HoursWindow, closures: readonly Closure[]): boolean {
    const spans = closures.map(spanOf).sort((a, b) => (a.start < b.start ? -1 : 1));
    let reached = window.start;

    for (const span of spans) {
        if (span.start > reached) {
            break;
        }

        reached = span.end > reached ? span.end : reached;
    }

    return reached >= window.end;
}

/** Whether a member's day is hatched whole: no working window, or every window covered by its closures. */
function hatchedDay(hours: Pick<DayHours, 'windows' | 'closures'>): boolean {
    return hours.windows.every((window) => covered(window, hours.closures));
}

/** The lines a member's day draws: one per closure with a time, at its start, the whole-day ones left to the hatch. */
function linesOf(hours: DayHours, date: string, key: string, clock: TenantClock, closed: ShiftWords): ClosureLine[] {
    return hours.closures.flatMap((closure, at) =>
        closure.start === null || closure.end === null
            ? []
            : [{ key: `${key}:closure:${at}`, start: `${date}T${closure.start}:00`, range: `${formatTimeOfDay(closure.start, clock)} - ${formatTimeOfDay(closure.end, clock)}`, reason: closure.reason, title: closureTitleOf(closure, clock, closed) }],
    );
}

type Reading = { window: Pick<Window, 'from' | 'to' | 'hours'>; clock: TenantClock; closed: ShiftWords };

/** A member's day: its chips, and its hatch and lines where the window has read that day; a day not yet read draws its chips alone. */
function memberCell(member: Member, date: string, chips: Chip[], reading: Reading): WeekCell {
    if (date < reading.window.from || date >= reading.window.to) {
        return { date, chips, lines: [], hatched: false };
    }

    const hours = hoursOn(reading.window, member.id, date);

    return { date, chips, lines: linesOf(hours, date, `member-${member.id}:${date}`, reading.clock, reading.closed), hatched: hatchedDay(hours) };
}

/**
 * The grid's rows for one window answer and the bookings the status and service cuts leave: a row
 * per member in the answer's order, then the other bookings' row where any of its chips falls on
 * one of the days drawn.
 */
export function weekRowsOf(window: Pick<Window, 'from' | 'to' | 'who' | 'members' | 'implicit' | 'hours' | 'closures'>, bookings: readonly CalendarBooking[], days: readonly string[], timezone: string, clock: TenantClock, closed: ShiftWords): WeekRow[] {
    const reading: Reading = { window, clock, closed };
    const ids = window.members.map((member) => member.id);
    const drawn = shownRows(window);
    const rows: WeekRow[] = window.members.map((member) => {
        const chips = chipsByDayOf(bookings, (booking) => booking.items.filter((item) => item.staff_id === member.id), timezone, window);

        return { key: `member-${member.id}`, member, cells: days.map((date) => memberCell(member, date, chips.get(date) ?? [], reading)) };
    });
    const others = chipsByDayOf(bookings, (booking) => booking.items.filter((item) => !ids.includes(item.staff_id) && (drawn === null || drawn.includes(item.staff_id))), timezone, window);
    const other: WeekCell[] = days.map((date) => ({ date, chips: others.get(date) ?? [], lines: [], hatched: false }));

    return other.some((cell) => cell.chips.length > 0) ? [...rows, { key: 'other', member: null, cells: other }] : rows;
}
