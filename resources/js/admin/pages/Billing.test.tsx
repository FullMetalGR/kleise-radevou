import { act, type ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { mount, type Mounted, press } from '@/components/ui/harness';
import { formatAmount } from '@/lib/format';
import { KEYED_BILLING, keyedBilling, keyedShell } from '@/testing/keyed';
import { ToastProvider } from '@/toast/toasts';
import { NOT_DONE } from '@/lib/visits';
import Billing, { type BillingOption, type BillingProps, CHECKOUT_PATH, PLAN_PATH, PORTAL_PATH, STAFF_PATH, TAX_ID_PROP } from './Billing';

/*
 * The Billing screen over a recording router: the notice in each of the five states as the server
 * words it, the grace end in grace alone as date and time in the tenant's zone and formats, the
 * plan card in the catalogue's currency and scale with the narrowing record beside it, Change plan
 * and the portal each showing its busy state and changing nothing until the server answers, the
 * downgrade refusal's sentence beside the control with the Staff link, and a tenant with no
 * subscription shown neither write, a free tenant its one sentence alone. A trial and a trial that
 * ended unpaid read the server's sentence verbatim and their end in a paragraph of the screen's
 * own, as the grace end is, and the checkout's control opens the plan choice where the prop carries
 * one, each plan's button posting it. A closing account reads the server's closing notice at the
 * top with the plan choice inside it, and a free one support as its way back.
 */

const shell = keyedShell();

const words = keyedBilling();

type Visit = { onStart?: () => void; onFinish?: () => void };

const inertia = vi.hoisted(() => ({
    props: {} as Record<string, unknown>,
    post: vi.fn(),
    listeners: new Map<string, (event: Event) => void>(),
}));

vi.mock('@inertiajs/react', () => ({
    Link: ({ href, className, children }: { href: string; className?: string; children: ReactNode }) => (
        <a href={href} className={className}>
            {children}
        </a>
    ),
    usePage: () => ({ props: inertia.props }),
    router: {
        post: inertia.post,
        on: (name: string, listener: (event: Event) => void) => {
            inertia.listeners.set(name, listener);

            return () => inertia.listeners.delete(name);
        },
    },
}));

const FLOOR = { name: 'Solo', amount: 1495, currency: 'EUR', includes: ['floor-line-token', 'one-member-token'] };

const ADD = { plan: 'staff', adds: true, name: 'Team', amount: 2495, currency: 'EUR', blocked: null };

const ACTIVE: BillingProps = {
    notice: { kind: 'success', sentence: 'active-sentence-token', paused: null },
    plan: FLOOR,
    change: ADD,
    unavailable: null,
    portal: true,
    vat: 'vat-token',
    resubscribe: null,
};

let mounted: Mounted;

function render(billing: BillingProps, subscription: Record<string, string> = { state: 'active' }, narrowed: string[] = [], deferred: Record<string, unknown> = {}): void {
    inertia.props = {
        tenant: { name: 'Luna', slug: 'luna', timezone: 'Europe/Athens', currency: 'EUR' },
        auth: { user: { id: 1, name: 'Alex Owner', role: 'owner' } },
        flash: null,
        errors: {},
        permissions: ['manageBilling'],
        entitlements: [],
        narrowed,
        subscription,
        settings: { granularity: 15, week_start: 1, date_format: 'd/m/Y', time_format: 'H:i', messages: [] },
        nav: [],
        billing,
        strings: { billing: KEYED_BILLING },
        ...deferred,
    };
    mounted.render(
        <ToastProvider>
            <Billing />
        </ToastProvider>,
    );
}

function text(selector: string): string | null {
    return document.querySelector(selector)?.textContent ?? null;
}

function button(selector: string): HTMLButtonElement {
    const found = document.querySelector(selector);

    if (!(found instanceof HTMLButtonElement)) {
        throw new Error(`No button at ${selector}.`);
    }

    return found;
}

function lastVisit(): [string, Record<string, unknown>, Visit] {
    const call = inertia.post.mock.lastCall;

    if (call === undefined) {
        throw new Error('No visit was made.');
    }

    return call as [string, Record<string, unknown>, Visit];
}

beforeEach(() => {
    inertia.post.mockReset();
    inertia.listeners.clear();
    mounted = mount();
});

afterEach(() => mounted.unmount());

describe('the state notice', () => {
    it.each([
        ['active', 'success', null],
        ['past_due', 'warning', 'paused-token'],
        ['canceled', 'warning', 'paused-token'],
        ['incomplete', 'warning', 'paused-token'],
    ] as const)('renders %s as its own sentence and kind, with the paused paragraph off active alone and no grace end', (state, kind, paused) => {
        render({ ...ACTIVE, notice: { kind, sentence: `${state}-sentence-token`, paused } }, { state });

        expect(document.querySelector('.admin-billing__notice')?.classList.contains(`admin-notice--${kind}`)).toBe(true);
        expect(text('.admin-billing__sentence')).toBe(`${state}-sentence-token`);
        expect(text('.admin-billing__paused')).toBe(paused);
        expect(document.querySelector('.admin-billing__grace')).toBeNull();
    });

    it('spells the grace end inside grace as date and time in the tenant\'s zone and formats, the hour the window closes on the day it closes', () => {
        render({ ...ACTIVE, notice: { kind: 'warning', sentence: 'grace-sentence-token', paused: 'after-grace-token' } }, { state: 'grace', grace_ends_at: '2026-10-06T22:30:00+00:00' });

        expect(text('.admin-billing__grace')).toBe(words.text('grace_end', { end: '07/10/2026 01:30' }));
        expect(text('.admin-billing__paused')).toBe('after-grace-token');
    });

    it('shows no grace end outside grace even where an end is on the prop', () => {
        render(ACTIVE, { state: 'past_due', grace_ends_at: '2026-10-06T22:30:00+00:00' });

        expect(document.querySelector('.admin-billing__grace')).toBeNull();
    });
});

describe('the plan card', () => {
    it('shows the name, the price in the catalogue\'s currency and scale, what it includes, and the narrowing record beside it', () => {
        render(ACTIVE, { state: 'active' }, ['staff', 'mystery']);

        expect(text('.admin-billing__plan-name')).toBe('Solo');
        expect(text('.admin-billing__price')).toBe(words.text('plan.price', { amount: formatAmount(1495, 'EUR', words.locale) }));
        expect(text('.admin-billing__price')).toContain('14.95');
        expect(Array.from(document.querySelectorAll('.admin-billing__includes li')).map((line) => line.textContent)).toEqual(FLOOR.includes);
        expect(text('.admin-billing__narrowed')).toBe(words.text('plan.narrowed', { features: shell.text('banners.features.staff.name') }));
        expect(text('.admin-billing__change-price')).toBe(words.text('plan.price', { amount: formatAmount(2495, 'EUR', words.locale) }));
        expect(text('.admin-billing__change-price')).toContain('24.95');
    });

    it('spells a price at its own currency\'s scale', () => {
        render({ ...ACTIVE, plan: { ...FLOOR, amount: 5000, currency: 'JPY' } });

        expect(text('.admin-billing__price')).toBe(words.text('plan.price', { amount: formatAmount(5000, 'JPY', words.locale) }));
        expect(text('.admin-billing__price')).toContain('5,000');
    });

    it('names no record when nothing was taken away', () => {
        render(ACTIVE);

        expect(document.querySelector('.admin-billing__narrowed')).toBeNull();
    });
});

describe('Change plan', () => {
    it('posts the plan asked for, shows its busy state and disables the portal, and changes nothing until the server answers', () => {
        render(ACTIVE);
        press(button('.admin-billing__change-button'));

        const [path, body, visit] = lastVisit();

        expect(path).toBe(PLAN_PATH);
        expect(body).toEqual({ plan: 'staff' });

        act(() => visit.onStart?.());

        expect(button('.admin-billing__change-button').getAttribute('aria-busy')).toBe('true');
        expect(text('.admin-billing__change-button')).toContain(words.text('change.busy'));
        expect(button('.admin-billing__change-button').disabled).toBe(true);
        expect(button('.admin-billing__portal').disabled).toBe(true);
        expect(text('.admin-billing__plan-name')).toBe('Solo');

        press(button('.admin-billing__portal'));
        expect(inertia.post).toHaveBeenCalledTimes(1);

        act(() => visit.onFinish?.());

        expect(button('.admin-billing__change-button').disabled).toBe(false);
    });

    it('offers Remove Staff with the refusal\'s own sentence and a link to Staff while it would be refused', () => {
        render({ ...ACTIVE, change: { ...ADD, plan: 'floor', adds: false, name: 'Solo', amount: 1495, blocked: 'refused-token' } });

        const price = words.text('plan.price', { amount: formatAmount(1495, 'EUR', words.locale) });

        expect(text('.admin-billing__change p')).toBe(words.text('change.remove_line', { plan: 'Solo', price }));
        expect(text('.admin-billing__change-button')).toBe(words.text('change.remove'));
        expect(text('.admin-billing__staff-note')).toBe(`refused-token ${words.text('change.staff_link')}`);
        expect(document.querySelector('.admin-billing__staff-link')?.getAttribute('href')).toBe(STAFF_PATH);
    });

    it('toasts a failure the server could not word, and the toast stays', () => {
        render(ACTIVE);
        press(button('.admin-billing__change-button'));

        const [, , visit] = lastVisit();

        act(() => visit.onStart?.());
        act(() => inertia.listeners.get('exception')?.(new CustomEvent('exception', { cancelable: true, detail: { exception: new Error('offline') } })));
        act(() => visit.onFinish?.());

        expect(text('.admin-toast[data-kind="error"]')).toContain(shell.text(NOT_DONE));
    });
});

describe('the limit on the billing writes', () => {
    const LIMITED = { status: 429, data: { code: 'rate_limited', message: 'rate_limited', data: { status: 429, detail: 'limited-token' } } };

    function refuse(selector: string): void {
        press(button(selector));

        const [, , visit] = lastVisit();

        act(() => visit.onStart?.());
        act(() => inertia.listeners.get('invalid')?.(new CustomEvent('invalid', { cancelable: true, detail: { response: LIMITED } })));
        act(() => visit.onFinish?.());
    }

    it('words a press past the limit as a warning notice with role status, never an alert or a toast', () => {
        render(ACTIVE);
        refuse('.admin-billing__portal');

        const notice = document.querySelector('.admin-billing__limited');

        expect(notice?.getAttribute('role')).toBe('status');
        expect(notice?.textContent).toBe(LIMITED.data.data.detail);
        expect(document.querySelector('[role="alert"]')).toBeNull();
        expect(document.querySelector('.admin-toast')).toBeNull();
    });

    it('clears the notice on the next press', () => {
        render(ACTIVE);
        refuse('.admin-billing__change-button');

        expect(text('.admin-billing__limited')).toBe(LIMITED.data.data.detail);

        press(button('.admin-billing__portal'));

        expect(document.querySelector('.admin-billing__limited')).toBeNull();
    });
});

describe('the portal', () => {
    it('posts to the portal route with its busy state', () => {
        render(ACTIVE);
        press(button('.admin-billing__portal'));

        const [path, body, visit] = lastVisit();

        expect(path).toBe(PORTAL_PATH);
        expect(body).toEqual({});

        act(() => visit.onStart?.());

        expect(button('.admin-billing__portal').getAttribute('aria-busy')).toBe('true');
        expect(text('.admin-billing__portal')).toContain(words.text('payment.busy'));
        expect(button('.admin-billing__change-button').disabled).toBe(true);
    });
});

describe('the tax lines', () => {
    it('shows the VAT line above the portal and nothing in the tax id line\'s place before the router loads it', () => {
        render(ACTIVE);

        expect(text('.admin-billing__vat')).toBe('vat-token');
        expect(document.querySelector('.admin-billing__tax-id')).toBeNull();
        expect(text('.admin-billing__payment')).toBe([words.text('payment.title'), words.text('payment.sentence'), 'vat-token', words.text('payment.button')].join(''));
    });

    it('shows the loaded tax id line verbatim, the server\'s sentence whatever its case, between the VAT line and the portal', () => {
        render(ACTIVE, { state: 'active' }, [], { [TAX_ID_PROP]: 'tax-id-token' });

        expect(text('.admin-billing__tax-id')).toBe('tax-id-token');
        expect(text('.admin-billing__payment')).toBe([words.text('payment.title'), words.text('payment.sentence'), 'vat-token', 'tax-id-token', words.text('payment.button')].join(''));
    });
});

describe('no subscription on file', () => {
    it('renders the notice and the sentence saying so, and neither write', () => {
        render({ ...ACTIVE, plan: null, change: null, portal: false, unavailable: 'unavailable-token' });

        expect(text('.admin-billing__sentence')).toBe('active-sentence-token');
        expect(text('.admin-billing__unavailable')).toBe('unavailable-token');
        expect(document.querySelector('.admin-billing__change-button')).toBeNull();
        expect(document.querySelector('.admin-billing__portal')).toBeNull();
        expect(document.querySelector('.admin-billing__plan-name')).toBeNull();
    });
});

describe('a free tenant', () => {
    it('renders the one free sentence as a success notice and nothing else: no plan section, no change, no checkout and no portal', () => {
        render({ notice: { kind: 'success', sentence: 'free-sentence-token', paused: null }, plan: null, change: null, unavailable: null, portal: false, vat: null, resubscribe: null });

        expect(text('.admin-billing__sentence')).toBe('free-sentence-token');
        expect(document.querySelector('.admin-billing__notice')?.className).toContain('success');
        expect(document.querySelector('.admin-billing__plan')).toBeNull();
        expect(document.querySelector('.admin-billing__unavailable')).toBeNull();
        expect(document.querySelector('.admin-billing__change-button')).toBeNull();
        expect(document.querySelector('.admin-billing__resubscribe')).toBeNull();
        expect(document.querySelector('.admin-billing__portal')).toBeNull();
        expect(document.querySelector('.admin-billing__paused')).toBeNull();
    });

    it('keeps the plan section where a narrowing record is all it holds', () => {
        render({ notice: { kind: 'success', sentence: 'free-sentence-token', paused: null }, plan: null, change: null, unavailable: null, portal: false, vat: null, resubscribe: null }, { state: 'active' }, ['staff']);

        expect(text('.admin-billing__narrowed')).toBe(words.text('plan.narrowed', { features: shell.text('banners.features.staff.name') }));
    });
});

describe('the way back from canceled', () => {
    const CANCELED: BillingProps = {
        ...ACTIVE,
        notice: { kind: 'warning', sentence: 'canceled-sentence-token', paused: 'paused-token' },
        change: null,
        unavailable: 'ended-token',
        resubscribe: { label: 'subscribe-again-token', pending: null, plan: 'staff', choice: null },
    };

    it('offers the control beside the ended sentence with no pending sentence, the portal still offered', () => {
        render(CANCELED, { state: 'canceled' });

        expect(text('.admin-billing__sentence')).toBe('canceled-sentence-token');
        expect(text('.admin-billing__resubscribe')).toBe('subscribe-again-token');
        expect(document.querySelector('.admin-billing__pending')).toBeNull();
        expect(document.querySelector('.admin-billing__portal')).not.toBeNull();
    });

    it('adds the on-its-way sentence and the server\'s Continue label while a checkout is open', () => {
        render({ ...CANCELED, resubscribe: { label: 'continue-token', pending: 'on-its-way-token', plan: 'staff', choice: null } }, { state: 'canceled' });

        expect(text('.admin-billing__pending')).toBe('on-its-way-token');
        expect(text('.admin-billing__resubscribe')).toBe('continue-token');
    });

    it('posts the plan the prop names to the checkout route with its busy state, disabling the portal, and changes nothing until the server answers', () => {
        render(CANCELED, { state: 'canceled' });
        press(button('.admin-billing__resubscribe'));

        const [path, body, visit] = lastVisit();

        expect(path).toBe(CHECKOUT_PATH);
        expect(body).toEqual({ plan: 'staff' });

        act(() => visit.onStart?.());

        expect(button('.admin-billing__resubscribe').getAttribute('aria-busy')).toBe('true');
        expect(button('.admin-billing__portal').disabled).toBe(true);
        expect(text('.admin-billing__sentence')).toBe('canceled-sentence-token');
    });

    it('disables the control while the portal is in flight, a press posting nothing more, and gives it back once the portal answers', () => {
        render(CANCELED, { state: 'canceled' });
        press(button('.admin-billing__portal'));

        const [path, , visit] = lastVisit();

        expect(path).toBe(PORTAL_PATH);

        act(() => visit.onStart?.());

        expect(button('.admin-billing__resubscribe').disabled).toBe(true);
        press(button('.admin-billing__resubscribe'));
        expect(inertia.post).toHaveBeenCalledTimes(1);

        act(() => visit.onFinish?.());

        expect(button('.admin-billing__resubscribe').disabled).toBe(false);
    });

    it('offers no control where the prop offers none', () => {
        render(ACTIVE);

        expect(document.querySelector('.admin-billing__resubscribe')).toBeNull();
        expect(document.querySelector('.admin-billing__pending')).toBeNull();
    });
});

const FLOOR_OPTION: BillingOption = { plan: 'floor', name: 'Solo', amount: 1495, currency: 'EUR', blocked: null };

const STAFF_OPTION: BillingOption = { plan: 'staff', name: 'Team', amount: 2495, currency: 'EUR', blocked: null };

const CHOICE = [FLOOR_OPTION, STAFF_OPTION];

const REFUSED = [{ ...FLOOR_OPTION, blocked: 'refused-token' }, STAFF_OPTION];

const TRIAL: BillingProps = {
    notice: { kind: 'success', sentence: 'trial-sentence-token', paused: null },
    plan: { name: 'trial-plan-token', amount: null, currency: null, includes: ['floor-line-token', 'staff-line-token'] },
    change: null,
    unavailable: null,
    portal: false,
    vat: null,
    resubscribe: { label: 'choose-plan-token', pending: null, plan: null, choice: CHOICE },
};

const TRIAL_ENDS = { state: 'trial', trial_ends_at: '2026-10-15T09:00:00+00:00' };

function options(): { name: string | null; price: string | null; button: string | null }[] {
    return Array.from(document.querySelectorAll('.admin-billing__option')).map((option) => ({
        name: option.querySelector('.admin-billing__option-name')?.textContent ?? null,
        price: option.querySelector('.admin-billing__option-price')?.textContent ?? null,
        button: option.querySelector('.admin-billing__option-button')?.textContent ?? null,
    }));
}

describe('a trial', () => {
    it('spells the trial\'s end in a paragraph of its own under the server\'s sentence, as date and time in the tenant\'s zone and formats, with no paused paragraph and no grace end', () => {
        render(TRIAL, TRIAL_ENDS);

        expect(document.querySelector('.admin-billing__notice')?.classList.contains('admin-notice--success')).toBe(true);
        expect(text('.admin-billing__sentence')).toBe('trial-sentence-token');
        expect(text('.admin-billing__trial')).toBe(words.text('trial_end', { end: '15/10/2026 12:00' }));
        expect(document.querySelector('.admin-billing__paused')).toBeNull();
        expect(document.querySelector('.admin-billing__grace')).toBeNull();
    });

    it('shows no trial end outside a trial and a trial that ended, even where an end is on the prop', () => {
        render(ACTIVE, { state: 'active', trial_ends_at: '2026-10-15T09:00:00+00:00' });

        expect(document.querySelector('.admin-billing__trial')).toBeNull();
    });

    it('shows the Free trial plan card with the full set and no price, no Change plan and no portal', () => {
        render(TRIAL, TRIAL_ENDS);

        expect(text('.admin-billing__plan-name')).toBe('trial-plan-token');
        expect(document.querySelector('.admin-billing__price')).toBeNull();
        expect(Array.from(document.querySelectorAll('.admin-billing__includes li')).map((line) => line.textContent)).toEqual(TRIAL.plan?.includes);
        expect(document.querySelector('.admin-billing__change-button')).toBeNull();
        expect(document.querySelector('.admin-billing__portal')).toBeNull();
    });

    it('offers Choose your plan, which opens the plans with their names and prices as the plan card spells them, and posts nothing until a plan is chosen', () => {
        render(TRIAL, TRIAL_ENDS);

        expect(text('.admin-billing__resubscribe')).toBe('choose-plan-token');
        expect(button('.admin-billing__resubscribe').getAttribute('aria-expanded')).toBe('false');
        expect(document.querySelector('.admin-billing__choice')).toBeNull();

        press(button('.admin-billing__resubscribe'));

        expect(button('.admin-billing__resubscribe').getAttribute('aria-expanded')).toBe('true');
        expect(options()).toEqual([
            { name: 'Solo', price: words.text('plan.price', { amount: formatAmount(1495, 'EUR', words.locale) }), button: words.text('checkout.choose', { plan: 'Solo' }) },
            { name: 'Team', price: words.text('plan.price', { amount: formatAmount(2495, 'EUR', words.locale) }), button: words.text('checkout.choose', { plan: 'Team' }) },
        ]);
        expect(inertia.post).not.toHaveBeenCalled();
    });

    it('posts the plan chosen to the checkout route with its busy state on that plan\'s button, the others disabled, and changes nothing until the server answers', () => {
        render(TRIAL, TRIAL_ENDS);
        press(button('.admin-billing__resubscribe'));
        press(button('[data-plan="staff"] .admin-billing__option-button'));

        const [path, body, visit] = lastVisit();

        expect(path).toBe(CHECKOUT_PATH);
        expect(body).toEqual({ plan: 'staff' });

        act(() => visit.onStart?.());

        expect(button('[data-plan="staff"] .admin-billing__option-button').getAttribute('aria-busy')).toBe('true');
        expect(button('[data-plan="floor"] .admin-billing__option-button').disabled).toBe(true);
        expect(button('.admin-billing__resubscribe').disabled).toBe(true);
        expect(text('.admin-billing__trial')).toBe(words.text('trial_end', { end: '15/10/2026 12:00' }));

        press(button('[data-plan="floor"] .admin-billing__option-button'));
        expect(inertia.post).toHaveBeenCalledTimes(1);

        act(() => visit.onFinish?.());

        expect(button('[data-plan="floor"] .admin-billing__option-button').disabled).toBe(false);
    });

    it('marks busy only the plan of the press in flight, none once it finishes, and the next press its own', () => {
        const busy = (): (string | null)[] => Array.from(document.querySelectorAll('.admin-billing__option')).map((option) => (option.querySelector('[aria-busy="true"]') === null ? null : option.getAttribute('data-plan')));

        render(TRIAL, TRIAL_ENDS);
        press(button('.admin-billing__resubscribe'));
        press(button('[data-plan="staff"] .admin-billing__option-button'));

        const [, , first] = lastVisit();

        act(() => first.onStart?.());
        expect(busy()).toEqual([null, 'staff']);

        act(() => first.onFinish?.());
        expect(busy()).toEqual([null, null]);

        press(button('[data-plan="floor"] .admin-billing__option-button'));

        const [, body, second] = lastVisit();

        expect(body).toEqual({ plan: 'floor' });

        act(() => second.onStart?.());
        expect(busy()).toEqual(['floor', null]);
        expect(button('[data-plan="staff"] .admin-billing__option-button').disabled).toBe(true);
    });

    it('disables the floor with the refusal\'s sentence beside it while the roster refuses it, the other plan still chosen', () => {
        render({ ...TRIAL, resubscribe: { label: 'choose-plan-token', pending: null, plan: null, choice: REFUSED } }, TRIAL_ENDS);
        press(button('.admin-billing__resubscribe'));

        const floor = button('[data-plan="floor"] .admin-billing__option-button');
        const reason = floor.getAttribute('aria-describedby');

        expect(floor.disabled).toBe(true);
        expect(reason).not.toBeNull();
        expect(document.getElementById(reason ?? '')?.textContent).toBe('refused-token');
        expect(document.querySelector('[data-plan="staff"] .admin-billing__option-blocked')).toBeNull();

        press(floor);
        expect(inertia.post).not.toHaveBeenCalled();

        press(button('[data-plan="staff"] .admin-billing__option-button'));
        expect(lastVisit()[1]).toEqual({ plan: 'staff' });
    });

    it('reads Continue to checkout under the on-its-way sentence while a checkout is open, the choice still offered and the press posting the plan chosen', () => {
        render({ ...TRIAL, resubscribe: { label: 'continue-token', pending: 'on-its-way-token', plan: null, choice: CHOICE } }, TRIAL_ENDS);

        expect(text('.admin-billing__pending')).toBe('on-its-way-token');
        expect(text('.admin-billing__resubscribe')).toBe('continue-token');

        press(button('.admin-billing__resubscribe'));
        press(button('[data-plan="floor"] .admin-billing__option-button'));

        expect(lastVisit().slice(0, 2)).toEqual([CHECKOUT_PATH, { plan: 'floor' }]);
    });
});

describe('a trial that ended unpaid', () => {
    it('spells the end it ended at in a paragraph of its own beside the paused paragraph, and offers Subscribe with the same choice', () => {
        render(
            {
                ...TRIAL,
                notice: { kind: 'warning', sentence: 'trial-ended-sentence-token', paused: 'paused-token' },
                plan: null,
                unavailable: 'unavailable-token',
                resubscribe: { label: 'subscribe-token', pending: null, plan: null, choice: CHOICE },
            },
            { state: 'canceled', trial_ends_at: '2026-10-01T09:00:00+00:00' },
        );

        expect(document.querySelector('.admin-billing__notice')?.classList.contains('admin-notice--warning')).toBe(true);
        expect(text('.admin-billing__sentence')).toBe('trial-ended-sentence-token');
        expect(text('.admin-billing__trial')).toBe(words.text('trial_ended', { end: '01/10/2026 12:00' }));
        expect(text('.admin-billing__paused')).toBe('paused-token');
        expect(text('.admin-billing__resubscribe')).toBe('subscribe-token');

        press(button('.admin-billing__resubscribe'));

        expect(options().map(({ name }) => name)).toEqual(['Solo', 'Team']);
    });
});

describe('the way back with nothing it could post', () => {
    it('offers Subscribe again opening the choice where the prop carries one in place of a plan', () => {
        render(
            {
                ...ACTIVE,
                notice: { kind: 'warning', sentence: 'canceled-sentence-token', paused: 'paused-token' },
                change: null,
                resubscribe: { label: 'subscribe-again-token', pending: null, plan: null, choice: REFUSED },
            },
            { state: 'canceled' },
        );

        expect(text('.admin-billing__sentence')).toBe('canceled-sentence-token');

        press(button('.admin-billing__resubscribe'));

        expect(inertia.post).not.toHaveBeenCalled();
        expect(button('[data-plan="floor"] .admin-billing__option-button').disabled).toBe(true);

        press(button('[data-plan="staff"] .admin-billing__option-button'));

        expect(lastVisit().slice(0, 2)).toEqual([CHECKOUT_PATH, { plan: 'staff' }]);
    });
});

describe('a closing account', () => {
    const CLOSING = 'closing-notice-token';

    it('shows the server\'s closing notice at the top as a warning, the paragraph of what is paused under it and the way back\'s plan choice inside it', () => {
        render(
            {
                ...ACTIVE,
                notice: { kind: 'warning', sentence: CLOSING, paused: 'paused-token' },
                change: null,
                unavailable: 'ended-token',
                resubscribe: { label: 'subscribe-again-token', pending: null, plan: null, choice: CHOICE },
            },
            { state: 'canceled' },
        );

        const notice = document.querySelector('.admin-billing__notice');
        const heading = document.querySelector('.admin-billing h1');

        expect(notice?.classList.contains('admin-notice--warning')).toBe(true);
        expect(notice?.getAttribute('role')).toBe('status');
        expect(heading !== null && notice !== null && heading.nextElementSibling === notice).toBe(true);
        expect(document.querySelectorAll('.admin-billing__sentence')).toHaveLength(1);
        expect(text('.admin-billing__sentence')).toBe(CLOSING);
        expect(text('.admin-billing__paused')).toBe('paused-token');
        expect(notice?.querySelector('.admin-billing__resubscribe')?.textContent).toBe('subscribe-again-token');

        press(button('.admin-billing__resubscribe'));
        press(button('[data-plan="staff"] .admin-billing__option-button'));

        expect(lastVisit().slice(0, 2)).toEqual([CHECKOUT_PATH, { plan: 'staff' }]);
    });

    it('renders a free tenant\'s closing notice with support as the way back, and no plan, no choice and no portal', () => {
        const free = 'free-closing-notice-token';

        render({ notice: { kind: 'warning', sentence: free, paused: 'paused-token' }, plan: null, change: null, unavailable: null, portal: false, vat: null, resubscribe: null });

        expect(text('.admin-billing__sentence')).toBe(free);
        expect(document.querySelector('.admin-billing__resubscribe')).toBeNull();
        expect(document.querySelector('.admin-billing__plan')).toBeNull();
        expect(document.querySelector('.admin-billing__portal')).toBeNull();
    });
});

describe('the screen\'s words', () => {
    it('reads its heading, the Plan section, Change plan\'s whole line and the portal\'s section from the billing section', () => {
        render(ACTIVE);

        const price = words.text('plan.price', { amount: formatAmount(2495, 'EUR', words.locale) });

        expect(text('.admin-billing h1')).toBe(words.text('title'));
        expect(text('#billing-plan')).toBe(words.text('plan.title'));
        expect(text('.admin-billing__change p')).toBe(words.text('change.add_line', { plan: 'Team', price }));
        expect(text('.admin-billing__change-button')).toBe(words.text('change.add'));
        expect(text('#billing-payment')).toBe(words.text('payment.title'));
        expect(text('.admin-billing__payment p')).toBe(words.text('payment.sentence'));
        expect(text('.admin-billing__portal')).toBe(words.text('payment.button'));
    });

    it('names each plan of the choice in one whole line, the plans\' list labelled, and the busy words of each write', () => {
        render(TRIAL, TRIAL_ENDS);
        press(button('.admin-billing__resubscribe'));

        const price = words.text('plan.price', { amount: formatAmount(1495, 'EUR', words.locale) });

        expect(document.querySelector('.admin-billing__choice')?.getAttribute('aria-label')).toBe(words.text('checkout.plans'));
        expect(document.querySelector('[data-plan="floor"] p')?.textContent).toBe(words.text('checkout.option', { plan: 'Solo', price }));

        press(button('[data-plan="floor"] .admin-billing__option-button'));
        act(() => lastVisit()[2].onStart?.());

        expect(text('[data-plan="floor"] .admin-billing__option-button')).toContain(words.text('checkout.busy'));
    });

    it('lists the narrowed features in the user\'s language, joined by ICU\'s list', () => {
        render(ACTIVE, { state: 'active' }, ['staff', 'staff']);

        expect(text('.admin-billing__narrowed')).toBe(words.text('plan.narrowed', { features: new Intl.ListFormat(words.locale, { type: 'conjunction' }).format([shell.text('banners.features.staff.name'), shell.text('banners.features.staff.name')]) }));
    });
});
