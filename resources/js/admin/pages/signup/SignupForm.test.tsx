import { act, useState } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { SignupStrings } from '@/types';
import { PendingNotice, ResendControl } from './Pending';
import Signup from './Signup';
import { type SignupData, type SignupErrors, SignupForm, type SignupLegal, TERMS_TICKED } from './SignupForm';
import type { TurnstileWidget } from './Turnstile';

/*
 * The signup form and the pending page as they render. The round trip is the browser suite's. The
 * strings are handed in as the server hands them, lang/en/signup.php's form and pending keys: the
 * English here is the test's copy of that file, and one case hands keyed strings instead to prove
 * the components hold no sentence of their own. The page's own answer to a refused POST, its
 * onError, runs over a recording useForm in place of Inertia's; the rest of the module is
 * Inertia's own.
 */

const inertia = vi.hoisted(() => ({
    post: vi.fn(),
    strings: null as unknown,
    data: {} as Record<string, string>,
}));

vi.mock('@inertiajs/react', async (importOriginal) => {
    const original = await importOriginal<typeof import('@inertiajs/react')>();
    const { useState } = await import('react');

    return {
        ...original,
        Head: () => null,
        usePage: () => ({ props: { strings: inertia.strings } }),
        useForm: (initial: Record<string, string>) => {
            const [data, setData] = useState(initial);
            inertia.data = data;

            return {
                data,
                errors: {},
                processing: false,
                setData: (field: string, value: string) => setData((current) => ({ ...current, [field]: value })),
                transform: () => {},
                post: inertia.post,
                reset: () => {},
            };
        },
    };
});

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

const strings: SignupStrings = {
    form: {
        title: 'Sign up',
        heading: 'Start your booking page',
        slug: 'Your address',
        slug_example: 'your-name',
        name: 'Business name',
        category: 'Kind of business',
        category_placeholder: 'Choose a kind of business',
        timezone: 'Timezone',
        timezone_placeholder: 'Choose a timezone',
        currency: 'Currency',
        currency_help: 'Three letters, such as EUR.',
        owner_name: 'Your name',
        owner_email: 'Your email',
        password: 'Password',
        terms: 'terms-sentence <terms>terms-text</terms> then <privacy>privacy-text</privacy>',
        check: 'Anti-spam check',
        submit: 'Sign up',
        submitting: 'Signing up...',
    },
    pending: {
        waiting_title: 'Check your email',
        waiting_heading: 'Check your email',
        waiting: 'We sent a link to :email. Open it within 24 hours to set up :business at :address.',
        your_address: 'the address you gave',
        your_business: 'your business',
        verified_title: 'Setting up',
        verified_heading: 'Setting up :business',
        verified: 'Your email is confirmed. This page opens your sign-in page at :address as soon as it is ready.',
        resend: 'Send the link again',
        resending: 'Sending...',
    },
};

/**
 * Every string replaced by its own key in brackets, the two sentences with values keeping their
 * placeholders and the terms box's sentence its two tag pairs around neutral tokens.
 */
function keyed(): SignupStrings {
    const section = <K extends keyof SignupStrings>(name: K): SignupStrings[K] =>
        Object.fromEntries(Object.keys(strings[name]).map((key) => [key, `[${name}.${key}]`])) as SignupStrings[K];

    return {
        form: { ...section('form'), terms: '[form.terms <terms>terms-link</terms> <privacy>privacy-link</privacy>]' },
        pending: { ...section('pending'), waiting: '[pending.waiting :email :business :address]', verified_heading: '[pending.verified_heading :business]', verified: '[pending.verified :address]' },
    };
}

