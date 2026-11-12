import { QueryClientProvider } from '@tanstack/react-query';
import { act, type ReactNode } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { bookingSources } from '../bookingSources';
import { keyed, said, saidBy } from '../bookingWords';
import { createQueryClient, type Api } from './api';
import type { MountOptions } from './attributes';
import { AVAILABILITY_PATH } from './availability';
import { parseBooking } from './booking';
import { cancelPath } from './cancel';
import type { BootConfig, Fetch } from './config';
import { confirmPath } from './confirm';
import { EXPIRED_MILESTONE } from './countdown';
import { BUSY_LABEL } from './DetailsStep';
import { CONTINUE_LABEL, PICK_A_SERVICE, PICK_A_TIME_LABEL } from './Estimate';
import { Flow } from './Flow';
import { HANDED_OFF_SENTENCE } from './flow';
import { HOLDS_PATH } from './hold';
import { BOOKINGS_PATH } from './manage';
import { CANCEL_LABEL, CANCELLING_SENTENCE, CONFIRM_QUESTION, KEEP_LABEL, KEEP_TO_RESCHEDULE_SENTENCE, ManageView, TRY_AGAIN_LABEL, TRYING_LABEL, YES_LABEL } from './ManageView';
import { PICK_SENTENCE, RESCHEDULE_LABEL } from './move';
import { MyBookingsView, SENDING_LABEL, SIGNING_OUT_LABEL } from './MyBookingsView';
import { CONFIRM_LABEL, CONFIRMING_LABEL, PAYMENT_LABEL } from './ReviewStep';
import { LIMIT_SENTENCE, LOADING_SERVICES_SENTENCE } from './ServiceStep';
import { STRIP_AHEAD, STRIP_CURRENT, STRIP_NAMES } from './Strip';
import { NO_TIMES } from './WhenStep';

/*
 * Every control that renders disabled states its reason in text within its step. The rule is held
 * three ways here. The table below names every control that can render disabled, by the class
 * that tells it apart, with each case in which it does and the sentence that says why. Each case
 * is driven on the real flow or the real manage view, and asserts the control disabled, the
 * sentence somewhere in the frame, the control's own label included, and that nothing else in the
 * frame is disabled without a row in the table. A source audit then reads every component and
 * refuses a disabled attribute on an element whose class is not in the table, so a new disabled
 * control cannot arrive without its reason being named first.
 *
 * The unavailable-choice rule of 7.5 applies to one choice today: a day of the week row whose
 * answer holds no starts is a real disabled button carrying its no-times words inside it, so its
 * reason is within the control itself rather than a sentence beside it, and a second table names
 * it with those words. No staff choice, period or slot ever renders disabled, a period holding no
 * start being left out rather than disabled; a service card does, at the chain's maximum, said
 * by the sentence above the list rather than within itself (below). The last tests say so, so
 * another disabled choice added later has to answer to 7.5 and to that table.
 *
 * The cards of My bookings (6.6) render the manage view's actions under the same classes, so the
 * same rows name them; the list's own block below drives them on the list, a dialog open on one
 * card disabling every other card's actions as well, an armed row's Yes and Keep on another card
 * among them (P9F-L17-c).
 *
 * The strip of 1.4 is several controls under one class, so it has a table of its own: a step
 * renders disabled as the current one or one not reached yet, saying which in its own text, or,
 * finished, while a hold flies, said on the submit beside it as Back is. The Next available date
 * button of 2.3 renders only where it has a day to go to and is never disabled.
 *
 * The service cards are several controls under one class too: at the chain's maximum the
 * unpressed ones render disabled, and the one sentence above the list says how many can be
 * chosen, which is the count of the pressed cards. The summary card's one Continue renders
 * disabled on service before the first segment, on staff while the catalog is on its way, on
 * when, where a pick is the transition and its own label says so, and on details while the hold
 * flies, its own label the busy one.
 */

interface Case {
    /** When the control renders disabled, in words. */
    readonly when: string;
    /** The sentence that says why, found in the frame. */
    readonly reason: string;
    /** Drives the real component to that state. */
    readonly reach: () => Promise<void>;
}

const CONFIG: BootConfig = { tenantName: 'Luna Hair Studio', timezone: 'Europe/Athens', currency: 'EUR', granularityMin: 5, importantInfo: null, defaultLanguage: 'en', language: 'en' };

