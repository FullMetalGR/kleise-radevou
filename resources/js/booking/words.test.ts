import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';
import { KEYED_LINES, KEYED_WORDS, said, saidBy } from '../bookingWords';
import { localeOf } from './language';
import { BOOKING_LINES_EL, BOOKING_LINES_EN } from './lines.generated';
import { choose } from './choose';
import { BUNDLED_LINES, lineOf, wordsFor, type Lines } from './words';

/*
 * The bundle's sentences: a key read with dots through the language's table, English per key
 * where the Greek lacks one, as the translator falls back, the key itself where neither holds it;
 * placeholders filled by the one fill(); and a choice by the mirror of the translator's choice
 * syntax, held to the server's choices over the fixture its test reads too
 * (tests/Support/choices.json, tests/Feature/Language/BundleMirrorsTest.php). The lines below are
 * the test's own, never the bundle's sentences, and the keyed table every component test renders
 * with is proven to bracket every English key.
 */

const LINES: Lines = {
    en: { language: 'First', shell: { loading: 'Loading :business', unavailable: 'Unavailable' }, duration: { hours: ':count h' } },
    el: { language: 'Second', shell: { loading: 'Second loading :business' } },
};

interface ChoiceCase {
    readonly line: string;
    readonly chosen: Readonly<Record<string, string>>;
}

describe('lineOf', () => {
    it('walks a key read with dots, and answers null for a key the table lacks or a section that is no line', () => {
        expect(lineOf(LINES.en, 'shell.loading')).toBe('Loading :business');
        expect(lineOf(LINES.en, 'language')).toBe('First');
        expect(lineOf(LINES.en, 'shell')).toBeNull();
        expect(lineOf(LINES.en, 'shell.missing')).toBeNull();
        expect(lineOf(LINES.en, 'language.deeper')).toBeNull();
        expect(lineOf(null, 'language')).toBeNull();
    });
});

describe('wordsFor', () => {
    it('answers a key in its language, filling the placeholders through fill()', () => {
        expect(wordsFor(LINES, 'en').text('shell.loading', { business: 'Luna' })).toBe('Loading Luna');
        expect(wordsFor(LINES, 'el').text('shell.loading', { business: 'Luna' })).toBe('Second loading Luna');
    });

    it('answers in English per key where the Greek table lacks the key, and in English throughout while the Greek file is pending', () => {
        expect(wordsFor(LINES, 'el').text('shell.unavailable')).toBe('Unavailable');
        expect(wordsFor({ en: LINES.en, el: null }, 'el').text('language')).toBe('First');
    });

    it('answers the key itself where neither table holds it, as the translator does', () => {
        expect(wordsFor({ en: {}, el: null }, 'el').text('shell.loading')).toBe('shell.loading');
    });

    it('chooses a line by its count, :count filled with it and a value named count winning over it', () => {
        const words = wordsFor({ en: { duration: { hours: '{1} :count one|[2,*] :count many :minutes' } }, el: null }, 'en');

        expect(words.choice('duration.hours', 1)).toBe('1 one');
        expect(words.choice('duration.hours', 3, { minutes: '30' })).toBe('3 many 30');
        expect(words.choice('duration.hours', 3, { count: 'three' })).toBe('three many :minutes');
    });

    it('carries the language and the locale the formatters take for it on this machine', () => {
        expect(wordsFor(LINES, 'el').language).toBe('el');
        expect(wordsFor(LINES, 'el').locale).toBe(localeOf('el'));
        expect(wordsFor(LINES, 'en').locale).toBe(localeOf('en'));
    });
});

describe('choose', () => {
    const cases = JSON.parse(readFileSync(resolve(process.cwd(), 'tests/Support/choices.json'), 'utf8')) as readonly ChoiceCase[];

    it('reads a fixture the server\'s test reads too', () => {
        expect(cases.length).toBeGreaterThan(5);
    });

    it.each(cases.flatMap((one) => Object.entries(one.chosen).map(([count, chosen]) => [one.line, Number(count), chosen] as const)))('chooses %j by %d as the translator does: %j', (line, count, chosen) => {
        expect(choose(line, count)).toBe(chosen);
    });
});

describe('the bundled lines', () => {
    it('are the generated module\'s two tables, the Greek null while its file is pending', () => {
        expect(BUNDLED_LINES).toEqual({ en: BOOKING_LINES_EN, el: BOOKING_LINES_EL });
        expect(BUNDLED_LINES.en).toHaveProperty('language');
    });
});

describe('the keyed table', () => {
    /** Every key of a table read with dots, beside its line. */
    function linesOf(table: object, prefix = ''): [string, string][] {
        return Object.entries(table).flatMap(([name, value]: [string, unknown]) =>
            typeof value === 'string' ? [[`${prefix}${name}`, value] as [string, string]] : linesOf(value as object, `${prefix}${name}.`),
        );
    }

    it('holds every English key, as the key in brackets with the line\'s placeholders after it', () => {
        const english = linesOf(BOOKING_LINES_EN);

        expect(linesOf(KEYED_LINES.en).map(([key]) => key)).toEqual(english.map(([key]) => key));
        expect(KEYED_LINES.el).toBeNull();

        for (const [key, line] of english) {
            const placeholders = [...line.matchAll(/(?<![A-Za-z0-9_:]):([A-Za-z][A-Za-z0-9_]*)/g)].map((match) => ` :${match[1] ?? ''}`);

            expect(lineOf(KEYED_LINES.en, key)).toBe(`[${key}${[...new Set(placeholders)].join('')}]`);
        }
    });

    it('renders a key as its brackets, its values filled after it', () => {
        expect(said('shell.loading')).toBe('[shell.loading]');
        expect(saidBy('duration.hours_minutes', 1, { minutes: '30' })).toBe('[duration.hours_minutes 1 30]');
        expect(KEYED_WORDS.language).toBe('en');
    });
});