const categories = [
    { value: 'hair_salon', label: 'Hair salon' },
    { value: 'barber', label: 'Barber' },
];
const HELD = 'That subdomain name is held by a signup in progress. It frees within a day if that signup is not verified.';
const LONG = 'Choose a shorter password: at most 72 characters, fewer if it uses letters outside the Latin alphabet.';
const data: SignupData = { slug: 'Luna', name: '', category: '', timezone: 'Europe/Athens', currency: 'EUR', owner_name: '', owner_email: '', password: '', terms: '', turnstile_token: '' };
const LEGAL: SignupLegal = { terms: 'https://kleiseradevou.gr/terms', privacy: 'https://kleiseradevou.gr/privacy' };
const widget = { siteKey: '0x4AAAAAAASignupSiteKey', script: 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit' };
const scripts = () => [...document.head.querySelectorAll('script')].filter((tag) => tag.getAttribute('src') === widget.script);

/** Every test leaves no widget script and no Turnstile API behind for the next. */
afterEach(() => {
    scripts().forEach((tag) => tag.remove());
    delete window.turnstile;
});

function render(
    over: {
        data?: SignupData;
        strings?: SignupStrings;
        errors?: SignupErrors;
        legal?: SignupLegal;
        refusal?: string;
        processing?: boolean;
        turnstile?: TurnstileWidget;
        attempt?: number;
        onChange?: (field: keyof SignupData, value: string) => void;
        onSubmit?: () => void;
    } = {},
): void {
    act(() =>
        root.render(
            <SignupForm
                data={over.data ?? data}
                strings={over.strings ?? strings}
                categories={categories}
                timezones={['Europe/Athens', 'UTC']}
                centralHost="kleiseradevou.gr"
                legal={over.legal ?? LEGAL}
                refusal={over.refusal ?? null}
                turnstile={over.turnstile ?? null}
                attempt={over.attempt ?? 0}
                errors={over.errors ?? {}}
                processing={over.processing ?? false}
                onChange={over.onChange ?? (() => {})}
                onSubmit={over.onSubmit ?? (() => {})}
            />,
        ),
    );
}

describe('SignupForm', () => {
    it('shows the address the slug becomes, lower-cased, as the person types, and the example before anything is typed', () => {
        render();
        expect(host.textContent).toContain('luna.kleiseradevou.gr');

        render({ data: { ...data, slug: '' } });
        expect(host.textContent).toContain('your-name.kleiseradevou.gr');
    });

    it('offers the kinds of business by their labels and no plan, and submits once', () => {
        const onSubmit = vi.fn();
        render({ onSubmit });

        const options = [...host.querySelectorAll<HTMLOptionElement>('#signup-category option')].map((option) => option.textContent);
        expect(options).toEqual(['Choose a kind of business', 'Hair salon', 'Barber']);
        expect(host.querySelector('.admin-segmented__option')).toBeNull();
        expect(host.textContent).not.toContain('Plan');
        expect(host.textContent).not.toContain('payment');

        act(() => host.querySelector('form')?.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })));
        expect(onSubmit).toHaveBeenCalledTimes(1);
    });

    it('holds no sentence of its own: every label, placeholder, help line, group name and button reads from the strings handed to it', () => {
        render({ strings: keyed(), data: { ...data, slug: '' }, turnstile: widget });
        const text = host.textContent ?? '';
        const labels = [...host.querySelectorAll('label')].map((label) => label.textContent);
        const options = [...host.querySelectorAll<HTMLOptionElement>('option')].map((option) => option.textContent);

        expect(labels).toEqual(['[form.slug]', '[form.name]', '[form.category]', '[form.timezone]', '[form.currency]', '[form.owner_name]', '[form.owner_email]', '[form.password]', '[form.terms terms-link privacy-link]']);
        expect(options).toContain('[form.category_placeholder]');
        expect(options).toContain('[form.timezone_placeholder]');
        expect(text).toContain('[form.slug_example].kleiseradevou.gr');
        expect(text).toContain('[form.currency_help]');
        expect(host.querySelector('#signup-turnstile_token')?.getAttribute('aria-label')).toBe('[form.check]');
        expect(host.querySelector('.admin-signup-submit')?.textContent).toBe('[form.submit]');
        for (const sentence of Object.values(strings.form)) {
            expect(text).not.toContain(sentence);
        }

        render({ strings: keyed(), processing: true });
        expect(host.querySelector('.admin-signup-submit')?.textContent).toContain('[form.submitting]');
    });

    it('puts each refusal on its field and the expired link above the button', () => {
        render({ errors: { slug: 'That subdomain name is reserved.', category: 'Choose the kind of business from the list.' }, refusal: 'This link has expired. Please sign up again.' });

        expect(host.querySelector('#signup-slug')?.getAttribute('aria-invalid')).toBe('true');
        expect(host.querySelector('#signup-category')?.getAttribute('aria-invalid')).toBe('true');
        expect(host.textContent).toContain('Choose the kind of business from the list.');
        expect(host.querySelector('.admin-signup-problem')?.textContent).toBe('This link has expired. Please sign up again.');
    });

    it('puts the long-password refusal on the password field and sets no length limit there, since bytes are not characters', () => {
        render({ errors: { password: LONG } });
        const password = host.querySelector<HTMLInputElement>('#signup-password');

        expect(password?.getAttribute('aria-invalid')).toBe('true');
        expect(password?.hasAttribute('maxlength')).toBe(false);
        expect(host.textContent).toContain(LONG);
        expect(host.querySelector('.admin-signup-problem')).toBeNull();
    });

    it('moves focus to the address when a held slug comes back, the first failing field in document order whatever the bag\'s key order', () => {
        render();
        host.querySelector<HTMLButtonElement>('.admin-signup-submit')?.focus();
        render({ errors: { password: LONG, slug: HELD } });

        expect(document.activeElement).toBe(host.querySelector('#signup-slug'));
    });

    it('moves focus to the password field when a refused password is the first failing field, and not again on a later render', () => {
        const bag = { password: LONG };

        render();
        render({ errors: bag });

        expect(document.activeElement).toBe(host.querySelector('#signup-password'));

        host.querySelector<HTMLInputElement>('#signup-name')?.focus();
        render({ errors: bag });

        expect(document.activeElement).toBe(host.querySelector('#signup-name'));
    });

    it('leaves focus where it was when the limit\'s notice or the expired link\'s comes back, neither marking a field', () => {
        render();
        const button = host.querySelector<HTMLButtonElement>('.admin-signup-submit');
        button?.focus();

        render({ refusal: 'Too many signup attempts from this address. Please wait a minute and try again.' });
        expect(document.activeElement).toBe(button);

        render({ refusal: 'This link has expired. Please sign up again.' });
        expect(document.activeElement).toBe(button);
        expect(host.querySelector('[aria-invalid]')).toBeNull();
    });

    it('upper-cases the currency on every keystroke and holds three characters, as the Settings screen does', () => {
        function Typed() {
            const [typed, setTyped] = useState(data);

            return <SignupForm data={typed} strings={strings} categories={categories} timezones={[]} centralHost="kleiseradevou.gr" legal={LEGAL} refusal={null}
                    turnstile={null}
                    attempt={0}
                    errors={{}}
                    processing={false}
                    onChange={(field, value) => setTyped((was) => ({ ...was, [field]: value }))} onSubmit={() => {}} />;
        }
        act(() => root.render(<Typed />));
        const currency = host.querySelector<HTMLInputElement>('#signup-currency');

        expect(currency?.value).toBe('EUR');
        expect(currency?.maxLength).toBe(3);

        act(() => {
            Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')?.set?.call(currency, 'usd');
            currency?.dispatchEvent(new Event('input', { bubbles: true }));
        });

        expect(currency?.value).toBe('USD');
    });

    it('shows the limit refusal and a busy button while the answer is on its way', () => {
        render({ refusal: 'Too many signup attempts from this address. Please wait a minute and try again.', processing: true });

        expect(host.querySelector('.admin-signup-problem')?.textContent).toContain('Too many signup attempts');
        expect(host.querySelector('.admin-signup-submit')?.textContent).toContain('Signing up...');
    });
});

