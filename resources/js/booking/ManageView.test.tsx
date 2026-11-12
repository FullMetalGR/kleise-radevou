import { QueryClientProvider, type QueryClient } from '@tanstack/react-query';
import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { englishLeft, ENGLISH_WORDS, GREEK_WORDS, keyed, KEYED_WORDS, said, saidBy } from '../bookingWords';
import { ApiError, createQueryClient, MAX_ATTEMPTS, TRANSPORT_SENTENCE, type Api } from './api';
import { App } from './App';
import type { MountOptions } from './attributes';
import { BOOKING_STATUSES } from './booking';
import { cancelPath } from './cancel';
import type { BootConfig, Fetch } from './config';
import type { Language } from './language';
import { bookingPath } from './manage';
import { CLOSE_LABEL, MOVED_SENTENCE, moveAvailabilityUrl, MOVING_SENTENCE, PICK_SENTENCE, RESCHEDULE_LABEL, reschedulePath } from './move';
import { BOOK_AGAIN_LABEL } from './myBookings';
import { BOOKING_LINES_EN } from './lines.generated';
import { formatMoney } from './money';
import { BOOKING_HEADING, TOTAL_LABEL, ZONE_SENTENCE } from './Summary';
import { formatTime, wallClockOf } from './time';
import type { Words } from './words';
import { EMPTY_DAY_SENTENCE, HINT_SENTENCE, LOADING_TIMES_SENTENCE, NEXT_WEEK_LABEL, NO_TIMES, PERIOD_NAMES, PERIODS_LABEL, TIMES_COUNT } from './WhenStep';
import { BOOKED_BY, CANCEL_LABEL, CANCELLING_SENTENCE, CONFIRM_QUESTION, KEEP_LABEL, LOADING_BOOKING_SENTENCE, ManageView, NEUTRAL_SENTENCE, statusSentence, TRY_AGAIN_LABEL, TRYING_LABEL, viewOf, YES_LABEL } from './ManageView';

/*
 * Manage mode on screen: the four views of 6.1 in their order, the ready view of 6.2, the
 * affordances of 6.3 and the two-click cancel of 6.4.
 *
 * The query client is the app's own, so the retry predicate under test is the real one: no 4xx
 * is retried and a 5xx gets three attempts. Only the delay between those attempts is taken out,
 * because the backoff is the client's and waiting seven seconds for it proves nothing here.
 */

const CONFIG: BootConfig = { tenantName: 'Luna Hair Studio', timezone: 'Pacific/Auckland', currency: 'EUR', granularityMin: 5, importantInfo: null, defaultLanguage: 'en', language: 'en' };

const UUID = '7b7f0e5a-4444-4000-8000-00000000000c';

const TOKEN = 'ab12'.repeat(16);

const ORIGIN = 'https://luna.kleiseradevou.gr';

// 22:30 UTC on the 6th is 10:30 on the 7th in Auckland.
const BOOKING = {
    uuid: UUID,
    status: 'confirmed',
    hold_class: null,
    hold_expires_at: null,
    customer_name: 'Casey Customer',
    customer_email: 'casey@example.com',
    customer_phone: '+30 210 0000000',
    total_minor: 5000,
    currency: 'JPY',
    items: [
        { sort: 0, service_id: 1, service_name: 'Haircut', staff_id: 7, staff_name: 'Elena', start: '2026-04-06T22:30:00+00:00', end: '2026-04-06T23:15:00+00:00', price_minor: 3500 },
        { sort: 1, service_id: 4, service_name: 'Beard trim', staff_id: 7, staff_name: null, start: '2026-04-06T23:15:00+00:00', end: '2026-04-06T23:35:00+00:00', price_minor: 1500 },
    ],
};

const NOT_FOUND = { code: 'not_found', message: 'not_found', data: { status: 404, detail: 'That booking could not be found.' } };

const FORBIDDEN = { code: 'forbidden', message: 'forbidden', data: { status: 403, detail: 'You may not read that booking.' } };

const BROKEN = { code: 'server_error', message: 'server_error', data: { status: 500, detail: 'Something went wrong at our end.' } };

const WINDOW_CLOSED = { code: 'window_closed', message: 'window_closed', data: { status: 403, detail: 'It is too late to cancel this booking online.' } };

const READ_PATH = bookingPath(UUID, TOKEN);

const CANCEL_PATH = cancelPath(UUID);

const ATTEMPTS = MAX_ATTEMPTS + 1;

const MOVE_PATH = reschedulePath(UUID);

const BOOKING_PATH_PREFIX = `/api/v1/bookings/${UUID}`;

// The mount's today is the 6th in Auckland, so the first window is the 6th to the 13th.
const FIRST_WINDOW = moveAvailabilityUrl(UUID, TOKEN, '2026-04-06', '2026-04-13');

const SECOND_WINDOW = moveAvailabilityUrl(UUID, TOKEN, '2026-04-13', '2026-04-20');

// 23:00 UTC on the 6th is 11:00 on the 7th in Auckland; the booking stands at 10:30.
const NEW_START = '2026-04-06T23:00:00+00:00';

const MOVE_ANSWER = { '2026-04-06': [], '2026-04-07': [NEW_START, '2026-04-06T23:30:00+00:00'], '2026-04-08': [] };

// The second week, in which no day holds a start.
const EMPTY_MOVE_WEEK = { '2026-04-13': [], '2026-04-14': [], '2026-04-15': [], '2026-04-16': [], '2026-04-17': [], '2026-04-18': [], '2026-04-19': [] };

const MOVED_BOOKING = {
    ...BOOKING,
    items: [
        { ...BOOKING.items[0], start: NEW_START, end: '2026-04-06T23:45:00+00:00' },
        { ...BOOKING.items[1], start: '2026-04-06T23:45:00+00:00', end: '2026-04-07T00:05:00+00:00' },
    ],
};

const UNAVAILABLE = { code: 'unavailable', message: 'unavailable', data: { status: 409, detail: 'That time is no longer available. Please pick another.' } };

const MOVE_WINDOW_CLOSED = { code: 'window_closed', message: 'window_closed', data: { status: 403, detail: 'It is too late to reschedule this booking online.' } };

/** What the status region says for a status under the keyed table, empty for one with no sentence. */
function statusSaid(status: string): string {
    const key = statusSentence(status);

    return key === null ? '' : said(key);
}

let container: HTMLDivElement;
let root: Root;
let client: QueryClient;
let reads: string[];
let cancels: string[];
let readAnswer: () => Promise<Response>;
let cancelAnswer: () => Promise<Response>;
let cancelWithheld: boolean;
let releaseCancel: () => void;
let errors: string[];
let moveReads: string[];
let moveReadAnswer: () => Promise<Response>;
let moves: { readonly path: string; readonly body: unknown }[];
let moveAnswer: () => Promise<Response>;
let moveWithheld: boolean;
let releaseMove: () => void;

function answering(body: unknown, status = 200): () => Promise<Response> {
    return () => Promise.resolve(new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }));
}

function dropping(): () => Promise<Response> {
    return () => Promise.reject(new TypeError('Failed to fetch'));
}

const routing: Fetch = (input, init) => {
    const path = input.replace(ORIGIN, '');

    if (path.startsWith(`${BOOKING_PATH_PREFIX}/availability`)) {
        moveReads.push(path);

        return moveReadAnswer();
    }

    if (path === MOVE_PATH) {
        moves.push({ path, body: JSON.parse(String(init.body)) });

        if (!moveWithheld) {
            return moveAnswer();
        }

        return new Promise<Response>((resolve) => {
            releaseMove = () => resolve(moveAnswer());
        });
    }

    if (path === CANCEL_PATH) {
        cancels.push(path);

        if (!cancelWithheld) {
            return cancelAnswer();
        }

        return new Promise<Response>((resolve) => {
            releaseCancel = () => resolve(cancelAnswer());
        });
    }

    reads.push(path);

    return readAnswer();
};

function options(overrides: Partial<MountOptions> = {}): MountOptions {
    return { mode: 'manage', serviceId: null, staffId: null, uuid: UUID, token: TOKEN, channel: null, ...overrides };
}

beforeEach(() => {
    container = document.createElement('div');
    document.body.appendChild(container);
    root = createRoot(container);
    reads = [];
    cancels = [];
    readAnswer = answering(BOOKING);
    cancelAnswer = answering({ ...BOOKING, status: 'cancelled' });
    cancelWithheld = false;
    releaseCancel = () => undefined;
    errors = [];
    moveReads = [];
    moveReadAnswer = answering(MOVE_ANSWER);
    moves = [];
    moveAnswer = answering(MOVED_BOOKING);
    moveWithheld = false;
    releaseMove = () => undefined;
    vi.spyOn(console, 'error').mockImplementation((...args: unknown[]) => {
        errors.push(args.map(String).join(' '));
    });
});

afterEach(async () => {
    await act(() => {
        root.unmount();
    });
    container.remove();
    vi.restoreAllMocks();
    vi.useRealTimers();
});

/**
 * Lets the query client's deferred notifications, every retry behind them and the effects they
 * trigger land.
 */
async function settle(turns = 12): Promise<void> {
    await act(async () => {
        for (let turn = 0; turn < turns; turn += 1) {
            // Under fake timers a zero-delay timeout fires only when the clock is told to move.
            if (vi.isFakeTimers()) {
                await vi.advanceTimersByTimeAsync(0);
            } else {
                await new Promise<void>((resolve) => setTimeout(resolve, 0));
            }
        }
    });
}

const MOUNTED_AT = Date.UTC(2026, 3, 6, 7, 0);

async function mount(mountOptions: MountOptions = options(), fetchFn: Fetch = routing, now: () => number = () => MOUNTED_AT, notice: string | null = null, words: Words = KEYED_WORDS, prefix: Language | null = null): Promise<void> {
    const api: Api = { origin: ORIGIN, fetch: fetchFn };

    client = createQueryClient();
    // Under fake timers every timer left standing is counted, so the cache's own garbage
    // collection, which is the query client's and not the view's, is switched off there.
    const gc = vi.isFakeTimers() ? { gcTime: Infinity } : {};

    client.setDefaultOptions({ queries: { ...client.getDefaultOptions().queries, retryDelay: 0, ...gc } });

    await act(() => {
        root.render(
            keyed(
                <QueryClientProvider client={client}>
                    <ManageView config={CONFIG} options={mountOptions} api={api} now={now} notice={notice} prefix={prefix} />
                </QueryClientProvider>,
                words,
            ),
        );
    });

    await settle();
}

function frame(): HTMLElement {
    const found = container.querySelector<HTMLElement>('.kr-booking__frame');

    if (found === null) {
        throw new Error('The manage view rendered no frame.');
    }

    return found;
}

