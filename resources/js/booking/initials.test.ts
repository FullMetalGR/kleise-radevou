import { describe, expect, it } from 'vitest';
import { initialsOf } from './initials';

describe('initialsOf', () => {
    it('reads the first letter of the first and of the last word, upper-cased, and the first alone when asked for one', () => {
        expect(initialsOf('Elena Papadaki')).toBe('EP');
        expect(initialsOf('Nikos  Georgiou')).toBe('NG');
        expect(initialsOf(' maria ')).toBe('M');
        expect(initialsOf('Luna Hair Studio', 1)).toBe('L');
        expect(initialsOf('luna', 1)).toBe('L');
    });

    it('answers nothing for an empty name and one letter of a name beyond the basic plane', () => {
        expect(initialsOf('')).toBe('');
        expect(initialsOf('   ', 1)).toBe('');
        expect(initialsOf('\u{1F600} face')).toBe('\u{1F600}F');
    });
});
