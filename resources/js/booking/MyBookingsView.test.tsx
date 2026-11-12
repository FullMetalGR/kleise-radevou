import { QueryClientProvider } from '@tanstack/react-query';
import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { englishLeft, ENGLISH_WORDS, GREEK_WORDS, keyed, KEYED_WORDS, said } from '../bookingWords';
import { createQueryClient, type Api } from './api';
import { App } from './App';
import type { MountOptions } from './attributes';
import { parseBooking, type Booking } from './booking';
import { cancelPath } from './cancel';
import type { BootConfig, Fetch } from './config';
import type { Language } from './language';
import { BOOKING_LINES_EN } from './lines.generated';
import { CANCEL_LABEL, CANCELLING_SENTENCE, CONFIRM_QUESTION, KEEP_LABEL, statusSentence, YES_LABEL } from './ManageView';
import { formatMoney } from './money';
import { CLOSE_LABEL, MOVED_SENTENCE, MOVING_SENTENCE, PICK_SENTENCE, RESCHEDULE_LABEL, reschedulePath } from './move';
import { BOOK_AGAIN_LABEL, LINK_PATH, MY_BOOKINGS_PATH, SIGN_OUT_PATH, TOKEN_FIELD, type MyBookingsPage } from './myBookings';
import {
    CANCELLED_SENTENCE,
    EMAIL_LABEL,
    FORM_INTRO,
    MY_BOOKINGS_TITLE,
    MyBookingsView,
    NO_BOOKINGS_SENTENCE,
    PAST_HEADING,
    SEND_LABEL,
    SENDING_LABEL,
    SIGN_OUT_LABEL,
    SIGNING_OUT_LABEL,
    UPCOMING_HEADING,
} from './MyBookingsView';
import { TOTAL_LABEL, WITH_STAFF, ZONE_SENTENCE } from './Summary';
import { formatMoment, formatTime, wallClockOf } from './time';
import type { Words } from './words';

/*
 * My bookings on screen: the form where no session is open and the list where one is, the
 * flashed sentence above either through the one region, the cards with the status sentence of
 * 6.2, the actions of 6.3 and Book again, and the two forms posted by the browser with the
 * page's token, each showing its busy label and sending once.
 *
 * The cards' actions call the manage page's routes with no token and credentials included, the
 * session cookie the browser's to send, and what they show next is the list GET /my-bookings
 * answers as JSON once an action lands. The wire here records every request with its init, and
 * answers each route as the test sets it.
 */

const CONFIG: BootConfig = { tenantName: 'Luna Hair Studio', timezone: 'Europe/Athens', currency: 'EUR', granularityMin: 5, importantInfo: null, defaultLanguage: 'en', language: 'en' };

const ORIGIN = 'https://luna.kleiseradevou.gr';

interface Sent {
    readonly method: string;
    readonly path: string;
    readonly credentials: RequestCredentials | undefined;
    readonly body: string | null;
}

let sent: Sent[];

/** What each route answers, set per test; a request left out is a promise never settled. */
let reread: () => Promise<Response>;
let cancelled: () => Promise<Response>;
let moveRead: () => Promise<Response>;
let moved: () => Promise<Response>;

function answering(body: unknown, status = 200): () => Promise<Response> {
    return () => Promise.resolve(new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }));
}

const never = (): Promise<Response> => new Promise<Response>(() => undefined);

const wire: Fetch = (input, init) => {
    const path = input.replace(ORIGIN, '');

    sent.push({ method: init.method ?? 'GET', path, credentials: init.credentials, body: typeof init.body === 'string' ? init.body : null });

    if (path === MY_BOOKINGS_PATH) {
        return reread();
    }

    if (path.endsWith('/cancel')) {
        return cancelled();
    }

    if (path.endsWith('/reschedule')) {
        return moved();
    }

    if (path.includes('/availability')) {
        return moveRead();
    }

    return never();
};

/** The list mode's api, as the boot builds it on the hosted origin: credentials included. */
const API: Api = { origin: ORIGIN, fetch: wire, credentials: 'include' };

// 06:00 UTC on Monday 2026-11-02 is 08:00 in Athens: the tenant's today, the dialog's first day.
const MOUNTED_AT = Date.UTC(2026, 10, 2, 6, 0);

const OPTIONS: MountOptions = { mode: 'list', serviceId: null, staffId: null, uuid: null, token: null, channel: null };

const CSRF = 'the-forms-own-cross-site-token';

const FLASHED = 'If we have bookings for this address, a link is on its way.';