function view(): string | null {
    return frame().getAttribute('data-kr-view');
}

function buttons(): HTMLButtonElement[] {
    return Array.from(container.querySelectorAll('button'));
}

function button(name: string): HTMLButtonElement {
    const found = buttons().find((candidate) => candidate.textContent === name);

    if (found === undefined) {
        throw new Error(`No button named "${name}" among: ${buttons().map((candidate) => candidate.textContent).join(', ')}`);
    }

    return found;
}

async function press(el: HTMLElement): Promise<void> {
    await act(() => {
        el.click();
    });

    await settle();
}

function polite(): string {
    return Array.from(container.querySelectorAll('[role="status"]')).map((region) => region.textContent ?? '').join(' ');
}

function alerts(): string {
    return Array.from(container.querySelectorAll('[role="alert"]')).map((region) => region.textContent ?? '').join(' ');
}

function texts(selector: string): string[] {
    return Array.from(container.querySelectorAll(selector)).map((node) => node.textContent ?? '');
}

describe('the four views of 6.1', () => {
    const data = { uuid: UUID, status: 'confirmed', holdClass: null, holdExpiresAt: null, customerName: '', customerEmail: '', customerPhone: null, totalMinor: 0, currency: 'EUR', items: [] };
    const refusal = new ApiError(404, 'That booking could not be found.', 'not_found');
    const broken = new ApiError(500, 'Something went wrong at our end.', 'server_error');

    it('decides in that order and no other', () => {
        expect(viewOf(null, TOKEN, data, refusal)).toBe('neutral');
        expect(viewOf(UUID, null, data, refusal)).toBe('neutral');
        expect(viewOf(UUID, TOKEN, data, refusal)).toBe('ready');
        expect(viewOf(UUID, TOKEN, undefined, null)).toBe('loading');
        expect(viewOf(UUID, TOKEN, undefined, refusal)).toBe('neutral');
        expect(viewOf(UUID, TOKEN, undefined, new ApiError(403, 'no', 'forbidden'))).toBe('neutral');
        expect(viewOf(UUID, TOKEN, undefined, broken)).toBe('retry');
        expect(viewOf(UUID, TOKEN, undefined, new TypeError('Failed to fetch'))).toBe('retry');
    });
});

describe('the read classification of 6.1', () => {
    it('renders the neutral panel and asks the server nothing when the token is missing', async () => {
        await mount(options({ token: null, channel: null }));

        expect(view()).toBe('neutral');
        expect(reads).toEqual([]);
        expect(container.textContent).toContain(said(NEUTRAL_SENTENCE));
        expect(buttons()).toHaveLength(0);
    });

    it('renders the neutral panel and asks the server nothing when the booking is missing', async () => {
        await mount(options({ uuid: null }));

        expect(view()).toBe('neutral');
        expect(reads).toEqual([]);
    });

    it('sends one GET carrying the token in the query string when both are in hand', async () => {
        await mount();

        expect(reads).toEqual([READ_PATH]);
        expect(view()).toBe('ready');
    });

    it('renders a 404 and a 403 read byte-identically, with no buttons and no server wording', async () => {
        readAnswer = answering(NOT_FOUND, 404);
        await mount();

        const missing = frame().outerHTML;

        expect(view()).toBe('neutral');
        expect(reads).toHaveLength(1);
        expect(buttons()).toHaveLength(0);
        expect(container.textContent).not.toContain('That booking could not be found.');

        await act(() => {
            root.unmount();
        });

        root = createRoot(container);
        reads = [];
        readAnswer = answering(FORBIDDEN, 403);
        await mount();

        expect(frame().outerHTML).toBe(missing);
        expect(container.textContent).not.toContain('You may not read that booking.');
    });

    it('renders the loading view while the read is in flight, and asks once', async () => {
        let release: () => void = () => undefined;
        readAnswer = () => new Promise<Response>((resolve) => {
            release = () => resolve(new Response(JSON.stringify(BOOKING), { status: 200, headers: { 'Content-Type': 'application/json' } }));
        });

        await mount();

        expect(view()).toBe('loading');
        expect(polite()).toContain(said(LOADING_BOOKING_SENTENCE));
        expect(buttons()).toHaveLength(0);

        release();
        await settle();

        expect(view()).toBe('ready');
        expect(reads).toHaveLength(1);
    });

    it('renders the retry panel for a 500, with an alert sentence and three more attempts', async () => {
        readAnswer = answering(BROKEN, 500);
        await mount();

        expect(view()).toBe('retry');
        expect(reads).toHaveLength(ATTEMPTS);
        expect(alerts()).toContain('Something went wrong at our end.');
        expect(container.textContent).not.toContain(said(NEUTRAL_SENTENCE));
        expect(button(said(TRY_AGAIN_LABEL)).disabled).toBe(false);
    });

    it('renders the retry panel for a transport failure, with the fixed sentence and never the browser message', async () => {
        readAnswer = dropping();
        await mount();

        expect(view()).toBe('retry');
        expect(alerts()).toContain(said(TRANSPORT_SENTENCE));
        expect(container.textContent).not.toContain('Failed to fetch');
    });

    it('refetches on Try again and disables it while that is in flight', async () => {
        readAnswer = answering(BROKEN, 500);
        await mount();

        let release: () => void = () => undefined;
        readAnswer = () => new Promise<Response>((resolve) => {
            release = () => resolve(new Response(JSON.stringify(BOOKING), { status: 200, headers: { 'Content-Type': 'application/json' } }));
        });

        await act(() => {
            button(said(TRY_AGAIN_LABEL)).click();
        });
        await settle(2);

        // Disabled, with the reason inside the control.
        expect(button(said(TRYING_LABEL)).disabled).toBe(true);
        expect(reads.length).toBe(ATTEMPTS + 1);

        release();
        await settle();

        expect(view()).toBe('ready');
    });

    it('stays ready when a refetch fails, because data present is ready whatever the refetch did', async () => {
        await mount();

        readAnswer = answering(NOT_FOUND, 404);

        await act(async () => {
            await client.invalidateQueries({ queryKey: ['booking', UUID] });
        });
        await settle();

        expect(view()).toBe('ready');
        expect(texts('.kr-booking__summary-service')).toEqual(['Haircut', 'Beard trim']);
    });
});

