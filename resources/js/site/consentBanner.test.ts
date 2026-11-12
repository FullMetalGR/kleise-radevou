import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { CONSENT_COOKIE, startConsent } from './consentBanner';

/*
 * The consent script, over the banner's markup as the server renders it: the choice in a
 * host-only cookie of its own, the loader's order, and Reject's expiry and reload. The page's
 * cookies are jsdom's own, read and written through a jar that records every string written,
 * since a cookie read back carries no attributes. Nothing loads: jsdom fetches no script, so
 * the inserted element is read where it was put.
 */

const MEASUREMENT_ID = 'G-TEST1234';
const SCRIPT = 'https://www.googletagmanager.com/gtag/js';
const LIFETIME = 180 * 24 * 60 * 60;

interface RecordingJar {
    cookie: string;
    readonly written: string[];
}

function recordingJar(): RecordingJar {
    const written: string[] = [];

    return {
        written,
        get cookie(): string {
            return document.cookie;
        },
        set cookie(value: string) {
            written.push(value);
            document.cookie = value;
        },
    };
}

function cookieNames(): string[] {
    return document.cookie
        .split(';')
        .map((pair) => pair.trim().split('=')[0] ?? '')
        .filter((name) => name !== '');
}

function renderBanner(): void {
    document.body.innerHTML = `
        <footer><a href="/cookies" class="site-consent-settings">Cookie settings</a></footer>
        <section class="site-consent" aria-label="Cookie choice" data-measurement-id="${MEASUREMENT_ID}" data-script="${SCRIPT}" hidden>
            <p class="site-consent-sentence">We would like to count visits. <a href="/cookies">Read the cookie notice</a></p>
            <div class="site-consent-choices">
                <button type="button" class="site-consent-choice" data-consent-choice="granted">Accept</button>
                <button type="button" class="site-consent-choice" data-consent-choice="denied">Reject</button>
            </div>
        </section>`;
}

function banner(): HTMLElement {
    const element = document.querySelector<HTMLElement>('.site-consent');

    if (element === null) {
        throw new Error('The banner is not on the page.');
    }

    return element;
}

function press(choice: 'granted' | 'denied'): void {
    document.querySelector<HTMLButtonElement>(`[data-consent-choice="${choice}"]`)?.click();
}

/**
 * Clicks the footer's control and answers whether the script kept the browser from following
 * it. A listener on the window, which the click reaches last, reads that and then keeps jsdom
 * from following it either, since jsdom navigates nowhere.
 */
function openSettings(): boolean {
    let prevented = false;
    const settle = (event: Event): void => {
        prevented = event.defaultPrevented;
        event.preventDefault();
    };

    window.addEventListener('click', settle, { once: true });
    document.querySelector('.site-consent-settings')?.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));

    return prevented;
}

function tagScripts(): HTMLScriptElement[] {
    return Array.from(document.head.querySelectorAll('script'));
}

/** The data layer's entries as the calls that pushed them, each an arguments object. */
function layerCalls(): unknown[][] {
    return (window.dataLayer ?? []).map((entry) => {
        expect(Object.prototype.toString.call(entry)).toBe('[object Arguments]');

        return Array.from(entry as ArrayLike<unknown>);
    });
}

function start(jar: RecordingJar, reload: () => void = vi.fn(), secure = false): void {
    startConsent({ document, window, cookies: jar, secure, reload });
}

beforeEach(() => {
    renderBanner();
});

afterEach(() => {
    for (const name of cookieNames()) {
        document.cookie = `${name}=; Max-Age=0; Path=/`;
    }

    document.head.innerHTML = '';
    document.body.innerHTML = '';
    delete window.dataLayer;
    delete window.gtag;
    vi.restoreAllMocks();
});

describe('the choice', () => {
    it('shows the banner on a page load that finds no choice, and loads nothing', () => {
        const jar = recordingJar();

        start(jar);

        expect(banner().hidden).toBe(false);
        expect(jar.written).toEqual([]);
        expect(tagScripts()).toEqual([]);
        expect(window.dataLayer).toBeUndefined();
    });

    it('writes Accept as granted in a host-only cookie of its own, 180 days, Path=/ and SameSite=Lax, and hides the banner', () => {
        const jar = recordingJar();

        start(jar);
        press('granted');

        expect(jar.written).toEqual([`${CONSENT_COOKIE}=granted; Max-Age=${LIFETIME}; Path=/; SameSite=Lax`]);
        expect(jar.written[0]).not.toMatch(/domain/i);
        expect(document.cookie).toContain(`${CONSENT_COOKIE}=granted`);
        expect(banner().hidden).toBe(true);
    });

    it('marks the cookie Secure where the host is', () => {
        const jar = recordingJar();

        start(jar, vi.fn(), true);
        press('denied');

        expect(jar.written[0]).toBe(`${CONSENT_COOKIE}=denied; Max-Age=${LIFETIME}; Path=/; SameSite=Lax; Secure`);
    });

    it('shows no banner and loads nothing on a page load that finds denied', () => {
        document.cookie = `${CONSENT_COOKIE}=denied; Path=/`;

        start(recordingJar());

        expect(banner().hidden).toBe(true);
        expect(tagScripts()).toEqual([]);
        expect(window.dataLayer).toBeUndefined();
    });

    it('asks again where the stored value is neither choice', () => {
        document.cookie = `${CONSENT_COOKIE}=maybe; Path=/`;

        start(recordingJar());

        expect(banner().hidden).toBe(false);
        expect(tagScripts()).toEqual([]);
    });

    it('does nothing on a page with no banner, leaving the footer\'s link a link', () => {
        document.body.innerHTML = '<footer><a href="/cookies" class="site-consent-settings">Cookie settings</a></footer>';
        const jar = recordingJar();

        start(jar);

        expect(openSettings()).toBe(false);
        expect(jar.written).toEqual([]);
        expect(window.dataLayer).toBeUndefined();
    });
});

