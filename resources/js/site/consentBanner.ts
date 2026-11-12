/*
 * The consent banner's script: Google Analytics behind consent on the central host, and nothing
 * loaded before it. The server renders the banner hidden on every central page while the tag's id
 * is set, with the id and the tag's address on it and every sentence in it; this script holds
 * none.
 *
 * The choice is a first-party cookie of its own, granted or denied, host-only (no Domain), on
 * Path=/, SameSite=Lax, Secure where the host is, for 180 days, after which the banner asks
 * again. No server code reads it. A page load finding no choice shows the banner; one finding
 * granted loads the tag; one finding denied does neither.
 *
 * The loader runs on Accept or on a load finding granted, once a page: the data layer and gtag
 * defined, Consent Mode's default set to denied for the four signals, analytics_storage updated
 * to granted with the ad signals left denied, then the script inserted from its address with the
 * id, and the tag configured with cookie_domain 'none', so its cookies stay host-only on the
 * central host and never reach a tenant's, all of which sit under the same domain.
 *
 * Reject writes denied and expires the tag's _ga cookies on this host, and where the tag had
 * loaded on the page it reloads it, so nothing of the tag stays running. The footer's "Cookie
 * settings" reopens the banner; with no script it stays the link to the cookie notice it is.
 */

/** The choice's cookie, which the consent script alone reads and writes. */
export const CONSENT_COOKIE = 'analytics_consent';

/** How long a choice stands, in seconds: 180 days. */
const LIFETIME = 180 * 24 * 60 * 60;

type Choice = 'granted' | 'denied';

declare global {
    interface Window {
        dataLayer?: unknown[];
        gtag?: (...args: unknown[]) => void;
    }
}

/**
 * Where the script runs: the page and its window, the cookie jar it reads and writes (the
 * document itself outside a test), whether the host is secure, and how the page reloads.
 */
interface ConsentPage {
    readonly document: Document;
    readonly window: Window;
    readonly cookies: { cookie: string };
    readonly secure: boolean;
    readonly reload: () => void;
}

/** The cookies the jar holds, each by its name and its value, in the order it lists them. */
function cookiePairs(cookies: { cookie: string }): Array<[string, string]> {
    return cookies.cookie
        .split(';')
        .map((pair): [string, string] => {
            const part = pair.trim();
            const at = part.indexOf('=');

            return at > 0 ? [part.slice(0, at), part.slice(at + 1)] : [part, ''];
        })
        .filter(([name]) => name !== '');
}

function storedChoice(cookies: { cookie: string }): Choice | null {
    const value = cookiePairs(cookies).find(([name]) => name === CONSENT_COOKIE)?.[1];

    return value === 'granted' || value === 'denied' ? value : null;
}

function storeChoice(page: ConsentPage, choice: Choice): void {
    page.cookies.cookie = `${CONSENT_COOKIE}=${choice}; Max-Age=${LIFETIME}; Path=/; SameSite=Lax${page.secure ? '; Secure' : ''}`;
}

/** Expires the tag's cookies on this host: _ga and each _ga_{id}, host-only on Path=/ as set. */
function expireTagCookies(cookies: { cookie: string }): void {
    for (const [name] of cookiePairs(cookies)) {
        if (name === '_ga' || name.startsWith('_ga_')) {
            cookies.cookie = `${name}=; Max-Age=0; Path=/`;
        }
    }
}

function loadTag(page: ConsentPage, id: string, script: string): void {
    const layer = page.window.dataLayer ?? [];
    page.window.dataLayer = layer;

    // gtag.js reads each entry as the arguments of one call, never as an array, so the entry
    // pushed is the arguments object itself.
    function gtag(..._call: unknown[]): void {
        layer.push(arguments);
    }

    page.window.gtag = gtag;
    gtag('consent', 'default', { analytics_storage: 'denied', ad_storage: 'denied', ad_user_data: 'denied', ad_personalization: 'denied' });
    gtag('consent', 'update', { analytics_storage: 'granted' });

    const address = new URL(script, page.document.baseURI);
    address.searchParams.set('id', id);

    const tag = page.document.createElement('script');
    tag.async = true;
    tag.src = address.href;
    page.document.head.appendChild(tag);

    gtag('js', new Date());
    gtag('config', id, { cookie_domain: 'none' });
}

/**
 * Wires the banner on a page that carries it, and does nothing on one that does not.
 */
export function startConsent(page: ConsentPage): void {
    const banner = page.document.querySelector<HTMLElement>('.site-consent');
    const id = banner?.dataset.measurementId ?? '';
    const script = banner?.dataset.script ?? '';

    if (banner === null || id === '' || script === '') {
        return;
    }

    let loaded = false;
    const load = (): void => {
        if (!loaded) {
            loaded = true;
            loadTag(page, id, script);
        }
    };

    const choice = storedChoice(page.cookies);

    if (choice === 'granted') {
        load();
    } else if (choice === null) {
        banner.hidden = false;
    }

    for (const button of banner.querySelectorAll<HTMLButtonElement>('[data-consent-choice]')) {
        button.addEventListener('click', () => {
            const chosen = button.dataset.consentChoice;

            if (chosen === 'granted') {
                storeChoice(page, 'granted');
                banner.hidden = true;
                load();
            } else if (chosen === 'denied') {
                storeChoice(page, 'denied');
                expireTagCookies(page.cookies);
                banner.hidden = true;

                if (loaded) {
                    page.reload();
                }
            }
        });
    }

    for (const control of page.document.querySelectorAll<HTMLAnchorElement>('.site-consent-settings')) {
        control.addEventListener('click', (event) => {
            event.preventDefault();
            banner.hidden = false;
            banner.querySelector<HTMLButtonElement>('[data-consent-choice]')?.focus();
        });
    }
}
