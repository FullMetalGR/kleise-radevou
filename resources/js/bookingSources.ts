import { readdirSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/*
 * The booking app's own source as the audits read it: every .ts and .tsx file directly under
 * resources/js/booking that is not a test, comments stripped, since prose may name what the code
 * must not do. One reader for the three audits, the clock and the money of 7.1 and 7.2, the live
 * regions of 7.3 and the disabled controls of 7.5, so they cannot disagree about what the app is.
 */

export interface Source {
    readonly file: string;
    readonly text: string;
}

const ROOT = resolve(process.cwd(), 'resources/js/booking');

/** Block comments, and line comments not preceded by a colon, so a URL's // survives. */
export function stripComments(code: string): string {
    return code.replace(/\/\*[\s\S]*?\*\//g, '').replace(/(^|[^:])\/\/.*$/gm, '$1');
}

export function bookingSources(): Source[] {
    return readdirSync(ROOT)
        .filter((file) => /\.tsx?$/.test(file) && !/\.test\.tsx?$/.test(file))
        .map((file) => ({ file, text: stripComments(readFileSync(resolve(ROOT, file), 'utf8')) }));
}
