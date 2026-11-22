import { act } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { key, mount, type Mounted, press } from '@/components/ui/harness';
import { FORCED_EDGE } from '@/components/ui/input';
import { Modal } from '@/components/ui/modal';
import type { TenantClock } from '@/lib/format';
import { KEYED_BOOKINGS, KEYED_CUSTOMERS, KEYED_SHELL, KEYED_WIZARD, keyedShell, keyedWizard } from '@/testing/keyed';
import { ToastProvider } from '@/toast/toasts';
import type { SharedProps } from '@/types';
import { BookingWizard, CREATE_URL } from './BookingWizard';
import { AVAILABILITY_URL, UNREADABLE } from './availability';
import { ANY_STAFF, type Catalog, NO_ANSWER, NO_TIMES } from './model';
import { NewBookingProvider, useNewBooking } from './NewBooking';

/*
 * The wizard's screens, over luna's catalog with the router and the fetch stood in: the strip,
 * the three steps, the one query, the submission and its two answers, the opening from the top
 * bar, and a toast raised while the modal is up. Every word is read through the keyed wizard
 * group, never a sentence.
 */

const wiz = keyedWizard();

/** The strip's day and time together, as the wizard words them. */
const TEN = wiz.text('when', { date: '03/11/2026', time: '10:00' });

type Visit = { onStart?: () => void; onSuccess?: () => void; onFinish?: () => void };

const inertia = vi.hoisted(() => ({
    post: vi.fn(),
    listeners: new Map<string, (event: Event) => void>(),
}));

vi.mock('@inertiajs/react', () => ({
    router: {
        post: inertia.post,
        on: (name: string, listener: (event: Event) => void) => {
            inertia.listeners.set(name, listener);

            return () => inertia.listeners.delete(name);
        },
    },
}));

const CATALOG: Catalog = {
    services: [
        { id: 1, name: 'Haircut', duration_min: 45, processing_min: 0, staff: [2, 3] },
        { id: 3, name: 'Colour', duration_min: 60, processing_min: 30, staff: [2, 3] },
    ],
    staff: [
        { id: 2, name: 'Elena Papadaki' },
        { id: 3, name: 'Nikos Georgiou' },
    ],
    members: 3,
};

const CLOCK: TenantClock = { timezone: 'Europe/Athens', dateFormat: 'd/m/Y', timeFormat: 'H:i' };

const TODAY = '2026-11-03';

/** The details the server words, neutral tokens: the wizard shows each as it came. */
const RANGE_DETAIL = 'the-server-detail-for-a-backward-range';

const SIGN_IN_DETAIL = 'the-server-detail-for-a-lapsed-session';

const OVERLAP_DETAIL = 'the-server-detail-for-a-taken-time';

const BUSY_DETAIL = 'the-server-detail-for-a-busy-server';

const STARTS = ['2026-11-03T07:00:00+00:00', '2026-11-03T08:00:00+00:00', '2026-11-03T12:00:00+00:00', '2026-11-03T15:30:00+00:00'];

let mounted: Mounted;
let fetched: ReturnType<typeof vi.fn>;
let answers: Record<string, string[]>;

beforeEach(() => {
    mounted = mount();
    answers = { [TODAY]: STARTS };
    fetched = vi.fn(async (url: string) => {
        const day = new URLSearchParams(url.split('?')[1] ?? '').get('from') ?? '';

        return new Response(JSON.stringify({ [day]: answers[day] ?? [] }), { status: 200 });
    });
    vi.stubGlobal('fetch', fetched);
    inertia.post.mockReset();
    inertia.listeners.clear();
});

afterEach(() => {
    mounted.unmount();
    vi.unstubAllGlobals();
});

async function settle(): Promise<void> {
    await act(async () => {
        await new Promise((resolve) => setTimeout(resolve, 0));
    });
}

function render(overrides: Partial<Parameters<typeof BookingWizard>[0]> = {}, onClose = vi.fn()): ReturnType<typeof vi.fn> {
    mounted.render(
        <ToastProvider>
            <BookingWizard prefill={{}} catalog={CATALOG} clock={CLOCK} granularity={5} weekStart={1} entitlements={['staff']} today={TODAY} onClose={onClose} words={wiz} {...overrides} />
        </ToastProvider>,
    );

    return onClose;
}

function dialog(): HTMLElement {
    const frame = document.querySelector<HTMLElement>('.admin-modal__frame');

    if (frame === null) {
        throw new Error('The wizard is not open.');
    }

    return frame;
}

