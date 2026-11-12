import { QueryClientProvider, type QueryClient } from '@tanstack/react-query';
import { act, type ReactNode } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { bookingSources } from '../bookingSources';
import { keyed, KEYED_LINES, said, saidBy } from '../bookingWords';
import { createQueryClient, type Api } from './api';
import { App, CRASHED_SENTENCE, ErrorBoundary, LOADING_SENTENCE, UNAVAILABLE_SENTENCE } from './App';
import type { MountOptions } from './attributes';
import { AVAILABILITY_PATH } from './availability';
import { cancelPath } from './cancel';
import type { BootConfig, Fetch } from './config';
import { confirmPath } from './confirm';
import { EXPIRED_MILESTONE } from './countdown';
import { BUSY_LABEL } from './DetailsStep';
import { sentenceFor } from './DoneStep';
import { CONTINUE_LABEL } from './Estimate';
import { Flow } from './Flow';
import { HANDED_OFF_SENTENCE, type Step } from './flow';
import { HOLDS_PATH } from './hold';
import { BOOKINGS_PATH } from './manage';
import { CANCEL_LABEL, CANCELLING_SENTENCE, CONFIRM_QUESTION, LOADING_BOOKING_SENTENCE, ManageView, NEUTRAL_SENTENCE, statusSentence, TRY_AGAIN_LABEL, TRYING_LABEL, YES_LABEL, type ManageViewName } from './ManageView';
import { MOVED_SENTENCE, MOVING_SENTENCE, RESCHEDULE_LABEL, reschedulePath } from './move';
import { parseBooking } from './booking';
import { MY_BOOKINGS_PATH, type MyBookingsPage } from './myBookings';
import { CANCELLED_SENTENCE, MyBookingsView, SENDING_LABEL, type MyBookingsViewName } from './MyBookingsView';
import { PAYMENT_LABEL } from './ReviewStep';
import { REMOVED_SENTENCE, STALE_SENTENCE } from './ServiceStep';
import { PERIOD_NAMES, STALE_TIMES_SENTENCE, TIMES_COUNT } from './WhenStep';

/*
 * The live regions over every screen the app can show. A DOM walk after each screen finds every
 * live-region element and holds it to what the one region component renders: a leaf paragraph,
 * polite with at most one keyed span of text or an alert with bare text, with no form inside it
 * or above it. The DOM alone cannot say which component wrote an element, so a source audit
 * closes the loop: the live-region markup is spelled in NoticeRegion.tsx and in no other source
 * file of the app. Every screen renders with the keyed table, so each site's words are named by
 * their key.
 */

/** The status region's sentence for a cancelled booking, under the keyed table. */
const CANCELLED_STATUS = said(statusSentence('cancelled') ?? NEUTRAL_SENTENCE);

const LIVE_SELECTOR = '[role="status"], [role="alert"], [role="log"], [role="marquee"], [role="timer"], [aria-live]';

const NOTICE_CLASS = 'kr-booking__notice';

/**
 * Every live-region element under the root that is not what NoticeRegion renders, each named by
 * what is wrong with it and its markup. An empty list is the rule holding.
 */
function walk(root: Element): string[] {
    return Array.from(root.querySelectorAll(LIVE_SELECTOR)).flatMap((region) => {
        const problems = problemsOf(region);

        return problems.length > 0 ? [`${problems.join('; ')}: ${region.outerHTML}`] : [];
    });
}

/**
 * What is wrong with one live-region element: its shape, what its role asks of it, and a form
 * around it or inside it.
 */
function problemsOf(region: Element): string[] {
    const problems: string[] = [];

    if (region.tagName !== 'P') {
        problems.push('not a paragraph');
    }

    if (region.hasAttribute('aria-live')) {
        problems.push('carries aria-live, which the region component never writes');
    }

    problems.push(...roleProblemsOf(region));

    if (region.closest('form') !== null) {
        problems.push('inside a form');
    }

    if (region.querySelector('form') !== null) {
        problems.push('holding a form');
    }

    return problems;
}

function hasNoticeClasses(region: Element, modifier: 'polite' | 'error'): boolean {
    return region.classList.contains(NOTICE_CLASS) && region.classList.contains(`${NOTICE_CLASS}--${modifier}`);
}

/** A leaf: no element at all, or one span holding none. */
function holdsAtMostOneLeafSpan(region: Element): boolean {
    const children = Array.from(region.children);
    const [only] = children;

    return children.length === 0 || (children.length === 1 && only?.tagName === 'SPAN' && only.children.length === 0);
}

function roleProblemsOf(region: Element): string[] {
    const role = region.getAttribute('role');

    if (role === 'status') {
        return [
            ...(hasNoticeClasses(region, 'polite') ? [] : ['a status region without the polite notice classes']),
            ...(holdsAtMostOneLeafSpan(region) ? [] : ['a status region holding more than one leaf span']),
        ];
    }

    if (role === 'alert') {
        return [
            ...(hasNoticeClasses(region, 'error') ? [] : ['an alert region without the error notice classes']),
            ...(region.children.length === 0 ? [] : ['an alert region holding elements']),
        ];
    }

    return [`a live region with role ${JSON.stringify(role)}`];
}

function fixture(html: string): HTMLElement {
    const root = document.createElement('div');

    root.innerHTML = html;

    return root;
}