// 07:32 UTC on Monday 2026-04-06 is 10:32 in Athens, so the tenant's today is the 6th.
const NOW = Date.UTC(2026, 3, 6, 7, 32);

const CATALOG = { services: [{ id: 5, name: 'Head spa', description: '', duration_min: 30, price_minor: 5000, currency: 'EUR', staff: [] }], max_chain_length: 5 };

// 12:00 UTC is 15:00 in Athens, an afternoon beside the morning, so the period control renders.
const TIMES = {
    '2026-04-06': ['2026-04-06T07:35:00+00:00', '2026-04-06T07:40:00+00:00', '2026-04-06T12:00:00+00:00'],
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
    total_minor: 4200,
    currency: 'EUR',
    items: [{ sort: 0, service_id: 5, service_name: 'Head spa', staff_id: 9, staff_name: 'Aiko', start: '2026-04-06T07:40:00+00:00', end: '2026-04-06T08:10:00+00:00', price_minor: 4200 }],
    manage_token: 'f'.repeat(64),
};

// The guest view with no token beside it: what the recovery's re-read and the manage read answer.
const GUEST_PENDING = { ...HELD, manage_token: undefined };

const CONFIRMED = { ...GUEST_PENDING, status: 'confirmed', hold_class: null, hold_expires_at: null };

const NOT_CONFIRMABLE = { code: 'not_confirmable', message: 'not_confirmable', data: { status: 409, detail: 'This booking can no longer be confirmed.' } };

const EXPIRED = { code: 'hold_expired', message: 'hold_expired', data: { status: 410, detail: 'The time we were holding for you has been released.' } };

const BROKEN = { code: 'server_error', message: 'server_error', data: { status: 500, detail: 'Something went wrong at our end.' } };

const MOVE_ANSWER = { '2026-04-06': [], '2026-04-07': ['2026-04-07T07:00:00+00:00', '2026-04-07T12:00:00+00:00'] };

type Answer = () => Promise<Response>;

function answering(body: unknown, status = 200): Answer {
    return () => Promise.resolve(new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }));
}

/** A request left out for the whole test, so whatever waits on it is seen in flight. */
const never: Answer = () => new Promise<Response>(() => undefined);

let container: HTMLDivElement;
let root: Root;
let hold: Answer;
let confirm: Answer;
let read: Answer;
let cancel: Answer;
let catalog: Answer;

/**
 * One fetch for both components, by path: the flow's hold, confirm, re-read, release,
 * availability and catalog, and the manage view's read, cancel and move list.
 */
const routing: Fetch = (input, init) => {
    const path = input.replace('https://luna.kleiseradevou.gr', '');

    if (init.method === 'DELETE') {
        return Promise.resolve(new Response(null, { status: 204 }));
    }

    if (path === HOLDS_PATH) {
        return hold();
    }

    if (path === confirmPath(HELD.uuid)) {
        return confirm();
    }

    if (path === cancelPath(HELD.uuid)) {
        return cancel();
    }

    if (path.startsWith(`${BOOKINGS_PATH}/${HELD.uuid}/availability`)) {
        return answering(MOVE_ANSWER)();
    }

    if (path.startsWith(`${BOOKINGS_PATH}/${HELD.uuid}?`)) {
        return read();
    }

    if (path.startsWith(AVAILABILITY_PATH)) {
        return answering(TIMES)();
    }

    return catalog();
};

beforeEach(() => {
    container = document.createElement('div');
    document.body.appendChild(container);
    root = createRoot(container);
    hold = answering(HELD, 201);
    confirm = answering(CONFIRMED);
    read = answering(GUEST_PENDING);
    cancel = answering({ ...CONFIRMED, status: 'cancelled' });
    catalog = answering(CATALOG);
});

afterEach(async () => {
    await act(() => {
        root.unmount();
    });
    container.remove();
});

async function render(element: ReactNode): Promise<void> {
    const client = createQueryClient();

    // The retry backoff is the client's, and waiting it out proves nothing here.
    client.setDefaultOptions({ queries: { ...client.getDefaultOptions().queries, retryDelay: 0 } });

    await act(() => {
        root.render(keyed(<QueryClientProvider client={client}>{element}</QueryClientProvider>));
    });
    await settle();
}

async function mountFlow(serviceId: number | null = 5): Promise<void> {
    const options: MountOptions = { mode: 'book', serviceId, staffId: null, uuid: null, token: null, channel: null };

    await render(<Flow config={CONFIG} options={options} api={{ origin: 'https://luna.kleiseradevou.gr', fetch: routing }} browser={{ now: () => NOW, navigate: () => undefined }} />);
}

