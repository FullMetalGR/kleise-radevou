import type { ReactNode } from 'react';
import { otherOf, switchHrefOf, type Language } from './language';
import { wordsFor, type LineKey, type Lines } from './words';

/*
 * The language switch: one component, rendered beside the view by the shell. On the hosted page
 * it is a link to the same path under the other language's prefix, the query kept, a token in it
 * included, the address TenantPages::addresses computes on the server. In the embed it is an
 * in-app control that changes the language the app holds with no request, since both languages
 * ride in the bundle; every later call carries the new one.
 *
 * It is labelled by the other language's own name, booking.php's language read in that language,
 * as the site's switch reads site.language, and carries that language as its lang, the link its
 * hreflang too, so a screen reader speaks the name in its own language.
 */

/** This language's own name, which labels the switch on the other language's pages. */
export const LANGUAGE_NAME: LineKey = 'language';

/** The hosted page's address as the location spells it: the path and the query with its mark. */
export interface PageAddress {
    readonly pathname: string;
    readonly search: string;
}

interface LanguageSwitchProps {
    readonly lines: Lines;
    /** The language the app holds. */
    readonly language: Language;
    /** The hosted page's address, null in the embed. */
    readonly page: PageAddress | null;
    readonly onSwitch: (language: Language) => void;
}

export function LanguageSwitch({ lines, language, page, onSwitch }: LanguageSwitchProps): ReactNode {
    const other = otherOf(language);
    const label = wordsFor(lines, other).text(LANGUAGE_NAME);

    if (page === null) {
        return (
            <button type="button" className="kr-booking__language" lang={other} onClick={() => onSwitch(other)}>
                {label}
            </button>
        );
    }

    return (
        <a className="kr-booking__language" href={switchHrefOf(page.pathname, page.search, language)} hrefLang={other} lang={other}>
            {label}
        </a>
    );
}
