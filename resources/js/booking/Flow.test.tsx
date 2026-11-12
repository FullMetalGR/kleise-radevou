import { QueryClientProvider, type QueryClient } from '@tanstack/react-query';
import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { englishLeft, ENGLISH_WORDS, GREEK_WORDS, keyed, KEYED_WORDS, said, saidBy } from '../bookingWords';
import { createQueryClient, TRANSPORT_SENTENCE, type Api } from './api';
import { AVAILABILITY_PATH } from './availability';
import { CATALOG_KEY } from './catalog';
import type { MountOptions } from './attributes';
import { confirmPath } from './confirm';
import { BOOKINGS_PATH, bookingKey, bookingPath } from './manage';
import type { BootConfig, Fetch } from './config';
import type { Language } from './language';
import { BUSY_LABEL } from './DetailsStep';
import { headingFor, sentenceFor } from './DoneStep';
import { formatDuration } from './duration';
import { ANY_PROFESSIONAL_LINE, CONTINUE_LABEL, ESTIMATE_LABEL, PICK_A_SERVICE, PICK_A_TIME_LABEL, REMOVE_LABEL, WITH_STAFF_LINE } from './Estimate';
import { Flow, STEP_TITLES } from './Flow';
import { EXPIRED_MILESTONE, NEAR_MILESTONE } from './countdown';
import { releasePath } from './release';
import { CONFIRM_LABEL, CONFIRMING_LABEL, PAYMENT_LABEL, RESTART_LABEL } from './ReviewStep';
import { LOADING_SERVICES_SENTENCE, REMOVED_SENTENCE } from './ServiceStep';
import { ANY_PROFESSIONAL, MAXIMUM_AVAILABILITY } from './StaffStep';
import { BACK_LABEL } from './Strip';
import { WITH_STAFF } from './Summary';
import { NEXT_AVAILABLE_LABEL, NEXT_WEEK_LABEL, PERIOD_NAMES, PREVIOUS_WEEK_LABEL, STALE_TIMES_SENTENCE, TIMES_COUNT } from './WhenStep';
import { afterService, anySegmentListsStaff, backTargetOf, HANDED_OFF_SENTENCE, initialDraft, initialFlowState, initialSegments, initialStep, preselectionVoid, reduce, restartOffered, underReview, type FlowEvent, type FlowState, type Step } from './flow';
import { EMPTY_DRAFT, HOLDS_PATH } from './hold';
import { formatMoney } from './money';
import { formatMoment, wallClockOf } from './time';
import type { Words } from './words';
import { BOOKING_LINES_EN } from './lines.generated';

/*
 * The state machine on the flow component: the initial step from the mount attributes, the
 * forward transitions, the auto-skip, Back, the step echoed on the container, and focus
 * following the customer and never the machine. The transition tables are also held as tables
 * on the pure functions. The flow renders with the keyed table, so every sentence of its own is
 * asserted by its key, and once with the English file, its values read from the generated
 * module.
 */

const CONFIG: BootConfig = { tenantName: 'Luna Hair Studio', timezone: 'Europe/Athens', currency: 'EUR', granularityMin: 5, importantInfo: null, defaultLanguage: 'en', language: 'en' };
const HAIRCUT = { id: 1, name: 'Haircut', description: '', duration_min: 45, price_minor: 3500, currency: 'EUR', staff: [{ id: 2, name: 'Elena' }, { id: 3, name: 'Nikos' }] };
const BEARD = { id: 4, name: 'Beard trim', description: '', duration_min: 20, price_minor: 1500, currency: 'EUR', staff: [{ id: 3, name: 'Nikos' }] };
const SPA = { id: 5, name: 'Head spa', description: '', duration_min: 30, price_minor: 5000, currency: 'JPY', staff: [] };
const WIRE = { services: [HAIRCUT, BEARD, SPA], max_chain_length: 5 };
const PARSED = { services: [{ id: 1, name: 'Haircut', description: '', durationMin: 45, priceMinor: 3500, currency: 'EUR', category: null, staff: [{ id: 2, name: 'Elena', photoUrl: null, title: null, bio: null }, { id: 3, name: 'Nikos', photoUrl: null, title: null, bio: null }] }, { id: 5, name: 'Head spa', description: '', durationMin: 30, priceMinor: 5000, currency: 'JPY', category: null, staff: [] }], maxChainLength: 5 };

function options(overrides: Partial<MountOptions> = {}): MountOptions {
    return { mode: 'book', serviceId: null, staffId: null, uuid: null, token: null, channel: null, ...overrides };
}

function answering(body: unknown, status = 200): Fetch {
    return () => Promise.resolve(new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }));
}

/** The first staff choice's whole text: its name and the line under it. */
const ANY = `${said(ANY_PROFESSIONAL)} ${said(MAXIMUM_AVAILABILITY)}`;

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
    vi.restoreAllMocks();
});

/**
 * Lets the query client's deferred notifications and every effect they trigger land.
 */
async function settle(): Promise<void> {
    await act(async () => {
        for (let turn = 0; turn < 4; turn += 1) {
            await new Promise<void>((resolve) => setTimeout(resolve, 0));
        }
    });
}

// 07:32 UTC on Monday 2026-04-06 is 10:32 in Athens, so the tenant's today is the 6th.
const NOW = Date.UTC(2026, 3, 6, 7, 32);

const TIMES = {
    '2026-04-06': ['2026-04-06T07:35:00+00:00', '2026-04-06T07:40:00+00:00'],
    '2026-04-07': [],
    '2026-04-08': ['2026-04-08T06:00:00+00:00'],
};

let client: QueryClient;
let availabilityRequests: string[];
let holdRequests: string[];
let requestedUrls: string[];
let sentUrls: string[];

/**
 * The language the next mount's api holds, as the shell hands it once the config names
 * one; none unless a case sets it, so every other case's requests carry no lang.
 */
let mountLanguage: Language | undefined;

beforeEach(() => {
    mountLanguage = undefined;
    mountWords = KEYED_WORDS;
});

/** The words the next mount renders with: the keyed table unless a case walks the Greek. */
let mountWords: Words;
let holdAnswer: () => Promise<Response>;
let catalogWire: unknown;
let confirmRequests: string[];
let confirmAnswer: () => Promise<Response>;
let confirmWithheld: boolean;
let releaseConfirm: () => void;
let availabilityFails: boolean;
let availabilityWithheld: boolean;
let releaseAvailability: () => void;
let releaseRequests: string[];
let releaseFails: boolean;
let navigations: string[];
let readRequests: string[];
let readAnswer: () => Promise<Response>;
let readWithheld: boolean;
let releaseRead: () => void;

/**
 * What the document says about its own visibility. jsdom's is fixed at visible, so the tests
 * that hide the tab read this through a getter installed once.
 */
let visibility: DocumentVisibilityState = 'visible';

Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => visibility });

beforeEach(() => {
    visibility = 'visible';
});

/**
 * The browser clock the flow is handed. NOW unless a test moves it, which only the countdown
 * and "Pick another time" ever read after the mount.
 */
let clock: number;

beforeEach(() => {
    clock = NOW;
});

/**
 * The booking the server answers a hold with. Deliberately not what the local chain says: the
 * service is named differently, the currency is not the catalog row's and the total is not the
 * sum the app could have computed, so a summary read off local state fails visibly.
 */
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
    items: [
        { sort: 0, service_id: 5, service_name: 'Head spa, as sold', staff_id: 9, staff_name: 'Aiko', start: '2026-04-06T07:40:00+00:00', end: '2026-04-06T08:10:00+00:00', price_minor: 4200 },
    ],
    manage_token: 'f'.repeat(64),
};

/**
 * The booking the server answers a confirm with: the same guest view, settled, with no hold
 * left on it and no token beside it.
 */
const CONFIRMED = { ...HELD, status: 'confirmed', hold_class: null, hold_expires_at: null, manage_token: undefined };

const CONFIRM_PATH = confirmPath(HELD.uuid);

/**
 * The booking as the recovery's re-read answers it: the guest view, still pending, with no token
 * beside it. What a refused confirm finds unless a test says otherwise.
 */
const GUEST_PENDING = { ...HELD, manage_token: undefined };

const READ_PATH = bookingPath(HELD.uuid, HELD.manage_token);

const NOT_CONFIRMABLE = { code: 'not_confirmable', message: 'not_confirmable', data: { status: 409, detail: 'This booking can no longer be confirmed.' } };

const EXPIRED = { code: 'hold_expired', message: 'hold_expired', data: { status: 410, detail: 'The time we were holding for you has been released.' } };

const BROKEN = { code: 'server_error', message: 'server_error', data: { status: 500, detail: 'Something went wrong at our end.' } };

const CONFLICT = { code: 'overlap', message: 'overlap', data: { status: 409, detail: 'That time was just taken. Please pick another.', segment: 0 } };

const REFUSAL = { code: 'invalid_request', message: 'invalid_request', data: { status: 400, detail: 'That email address does not look like an email address.' } };

const LIMITED = { code: 'rate_limited', message: 'rate_limited', data: { status: 429, detail: 'Too many requests. Please wait a moment.' } };

/**
 * A fetch answering the catalog on the services path, the given body on the availability path
 * and whatever the test set on the holds path, recording every request.
 */
function routing(availability: unknown = TIMES, status = 200): Fetch {
    return (input, init) => {
        // The language a mount's api holds rides last on every request, and is matched apart.
        const path = input.replace('https://luna.kleiseradevou.gr', '').replace(/[?&]lang=(?:en|el)$/, '');

        sentUrls.push(input);
        requestedUrls.push(path);

        if (init.method === 'DELETE') {
            releaseRequests.push(path);

            return releaseFails ? Promise.reject(new TypeError('Failed to fetch')) : Promise.resolve(new Response(null, { status: 204 }));
        }

        if (path === HOLDS_PATH) {
            holdRequests.push(String(init.body));

            return holdAnswer();
        }

        if (path.startsWith(`${BOOKINGS_PATH}/${HELD.uuid}?`)) {
            readRequests.push(path);

            // Withheld when the test says so, so the recovery can be seen in flight.
            return answerOrWithhold(readWithheld, () => readAnswer(), (release) => {
                releaseRead = release;
            });
        }

        if (path === CONFIRM_PATH) {
            confirmRequests.push(String(init.body));

            // Withheld when the test says so, so a second press can be made while the first
            // request is still out and disabled has not reached the DOM.
            return answerOrWithhold(confirmWithheld, () => confirmAnswer(), (release) => {
                releaseConfirm = release;
            });
        }

        if (path.startsWith(AVAILABILITY_PATH)) {
            availabilityRequests.push(path);

            // A 4xx, so the retry predicate lets the failure stand at once.
            const answer = (): Promise<Response> => (availabilityFails ? answering(LIMITED, 429)(input, {}) : answering(availability, status)(input, {}));

            if (!availabilityWithheld) {
                return answer();
            }

            // Withheld until the test releases it, so a refetch can be observed in flight.
            return new Promise<Response>((resolve) => {
                releaseAvailability = () => resolve(answer());
            });
        }

        return answering(catalogWire)(input, {});
    };
}

/**
 * The answer at once, or, when withheld, a request left out until the test calls the release it
 * is handed. The answer is asked for when it is given, so a test can change it while it waits.
 */
function answerOrWithhold(withheld: boolean, answer: () => Promise<Response>, hand: (release: () => void) => void): Promise<Response> {
    if (!withheld) {
        return answer();
    }

    return new Promise<Response>((resolve) => {
        hand(() => resolve(answer()));
    });
}

/**
 * Mounts the flow on fresh wire state. A case that must hold back a request the mount itself
 * sets off arms it in `arm`, which runs after the reset and before the render, so the request is
 * withheld however quickly the mount's own effects send it.
 */
async function mount(mountOptions: MountOptions, fetchFn: Fetch = routing(), config: BootConfig = CONFIG, arm: () => void = () => undefined): Promise<void> {
    const api: Api = { origin: 'https://luna.kleiseradevou.gr', fetch: fetchFn, language: mountLanguage };

    client = createQueryClient();
    availabilityRequests = [];
    holdRequests = [];
    requestedUrls = [];
    sentUrls = [];
    holdAnswer = () => answering(HELD, 201)('', {});
    catalogWire = WIRE;
    confirmRequests = [];
    confirmAnswer = () => answering(CONFIRMED)('', {});
    confirmWithheld = false;
    releaseConfirm = () => undefined;
    availabilityFails = false;
    availabilityWithheld = false;
    releaseAvailability = () => undefined;
    releaseRequests = [];
    releaseFails = false;
    navigations = [];
    readRequests = [];
    readAnswer = () => answering(GUEST_PENDING)('', {});
    readWithheld = false;
    releaseRead = () => undefined;
    arm();

    await act(() => {
        root.render(
            keyed(
                <QueryClientProvider client={client}>
                    <Flow config={config} options={mountOptions} api={api} browser={{ now: () => clock, navigate: (url) => navigations.push(url) }} />
                </QueryClientProvider>,
                mountWords,
            ),
        );
    });
}

/**
 * The cache entries for one chain, whatever the window: the key is matched on its prefix.
 */
/**
 * A fetch that withholds the catalog until released, answering everything else at once, for
 * the tests that must see the flow before the catalog has answered.
 */
function holdingCatalog(): { readonly fetch: Fetch; readonly release: () => void } {
    let release: () => void = () => undefined;
    const fetch: Fetch = (input, init) => {
        if (input.endsWith('/api/v1/services')) {
            return new Promise((resolve) => {
                release = () => resolve(answering(WIRE)(input, init));
            });
        }

        return routing()(input, init);
    };

    return { fetch, release: () => release() };
}

function availabilityEntries(items: readonly { service_id: number; staff_id: number | null }[]) {
    return client.getQueryCache().findAll({ queryKey: ['availability', items] });
}

/**
 * A fetch answering each availability window by its first day, the empty answer for a window
 * not named, and everything else as routing() does.
 */
function byWindow(answers: Readonly<Record<string, unknown>>): Fetch {
    return (input, init) => {
        const path = input.replace('https://luna.kleiseradevou.gr', '');
        const from = new URLSearchParams(path.split('?')[1] ?? '').get('from') ?? '';

        return routing(path.startsWith(AVAILABILITY_PATH) ? (answers[from] ?? {}) : TIMES)(input, init);
    };
}

/** An empty first week whose answer names the 22nd, two windows on, and that week holding it. */
const EMPTY_FIRST_WEEK = {
    '2026-04-06': { '2026-04-06': [], '2026-04-07': [], '2026-04-08': [], next_available_from: '2026-04-22' },
    '2026-04-20': { '2026-04-20': [], '2026-04-21': [], '2026-04-22': ['2026-04-22T06:00:00+00:00', '2026-04-22T07:00:00+00:00'] },
};

function jumpButton(): HTMLButtonElement | null {
    return container.querySelector<HTMLButtonElement>('.kr-booking__jump');
}

function days(): HTMLButtonElement[] {
    return Array.from(container.querySelectorAll<HTMLButtonElement>('.kr-booking__day'));
}

function slots(): HTMLButtonElement[] {
    return Array.from(container.querySelectorAll<HTMLButtonElement>('.kr-booking__slot'));
}

function frame(): HTMLElement {
    return container.querySelector('.kr-booking__frame') as HTMLElement;
}