/** A booking as the wire spells it, the guest view. */
function wired(uuid: string, status: string, start: string, overrides: Record<string, unknown> = {}): Record<string, unknown> {
    return {
        uuid,
        status,
        hold_class: null,
        hold_expires_at: null,
        customer_name: 'Casey Customer',
        customer_email: 'casey@example.com',
        customer_phone: null,
        total_minor: 5000,
        currency: 'JPY',
        items: [{ sort: 0, service_id: 12, service_name: 'Haircut', staff_id: 4, staff_name: 'Elena', start, end: start.replace('08:00', '08:30'), price_minor: 5000 }],
        ...overrides,
    };
}

function parsed(wire: Record<string, unknown>): Booking {
    const read = parseBooking(wire);

    if (read === null) {
        throw new Error('The fixture is no booking.');
    }

    return read;
}

function booking(uuid: string, status: string, start: string, overrides: Record<string, unknown> = {}): Booking {
    return parsed(wired(uuid, status, start, overrides));
}

const LATER_ITEM = { sort: 0, service_id: 13, service_name: 'Colour', staff_id: 1, staff_name: null, start: '2026-11-05T08:00:00+00:00', end: '2026-11-05T09:00:00+00:00', price_minor: 5000 };

const SOONER_WIRE = wired('upcoming-1', 'awaiting_approval', '2026-11-03T08:00:00+00:00');
const LATER_WIRE = wired('upcoming-2', 'confirmed', '2026-11-05T08:00:00+00:00', { items: [LATER_ITEM] });
const RECENT_WIRE = wired('past-1', 'completed', '2026-10-28T08:00:00+00:00');

const SOONER = parsed(SOONER_WIRE);
const LATER = parsed(LATER_WIRE);
const RECENT = parsed(RECENT_WIRE);

/** A start the move read offers: 12:00 UTC on the 3rd, 14:00 in Athens. */
const NEW_START = '2026-11-03T12:00:00+00:00';

/** The list as GET /my-bookings answers it, with the later booking as the test has it. */
function listWire(later: Record<string, unknown>): unknown {
    return { bookings: { upcoming: [SOONER_WIRE, later], past: [RECENT_WIRE] }, notice: null, csrf: CSRF };
}

const LIST: MyBookingsPage = { bookings: { upcoming: [SOONER, LATER], past: [RECENT] }, notice: null, csrf: CSRF };

const FORM: MyBookingsPage = { bookings: null, notice: null, csrf: CSRF };

/** What a card's status line says for a status under the keyed table, empty for one with no sentence. */
function statusSaid(status: string): string {
    const key = statusSentence(status);

    return key === null ? '' : said(key);
}

let container: HTMLDivElement;
let root: Root;

beforeEach(() => {
    container = document.createElement('div');
    document.body.appendChild(container);
    root = createRoot(container);
    sent = [];
    reread = never;
    cancelled = never;
    moveRead = never;
    moved = never;
});

afterEach(async () => {
    await act(() => {
        root.unmount();
    });
    container.remove();
});

async function render(page: MyBookingsPage, api: Api = API, prefix: Language | null = null, words: Words = KEYED_WORDS): Promise<void> {
    const client = createQueryClient();

    // The retry backoff is the client's, and waiting it out proves nothing here.
    client.setDefaultOptions({ queries: { ...client.getDefaultOptions().queries, retryDelay: 0 } });

    await act(() => {
        root.render(
            keyed(
                <QueryClientProvider client={client}>
                    <MyBookingsView config={CONFIG} options={OPTIONS} page={page} api={api} now={() => MOUNTED_AT} prefix={prefix} />
                </QueryClientProvider>,
                words,
            ),
        );
    });
    // The polite region takes its sentence one effect later.
    await settle();
}

/**
 * Lets the query client's deferred notifications and every effect they trigger land.
 */
async function settle(turns = 12): Promise<void> {
    await act(async () => {
        for (let turn = 0; turn < turns; turn += 1) {
            await new Promise<void>((resolve) => setTimeout(resolve, 0));
        }
    });
}

async function press(el: HTMLElement): Promise<void> {
    await act(() => {
        el.focus();
        el.click();
    });
    await settle();
}

function cards(): HTMLElement[] {
    return Array.from(container.querySelectorAll<HTMLElement>('.kr-booking__card'));
}

function card(at: number): HTMLElement {
    const found = cards()[at];

    if (found === undefined) {
        throw new Error(`No card at ${at}.`);
    }

    return found;
}

/** The buttons a card renders, by their words. */
function labels(within: HTMLElement): string[] {
    return Array.from(within.querySelectorAll('button')).map((control) => control.textContent ?? '');
}

function buttonIn(within: HTMLElement, name: string): HTMLButtonElement {
    const found = Array.from(within.querySelectorAll('button')).find((candidate) => candidate.textContent === name);

    if (found === undefined) {
        throw new Error(`No button named "${name}" among: ${labels(within).join(', ')}`);
    }

    return found;
}