describe('the walk itself', () => {
    it('passes what the region component renders, polite empty, polite with a span, and an alert', () => {
        const clean = fixture(
            '<p class="kr-booking__notice kr-booking__notice--polite" role="status"></p>'
                + '<p class="kr-booking__notice kr-booking__notice--polite" role="status"><span>A sentence the server wrote.</span></p>'
                + '<p class="kr-booking__notice kr-booking__notice--error" role="alert">Something went wrong.</p>'
                + '<form><input name="customer_name"></form>',
        );

        expect(walk(clean)).toEqual([]);
    });

    it.each([
        ['a div with role status', '<div class="kr-booking__notice kr-booking__notice--polite" role="status"></div>', 'not a paragraph'],
        ['a status paragraph inside a form', '<form><p class="kr-booking__notice kr-booking__notice--polite" role="status"></p></form>', 'inside a form'],
        ['a status paragraph with a nested element', '<p class="kr-booking__notice kr-booking__notice--polite" role="status"><span><b>Hi</b></span></p>', 'more than one leaf span'],
        ['a status paragraph with two spans', '<p class="kr-booking__notice kr-booking__notice--polite" role="status"><span>a</span><span>b</span></p>', 'more than one leaf span'],
        ['an alert paragraph holding a span', '<p class="kr-booking__notice kr-booking__notice--error" role="alert"><span>a</span></p>', 'an alert region holding elements'],
        ['a status paragraph without the notice classes', '<p role="status"></p>', 'without the polite notice classes'],
        ['an alert paragraph with the polite classes', '<p class="kr-booking__notice kr-booking__notice--polite" role="alert">a</p>', 'without the error notice classes'],
        ['a bare aria-live container', '<div aria-live="polite"><form></form></div>', 'holding a form'],
        ['a log region', '<p class="kr-booking__notice kr-booking__notice--polite" role="log"></p>', 'role "log"'],
        ['a timer region', '<p role="timer">1:00</p>', 'role "timer"'],
    ])('flags %s', (_name, html, problem) => {
        const violations = walk(fixture(html));

        expect(violations).toHaveLength(1);
        expect(violations[0]).toContain(problem);
        expect(violations[0]).toMatch(/: <(p|div) /);
    });
});

const ORIGIN = 'https://luna.kleiseradevou.gr';

const CONFIG: BootConfig = { tenantName: 'Luna Hair Studio', timezone: 'Europe/Athens', currency: 'EUR', granularityMin: 5, importantInfo: null, defaultLanguage: 'en', language: 'en' };

type Answer = () => Promise<Response>;

function reply(body: unknown, status = 200): Answer {
    return () => Promise.resolve(new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }));
}

const NEVER: Answer = () => new Promise<Response>(() => undefined);

const DROPPED: Answer = () => Promise.reject(new TypeError('Failed to fetch'));

let container: HTMLDivElement;
let root: Root;
// The query client of the surface mounted last, so a screen can make a refetch fail under data.
let client: QueryClient;

beforeEach(() => {
    container = document.createElement('div');
    document.body.appendChild(container);
    root = createRoot(container);
    // The crash screen and the manage view's retries report to the console; none of it is under
    // test here.
    vi.spyOn(console, 'error').mockImplementation(() => undefined);
});

afterEach(async () => {
    await act(() => {
        root.unmount();
    });
    container.remove();
    vi.restoreAllMocks();
});

/**
 * Lets the query client's deferred notifications, any retries behind them and the effects they
 * trigger land.
 */
async function settle(turns = 12): Promise<void> {
    await act(async () => {
        for (let turn = 0; turn < turns; turn += 1) {
            await new Promise<void>((resolve) => setTimeout(resolve, 0));
        }
    });
}

async function render(node: ReactNode): Promise<void> {
    await act(() => {
        root.render(keyed(node));
    });
    await settle();
}

function buttons(): HTMLButtonElement[] {
    return Array.from(container.querySelectorAll('button'));
}

function button(name: string): HTMLButtonElement {
    const found = buttons().find((candidate) => candidate.textContent === name || candidate.getAttribute('aria-label') === name);

    if (found === undefined) {
        throw new Error(`No button named "${name}" among: ${buttons().map((candidate) => candidate.getAttribute('aria-label') ?? candidate.textContent).join(', ')}`);
    }

    return found;
}

function first(selector: string): HTMLElement {
    const found = container.querySelector<HTMLElement>(selector);

    if (found === null) {
        throw new Error(`Nothing matches ${selector}.`);
    }

    return found;
}

async function press(el: HTMLElement): Promise<void> {
    await act(() => {
        el.focus();
        el.click();
    });
    await settle();
}

function frameAttribute(name: string): string | null {
    return first('.kr-booking__frame').getAttribute(name);
}

// The shell.

function bookOptions(overrides: Partial<MountOptions> = {}): MountOptions {
    return { mode: 'book', serviceId: null, staffId: null, uuid: null, token: null, channel: null, ...overrides };
}

const SHELL_API: Api = { origin: ORIGIN, fetch: () => new Promise<Response>(() => undefined) };

function Bomb(): ReactNode {
    throw new Error('boom');
}

async function shellLoading(): Promise<void> {
    await render(<App source={{ kind: 'pending', promise: new Promise<BootConfig>(() => undefined) }} options={bookOptions()} api={SHELL_API} lines={KEYED_LINES} />);

    expect(container.textContent).toContain(said(LOADING_SENTENCE));
}

async function shellUnavailable(): Promise<void> {
    const promise = Promise.reject(new Error('The config did not arrive.'));

    promise.catch(() => undefined);

    await render(<App source={{ kind: 'pending', promise }} options={bookOptions()} api={SHELL_API} lines={KEYED_LINES} />);

    expect(container.textContent).toContain(said(UNAVAILABLE_SENTENCE));
}

async function shellCrashed(): Promise<void> {
    await render(
        <ErrorBoundary>
            <Bomb />
        </ErrorBoundary>,
    );

    expect(container.textContent).toContain(said(CRASHED_SENTENCE));
}

// The booking flow.

// 07:32 UTC on Monday 2026-04-06 is 10:32 in Athens, so the tenant's today is the 6th.
const NOW = Date.UTC(2026, 3, 6, 7, 32);

