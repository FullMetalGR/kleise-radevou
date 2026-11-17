import { act } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { mount, type Mounted, press } from '@/components/ui/harness';
import { stringsOf } from '@/lib/strings';
import { PAYMENT_WAIT, type PaymentConnection, type PaymentProvider, type Payments } from '@/settings/payments';
import { KEYED_PAYMENTS, KEYED_SETUP, keyedPayments, keyedSetup } from '@/testing/keyed';
import { ToastProvider } from '@/toast/toasts';
import type { Setup, StepKey } from '../model';
import { SetupWizard } from '../SetupWizard';
import { ONBOARDING_URL } from './PaymentsStep';

/*
 * The Online payments step, open in the wizard: Settings' Payments section whole, read in the
 * payments words the page carries, under the step's help line, with the wizard's Next and Skip
 * for now. Connect through us and Continue onboarding post to the step's own route, every other
 * write to Settings' own, each held by the section's one-write rule with its wait said; Next
 * writes nothing. An open credentials form is an unsaved edit the wizard asks about before the
 * step is left, until the form is cancelled or its save has landed.
 */

const inertia = vi.hoisted(() => ({ post: vi.fn(), props: {} as Record<string, unknown>, listeners: new Map<string, (event: Event) => void>() }));

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: inertia.props }),
    router: {
        post: inertia.post,
        on: (name: string, listener: (event: Event) => void) => {
            inertia.listeners.set(name, listener);

            return () => inertia.listeners.delete(name);
        },
    },
}));

const words = keyedSetup();
const step = stringsOf<{ name: string; help: string }>(KEYED_SETUP.steps.payments as unknown as { name: string; help: string }, 'en');
const payments = keyedPayments();

/* A provider shaped as the registry's are, under an invented name, every mode offered. */
const PROVIDER: PaymentProvider = {
    slug: 'tillpoint',
    name: 'Tillpoint',
    rate_url: 'https://tillpoint.example/pricing',
    modes: [
        { mode: 'platform', offered: true, takes_fee: true, fee: { percent: '1.5', fixed_minor: null, currency: 'EUR' } },
        { mode: 'own', offered: true, takes_fee: false, fee: null },
    ],
    fields: [{ name: 'api_token', label: 'api_token-label', help: 'api_token-help', secret: true }],
};

function connection(overrides: Partial<PaymentConnection>): PaymentConnection {
    return {
        provider: 'tillpoint',
        name: 'Tillpoint',
        mode: 'platform',
        state: 'pending',
        last_checked_at: '2026-11-02T06:00:00+00:00',
        last_check_ok: null,
        requirements: null,
        information_due: false,
        fee: { percent: '1.5', fixed_minor: null, currency: 'EUR' },
        stored_secrets: [],
        webhook_url: null,
        ...overrides,
    };
}

function setupOf(data: Payments): Setup {
    return {
        steps: [
            { key: 'profile', done: true, skipped: false },
            { key: 'payments', done: false, skipped: false },
            { key: 'link', done: false, skipped: false },
        ],
        opened: true,
        opens: null,
        data: { payments: data },
    };
}

type Visit = { onStart?: () => void; onSuccess?: () => void; onFinish?: () => void };

let view: Mounted | null = null;
let moves: StepKey[] = [];

function open(data: Payments) {
    inertia.post.mockReset();
    inertia.listeners.clear();
    inertia.props = {
        tenant: { name: 'Luna', slug: 'luna', timezone: 'Europe/Athens', currency: 'EUR' },
        settings: { granularity: 15, week_start: 1, date_format: 'd/m/Y', time_format: 'H:i', colour_by: 'staff', messages: [] },
        strings: { shell: {}, payments: KEYED_PAYMENTS },
    };
    moves = [];
    view = mount();
    view.render(
        <ToastProvider>
            <SetupWizard setup={setupOf(data)} table={KEYED_SETUP} words={words} step="payments" onStep={(key) => moves.push(key)} onClose={() => {}} />
        </ToastProvider>,
    );
}

afterEach(() => {
    view?.unmount();
    view = null;
});