describe('the ready view of 6.2', () => {
    it('renders the title, the items, the total, the customer line, the status region, the actions and Book again in that order', async () => {
        readAnswer = answering({ ...BOOKING, status: 'awaiting_approval', hold_class: 'approval', hold_expires_at: '2026-04-08T07:00:00+00:00' });
        await mount();

        // The timezone line is in the selector on purpose. Every booking page carries one;
        // here it is placed with the details, after the customer line, and the other elements
        // keep their order around it. This is what holds that placement.
        const order = Array.from(
            container.querySelectorAll(
                '.kr-booking__subtitle, .kr-booking__summary, .kr-booking__total, .kr-booking__customer, .kr-booking__timezone, [role="status"], .kr-booking__actions, .kr-booking__again',
            ),
        ).map((node) => node.className.replace('kr-booking__', '').split(' ')[0] ?? '');

        expect(order).toEqual(['subtitle', 'list', 'total', 'customer', 'timezone', 'notice', 'notice', 'actions', 'again']);
    });

    it('keeps that order with the reschedule dialog open, the dialog after the actions and before Book again', async () => {
        await mount();
        await press(button(said(RESCHEDULE_LABEL)));

        // What 6.2 names outside the dialog, and the dialog itself; the dialog's own heading,
        // regions and actions are its business and are left out of the reading.
        const order = Array.from(
            container.querySelectorAll(
                '.kr-booking__subtitle, .kr-booking__summary, .kr-booking__total, .kr-booking__customer, .kr-booking__timezone, [role="status"], .kr-booking__actions, [role="dialog"], .kr-booking__again',
            ),
        )
            .filter((node) => (node.parentElement?.closest('[role="dialog"]') ?? null) === null)
            .map((node) => node.className.replace('kr-booking__', '').split(' ')[0] ?? '');

        expect(dialog()).not.toBeNull();
        expect(order).toEqual(['subtitle', 'list', 'total', 'customer', 'timezone', 'notice', 'notice', 'actions', 'dialog', 'again']);
    });

    it('links Book again, after the actions, to the hosted page on the origin the app talks to, preselecting the first item\'s service and its staff member, for every status', async () => {
        for (const status of BOOKING_STATUSES) {
            reads = [];
            readAnswer = answering({ ...BOOKING, status });
            await mount();

            const links = Array.from(container.querySelectorAll<HTMLAnchorElement>('.kr-booking__again'));

            // The first item is Haircut, service 1, on Elena, staff 7; the second item's service
            // and its implicit member are nobody's business here.
            expect(links.map((link) => [link.getAttribute('href'), link.textContent])).toEqual([[`${ORIGIN}/?service=1&staff=7`, said(BOOK_AGAIN_LABEL)]]);
            // Last in the ready view, after the actions wherever the status offers any.
            expect(container.querySelector('.kr-booking__manage')?.lastElementChild).toBe(links[0]);

            await act(() => {
                root.unmount();
            });

            root = createRoot(container);
        }
    });

    it('leaves the staff out of Book again where the first item runs on the implicit member, whom the guest view names as nobody', async () => {
        readAnswer = answering({ ...BOOKING, items: [{ ...BOOKING.items[0], staff_name: null }, BOOKING.items[1]] });
        await mount();

        expect(container.querySelector('.kr-booking__again')?.getAttribute('href')).toBe(`${ORIGIN}/?service=1`);
    });

    it('keeps the prefix the hosted manage page was opened under in Book again, the business page under it, and is bare for none', async () => {
        await mount(options(), routing, () => MOUNTED_AT, null, KEYED_WORDS, 'el');

        expect(container.querySelector('.kr-booking__again')?.getAttribute('href')).toBe(`${ORIGIN}/el?service=1&staff=7`);

        await act(() => {
            root.unmount();
        });

        root = createRoot(container);
        await mount(options(), routing, () => MOUNTED_AT, null, KEYED_WORDS, 'en');

        expect(container.querySelector('.kr-booking__again')?.getAttribute('href')).toBe(`${ORIGIN}/en?service=1&staff=7`);
    });

    it('reads that prefix off the hosted manage page\'s own path, through the shell', async () => {
        await act(() => {
            root.render(<App source={{ kind: 'ready', config: CONFIG }} options={options()} api={{ origin: ORIGIN, fetch: routing }} page={{ pathname: `/el/manage/${UUID}`, search: `?token=${TOKEN}` }} />);
        });
        await settle();

        expect(container.querySelector('.kr-booking__again')?.getAttribute('href')).toBe(`${ORIGIN}/el?service=1&staff=7`);
    });

    it('carries no credential in Book again: neither the token nor the booking is in the link', async () => {
        await mount();

        const href = container.querySelector('.kr-booking__again')?.getAttribute('href') ?? '';

        expect(href).not.toBe('');
        expect(href).not.toContain(TOKEN);
        expect(href).not.toContain(UUID);
    });

    it('renders the items in the tenant clock and the money in the booking currency', async () => {
        await mount();

        expect(texts('.kr-booking__summary-when')[0]).toContain('10:30');
        expect(texts('.kr-booking__summary-when')[1]).toContain('11:15');
        expect(texts('.kr-booking__total')[0]).toContain('5,000');
        // The yen sign is written as its escape, because every file in this repository is ASCII.
        expect(texts('.kr-booking__summary-price')).toEqual(['\u00a53,500', '\u00a51,500']);
    });

    it('heads the card with its key, words the total by its key over the amount in the page\'s language, and names the tenant\'s zone by the one zone key', async () => {
        await mount();

        expect(texts('.kr-booking__booking > .kr-booking__subtitle')).toEqual([said(BOOKING_HEADING)]);
        expect(texts('.kr-booking__total')).toEqual([`${said(TOTAL_LABEL)} ${formatMoney(5000, 'JPY', KEYED_WORDS.locale)}`]);
        expect(texts('.kr-booking__booking > .kr-booking__timezone')).toEqual([said(ZONE_SENTENCE, { zone: CONFIG.timezone })]);
    });

    it('reads the English file\'s words on an English page: the card, the customer, the zone, the status, the actions and Book again', async () => {
        readAnswer = answering({ ...BOOKING, status: 'awaiting_approval', hold_class: 'approval', hold_expires_at: '2026-04-08T07:00:00+00:00' });
        await mount(options(), routing, () => MOUNTED_AT, null, ENGLISH_WORDS);

        expect(texts('.kr-booking__booking > .kr-booking__subtitle')).toEqual([BOOKING_LINES_EN.booking.heading]);
        expect(texts('.kr-booking__total')[0]).toContain(BOOKING_LINES_EN.booking.total);
        expect(texts('.kr-booking__customer')).toEqual([BOOKING_LINES_EN.manage.booked_by.replace(':customer', 'Casey Customer, casey@example.com, +30 210 0000000')]);
        expect(texts('.kr-booking__booking > .kr-booking__timezone')).toEqual([BOOKING_LINES_EN.zone.replace(':zone', CONFIG.timezone)]);
        expect(polite()).toContain(BOOKING_LINES_EN.manage.statuses.awaiting_approval);
        expect(buttons().map((control) => control.textContent)).toEqual([BOOKING_LINES_EN.manage.cancel, BOOKING_LINES_EN.move.reschedule]);
        expect(texts('.kr-booking__again')).toEqual([BOOKING_LINES_EN.booking.book_again]);
        expect(texts('.kr-booking__summary-staff')).toEqual([BOOKING_LINES_EN.summary.staff.replace(':staff', 'Elena')]);
    });

    it('says the dead link\'s one sentence from the English file on an English page', async () => {
        await mount(options({ token: null }), routing, () => MOUNTED_AT, null, ENGLISH_WORDS);

        expect(container.textContent).toContain(BOOKING_LINES_EN.manage.neutral);
    });

    it('names the customer on one line', async () => {
        await mount();

        expect(texts('.kr-booking__customer')).toEqual([said(BOOKED_BY, { customer: 'Casey Customer, casey@example.com, +30 210 0000000' })]);
    });

    it('renders the details for every status, whatever the controls do', async () => {
        for (const status of BOOKING_STATUSES) {
            reads = [];
            readAnswer = answering({ ...BOOKING, status });
            await mount();

            expect(texts('.kr-booking__summary-service')).toEqual(['Haircut', 'Beard trim']);
            expect(texts('.kr-booking__total')[0]).toContain('5,000');

            await act(() => {
                root.unmount();
            });

            root = createRoot(container);
        }
    });

    it('says one sentence per status in an always-mounted polite region, and nothing for confirmed', () => {
        expect(statusSentence('confirmed')).toBeNull();
        expect(BOOKING_STATUSES.filter((status) => statusSentence(status) === null)).toEqual(['confirmed']);
        expect(new Set(BOOKING_STATUSES.map(statusSentence)).size).toBe(BOOKING_STATUSES.length);
    });

    it('mounts the status region empty and takes its sentence one effect later', async () => {
        readAnswer = answering({ ...BOOKING, status: 'cancelled' });
        await mount();

        expect(polite()).toContain(statusSaid('cancelled'));
        expect(container.querySelectorAll('[role="status"]').length).toBeGreaterThan(0);
    });
});

describe('the sentence a redirect flashed', () => {
    const FLASHED = 'Your payment is on its way to us. Your booking is confirmed once it arrives.';

    it('shows it in a polite region of its own under the title, above the booking, and keeps the ready view\'s own two regions after the booking', async () => {
        await mount(options(), routing, () => MOUNTED_AT, FLASHED);

        const order = Array.from(container.querySelectorAll('.kr-booking__title, [role="status"], .kr-booking__booking, .kr-booking__actions')).map((node) =>
            node.getAttribute('role') === 'status' ? `status:${node.textContent ?? ''}` : (node.className.replace('kr-booking__', '').split(' ')[0] ?? ''),
        );

        expect(view()).toBe('ready');
        expect(order).toEqual(['title', `status:${FLASHED}`, 'card', 'status:', 'status:', 'actions']);
        expect(alerts()).toBe('');
    });

    it('says it through the one region while the booking is still loading, the region staying the same element once the booking is ready', async () => {
        let release: () => void = () => undefined;

        readAnswer = () =>
            new Promise<Response>((resolve) => {
                release = () => resolve(new Response(JSON.stringify(BOOKING), { status: 200, headers: { 'Content-Type': 'application/json' } }));
            });
        await mount(options(), routing, () => MOUNTED_AT, FLASHED);

        const region = container.querySelector('.kr-booking__title + [role="status"]');

        expect(view()).toBe('loading');
        expect(region?.textContent).toBe(FLASHED);
        expect(region?.className).toBe('kr-booking__notice kr-booking__notice--polite');

        await act(() => {
            release();
        });
        await settle();

        expect(view()).toBe('ready');
        expect(container.querySelector('.kr-booking__title + [role="status"]')).toBe(region);
        expect(region?.textContent).toBe(FLASHED);
    });

    it('shows nothing on the neutral panel, which carries no server wording, nor where nothing was flashed', async () => {
        await mount(options({ token: null }), routing, () => MOUNTED_AT, FLASHED);

        expect(view()).toBe('neutral');
        expect(container.textContent).not.toContain(FLASHED);

        await act(() => {
            root.unmount();
        });

        root = createRoot(container);
        await mount();

        expect(container.querySelectorAll('[role="status"]')).toHaveLength(2);
    });
});

describe('the affordances of 6.3', () => {
    it.each(['confirmed', 'pending', 'awaiting_approval', 'awaiting_payment'])('offers Cancel on %s', async (status) => {
        readAnswer = answering({ ...BOOKING, status, hold_class: status === 'confirmed' ? null : 'checkout', hold_expires_at: status === 'confirmed' ? null : '2026-04-06T08:00:00+00:00' });
        await mount();

        expect(button(said(CANCEL_LABEL)).disabled).toBe(false);
    });

    it.each(['completed', 'no_show', 'cancelled', 'rejected', 'expired'])('renders no action area at all on %s', async (status) => {
        readAnswer = answering({ ...BOOKING, status });
        await mount();

        expect(container.querySelector('.kr-booking__actions')).toBeNull();
        expect(buttons()).toHaveLength(0);
        expect(polite()).toContain(statusSaid(status));
    });
});

describe('the cancel of 6.4', () => {
    it('sends nothing on the first press and opens the confirmation row', async () => {
        await mount();

        await press(button(said(CANCEL_LABEL)));

        expect(cancels).toEqual([]);
        expect(container.textContent).toContain(said(CONFIRM_QUESTION));
        expect(button(said(YES_LABEL))).toBeInstanceOf(HTMLButtonElement);
        expect(button(said(KEEP_LABEL)).disabled).toBe(false);
    });

    it('sends one POST with the token in the body on Yes, and refetches rather than writing anything itself', async () => {
        await mount();
        await press(button(said(CANCEL_LABEL)));

        readAnswer = answering({ ...BOOKING, status: 'cancelled' });

        await press(button(said(YES_LABEL)));

        expect(cancels).toEqual([CANCEL_PATH]);
        expect(reads).toEqual([READ_PATH, READ_PATH]);
        expect(polite()).toContain(statusSaid('cancelled'));
        expect(container.querySelector('.kr-booking__actions')).toBeNull();
        expect(view()).toBe('ready');
    });

    it('renders a polite progress line while the cancel flies, and no alert', async () => {
        await mount();
        await press(button(said(CANCEL_LABEL)));

        cancelWithheld = true;

        await act(() => {
            button(said(YES_LABEL)).click();
        });
        await settle(2);

        expect(polite()).toContain(said(CANCELLING_SENTENCE));
        expect(alerts()).toBe('');
        expect(button(said(YES_LABEL)).disabled).toBe(true);
        expect(button(said(KEEP_LABEL)).disabled).toBe(false);

        releaseCancel();
        await settle();
    });

    it('refuses a second Yes while one is in flight, before disabled reaches the DOM', async () => {
        await mount();
        await press(button(said(CANCEL_LABEL)));

        cancelWithheld = true;

        await act(() => {
            button(said(YES_LABEL)).click();
            button(said(YES_LABEL)).click();
        });
        await settle(2);

        expect(cancels).toHaveLength(1);

        releaseCancel();
        await settle();
    });

    it('keeps the booking and the row on a 403, with the sentence rendered politely and never the neutral panel', async () => {
        await mount();
        await press(button(said(CANCEL_LABEL)));

        cancelAnswer = answering(WINDOW_CLOSED, 403);

        await press(button(said(YES_LABEL)));

        expect(view()).toBe('ready');
        expect(polite()).toContain('It is too late to cancel this booking online.');
        expect(container.textContent).not.toContain(said(NEUTRAL_SENTENCE));
        expect(texts('.kr-booking__summary-service')).toEqual(['Haircut', 'Beard trim']);
        expect(button(said(YES_LABEL)).disabled).toBe(false);
        expect(reads).toEqual([READ_PATH]);
    });

    it('renders a 500 cancel refusal as an alert, because 4.1 governs the tone and 6.4 names the refusal that is polite', async () => {
        // A refusal renders politely and a 5xx is an alert. The alert rule governs every
        // recoverable failure, and the polite refusal is the closed-window 403, which is a
        // worded answer and must never become the neutral panel. So the two sentences go to
        // two different regions, and a change that sent a 500 to the polite one fails here
        // rather than passing quietly.
        await mount();
        await press(button(said(CANCEL_LABEL)));

        cancelAnswer = answering(BROKEN, 500);

        await press(button(said(YES_LABEL)));

        expect(alerts()).toContain('Something went wrong at our end.');
        expect(polite()).not.toContain('Something went wrong at our end.');
        expect(view()).toBe('ready');
        expect(texts('.kr-booking__summary-service')).toEqual(['Haircut', 'Beard trim']);
        expect(button(said(YES_LABEL)).disabled).toBe(false);
        expect(cancels).toHaveLength(1);
    });

    it('frees the latch on Keep this booking, so a cancel whose answer came back stale cannot deaden the next', async () => {
        await mount();
        await press(button(said(CANCEL_LABEL)));

        cancelWithheld = true;
        cancelAnswer = answering(WINDOW_CLOSED, 403);

        await act(() => {
            button(said(YES_LABEL)).click();
        });
        await settle(2);
        await press(button(said(KEEP_LABEL)));

        expect(container.textContent).not.toContain(said(CONFIRM_QUESTION));

        // The withdrawn cancel answers now. Its verdict is stale and renders nothing, and the
        // latch it would otherwise still hold was freed by the way out.
        releaseCancel();
        await settle();

        cancelWithheld = false;
        cancelAnswer = answering({ ...BOOKING, status: 'cancelled' });

        await press(button(said(CANCEL_LABEL)));
        await press(button(said(YES_LABEL)));

        expect(cancels).toHaveLength(2);
        expect(polite()).not.toContain('It is too late');
    });

    it('renders nothing and announces nothing for a verdict whose ticket is stale', async () => {
        await mount();
        await press(button(said(CANCEL_LABEL)));

        cancelWithheld = true;
        cancelAnswer = answering(WINDOW_CLOSED, 403);

        await act(() => {
            button(said(YES_LABEL)).click();
        });
        await settle(2);
        await press(button(said(KEEP_LABEL)));

        releaseCancel();
        await settle();

        expect(polite()).not.toContain('It is too late');
        expect(alerts()).toBe('');
        expect(container.textContent).not.toContain(said(CONFIRM_QUESTION));
        expect(button(said(CANCEL_LABEL)).disabled).toBe(false);
    });
});

