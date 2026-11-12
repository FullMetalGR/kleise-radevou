import { act } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { mount, type Mounted, press } from '@/components/ui/harness';
import { KEYED_SHELL, KEYED_WIZARD, keyedShell, keyedWizard } from '@/testing/keyed';
import { ToastProvider } from '@/toast/toasts';
import type { ConsoleProps, SharedProps } from '@/types';
import { AdminLayout, ConsoleLayout, consoleZoneLine, pageLayout } from './layouts';

/*
 * The shell's frame: the top bar's one control is the New booking button, rendered for
 * manageBookings alone, and it opens the wizard over whatever page is showing. The brand: a small
 * wordmark beside the business's name in the admin's top bar and nothing else of the brand there,
 * the wordmark in place of the console's name on the console's shell, which wears the brand's
 * scope, and the brand's scope around the signup's pages and the console's door. The name is the
 * brand prop's, never one compiled into the bundle. The frames render inside the shell's keyed
 * table, as a page renders inside the shell's words, and read what they expect through it.
 */

const shell = keyedShell();

const inertia = vi.hoisted(() => ({
    props: {} as Record<string, unknown>,
    post: vi.fn(),
    put: vi.fn(),
    listeners: new Map<string, (event: Event) => void>(),
}));

vi.mock('@inertiajs/react', () => ({
    Link: ({ href, children, ...rest }: { href: string; children: React.ReactNode }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
    usePage: () => ({ props: inertia.props, url: '/admin/settings' }),
    router: {
        post: inertia.post,
        put: inertia.put,
        on: (name: string, listener: (event: Event) => void) => {
            inertia.listeners.set(name, listener);

            return () => inertia.listeners.delete(name);
        },
    },
}));

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
    nav: [{ label: KEYED_SHELL.nav.settings, url: '/admin/settings', group: KEYED_SHELL.nav.groups.business, pending: null, current: true }],
    catalog: { services: [{ id: 1, name: 'Haircut', duration_min: 45, processing_min: 0, staff: [2] }], staff: [{ id: 2, name: 'Elena Papadaki' }], members: 2 },
    wizard: KEYED_WIZARD,
};

let mounted: Mounted;

beforeEach(() => {
    mounted = mount();
    vi.stubGlobal('fetch', vi.fn(async () => new Response('{}', { status: 200 })));
});

afterEach(() => {
    mounted.unmount();
    vi.unstubAllGlobals();
});

function render(props: SharedProps): void {
    inertia.props = props;
    mounted.render(
        <ToastProvider>
            <AdminLayout>
                <h1>The page</h1>
            </AdminLayout>
        </ToastProvider>,
    );
}

describe('the top bar\'s New booking button', () => {
    it('renders for manageBookings and opens the wizard over the page', async () => {
        render(PROPS);

        expect(document.querySelector('.admin-topbar .admin-new-booking')?.textContent).toBe(shell.text('top_bar.new_booking'));
        expect(document.querySelector('.admin-wizard')).toBeNull();

        press(document.querySelector('.admin-new-booking'));

        expect(document.querySelector('.admin-wizard .admin-modal__title')?.textContent).toBe(keyedWizard().text('title'));
        expect(document.querySelector('main h1')?.textContent).toBe('The page');
    });

    it('renders for nobody else', () => {
        render({ ...PROPS, permissions: ['viewBookings', 'approveBookings'], catalog: null, wizard: null });

        expect(document.querySelector('.admin-new-booking')).toBeNull();
    });
});

describe('the admin\'s top bar and the brand', () => {
    it('carries a small wordmark naming the brand prop\'s name beside the business\'s name', () => {
        render(PROPS);

        const bar = document.querySelector('.admin-topbar');
        const wordmark = bar?.querySelector('.brand-wordmark');

        expect(wordmark?.classList.contains('brand-wordmark--small')).toBe(true);
        expect(wordmark?.querySelector('.brand-wordmark__name')?.textContent).toBe('Example Bookings');
        expect(wordmark?.nextElementSibling?.classList.contains('admin-business')).toBe(true);
        expect(bar?.querySelector('.admin-business')?.textContent).toBe('Luna');
    });

    it('keeps the admin outside the brand\'s scope, so its own theme applies everywhere in it', () => {
        render(PROPS);

        expect(document.querySelector('.admin-shell')).not.toBeNull();
        expect(document.querySelector('.brand-scope')).toBeNull();
    });

    it('names whatever the server names, nothing compiled in', () => {
        render({ ...PROPS, brand: { name: 'Another Name' } });

        expect(document.querySelector('.admin-topbar .brand-wordmark__name')?.textContent).toBe('Another Name');
    });
});

