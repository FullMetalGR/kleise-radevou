import { describe, expect, it } from 'vitest';
import { periodOf, periodsOf } from './periods';

/*
 * The period control's split: a day's starts grouped at 12:00 and 17:00 on the tenant's wall
 * clock into Morning, Afternoon and Evening, read in the tenant's zone and never in UTC or
 * the browser's, each start kept whole and in its order, and a period holding no start left
 * out.
 */

// Kolkata is 5:30 ahead of UTC all year, so both the hour and the minute of the wire differ
// from the wall clock: 12:00 there is 06:30Z, a UTC morning.
const KOLKATA = 'Asia/Kolkata';

const BEFORE_NOON = '2026-04-06T06:29:00Z';
const NOON = '2026-04-06T06:30:00Z';
const BEFORE_FIVE = '2026-04-06T11:29:00Z';
const FIVE = '2026-04-06T11:30:00Z';

describe('periodOf', () => {
    it('splits at 12:00 and 17:00 on the tenant wall clock', () => {
        expect(periodOf(BEFORE_NOON, KOLKATA)).toBe('Morning');
        expect(periodOf(NOON, KOLKATA)).toBe('Afternoon');
        expect(periodOf(BEFORE_FIVE, KOLKATA)).toBe('Afternoon');
        expect(periodOf(FIVE, KOLKATA)).toBe('Evening');
    });

    it('reads the tenant zone and not UTC', () => {
        expect(periodOf(NOON, 'UTC')).toBe('Morning');
        expect(periodOf(FIVE, 'UTC')).toBe('Morning');
        expect(periodOf('2026-04-06T09:00:00+00:00', 'Europe/Athens')).toBe('Afternoon');
    });

    it('puts midnight in the morning and the last minute of the day in the evening', () => {
        expect(periodOf('2026-04-05T18:30:00Z', KOLKATA)).toBe('Morning');
        expect(periodOf('2026-04-06T18:29:00Z', KOLKATA)).toBe('Evening');
    });
});

describe('periodsOf', () => {
    it('groups the starts into the three periods in order, each start whole and in its order', () => {
        const starts = [BEFORE_NOON, NOON, BEFORE_FIVE, FIVE, '2026-04-06T12:00:00.000+00:00'];

        expect(periodsOf(starts, KOLKATA)).toEqual([
            { name: 'Morning', starts: [BEFORE_NOON] },
            { name: 'Afternoon', starts: [NOON, BEFORE_FIVE] },
            { name: 'Evening', starts: [FIVE, '2026-04-06T12:00:00.000+00:00'] },
        ]);
    });

    it('leaves out a period holding no start', () => {
        expect(periodsOf([BEFORE_NOON, FIVE], KOLKATA)).toEqual([
            { name: 'Morning', starts: [BEFORE_NOON] },
            { name: 'Evening', starts: [FIVE] },
        ]);
        expect(periodsOf([NOON, BEFORE_FIVE], KOLKATA)).toEqual([{ name: 'Afternoon', starts: [NOON, BEFORE_FIVE] }]);
    });

    it('is nothing for a day with no starts', () => {
        expect(periodsOf([], KOLKATA)).toEqual([]);
    });

    it('splits the same starts differently in another zone', () => {
        expect(periodsOf([NOON, FIVE], 'UTC')).toEqual([{ name: 'Morning', starts: [NOON, FIVE] }]);
    });
});