function steps(): HTMLButtonElement[] {
    return [...dialog().querySelectorAll<HTMLButtonElement>('.admin-wizard-step')];
}

function values(): string[] {
    return [...dialog().querySelectorAll('.admin-wizard-step__value')].map((value) => value.textContent ?? '');
}

function choose(selector: string, value: string): void {
    const select = dialog().querySelector<HTMLSelectElement>(selector);

    if (select === null) {
        throw new Error(`No ${selector}.`);
    }

    act(() => {
        const setter = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'value')?.set;
        setter?.call(select, value);
        select.dispatchEvent(new Event('change', { bubbles: true }));
    });
}

function type(selector: string, value: string): void {
    const input = dialog().querySelector<HTMLInputElement>(selector);

    if (input === null) {
        throw new Error(`No ${selector}.`);
    }

    act(() => {
        const setter = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')?.set;
        setter?.call(input, value);
        input.dispatchEvent(new Event('input', { bubbles: true }));
    });
}

async function toWhen(): Promise<void> {
    press(dialog().querySelector('.admin-wizard-continue'));
    await settle();
}

async function toCustomer(slot = STARTS[1] ?? ''): Promise<void> {
    await toWhen();
    press(dialog().querySelector(`.admin-wizard-slot[value="${slot}"]`));
    press(dialog().querySelector('.admin-wizard-continue'));
}

describe('the strip, 4.1', () => {
    it('shows the running answers with a non-breaking space where none, disables the current and unreached steps, marks the current one, and reopens a finished step from its button with the machine date on the date button', async () => {
        render();

        expect(dialog().querySelector('.admin-modal__title')?.textContent).toBe(wiz.text('title'));
        expect(values()).toEqual(['Haircut', NO_ANSWER, NO_ANSWER]);
        expect(steps().map((step) => step.disabled)).toEqual([true, true, true]);
        expect(steps().map((step) => step.getAttribute('aria-current'))).toEqual(['step', null, null]);
        expect(steps()[1]?.value).toBe(TODAY);

        await toCustomer();

        expect(values()).toEqual(['Haircut', TEN, NO_ANSWER]);
        expect(steps().map((step) => step.disabled)).toEqual([false, false, true]);
        expect(steps().map((step) => step.getAttribute('aria-current'))).toEqual([null, null, 'step']);

        press(steps()[0] ?? null);

        expect(steps().map((step) => step.getAttribute('aria-current'))).toEqual(['step', null, null]);
        expect(dialog().querySelector('.admin-wizard-service')).not.toBeNull();
    });

    it('draws an ordered list of three step cards, each with a number circle a screen reader skips that turns to a tick once the step is done, the current one ringed and never dimmed, the unreached dimmed, and on a phone the words of the current step alone', async () => {
        render();

        const strip = dialog().querySelector('.admin-wizard-strip');
        expect(strip?.tagName).toBe('OL');
        expect([...(strip?.children ?? [])].map((item) => `${item.tagName} ${item.querySelectorAll(':scope > button.admin-wizard-step').length}`)).toEqual(['LI 1', 'LI 1', 'LI 1']);
        expect(circles().map((circle) => circle.getAttribute('aria-hidden'))).toEqual(['true', 'true', 'true']);
        expect(circles().map(faceOf)).toEqual(['1', '2', '3']);
        expect(steps().map(spokenNameOf)).toEqual(STEP_NAMES.map((name, at) => wiz.text('step', { n: String(at + 1), step: wiz.text(name) })));
        expect(steps().map(shownNameOf)).toEqual(STEP_NAMES.map((name) => wiz.text(name)));
        expect(steps().map((step) => ['aria-[current=step]:inset-ring-2', 'aria-[current=step]:inset-ring-primary'].filter((name) => !step.classList.contains(name)))).toEqual([[], [], []]);
        expect(steps().map(dimmed)).toEqual([false, true, true]);
        expect(phoneHidden('.admin-wizard-step__value')).toEqual([false, true, true]);
        expect(phoneHidden('.admin-wizard-step__title')).toEqual([false, true, true]);

        await toCustomer();

        expect(circles().map(faceOf)).toEqual(['tick', 'tick', '3']);
        expect(steps().map(dimmed)).toEqual([false, false, false]);
        expect(phoneHidden('.admin-wizard-step__value')).toEqual([true, true, false]);
        expect(phoneHidden('.admin-wizard-step__title')).toEqual([true, true, false]);
    });
});

/** The strip's step names, in the order the strip shows them. */
const STEP_NAMES = ['steps.services', 'steps.when', 'steps.customer'] as const;

