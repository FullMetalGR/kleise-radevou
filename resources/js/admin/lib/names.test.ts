import { describe, expect, it } from 'vitest';
import { localeOf } from '../../booking/language';
import { ISO_WEEKDAYS, dayTitle, listOf, monthName, monthTitle, unitFigure, weekdayName } from './names';

/*
 * The weekday and month names from ICU: each held to Intl's own output in the language,
 * never to a literal, in English and in Greek, and the two apart.
 */

/** Intl's spelling of a UTC date in a language. */
function intl(language: 'en' | 'el', options: Intl.DateTimeFormatOptions, date: string): string {
    return new Intl.DateTimeFormat(localeOf(language), { ...options, timeZone: 'UTC' }).format(new Date(`${date}T00:00:00Z`));
}

describe('the names', () => {
    it.each(['en', 'el'] as const)('spell each ISO weekday short and long by ICU in %s, Monday first', (language) => {
        expect(ISO_WEEKDAYS.map((weekday) => weekdayName(weekday, 'long', language))).toEqual(['2024-01-01', '2024-01-02', '2024-01-03', '2024-01-04', '2024-01-05', '2024-01-06', '2024-01-07'].map((day) => intl(language, { weekday: 'long' }, day)));
        expect(weekdayName(1, 'short', language)).toBe(intl(language, { weekday: 'short' }, '2026-11-02'));
        expect(weekdayName(7, 'short', language)).toBe(intl(language, { weekday: 'short' }, '2026-11-08'));
    });

    it.each(['en', 'el'] as const)('spell a month\'s title, its name and year, by ICU in %s', (language) => {
        expect(monthTitle(2026, 11, language)).toBe(intl(language, { month: 'long', year: 'numeric' }, '2026-11-01'));
        expect(monthTitle(2027, 1, language)).toBe(intl(language, { month: 'long', year: 'numeric' }, '2027-01-01'));
    });

    it.each(['en', 'el'] as const)('spell a day\'s full date, its weekday, day, month and year, by ICU in %s', (language) => {
        expect(dayTitle(2026, 11, 3, language)).toBe(intl(language, { dateStyle: 'full' }, '2026-11-03'));
        expect(dayTitle(2020, 2, 29, language)).toBe(intl(language, { dateStyle: 'full' }, '2020-02-29'));
    });

    it.each(['en', 'el'] as const)('spell a month\'s short name alone by ICU in %s, a number outside one to twelve as itself', (language) => {
        expect(monthName(10, language)).toBe(intl(language, { month: 'short' }, '2026-10-01'));
        expect(monthName(1, language)).toBe(intl(language, { month: 'short' }, '2026-01-01'));
        expect(monthName(13, language)).toBe('13');
    });

    it('name the two languages apart', () => {
        expect(weekdayName(1, 'long', 'el')).not.toBe(weekdayName(1, 'long', 'en'));
        expect(monthTitle(2026, 11, 'el')).not.toBe(monthTitle(2026, 11, 'en'));
        expect(dayTitle(2026, 11, 3, 'el')).not.toBe(dayTitle(2026, 11, 3, 'en'));
    });

    it('answer a weekday outside one to seven with the number itself', () => {
        expect(weekdayName(8, 'long', 'en')).toBe('8');
        expect(weekdayName(0, 'short', 'el')).toBe('0');
    });

    it.each(['en', 'el'] as const)('join a list as ICU joins one in %s, a lone item itself', (language) => {
        expect(listOf(['Wed 14:00-16:00', 'Fri'], language)).toBe(new Intl.ListFormat(localeOf(language), { type: 'conjunction' }).format(['Wed 14:00-16:00', 'Fri']));
        expect(listOf(['Fri'], language)).toBe('Fri');
    });

    it.each([
        ['en', 'narrow'],
        ['el', 'long'],
    ] as const)('spell a figure of hours and of minutes in %s in ICU\'s %s width', (language, width) => {
        const icu = (value: number, unit: 'hour' | 'minute', display: 'narrow' | 'long') => new Intl.NumberFormat(localeOf(language), { style: 'unit', unit, unitDisplay: display }).format(value);

        for (const value of [1, 2, 44]) {
            expect(unitFigure(value, 'hour', language)).toBe(icu(value, 'hour', width));
            expect(unitFigure(value, 'minute', language)).toBe(icu(value, 'minute', width));
        }

        expect(icu(2, 'minute', 'long')).not.toBe(icu(2, 'minute', 'narrow'));
    });
});
