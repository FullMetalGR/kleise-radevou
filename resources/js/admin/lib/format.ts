import { formatMoney } from '../../booking/money';
import { parseDay, wallClockOf } from '../../booking/time';
import type { SharedProps } from '../types';

/*
 * Formatting, identically on every screen: one clock, the tenant's.
 *
 * - A wire timestamp converts to the tenant's zone and spells as date then time joined by one
 *   plain space, no connector word, or as the date alone where only its day is wanted.
 * - A date with no instant spells in the tenant's date format, and a time of day with no instant
 *   in the tenant's time format, neither converted.
 * - Anything not matching the expected shape renders verbatim: a wrong guess reads as a bug, the
 *   raw value reads as data.
 * - Money uses the row's own currency and that currency's scale.
 *
 * The tenant's formats are PHP date tokens fixed at provisioning, d/m/Y and H:i, and nothing
 * offers a way to change them yet, so the tokens those two use are the ones understood, plus the
 * twelve-hour ones, g, h, G, A and a, because the time field's type-ahead is specified for a
 * twelve-hour tenant. A format holding any other letter, or an escape, is one this does not
 * understand, and the value renders raw rather than half-spelled. Everything else in a format is
 * punctuation and is kept.
 *
 * The rules the booking app already spells are spent from there, not written twice: the instant
 * parse and the tenant-zone wall clock from its time module, the currency scale from its money
 * module. The admin imports them; the booking bundle imports nothing back.
 */

export type TenantClock = {
    readonly timezone: string;
    readonly dateFormat: string;
    readonly timeFormat: string;
};

export function clockOf(props: Pick<SharedProps, 'tenant' | 'settings'>): TenantClock {
    return { timezone: props.tenant.timezone, dateFormat: props.settings.date_format, timeFormat: props.settings.time_format };
}

type Digits = { year: number; month: number; day: number; hour: number; minute: number };

const DATE_TOKENS: Record<string, (at: Digits) => string> = {
    d: (at) => pad(at.day, 2),
    m: (at) => pad(at.month, 2),
    Y: (at) => pad(at.year, 4),
};

const TIME_TOKENS: Record<string, (at: Digits) => string> = {
    H: (at) => pad(at.hour, 2),
    G: (at) => String(at.hour),
    h: (at) => pad(twelve(at.hour), 2),
    g: (at) => String(twelve(at.hour)),
    A: (at) => (at.hour < 12 ? 'AM' : 'PM'),
    a: (at) => (at.hour < 12 ? 'am' : 'pm'),
    i: (at) => pad(at.minute, 2),
};

/** The hour on a twelve-hour dial: midnight and noon both read twelve. */
function twelve(hour: number): number {
    return hour % 12 === 0 ? 12 : hour % 12;
}

const TIME_OF_DAY = /^([01]\d|2[0-3]):([0-5]\d)(?::[0-5]\d)?$/;

function pad(value: number, width: number): string {
    return String(value).padStart(width, '0');
}

/**
 * The format spelled over the digits, or null when it holds a letter outside the tokens given or
 * an escape: a format this does not understand.
 */
function spell(format: string, tokens: Record<string, (at: Digits) => string>, at: Digits): string | null {
    let out = '';

    for (const character of format) {
        const token = Object.hasOwn(tokens, character) ? tokens[character] : undefined;

        if (token !== undefined) {
            out += token(at);
        } else if (/[A-Za-z\\]/.test(character)) {
            return null;
        } else {
            out += character;
        }
    }

    return out;
}

/** A wire timestamp's digits on the tenant's wall clock, or null where it is no instant. */
function wallDigitsOf(wire: string, clock: TenantClock): Digits | null {
    try {
        return wallClockOf(wire, clock.timezone);
    } catch {
        return null;
    }
}

export function formatInstant(wire: string, clock: TenantClock): string {
    const at = wallDigitsOf(wire, clock);

    if (at === null) {
        return wire;
    }

    const date = spell(clock.dateFormat, DATE_TOKENS, at);
    const time = spell(clock.timeFormat, TIME_TOKENS, at);

    return date === null || time === null ? wire : `${date} ${time}`;
}

/**
 * The day of a wire timestamp in the tenant's zone, spelled in the tenant's date format with no
 * time: a customer's last visit as its tile reads it.
 */
export function formatInstantDay(wire: string, clock: TenantClock): string {
    const at = wallDigitsOf(wire, clock);

    return at === null ? wire : (spell(clock.dateFormat, DATE_TOKENS, at) ?? wire);
}

export function formatDate(value: string, clock: TenantClock): string {
    let at: Digits;

    try {
        at = { ...parseDay(value), hour: 0, minute: 0 };
    } catch {
        return value;
    }

    return spell(clock.dateFormat, DATE_TOKENS, at) ?? value;
}

/**
 * A date's day and month in the tenant's date format, the year and the separator beside it left
 * out (d/m/Y spells 02/11, Y-m-d 11-02): a week's column heads, where the range names the year.
 */
export function formatDayMonth(value: string, clock: TenantClock): string {
    const format = clock.dateFormat.replace(/[^A-Za-z\\]*Y$/, '').replace(/^Y[^A-Za-z\\]*/, '');

    return formatDate(value, { ...clock, dateFormat: format });
}

export function formatTimeOfDay(value: string, clock: TenantClock): string {
    const match = TIME_OF_DAY.exec(value);

    if (match === null) {
        return value;
    }

    const at = { year: 0, month: 0, day: 0, hour: Number(match[1]), minute: Number(match[2]) };

    return spell(clock.timeFormat, TIME_TOKENS, at) ?? value;
}

/**
 * An amount of minor units in the row's own currency at that currency's scale, the glyphs in the
 * viewer's locale unless one is given.
 */
export function formatAmount(minor: number, currency: string, locale?: string): string {
    return formatMoney(minor, currency, locale);
}

/**
 * A whole count in the locale given, its digits and its grouping the language's: every count a
 * user reads on Home and Reports, a thousand and more grouped.
 */
export function formatCount(count: number, locale: string): string {
    return new Intl.NumberFormat(locale, { maximumFractionDigits: 0 }).format(count);
}

/**
 * A figure already in percent (58.3 for 58.3 per cent) in the locale given, to one decimal at
 * most unless more are asked for, a figure past a hundred as it is. Signed, it carries its sign
 * either way, a negative nought read as minus, so a fall the server rounded to nought still reads
 * as a fall. The platform's fee asks for two, its percentage configured in basis points.
 */
export function formatPercent(percent: number, locale: string, signed = false, decimals = 1): string {
    return new Intl.NumberFormat(locale, { style: 'percent', maximumFractionDigits: decimals, signDisplay: signed ? 'always' : 'auto' }).format(percent / 100);
}