function step(): string | null {
    return frame().getAttribute('data-kr-step');
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

/**
 * The review's action button on the confirm branch, found by its class: its label is the busy
 * label while a confirm or its recovery is out, so a lookup by label would miss it exactly then.
 */
/** The crumb's round back control, named by its aria-label, or null where there is none. */
function backButton(): HTMLButtonElement | null {
    return container.querySelector<HTMLButtonElement>('.kr-booking__back');
}

/** The summary card's one Continue, found by its class, since its label is the step's. */
function proceedButton(): HTMLButtonElement {
    const found = container.querySelector<HTMLButtonElement>('.kr-booking__continue');

    if (found === null) {
        throw new Error('No summary card Continue.');
    }

    return found;
}

function confirmButton(): HTMLButtonElement {
    const found = container.querySelector<HTMLButtonElement>('.kr-booking__confirm');

    if (found === null) {
        throw new Error('No confirm button.');
    }

    return found;
}

function serviceRow(name: string): HTMLButtonElement {
    const found = Array.from(container.querySelectorAll<HTMLButtonElement>('.kr-booking__service')).find((row) => row.querySelector('.kr-booking__service-name')?.textContent === name);

    if (found === undefined) {
        throw new Error(`No service row named "${name}".`);
    }

    return found;
}

async function press(el: HTMLElement): Promise<void> {
    await act(() => {
        el.focus();
        el.click();
    });
}

function field(name: string): HTMLInputElement {
    return container.querySelector(`input[name="${name}"]`) as HTMLInputElement;
}

/**
 * The step's polite region, which on review is the notice region and never the countdown's
 * milestone region above it.
 */
function politeText(): string {
    return Array.from(container.querySelectorAll('[role="status"]')).find((region) => region.closest('.kr-booking__countdown') === null)?.textContent ?? '';
}

/** The service step's removal announcement, the polite region that is the step's own last child. */
function announced(): string {
    const regions = Array.from(container.querySelectorAll('.kr-booking__step > [role="status"]'));

    return regions[regions.length - 1]?.textContent ?? '';
}

function politeSpan(): Element | null {
    return container.querySelector('[role="status"] span');
}

/**
 * A keystroke as the browser delivers one, past the value tracker React installs on the node.
 */
async function typeInto(name: string, value: string): Promise<void> {
    const input = field(name);

    await act(() => {
        Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')?.set?.call(input, value);
        input.dispatchEvent(new Event('input', { bubbles: true }));
    });
}

/**
 * A keystroke into the note, the details step's one textarea, as typeInto delivers one to an input.
 */
async function typeNote(value: string): Promise<void> {
    const note = container.querySelector('textarea[name="note"]') as HTMLTextAreaElement;

    await act(() => {
        Object.getOwnPropertyDescriptor(HTMLTextAreaElement.prototype, 'value')?.set?.call(note, value);
        note.dispatchEvent(new Event('input', { bubbles: true }));
    });
}

/**
 * The journey as far as the details form: a chain of one service that lists nobody, a day, a
 * time, and the customer typed in.
 */
async function reachDetails(draft: { name: string; email: string; phone?: string } = { name: 'Casey Customer', email: 'casey@example.com' }): Promise<void> {
    await mount(options({ serviceId: 5 }));
    await settle();
    await press(days()[0] as HTMLElement);
    await press(slots()[1] as HTMLElement);
    await typeInto('customer_name', draft.name);
    await typeInto('customer_email', draft.email);

    if (draft.phone !== undefined) {
        await typeInto('customer_phone', draft.phone);
    }
}

describe('the tables', () => {
    it.each<[number | null, number | null, Step]>([
        [null, null, 'service'],
        [null, 4, 'service'],
        [12, null, 'staff'],
        [12, 4, 'when'],
    ])('starts at %j / %j on %s', (serviceId, staffId, expected) => {
        expect(initialStep(options({ serviceId, staffId }))).toBe(expected);
    });

    it('seeds the preselected pairing unvalidated, and nothing without a service', () => {
        expect(initialSegments(options())).toEqual([]);
        expect(initialSegments(options({ staffId: 4 }))).toEqual([]);
        expect(initialSegments(options({ serviceId: 12 }))).toEqual([{ serviceId: 12, staffId: null }]);
        expect(initialSegments(options({ serviceId: 12, staffId: 4 }))).toEqual([{ serviceId: 12, staffId: 4 }]);
    });

    it('sends Continue on service to staff, or to when once the catalog answered and no segment lists staff', () => {
        expect(afterService([{ serviceId: 1, staffId: null }], PARSED)).toBe('staff');
        expect(afterService([{ serviceId: 5, staffId: null }], PARSED)).toBe('when');
        expect(afterService([{ serviceId: 5, staffId: null }, { serviceId: 1, staffId: null }], PARSED)).toBe('staff');
        expect(afterService([{ serviceId: 99, staffId: null }], PARSED)).toBe('when');
        expect(afterService([{ serviceId: 5, staffId: null }], undefined)).toBe('staff');
        expect(anySegmentListsStaff([{ serviceId: 1, staffId: null }], undefined)).toBe(false);
    });

    it.each<[Step, boolean, Step, Step | null]>([
        ['service', false, 'service', null],
        ['service', true, 'service', null],
        ['staff', false, 'service', 'service'],
        ['staff', true, 'service', 'service'],
        ['staff', false, 'staff', null],
        ['when', true, 'service', 'staff'],
        ['when', true, 'staff', 'staff'],
        ['when', true, 'when', null],
        ['when', false, 'service', 'service'],
        ['when', false, 'staff', null],
        ['when', false, 'when', null],
        ['details', false, 'service', 'when'],
        ['details', true, 'when', 'when'],
        ['review', true, 'service', null],
        ['done', true, 'service', null],
    ])('Back from %s (a segment lists staff: %s, the mount started at %s) targets %j', (from, listsStaff, initial, expected) => {
        const segments = [{ serviceId: listsStaff ? 1 : 5, staffId: null }];

        expect(backTargetOf(from, segments, PARSED, initial)).toBe(expected);
    });

    it('voids a mount naming a service no catalog row has, and only that', () => {
        expect(preselectionVoid(options(), PARSED)).toBe(false);
        expect(preselectionVoid(options({ staffId: 3 }), PARSED)).toBe(false);
        expect(preselectionVoid(options({ serviceId: 1 }), PARSED)).toBe(false);
        expect(preselectionVoid(options({ serviceId: 1, staffId: 9 }), PARSED)).toBe(false);
        expect(preselectionVoid(options({ serviceId: 99 }), PARSED)).toBe(true);
        expect(preselectionVoid(options({ serviceId: 99, staffId: 3 }), PARSED)).toBe(true);
    });

    it('answers no Back from when before the catalog has answered, unless the mount started at service', () => {
        expect(backTargetOf('when', [{ serviceId: 1, staffId: null }], undefined, 'service')).toBe('service');
        expect(backTargetOf('when', [{ serviceId: 1, staffId: null }], undefined, 'staff')).toBeNull();
    });
});

describe('what resets and what never does', () => {
    const journey: FlowState = {
        step: 'when',
        segments: [{ serviceId: 1, staffId: 3 }, { serviceId: 5, staffId: null }],
        window: 1,
        selectedDay: '2026-04-21',
        chosenStart: null,
        conflict: { text: 'That time was taken a moment ago.', tone: 'polite' },
        detailsNotice: { text: 'Please give an email address.', tone: 'polite' },
        draft: { name: 'Casey Customer', email: 'casey@example.com', phone: '', note: '' },
        booking: null,
        reviewNotice: null,
        expired: false,
        recovering: false,
        start: 'staff',
        carried: null,
    };

    const pendingBooking = { uuid: 'u', status: 'pending', holdClass: 'checkout', holdExpiresAt: null, customerName: 'Casey', customerEmail: 'casey@example.com', customerPhone: null, totalMinor: 1, currency: 'EUR', items: [] };

    it('starts from the mount attributes alone, with nothing chosen and nothing to say', () => {
        expect(initialFlowState(options({ serviceId: 12, staffId: 4 }))).toEqual({ step: 'when', segments: [{ serviceId: 12, staffId: 4 }], window: 0, selectedDay: null, chosenStart: null, conflict: null, detailsNotice: null, draft: EMPTY_DRAFT, booking: null, reviewNotice: null, expired: false, recovering: false, start: 'when', carried: null });
        expect(initialFlowState(options())).toEqual({ step: 'service', segments: [], window: 0, selectedDay: null, chosenStart: null, conflict: null, detailsNotice: null, draft: EMPTY_DRAFT, booking: null, reviewNotice: null, expired: false, recovering: false, start: 'service', carried: null });
        // A link naming a member alone, ?staff= on the hosted page: the member rides along as a
        // voided mount's does, onto a picked service that lists them.
        expect(initialFlowState(options({ staffId: 4 }))).toEqual({ ...initialFlowState(options()), carried: 4 });
    });

    it('seeds the draft once from the hosted page\'s prefill, the note empty and a null phone as nothing typed, and from nothing without one', () => {
        const prefill = { name: 'Casey Customer', email: 'casey@example.com', phone: '+30 210 0000000' };

        expect(initialFlowState(options(), prefill)).toEqual({ ...initialFlowState(options()), draft: { name: 'Casey Customer', email: 'casey@example.com', phone: '+30 210 0000000', note: '' } });
        expect(initialFlowState(options(), { ...prefill, phone: null }).draft).toEqual({ name: 'Casey Customer', email: 'casey@example.com', phone: '', note: '' });
        expect(initialFlowState(options(), null).draft).toBe(EMPTY_DRAFT);
        expect(initialDraft(null)).toBe(EMPTY_DRAFT);
    });

    it('picking a slot keeps the wire value whole, clears the conflict and details notices, moves to details, and resets nothing else', () => {
        const start = '2026-04-21T07:35:00+00:00';
        const after = reduce(journey, { type: 'pick', start });

        expect(after).toEqual({ ...journey, step: 'details', chosenStart: start, conflict: null, detailsNotice: null });
        expect(after.chosenStart).toBe(start);
        expect(after.segments).toBe(journey.segments);
    });

    it('a move between steps clears nothing: the chain, the selected day, the window, the chosen start and both notices survive', () => {
        const held = { ...journey, chosenStart: '2026-04-21T07:35:00+00:00' };

        expect(reduce(held, { type: 'go', step: 'staff' })).toEqual({ ...held, step: 'staff' });
        expect(reduce(reduce(held, { type: 'go', step: 'staff' }), { type: 'go', step: 'when' })).toEqual(held);
    });

    it('selecting a day and moving the window touch only those, and the window never goes before today', () => {
        expect(reduce(journey, { type: 'select-day', day: '2026-04-22' })).toEqual({ ...journey, selectedDay: '2026-04-22' });
        expect(reduce(journey, { type: 'window', index: 2 })).toEqual({ ...journey, window: 2 });
        expect(reduce(journey, { type: 'window', index: 0 })).toEqual({ ...journey, window: 0 });
        expect(reduce(journey, { type: 'window', index: -1 })).toEqual({ ...journey, window: 0 });
    });

    it('a jump to a day moves the window and the selected day together and touches nothing else, the window never before today', () => {
        expect(reduce(journey, { type: 'jump', index: 3, day: '2026-04-29' })).toEqual({ ...journey, window: 3, selectedDay: '2026-04-29' });
        expect(reduce(journey, { type: 'jump', index: 1, day: '2026-04-16' })).toEqual({ ...journey, selectedDay: '2026-04-16' });
        expect(reduce(journey, { type: 'jump', index: -1, day: '2026-04-06' })).toEqual({ ...journey, window: 0, selectedDay: '2026-04-06' });
    });

    it('a hold 409 clears the chosen slot and replaces the conflict, and keeps the chain, the day and the draft', () => {
        const held = { ...journey, step: 'details' as const, chosenStart: '2026-04-21T07:35:00+00:00' };
        const taken = { text: 'That time was just taken. Please pick another.', tone: 'polite' } as const;
        const after = reduce(held, { type: 'conflict', notice: taken });

        expect(after).toEqual({ ...held, step: 'when', chosenStart: null, conflict: taken });
        expect(after.draft).toBe(journey.draft);
        expect(after.segments).toBe(journey.segments);
        expect(after.selectedDay).toBe(journey.selectedDay);
    });

    it('routes the answer by the returned status alone: pending to review, anything else to done', () => {
        const booking = pendingBooking;
        const pending = reduce(journey, { type: 'held', booking });

        expect(pending.step).toBe('review');
        expect(pending.booking).toBe(booking);
        expect(reduce(journey, { type: 'held', booking: { ...booking, status: 'awaiting_approval' } }).step).toBe('done');
        expect(reduce(journey, { type: 'held', booking: { ...booking, status: 'confirmed' } }).step).toBe('done');
        expect(reduce(journey, { type: 'held', booking: { ...booking, status: 'awaiting_payment' } }).step).toBe('done');
    });

    it('a pending hold clears the expiry flag, because that hold is a new one', () => {
        expect(reduce({ ...journey, expired: true }, { type: 'held', booking: pendingBooking }).expired).toBe(false);
    });

    it('a confirmed booking is the outcome: done reads the status the server returned', () => {
        const reviewing = { ...journey, step: 'review' as const, booking: pendingBooking };
        const settled = { ...pendingBooking, status: 'confirmed', holdClass: null };
        const after = reduce(reviewing, { type: 'confirmed', booking: settled });

        expect(after).toEqual({ ...reviewing, step: 'done', booking: settled });
        expect(after.booking).toBe(settled);
    });

    it('a hold paid at the venue keeps saying so on the booking its confirm settled, which the confirm\'s answer does not carry', () => {
        const reviewing = { ...journey, step: 'review' as const, booking: { ...pendingBooking, paysAtVenue: true as const } };
        const settled = { ...pendingBooking, status: 'confirmed', holdClass: null };

        expect(reduce(reviewing, { type: 'confirmed', booking: settled }).booking).toEqual({ ...settled, paysAtVenue: true });
        expect(reduce({ ...reviewing, booking: pendingBooking }, { type: 'confirmed', booking: settled }).booking).toBe(settled);
    });

    it('a refused confirm leaves every other thing standing, and only a refusal that says so raises the expiry flag', () => {
        const reviewing = { ...journey, step: 'review' as const, booking: pendingBooking };
        const notice = { text: 'This booking can no longer be confirmed.', tone: 'polite' as const };
        const refused = reduce(reviewing, { type: 'confirm-refused', notice, expired: false });

        expect(refused).toEqual({ ...reviewing, reviewNotice: notice });
        expect(refused.booking).toBe(pendingBooking);
        expect(reduce(reviewing, { type: 'confirm-refused', notice, expired: true }).expired).toBe(true);
        expect(reduce({ ...reviewing, expired: true }, { type: 'confirm-refused', notice, expired: false }).expired).toBe(true);
    });

    it('the countdown reaching zero raises the expiry flag a 410 raises, and touches nothing else', () => {
        const reviewing = { ...journey, step: 'review' as const, booking: pendingBooking };

        expect(reduce(reviewing, { type: 'expire' })).toEqual({ ...reviewing, expired: true });
    });

    it('Pick another time clears the held booking, the expiry flag, the chosen slot and the review notice, lands on when, and resets nothing else', () => {
        const reviewing: FlowState = {
            ...journey,
            step: 'review',
            chosenStart: '2026-04-21T07:35:00+00:00',
            booking: pendingBooking,
            reviewNotice: { text: 'The time we were holding for you has been released.', tone: 'polite' },
            expired: true,
            recovering: true,
        };
        const after = reduce(reviewing, { type: 'restart' });

        expect(after).toEqual({ ...reviewing, step: 'when', booking: null, expired: false, recovering: false, chosenStart: null, reviewNotice: null });
        expect(after.draft).toBe(journey.draft);
        expect(after.segments).toBe(journey.segments);
        expect(after.conflict).toBe(journey.conflict);
        expect(after.detailsNotice).toBe(journey.detailsNotice);
    });

    it('offers Pick another time once the hold has expired, a notice exists or a recovery is in flight, and not otherwise', () => {
        const notice = { text: 'This booking can no longer be confirmed.', tone: 'polite' as const };

        expect(restartOffered({ expired: false, reviewNotice: null, recovering: false })).toBe(false);
        expect(restartOffered({ expired: true, reviewNotice: null, recovering: false })).toBe(true);
        expect(restartOffered({ expired: false, reviewNotice: notice, recovering: false })).toBe(true);
        expect(restartOffered({ expired: true, reviewNotice: notice, recovering: false })).toBe(true);
        expect(restartOffered({ expired: false, reviewNotice: null, recovering: true })).toBe(true);
    });

    it('a refused confirm starts a recovery and touches nothing else; its end, either way, clears the flag', () => {
        const reviewing = { ...journey, step: 'review' as const, booking: pendingBooking };
        const recovering = reduce(reviewing, { type: 'recover', expired: false });
        const notice = { text: 'This booking can no longer be confirmed.', tone: 'polite' as const };
        const settled = { ...pendingBooking, status: 'awaiting_approval' };

        expect(recovering).toEqual({ ...reviewing, recovering: true });
        expect(reduce(recovering, { type: 'confirm-refused', notice, expired: false })).toEqual({ ...reviewing, reviewNotice: notice });
        expect(reduce(recovering, { type: 'confirmed', booking: settled })).toEqual({ ...reviewing, step: 'done', booking: settled });
    });

    it('a 410 raises the expiry flag as the recovery starts, not when it ends, and nothing lowers it on the way', () => {
        const reviewing = { ...journey, step: 'review' as const, booking: pendingBooking };
        const recovering = reduce(reviewing, { type: 'recover', expired: true });
        const notice = { text: 'The time we were holding for you has been released.', tone: 'polite' as const };

        expect(recovering).toEqual({ ...reviewing, recovering: true, expired: true });
        expect(reduce({ ...reviewing, expired: true }, { type: 'recover', expired: false }).expired).toBe(true);
        expect(reduce(recovering, { type: 'confirm-refused', notice, expired: true })).toEqual({ ...reviewing, reviewNotice: notice, expired: true });
    });

    it('a hand-off says why the button stays disabled, which is a notice, so the way out is offered beside it', () => {
        const reviewing = { ...journey, step: 'review' as const, booking: pendingBooking };
        const after = reduce(reviewing, { type: 'handed-off' });

        expect(after).toEqual({ ...reviewing, reviewNotice: { key: HANDED_OFF_SENTENCE, tone: 'polite' } });
        expect(restartOffered(after)).toBe(true);
    });

    it('keeps under review the hold\'s identifier and deadline and nothing more, so no checkout url, which carries the token, is held by it', () => {
        const paying = { ...pendingBooking, holdExpiresAt: '2026-04-06T08:15:00+00:00', checkoutUrl: 'https://pay.example.com/session/abc?token=secret' };

        expect(underReview({ ...journey, step: 'review', booking: paying })).toEqual({ uuid: 'u', holdExpiresAt: '2026-04-06T08:15:00+00:00' });
        expect(JSON.stringify(underReview({ ...journey, step: 'review', booking: paying }))).not.toContain('pay.example.com');
        expect(underReview({ ...journey, step: 'done', booking: paying })).toBeNull();
        expect(underReview({ ...journey, step: 'review', booking: null })).toBeNull();
    });

    it('writes one draft field at a time and touches nothing else', () => {
        const typed = reduce(reduce(reduce(initialFlowState(options()), { type: 'draft', field: 'name', value: 'Casey' }), { type: 'draft', field: 'email', value: 'casey@example.com' }), { type: 'draft', field: 'phone', value: '' });
        const noted = reduce(typed, { type: 'draft', field: 'note', value: 'Allergic to lavender.' });

        expect(typed.draft).toEqual({ name: 'Casey', email: 'casey@example.com', phone: '', note: '' });
        expect(noted).toEqual({ ...typed, draft: { ...typed.draft, note: 'Allergic to lavender.' } });
        expect(reduce(journey, { type: 'refused', notice: { text: 'A name is required.', tone: 'polite' } })).toEqual({ ...journey, detailsNotice: { text: 'A name is required.', tone: 'polite' } });
    });

    it('builds the chain by index: append, remove and choose touch only the segments', () => {
        const empty = initialFlowState(options());
        const one = reduce(empty, { type: 'append', service: { id: 1, staff: [] } });
        const two = reduce(one, { type: 'append', service: { id: 1, staff: [] } });
        const chosen = reduce(two, { type: 'choose', index: 1, staffId: 3 });

        expect(one.segments).toEqual([{ serviceId: 1, staffId: null }]);
        expect(chosen.segments).toEqual([{ serviceId: 1, staffId: null }, { serviceId: 1, staffId: 3 }]);
        expect(reduce(chosen, { type: 'remove', index: 0 })).toEqual({ ...chosen, segments: [{ serviceId: 1, staffId: 3 }] });
        expect(reduce(chosen, { type: 'choose', index: 1, staffId: null }).segments).toEqual(two.segments);
    });

    it('a dead preselection clears the segments, starts the flow over at service as a bare mount would, and resets nothing else', () => {
        const seeded = { ...initialFlowState(options({ serviceId: 99, staffId: 3 })), window: 1, draft: { name: 'Casey', email: '', phone: '', note: '' } };
        const after = reduce(seeded, { type: 'void', staffId: 3 });

        expect(after).toEqual({ ...seeded, step: 'service', segments: [], start: 'service', carried: 3 });
        expect(after.draft).toBe(seeded.draft);
    });

    it('carries the member a void left riding along onto a newly picked service only if that service lists them', () => {
        const voided = reduce(initialFlowState(options({ serviceId: 99, staffId: 3 })), { type: 'void', staffId: 3 });
        const listing = { id: 1, staff: [{ id: 2, name: 'Elena', photoUrl: null, title: null, bio: null }, { id: 3, name: 'Nikos', photoUrl: null, title: null, bio: null }] };
        const notListing = { id: 7, staff: [{ id: 2, name: 'Elena', photoUrl: null, title: null, bio: null }] };
        const nobody = { id: 5, staff: [] };
        const chain = reduce(reduce(reduce(voided, { type: 'append', service: listing }), { type: 'append', service: notListing }), { type: 'append', service: nobody });

        expect(chain.segments).toEqual([{ serviceId: 1, staffId: 3 }, { serviceId: 7, staffId: null }, { serviceId: 5, staffId: null }]);
        // A mount naming the member alone, a staff link's ?staff=, carries them the same
        // way, where before it carried nobody.
        expect(reduce(initialFlowState(options({ staffId: 3 })), { type: 'append', service: listing }).segments).toEqual([{ serviceId: 1, staffId: 3 }]);
        expect(reduce(initialFlowState(options({ staffId: 3 })), { type: 'append', service: notListing }).segments).toEqual([{ serviceId: 7, staffId: null }]);
    });
});

describe('the flow container', () => {
    it('reads the English file\'s title and loading line before the catalog has answered', async () => {
        await act(() => {
            root.render(
                keyed(
                    <QueryClientProvider client={createQueryClient()}>
                        <Flow config={CONFIG} options={options()} api={{ origin: 'https://luna.kleiseradevou.gr', fetch: () => new Promise<Response>(() => undefined) }} />
                    </QueryClientProvider>,
                    ENGLISH_WORDS,
                ),
            );
        });

        expect(container.querySelector('.kr-booking__title')?.textContent).toBe(BOOKING_LINES_EN.titles.service);
        expect(container.textContent).toContain(BOOKING_LINES_EN.service.loading);
        expect(container.querySelector('.kr-booking__continue')?.textContent).toBe(BOOKING_LINES_EN.estimate.continue);
    });

    it('carries the step beside the mode and the state, starting at service for a bare mount', async () => {
        await mount(options());

        expect(frame().getAttribute('data-kr-mode')).toBe('book');
        expect(frame().getAttribute('data-kr-state')).toBe('ready');
        expect(step()).toBe('service');
        expect(container.querySelector('.kr-booking__title')?.textContent).toBe(said(STEP_TITLES.service));
        expect(container.querySelector('.kr-booking__estimate-business')?.textContent).toBe('Luna Hair Studio');
    });

    it('titles the frame by the step: the five steps\' own titles, and done by the status the server returned', async () => {
        await mount(options());
        await settle();
        await press(serviceRow('Haircut'));
        await press(proceedButton());

        expect(container.querySelector('.kr-booking__title')?.textContent).toBe(said(STEP_TITLES.staff));

        await press(proceedButton());

        expect(container.querySelector('.kr-booking__title')?.textContent).toBe(said(STEP_TITLES.when));

        await remount();
        await reachDetails();

        expect(container.querySelector('.kr-booking__title')?.textContent).toBe(said(STEP_TITLES.details));

        await press(proceedButton());
        await settle();

        expect(step()).toBe('review');
        expect(container.querySelector('.kr-booking__title')?.textContent).toBe(said(STEP_TITLES.review));

        await press(confirmButton());
        await settle();

        expect(step()).toBe('done');
        expect(container.querySelectorAll('.kr-booking__title')).toHaveLength(1);
        expect(container.querySelector('.kr-booking__title')?.textContent).toBe(said(headingFor('confirmed')));
    });

    it('starts at staff with a preselected service and at when with both, before the catalog answers', async () => {
        await mount(options({ serviceId: 1 }), () => new Promise<Response>(() => undefined));
        expect(step()).toBe('staff');

        await act(() => {
            root.unmount();
        });
        root = createRoot(container);

        await mount(options({ serviceId: 1, staffId: 3 }), () => new Promise<Response>(() => undefined));
        expect(step()).toBe('when');
    });
});

describe('the flow in Greek', () => {
    // The walk by keys: every screen rendered with lang/el/booking.php's lines shows no English
    // line of booking.php but the shared ones and no :word unfilled. The tenant's own names (the
    // services, the members, the business) stay as typed.
    beforeEach(() => {
        mountLanguage = 'el';
        mountWords = GREEK_WORDS;
    });

    it('fails a screen left in English or with a :word unfilled, so the walk can fail', async () => {
        mountWords = ENGLISH_WORDS;
        await mount(options());
        await settle();

        const stray = document.createElement('p');

        stray.textContent = GREEK_WORDS.text('service.removed').replace(/\S+$/, ':service');
        container.appendChild(stray);

        expect(englishLeft(container)).toEqual(expect.arrayContaining(['titles.service', 'estimate.continue', ':service']));
        stray.remove();
    });

    it('walks service, staff, when, details, review and done with no English line left', async () => {
        const catalog = holdingCatalog();

        await mount(options(), catalog.fetch);
        expect(englishLeft(container)).toEqual([]);

        await act(() => catalog.release());
        await settle();
        expect(step()).toBe('service');
        expect(englishLeft(container)).toEqual([]);

        await press(serviceRow('Haircut'));
        expect(englishLeft(container)).toEqual([]);

        await press(proceedButton());
        expect(step()).toBe('staff');
        expect(englishLeft(container)).toEqual([]);

        await press(proceedButton());
        await settle();
        expect(step()).toBe('when');
        expect(englishLeft(container)).toEqual([]);

        await press(days()[0] as HTMLElement);
        await press(slots()[1] as HTMLElement);
        expect(englishLeft(container)).toEqual([]);

        await press(proceedButton());
        expect(step()).toBe('details');
        await typeInto('customer_name', 'Casey Customer');
        await typeInto('customer_email', 'casey@example.com');
        await typeNote('Allergic to lavender.');
        expect(englishLeft(container)).toEqual([]);

        await press(proceedButton());
        await settle();
        expect(step()).toBe('review');
        expect(englishLeft(container)).toEqual([]);

        await press(confirmButton());
        await settle();
        expect(step()).toBe('done');
        expect(container.querySelector('.kr-booking__title')?.textContent).toBe(GREEK_WORDS.text(headingFor('confirmed')));
        expect(englishLeft(container)).toEqual([]);
    });

    it.each(['confirmed', 'awaiting_approval', 'awaiting_payment', 'completed'])('lands done on %s in Greek, its heading and sentence the status\'s, no English line left', async (status) => {
        await reachDetails();
        confirmAnswer = () => answering({ ...CONFIRMED, status })('', {});
        await press(proceedButton());
        await settle();
        await press(confirmButton());
        await settle();

        expect(step()).toBe('done');
        expect(container.querySelector('.kr-booking__title')?.textContent).toBe(GREEK_WORDS.text(headingFor(status)));
        expect(container.textContent).toContain(GREEK_WORDS.text(sentenceFor(status)));
        expect(englishLeft(container)).toEqual([]);
    });

    it('spells the days and the money by Intl in Greek, the times on the tenant\'s clock as they are', async () => {
        await reachDetails();
        await press(proceedButton());
        await settle();

        const moment = formatMoment(wallClockOf(HELD.items[0]?.start ?? '', 'Europe/Athens'), GREEK_WORDS.locale);
        const englishMoment = formatMoment(wallClockOf(HELD.items[0]?.start ?? '', 'Europe/Athens'), ENGLISH_WORDS.locale);

        // The language's names and glyphs, the machine's order and hour cycle: the held item's
        // 07:40 UTC is 10:40 on the tenant's clock in both languages.
        expect(GREEK_WORDS.locale.split('-')[0]).toBe('el');
        expect(moment).not.toBe(englishMoment);
        expect(moment).toContain('10:40');
        expect(container.textContent).toContain(moment);
        expect(container.textContent).toContain(formatMoney(4200, 'EUR', GREEK_WORDS.locale));
        expect(container.textContent).not.toContain(formatMoney(4200, 'EUR', ENGLISH_WORDS.locale));
        expect(englishLeft(container)).toEqual([]);
    });
});

describe('step service', () => {
    it('appends a segment on a choice and does not advance; the summary card\'s Continue is enabled only then', async () => {
        await mount(options());
        await settle();

        expect(proceedButton().textContent).toBe(said(CONTINUE_LABEL));
        expect(proceedButton().disabled).toBe(true);
        expect(container.querySelector('.kr-booking__estimate-empty')?.textContent).toBe(said(PICK_A_SERVICE));

        await press(serviceRow('Haircut'));

        expect(step()).toBe('service');
        expect(serviceRow('Haircut').getAttribute('aria-pressed')).toBe('true');
        expect(container.querySelector('.kr-booking__estimate-name')?.textContent).toBe('Haircut');
        expect(proceedButton().disabled).toBe(false);
        expect(buttons().filter((candidate) => candidate.textContent === said(CONTINUE_LABEL))).toEqual([proceedButton()]);
    });

    it('removes the service again on a second press of its card, back to a disabled Continue, nothing advancing', async () => {
        await mount(options());
        await settle();
        await press(serviceRow('Haircut'));
        await press(serviceRow('Haircut'));

        expect(step()).toBe('service');
        expect(serviceRow('Haircut').getAttribute('aria-pressed')).toBe('false');
        expect(container.querySelector('.kr-booking__estimate-item')).toBeNull();
        expect(proceedButton().disabled).toBe(true);
        expect(document.activeElement).toBe(serviceRow('Haircut'));
    });

    it('moves to staff on Continue when a segment lists staff, landing focus on the container', async () => {
        await mount(options());
        await settle();
        await press(serviceRow('Haircut'));
        await press(button(said(CONTINUE_LABEL)));

        expect(step()).toBe('staff');
        expect(document.activeElement).toBe(frame());
    });

    it('moves straight to when on Continue when no segment lists staff', async () => {
        await mount(options());
        await settle();
        await press(serviceRow('Head spa'));
        await press(button(said(CONTINUE_LABEL)));

        expect(step()).toBe('when');
        expect(document.activeElement).toBe(frame());
    });
});

describe('step staff', () => {
    it('renders one picker per segment whose service lists staff, Any professional pressed by default', async () => {
        await mount(options());
        await settle();
        await press(serviceRow('Haircut'));
        await press(serviceRow('Head spa'));
        await press(button(said(CONTINUE_LABEL)));

        expect(step()).toBe('staff');
        expect(container.querySelectorAll('.kr-booking__staff')).toHaveLength(1);
        expect(container.querySelector('.kr-booking__subtitle')?.textContent).toBe('Haircut');

        const choices = Array.from(container.querySelectorAll<HTMLButtonElement>('.kr-booking__choice'));

        expect(choices.map((choice) => choice.textContent)).toEqual([ANY, 'Elena', 'Nikos']);
        expect(choices.map((choice) => choice.getAttribute('aria-pressed'))).toEqual(['true', 'false', 'false']);
    });

    it('presses a named person and reports back to Any professional, then moves to when on Continue', async () => {
        await mount(options());
        await settle();
        await press(serviceRow('Beard trim'));
        await press(button(said(CONTINUE_LABEL)));

        await press(button('Nikos'));
        expect(button('Nikos').getAttribute('aria-pressed')).toBe('true');
        expect(button(ANY).getAttribute('aria-pressed')).toBe('false');

        await press(button(ANY));
        expect(button(ANY).getAttribute('aria-pressed')).toBe('true');
        expect(button('Nikos').getAttribute('aria-pressed')).toBe('false');

        await press(button('Nikos'));
        await press(button(said(CONTINUE_LABEL)));

        expect(step()).toBe('when');
        expect(document.activeElement).toBe(frame());

        await press(button(said(BACK_LABEL)));

        expect(step()).toBe('staff');
        expect(button('Nikos').getAttribute('aria-pressed')).toBe('true');
    });

    it('auto-skips to when once the catalog answers and no segment lists staff, moving no focus', async () => {
        const catalog = holdingCatalog();

        await mount(options({ serviceId: 5 }), catalog.fetch);

        expect(step()).toBe('staff');

        await act(() => catalog.release());
        await settle();

        expect(step()).toBe('when');
        expect(document.activeElement).toBe(document.body);
    });

    it('does not auto-skip a preselected service the catalog does not list, which the void takes back to service instead', async () => {
        await mount(options({ serviceId: 99 }));
        await settle();

        expect(step()).toBe('service');
        expect(document.activeElement).toBe(document.body);
    });

    it('offers no Back from when to a staff step the customer never saw, when a mount named both', async () => {
        await mount(options({ serviceId: 1, staffId: 3 }));
        await settle();

        expect(step()).toBe('when');
        expect(backButton()).toBeNull();
    });

    it('goes back from when to the staff step a mount naming a service alone did show', async () => {
        await mount(options({ serviceId: 4 }));
        await settle();
        await press(button('Nikos'));
        await press(button(said(CONTINUE_LABEL)));

        expect(step()).toBe('when');

        await press(button(said(BACK_LABEL)));

        expect(step()).toBe('staff');
        expect(button('Nikos').getAttribute('aria-pressed')).toBe('true');
    });
});

/**
 * The pressed choice of each staff picker on the staff step, in segment order.
 */
function pressedChoices(): (string | null)[] {
    return Array.from(container.querySelectorAll('.kr-booking__staff')).map((picker) => picker.querySelector('.kr-booking__choice[aria-pressed="true"]')?.textContent ?? null);
}

async function remount(): Promise<void> {
    await act(() => {
        root.unmount();
    });
    root = createRoot(container);
}

describe('the judge-once preselection check', () => {
    it('voids a mount naming a service the catalog does not list on its first answer: the picker, no chain, nothing said, no focus moved', async () => {
        await mount(options({ serviceId: 99 }));

        expect(step()).toBe('staff');

        await settle();

        expect(step()).toBe('service');
        expect(Array.from(container.querySelectorAll('.kr-booking__service-name')).map((name) => name.textContent)).toEqual(['Haircut', 'Beard trim', 'Head spa']);
        expect(container.querySelector('.kr-booking__estimate-item')).toBeNull();
        expect(politeText()).toBe('');
        expect(container.querySelector('[role="alert"]')).toBeNull();
        expect(document.activeElement).toBe(document.body);
        expect(backButton()).toBeNull();
        expect(availabilityRequests).toEqual([]);
    });

    it('voids a mount naming a dead service and a member alike, from when back to service', async () => {
        await mount(options({ serviceId: 99, staffId: 3 }));

        expect(step()).toBe('when');

        await settle();

        expect(step()).toBe('service');
        expect(container.querySelector('.kr-booking__estimate-item')).toBeNull();
        expect(politeText()).toBe('');
        expect(container.querySelector('[role="alert"]')).toBeNull();
    });

    it('offers Back after a void as a bare mount does: staff to service, and when to staff or to service by 1.4', async () => {
        await mount(options({ serviceId: 99 }));
        await settle();
        await press(serviceRow('Haircut'));
        await press(button(said(CONTINUE_LABEL)));

        expect(step()).toBe('staff');

        await press(button(said(BACK_LABEL)));

        expect(step()).toBe('service');
        expect(document.activeElement).toBe(frame());

        await press(button(said(CONTINUE_LABEL)));
        await press(button(said(CONTINUE_LABEL)));

        expect(step()).toBe('when');

        await press(button(said(BACK_LABEL)));

        expect(step()).toBe('staff');

        await remount();
        await mount(options({ serviceId: 99 }));
        await settle();
        await press(serviceRow('Head spa'));
        await press(button(said(CONTINUE_LABEL)));

        expect(step()).toBe('when');

        await press(button(said(BACK_LABEL)));

        expect(step()).toBe('service');
    });

    it('carries a preselected member past the void onto a picked service only if that service lists them', async () => {
        await mount(options({ serviceId: 99, staffId: 3 }));
        await settle();
        await press(serviceRow('Haircut'));
        await press(serviceRow('Beard trim'));
        await press(button(said(CONTINUE_LABEL)));

        expect(pressedChoices()).toEqual(['Nikos', 'Nikos']);

        await remount();
        await mount(options({ serviceId: 99, staffId: 2 }));
        await settle();
        await press(serviceRow('Haircut'));
        await press(serviceRow('Beard trim'));
        await press(button(said(CONTINUE_LABEL)));

        expect(pressedChoices()).toEqual(['Elena', ANY]);
    });

    it('carries a member a mount names without a service onto a picked service only if that service lists them, a staff link\'s preselection', async () => {
        await mount(options({ staffId: 3 }));
        await settle();

        expect(step()).toBe('service');

        await press(serviceRow('Haircut'));
        await press(serviceRow('Beard trim'));
        await press(button(said(CONTINUE_LABEL)));

        expect(pressedChoices()).toEqual(['Nikos', 'Nikos']);

        await remount();
        await mount(options({ staffId: 2 }));
        await settle();
        await press(serviceRow('Haircut'));
        await press(serviceRow('Beard trim'));
        await press(button(said(CONTINUE_LABEL)));

        expect(pressedChoices()).toEqual(['Elena', ANY]);
    });

    it('opens a link\'s three attributes on the when step with the pair, and voids its dead service as 1.6 says', async () => {
        await mount(options({ serviceId: 1, staffId: 3, channel: 'instagram' }));
        await settle();

        expect(step()).toBe('when');
        expect(availabilityEntries([{ service_id: 1, staff_id: 3 }])).toHaveLength(1);

        await remount();
        await mount(options({ serviceId: 12, staffId: 3, channel: 'instagram' }));
        await settle();

        expect(step()).toBe('service');
        expect(container.querySelector('.kr-booking__estimate-item')).toBeNull();
    });

    it('leaves a live preselection alone, a member its service does not list still seeded unvalidated', async () => {
        await mount(options({ serviceId: 4, staffId: 2 }));
        await settle();

        expect(step()).toBe('when');
        expect(availabilityEntries([{ service_id: 4, staff_id: 2 }])).toHaveLength(1);
    });

    it('judges once: a refetch that drops a live preselected service moves nothing and clears nothing', async () => {
        await mount(options({ serviceId: 1 }));
        await settle();
        await press(button('Elena'));

        catalogWire = { ...WIRE, services: [BEARD, SPA] };
        await act(() => client.invalidateQueries({ queryKey: CATALOG_KEY }));
        await settle();

        expect(requestedUrls.filter((path) => path === '/api/v1/services')).toHaveLength(2);
        expect(step()).toBe('staff');

        catalogWire = WIRE;
        await act(() => client.invalidateQueries({ queryKey: CATALOG_KEY }));
        await settle();

        expect(step()).toBe('staff');
        expect(pressedChoices()).toEqual(['Elena']);
    });
});

describe('Back', () => {
    it('is absent on service, goes from staff to service, and is removed from staff by a preselected service', async () => {
        await mount(options());
        await settle();

        expect(backButton()).toBeNull();

        await press(serviceRow('Haircut'));
        await press(button(said(CONTINUE_LABEL)));
        await press(button(said(BACK_LABEL)));

        expect(step()).toBe('service');
        expect(document.activeElement).toBe(frame());
        expect(container.querySelector('.kr-booking__estimate-name')?.textContent).toBe('Haircut');

        await act(() => {
            root.unmount();
        });
        root = createRoot(container);

        await mount(options({ serviceId: 1 }));
        await settle();

        expect(step()).toBe('staff');
        expect(backButton()).toBeNull();
    });

    it('is the crumb\'s round control, before the trail and above the step, and goes from when to service when staff were no real choice', async () => {
        await mount(options());
        await settle();
        await press(serviceRow('Head spa'));
        await press(button(said(CONTINUE_LABEL)));

        expect(step()).toBe('when');

        const bar = container.querySelector('.kr-booking__crumbbar');

        expect(frame().firstElementChild).toBe(bar);
        expect(bar?.firstElementChild).toBe(backButton());
        expect(backButton()?.getAttribute('aria-label')).toBe(said(BACK_LABEL));
        expect(container.querySelectorAll('.kr-booking__back')).toHaveLength(1);
        expect(container.querySelector('.kr-booking__layout')?.contains(backButton())).toBe(false);

        await press(button(said(BACK_LABEL)));

        expect(step()).toBe('service');
    });
});

describe('the strip', () => {
    function crumbs(): HTMLButtonElement[] {
        return Array.from(container.querySelectorAll<HTMLButtonElement>('.kr-booking__crumb'));
    }

    function crumb(at: Step): HTMLButtonElement {
        const found = crumbs().find((candidate) => candidate.value === at);

        if (found === undefined) {
            throw new Error(`No step ${at} on the strip.`);
        }

        return found;
    }

    function reading(): string[] {
        return crumbs().map((candidate) => `${candidate.value}:${candidate.getAttribute('aria-current') === 'step' ? 'current' : candidate.disabled ? 'disabled' : 'live'}`);
    }

    it('renders above the step on service, staff, when and details, finished steps live, the current one aria-current, and none on review or done', async () => {
        await mount(options());
        await settle();

        expect(reading()).toEqual(['service:current', 'when:disabled', 'details:disabled']);
        expect(container.querySelector('.kr-booking__crumbbar')?.contains(container.querySelector('.kr-booking__crumbs'))).toBe(true);
        expect(container.querySelector('.kr-booking__crumbbar')?.nextElementSibling?.classList.contains('kr-booking__layout')).toBe(true);

        await press(serviceRow('Haircut'));

        expect(reading()).toEqual(['service:current', 'staff:disabled', 'when:disabled', 'details:disabled']);

        await press(button(said(CONTINUE_LABEL)));

        expect(reading()).toEqual(['service:live', 'staff:current', 'when:disabled', 'details:disabled']);

        await press(button(said(CONTINUE_LABEL)));
        await settle();
        await press(days()[0] as HTMLElement);
        await press(slots()[0] as HTMLElement);

        expect(step()).toBe('details');
        expect(reading()).toEqual(['service:live', 'staff:live', 'when:live', 'details:current']);

        await typeInto('customer_name', 'Casey Customer');
        await typeInto('customer_email', 'casey@example.com');
        await press(button(said(CONTINUE_LABEL)));
        await settle();

        expect(step()).toBe('review');
        expect(crumbs()).toEqual([]);

        await press(confirmButton());
        await settle();

        expect(step()).toBe('done');
        expect(crumbs()).toEqual([]);
    });

    it('takes a press on a finished step there, with the chain, the day, the time and the draft intact and focus on the container, and Continue goes on from it', async () => {
        await mount(options());
        await settle();
        await press(serviceRow('Haircut'));
        await press(button(said(CONTINUE_LABEL)));
        await press(button('Elena'));
        await press(button(said(CONTINUE_LABEL)));
        await settle();
        await press(days()[2] as HTMLElement);

        const picked = slots()[0]?.value;

        await press(slots()[0] as HTMLElement);
        await typeInto('customer_name', 'Casey Customer');
        await typeInto('customer_email', 'casey@example.com');
        await press(crumb('service'));

        expect(step()).toBe('service');
        expect(document.activeElement).toBe(frame());
        expect(Array.from(container.querySelectorAll('.kr-booking__estimate-name')).map((name) => name.textContent)).toEqual(['Haircut']);
        expect(reading()).toEqual(['service:current', 'staff:disabled', 'when:disabled', 'details:disabled']);
        expect(container.querySelector('.kr-booking__crumb-answer')).toBeNull();

        await press(button(said(CONTINUE_LABEL)));

        expect(button('Elena').getAttribute('aria-pressed')).toBe('true');

        await press(crumb('service'));
        await press(button(said(CONTINUE_LABEL)));
        await press(button(said(CONTINUE_LABEL)));
        await settle();

        expect(step()).toBe('when');
        expect(days().map((day) => day.getAttribute('aria-pressed'))).toEqual(['false', 'false', 'true']);

        await press(slots()[0] as HTMLElement);

        expect(field('customer_name').value).toBe('Casey Customer');
        expect(field('customer_email').value).toBe('casey@example.com');
        expect(slots()).toHaveLength(0);
        expect(picked).toBeDefined();
    });

    it('goes no further than Back would: a mount naming a service shows no service step to go back to', async () => {
        await mount(options({ serviceId: 4 }));
        await settle();

        expect(reading()).toEqual(['staff:current', 'when:disabled', 'details:disabled']);

        await press(button(said(CONTINUE_LABEL)));
        await settle();

        expect(reading()).toEqual(['staff:live', 'when:current', 'details:disabled']);
        expect(button(said(BACK_LABEL))).toBeDefined();

        await press(button(said(BACK_LABEL)));

        expect(step()).toBe('staff');
    });

    it('refuses a press on a finished step while a hold flies, as Back does', async () => {
        await reachDetails();

        holdAnswer = () => new Promise<Response>(() => undefined);

        await press(button(said(CONTINUE_LABEL)));
        await settle();

        expect(crumb('when').disabled).toBe(true);

        await act(() => {
            crumb('when').disabled = false;
            crumb('when').click();
        });

        expect(step()).toBe('details');
    });
});

describe('the running summary', () => {
    function estimate(): HTMLElement | null {
        return container.querySelector('.kr-booking__estimate');
    }

    function estimated(): (string | null)[] {
        return Array.from(container.querySelectorAll('.kr-booking__estimate-name')).map((name) => name.textContent);
    }

    it('renders from the service step on, with or without a segment, on the service, staff, when and details steps, beside the step', async () => {
        await mount(options());
        await settle();

        expect(estimate()?.textContent).toContain(said(PICK_A_SERVICE));
        expect(estimated()).toEqual([]);

        await press(serviceRow('Haircut'));

        expect(estimated()).toEqual(['Haircut']);
        expect(estimate()?.textContent).toContain(said(ESTIMATE_LABEL));

        await press(serviceRow('Head spa'));
        await press(button(said(CONTINUE_LABEL)));

        expect(step()).toBe('staff');
        expect(estimated()).toEqual(['Haircut', 'Head spa']);

        await press(button(said(CONTINUE_LABEL)));
        await settle();

        expect(step()).toBe('when');
        expect(estimated()).toEqual(['Haircut', 'Head spa']);
        expect(estimate()?.closest('.kr-booking__main')).toBeNull();
        expect(estimate()?.parentElement?.classList.contains('kr-booking__layout')).toBe(true);

        await press(days()[0] as HTMLElement);
        await press(slots()[0] as HTMLElement);

        expect(step()).toBe('details');
        expect(estimated()).toEqual(['Haircut', 'Head spa']);
    });

    it('carries the one Continue for each step: on, on, disabled saying to pick a time, then the details form\'s submit, busy while the hold flies', async () => {
        await mount(options());
        await settle();
        await press(serviceRow('Haircut'));

        expect(proceedButton().type).toBe('button');

        await press(proceedButton());

        expect(step()).toBe('staff');
        expect(proceedButton().disabled).toBe(false);

        await press(proceedButton());
        await settle();

        expect(step()).toBe('when');
        expect(proceedButton().textContent).toBe(said(PICK_A_TIME_LABEL));
        expect(proceedButton().disabled).toBe(true);
        expect(buttons().map((candidate) => candidate.textContent)).not.toContain(said(CONTINUE_LABEL));

        await press(days()[0] as HTMLElement);
        await press(slots()[0] as HTMLElement);

        const form = container.querySelector('form') as HTMLFormElement;

        expect(step()).toBe('details');
        expect(proceedButton().type).toBe('submit');
        expect(proceedButton().form).toBe(form);
        expect(form.id).not.toBe('');
        expect(form.contains(proceedButton())).toBe(false);
        expect(form.querySelector('button')).toBeNull();

        await typeInto('customer_name', 'Casey Customer');
        await typeInto('customer_email', 'casey@example.com');
        holdAnswer = () => new Promise<Response>(() => undefined);
        await press(proceedButton());
        await settle();

        expect(holdRequests).toHaveLength(1);
        expect(proceedButton().textContent).toBe(said(BUSY_LABEL));
        expect(proceedButton().disabled).toBe(true);
    });

    it('keeps Continue disabled on the staff step while the catalog is on its way', async () => {
        const catalog = holdingCatalog();

        await mount(options({ serviceId: 1 }), catalog.fetch);

        expect(step()).toBe('staff');
        expect(proceedButton().disabled).toBe(true);
        expect(container.textContent).toContain(said(LOADING_SERVICES_SENTENCE));

        await act(() => catalog.release());
        await settle();

        expect(proceedButton().disabled).toBe(false);
    });

    it('states the people from the staff step on and the day and time once chosen', async () => {
        await mount(options());
        await settle();
        await press(serviceRow('Haircut'));
        await press(button(said(CONTINUE_LABEL)));

        expect(container.querySelector('.kr-booking__estimate-detail')?.textContent).toBe(said(ANY_PROFESSIONAL_LINE, { duration: formatDuration(45, KEYED_WORDS) }));

        await press(button('Elena'));

        expect(container.querySelector('.kr-booking__estimate-detail')?.textContent).toBe(said(WITH_STAFF_LINE, { duration: formatDuration(45, KEYED_WORDS), staff: 'Elena' }));

        await press(button(said(CONTINUE_LABEL)));
        await settle();
        await press(days()[0] as HTMLElement);

        expect(container.querySelector('.kr-booking__estimate-when')?.textContent).not.toBe('');
        expect(container.querySelectorAll('.kr-booking__remove')).toHaveLength(0);
    });

    it('removes a line by its remove control on the service step, announcing it in the step\'s region and re-seating focus', async () => {
        await mount(options());
        await settle();
        await press(serviceRow('Haircut'));
        await press(serviceRow('Beard trim'));
        await press(button(said(REMOVE_LABEL, { service: 'Haircut' })));

        expect(estimated()).toEqual(['Beard trim']);
        expect(serviceRow('Haircut').getAttribute('aria-pressed')).toBe('false');
        expect(document.activeElement).toBe(button(said(REMOVE_LABEL, { service: 'Beard trim' })));
        expect(announced()).toBe(said(REMOVED_SENTENCE, { service: 'Haircut' }));
    });

    it('clears the removal announcement once the service step is left, so a return mounts its region empty', async () => {
        await mount(options());
        await settle();
        await press(serviceRow('Haircut'));
        await press(serviceRow('Beard trim'));
        await press(serviceRow('Beard trim'));

        expect(announced()).toBe(said(REMOVED_SENTENCE, { service: 'Beard trim' }));

        await press(button(said(CONTINUE_LABEL)));
        await press(button(said(BACK_LABEL)));

        expect(step()).toBe('service');
        expect(announced()).toBe('');
    });

    it('renders on neither review nor done: the review\'s summary is the server\'s, read off the booking it answered', async () => {
        await reachDetails();

        expect(estimated()).toEqual(['Head spa']);

        await press(button(said(CONTINUE_LABEL)));
        await settle();

        expect(step()).toBe('review');
        expect(estimate()).toBeNull();
        expect(container.querySelector('.kr-booking__summary-service')?.textContent).toBe('Head spa, as sold');

        await press(confirmButton());
        await settle();

        expect(step()).toBe('done');
        expect(estimate()).toBeNull();
    });
});

describe('focus', () => {
    it('steals nothing on mount: the body still holds focus after a mount with no interaction', async () => {
        await mount(options());
        await settle();

        expect(document.activeElement).toBe(document.body);
    });
});

/**
 * Every event the flow's reducer takes, listed once. The record's type is what makes the list
 * exhaustive: an event added to FlowEvent and missing here fails the type check, and an event
 * here that no row of the table below dispatches fails the table's own test.
 */
const EVENTS: Record<FlowEvent['type'], true> = {
    go: true,
    append: true,
    void: true,
    remove: true,
    choose: true,
    window: true,
    'select-day': true,
    jump: true,
    pick: true,
    draft: true,
    held: true,
    conflict: true,
    refused: true,
    confirmed: true,
    'confirm-refused': true,
    recover: true,
    'handed-off': true,
    expire: true,
    restart: true,
};

/**
 * Where focus is after a transition: on the flow container; where it was before, which for a
 * machine's move is the host page and for a press within a step is the control pressed; or
 * re-seated by the step's own rule onto a control, because the one pressed unmounted.
 */
type Landing = 'container' | 'unmoved' | 'reseated';

interface Transition {
    readonly name: string;
    readonly events: readonly FlowEvent['type'][];
    readonly cause: 'customer' | 'machine';
    readonly landing: Landing;
    /**
     * Drives the flow to the transition and makes it, handed the way to put focus on the host
     * page where a machine's move is about to come. Answers the control focus must be on when
     * that is a control of the flow, and nothing when it is the container or the host page.
     */
    readonly run: (away: () => void) => Promise<HTMLElement | undefined>;
}

/**
 * A hold answer the test hands over when it chooses, so focus can be put elsewhere while the
 * request is out.
 */
function withheldHold(body: unknown, status: number): () => void {
    let answer: () => void = () => undefined;

    holdAnswer = () =>
        new Promise<Response>((resolve) => {
            answer = () => resolve(answering(body, status)('', {}));
        });

    return () => answer();
}

async function tick(ms: number): Promise<void> {
    await act(async () => {
        await new Promise<void>((resolve) => setTimeout(resolve, ms));
    });
}

function removeButtons(): HTMLButtonElement[] {
    return Array.from(container.querySelectorAll<HTMLButtonElement>('.kr-booking__remove'));
}

const TRANSITIONS: readonly Transition[] = [
    {
        name: 'Continue on service',
        events: ['go'],
        cause: 'customer',
        landing: 'container',
        run: async () => {
            await mount(options());
            await settle();
            await press(serviceRow('Haircut'));
            await press(button(said(CONTINUE_LABEL)));

            expect(step()).toBe('staff');

            return undefined;
        },
    },
    {
        name: 'Continue on staff',
        events: ['go'],
        cause: 'customer',
        landing: 'container',
        run: async () => {
            await mount(options({ serviceId: 4 }));
            await settle();
            await press(button(said(CONTINUE_LABEL)));

            expect(step()).toBe('when');

            return undefined;
        },
    },
    {
        name: 'Back',
        events: ['go'],
        cause: 'customer',
        landing: 'container',
        run: async () => {
            await mount(options({ serviceId: 4 }));
            await settle();
            await press(button(said(CONTINUE_LABEL)));
            await press(button(said(BACK_LABEL)));

            expect(step()).toBe('staff');

            return undefined;
        },
    },
    {
        name: 'picking a start',
        events: ['pick'],
        cause: 'customer',
        landing: 'container',
        run: async () => {
            await mount(options({ serviceId: 5 }));
            await settle();
            await press(days()[0] as HTMLElement);
            await press(slots()[0] as HTMLElement);

            expect(step()).toBe('details');

            return undefined;
        },
    },
    {
        name: 'a hold answering pending, to review',
        events: ['held'],
        cause: 'customer',
        landing: 'container',
        run: async () => {
            await reachDetails();
            await press(button(said(CONTINUE_LABEL)));
            await settle();

            expect(step()).toBe('review');

            return undefined;
        },
    },
    {
        name: 'a hold answering any other status, straight to done',
        events: ['held'],
        cause: 'customer',
        landing: 'container',
        run: async () => {
            await reachDetails();

            holdAnswer = () => answering({ ...HELD, status: 'awaiting_approval', hold_class: 'approval' }, 201)('', {});

            await press(button(said(CONTINUE_LABEL)));
            await settle();

            expect(step()).toBe('done');

            return undefined;
        },
    },
    {
        name: 'a hold 409, back to when',
        events: ['conflict'],
        cause: 'customer',
        landing: 'container',
        run: async () => {
            await reachDetails();

            holdAnswer = () => answering(CONFLICT, 409)('', {});

            await press(button(said(CONTINUE_LABEL)));
            await settle();

            expect(step()).toBe('when');

            return undefined;
        },
    },
    {
        name: 'a confirm answering, to done',
        events: ['confirmed'],
        cause: 'customer',
        landing: 'container',
        run: async () => {
            await reachReview();
            await press(confirmButton());
            await settle();

            expect(step()).toBe('done');

            return undefined;
        },
    },
    {
        name: 'a refused confirm whose re-read finds it settled, to done',
        events: ['recover', 'confirmed'],
        cause: 'customer',
        landing: 'container',
        run: async () => {
            await refusedAndRecovering();

            readAnswer = rereading('confirmed');

            await answerTheRead();

            expect(step()).toBe('done');

            return undefined;
        },
    },
    {
        name: 'Pick another time, to when',
        events: ['restart'],
        cause: 'customer',
        landing: 'container',
        run: async () => {
            await reachReview();

            confirmAnswer = refusing(NOT_CONFIRMABLE);

            await press(confirmButton());
            await settle();
            await press(button(said(RESTART_LABEL)));
            await settle();

            expect(step()).toBe('when');

            return undefined;
        },
    },
    {
        name: 'the staff auto-skip',
        events: ['go'],
        cause: 'machine',
        landing: 'unmoved',
        run: async (away) => {
            const catalog = holdingCatalog();

            await mount(options({ serviceId: 5 }), catalog.fetch);

            expect(step()).toBe('staff');

            away();
            await act(() => catalog.release());
            await settle();

            expect(step()).toBe('when');

            return undefined;
        },
    },
    {
        name: 'the void of a preselection the catalog does not list',
        events: ['void'],
        cause: 'machine',
        landing: 'unmoved',
        run: async (away) => {
            const catalog = holdingCatalog();

            await mount(options({ serviceId: 99 }), catalog.fetch);

            expect(step()).toBe('staff');

            away();
            await act(() => catalog.release());
            await settle();

            expect(step()).toBe('service');

            return undefined;
        },
    },
    {
        name: 'the availability refetch a hold 409 set off, answering',
        events: [],
        cause: 'machine',
        landing: 'unmoved',
        run: async (away) => {
            await reachDetails();

            holdAnswer = () => answering(CONFLICT, 409)('', {});
            availabilityWithheld = true;

            await press(button(said(CONTINUE_LABEL)));
            await settle();

            expect(step()).toBe('when');

            away();
            await act(async () => {
                releaseAvailability();
            });
            await settle();

            expect(slots().map((slot) => slot.value)).toEqual(TIMES['2026-04-06']);

            return undefined;
        },
    },
    {
        name: 'a withheld first availability answer, released, selecting its first day with starts',
        events: ['select-day'],
        cause: 'machine',
        landing: 'unmoved',
        run: async (away) => {
            // Armed before the render, so the first availability read is the withheld one however
            // quickly the mount's own effects send it.
            await mount(options({ serviceId: 5 }), routing(), CONFIG, () => {
                availabilityWithheld = true;
            });
            await settle();

            expect(step()).toBe('when');
            expect(days()).toHaveLength(0);

            away();
            await act(async () => {
                releaseAvailability();
            });
            await settle();

            expect(days()).toHaveLength(3);
            expect(days().filter((day) => day.getAttribute('aria-pressed') === 'true').map((day) => day.value)).toEqual(['2026-04-06']);

            return undefined;
        },
    },
    {
        name: 'a catalog refetch answering',
        events: [],
        cause: 'machine',
        landing: 'unmoved',
        run: async (away) => {
            await mount(options());
            await settle();

            const before = requestedUrls.length;

            away();
            await act(() => client.invalidateQueries({ queryKey: CATALOG_KEY }));
            await settle();

            expect(requestedUrls.length).toBeGreaterThan(before);
            expect(step()).toBe('service');

            return undefined;
        },
    },
    {
        name: 'the countdown passing its near milestone, then running out',
        events: ['expire'],
        cause: 'machine',
        landing: 'unmoved',
        run: async (away) => {
            // Sixty-one seconds and a twentieth ahead, so each tick lands fifty milliseconds on
            // and the table does not wait a whole second for one.
            const deadline = '2026-04-06T07:33:01.050+00:00';

            await reachDetails();

            holdAnswer = () => answering({ ...HELD, hold_expires_at: deadline }, 201)('', {});

            await press(button(said(CONTINUE_LABEL)));
            await settle();

            expect(container.querySelector('.kr-booking__countdown [role="status"]')?.textContent).toBe('');

            away();
            clock = NOW + 31_000;
            await tick(120);

            expect(container.querySelector('.kr-booking__countdown [role="status"]')?.textContent).toBe(said(NEAR_MILESTONE));
            expect(document.activeElement).not.toBe(frame());

            clock = Date.parse(deadline);
            await tick(120);

            expect(container.querySelector('.kr-booking__countdown [role="status"]')?.textContent).toBe(said(EXPIRED_MILESTONE));
            expect(confirmButton().disabled).toBe(true);

            return undefined;
        },
    },
    {
        name: 'a 410 raising the expiry flag as its recovery starts',
        events: ['recover'],
        cause: 'machine',
        landing: 'unmoved',
        run: async (away) => {
            await reachReview();

            confirmWithheld = true;
            readWithheld = true;
            confirmAnswer = refusing(EXPIRED);

            await press(confirmButton());
            await settle();

            away();
            await act(async () => {
                releaseConfirm();
            });
            await settle();

            expect(readRequests).toHaveLength(1);
            expect(container.querySelector('.kr-booking__countdown-value')?.textContent).toBe('0:00');

            return undefined;
        },
    },
    {
        name: 'a refused confirm whose re-read finds it unsettled, the refusal standing',
        events: ['recover', 'confirm-refused'],
        cause: 'machine',
        landing: 'unmoved',
        run: async (away) => {
            await refusedAndRecovering();

            away();
            await answerTheRead();

            expect(step()).toBe('review');
            expect(politeText()).toBe(NOT_CONFIRMABLE.data.detail);

            return undefined;
        },
    },
    {
        name: 'a hand-off, focus staying where the press left it',
        events: ['handed-off'],
        cause: 'machine',
        landing: 'unmoved',
        run: async () => {
            await reachPayment();

            const pay = button(said(PAYMENT_LABEL));

            await press(pay);
            await settle();

            expect(navigations).toEqual([CHECKOUT_URL]);
            expect(politeText()).toBe(said(HANDED_OFF_SENTENCE));

            return pay;
        },
    },
    {
        name: 'a details refusal',
        events: ['refused'],
        cause: 'machine',
        landing: 'unmoved',
        run: async (away) => {
            await reachDetails();

            const answer = withheldHold(REFUSAL, 400);

            await press(button(said(CONTINUE_LABEL)));

            away();
            await act(async () => {
                answer();
            });
            await settle();

            expect(step()).toBe('details');
            expect(politeText()).toBe(REFUSAL.data.detail);

            return undefined;
        },
    },
    {
        name: 'an append, the card staying in place and pressed',
        events: ['append'],
        cause: 'customer',
        landing: 'unmoved',
        run: async () => {
            await mount(options());
            await settle();
            await press(serviceRow('Haircut'));

            const row = serviceRow('Beard trim');

            await press(row);

            expect(container.querySelectorAll('.kr-booking__estimate-item')).toHaveLength(2);
            expect(row.isConnected).toBe(true);
            expect(row.getAttribute('aria-pressed')).toBe('true');

            return row;
        },
    },
    {
        name: 'choosing a staff member',
        events: ['choose'],
        cause: 'customer',
        landing: 'unmoved',
        run: async () => {
            await mount(options({ serviceId: 4 }));
            await settle();

            const nikos = button('Nikos');

            await press(nikos);

            expect(nikos.getAttribute('aria-pressed')).toBe('true');

            return nikos;
        },
    },
    {
        name: 'Next window',
        events: ['window'],
        cause: 'customer',
        landing: 'unmoved',
        run: async () => {
            await mount(options({ serviceId: 5 }));
            await settle();

            const next = button(said(NEXT_WEEK_LABEL));

            await press(next);
            await settle();

            expect(availabilityRequests).toHaveLength(2);

            return next;
        },
    },
    {
        name: 'Next available date on a week whose every day is empty, to a later week',
        events: ['jump'],
        cause: 'customer',
        landing: 'container',
        run: async () => {
            await mount(options({ serviceId: 5 }), byWindow(EMPTY_FIRST_WEEK));
            await settle();
            await press(jumpButton() as HTMLButtonElement);
            await settle();

            expect(days().map((day) => day.value)).toEqual(['2026-04-20', '2026-04-21', '2026-04-22']);

            return undefined;
        },
    },
    {
        name: 'Next available date on an empty day in a week with starts, within the week',
        events: ['jump'],
        cause: 'customer',
        landing: 'container',
        run: async () => {
            const times: Record<string, string[]> = { ...TIMES };

            await mount(options({ serviceId: 5 }), routing(times));
            await settle();
            await press(days()[2] as HTMLElement);
            await press(slots()[0] as HTMLElement);
            await typeInto('customer_name', 'Casey Customer');
            await typeInto('customer_email', 'casey@example.com');

            holdAnswer = () => answering(CONFLICT, 409)('', {});
            times['2026-04-08'] = [];

            await press(button(said(CONTINUE_LABEL)));
            await settle();
            await press(jumpButton() as HTMLButtonElement);

            expect(days().map((day) => day.getAttribute('aria-pressed'))).toEqual(['true', 'false', 'false']);

            return undefined;
        },
    },
    {
        name: 'a finished step of the strip',
        events: ['go'],
        cause: 'customer',
        landing: 'container',
        run: async () => {
            await mount(options({ serviceId: 4 }));
            await settle();
            await press(button(said(CONTINUE_LABEL)));
            await settle();
            await press(container.querySelector<HTMLButtonElement>('.kr-booking__crumb[value="staff"]') as HTMLButtonElement);

            expect(step()).toBe('staff');

            return undefined;
        },
    },
    {
        name: 'selecting a day',
        events: ['select-day'],
        cause: 'customer',
        landing: 'unmoved',
        run: async () => {
            await mount(options({ serviceId: 5 }));
            await settle();

            const day = days()[2] as HTMLButtonElement;

            expect(day.getAttribute('aria-pressed')).toBe('false');

            await press(day);

            expect(day.getAttribute('aria-pressed')).toBe('true');

            return day;
        },
    },
    {
        name: 'typing into the details form',
        events: ['draft'],
        cause: 'customer',
        landing: 'unmoved',
        run: async () => {
            await mount(options({ serviceId: 5 }));
            await settle();
            await press(days()[0] as HTMLElement);
            await press(slots()[0] as HTMLElement);

            const name = field('customer_name');

            name.focus();
            await typeInto('customer_name', 'Casey Customer');

            expect(field('customer_name').value).toBe('Casey Customer');

            return name;
        },
    },
    {
        name: 'a removal by a press of its pressed card, which stays mounted',
        events: ['remove'],
        cause: 'customer',
        landing: 'unmoved',
        run: async () => {
            await mount(options());
            await settle();
            await press(serviceRow('Haircut'));

            const row = serviceRow('Haircut');

            await press(row);

            expect(removeButtons()).toHaveLength(0);
            expect(row.getAttribute('aria-pressed')).toBe('false');

            return row;
        },
    },
    {
        name: 'a removal by the summary card\'s remove control, re-seated on the one now at that index',
        events: ['remove'],
        cause: 'customer',
        landing: 'reseated',
        run: async () => {
            await mount(options());
            await settle();
            await press(serviceRow('Haircut'));
            await press(serviceRow('Beard trim'));
            await press(button(said(REMOVE_LABEL, { service: 'Haircut' })));

            expect(removeButtons().map((remove) => remove.getAttribute('aria-label'))).toEqual([said(REMOVE_LABEL, { service: 'Beard trim' })]);

            return removeButtons()[0];
        },
    },
    {
        name: 'Previous back to the first window, whose control unmounts, re-seated on Next',
        events: ['window'],
        cause: 'customer',
        landing: 'reseated',
        run: async () => {
            await mount(options({ serviceId: 5 }));
            await settle();
            await press(button(said(NEXT_WEEK_LABEL)));
            await settle();
            await press(button(said(PREVIOUS_WEEK_LABEL)));
            await settle();

            expect(buttons().map((candidate) => candidate.textContent)).not.toContain(said(PREVIOUS_WEEK_LABEL));

            return button(said(NEXT_WEEK_LABEL));
        },
    },
];

describe('7.4, every transition in one table', () => {
    let host: HTMLInputElement;

    beforeEach(() => {
        host = document.createElement('input');
        host.setAttribute('aria-label', 'A field on the host page');
        document.body.appendChild(host);
    });

    afterEach(() => {
        host.remove();
    });

    it('steals nothing on the initial mount: the body still holds focus once everything has answered', async () => {
        await mount(options());

        expect(document.activeElement).toBe(document.body);

        await settle();

        expect(document.activeElement).toBe(document.body);
    });

    it('is exhaustive over the flow events: every event type is some row\'s, and no row names one the flow lacks', () => {
        const covered = new Set(TRANSITIONS.flatMap((row) => row.events));

        expect([...covered].sort()).toEqual(Object.keys(EVENTS).sort());
    });

    it('lets no machine-caused row move focus at all', () => {
        expect(TRANSITIONS.filter((row) => row.cause === 'machine' && row.landing !== 'unmoved').map((row) => row.name)).toEqual([]);
    });

    it.each(TRANSITIONS)('$name ($cause): focus $landing', async ({ cause, landing, run }) => {
        const expected = await run(() => host.focus());
        const focused = document.activeElement;

        if (landing === 'container') {
            expect(focused).toBe(frame());

            return;
        }

        expect(focused).not.toBe(frame());

        if (cause === 'machine') {
            expect(focused).toBe(expected ?? host);

            return;
        }

        expect(expected).toBeDefined();
        expect(focused).toBe(expected);
        expect(expected?.isConnected).toBe(true);
    });
});

describe('the availability query', () => {
    it('runs only with a non-empty chain whose every service the catalog listed', async () => {
        await mount(options());
        await settle();

        expect(availabilityRequests).toEqual([]);

        await press(serviceRow('Head spa'));
        await settle();

        expect(availabilityRequests).toHaveLength(1);
        expect(availabilityEntries([{ service_id: 5, staff_id: null }])).toHaveLength(1);
    });

    it('does not run for a chain naming a service the catalog does not list', async () => {
        await mount(options({ serviceId: 99, staffId: 3 }));
        await settle();

        expect(step()).toBe('service');
        expect(availabilityRequests).toEqual([]);
    });

    it('waits for the catalog before running for a preselected chain', async () => {
        const catalog = holdingCatalog();

        await mount(options({ serviceId: 1, staffId: 3 }), catalog.fetch);
        await settle();

        expect(step()).toBe('when');
        expect(availabilityRequests).toEqual([]);

        await act(() => catalog.release());
        await settle();

        expect(availabilityRequests).toHaveLength(1);
    });

    it('asks for seven days from the tenant today with every item\'s staff_id explicit, null spelled as null', async () => {
        await mount(options({ serviceId: 1, staffId: 3 }));
        await settle();

        const params = new URLSearchParams((availabilityRequests[0] as string).slice(AVAILABILITY_PATH.length + 1));

        expect(params.get('items')).toBe('[{"service_id":1,"staff_id":3}]');
        expect(params.get('from')).toBe('2026-04-06');
        expect(params.get('to')).toBe('2026-04-13');
    });

    it('spells no preference as a null staff_id on the wire and in the key, one cache entry for a chain sent twice', async () => {
        await mount(options());
        await settle();
        await press(serviceRow('Haircut'));
        await press(button(said(CONTINUE_LABEL)));
        await press(button(said(CONTINUE_LABEL)));
        await settle();

        expect(step()).toBe('when');
        expect(availabilityRequests).toHaveLength(1);
        expect(new URLSearchParams((availabilityRequests[0] as string).slice(AVAILABILITY_PATH.length + 1)).get('items')).toBe('[{"service_id":1,"staff_id":null}]');

        await press(button(said(BACK_LABEL)));
        await press(button(said(CONTINUE_LABEL)));
        await settle();

        expect(step()).toBe('when');

        const entries = availabilityEntries([{ service_id: 1, staff_id: null }]);

        expect(entries).toHaveLength(1);
        expect(JSON.stringify(entries[0]?.queryKey)).toBe('["availability",[{"service_id":1,"staff_id":null}],"2026-04-06","2026-04-13"]');
        expect(availabilityRequests).toHaveLength(1);
    });

    it('stays subscribed on the details step, so an invalidation refetches at once', async () => {
        await mount(options());
        await settle();
        await press(serviceRow('Head spa'));
        await press(button(said(CONTINUE_LABEL)));
        await settle();
        await press(days()[0] as HTMLElement);
        await press(slots()[0] as HTMLElement);

        expect(step()).toBe('details');
        expect(availabilityEntries([{ service_id: 5, staff_id: null }])[0]?.getObserversCount()).toBe(1);

        await act(async () => {
            await client.invalidateQueries({ queryKey: ['availability'] });
        });

        expect(availabilityRequests).toHaveLength(2);
    });

    it('moves the window by whole weeks, previous absent on the first, and never before today', async () => {
        await mount(options({ serviceId: 5 }));
        await settle();

        expect(step()).toBe('when');
        expect(buttons().map((candidate) => candidate.textContent)).not.toContain(said(PREVIOUS_WEEK_LABEL));

        await press(button(said(NEXT_WEEK_LABEL)));
        await settle();

        expect(new URLSearchParams((availabilityRequests[1] as string).slice(AVAILABILITY_PATH.length + 1)).get('from')).toBe('2026-04-13');
        expect(new URLSearchParams((availabilityRequests[1] as string).slice(AVAILABILITY_PATH.length + 1)).get('to')).toBe('2026-04-20');

        await press(button(said(PREVIOUS_WEEK_LABEL)));
        await settle();

        // The first window's key is stale the moment it was answered, so coming back refetches it.
        expect(availabilityRequests).toHaveLength(3);
        expect(new URLSearchParams((availabilityRequests[2] as string).slice(AVAILABILITY_PATH.length + 1)).get('from')).toBe('2026-04-06');
        expect(buttons().map((candidate) => candidate.textContent)).not.toContain(said(PREVIOUS_WEEK_LABEL));
        expect(days().map((day) => day.value)).toEqual(['2026-04-06', '2026-04-07', '2026-04-08']);
    });
});

describe('step when', () => {
    it('names the config time zone, renders the week row from the answer with its first day with starts selected, and picks a time into details with focus on the container', async () => {
        await mount(options({ serviceId: 5 }));
        await settle();

        expect(container.querySelector('.kr-booking__timezone')?.textContent).toContain('Europe/Athens');
        expect(days().map((day) => day.value)).toEqual(['2026-04-06', '2026-04-07', '2026-04-08']);
        expect(days().map((day) => day.getAttribute('aria-pressed'))).toEqual(['true', 'false', 'false']);
        expect(days().map((day) => day.disabled)).toEqual([false, true, false]);
        expect(container.querySelector('.kr-booking__hint')).toBeNull();
        expect(document.activeElement).toBe(document.body);
        expect(slots().map((slot) => slot.value)).toEqual(TIMES['2026-04-06']);
        expect(slots()[0]?.textContent).toMatch(/10:35/);

        await press(slots()[1] as HTMLElement);

        expect(step()).toBe('details');
        expect(document.activeElement).toBe(frame());
    });

    it('keeps the selected day selected after Back and forward again', async () => {
        await mount(options());
        await settle();
        await press(serviceRow('Haircut'));
        await press(button(said(CONTINUE_LABEL)));
        await press(button(said(CONTINUE_LABEL)));
        await settle();
        await press(days()[2] as HTMLElement);

        expect(days()[2]?.getAttribute('aria-pressed')).toBe('true');
        expect(slots().map((slot) => slot.value)).toEqual(TIMES['2026-04-08']);

        await press(button(said(BACK_LABEL)));

        expect(step()).toBe('staff');

        await press(button(said(CONTINUE_LABEL)));
        await settle();

        expect(step()).toBe('when');
        expect(days().map((day) => day.getAttribute('aria-pressed'))).toEqual(['false', 'false', 'true']);
        expect(slots().map((slot) => slot.value)).toEqual(TIMES['2026-04-08']);

        await press(slots()[0] as HTMLElement);
        await press(button(said(BACK_LABEL)));

        expect(step()).toBe('when');
        expect(days()[2]?.getAttribute('aria-pressed')).toBe('true');
    });

    it('shows the period of the time picked on coming Back from details, the flow handing its chosen start down', async () => {
        // 12:00 and 15:30 UTC are 15:00 and 18:30 in Athens: an afternoon and an evening after the
        // morning.
        const afternoon = '2026-04-06T12:00:00+00:00';

        await mount(options({ serviceId: 5 }), routing({ ...TIMES, '2026-04-06': [...TIMES['2026-04-06'], afternoon, '2026-04-06T15:30:00+00:00'] }));
        await settle();

        const periods = (): HTMLButtonElement[] => Array.from(container.querySelectorAll<HTMLButtonElement>('.kr-booking__period'));

        expect(periods().map((period) => period.textContent)).toEqual([`${said(PERIOD_NAMES.Morning)} ${saidBy(TIMES_COUNT, 2)}`, `${said(PERIOD_NAMES.Afternoon)} ${saidBy(TIMES_COUNT, 1)}`, `${said(PERIOD_NAMES.Evening)} ${saidBy(TIMES_COUNT, 1)}`]);
        expect(periods().map((period) => period.getAttribute('aria-pressed'))).toEqual(['true', 'false', 'false']);
        expect(slots().map((slot) => slot.value)).toEqual(TIMES['2026-04-06']);

        await press(button(`${said(PERIOD_NAMES.Afternoon)} ${saidBy(TIMES_COUNT, 1)}`));
        await press(slots()[0] as HTMLElement);

        expect(step()).toBe('details');

        await press(button(said(BACK_LABEL)));

        expect(step()).toBe('when');
        expect(periods().map((period) => period.getAttribute('aria-pressed'))).toEqual(['false', 'true', 'false']);
        expect(slots().map((slot) => slot.value)).toEqual([afternoon]);
        expect(holdRequests).toEqual([]);
    });

    it('renders the empty day sentence and no button for a chosen day whose refetch after a 409 came back with no times, the day kept', async () => {
        const times: Record<string, string[]> = { ...TIMES };

        await mount(options({ serviceId: 5 }), routing(times));
        await settle();
        await press(days()[2] as HTMLElement);
        await press(slots()[0] as HTMLElement);
        await typeInto('customer_name', 'Casey Customer');
        await typeInto('customer_email', 'casey@example.com');

        holdAnswer = () => answering(CONFLICT, 409)('', {});
        times['2026-04-08'] = [];

        await press(button(said(CONTINUE_LABEL)));
        await settle();

        expect(step()).toBe('when');
        expect(days().map((day) => day.getAttribute('aria-pressed'))).toEqual(['false', 'false', 'true']);
        expect(days()[2]?.disabled).toBe(true);
        expect(container.querySelector('.kr-booking__empty')).not.toBeNull();
        expect(container.querySelector('.kr-booking__hint')).toBeNull();
        expect(slots()).toHaveLength(0);
    });

    it('keeps the day selected on arrival, never pressed, when the refetch after a 409 empties it, rather than moving to the next day with times', async () => {
        const times: Record<string, string[]> = { ...TIMES };

        await mount(options({ serviceId: 5 }), routing(times));
        await settle();

        expect(days().map((day) => day.getAttribute('aria-pressed'))).toEqual(['true', 'false', 'false']);

        await press(slots()[0] as HTMLElement);
        await typeInto('customer_name', 'Casey Customer');
        await typeInto('customer_email', 'casey@example.com');

        holdAnswer = () => answering(CONFLICT, 409)('', {});
        times['2026-04-06'] = [];

        await press(button(said(CONTINUE_LABEL)));
        await settle();

        expect(step()).toBe('when');
        expect(days().map((day) => day.getAttribute('aria-pressed'))).toEqual(['true', 'false', 'false']);
        expect(days()[0]?.disabled).toBe(true);
        expect(container.querySelector('.kr-booking__empty')).not.toBeNull();
        expect(container.querySelector('.kr-booking__hint')).toBeNull();
        expect(slots()).toHaveLength(0);
    });

    it('offers Next available date under the hint of a week whose every day is empty, and its press moves the window to the week holding the day the answer named, selected, with the container focused', async () => {
        await mount(options({ serviceId: 5 }), byWindow(EMPTY_FIRST_WEEK));
        await settle();

        expect(container.querySelector('.kr-booking__hint')).not.toBeNull();
        expect(jumpButton()?.textContent).toBe(said(NEXT_AVAILABLE_LABEL));
        expect(availabilityRequests).toHaveLength(1);

        await press(jumpButton() as HTMLButtonElement);
        await settle();

        expect(availabilityRequests).toHaveLength(2);
        expect(availabilityRequests[1]).toContain('from=2026-04-20');
        expect(days().map((day) => day.getAttribute('aria-pressed'))).toEqual(['false', 'false', 'true']);
        expect(slots().map((slot) => slot.value)).toEqual(['2026-04-22T06:00:00+00:00', '2026-04-22T07:00:00+00:00']);
        expect(jumpButton()).toBeNull();
        expect(document.activeElement).toBe(frame());
        expect(step()).toBe('when');
    });

    it('offers Next available date under an empty chosen day in a week with starts, and its press selects the first such day with no request, the container focused and the draft kept', async () => {
        const times: Record<string, string[]> = { ...TIMES };

        await mount(options({ serviceId: 5 }), routing(times));
        await settle();
        await press(days()[2] as HTMLElement);
        await press(slots()[0] as HTMLElement);
        await typeInto('customer_name', 'Casey Customer');
        await typeInto('customer_email', 'casey@example.com');

        holdAnswer = () => answering(CONFLICT, 409)('', {});
        times['2026-04-08'] = [];

        await press(button(said(CONTINUE_LABEL)));
        await settle();

        const requests = availabilityRequests.length;

        expect(container.querySelector('.kr-booking__empty')).not.toBeNull();
        expect(jumpButton()?.value).toBe('2026-04-06');

        await press(jumpButton() as HTMLButtonElement);
        await settle();

        expect(availabilityRequests).toHaveLength(requests);
        expect(days().map((day) => day.getAttribute('aria-pressed'))).toEqual(['true', 'false', 'false']);
        expect(slots().length).toBeGreaterThan(0);
        expect(document.activeElement).toBe(frame());

        await press(slots()[0] as HTMLElement);

        expect(field('customer_name').value).toBe('Casey Customer');
        expect(field('customer_email').value).toBe('casey@example.com');
    });

    it('offers no Next available date where the empty week\'s answer names no later day', async () => {
        await mount(options({ serviceId: 5 }), routing({ '2026-04-06': [], '2026-04-07': [], next_available_from: null }));
        await settle();

        expect(container.querySelector('.kr-booking__hint')).not.toBeNull();
        expect(jumpButton()).toBeNull();
    });

    it('lands the query refusal in the primary region politely when there is no data', async () => {
        await mount(options({ serviceId: 5 }), routing({ code: 'rate_limited', message: 'rate_limited', data: { status: 429, detail: 'Too many requests. Please wait a moment.' } }, 429));
        await settle();

        expect(container.querySelector('[role="status"]')?.textContent).toBe('Too many requests. Please wait a moment.');
        expect(container.querySelector('[role="alert"]')).toBeNull();
        expect(days()).toHaveLength(0);
    });
});

describe('step details', () => {
    it('sends the trimmed customer, the chain with an explicit staff_id, and the start byte-identical to the offered one', async () => {
        await reachDetails({ name: '  Casey Customer  ', email: ' casey@example.com ', phone: ' +30 210 0000000 ' });
        await press(button(said(CONTINUE_LABEL)));
        await settle();

        expect(holdRequests).toHaveLength(1);
        expect(JSON.parse(holdRequests[0] as string)).toEqual({
            customer_name: 'Casey Customer',
            customer_email: 'casey@example.com',
            customer_phone: '+30 210 0000000',
            items: [{ service_id: 5, staff_id: null }],
            start: TIMES['2026-04-06'][1],
        });
        expect(holdRequests[0]).toContain(`"start":"${TIMES['2026-04-06'][1] as string}"`);
    });

    it('omits the phone key when nothing was typed into it', async () => {
        await reachDetails();
        await press(button(said(CONTINUE_LABEL)));
        await settle();

        expect(Object.keys(JSON.parse(holdRequests[0] as string))).toEqual(['customer_name', 'customer_email', 'items', 'start']);
    });

    it('sends the note trimmed and the mount\'s channel, and names no source', async () => {
        await mount(options({ serviceId: 5, channel: 'qr' }));
        await settle();
        await press(days()[0] as HTMLElement);
        await press(slots()[1] as HTMLElement);
        await typeInto('customer_name', 'Casey Customer');
        await typeInto('customer_email', 'casey@example.com');
        await typeNote('  Allergic to lavender.  ');
        await press(button(said(CONTINUE_LABEL)));
        await settle();

        const body = JSON.parse(holdRequests[0] as string) as Record<string, unknown>;

        expect(body['note']).toBe('Allergic to lavender.');
        expect(body['channel']).toBe('qr');
        expect(Object.keys(body)).toEqual(['customer_name', 'customer_email', 'items', 'start', 'note', 'channel']);
        expect(holdRequests[0]).not.toContain('source');
    });

    it('omits the note when it is blank and the channel when the mount names none', async () => {
        await reachDetails();
        await typeNote('   ');
        await press(button(said(CONTINUE_LABEL)));
        await settle();

        expect(Object.keys(JSON.parse(holdRequests[0] as string))).toEqual(['customer_name', 'customer_email', 'items', 'start']);
    });

    it('sends nothing on a second submit while one flies, the latch refusing it before disabled reaches the DOM', async () => {
        let release: (value: Response) => void = () => undefined;

        await reachDetails();

        holdAnswer = () => new Promise<Response>((resolve) => {
            release = resolve;
        });

        const submit = button(said(CONTINUE_LABEL));
        let stillEnabled = false;

        await act(() => {
            submit.click();
            // The mutation observer notifies through a deferred scheduler, so the rendered
            // disabled attribute has not landed yet and only the latch is refusing these.
            stillEnabled = !submit.disabled;
            submit.click();
            submit.click();
        });

        expect(stillEnabled).toBe(true);
        expect(holdRequests).toHaveLength(1);

        await settle();

        expect(button(said(BUSY_LABEL)).disabled).toBe(true);

        await act(async () => {
            release(new Response(JSON.stringify(HELD), { status: 201, headers: { 'Content-Type': 'application/json' } }));
        });
        await settle();

        expect(step()).toBe('review');
        expect(holdRequests).toHaveLength(1);
    });

    it('keeps the whole draft over a Back off the form and a re-entry, because the flow owns it and the form does not', async () => {
        await reachDetails({ name: 'Casey Customer', email: 'casey@example.com', phone: '+30 210 0000000' });
        await press(button(said(BACK_LABEL)));

        expect(step()).toBe('when');
        expect(container.querySelector('form')).toBeNull();

        await press(days()[2] as HTMLElement);
        await press(slots()[0] as HTMLElement);

        expect(step()).toBe('details');
        expect(field('customer_name').value).toBe('Casey Customer');
        expect(field('customer_email').value).toBe('casey@example.com');
        expect(field('customer_phone').value).toBe('+30 210 0000000');

        await press(button(said(CONTINUE_LABEL)));
        await settle();

        expect(JSON.parse(holdRequests[0] as string)).toMatchObject({ start: TIMES['2026-04-08'][0], customer_name: 'Casey Customer', customer_email: 'casey@example.com', customer_phone: '+30 210 0000000' });
    });

    it('refuses Back while a hold flies, so no answer lands on a step the customer has left', async () => {
        await reachDetails();

        holdAnswer = () => new Promise<Response>(() => undefined);

        await press(button(said(CONTINUE_LABEL)));
        await settle();

        const back = button(said(BACK_LABEL));

        expect(back.disabled).toBe(true);

        await press(back);

        expect(step()).toBe('details');
    });

    it('renders a 400 and a 429 politely, keeping everything typed, and never an alert', async () => {
        await reachDetails({ name: 'Casey Customer', email: 'casey@example.com' });

        holdAnswer = () => answering(REFUSAL, 400)('', {});

        await press(button(said(CONTINUE_LABEL)));
        await settle();

        expect(step()).toBe('details');
        expect(politeText()).toBe(REFUSAL.data.detail);
        expect(container.querySelector('[role="alert"]')).toBeNull();
        expect(field('customer_name').value).toBe('Casey Customer');
        expect(field('customer_email').value).toBe('casey@example.com');

        holdAnswer = () => answering(LIMITED, 429)('', {});

        await press(button(said(CONTINUE_LABEL)));
        await settle();

        expect(step()).toBe('details');
        expect(politeText()).toBe(LIMITED.data.detail);
        expect(container.querySelector('[role="alert"]')).toBeNull();
        expect(holdRequests).toHaveLength(2);
    });

    it('renders a transport failure as the fixed alert sentence, never the browser message', async () => {
        await reachDetails();

        holdAnswer = () => Promise.reject(new TypeError('Failed to fetch'));

        await press(button(said(CONTINUE_LABEL)));
        await settle();

        expect(step()).toBe('details');
        expect(container.querySelector('[role="alert"]')?.textContent).toBe(said(TRANSPORT_SENTENCE));
        expect(politeText()).toBe('');
        expect(container.textContent).not.toContain('Failed to fetch');
        expect(holdRequests).toHaveLength(1);
    });

    it('never retries a refused hold', async () => {
        await reachDetails();

        holdAnswer = () => answering({ code: 'server_error', message: 'server_error', data: { status: 500, detail: 'Something went wrong.' } }, 500)('', {});

        await press(button(said(CONTINUE_LABEL)));
        await settle();

        expect(holdRequests).toHaveLength(1);
        expect(container.querySelector('[role="alert"]')?.textContent).toBe('Something went wrong.');
    });
});

describe('the answer', () => {
    it('moves a pending hold to review, whose summary is the returned booking and not the local chain', async () => {
        await reachDetails();
        await press(button(said(CONTINUE_LABEL)));
        await settle();

        expect(step()).toBe('review');
        expect(document.activeElement).toBe(frame());
        expect(container.querySelector('.kr-booking__summary-service')?.textContent).toBe('Head spa, as sold');
        expect(container.querySelector('.kr-booking__summary-staff')?.textContent).toBe(said(WITH_STAFF, { staff: 'Aiko' }));
        expect(container.querySelector('.kr-booking__total')?.textContent).toContain('42.00');
        expect(container.querySelector('.kr-booking__total')?.textContent).not.toContain('5,000');
        expect(container.querySelector('form')).toBeNull();
        expect(backButton()).toBeNull();
    });

    it('takes any other status straight to done, with the sentence for it filled one effect after mounting', async () => {
        const records: MutationRecord[] = [];
        const observer = new MutationObserver((batch) => records.push(...batch));

        await reachDetails();

        holdAnswer = () => answering({ ...HELD, status: 'awaiting_approval', hold_class: 'approval', hold_expires_at: '2026-04-08T06:00:00+00:00' }, 201)('', {});
        observer.observe(container, { childList: true, subtree: true, characterData: true });

        await press(button(said(CONTINUE_LABEL)));
        await settle();

        records.push(...observer.takeRecords());
        observer.disconnect();

        const inserted = records.findIndex((record) => Array.from(record.addedNodes).some((node) => node instanceof HTMLElement && (node.getAttribute('role') === 'status' || node.querySelector('[role="status"]') !== null)));
        const texted = records.findIndex((record) => record.target instanceof HTMLElement && record.target.getAttribute('role') === 'status' && record.addedNodes.length > 0);

        expect(step()).toBe('done');
        expect(politeText()).toBe(said(sentenceFor('awaiting_approval')));
        expect(inserted).toBeGreaterThan(-1);
        expect(texted).toBeGreaterThan(inserted);
        expect(container.querySelector('.kr-booking__summary-service')).toBeNull();
        expect(buttons()).toHaveLength(0);
    });
});

describe('the prefill under a My bookings session', () => {
    const PREFILLED: BootConfig = { ...CONFIG, customer: { name: 'Casey Customer', email: 'casey@example.com', phone: null } };

    async function reachPrefilledDetails(): Promise<void> {
        await mount(options({ serviceId: 5 }), routing(), PREFILLED);
        await settle();
        await press(days()[0] as HTMLElement);
        await press(slots()[1] as HTMLElement);
    }

    it('opens the details step with the name and the email filled in and the phone and the note empty, every field editable', async () => {
        await reachPrefilledDetails();

        expect(step()).toBe('details');
        expect(field('customer_name').value).toBe('Casey Customer');
        expect(field('customer_email').value).toBe('casey@example.com');
        expect(field('customer_phone').value).toBe('');
        expect(container.querySelector('textarea')?.value).toBe('');
        expect(['customer_name', 'customer_email', 'customer_phone'].map((name) => field(name).readOnly || field(name).disabled)).toEqual([false, false, false]);

        await typeInto('customer_name', 'Casey C.');

        expect(field('customer_name').value).toBe('Casey C.');
    });

    it('holds with the prefilled customer where nothing was changed', async () => {
        await reachPrefilledDetails();
        await press(button(said(CONTINUE_LABEL)));
        await settle();

        expect(JSON.parse(holdRequests[0] as string)).toMatchObject({ customer_name: 'Casey Customer', customer_email: 'casey@example.com' });
        expect(Object.keys(JSON.parse(holdRequests[0] as string))).toEqual(['customer_name', 'customer_email', 'items', 'start']);
    });

    it('keeps the prefilled draft, as edited, over a 409 that unmounts the form, as a typed one is kept (1.5)', async () => {
        await reachPrefilledDetails();
        await typeInto('customer_email', 'casey.c@example.com');

        holdAnswer = () => answering(CONFLICT, 409)('', {});

        await press(button(said(CONTINUE_LABEL)));
        await settle();

        expect(step()).toBe('when');
        expect(container.querySelector('form')).toBeNull();

        await press(slots()[0] as HTMLElement);

        expect(field('customer_name').value).toBe('Casey Customer');
        expect(field('customer_email').value).toBe('casey.c@example.com');
    });

    it('opens the details step empty where the config carries no prefill, as a mount always has', async () => {
        await mount(options({ serviceId: 5 }));
        await settle();
        await press(days()[0] as HTMLElement);
        await press(slots()[1] as HTMLElement);

        expect(step()).toBe('details');
        expect(['customer_name', 'customer_email', 'customer_phone'].map((name) => field(name).value)).toEqual(['', '', '']);
    });
});

describe('a hold 409', () => {
    it('refetches availability once from the hook, lands on when with the sentence verbatim and the container focused, and keeps the draft', async () => {
        await reachDetails();

        holdAnswer = () => answering(CONFLICT, 409)('', {});

        const before = availabilityRequests.length;

        await press(button(said(CONTINUE_LABEL)));
        await settle();

        expect(step()).toBe('when');
        expect(availabilityRequests).toHaveLength(before + 1);
        expect(politeText()).toBe(CONFLICT.data.detail);
        expect(container.querySelector('[role="alert"]')).toBeNull();
        expect(document.activeElement).toBe(frame());
        expect(container.querySelector('form')).toBeNull();
        expect(days().map((day) => day.getAttribute('aria-pressed'))).toEqual(['true', 'false', 'false']);

        await press(slots()[0] as HTMLElement);

        expect(step()).toBe('details');
        expect(field('customer_name').value).toBe('Casey Customer');
        expect(field('customer_email').value).toBe('casey@example.com');
        expect(politeText()).toBe('');

        holdAnswer = () => answering(HELD, 201)('', {});

        await press(button(said(CONTINUE_LABEL)));
        await settle();

        const second = JSON.parse(holdRequests[1] as string);

        expect(step()).toBe('review');
        expect({ ...second, start: TIMES['2026-04-06'][1] }).toEqual(JSON.parse(holdRequests[0] as string));
        expect(second.start).toBe(TIMES['2026-04-06'][0]);
    });

    it('does not announce the conflict again when the refetch it triggered answers', async () => {
        await reachDetails();

        holdAnswer = () => answering(CONFLICT, 409)('', {});

        await press(button(said(CONTINUE_LABEL)));
        await settle();

        const announced = politeSpan();

        expect(announced?.textContent).toBe(CONFLICT.data.detail);

        await act(async () => {
            await client.invalidateQueries({ queryKey: ['availability'] });
        });
        await settle();

        expect(politeSpan()).toBe(announced);
        expect(politeText()).toBe(CONFLICT.data.detail);
    });

    it('does not announce the conflict again when the refetch it triggered fails, leaving the sentence beside the stale notice and destroying no offer', async () => {
        await reachDetails();

        holdAnswer = () => answering(CONFLICT, 409)('', {});
        availabilityFails = true;
        availabilityWithheld = true;

        await press(button(said(CONTINUE_LABEL)));
        await settle();

        // The refetch the 409 triggered is still in flight, so the sentence is announced once
        // and the node carrying it can be held on to across the answer that fails.
        const announced = politeSpan();

        expect(step()).toBe('when');
        expect(announced?.textContent).toBe(CONFLICT.data.detail);

        await act(async () => {
            releaseAvailability();
        });
        await settle();

        const regions = Array.from(container.querySelectorAll('[role="status"]'));

        expect(politeSpan()).toBe(announced);
        expect(regions[0]?.textContent).toBe(CONFLICT.data.detail);
        expect(regions[1]?.textContent).toBe(said(STALE_TIMES_SENTENCE));
        expect(container.querySelector('[role="alert"]')).toBeNull();
        expect(slots().map((slot) => slot.value)).toEqual(TIMES['2026-04-06']);
    });
});

/**
 * The journey as far as the review step: the details form filled in and a pending hold taken.
 */
async function reachReview(): Promise<void> {
    await reachDetails();
    await press(button(said(CONTINUE_LABEL)));
    await settle();
}

/**
 * Two presses of one button inside a single task, answering with what disabled read between
 * them. No render can have happened in that window, so the attribute still reads what it read
 * before the first press: that window is exactly what the latch guards and what the rendered
 * affordance cannot.
 */
async function pressTwice(el: HTMLButtonElement): Promise<boolean> {
    let between = true;

    await act(() => {
        el.click();
        between = el.disabled;
        el.click();
    });

    return between;
}

describe('the confirm', () => {
    it('sends one POST to the confirm path with the token in the body and nowhere in the URL, and a second press while one flies sends nothing', async () => {
        await reachReview();

        confirmWithheld = true;

        const action = confirmButton();
        const disabledBetweenPresses = await pressTwice(action);

        expect(confirmRequests).toEqual([JSON.stringify({ token: HELD.manage_token })]);
        expect(disabledBetweenPresses).toBe(false);
        expect(requestedUrls.filter((url) => url.startsWith('/api/v1/bookings'))).toEqual([CONFIRM_PATH]);
        expect(CONFIRM_PATH).not.toContain(HELD.manage_token);

        await act(async () => {
            releaseConfirm();
        });
        await settle();

        expect(step()).toBe('done');
        expect(confirmRequests).toHaveLength(1);
    });

    it('disables the button while the confirm is out, which is the affordance and not the guard, and says why in its busy label', async () => {
        await reachReview();

        confirmWithheld = true;

        expect(confirmButton().textContent).toBe(said(CONFIRM_LABEL));

        await press(confirmButton());

        // The mutation observer notifies through a deferred scheduler, so the attribute is a
        // macrotask behind the press and is never what a second press is refused by.
        await act(async () => {
            await new Promise<void>((resolve) => setTimeout(resolve, 0));
        });

        expect(confirmButton().disabled).toBe(true);
        expect(confirmButton().textContent).toBe(said(CONFIRMING_LABEL));

        await act(async () => {
            releaseConfirm();
        });
        await settle();

        expect(step()).toBe('done');
    });

    it('moves a confirmed booking to done with the container focused, its paragraph mounted empty and filled one effect later', async () => {
        const records: MutationRecord[] = [];
        const observer = new MutationObserver((batch) => records.push(...batch));

        await reachReview();

        observer.observe(container, { childList: true, subtree: true, characterData: true });

        await press(confirmButton());
        await settle();

        records.push(...observer.takeRecords());
        observer.disconnect();

        const inserted = records.findIndex((record) => Array.from(record.addedNodes).some((node) => node instanceof HTMLElement && (node.getAttribute('role') === 'status' || node.querySelector('[role="status"]') !== null)));
        const texted = records.findIndex((record) => record.target instanceof HTMLElement && record.target.getAttribute('role') === 'status' && record.addedNodes.length > 0);

        expect(step()).toBe('done');
        expect(document.activeElement).toBe(frame());
        expect(politeText()).toBe(said(sentenceFor('confirmed')));
        expect(inserted).toBeGreaterThan(-1);
        expect(texted).toBeGreaterThan(inserted);
        expect(buttons()).toHaveLength(0);
        expect(container.querySelector('.kr-booking__summary-service')).toBeNull();
    });

    it('renders a 409 politely beside the summary, refetches no availability, and lets the customer press again', async () => {
        await reachReview();

        confirmAnswer = () => answering(NOT_CONFIRMABLE, 409)('', {});

        const before = availabilityRequests.length;

        await press(confirmButton());
        await settle();

        expect(step()).toBe('review');
        expect(politeText()).toBe(NOT_CONFIRMABLE.data.detail);
        expect(container.querySelector('[role="alert"]')).toBeNull();
        expect(availabilityRequests).toHaveLength(before);
        expect(container.querySelector('.kr-booking__summary-service')?.textContent).toBe('Head spa, as sold');
        expect(container.querySelector('.kr-booking__total')?.textContent).toContain('42.00');
        expect(confirmButton().disabled).toBe(false);

        // The latch was freed with the refusal, so the next press is a real one.
        await press(confirmButton());
        await settle();

        expect(confirmRequests).toHaveLength(2);
    });

    it('renders a 410 politely and leaves the button disabled, whatever the browser clock thinks', async () => {
        await reachReview();

        confirmAnswer = () => answering(EXPIRED, 410)('', {});

        await press(confirmButton());
        await settle();

        expect(step()).toBe('review');
        expect(politeText()).toBe(EXPIRED.data.detail);
        expect(container.querySelector('[role="alert"]')).toBeNull();
        expect(confirmButton().disabled).toBe(true);
        expect(button(said(RESTART_LABEL)).disabled).toBe(false);
        expect(container.querySelector('.kr-booking__summary-service')?.textContent).toBe('Head spa, as sold');
        expect(confirmRequests).toHaveLength(1);
    });

    it('renders a transport failure as the fixed alert sentence, never the browser message', async () => {
        await reachReview();

        confirmAnswer = () => Promise.reject(new TypeError('Failed to fetch'));

        await press(confirmButton());
        await settle();

        expect(step()).toBe('review');
        expect(container.querySelector('[role="alert"]')?.textContent).toBe(said(TRANSPORT_SENTENCE));
        expect(container.textContent).not.toContain('Failed to fetch');
        expect(confirmRequests).toHaveLength(1);
    });

    it('never retries: a 500 is sent once and alerts', async () => {
        await reachReview();

        confirmAnswer = () => answering(BROKEN, 500)('', {});

        await press(confirmButton());
        await settle();

        expect(confirmRequests).toHaveLength(1);
        expect(container.querySelector('[role="alert"]')?.textContent).toBe(BROKEN.data.detail);
        expect(step()).toBe('review');
    });
});

const UNPAID = { code: 'payment_required', message: 'payment_required', data: { status: 402, detail: 'This booking cannot be confirmed until it is paid.' } };

const CLOSED = { code: 'window_closed', message: 'window_closed', data: { status: 403, detail: 'Bookings for this time are closed.' } };

const APPROVAL = { code: 'approval_required', message: 'approval_required', data: { status: 409, detail: 'This booking is waiting for approval.' } };

/**
 * A refused confirm as the server answers one, or the transport dropping it.
 */
function refusing(body: { readonly data: { readonly status: number } } | null): () => Promise<Response> {
    return body === null ? () => Promise.reject(new TypeError('Failed to fetch')) : () => answering(body, body.data.status)('', {});
}

/**
 * The re-read finding the booking in the given status.
 */
function rereading(status: string): () => Promise<Response> {
    return () => answering({ ...GUEST_PENDING, status, hold_class: null, hold_expires_at: null })('', {});
}

function alertText(): string | null {
    return container.querySelector('[role="alert"]')?.textContent ?? null;
}

/**
 * Presses a button whose rendered disabled attribute the test takes away for the press, so the
 * only thing that can refuse it is the synchronous latch: the window before disabled reaches the
 * DOM, held open. The attribute is put back as rendered straight after, so the next render
 * still owns it.
 */
async function pressPastDisabled(el: HTMLButtonElement): Promise<void> {
    const rendered = el.disabled;

    await act(() => {
        el.disabled = false;
        el.click();
        el.disabled = rendered;
    });
}

/**
 * The review with the confirm refused and its re-read still out.
 */
async function refusedAndRecovering(refusal: () => Promise<Response> = refusing(NOT_CONFIRMABLE)): Promise<void> {
    await reachReview();

    readWithheld = true;
    confirmAnswer = refusal;

    await press(confirmButton());
    await settle();
}

async function answerTheRead(): Promise<void> {
    await act(async () => {
        releaseRead();
    });
    await settle();
}

describe('the confirm refusal recovery', () => {
    const statuses = [
        { name: '400', body: REFUSAL, tone: 'polite', expired: false },
        { name: '402', body: UNPAID, tone: 'polite', expired: false },
        { name: '403', body: CLOSED, tone: 'polite', expired: false },
        { name: '409', body: NOT_CONFIRMABLE, tone: 'polite', expired: false },
        { name: '410', body: EXPIRED, tone: 'polite', expired: true },
        { name: '500', body: BROKEN, tone: 'alert', expired: false },
        { name: 'transport failure', body: null, tone: 'alert', expired: false },
    ] as const;

    it.each(statuses)('re-reads once on a $name, with the credentials, outside the cache, the latch held throughout so a press sends nothing', async ({ body, tone, expired }) => {
        await refusedAndRecovering(refusing(body));

        expect(readRequests).toEqual([READ_PATH]);
        expect(client.getQueryCache().find({ queryKey: bookingKey(HELD.uuid) })).toBeUndefined();

        // Nothing is said while the booking is looked up, and the way out is already offered.
        expect(politeText()).toBe('');
        expect(alertText()).toBeNull();
        expect(button(said(RESTART_LABEL))).toBeDefined();
        expect(confirmButton().disabled).toBe(true);

        await pressPastDisabled(confirmButton());

        expect(confirmRequests).toHaveLength(1);

        await answerTheRead();

        const sentence = body === null ? said(TRANSPORT_SENTENCE) : body.data.detail;

        expect(readRequests).toHaveLength(1);
        expect(step()).toBe('review');
        expect(tone === 'alert' ? alertText() : politeText()).toBe(sentence);
        expect(tone === 'alert' ? politeText() : alertText()).toBe(tone === 'alert' ? '' : null);
        expect(container.querySelector('.kr-booking__summary-service')?.textContent).toBe('Head spa, as sold');
        expect(confirmButton().disabled).toBe(expired);
        expect(button(said(RESTART_LABEL))).toBeDefined();

        if (!expired) {
            // A current recovery freed the latch, so the next press is a real one.
            await press(confirmButton());
            await settle();

            expect(confirmRequests).toHaveLength(2);
        }
    });

    it.each(['confirmed', 'awaiting_approval', 'awaiting_payment'])('lands a re-read reading %s on done with the sentence for it, the refusal nowhere and the container focused', async (status) => {
        await refusedAndRecovering();

        readAnswer = rereading(status);

        await answerTheRead();

        expect(step()).toBe('done');
        expect(politeText()).toBe(said(sentenceFor(status)));
        expect(alertText()).toBeNull();
        expect(container.textContent).not.toContain(NOT_CONFIRMABLE.data.detail);
        expect(document.activeElement).toBe(frame());
        expect(confirmRequests).toHaveLength(1);
    });

    it.each([
        { name: 'a 404', answer: () => answering({ code: 'not_found', message: 'not_found', data: { status: 404, detail: 'Not found.' } }, 404)('', {}) },
        { name: 'a 500', answer: () => answering(BROKEN, 500)('', {}) },
        { name: 'a transport failure', answer: () => Promise.reject(new TypeError('Failed to fetch')) },
    ])('swallows a re-read that fails with $name, sent once, and the original refusal stands', async ({ answer }) => {
        await refusedAndRecovering();

        readAnswer = answer;

        await answerTheRead();

        expect(readRequests).toHaveLength(1);
        expect(step()).toBe('review');
        expect(politeText()).toBe(NOT_CONFIRMABLE.data.detail);
        expect(alertText()).toBeNull();
        expect(container.textContent).not.toContain('Not found.');
        expect(confirmButton().disabled).toBe(false);
    });

    it('lands a re-read answering after Pick another time nowhere: nothing rendered, nothing announced, no focus moved, no latch freed', async () => {
        await refusedAndRecovering();
        await press(button(said(RESTART_LABEL)));
        await settle();

        expect(step()).toBe('when');

        // A fresh hold, and its confirm out and hung, this time to be answered confirmed.
        confirmWithheld = true;
        confirmAnswer = () => answering(CONFIRMED)('', {});

        await press(slots()[0] as HTMLElement);
        await press(button(said(CONTINUE_LABEL)));
        await settle();
        await press(confirmButton());

        expect(confirmRequests).toHaveLength(2);

        const focused = document.activeElement;

        // The first journey's re-read answers now, and says the booking is confirmed.
        readAnswer = rereading('confirmed');

        await answerTheRead();

        expect(step()).toBe('review');
        expect(politeText()).toBe('');
        expect(alertText()).toBeNull();
        expect(document.activeElement).toBe(focused);

        // The stale answer freed nothing: the new confirm's latch still refuses a press.
        await pressPastDisabled(confirmButton());

        expect(confirmRequests).toHaveLength(2);

        await act(async () => {
            releaseConfirm();
        });
        await settle();

        expect(step()).toBe('done');
    });

    it('offers Pick another time while the re-read is out on a hold with a countdown', async () => {
        await refusedAndRecovering();

        expect(container.querySelector('.kr-booking__countdown')).not.toBeNull();
        expect(button(said(RESTART_LABEL))).toBeDefined();
        expect(readRequests).toHaveLength(1);
    });

    it('offers Pick another time while the re-read is out on a hold with no expiry, so no countdown', async () => {
        await reachDetails();

        holdAnswer = () => answering({ ...HELD, hold_expires_at: null }, 201)('', {});

        await press(button(said(CONTINUE_LABEL)));
        await settle();

        readWithheld = true;
        confirmAnswer = refusing(NOT_CONFIRMABLE);

        await press(confirmButton());
        await settle();

        expect(container.querySelector('.kr-booking__countdown')).toBeNull();
        expect(politeText()).toBe('');
        expect(button(said(RESTART_LABEL))).toBeDefined();

        // Taking it with the re-read hung is the way out, and the answer lands nowhere after.
        await press(button(said(RESTART_LABEL)));
        await settle();

        expect(step()).toBe('when');
        expect(releaseRequests).toEqual([releasePath(HELD.uuid, HELD.manage_token)]);

        await answerTheRead();

        expect(step()).toBe('when');
        expect(politeText()).toBe('');
    });

    it('once a 410 raised the flag, the countdown reads as run out and announces nothing further', async () => {
        const deadline = Date.UTC(2026, 3, 6, 8, 15);

        clock = deadline - 50_000;

        await reachReview();

        const countdownRegion = (): string | null => container.querySelector('.kr-booking__countdown [role="status"]')?.textContent ?? null;

        expect(countdownRegion()).toBe(said(NEAR_MILESTONE));

        confirmAnswer = refusing(EXPIRED);

        await press(confirmButton());
        await settle();

        expect(politeText()).toBe(EXPIRED.data.detail);
        expect(container.querySelector('.kr-booking__countdown-value')?.textContent).toBe('0:00');
        expect(countdownRegion()).toBe('');

        // The clock moving on past where a tick would land says nothing more.
        clock = deadline - 30_000;

        await act(async () => {
            await new Promise<void>((resolve) => setTimeout(resolve, 120));
        });
        await settle();

        expect(countdownRegion()).toBe('');
        expect(container.querySelector('.kr-booking__countdown-value')?.textContent).toBe('0:00');
        expect(confirmButton().disabled).toBe(true);
    });

    it('raises the flag the moment a 410 arrives, so the countdown says nothing across its threshold while the re-read is still out', async () => {
        const deadline = Date.UTC(2026, 3, 6, 8, 15);
        const countdownRegion = (): string | null => container.querySelector('.kr-booking__countdown [role="status"]')?.textContent ?? null;

        // A minute and a fifth of a second left: no milestone yet, the next tick 200ms away.
        clock = deadline - 60_200;

        await refusedAndRecovering(refusing(EXPIRED));

        expect(readRequests).toHaveLength(1);
        expect(container.querySelector('.kr-booking__countdown-value')?.textContent).toBe('0:00');
        expect(countdownRegion()).toBe('');
        expect(confirmButton().disabled).toBe(true);
        expect(button(said(RESTART_LABEL))).toBeDefined();

        // The clock crosses under a minute with the re-read still hung, and a tick would land.
        clock = deadline - 59_000;

        await act(async () => {
            await new Promise<void>((resolve) => setTimeout(resolve, 300));
        });
        await settle();

        expect(countdownRegion()).toBe('');
        expect(container.querySelector('.kr-booking__countdown-value')?.textContent).toBe('0:00');

        // The recovery still runs, and ends in the refusal it stands on.
        await answerTheRead();

        expect(politeText()).toBe(EXPIRED.data.detail);
        expect(countdownRegion()).toBe('');
        expect(confirmButton().disabled).toBe(true);
    });

    describe('the four cases of 4.4', () => {
        it('the background release ran while they were away and the re-read says cancelled: the refusal, politely, and a way back to a live list', async () => {
            await reachReview();
            await turnTab('hidden');
            await turnTab('visible');

            expect(releaseRequests).toHaveLength(1);

            confirmAnswer = refusing(NOT_CONFIRMABLE);
            readAnswer = rereading('cancelled');

            await press(confirmButton());
            await settle();

            expect(step()).toBe('review');
            expect(politeText()).toBe(NOT_CONFIRMABLE.data.detail);
            expect(alertText()).toBeNull();

            const before = availabilityRequests.length;

            await press(button(said(RESTART_LABEL)));
            await settle();

            expect(step()).toBe('when');
            expect(slots()).toHaveLength(2);
            expect(availabilityRequests).toHaveLength(before);
            expect(releaseRequests).toHaveLength(1);
        });

        it('the service requires approval and the re-read says awaiting_approval: the outcome screen, the refusal sentence nowhere', async () => {
            await reachReview();

            confirmAnswer = refusing(APPROVAL);
            readAnswer = rereading('awaiting_approval');

            await press(confirmButton());
            await settle();

            expect(step()).toBe('done');
            expect(politeText()).toBe(said(sentenceFor('awaiting_approval')));
            expect(container.textContent).not.toContain(APPROVAL.data.detail);
            expect(alertText()).toBeNull();
        });

        it('their first confirm committed and the answer was lost, and the re-read says confirmed, its sentence and no alert', async () => {
            await reachReview();

            confirmAnswer = refusing(null);
            readAnswer = rereading('confirmed');

            await press(confirmButton());
            await settle();

            expect(step()).toBe('done');
            expect(politeText()).toBe(said(sentenceFor('confirmed')));
            expect(alertText()).toBeNull();
            expect(container.textContent).not.toContain(said(TRANSPORT_SENTENCE));
        });

        it('an online service needs payment and the re-read says still pending: the refusal, and taking the restart releases the still-live hold', async () => {
            await reachReview();

            confirmAnswer = refusing(UNPAID);

            await press(confirmButton());
            await settle();

            expect(step()).toBe('review');
            expect(politeText()).toBe(UNPAID.data.detail);
            expect(releaseRequests).toEqual([]);

            await press(button(said(RESTART_LABEL)));
            await settle();

            expect(step()).toBe('when');
            expect(releaseRequests).toEqual([releasePath(HELD.uuid, HELD.manage_token)]);
        });
    });
});

/**
 * The page going away, as the browser says it: pagehide on the window.
 */
async function pageHide(init: PageTransitionEventInit = {}): Promise<void> {
    await act(() => {
        window.dispatchEvent(new PageTransitionEvent('pagehide', init));
    });
}

/**
 * The page shown again: persisted when the browser restores it from its back-forward cache,
 * which brings back the same document, the flow still mounted in the state it was left in.
 */
async function pageShow(init: PageTransitionEventInit = {}): Promise<void> {
    await act(() => {
        window.dispatchEvent(new PageTransitionEvent('pageshow', init));
    });
}

/**
 * The tab's visibility changing, as the browser says it: the state first, then the event.
 */
async function turnTab(to: DocumentVisibilityState): Promise<void> {
    visibility = to;

    await act(() => {
        document.dispatchEvent(new Event('visibilitychange'));
    });
}

const CHECKOUT_URL = 'https://pay.example.com/session/abc';

async function reachPayment(): Promise<void> {
    await reachDetails();

    holdAnswer = () => answering({ ...HELD, checkout_url: CHECKOUT_URL }, 201)('', {});

    await press(button(said(CONTINUE_LABEL)));
    await settle();
}

describe('the language on the wire', () => {
    it('sends the language the app holds as lang on every request of a flow: the catalog, the availability, the hold and the confirm', async () => {
        mountLanguage = 'el';

        await reachReview();
        await press(confirmButton());
        await settle();

        expect(step()).toBe('done');
        expect(requestedUrls.some((path) => path === '/api/v1/services')).toBe(true);
        expect(requestedUrls.some((path) => path.startsWith(AVAILABILITY_PATH))).toBe(true);
        expect(requestedUrls).toContain(HOLDS_PATH);
        expect(requestedUrls).toContain(CONFIRM_PATH);
        expect(sentUrls.filter((url) => !/[?&]lang=el$/.test(url))).toEqual([]);
    });

    it('sends it on the release a page hide sends too', async () => {
        mountLanguage = 'el';

        await reachReview();
        await pageHide();

        expect(releaseRequests).toEqual([releasePath(HELD.uuid, HELD.manage_token)]);
        expect(sentUrls.filter((url) => url.includes(releasePath(HELD.uuid, HELD.manage_token)))).toEqual([`https://luna.kleiseradevou.gr${releasePath(HELD.uuid, HELD.manage_token)}&lang=el`]);
        expect(sentUrls.filter((url) => !/[?&]lang=el$/.test(url))).toEqual([]);
    });
});

describe('the automatic release', () => {
    it('sends exactly one DELETE on page hide, the token in its query string and in no other URL', async () => {
        await reachReview();
        await pageHide();

        expect(releaseRequests).toEqual([releasePath(HELD.uuid, HELD.manage_token)]);
        expect(requestedUrls.filter((url) => url.includes(HELD.manage_token))).toEqual(releaseRequests);
        expect(document.documentElement.outerHTML).not.toContain(HELD.manage_token);
    });

    it('sends exactly one DELETE when the tab turns hidden, and nothing when it turns visible', async () => {
        await reachReview();
        await turnTab('visible');

        expect(releaseRequests).toEqual([]);

        await turnTab('hidden');

        expect(releaseRequests).toEqual([releasePath(HELD.uuid, HELD.manage_token)]);
    });

    it('sends one request for one hold whichever event comes first and however often they come', async () => {
        await reachReview();
        await turnTab('hidden');
        await pageHide();
        await turnTab('visible');
        await turnTab('hidden');
        await pageHide();

        expect(releaseRequests).toHaveLength(1);
    });

    it('sends nothing when there is no hold', async () => {
        await reachDetails();
        await pageHide();
        await turnTab('hidden');

        expect(releaseRequests).toEqual([]);
    });

    it('sends nothing once the journey is done, a confirmed booking being no hold', async () => {
        await reachReview();
        await press(confirmButton());
        await settle();

        expect(step()).toBe('done');

        await pageHide();
        await turnTab('hidden');

        expect(releaseRequests).toEqual([]);
    });

    it('sends nothing once the journey is done on a lodged approval request, which must survive the tab closing', async () => {
        await reachReview();

        confirmAnswer = () => answering({ ...HELD, status: 'awaiting_approval', manage_token: undefined })('', {});

        await press(confirmButton());
        await settle();

        expect(step()).toBe('done');

        await pageHide();

        expect(releaseRequests).toEqual([]);
    });

    it('sends nothing for a hold that went straight to done', async () => {
        await reachDetails();

        holdAnswer = () => answering({ ...HELD, status: 'awaiting_approval' }, 201)('', {});

        await press(button(said(CONTINUE_LABEL)));
        await settle();

        expect(step()).toBe('done');

        await pageHide();

        expect(releaseRequests).toEqual([]);
    });

    it('sends nothing once the deadline has passed by the countdown\'s own clock, the sweeper owning that hold', async () => {
        await reachReview();

        // No tick has run: the clock alone says the deadline is behind, which is the split.
        clock = Date.UTC(2026, 3, 6, 8, 15);

        await pageHide();
        await turnTab('hidden');

        expect(releaseRequests).toEqual([]);
    });

    it('sends nothing more when Pick another time already released the hold', async () => {
        await reachReview();

        confirmAnswer = () => answering(NOT_CONFIRMABLE, 409)('', {});

        await press(confirmButton());
        await settle();
        await press(button(said(RESTART_LABEL)));
        await settle();

        expect(releaseRequests).toHaveLength(1);

        await pageHide();
        await turnTab('hidden');

        expect(releaseRequests).toHaveLength(1);
    });

    it('sends nothing when the hold was handed off', async () => {
        await reachPayment();
        await press(button(said(PAYMENT_LABEL)));
        await pageHide();
        await turnTab('hidden');

        expect(releaseRequests).toEqual([]);
    });

    it('renders nothing, announces nothing and logs nothing when the release fails', async () => {
        const spies = (['log', 'info', 'warn', 'error', 'debug'] as const).map((level) => vi.spyOn(console, level).mockImplementation(() => undefined));

        await reachReview();

        releaseFails = true;

        await pageHide();
        await settle();

        expect(releaseRequests).toHaveLength(1);
        expect(step()).toBe('review');
        expect(container.querySelector('[role="alert"]')).toBeNull();
        expect(politeText()).toBe('');
        expect(spies.every((spy) => spy.mock.calls.length === 0)).toBe(true);
    });

    it('leaves the confirm live on a tab that comes back, so the server answers for the hold it released', async () => {
        await reachReview();
        await turnTab('hidden');
        await turnTab('visible');

        confirmAnswer = () => answering(NOT_CONFIRMABLE, 409)('', {});

        expect(confirmButton().disabled).toBe(false);

        await press(confirmButton());
        await settle();

        expect(confirmRequests).toEqual([JSON.stringify({ token: HELD.manage_token })]);
        expect(politeText()).toBe(NOT_CONFIRMABLE.data.detail);
    });

    it('lets Pick another time send nothing more for a hold a hide already released', async () => {
        await reachReview();
        await turnTab('hidden');
        await turnTab('visible');

        confirmAnswer = () => answering(NOT_CONFIRMABLE, 409)('', {});

        await press(confirmButton());
        await settle();
        await press(button(said(RESTART_LABEL)));
        await settle();

        expect(step()).toBe('when');
        expect(releaseRequests).toHaveLength(1);
    });

    it('leaves with the flow: nothing is sent after the flow unmounts', async () => {
        await reachReview();

        await act(() => {
            root.unmount();
        });

        await pageHide();
        await turnTab('hidden');

        expect(releaseRequests).toEqual([]);
    });

    it('releases only its own hold where two flows share one page', async () => {
        const OTHER = { ...HELD, uuid: '7b7f0e5a-2222-4000-8000-00000000000b', manage_token: 'e'.repeat(64) };

        await reachReview();

        // A second flow on the same page, driven to its own review through its own container.
        const first = container;
        const second = document.createElement('div');
        const secondRoot = createRoot(second);
        const api: Api = { origin: 'https://luna.kleiseradevou.gr', fetch: routing() };

        document.body.appendChild(second);
        holdAnswer = () => answering(OTHER, 201)('', {});

        try {
            await act(() => {
                secondRoot.render(
                    keyed(
                        <QueryClientProvider client={createQueryClient()}>
                            <Flow config={CONFIG} options={options({ serviceId: 5 })} api={api} browser={{ now: () => clock, navigate: (url) => navigations.push(url) }} />
                        </QueryClientProvider>,
                    ),
                );
            });
            container = second;
            await settle();
            await press(days()[0] as HTMLElement);
            await press(slots()[0] as HTMLElement);
            await typeInto('customer_name', 'Robin Other');
            await typeInto('customer_email', 'robin@example.com');
            await press(button(said(CONTINUE_LABEL)));
            await settle();

            expect(step()).toBe('review');

            await pageHide();

            expect([...releaseRequests].sort()).toEqual([releasePath(HELD.uuid, HELD.manage_token), releasePath(OTHER.uuid, OTHER.manage_token)].sort());

            // The first flow's hold is gone; the second releasing again would be a second request.
            await act(() => {
                secondRoot.unmount();
            });
            container = first;
            await pageHide();

            expect(releaseRequests).toHaveLength(2);
        } finally {
            container = first;
            second.remove();
        }
    });
});

describe('the hand-off', () => {
    it('reads Continue to payment where the hold carried a checkout_url, and nothing else in the action row', async () => {
        await reachPayment();

        expect(step()).toBe('review');
        expect(buttons().map((candidate) => candidate.textContent)).toEqual([said(PAYMENT_LABEL)]);
    });

    it('sends no confirm, navigates to the url through the location, and renders the url nowhere', async () => {
        await reachPayment();
        await press(button(said(PAYMENT_LABEL)));
        await settle();

        expect(confirmRequests).toHaveLength(0);
        expect(navigations).toEqual([CHECKOUT_URL]);
        expect(step()).toBe('review');
        expect(document.documentElement.outerHTML).not.toContain('pay.example.com');
    });

    it('keeps the latch: a second press before disabled reaches the DOM navigates nothing more, and the button stays disabled', async () => {
        await reachPayment();

        const disabledBetweenPresses = await pressTwice(button(said(PAYMENT_LABEL)));

        expect(disabledBetweenPresses).toBe(false);
        expect(navigations).toEqual([CHECKOUT_URL]);

        await settle();

        expect(button(said(PAYMENT_LABEL)).disabled).toBe(true);
        expect(confirmRequests).toHaveLength(0);
    });

    it('says why the button stays disabled and offers Pick another time beside it, on the page the back-forward cache restores, and restarting from there releases the still-live hold once', async () => {
        await reachPayment();
        await press(button(said(PAYMENT_LABEL)));
        await settle();

        // The navigation to the checkout hides the page and the browser keeps it in its cache;
        // neither hide releases a hold handed off.
        await turnTab('hidden');
        await pageHide({ persisted: true });

        expect(releaseRequests).toEqual([]);

        // The customer comes back through history: the same document, restored as it was left.
        await pageShow({ persisted: true });
        await turnTab('visible');

        expect(step()).toBe('review');
        expect(button(said(PAYMENT_LABEL)).disabled).toBe(true);
        expect(politeText()).toBe(said(HANDED_OFF_SENTENCE));
        expect(alertText()).toBeNull();
        expect(releaseRequests).toEqual([]);

        // The split is on the deadline and never on the control: the hold is live, so it goes.
        await press(button(said(RESTART_LABEL)));
        await settle();

        expect(step()).toBe('when');
        expect(releaseRequests).toEqual([releasePath(HELD.uuid, HELD.manage_token)]);
        expect(document.activeElement).toBe(frame());

        // Released once, by whichever path: a hide after the restart sends nothing more.
        await turnTab('hidden');
        await pageHide();

        expect(releaseRequests).toHaveLength(1);
    });

    it('restarting from a handed-off page past the deadline releases nothing, the sweeper owning that hold', async () => {
        await reachPayment();
        await press(button(said(PAYMENT_LABEL)));
        await settle();

        clock = Date.parse(HELD.hold_expires_at) + 1000;

        await press(button(said(RESTART_LABEL)));
        await settle();

        expect(step()).toBe('when');
        expect(releaseRequests).toEqual([]);
    });

    it('marks its own hold only: the next hold, taken after a hand-off, is released by a hide', async () => {
        const NEXT = { ...HELD, uuid: '7b7f0e5a-2222-4000-8000-00000000000c', manage_token: 'd'.repeat(64) };

        await reachPayment();
        await press(button(said(PAYMENT_LABEL)));
        await press(button(said(RESTART_LABEL)));
        await settle();

        holdAnswer = () => answering(NEXT, 201)('', {});

        await press(slots()[0] as HTMLElement);
        await press(button(said(CONTINUE_LABEL)));
        await settle();

        expect(step()).toBe('review');

        await pageHide();

        expect(releaseRequests).toEqual([releasePath(HELD.uuid, HELD.manage_token), releasePath(NEXT.uuid, NEXT.manage_token)]);
    });
});

describe('the countdown on review', () => {
    it('renders from the hold the server answered, first in the step, and its zero raises the expiry flag', async () => {
        await reachDetails();

        holdAnswer = () => answering({ ...HELD, hold_expires_at: '2026-04-06T07:31:00+00:00' }, 201)('', {});

        await press(button(said(CONTINUE_LABEL)));
        await settle();

        expect(step()).toBe('review');
        expect(container.querySelector('.kr-booking__step > :first-child')?.className).toContain('kr-booking__countdown');
        expect(container.querySelector('.kr-booking__countdown-value')?.textContent).toBe('0:00');
        expect(container.querySelector('.kr-booking__countdown [role="status"]')?.textContent).toBe(said(EXPIRED_MILESTONE));
        expect(confirmButton().disabled).toBe(true);
        expect(button(said(RESTART_LABEL))).toBeDefined();
    });

    it('renders none for a hold with no expiry, which never blocks the confirm', async () => {
        await reachDetails();

        holdAnswer = () => answering({ ...HELD, hold_expires_at: null }, 201)('', {});

        await press(button(said(CONTINUE_LABEL)));
        await settle();

        expect(step()).toBe('review');
        expect(container.querySelector('.kr-booking__countdown')).toBeNull();
        expect(confirmButton().disabled).toBe(false);
    });
});

describe('Pick another time', () => {
    it('is absent on a review with nothing said and nothing expired, and offered once a notice exists', async () => {
        await reachReview();

        expect(container.textContent).not.toContain(said(RESTART_LABEL));

        confirmAnswer = () => answering(NOT_CONFIRMABLE, 409)('', {});

        await press(confirmButton());
        await settle();

        expect(button(said(RESTART_LABEL))).toBeDefined();
    });

    it('before the deadline sends exactly one DELETE, the token in its query string and in no other URL', async () => {
        await reachReview();

        confirmAnswer = () => answering(NOT_CONFIRMABLE, 409)('', {});

        await press(confirmButton());
        await settle();
        await pressTwice(button(said(RESTART_LABEL)));
        await settle();

        expect(releaseRequests).toEqual([releasePath(HELD.uuid, HELD.manage_token)]);
        expect(releaseRequests[0]).toBe(`${HOLDS_PATH}/${HELD.uuid}?token=${HELD.manage_token}`);
        // The token's three transports of 5.5: the confirm body, the recovery's re-read and the
        // release, and no other URL.
        expect(requestedUrls.filter((url) => url.includes(HELD.manage_token))).toEqual([READ_PATH, ...releaseRequests]);
        expect(document.documentElement.outerHTML).not.toContain(HELD.manage_token);
    });

    it('after the deadline sends nothing, the sweeper owning that hold', async () => {
        await reachDetails();

        holdAnswer = () => answering({ ...HELD, hold_expires_at: '2026-04-06T07:31:00+00:00' }, 201)('', {});

        await press(button(said(CONTINUE_LABEL)));
        await settle();
        await press(button(said(RESTART_LABEL)));
        await settle();

        expect(step()).toBe('when');
        expect(releaseRequests).toEqual([]);
    });

    it('splits on the deadline by the clock at the press, not on what raised the flag', async () => {
        await reachReview();

        confirmAnswer = () => answering(EXPIRED, 410)('', {});

        await press(confirmButton());
        await settle();

        // The server said 410; the countdown still reads time left, so the release still goes.
        await press(button(said(RESTART_LABEL)));
        await settle();

        expect(releaseRequests).toHaveLength(1);
    });

    it('renders nothing when the release fails', async () => {
        const spies = (['log', 'info', 'warn', 'error', 'debug'] as const).map((level) => vi.spyOn(console, level).mockImplementation(() => undefined));

        await reachReview();

        releaseFails = true;
        confirmAnswer = () => answering(NOT_CONFIRMABLE, 409)('', {});

        await press(confirmButton());
        await settle();
        await press(button(said(RESTART_LABEL)));
        await settle();

        expect(releaseRequests).toHaveLength(1);
        expect(step()).toBe('when');
        expect(container.querySelector('[role="alert"]')).toBeNull();
        expect(politeText()).toBe('');
        expect(spies.every((spy) => spy.mock.calls.length === 0)).toBe(true);
    });

    it('clears the held booking, the flag, the slot and the notice, lands on when focused on the list the query already holds, and keeps the rest', async () => {
        await reachReview();

        confirmAnswer = () => answering(EXPIRED, 410)('', {});

        await press(confirmButton());
        await settle();

        const before = availabilityRequests.length;

        await press(button(said(RESTART_LABEL)));
        await settle();

        expect(step()).toBe('when');
        expect(document.activeElement).toBe(frame());
        expect(availabilityRequests).toHaveLength(before);
        expect(slots()).toHaveLength(2);
        expect(days()[0]?.getAttribute('aria-pressed')).toBe('true');
        expect(container.querySelector('[role="alert"]')).toBeNull();
        expect(politeText()).toBe('');
        expect(container.textContent).not.toContain(EXPIRED.data.detail);

        await press(slots()[0] as HTMLElement);

        expect(step()).toBe('details');
        expect(field('customer_name').value).toBe('Casey Customer');
        expect(field('customer_email').value).toBe('casey@example.com');

        // A new hold is a fresh review: the flag is down, the notice gone, the confirm live.
        await press(button(said(CONTINUE_LABEL)));
        await settle();

        expect(step()).toBe('review');
        expect(confirmButton().disabled).toBe(false);
        expect(container.textContent).not.toContain(said(RESTART_LABEL));
        expect(JSON.parse(holdRequests[1] ?? '{}')).toMatchObject({ start: TIMES['2026-04-06'][0] });
    });

    it('resets the confirm latch, so a confirm hung past the deadline cannot leave the next hold\'s confirm dead or land on it', async () => {
        const deadline = Date.UTC(2026, 3, 6, 8, 15);

        clock = deadline - 50;

        await reachReview();

        confirmWithheld = true;

        await press(confirmButton());

        // The countdown's next tick reads the moved clock and reaches zero with the confirm out.
        clock = deadline;

        await act(async () => {
            await new Promise<void>((resolve) => setTimeout(resolve, 120));
        });
        await settle();

        expect(confirmButton().disabled).toBe(true);

        await press(button(said(RESTART_LABEL)));
        await settle();

        expect(releaseRequests).toEqual([]);

        // The hung confirm answering now lands nowhere.
        const hung = releaseConfirm;

        await act(async () => {
            hung();
        });
        await settle();

        expect(step()).toBe('when');

        holdAnswer = () => answering({ ...HELD, hold_expires_at: '2026-04-06T08:30:00+00:00' }, 201)('', {});
        confirmWithheld = false;

        await press(slots()[0] as HTMLElement);
        await press(button(said(CONTINUE_LABEL)));
        await settle();

        expect(step()).toBe('review');
        expect(confirmButton().disabled).toBe(false);

        await press(confirmButton());
        await settle();

        expect(confirmRequests).toHaveLength(2);
        expect(step()).toBe('done');
    });
});

describe('the manage token', () => {
    it('appears nowhere in the DOM, in storage, in a URL this code builds, or in the console', async () => {
        const spies = (['log', 'info', 'warn', 'error', 'debug'] as const).map((level) => vi.spyOn(console, level).mockImplementation(() => undefined));

        localStorage.clear();
        sessionStorage.clear();

        await reachDetails();
        await press(button(said(CONTINUE_LABEL)));
        await settle();

        const logged = spies.flatMap((spy) => spy.mock.calls.flat()).map((argument) => String(argument));

        expect(step()).toBe('review');
        expect(document.documentElement.outerHTML).not.toContain(HELD.manage_token);
        expect(container.innerHTML).not.toContain(HELD.manage_token);
        expect(localStorage.length).toBe(0);
        expect(sessionStorage.length).toBe(0);
        expect(document.cookie).toBe('');
        expect(requestedUrls.some((url) => url.includes(HELD.manage_token))).toBe(false);
        expect(logged.some((line) => line.includes(HELD.manage_token))).toBe(false);
    });

    it('is in no DOM node, no storage and no console entry once the booking is confirmed, though the confirm carried it', async () => {
        const spies = (['log', 'info', 'warn', 'error', 'debug'] as const).map((level) => vi.spyOn(console, level).mockImplementation(() => undefined));

        localStorage.clear();
        sessionStorage.clear();

        await reachReview();
        await press(confirmButton());
        await settle();

        const logged = spies.flatMap((spy) => spy.mock.calls.flat()).map((argument) => String(argument));

        expect(step()).toBe('done');
        expect(confirmRequests).toEqual([JSON.stringify({ token: HELD.manage_token })]);
        expect(document.documentElement.outerHTML).not.toContain(HELD.manage_token);
        expect(localStorage.length).toBe(0);
        expect(sessionStorage.length).toBe(0);
        expect(document.cookie).toBe('');
        expect(requestedUrls.some((url) => url.includes(HELD.manage_token))).toBe(false);
        expect(logged.some((line) => line.includes(HELD.manage_token))).toBe(false);
    });
});
