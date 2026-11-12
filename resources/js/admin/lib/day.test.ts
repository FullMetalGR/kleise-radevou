import { describe, expect, it } from 'vitest';
import { dayKey, isoWeekday, utcDayOf } from './day';

/*
 * The admin's one day key: the calendar's model, the Month grid and the date field's day calendar
 * all spell a day through it; and the one reading of a day's ISO weekday.
 */

describe('the day key', () => {
    it('spells a date YYYY-MM-DD, the year padded to four digits and the month counted from one', () => {
        expect(dayKey(2026, 11, 3)).toBe('2026-11-03');
        expect(dayKey(1, 1, 1)).toBe('0001-01-01');
        expect(dayKey(9999, 12, 31)).toBe('9999-12-31');
    });

    it('reads the day a Date holds in its UTC fields, whatever the hour', () => {
        expect(utcDayOf(new Date(Date.UTC(2026, 10, 3, 23, 59)))).toBe('2026-11-03');
        expect(utcDayOf(new Date(Date.UTC(2026, 0, 1, 0, 0)))).toBe('2026-01-01');
    });
});

describe('the ISO weekday', () => {
    it('reads Monday as one to Sunday as seven, with no zone to move the day', () => {
        expect(isoWeekday('2026-12-07')).toBe(1);
        expect(isoWeekday('2026-12-09')).toBe(3);
        expect(isoWeekday('2026-12-13')).toBe(7);
        expect(isoWeekday('2026-03-29')).toBe(7);
        expect(isoWeekday('2024-02-29')).toBe(4);
    });
});