async function mountManage(): Promise<void> {
    const options: MountOptions = { mode: 'manage', serviceId: null, staffId: null, uuid: HELD.uuid, token: HELD.manage_token, channel: null };
    const api: Api = { origin: 'https://luna.kleiseradevou.gr', fetch: routing };

    await render(<ManageView config={CONFIG} options={options} api={api} now={() => NOW} />);
}

/**
 * Lets the query client's deferred notifications, the retries behind them and every effect they
 * trigger land. Disabled is read only after this, never between an awaited press and it.
 */
async function settle(): Promise<void> {
    await act(async () => {
        for (let turn = 0; turn < 12; turn += 1) {
            await new Promise<void>((done) => setTimeout(done, 0));
        }
    });
}

function frame(): HTMLElement {
    const found = container.querySelector<HTMLElement>('.kr-booking__frame');

    if (found === null) {
        throw new Error('Nothing rendered a frame.');
    }

    return found;
}

function button(name: string): HTMLButtonElement {
    const all = Array.from(container.querySelectorAll('button'));
    const found = all.find((candidate) => candidate.textContent === name);

    if (found === undefined) {
        throw new Error(`No button named "${name}" among: ${all.map((candidate) => candidate.textContent).join(', ')}`);
    }

    return found;
}

function all(selector: string): HTMLElement[] {
    return Array.from(container.querySelectorAll<HTMLElement>(selector));
}

async function press(el: HTMLElement): Promise<void> {
    await act(() => {
        el.focus();
        el.click();
    });
    await settle();
}

/**
 * A keystroke as the browser delivers one, past the value tracker React installs on the node.
 */
async function typeInto(name: string, value: string): Promise<void> {
    const input = container.querySelector<HTMLInputElement>(`input[name="${name}"]`);

    if (input === null) {
        throw new Error(`No field named "${name}".`);
    }

    await act(() => {
        Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')?.set?.call(input, value);
        input.dispatchEvent(new Event('input', { bubbles: true }));
    });
}

/** The journey as far as the details form: the one service, the first day, its second time, the customer typed in. */
async function reachDetails(): Promise<void> {
    await mountFlow();
    await press(all('.kr-booking__day')[0] as HTMLElement);
    await press(all('.kr-booking__slot')[1] as HTMLElement);
    await typeInto('customer_name', 'Casey Customer');
    await typeInto('customer_email', 'casey@example.com');
}

/** The details step reached from the flow already mounted on when: the first day, its second time. */
async function reachDetailsAgain(): Promise<void> {
    await press(all('.kr-booking__day')[0] as HTMLElement);
    await press(all('.kr-booking__slot')[1] as HTMLElement);
}

async function holdFlying(): Promise<void> {
    await reachDetails();
    hold = never;
    await press(button(said(CONTINUE_LABEL)));
}

async function bareService(): Promise<void> {
    await mountFlow(null);
}

async function staffWaiting(): Promise<void> {
    catalog = never;
    await mountFlow();
}

async function whenShown(): Promise<void> {
    await mountFlow();
}

/** A chain of one at a cap of one: the other card renders disabled. */
async function capReached(): Promise<void> {
    catalog = answering({ ...CATALOG, services: [...CATALOG.services, { id: 6, name: 'Beard trim', description: '', duration_min: 20, price_minor: 1500, currency: 'EUR', staff: [] }], max_chain_length: 1 });
    await mountFlow(null);
    await press(all('.kr-booking__service')[0] as HTMLElement);
}

async function reachReview(): Promise<void> {
    await reachDetails();
    await press(button(said(CONTINUE_LABEL)));
}

async function confirmFlying(): Promise<void> {
    await reachReview();
    confirm = never;
    await press(button(said(CONFIRM_LABEL)));
}

async function recoveryFlying(): Promise<void> {
    await reachReview();
    confirm = answering(NOT_CONFIRMABLE, 409);
    read = never;
    await press(button(said(CONFIRM_LABEL)));
}

async function countdownRanOut(): Promise<void> {
    await reachDetails();
    hold = answering({ ...HELD, hold_expires_at: '2026-04-06T07:31:00+00:00' }, 201);
    await press(button(said(CONTINUE_LABEL)));
}