describe('SignupForm\'s terms box', () => {
    const box = () => host.querySelector<HTMLInputElement>('#signup-terms');

    it('sits after the password and above the anti-spam check and the button, a checkbox labelled by its key with no help line', () => {
        render({ strings: keyed(), turnstile: widget });
        const order = [...host.querySelectorAll('#signup-password, #signup-terms, #signup-turnstile_token, .admin-signup-submit')].map((element) =>
            element.id === '' ? 'submit' : element.id,
        );

        expect(order).toEqual(['signup-password', 'signup-terms', 'signup-turnstile_token', 'submit']);
        expect(box()?.type).toBe('checkbox');
        expect(box()?.name).toBe('terms');
        expect(host.querySelector('label[for="signup-terms"]')?.textContent).toBe('[form.terms terms-link privacy-link]');
        expect(box()?.hasAttribute('aria-describedby')).toBe(false);
        expect(host.querySelector('#signup-terms-help')).toBeNull();
    });

    it('turns the two tag pairs into two links to the props\' addresses, each opening in a new tab, and shows no tag as text', () => {
        render({ strings: keyed(), legal: { terms: 'https://central.example/el/terms', privacy: 'https://central.example/el/privacy' } });
        const label = host.querySelector('label[for="signup-terms"]');
        const links = [...(label?.querySelectorAll('a') ?? [])];

        expect(links.map((link) => [link.textContent, link.getAttribute('href'), link.getAttribute('target'), link.getAttribute('rel')])).toEqual([
            ['terms-link', 'https://central.example/el/terms', '_blank', 'noopener noreferrer'],
            ['privacy-link', 'https://central.example/el/privacy', '_blank', 'noopener noreferrer'],
        ]);
        expect(links).toHaveLength(2);
        expect(label?.textContent).not.toContain('<');
        expect(label?.textContent).not.toContain('>');
        expect(label?.innerHTML).not.toContain('&lt;');
    });

    it('reads the sentence as text, never as markup, an angle bracket in a link text staying a character', () => {
        render({ strings: { ...strings, form: { ...strings.form, terms: 'lead <terms>a <b>bold</b></terms> <privacy>p &amp; q</privacy>' } } });
        const label = host.querySelector('label[for="signup-terms"]');

        expect(label?.querySelector('b')).toBeNull();
        expect([...(label?.querySelectorAll('a') ?? [])].map((link) => link.textContent)).toEqual(['p &amp; q']);
        expect(label?.textContent).toBe('lead <terms>a <b>bold</b></terms> p &amp; q');
    });

    it('ticks and unticks through the form\'s own field, posting the ticked value or nothing', () => {
        const onChange = vi.fn();
        render({ onChange });
        expect(box()?.checked).toBe(false);

        act(() => box()?.click());
        expect(onChange).toHaveBeenLastCalledWith('terms', TERMS_TICKED);

        render({ onChange, data: { ...data, terms: TERMS_TICKED } });
        expect(box()?.checked).toBe(true);

        act(() => box()?.click());
        expect(onChange).toHaveBeenLastCalledWith('terms', '');
    });

    it('puts the refusal under the box, the box announcing it, and moves focus to the box when it is the first failing field', () => {
        render();
        host.querySelector<HTMLButtonElement>('.admin-signup-submit')?.focus();
        render({ errors: { terms: 'refusal-token' } });

        expect(box()?.getAttribute('aria-invalid')).toBe('true');
        expect(box()?.getAttribute('aria-describedby')).toBe('signup-terms-error');
        expect(host.querySelector('#signup-terms-error')?.textContent).toBe('refusal-token');
        expect(host.querySelector('#signup-terms')?.closest('.admin-signup-terms')?.contains(host.querySelector('#signup-terms-error'))).toBe(true);
        expect(document.activeElement).toBe(box());
    });
});