const q = (selector: string) => document.body.querySelector(selector);

function lastPost(): [string, unknown, Visit] {
    return inertia.post.mock.lastCall as [string, unknown, Visit];
}

function type(selector: string, value: string): void {
    const field = document.body.querySelector(selector);

    if (!(field instanceof HTMLInputElement)) {
        throw new Error(`No field at ${selector}.`);
    }

    act(() => {
        Object.getOwnPropertyDescriptor(Object.getPrototypeOf(field), 'value')?.set?.call(field, value);
        field.dispatchEvent(new Event('input', { bubbles: true }));
    });
}

describe('the Online payments step', () => {
    it('shows Settings\' Payments section under the step\'s help line, with the wizard\'s Next and Skip for now', () => {
        open({ providers: [PROVIDER], connection: null });

        expect(q('.admin-setup-payments__help')?.textContent).toBe(step.text('help'));
        expect(q('.admin-payments-provider[data-provider="tillpoint"]')).not.toBeNull();
        expect(q('.admin-payments-platform')?.textContent).toBe(payments.text('buttons.platform', { provider: 'Tillpoint' }));
        expect(q('.admin-setup__primary')?.textContent).toBe(words.text('next'));
        expect(q('.admin-setup__skip')?.textContent).toBe(words.text('skip'));
    });

    it('connects through us by the step\'s own route, its wait said while it runs', () => {
        open({ providers: [PROVIDER], connection: null });

        press(q('.admin-payments-platform'));

        expect(inertia.post).toHaveBeenCalledTimes(1);
        const [url, body, visit] = lastPost();
        expect([url, body]).toEqual([ONBOARDING_URL, { provider: 'tillpoint' }]);

        act(() => visit.onStart?.());
        expect(q('.admin-payments__wait')?.textContent).toBe(payments.text(PAYMENT_WAIT.platform));
    });

    it('continues an unfinished onboarding by the step\'s own route', () => {
        open({ providers: [PROVIDER], connection: connection({ state: 'pending' }) });

        press(q('.admin-payments-resume'));

        expect(lastPost()[0]).toBe(`${ONBOARDING_URL}/resume`);
    });

    it('sends every other write to Settings\' own route', () => {
        open({ providers: [PROVIDER], connection: connection({ mode: 'own', state: 'active', last_check_ok: true, fee: null, stored_secrets: ['api_token'], webhook_url: 'http://luna.localhost/webhooks/tillpoint/token' }) });

        press(q('.admin-payments-test'));

        expect(lastPost()[0]).toBe('/admin/settings/payments/test');
    });

    it('moves on by Next with nothing written', () => {
        open({ providers: [PROVIDER], connection: null });

        press(q('.admin-setup__primary'));

        expect(inertia.post).not.toHaveBeenCalled();
        expect(moves).toEqual(['link']);
    });

    it('asks before a credentials form left open is left behind, and no longer once the form is cancelled', () => {
        open({ providers: [PROVIDER], connection: null });

        press(q('.admin-payments-own'));
        expect(q('.admin-payments-credentials')).not.toBeNull();

        press(q('.admin-setup__primary'));
        expect(q('.admin-setup-discard')).not.toBeNull();
        expect(moves).toEqual([]);

        press(q('.admin-setup-discard__keep'));
        press(q('.admin-payments-cancel'));
        press(q('.admin-setup__primary'));

        expect(inertia.post).not.toHaveBeenCalled();
        expect(moves).toEqual(['link']);
    });

    it('asks nothing once the save of an open credentials form has landed and closed it', () => {
        open({ providers: [PROVIDER], connection: null });

        press(q('.admin-payments-own'));
        type('#payments-tillpoint-api_token', 'token-typed');
        press(q('.admin-payments-save'));

        const [url, , visit] = lastPost();
        expect(url).toBe('/admin/settings/payments');
        act(() => {
            visit.onStart?.();
            visit.onSuccess?.();
            visit.onFinish?.();
        });
        expect(q('.admin-payments-credentials')).toBeNull();

        press(q('.admin-setup__primary'));
        expect(moves).toEqual(['link']);
    });
});
