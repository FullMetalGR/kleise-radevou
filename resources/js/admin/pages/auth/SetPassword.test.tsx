import { act } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { mount, type Mounted, press } from '@/components/ui/harness';
import { keyedShell, WithShell } from '@/testing/keyed';
import SetPassword, { SET_PASSWORD_HEADING } from './SetPassword';
import { SetPasswordForm } from './SetPasswordForm';

/*
 * The set-password link's page, outside the shell: one password field and a Set password button,
 * posting to the URL the page was opened at, which is the link, so no prop carries the token.
 * The button is busy while the answer is on its way and nothing is optimistic: a refused
 * password comes back on the field in the server's words, and a set or a dead link lands on the
 * sign-in page with the server's flash. The round trip is the feature suite's. The words are the
 * shell's set_password group, rendered with the keyed table, the help's :floor the page's floor
 * prop; the server's refusals are tokens here.
 */

const shell = keyedShell();

/** A floor the rule never uses (its own is 8), so the help can only print the prop it is given. */
const FLOOR = 12;

type Visit = { onFinish?: () => void };

const inertia = vi.hoisted(() => ({
    url: '',
    props: {} as Record<string, unknown>,
    post: vi.fn(),
    reset: vi.fn(),
    errors: {} as { password?: string },
    processing: false,
}));

vi.mock('@inertiajs/react', async () => {
    const { useState } = await import('react');

    return {
        Head: () => null,
        usePage: () => ({ url: inertia.url, props: inertia.props }),
        useForm: (initial: { password: string }) => {
            const [data, setData] = useState(initial);

            return {
                data,
                errors: inertia.errors,
                processing: inertia.processing,
                setData: (key: 'password', value: string) => setData((current) => ({ ...current, [key]: value })),
                post: inertia.post,
                reset: inertia.reset,
            };
        },
    };
});

const TOKEN = 'a'.repeat(64);

let view: Mounted;

const input = () => view.host.querySelector<HTMLInputElement>('input[name="password"]');
const button = () => view.host.querySelector<HTMLButtonElement>('.admin-set-password-submit');
const describedBy = (control: Element | null) => document.getElementById(control?.getAttribute('aria-describedby') ?? '');

function type(value: string): void {
    const field = input();

    if (field === null) {
        throw new Error('No password field.');
    }

    act(() => {
        Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')?.set?.call(field, value);
        field.dispatchEvent(new Event('input', { bubbles: true }));
    });
}

function renderForm(answer: { error?: string; processing?: boolean } = {}, onSubmit = () => {}): void {
    view.render(
        <WithShell>
            <SetPasswordForm password="" floor={FLOOR} error={answer.error} processing={answer.processing ?? false} onPassword={() => {}} onSubmit={onSubmit} />
        </WithShell>,
    );
}

function renderPage(): void {
    view.render(
        <WithShell>
            <SetPassword />
        </WithShell>,
    );
}

beforeEach(() => {
    inertia.url = `/admin/set-password/${TOKEN}`;
    inertia.props = { tenant: { name: 'Luna Hair Studio', slug: 'luna', timezone: 'Europe/Athens', currency: 'EUR' }, flash: null, errors: {}, floor: FLOOR };
    inertia.post.mockReset();
    inertia.reset.mockReset();
    inertia.errors = {};
    inertia.processing = false;
    view = mount();
});

afterEach(() => view.unmount());

describe('the set-password form', () => {
    it('renders one password field for a new password, labelled and pointed at by id, and a ready Set password button', () => {
        renderForm();

        const label = view.host.querySelector('label');

        expect(view.host.querySelectorAll('input')).toHaveLength(1);
        expect(input()?.getAttribute('type')).toBe('password');
        expect(input()?.getAttribute('autocomplete')).toBe('new-password');
        expect(label?.textContent).toBe(shell.text('set_password.password'));
        expect(document.getElementById(label?.htmlFor ?? '')).toBe(input());
        expect(describedBy(input())?.textContent).toBe(shell.text('set_password.help', { floor: String(FLOOR) }));
        expect(button()?.textContent).toBe(shell.text('set_password.submit'));
        expect(button()?.disabled).toBe(false);
        expect(button()?.getAttribute('type')).toBe('submit');
    });

    it('answers a refused password on the field in the server\'s words, politely, with no alert, and moves focus to it', () => {
        renderForm();
        button()?.focus();
        renderForm({ error: 'refusal-token' });

        expect(describedBy(input())?.textContent).toBe('refusal-token');
        expect(input()?.getAttribute('aria-invalid')).toBe('true');
        expect(view.host.querySelector('[role="alert"]')).toBeNull();
        expect(document.activeElement).toBe(input());
    });

    it('is busy while the answer is on its way, and submits once on a press', () => {
        const submitted = vi.fn();
        renderForm({}, submitted);
        press(button());

        expect(submitted).toHaveBeenCalledTimes(1);

        renderForm({ processing: true }, submitted);

        expect(button()?.textContent).toBe(shell.text('set_password.submitting'));
        expect(button()?.getAttribute('aria-busy')).toBe('true');
    });
});

describe('the set-password page', () => {
    it('heads the page for the business, and posts the password typed to the URL it was opened at, the link, clearing the field once the answer lands', () => {
        renderPage();

        expect(view.host.querySelector('h1')?.textContent).toBe(shell.text(SET_PASSWORD_HEADING));
        expect(describedBy(input())?.textContent).toBe(shell.text('set_password.help', { floor: String(FLOOR) }));
        expect(view.host.textContent).toContain('Luna Hair Studio');

        type('a brand new password');
        press(button());

        expect(inertia.post).toHaveBeenCalledTimes(1);

        const [url, visit] = inertia.post.mock.lastCall as [string, Visit];

        expect(url).toBe(`/admin/set-password/${TOKEN}`);
        expect(input()?.value).toBe('a brand new password');

        visit.onFinish?.();

        expect(inertia.reset).toHaveBeenCalledWith('password');
    });

    it('shows the refusal the server put in the errors bag on the field', () => {
        inertia.errors = { password: 'refusal-token' };
        renderPage();

        expect(describedBy(input())?.textContent).toBe('refusal-token');
    });
});
