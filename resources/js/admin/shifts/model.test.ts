import { describe, expect, it } from 'vitest';
import { datesOf, windowAfter, windowMinutes } from './model';

/*
 * The Shifts screen's arithmetic: the week's dates, a day's minutes from its windows and the
 * first values of a second window. None of it reads the cadence, which is the server's resolver
 * alone; a week moved by days is booking/time's addDays.
 */

describe('the week', () => {
    it('lists the dates from the first to the day before the end, across a month and a clock change', () => {
        expect(datesOf({ from: '2026-10-26', to: '2026-11-02' })).toEqual(['2026-10-26', '2026-10-27', '2026-10-28', '2026-10-29', '2026-10-30', '2026-10-31', '2026-11-01']);
    });
});

describe('a day\'s windows', () => {
    it('counts the minutes they cover, the end of the day included and a window out of order as none', () => {
        expect(windowMinutes([{ start: '09:00', end: '13:00' }, { start: '14:00', end: '18:30' }])).toBe(510);
        expect(windowMinutes([{ start: '20:00', end: '24:00' }])).toBe(240);
        expect(windowMinutes([{ start: '13:00', end: '09:00' }])).toBe(0);
        expect(windowMinutes([])).toBe(0);
    });

    it('starts a second window an hour after the first ends, an hour long, or at its end where the day has no room', () => {
        expect(windowAfter({ start: '09:00', end: '13:00' })).toEqual({ start: '14:00', end: '15:00' });
        expect(windowAfter({ start: '18:00', end: '22:30' })).toEqual({ start: '22:30', end: '22:30' });
    });
});