describe('SignupForm with Turnstile', () => {
    const FAILED = 'The anti-spam check did not pass. Please try again.';

    function cloudflare() {
        const api = {
            render: vi.fn((_box: HTMLElement, options: { callback: (token: string) => void }) => {
                options.callback('XXXX.DUMMY.TOKEN');

                return `widget-${api.render.mock.calls.length}`;
            }),
            remove: vi.fn(),
        };
        window.turnstile = api;

        return api;
    }

    it('renders no widget, no script and no anti-spam field while the server hands it none', () => {
        render();

        expect(scripts()).toHaveLength(0);
        expect(host.querySelector('#signup-turnstile_token')).toBeNull();
        expect(host.textContent).not.toContain('anti-spam');
    });

    it('loads the script once from the address the server gave, renders the widget with the site key, and hands its tokens to the form\'s own field', async () => {
        const onChange = vi.fn();
        render({ turnstile: widget, onChange });
        render({ turnstile: widget, onChange, processing: true });

        expect(scripts()).toHaveLength(1);
        expect(scripts()[0]?.async).toBe(true);
        expect(host.querySelector('#signup-turnstile_token')?.getAttribute('aria-label')).toBe('Anti-spam check');

        const api = cloudflare();
        await act(async () => {
            scripts()[0]?.dispatchEvent(new Event('load'));
        });

        expect(api.render).toHaveBeenCalledTimes(1);
        expect(api.render.mock.calls[0]?.[0]).toBe(host.querySelector('.admin-signup-turnstile'));
        expect(api.render.mock.calls[0]?.[1]).toMatchObject({ sitekey: widget.siteKey });
        expect(onChange).toHaveBeenCalledWith('turnstile_token', 'XXXX.DUMMY.TOKEN');

        const options = api.render.mock.calls[0]?.[1] as unknown as { 'expired-callback': () => void; 'error-callback': () => void };
        options['expired-callback']();
        expect(onChange).toHaveBeenLastCalledWith('turnstile_token', '');
        onChange.mockClear();
        options['error-callback']();
        expect(onChange).toHaveBeenLastCalledWith('turnstile_token', '');
    });

    it('names the refusal under the widget, moves focus there, and mounts the widget anew for the next attempt', async () => {
        const api = cloudflare();
        await act(async () => render({ turnstile: widget }));
        host.querySelector<HTMLButtonElement>('.admin-signup-submit')?.focus();

        await act(async () => render({ turnstile: widget, attempt: 1, errors: { turnstile_token: FAILED } }));
        const check = host.querySelector('#signup-turnstile_token');

        expect(check?.getAttribute('aria-invalid')).toBe('true');
        expect(check?.getAttribute('aria-describedby')).toBe('signup-turnstile_token-error');
        expect(host.querySelector('#signup-turnstile_token-error')?.textContent).toBe(FAILED);
        expect(document.activeElement).toBe(check);
        expect(api.remove).toHaveBeenCalledWith('widget-1');
        expect(api.render).toHaveBeenCalledTimes(2);
        expect(scripts()).toHaveLength(0);
    });
});

