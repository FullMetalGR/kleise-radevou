import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { englishLeft, GREEK_WORDS, keyed } from '../bookingWords';
import { App, CRASHED_SENTENCE, ErrorBoundary, LOADING_SENTENCE, UNAVAILABLE_SENTENCE } from './App';
import type { MountOptions } from './attributes';
import { boot, type BootEnvironment } from './boot';
import { SERVICES_PATH } from './catalog';
import { CONFIG_PATH, INLINED_CONFIG_ID, type BootConfig, type Fetch } from './config';
import { STEP_TITLES } from './Flow';
import { LANGUAGE_NAME } from './LanguageSwitch';
import { BOOKING_LINES_EL, BOOKING_LINES_EN } from './lines.generated';
import { REGISTRY_KEY } from './registry';
import { choose } from './choose';
import { BUNDLED_LINES, lineOf } from './words';

/*
 * The booking app in Greek: the bundle carries lang/el/booking.php as its Greek table, the
 * sentences rendered before or without the config ride in it and are read in the start language,
 * the embed's on a Greek host page and the hosted page's under /el, and the switch labels the
 * way back by English's own name and links to the same path under /en, a token path included.
 * Every assertion reads the Greek through the generated module and the English as what must be
 * absent, so no sentence is spelled here in either language. The flow, the manage page and My
 * bookings are walked in their own test files with the same englishLeft.
 */

const CONFIG: BootConfig = { tenantName: 'Luna Hair Studio', timezone: 'Europe/Athens', currency: 'EUR', granularityMin: 5, importantInfo: null, defaultLanguage: 'en', language: 'el' };
const WIRE = { tenant_name: 'Luna Hair Studio', timezone: 'Europe/Athens', currency: 'EUR', granularity_min: 5, important_info: null, default_language: 'en', language: 'el' };
const CATALOG = { services: [{ id: 1, name: 'Haircut', description: '', duration_min: 45, price_minor: 3500, currency: 'EUR', staff: [] }], max_chain_length: 5 };
const OPTIONS: MountOptions = { mode: 'book', serviceId: null, staffId: null, uuid: null, token: null, channel: null };
const SILENT: Fetch = () => new Promise<Response>(() => undefined);

function json(body: unknown, status = 200): Response {
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

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
    vi.restoreAllMocks();
});

async function settle(): Promise<void> {
    await act(async () => {
        for (let turn = 0; turn < 6; turn += 1) {
            await new Promise<void>((resolve) => setTimeout(resolve, 0));
        }
    });
}

/** The embed's mount on the host page. */
function mount(): HTMLElement {
    return document.querySelector('.kr-booking') as HTMLElement;
}

/** An embed booted on this document with the bundle's own lines. */
async function bootEmbed(fetchFn: Fetch, script = 'https://luna.kleiseradevou.gr/embed.js'): Promise<void> {
    const element = document.createElement('script');

    element.src = script;

    const env: BootEnvironment = { document, script: element, global: window, fetch: fetchFn, css: '', lines: BUNDLED_LINES };

    await act(async () => {
        boot(env);
        await Promise.resolve();
    });
}

describe('the Greek table', () => {
    it('is lang/el/booking.php key for key, each choice counted in the same cases as its English', () => {
        const keysOf = (table: object, prefix = ''): string[] =>
            Object.entries(table).flatMap(([name, value]) => (typeof value === 'string' ? [`${prefix}${name}`] : keysOf(value as object, `${prefix}${name}.`)));

        expect(BOOKING_LINES_EL).not.toBeNull();
        expect(keysOf(BOOKING_LINES_EL ?? {})).toEqual(keysOf(BOOKING_LINES_EN));

        for (const key of keysOf(BOOKING_LINES_EN)) {
            const english = lineOf(BOOKING_LINES_EN, key) ?? '';
            const greek = lineOf(BOOKING_LINES_EL, key) ?? '';

            if (english.includes('|')) {
                for (const count of [1, 2, 5]) {
                    expect(choose(greek, count), `${key} by ${count}`).not.toBe(choose(greek, count === 1 ? 2 : 1));
                }
            }
        }
    });
});