describe('the credential', () => {
    it('reaches no DOM attribute, no storage and no console entry', async () => {
        await mount();
        await press(button(said(CANCEL_LABEL)));
        await press(button(said(YES_LABEL)));

        const attributes = Array.from(container.querySelectorAll('*')).flatMap((node) => Array.from(node.attributes).map((attribute) => attribute.value));

        expect(container.innerHTML).not.toContain(TOKEN);
        expect(attributes.some((value) => value.includes(TOKEN))).toBe(false);
        expect(JSON.stringify(localStorage)).not.toContain(TOKEN);
        expect(JSON.stringify(sessionStorage)).not.toContain(TOKEN);
        expect(errors.join(' ')).not.toContain(TOKEN);
        expect(cancels).toHaveLength(1);
    });
});

function dialog(): HTMLElement | null {
    return container.querySelector<HTMLElement>('[role="dialog"]');
}

function openDialog(): HTMLElement {
    const found = dialog();

    if (found === null) {
        throw new Error('No dialog is open.');
    }

    return found;
}

function inDialog(selector: string): HTMLElement[] {
    return Array.from(openDialog().querySelectorAll<HTMLElement>(selector));
}

function dayButton(date: string): HTMLButtonElement {
    const found = openDialog().querySelector<HTMLButtonElement>(`.kr-booking__day[value="${date}"]`);

    if (found === null) {
        throw new Error(`The dialog offers no day ${date}.`);
    }

    return found;
}

function slotButton(start: string): HTMLButtonElement {
    const found = openDialog().querySelector<HTMLButtonElement>(`.kr-booking__slot[value="${start}"]`);

    if (found === null) {
        throw new Error(`The dialog offers no slot at ${start}.`);
    }

    return found;
}

function summaryWhen(): string {
    return container.querySelector('.kr-booking__summary-when')?.textContent ?? '';
}

/** Opens the dialog on a mounted confirmed booking and picks the 7th, where two times are offered. */
async function toTheSeventh(): Promise<void> {
    await mount();
    await press(button(said(RESCHEDULE_LABEL)));
    await press(dayButton('2026-04-07'));
}

describe('the reschedule control of 6.3', () => {
    it.each([
        ['confirmed', null, null],
        ['pending', 'checkout', '2026-04-06T08:00:00+00:00'],
        ['awaiting_approval', 'approval', '2026-04-08T07:00:00+00:00'],
    ])('offers Reschedule beside Cancel on a %s booking', async (status, holdClass, expires) => {
        readAnswer = answering({ ...BOOKING, status, hold_class: holdClass, hold_expires_at: expires });
        await mount();

        const actions = container.querySelector('.kr-booking__actions');

        expect(Array.from(actions?.querySelectorAll('button') ?? []).map((control) => control.textContent)).toEqual([said(CANCEL_LABEL), said(RESCHEDULE_LABEL)]);
        expect(button(said(RESCHEDULE_LABEL)).disabled).toBe(false);
    });

    it('offers Cancel and not Reschedule on a pending booking whose hold has lapsed', async () => {
        readAnswer = answering({ ...BOOKING, status: 'pending', hold_class: 'checkout', hold_expires_at: '2026-04-06T06:59:59+00:00' });
        await mount();

        expect(buttons().map((control) => control.textContent)).toEqual([said(CANCEL_LABEL)]);
    });

    it.each(['completed', 'no_show', 'cancelled', 'rejected', 'expired'])('offers no Reschedule on %s', async (status) => {
        readAnswer = answering({ ...BOOKING, status });
        await mount();

        expect(buttons()).toHaveLength(0);
    });
});

