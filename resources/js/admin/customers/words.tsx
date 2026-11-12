import { createContext, type ReactNode, useContext } from 'react';
import { type KeysOf, type Strings, stringsOf } from '@/lib/strings';
import type { CustomersStrings, WizardStrings } from '@/types';
import type { Language } from '../../booking/language';

/*
 * The words of the Customers screens and of the manual booking wizard: the `customers` section of
 * lang/{locale}/admin, which CustomerController names beside the shell's on the list and a
 * customer's page, and its wizard group, which the shared props hand beside the catalog to every
 * page of a user who may book by hand, read by key in the page's language. A screen builds the
 * words from its props and provides them to the table, the tiles, Export and the Danger zone under
 * it; a render outside any provider reads the keys themselves.
 */

export type CustomersKey = KeysOf<CustomersStrings>;

export type CustomersWords = Strings<CustomersKey>;

export type WizardKey = KeysOf<WizardStrings>;

export type WizardWords = Strings<WizardKey>;

/** The customers section a page's strings prop carries, in its language; a missing section answers the keys. */
export function customersWordsOf(strings: { customers?: CustomersStrings } | undefined, language: Language): CustomersWords {
    return stringsOf<CustomersStrings>(strings?.customers ?? null, language);
}

/** The wizard's words the shared props carry beside the catalog, in the page's language; none answers the keys. */
export function wizardWordsOf(wizard: WizardStrings | null | undefined, language: Language): WizardWords {
    return stringsOf<WizardStrings>(wizard ?? null, language);
}

const CustomersContext = createContext<CustomersWords>(stringsOf<CustomersStrings>(null, 'en'));

/** The customers words for the parts under a screen. */
export function CustomersProvider({ words, children }: { words: CustomersWords; children: ReactNode }) {
    return <CustomersContext.Provider value={words}>{children}</CustomersContext.Provider>;
}

/** The customers words: the screen's, or the keys themselves outside a provider. */
export function useCustomers(): CustomersWords {
    return useContext(CustomersContext);
}