function circles(): HTMLElement[] {
    return steps().map((step) => {
        const circle = step.querySelector<HTMLElement>('.admin-wizard-step__number');

        if (circle === null) {
            throw new Error('A step has no number circle.');
        }

        return circle;
    });
}

/** What a step's circle shows: its tick once the step is done, its number otherwise. */
function faceOf(circle: HTMLElement): string {
    return circle.querySelector('svg') === null ? (circle.textContent ?? '') : 'tick';
}

/** The name a screen reader takes for a step, its number with it, kept from the eye. */
function spokenNameOf(step: HTMLElement): string {
    const name = step.querySelector('.admin-wizard-step__name');

    return name?.classList.contains('sr-only') === true ? (name.textContent ?? '') : '';
}

/** The name a step shows beside its circle, which a screen reader skips for the spoken one. */
function shownNameOf(step: HTMLElement): string {
    const title = step.querySelector('.admin-wizard-step__title');

    return title?.getAttribute('aria-hidden') === 'true' ? (title.textContent ?? '') : '';
}

/** Whether a step is drawn faded, as a step the admin cannot reach yet is. */
function dimmed(step: HTMLElement): boolean {
    return step.classList.contains('opacity-55');
}

/** For each step, whether the part named is kept for a screen reader alone on a phone. */
function phoneHidden(part: string): boolean[] {
    return steps().map((step) => step.querySelector(part)?.classList.contains('max-desktop:sr-only') ?? false);
}

/** True when a class list names the mono face, the name spelled in two parts so no stylesheet gains it. */
function mono(element: Element | null): boolean {
    return [...(element?.classList ?? [])].includes(['font', 'mono'].join('-'));
}

describe('a customer\'s Book now, 4', () => {
    it('opens with the customer in the strip and the fields, the step answered and still editable, Create live once a slot is chosen', async () => {
        render({ prefill: { customer: { name: 'Casey Customer', email: 'casey@example.com', phone: '+30 210 1111111' } } });

        expect(values()).toEqual(['Haircut', NO_ANSWER, 'Casey Customer']);
        expect(steps().map((step) => step.disabled)).toEqual([true, true, true]);

        await toCustomer();

        expect(dialog().querySelector<HTMLInputElement>('.admin-wizard-name')?.value).toBe('Casey Customer');
        expect(dialog().querySelector<HTMLInputElement>('.admin-wizard-email')?.value).toBe('casey@example.com');
        expect(dialog().querySelector<HTMLInputElement>('.admin-wizard-phone')?.value).toBe('+30 210 1111111');
        expect(dialog().querySelector<HTMLButtonElement>('.admin-wizard-create')?.disabled).toBe(false);

        type('.admin-wizard-name', 'Casey C.');
        expect(values()[2]).toBe('Casey C.');
        type('.admin-wizard-email', ' ');
        expect(dialog().querySelector<HTMLButtonElement>('.admin-wizard-create')?.disabled).toBe(true);
        type('.admin-wizard-email', 'casey@example.com');
        press(dialog().querySelector('.admin-wizard-create'));

        const [, body] = inertia.post.mock.calls[0] as [string, Record<string, unknown>, Visit];
        expect(body).toMatchObject({ customer_name: 'Casey C.', customer_email: 'casey@example.com', customer_phone: '+30 210 1111111', start: STARTS[1] });
    });
});