describe('the loader', () => {
    it('sets Consent Mode\'s denied default for the four signals, updates analytics_storage to granted, inserts the script with the id, then configures the tag with cookie_domain none', () => {
        const callsAtInsert: unknown[][][] = [];
        const append = document.head.appendChild.bind(document.head);
        vi.spyOn(document.head, 'appendChild').mockImplementation(<T extends Node>(node: T): T => {
            callsAtInsert.push(layerCalls());

            return append(node);
        });

        start(recordingJar());
        press('granted');

        const calls = layerCalls();
        const scripts = tagScripts();

        expect(calls[0]).toEqual(['consent', 'default', { analytics_storage: 'denied', ad_storage: 'denied', ad_user_data: 'denied', ad_personalization: 'denied' }]);
        expect(calls[1]).toEqual(['consent', 'update', { analytics_storage: 'granted' }]);
        expect(calls[2]?.[0]).toBe('js');
        expect(calls[2]?.[1]).toBeInstanceOf(Date);
        expect(calls[3]).toEqual(['config', MEASUREMENT_ID, { cookie_domain: 'none' }]);
        expect(calls).toHaveLength(4);
        expect(callsAtInsert).toEqual([calls.slice(0, 2)]);
        expect(scripts).toHaveLength(1);
        expect(scripts[0]?.src).toBe(`${SCRIPT}?id=${MEASUREMENT_ID}`);
        expect(scripts[0]?.async).toBe(true);
        expect(typeof window.gtag).toBe('function');
    });

    it('loads on a page load that finds granted, with no banner shown', () => {
        document.cookie = `${CONSENT_COOKIE}=granted; Path=/`;

        start(recordingJar());

        expect(banner().hidden).toBe(true);
        expect(tagScripts()).toHaveLength(1);
        expect(layerCalls()[0]?.slice(0, 2)).toEqual(['consent', 'default']);
    });

    it('loads the tag once on a page however often Accept is pressed', () => {
        start(recordingJar());
        press('granted');
        openSettings();
        press('granted');

        expect(tagScripts()).toHaveLength(1);
        expect(layerCalls().filter((call) => call[0] === 'config')).toHaveLength(1);
    });
});

describe('cookie settings and Reject', () => {
    it('reopens the banner from the footer\'s control without following its link, focusing the first choice', () => {
        document.cookie = `${CONSENT_COOKIE}=denied; Path=/`;
        start(recordingJar());

        expect(openSettings()).toBe(true);
        expect(banner().hidden).toBe(false);
        expect(document.activeElement).toBe(document.querySelector('[data-consent-choice="granted"]'));
    });

    it('writes Reject as denied, expires the tag\'s _ga cookies alone and, where the tag never ran, stays on the page', () => {
        document.cookie = '_ga=GA1.1.123.456; Path=/';
        document.cookie = `_ga_${MEASUREMENT_ID.slice(2)}=GS1.1.789; Path=/`;
        document.cookie = 'kept=1; Path=/';
        const jar = recordingJar();
        const reload = vi.fn();

        start(jar, reload);
        press('denied');

        expect(jar.written).toEqual([
            `${CONSENT_COOKIE}=denied; Max-Age=${LIFETIME}; Path=/; SameSite=Lax`,
            '_ga=; Max-Age=0; Path=/',
            `_ga_${MEASUREMENT_ID.slice(2)}=; Max-Age=0; Path=/`,
        ]);
        expect(cookieNames().sort()).toEqual([CONSENT_COOKIE, 'kept']);
        expect(banner().hidden).toBe(true);
        expect(reload).not.toHaveBeenCalled();
        expect(tagScripts()).toEqual([]);
    });

    it('reloads on Reject where the tag had run on the page, after Accept', () => {
        const reload = vi.fn();

        start(recordingJar(), reload);
        press('granted');
        document.cookie = '_ga=GA1.1.123.456; Path=/';
        openSettings();
        press('denied');

        expect(document.cookie).toContain(`${CONSENT_COOKIE}=denied`);
        expect(cookieNames()).not.toContain('_ga');
        expect(reload).toHaveBeenCalledTimes(1);
    });

    it('reloads on Reject where the tag had run on a page load that found granted', () => {
        document.cookie = `${CONSENT_COOKIE}=granted; Path=/`;
        const reload = vi.fn();

        start(recordingJar(), reload);
        openSettings();
        press('denied');

        expect(document.cookie).toContain(`${CONSENT_COOKIE}=denied`);
        expect(reload).toHaveBeenCalledTimes(1);
    });
});