describe('the narrowing banner', () => {
    it('sits in the page area above the page content while the record names a feature, linking to Billing for manageBilling', () => {
        render({ ...PROPS, entitlements: [], narrowed: ['staff'], permissions: [...PROPS.permissions, 'manageBilling'] });

        const page = document.querySelector('.admin-page');
        expect(page?.firstElementChild?.classList.contains('admin-narrowed')).toBe(true);
        expect(document.querySelectorAll('.admin-narrowed__line')).toHaveLength(1);
        expect(document.querySelector('a.admin-narrowed__billing')?.getAttribute('href')).toBe('/admin/billing');
    });

    it('names Billing in plain text for a user without manageBilling', () => {
        render({ ...PROPS, entitlements: [], narrowed: ['staff'] });

        expect(document.querySelector('.admin-narrowed__billing')?.tagName).toBe('SPAN');
    });

    it('names platform support with no link on a free tenant, whose plan is the console\'s, from the shared free prop', () => {
        render({ ...PROPS, entitlements: [], narrowed: ['staff'], free: true, permissions: [...PROPS.permissions, 'manageBilling'] });

        expect(document.querySelector('.admin-narrowed__line')?.textContent).toBe(shell.text('banners.features.staff.gone_free'));
        expect(document.querySelector('.admin-narrowed a')).toBeNull();
        expect(document.querySelector('.admin-narrowed__billing')).toBeNull();
    });

    it('is absent while the record is empty', () => {
        render(PROPS);

        expect(document.querySelector('.admin-narrowed')).toBeNull();
    });
});

describe('the support session\'s banner', () => {
    const SUPPORT: SharedProps = {
        ...PROPS,
        auth: { user: { id: null, name: shell.text('shared.support.named', { operator: 'Pat Operator' }), role: 'support' } },
        support: { operator: 'Pat Operator', ends_at: '2026-11-02T07:00:00+00:00' },
    };

    it('sits first in the page area for support, above the narrowing banner, naming the operator and the end on the tenant\'s clock', () => {
        render({ ...SUPPORT, entitlements: [], narrowed: ['staff'] });

        const page = document.querySelector('.admin-page');
        expect(page?.children[0]?.classList.contains('admin-support-banner')).toBe(true);
        expect(page?.children[1]?.classList.contains('admin-narrowed')).toBe(true);
        expect(document.querySelector('.admin-support-banner__line')?.textContent).toBe(shell.text('banners.support.line', { operator: 'Pat Operator', end: '02/11/2026 09:00', zone: 'Europe/Athens' }));
    });

    it('posts the admin\'s sign-out on End session, busy until the answer lands', () => {
        inertia.post.mockClear();
        render(SUPPORT);

        press(document.querySelector('.admin-support-banner__end'));

        expect(inertia.post).toHaveBeenCalledOnce();
        expect(inertia.post.mock.calls[0]?.[0]).toBe('/admin/logout');

        const visit = inertia.post.mock.calls[0]?.[2] as { onStart: () => void; onFinish: () => void };
        act(() => visit.onStart());

        expect(document.querySelector('.admin-support-banner__end')?.textContent).toBe(shell.text('banners.support.ending'));
        expect(document.querySelector('.admin-signout')?.textContent).toBe(shell.text('top_bar.sign_out'));

        act(() => visit.onFinish());

        expect(document.querySelector('.admin-support-banner__end')?.textContent).toBe(shell.text('banners.support.end'));
    });

    it('is absent for a user', () => {
        render({ ...PROPS, support: null });

        expect(document.querySelector('.admin-support-banner')).toBeNull();
    });
});