describe('the reschedule dialog of 6.5', () => {
    it('asks nothing until pressed, then opens a labelled modal dialog holding the focus, with the background disabled and Close enabled', async () => {
        await mount();

        expect(moveReads).toEqual([]);
        expect(dialog()).toBeNull();

        const trigger = button(said(RESCHEDULE_LABEL));

        await press(trigger);

        const opened = openDialog();
        const heading = document.getElementById(opened.getAttribute('aria-labelledby') ?? '');

        expect(opened.getAttribute('aria-modal')).toBe('true');
        expect(heading?.textContent).toBe(said(PICK_SENTENCE));
        expect(opened.contains(heading)).toBe(true);
        expect(document.activeElement).toBe(opened);
        expect(opened.tabIndex).toBe(-1);
        expect(button(said(RESCHEDULE_LABEL))).toBe(trigger);
        expect(trigger.disabled).toBe(true);
        expect(button(said(CANCEL_LABEL)).disabled).toBe(true);
        expect(button(said(CLOSE_LABEL)).disabled).toBe(false);
        expect(dialog()?.previousElementSibling?.className).toContain('kr-booking__actions');
    });

    it('asks the per-booking read with the token in the query string alone, keyed without it, once, and only while open', async () => {
        await mount();
        await press(button(said(RESCHEDULE_LABEL)));

        expect(moveReads).toEqual([FIRST_WINDOW]);
        expect(FIRST_WINDOW).toBe(`/api/v1/bookings/${UUID}/availability?token=${TOKEN}&from=2026-04-06&to=2026-04-13`);

        const keys = client.getQueryCache().findAll({ queryKey: ['availability'] }).map((query) => query.queryKey);

        expect(keys).toEqual([['availability', 'move', UUID, '2026-04-06', '2026-04-13']]);
        expect(JSON.stringify(keys)).not.toContain(TOKEN);

        await press(button(said(CLOSE_LABEL)));

        expect(dialog()).toBeNull();
        expect(client.getQueryCache().findAll({ queryKey: ['availability', 'move'] }).map((query) => query.getObserversCount())).toEqual([0]);
        expect(moveReads).toEqual([FIRST_WINDOW]);
        expect(reads).toEqual([READ_PATH]);
    });

    it('renders the when step list: the week row from the answer, no previous on the first week, the first day with times selected into the grid, the empty days disabled, the hint for an empty week', async () => {
        await mount();
        await press(button(said(RESCHEDULE_LABEL)));

        expect(inDialog('.kr-booking__day').map((day) => day.getAttribute('value'))).toEqual(['2026-04-06', '2026-04-07', '2026-04-08']);
        expect(inDialog('.kr-booking__previous')).toHaveLength(0);
        expect(inDialog('.kr-booking__next')).toHaveLength(1);
        expect(openDialog().textContent).not.toContain(said(HINT_SENTENCE));
        expect(openDialog().textContent).toContain(said(ZONE_SENTENCE, { zone: 'Pacific/Auckland' }));
        expect(dayButton('2026-04-07').getAttribute('aria-pressed')).toBe('true');
        expect(inDialog('.kr-booking__slot').map((slot) => slot.textContent)).toEqual([
            formatTime(wallClockOf(NEW_START, CONFIG.timezone)),
            formatTime(wallClockOf('2026-04-06T23:30:00+00:00', CONFIG.timezone)),
        ]);
        expect(inDialog('.kr-booking__day').filter((day) => day.hasAttribute('disabled')).map((day) => day.getAttribute('value'))).toEqual(['2026-04-06', '2026-04-08']);
        expect(dayButton('2026-04-08').textContent).toContain(said(NO_TIMES));

        moveReadAnswer = answering(EMPTY_MOVE_WEEK);

        await press(inDialog('.kr-booking__next')[0] as HTMLElement);

        expect(moveReads).toEqual([FIRST_WINDOW, SECOND_WINDOW]);
        expect(inDialog('.kr-booking__previous')).toHaveLength(1);
        expect(inDialog('.kr-booking__day')).toHaveLength(7);
        expect(inDialog('.kr-booking__day[aria-pressed="true"]')).toHaveLength(0);
        expect(openDialog().textContent).toContain(said(HINT_SENTENCE));
        expect(inDialog('.kr-booking__slot')).toHaveLength(0);
    });

    it('draws the when step\'s period control over the same list, a period pressed and then a start in it moving the booking there', async () => {
        // 02:00 UTC on the 7th is 14:00 in Auckland, an afternoon after the two morning times.
        const later = '2026-04-07T02:00:00+00:00';

        moveReadAnswer = answering({ ...MOVE_ANSWER, '2026-04-07': [...MOVE_ANSWER['2026-04-07'], later] });
        await mount();
        await press(button(said(RESCHEDULE_LABEL)));

        const periods = (): HTMLElement[] => inDialog('.kr-booking__period');

        expect(inDialog('[role="group"]').map((group) => group.getAttribute('aria-label'))).toEqual([said(PERIODS_LABEL)]);
        expect(periods().map((period) => period.textContent)).toEqual([`${said(PERIOD_NAMES.Morning)} ${saidBy(TIMES_COUNT, 2)}`, `${said(PERIOD_NAMES.Afternoon)} ${saidBy(TIMES_COUNT, 1)}`]);
        expect(periods().map((period) => period.getAttribute('aria-pressed'))).toEqual(['true', 'false']);
        expect(inDialog('.kr-booking__slot').map((slot) => slot.getAttribute('value'))).toEqual(MOVE_ANSWER['2026-04-07']);

        const afternoon = periods()[1] as HTMLElement;

        afternoon.focus();
        await press(afternoon);

        expect(document.activeElement).toBe(afternoon);
        expect(periods().map((period) => period.getAttribute('aria-pressed'))).toEqual(['false', 'true']);
        expect(inDialog('.kr-booking__slot').map((slot) => slot.getAttribute('value'))).toEqual([later]);
        expect(moves).toEqual([]);

        await press(slotButton(later));

        expect(moves).toEqual([{ path: MOVE_PATH, body: { token: TOKEN, start: later } }]);
        expect(dialog()).toBeNull();
        expect(polite()).toContain(said(MOVED_SENTENCE));
    });

    it('keeps the chosen day when the refetch after a 409 empties it: pressed, disabled, the empty-day sentence and no slot', async () => {
        await toTheSeventh();

        moveAnswer = answering(UNAVAILABLE, 409);
        moveReadAnswer = answering({ ...MOVE_ANSWER, '2026-04-07': [] });

        await press(slotButton(NEW_START));

        expect(moveReads).toEqual([FIRST_WINDOW, FIRST_WINDOW]);
        expect(dayButton('2026-04-07').getAttribute('aria-pressed')).toBe('true');
        expect(dayButton('2026-04-07').disabled).toBe(true);
        expect(openDialog().textContent).toContain(said(EMPTY_DAY_SENTENCE));
        expect(openDialog().textContent).not.toContain(said(HINT_SENTENCE));
        expect(inDialog('.kr-booking__slot')).toHaveLength(0);
    });

    it('keeps the day selected on opening, never pressed, when the refetch after a 409 empties it: pressed, disabled, the empty-day sentence and no hint', async () => {
        await mount();
        await press(button(said(RESCHEDULE_LABEL)));

        expect(dayButton('2026-04-07').getAttribute('aria-pressed')).toBe('true');

        moveAnswer = answering(UNAVAILABLE, 409);
        moveReadAnswer = answering({ ...MOVE_ANSWER, '2026-04-07': [] });

        await press(slotButton(NEW_START));

        expect(moveReads).toEqual([FIRST_WINDOW, FIRST_WINDOW]);
        expect(dayButton('2026-04-07').getAttribute('aria-pressed')).toBe('true');
        expect(dayButton('2026-04-07').disabled).toBe(true);
        expect(openDialog().textContent).toContain(said(EMPTY_DAY_SENTENCE));
        expect(openDialog().textContent).not.toContain(said(HINT_SENTENCE));
        expect(inDialog('.kr-booking__slot')).toHaveLength(0);
    });

    it('renders the loading line while the read is in flight', async () => {
        let answer: () => void = () => undefined;

        moveReadAnswer = () =>
            new Promise<Response>((resolve) => {
                answer = () => resolve(new Response(JSON.stringify(MOVE_ANSWER), { status: 200, headers: { 'Content-Type': 'application/json' } }));
            });
        await mount();
        await press(button(said(RESCHEDULE_LABEL)));

        expect(openDialog().textContent).toContain(said(LOADING_TIMES_SENTENCE));
        expect(inDialog('.kr-booking__day')).toHaveLength(0);
        expect(inDialog('.kr-booking__skeleton[aria-hidden="true"] .kr-booking__blank').length).toBeGreaterThan(0);

        answer();
        await settle();

        expect(openDialog().textContent).not.toContain(said(LOADING_TIMES_SENTENCE));
        expect(inDialog('.kr-booking__skeleton')).toHaveLength(0);
    });

    it('renders a 4xx read failure politely inside the dialog, with Close there and the view still ready', async () => {
        moveReadAnswer = answering(NOT_FOUND, 404);
        await mount();
        await press(button(said(RESCHEDULE_LABEL)));

        expect(moveReads).toHaveLength(1);
        expect(inDialog('[role="status"]').map((region) => region.textContent).join(' ')).toContain('That booking could not be found.');
        expect(alerts()).toBe('');
        expect(button(said(CLOSE_LABEL)).disabled).toBe(false);
        expect(view()).toBe('ready');
        expect(container.textContent).not.toContain(said(NEUTRAL_SENTENCE));
    });

    it('renders a 5xx read failure as an alert inside the dialog, after the retries, and the view stays ready', async () => {
        moveReadAnswer = answering(BROKEN, 500);
        await mount();
        await press(button(said(RESCHEDULE_LABEL)));
        await settle(40);

        expect(moveReads).toHaveLength(ATTEMPTS);
        expect(inDialog('[role="alert"]').map((region) => region.textContent).join(' ')).toBe('Something went wrong at our end.');
        expect(button(said(CLOSE_LABEL)).disabled).toBe(false);
        expect(view()).toBe('ready');
    });

    it('sends one POST with the token and the start and nothing else, with the progress line in place of the pickers and nothing moved yet', async () => {
        await toTheSeventh();
        const before = summaryWhen();

        moveWithheld = true;

        await act(() => {
            slotButton(NEW_START).click();
        });
        await settle(2);

        expect(moves).toEqual([{ path: MOVE_PATH, body: { token: TOKEN, start: NEW_START } }]);
        expect(inDialog('[role="status"]').map((region) => region.textContent).join(' ')).toContain(said(MOVING_SENTENCE));
        expect(inDialog('.kr-booking__slot, .kr-booking__day, .kr-booking__next')).toHaveLength(0);
        expect(button(said(CLOSE_LABEL)).disabled).toBe(false);
        expect(document.activeElement).toBe(openDialog());
        expect(summaryWhen()).toBe(before);
        expect(alerts()).toBe('');

        releaseMove();
        await settle();
    });

    it('refuses a second press while a move flies, read synchronously between the presses', async () => {
        await toTheSeventh();

        moveWithheld = true;

        await act(() => {
            const slot = slotButton(NEW_START);

            slot.click();
            // Nothing has re-rendered: the control is still there and enabled, so only the
            // latch can refuse the next press.
            expect(slot.isConnected).toBe(true);
            expect(slot.disabled).toBe(false);
            slot.click();
        });
        await settle(2);

        expect(moves).toHaveLength(1);

        releaseMove();
        await settle();
    });

    it('closes on success, says so politely in the ready view, and shows the time the refetch answered', async () => {
        await toTheSeventh();
        const readsBefore = reads.length;

        readAnswer = answering(MOVED_BOOKING);

        await press(slotButton(NEW_START));

        expect(dialog()).toBeNull();
        expect(reads).toHaveLength(readsBefore + 1);
        expect(polite()).toContain(said(MOVED_SENTENCE));
        expect(alerts()).toBe('');
        expect(summaryWhen()).toContain(formatTime(wallClockOf(NEW_START, CONFIG.timezone)));
        expect(button(said(RESCHEDULE_LABEL)).disabled).toBe(false);
        expect(button(said(CANCEL_LABEL)).disabled).toBe(false);
    });

    it('mounts the moved notice into a region that already existed', async () => {
        await toTheSeventh();

        const regions = Array.from(container.querySelectorAll('.kr-booking__manage > [role="status"]'));

        await press(slotButton(NEW_START));

        const after = Array.from(container.querySelectorAll('.kr-booking__manage > [role="status"]'));

        expect(after.find((region) => region.textContent === said(MOVED_SENTENCE))).toBeDefined();
        expect(regions).toContain(after.find((region) => region.textContent === said(MOVED_SENTENCE)));
    });

    it('keeps the dialog open on a 409 with the sentence inside it, the summary unchanged, and refetches the move list once', async () => {
        await toTheSeventh();
        const before = summaryWhen();
        const readsBefore = reads.length;

        moveAnswer = answering(UNAVAILABLE, 409);

        await press(slotButton(NEW_START));

        expect(moves).toHaveLength(1);
        expect(inDialog('[role="status"]').map((region) => region.textContent).join(' ')).toContain('That time is no longer available. Please pick another.');
        expect(alerts()).toBe('');
        expect(summaryWhen()).toBe(before);
        expect(reads).toHaveLength(readsBefore);
        expect(moveReads).toEqual([FIRST_WINDOW, FIRST_WINDOW]);
        expect(inDialog('.kr-booking__slot').length).toBeGreaterThan(0);
        expect(polite()).not.toContain(said(MOVED_SENTENCE));
    });

    it('renders a 403 window_closed politely inside the dialog and refetches nothing', async () => {
        await toTheSeventh();

        moveAnswer = answering(MOVE_WINDOW_CLOSED, 403);

        await press(slotButton(NEW_START));

        expect(inDialog('[role="status"]').map((region) => region.textContent).join(' ')).toContain('It is too late to reschedule this booking online.');
        expect(alerts()).toBe('');
        expect(moveReads).toEqual([FIRST_WINDOW]);
        expect(view()).toBe('ready');
    });

    it('renders a 500 as an alert inside the dialog and never retries the move', async () => {
        await toTheSeventh();

        moveAnswer = answering(BROKEN, 500);

        await press(slotButton(NEW_START));
        await settle(20);

        expect(moves).toHaveLength(1);
        expect(inDialog('[role="alert"]').map((region) => region.textContent).join(' ')).toBe('Something went wrong at our end.');
        expect(moveReads).toEqual([FIRST_WINDOW]);
    });

    it('renders nothing and announces nothing for a success that lands after the close, while the booking is still refetched', async () => {
        await toTheSeventh();
        const readsBefore = reads.length;

        moveWithheld = true;
        readAnswer = answering(MOVED_BOOKING);

        await press(slotButton(NEW_START));
        await press(button(said(CLOSE_LABEL)));

        expect(dialog()).toBeNull();

        releaseMove();
        await settle();

        expect(dialog()).toBeNull();
        expect(polite()).not.toContain(said(MOVED_SENTENCE));
        expect(reads).toHaveLength(readsBefore + 1);
        expect(summaryWhen()).toContain(formatTime(wallClockOf(NEW_START, CONFIG.timezone)));
    });

    it('renders nothing for a refusal that lands after the close, and a dialog opened again starts clean', async () => {
        await toTheSeventh();

        moveWithheld = true;
        moveAnswer = answering(UNAVAILABLE, 409);

        await press(slotButton(NEW_START));
        await press(button(said(CLOSE_LABEL)));
        await press(button(said(RESCHEDULE_LABEL)));

        expect(openDialog().textContent).not.toContain(said(MOVING_SENTENCE));

        releaseMove();
        await settle();

        expect(container.textContent).not.toContain('That time is no longer available.');
        expect(inDialog('.kr-booking__day').length).toBeGreaterThan(0);

        // The fresh dialog's latch is free: a pick sends at once.
        moveWithheld = false;
        moveAnswer = answering(MOVED_BOOKING);
        await press(dayButton('2026-04-07'));
        await press(slotButton(NEW_START));

        expect(moves).toHaveLength(2);
        expect(dialog()).toBeNull();
    });

    it('carries the token on the wire only as its two transports do', async () => {
        await toTheSeventh();
        await press(slotButton(NEW_START));

        const attributes = Array.from(container.querySelectorAll('*')).flatMap((node) => Array.from(node.attributes).map((attribute) => attribute.value));

        expect(container.innerHTML).not.toContain(TOKEN);
        expect(attributes.some((value) => value.includes(TOKEN))).toBe(false);
        expect(JSON.stringify(localStorage)).not.toContain(TOKEN);
        expect(errors.join(' ')).not.toContain(TOKEN);
        expect(moves.map((move) => move.path)).toEqual([MOVE_PATH]);
        expect(MOVE_PATH).not.toContain(TOKEN);
    });
});

