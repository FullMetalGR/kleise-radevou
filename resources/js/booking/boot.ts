import { createElement } from 'react';
import { createRoot } from 'react-dom/client';
import type { Api } from './api';
import { App, CONSOLE_PREFIX, type ConfigSource } from './App';
import { credentialsFor, readMountOptions } from './attributes';
import { apiOriginOf, fetchConfig, inlinedConfig, type BootConfig, type Fetch } from './config';
import { hostLanguageOf, type Language } from './language';
import type { PageAddress } from './LanguageSwitch';
import { inlinedNotice } from './manage';
import { inlinedMyBookings, type MyBookingsPage } from './myBookings';
import { adoptRegistry } from './registry';
import { installStylesheet } from './stylesheet';
import type { Lines } from './words';

/*
 * The boot contract. The bundle self-boots on import: it mounts immediately, or on
 * DOMContentLoaded if the document is still loading. mountAll() finds every mount element on
 * the page and mounts each once; mountWidget() mounts one, and a second call for the same
 * element is a no-op that leaves the first render untouched. The config is loaded once per
 * boot and shared by every mount on the page, and a boot that finds nothing left to mount
 * loads nothing: pasting the script tag twice costs no second request.
 */

const MOUNT_SELECTOR = '.kr-booking';

/**
 * What the entry hands the boot: the document, the executing script element, the global the
 * registry lives on, fetch, and the stylesheet the bundle carries. Handed in rather than read
 * from globals so the component tests can boot into a document of their own.
 */
export interface BootEnvironment {
    readonly document: Document;
    readonly script: unknown;
    readonly global: object;
    readonly fetch: Fetch;
    readonly css: string;
    /** Both languages' lines, the bundle's own unless a test hands others in. */
    readonly lines?: Lines;
}

export interface MountDependencies {
    readonly registry: WeakSet<Element>;
    readonly source: ConfigSource;
    /** The origin every request goes to, derived from the script URL, and the fetch to use. */
    readonly api: Api;
    /**
     * My bookings' list: read off the page only where the config source is the inlined one, and
     * null everywhere else, which is what makes a mount naming list book mode.
     */
    readonly myBookings?: MyBookingsPage | null;
    /**
     * The sentence the manage page inlined beside the config: read off the page only where the
     * config source is the inlined one, as the list is, and null everywhere else.
     */
    readonly notice?: string | null;
    /** The host page's language, the start of the sentences rendered before the config. */
    readonly start?: Language | null;
    /**
     * The hosted page's address, read where the config source is the inlined one, which the
     * switch's link is computed from; null in the embed, whose switch is an in-app control.
     */
    readonly page?: PageAddress | null;
    /** The lines the boot was handed, for the app. */
    readonly lines?: Lines;
}

export function boot(env: BootEnvironment): void {
    if (env.document.readyState === 'loading') {
        env.document.addEventListener('DOMContentLoaded', () => mountAll(env), { once: true });

        return;
    }

    mountAll(env);
}

/**
 * Mounts into every mount element not mounted yet and answers how many that was.
 */
export function mountAll(env: BootEnvironment): number {
    const registry = adoptRegistry(env.global);
    const pending = Array.from(env.document.querySelectorAll(MOUNT_SELECTOR)).filter((el) => !registry.has(el));

    if (pending.length === 0) {
        return 0;
    }

    const apiOrigin = apiOriginOf(env.script);

    if (apiOrigin === null) {
        console.error(CONSOLE_PREFIX, 'The embed script has no URL to derive the API origin from; nothing was mounted.');

        return 0;
    }

    installStylesheet(env.document, env.css);

    const start = hostLanguageOf(env.document);
    const source = configSource(env, apiOrigin, start);
    const api: Api = { origin: apiOrigin, fetch: env.fetch };
    // The list is the hosted page's, read where its config is: on the script's own origin and
    // beside an inlined config, never on a page a tenant's site serves; so is the page's address.
    const hosted = source.kind === 'ready';
    const myBookings = hosted ? inlinedMyBookings(env.document) : null;
    const notice = hosted ? inlinedNotice(env.document) : null;
    const page = hosted ? env.document.location : null;
    let mounted = 0;

    for (const el of pending) {
        if (mountWidget(el, { registry, source, api, myBookings, notice, start, page, lines: env.lines })) {
            mounted += 1;
        }
    }

    return mounted;
}

/**
 * Mounts one element and answers whether it did; false means it was mounted already. The
 * React root replaces the element's children on first render, which is how the noscript
 * sentence goes with no hiding logic and no flash.
 */
export function mountWidget(el: Element, deps: MountDependencies): boolean {
    if (deps.registry.has(el)) {
        return false;
    }

    deps.registry.add(el);

    const myBookings = deps.myBookings ?? null;
    const options = readMountOptions(el, myBookings !== null);
    // The mount's credentials mode: include in list mode alone, which is read only where the page
    // inlined the list beside the config on the script's own origin, and omit everywhere else, so
    // the embed never sends a cookie.
    const api: Api = { ...deps.api, credentials: credentialsFor(options.mode) };

    const onLanguage = (language: Language): void => {
        el.setAttribute('lang', language);
    };

    createRoot(el).render(
        createElement(App, { source: deps.source, options, api, myBookings, notice: deps.notice ?? null, start: deps.start ?? null, page: deps.page ?? null, onLanguage, lines: deps.lines }),
    );
    adoptLanguage(deps.source, onLanguage);

    return true;
}

/**
 * The mount carries the app's language as its lang once the config names it, since it sits
 * on a host page that may speak another: at once off an inlined config, and when the
 * fetched one lands; a config that never lands names none. The switch moves it with the
 * language it chooses.
 */
function adoptLanguage(source: ConfigSource, onLanguage: (language: Language) => void): void {
    const adopt = (config: BootConfig): void => {
        onLanguage(config.language);
    };

    if (source.kind === 'ready') {
        adopt(source.config);

        return;
    }

    source.promise.then(adopt, () => undefined);
}

/**
 * The inlined config is the hosted page's, so it is read only when the page is on the
 * script's own origin; a foreign page carrying an element by that id is not trusted for it
 * and the config is fetched, asked in the host page's language where its <html lang> names
 * English or Greek, and the language it answers is the one the app holds. A failed fetch is
 * reported once, here, whatever the number of mounts sharing it.
 */
function configSource(env: BootEnvironment, apiOrigin: string, start: Language | null): ConfigSource {
    if (env.document.location.origin === apiOrigin) {
        const inlined = inlinedConfig(env.document);

        if (inlined !== null) {
            return { kind: 'ready', config: inlined };
        }
    }

    const promise = fetchConfig(apiOrigin, env.fetch, start);

    promise.catch((error: unknown) => {
        console.error(CONSOLE_PREFIX, 'The booking config could not be loaded; online booking is unavailable on this page.', error);
    });

    return { kind: 'pending', promise };
}
