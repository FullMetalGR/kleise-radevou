import { describe, expect, it } from 'vitest';
import { clockOf, formatAmount, formatCount, formatDate, formatDayMonth, formatInstant, formatInstantDay, formatPercent, formatTimeOfDay, type TenantClock } from './format';

/*
 * Formatting. A wire timestamp converts to the tenant's zone and spells as date then time joined
 * by one plain space; a date or a time with no instant spells in the tenant's format with no
 * conversion; anything not matching the expected shape renders verbatim, and so does a value under
 * a format string outside the tokens understood; money is formatted in the row's own currency at
 * that currency's scale.
 */

const ATHENS: TenantClock = { timezone: 'Europe/Athens', dateFormat: 'd/m/Y', timeFormat: 'H:i' };

describe('an instant', () => {
    it('converts to the tenant zone and spells date then time joined by one space', () => {
        expect(formatInstant('2026-01-15T08:05:00Z', ATHENS)).toBe('15/01/2026 10:05');
        expect(formatInstant('2026-07-15T08:05:00Z', ATHENS)).toBe('15/07/2026 11:05');
    });

    it('rolls the date over with the zone, and reads an offset when the wire carries one', () => {
        expect(formatInstant('2026-01-15T22:30:00Z', ATHENS)).toBe('16/01/2026 00:30');
        expect(formatInstant('2026-01-15T22:30:00+01:00', ATHENS)).toBe('15/01/2026 23:30');
    });

    it('holds across the spring change in the tenant zone', () => {
        expect(formatInstant('2026-03-29T00:30:00Z', ATHENS)).toBe('29/03/2026 02:30');
        expect(formatInstant('2026-03-29T01:30:00Z', ATHENS)).toBe('29/03/2026 04:30');
    });

    it('renders verbatim what is not an instant, and under an unknown zone', () => {
        expect(formatInstant('2026-01-15 08:05:00', ATHENS)).toBe('2026-01-15 08:05:00');
        expect(formatInstant('2026-02-30T08:00:00Z', ATHENS)).toBe('2026-02-30T08:00:00Z');
        expect(formatInstant('soon', ATHENS)).toBe('soon');
        expect(formatInstant('2026-01-15T08:05:00Z', { ...ATHENS, timezone: 'Nowhere/Else' })).toBe('2026-01-15T08:05:00Z');
    });
});

describe('the day of an instant', () => {
    it('converts to the tenant zone and spells the day alone in the tenant date format, an unreadable value verbatim', () => {
        expect(formatInstantDay('2026-01-15T08:05:00Z', ATHENS)).toBe('15/01/2026');
        expect(formatInstantDay('2026-01-15T22:30:00Z', ATHENS)).toBe('16/01/2026');
        expect(formatInstantDay('2026-01-15T22:30:00Z', { ...ATHENS, dateFormat: 'Y-m-d' })).toBe('2026-01-16');
        expect(formatInstantDay('soon', ATHENS)).toBe('soon');
        expect(formatInstantDay('2026-01-15T08:05:00Z', { ...ATHENS, dateFormat: 'D d' })).toBe('2026-01-15T08:05:00Z');
    });
});

describe('a date with no instant', () => {
    it('spells in the tenant date format with no conversion', () => {
        expect(formatDate('2026-01-15', ATHENS)).toBe('15/01/2026');
        expect(formatDate('2026-12-31', { ...ATHENS, timezone: 'Pacific/Kiritimati' })).toBe('31/12/2026');
    });

    it('renders verbatim what is not a date', () => {
        expect(formatDate('15/01/2026', ATHENS)).toBe('15/01/2026');
        expect(formatDate('2026-02-30', ATHENS)).toBe('2026-02-30');
        expect(formatDate('', ATHENS)).toBe('');
    });
});

describe('the day and month of a date', () => {
    it('spells the tenant\'s date format with the year and its separator left out, the order kept', () => {
        expect(formatDayMonth('2026-11-02', ATHENS)).toBe('02/11');
        expect(formatDayMonth('2026-11-02', { ...ATHENS, dateFormat: 'm/d/Y' })).toBe('11/02');
        expect(formatDayMonth('2026-11-02', { ...ATHENS, dateFormat: 'Y-m-d' })).toBe('11-02');
        expect(formatDayMonth('2026-11-02', { ...ATHENS, dateFormat: 'd.m.Y' })).toBe('02.11');
    });

    it('renders verbatim what it cannot read, as the whole date does', () => {
        expect(formatDayMonth('2026-02-30', ATHENS)).toBe('2026-02-30');
        expect(formatDayMonth('2026-01-15', { ...ATHENS, dateFormat: 'D, d M Y' })).toBe('2026-01-15');
    });
});

describe('a time of day with no instant', () => {
    it('spells in the tenant time format with no conversion, seconds or none', () => {
        expect(formatTimeOfDay('09:30', ATHENS)).toBe('09:30');
        expect(formatTimeOfDay('23:05:00', { ...ATHENS, timezone: 'America/New_York' })).toBe('23:05');
    });

    it('renders verbatim what is not a time of day', () => {
        expect(formatTimeOfDay('24:00', ATHENS)).toBe('24:00');
        expect(formatTimeOfDay('9:30', ATHENS)).toBe('9:30');
        expect(formatTimeOfDay('noon', ATHENS)).toBe('noon');
    });
});