const HOLD_LAPSED = { code: 'hold_lapsed', message: 'hold_lapsed', data: { status: 409, detail: 'That time is not being held any more, so the booking cannot be moved.' } };

async function key(target: Element, name: string, shift = false): Promise<void> {
    await act(() => {
        target.dispatchEvent(new KeyboardEvent('keydown', { key: name, shiftKey: shift, bubbles: true, cancelable: true }));
    });
    await settle();
}

function focused(): Element | null {
    return document.activeElement;
}

/** The controls a Tab can reach inside the open dialog, in document order. */
function dialogControls(): HTMLButtonElement[] {
    return Array.from(openDialog().querySelectorAll<HTMLButtonElement>('button:not([disabled])'));
}

function lastOf<T>(list: readonly T[]): T {
    const found = list[list.length - 1];

    if (found === undefined) {
        throw new Error('The list is empty.');
    }

    return found;
}

describe('the keyboard inside the dialog, 6.5 and 7.5', () => {
    let heard: string[];
    const listener = (event: KeyboardEvent): void => {
        heard.push(event.key);
    };

    beforeEach(() => {
        heard = [];
        document.addEventListener('keydown', listener);
    });

    afterEach(() => {
        document.removeEventListener('keydown', listener);
    });

    it('closes on Escape and lets nothing on the host page hear that press, while an Escape with the dialog closed is the host page\'s', async () => {
        await mount();
        await press(button(said(RESCHEDULE_LABEL)));

        await key(openDialog(), 'Escape');

        expect(dialog()).toBeNull();
        expect(heard).toEqual([]);

        await key(button(said(RESCHEDULE_LABEL)), 'Escape');

        expect(heard).toEqual(['Escape']);
    });

    it('closes on an Escape pressed on a control inside the dialog, one press one layer', async () => {
        await toTheSeventh();

        await key(slotButton(NEW_START), 'Escape');

        expect(dialog()).toBeNull();
        expect(heard).toEqual([]);
        expect(moves).toEqual([]);
    });

    it('cycles Tab within the dialog, the container first: Tab from the last control lands on the container, Shift+Tab from the container on the last control', async () => {
        await toTheSeventh();

        const opened = openDialog();
        const controls = dialogControls();
        const first = controls[0];
        const last = lastOf(controls);

        expect(last.textContent).toBe(said(CLOSE_LABEL));

        await act(() => {
            opened.focus();
        });
        await key(opened, 'Tab');

        expect(focused()).toBe(first);

        await act(() => {
            last.focus();
        });
        await key(last, 'Tab');

        expect(focused()).toBe(opened);

        await key(opened, 'Tab', true);

        expect(focused()).toBe(last);

        await act(() => {
            first?.focus();
        });
        await key(first ?? opened, 'Tab', true);

        expect(focused()).toBe(opened);
    });

    it('lets nothing outside the dialog take the focus through a full turn either way', async () => {
        await toTheSeventh();

        const opened = openDialog();
        const turn = dialogControls().length + 2;
        const visited: Element[] = [];

        for (const shift of [false, true]) {
            await act(() => {
                opened.focus();
            });

            for (let step = 0; step < turn; step += 1) {
                await key(focused() ?? opened, 'Tab', shift);
                visited.push(focused() ?? document.body);
            }
        }

        expect(visited.every((element) => opened.contains(element))).toBe(true);
        expect(new Set(visited).size).toBe(dialogControls().length + 1);
    });
});

describe('the focus return to the trigger, 6.5', () => {
    it('lands on the trigger, enabled, after Escape', async () => {
        await mount();
        const trigger = button(said(RESCHEDULE_LABEL));

        await press(trigger);
        await key(openDialog(), 'Escape');

        expect(focused()).toBe(trigger);
        expect(trigger.disabled).toBe(false);
    });

    it('lands on the trigger, enabled, after the close control', async () => {
        await mount();
        const trigger = button(said(RESCHEDULE_LABEL));

        await press(trigger);
        await press(button(said(CLOSE_LABEL)));

        expect(focused()).toBe(trigger);
        expect(trigger.disabled).toBe(false);
    });

    it('lands on the trigger, enabled, after a successful move', async () => {
        await toTheSeventh();
        const trigger = button(said(RESCHEDULE_LABEL));

        readAnswer = answering(MOVED_BOOKING);

        await press(slotButton(NEW_START));

        expect(dialog()).toBeNull();
        expect(focused()).toBe(trigger);
        expect(trigger.disabled).toBe(false);
    });

    it('closes nothing and moves the focus nowhere on a refusal', async () => {
        await toTheSeventh();

        moveWithheld = true;
        moveAnswer = answering(UNAVAILABLE, 409);

        await act(() => {
            slotButton(NEW_START).click();
        });
        await settle(2);

        const holding = focused();

        releaseMove();
        await settle();

        expect(openDialog().textContent).toContain('That time is no longer available.');
        expect(focused()).toBe(holding);
        expect(focused()).toBe(openDialog());
    });

    it('moves nothing for a success that lands after the close, beyond the return the close itself made', async () => {
        await toTheSeventh();
        const trigger = button(said(RESCHEDULE_LABEL));

        moveWithheld = true;

        await press(slotButton(NEW_START));
        await press(button(said(CLOSE_LABEL)));

        expect(focused()).toBe(trigger);

        await act(() => {
            button(said(CANCEL_LABEL)).focus();
        });

        releaseMove();
        await settle();

        expect(focused()).toBe(button(said(CANCEL_LABEL)));
    });
});

describe('the action area\'s two actions are exclusive', () => {
    it('disables every button of the area but Close while the dialog is open, Keep this booking included', async () => {
        await mount();

        // The one route to an armed row under an open dialog: both presses land before either
        // re-render, so neither disabled attribute is in the DOM yet.
        await act(() => {
            button(said(CANCEL_LABEL)).click();
            button(said(RESCHEDULE_LABEL)).click();
        });
        await settle();

        expect(dialog()).not.toBeNull();

        const background = buttons().filter((control) => !openDialog().contains(control));

        expect(background.map((control) => control.textContent)).toEqual([said(CANCEL_LABEL), said(RESCHEDULE_LABEL), said(YES_LABEL), said(KEEP_LABEL)]);
        expect(background.every((control) => control.disabled)).toBe(true);
        expect(button(said(CLOSE_LABEL)).disabled).toBe(false);
    });

    it('disables Reschedule while the cancel row is armed, and gives it back on Keep this booking', async () => {
        await mount();
        await press(button(said(CANCEL_LABEL)));

        expect(button(said(RESCHEDULE_LABEL)).disabled).toBe(true);

        await press(button(said(KEEP_LABEL)));

        expect(button(said(RESCHEDULE_LABEL)).disabled).toBe(false);
    });

    it('disables Reschedule while a cancel flies, and a Keep that withdraws the cancel gives it back', async () => {
        await mount();
        await press(button(said(CANCEL_LABEL)));

        cancelWithheld = true;

        await press(button(said(YES_LABEL)));

        expect(polite()).toContain(said(CANCELLING_SENTENCE));
        expect(button(said(RESCHEDULE_LABEL)).disabled).toBe(true);

        await press(button(said(KEEP_LABEL)));

        expect(polite()).not.toContain(said(CANCELLING_SENTENCE));
        expect(button(said(RESCHEDULE_LABEL)).disabled).toBe(false);

        releaseCancel();
        await settle();
    });

    it('keeps the moved sentence until the customer does something else', async () => {
        await toTheSeventh();

        readAnswer = answering(MOVED_BOOKING);

        await press(slotButton(NEW_START));

        expect(polite()).toContain(said(MOVED_SENTENCE));

        // A refetch of the booking that changes nothing on screen leaves it standing.
        await act(async () => {
            await client.invalidateQueries({ queryKey: ['booking', UUID] });
        });
        await settle();

        expect(polite()).toContain(said(MOVED_SENTENCE));

        await press(button(said(CANCEL_LABEL)));
        await press(button(said(KEEP_LABEL)));

        expect(polite()).not.toContain(said(MOVED_SENTENCE));
    });
});

