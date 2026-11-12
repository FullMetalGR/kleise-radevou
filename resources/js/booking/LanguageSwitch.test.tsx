import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { KEYED_LINES } from '../bookingWords';
import { App, LOADING_SENTENCE, UNAVAILABLE_SENTENCE, type ConfigSource } from './App';
import type { Api } from './api';
import type { MountOptions } from './attributes';
import { AVAILABILITY_PATH } from './availability';
import { boot, type BootEnvironment } from './boot';
import { SERVICES_PATH } from './catalog';
import { CONFIG_PATH, type BootConfig, type Fetch } from './config';
import { STEP_TITLES } from './Flow';
import { switchHrefOf, type Language } from './language';
import { LANGUAGE_NAME } from './LanguageSwitch';
import { REGISTRY_KEY } from './registry';
import { lineOf, type LineKey, type Lines, type Table } from './words';

/*
 * The language switch and the start language. The switch renders beside the view, labelled by the
 * other language's own name in that language and carrying its lang: on the hosted page a link to
 * the same path under the other language's prefix, in the embed an in-app control that changes the
 * language the app holds with no request, the mount's lang and every later call following it. The
 * sentences before the config are read in the start language, English where the host page names
 * neither. The lines are the keyed table and a keyed Greek twin of it, each line its key in
 * brackets marked by its language, so a render shows which language it read.
 */

/** The keyed table again, each line marked as the Greek one. */
function greekOf(table: Table): Table {
    return Object.fromEntries(Object.entries(table).map(([name, value]) => [name, typeof value === 'string' ? `[el ${value.slice(1)}` : greekOf(value)]));
}

const LINES: Lines = { en: KEYED_LINES.en, el: greekOf(KEYED_LINES.en) };

/** A key's line in one language of the test's lines. */
function lineIn(language: Language, key: LineKey): string {
    return lineOf(LINES[language], key) ?? key;
}

const CONFIG: BootConfig = { tenantName: 'Luna Hair Studio', timezone: 'Europe/Athens', currency: 'EUR', granularityMin: 5, importantInfo: null, defaultLanguage: 'en', language: 'en' };
const WIRE = { tenant_name: 'Luna Hair Studio', timezone: 'Europe/Athens', currency: 'EUR', granularity_min: 5, important_info: null, default_language: 'en', language: 'en' };
const CATALOG = { services: [{ id: 1, name: 'Haircut', description: '', duration_min: 45, price_minor: 3500, currency: 'EUR', staff: [] }], max_chain_length: 5 };
const OPTIONS: MountOptions = { mode: 'book', serviceId: null, staffId: null, uuid: null, token: null, channel: null };
const SILENT: Fetch = () => new Promise<Response>(() => undefined);
const API: Api = { origin: 'https://luna.kleiseradevou.gr', fetch: SILENT };

let container: HTMLDivElement;
let root: Root;

beforeEach(() => {
    container = document.createElement('div');
    document.body.appendChild(container);
    root = createRoot(container);
});

afterEach(async () => {
    await act(() => {
        root.unmount();
    });
    container.remove();
    document.body.innerHTML = '';
    document.documentElement.removeAttribute('lang');
    delete (window as unknown as Record<string, unknown>)[REGISTRY_KEY];
});

interface Rendering {
    readonly source?: ConfigSource;
    readonly start?: Language | null;
    readonly page?: { readonly pathname: string; readonly search: string } | null;
    readonly onLanguage?: (language: Language) => void;
}

async function render({ source = { kind: 'ready', config: CONFIG }, start = null, page = null, onLanguage }: Rendering): Promise<void> {
    await act(() => {
        root.render(<App source={source} options={OPTIONS} api={API} lines={LINES} start={start} page={page} onLanguage={onLanguage} />);
    });
}

function control(): HTMLElement | null {
    return container.querySelector('.kr-booking__language');
}

function title(): string | null | undefined {
    return container.querySelector('.kr-booking__title')?.textContent;
}

