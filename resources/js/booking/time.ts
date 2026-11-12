/*
 * One clock. Every time renders in the tenant's zone, unconditionally, and the customer's browser
 * clock is read only to establish the current instant. The mechanism: the wire's UTC value is
 * parsed explicitly into a real instant, never through the bare Date constructor, whose grammar
 * is implementation-defined for anything but the one canonical form; that instant is formatted
 * once in the tenant's zone; and the resulting wall-clock digits are carried, so that every later
 * formatter consumes exactly those digits. Glyphs follow the customer's locale, the hour follows
 * the salon.
 *
 * The carrier every later formatter reads the digits back through is a fixed zone with no
 * clock changes, UTC, rather than the browser's own: a Date built from wall-clock digits in
 * the browser's zone lands one hour off inside that zone's spring-forward gap, and a customer
 * in a zone changing its clocks on a day the tenant's does not would see a shifted hour. The
 * digits go in through Date.UTC and come out under timeZone UTC, which is the same digits in
 * every browser on every day, and is what "consumes exactly those digits" means here.
 *
 * "Today" is the tenant's today: the current instant formatted in the tenant's zone, never
 * the browser's date.
 *
 * The booking app hands every renderer the locale of the page's language (localeOf in
 * language.ts), so the names of days and months are the page's while the order and the hour cycle
 * stay the machine's; a caller that gives none, as the admin's do, gets the machine's locale. The
 * resolver keeps its fixed en-US, since it reads digits, not names.
 */

/**
 * A tenant wall-clock reading: the calendar date as the availability response keys it, and
 * the digits every formatter consumes.
 */
export interface WallClock {
    readonly date: string;
    readonly year: number;
    readonly month: number;
    readonly day: number;
    readonly hour: number;
    readonly minute: number;
}

export interface CalendarDate {
    readonly year: number;
    readonly month: number;
    readonly day: number;
}

const INSTANT = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(?:\.(\d+))?(Z|[+-]\d{2}:\d{2})$/;

const DAY = /^(\d{4})-(\d{2})-(\d{2})$/;

const MINUTE_MS = 60 * 1000;

const DAY_MS = 24 * 60 * MINUTE_MS;

/**
 * An RFC 3339 instant as epoch milliseconds. Throws on anything else, the space-separated
 * form included, rather than guessing; a field the calendar does not hold, such as the
 * thirtieth of February or a twenty-fifth hour, is refused rather than carried over.
 */
export function parseInstant(wire: string): number {
    const match = INSTANT.exec(wire);

    if (match === null) {
        throw new Error(`"${wire}" is not an RFC 3339 instant.`);
    }

    const [year, month, day, hour, minute, second] = [1, 2, 3, 4, 5, 6].map((index) => Number(match[index]));
    const fraction = match[7];
    const millisecond = fraction === undefined ? 0 : Number(fraction.padEnd(3, '0').slice(0, 3));
    const utc = Date.UTC(year as number, (month as number) - 1, day as number, hour as number, minute as number, second as number, millisecond);
    const at = new Date(utc);

    if (at.getUTCFullYear() !== year || at.getUTCMonth() !== (month as number) - 1 || at.getUTCDate() !== day || at.getUTCHours() !== hour || at.getUTCMinutes() !== minute || at.getUTCSeconds() !== second) {
        throw new Error(`"${wire}" is not an instant the calendar holds.`);
    }

    return utc - offsetMinutesOf(match[8] ?? 'Z') * MINUTE_MS;
}

function offsetMinutesOf(zone: string): number {
    if (zone === 'Z') {
        return 0;
    }

    const sign = zone.startsWith('-') ? -1 : 1;
    const hours = Number(zone.slice(1, 3));
    const minutes = Number(zone.slice(4, 6));

    return sign * (hours * 60 + minutes);
}

/**
 * A tenant-local calendar date as the availability response keys it, YYYY-MM-DD. Throws on
 * anything else.
 */
export function parseDay(key: string): CalendarDate {
    const match = DAY.exec(key);

    if (match === null) {
        throw new Error(`"${key}" is not a calendar date spelled YYYY-MM-DD.`);
    }

    const year = Number(match[1]);
    const month = Number(match[2]);
    const day = Number(match[3]);
    const at = new Date(Date.UTC(year, month - 1, day));

    if (at.getUTCFullYear() !== year || at.getUTCMonth() !== month - 1 || at.getUTCDate() !== day) {
        throw new Error(`"${key}" is not a date the calendar holds.`);
    }

    return { year, month, day };
}

/**
 * The formatter that resolves an instant to the tenant's wall clock, one per zone, because
 * building one is the expensive part and a day of slots asks a few hundred times.
 */
const resolvers = new Map<string, Intl.DateTimeFormat>();