function dayIn(within: HTMLElement, date: string): HTMLButtonElement {
    const found = within.querySelector<HTMLButtonElement>(`[role="dialog"] .kr-booking__day[value="${date}"]`);

    if (found === null) {
        throw new Error(`The dialog offers no day ${date}.`);
    }

    return found;
}

function slotIn(within: HTMLElement, start: string): HTMLButtonElement {
    const found = within.querySelector<HTMLButtonElement>(`[role="dialog"] .kr-booking__slot[value="${start}"]`);

    if (found === null) {
        throw new Error(`The dialog offers no slot at ${start}.`);
    }

    return found;
}

function polite(within: HTMLElement): string {
    return Array.from(within.querySelectorAll('[role="status"]')).map((region) => region.textContent ?? '').join(' ');
}

function frame(): HTMLElement {
    const found = container.querySelector<HTMLElement>('.kr-booking__frame');

    if (found === null) {
        throw new Error('Nothing rendered a frame.');
    }

    return found;
}

function forms(): HTMLFormElement[] {
    return Array.from(container.querySelectorAll('form'));
}

/**
 * A submit as the browser dispatches one before it carries the form away, answering whether the
 * app let it go.
 */
async function submit(form: HTMLFormElement | undefined): Promise<boolean> {
    if (form === undefined) {
        throw new Error('No form to submit.');
    }

    let carried = false;

    await act(() => {
        carried = form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    });

    return carried;
}

describe('the form, where no session is open', () => {
    it('asks for an address in a form the browser posts to the tenant\'s origin with the page\'s token, on the form view of the list mode', async () => {
        await render(FORM);

        const [form] = forms();
        const input = form?.querySelector<HTMLInputElement>('input[name="email"]');
        const label = input === null || input === undefined ? null : container.querySelector(`label[for="${input.id}"]`);

        expect(frame().getAttribute('data-kr-mode')).toBe('list');
        expect(frame().getAttribute('data-kr-view')).toBe('form');
        expect(container.querySelector('.kr-booking__title')?.textContent).toBe('Luna Hair Studio');
        expect(container.querySelector('.kr-booking__subtitle')?.textContent).toBe(said(MY_BOOKINGS_TITLE));
        expect(container.textContent).toContain(said(FORM_INTRO));
        expect(forms()).toHaveLength(1);
        expect(form?.getAttribute('method')).toBe('post');
        expect(form?.getAttribute('action')).toBe(ORIGIN + LINK_PATH);
        expect(form?.querySelector<HTMLInputElement>(`input[type="hidden"][name="${TOKEN_FIELD}"]`)?.value).toBe(CSRF);
        expect(input?.type).toBe('email');
        expect(input?.required).toBe(true);
        expect(label?.textContent).toBe(said(EMAIL_LABEL));
        expect(form?.querySelector('button[type="submit"]')?.textContent).toBe(said(SEND_LABEL));
    });

    it('says the sentence a redirect flashed above the form, in the one polite region and outside the form', async () => {
        await render({ ...FORM, notice: FLASHED });

        const region = container.querySelector('[role="status"]');

        expect(region?.textContent).toBe(FLASHED);
        expect(region?.closest('form')).toBeNull();
    });

    it('lets one submit go, shows the busy label, and sends nothing on a second press, without disabling anything', async () => {
        await render(FORM);

        const [form] = forms();

        expect(await submit(form)).toBe(true);
        expect(form?.querySelector('button')?.textContent).toBe(said(SENDING_LABEL));
        expect(form?.getAttribute('aria-busy')).toBe('true');
        expect(await submit(form)).toBe(false);
        expect(container.querySelectorAll('[disabled]')).toHaveLength(0);
    });

    it('starts unlatched again when the browser brings the page back from its history', async () => {
        await render(FORM);

        const [form] = forms();

        await submit(form);
        await act(() => {
            window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true }));
        });

        expect(form?.querySelector('button')?.textContent).toBe(said(SEND_LABEL));
        expect(await submit(form)).toBe(true);
    });
});