// The flow's browser clock, moved past a hold's deadline by the screen that runs it out.
let clock = NOW;

const HAIRCUT = { id: 1, name: 'Haircut', description: '', duration_min: 45, price_minor: 3500, currency: 'EUR', staff: [{ id: 2, name: 'Elena' }, { id: 3, name: 'Nikos' }] };
const SPA = { id: 5, name: 'Head spa', description: '', duration_min: 30, price_minor: 5000, currency: 'EUR', staff: [] };
const CATALOG = { services: [HAIRCUT, SPA], max_chain_length: 5 };

const TIMES = {
    '2026-04-06': ['2026-04-06T07:35:00+00:00', '2026-04-06T07:40:00+00:00'],
    '2026-04-07': [],
    '2026-04-08': ['2026-04-08T06:00:00+00:00'],
};

const HELD = {
    uuid: '7b7f0e5a-2222-4000-8000-00000000000a',
    status: 'pending',
    hold_class: 'checkout',
    hold_expires_at: '2026-04-06T08:15:00+00:00',
    customer_name: 'Casey Customer',
    customer_email: 'casey@example.com',
    customer_phone: null,
    total_minor: 5000,
    currency: 'EUR',
    items: [{ sort: 0, service_id: 5, service_name: 'Head spa', staff_id: 9, staff_name: 'Aiko', start: '2026-04-06T07:40:00+00:00', end: '2026-04-06T08:10:00+00:00', price_minor: 5000 }],
    manage_token: 'f'.repeat(64),
};

const CONFIRMED = { ...HELD, status: 'confirmed', hold_class: null, hold_expires_at: null, manage_token: undefined };

const GUEST_PENDING = { ...HELD, manage_token: undefined };

const LIMITED = { code: 'rate_limited', message: 'rate_limited', data: { status: 429, detail: 'Too many requests. Please wait a moment.' } };

const CONFLICT = { code: 'overlap', message: 'overlap', data: { status: 409, detail: 'That time was just taken. Please pick another.', segment: 0 } };

const REFUSAL = { code: 'invalid_request', message: 'invalid_request', data: { status: 400, detail: 'That email address does not look like an email address.' } };

const NOT_CONFIRMABLE = { code: 'not_confirmable', message: 'not_confirmable', data: { status: 409, detail: 'This booking can no longer be confirmed.' } };

interface FlowAnswers {
    catalog: Answer;
    availability: Answer;
    hold: Answer;
    confirm: Answer;
    read: Answer;
}

let flow: FlowAnswers;

beforeEach(() => {
    clock = NOW;
    flow = { catalog: reply(CATALOG), availability: reply(TIMES), hold: reply(HELD, 201), confirm: reply(CONFIRMED), read: reply(GUEST_PENDING) };
});

const flowFetch: Fetch = (input, init) => {
    const path = input.replace(ORIGIN, '');

    if (init.method === 'DELETE') {
        return Promise.resolve(new Response(null, { status: 204 }));
    }

    if (path === HOLDS_PATH) {
        return flow.hold();
    }

    if (path === confirmPath(HELD.uuid)) {
        return flow.confirm();
    }

    if (path.startsWith(`${BOOKINGS_PATH}/`)) {
        return flow.read();
    }

    if (path.startsWith(AVAILABILITY_PATH)) {
        return flow.availability();
    }

    return flow.catalog();
};

/**
 * A query client with the backoff between retries taken out, so a failure that is retried
 * settles within one settle(); waiting the backoff out proves nothing here.
 */
function quickClient(): QueryClient {
    client = createQueryClient();
    client.setDefaultOptions({ queries: { ...client.getDefaultOptions().queries, retryDelay: 0 } });

    return client;
}

/** Refetches every query on screen, each answering whatever its answer now is. */
async function refetchAll(): Promise<void> {
    await act(() => client.invalidateQueries());
    await settle();
}

async function mountFlow(mountOptions: MountOptions = bookOptions()): Promise<void> {
    await render(
        <QueryClientProvider client={quickClient()}>
            <Flow config={CONFIG} options={mountOptions} api={{ origin: ORIGIN, fetch: flowFetch }} browser={{ now: () => clock, navigate: () => undefined }} />
        </QueryClientProvider>,
    );
}

function step(): string | null {
    return frameAttribute('data-kr-step');
}

function serviceRow(name: string): HTMLElement {
    const found = Array.from(container.querySelectorAll<HTMLElement>('.kr-booking__service')).find((row) => row.querySelector('.kr-booking__service-name')?.textContent === name);

    if (found === undefined) {
        throw new Error(`No service row named "${name}".`);
    }

    return found;
}

async function typeInto(name: string, value: string): Promise<void> {
    const input = first(`input[name="${name}"]`);

    await act(() => {
        Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')?.set?.call(input, value);
        input.dispatchEvent(new Event('input', { bubbles: true }));
    });
}

/**
 * A chain of one service that lists nobody, the 6th, its second time, and the customer typed in.
 */
async function reachDetails(): Promise<void> {
    await mountFlow(bookOptions({ serviceId: 5 }));
    await press(first('.kr-booking__day'));
    await press(container.querySelectorAll<HTMLElement>('.kr-booking__slot')[1] ?? first('.kr-booking__slot'));
    await typeInto('customer_name', 'Casey Customer');
    await typeInto('customer_email', 'casey@example.com');

    expect(step()).toBe('details');
}

async function reachReview(): Promise<void> {
    await reachDetails();
    await press(button(said(CONTINUE_LABEL)));

    expect(step()).toBe('review');
}

async function serviceLoading(): Promise<void> {
    flow.catalog = NEVER;
    await mountFlow();

    expect(first('.kr-booking__picker').getAttribute('aria-busy')).toBe('true');
}