function resolverFor(timezone: string): Intl.DateTimeFormat {
    let resolver = resolvers.get(timezone);

    if (resolver === undefined) {
        resolver = new Intl.DateTimeFormat('en-US', {
            timeZone: timezone,
            hourCycle: 'h23',
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
        });
        resolvers.set(timezone, resolver);
    }

    return resolver;
}

function wallClockAt(instant: number, timezone: string): WallClock {
    const parts = resolverFor(timezone).formatToParts(new Date(instant));
    const digit = (type: Intl.DateTimeFormatPartTypes): number => Number(parts.find((part) => part.type === type)?.value ?? 0);
    const year = digit('year');
    const month = digit('month');
    const day = digit('day');

    return { date: dayKey(year, month, day), year, month, day, hour: digit('hour'), minute: digit('minute') };
}

/**
 * A calendar date spelled as the availability answer keys it, YYYY-MM-DD.
 */
function dayKey(year: number, month: number, day: number): string {
    return `${String(year).padStart(4, '0')}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
}

/**
 * The tenant wall clock of a start as the wire spells it.
 */
export function wallClockOf(wire: string, timezone: string): WallClock {
    return wallClockAt(parseInstant(wire), timezone);
}

/**
 * The tenant's today for an instant: the current one, read from the browser clock by the
 * caller, formatted in the tenant's zone.
 */
export function todayIn(timezone: string, now: number): string {
    return wallClockAt(now, timezone).date;
}

/**
 * A calendar date some whole days on, or back. Pure calendar arithmetic over UTC, where no
 * day is shorter or longer than any other.
 */
export function addDays(day: string, days: number): string {
    const { year, month, day: date } = parseDay(day);
    const moved = new Date(Date.UTC(year, month - 1, date + days));

    return dayKey(moved.getUTCFullYear(), moved.getUTCMonth() + 1, moved.getUTCDate());
}

/**
 * How many whole calendar days lie from one date to another, negative when the second is
 * earlier. Pure calendar arithmetic over UTC, as addDays is.
 */
export function daysBetween(from: string, to: string): number {
    return Math.round((atMidnight(to).getTime() - atMidnight(from).getTime()) / DAY_MS);
}

/**
 * The shapes carried digits render in: the hour, a day label, a day's face on the week row, its
 * short weekday over its day of the month, and a moment, the day label and the hour in one,
 * joined as the locale joins them.
 */
const SHAPES = {
    time: { timeZone: 'UTC', hour: 'numeric', minute: '2-digit' },
    day: { timeZone: 'UTC', weekday: 'short', day: 'numeric', month: 'short' },
    face: { timeZone: 'UTC', weekday: 'short', day: 'numeric' },
    moment: { timeZone: 'UTC', weekday: 'short', day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' },
} as const satisfies Record<string, Intl.DateTimeFormatOptions>;

/**
 * The formatters that render carried digits, one per locale and shape.
 */
const renderers = new Map<string, Intl.DateTimeFormat>();

function rendererFor(kind: keyof typeof SHAPES, locale: string | undefined): Intl.DateTimeFormat {
    const id = `${kind}:${locale ?? ''}`;
    let renderer = renderers.get(id);

    if (renderer === undefined) {
        renderer = new Intl.DateTimeFormat(locale, SHAPES[kind]);
        renderers.set(id, renderer);
    }

    return renderer;
}

/**
 * The hour and minute carried, in the machine's locale when no locale is given.
 */
export function formatTime(wall: WallClock, locale?: string): string {
    return rendererFor('time', locale).format(new Date(Date.UTC(wall.year, wall.month - 1, wall.day, wall.hour, wall.minute)));
}

/**
 * The day and the hour carried, the day label's shape and the time's in one formatter, so the
 * locale joins them in its own way and no connector is spelled here, in the machine's locale
 * when no locale is given.
 */
export function formatMoment(wall: WallClock, locale?: string): string {
    return rendererFor('moment', locale).format(new Date(Date.UTC(wall.year, wall.month - 1, wall.day, wall.hour, wall.minute)));
}

/**
 * A day label for a tenant-local date: the weekday, the day and the month, in the machine's
 * locale when no locale is given.
 */
export function formatDay(day: string, locale?: string): string {
    return rendererFor('day', locale).format(atMidnight(day));
}

/**
 * A day's face on the week row: the short weekday and the day of the month of a tenant-local
 * date, each as the locale spells it, in the machine's locale when no locale is given.
 */
export function formatDayFace(day: string, locale?: string): { readonly weekday: string; readonly date: string } {
    const parts = rendererFor('face', locale).formatToParts(atMidnight(day));
    const part = (type: Intl.DateTimeFormatPartTypes): string => parts.find((candidate) => candidate.type === type)?.value ?? '';

    return { weekday: part('weekday'), date: part('day') };
}

/**
 * A tenant-local date carried as its digits at midnight in the fixed zone every renderer reads.
 */
function atMidnight(day: string): Date {
    const { year, month, day: date } = parseDay(day);

    return new Date(Date.UTC(year, month - 1, date));
}