describe('the list, where a session is open', () => {
    it('lists the upcoming bookings and then the past ones, as the server ordered them, each a card', async () => {
        await render(LIST);

        const headings = Array.from(container.querySelectorAll('.kr-booking__group > .kr-booking__subtitle')).map((heading) => heading.textContent);
        const cards = Array.from(container.querySelectorAll('.kr-booking__card'));

        expect(frame().getAttribute('data-kr-view')).toBe('list');
        expect(headings).toEqual([said(UPCOMING_HEADING), said(PAST_HEADING)]);
        expect(cards).toHaveLength(3);
        expect(cards.map((card) => card.closest('.kr-booking__group')?.querySelector('.kr-booking__subtitle')?.textContent)).toEqual([said(UPCOMING_HEADING), said(UPCOMING_HEADING), said(PAST_HEADING)]);
        expect(container.querySelector('.kr-booking__mine > .kr-booking__timezone')?.textContent).toBe(said(ZONE_SENTENCE, { zone: 'Europe/Athens' }));
    });

    it('carries on each card the services, the day and time in the tenant\'s clock, the staff where named, the total in the row\'s currency and the status sentence of 6.2', async () => {
        await render(LIST);

        const [sooner, later, recent] = Array.from(container.querySelectorAll<HTMLElement>('.kr-booking__card'));
        const wall = wallClockOf('2026-11-03T08:00:00+00:00', 'Europe/Athens');

        expect(sooner?.textContent).toContain('Haircut');
        expect(sooner?.querySelector('.kr-booking__summary-when')?.textContent).toBe(formatMoment(wall, KEYED_WORDS.locale));
        expect(sooner?.querySelector('.kr-booking__summary-staff')?.textContent).toBe(said(WITH_STAFF, { staff: 'Elena' }));
        expect(sooner?.querySelector('.kr-booking__total')?.textContent).toBe(`${said(TOTAL_LABEL)} ${formatMoney(5000, 'JPY', KEYED_WORDS.locale)}`);
        expect(sooner?.querySelector('.kr-booking__status')?.textContent).toBe(statusSaid('awaiting_approval'));
        // Confirmed needs no sentence, and an item on the implicit member names nobody.
        expect(later?.querySelector('.kr-booking__status')).toBeNull();
        expect(later?.querySelector('.kr-booking__summary-staff')).toBeNull();
        expect(recent?.querySelector('.kr-booking__status')?.textContent).toBe(statusSaid('completed'));
    });

    it('links each card to Book again on the hosted page with the first item\'s service and its staff, the implicit member left out', async () => {
        await render(LIST);

        const links = Array.from(container.querySelectorAll<HTMLAnchorElement>('.kr-booking__again'));

        expect(links.map((link) => link.textContent)).toEqual([said(BOOK_AGAIN_LABEL), said(BOOK_AGAIN_LABEL), said(BOOK_AGAIN_LABEL)]);
        expect(links.map((link) => link.getAttribute('href'))).toEqual([`${ORIGIN}/?service=12&staff=4`, `${ORIGIN}/?service=13`, `${ORIGIN}/?service=12&staff=4`]);
    });

    it('offers on each card the actions of 6.3 from its status, and makes no request to show them', async () => {
        await render(LIST);

        // An approval request with no expiry on the wire may be cancelled and not moved; a
        // confirmed booking both; a completed one neither, its card's one control Book again.
        expect(cards().map(labels)).toEqual([[said(CANCEL_LABEL)], [said(CANCEL_LABEL), said(RESCHEDULE_LABEL)], []]);
        expect(sent).toEqual([]);
    });

    it('signs out through a form the browser posts with the page\'s token, its busy label shown and a second press sending nothing', async () => {
        await render(LIST);

        const [form] = forms();

        expect(forms()).toHaveLength(1);
        expect(form?.getAttribute('method')).toBe('post');
        expect(form?.getAttribute('action')).toBe(ORIGIN + SIGN_OUT_PATH);
        expect(form?.querySelector<HTMLInputElement>(`input[type="hidden"][name="${TOKEN_FIELD}"]`)?.value).toBe(CSRF);
        expect(form?.querySelector('button')?.textContent).toBe(said(SIGN_OUT_LABEL));
        expect(await submit(form)).toBe(true);
        expect(form?.querySelector('button')?.textContent).toBe(said(SIGNING_OUT_LABEL));
        expect(await submit(form)).toBe(false);
    });

    it('says there are none where the session\'s address has no bookings left, and still signs out', async () => {
        await render({ ...LIST, bookings: { upcoming: [], past: [] } });

        expect(container.textContent).toContain(said(NO_BOOKINGS_SENTENCE));
        expect(container.querySelectorAll('.kr-booking__card')).toHaveLength(0);
        expect(container.textContent).not.toContain(said(UPCOMING_HEADING));
        expect(forms()).toHaveLength(1);
    });

    it('says a flashed sentence above the list too', async () => {
        await render({ ...LIST, notice: 'You have signed out.' });

        expect(container.querySelector('[role="status"]')?.textContent).toBe('You have signed out.');
    });
});