describe('the switch', () => {
    it('is, in the embed, a button labelled by the other language\'s own name in that language and carrying its lang', async () => {
        await render({});

        expect(control()?.tagName).toBe('BUTTON');
        expect(control()?.getAttribute('type')).toBe('button');
        expect(control()?.textContent).toBe(lineIn('el', LANGUAGE_NAME));
        expect(control()?.getAttribute('lang')).toBe('el');
    });

    it('changes the language the app holds when pressed, with no request, and tells the mount; pressed again, back', async () => {
        const told = vi.fn<(language: Language) => void>();
        const fetchFn = vi.fn(SILENT);

        await act(() => {
            root.render(<App source={{ kind: 'ready', config: CONFIG }} options={OPTIONS} api={{ ...API, fetch: fetchFn }} lines={LINES} onLanguage={told} />);
        });

        const asked = fetchFn.mock.calls.length;

        await act(() => {
            control()?.click();
        });

        expect(fetchFn.mock.calls).toHaveLength(asked);
        expect(told).toHaveBeenLastCalledWith('el');
        expect(title()).toBe(lineIn('el', STEP_TITLES.service));
        expect(control()?.textContent).toBe(lineIn('en', LANGUAGE_NAME));
        expect(control()?.getAttribute('lang')).toBe('en');

        await act(() => {
            control()?.click();
        });

        expect(told).toHaveBeenLastCalledWith('en');
        expect(title()).toBe(lineIn('en', STEP_TITLES.service));
    });

    it('is, on the hosted page, a link to the same path under the other language\'s prefix, its query kept, as TenantPages::addresses leads', async () => {
        const page = { pathname: '/el/manage/0b9e6c1a-2f3d-4a5b-8c7d-9e0f1a2b3c4d', search: '?token=abc' };

        await render({ source: { kind: 'ready', config: { ...CONFIG, language: 'el' } }, page });

        expect(control()?.tagName).toBe('A');
        expect(control()?.getAttribute('href')).toBe(switchHrefOf(page.pathname, page.search, 'el'));
        expect(control()?.getAttribute('href')).toBe('/en/manage/0b9e6c1a-2f3d-4a5b-8c7d-9e0f1a2b3c4d?token=abc');
        expect(control()?.getAttribute('hreflang')).toBe('en');
        expect(control()?.getAttribute('lang')).toBe('en');
        expect(control()?.textContent).toBe(lineIn('en', LANGUAGE_NAME));
        expect(title()).toBe(lineIn('el', STEP_TITLES.service));
    });
});

describe('the sentences before the config', () => {
    const PENDING: ConfigSource = { kind: 'pending', promise: new Promise<BootConfig>(() => undefined) };

    it.each<[string, Language | null, Language]>([
        ['a Greek host page', 'el', 'el'],
        ['an English host page', 'en', 'en'],
        ['a host page naming neither', null, 'en'],
    ])('read the loading line on %s in %s', async (_name, start, language) => {
        await render({ source: PENDING, start });

        expect(container.querySelector('[role="status"]')?.textContent).toBe(lineIn(language, LOADING_SENTENCE));
    });

    it('read the unavailable sentence in the start language too', async () => {
        const refused: ConfigSource = { kind: 'pending', promise: Promise.reject(new Error('suspended')) };

        await render({ source: refused, start: 'el' });
        await act(async () => {
            await Promise.resolve();
        });

        expect(container.querySelector('[role="alert"]')?.textContent).toBe(lineIn('el', UNAVAILABLE_SENTENCE));
    });
});

describe('the embed\'s switch through the boot', () => {
    function json(body: unknown): Response {
        return new Response(JSON.stringify(body), { status: 200, headers: { 'Content-Type': 'application/json' } });
    }

    it('moves the mount\'s lang with the language, and every later call carries the new one', async () => {
        const fetchFn = vi.fn<Fetch>((url) => {
            const path = url.split('?')[0] ?? url;

            if (path.endsWith(CONFIG_PATH)) {
                return Promise.resolve(json(WIRE));
            }

            return path.endsWith(SERVICES_PATH) ? Promise.resolve(json(CATALOG)) : new Promise<Response>(() => undefined);
        });
        const script = document.createElement('script');

        script.src = 'https://salon-x.kleiseradevou.gr/embed.js';
        document.body.innerHTML = '<div class="kr-booking"></div>';

        const env: BootEnvironment = { document, script, global: window, fetch: fetchFn, css: '', lines: LINES };
        const mount = (): HTMLElement => document.querySelector('.kr-booking') as HTMLElement;
        const settle = async (): Promise<void> => {
            await act(async () => {
                for (let turn = 0; turn < 4; turn += 1) {
                    await new Promise<void>((resolve) => setTimeout(resolve, 0));
                }
            });
        };

        await act(async () => {
            boot(env);
            await Promise.resolve();
        });
        await settle();

        expect(mount().getAttribute('lang')).toBe('en');

        const asked = fetchFn.mock.calls.length;

        await act(() => {
            mount().querySelector<HTMLElement>('.kr-booking__language')?.click();
        });

        expect(fetchFn.mock.calls).toHaveLength(asked);
        expect(mount().getAttribute('lang')).toBe('el');

        await act(() => {
            mount().querySelector<HTMLElement>('.kr-booking__service')?.click();
        });
        await act(() => {
            mount().querySelector<HTMLElement>('.kr-booking__continue')?.click();
        });
        await settle();

        const urls = fetchFn.mock.calls.map(([url]) => url);

        expect(urls.filter((url) => url.includes(SERVICES_PATH))).toEqual([`https://salon-x.kleiseradevou.gr${SERVICES_PATH}?lang=en`]);
        expect(urls.filter((url) => url.includes(AVAILABILITY_PATH)).every((url) => url.endsWith('&lang=el'))).toBe(true);
        expect(urls.filter((url) => url.includes(AVAILABILITY_PATH))).not.toHaveLength(0);
    });
});
