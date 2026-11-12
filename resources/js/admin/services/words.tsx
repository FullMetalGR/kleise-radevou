import { createContext, type ReactNode, useContext } from 'react';
import { type KeysOf, type Strings, stringsOf } from '@/lib/strings';
import type { ServicesStrings } from '@/types';
import type { Language } from '../../booking/language';

/*
 * The words of the Services screen: the `services` section of lang/{locale}/admin, which
 * ServiceController names beside the shell's on the catalog, the create route and a service's
 * page, read by key in the page's language. The screen builds the words from its props and
 * provides them to the table and the form under it; a render outside any provider reads the keys
 * themselves.
 */

export type ServicesKey = KeysOf<ServicesStrings>;

export type ServicesWords = Strings<ServicesKey>;

/** The services section the strings prop carries, in its language; none answers the keys. */
export function servicesWordsOf(strings: { services?: ServicesStrings } | undefined, language: Language): ServicesWords {
    return stringsOf<ServicesStrings>(strings?.services ?? null, language);
}

const ServicesContext = createContext<ServicesWords>(stringsOf<ServicesStrings>(null, 'en'));

/** The services words for the parts under the screen. */
export function ServicesProvider({ words, children }: { words: ServicesWords; children: ReactNode }) {
    return <ServicesContext.Provider value={words}>{children}</ServicesContext.Provider>;
}

/** The services words: the screen's, or the keys themselves outside a provider. */
export function useServices(): ServicesWords {
    return useContext(ServicesContext);
}