describe('step 0, 4.2', () => {
    it('fills the first segment with the first active service, leaves it alone once chosen, and shows no Staff select with the empty entitlements and one member', () => {
        render({ entitlements: [], catalog: { ...CATALOG, members: 1 } });

        expect(dialog().querySelector<HTMLSelectElement>('.admin-wizard-service')?.value).toBe('1');
        expect(dialog().querySelector('.admin-wizard-staff')).toBeNull();
        expect(dialog().querySelector('.admin-wizard-remove')).toBeNull();
        expect(dialog().querySelector('.admin-wizard-same-staff')).toBeNull();

        choose('.admin-wizard-service', '');

        expect(dialog().querySelector<HTMLSelectElement>('.admin-wizard-service')?.value).toBe('');
        expect(dialog().querySelector<HTMLButtonElement>('.admin-wizard-continue')?.disabled).toBe(true);
    });

    it('lists Any staff then the segment\'s active performers, shows Remove and the same-staff checkbox only past one segment, and follows every segment naming a service', () => {
        render({ catalog: { ...CATALOG, services: [{ id: 1, name: 'Haircut', duration_min: 45, processing_min: 0, staff: [2] }, ...CATALOG.services.slice(1)] } });

        expect([...(dialog().querySelector<HTMLSelectElement>('.admin-wizard-staff')?.options ?? [])].map((option) => option.textContent)).toEqual([wiz.text(ANY_STAFF), 'Elena Papadaki']);
        expect(dialog().querySelector<HTMLButtonElement>('.admin-wizard-continue')?.disabled).toBe(false);

        press(dialog().querySelector('.admin-wizard-add'));

        expect(dialog().querySelectorAll('.admin-wizard-segment').length).toBe(2);
        expect(dialog().querySelectorAll('.admin-wizard-remove').length).toBe(2);
        expect(dialog().querySelector('.admin-wizard-same-staff')).not.toBeNull();
        expect(dialog().querySelector('.admin-wizard-segment__caption')?.textContent).toBe(wiz.text('segment', { n: '1' }));
        expect(dialog().querySelector<HTMLButtonElement>('.admin-wizard-continue')?.disabled).toBe(true);

        choose('#wizard-service-1', '3');

        expect(dialog().querySelector<HTMLButtonElement>('.admin-wizard-continue')?.disabled).toBe(false);
        expect([...(dialog().querySelector<HTMLSelectElement>('#wizard-staff-1')?.options ?? [])].map((option) => option.textContent)).toEqual([wiz.text(ANY_STAFF), 'Elena Papadaki', 'Nikos Georgiou']);

        press(dialog().querySelectorAll('.admin-wizard-remove')[1] ?? null);

        expect(dialog().querySelectorAll('.admin-wizard-segment').length).toBe(1);
        expect(dialog().querySelector('.admin-wizard-remove')).toBeNull();
    });

    it('sets each segment as a fieldset inside an inner card, in a dialog body no wider than 1000 pixels that holds a query container', () => {
        render();
        press(dialog().querySelector('.admin-wizard-add'));

        const segments = [...dialog().querySelectorAll('.admin-wizard-segment')];
        expect(segments.map((segment) => segment.tagName)).toEqual(['FIELDSET', 'FIELDSET']);
        expect(segments.map((segment) => innerCard(segment.parentElement))).toEqual([true, true]);
        expect([...dialog().classList].filter((name) => name.startsWith('max-w-'))).toEqual(['max-w-[62.5rem]']);
        expect(dialog().querySelector('.admin-wizard-strip')?.closest('.\\@container')).not.toBeNull();
    });
});

/** True when the element is an inner card: the smaller radius inside a line ring. */
function innerCard(element: Element | null): boolean {
    return element?.tagName === 'SECTION' && ['rounded-inner', 'ring-border'].every((name) => element.classList.contains(name));
}

