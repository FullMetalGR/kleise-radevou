import { act } from 'react';
import { createRoot } from 'react-dom/client';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { keyed, KEYED_LINES, said } from '../bookingWords';
import { App, CONSOLE_PREFIX, CRASHED_SENTENCE, ErrorBoundary, LOADING_SENTENCE, UNAVAILABLE_SENTENCE, type ConfigSource } from './App';
import type { MountOptions } from './attributes';
import { CANCEL_LABEL, LOADING_BOOKING_SENTENCE, YES_LABEL } from './ManageView';
import { INLINED_NOTICE_ID } from './manage';
import { INLINED_LIST_ID, MY_BOOKINGS_PATH } from './myBookings';
import { boot, mountAll, mountWidget, type BootEnvironment } from './boot';
import { SERVICES_PATH } from './catalog';
import { CONFIG_PATH, INLINED_CONFIG_ID, type BootConfig, type Fetch } from './config';
import { REGISTRY_KEY } from './registry';
import { STYLESHEET_ATTRIBUTE } from './stylesheet';

/*
 * The boot contract, run against a document of this test's own: every mount element once,
 * the noscript child gone on first render, the API origin off the script URL, the hosted
 * page's inlined config used without a request, the loading and the unavailable states, and
 * a second execution changing nothing, junk on the global included. The flow behind the
 * shell is tested on its own; here the catalog request is left on the wire unless a test
 * says otherwise, so the boot is what is under test.
 */

const WIRE = { tenant_name: 'Luna Hair Studio', timezone: 'Europe/Athens', currency: 'EUR', granularity_min: 5, important_info: null, default_language: 'en', language: 'en' };
const CATALOG_WIRE = { services: [{ id: 1, name: 'Haircut', description: '', duration_min: 45, price_minor: 3500, currency: 'EUR', staff: [] }], max_chain_length: 5 };
const NOSCRIPT = '<noscript>Online booking requires JavaScript. Please contact us directly to book.</noscript>';
const CSS = '.kr-booking{--kr-primary:#2563eb}';
const FOREIGN_SCRIPT = 'https://salon-x.kleiseradevou.gr/embed.js';