describe('the cards\' actions, 6.3 to 6.5 on the session', () => {
    // 2026-11-02 08:00 Athens is the mount; a hold expiring at 09:00 UTC is live, at 05:00 gone.
    it.each([
        ['confirmed', null, [said(CANCEL_LABEL), said(RESCHEDULE_LABEL)]],
        ['pending', '2026-11-02T09:00:00+00:00', [said(CANCEL_LABEL), said(RESCHEDULE_LABEL)]],
        ['pending', '2026-11-02T05:00:00+00:00', [said(CANCEL_LABEL)]],
        ['awaiting_approval', '2026-11-03T09:00:00+00:00', [said(CANCEL_LABEL), said(RESCHEDULE_LABEL)]],
        ['awaiting_payment', '2026-11-02T05:00:00+00:00', [said(CANCEL_LABEL)]],
        ['completed', null, []],
        ['no_show', null, []],
        ['cancelled', null, []],
        ['rejected', null, []],
        ['expired', null, []],
    ])('renders on a %s booking expiring %s the controls %j', async (status, expires, controls) => {
        await render({ ...LIST, bookings: { upcoming: [booking('one', status, '2026-11-05T08:00:00+00:00', { hold_expires_at: expires })], past: [] } });

        expect(labels(card(0))).toEqual(controls);
    });

    it('renders no action where the mount\'s requests would carry no cookie, so no control is one that could only fail', async () => {
        await render(LIST, { ...API, credentials: 'omit' });

        expect(cards().map(labels)).toEqual([[], [], []]);
    });

    it('sends nothing on the first press of Cancel, then one POST to the cancel route on Yes, no token in its body and credentials included', async () => {
        await render(LIST);
        await press(buttonIn(card(1), said(CANCEL_LABEL)));

        expect(sent).toEqual([]);
        expect(card(1).textContent).toContain(said(CONFIRM_QUESTION));
        expect(labels(card(1))).toEqual([said(CANCEL_LABEL), said(RESCHEDULE_LABEL), said(YES_LABEL), said(KEEP_LABEL)]);

        await press(buttonIn(card(1), said(YES_LABEL)));

        expect(sent).toEqual([{ method: 'POST', path: cancelPath(LATER.uuid), credentials: 'include', body: '{}' }]);
    });

    it('says the cancel is under way in the card\'s polite region while it flies, and raises no alert', async () => {
        await render(LIST);
        await press(buttonIn(card(1), said(CANCEL_LABEL)));
        await press(buttonIn(card(1), said(YES_LABEL)));

        expect(polite(card(1))).toBe(said(CANCELLING_SENTENCE));
        expect(container.querySelectorAll('[role="alert"]')).toHaveLength(0);
        expect(buttonIn(card(1), said(YES_LABEL)).disabled).toBe(true);
        expect(buttonIn(card(1), said(KEEP_LABEL)).disabled).toBe(false);
    });

    it('reads the list again as JSON with credentials once the cancel lands, the card saying it landed and showing the server\'s answer only when that read does', async () => {
        const withheld = { release: (): void => undefined };

        cancelled = answering({ status: 'cancelled' });
        reread = () =>
            new Promise<Response>((resolve) => {
                withheld.release = () => resolve(new Response(JSON.stringify(listWire({ ...LATER_WIRE, status: 'cancelled' })), { status: 200 }));
            });

        await render(LIST);
        await press(buttonIn(card(1), said(CANCEL_LABEL)));
        await press(buttonIn(card(1), said(YES_LABEL)));

        expect(sent.map(({ method, path, credentials }) => [method, path, credentials])).toEqual([
            ['POST', cancelPath(LATER.uuid), 'include'],
            ['GET', MY_BOOKINGS_PATH, 'include'],
        ]);
        // Nothing optimistic: the row is closed and the landing said, the booking as it stood.
        expect(card(1).textContent).not.toContain(said(CONFIRM_QUESTION));
        expect(card(1).querySelector('.kr-booking__status')).toBeNull();
        expect(polite(card(1))).toBe(said(CANCELLED_SENTENCE));

        withheld.release();
        await settle();

        expect(card(1).querySelector('.kr-booking__status')?.textContent).toBe(statusSaid('cancelled'));
        expect(labels(card(1))).toEqual([]);
        expect(polite(card(1))).toBe(said(CANCELLED_SENTENCE));
    });

    it('keeps the card and its row on a refusal, the server\'s sentence said politely, and the list not read again', async () => {
        cancelled = answering({ code: 'window_closed', message: 'window_closed', data: { status: 403, detail: 'It is too late to cancel this booking online.' } }, 403);

        await render(LIST);
        await press(buttonIn(card(1), said(CANCEL_LABEL)));
        await press(buttonIn(card(1), said(YES_LABEL)));

        expect(polite(card(1))).toBe('It is too late to cancel this booking online.');
        expect(card(1).textContent).toContain(said(CONFIRM_QUESTION));
        expect(buttonIn(card(1), said(YES_LABEL)).disabled).toBe(false);
        expect(sent.filter(({ path }) => path === MY_BOOKINGS_PATH)).toEqual([]);
    });

    it('lands the focus on the card when Keep withdraws the cancel, the row gone and nothing sent', async () => {
        await render(LIST);
        await press(buttonIn(card(1), said(CANCEL_LABEL)));
        await press(buttonIn(card(1), said(KEEP_LABEL)));

        expect(card(1).textContent).not.toContain(said(CONFIRM_QUESTION));
        expect(document.activeElement).toBe(card(1));
        expect(sent).toEqual([]);
    });

    it('opens the reschedule dialog over the per-booking read with no token and credentials included, Close always there, every other card\'s actions disabled while it is open', async () => {
        moveRead = answering({ '2026-11-02': [], '2026-11-03': [NEW_START] });

        await render({ ...LIST, bookings: { upcoming: [SOONER, LATER, booking('upcoming-3', 'confirmed', '2026-11-06T08:00:00+00:00')], past: [RECENT] } });
        await press(buttonIn(card(1), said(RESCHEDULE_LABEL)));

        const dialog = card(1).querySelector<HTMLElement>('[role="dialog"]');

        expect(dialog?.getAttribute('aria-modal')).toBe('true');
        expect(dialog?.textContent).toContain(said(PICK_SENTENCE));
        expect(document.activeElement).toBe(dialog);
        expect(sent).toEqual([{ method: 'GET', path: '/api/v1/bookings/upcoming-2/availability?from=2026-11-02&to=2026-11-09', credentials: 'include', body: null }]);
        expect(buttonIn(card(1), said(CLOSE_LABEL)).disabled).toBe(false);
        expect([0, 1, 2].map((at) => Array.from(card(at).querySelectorAll<HTMLButtonElement>('.kr-booking__actions button:not(.kr-booking__close)')).map((control) => control.disabled))).toEqual([
            [true],
            [true, true],
            [true, true],
        ]);

        await press(buttonIn(card(1), said(CLOSE_LABEL)));

        expect(card(1).querySelector('[role="dialog"]')).toBeNull();
        expect(document.activeElement).toBe(buttonIn(card(1), said(RESCHEDULE_LABEL)));
        expect(cards().flatMap((each) => Array.from(each.querySelectorAll('button')).map((control) => control.disabled))).toEqual([false, false, false, false, false]);
    });

    it('moves through the reschedule route with the start and no token, credentials included, the pickers replaced by the progress line while it flies', async () => {
        moveRead = answering({ '2026-11-02': [], '2026-11-03': [NEW_START] });

        await render(LIST);
        await press(buttonIn(card(1), said(RESCHEDULE_LABEL)));
        await press(dayIn(card(1), '2026-11-03'));
        await press(slotIn(card(1), NEW_START));

        expect(sent.filter(({ method }) => method === 'POST')).toEqual([{ method: 'POST', path: reschedulePath(LATER.uuid), credentials: 'include', body: JSON.stringify({ start: NEW_START }) }]);
        expect(polite(card(1))).toContain(said(MOVING_SENTENCE));
        expect(card(1).querySelectorAll('.kr-booking__slot')).toHaveLength(0);
        expect(buttonIn(card(1), said(CLOSE_LABEL)).disabled).toBe(false);
    });

    it('closes the dialog on a landed move, says so in the card, and shows the new time only once the list read again answers it', async () => {
        const withheld = { release: (): void => undefined };

        moveRead = answering({ '2026-11-02': [], '2026-11-03': [NEW_START] });
        moved = answering({ status: 'confirmed' });
        reread = () =>
            new Promise<Response>((resolve) => {
                withheld.release = () => resolve(new Response(JSON.stringify(listWire({ ...LATER_WIRE, items: [{ ...LATER_ITEM, start: NEW_START, end: '2026-11-03T13:00:00+00:00' }] })), { status: 200 }));
            });

        await render(LIST);
        const before = card(1).querySelector('.kr-booking__summary-when')?.textContent;

        await press(buttonIn(card(1), said(RESCHEDULE_LABEL)));
        await press(dayIn(card(1), '2026-11-03'));
        await press(slotIn(card(1), NEW_START));

        expect(card(1).querySelector('[role="dialog"]')).toBeNull();
        expect(polite(card(1))).toBe(said(MOVED_SENTENCE));
        expect(document.activeElement).toBe(buttonIn(card(1), said(RESCHEDULE_LABEL)));
        expect(sent.map(({ method, path }) => [method, path])).toEqual([
            ['GET', '/api/v1/bookings/upcoming-2/availability?from=2026-11-02&to=2026-11-09'],
            ['POST', reschedulePath(LATER.uuid)],
            ['GET', MY_BOOKINGS_PATH],
        ]);
        expect(card(1).querySelector('.kr-booking__summary-when')?.textContent).toBe(before);

        withheld.release();
        await settle();

        expect(card(1).querySelector('.kr-booking__summary-when')?.textContent).toContain(formatTime(wallClockOf(NEW_START, 'Europe/Athens')));
        expect(polite(card(1))).toBe(said(MOVED_SENTENCE));
    });

    it('keeps the dialog open on a refusal with the sentence inside it, the card\'s time unchanged and the list not read again', async () => {
        moveRead = answering({ '2026-11-02': [], '2026-11-03': [NEW_START] });
        moved = answering({ code: 'unavailable', message: 'unavailable', data: { status: 409, detail: 'That time is no longer available. Please pick another.' } }, 409);

        await render(LIST);
        const before = card(1).querySelector('.kr-booking__summary-when')?.textContent;

        await press(buttonIn(card(1), said(RESCHEDULE_LABEL)));
        await press(dayIn(card(1), '2026-11-03'));
        await press(slotIn(card(1), NEW_START));

        expect(card(1).querySelector('[role="dialog"]')?.textContent).toContain('That time is no longer available. Please pick another.');
        expect(card(1).querySelector('.kr-booking__summary-when')?.textContent).toBe(before);
        expect(sent.filter(({ path }) => path === MY_BOOKINGS_PATH)).toEqual([]);
    });

    it('shows the form when the list read again after an action finds the session gone', async () => {
        cancelled = answering({ status: 'cancelled' });
        reread = answering({ bookings: null, notice: null, csrf: CSRF });

        await render(LIST);
        await press(buttonIn(card(1), said(CANCEL_LABEL)));
        await press(buttonIn(card(1), said(YES_LABEL)));

        expect(frame().getAttribute('data-kr-view')).toBe('form');
        expect(forms()[0]?.getAttribute('action')).toBe(ORIGIN + LINK_PATH);
    });
});