describe('the tenant formats', () => {
    it('renders the tokens d, m, Y, H and i around any punctuation', () => {
        const clock = { timezone: 'Europe/Athens', dateFormat: 'Y-m-d', timeFormat: 'H.i' };

        expect(formatDate('2026-01-05', clock)).toBe('2026-01-05');
        expect(formatTimeOfDay('07:45', clock)).toBe('07.45');
        expect(formatInstant('2026-01-05T05:45:00Z', clock)).toBe('2026-01-05 07.45');
    });

    it('renders the raw value under a format string holding a token it does not understand', () => {
        expect(formatDate('2026-01-15', { ...ATHENS, dateFormat: 'D, d M Y' })).toBe('2026-01-15');
        expect(formatTimeOfDay('14:30', { ...ATHENS, timeFormat: 'H:i T' })).toBe('14:30');
        expect(formatDate('2026-01-15', { ...ATHENS, dateFormat: '\\d d/m/Y' })).toBe('2026-01-15');
        expect(formatInstant('2026-01-15T08:05:00Z', { ...ATHENS, timeFormat: 'H:i:s' })).toBe('2026-01-15T08:05:00Z');
    });

    it('renders the twelve-hour tokens g, h, G, A and a, so a twelve-hour tenant reads its own clock', () => {
        expect(formatTimeOfDay('09:00', { ...ATHENS, timeFormat: 'g:i A' })).toBe('9:00 AM');
        expect(formatTimeOfDay('14:05', { ...ATHENS, timeFormat: 'g:i A' })).toBe('2:05 PM');
        expect(formatTimeOfDay('00:30', { ...ATHENS, timeFormat: 'h:i a' })).toBe('12:30 am');
        expect(formatTimeOfDay('12:00', { ...ATHENS, timeFormat: 'g:i a' })).toBe('12:00 pm');
        expect(formatTimeOfDay('07:15', { ...ATHENS, timeFormat: 'G.i' })).toBe('7.15');
        expect(formatInstant('2026-01-15T20:05:00Z', { ...ATHENS, timeFormat: 'g:i A' })).toBe('15/01/2026 10:05 PM');
    });

    it('reads the clock from the shared props', () => {
        expect(
            clockOf({
                tenant: { name: 'Luna', slug: 'luna', timezone: 'Europe/Athens', currency: 'EUR' },
                settings: { granularity: 5, week_start: 1, date_format: 'd/m/Y', time_format: 'H:i', colour_by: 'staff', messages: [] },
            }),
        ).toEqual(ATHENS);
    });
});

describe('money', () => {
    // The symbol and the grouping are the locale's, spelled by the booking app's formatMoney; what
    // these pin is the scale. 5000 JPY is five thousand yen with no fraction digits, never fifty.
    it('uses the currency scale, the divisor derived from the currency', () => {
        expect(formatAmount(5000, 'JPY', 'en-US')).toBe('\u00a55,000');
        expect(formatAmount(5000, 'EUR', 'en-US')).toBe('\u20ac50.00');
        expect(formatAmount(5000, 'EUR', 'el-GR')).toBe('50,00\u00a0\u20ac');
        expect(formatAmount(5000, 'KWD', 'en-US')).toBe('KWD\u00a05.000');
    });

    it('formats each row in the currency it carries', () => {
        expect(formatAmount(5000, 'JPY', 'en-US')).toContain('\u00a5');
        expect(formatAmount(5000, 'EUR', 'en-US')).toContain('\u20ac');
    });
});

describe('a count', () => {
    // A whole count in the language's own digits and grouping: a thousand and more grouped as
    // the locale groups it, never the machine's.
    it('groups a count from a thousand up as the locale given writes it', () => {
        expect(formatCount(7, 'en-GB')).toBe('7');
        expect(formatCount(999, 'el-GR')).toBe('999');
        expect(formatCount(1250, 'en-GB')).toBe('1,250');
        expect(formatCount(12500, 'el-GR')).toBe('12.500');
    });
});

describe('a percentage', () => {
    // A figure already in percent, 58.3 for 58.3 per cent, to one decimal at most in the locale
    // given.
    it('spells the figure as it is, past a hundred too, in the locale\'s glyphs', () => {
        expect(formatPercent(58.3, 'en-US')).toBe('58.3%');
        expect(formatPercent(112.5, 'en-US')).toBe('112.5%');
        expect(formatPercent(10, 'en-US')).toBe('10%');
        expect(formatPercent(58.3, 'el-GR')).toBe('58,3%');
    });

    it('carries its sign when signed, a fall to nothing said as minus nought', () => {
        expect(formatPercent(15, 'en-US', true)).toBe('+15%');
        expect(formatPercent(-33, 'en-US', true)).toBe('-33%');
        expect(formatPercent(0, 'en-US', true)).toBe('+0%');
        expect(formatPercent(-0, 'en-US', true)).toBe('-0%');
    });

    it('keeps as many decimals as it is asked for, a fee of two places as configured', () => {
        expect(formatPercent(1.25, 'en-US')).toBe('1.3%');
        expect(formatPercent(1.25, 'en-US', false, 2)).toBe('1.25%');
        expect(formatPercent(1.5, 'en-US', false, 2)).toBe('1.5%');
        expect(formatPercent(1.25, 'el-GR', false, 2)).toBe('1,25%');
    });
});
