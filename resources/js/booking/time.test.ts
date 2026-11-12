import { describe, expect, it } from 'vitest';
import { addDays, daysBetween, formatDay, formatDayFace, formatMoment, formatTime, parseDay, parseInstant, todayIn, wallClockOf } from './time';

/*
 * One clock: the wire's UTC value is parsed explicitly and never through the bare Date
 * constructor, formatted in the tenant's zone, and the resulting wall-clock digits are what
 * every later formatter consumes. Glyphs follow the customer's locale; the hour follows the
 * salon. Today is the tenant's today, never the browser's.
 */

describe('parseInstant', () => {
    it('reads an RFC 3339 instant spelled with an offset, with Z, or with a fraction', () => {
        expect(parseInstant('2026-04-06T07:35:00+00:00')).toBe(Date.UTC(2026, 3, 6, 7, 35, 0));
        expect(parseInstant('2026-04-06T07:35:00Z')).toBe(Date.UTC(2026, 3, 6, 7, 35, 0));
        expect(parseInstant('2026-04-06T10:35:00+03:00')).toBe(Date.UTC(2026, 3, 6, 7, 35, 0));
        expect(parseInstant('2026-04-06T02:35:00-05:00')).toBe(Date.UTC(2026, 3, 6, 7, 35, 0));
        expect(parseInstant('2026-04-06T07:35:00.250+00:00')).toBe(Date.UTC(2026, 3, 6, 7, 35, 0, 250));
    });

    it.each([
        ['the space-separated form', '2026-04-06 07:35:00+00:00'],
        ['a date alone', '2026-04-06'],
        ['no offset', '2026-04-06T07:35:00'],
        ['a thirteenth month', '2026-13-06T07:35:00+00:00'],
        ['the thirtieth of February', '2026-02-30T07:35:00+00:00'],
        ['a twenty-fifth hour', '2026-04-06T25:00:00+00:00'],
        ['a sixty-first minute', '2026-04-06T07:61:00+00:00'],
        ['an empty string', ''],
        ['words', 'tomorrow at ten'],
    ])('throws on %s rather than guessing', (_name, wire) => {
        expect(() => parseInstant(wire)).toThrow(wire === '' ? /instant/ : wire);
    });
});

describe('parseDay', () => {
    it('reads a tenant-local calendar date', () => {
        expect(parseDay('2026-04-06')).toEqual({ year: 2026, month: 4, day: 6 });
    });

    it.each(['2026-4-6', '2026-04-06T00:00:00Z', '2026-02-30', '2026-00-10', 'today', ''])('throws on %j', (key) => {
        expect(() => parseDay(key)).toThrow();
    });
});

describe('wallClockOf', () => {
    it('carries the tenant wall clock of a start: 22:30 UTC is the next calendar day in Pacific/Auckland', () => {
        expect(wallClockOf('2026-04-06T22:30:00+00:00', 'Pacific/Auckland')).toEqual({ date: '2026-04-07', year: 2026, month: 4, day: 7, hour: 10, minute: 30 });
    });

    it('follows the tenant through a clock change: 07:35 UTC is 10:35 in Athens in April and 09:35 in January', () => {
        expect(wallClockOf('2026-04-06T07:35:00+00:00', 'Europe/Athens')).toMatchObject({ date: '2026-04-06', hour: 10, minute: 35 });
        expect(wallClockOf('2026-01-06T07:35:00+00:00', 'Europe/Athens')).toMatchObject({ date: '2026-01-06', hour: 9, minute: 35 });
    });

    it('spells midnight as hour zero and stays on the day before west of UTC', () => {
        expect(wallClockOf('2026-04-07T07:00:00+00:00', 'America/Los_Angeles')).toMatchObject({ date: '2026-04-07', hour: 0, minute: 0 });
        expect(wallClockOf('2026-04-07T02:00:00+00:00', 'America/Los_Angeles')).toMatchObject({ date: '2026-04-06', hour: 19, minute: 0 });
    });

    it('never goes through the bare Date constructor: the space-separated form throws', () => {
        expect(() => wallClockOf('2026-04-06 22:30:00+00:00', 'Pacific/Auckland')).toThrow();
    });
});

describe('todayIn', () => {
    it('is the tenant today for the current instant, not the browser day', () => {
        const eveningInLondon = Date.UTC(2026, 3, 6, 22, 30);

        expect(todayIn('Pacific/Auckland', eveningInLondon)).toBe('2026-04-07');
        expect(todayIn('Europe/London', eveningInLondon)).toBe('2026-04-06');
        expect(todayIn('America/Los_Angeles', Date.UTC(2026, 3, 7, 5, 0))).toBe('2026-04-06');
    });
});

describe('addDays', () => {
    it('moves whole calendar days, across a month end, a year end and a leap day', () => {
        expect(addDays('2026-04-06', 14)).toBe('2026-04-20');
        expect(addDays('2026-04-20', 14)).toBe('2026-05-04');
        expect(addDays('2026-12-25', 14)).toBe('2027-01-08');
        expect(addDays('2028-02-20', 10)).toBe('2028-03-01');
        expect(addDays('2026-04-20', -14)).toBe('2026-04-06');
        expect(addDays('2026-04-06', 0)).toBe('2026-04-06');
    });
});

