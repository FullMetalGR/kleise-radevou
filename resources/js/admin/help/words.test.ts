import { describe, expect, expectTypeOf, it } from 'vitest';
import { ADMIN_LINES_EN } from '../lines.generated';
import { helpWordsOf, type HelpStrings } from './words';

/*
 * The panel's words: their type is the help section of lang/en/admin key for key, so the two
 * cannot part, and they are read by key in the language they came in.
 */

/** A table's shape: every line a string, every group its own shape, nothing else. */
type Shape<T> = { -readonly [K in keyof T]: T[K] extends string ? string : Shape<T[K]> };

describe('the help panel\'s words', () => {
    it('are the keys of lang/en/admin/help.php, no more and no fewer', () => {
        expectTypeOf<Shape<typeof ADMIN_LINES_EN.help>>().toEqualTypeOf<HelpStrings>();
    });

    it('are read by key, a count choosing its line', () => {
        const words = helpWordsOf(ADMIN_LINES_EN.help, 'en');

        expect(words.text('tabs.tickets')).toBe(ADMIN_LINES_EN.help.tabs.tickets);
        expect(words.choice('search.found', 1)).toBe('1 answer matches.');
        expect(words.choice('search.found', 3)).toBe('3 answers match.');
    });
});