describe('step 1, 4.3', () => {
    it('asks the one endpoint only while every segment names a service, the default day prefetched at open, with the chain in the create shape', async () => {
        render();
        await settle();

        expect(fetched).toHaveBeenCalledTimes(1);

        choose('.admin-wizard-service', '');
        await settle();
        expect(fetched).toHaveBeenCalledTimes(1);

        choose('.admin-wizard-service', '3');
        await settle();

        expect(fetched).toHaveBeenCalledTimes(2);
        const url: unknown = fetched.mock.calls[1]?.[0];
        expect(typeof url === 'string' && url.startsWith(`${AVAILABILITY_URL}?`)).toBe(true);
        const query = new URLSearchParams(String(url).split('?')[1]);
        expect(JSON.parse(query.get('items') ?? '')).toEqual([{ service_id: 3, staff_id: null }]);
        expect([query.get('from'), query.get('to'), query.get('same_staff')]).toEqual([TODAY, '2026-11-04', 'false']);
    });

    it('disables no day, offers yesterday, groups the day\'s starts at noon and at five with counts and omits empty groups, labels each from the wall clock, and shows the info notice with no button on an empty day', async () => {
        answers['2026-11-02'] = [];
        render();
        await toWhen();

        const zone = dialog().querySelector('.admin-wizard-zone');
        expect(zone?.textContent).toBe(wiz.text('zone', { zone: 'Europe/Athens' }));
        expect([...dialog().querySelectorAll<HTMLButtonElement>('button[data-date]')].some((day) => day.disabled)).toBe(false);
        expect([...dialog().querySelectorAll('.admin-wizard-group__title')].map((title) => title.textContent)).toEqual([`${wiz.text('periods.morning')} (2)`, `${wiz.text('periods.afternoon')} (1)`, `${wiz.text('periods.evening')} (1)`]);
        expect([...dialog().querySelectorAll('.admin-wizard-slot')].map((slot) => slot.textContent)).toEqual(['09:00', '10:00', '14:00', '17:30']);
        expect(dialog().querySelector<HTMLButtonElement>('.admin-wizard-continue')?.disabled).toBe(true);

        press(dialog().querySelector('.admin-wizard-slot[value="2026-11-03T08:00:00+00:00"]'));

        expect(dialog().querySelector('.admin-wizard-slot[aria-pressed="true"]')?.textContent).toBe('10:00');
        expect(dialog().querySelector<HTMLButtonElement>('.admin-wizard-continue')?.disabled).toBe(false);
        expect(values()[1]).toBe(TEN);

        press(dialog().querySelector('button[data-date="2026-11-02"]'));
        await settle();

        expect(dialog().querySelector('.admin-notice--info')?.textContent).toBe(wiz.text(NO_TIMES));
        expect(dialog().querySelector('.admin-wizard-slot')).toBeNull();
        expect(dialog().querySelector<HTMLButtonElement>('.admin-wizard-continue')?.disabled).toBe(true);
        expect(values()[1]).toBe('02/11/2026');
        expect(steps()[1]?.value).toBe('2026-11-02');
    });

    it('draws a real line round every start where the system forces its own colours, so choosing one moves none, and paints the chosen one in the system\'s highlight there, its keyboard outline in the system\'s colour for text', async () => {
        render();
        await toWhen();

        const slots = [...dialog().querySelectorAll('.admin-wizard-slot')];

        expect(slots.length).toBeGreaterThan(0);

        for (const slot of slots) {
            expect([...slot.classList].filter((name) => name.startsWith('forced-colors:'))).toEqual([
                FORCED_EDGE,
                'forced-colors:aria-pressed:border-[Highlight]',
                'forced-colors:aria-pressed:bg-[Highlight]',
                'forced-colors:aria-pressed:text-[HighlightText]',
                'forced-colors:aria-pressed:forced-color-adjust-none',
                'forced-colors:aria-pressed:focus-visible:outline-[CanvasText]',
            ]);
        }
    });

    it('keeps the slot on the same day, clears it on toggling same-staff and on a change to the chain, and refuses to remove the only segment', async () => {
        render();
        await toWhen();
        press(dialog().querySelector('.admin-wizard-slot[value="2026-11-03T08:00:00+00:00"]'));
        press(dialog().querySelector('button[data-date="2026-11-03"]'));

        expect(dialog().querySelector('.admin-wizard-slot[aria-pressed="true"]')).not.toBeNull();

        press(steps()[0] ?? null);
        press(dialog().querySelector('.admin-wizard-add'));
        choose('#wizard-service-1', '3');
        await toWhen();

        expect(dialog().querySelector('.admin-wizard-slot[aria-pressed="true"]')).toBeNull();

        press(dialog().querySelector('.admin-wizard-slot[value="2026-11-03T08:00:00+00:00"]'));
        press(steps()[0] ?? null);
        // The same-staff tick is the check field's box.
        expect(['size-5', 'accent-primary', 'focus-ring-field'].every((look) => dialog().querySelector('.admin-wizard-same-staff__tick')?.classList.contains(look))).toBe(true);
        press(dialog().querySelector('.admin-wizard-same-staff__tick'));
        await toWhen();

        expect(dialog().querySelector('.admin-wizard-slot[aria-pressed="true"]')).toBeNull();
        expect(fetched.mock.calls.length).toBeGreaterThan(1);
    });

    it('sets each slot as a 44 pixel pill of lining figures, pressed once chosen, three to a line under the day calendar and beside it from a roomy dialog', async () => {
        render();
        await toWhen();
        press(dialog().querySelector(`.admin-wizard-slot[value="${STARTS[1]}"]`));

        const slots = [...dialog().querySelectorAll('.admin-wizard-slot')];
        expect(slots.map((slot) => slot.getAttribute('aria-pressed'))).toEqual(['false', 'true', 'false', 'false']);
        expect(slots.map((slot) => ['h-11', 'rounded-field', 'tabular-nums'].filter((name) => !slot.classList.contains(name)))).toEqual([[], [], [], []]);
        expect(slots.filter(mono)).toEqual([]);
        expect(slots.map((slot) => slot.parentElement?.classList.contains('grid-cols-3'))).toEqual([true, true, true, true]);

        const when = dialog().querySelector('.admin-wizard-when');
        expect(when?.classList.contains('@xl:grid-cols-2')).toBe(true);
        expect([...(when?.classList ?? [])].filter((name) => name.startsWith('desktop:'))).toEqual([]);
    });

    it('shows the error notice with the server\'s sentence when the read fails', async () => {
        fetched.mockImplementation(async () => new Response(JSON.stringify({ code: 'invalid_request', data: { status: 400, detail: RANGE_DETAIL } }), { status: 400 }));
        render();
        await toWhen();

        expect(dialog().querySelector('.admin-notice--error')?.textContent).toBe(RANGE_DETAIL);
    });

    it('says the day\'s times are loading in a status line, the shared spinner inline before the words and hidden from assistive technology', async () => {
        fetched.mockImplementation(() => new Promise<Response>(() => {}));
        render();
        await toWhen();

        const loading = dialog().querySelector('p.admin-wizard-loading');
        const spinner = loading?.querySelector('.admin-spinner');

        expect(loading?.getAttribute('role')).toBe('status');
        expect(loading?.textContent).toBe(wiz.text('loading'));
        expect(loading?.firstElementChild).toBe(spinner);
        expect(spinner?.getAttribute('aria-hidden')).toBe('true');
        expect(spinner?.hasAttribute('role')).toBe(false);
        expect([...(spinner?.classList ?? [])]).toEqual(expect.arrayContaining(['size-3.5', 'inline-block']));
        expect(dialog().querySelector('[class*="admin-wizard-spin"]')).toBeNull();
    });

    it('shows a lapsed session as the 401 envelope\'s sentence, never as a day with no times', async () => {
        fetched.mockImplementation(async () => new Response(JSON.stringify({ code: 'unauthenticated', message: 'unauthenticated', data: { status: 401, detail: SIGN_IN_DETAIL } }), { status: 401 }));
        render();
        await toWhen();

        expect(dialog().querySelector('.admin-notice--error')?.textContent).toBe(SIGN_IN_DETAIL);
        expect(dialog().querySelector('.admin-notice--info')).toBeNull();
        expect(dialog().querySelector('.admin-wizard-slot')).toBeNull();
    });

    it('shows a page answered in place of the day\'s list, a followed redirect to the sign-in page, as an error with its sentence and never as a day with no times', async () => {
        fetched.mockImplementation(async () => new Response('<!doctype html><html><body><h1>Sign in</h1></body></html>', { status: 200, headers: { 'Content-Type': 'text/html' } }));
        render();
        await toWhen();

        expect(dialog().querySelector('.admin-notice--error')?.textContent).toBe(wiz.text(UNREADABLE));
        expect(dialog().querySelector('.admin-notice--info')).toBeNull();
        expect(dialog().querySelector('.admin-wizard-slot')).toBeNull();
    });
});

