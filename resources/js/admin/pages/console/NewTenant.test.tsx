import { act } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { mount, type Mounted, press } from '@/components/ui/harness';
import { KEYED_SHELL } from '@/testing/keyed';
import { ToastProvider } from '@/toast/toasts';
import NewTenant, { CREATE_FAILED, NEW_TENANT_NOTE, STORE_PATH } from './NewTenant';

/*
 * The free tenant's form, over a recording router: the fields the signup's form takes save the
 * password, the address the slug becomes shown as it is typed, the timezone at Europe/Athens and
 * the currency at EUR to start, the currency upper-cased on every keystroke, the language at
 * English to start with Greek beside it, the console's own two English words, and one checkbox
 * per feature; Create tenant posting every field and the checked features as one list, busy until
 * the server's answer lands, one submit at a time; every refusal the server's sentence on its
 * field with focus on the first, and a failure the server could not word an error toast that
 * stays.
 */

type Visit = { onStart?: () => void; onFinish?: () => void; onError?: (errors: Record<string, string>) => void };

const inertia = vi.hoisted(() => ({
    post: vi.fn(),
    listeners: new Map<string, (event: Event) => void>(),
}));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, className, children }: { href: string; className?: string; children?: unknown }) => (
        <a href={href} className={className}>
            {children as string}
        </a>
    ),
    usePage: () => ({ props: {} }),
    router: {
        post: inertia.post,
        on: (name: string, listener: (event: Event) => void) => {
            inertia.listeners.set(name, listener);

            return () => inertia.listeners.delete(name);
        },
    },
}));

const STAFF = { value: 'staff', label: 'Staff', help: 'More than one staff member, each with their own hours, services, calendar and approvals.' };

let view: Mounted;

function show(): void {
    view.render(
        <ToastProvider>
            <NewTenant
                flash={null}
                operator="Pat Operator"
                errors={{}}
                nav={[]}
                zone="Europe/Athens"
                brand={{ name: 'Example Bookings' }}
                strings={{ shell: KEYED_SHELL }}
                categories={[
                    { value: 'hair_salon', label: 'Hair salon' },
                    { value: 'barber', label: 'Barber' },
                ]}
                timezones={['Europe/Athens', 'Europe/Lisbon', 'UTC']}
                centralHost="kleiseradevou.gr"
                features={[STAFF]}
            />
        </ToastProvider>,
    );
}

const one = (selector: string) => document.body.querySelector(selector);

function control<T extends HTMLElement>(selector: string, kind: { new (): T }): T {
    const element = one(selector);

    if (!(element instanceof kind)) {
        throw new Error(`No control at ${selector}.`);
    }

    return element;
}

/** Types into a text field as a person does, through React's own value setter. */
function type(selector: string, text: string): void {
    const input = control(selector, HTMLInputElement);

    act(() => {
        Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')?.set?.call(input, text);
        input.dispatchEvent(new Event('input', { bubbles: true }));
    });
}

/** Chooses an option of a select as a person does. */
function choose(selector: string, value: string): void {
    const select = control(selector, HTMLSelectElement);

    act(() => {
        Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'value')?.set?.call(select, value);
        select.dispatchEvent(new Event('change', { bubbles: true }));
    });
}

function lastVisit(): [string, Record<string, unknown>, Visit] {
    const call = inertia.post.mock.lastCall;

    if (call === undefined) {
        throw new Error('No visit was made.');
    }

    return call as [string, Record<string, unknown>, Visit];
}

function submit(): void {
    press(one('.admin-new-tenant-submit'));
}

beforeEach(() => {
    inertia.post.mockReset();
    inertia.listeners.clear();
    view = mount();
});

afterEach(() => view.unmount());