describe('daysBetween', () => {
    it('counts whole calendar days from one date to another, across a month end, a year end and a leap day, negative backwards', () => {
        expect(daysBetween('2026-04-06', '2026-04-06')).toBe(0);
        expect(daysBetween('2026-04-06', '2026-04-20')).toBe(14);
        expect(daysBetween('2026-12-25', '2027-01-08')).toBe(14);
        expect(daysBetween('2028-02-20', '2028-03-01')).toBe(10);
        expect(daysBetween('2026-04-20', '2026-04-06')).toBe(-14);
        expect(daysBetween('2026-03-28', '2026-03-30')).toBe(2);
    });
});

describe('the formatters', () => {
    const start = wallClockOf('2026-04-06T22:30:00+00:00', 'Pacific/Auckland');

    it('render the tenant hour in the customer locale: 10:30 in Auckland reads as 10:30 in every locale', () => {
        expect(formatTime(start, 'en-US')).toBe('10:30 AM');
        expect(formatTime(start, 'en-GB')).toBe('10:30');
        expect(formatTime(start, 'de-DE')).toBe('10:30');
        expect(formatTime(start, 'ar-EG')).toContain('\u0661\u0660:\u0663\u0660');
    });

    it('render the digits carried and nothing reinterpreted through a zone: 23:55 stays 23:55', () => {
        const late = wallClockOf('2026-04-06T20:55:00+00:00', 'Europe/Athens');

        expect(late).toMatchObject({ date: '2026-04-06', hour: 23, minute: 55 });
        expect(formatTime(late, 'en-GB')).toBe('23:55');
    });

    it('render the tenant hour whole inside the browser zone spring-forward gap', () => {
        // New York skips 02:00 to 03:00 on 2026-03-08; Athens does not change until the 29th.
        const zone = process.env['TZ'];
        process.env['TZ'] = 'America/New_York';

        try {
            expect(new Date(2026, 2, 8, 2, 30).getHours()).toBe(3);
            expect(formatTime(wallClockOf('2026-03-08T00:30:00+00:00', 'Europe/Athens'), 'en-GB')).toBe('2:30');
            expect(formatDay('2026-03-08', 'en-US')).toBe('Sun, Mar 8');
            expect(formatDayFace('2026-03-08', 'en-US')).toEqual({ weekday: 'Sun', date: '8' });
        } finally {
            if (zone === undefined) {
                delete process.env['TZ'];
            } else {
                process.env['TZ'] = zone;
            }
        }
    });

    it('render a day label with the weekday, the day and the month in the customer locale', () => {
        expect(formatDay('2026-04-07', 'en-US')).toBe('Tue, Apr 7');
        expect(formatDay('2026-04-07', 'en-GB')).toBe('Tue 7 Apr');
        expect(formatDay('2026-04-07', 'de-DE')).toMatch(/^Di\.?,? 7\.? Apr\.?$/);
        expect(formatDay('2026-04-07', 'el-GR')).toContain('7');
    });

    it('render a day face on the week row, the short weekday and the day of the month apart, in the customer locale', () => {
        expect(formatDayFace('2026-04-07', 'en-US')).toEqual({ weekday: 'Tue', date: '7' });
        expect(formatDayFace('2026-04-07', 'de-DE')).toEqual({ weekday: expect.stringMatching(/^Di\.?$/) as string, date: '7' });
        expect(formatDayFace('2026-04-30', 'ar-EG').date).toBe('\u0663\u0660');
        expect(formatDayFace('2026-03-01', 'en-GB')).toEqual({ weekday: 'Sun', date: '1' });
    });

    it('render a moment, the day label and the hour in one, joined as the locale joins them, which in English is the comma the two were once joined by', () => {
        expect(formatMoment(start, 'en-US')).toBe('Tue, Apr 7, 10:30 AM');
        expect(formatMoment(start, 'en-GB')).toBe('Tue 7 Apr, 10:30');

        for (const locale of ['en-US', 'en-GB', 'en']) {
            expect(formatMoment(start, locale)).toBe(`${formatDay(start.date, locale)}, ${formatTime(start, locale)}`);
        }

        expect(formatMoment(start, 'el-GR')).toContain('7');
    });

    it('use the machine locale when given none', () => {
        expect(formatMoment(start)).toBe(formatMoment(start, Intl.DateTimeFormat().resolvedOptions().locale));
        expect(formatTime(start)).toBe(formatTime(start, Intl.DateTimeFormat().resolvedOptions().locale));
        expect(formatDay('2026-04-07')).toBe(formatDay('2026-04-07', Intl.DateTimeFormat().resolvedOptions().locale));
        expect(formatDayFace('2026-04-07')).toEqual(formatDayFace('2026-04-07', Intl.DateTimeFormat().resolvedOptions().locale));
    });
});
