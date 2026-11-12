import { describe, expect, it } from 'vitest';
import { keyedShell } from '@/testing/keyed';
import { FALLBACK_SENTENCE, sentenceOf } from './sentence';

/*
 * One sentence, resolved in one order. A non-blank detail, otherwise the machine message,
 * otherwise a plain error's own message, otherwise a fixed fallback, the shell's, read from the
 * strings given, the keyed table here.
 */

const shell = keyedShell();

describe('the one sentence', () => {
    it('is the detail when there is one', () => {
        expect(sentenceOf({ code: 'invalid_request', message: 'invalid_request', data: { status: 422, detail: 'The name is required.' } }, shell)).toBe('The name is required.');
    });

    it('is the machine message when the detail is blank or missing', () => {
        expect(sentenceOf({ code: 'overlap', message: 'overlap', data: { status: 409, detail: '   ' } }, shell)).toBe('overlap');
        expect(sentenceOf({ code: 'overlap', message: 'overlap', data: { status: 409 } }, shell)).toBe('overlap');
    });

    it('is a plain error message when there is no envelope', () => {
        expect(sentenceOf(new TypeError('Failed to fetch'), shell)).toBe('Failed to fetch');
    });

    it('prefers the detail an error object carries over its own message', () => {
        expect(sentenceOf(Object.assign(new Error('Request failed with status 409'), { data: { detail: 'That time was just taken.' } }), shell)).toBe('That time was just taken.');
    });

    it('is the fixed fallback when nothing else says anything', () => {
        const fallback = shell.text(FALLBACK_SENTENCE);

        expect(fallback).toBe('[toaster.fallback]');
        expect(sentenceOf(null, shell)).toBe(fallback);
        expect(sentenceOf({ message: '' }, shell)).toBe(fallback);
        expect(sentenceOf(new Error(''), shell)).toBe(fallback);
        expect(sentenceOf('a string', shell)).toBe(fallback);
    });
});
