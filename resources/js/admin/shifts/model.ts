/*
 * The Shifts screen's props: the members, the week asked for (from its first date, to the day
 * after its last, as the Calendar's window), per member per date the windows in force, where
 * they came from and the closures reaching the member and the windows the pattern gives that
 * date with the override left out, each member's pattern as stored and the minutes per date and
 * per member with the closures subtracted. A window and a closure carry no instant: two
 * wall-clock readings HH:MM, the end of the day spelled 24:00, never converted.
 */

import { utcDayOf } from '@/lib/day';

export type ShiftWindow = { start: string; end: string };

/** The window a day opens with where nothing nearer gives one, in every weekly and day editor. */
export const DEFAULT_WINDOW: Readonly<ShiftWindow> = { start: '09:00', end: '17:00' };

export type ShiftClosure = { start: string | null; end: string | null; reason: string | null; business: boolean };

export type ShiftSource = 'pattern' | 'override' | 'none';

export type ShiftDay = { staff_id: number; date: string; windows: ShiftWindow[]; source: ShiftSource; closures: ShiftClosure[]; pattern: ShiftWindow[] };

/** A window of a repeating week: its ISO weekday, Monday one to Sunday seven, and two readings HH:MM. */
export type WeeklyWindow = { weekday: number; start: string; end: string };

export type ShiftPattern = {
    staff_id: number;
    cadence_weeks: number;
    starts_on: string;
    ends_on: string | null;
    windows: WeeklyWindow[];
    minutes: number;
};

export type ShiftMember = { id: number; name: string };

export type ShiftWeek = { from: string; to: string };

export type ShiftTotals = { days: { date: string; minutes: number }[]; members: { staff_id: number; minutes: number }[] };

/** The dates of the week in order, from its first to the day before its end. */
export function datesOf(week: ShiftWeek): string[] {
    const dates: string[] = [];
    const at = new Date(`${week.from}T00:00:00Z`);
    const end = new Date(`${week.to}T00:00:00Z`);

    while (at < end && dates.length < 42) {
        dates.push(utcDayOf(at));
        at.setUTCDate(at.getUTCDate() + 1);
    }

    return dates;
}

/** One member's day of the week's days, or an empty day where the props hold none. */
export function dayOf(days: readonly ShiftDay[], staffId: number, date: string): ShiftDay {
    return days.find((day) => day.staff_id === staffId && day.date === date) ?? { staff_id: staffId, date, windows: [], source: 'none', closures: [], pattern: [] };
}

/** A member's pattern among the patterns the props hold, or none where they have no pattern. */
export function patternOf(patterns: readonly ShiftPattern[], staffId: number): ShiftPattern | undefined {
    return patterns.find((pattern) => pattern.staff_id === staffId);
}

/** The minutes of a wall-clock reading HH:MM, 24:00 the end of the day. */
function minutesOf(reading: string): number {
    const [hours, minutes] = reading.split(':');

    return Number(hours ?? 0) * 60 + Number(minutes ?? 0);
}

/** The reading HH:MM of a count of minutes into the day. */
function readingOf(minutes: number): string {
    return `${String(Math.floor(minutes / 60)).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}`;
}

/** The minutes the windows given cover, a window not yet in order counting none. */
export function windowMinutes(windows: readonly ShiftWindow[]): number {
    return windows.reduce((sum, window) => sum + Math.max(0, minutesOf(window.end) - minutesOf(window.start)), 0);
}

/**
 * A second window's first values, after the one given: an hour after its end and an hour long,
 * or its end twice where the day has no room, for the person to set.
 */
export function windowAfter(first: ShiftWindow): ShiftWindow {
    const start = minutesOf(first.end) + 60;

    return start + 60 < 1440 ? { start: readingOf(start), end: readingOf(start + 60) } : { start: first.end, end: first.end };
}

/** The minutes of a member's week in the totals, closures taken off; zero where they hold none. */
export function memberMinutes(totals: ShiftTotals, staffId: number): number {
    return totals.members.find((member) => member.staff_id === staffId)?.minutes ?? 0;
}

/** The minutes of a date across the members in the totals, closures taken off; zero where they hold none. */
export function dateMinutes(totals: ShiftTotals, date: string): number {
    return totals.days.find((day) => day.date === date)?.minutes ?? 0;
}
