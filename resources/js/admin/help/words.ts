import { type KeysOf, type Strings, stringsOf } from '@/lib/strings';
import type { Language } from '../../booking/language';

/*
 * The panel's words: the help section of lang/{locale}/admin, which comes with the answers the
 * first time the panel opens, in the language of that request, read by key. Every sentence the
 * panel shows is one of its keys, save its heading, the bubble's own name from the shell's help
 * group, which every screen carries. The type is the section key for key; a test holds it to the
 * file.
 */

export type HelpStrings = {
    platform: string;
    close: string;
    tabs: { answers: string; tickets: string; tickets_unread: string };
    search: { label: string; found: string; none: string };
    still: string;
    open: string;
    back: string;
    retry: string;
    form: { title: string; subject: string; body: string; send: string; sending: string };
    list: { loading: string; empty: string; unread: string; last: string; failed: string };
    statuses: { open: string; closed: string };
    thread: { loading: string; closed: string; reply: string; send: string; sending: string; failed: string };
    validation: { subject: string; body: string };
    refusals: { tickets: string; messages: string; demo: string };
};

export type HelpWords = Strings<KeysOf<HelpStrings>>;

/** The panel's words as the server sent them, read by key in the language they came in. */
export function helpWordsOf(words: HelpStrings, language: Language): HelpWords {
    return stringsOf<HelpStrings>(words, language);
}