describe('the prefix the page was opened under', () => {
    it.each([
        ['a bare path', null, ''],
        ['/en', 'en', '/en'],
        ['/el', 'el', '/el'],
    ] as const)('posts the form under %s and leads back to it', async (_, prefix, under) => {
        await render(FORM, API, prefix);

        expect(forms().map((form) => form.getAttribute('action'))).toEqual([`${ORIGIN}${under}${LINK_PATH}`]);
    });

    // The business page is the root, /en and /el under a prefix with no trailing slash.
    it.each([
        ['a bare path', null, '', '/'],
        ['/en', 'en', '/en', '/en'],
        ['/el', 'el', '/el', '/el'],
    ] as const)('signs out under %s and opens Book again on the business page under it, the query kept', async (_, prefix, under, home) => {
        await render(LIST, API, prefix);

        expect(forms().map((form) => form.getAttribute('action'))).toEqual([`${ORIGIN}${under}${SIGN_OUT_PATH}`]);
        expect(Array.from(container.querySelectorAll('.kr-booking__again')).map((link) => link.getAttribute('href'))).toEqual([
            `${ORIGIN}${home}?service=12&staff=4`,
            `${ORIGIN}${home}?service=13`,
            `${ORIGIN}${home}?service=12&staff=4`,
        ]);
    });

    it.each([
        ['/my-bookings', ''],
        ['/en/my-bookings', '/en'],
        ['/el/my-bookings', '/el'],
        ['/el/my-bookings/link', '/el'],
    ])('reads the prefix off the hosted page at %s, through the shell', async (pathname, under) => {
        await act(() => {
            root.render(<App source={{ kind: 'ready', config: CONFIG }} options={OPTIONS} api={API} myBookings={FORM} page={{ pathname, search: '' }} />);
        });

        expect(forms().map((form) => form.getAttribute('action'))).toEqual([`${ORIGIN}${under}${LINK_PATH}`]);
    });
});

