/*
 * A calendar date as the admin spells one, YYYY-MM-DD: the machine date every admin screen keys a
 * day by, the year padded to four digits. The admin's one spelling of it; the booking app keeps
 * its own beside its calendar arithmetic, since the embed's bundle is its own.
 */

function pad(value: number, width: number): string {
    return String(value).padStart(width, '0');
}

/** The date of a year, a month counted from one, and a day of that month. */
export function dayKey(year: number, month: number, day: number): string {
    return `${pad(year, 4)}-${pad(month, 2)}-${pad(day, 2)}`;
}

/** The date a Date holds in its UTC fields, where the calendar library and the grids keep a day. */
export function utcDayOf(date: Date): string {
    return dayKey(date.getUTCFullYear(), date.getUTCMonth() + 1, date.getUTCDate());
}

/** The ISO weekday of a date YYYY-MM-DD, 1 for Monday to 7 for Sunday, read as a calendar date with no zone. */
export function isoWeekday(date: string): number {
    const [year = 0, month = 1, day = 1] = date.split('-').map(Number);

    return new Date(Date.UTC(year, month - 1, day)).getUTCDay() || 7;
}
