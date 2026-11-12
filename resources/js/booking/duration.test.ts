import { describe, expect, it } from 'vitest';
import { ENGLISH_WORDS, KEYED_WORDS, saidBy } from '../bookingWords';
import { DURATION_HOURS, DURATION_HOURS_MINUTES, DURATION_MINUTES, formatDuration } from './duration';
import { BOOKING_LINES_EN } from './lines.generated';
import { fill } from './placeholders';
import type { LineKey } from './words';

/*
 * A duration as every surface of the booking app spells one: the service cards, the summary card
 * and its phone bar, and the steps. Minutes under the hour, whole hours alone, and hours with
 * the minutes left over, each a whole line of booking.php read as a choice: asserted on the
 * keyed table, the shape and its numbers, and once on the English file, whose lines are read
 * from the generated module.
 */

describe('formatDuration', () => {
    it.each<[number, LineKey, number, string | null]>([
        [0, DURATION_MINUTES, 0, null],
        [5, DURATION_MINUTES, 5, null],
        [45, DURATION_MINUTES, 45, null],
        [59, DURATION_MINUTES, 59, null],
        [60, DURATION_HOURS, 1, null],
        [65, DURATION_HOURS_MINUTES, 1, '5'],
        [90, DURATION_HOURS_MINUTES, 1, '30'],
        [120, DURATION_HOURS, 2, null],
        [125, DURATION_HOURS_MINUTES, 2, '5'],
        [600, DURATION_HOURS, 10, null],
    ])('spells %i minutes as the %s line chosen by %i', (minutes, key, count, rest) => {
        expect(formatDuration(minutes, KEYED_WORDS)).toBe(saidBy(key, count, rest === null ? {} : { minutes: rest }));
    });

    it('reads the English file\'s lines, the number filled in each shape', () => {
        expect(formatDuration(45, ENGLISH_WORDS)).toBe(fill(BOOKING_LINES_EN.duration.minutes, { count: '45' }));
        expect(formatDuration(120, ENGLISH_WORDS)).toBe(fill(BOOKING_LINES_EN.duration.hours, { count: '2' }));
        expect(formatDuration(90, ENGLISH_WORDS)).toBe(fill(BOOKING_LINES_EN.duration.hours_minutes, { count: '1', minutes: '30' }));
    });
});