async function serviceRefused(): Promise<void> {
    flow.catalog = reply(LIMITED, 429);
    await mountFlow();

    expect(container.textContent).toContain(LIMITED.data.detail);
    expect(container.querySelector('.kr-booking__service')).toBeNull();
}

async function serviceDropped(): Promise<void> {
    flow.catalog = DROPPED;
    await mountFlow();

    expect(container.querySelector('[role="alert"]')).not.toBeNull();
    expect(container.querySelector('.kr-booking__service')).toBeNull();
}

async function serviceEmpty(): Promise<void> {
    flow.catalog = reply({ services: [], max_chain_length: 5 });
    await mountFlow();

    expect(container.querySelector('.kr-booking__service')).toBeNull();
    expect(step()).toBe('service');
}

async function serviceList(): Promise<void> {
    await mountFlow();

    expect(container.querySelectorAll('.kr-booking__service')).toHaveLength(2);
}

async function serviceRemoval(): Promise<void> {
    await mountFlow();
    await press(serviceRow('Haircut'));
    await press(serviceRow('Head spa'));
    await press(first('.kr-booking__remove'));

    expect(container.textContent).toContain(said(REMOVED_SENTENCE, { service: 'Haircut' }));
    expect(container.querySelectorAll('.kr-booking__remove')).toHaveLength(1);
}

async function staff(): Promise<void> {
    await mountFlow();
    await press(serviceRow('Haircut'));
    await press(button(said(CONTINUE_LABEL)));

    expect(step()).toBe('staff');
}

async function whenLoading(): Promise<void> {
    flow.availability = NEVER;
    await mountFlow(bookOptions({ serviceId: 5 }));

    expect(step()).toBe('when');
    expect(container.querySelector('.kr-booking__day')).toBeNull();
}

async function whenReady(): Promise<void> {
    await mountFlow(bookOptions({ serviceId: 5 }));
    await press(first('.kr-booking__day'));

    expect(step()).toBe('when');
    expect(container.querySelectorAll('.kr-booking__slot')).toHaveLength(2);
}

/** The 6th with an afternoon beside its morning, 12:00 UTC being 15:00 in Athens, the later period pressed. */
async function whenPeriod(): Promise<void> {
    flow.availability = reply({ ...TIMES, '2026-04-06': [...TIMES['2026-04-06'], '2026-04-06T12:00:00+00:00'] });
    await mountFlow(bookOptions({ serviceId: 5 }));
    await press(first('.kr-booking__period[aria-pressed="false"]'));

    expect(step()).toBe('when');
    expect(first('.kr-booking__period[aria-pressed="true"]').textContent).toBe(`${said(PERIOD_NAMES.Afternoon)} ${saidBy(TIMES_COUNT, 1)}`);
    expect(container.querySelectorAll('.kr-booking__slot')).toHaveLength(1);
}

async function whenConflict(): Promise<void> {
    await reachDetails();
    flow.hold = reply(CONFLICT, 409);
    await press(button(said(CONTINUE_LABEL)));

    expect(step()).toBe('when');
    expect(container.textContent).toContain(CONFLICT.data.detail);
}

async function detailsIdle(): Promise<void> {
    await reachDetails();

    expect(container.querySelector('form')).not.toBeNull();
}

async function detailsHolding(): Promise<void> {
    await reachDetails();
    flow.hold = NEVER;
    await press(button(said(CONTINUE_LABEL)));

    expect(step()).toBe('details');
    expect(button(said(BUSY_LABEL)).disabled).toBe(true);
}

async function detailsRefused(): Promise<void> {
    await reachDetails();
    flow.hold = reply(REFUSAL, 400);
    await press(button(said(CONTINUE_LABEL)));

    expect(step()).toBe('details');
    expect(container.textContent).toContain(REFUSAL.data.detail);
}

async function detailsDropped(): Promise<void> {
    await reachDetails();
    flow.hold = DROPPED;
    await press(button(said(CONTINUE_LABEL)));

    expect(step()).toBe('details');
    expect(container.querySelector('[role="alert"]')).not.toBeNull();
}

async function reviewCountdown(): Promise<void> {
    await reachReview();

    expect(container.querySelector('.kr-booking__countdown')).not.toBeNull();
}

async function reviewRefused(): Promise<void> {
    await reachReview();
    flow.confirm = reply(NOT_CONFIRMABLE, 409);
    await press(first('.kr-booking__confirm'));

    expect(step()).toBe('review');
    expect(container.textContent).toContain(NOT_CONFIRMABLE.data.detail);
}

async function done(): Promise<void> {
    await reachReview();
    await press(first('.kr-booking__confirm'));

    expect(step()).toBe('done');
}

// The manage view.

const UUID = '7b7f0e5a-4444-4000-8000-00000000000c';

const TOKEN = 'ab12'.repeat(16);

const MOUNTED_AT = Date.UTC(2026, 3, 6, 7, 0);

const BOOKING = {
    uuid: UUID,
    status: 'confirmed',
    hold_class: null,
    hold_expires_at: null,
    customer_name: 'Casey Customer',
    customer_email: 'casey@example.com',
    customer_phone: null,
    total_minor: 3500,
    currency: 'EUR',
    items: [{ sort: 0, service_id: 1, service_name: 'Haircut', staff_id: 7, staff_name: 'Elena', start: '2026-04-07T07:30:00+00:00', end: '2026-04-07T08:15:00+00:00', price_minor: 3500 }],
};

const NEW_START = '2026-04-07T09:00:00+00:00';

const MOVE_TIMES = { '2026-04-06': [], '2026-04-07': [NEW_START, '2026-04-07T09:30:00+00:00'], '2026-04-08': [] };

