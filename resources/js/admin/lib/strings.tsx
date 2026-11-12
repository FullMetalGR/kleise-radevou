import { createContext, Fragment, type ReactNode, useContext, useMemo } from 'react';
import { choose } from '../../booking/choose';
import { localeOf, parseLanguage, type Language } from '../../booking/language';
import { fill } from '../../booking/placeholders';
import { formatCount } from '@/lib/format';
import type { ShellStrings } from '@/types';

/*
 * The admin's words on the client. No sentence an admin user reads is written in the admin's code:
 * each is a key of a section of lang/{en,el}/admin, which the server hands every page in the
 * strings prop in the language the page is answered in, and a constant that held a sentence holds
 * its key. A section's strings answer a key, read with dots: its line, else the key itself, as the
 * translator answers a key it has no line for, so no component carries a default of its own.
 *
 * A line's :word placeholders are filled by the one fill() both bundles read, never by
 * concatenation, and a line read as a choice is chosen by the translator's choice syntax. The
 * strings carry the locale the formatters take for the language on this machine, so the names of
 * days and months follow the page's language while the tenant's own formats stay.
 *
 * The shell's section reaches every component through ShellContext, which the admin's entry
 * provides above every frame from the page's props on every host: the admin's in the user's
 * language, the console's in English, the central host's two groups the signup's pages render in
 * the request's. A render outside any provider reads the keys themselves, in English names.
 */

/** One section's lines, nested as its file nests them. */
type Table = { readonly [key: string]: string | Table };

/** The values a line's :word placeholders are filled with, by name. */
type Values = Readonly<Record<string, string>>;

/** The keys of a section's type, read with dots; the type check refuses a key the section lacks. */
export type KeysOf<T> = { [K in keyof T & string]: T[K] extends string ? K : `${K}.${KeysOf<T[K]>}` }[keyof T & string];

/** A key of the shell's section. */
export type ShellKey = KeysOf<ShellStrings>;

/** A section's words in a language: its lines answering a key, and the locale the formatters take. */
export interface Strings<K extends string> {
    readonly language: Language;
    /** The locale Intl formats in for the language on this machine. */
    readonly locale: string;
    /** A key's line, its placeholders filled. */
    readonly text: (key: K, values?: Values) => string;
    /**
     * A key's line chosen by a count, :count filled with it spelled by formatCount in the locale,
     * grouped from a thousand up as the language groups it, unless a value names count: a caller
     * that places the figure itself keeps the placeholder that way.
     */
    readonly choice: (key: K, count: number, values?: Values) => string;
}

export type ShellWords = Strings<ShellKey>;

/** The line a key read with dots names in a table, or null where the table holds none. */
function lineOf(table: Table | null, key: string): string | null {
    let at: string | Table | undefined = table ?? undefined;

    for (const segment of key.split('.')) {
        if (at === undefined || typeof at === 'string') {
            return null;
        }

        at = at[segment];
    }

    return typeof at === 'string' ? at : null;
}

/**
 * A section's words in a language: the table the server handed, or null where none came, which
 * answers every key with the key itself.
 */
export function stringsOf<T>(table: T | null | undefined, language: Language, machine?: string): Strings<KeysOf<T>> {
    const own = (table ?? null) as Table | null;
    const line = (key: string): string => lineOf(own, key) ?? key;

    const locale = localeOf(language, machine);

    return {
        language,
        locale,
        text: (key, values = {}) => fill(line(key), values),
        choice: (key, count, values = {}) => fill(choose(line(key), count), { count: formatCount(count, locale), ...values }),
    };
}

/** A placeholder's name as a pattern, nothing in it read as one. */
function escaped(name: string): string {
    return name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/**
 * A line cut at the :word placeholders it still holds, each replaced by the node named for it, in
 * the order the line puts them: how a sentence keeps a link or a control inside it as one whole
 * key, the node a named placeholder the language places where its grammar wants it. The longer
 * names are tried first, so :ends never eats :end. Each run of words between the nodes goes
 * through the wrapper where one is given; a line holding none of the names is its one run. Only
 * the plain :name form is read, never fill()'s :Name and :NAME forms; a node whose placeholder the
 * line lacks is left out, unseen. Nothing here checks that a line holds its placeholders: the
 * language files' parity test holds a line and its placeholders together.
 */
export function placed(line: string, nodes: Readonly<Record<string, ReactNode>>, wrap: (words: string) => ReactNode = (words) => words): ReactNode[] {
    const names = Object.keys(nodes).sort((a, b) => b.length - a.length);

    if (names.length === 0) {
        return [wrap(line)];
    }

    const pattern = new RegExp(`:(${names.map(escaped).join('|')})(?![A-Za-z0-9_])`);
    const pieces: ReactNode[] = [];
    let rest = line;

    for (let match = pattern.exec(rest); match !== null; match = pattern.exec(rest)) {
        if (match.index > 0) {
            pieces.push(<Fragment key={pieces.length}>{wrap(rest.slice(0, match.index))}</Fragment>);
        }

        pieces.push(<Fragment key={pieces.length}>{nodes[match[1] ?? '']}</Fragment>);
        rest = rest.slice(match.index + match[0].length);
    }

    if (rest !== '') {
        pieces.push(<Fragment key={pieces.length}>{wrap(rest)}</Fragment>);
    }

    return pieces;
}

/** The shell's words a page's props carry, on any host: its language, en where it names none, and its shell section. */
export function shellOf(props: Readonly<Record<string, unknown>>): { language: Language; strings: Partial<ShellStrings> | null } {
    const strings = props.strings;
    const shell: unknown = typeof strings === 'object' && strings !== null ? (strings as Record<string, unknown>).shell : null;

    return {
        language: parseLanguage(props.language) ?? 'en',
        strings: typeof shell === 'object' && shell !== null ? (shell as Partial<ShellStrings>) : null,
    };
}

const ShellContext = createContext<ShellWords>(stringsOf<ShellStrings>(null, 'en'));

/** The shell's words for every component under it, the section in the language given. */
export function ShellProvider({ strings, language, children }: { strings: Partial<ShellStrings> | null; language: Language; children: ReactNode }) {
    const words = useMemo(() => stringsOf<ShellStrings>(strings as ShellStrings | null, language), [strings, language]);

    return <ShellContext.Provider value={words}>{children}</ShellContext.Provider>;
}

/** The shell's words: the page's section in its language, or the keys themselves outside a provider. */
export function useShell(): ShellWords {
    return useContext(ShellContext);
}