describe('the top bar\'s language control', () => {
    // The names as the server hands them, here the keyed names in brackets.
    const LANGUAGES = [
        { value: 'en', name: '[site.language.en]' },
        { value: 'el', name: '[site.language.el]' },
    ];

    it('renders no control where the server shares no choices, as for support', () => {
        render(PROPS);

        expect(document.querySelector('.admin-language')).toBeNull();
    });

    it('renders the choices before the theme switch, the page\'s language pressed', () => {
        render({ ...PROPS, language: 'el', languages: LANGUAGES });

        const control = document.querySelector('.admin-topbar .admin-language');
        expect(control?.nextElementSibling?.classList.contains('admin-theme')).toBe(true);
        expect([...document.querySelectorAll('.admin-language__choice')].map((choice) => [choice.textContent, choice.getAttribute('aria-pressed')])).toEqual([
            ['[site.language.en]', 'false'],
            ['[site.language.el]', 'true'],
        ]);
    });

    it('writes the chosen language through PUT /admin/language/{language}, busy until the answer lands', () => {
        inertia.put.mockClear();
        render({ ...PROPS, languages: LANGUAGES });

        press(document.querySelector('.admin-language__choice[lang="el"]'));

        expect(inertia.put).toHaveBeenCalledOnce();
        expect(inertia.put.mock.calls[0]?.[0]).toBe('/admin/language/el');

        const visit = inertia.put.mock.calls[0]?.[2] as { onStart: () => void; onFinish: () => void };
        act(() => visit.onStart());

        expect(document.querySelector('.admin-language__choice[lang="el"]')?.getAttribute('aria-busy')).toBe('true');

        act(() => visit.onFinish());

        expect(document.querySelector('.admin-language__choice[lang="el"]')?.getAttribute('aria-busy')).toBeNull();
    });

    it('shows a write the server refused as an error toast with the answer\'s own sentence', () => {
        inertia.put.mockClear();
        render({ ...PROPS, languages: LANGUAGES });

        press(document.querySelector('.admin-language__choice[lang="el"]'));
        const visit = inertia.put.mock.calls[0]?.[2] as { onStart: () => void; onFinish: () => void };
        act(() => visit.onStart());
        act(() => inertia.listeners.get('invalid')?.(new CustomEvent('invalid', { cancelable: true, detail: { response: { status: 400, data: { code: 'invalid_request', message: 'invalid_request', data: { status: 400, detail: '[refusal]' } } } } })));
        act(() => visit.onFinish());

        expect([...document.querySelectorAll('.admin-toast p')].map((paragraph) => paragraph.textContent)).toContain('[refusal]');
    });

    it('repaints the body in the shared colour theme a save hands back, with no reload, and in neutral where none is shared', () => {
        render({ ...PROPS, theme: 'violet' });
        expect(document.body.dataset.theme).toBe('violet');

        render({ ...PROPS, theme: 'rose' });
        expect(document.body.dataset.theme).toBe('rose');

        render({ ...PROPS, theme: undefined });
        expect(document.body.dataset.theme).toBe('neutral');
    });

    it('keeps the document\'s lang in step with the page\'s language, inside the shell and on the door', () => {
        render({ ...PROPS, language: 'el' });
        expect(document.documentElement.lang).toBe('el');

        render({ ...PROPS, language: 'en' });
        expect(document.documentElement.lang).toBe('en');

        inertia.props = { ...PROPS, language: 'el' };
        mounted.render(<ToastProvider>{pageLayout('auth/Login')(<h1>auth/Login</h1>)}</ToastProvider>);
        expect(document.documentElement.lang).toBe('el');
    });
});

const CONSOLE: ConsoleProps = { flash: null, operator: null, errors: {}, nav: [], zone: 'Europe/Athens', brand: { name: 'Example Bookings' }, strings: { shell: KEYED_SHELL } };

function renderConsole(props: ConsoleProps): void {
    inertia.props = props;
    mounted.render(
        <ToastProvider>
            <ConsoleLayout>
                <h1>Tenants</h1>
            </ConsoleLayout>
        </ToastProvider>,
    );
}