describe('in English', () => {
    it('reads the form\'s words from the English file', async () => {
        await render(FORM, API, null, ENGLISH_WORDS);

        expect(container.querySelector('.kr-booking__subtitle')?.textContent).toBe(BOOKING_LINES_EN.my_bookings.title);
        expect(container.querySelector('.kr-booking__description')?.textContent).toBe(BOOKING_LINES_EN.my_bookings.intro);
        expect(container.querySelector('label')?.textContent).toBe(BOOKING_LINES_EN.my_bookings.email);
        expect(forms()[0]?.querySelector('button')?.textContent).toBe(BOOKING_LINES_EN.my_bookings.send);
    });

    it('reads the list\'s words from the English file', async () => {
        await render(LIST, API, null, ENGLISH_WORDS);

        expect(Array.from(container.querySelectorAll('.kr-booking__group > .kr-booking__subtitle')).map((heading) => heading.textContent)).toEqual([BOOKING_LINES_EN.my_bookings.upcoming, BOOKING_LINES_EN.my_bookings.past]);
        expect(container.querySelector('.kr-booking__mine > .kr-booking__timezone')?.textContent).toBe(BOOKING_LINES_EN.zone.replace(':zone', 'Europe/Athens'));
        expect(card(0).querySelector('.kr-booking__total span')?.textContent).toBe(BOOKING_LINES_EN.booking.total);
        expect(card(0).querySelector('.kr-booking__status')?.textContent).toBe(BOOKING_LINES_EN.manage.statuses.awaiting_approval);
        expect(card(0).querySelector('.kr-booking__again')?.textContent).toBe(BOOKING_LINES_EN.booking.book_again);
        expect(forms()[0]?.querySelector('button')?.textContent).toBe(BOOKING_LINES_EN.my_bookings.sign_out);
    });

    it('says there are none from the English file', async () => {
        await render({ ...LIST, bookings: { upcoming: [], past: [] } }, API, null, ENGLISH_WORDS);

        expect(container.querySelector('.kr-booking__empty')?.textContent).toBe(BOOKING_LINES_EN.my_bookings.none);
    });
});

