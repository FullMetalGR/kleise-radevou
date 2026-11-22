import { act, type ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, type Mock, vi } from 'vitest';
import { mount, type Mounted, press } from '@/components/ui/harness';
import { formatAmount } from '@/lib/format';
import { blankForm, patchOf } from '@/services/model';
import { KEYED_SERVICES, KEYED_SETUP, keyedServices, keyedSetup } from '@/testing/keyed';
import { ToastProvider } from '@/toast/toasts';
import type { Setup, StepKey } from '../model';
import { SetupWizard } from '../SetupWizard';
import { SERVICES_URL, type ServicesStepStrings } from './ServicesStep';

/*
 * The Services step, open in the wizard: the services in a short list with their duration and
 * price, a short add form of name, duration and price in the account's currency in the Services
 * screen's words, and a line linking that screen for anything else. The add posts the screen's
 * create, every other field its blank value, by a visit that keeps the page, and blanks the form
 * once it lands; a refusal naming a drawn field is said on it, one naming none in an error toast.
 * The primary is the wizard's Next, held with its reason while no service exists and busy while
 * the add is in flight. Once the add has landed the focus is on the name. A name, a duration or a
 * price typed is asked about before it is left.
 */

const inertia = vi.hoisted(() => ({ post: vi.fn(), listeners: new Map<string, (event: Event) => void>() }));

vi.mock('@inertiajs/react', () => ({
    router: {
        post: inertia.post,
        on: (name: string, listener: (event: Event) => void) => {
            inertia.listeners.set(name, listener);

            return () => inertia.listeners.delete(name);
        },
    },
    usePage: () => ({ props: { strings: { services: KEYED_SERVICES }, tenant: { currency: 'EUR' }, settings: { granularity: 15 } } }),
    Link: ({ href, className, children }: { href: string; className?: string; children: ReactNode }) => (
        <a href={href} className={className}>
            {children}
        </a>
    ),
}));

const words = keyedSetup();
const services = keyedServices();
const step = KEYED_SETUP.steps.services as ServicesStepStrings;

type Row = { id: number; name: string; duration_min: number; price_minor: number; currency: string };

type Visit = { preserveState?: boolean; preserveScroll?: boolean; onStart?: () => void; onSuccess?: () => void; onFinish?: () => void };

const CUT: Row = { id: 4, name: 'Haircut', duration_min: 45, price_minor: 2500, currency: 'EUR' };

let view: Mounted;
let onStep: Mock<(step: StepKey) => void>;
let onClose: Mock<() => void>;

function open(rows: Row[]) {
    const setup: Setup = {
        steps: [
            { key: 'profile', done: true, skipped: false },
            { key: 'services', done: rows.length > 0, skipped: false },
            { key: 'link', done: false, skipped: false },
        ],
        opened: true,
        opens: null,
        data: { services: { services: rows } },
    };

    view.render(
        <ToastProvider>
            <SetupWizard setup={setup} table={KEYED_SETUP} words={words} step="services" onStep={onStep} onClose={onClose} />
        </ToastProvider>,
    );
}

beforeEach(() => {
    inertia.post.mockReset();
    inertia.listeners.clear();
    onStep = vi.fn<(step: StepKey) => void>();
    onClose = vi.fn<() => void>();
    view = mount();
});

afterEach(() => {
    view.unmount();
});

const q = (selector: string) => document.body.querySelector(selector);

function valueAt(selector: string): string | null {
    const field = q(selector);

    return field instanceof HTMLInputElement ? field.value : null;
}

/** Sets a text field's value as a person types it, through React's own value setter. */
function typeInto(selector: string, text: string): void {
    const field = q(selector) as HTMLInputElement;

    act(() => {
        Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')?.set?.call(field, text);
        field.dispatchEvent(new Event('input', { bubbles: true }));
    });
}