describe('the sentences before the config, in Greek', () => {
    it('reads the loading line in Greek in an embed on a Greek host page, a region subtag ignored, and the mount carries el', async () => {
        document.documentElement.setAttribute('lang', 'el-GR');
        document.body.innerHTML = '<div class="kr-booking"></div>';

        await bootEmbed(SILENT);

        expect(mount().textContent).toContain(GREEK_WORDS.text(LOADING_SENTENCE));
        expect(englishLeft(mount())).toEqual([]);
    });

    it('reads the unavailable line in Greek in an embed on a Greek host page whose config never lands', async () => {
        vi.spyOn(console, 'error').mockImplementation(() => undefined);
        document.documentElement.setAttribute('lang', 'el');
        document.body.innerHTML = '<div class="kr-booking"></div>';

        await bootEmbed(() => Promise.resolve(json({ code: 'not_found', message: 'not_found', data: { status: 404 } }, 404)));
        await settle();

        expect(mount().querySelector('[role="alert"]')?.textContent).toBe(GREEK_WORDS.text(UNAVAILABLE_SENTENCE));
        expect(englishLeft(mount())).toEqual([]);
    });

    it('reads the unavailable line in Greek on the hosted page under /el, its <html lang> el, whose config never lands', async () => {
        vi.spyOn(console, 'error').mockImplementation(() => undefined);
        document.documentElement.setAttribute('lang', 'el');
        // An unreadable inlined config is asked of the endpoint again, which refuses it here.
        document.body.innerHTML = `<script type="application/json" id="${INLINED_CONFIG_ID}">{</script><div class="kr-booking"></div>`;

        await bootEmbed(() => Promise.resolve(json({ code: 'not_found', message: 'not_found', data: { status: 404 } }, 404)), `${window.location.origin}/embed.js`);
        await settle();

        expect(mount().querySelector('[role="alert"]')?.textContent).toBe(GREEK_WORDS.text(UNAVAILABLE_SENTENCE));
        expect(englishLeft(mount())).toEqual([]);
    });

    it('reads the crash line in Greek under the Greek words', async () => {
        vi.spyOn(console, 'error').mockImplementation(() => undefined);

        function Bomb(): never {
            throw new Error('render crash');
        }

        await act(() => {
            root.render(
                keyed(
                    <ErrorBoundary>
                        <Bomb />
                    </ErrorBoundary>,
                    GREEK_WORDS,
                ),
            );
        });

        expect(container.querySelector('[role="alert"]')?.textContent).toBe(GREEK_WORDS.text(CRASHED_SENTENCE));
        expect(englishLeft(container)).toEqual([]);
    });
});

describe('the app in Greek once the config is in hand', () => {
    it('holds Greek in an embed on a Greek host page, its root carrying lang, the switch labelled by English in English', async () => {
        document.documentElement.setAttribute('lang', 'el');
        document.body.innerHTML = '<div class="kr-booking"></div>';

        await bootEmbed((url) => Promise.resolve(json(url.split('?')[0]?.endsWith(CONFIG_PATH) === true ? WIRE : url.split('?')[0]?.endsWith(SERVICES_PATH) === true ? CATALOG : {})));
        await settle();

        const control = mount().querySelector('.kr-booking__language');

        expect(mount().getAttribute('lang')).toBe('el');
        expect(mount().querySelector('.kr-booking__title')?.textContent).toBe(GREEK_WORDS.text(STEP_TITLES.service));
        expect(control?.textContent).toBe(BOOKING_LINES_EN.language);
        expect(control?.getAttribute('lang')).toBe('en');
        expect(englishLeft(mount())).toEqual([]);
    });

    it.each([
        ['/el', '', '/en'],
        ['/el/manage/7b7f0e5a-4444-4000-8000-00000000000c', `?token=${'ab12'.repeat(16)}`, `/en/manage/7b7f0e5a-4444-4000-8000-00000000000c?token=${'ab12'.repeat(16)}`],
        ['/el/my-bookings', '', '/en/my-bookings'],
    ])('links the hosted page at %s%s to the same path in English, %s', async (pathname, search, href) => {
        await act(() => {
            root.render(<App source={{ kind: 'ready', config: CONFIG }} options={OPTIONS} api={{ origin: 'https://luna.kleiseradevou.gr', fetch: SILENT }} lines={BUNDLED_LINES} page={{ pathname, search }} />);
        });

        const control = container.querySelector('.kr-booking__language');

        expect(control?.getAttribute('href')).toBe(href);
        expect(control?.textContent).toBe(BOOKING_LINES_EN.language);
        expect(englishLeft(container)).toEqual([]);
    });

    it('labels the switch on an English page by Greek in Greek', async () => {
        await act(() => {
            root.render(<App source={{ kind: 'ready', config: { ...CONFIG, language: 'en' } }} options={OPTIONS} api={{ origin: 'https://luna.kleiseradevou.gr', fetch: SILENT }} lines={BUNDLED_LINES} page={{ pathname: '/', search: '' }} />);
        });

        expect(container.querySelector(`.kr-booking__language`)?.textContent).toBe(GREEK_WORDS.text(LANGUAGE_NAME));
        expect(container.querySelector('.kr-booking__language')?.getAttribute('lang')).toBe('el');
    });
});