describe('the free tenant\'s form', () => {
    it('starts at Europe/Athens, EUR and English with no kind of business and no feature, the language offered as English and Greek, says what the account is, and asks for no password', () => {
        show();

        expect(one('h1')?.textContent).toBe('New tenant');
        expect(one('.admin-new-tenant__note')?.textContent).toBe(NEW_TENANT_NOTE);
        expect(control('#new-tenant-timezone', HTMLSelectElement).value).toBe('Europe/Athens');
        expect(control('#new-tenant-currency', HTMLInputElement).value).toBe('EUR');
        expect(control('#new-tenant-locale', HTMLSelectElement).value).toBe('en');
        expect([...control('#new-tenant-locale', HTMLSelectElement).options].map((option) => [option.value, option.textContent])).toEqual([
            ['en', 'English'],
            ['el', 'Greek'],
        ]);
        expect(one('label[for="new-tenant-locale"]')?.textContent).toBe('Language');
        expect(control('#new-tenant-category', HTMLSelectElement).value).toBe('');
        expect([...control('#new-tenant-category', HTMLSelectElement).options].map((option) => option.textContent)).toEqual(['None', 'Hair salon', 'Barber']);
        expect(control('#new-tenant-feature-staff', HTMLInputElement).checked).toBe(false);
        expect(one('label[for="new-tenant-feature-staff"]')?.textContent).toBe('Staff');
        expect(one('#new-tenant-feature-staff-help')?.textContent).toBe(STAFF.help);
        expect(one('input[type="password"]')).toBeNull();
        expect(one('.admin-new-tenant-back')?.getAttribute('href')).toBe('/');
    });

    it('shows the address the slug becomes, lower-cased, as it is typed, and upper-cases the currency on every keystroke', () => {
        show();

        expect(one('#new-tenant-slug-help')?.textContent).toBe('their-name.kleiseradevou.gr');

        type('#new-tenant-slug', 'Acme');
        type('#new-tenant-currency', 'gbp');

        expect(one('#new-tenant-slug-help')?.textContent).toBe('acme.kleiseradevou.gr');
        expect(control('#new-tenant-currency', HTMLInputElement).value).toBe('GBP');
    });

    it('posts every field and the checked features as one list, busy until the answer lands, one submit at a time', () => {
        show();
        type('#new-tenant-slug', 'acme');
        type('#new-tenant-name', 'Acme Barbers');
        choose('#new-tenant-category', 'barber');
        choose('#new-tenant-timezone', 'Europe/Lisbon');
        type('#new-tenant-owner_name', 'Alex Owner');
        type('#new-tenant-owner_email', 'alex@acme.example');
        choose('#new-tenant-locale', 'el');
        press(one('#new-tenant-feature-staff'));
        submit();

        const [path, body, visit] = lastVisit();

        expect(path).toBe(STORE_PATH);
        expect(body).toEqual({
            slug: 'acme',
            name: 'Acme Barbers',
            category: 'barber',
            timezone: 'Europe/Lisbon',
            currency: 'EUR',
            owner_name: 'Alex Owner',
            owner_email: 'alex@acme.example',
            locale: 'el',
            features: ['staff'],
        });

        act(() => visit.onStart?.());

        expect(one('.admin-new-tenant-submit')?.textContent).toBe('Creating...');
        expect(one('.admin-new-tenant-submit')?.getAttribute('aria-busy')).toBe('true');

        submit();
        expect(inertia.post).toHaveBeenCalledTimes(1);

        act(() => visit.onFinish?.());

        expect(one('.admin-new-tenant-submit')?.textContent).toBe('Create tenant');
    });

    it('posts the features as the empty list where none is checked', () => {
        show();
        submit();

        expect(lastVisit()[1]['features']).toEqual([]);
    });

    it('shows each refusal on its field with the server\'s sentence and moves focus to the first, the features\' under the boxes', () => {
        show();
        submit();

        const [, , visit] = lastVisit();

        act(() => {
            visit.onStart?.();
            visit.onError?.({ name: 'Enter the name of the business.', slug: 'That subdomain name is already taken.', locale: 'Choose the language from the list.', features: 'Choose the features from the list.' });
            visit.onFinish?.();
        });

        expect(one('#new-tenant-slug-error')?.textContent).toBe('That subdomain name is already taken.');
        expect(one('#new-tenant-slug')?.getAttribute('aria-invalid')).toBe('true');
        expect(one('#new-tenant-name-error')?.textContent).toBe('Enter the name of the business.');
        expect(one('#new-tenant-locale-error')?.textContent).toBe('Choose the language from the list.');
        expect(one('#new-tenant-locale')?.getAttribute('aria-invalid')).toBe('true');
        expect(one('#new-tenant-features-error')?.textContent).toBe('Choose the features from the list.');
        expect(one('#new-tenant-features')?.getAttribute('aria-describedby')).toBe('new-tenant-features-error');
        expect(document.activeElement?.id).toBe('new-tenant-slug');
    });

    it('says a failure the server could not word in an error toast that stays, everything typed kept', () => {
        show();
        type('#new-tenant-slug', 'acme');
        submit();

        const [, , visit] = lastVisit();

        act(() => visit.onStart?.());
        act(() => inertia.listeners.get('exception')?.(new CustomEvent('exception', { cancelable: true, detail: {} })));
        act(() => visit.onFinish?.());

        expect([...document.querySelectorAll('.admin-toast[data-kind="error"] p')].map((line) => line.textContent)).toEqual([CREATE_FAILED]);
        expect(control('#new-tenant-slug', HTMLInputElement).value).toBe('acme');
    });
});