async function goneBy410(): Promise<void> {
    await reachReview();
    confirm = answering(EXPIRED, 410);
    await press(button(said(CONFIRM_LABEL)));
}

async function handedOff(): Promise<void> {
    await reachDetails();
    hold = answering({ ...HELD, checkout_url: 'https://pay.example.com/session/abc' }, 201);
    await press(button(said(CONTINUE_LABEL)));
    await press(button(said(PAYMENT_LABEL)));
}

async function retryFlying(): Promise<void> {
    read = answering(BROKEN, 500);
    await mountManage();
    read = never;
    await press(button(said(TRY_AGAIN_LABEL)));
}

async function cancelArmed(): Promise<void> {
    read = answering(CONFIRMED);
    await mountManage();
    await press(button(said(CANCEL_LABEL)));
}

async function cancelFlying(): Promise<void> {
    await cancelArmed();
    cancel = never;
    await press(button(said(YES_LABEL)));
}

async function dialogOpen(): Promise<void> {
    read = answering(CONFIRMED);
    await mountManage();
    await press(button(said(RESCHEDULE_LABEL)));
}

/**
 * The dialog open over an armed cancel row: both presses land before either re-render, so
 * neither disabled attribute is in the DOM yet. The one route to Keep this booking disabled.
 */
async function dialogOverArmedRow(): Promise<void> {
    read = answering(CONFIRMED);
    await mountManage();
    await act(() => {
        button(said(CANCEL_LABEL)).click();
        button(said(RESCHEDULE_LABEL)).click();
    });
    await settle();
}

/**
 * Every control that can render disabled, keyed by the class that tells it apart, with each case
 * and the sentence within the step that says why.
 */
const REASONS: Readonly<Record<string, readonly Case[]>> = {
    'kr-booking__continue': [
        { when: 'on service before the first segment', reason: said(PICK_A_SERVICE), reach: bareService },
        { when: 'on staff while the catalog is on its way', reason: said(LOADING_SERVICES_SENTENCE), reach: staffWaiting },
        { when: 'on when, where a pick is the transition', reason: said(PICK_A_TIME_LABEL), reach: whenShown },
        { when: 'on details while the hold flies', reason: said(BUSY_LABEL), reach: holdFlying },
    ],
    'kr-booking__back': [{ when: 'while the hold flies, said on the submit beside it', reason: said(BUSY_LABEL), reach: holdFlying }],
    'kr-booking__confirm': [
        { when: 'while a confirm is out', reason: said(CONFIRMING_LABEL), reach: confirmFlying },
        { when: 'while the recovery re-read of a refusal is out', reason: said(CONFIRMING_LABEL), reach: recoveryFlying },
        { when: 'once the countdown has run out', reason: said(EXPIRED_MILESTONE), reach: countdownRanOut },
        { when: 'once a 410 whose recovery stood has said the hold is gone', reason: EXPIRED.data.detail, reach: goneBy410 },
        { when: 'once the hold is handed off to the checkout', reason: said(HANDED_OFF_SENTENCE), reach: handedOff },
    ],
    'kr-booking__retry': [{ when: 'while its refetch flies', reason: said(TRYING_LABEL), reach: retryFlying }],
    'kr-booking__cancel': [
        { when: 'while the cancel row is armed', reason: said(CONFIRM_QUESTION), reach: cancelArmed },
        { when: 'while the reschedule dialog is open', reason: said(PICK_SENTENCE), reach: dialogOpen },
    ],
    'kr-booking__reschedule': [
        { when: 'while the cancel row is armed', reason: said(KEEP_TO_RESCHEDULE_SENTENCE), reach: cancelArmed },
        { when: 'while the reschedule dialog is open', reason: said(PICK_SENTENCE), reach: dialogOpen },
    ],
    'kr-booking__yes': [
        { when: 'while a cancel flies', reason: said(CANCELLING_SENTENCE), reach: cancelFlying },
        { when: 'while the reschedule dialog is open', reason: said(PICK_SENTENCE), reach: dialogOverArmedRow },
    ],
    'kr-booking__keep': [{ when: 'while the reschedule dialog is open', reason: said(PICK_SENTENCE), reach: dialogOverArmedRow }],
};

const NAMED = Object.keys(REASONS);

/** The choices 7.5's unavailable-choice rule governs. */
const CHOICES = ['kr-booking__service', 'kr-booking__choice', 'kr-booking__day', 'kr-booking__period', 'kr-booking__slot'];

