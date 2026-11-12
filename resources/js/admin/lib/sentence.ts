import type { ShellWords } from './strings';

/*
 * The one sentence an admin screen shows for a refusal, resolved in one order: a non-blank
 * detail, otherwise the machine message, otherwise a plain error's own message, otherwise a fixed
 * fallback, the shell's, read from the strings the caller hands in. Every validation failure
 * carries the same machine code and puts the real per-field sentence in detail, so the machine
 * half alone would report invalid_request for every bad field on every form.
 *
 * A plain error's message is the same field as the machine message, read in the same place, so an
 * error object that also carries an envelope's detail still answers with the detail.
 */

/** The fallback, a key of the shell's section. */
export const FALLBACK_SENTENCE = 'toaster.fallback';

function said(value: unknown): string | null {
    return typeof value === 'string' && value.trim() !== '' ? value : null;
}

function field(value: unknown, name: string): unknown {
    return typeof value === 'object' && value !== null ? (value as Record<string, unknown>)[name] : undefined;
}

export function sentenceOf(error: unknown, shell: ShellWords): string {
    return said(field(field(error, 'data'), 'detail')) ?? said(field(error, 'message')) ?? shell.text(FALLBACK_SENTENCE);
}
