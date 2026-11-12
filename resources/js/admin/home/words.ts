import { type KeysOf, type Strings, stringsOf } from '@/lib/strings';
import type { HomeStrings } from '@/types';
import type { Language } from '../../booking/language';

/*
 * Home's words: the `home` section of lang/{locale}/admin, which HomeController names beside
 * the shell's, read by key in the page's language. Every sentence of Home is one of its keys;
 * no component under home/ spells one of its own.
 */

export type HomeKey = KeysOf<HomeStrings>;

export type HomeWords = Strings<HomeKey>;

/** The home section a page's strings prop carries, in its language; a missing section answers the keys. */
export function homeWordsOf(strings: { home?: HomeStrings } | undefined, language: Language): HomeWords {
    return stringsOf<HomeStrings>(strings?.home ?? null, language);
}