/**
 * The choices that render disabled when unavailable, keyed by class, with the words each carries
 * inside itself when it does: 7.5's state in text within the control. A day alone, today.
 */
const UNAVAILABLE: Readonly<Record<string, string>> = { 'kr-booking__day': said(NO_TIMES) };

const UNAVAILABLE_NAMES = Object.keys(UNAVAILABLE);

/** The strip's steps, one class for every step. */
const STRIP = 'kr-booking__crumb';

/** What a disabled step of the strip says within itself: it is the current one, or not reached. */
const STRIP_STATES = [said(STRIP_CURRENT), said(STRIP_AHEAD)];

/** The service cards, one class for every card. */
const CARD = 'kr-booking__service';

/**
 * A service card whose disabled state is said: the sentence above the list naming as many
 * services as the pressed cards are, which is the chain at its maximum.
 */
function cardSaysWhy(control: HTMLElement, within: HTMLElement): boolean {
    const chosen = within.querySelectorAll(`.${CARD}[aria-pressed="true"]`).length;

    return control.classList.contains(CARD) && control.getAttribute('aria-pressed') === 'false' && (within.textContent ?? '').includes(saidBy(LIMIT_SENTENCE, chosen));
}

/**
 * A step of the strip whose disabled state is said: within itself as current or not reached
 * yet, or, a finished one, by the submit's busy label in the frame while a hold flies.
 */
function stripSaysWhy(control: HTMLElement, within: HTMLElement): boolean {
    const own = control.textContent ?? '';

    return control.classList.contains(STRIP) && (STRIP_STATES.some((words) => own.includes(words)) || (within.textContent ?? '').includes(said(BUSY_LABEL)));
}

/** An unavailable choice whose own text carries the words its row names. */
function saysWhyWithin(control: HTMLElement): boolean {
    return Object.entries(UNAVAILABLE).some(([name, words]) => control.classList.contains(name) && (control.textContent ?? '').includes(words));
}

/**
 * Every disabled control in the frame that no row of the table names and that is no
 * unavailable choice saying so within itself, as its markup, so a failure shows exactly what
 * went unnamed.
 */
function unnamedDisabled(within: HTMLElement): string[] {
    return Array.from(within.querySelectorAll<HTMLElement>('button[disabled], input[disabled], select[disabled], textarea[disabled], fieldset[disabled]'))
        .filter((control) => !NAMED.some((name) => control.classList.contains(name)) && !saysWhyWithin(control) && !stripSaysWhy(control, within) && !cardSaysWhy(control, within))
        .map((control) => control.outerHTML);
}

const cases = Object.entries(REASONS).flatMap(([name, rows]) => rows.map((row) => ({ name, ...row })));

describe('every disabled control says why within its step', () => {
    it.each(cases)('$name $when', async ({ name, reason, reach }) => {
        await reach();

        const controls = Array.from(frame().querySelectorAll<HTMLButtonElement>(`.${name}`));

        expect(controls).toHaveLength(1);
        expect(controls[0]?.disabled).toBe(true);
        expect(frame().textContent).toContain(reason);
        expect(unnamedDisabled(frame())).toEqual([]);
    });

    it('names at least one case for every control in the table', () => {
        expect(NAMED.filter((name) => (REASONS[name] ?? []).length === 0)).toEqual([]);
    });
});