function refuse(sentence: string, field: string | null): void {
    const data = field === null ? { detail: sentence } : { detail: sentence, field };

    act(() => inertia.listeners.get('invalid')?.(new CustomEvent('invalid', { cancelable: true, detail: { response: { status: 400, data: { code: 'invalid_request', data } } } })));
}

const toasts = () => [...document.body.querySelectorAll('.admin-toast[data-kind="error"] p')].map((line) => line.textContent);

const primary = () => q('.admin-setup__primary') as HTMLButtonElement;

describe('the Services step', () => {
    it('says no service exists yet and holds Next with its reason, the form blank on the granularity', () => {
        open([]);

        expect(q('.admin-setup-services__help')?.textContent).toBe(step.help);
        expect(q('.admin-setup-services__empty')?.textContent).toBe(step.empty);
        expect(q('.admin-setup-services__list')).toBeNull();
        expect(['#service-name', '#service-duration_min', '#service-price'].map(valueAt)).toEqual(['', '15', '']);
        expect(q('label[for="service-price"]')?.textContent).toBe(services.text('form.price', { currency: 'EUR' }));
        expect([primary().textContent, primary().disabled]).toEqual([words.text('next'), true]);
        expect(q('#admin-setup-blocked')?.textContent).toBe(step.blocked);
        expect(q('.admin-setup__skip')).toBeNull();
    });

    it('lists each service with its duration and price, Next the wizard\'s own once one exists, and links the Services screen', () => {
        open([CUT]);

        expect(q('.admin-setup-services__name')?.textContent).toBe('Haircut');
        expect(q('.admin-setup-services__figures')?.textContent).toBe(services.text('table.minutes', { n: '45' }) + formatAmount(2500, 'EUR', 'en'));
        expect([primary().textContent, primary().disabled]).toEqual([words.text('next'), false]);
        expect(q('#admin-setup-blocked')).toBeNull();

        const link = q('.admin-setup-services__open');
        expect([link?.textContent, link?.getAttribute('href')]).toEqual([step.open, '/admin/services']);
        expect(q('.admin-setup-services__more')?.textContent).toBe(`${step.more} ${step.open}`);

        press(primary());
        expect(onStep).toHaveBeenCalledWith('link');
    });

    it('sets the list and the add form each in an inner card, neither drawing an edge of its own', () => {
        open([CUT]);

        const drawn = (element: Element | null | undefined) => [...(element?.classList ?? [])].filter((name) => name === 'border' || name.startsWith('rounded-'));

        for (const hook of ['ul.admin-setup-services__list', 'form.admin-setup-services__form']) {
            const card = q(hook)?.parentElement;

            expect(card?.tagName).toBe('SECTION');
            expect(['bg-card', 'rounded-inner', 'ring-[1.5px]', 'ring-border'].every((look) => card?.classList.contains(look))).toBe(true);
            expect(drawn(q(hook))).toEqual([]);
        }
    });

    it('lets a long unbroken service name shrink and break anywhere, so the inner card never cuts it', () => {
        open([CUT]);

        expect(['min-w-0', 'wrap-anywhere'].every((look) => q('.admin-setup-services__name')?.classList.contains(look))).toBe(true);
    });

    it('holds Add until the service has a name, said beside it', () => {
        open([]);

        expect((q('.admin-setup-services__add') as HTMLButtonElement).disabled).toBe(true);
        expect(q('.admin-setup-services__reason')?.textContent).toBe(services.text('form.needs_name'));

        typeInto('#service-name', 'Haircut');
        expect((q('.admin-setup-services__add') as HTMLButtonElement).disabled).toBe(false);
        expect(q('.admin-setup-services__reason')).toBeNull();
    });

    it('adds the service as the Services screen creates one, every other field blank, busy until it lands, then blanks the form', () => {
        open([]);
        typeInto('#service-name', '  Haircut ');
        typeInto('#service-duration_min', '45');
        typeInto('#service-price', '25,50');

        press(q('.admin-setup-services__add'));

        expect(inertia.post).toHaveBeenCalledTimes(1);
        const [url, body, visit] = inertia.post.mock.lastCall as [string, unknown, Visit];
        expect([url, body]).toEqual([SERVICES_URL, patchOf({ ...blankForm(15), name: '  Haircut ', duration_min: '45', price: '25,50' }, 'EUR', true)]);
        expect(body).toMatchObject({ name: 'Haircut', duration_min: 45, price_minor: 2550, currency: 'EUR', payment_mode: 'onsite' });
        expect(visit).toMatchObject({ preserveState: true, preserveScroll: true });

        act(() => visit.onStart?.());
        expect(q('.admin-setup-services__add')?.textContent).toBe(step.adding);
        expect(primary().textContent).toBe(step.adding);

        act(() => {
            visit.onSuccess?.();
            visit.onFinish?.();
        });
        expect(['#service-name', '#service-duration_min', '#service-price'].map(valueAt)).toEqual(['', '15', '']);
        expect(onStep).not.toHaveBeenCalled();
    });

    it('puts the focus on the name once the add has landed and the form is blank', () => {
        open([]);
        typeInto('#service-name', 'Haircut');
        (q('.admin-setup-services__add') as HTMLButtonElement).focus();

        press(q('.admin-setup-services__add'));
        const visit = inertia.post.mock.lastCall?.[2] as Visit;
        act(() => visit.onStart?.());
        expect(document.activeElement).not.toBe(q('#service-name'));

        act(() => {
            visit.onSuccess?.();
            visit.onFinish?.();
        });
        expect(valueAt('#service-name')).toBe('');
        expect(document.activeElement).toBe(q('#service-name'));
    });

    it('asks before a typed name, duration or price is left, and asks nothing of a blank form or once the add has landed', () => {
        open([CUT]);

        for (const [field, typed, blank] of [
            ['#service-name', 'Haircut', ''],
            ['#service-duration_min', '45', '15'],
            ['#service-price', '25', ''],
        ] as const) {
            typeInto(field, typed);
            press(q('.admin-setup__close'));
            expect(q('.admin-setup-discard .admin-modal__title')?.textContent).toBe(words.text('discard.title'));
            press(q('.admin-setup-discard__keep'));
            typeInto(field, blank);
        }

        expect(onClose).not.toHaveBeenCalled();

        typeInto('#service-name', 'Haircut');
        press(q('.admin-setup-services__add'));
        const visit = inertia.post.mock.lastCall?.[2] as Visit;
        act(() => visit.onStart?.());
        act(() => {
            visit.onSuccess?.();
            visit.onFinish?.();
        });

        press(q('.admin-setup__close'));
        expect(q('.admin-setup-discard')).toBeNull();
        expect(onClose).toHaveBeenCalledTimes(1);
    });

    it('says a refused duration or price on its field, and a refusal naming no drawn field in an error toast', () => {
        open([]);
        typeInto('#service-name', 'Haircut');
        typeInto('#service-duration_min', '7');

        press(q('.admin-setup-services__add'));
        let visit = inertia.post.mock.lastCall?.[2] as Visit;
        act(() => visit.onStart?.());
        refuse('Not on the grid.', 'duration_min');
        act(() => visit.onFinish?.());
        expect(q('#service-duration_min-error')?.textContent).toBe('Not on the grid.');

        press(q('.admin-setup-services__add'));
        visit = inertia.post.mock.lastCall?.[2] as Visit;
        act(() => visit.onStart?.());
        refuse('Too dear.', 'price_minor');
        act(() => visit.onFinish?.());
        expect(q('#service-price-error')?.textContent).toBe('Too dear.');

        press(q('.admin-setup-services__add'));
        visit = inertia.post.mock.lastCall?.[2] as Visit;
        act(() => visit.onStart?.());
        refuse('The horizon is refused.', 'horizon_days');
        act(() => visit.onFinish?.());
        expect(toasts()).toEqual(['The horizon is refused.']);
        expect(valueAt('#service-name')).toBe('Haircut');
    });
});
