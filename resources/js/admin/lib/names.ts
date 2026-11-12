import { localeOf, type Language } from '../../booking/language';

/*
 * Weekday and month names from ICU: spelled by Intl.DateTimeFormat in the language given, the
 * locale localeOf() computes for it on this machine, never a list written in code and never the
 * browser's own language. Only the names come from here: the tenant's date order and its 12/24-hour
 * format stay lib/format's, and no connector word joins a name to anything.
 *
 * A day's full date (dayTitle) is ICU's full date style, the name a picker's day is read by.
 *
 * A figure of hours or minutes (unitFigure) is ICU's too, so no unit letter is glued to a
 * placeholder in a language file: in English the narrow width ("44h", "30m"), in Greek the long,
 * the unit spelled and bent by the number as the glossary spells the minutes.
 *
 * The weekdays are ISO's, 1 for Monday to 7 for Sunday, as the rules and the week start number
 * them; each is read off a day of the first week of 2024, whose first day was a Monday.
 *
 * A list of items is joined as ICU joins one in the language (listOf), "Wed and Fri" in English, so
 * no connector word is written in code.
 */

export type Width = 'short' | 'long';

/** The ISO weekdays in order, Monday first. */
export const ISO_WEEKDAYS: readonly number[] = [1, 2, 3, 4, 5, 6, 7];

const formatters = new Map<string, Intl.DateTimeFormat>();

function formatterOf(locale: string, options: Intl.DateTimeFormatOptions): Intl.DateTimeFormat {
    const key = `${locale}|${JSON.stringify(options)}`;
    let formatter = formatters.get(key);

    if (formatter === undefined) {
        formatter = new Intl.DateTimeFormat(locale, { ...options, timeZone: 'UTC' });
        formatters.set(key, formatter);
    }

    return formatter;
}

/** An ISO weekday's name in a language, short or long; a number outside one to seven is itself. */
export function weekdayName(weekday: number, width: Width, language: Language, machine?: string): string {
    if (!Number.isInteger(weekday) || weekday < 1 || weekday > 7) {
        return String(weekday);
    }

    return formatterOf(localeOf(language, machine), { weekday: width }).format(new Date(Date.UTC(2024, 0, weekday)));
}

/** A month's title in a language: its name and its year, as ICU joins them. */
export function monthTitle(year: number, month: number, language: Language, machine?: string): string {
    return formatterOf(localeOf(language, machine), { month: 'long', year: 'numeric' }).format(new Date(Date.UTC(year, month - 1, 1)));
}

/**
 * A month's name alone in a language, short as a chart's axis names a month or long as a picker's
 * month dropdown does; a number outside one to twelve is itself.
 */
export function monthName(month: number, language: Language, machine?: string, width: Width = 'short'): string {
    if (!Number.isInteger(month) || month < 1 || month > 12) {
        return String(month);
    }

    return formatterOf(localeOf(language, machine), { month: width }).format(new Date(Date.UTC(2026, month - 1, 1)));
}

/** A year alone in a language, as a picker's year dropdown names it: ICU's numeric year. */
export function yearName(year: number, language: Language, machine?: string): string {
    return formatterOf(localeOf(language, machine), { year: 'numeric' }).format(new Date(Date.UTC(year, 6, 1)));
}

/** A day's full date in a language, as a picker names its day: ICU's full date style. */
export function dayTitle(year: number, month: number, day: number, language: Language, machine?: string): string {
    return formatterOf(localeOf(language, machine), { dateStyle: 'full' }).format(new Date(Date.UTC(year, month - 1, day)));
}

export type Unit = 'hour' | 'minute';

const units = new Map<string, Intl.NumberFormat>();

/**
 * A whole figure with its unit in a language: ICU's narrow width in English, "44h", "30m", and its
 * long width in Greek, where one letter for each unit would not read as the glossary's words. The
 * formatters are cached by locale, unit and width.
 */
export function unitFigure(value: number, unit: Unit, language: Language, machine?: string): string {
    const locale = localeOf(language, machine);
    const width = language === 'el' ? 'long' : 'narrow';
    const key = `${locale}|${unit}|${width}`;
    let formatter = units.get(key);

    if (formatter === undefined) {
        formatter = new Intl.NumberFormat(locale, { style: 'unit', unit, unitDisplay: width });
        units.set(key, formatter);
    }

    return formatter.format(value);
}

const lists = new Map<string, Intl.ListFormat>();

/** Items joined as ICU joins a list in a language, "a and b" in English; no connector word is written in code. */
export function listOf(items: readonly string[], language: Language, machine?: string): string {
    const locale = localeOf(language, machine);
    let formatter = lists.get(locale);

    if (formatter === undefined) {
        formatter = new Intl.ListFormat(locale, { type: 'conjunction' });
        lists.set(locale, formatter);
    }

    return formatter.format(items);
}