describe('step 2 and the submission, 4.4', () => {
    it('shows the date, the span with the end from the geometry less the last tail, and a Service line per segment, and follows name and email', async () => {
        render();
        press(dialog().querySelector('.admin-wizard-add'));
        choose('#wizard-service-1', '3');
        choose('#wizard-staff-0', '2');
        await toCustomer();

        expect(dialog().querySelector('.admin-wizard-summary__date')?.textContent).toBe('03/11/2026');
        expect(dialog().querySelector('.admin-wizard-summary__time')?.textContent).toBe(wiz.text('summary.span', { time: '10:00', end: '11:45' }));
        expect([...dialog().querySelectorAll('.admin-wizard-summary__service')].map((line) => line.textContent)).toEqual([wiz.text('summary.service_staff', { service: 'Haircut', staff: 'Elena Papadaki' }), wiz.text('summary.service_staff', { service: 'Colour', staff: wiz.text(ANY_STAFF) })]);
        expect(dialog().querySelector<HTMLButtonElement>('.admin-wizard-create')?.disabled).toBe(true);

        type('.admin-wizard-name', ' Ada North ');
        type('.admin-wizard-email', 'not-an-email');

        expect(dialog().querySelector<HTMLButtonElement>('.admin-wizard-create')?.disabled).toBe(false);
        expect(values()[2]).toBe('Ada North');
    });

    it('names the service alone where the choice was never real', async () => {
        render({ entitlements: [], catalog: { ...CATALOG, members: 1 } });
        await toCustomer();

        expect([...dialog().querySelectorAll('.admin-wizard-summary__service')].map((line) => line.textContent)).toEqual(['Haircut']);
    });

    it('sets the summary as a description list inside an inner card, placed beside the fields from a roomy dialog without moving it in the reading order, its span in lining figures', async () => {
        render();
        await toCustomer();

        const summary = dialog().querySelector('.admin-wizard-summary');
        const card = summary?.parentElement ?? null;
        expect(summary?.tagName).toBe('DL');
        expect(innerCard(card)).toBe(true);
        expect(['@xl:col-start-2', '@xl:row-start-1'].filter((name) => !card?.classList.contains(name))).toEqual([]);
        expect(card?.nextElementSibling?.querySelector('#wizard-customer-name')).not.toBeNull();

        const customer = dialog().querySelector('.admin-wizard-customer');
        expect(customer?.classList.contains('@xl:grid-cols-[minmax(0,1fr)_18.125rem]')).toBe(true);
        expect([...(customer?.classList ?? [])].filter((name) => name.startsWith('desktop:'))).toEqual([]);

        const span = dialog().querySelector('.admin-wizard-summary__time');
        expect(span?.classList.contains('tabular-nums')).toBe(true);
        expect(mono(span)).toBe(false);
    });

    it('sends the customer, the ordered segments and the exact start the answer carried, shows busy with Cancel and the close control disabled, and closes on success', async () => {
        const onClose = render();
        await toCustomer();
        type('.admin-wizard-name', 'Ada North');
        type('.admin-wizard-email', 'not-an-email');
        press(dialog().querySelector('.admin-wizard-create'));

        expect(inertia.post).toHaveBeenCalledTimes(1);
        const [url, body, options] = inertia.post.mock.calls[0] as [string, Record<string, unknown>, Visit];
        expect(url).toBe(CREATE_URL);
        expect(body).toEqual({ customer_name: 'Ada North', customer_email: 'not-an-email', customer_phone: '', items: [{ service_id: 1, staff_id: null }], start: '2026-11-03T08:00:00+00:00', same_staff: false });

        expect(dialog().querySelector<HTMLButtonElement>('.admin-modal__close')?.disabled).toBe(false);

        act(() => options.onStart?.());
        expect(dialog().querySelector('.admin-wizard-create')?.getAttribute('aria-busy')).toBe('true');
        expect(dialog().querySelector('.admin-wizard-create')?.textContent).toContain(wiz.text('creating'));
        expect(dialog().querySelector<HTMLButtonElement>('.admin-wizard-cancel')?.disabled).toBe(true);
        expect(dialog().querySelector<HTMLButtonElement>('.admin-modal__close')?.disabled).toBe(true);

        act(() => options.onSuccess?.());
        act(() => options.onFinish?.());
        expect(onClose).toHaveBeenCalledTimes(1);
    });

    it('shows a refusal as an error toast naming the failing segment and keeps the wizard open with the slot and every answer intact', async () => {
        const onClose = render();
        await toCustomer();
        type('.admin-wizard-name', 'Ada North');
        type('.admin-wizard-email', 'ada@example.test');
        press(dialog().querySelector('.admin-wizard-create'));

        const options = inertia.post.mock.calls[0]?.[2] as Visit;
        act(() => options.onStart?.());
        const refused = new CustomEvent('invalid', { cancelable: true, detail: { response: { status: 409, data: { code: 'overlap', message: 'overlap', data: { status: 409, detail: OVERLAP_DETAIL, segment: 0 } } } } });
        act(() => inertia.listeners.get('invalid')?.(refused));
        act(() => options.onFinish?.());

        expect(refused.defaultPrevented).toBe(true);
        expect(document.querySelector('.admin-toast[data-kind="error"]')?.textContent).toContain(wiz.text('refused_segment_service', { sentence: OVERLAP_DETAIL, n: '1', service: 'Haircut' }));
        expect(onClose).not.toHaveBeenCalled();
        expect(dialog().querySelector<HTMLInputElement>('.admin-wizard-name')?.value).toBe('Ada North');
        expect(values()).toEqual(['Haircut', TEN, 'Ada North']);

        press(steps()[1] ?? null);
        await settle();

        expect(dialog().querySelector('.admin-wizard-slot[aria-pressed="true"]')?.textContent).toBe('10:00');
    });

    it('lets a toast raised while the wizard is open be reached by assistive technology', async () => {
        render();
        await toCustomer();
        type('.admin-wizard-name', 'Ada');
        type('.admin-wizard-email', 'a@b');
        press(dialog().querySelector('.admin-wizard-create'));
        const options = inertia.post.mock.calls[0]?.[2] as Visit;
        act(() => options.onStart?.());
        act(() => inertia.listeners.get('invalid')?.(new CustomEvent('invalid', { cancelable: true, detail: { response: { status: 503, data: { code: 'busy', data: { status: 503, detail: BUSY_DETAIL } } } } })));

        let node: Element | null = document.querySelector('.admin-toast[data-kind="error"]');
        expect(node).not.toBeNull();
        expect(document.querySelector('[data-aria-hidden]')).not.toBeNull();

        while (node !== null) {
            expect(node.getAttribute('aria-hidden')).not.toBe('true');
            node = node.parentElement;
        }
    });
});

