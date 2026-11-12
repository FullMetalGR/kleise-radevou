import { describe, expect, it } from 'vitest';
import { assertAscii, escapeKnownNonAscii, escapeNonAscii, isGreek, KNOWN_NON_ASCII, type Output } from './vite.shared.ts';

/*
 * The admin build's escape, vite.admin.config.ts: each character the calendar grid's library is
 * known to carry becomes an ASCII escape that reads back as the character, an astral character
 * becomes its surrogate pair, a chunk carrying any other non-ASCII character is refused by name
 * before the assertion runs, and an asset is left for the assertion to refuse. The embed build's
 * escape (vite.config.ts) is the same pass with the Greek and Coptic block as its known
 * characters.
 */

function chunk(code: string, fileName = 'app.js'): Output {
    return { [fileName]: { type: 'chunk', fileName, code } };
}

describe('the admin build escape', () => {
    it('spells each known character as an ASCII escape that reads back as the character', () => {
        expect(KNOWN_NON_ASCII).toEqual(['\u00a0', '\u200e', '\u2013']);

        for (const character of KNOWN_NON_ASCII) {
            const escaped = escapeNonAscii(`"${character}"`);

            expect(escaped).toMatch(/^"\\u[0-9a-f]{4}"$/);
            expect(JSON.parse(escaped)).toBe(character);
        }
    });

    it('spells an astral character as its surrogate pair and leaves ASCII alone', () => {
        const escaped = escapeNonAscii('"a\u{1F600}b"');

        expect(escaped).toBe('"a\\ud83d\\ude00b"');
        expect(JSON.parse(escaped)).toBe('a\u{1F600}b');
        expect(escapeNonAscii('plain ascii \\u00a0')).toBe('plain ascii \\u00a0');
    });

    it('rewrites a chunk carrying the known characters so the assertion passes it', () => {
        const bundle = chunk('a\u00a0b\u200ec\u2013d');

        escapeKnownNonAscii(bundle);

        expect(bundle['app.js']?.type === 'chunk' ? bundle['app.js'].code : null).toBe('a\\u00a0b\\u200ec\\u2013d');
        expect(() => assertAscii(bundle)).not.toThrow();
    });

    it('refuses by name a chunk carrying any other non-ASCII character, before the assertion', () => {
        const bundle = chunk('caf\u00e9\u200e', 'other.js');

        expect(() => escapeKnownNonAscii(bundle)).toThrow('other.js carries U+00E9 at offset 3, which no dependency is known to spell');
        expect(bundle['other.js']?.type === 'chunk' ? bundle['other.js'].code : null).toBe('caf\u00e9\u200e');
        expect(() => escapeKnownNonAscii(chunk('\u{1F600}', 'astral.js'))).toThrow('astral.js carries U+1F600 at offset 0');
    });

    it('leaves an asset alone for the assertion to refuse by name', () => {
        const bundle: Output = { 'app.css': { type: 'asset', fileName: 'app.css', source: 'a\u2013b' } };

        escapeKnownNonAscii(bundle);

        expect(bundle['app.css']?.type === 'asset' ? bundle['app.css'].source : null).toBe('a\u2013b');
        expect(() => assertAscii(bundle)).toThrow('app.css carries U+2013 at offset 1');
    });
});

describe('the embed build escape', () => {
    it('knows the Greek and Coptic block and nothing past it', () => {
        expect(['\u0370', '\u0386', '\u03ac', '\u03c9', '\u03ff'].every(isGreek)).toBe(true);
        expect(['a', '\u036f', '\u0400', '\u00e9', '\u00ab', '\u1f00', '\u0391\u0392'].some(isGreek)).toBe(false);
    });

    it('rewrites a chunk carrying Greek letters as escapes that read back as the letters, so the assertion passes it', () => {
        const greek = '\u0395\u03bb\u03bb\u03b7\u03bd\u03b9\u03ba\u03ac';
        const bundle = chunk(`var a="${greek}";`, 'embed.js');

        escapeKnownNonAscii(bundle, isGreek);

        const code = bundle['embed.js']?.type === 'chunk' ? bundle['embed.js'].code : '';

        expect(code).toBe('var a="\\u0395\\u03bb\\u03bb\\u03b7\\u03bd\\u03b9\\u03ba\\u03ac";');
        expect(JSON.parse(code.slice(6, -1))).toBe(greek);
        expect(() => assertAscii(bundle)).not.toThrow();
    });

    it('refuses by name a chunk carrying a character outside the block beside Greek letters', () => {
        const bundle = chunk('"\u03b1\u00ab"', 'embed.js');

        expect(() => escapeKnownNonAscii(bundle, isGreek)).toThrow('embed.js carries U+00AB at offset 2');
        expect(bundle['embed.js']?.type === 'chunk' ? bundle['embed.js'].code : null).toBe('"\u03b1\u00ab"');
    });
});