const BROKEN = { code: 'server_error', message: 'server_error', data: { status: 500, detail: 'Something went wrong at our end.' } };

const WINDOW_CLOSED = { code: 'window_closed', message: 'window_closed', data: { status: 403, detail: 'It is too late to cancel this booking online.' } };

const CANCELLED = { ...BOOKING, status: 'cancelled' };

const UNAVAILABLE = { code: 'unavailable', message: 'unavailable', data: { status: 409, detail: 'That time is no longer available. Please pick another.' } };

interface ManageAnswers {
    read: Answer;
    cancel: Answer;
    moveRead: Answer;
    move: Answer;
}

let manage: ManageAnswers;

beforeEach(() => {
    manage = { read: reply(BOOKING), cancel: reply(CANCELLED), moveRead: reply(MOVE_TIMES), move: reply(BOOKING) };
});

const manageFetch: Fetch = (input) => {
    const path = input.replace(ORIGIN, '');

    if (path.startsWith(`${BOOKINGS_PATH}/${UUID}/availability`)) {
        return manage.moveRead();
    }

    if (path === reschedulePath(UUID)) {
        return manage.move();
    }

    if (path === cancelPath(UUID)) {
        return manage.cancel();
    }

    return manage.read();
};

async function mountManage(overrides: Partial<MountOptions> = {}, notice: string | null = null): Promise<void> {
    await render(
        <QueryClientProvider client={quickClient()}>
            <ManageView config={CONFIG} options={{ mode: 'manage', serviceId: null, staffId: null, uuid: UUID, token: TOKEN, channel: null, ...overrides }} api={{ origin: ORIGIN, fetch: manageFetch }} now={() => MOUNTED_AT} notice={notice} />
        </QueryClientProvider>,
    );
}

/** The sentence the pay hop's redirect flashed onto the manage page. */
const MANAGE_FLASHED = 'Your payment is on its way to us.';

function view(): string | null {
    return frameAttribute('data-kr-view');
}

function dialog(): HTMLElement {
    return first('[role="dialog"]');
}

async function toTheSeventh(): Promise<void> {
    await mountManage();
    await press(button(said(RESCHEDULE_LABEL)));

    const day = dialog().querySelector<HTMLElement>('.kr-booking__day[value="2026-04-07"]');

    if (day === null) {
        throw new Error('The dialog offers no 7th.');
    }

    await press(day);
}

function newStart(): HTMLElement {
    const slot = dialog().querySelector<HTMLElement>(`.kr-booking__slot[value="${NEW_START}"]`);

    if (slot === null) {
        throw new Error('The dialog offers no new start.');
    }

    return slot;
}

async function manageNeutral(): Promise<void> {
    await mountManage({ token: null, channel: null });

    expect(view()).toBe('neutral');
    expect(container.textContent).toContain(said(NEUTRAL_SENTENCE));
}

async function manageLoading(): Promise<void> {
    manage.read = NEVER;
    await mountManage();

    expect(view()).toBe('loading');
    expect(container.textContent).toContain(said(LOADING_BOOKING_SENTENCE));
}

async function manageRetry(): Promise<void> {
    manage.read = reply(BROKEN, 500);
    await mountManage();

    expect(view()).toBe('retry');
}

async function manageReady(): Promise<void> {
    await mountManage();

    expect(view()).toBe('ready');
}

async function manageFlashed(): Promise<void> {
    await mountManage({}, MANAGE_FLASHED);

    expect(view()).toBe('ready');
}

async function manageArmed(): Promise<void> {
    await mountManage();
    await press(button(said(CANCEL_LABEL)));

    expect(container.textContent).toContain(said(CONFIRM_QUESTION));
}

async function manageCancelling(): Promise<void> {
    await mountManage();
    await press(button(said(CANCEL_LABEL)));
    manage.cancel = NEVER;
    await press(button(said(YES_LABEL)));

    expect(container.textContent).toContain(said(CANCELLING_SENTENCE));
}

async function manageDialog(): Promise<void> {
    await mountManage();
    await press(button(said(RESCHEDULE_LABEL)));

    expect(dialog().querySelectorAll('.kr-booking__day').length).toBeGreaterThan(0);
}

/** The dialog over a 7th with a morning before its two afternoon times, 06:00 UTC being 09:00 in Athens, the later period pressed. */
async function manageDialogPeriod(): Promise<void> {
    manage.moveRead = reply({ ...MOVE_TIMES, '2026-04-07': ['2026-04-07T06:00:00+00:00', ...MOVE_TIMES['2026-04-07']] });
    await mountManage();
    await press(button(said(RESCHEDULE_LABEL)));
    await press(first('.kr-booking__period[aria-pressed="false"]'));

    expect(first('.kr-booking__period[aria-pressed="true"]').textContent).toBe(`${said(PERIOD_NAMES.Afternoon)} ${saidBy(TIMES_COUNT, 2)}`);
    expect(dialog().querySelectorAll('.kr-booking__slot')).toHaveLength(2);
}

async function manageMoving(): Promise<void> {
    await toTheSeventh();
    manage.move = NEVER;
    await press(newStart());

    expect(dialog().textContent).toContain(said(MOVING_SENTENCE));
}

async function manageMoveRefused(): Promise<void> {
    await toTheSeventh();
    manage.move = reply(UNAVAILABLE, 409);
    await press(newStart());

    expect(dialog().textContent).toContain(UNAVAILABLE.data.detail);
}

// The lines a failed refetch leaves over data, the flow's staff step before its catalog, the review
// step handed off and run out, and the manage view after each verdict and while Try again flies.

async function serviceStale(): Promise<void> {
    await mountFlow();
    flow.catalog = DROPPED;
    await refetchAll();

    expect(container.textContent).toContain(said(STALE_SENTENCE));
    expect(container.querySelectorAll('.kr-booking__service')).toHaveLength(2);
}

