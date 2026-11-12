import { createElement, type ReactNode } from 'react';
import { BOOKING_LINES_EN } from './booking/lines.generated';
import { BUNDLED_LINES, lineOf, wordsFor, WordsContext, type LineKey, type Lines, type Table, type Values, type Words } from './booking/words';

/*
 * The words the booking app's component tests render with, the signup's pattern
 * (SignupForm.test.tsx): the keyed table, every English line replaced by its own key in brackets
 * with the placeholders the line carries kept after the key, so a render shows which key it read
 * and with which values and no test holds a sentence in either language. A component a test
 * renders with the keyed table that printed a constant without reading it through the words
 * would show the bare key, not the brackets, and fail. The English words are the bundle's own
 * lines, for the one render per step that reads the real English, its values read from the
 * generated module and never spelled.
 */

/** The placeholders a line carries, each once, in the order the line names them. */
function placeholdersOf(line: string): string[] {
    return [...new Set([...line.matchAll(/(?<![A-Za-z0-9_:]):([A-Za-z][A-Za-z0-9_]*)/g)].map((match) => match[1] ?? ''))];
}

function keyedTable(table: Table, prefix: string): Table {
    return Object.fromEntries(
        Object.entries(table).map(([name, value]) => {
            const key = `${prefix}${name}`;

            return [name, typeof value === 'string' ? `[${[key, ...placeholdersOf(value).map((placeholder) => `:${placeholder}`)].join(' ')}]` : keyedTable(value, `${key}.`)];
        }),
    );
}

/** The English file's every key, each line its key in brackets; no Greek table. */
export const KEYED_LINES: Lines = { en: keyedTable(BOOKING_LINES_EN, ''), el: null };

export const KEYED_WORDS: Words = wordsFor(KEYED_LINES, 'en');

/** The bundle's own English words. */
export const ENGLISH_WORDS: Words = wordsFor(BUNDLED_LINES, 'en');

/** What a key renders as under the keyed table, its values filled. */
export function said(key: LineKey, values?: Values): string {
    return KEYED_WORDS.text(key, values);
}

/** What a key read as a choice by a count renders as under the keyed table. */
export function saidBy(key: LineKey, count: number, values?: Values): string {
    return KEYED_WORDS.choice(key, count, values);
}

/** A tree rendered with the keyed table, or with the words given. */
export function keyed(node: ReactNode, words: Words = KEYED_WORDS): ReactNode {
    return createElement(WordsContext.Provider, { value: words }, node);
}

/**
 * The bundle's own Greek words, lang/el/booking.php through the generated module, for the
 * Greek walk: a screen rendered with them shows no English line of its own.
 */
export const GREEK_WORDS: Words = wordsFor(BUNDLED_LINES, 'el');

/** Every line of a table by its key read with dots. */
function linesOf(table: Table, prefix = ''): [string, string][] {
    return Object.entries(table).flatMap(([name, value]): [string, string][] => (typeof value === 'string' ? [[`${prefix}${name}`, value]] : linesOf(value, `${prefix}${name}.`)));
}

/** A literal text as a pattern matching itself. */
function literal(text: string): string {
    return text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/**
 * Each English line of booking.php as the patterns it can render as: every form of a choice, its
 * condition stripped, each :word free to be filled with anything. A line the Greek table holds
 * the same is a shared value (LanguageFilesTest's list) and is left out.
 */
const ENGLISH_PATTERNS: readonly { readonly key: string; readonly pattern: RegExp }[] = linesOf(BOOKING_LINES_EN)
    .filter(([key, line]) => BUNDLED_LINES.el === null || lineOf(BUNDLED_LINES.el, key) !== line)
    .flatMap(([key, line]) =>
        line.split('|').map((form) => ({
            key,
            pattern: new RegExp(
                form
                    .replace(/^\s*[{[][^}\]]*[}\]]/, '')
                    .trim()
                    .split(/:[A-Za-z][A-Za-z0-9_]*/)
                    .map(literal)
                    .join('.+?'),
            ),
        })),
    );

/** A :word left unfilled in a text, a time's minutes and an address's port left alone. */
const UNFILLED = /(?<![A-Za-z0-9_:/]):[A-Za-z][A-Za-z0-9_]*/g;

/**
 * What a rendered tree leaves in English or unfilled (a walk by keys): the key of every English
 * line of booking.php its text or its labels show, and every :word left in them. The language
 * switch's own label, the one element marked lang="en", reads English by design and is left out.
 * Empty for a screen wholly in Greek.
 */
export function englishLeft(root: Element): string[] {
    const copy = root.cloneNode(true) as Element;

    for (const english of Array.from(copy.querySelectorAll('[lang="en"]'))) {
        english.remove();
    }

    const labels = Array.from(copy.querySelectorAll('[aria-label], [title], [placeholder], [alt]')).flatMap((el) =>
        ['aria-label', 'title', 'placeholder', 'alt'].map((name) => el.getAttribute(name) ?? '').filter((value) => value !== ''),
    );
    const texts = [copy.textContent, ...labels].filter((text): text is string => text !== null && text !== '');
    const left = new Set<string>();

    for (const text of texts) {
        for (const { key, pattern } of ENGLISH_PATTERNS) {
            if (pattern.test(text)) {
                left.add(key);
            }
        }

        for (const match of text.matchAll(UNFILLED)) {
            left.add(match[0]);
        }
    }

    return [...left].sort();
}
