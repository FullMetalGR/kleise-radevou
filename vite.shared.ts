import { readdirSync, rmSync } from 'node:fs';
import { join } from 'node:path';

/*
 * What the two builds share. The embed and the admin are two Vite builds rather than two entries
 * of one: the embed is an IIFE measured by the size gate and the admin is an ES module app with a
 * manifest, and neither may touch the other's output.
 */

export const BUILD_DIR = 'public/build';

export const ADMIN_BUILD_DIR = `${BUILD_DIR}/admin`;

/** What the assertion and the escape read of a build's output; Rolldown's OutputBundle is one. */
export type Output = Record<string, { type: 'chunk'; fileName: string; code: string } | { type: 'asset'; fileName: string; source: string | Uint8Array }>;

const NON_ASCII = /[^\x00-\x7F]/;

/** A code point spelled the way a character table does, U+XXXX. */
function nameOf(character: string): string {
    return `U+${(character.codePointAt(0) ?? 0).toString(16).toUpperCase().padStart(4, '0')}`;
}

/**
 * Refuses non-ASCII output, whichever build wrote it. Every file in this repository is ASCII, the
 * build output included, and the minifier has no switch for it, so a dependency that brought a
 * non-ASCII byte in fails here, by name, rather than slipping into a served file.
 */
export function assertAscii(bundle: Output): void {
    for (const item of Object.values(bundle)) {
        const text = item.type === 'chunk' ? item.code : item.source;
        const offending = typeof text === 'string' ? NON_ASCII.exec(text) : null;

        if (offending !== null) {
            throw new Error(`${item.fileName} carries ${nameOf(offending[0])} at offset ${offending.index}; every file here is ASCII.`);
        }
    }
}

/**
 * The non-ASCII characters the calendar grid's library spells as \uXXXX in its source, which the
 * minifier writes out literally: the no-break space, the left-to-right mark and the en dash.
 * The admin build rewrites these three back to escapes and refuses every other, by name.
 */
export const KNOWN_NON_ASCII: readonly string[] = ['\u00a0', '\u200e', '\u2013'];

/**
 * The characters the embed build escapes: the Greek and Coptic block, where every letter of
 * lang/el/booking.php sits. The generated module spells them as \uXXXX, which the minifier writes
 * out literally, so the embed build rewrites them back to escapes and refuses every other
 * character, by name.
 */
export function isGreek(character: string): boolean {
    return /^[\u0370-\u03ff]$/.test(character);
}

/**
 * A JavaScript text with every non-ASCII character spelled as its \uXXXX escape, one per UTF-16
 * code unit, so an astral character becomes its surrogate pair. A literal in a string, a template
 * or a regular expression reads the same either way, and an identifier admits the escape too.
 */
export function escapeNonAscii(text: string): string {
    return text.replace(/[^\x00-\x7F]/g, (unit) => `\\u${unit.charCodeAt(0).toString(16).padStart(4, '0')}`);
}

/**
 * Rewrites the known non-ASCII characters of every chunk as escapes, so what a source spelled as
 * an escape lands in the output as one, and refuses by name a chunk carrying any other, since a
 * character nobody expected is looked at rather than escaped unread. The known characters are a
 * list, the grid library's three for the admin build, or a test, isGreek for the embed's. An
 * asset is left alone for assertAscii to refuse, because a stylesheet or an image is not escaped
 * the same way.
 */
export function escapeKnownNonAscii(bundle: Output, known: readonly string[] | ((character: string) => boolean) = KNOWN_NON_ASCII): void {
    const isKnown = typeof known === 'function' ? known : (character: string) => known.includes(character);

    for (const item of Object.values(bundle)) {
        if (item.type !== 'chunk') {
            continue;
        }

        for (const match of item.code.matchAll(/[^\x00-\x7F]/gu)) {
            if (!isKnown(match[0])) {
                throw new Error(`${item.fileName} carries ${nameOf(match[0])} at offset ${match.index}, which no dependency is known to spell; every file here is ASCII.`);
            }
        }

        item.code = escapeNonAscii(item.code);
    }
}

/**
 * Empties a build directory before a build, leaving the named entries alone: the embed's build
 * cleans public/build without emptying the admin's directory inside it.
 */
export function emptyExcept(directory: string, kept: readonly string[]): void {
    let entries: string[];

    try {
        entries = readdirSync(directory);
    } catch {
        return;
    }

    for (const entry of entries) {
        if (!kept.includes(entry)) {
            rmSync(join(directory, entry), { recursive: true, force: true });
        }
    }
}