async function staffLoading(): Promise<void> {
    flow.catalog = NEVER;
    await mountFlow(bookOptions({ serviceId: 1 }));

    expect(first('.kr-booking__step').getAttribute('aria-busy')).toBe('true');
}

async function staffRefused(): Promise<void> {
    flow.catalog = reply(LIMITED, 429);
    await mountFlow(bookOptions({ serviceId: 1 }));

    expect(container.textContent).toContain(LIMITED.data.detail);
}

async function whenStale(): Promise<void> {
    await whenReady();
    flow.availability = DROPPED;
    await refetchAll();

    expect(container.textContent).toContain(said(STALE_TIMES_SENTENCE));
    expect(container.querySelectorAll('.kr-booking__slot')).toHaveLength(2);
}

async function reviewHandedOff(): Promise<void> {
    await reachDetails();
    flow.hold = reply({ ...HELD, checkout_url: 'https://pay.example.com/session/abc' }, 201);
    await press(button(said(CONTINUE_LABEL)));
    await press(button(said(PAYMENT_LABEL)));

    expect(button(said(PAYMENT_LABEL)).disabled).toBe(true);
    expect(container.textContent).toContain(said(HANDED_OFF_SENTENCE));
}

async function reviewRunOut(): Promise<void> {
    await reachReview();
    clock = Date.parse(HELD.hold_expires_at) + 1;
    // The countdown's next tick is at most a second away and reads the moved clock.
    await act(() => new Promise<void>((resolve) => setTimeout(resolve, 1_100)));
    await settle();

    expect(container.textContent).toContain(said(EXPIRED_MILESTONE));
}

async function manageTrying(): Promise<void> {
    await manageRetry();
    manage.read = NEVER;
    await press(button(said(TRY_AGAIN_LABEL)));

    expect(button(said(TRYING_LABEL)).disabled).toBe(true);
}

async function manageCancelRefused(): Promise<void> {
    await manageArmed();
    manage.cancel = reply(WINDOW_CLOSED, 403);
    await press(button(said(YES_LABEL)));

    expect(container.textContent).toContain(WINDOW_CLOSED.data.detail);
    expect(container.textContent).toContain(said(CONFIRM_QUESTION));
}

async function manageCancelled(): Promise<void> {
    await manageArmed();
    manage.read = reply(CANCELLED);
    await press(button(said(YES_LABEL)));

    expect(container.textContent).toContain(CANCELLED_STATUS);
    expect(container.textContent).not.toContain(said(CONFIRM_QUESTION));
}

async function manageMoved(): Promise<void> {
    await toTheSeventh();
    await press(newStart());

    expect(container.querySelector('[role="dialog"]')).toBeNull();
    expect(container.textContent).toContain(said(MOVED_SENTENCE));
}

async function manageDialogStale(): Promise<void> {
    await toTheSeventh();
    manage.moveRead = DROPPED;
    await refetchAll();

    expect(dialog().textContent).toContain(said(STALE_TIMES_SENTENCE));
}

type Reach = () => Promise<void>;

// My bookings, the list mode of 6.6, on the shell as the boot mounts it on the hosted page.

const MINE_FLASHED = 'If we have bookings for this address, a link is on its way.';

const MINE_BOOKING = parseBooking(CONFIRMED);

async function mountMine(page: Omit<MyBookingsPage, 'csrf'>): Promise<void> {
    await render(<App source={{ kind: 'ready', config: CONFIG }} options={bookOptions({ mode: 'list' })} api={SHELL_API} lines={KEYED_LINES} myBookings={{ ...page, csrf: 'csrf' }} />);
}

async function mineForm(): Promise<void> {
    await mountMine({ bookings: null, notice: null });
}

async function mineFormFlashed(): Promise<void> {
    await mountMine({ bookings: null, notice: MINE_FLASHED });
}

