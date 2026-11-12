import { createContext, type ReactNode, useContext } from 'react';
import { type KeysOf, type Strings, stringsOf } from '@/lib/strings';
import type { StaffStrings } from '@/types';
import type { Language } from '../../booking/language';

/*
 * The words of the Staff screen: the `staff` section of lang/{locale}/admin, which StaffController
 * names beside the shell's on the list, the create route and a member's page, the floor's closures
 * included, read by key in the page's language. The screen builds the words from its props and
 * provides them to the table, the form, the read-only page and the closures card under it; a
 * render outside any provider reads the keys themselves.
 */

export type StaffKey = KeysOf<StaffStrings>;

export type StaffWords = Strings<StaffKey>;

/** The staff section the strings prop carries, in its language; none answers the keys. */
export function staffWordsOf(strings: { staff?: StaffStrings } | undefined, language: Language): StaffWords {
    return stringsOf<StaffStrings>(strings?.staff ?? null, language);
}

const StaffContext = createContext<StaffWords>(stringsOf<StaffStrings>(null, 'en'));

/** The staff words for the parts under the screen. */
export function StaffProvider({ words, children }: { words: StaffWords; children: ReactNode }) {
    return <StaffContext.Provider value={words}>{children}</StaffContext.Provider>;
}

/** The staff words: the screen's, or the keys themselves outside a provider. */
export function useStaff(): StaffWords {
    return useContext(StaffContext);
}