function json(body: unknown, status = 200): Response {
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

/**
 * The language every request after the config carries, the config's: WIRE names English.
 */
const SPOKEN = '?lang=en';

/**
 * A request URL without its query string, so a path is matched whatever language it carries.
 */
function pathOf(url: string): string {
    return url.split('?')[0] ?? url;
}

/**
 * Answers the config, and leaves every other request pending unless a catalog is given.
 */
function answering(config: unknown = WIRE, status = 200, catalog: unknown = null): Fetch {
    return (url) => {
        if (pathOf(url).endsWith(CONFIG_PATH)) {
            return Promise.resolve(json(config, status));
        }

        return catalog === null ? new Promise<Response>(() => undefined) : Promise.resolve(json(catalog));
    };
}

function scriptWithSrc(src: string): HTMLScriptElement {
    const script = document.createElement('script');

    script.src = src;

    return script;
}

function environment(overrides: Partial<BootEnvironment> = {}): BootEnvironment {
    return {
        document,
        script: scriptWithSrc(FOREIGN_SCRIPT),
        global: window,
        fetch: vi.fn(answering()),
        css: CSS,
        lines: KEYED_LINES,
        ...overrides,
    };
}

/**
 * Boots and lets every scheduled effect, promise continuation and React update settle.
 */
async function bootAndSettle(env: BootEnvironment): Promise<void> {
    await act(async () => {
        boot(env);
        await Promise.resolve();
    });
}

/**
 * Lets the query client's deferred notifications land as well.
 */
async function settleTimers(): Promise<void> {
    await act(async () => {
        for (let turn = 0; turn < 4; turn += 1) {
            await new Promise<void>((resolve) => setTimeout(resolve, 0));
        }
    });
}

function requestsFor(fetchFn: Fetch, path: string): string[] {
    return vi.mocked(fetchFn).mock.calls.map(([url]) => url).filter((url) => pathOf(url).endsWith(path));
}

function mounts(): HTMLElement[] {
    return Array.from(document.querySelectorAll<HTMLElement>('.kr-booking'));
}

function frames(): HTMLElement[] {
    return Array.from(document.querySelectorAll<HTMLElement>('.kr-booking__frame'));
}

beforeEach(() => {
    document.head.innerHTML = '';
    document.body.innerHTML = '';
    document.documentElement.removeAttribute('lang');
    delete (window as unknown as Record<string, unknown>)[REGISTRY_KEY];
});

afterEach(() => {
    vi.restoreAllMocks();
});

describe('boot', () => {
    it('mounts into every mount element once, replacing the noscript child with the shell', async () => {
        document.body.innerHTML = `<div class="kr-booking">${NOSCRIPT}</div><p>host text</p><div class="kr-booking">${NOSCRIPT}</div>`;

        for (const mount of mounts()) {
            expect(mount.childNodes).toHaveLength(1);
            expect(mount.firstElementChild?.tagName).toBe('NOSCRIPT');
        }

        const env = environment();

        await bootAndSettle(env);

        expect(frames()).toHaveLength(2);

        for (const mount of mounts()) {
            expect(mount.querySelector('noscript')).toBeNull();
            expect(mount.querySelector('.kr-booking__estimate-business')?.textContent).toBe('Luna Hair Studio');
            expect(mount.querySelector('.kr-booking__frame')?.getAttribute('data-kr-state')).toBe('ready');
            expect(mount.querySelector('.kr-booking__frame')?.getAttribute('data-kr-step')).toBe('service');
        }

        expect(requestsFor(env.fetch, CONFIG_PATH)).toHaveLength(1);
        expect(document.querySelector('body > p')?.textContent).toBe('host text');
    });

    it('derives the API origin from its own script URL and needs no tenant attribute', async () => {
        document.body.innerHTML = '<div class="kr-booking" data-tenant="somebody-else"></div>';

        const env = environment({ script: scriptWithSrc('https://salon-x.kleiseradevou.gr/embed.js') });

        await bootAndSettle(env);

        expect(requestsFor(env.fetch, CONFIG_PATH)).toEqual([`https://salon-x.kleiseradevou.gr${CONFIG_PATH}`]);
        expect(requestsFor(env.fetch, SERVICES_PATH)).toEqual([`https://salon-x.kleiseradevou.gr${SERVICES_PATH}${SPOKEN}`]);
        expect(frames()).toHaveLength(1);
        expect(JSON.stringify(vi.mocked(env.fetch).mock.calls)).not.toContain('somebody-else');
    });

    it('requests the catalog from the derived origin with credentials omitted, once per mount, and lists it', async () => {
        document.body.innerHTML = '<div class="kr-booking"></div><div class="kr-booking"></div>';

        const env = environment({ fetch: vi.fn(answering(WIRE, 200, CATALOG_WIRE)) });

        await bootAndSettle(env);
        await settleTimers();

        expect(requestsFor(env.fetch, SERVICES_PATH)).toHaveLength(2);
        expect(env.fetch).toHaveBeenCalledWith(`https://salon-x.kleiseradevou.gr${SERVICES_PATH}${SPOKEN}`, { headers: { Accept: 'application/json' }, credentials: 'omit' });

        for (const mount of mounts()) {
            expect(mount.querySelector('.kr-booking__service-name')?.textContent).toBe('Haircut');
        }

        expect(document.activeElement).toBe(document.body);
    });

    it('mounts nothing and says why when there is no script URL to read', async () => {
        document.body.innerHTML = '<div class="kr-booking"></div>';

        const error = vi.spyOn(console, 'error').mockImplementation(() => undefined);
        const env = environment({ script: null });

        await bootAndSettle(env);

        expect(frames()).toHaveLength(0);
        expect(env.fetch).not.toHaveBeenCalled();
        expect(error).toHaveBeenCalledWith(CONSOLE_PREFIX, expect.stringContaining('API origin'));
    });

    it('changes nothing on a second execution: one mount, one request, the first render untouched', async () => {
        document.body.innerHTML = `<div class="kr-booking">${NOSCRIPT}</div>`;

        const env = environment({ fetch: vi.fn(answering(WIRE, 200, CATALOG_WIRE)) });

        await bootAndSettle(env);
        await settleTimers();

        const rendered = document.body.innerHTML;

        expect(frames()).toHaveLength(1);
        expect(rendered).toContain('Haircut');

        await bootAndSettle(env);
        await bootAndSettle(environment({ fetch: env.fetch }));
        await settleTimers();

        expect(frames()).toHaveLength(1);
        expect(document.body.innerHTML).toBe(rendered);
        expect(requestsFor(env.fetch, CONFIG_PATH)).toHaveLength(1);
        expect(requestsFor(env.fetch, SERVICES_PATH)).toHaveLength(1);
        expect(document.head.querySelectorAll(`style[${STYLESHEET_ATTRIBUTE}]`)).toHaveLength(1);
    });

    it('still mounts exactly once when another script left junk on the global', async () => {
        document.body.innerHTML = '<div class="kr-booking"></div>';
        (window as unknown as Record<string, unknown>)[REGISTRY_KEY] = 'junk';

        const env = environment();

        await bootAndSettle(env);
        await bootAndSettle(env);

        expect(frames()).toHaveLength(1);
        expect((window as unknown as Record<string, unknown>)[REGISTRY_KEY]).toBeInstanceOf(WeakSet);
    });

    it('mounts on DOMContentLoaded when the document is still loading', async () => {
        document.body.innerHTML = '<div class="kr-booking"></div>';
        Object.defineProperty(document, 'readyState', { value: 'loading', configurable: true });

        const env = environment();

        try {
            await bootAndSettle(env);

            expect(frames()).toHaveLength(0);
            expect(env.fetch).not.toHaveBeenCalled();

            await act(async () => {
                document.dispatchEvent(new Event('DOMContentLoaded'));
                await Promise.resolve();
            });

            expect(frames()).toHaveLength(1);
        } finally {
            delete (document as unknown as Record<string, unknown>)['readyState'];
        }
    });

    it('installs its stylesheet at the end of head, once, after the host stylesheets', async () => {
        document.head.innerHTML = '<style id="host">p{color:red}</style>';
        document.body.innerHTML = '<div class="kr-booking"></div>';

        await bootAndSettle(environment());

        const styles = Array.from(document.head.querySelectorAll('style'));

        expect(styles.map((style) => style.id || style.getAttribute(STYLESHEET_ATTRIBUTE))).toEqual(['host', '']);
        expect(styles[1]?.textContent).toBe(CSS);
    });

    it('reads the mount attributes and echoes the mode, the step and the manage view on the frame', async () => {
        document.body.innerHTML = '<div class="kr-booking" data-mode=" manage "></div><div class="kr-booking" data-service="12"></div>';

        await bootAndSettle(environment());

        // The trimmed mode is manage, so the first mount is the manage view rather than the
        // flow: it carries no step, and the view it carries is the neutral panel, because a
        // mount naming no credentials asks the server nothing.
        expect(frames().map((frame) => frame.getAttribute('data-kr-mode'))).toEqual(['manage', 'book']);
        expect(frames().map((frame) => frame.getAttribute('data-kr-step'))).toEqual([null, 'staff']);
        expect(frames().map((frame) => frame.getAttribute('data-kr-view'))).toEqual(['neutral', null]);
    });
});

describe('the hosted page', () => {
    const OWN_SCRIPT = `${window.location.origin}/embed.js`;

    it('uses the inlined config without a config request', async () => {
        document.body.innerHTML = `<script type="application/json" id="${INLINED_CONFIG_ID}">${JSON.stringify(WIRE)}</script><div class="kr-booking">${NOSCRIPT}</div>`;

        const env = environment({ script: scriptWithSrc(OWN_SCRIPT) });

        await act(() => {
            boot(env);
        });

        expect(requestsFor(env.fetch, CONFIG_PATH)).toHaveLength(0);
        expect(frames()).toHaveLength(1);
        expect(document.querySelector('.kr-booking noscript')).toBeNull();
        expect(document.querySelector('.kr-booking__estimate-business')?.textContent).toBe('Luna Hair Studio');
        expect(frames()[0]?.getAttribute('data-kr-state')).toBe('ready');
        expect(frames()[0]?.getAttribute('data-kr-step')).toBe('service');
    });

    const LIST_SCRIPT = `<script type="application/json" id="${INLINED_LIST_ID}">${JSON.stringify({ bookings: null, notice: null, csrf: 'csrf' })}</script>`;

    it('renders My bookings in list mode where the mount names it and the page inlined the list beside the config', async () => {
        document.body.innerHTML = `<script type="application/json" id="${INLINED_CONFIG_ID}">${JSON.stringify(WIRE)}</script>${LIST_SCRIPT}<div class="kr-booking" data-mode="list">${NOSCRIPT}</div>`;

        const env = environment({ script: scriptWithSrc(OWN_SCRIPT) });

        await bootAndSettle(env);

        expect(requestsFor(env.fetch, CONFIG_PATH)).toHaveLength(0);
        expect(frames()[0]?.getAttribute('data-kr-mode')).toBe('list');
        expect(frames()[0]?.getAttribute('data-kr-view')).toBe('form');
    });

    it('sends the list mode\'s requests with credentials included and every other mount\'s on the same page with them omitted', async () => {
        const confirmed = {
            uuid: '7b7f0e5a-0000-4000-8000-000000000001',
            status: 'confirmed',
            hold_class: null,
            hold_expires_at: null,
            customer_name: 'Casey Customer',
            customer_email: 'casey@example.com',
            customer_phone: null,
            total_minor: 3500,
            currency: 'EUR',
            items: [{ sort: 0, service_id: 1, service_name: 'Haircut', staff_id: 2, staff_name: 'Elena', start: '2036-11-03T08:00:00+00:00', end: '2036-11-03T08:45:00+00:00', price_minor: 3500 }],
        };
        const listed = `<script type="application/json" id="${INLINED_LIST_ID}">${JSON.stringify({ bookings: { upcoming: [confirmed], past: [] }, notice: null, csrf: 'csrf' })}</script>`;

        document.body.innerHTML = `<script type="application/json" id="${INLINED_CONFIG_ID}">${JSON.stringify(WIRE)}</script>${listed}<div class="kr-booking" data-mode="list"></div><div class="kr-booking"></div>`;

        const env = environment({ script: scriptWithSrc(OWN_SCRIPT), fetch: vi.fn(answering(WIRE, 200, CATALOG_WIRE)) });

        await bootAndSettle(env);
        await settleTimers();

        const list = frames().find((frame) => frame.getAttribute('data-kr-mode') === 'list');
        const press = async (name: string): Promise<void> => {
            await act(() => {
                Array.from(list?.querySelectorAll('button') ?? [])
                    .find((control) => control.textContent === name)
                    ?.click();
            });
            await settleTimers();
        };

        await press(said(CANCEL_LABEL));
        await press(said(YES_LABEL));

        const calls = vi.mocked(env.fetch).mock.calls.map(([url, init]) => [url.replace(window.location.origin, ''), init.method ?? 'GET', init.credentials]);

        expect(calls).toContainEqual([`${SERVICES_PATH}${SPOKEN}`, 'GET', 'omit']);
        expect(calls).toContainEqual([`/api/v1/bookings/${confirmed.uuid}/cancel${SPOKEN}`, 'POST', 'include']);
        expect(calls.filter(([, , credentials]) => credentials === 'include').map(([url]) => url)).toEqual([`/api/v1/bookings/${confirmed.uuid}/cancel${SPOKEN}`, `/en${MY_BOOKINGS_PATH}`]);
    });

    it('reads a mount naming list as book mode on a page that is not on the script origin, whatever the page carries', async () => {
        document.body.innerHTML = `<script type="application/json" id="${INLINED_CONFIG_ID}">${JSON.stringify(WIRE)}</script>${LIST_SCRIPT}<div class="kr-booking" data-mode="list"></div>`;

        const env = environment({ script: scriptWithSrc(FOREIGN_SCRIPT) });

        await bootAndSettle(env);

        expect(requestsFor(env.fetch, CONFIG_PATH)).toHaveLength(1);
        expect(frames()[0]?.getAttribute('data-kr-mode')).toBe('book');
        expect(frames()[0]?.getAttribute('data-kr-step')).toBe('service');
    });

    it('reads a mount naming list as book mode on the hosted origin where the page inlined no list', async () => {
        document.body.innerHTML = `<script type="application/json" id="${INLINED_CONFIG_ID}">${JSON.stringify(WIRE)}</script><div class="kr-booking" data-mode="list"></div>`;

        await bootAndSettle(environment({ script: scriptWithSrc(OWN_SCRIPT) }));

        expect(frames()[0]?.getAttribute('data-kr-mode')).toBe('book');
    });

    const FLASHED = 'Your payment is on its way to us.';
    const NOTICE_SCRIPT = `<script type="application/json" id="${INLINED_NOTICE_ID}">${JSON.stringify({ notice: FLASHED })}</script>`;
    const MANAGE_MOUNT = '<div class="kr-booking" data-mode="manage" data-uuid="7b7f0e5a-0000-4000-8000-000000000001" data-token="tok"></div>';

    it('shows the sentence the manage page inlined beside the config in the manage view', async () => {
        document.body.innerHTML = `<script type="application/json" id="${INLINED_CONFIG_ID}">${JSON.stringify(WIRE)}</script>${NOTICE_SCRIPT}${MANAGE_MOUNT}`;

        await bootAndSettle(environment({ script: scriptWithSrc(OWN_SCRIPT) }));
        await settleTimers();

        expect(frames()[0]?.getAttribute('data-kr-mode')).toBe('manage');
        expect(frames()[0]?.querySelector('[role="status"]')?.textContent).toBe(FLASHED);
    });

    it('trusts no inlined sentence on a page that is not on the script origin', async () => {
        document.body.innerHTML = `<script type="application/json" id="${INLINED_CONFIG_ID}">${JSON.stringify(WIRE)}</script>${NOTICE_SCRIPT}${MANAGE_MOUNT}`;

        await bootAndSettle(environment({ script: scriptWithSrc(FOREIGN_SCRIPT) }));
        await settleTimers();

        expect(frames()[0]?.getAttribute('data-kr-mode')).toBe('manage');
        expect(frames()[0]?.getAttribute('data-kr-view')).toBe('loading');
        expect(document.body.querySelector('.kr-booking')?.textContent).not.toContain(FLASHED);
    });

    it('does not trust an inlined config on a page that is not on the script origin', async () => {
        document.body.innerHTML = `<script type="application/json" id="${INLINED_CONFIG_ID}">${JSON.stringify({ ...WIRE, tenant_name: 'Impostor' })}</script><div class="kr-booking"></div>`;

        const env = environment({ script: scriptWithSrc(FOREIGN_SCRIPT) });

        await bootAndSettle(env);

        expect(requestsFor(env.fetch, CONFIG_PATH)).toHaveLength(1);
        expect(document.querySelector('.kr-booking__estimate-business')?.textContent).toBe('Luna Hair Studio');
    });
});

describe('the embed while the config is on the wire', () => {
    it('shows the loading state until the config lands, requesting no catalog before it', async () => {
        document.body.innerHTML = '<div class="kr-booking"></div>';

        let answer: (value: Response) => void = () => undefined;
        const env = environment({
            fetch: vi.fn(() =>
                new Promise<Response>((resolve) => {
                    answer = resolve;
                }),
            ),
        });

        await bootAndSettle(env);

        const loading = frames()[0];

        expect(loading?.getAttribute('data-kr-state')).toBe('loading');
        expect(loading?.getAttribute('aria-busy')).toBe('true');
        expect(loading?.hasAttribute('data-kr-step')).toBe(false);
        expect(loading?.querySelector('[role="status"]')).not.toBeNull();
        expect(loading?.querySelector('.kr-booking__title')).toBeNull();
        expect(requestsFor(env.fetch, SERVICES_PATH)).toHaveLength(0);

        await act(async () => {
            answer(json(WIRE));
            await Promise.resolve();
            await Promise.resolve();
        });

        const ready = frames()[0];

        expect(frames()).toHaveLength(1);
        expect(ready?.getAttribute('data-kr-state')).toBe('ready');
        expect(ready?.getAttribute('aria-busy')).toBe('false');
        expect(ready?.getAttribute('data-kr-step')).toBe('service');
        expect(ready?.querySelector('.kr-booking__estimate-business')?.textContent).toBe('Luna Hair Studio');
        expect(requestsFor(env.fetch, SERVICES_PATH)).toHaveLength(1);
    });

    it('shows the unavailable state, reported once with the prefix, when the config cannot be loaded', async () => {
        document.body.innerHTML = '<div class="kr-booking"></div><div class="kr-booking"></div>';

        const error = vi.spyOn(console, 'error').mockImplementation(() => undefined);
        const env = environment({ fetch: vi.fn(answering({ message: 'suspended' }, 503)) });

        await bootAndSettle(env);
        await act(async () => {
            await Promise.resolve();
        });

        expect(frames()).toHaveLength(2);

        for (const frame of frames()) {
            expect(frame.getAttribute('data-kr-state')).toBe('unavailable');
            expect(frame.hasAttribute('data-kr-step')).toBe(false);
            expect(frame.querySelector('[role="alert"]')?.textContent).toBe(said(UNAVAILABLE_SENTENCE));
            expect(frame.querySelector('.kr-booking__title')).toBeNull();
        }

        expect(requestsFor(env.fetch, SERVICES_PATH)).toHaveLength(0);
        expect(error).toHaveBeenCalledTimes(1);
        expect(error).toHaveBeenCalledWith(CONSOLE_PREFIX, expect.stringContaining('config'), expect.any(Error));
    });
});

describe('the language', () => {
    /**
     * The config endpoint as the server answers it: the language asked where the lang names one,
     * else the tenant default, English; and the catalog.
     */
    function serverAnswering(): Fetch {
        return (url) => {
            if (pathOf(url).endsWith(CONFIG_PATH)) {
                const asked = new URL(url).searchParams.get('lang');

                return Promise.resolve(json({ ...WIRE, language: (asked === 'en' || asked === 'el') ? asked : 'en' }));
            }

            return Promise.resolve(json(CATALOG_WIRE));
        };
    }

    it.each<[string | null, string | null, string]>([
        ['en', 'en', 'en'],
        ['el', 'el', 'el'],
        ['el-GR', 'el', 'el'],
        ['EL', 'el', 'el'],
        ['fr', null, 'en'],
        ['', null, 'en'],
        [null, null, 'en'],
    ])('starts an embed on a host page of lang %j: asks the config in %j and holds the language it answers, %j, on the mount and on every request', async (tag, asked, held) => {
        if (tag !== null) {
            document.documentElement.setAttribute('lang', tag);
        }

        document.body.innerHTML = '<div class="kr-booking"></div>';

        const env = environment({ fetch: vi.fn(serverAnswering()) });

        await bootAndSettle(env);
        await settleTimers();

        expect(requestsFor(env.fetch, CONFIG_PATH)).toEqual([`https://salon-x.kleiseradevou.gr${CONFIG_PATH}${asked === null ? '' : `?lang=${asked}`}`]);
        expect(requestsFor(env.fetch, SERVICES_PATH)).toEqual([`https://salon-x.kleiseradevou.gr${SERVICES_PATH}?lang=${held}`]);
        expect(mounts()[0]?.getAttribute('lang')).toBe(held);
        expect(document.documentElement.getAttribute('lang')).toBe(tag);
    });

    it('holds on the hosted page the language its inlined config names, asking for no config, on the mount and on every request', async () => {
        document.documentElement.setAttribute('lang', 'el');
        document.body.innerHTML = `<script type="application/json" id="${INLINED_CONFIG_ID}">${JSON.stringify({ ...WIRE, language: 'el' })}</script><div class="kr-booking"></div>`;

        const env = environment({ script: scriptWithSrc(`${window.location.origin}/embed.js`), fetch: vi.fn(answering(WIRE, 200, CATALOG_WIRE)) });

        await bootAndSettle(env);
        await settleTimers();

        expect(requestsFor(env.fetch, CONFIG_PATH)).toHaveLength(0);
        expect(requestsFor(env.fetch, SERVICES_PATH)).toEqual([`${window.location.origin}${SERVICES_PATH}?lang=el`]);
        expect(mounts()[0]?.getAttribute('lang')).toBe('el');
    });

    it('names no language on the mount while the config is on the wire, nor when it never lands', async () => {
        document.body.innerHTML = '<div class="kr-booking"></div><div class="kr-booking"></div>';
        vi.spyOn(console, 'error').mockImplementation(() => undefined);

        await bootAndSettle(environment({ fetch: vi.fn(() => new Promise<Response>(() => undefined)) }));

        expect(mounts().map((mount) => mount.hasAttribute('lang'))).toEqual([false, false]);

        document.body.innerHTML = '<div class="kr-booking"></div>';
        delete (window as unknown as Record<string, unknown>)[REGISTRY_KEY];

        await bootAndSettle(environment({ fetch: vi.fn(answering(WIRE, 500)) }));
        await settleTimers();

        expect(mounts()[0]?.hasAttribute('lang')).toBe(false);
        expect(frames()[0]?.getAttribute('data-kr-state')).toBe('unavailable');
    });
});

describe('mountAll', () => {
    it('answers how many elements it mounted, and zero when every one is mounted already', async () => {
        document.body.innerHTML = '<div class="kr-booking"></div><div class="kr-booking"></div>';

        const env = environment();
        let first = 0;
        let second = 0;

        await act(async () => {
            first = mountAll(env);
            await Promise.resolve();
        });
        await act(async () => {
            second = mountAll(env);
            await Promise.resolve();
        });

        expect(first).toBe(2);
        expect(second).toBe(0);
    });
});

describe('mountWidget', () => {
    it('mounts an element once and is a no-op the second time, leaving the first render untouched', async () => {
        document.body.innerHTML = `<div class="kr-booking">${NOSCRIPT}</div>`;

        const el = mounts()[0] as HTMLElement;
        const registry = new WeakSet<Element>();
        const source = { kind: 'ready', config: { tenantName: 'Luna Hair Studio', timezone: 'Europe/Athens', currency: 'EUR', granularityMin: 5, importantInfo: null, defaultLanguage: 'en', language: 'en' } } as const;
        const api = { origin: 'https://salon-x.kleiseradevou.gr', fetch: answering() };
        let first = false;
        let second = false;

        await act(() => {
            first = mountWidget(el, { registry, source, api });
        });

        const rendered = el.innerHTML;

        await act(() => {
            second = mountWidget(el, { registry, source, api });
        });

        expect(first).toBe(true);
        expect(second).toBe(false);
        expect(registry.has(el)).toBe(true);
        expect(el.innerHTML).toBe(rendered);
        expect(el.querySelector('noscript')).toBeNull();
        expect(el.querySelectorAll('.kr-booking__frame')).toHaveLength(1);
    });
});

describe('ErrorBoundary', () => {
    it('renders one alert sentence and re-reports the error with the prefix', async () => {
        document.body.innerHTML = '<div class="kr-booking"></div>';

        const error = vi.spyOn(console, 'error').mockImplementation(() => undefined);
        const mount = mounts()[0] as HTMLElement;

        function Bomb(): never {
            throw new Error('render crash');
        }

        await act(() => {
            createRoot(mount).render(
                <ErrorBoundary>
                    <Bomb />
                </ErrorBoundary>,
            );
        });

        expect(mount.querySelector('[role="alert"]')?.textContent).toContain('reload the page');
        expect(mount.textContent).not.toContain('render crash');
        expect(error).toHaveBeenCalledWith(CONSOLE_PREFIX, expect.stringContaining('crashed'), expect.any(Error));
    });
});

/**
 * Every mutation the render makes under the element, in order, taken whole once the render and
 * its effects have landed.
 */
async function recordWhile(el: HTMLElement, render: () => void): Promise<MutationRecord[]> {
    const records: MutationRecord[] = [];
    const observer = new MutationObserver((batch) => records.push(...batch));

    observer.observe(el, { childList: true, subtree: true, characterData: true });

    await act(async () => {
        render();
        await Promise.resolve();
    });

    records.push(...observer.takeRecords());
    observer.disconnect();

    return records;
}

function isRegion(node: Node, role: string): boolean {
    return node instanceof HTMLElement && (node.getAttribute('role') === role || node.querySelector(`[role="${role}"]`) !== null);
}

/**
 * The polite region's insertion and its text as two writes, the text strictly later: the
 * record that inserts the region, and the first record that writes into it.
 */
function politeOrder(records: readonly MutationRecord[]): { readonly insertion: number; readonly text: number } {
    return {
        insertion: records.findIndex((record) => Array.from(record.addedNodes).some((node) => isRegion(node, 'status'))),
        text: records.findIndex((record) => record.target instanceof HTMLElement && record.target.getAttribute('role') === 'status' && record.addedNodes.length > 0),
    };
}

const SHELL_OPTIONS: MountOptions = { mode: 'book', serviceId: null, staffId: null, uuid: null, token: null, channel: null };

const SHELL_CONFIG: BootConfig = { tenantName: 'Luna Hair Studio', timezone: 'Europe/Athens', currency: 'EUR', granularityMin: 5, importantInfo: null, defaultLanguage: 'en', language: 'en' };

/** A fetch that never answers, so whatever the shell is waiting on stays on the wire. */
const SILENT: Fetch = () => new Promise<Response>(() => undefined);

describe('the shell\'s notices go through the one region, 7.3', () => {
    let el: HTMLDivElement;

    beforeEach(() => {
        document.body.innerHTML = '';
        el = document.createElement('div');
        document.body.appendChild(el);
    });

    async function renderApp(source: ConfigSource, options: MountOptions = SHELL_OPTIONS): Promise<MutationRecord[]> {
        return recordWhile(el, () => {
            createRoot(el).render(<App source={source} options={options} api={{ origin: 'https://luna.kleiseradevou.gr', fetch: SILENT }} lines={KEYED_LINES} />);
        });
    }

    it('mounts the loading line\'s polite region empty and writes the sentence into it one effect later', async () => {
        const records = await renderApp({ kind: 'pending', promise: new Promise(() => undefined) });
        const { insertion, text } = politeOrder(records);

        expect(insertion).toBeGreaterThan(-1);
        expect(text).toBeGreaterThan(insertion);
        expect(el.querySelector('[role="status"]')?.textContent).toBe(said(LOADING_SENTENCE));
        expect(el.querySelector('[role="alert"]')).toBeNull();
    });

    it('announces the unavailable sentence as an alert on appearance, born with its text, the polite region beside it empty', async () => {
        const source: ConfigSource = { kind: 'pending', promise: Promise.reject(new Error('suspended')) };

        await renderApp(source);

        const records = await recordWhile(el, () => undefined);
        const alert = el.querySelector('[role="alert"]');

        expect(alert?.textContent).toBe(said(UNAVAILABLE_SENTENCE));
        expect(el.querySelector('[role="status"]')?.textContent).toBe('');
        // Nothing wrote into the alert after it appeared: it came into the DOM holding its text.
        expect(records.filter((record) => record.target === alert)).toEqual([]);
    });

    it('inserts the unavailable alert with its text already inside it', async () => {
        let refuse: (error: Error) => void = () => undefined;
        const source: ConfigSource = {
            kind: 'pending',
            promise: new Promise((_, reject) => {
                refuse = reject;
            }),
        };

        await renderApp(source);

        const records = await recordWhile(el, () => refuse(new Error('suspended')));
        const alert = el.querySelector('[role="alert"]');
        const inserted = records.findIndex((record) => Array.from(record.addedNodes).some((node) => isRegion(node, 'alert')));

        expect(inserted).toBeGreaterThan(-1);
        expect(alert?.textContent).toBe(said(UNAVAILABLE_SENTENCE));
        expect(records.slice(inserted + 1).filter((record) => record.target === alert || alert?.contains(record.target) === true)).toEqual([]);
    });

    it('renders the crash sentence through the region as an alert, the polite region empty', async () => {
        vi.spyOn(console, 'error').mockImplementation(() => undefined);

        function Bomb(): never {
            throw new Error('render crash');
        }

        await recordWhile(el, () => {
            createRoot(el).render(
                keyed(
                    <ErrorBoundary>
                        <Bomb />
                    </ErrorBoundary>,
                ),
            );
        });

        expect(el.querySelector('[role="alert"]')?.textContent).toBe(said(CRASHED_SENTENCE));
        expect(el.querySelector('[role="status"]')?.textContent).toBe('');
    });

    it('mounts the manage view\'s loading line into a polite region that was empty first', async () => {
        const records = await renderApp({ kind: 'ready', config: SHELL_CONFIG }, { ...SHELL_OPTIONS, mode: 'manage', uuid: '7b7f0e5a-4444-4000-8000-00000000000c', token: 'ab12'.repeat(16) });
        const { insertion, text } = politeOrder(records);

        expect(el.querySelector('.kr-booking__frame')?.getAttribute('data-kr-view')).toBe('loading');
        expect(insertion).toBeGreaterThan(-1);
        expect(text).toBeGreaterThan(insertion);
        expect(el.querySelector('[role="status"]')?.textContent).toBe(said(LOADING_BOOKING_SENTENCE));
    });
});