async function mineSending(): Promise<void> {
    await mountMine({ bookings: null, notice: null });
    await act(() => {
        first('form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    });

    expect(container.textContent).toContain(said(SENDING_LABEL));
}

async function mineList(): Promise<void> {
    await mountMine({ bookings: { upcoming: MINE_BOOKING === null ? [] : [MINE_BOOKING], past: [] }, notice: null });
}

async function mineListFlashed(): Promise<void> {
    await mountMine({ bookings: { upcoming: [], past: MINE_BOOKING === null ? [] : [MINE_BOOKING] }, notice: 'You have signed out.' });
}

/*
 * The cards' actions: the list mounted with the manage page's booking on one card, its
 * requests under the list mode's credentials, answered by the manage screens' answers and a
 * list read again after an action.
 */

const MINE_API: Api = {
    origin: ORIGIN,
    credentials: 'include',
    fetch: (input) => (input.replace(ORIGIN, '') === MY_BOOKINGS_PATH ? reply({ bookings: { upcoming: [CANCELLED], past: [] }, notice: null, csrf: 'csrf' })() : manageFetch(input, {})),
};

async function mountMineCard(): Promise<void> {
    const listed = parseBooking(BOOKING);

    await render(
        <QueryClientProvider client={quickClient()}>
            <MyBookingsView config={CONFIG} options={bookOptions({ mode: 'list' })} page={{ bookings: { upcoming: listed === null ? [] : [listed], past: [] }, notice: null, csrf: 'csrf' }} api={MINE_API} now={() => MOUNTED_AT} />
        </QueryClientProvider>,
    );
}

async function mineArmed(): Promise<void> {
    await mountMineCard();
    await press(button(said(CANCEL_LABEL)));

    expect(container.textContent).toContain(said(CONFIRM_QUESTION));
}

async function mineCancelling(): Promise<void> {
    manage.cancel = NEVER;
    await mineArmed();
    await press(button(said(YES_LABEL)));

    expect(container.textContent).toContain(said(CANCELLING_SENTENCE));
}

async function mineCancelRefused(): Promise<void> {
    manage.cancel = reply(WINDOW_CLOSED, 403);
    await mineArmed();
    await press(button(said(YES_LABEL)));

    expect(container.textContent).toContain(WINDOW_CLOSED.data.detail);
}

async function mineCancelled(): Promise<void> {
    await mineArmed();
    await press(button(said(YES_LABEL)));

    expect(container.textContent).toContain(said(CANCELLED_SENTENCE));
    expect(container.textContent).toContain(CANCELLED_STATUS);
}

async function mineDialog(): Promise<void> {
    await mountMineCard();
    await press(button(said(RESCHEDULE_LABEL)));

    const day = dialog().querySelector<HTMLElement>('.kr-booking__day[value="2026-04-07"]');

    if (day === null) {
        throw new Error('The dialog offers no 7th.');
    }

    await press(day);
}

async function mineMoving(): Promise<void> {
    manage.move = NEVER;
    await mineDialog();
    await press(newStart());

    expect(container.textContent).toContain(said(MOVING_SENTENCE));
}

async function mineMoved(): Promise<void> {
    await mineDialog();
    await press(newStart());

    expect(container.querySelector('[role="dialog"]')).toBeNull();
    expect(container.textContent).toContain(said(MOVED_SENTENCE));
}

/*
 * Every screen the walk visits, grouped by the app's own names for where it is: the shell's three
 * states, the flow's steps and the manage view's four views. The groups are typed over Step and
 * ManageViewName, so a step or a view added to the app fails the type check here until it has
 * screens, and every screen is checked to be on the step or the view its group names. Within a
 * group, REGION_SITES below is what forces a new state to be named: a region added anywhere in the
 * source fails until a screen here makes it speak.
 */
const SHELL_SCREENS: Readonly<Record<string, Reach>> = {
    loading: shellLoading,
    unavailable: shellUnavailable,
    crashed: shellCrashed,
};

const FLOW_SCREENS: Readonly<Record<Step, Readonly<Record<string, Reach>>>> = {
    service: {
        'the catalog loading': serviceLoading,
        'a catalog refusal and no data': serviceRefused,
        'a catalog transport failure and no data': serviceDropped,
        'an empty catalog': serviceEmpty,
        'the list': serviceList,
        'the list under a failed refetch': serviceStale,
        'the cards after a removal announced': serviceRemoval,
    },
    staff: {
        'the catalog loading': staffLoading,
        'a catalog refusal and no data': staffRefused,
        'the choices': staff,
    },
    when: {
        loading: whenLoading,
        'with data': whenReady,
        'with a later period chosen': whenPeriod,
        'with data under a failed refetch': whenStale,
        'after a hold 409': whenConflict,
    },
    details: {
        idle: detailsIdle,
        'with the hold in flight': detailsHolding,
        'after a 400 refusal': detailsRefused,
        'after a transport failure': detailsDropped,
    },
    review: {
        'with a countdown': reviewCountdown,
        'after the countdown ran out': reviewRunOut,
        'after a refused confirm': reviewRefused,
        'handed off to payment': reviewHandedOff,
    },
    done: {
        confirmed: done,
    },
};

const MANAGE_SCREENS: Readonly<Record<ManageViewName, Readonly<Record<string, Reach>>>> = {
    neutral: {
        'a missing token': manageNeutral,
    },
    loading: {
        'the read in flight': manageLoading,
    },
    retry: {
        'a 5xx': manageRetry,
        'Try again in flight': manageTrying,
    },
    ready: {
        'the booking': manageReady,
        'the booking with a sentence flashed': manageFlashed,
        'the cancel row armed': manageArmed,
        'a cancel in flight': manageCancelling,
        'after a refused cancel': manageCancelRefused,
        'after a cancel succeeded': manageCancelled,
        'the reschedule dialog open': manageDialog,
        'the dialog with a later period chosen': manageDialogPeriod,
        'the dialog under a failed refetch': manageDialogStale,
        'a move in flight': manageMoving,
        'after a refused move': manageMoveRefused,
        'after a move succeeded': manageMoved,
    },
};

const MINE_SCREENS: Readonly<Record<MyBookingsViewName, Readonly<Record<string, Reach>>>> = {
    form: {
        'the form': mineForm,
        'the form with a sentence flashed': mineFormFlashed,
        'the form sending': mineSending,
    },
    list: {
        'the bookings': mineList,
        'the bookings with a sentence flashed': mineListFlashed,
        'a card\'s cancel row armed': mineArmed,
        'a card\'s cancel in flight': mineCancelling,
        'after a card\'s refused cancel': mineCancelRefused,
        'after a card\'s cancel succeeded': mineCancelled,
        'a card\'s reschedule dialog open': mineDialog,
        'a card\'s move in flight': mineMoving,
        'after a card\'s move succeeded': mineMoved,
    },
};

interface Screen {
    readonly name: string;
    readonly reach: Reach;
    /** The frame attribute and the value it holds on this screen; the shell has no frame. */
    readonly on: readonly ['data-kr-step' | 'data-kr-view', string] | null;
}

function screensOf(surface: string, attribute: 'data-kr-step' | 'data-kr-view', groups: Readonly<Record<string, Readonly<Record<string, Reach>>>>): Screen[] {
    return Object.entries(groups).flatMap(([where, screens]) => Object.entries(screens).map(([state, reach]) => ({ name: `${surface} ${where}: ${state}`, reach, on: [attribute, where] as const })));
}

const SCREENS: readonly Screen[] = [
    ...Object.entries(SHELL_SCREENS).map(([state, reach]) => ({ name: `shell: ${state}`, reach, on: null })),
    ...screensOf('flow', 'data-kr-step', FLOW_SCREENS),
    ...screensOf('manage', 'data-kr-view', MANAGE_SCREENS),
    ...screensOf('list', 'data-kr-view', MINE_SCREENS),
];

// The neutral panel says its one sentence as static text and mounts no region at all, so it is
// the one screen the walk finds nothing on.
const WITHOUT_REGIONS = ['manage neutral: a missing token'];

/*
 * Every region call site of the source, by file, named with a screen above on which it speaks
 * and the words it says there, distinct enough to name the site. The source audit counts the
 * call sites per file, so a region added anywhere in the app fails until it is named here with a
 * screen the walk visits.
 */
const REGION_SITES: readonly { readonly file: string; readonly screen: string; readonly says: string }[] = [
    { file: 'App.tsx', screen: 'shell: loading', says: said(LOADING_SENTENCE) },
    { file: 'App.tsx', screen: 'shell: unavailable', says: said(UNAVAILABLE_SENTENCE) },
    { file: 'App.tsx', screen: 'shell: crashed', says: said(CRASHED_SENTENCE) },
    { file: 'ServiceStep.tsx', screen: 'flow service: the cards after a removal announced', says: said(REMOVED_SENTENCE, { service: 'Haircut' }) },
    { file: 'ServiceStep.tsx', screen: 'flow service: the list under a failed refetch', says: said(STALE_SENTENCE) },
    { file: 'StaffStep.tsx', screen: 'flow staff: a catalog refusal and no data', says: LIMITED.data.detail },
    { file: 'WhenStep.tsx', screen: 'flow when: after a hold 409', says: CONFLICT.data.detail },
    { file: 'WhenStep.tsx', screen: 'flow when: with data under a failed refetch', says: said(STALE_TIMES_SENTENCE) },
    { file: 'DetailsStep.tsx', screen: 'flow details: after a 400 refusal', says: REFUSAL.data.detail },
    { file: 'ReviewStep.tsx', screen: 'flow review: handed off to payment', says: said(HANDED_OFF_SENTENCE) },
    { file: 'Countdown.tsx', screen: 'flow review: after the countdown ran out', says: said(EXPIRED_MILESTONE) },
    { file: 'DoneStep.tsx', screen: 'flow done: confirmed', says: said(sentenceFor('confirmed')) },
    { file: 'ManageView.tsx', screen: 'manage ready: the booking with a sentence flashed', says: MANAGE_FLASHED },
    { file: 'ManageView.tsx', screen: 'manage loading: the read in flight', says: said(LOADING_BOOKING_SENTENCE) },
    { file: 'ManageView.tsx', screen: 'manage retry: a 5xx', says: BROKEN.data.detail },
    { file: 'ManageView.tsx', screen: 'manage ready: after a cancel succeeded', says: CANCELLED_STATUS },
    { file: 'ManageView.tsx', screen: 'manage ready: after a move succeeded', says: said(MOVED_SENTENCE) },
    { file: 'RescheduleDialog.tsx', screen: 'manage ready: a move in flight', says: said(MOVING_SENTENCE) },
    { file: 'RescheduleDialog.tsx', screen: 'manage ready: the dialog under a failed refetch', says: said(STALE_TIMES_SENTENCE) },
    { file: 'MyBookingsView.tsx', screen: 'list form: the form with a sentence flashed', says: MINE_FLASHED },
];

describe('every screen', () => {
    it.each(SCREENS)('renders only leaf notice paragraphs outside any form on $name', async ({ name, reach, on }) => {
        await reach();

        if (on !== null) {
            expect(frameAttribute(on[0])).toBe(on[1]);
        }

        const regions = Array.from(container.querySelectorAll(LIVE_SELECTOR));

        expect(regions.length > 0).toBe(!WITHOUT_REGIONS.includes(name));
        expect(walk(container)).toEqual([]);

        for (const site of REGION_SITES.filter((candidate) => candidate.screen === name)) {
            expect(regions.map((region) => region.textContent), site.file).toContain(site.says);
        }
    });

    it('names a walked screen for every region site, and every site of the source is named', () => {
        const names = SCREENS.map((screen) => screen.name);
        const counted = Object.fromEntries(bookingSources().map(({ file, text }) => [file, (text.match(/<NoticeRegion\b/g) ?? []).length] as const).filter(([, count]) => count > 0));
        const named: Record<string, number> = {};

        for (const site of REGION_SITES) {
            named[site.file] = (named[site.file] ?? 0) + 1;
        }

        expect(new Set(names).size).toBe(names.length);
        expect(REGION_SITES.filter((site) => !names.includes(site.screen))).toEqual([]);
        expect(named).toEqual(counted);
    });
});

// The source audit.

const LIVE_MARKUP = /role=\{?\s*["'](?:status|alert|log|marquee|timer)["']|aria-live/;

describe('the source', () => {
    it('spells live-region markup in the region component and in no other file of the app', () => {
        const sources = bookingSources();
        const spelling = sources.filter(({ text }) => LIVE_MARKUP.test(text)).map(({ file }) => file);

        expect(sources.map(({ file }) => file)).toContain('App.tsx');
        expect(spelling).toEqual(['NoticeRegion.tsx']);
    });

    it('matches every spelling the audit is there to catch', () => {
        for (const spelled of ['role="status"', "role='alert'", 'role={"log"}', "role={'timer'}", 'role="marquee"', 'aria-live="polite"']) {
            expect(LIVE_MARKUP.test(spelled), spelled).toBe(true);
        }

        expect(LIVE_MARKUP.test('role="dialog"')).toBe(false);
    });
});