describe('PendingNotice', () => {
    it('asks the holder to check the address the link went to before the click', () => {
        act(() => root.render(<PendingNotice strings={strings} business="Luna Studio" email="owner@luna.example" verified={false} address="https://luna.kleiseradevou.gr" />));

        expect(host.querySelector('h1')?.textContent).toBe('Check your email');
        expect(host.textContent).toContain('We sent a link to owner@luna.example. Open it within 24 hours to set up Luna Studio at https://luna.kleiseradevou.gr.');
    });

    it('names neither the address nor the business to a browser that does not hold the signup', () => {
        act(() => root.render(<PendingNotice strings={strings} business={null} email={null} verified={false} address="https://luna.kleiseradevou.gr" />));

        expect(host.querySelector('h1')?.textContent).toBe('Check your email');
        expect(host.textContent).toContain('We sent a link to the address you gave. Open it within 24 hours to set up your business at https://luna.kleiseradevou.gr.');
    });

    it('says the business is being set up after the click, and speaks of no payment', () => {
        act(() => root.render(<PendingNotice strings={strings} business="Luna Studio" email="owner@luna.example" verified address="https://luna.kleiseradevou.gr" />));

        expect(host.querySelector('[role="status"]')?.textContent).toContain('Setting up Luna Studio');
        expect(host.textContent).toContain('Your email is confirmed. This page opens your sign-in page at https://luna.kleiseradevou.gr as soon as it is ready.');
        expect(host.textContent).not.toContain('payment');

        act(() => root.render(<PendingNotice strings={strings} business={null} email={null} verified address="https://luna.kleiseradevou.gr" />));
        expect(host.querySelector('h1')?.textContent).toBe('Setting up your business');
    });

    it('holds no sentence of its own: both states read from the strings handed to it, their placeholders filled through the one helper', () => {
        act(() => root.render(<PendingNotice strings={keyed()} business={null} email={null} verified={false} address="https://luna.kleiseradevou.gr" />));

        expect(host.querySelector('h1')?.textContent).toBe('[pending.waiting_heading]');
        expect(host.textContent).toContain('[pending.waiting [pending.your_address] [pending.your_business] https://luna.kleiseradevou.gr]');

        act(() => root.render(<PendingNotice strings={keyed()} business="Luna Studio" email="owner@luna.example" verified address="https://luna.kleiseradevou.gr" />));

        expect(host.querySelector('h1')?.textContent).toBe('[pending.verified_heading Luna Studio]');
        expect(host.textContent).toContain('[pending.verified https://luna.kleiseradevou.gr]');
        for (const sentence of Object.values(strings.pending)) {
            expect(host.textContent).not.toContain(sentence);
        }
    });
});

