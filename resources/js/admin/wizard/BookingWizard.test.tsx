import { act } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { key, mount, type Mounted, press } from '@/components/ui/harness';
import { FORCED_EDGE } from '@/components/ui/input';
import { Modal } from '@/components/ui/modal';
import { formatAmount, type TenantClock } from '@/lib/format';
import { dayTitle } from '@/lib/names';
import { KEYED_BOOKINGS, KEYED_CUSTOMERS, KEYED_SHELL, KEYED_WIZARD, keyedShell, keyedWizard } from '@/testing/keyed';
import { ToastProvider } from '@/toast/toasts';
import type { SharedProps } from '@/types';
import { BookingWizard, CREATE_URL } from './BookingWizard';
import { AVAILABILITY_URL, UNREADABLE } from './availability';
import { ANY_STAFF, type Catalog, lengthLabel, NO_ANSWER, NO_TIMES, serviceHint } from './model';
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
        { id: 1, name: 'Haircut', duration_min: 45, processing_min: 0, price_minor: 3500, currency: 'EUR', staff: [2, 3] },
        { id: 3, name: 'Colour', duration_min: 60, processing_min: 30, price_minor: 8000, currency: 'EUR', staff: [2, 3] },
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

describe('the strip', () => {
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

describe('a customer\'s Book now', () => {
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

describe('step 0', () => {
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
        render({ catalog: { ...CATALOG, services: [{ id: 1, name: 'Haircut', duration_min: 45, processing_min: 0, price_minor: 3500, currency: 'EUR', staff: [2] }, ...CATALOG.services.slice(1)] } });

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

    it('shows the segment legend on a single segment in the display font, hints a chosen service with its length and price, and puts the plus icon on Add segment', () => {
        render();

        const legend = dialog().querySelector('.admin-wizard-segment__caption');
        expect(legend?.textContent).toBe(wiz.text('segment', { n: '1' }));
        expect(['font-display', 'text-body', 'font-semibold'].filter((name) => !legend?.classList.contains(name))).toEqual([]);

        const haircut = CATALOG.services[0];

        if (haircut === undefined) {
            throw new Error('The catalog lost Haircut.');
        }

        const select = dialog().querySelector('#wizard-service-0');
        expect(dialog().querySelector('#wizard-service-0-help')?.textContent).toBe(serviceHint(haircut, wiz));
        expect(select?.getAttribute('aria-describedby')).toContain('wizard-service-0-help');

        choose('.admin-wizard-service', '');

        expect(dialog().querySelector('#wizard-service-0-help')).toBeNull();
        expect(dialog().querySelector('.admin-wizard-add svg')).not.toBeNull();
        expect(dialog().querySelector('.admin-wizard-add svg')?.getAttribute('aria-hidden')).toBe('true');
    });
});

/** True when the element is an inner card: the smaller radius inside a line ring. */
function innerCard(element: Element | null): boolean {
    return element?.tagName === 'SECTION' && ['rounded-inner', 'ring-border'].every((name) => element.classList.contains(name));
}

describe('step 1', () => {
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
        expect([...dialog().querySelectorAll<HTMLButtonElement>('.admin-wizard-day')].some((day) => day.disabled)).toBe(false);
        expect([...dialog().querySelectorAll('.admin-wizard-group__title')].map((title) => title.textContent)).toEqual([`${wiz.text('periods.morning')} (2)`, `${wiz.text('periods.afternoon')} (1)`, `${wiz.text('periods.evening')} (1)`]);
        expect([...dialog().querySelectorAll('.admin-wizard-slot')].map((slot) => slot.textContent)).toEqual(['09:00', '10:00', '14:00', '17:30']);
        expect(dialog().querySelector<HTMLButtonElement>('.admin-wizard-continue')?.disabled).toBe(true);

        press(dialog().querySelector('.admin-wizard-slot[value="2026-11-03T08:00:00+00:00"]'));

        expect(dialog().querySelector('.admin-wizard-slot[aria-pressed="true"]')?.textContent).toBe('10:00');
        expect(dialog().querySelector<HTMLButtonElement>('.admin-wizard-continue')?.disabled).toBe(false);
        expect(values()[1]).toBe(TEN);

        press(dialog().querySelector('.admin-wizard-day[value="2026-11-02"]'));
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
        press(dialog().querySelector('.admin-wizard-day[value="2026-11-03"]'));

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

    it('sets each slot as a 44 pixel pill of lining figures, pressed once chosen, three to a line at phone width and filling the column from a roomy dialog', async () => {
        render();
        await toWhen();
        press(dialog().querySelector(`.admin-wizard-slot[value="${STARTS[1]}"]`));

        const slots = [...dialog().querySelectorAll('.admin-wizard-slot')];
        expect(slots.map((slot) => slot.getAttribute('aria-pressed'))).toEqual(['false', 'true', 'false', 'false']);
        expect(slots.map((slot) => ['h-11', 'rounded-field', 'tabular-nums'].filter((name) => !slot.classList.contains(name)))).toEqual([[], [], [], []]);
        expect(slots.filter(mono)).toEqual([]);
        expect(slots.map((slot) => slot.parentElement?.classList.contains('grid-cols-3'))).toEqual([true, true, true, true]);
        expect(['gap-2', '@max-sm:gap-1.5', '@xl:grid-cols-[repeat(auto-fill,minmax(5.25rem,1fr))]'].filter((name) => !slots[0]?.parentElement?.classList.contains(name))).toEqual([]);
        expect([...(slots[0]?.parentElement?.classList ?? [])].filter((name) => name.startsWith('desktop:'))).toEqual([]);
    });

    it('shows the seven days of the chosen day\'s week from the week start, the chosen one pressed and today marked, each named in full', async () => {
        render({ weekStart: 7 });
        await toWhen();

        const days = [...dialog().querySelectorAll<HTMLButtonElement>('.admin-wizard-day')];
        expect(days.map((day) => day.value)).toEqual(['2026-11-01', '2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05', '2026-11-06', '2026-11-07']);
        expect(days.map((day) => day.getAttribute('aria-pressed'))).toEqual(['false', 'false', 'true', 'false', 'false', 'false', 'false']);
        expect(days.map((day) => day.getAttribute('aria-current'))).toEqual([null, null, 'date', null, null, null, null]);
        expect(days[2]?.getAttribute('aria-label')).toBe(dayTitle(2026, 11, 3, 'en'));
        expect([days[2]?.querySelector('.admin-wizard-day__weekday')?.textContent, days[2]?.querySelector('.admin-wizard-day__number')?.textContent]).toEqual(['Tue', '3']);
        expect(['@min-[35.75rem]/week:w-15', 'rounded-[1.125rem]', 'aria-pressed:bg-primary', 'aria-[current=date]:inset-ring-primary'].filter((name) => !days[2]?.classList.contains(name))).toEqual([]);
        expect(['font-display', 'font-semibold'].filter((name) => !days[2]?.querySelector('.admin-wizard-day__number')?.classList.contains(name))).toEqual([]);
    });

    it('sizes the chips by the week row\'s own width, the row spanning the step\'s column beside the summary, never by the dialog\'s body', async () => {
        render();
        await toWhen();

        const week = dialog().querySelector('.admin-wizard-week');
        const days = [...dialog().querySelectorAll<HTMLButtonElement>('.admin-wizard-day')];
        expect(week?.classList.contains('@container/week')).toBe(true);
        expect(week?.parentElement?.classList.contains('admin-wizard-when')).toBe(true);
        expect(days.map((day) => [day.classList.contains('@min-[35.75rem]/week:w-15'), day.parentElement?.classList.contains('@min-[35.75rem]/week:flex-none')])).toEqual(days.map(() => [true, true]));
        expect(days[0]?.closest('ul')?.classList.contains('@min-[35.75rem]/week:flex-none')).toBe(true);

        const queries = [week, ...(week?.querySelectorAll('*') ?? [])].flatMap((part) => [...(part?.classList ?? [])]).filter((name) => /^(@|(sm|md|lg|xl|2xl):)/.test(name));
        expect(queries.filter((name) => name !== '@container/week' && !name.includes('/week:'))).toEqual([]);
    });

    it('marks today with a dot under its number besides the ring, drawn in the chip\'s own ink so it shows on a plain chip and on the chosen one alike', async () => {
        render();
        await toWhen();

        const today = dialog().querySelector<HTMLButtonElement>('.admin-wizard-day[aria-current="date"]');
        expect(today?.getAttribute('aria-pressed')).toBe('true');
        expect(
            ['relative', 'aria-[current=date]:after:absolute', 'aria-[current=date]:after:bottom-1.5', 'aria-[current=date]:after:left-1/2', 'aria-[current=date]:after:-translate-x-1/2', 'aria-[current=date]:after:size-1', 'aria-[current=date]:after:rounded-full', 'aria-[current=date]:after:border-2', 'aria-[current=date]:after:border-current'].filter((name) => !today?.classList.contains(name)),
        ).toEqual([]);
        expect([...(today?.classList ?? [])].filter((name) => name.startsWith('after:'))).toEqual([]);
    });

    it('loads a chip\'s day, and moves a week either way from the named round buttons onto the same weekday, loading its times', async () => {
        render();
        await toWhen();

        press(dialog().querySelector('.admin-wizard-day[value="2026-11-05"]'));
        await settle();

        expect(steps()[1]?.value).toBe('2026-11-05');
        expect(new URLSearchParams(String(fetched.mock.calls.at(-1)?.[0]).split('?')[1]).get('from')).toBe('2026-11-05');

        const next = dialog().querySelector('.admin-wizard-week-next');
        expect(next?.textContent).toBe(wiz.text('week.next'));
        expect(next?.classList.contains('rounded-full')).toBe(true);
        press(next);
        await settle();

        expect(steps()[1]?.value).toBe('2026-11-12');
        expect(new URLSearchParams(String(fetched.mock.calls.at(-1)?.[0]).split('?')[1]).get('from')).toBe('2026-11-12');
        expect([...dialog().querySelectorAll<HTMLButtonElement>('.admin-wizard-day')].map((day) => day.value)[0]).toBe('2026-11-09');
        expect(dialog().querySelector('.admin-wizard-day[aria-current]')).toBeNull();

        const previous = dialog().querySelector('.admin-wizard-week-previous');
        expect(previous?.textContent).toBe(wiz.text('week.previous'));
        press(previous);
        press(dialog().querySelector('.admin-wizard-week-previous'));
        await settle();

        expect(steps()[1]?.value).toBe('2026-10-29');
        expect(dialog().querySelector('.admin-wizard-day[aria-pressed="true"]')?.getAttribute('value')).toBe('2026-10-29');
    });

    it('reaches a day two months ahead and a past day through the date field under the chips, set to the right and full width at phone width', async () => {
        const shell = keyedShell();
        render();
        await toWhen();

        const field = dialog().querySelector<HTMLButtonElement>('.admin-wizard-date');
        expect(field?.value).toBe(TODAY);
        expect(field?.getAttribute('aria-label')).toBe(wiz.text('date_field'));
        expect(['w-full', '@xl:w-42'].filter((name) => !field?.classList.contains(name))).toEqual([]);
        expect(field?.parentElement?.classList.contains('justify-end')).toBe(true);

        press(field);
        press(document.body.querySelector(`.admin-date-field__popover button[aria-label="${shell.text('kit.month_grid.next')}"]`));
        press(document.body.querySelector(`.admin-date-field__popover button[aria-label="${shell.text('kit.month_grid.next')}"]`));
        press(document.body.querySelector('.admin-date-field__popover button[data-date="2027-01-05"]'));
        await settle();

        expect(steps()[1]?.value).toBe('2027-01-05');
        expect(new URLSearchParams(String(fetched.mock.calls.at(-1)?.[0]).split('?')[1]).get('from')).toBe('2027-01-05');
        expect(dialog().querySelector('.admin-wizard-day[aria-pressed="true"]')?.getAttribute('value')).toBe('2027-01-05');

        press(dialog().querySelector('.admin-wizard-date'));
        for (let step = 0; step < 3; step++) {
            press(document.body.querySelector(`.admin-date-field__popover button[aria-label="${shell.text('kit.month_grid.previous')}"]`));
        }
        press(document.body.querySelector('.admin-date-field__popover button[data-date="2026-10-20"]'));
        await settle();

        expect(steps()[1]?.value).toBe('2026-10-20');
    });

    it('offers no Clear in the date field, since the booking always has a day', async () => {
        render();
        await toWhen();

        press(dialog().querySelector('.admin-wizard-date'));

        expect(document.body.querySelector('.admin-date-field__popover')).not.toBeNull();
        expect(document.body.querySelector('.admin-date-field__clear')).toBeNull();
    });

    it('captions the times with the chosen day over the zone line, and hints a time is needed until one is chosen', async () => {
        render();
        await toWhen();

        const caption = dialog().querySelector('.admin-wizard-caption');
        expect(caption?.firstChild?.textContent).toBe(wiz.text('available'));
        expect(caption?.querySelector('.admin-wizard-caption__day')?.textContent).toBe('Tue 03/11/2026');
        expect(caption?.nextElementSibling?.classList.contains('admin-wizard-zone')).toBe(true);
        expect(dialog().querySelector('.admin-wizard-hint')?.textContent).toBe(wiz.text('time_hint'));

        press(dialog().querySelector(`.admin-wizard-slot[value="${STARTS[1]}"]`));

        expect(dialog().querySelector('.admin-wizard-hint')).toBeNull();
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

describe('step 2 and the submission', () => {
    it('summarises the chain on the customer step, each line with its price, its own times and its member, the day on a chip, and follows name and email', async () => {
        render();
        press(dialog().querySelector('.admin-wizard-add'));
        choose('#wizard-service-1', '3');
        choose('#wizard-staff-0', '2');
        await toCustomer();

        expect([...dialog().querySelectorAll('.admin-wizard-summary__service')].map((line) => line.textContent)).toEqual(['Haircut', 'Colour']);
        expect([...dialog().querySelectorAll('.admin-wizard-summary__span')].map((line) => line.textContent)).toEqual([
            wiz.text('joined', { first: wiz.text('summary.span', { time: '10:00', end: '10:45' }), second: 'Elena Papadaki' }),
            wiz.text('joined', { first: wiz.text('summary.span', { time: '10:45', end: '11:45' }), second: wiz.text(ANY_STAFF) }),
        ]);
        expect(dialog().querySelector('.admin-wizard-summary__when')?.textContent).toBe(wiz.text('joined', { first: 'Tue 03/11/2026', second: '10:00' }));
        expect(dialog().querySelector('.admin-wizard-summary__duration')?.textContent).toBe(lengthLabel(105, wiz));
        expect(dialog().querySelector('.admin-wizard-summary__total')?.textContent).toBe(formatAmount(11500, 'EUR', wiz.locale));
        expect(dialog().querySelector<HTMLButtonElement>('.admin-wizard-create')?.disabled).toBe(true);

        type('.admin-wizard-name', ' Ada North ');
        type('.admin-wizard-email', 'not-an-email');

        expect(dialog().querySelector<HTMLButtonElement>('.admin-wizard-create')?.disabled).toBe(false);
        expect(values()[2]).toBe('Ada North');
    });

    it('gives the times alone on a line where the member choice was never real', async () => {
        render({ entitlements: [], catalog: { ...CATALOG, members: 1 } });
        await toCustomer();

        expect([...dialog().querySelectorAll('.admin-wizard-summary__span')].map((line) => line.textContent)).toEqual([wiz.text('summary.span', { time: '10:00', end: '10:45' })]);
    });

    it('draws the summary on every step after the step\'s content, in a sticky 290 pixel column beside it once the body is 48rem wide', async () => {
        render();

        for (const step of [0, 1, 2]) {
            if (step === 1) {
                await toWhen();
            }

            if (step === 2) {
                press(dialog().querySelector(`.admin-wizard-slot[value="${STARTS[1]}"]`));
                press(dialog().querySelector('.admin-wizard-continue'));
            }

            const summary = dialog().querySelector('.admin-wizard-summary');
            const layout = summary?.parentElement;
            expect(summary?.querySelector('h3')?.textContent).toBe(wiz.text('summary.title'));
            expect(['@3xl:sticky', '@3xl:top-0'].filter((name) => !summary?.classList.contains(name))).toEqual([]);
            expect(['grid-cols-1', '@3xl:grid-cols-[minmax(0,1fr)_18.125rem]', '@3xl:items-start'].filter((name) => !layout?.classList.contains(name))).toEqual([]);
            expect([...(summary?.classList ?? []), ...(layout?.classList ?? [])].filter((name) => name.startsWith('@xl:'))).toEqual([]);
            expect(layout?.lastElementChild).toBe(summary);
            expect(layout?.closest('.\\@container')).not.toBeNull();
        }
    });

    it('holds the customer fields at 480 pixels at most, with a note naming the source the booking is recorded under', async () => {
        render();
        await toCustomer();

        const customer = dialog().querySelector('.admin-wizard-customer');
        expect(customer?.classList.contains('max-w-[30rem]')).toBe(true);
        expect(customer?.querySelector('#wizard-customer-name')).not.toBeNull();
        expect(customer?.querySelector('.admin-wizard-source')?.textContent).toBe(wiz.text('source_note', { source: keyedShell().text('shared.sources.admin') }));
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

describe('the opening', () => {
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