describe('the reschedule affordance lapses with the hold', () => {
    // The hold lapses three seconds after the mount.
    const LAPSING = { ...BOOKING, status: 'pending', hold_class: 'checkout', hold_expires_at: '2026-04-06T07:00:03+00:00' };

    beforeEach(() => {
        vi.useFakeTimers({ now: MOUNTED_AT });
    });

    it('offers Reschedule, then does not once the clock passes the expiry, with no refetch, no reload and Cancel standing', async () => {
        readAnswer = answering(LAPSING);
        await mount(options(), routing, () => Date.now());

        const readsBefore = reads.length;

        expect(button(said(RESCHEDULE_LABEL)).disabled).toBe(false);

        await act(async () => {
            await vi.advanceTimersByTimeAsync(2999);
        });

        expect(buttons().map((control) => control.textContent)).toEqual([said(CANCEL_LABEL), said(RESCHEDULE_LABEL)]);

        await act(async () => {
            await vi.advanceTimersByTimeAsync(1);
        });

        expect(buttons().map((control) => control.textContent)).toEqual([said(CANCEL_LABEL)]);
        expect(button(said(CANCEL_LABEL)).disabled).toBe(false);
        expect(reads).toHaveLength(readsBefore);
        expect(vi.getTimerCount()).toBe(0);
    });

    it('never takes it from a confirmed booking, and runs no timer for one', async () => {
        await mount(options(), routing, () => Date.now());

        expect(vi.getTimerCount()).toBe(0);

        await act(async () => {
            await vi.advanceTimersByTimeAsync(24 * 60 * 60 * 1000);
        });

        expect(button(said(RESCHEDULE_LABEL)).disabled).toBe(false);
    });

    it('runs no timer once the view is gone', async () => {
        readAnswer = answering(LAPSING);
        await mount(options(), routing, () => Date.now());

        expect(vi.getTimerCount()).toBeGreaterThan(0);

        await act(() => {
            root.unmount();
        });

        expect(vi.getTimerCount()).toBe(0);

        root = createRoot(container);
    });

    it('keeps a dialog open across the lapse, renders the server\'s 409 inside it politely, and reveals no Reschedule once closed', async () => {
        readAnswer = answering(LAPSING);
        await mount(options(), routing, () => Date.now());
        await press(button(said(RESCHEDULE_LABEL)));
        await press(dayButton('2026-04-07'));

        await act(async () => {
            await vi.advanceTimersByTimeAsync(5000);
        });

        expect(dialog()).not.toBeNull();
        // The trigger stays, disabled, for the dialog's whole lifetime; it goes with the close.
        expect(button(said(RESCHEDULE_LABEL)).disabled).toBe(true);

        moveAnswer = answering(HOLD_LAPSED, 409);

        await press(slotButton(NEW_START));

        expect(inDialog('[role="status"]').map((region) => region.textContent).join(' ')).toContain(HOLD_LAPSED.data.detail);
        expect(alerts()).toBe('');

        await press(button(said(CLOSE_LABEL)));

        expect(dialog()).toBeNull();
        expect(buttons().map((control) => control.textContent)).toEqual([said(CANCEL_LABEL)]);
        // The trigger is gone, so the return lands on the container, never on the page body.
        expect(focused()).toBe(frame());
    });
});

/**
 * Every transition the manage view makes, by name. The record below is typed over the union, so
 * a name added here and missing there fails the type check, and the table's own test fails for a
 * name no row covers.
 */
type ManageTransition =
    | 'initial load'
    | 'open the dialog'
    | 'pick a time'
    | 'close by Escape'
    | 'close by Close'
    | 'close by a current success'
    | 'close after a lapse took the trigger'
    | 'a move refusal'
    | 'a success landing after the close'
    | 'the affordance lapsing'
    | 'the affordance lapsing under the focus'
    | 'a booking refetch'
    | 'a cancel succeeding'
    | 'a cancel refused'
    | 'the retry panel read answering'
    | 'ask to cancel'
    | 'yes, cancel it'
    | 'keep this booking'
    | 'try again'
    | 'a day inside the dialog'
    | 'next window inside the dialog';

const MANAGE_TRANSITIONS: Record<ManageTransition, true> = {
    'initial load': true,
    'open the dialog': true,
    'pick a time': true,
    'close by Escape': true,
    'close by Close': true,
    'close by a current success': true,
    'close after a lapse took the trigger': true,
    'a move refusal': true,
    'a success landing after the close': true,
    'the affordance lapsing': true,
    'the affordance lapsing under the focus': true,
    'a booking refetch': true,
    'a cancel succeeding': true,
    'a cancel refused': true,
    'the retry panel read answering': true,
    'ask to cancel': true,
    'yes, cancel it': true,
    'keep this booking': true,
    'try again': true,
    'a day inside the dialog': true,
    'next window inside the dialog': true,
};

/**
 * Where focus is after a transition: on the dialog container, on the Reschedule trigger, on the
 * view's container, or where it was, which for a machine's move is the host page and for a
 * press is the control pressed.
 */
type ManageLanding = 'dialog' | 'trigger' | 'container' | 'unmoved';

interface ManageRow {
    readonly transition: ManageTransition;
    readonly cause: 'customer' | 'machine';
    readonly landing: ManageLanding;
    /**
     * Drives the view to the transition and makes it, handed the way to put focus on the host
     * page where a machine's move is about to come. Answers the element focus must be on when
     * the landing is unmoved and that is not the host page.
     */
    readonly run: (away: () => void) => Promise<Element | undefined>;
}

// The hold lapses three seconds after the mount.
const LAPSES_SOON = { ...BOOKING, status: 'pending', hold_class: 'checkout', hold_expires_at: '2026-04-06T07:00:03+00:00' };

/**
 * A read answer the test hands over when it chooses.
 */
function withheldRead(body: unknown): () => void {
    let answer: () => void = () => undefined;

    readAnswer = () =>
        new Promise<Response>((resolve) => {
            answer = () => resolve(new Response(JSON.stringify(body), { status: 200, headers: { 'Content-Type': 'application/json' } }));
        });

    return () => answer();
}

/**
 * Focuses a control and presses it, as a keyboard user does.
 */
async function pressFocused(el: HTMLElement): Promise<void> {
    await act(() => {
        el.focus();
    });
    await press(el);
}

async function lapse(): Promise<void> {
    await act(async () => {
        await vi.advanceTimersByTimeAsync(5000);
    });
}

const MANAGE_ROWS: readonly ManageRow[] = [
    {
        transition: 'open the dialog',
        cause: 'customer',
        landing: 'dialog',
        run: async () => {
            await mount();
            await pressFocused(button(said(RESCHEDULE_LABEL)));

            return undefined;
        },
    },
    {
        transition: 'pick a time',
        cause: 'customer',
        landing: 'dialog',
        run: async () => {
            await toTheSeventh();

            moveWithheld = true;

            await pressFocused(slotButton(NEW_START));

            expect(openDialog().textContent).toContain(said(MOVING_SENTENCE));

            return undefined;
        },
    },
    {
        transition: 'close by Escape',
        cause: 'customer',
        landing: 'trigger',
        run: async () => {
            await mount();
            await pressFocused(button(said(RESCHEDULE_LABEL)));
            await key(openDialog(), 'Escape');

            expect(dialog()).toBeNull();

            return undefined;
        },
    },
    {
        transition: 'close by Close',
        cause: 'customer',
        landing: 'trigger',
        run: async () => {
            await mount();
            await pressFocused(button(said(RESCHEDULE_LABEL)));
            await pressFocused(button(said(CLOSE_LABEL)));

            expect(dialog()).toBeNull();

            return undefined;
        },
    },
    {
        transition: 'close by a current success',
        cause: 'customer',
        landing: 'trigger',
        run: async () => {
            await toTheSeventh();

            readAnswer = answering(MOVED_BOOKING);

            await pressFocused(slotButton(NEW_START));

            expect(dialog()).toBeNull();
            expect(polite()).toContain(said(MOVED_SENTENCE));

            return undefined;
        },
    },
    {
        transition: 'close after a lapse took the trigger',
        cause: 'customer',
        landing: 'container',
        run: async () => {
            vi.useFakeTimers({ now: MOUNTED_AT });
            readAnswer = answering(LAPSES_SOON);
            await mount(options(), routing, () => Date.now());
            await pressFocused(button(said(RESCHEDULE_LABEL)));
            await lapse();
            await pressFocused(button(said(CLOSE_LABEL)));

            expect(dialog()).toBeNull();
            expect(buttons().map((control) => control.textContent)).toEqual([said(CANCEL_LABEL)]);

            return undefined;
        },
    },
    {
        transition: 'a move refusal',
        cause: 'machine',
        landing: 'unmoved',
        run: async (away) => {
            await toTheSeventh();

            moveWithheld = true;
            moveAnswer = answering(UNAVAILABLE, 409);

            await pressFocused(slotButton(NEW_START));

            away();
            releaseMove();
            await settle();

            expect(openDialog().textContent).toContain(UNAVAILABLE.data.detail);

            return undefined;
        },
    },
    {
        transition: 'a success landing after the close',
        cause: 'machine',
        landing: 'unmoved',
        run: async (away) => {
            await toTheSeventh();

            moveWithheld = true;
            readAnswer = answering(MOVED_BOOKING);

            await pressFocused(slotButton(NEW_START));
            await pressFocused(button(said(CLOSE_LABEL)));

            away();
            releaseMove();
            await settle();

            expect(moves).toHaveLength(1);
            expect(reads.length).toBeGreaterThan(1);

            return undefined;
        },
    },
    {
        transition: 'the affordance lapsing',
        cause: 'machine',
        landing: 'unmoved',
        run: async (away) => {
            vi.useFakeTimers({ now: MOUNTED_AT });
            readAnswer = answering(LAPSES_SOON);
            await mount(options(), routing, () => Date.now());

            away();
            await lapse();

            expect(buttons().map((control) => control.textContent)).toEqual([said(CANCEL_LABEL)]);

            return undefined;
        },
    },
    {
        transition: 'the affordance lapsing under the focus',
        cause: 'machine',
        landing: 'unmoved',
        run: async () => {
            vi.useFakeTimers({ now: MOUNTED_AT });
            readAnswer = answering(LAPSES_SOON);
            await mount(options(), routing, () => Date.now());
            await act(() => {
                button(said(RESCHEDULE_LABEL)).focus();
            });
            await lapse();

            expect(buttons().map((control) => control.textContent)).toEqual([said(CANCEL_LABEL)]);

            // The trigger goes with the focus it held, and nothing takes that focus anywhere:
            // not to the container, not to Cancel. The page body is what a removed element
            // leaves behind.
            return document.body;
        },
    },
    {
        transition: 'a booking refetch',
        cause: 'machine',
        landing: 'unmoved',
        run: async (away) => {
            await mount();

            readAnswer = answering(MOVED_BOOKING);

            away();
            await act(async () => {
                await client.invalidateQueries({ queryKey: ['booking', UUID] });
            });
            await settle();

            expect(reads).toHaveLength(2);

            return undefined;
        },
    },
    {
        transition: 'initial load',
        cause: 'machine',
        landing: 'unmoved',
        run: async () => {
            await mount();

            expect(view()).toBe('ready');

            // The initial mount steals nothing on the manage surface either: no host field is
            // focused first, so the body is what must still hold the focus.
            return document.body;
        },
    },
    {
        transition: 'a cancel succeeding',
        cause: 'machine',
        landing: 'unmoved',
        run: async (away) => {
            await mount();
            await pressFocused(button(said(CANCEL_LABEL)));

            cancelWithheld = true;
            readAnswer = answering({ ...BOOKING, status: 'cancelled' });

            await pressFocused(button(said(YES_LABEL)));

            away();
            releaseCancel();
            await settle();

            expect(polite()).toContain(statusSaid('cancelled'));

            return undefined;
        },
    },
    {
        transition: 'a cancel refused',
        cause: 'machine',
        landing: 'unmoved',
        run: async (away) => {
            await mount();
            await pressFocused(button(said(CANCEL_LABEL)));

            cancelWithheld = true;
            cancelAnswer = answering(WINDOW_CLOSED, 403);

            await pressFocused(button(said(YES_LABEL)));

            away();
            releaseCancel();
            await settle();

            expect(polite()).toContain(WINDOW_CLOSED.data.detail);

            return undefined;
        },
    },
    {
        transition: 'the retry panel read answering',
        cause: 'machine',
        landing: 'unmoved',
        run: async (away) => {
            readAnswer = answering(BROKEN, 500);
            await mount();

            expect(view()).toBe('retry');

            const answer = withheldRead(BOOKING);

            await pressFocused(button(said(TRY_AGAIN_LABEL)));

            away();
            await act(() => answer());
            await settle();

            expect(view()).toBe('ready');

            return undefined;
        },
    },
    {
        transition: 'keep this booking',
        cause: 'customer',
        landing: 'container',
        run: async () => {
            await mount();
            await pressFocused(button(said(CANCEL_LABEL)));
            await pressFocused(button(said(KEEP_LABEL)));

            // The press unmounts the row holding the pressed control, so the view lands the
            // focus on its container rather than dropping it to the page body.
            expect(container.textContent).not.toContain(said(CONFIRM_QUESTION));

            return undefined;
        },
    },
    {
        transition: 'ask to cancel',
        cause: 'customer',
        landing: 'unmoved',
        run: async () => {
            await mount();

            const cancel = button(said(CANCEL_LABEL));

            await pressFocused(cancel);

            expect(container.textContent).toContain(said(CONFIRM_QUESTION));
            // Cancel is disabled by its own press and keeps the focus; the code moves nothing.
            expect(cancel.disabled).toBe(true);

            return cancel;
        },
    },
    {
        transition: 'yes, cancel it',
        cause: 'customer',
        landing: 'unmoved',
        run: async () => {
            await mount();
            await pressFocused(button(said(CANCEL_LABEL)));

            cancelWithheld = true;

            const yes = button(said(YES_LABEL));

            await pressFocused(yes);

            expect(polite()).toContain(said(CANCELLING_SENTENCE));
            expect(yes.disabled).toBe(true);

            return yes;
        },
    },
    {
        transition: 'try again',
        cause: 'customer',
        landing: 'unmoved',
        run: async () => {
            readAnswer = answering(BROKEN, 500);
            await mount();
            withheldRead(BOOKING);

            const retry = button(said(TRY_AGAIN_LABEL));

            await pressFocused(retry);

            expect(retry.textContent).toBe(said(TRYING_LABEL));
            expect(retry.disabled).toBe(true);

            return retry;
        },
    },
    {
        transition: 'a day inside the dialog',
        cause: 'customer',
        landing: 'unmoved',
        run: async () => {
            await mount();
            await pressFocused(button(said(RESCHEDULE_LABEL)));

            const day = dayButton('2026-04-07');

            await pressFocused(day);

            expect(day.getAttribute('aria-pressed')).toBe('true');

            return day;
        },
    },
    {
        transition: 'next window inside the dialog',
        cause: 'customer',
        landing: 'unmoved',
        run: async () => {
            await mount();
            await pressFocused(button(said(RESCHEDULE_LABEL)));

            const next = button(said(NEXT_WEEK_LABEL));

            await pressFocused(next);

            expect(moveReads).toContain(SECOND_WINDOW);

            return next;
        },
    },
];