describe('in Greek', () => {
    // The walk by keys: the form, the list, the empty list, a card's cancel and the reschedule
    // dialog rendered with lang/el/booking.php's lines show no English line of booking.php but
    // the shared ones and no :word unfilled.
    it('renders the form and its busy label with no English line left', async () => {
        await render(FORM, API, 'el', GREEK_WORDS);
        expect(englishLeft(container)).toEqual([]);

        expect(await submit(forms()[0])).toBe(true);
        expect(forms()[0]?.querySelector('button')?.textContent).toBe(GREEK_WORDS.text(SENDING_LABEL));
        expect(englishLeft(container)).toEqual([]);
    });

    it('renders the list, the empty list and the sign-out\'s busy label with no English line left', async () => {
        await render(LIST, API, 'el', GREEK_WORDS);
        expect(englishLeft(container)).toEqual([]);

        expect(await submit(forms()[0])).toBe(true);
        expect(forms()[0]?.querySelector('button')?.textContent).toBe(GREEK_WORDS.text(SIGNING_OUT_LABEL));
        expect(englishLeft(container)).toEqual([]);

        await act(() => {
            root.unmount();
        });
        root = createRoot(container);
        await render({ ...LIST, bookings: { upcoming: [], past: [] } }, API, 'el', GREEK_WORDS);
        expect(container.querySelector('.kr-booking__empty')?.textContent).toBe(GREEK_WORDS.text('my_bookings.none'));
        expect(englishLeft(container)).toEqual([]);
    });

    it('walks a card\'s cancel to its landing and opens the reschedule dialog with no English line left', async () => {
        cancelled = answering({ status: 'cancelled' });
        reread = () => Promise.resolve(new Response(JSON.stringify(listWire({ ...LATER_WIRE, status: 'cancelled' })), { status: 200 }));

        await render(LIST, API, 'el', GREEK_WORDS);
        await press(buttonIn(card(1), GREEK_WORDS.text(RESCHEDULE_LABEL)));
        expect(englishLeft(container)).toEqual([]);

        await press(buttonIn(container, GREEK_WORDS.text(CLOSE_LABEL)));
        await press(buttonIn(card(1), GREEK_WORDS.text(CANCEL_LABEL)));
        expect(card(1).textContent).toContain(GREEK_WORDS.text(CONFIRM_QUESTION));
        expect(englishLeft(container)).toEqual([]);

        await press(buttonIn(card(1), GREEK_WORDS.text(YES_LABEL)));
        await settle();
        expect(polite(card(1))).toBe(GREEK_WORDS.text(CANCELLED_SENTENCE));
        expect(englishLeft(container)).toEqual([]);
    });
});

describe('the shell', () => {
    it('renders the list mode where the mount names it and the page inlined the list, and the flow where it inlined none', async () => {
        await act(() => {
            root.render(<App source={{ kind: 'ready', config: CONFIG }} options={OPTIONS} api={API} myBookings={FORM} />);
        });

        expect(frame().getAttribute('data-kr-mode')).toBe('list');
        expect(frame().getAttribute('data-kr-view')).toBe('form');

        await act(() => {
            root.render(<App source={{ kind: 'ready', config: CONFIG }} options={{ ...OPTIONS, mode: 'book' }} api={API} myBookings={null} />);
        });

        expect(container.querySelector('[data-kr-step]')).not.toBeNull();
    });
});
