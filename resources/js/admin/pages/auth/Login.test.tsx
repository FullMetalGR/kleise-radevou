import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { KEYED_SHELL, keyedShell, WithShell } from '@/testing/keyed';
import Login from './Login';
import { LoginForm } from './LoginForm';

/*
 * The door's form as it renders: two fields, one button, and the server's sentence when there is
 * one. The round trip itself is the browser suite's. The form renders inside the shell's keyed
 * table and its words are read through it; the server's sentences it shows are handed in as the
 * keyed table spells them.
 */

const shell = keyedShell();

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title: string }) => <title>{title}</title>,
    useForm: (data: Record<string, string>) => ({ data, errors: {}, processing: false, setData: () => {}, post: () => {}, reset: () => {} }),
}));

let host: HTMLDivElement;
let root: Root;

beforeEach(() => {
    host = document.createElement('div');
    document.body.append(host);
    root = createRoot(host);
});

afterEach(() => {
    act(() => root.unmount());
    host.remove();
});

type Answer = { refusal?: string; errors?: { email?: string; password?: string } };

function render(answer: Answer | null, processing: boolean, onSubmit = () => {}): void {
    act(() =>
        root.render(
            <WithShell>
                <LoginForm
                    email=""
                    password=""
                    refusal={answer?.refusal ?? null}
                    errors={answer?.errors ?? {}}
                    processing={processing}
                    onEmail={() => {}}
                    onPassword={() => {}}
                    onSubmit={onSubmit}
                />
            </WithShell>,
        ),
    );
}

const REFUSED = KEYED_SHELL.refusals.sign_in;
const TOO_MANY = KEYED_SHELL.refusals.too_many;
const input = (name: string) => host.querySelector<HTMLInputElement>(`input[name="${name}"]`);
const describedBy = (control: Element | null) => document.getElementById(control?.getAttribute('aria-describedby') ?? '');

describe('the sign-in page', () => {
    it('titles and heads itself in the shell\'s login words, the form below', () => {
        act(() =>
            root.render(
                <WithShell>
                    <Login refusal={null} />
                </WithShell>,
            ),
        );

        expect(document.querySelector('title')?.textContent).toBe(shell.text('login.title'));
        expect(host.querySelector('h1')?.textContent).toBe(shell.text('login.heading'));
        expect(host.querySelector('.admin-login-submit')?.textContent).toBe(shell.text('login.submit'));
    });
});

describe('the sign-in form', () => {
    it('renders the email and password fields and a ready button, no sentence and no remember-me', () => {
        render(null, false);

        expect(host.querySelector('input[name="email"]')).not.toBeNull();
        expect(host.querySelector('input[name="password"]')?.getAttribute('type')).toBe('password');
        expect(host.querySelector<HTMLButtonElement>('.admin-login-submit')?.disabled).toBe(false);
        expect(host.querySelector('.admin-login-submit')?.textContent).toBe(shell.text('login.submit'));
        expect(host.querySelector('[role="alert"]')).toBeNull();
        expect(host.querySelectorAll('input')).toHaveLength(2);
    });

    it('points each label at its input by id and wraps nothing in it', () => {
        render(null, false);

        const labels = [...host.querySelectorAll('label')];

        expect(labels.map((label) => label.textContent)).toEqual([shell.text('login.email'), shell.text('login.password')]);

        for (const label of labels) {
            const target = document.getElementById(label.htmlFor);

            expect(label.htmlFor).not.toBe('');
            expect(target?.tagName).toBe('INPUT');
            expect(label.children).toHaveLength(0);
            expect(label.contains(target)).toBe(false);
        }

        expect(document.getElementById(labels[0]?.htmlFor ?? '')?.getAttribute('name')).toBe('email');
        expect(document.getElementById(labels[1]?.htmlFor ?? '')?.getAttribute('name')).toBe('password');
    });

    it('answers a refused pair on the email field through the field shell, politely, with no alert', () => {
        render({ errors: { email: REFUSED } }, false);

        expect(describedBy(input('email'))?.textContent).toBe(REFUSED);
        expect(input('email')?.getAttribute('aria-invalid')).toBe('true');
        expect(input('password')?.hasAttribute('aria-invalid')).toBe(false);
        expect(host.querySelector('[role="alert"]')).toBeNull();
        expect(host.textContent?.split(REFUSED)).toHaveLength(2);
    });

    it('answers a field the server named on that field alone', () => {
        render({ errors: { password: KEYED_SHELL.validation.login.password_required } }, false);

        expect(describedBy(input('password'))?.textContent).toBe(KEYED_SHELL.validation.login.password_required);
        expect(input('password')?.getAttribute('aria-invalid')).toBe('true');
        expect(input('email')?.hasAttribute('aria-invalid')).toBe(false);
        expect(host.querySelector('[role="alert"]')).toBeNull();
    });

    it('answers the sign-in limit in a polite notice that marks no field, with no alert', () => {
        render({ refusal: TOO_MANY }, false);

        const notice = host.querySelector('.admin-login-problem');

        expect(notice?.textContent).toBe(TOO_MANY);
        expect(notice?.getAttribute('role')).toBe('status');
        expect(host.querySelector('[role="alert"]')).toBeNull();
        expect(host.querySelector('[aria-invalid]')).toBeNull();
    });

    it('moves focus to the first failing field in document order once the refused answer comes back, and not again on a later render', () => {
        const bag = { password: 'The password field is required.', email: 'The email field is required.' };

        render(null, false);
        host.querySelector<HTMLButtonElement>('.admin-login-submit')?.focus();
        render({ errors: bag }, false);

        expect(document.activeElement).toBe(input('email'));

        input('password')?.focus();
        render({ errors: bag }, false);

        expect(document.activeElement).toBe(input('password'));
    });

    it('moves focus again on a second refused answer worded as the first, a new bag equal to the last, once focus has moved away', () => {
        render(null, false);
        render({ errors: { email: REFUSED } }, false);

        expect(document.activeElement).toBe(input('email'));

        input('password')?.focus();
        render({ errors: { email: REFUSED } }, false);

        expect(document.activeElement).toBe(input('email'));
    });

    it('moves focus to the email field when a wrong pair comes back on it', () => {
        render(null, false);
        render({ errors: { email: REFUSED } }, false);

        expect(document.activeElement).toBe(input('email'));
    });

    it('leaves focus where it was when the sign-in limit refuses, the notice marking no field', () => {
        render(null, false);
        const button = host.querySelector<HTMLButtonElement>('.admin-login-submit');
        button?.focus();
        render({ refusal: TOO_MANY }, false);

        expect(document.activeElement).toBe(button);
    });

    it('moves nothing when the form first renders with a field marked', () => {
        render({ errors: { email: REFUSED } }, false);

        expect(document.activeElement).toBe(document.body);
    });

    it('is busy while the answer is on its way', () => {
        render(null, true);

        const button = host.querySelector<HTMLButtonElement>('.admin-login-submit');

        expect(button?.disabled).toBe(true);
        expect(button?.getAttribute('aria-busy')).toBe('true');
        expect(button?.textContent).toBe(shell.text('login.submitting'));
    });

    it('submits through the handler rather than the browser', () => {
        const submit = vi.fn();

        render(null, false, submit);
        act(() => host.querySelector('form')?.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })));

        expect(submit).toHaveBeenCalledOnce();
    });
});