/** The page header's button as the layout writes it, over the opening API a calendar slot will use too. */
function NewBookingButton() {
    const { open } = useNewBooking();

    return (
        <button type="button" className="admin-new-booking" onClick={() => open()}>
            {keyedShell().text('top_bar.new_booking')}
        </button>
    );
}

describe('the opening, 1.5', () => {
    const PROPS: SharedProps = {
        tenant: { name: 'Luna', slug: 'luna', timezone: 'Europe/Athens', currency: 'EUR' },
        auth: { user: { id: 1, name: 'Alex Owner', role: 'owner' } },
        flash: null,
        errors: {},
        brand: { name: 'Example Bookings' },
        language: 'en',
        strings: { shell: KEYED_SHELL },
        permissions: ['viewBookings', 'manageBookings'],
        entitlements: ['staff'],
        narrowed: [],
        subscription: { state: 'active' },
        free: false,
        settings: { granularity: 5, week_start: 1, date_format: 'd/m/Y', time_format: 'H:i', colour_by: 'staff', messages: [] },
        nav: [],
        catalog: CATALOG,
        wizard: KEYED_WIZARD,
    };

    it('opens the wizard from the button over a screen holding its own modal, and Escape closes the wizard and nothing beneath it', async () => {
        const closeBeneath = vi.fn();
        mounted.render(
            <ToastProvider>
                <NewBookingProvider props={PROPS}>
                    <NewBookingButton />
                    <Modal open title="Booking" onClose={closeBeneath}>
                        <p>What was booked</p>
                    </Modal>
                </NewBookingProvider>
            </ToastProvider>,
        );

        expect(document.querySelectorAll('.admin-modal__frame').length).toBe(1);

        press(document.querySelector('.admin-new-booking'));
        await settle();

        expect(document.querySelectorAll('.admin-modal__frame').length).toBe(2);
        expect(document.querySelector('.admin-wizard')).not.toBeNull();

        key(document.querySelector('.admin-wizard'), 'Escape');
        await settle();

        expect(document.querySelector('.admin-wizard')).toBeNull();
        expect(document.querySelectorAll('.admin-modal__frame').length).toBe(1);
        expect(closeBeneath).not.toHaveBeenCalled();
    });

    it('opens over two different pages with the words the shared props carry beside the catalog, whatever sections the page itself names', async () => {
        const pages: [string, SharedProps][] = [
            ['admin-bookings-screen', { ...PROPS, strings: { shell: KEYED_SHELL, bookings: KEYED_BOOKINGS } as SharedProps['strings'] }],
            ['admin-customers-screen', { ...PROPS, strings: { shell: KEYED_SHELL, customers: KEYED_CUSTOMERS } as SharedProps['strings'] }],
        ];

        for (const [screen, props] of pages) {
            mounted.render(
                <ToastProvider>
                    <NewBookingProvider props={props}>
                        <main className={screen}>
                            <NewBookingButton />
                        </main>
                    </NewBookingProvider>
                </ToastProvider>,
            );

            press(document.querySelector(`.${screen} .admin-new-booking`));
            await settle();

            expect(document.querySelector('.admin-modal__title')?.textContent).toBe(wiz.text('title'));
            expect([...document.querySelectorAll('.admin-wizard-step__name')].map((name) => name.textContent)).toEqual(
                (['steps.services', 'steps.when', 'steps.customer'] as const).map((step, at) => wiz.text('step', { n: String(at + 1), step: wiz.text(step) })),
            );
            expect(document.querySelector('.admin-wizard-cancel')?.textContent).toBe(wiz.text('cancel'));

            mounted.unmount();
            mounted = mount();
        }
    });

    it('mounts nothing for a user the server handed no catalog', () => {
        mounted.render(
            <ToastProvider>
                <NewBookingProvider props={{ ...PROPS, catalog: null }}>
                    <NewBookingButton />
                </NewBookingProvider>
            </ToastProvider>,
        );

        press(document.querySelector('.admin-new-booking'));

        expect(document.querySelector('.admin-wizard')).toBeNull();
    });
});