describe('7.4 on the manage surface, every transition in one table', () => {
    let host: HTMLInputElement;

    beforeEach(() => {
        host = document.createElement('input');
        host.setAttribute('aria-label', 'A field on the host page');
        document.body.appendChild(host);
    });

    afterEach(() => {
        host.remove();
    });

    it('is exhaustive over the view\'s transitions: every one is exactly one row', () => {
        const rows = MANAGE_ROWS.map((row) => row.transition);

        expect(new Set(rows).size).toBe(rows.length);
        expect([...rows].sort()).toEqual(Object.keys(MANAGE_TRANSITIONS).sort());
    });

    it('lets no machine-caused row move focus at all', () => {
        expect(MANAGE_ROWS.filter((row) => row.cause === 'machine' && row.landing !== 'unmoved').map((row) => row.transition)).toEqual([]);
    });

    it.each(MANAGE_ROWS)('$transition ($cause): focus $landing', async ({ cause, landing, run }) => {
        const expected = await run(() => host.focus());
        const now = focused();

        switch (landing) {
            case 'dialog':
                expect(now).toBe(openDialog());

                return;
            case 'trigger':
                expect(now).toBe(button(said(RESCHEDULE_LABEL)));
                expect(button(said(RESCHEDULE_LABEL)).disabled).toBe(false);

                return;
            case 'container':
                expect(now).toBe(frame());

                return;
            case 'unmoved':
                expect(now).not.toBe(frame());
                expect(now).not.toBe(dialog());

                if (cause === 'machine') {
                    expect(now).toBe(expected ?? host);

                    return;
                }

                expect(expected).toBeDefined();
                expect(now).toBe(expected);
                expect(expected?.isConnected).toBe(true);
        }
    });
});

/**
 * One stop of the dialog's Tab cycle, named the way the test's own list names it: the dialog
 * itself, a day or a slot by the wire value it carries, anything else by its visible label.
 */
function stopName(element: Element | null, opened: HTMLElement): string {
    if (element === opened) {
        return 'the dialog';
    }

    if (element instanceof HTMLButtonElement && element.value !== '') {
        return element.value;
    }

    return element?.textContent ?? 'nothing';
}

describe('7.5, the Tab cycle inside the dialog against a list written out by hand', () => {
    // Read once off the rendered dialog on the seventh and written down here, so the cycle is held
    // against this list and never against the selector the code walks: the dialog itself, the
    // seventh, the one day with times, since the sixth and the eighth are disabled and no stop,
    // Next week after the days, the two times of the seventh, then Close.
    const CYCLE = ['the dialog', '2026-04-07', said(NEXT_WEEK_LABEL), NEW_START, '2026-04-06T23:30:00+00:00', said(CLOSE_LABEL)];

    it('steps forward through the list from the container and wraps back to it, then backward the same way', async () => {
        await toTheSeventh();

        const opened = openDialog();
        const forward: string[] = [];
        const backward: string[] = [];

        await act(() => {
            opened.focus();
        });

        for (let press = 0; press < CYCLE.length; press += 1) {
            await key(focused() ?? opened, 'Tab');
            forward.push(stopName(focused(), opened));
        }

        expect(forward).toEqual([...CYCLE.slice(1), 'the dialog']);

        for (let press = 0; press < CYCLE.length; press += 1) {
            await key(focused() ?? opened, 'Tab', true);
            backward.push(stopName(focused(), opened));
        }

        expect(backward).toEqual([...CYCLE.slice(1).reverse(), 'the dialog']);
    });
});

describe('the manage page in Greek', () => {
    // The walk by keys: each view and each state rendered with lang/el/booking.php's lines shows no
    // English line of booking.php but the shared ones and no :word unfilled; the services, the
    // members and the customer stay as typed.
    async function greek(): Promise<void> {
        await mount(options(), routing, () => MOUNTED_AT, null, GREEK_WORDS, 'el');
    }

    function greekButton(key: Parameters<typeof GREEK_WORDS.text>[0]): HTMLButtonElement {
        return button(GREEK_WORDS.text(key));
    }

    it('renders the loading, neutral and retry views with no English line left', async () => {
        readAnswer = () => new Promise<Response>(() => undefined);
        await greek();
        expect(view()).toBe('loading');
        expect(englishLeft(container)).toEqual([]);

        await act(() => {
            root.unmount();
        });
        root = createRoot(container);
        readAnswer = answering(NOT_FOUND, 404);
        await greek();
        expect(view()).toBe('neutral');
        expect(englishLeft(container)).toEqual([]);

        await act(() => {
            root.unmount();
        });
        root = createRoot(container);
        readAnswer = dropping();
        await greek();
        expect(view()).toBe('retry');
        expect(alerts()).toContain(GREEK_WORDS.text(TRANSPORT_SENTENCE));
        expect(englishLeft(container)).toEqual([]);
    });

    it.each(BOOKING_STATUSES.map((status) => [status]))('renders the ready view of a %s booking with no English line left', async (status) => {
        readAnswer = answering({ ...BOOKING, status, hold_class: status === 'pending' ? 'checkout' : null, hold_expires_at: status === 'pending' ? '2026-04-06T08:00:00+00:00' : null });
        await greek();

        expect(view()).toBe('ready');

        if (status !== 'confirmed') {
            expect(container.textContent).toContain(GREEK_WORDS.text(statusSentence(status) ?? 'manage.statuses.pending'));
        }

        expect(englishLeft(container)).toEqual([]);
    });

    it('walks the cancel\'s question, its progress line and its landing with no English line left', async () => {
        await greek();
        await press(greekButton(CANCEL_LABEL));
        expect(container.textContent).toContain(GREEK_WORDS.text(CONFIRM_QUESTION));
        expect(englishLeft(container)).toEqual([]);

        cancelWithheld = true;
        await press(greekButton(YES_LABEL));
        expect(polite()).toContain(GREEK_WORDS.text(CANCELLING_SENTENCE));
        expect(englishLeft(container)).toEqual([]);

        readAnswer = answering({ ...BOOKING, status: 'cancelled' });
        releaseCancel();
        await settle();
        expect(englishLeft(container)).toEqual([]);
    });

    it('walks the reschedule dialog to a landed move with no English line left', async () => {
        await greek();
        await press(greekButton(RESCHEDULE_LABEL));
        expect(englishLeft(container)).toEqual([]);

        await press(dayButton('2026-04-07'));
        expect(openDialog().textContent).toContain(GREEK_WORDS.text(PICK_SENTENCE));
        expect(englishLeft(container)).toEqual([]);

        moveWithheld = true;
        await press(slotButton(NEW_START));
        expect(englishLeft(container)).toEqual([]);

        readAnswer = answering(MOVED_BOOKING);
        releaseMove();
        await settle();
        expect(polite()).toContain(GREEK_WORDS.text(MOVED_SENTENCE));
        expect(englishLeft(container)).toEqual([]);
    });
});