describe('My bookings, the list mode of 6.6, whose forms disable nothing and whose cards\' actions disable as the manage page\'s do', () => {
    // Its two forms show their busy label inside their button and latch a second press, so no
    // control of them is ever disabled. The cards' actions are the manage page's, under the same
    // classes and so the same rows of the table above, for the same reasons; and a dialog open on
    // one card disables every other card's actions too, the by-mouse half of its modality over the
    // list, its heading the sentence that says why.
    it.each([
        ['the form while its link is asked for', null, said(SENDING_LABEL)],
        ['the list while it signs out', { upcoming: [], past: [] }, said(SIGNING_OUT_LABEL)],
    ])('renders no disabled control on %s', async (_name, bookings, busy) => {
        const options: MountOptions = { mode: 'list', serviceId: null, staffId: null, uuid: null, token: null, channel: null };

        await render(<MyBookingsView config={CONFIG} options={options} page={{ bookings, notice: null, csrf: 'csrf' }} api={{ origin: 'https://luna.kleiseradevou.gr', fetch: routing }} />);
        await act(() => {
            frame().querySelector('form')?.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        });

        expect(frame().textContent).toContain(busy);
        expect(frame().querySelectorAll('[disabled]')).toHaveLength(0);
    });

    const OTHER = { ...CONFIRMED, uuid: '7b7f0e5a-2222-4000-8000-00000000000b' };

    /** Two confirmed bookings on two cards, the first the routed one, under the list's credentials. */
    async function mountCards(): Promise<void> {
        const options: MountOptions = { mode: 'list', serviceId: null, staffId: null, uuid: null, token: null, channel: null };
        const listed = [CONFIRMED, OTHER].map(parseBooking).filter((booking) => booking !== null);

        await render(<MyBookingsView config={CONFIG} options={options} page={{ bookings: { upcoming: listed, past: [] }, notice: null, csrf: 'csrf' }} api={{ origin: 'https://luna.kleiseradevou.gr', fetch: routing, credentials: 'include' }} now={() => NOW} />);
    }

    function onCard(at: number, name: string): HTMLButtonElement {
        const found = Array.from(frame().querySelectorAll('.kr-booking__card')[at]?.querySelectorAll<HTMLButtonElement>('button') ?? []).find((control) => control.textContent === name);

        if (found === undefined) {
            throw new Error(`Card ${at} has no button named "${name}".`);
        }

        return found;
    }

    async function cardArmed(): Promise<void> {
        await mountCards();
        await press(onCard(0, said(CANCEL_LABEL)));
    }

    async function cardCancelling(): Promise<void> {
        cancel = never;
        await cardArmed();
        await press(onCard(0, said(YES_LABEL)));
    }

    async function cardDialog(): Promise<void> {
        await mountCards();
        await press(onCard(0, said(RESCHEDULE_LABEL)));
    }

    // The cross-card rule's other half (P9F-L17-c): the first card's row armed, then the second
    // card's dialog opened over the list, which disables the armed row's two pills as well.
    async function armedUnderDialog(): Promise<void> {
        await cardArmed();
        await press(onCard(1, said(RESCHEDULE_LABEL)));
    }

    it.each([
        ['the first card\'s Cancel while its row is armed', 0, said(CANCEL_LABEL), said(CONFIRM_QUESTION), cardArmed],
        ['the first card\'s Reschedule while its row is armed', 0, said(RESCHEDULE_LABEL), said(KEEP_TO_RESCHEDULE_SENTENCE), cardArmed],
        ['the first card\'s Yes while its cancel flies', 0, said(YES_LABEL), said(CANCELLING_SENTENCE), cardCancelling],
        ['the first card\'s Cancel while its dialog is open', 0, said(CANCEL_LABEL), said(PICK_SENTENCE), cardDialog],
        ['the first card\'s Reschedule while its dialog is open', 0, said(RESCHEDULE_LABEL), said(PICK_SENTENCE), cardDialog],
        ['the second card\'s Cancel while the first card\'s dialog is open', 1, said(CANCEL_LABEL), said(PICK_SENTENCE), cardDialog],
        ['the second card\'s Reschedule while the first card\'s dialog is open', 1, said(RESCHEDULE_LABEL), said(PICK_SENTENCE), cardDialog],
        ['the first card\'s armed Yes while the second card\'s dialog is open', 0, said(YES_LABEL), said(PICK_SENTENCE), armedUnderDialog],
        ['the first card\'s armed Keep while the second card\'s dialog is open', 0, said(KEEP_LABEL), said(PICK_SENTENCE), armedUnderDialog],
    ])('disables %s, saying why within the frame', async (_name, at, control, reason, reach) => {
        await reach();

        expect(onCard(at, control).disabled).toBe(true);
        expect(frame().textContent).toContain(reason);
        expect(unnamedDisabled(frame())).toEqual([]);
    });

    it('disables nothing on a card at rest, nor on the list around it', async () => {
        await mountCards();

        expect(frame().querySelectorAll('[disabled]')).toHaveLength(0);
    });
});

