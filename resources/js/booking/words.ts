import { createContext, useContext } from 'react';
import { choose } from './choose';
import { localeOf, type Language } from './language';
import { BOOKING_LINES_EL, BOOKING_LINES_EN } from './lines.generated';
import { fill } from './placeholders';

/*
 * The bundle's sentences. No sentence a customer reads is written in the bundle's code: each is a
 * key of lang/en/booking.php, which php artisan lang:export booking writes, with its Greek twin,
 * into the generated module beside this one, and a constant that held a sentence holds its key.
 * The words of one language answer a key: its line in that language's table, else the English one,
 * as the translator falls back while a Greek file is pending or a key is missing from it, else the
 * key itself, as the translator answers a key it has no line for.
 *
 * A line's :word placeholders are filled by the one fill(), and a line read as a choice is chosen
 * by a mirror of the translator's choice syntax, {n}, [a,b], [a,*], [*,b] and the plain forms
 * split by a bar, held to the server's choices over one fixture: nothing pluralises by hand and no
 * sentence is assembled from fragments. The words carry the locale the formatters take for their
 * language on this machine, so the names of days and months follow the page's language and the
 * order and the hour cycle stay the machine's.
 *
 * The app holds one language at a time and hands its words down through WordsContext: the shell
 * provides the start language's before the config is in hand and the config's language after, the
 * switch changing it. A render outside any provider reads English, the bundle's own lines.
 */

/** One language's lines, nested as lang/{en,el}/booking.php nests them. */
export type Table = { readonly [key: string]: string | Table };

/** Both languages' lines as the bundle carries them, the Greek null while its file is pending. */
export interface Lines {
    readonly en: Table;
    readonly el: Table | null;
}

type KeysOf<T> = { [K in keyof T & string]: T[K] extends string ? K : `${K}.${KeysOf<T[K]>}` }[keyof T & string];

/** A key of lang/en/booking.php read with dots; the type check refuses a key the file lacks. */
export type LineKey = KeysOf<typeof BOOKING_LINES_EN>;

/** The values a line's :word placeholders are filled with, by name. */
export type Values = Readonly<Record<string, string>>;

/** The lines the bundle carries: the generated module's two tables. */
export const BUNDLED_LINES: Lines = { en: BOOKING_LINES_EN, el: BOOKING_LINES_EL };

/** One language's words: its lines answering a key, and the locale its formatters take. */
export interface Words {
    readonly language: Language;
    /** The locale Intl formats in for the language on this machine. */
    readonly locale: string;
    /** A key's line, its placeholders filled. */
    readonly text: (key: LineKey, values?: Values) => string;
    /** A key's line chosen by a count, :count filled with it unless a value names count. */
    readonly choice: (key: LineKey, count: number, values?: Values) => string;
}

/**
 * The line a key read with dots names in a table, or null where the table holds none.
 */
export function lineOf(table: Table | null, key: string): string | null {
    let at: string | Table | undefined = table ?? undefined;

    for (const segment of key.split('.')) {
        if (at === undefined || typeof at === 'string') {
            return null;
        }

        at = at[segment];
    }

    return typeof at === 'string' ? at : null;
}

export function wordsFor(lines: Lines, language: Language, machine?: string): Words {
    const own = language === 'el' ? lines.el : lines.en;
    const line = (key: LineKey): string => lineOf(own, key) ?? lineOf(lines.en, key) ?? key;

    return {
        language,
        locale: localeOf(language, machine),
        text: (key, values = {}) => fill(line(key), values),
        choice: (key, count, values = {}) => fill(choose(line(key), count), { count: String(count), ...values }),
    };
}

/** The words a component reads its sentences through: English outside any provider. */
export const WordsContext = createContext<Words>(wordsFor(BUNDLED_LINES, 'en'));

export function useWords(): Words {
    return useContext(WordsContext);
}