describe('the console\'s shell', () => {
    it('names the console in the top bar, renders an empty rail and the page, and no operator or Sign out while the operator is null', () => {
        renderConsole(CONSOLE);

        expect(document.querySelector('.admin-topbar .admin-business')?.textContent).toBe('Example Bookings console');
        expect(document.querySelector('.admin-rail')).not.toBeNull();
        expect(document.querySelectorAll('.admin-rail-entry')).toHaveLength(0);
        expect(document.querySelector('.admin-signout')).toBeNull();
        expect(document.querySelector('.admin-new-booking')).toBeNull();
        expect(document.querySelector('main h1')?.textContent).toBe('Tenants');
    });

    it('keeps the admin\'s theme switch', () => {
        renderConsole(CONSOLE);

        expect(document.querySelector('.admin-topbar .admin-theme')).not.toBeNull();
    });

    it('carries the wordmark in place of the console\'s name, the brand prop\'s name in it, and wears the brand\'s scope', () => {
        renderConsole({ ...CONSOLE, brand: { name: 'Another Name' } });

        const business = document.querySelector('.admin-topbar .admin-business');

        expect(business?.querySelector('.brand-wordmark:not(.brand-wordmark--small) .brand-wordmark__name')?.textContent).toBe('Another Name');
        expect(business?.querySelector('.admin-console-label')?.textContent).toBe('console');
        expect(document.querySelector('.admin-shell')?.classList.contains('brand-scope')).toBe(true);
    });

    it('names the console\'s zone once, in the top bar, as the shared prop names it', () => {
        renderConsole(CONSOLE);

        expect(document.querySelectorAll('.admin-console-zone')).toHaveLength(1);
        expect(document.querySelector('.admin-topbar .admin-console-zone')?.textContent).toBe('Times are in Europe/Athens');
        expect(consoleZoneLine('UTC')).toBe('Times are in UTC');
    });

    it('names the operator and posts the sign-out to the host\'s /logout once the operator prop names one', () => {
        inertia.post.mockClear();
        renderConsole({ ...CONSOLE, operator: 'Pat Operator' });

        expect(document.querySelector('.admin-topbar')?.textContent).toContain('Pat Operator');

        press(document.querySelector('.admin-signout'));

        expect(inertia.post).toHaveBeenCalledOnce();
        expect(inertia.post.mock.calls[0]?.[0]).toBe('/logout');
    });

    it('renders the rail from the nav prop the server hands it', () => {
        renderConsole({
            ...CONSOLE,
            operator: 'Pat Operator',
            nav: [
                { label: 'Tenants', url: '/', group: 'Platform', pending: null, current: false },
                { label: 'Audit', url: '/audit', group: 'Platform', pending: null, current: true },
            ],
        });

        expect([...document.querySelectorAll('.admin-rail-entry')].map((entry) => entry.getAttribute('href'))).toEqual(['/', '/audit']);
        expect(document.querySelector('.admin-rail-entry[aria-current="page"]')?.textContent).toBe('Audit');
    });

    it('shows the flash as a toast', () => {
        renderConsole({ ...CONSOLE, flash: { kind: 'success', message: 'Tenant suspended.' } });

        expect([...document.querySelectorAll('.admin-toast p')].map((paragraph) => paragraph.textContent)).toContain('Tenant suspended.');
    });
});

describe('the frame a page renders in, by its name', () => {
    function renderPage(name: string, props: Record<string, unknown>): void {
        inertia.props = props;
        mounted.render(<ToastProvider>{pageLayout(name)(<h1>{name}</h1>)}</ToastProvider>);
    }

    it('renders the console\'s door alone, outside the console\'s shell', () => {
        renderPage('console/Login', CONSOLE);

        expect(document.querySelector('.admin-shell')).toBeNull();
        expect(document.querySelector('h1')?.textContent).toBe('console/Login');
    });

    it('renders every other console page inside the console\'s shell', () => {
        renderPage('console/Tenants', CONSOLE);

        expect(document.querySelector('.admin-shell .admin-business')?.textContent).toBe('Example Bookings console');
        expect(document.querySelector('.admin-page h1')?.textContent).toBe('console/Tenants');
    });

    it('renders the console\'s home, the tenant list, and its 404 page inside the console\'s shell, the operator named beside Sign out', () => {
        for (const name of ['console/Tenants', 'console/NotFound']) {
            renderPage(name, { ...CONSOLE, operator: 'Pat Operator' });

            expect(document.querySelector('.admin-page h1')?.textContent).toBe(name);
            expect(document.querySelector('.admin-topbar')?.textContent).toContain('Pat Operator');
            expect(document.querySelector('.admin-topbar .admin-signout')?.textContent).toBe(shell.text('top_bar.sign_out'));
        }
    });

    it('renders the admin\'s door and the signup alone, and every admin page inside the admin\'s shell', () => {
        renderPage('auth/Login', PROPS);
        expect(document.querySelector('.admin-shell')).toBeNull();

        renderPage('signup/Signup', PROPS);
        expect(document.querySelector('.admin-shell')).toBeNull();

        renderPage('Settings', PROPS);
        expect(document.querySelector('.admin-shell .admin-business')?.textContent).toBe('Luna');
    });

    it('wraps the signup\'s pages and the console\'s door in the brand\'s scope, and leaves the admin\'s door in the admin\'s own theme', () => {
        renderPage('signup/Signup', { flash: null, errors: {}, brand: { name: 'Example Bookings' } });
        expect(document.querySelector('.brand-scope h1')?.textContent).toBe('signup/Signup');

        renderPage('signup/Pending', { flash: null, errors: {}, brand: { name: 'Example Bookings' } });
        expect(document.querySelector('.brand-scope h1')?.textContent).toBe('signup/Pending');

        renderPage('console/Login', CONSOLE);
        expect(document.querySelector('.brand-scope h1')?.textContent).toBe('console/Login');

        renderPage('auth/Login', PROPS);
        expect(document.querySelector('.brand-scope')).toBeNull();
        expect(document.querySelector('h1')?.textContent).toBe('auth/Login');
    });
});
