import { createContext, type ReactNode, useContext } from 'react';
import { type KeysOf, type Strings, stringsOf } from '@/lib/strings';
import type { BillingStrings, EmailsStrings, PaymentsStrings, SettingsStrings } from '@/types';
import type { Language } from '../../booking/language';

/*
 * The words of the Settings screen: the `settings` section of lang/{locale}/admin, which
 * SettingsController names beside the shell's, read by key in the page's language. The page builds
 * the words from its props and provides them to the parts under it, the model's checks among their
 * readers; a render outside any provider reads the keys themselves.
 *
 * The Payments section reads the `payments` section beside it, which the controller names too,
 * provided the same way. The Danger zone and the Billing screen read the `billing` section, which
 * SettingsController names beside the settings section and BillingController on its own screen,
 * provided the same way. The Emails section reads the `emails` section, which SettingsController
 * names too, provided the same way.
 */

export type SettingsKey = KeysOf<SettingsStrings>;

export type SettingsWords = Strings<SettingsKey>;

/** The settings section the strings prop carries, in its language; none answers the keys. */
export function settingsWordsOf(strings: { settings?: SettingsStrings } | undefined, language: Language): SettingsWords {
    return stringsOf<SettingsStrings>(strings?.settings ?? null, language);
}

const SettingsContext = createContext<SettingsWords>(stringsOf<SettingsStrings>(null, 'en'));

/** The settings words for the parts under the screen. */
export function SettingsProvider({ words, children }: { words: SettingsWords; children: ReactNode }) {
    return <SettingsContext.Provider value={words}>{children}</SettingsContext.Provider>;
}

/** The settings words: the screen's, or the keys themselves outside a provider. */
export function useSettings(): SettingsWords {
    return useContext(SettingsContext);
}

export type PaymentsKey = KeysOf<PaymentsStrings>;

export type PaymentsWords = Strings<PaymentsKey>;

/** The payments section the strings prop carries, in its language; none answers the keys. */
export function paymentsWordsOf(strings: { payments?: PaymentsStrings } | undefined, language: Language): PaymentsWords {
    return stringsOf<PaymentsStrings>(strings?.payments ?? null, language);
}

const PaymentsContext = createContext<PaymentsWords>(stringsOf<PaymentsStrings>(null, 'en'));

/** The payments words for the Payments section's parts. */
export function PaymentsProvider({ words, children }: { words: PaymentsWords; children: ReactNode }) {
    return <PaymentsContext.Provider value={words}>{children}</PaymentsContext.Provider>;
}

/** The payments words: the screen's, or the keys themselves outside a provider. */
export function usePayments(): PaymentsWords {
    return useContext(PaymentsContext);
}

export type BillingKey = KeysOf<BillingStrings>;

export type BillingWords = Strings<BillingKey>;

/** The billing section the strings prop carries, in its language; none answers the keys. */
export function billingWordsOf(strings: { billing?: BillingStrings } | undefined, language: Language): BillingWords {
    return stringsOf<BillingStrings>(strings?.billing ?? null, language);
}

const BillingContext = createContext<BillingWords>(stringsOf<BillingStrings>(null, 'en'));

/** The billing words for the Danger zone and the Billing screen's parts. */
export function BillingProvider({ words, children }: { words: BillingWords; children: ReactNode }) {
    return <BillingContext.Provider value={words}>{children}</BillingContext.Provider>;
}

/** The billing words: the screen's, or the keys themselves outside a provider. */
export function useBilling(): BillingWords {
    return useContext(BillingContext);
}

export type EmailsKey = KeysOf<EmailsStrings>;

export type EmailsWords = Strings<EmailsKey>;

/** The emails section the strings prop carries, in its language; none answers the keys. */
export function emailsWordsOf(strings: { emails?: EmailsStrings } | undefined, language: Language): EmailsWords {
    return stringsOf<EmailsStrings>(strings?.emails ?? null, language);
}

const EmailsContext = createContext<EmailsWords>(stringsOf<EmailsStrings>(null, 'en'));

/** The emails words for the Emails section. */
export function EmailsProvider({ words, children }: { words: EmailsWords; children: ReactNode }) {
    return <EmailsContext.Provider value={words}>{children}</EmailsContext.Provider>;
}

/** The emails words: the screen's, or the keys themselves outside a provider. */
export function useEmails(): EmailsWords {
    return useContext(EmailsContext);
}
