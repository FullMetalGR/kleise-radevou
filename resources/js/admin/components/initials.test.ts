import { describe, expect, it } from 'vitest';
import { initialsOf } from './initials';

/*
 * A member's initials, which the Calendar's heads and Who rows and the Shifts rows ring in the
 * member's hue.
 */

describe('initials', () => {
    it('are the first letters of the first and last words, upper-cased', () => {
        expect(initialsOf('Elena Papadaki')).toBe('EP');
        expect(initialsOf('  maria  ')).toBe('M');
        expect(initialsOf('Anna Maria Louka')).toBe('AL');
        expect(initialsOf('')).toBe('');
    });
});