describe('the strip of 1.4, whose disabled steps say why', () => {
    function steps(): HTMLButtonElement[] {
        return Array.from(frame().querySelectorAll<HTMLButtonElement>(`.${STRIP}`));
    }

    /** Each step of the strip as its value, whether disabled, and what it says within itself. */
    function read(): [string, boolean, string | null][] {
        return steps().map((step) => [step.value, step.disabled, STRIP_STATES.find((words) => (step.textContent ?? '').includes(words)) ?? null]);
    }

    it('says on the current step and on each one not reached yet, within itself, why it is disabled, and leaves a finished one live', async () => {
        await mountFlow();

        expect(read()).toEqual([
            ['when', true, said(STRIP_CURRENT)],
            ['details', true, said(STRIP_AHEAD)],
        ]);

        await reachDetailsAgain();

        expect(read()).toEqual([
            ['when', false, null],
            ['details', true, said(STRIP_CURRENT)],
        ]);
        expect(unnamedDisabled(frame())).toEqual([]);
    });

    it('disables a finished step while the hold flies, said on the submit beside it as Back is', async () => {
        await holdFlying();

        expect(read()).toEqual([
            ['when', true, null],
            ['details', true, said(STRIP_CURRENT)],
        ]);
        expect(frame().textContent).toContain(BUSY_LABEL);
        expect(unnamedDisabled(frame())).toEqual([]);
    });

    it('reports a disabled step that says nothing within itself while no hold flies', () => {
        const strip = document.createElement('div');

        strip.innerHTML = `<button class="${STRIP}" value="when" disabled>${said(STRIP_NAMES.when)}</button><button class="${STRIP}" value="details" disabled>${said(STRIP_NAMES.details)} ${said(STRIP_AHEAD)}</button>`;

        expect(unnamedDisabled(strip)).toEqual([`<button class="${STRIP}" value="when" disabled="">${said(STRIP_NAMES.when)}</button>`]);
    });
});

describe('the service cards at the chain\'s maximum, whose disabled state the sentence above the list says', () => {
    it('disables the unpressed cards at the cap, the sentence naming as many as are chosen in the frame', async () => {
        await capReached();

        const cards = all(`.${CARD}`);

        expect(cards.map((card) => [card.getAttribute('aria-pressed'), card.hasAttribute('disabled')])).toEqual([
            ['true', false],
            ['false', true],
        ]);
        expect(frame().textContent).toContain(saidBy(LIMIT_SENTENCE, 1));
        expect(unnamedDisabled(frame())).toEqual([]);
    });

    it('reports a disabled card when no sentence says why', () => {
        const list = document.createElement('div');

        list.innerHTML = `<button class="${CARD}" aria-pressed="true">Head spa</button><button class="${CARD}" aria-pressed="false" disabled>Beard trim</button>`;

        expect(unnamedDisabled(list)).toEqual([`<button class="${CARD}" aria-pressed="false" disabled="">Beard trim</button>`]);
    });
});

describe('the unavailable-choice rule of 7.5, which applies to a day alone', () => {
    /** The values of the days rendered disabled, and whether each says No times within itself. */
    function disabledDays(): [string | null, boolean][] {
        return all('.kr-booking__day')
            .filter((day) => day.hasAttribute('disabled'))
            .map((day) => [day.getAttribute('value'), (day.textContent ?? '').includes(said(NO_TIMES))]);
    }

    it('makes a day with no starts a disabled button saying No times within itself, and no period or slot disabled', async () => {
        await mountFlow();

        expect(disabledDays()).toEqual([['2026-04-07', true]]);
        expect(all('.kr-booking__day').filter((day) => !day.hasAttribute('disabled')).some((day) => (day.textContent ?? '').includes(said(NO_TIMES)))).toBe(false);
        expect(all('.kr-booking__period')).toHaveLength(2);
        expect(all('.kr-booking__period').some((period) => period.hasAttribute('disabled'))).toBe(false);
        expect(all('.kr-booking__slot').length).toBeGreaterThan(0);
        expect(all('.kr-booking__slot').some((slot) => slot.hasAttribute('disabled'))).toBe(false);
        expect(unnamedDisabled(frame())).toEqual([]);
    });

    it('holds inside the reschedule dialog, over the same list', async () => {
        await dialogOpen();

        expect(disabledDays()).toEqual([['2026-04-06', true]]);
        expect(all('.kr-booking__period')).toHaveLength(2);
        expect(all('.kr-booking__period').some((period) => period.hasAttribute('disabled'))).toBe(false);
        expect(all('.kr-booking__slot').some((slot) => slot.hasAttribute('disabled'))).toBe(false);
        expect(unnamedDisabled(frame())).toEqual([]);
    });

    it('reports a disabled day that does not say why within itself', () => {
        const row = document.createElement('div');

        row.innerHTML = `<button class="kr-booking__day" value="2026-04-07" disabled>Tue 7</button><button class="kr-booking__day" value="2026-04-08" disabled>Wed 8 ${said(NO_TIMES)}</button>`;

        expect(unnamedDisabled(row)).toEqual(['<button class="kr-booking__day" value="2026-04-07" disabled="">Tue 7</button>']);
    });
});