describe('ResendControl', () => {
    it('posts the resend on a press, shows a busy button while the answer is on its way, and the server\'s one sentence under it', () => {
        const onResend = vi.fn();
        act(() => root.render(<ResendControl strings={strings} busy={false} notice={null} onResend={onResend} />));

        const button = host.querySelector<HTMLButtonElement>('.admin-signup-resend');
        expect(button?.textContent).toBe('Send the link again');
        expect(host.querySelector('.admin-signup-resent')).toBeNull();
        act(() => button?.click());
        expect(onResend).toHaveBeenCalledTimes(1);

        act(() => root.render(<ResendControl strings={strings} busy notice={null} onResend={onResend} />));
        expect(host.querySelector('.admin-signup-resend')?.textContent).toContain('Sending...');

        act(() => root.render(<ResendControl strings={strings} busy={false} notice="If that signup is still waiting, a new link is on its way." onResend={onResend} />));
        expect(host.querySelector('.admin-signup-resent')?.textContent).toBe('If that signup is still waiting, a new link is on its way.');
    });

    it('holds no sentence of its own: the control and its busy label read from the strings handed to it', () => {
        act(() => root.render(<ResendControl strings={keyed()} busy={false} notice={null} onResend={() => {}} />));
        expect(host.querySelector('.admin-signup-resend')?.textContent).toBe('[pending.resend]');

        act(() => root.render(<ResendControl strings={keyed()} busy notice={null} onResend={() => {}} />));
        expect(host.querySelector('.admin-signup-resend')?.textContent).toContain('[pending.resending]');
    });
});

describe('the signup page', () => {
    type Options = { callback: (token: string) => void };

    beforeEach(() => {
        inertia.post.mockReset();
        inertia.strings = strings;
    });

    it('empties the token a refused answer leaves behind and mounts the widget anew, the form holding the fresh widget\'s token alone', async () => {
        const api = { render: vi.fn((_box: HTMLElement, _options: Options) => `widget-${api.render.mock.calls.length}`), remove: vi.fn() };
        window.turnstile = api;
        await act(async () =>
            root.render(<Signup categories={categories} timezones={['Europe/Athens', 'UTC']} centralHost="kleiseradevou.gr" action="/signup" legal={LEGAL} refusal={null} turnstile={widget} />),
        );
        act(() => api.render.mock.calls[0]?.[1].callback('XXXX.FIRST.TOKEN'));
        act(() => host.querySelector('form')?.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })));

        expect(inertia.data.turnstile_token).toBe('XXXX.FIRST.TOKEN');
        expect(inertia.post).toHaveBeenCalledTimes(1);

        const [action, visit] = inertia.post.mock.lastCall as [string, { onError: () => void }];

        expect(action).toBe('/signup');

        await act(async () => visit.onError());

        expect(inertia.data.turnstile_token).toBe('');
        expect(api.remove).toHaveBeenCalledWith('widget-1');
        expect(api.render).toHaveBeenCalledTimes(2);
        expect(api.render.mock.calls[1]?.[0]).toBe(host.querySelector('.admin-signup-turnstile'));

        act(() => api.render.mock.calls[1]?.[1].callback('XXXX.FRESH.TOKEN'));

        expect(inertia.data.turnstile_token).toBe('XXXX.FRESH.TOKEN');
        expect(inertia.data.slug).toBe('');
        expect(inertia.data.timezone).toBe('Europe/Athens');
    });

    it('starts with the terms box unticked and holds the ticked value once it is ticked', async () => {
        await act(async () =>
            root.render(<Signup categories={categories} timezones={['Europe/Athens', 'UTC']} centralHost="kleiseradevou.gr" action="/signup" legal={LEGAL} refusal={null} turnstile={null} />),
        );

        expect(inertia.data.terms).toBe('');

        act(() => host.querySelector<HTMLInputElement>('#signup-terms')?.click());

        expect(inertia.data.terms).toBe(TERMS_TICKED);
        expect([...host.querySelectorAll('label[for="signup-terms"] a')].map((link) => link.getAttribute('href'))).toEqual([LEGAL.terms, LEGAL.privacy]);
    });
});