/*
 * The source audit. Every JSX opening element of the booking app's source, read comments
 * stripped through the reader the three audits share, and one that carries a disabled attribute
 * must carry a class the table names. The scanner is small and is
 * tested on fixtures first, so the audit cannot pass by being broken.
 */

interface Tag {
    readonly file: string;
    readonly text: string;
}

/**
 * Every opening element of a source: from a < followed by a letter to the > that closes it
 * outside any braces or quotes, so an arrow or a comparison inside an attribute expression does
 * not end the element early. Type arguments are read as elements too, and carry no attributes.
 */
function elementsOf(file: string, source: string): Tag[] {
    const elements: Tag[] = [];

    for (let start = source.indexOf('<'); start !== -1; start = source.indexOf('<', start + 1)) {
        if (!/[A-Za-z]/.test(source.charAt(start + 1))) {
            continue;
        }

        let depth = 0;
        let quote: string | null = null;
        let end = start + 1;

        for (; end < source.length; end += 1) {
            const char = source.charAt(end);

            if (quote !== null) {
                if (char === quote) {
                    quote = null;
                }
            } else if (char === '"' || char === "'" || char === '`') {
                quote = char;
            } else if (char === '{') {
                depth += 1;
            } else if (char === '}') {
                depth -= 1;
            } else if (char === '>' && depth === 0) {
                break;
            }
        }

        elements.push({ file, text: source.slice(start, end + 1) });
    }

    return elements;
}

function disables(element: Tag): boolean {
    return /\sdisabled(?=[\s=/>])/.test(element.text);
}

/** The class tokens of an element's literal className, or none for an element without one. */
function classesOf(element: Tag): string[] {
    return /\sclassName="([^"]*)"/.exec(element.text)?.[1]?.split(/\s+/) ?? [];
}

/** Every element carrying disabled whose classes include no name the tables have, as file and markup. */
function unnamedInSource(elements: readonly Tag[]): string[] {
    return elements.filter((element) => disables(element) && !classesOf(element).some((name) => NAMED.includes(name) || UNAVAILABLE_NAMES.includes(name) || name === STRIP || name === CARD)).map((element) => `${element.file}: ${element.text}`);
}

function componentElements(): Tag[] {
    return bookingSources().flatMap(({ file, text }) => elementsOf(file, text));
}

describe('the source audit of disabled controls', () => {
    it('passes a named control whose attribute expressions hold arrows and comparisons', () => {
        const fixture = '<button type="button" className="kr-booking__button kr-booking__back" onClick={() => go(a > b ? "x" : "y")} disabled={busy}>Back</button>';

        expect(elementsOf('fixture', fixture).filter(disables)).toHaveLength(1);
        expect(unnamedInSource(elementsOf('fixture', fixture))).toEqual([]);
    });

    it('fails an unnamed control, a class that merely begins with a named one included', () => {
        const fixture = '<div><button className="kr-booking__button kr-booking__other" disabled={busy}>Go</button><button className="kr-booking__confirm-row" disabled>No</button></div>';

        expect(unnamedInSource(elementsOf('fixture', fixture))).toHaveLength(2);
    });

    it('finds no disabled attribute on an element the table does not name', () => {
        expect(unnamedInSource(componentElements())).toEqual([]);
    });

    it('finds every control either table names disabled somewhere in the source, so no row is stale', () => {
        const disabled = componentElements().filter(disables).flatMap(classesOf);

        expect([...NAMED, ...UNAVAILABLE_NAMES, STRIP, CARD].filter((name) => !disabled.includes(name))).toEqual([]);
    });

    it('finds the five choices of 7.5 in the source and only the day and the service card carrying disabled', () => {
        const elements = componentElements();
        const present = CHOICES.filter((name) => elements.some((element) => classesOf(element).includes(name)));
        const disabled = CHOICES.filter((name) => elements.some((element) => disables(element) && classesOf(element).includes(name)));

        expect(present).toEqual(CHOICES);
        expect(disabled).toEqual([CARD, ...UNAVAILABLE_NAMES]);
    });
});
