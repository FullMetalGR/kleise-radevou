import { readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { act, type MouseEvent, type ReactNode, useState } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { drag, key, onCompact, onPhone, press, settle, wideWidth } from '@/components/ui/harness';
import { PageHeader } from '@/components/ui/page-header';
import { KEYED_SHELL, keyedShell, WithShell } from '@/testing/keyed';
import type { NavEntry } from '../types';
import { RAIL_LABEL } from './Rail';
import { BOUNDARY_NOTICE, BOUNDARY_REST } from './RouteBoundary';
import { MENU_LABEL } from './MenuButton';
import { Shell } from './Shell';

/*
 * The shell and the boundary each page renders inside. From the wide breakpoint the menu stands
 * in the sidebar beside the page, and the page's first row, its header, carries the shell's
 * controls and no menu button. Below it the sidebar goes, the menu's first group stands in a bar
 * along the foot, and the whole menu becomes a drawer on the modal primitive, opened by a round
 * button first in the page's first row or by the bar's Menu button: the same nav list with the
 * same current entry, then the controls, which leave the first row, closing on a choice, on its
 * twin of the button, on Escape and on the backdrop, focus going back to the button that opened
 * it. The framework's link is stood in for by an anchor that makes no visit, as the real one
 * makes its own. The shell renders inside the keyed table, its own words read here through it,
 * and the menu's labels are the server's, handed as the keyed table spells them. A skip link to
 * the page's content is the first thing the keyboard reaches. The page here is a header and
 * nothing else, as every screen opens with one.
 */

const shell = keyedShell();
const N = KEYED_SHELL.nav;
const MENU = shell.text(MENU_LABEL);
const RAIL = `nav[aria-label="${shell.text(RAIL_LABEL)}"]`;

vi.mock('@inertiajs/react', () => ({
    Link: ({ href, children, onClick, ...rest }: { href: string; children: ReactNode; onClick?: (event: MouseEvent<HTMLAnchorElement>) => void }) => (
        <a
            href={href}
            {...rest}
            onClick={(event) => {
                event.preventDefault();
                onClick?.(event);
            }}
        >
            {children}
        </a>
    ),
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
    vi.restoreAllMocks();
});

const NAV: NavEntry[] = [
    { key: 'calendar', label: N.calendar, url: '/admin', group: N.groups.schedule, pending: null, current: true },
    { key: 'bookings', label: N.bookings, url: '/admin/bookings', group: N.groups.schedule, pending: null, current: false },
];

function Broken(): never {
    throw new TypeError('the grid had no rows');
}

const PAGE = <PageHeader title="The page" />;

function render(route: string, page: React.ReactNode, onToggleTheme = () => {}): void {
    act(() =>
        root.render(
            <WithShell>
                <Shell
                    route={route}
                    nav={NAV}
                    brand={(collapsed) => <span className="brand">{collapsed ? 'small' : 'whole'}</span>}
                    business="Luna Hair Studio"
                    userName="Alex Owner"
                    theme="light"
                    onToggleTheme={onToggleTheme}
                    signingOut={false}
                    onSignOut={() => {}}
                >
                    {page}
                </Shell>
            </WithShell>,
        ),
    );
}

describe('the shell', () => {
    it('renders the sidebar with the brand, the business and the menu, and the page with the controls in its first row', () => {
        render('/admin', PAGE);

        expect(host.querySelector('aside.admin-sidebar nav')?.textContent).toContain(N.bookings);
        expect(host.querySelector('aside.admin-sidebar .brand')?.textContent).toBe('whole');
        expect(host.querySelector('aside.admin-sidebar .admin-business')?.textContent).toContain('Luna Hair Studio');
        expect(host.querySelector('main h1')?.textContent).toBe('The page');
        expect(host.querySelector('main .admin-topbar .admin-controls .admin-user')?.textContent).toBe('Alex Owner');
        expect(host.querySelector('.admin-bottom-bar')).toBeNull();
        expect(host.querySelector('.admin-menu')).toBeNull();
    });

    it('puts its own button last in the page\'s first row, after the page\'s, quiet where the page has buttons of its own', () => {
        const action = (quiet: boolean) => (
            <button type="button" className="shell-action" data-quiet={String(quiet)}>
                New
            </button>
        );

        act(() =>
            root.render(
                <WithShell>
                    <Shell route="/admin" nav={NAV} brand={() => null} business={null} userName="Alex" theme="light" onToggleTheme={() => {}} signingOut={false} action={action}>
                        <PageHeader title="The page" actions={<button type="button">Own</button>} />
                    </Shell>
                </WithShell>,
            ),
        );

        const end = host.querySelector('.admin-topbar .admin-page-header__end');

        expect(end?.lastElementChild?.matches('.shell-action')).toBe(true);
        expect(end?.lastElementChild?.getAttribute('data-quiet')).toBe('true');
        expect(host.querySelector('.admin-business')).toBeNull();
    });

    it('shows the boundary notice with the error\'s name, message and component stack, keeps the menu and the controls working, and re-reports to the console', () => {
        const report = vi.spyOn(console, 'error').mockImplementation(() => {});
        const toggle = vi.fn();

        render('/admin', <Broken />, toggle);

        const notice = host.querySelector('main [role="alert"]');
        const detail = notice?.querySelector('pre')?.textContent ?? '';

        expect(notice?.querySelector('h1')?.textContent).toBe(shell.text(BOUNDARY_NOTICE));
        expect(notice?.textContent).toContain(shell.text(BOUNDARY_REST));
        expect(detail).toContain('TypeError: the grid had no rows');
        expect(detail).toContain('Broken');
        expect(report.mock.calls.some((call) => call[0] instanceof TypeError && (call[0] as Error).message === 'the grid had no rows')).toBe(true);

        expect(host.querySelectorAll('nav a')).toHaveLength(2);
        act(() => host.querySelector<HTMLButtonElement>('.admin-theme')?.click());
        expect(toggle).toHaveBeenCalledOnce();
    });

    it('mounts a fresh boundary on another route', () => {
        vi.spyOn(console, 'error').mockImplementation(() => {});

        render('/admin', <Broken />);
        expect(host.querySelector('main [role="alert"]')).not.toBeNull();

        render('/admin/bookings', <PageHeader title="Another page" />);
        expect(host.querySelector('main [role="alert"]')).toBeNull();
        expect(host.querySelector('main h1')?.textContent).toBe('Another page');
    });

    it('keeps the page mounted across a change of query string on the same path, and remounts it on another path', () => {
        function Counter() {
            const [count, setCount] = useState(0);

            return (
                <button type="button" className="counter" onClick={() => setCount(count + 1)}>
                    {count}
                </button>
            );
        }

        render('/admin/bookings', <Counter />);
        act(() => host.querySelector<HTMLButtonElement>('.counter')?.click());
        render('/admin/bookings?staff=3&search=ada', <Counter />);
        expect(host.querySelector('.counter')?.textContent).toBe('1');

        render('/admin/bookings/7b7f0e5a-0000-4000-8000-000000000001?staff=3', <Counter />);
        expect(host.querySelector('.counter')?.textContent).toBe('0');
    });

    it('shows sign-out busy while it is on its way', () => {
        act(() =>
            root.render(
                <WithShell>
                    <Shell route="/admin" nav={NAV} brand={() => null} business="Luna" userName="Alex" theme="light" onToggleTheme={() => {}} signingOut onSignOut={() => {}}>
                        {PAGE}
                    </Shell>
                </WithShell>,
            ),
        );

        const button = host.querySelector<HTMLButtonElement>('.admin-signout');

        expect(button?.disabled).toBe(true);
        expect(button?.textContent).toBe(shell.text('top_bar.signing_out'));
    });
});

describe('the skip link', () => {
    const FOCUSABLE = 'a[href], button, input, select, textarea, [tabindex]:not([tabindex="-1"])';

    it('is the first thing the keyboard reaches, pointing at the page\'s content, which takes focus from a script alone', () => {
        render('/admin', PAGE);

        const skip = host.querySelector(FOCUSABLE);
        const main = host.querySelector('main');

        expect(skip?.matches('a.admin-skip')).toBe(true);
        expect(skip?.getAttribute('href')).toBe('#admin-main');
        expect(skip?.textContent).toBe(shell.text('nav.skip'));
        expect(main?.id).toBe('admin-main');
        expect(main?.getAttribute('tabindex')).toBe('-1');
    });

    it('is shown on focus alone', () => {
        render('/admin', <h1>The page</h1>);

        const skip = host.querySelector('.admin-skip');

        expect(skip?.classList.contains('sr-only')).toBe(true);
        expect(skip?.classList.contains('focus:not-sr-only')).toBe(true);
    });

    it('moves the focus to the page\'s content on a press, leaving the address as it was', () => {
        render('/admin', <h1>The page</h1>);

        const before = window.location.href;
        press(host.querySelector('.admin-skip'));

        expect(document.activeElement).toBe(host.querySelector('main'));
        expect(window.location.href).toBe(before);
    });

    it('comes first below the breakpoint too, before the menu\'s button', () => {
        const undo = onPhone();

        try {
            render('/admin', PAGE);

            expect(host.querySelector(FOCUSABLE)?.matches('a.admin-skip')).toBe(true);
        } finally {
            undo();
        }
    });
});

describe('at or above the wide breakpoint', () => {
    it('stands the menu in the sidebar beside the page and puts no menu button in the first row', () => {
        render('/admin', PAGE);

        expect(host.querySelector(`aside.admin-sidebar ${RAIL}`)).not.toBeNull();
        expect(host.querySelector('.admin-menu')).toBeNull();
        expect(host.querySelector('.admin-topbar')?.firstElementChild?.matches('h1')).toBe(true);
        expect(document.querySelectorAll('[role="dialog"]')).toHaveLength(0);
    });

    it('sets the sidebar before the page, so the keyboard reaches the menu after the skip link and before the content', () => {
        render('/admin', PAGE);

        const sidebar = host.querySelector('aside.admin-sidebar');

        expect(sidebar?.compareDocumentPosition(host.querySelector('main') as Node)).toBe(Node.DOCUMENT_POSITION_FOLLOWING);
        expect(host.querySelector('.admin-skip')?.compareDocumentPosition(sidebar as Node)).toBe(Node.DOCUMENT_POSITION_FOLLOWING);
    });
});

describe.each([
    ['on a compact screen', onCompact],
    ['on a phone', onPhone],
])('below the wide breakpoint, %s', (_name, screen) => {
    let undo: () => void;

    beforeEach(() => {
        undo = screen();
    });

    afterEach(() => undo());

    const control = () => host.querySelector<HTMLButtonElement>('.admin-topbar .admin-menu');
    const drawer = () => document.querySelector('[role="dialog"]');

    function open(): HTMLButtonElement {
        render('/admin', PAGE);

        const button = control();

        if (button === null) {
            throw new Error('No control.');
        }

        button.focus();
        press(button);

        return button;
    }

    function renderNav(nav: NavEntry[]): void {
        act(() =>
            root.render(
                <WithShell>
                    <Shell route="/admin" nav={nav} brand={() => null} business="Luna Hair Studio" userName="Owner" theme="light" onToggleTheme={() => {}} signingOut={false}>
                        {PAGE}
                    </Shell>
                </WithShell>,
            ),
        );
    }

    it('takes the sidebar away, puts the menu\'s button first in the page\'s first row and the first group in the bar along the foot, the page still rendered', () => {
        render('/admin', PAGE);

        expect(host.querySelector('aside')).toBeNull();
        expect(host.querySelector('nav.admin-rail')).toBeNull();
        expect(host.querySelector('.admin-topbar')?.firstElementChild).toBe(control());
        expect(control()?.textContent).toBe(MENU);
        expect(control()?.getAttribute('aria-haspopup')).toBe('dialog');
        expect(control()?.getAttribute('aria-expanded')).toBe('false');
        expect(host.querySelectorAll('.admin-menu')).toHaveLength(1);
        expect([...host.querySelectorAll('nav.admin-bottom-bar a')].map((link) => link.getAttribute('href'))).toEqual(['/admin', '/admin/bookings']);
        expect(host.querySelector('main h1')?.textContent).toBe('The page');
    });

    it('moves the controls out of the page\'s first row, which keeps its own button', () => {
        const action = () => (
            <button type="button" className="shell-action">
                New
            </button>
        );

        act(() =>
            root.render(
                <WithShell>
                    <Shell route="/admin" nav={NAV} brand={() => null} business={null} userName="Alex" theme="light" onToggleTheme={() => {}} signingOut={false} onSignOut={() => {}} action={action}>
                        {PAGE}
                    </Shell>
                </WithShell>,
            ),
        );

        expect(host.querySelector('.admin-controls')).toBeNull();
        expect(host.querySelector('.admin-user')).toBeNull();
        expect(host.querySelector('.admin-topbar .admin-page-header__end')?.lastElementChild?.matches('.shell-action')).toBe(true);
    });

    it('carries the pending count on the bar\'s entry where the bar holds it, and on the menu\'s button where it does not, and none for none', () => {
        renderNav([
            { key: 'calendar', label: N.calendar, url: '/admin', group: N.groups.schedule, pending: null, current: true },
            { key: 'bookings', label: N.bookings, url: '/admin/bookings', group: N.groups.schedule, pending: 3, current: false },
        ]);

        expect(host.querySelector('.admin-bottom-bar a[href="/admin/bookings"] [role="img"]')?.textContent).toBe('3');
        expect(control()?.querySelector('.admin-menu-pending')).toBeNull();
        expect(control()?.textContent).toBe(MENU);

        press(control());

        expect(drawer()?.querySelector('a[href="/admin/bookings"] .admin-rail-pending')?.textContent).toBe('3');

        key(document.body, 'Escape');

        renderNav([
            { key: 'calendar', label: N.calendar, url: '/admin', group: N.groups.schedule, pending: null, current: true },
            { key: 'bookings', label: N.bookings, url: '/admin/bookings', group: N.groups.business, pending: 3, current: false },
        ]);

        expect(host.querySelector('.admin-bottom-bar')).toBeNull();
        expect(control()?.querySelector('.admin-menu-pending')?.textContent).toBe('3');
        expect(control()?.querySelector('.admin-menu-pending')?.getAttribute('aria-label')).toBe(shell.choice('nav.pending', 3));

        renderNav([
            { key: 'home', label: N.home, url: '/admin', group: N.groups.schedule, pending: null, current: true },
            { key: 'calendar', label: N.calendar, url: '/admin/calendar', group: N.groups.schedule, pending: null, current: false },
            { key: 'bookings', label: N.bookings, url: '/admin/bookings', group: N.groups.business, pending: 3, current: false },
        ]);

        expect(host.querySelector('.admin-bottom-bar')).not.toBeNull();
        expect(host.querySelector('.admin-bottom-bar [role="img"]')).toBeNull();
        expect(control()?.querySelector('.admin-menu-pending')?.textContent).toBe('3');

        render('/admin', PAGE);

        expect(control()?.querySelector('.admin-menu-pending')).toBeNull();
        expect(control()?.textContent).toBe(MENU);
    });

    it('opens the menu as a drawer on the modal primitive, the same list with the same current entry, under the brand and the business', () => {
        open();

        expect(drawer()?.classList.contains('admin-modal__frame--drawer')).toBe(true);
        expect(drawer()?.querySelector('.admin-modal__title')?.textContent).toBe(MENU);
        expect([...(drawer()?.querySelectorAll(`${RAIL} a`) ?? [])].map((link) => link.getAttribute('href'))).toEqual(['/admin', '/admin/bookings']);
        expect(drawer()?.querySelector('[aria-current="page"]')?.getAttribute('href')).toBe('/admin');
        expect(drawer()?.querySelector('.brand')?.textContent).toBe('whole');
        expect(drawer()?.querySelector('.admin-business')?.textContent).toContain('Luna Hair Studio');
        expect(control()?.getAttribute('aria-expanded')).toBe('true');
    });

    it('holds the whole menu with every label, then the controls stacked, and a twin of the button at its head named Close', () => {
        open();

        const rail = drawer()?.querySelector(RAIL);
        const controls = drawer()?.querySelector('.admin-controls');
        const twin = drawer()?.querySelector<HTMLButtonElement>('.admin-menu');

        expect([...(rail?.querySelectorAll('.admin-rail-entry__label') ?? [])].map((label) => label.textContent)).toEqual([N.calendar, N.bookings]);
        expect(controls?.classList.contains('flex-col')).toBe(true);
        expect(controls?.querySelector('.admin-user')?.textContent).toBe('Alex Owner');
        expect(controls?.querySelector('.admin-signout')).not.toBeNull();
        expect(controls?.querySelector('.admin-theme')).not.toBeNull();
        expect(rail?.compareDocumentPosition(controls as Node)).toBe(Node.DOCUMENT_POSITION_FOLLOWING);
        expect(twin?.textContent).toBe(shell.text('nav.close'));
        expect(twin?.getAttribute('aria-expanded')).toBe('true');
        expect(twin?.compareDocumentPosition(rail as Node)).toBe(Node.DOCUMENT_POSITION_FOLLOWING);
    });

    it('closes on its twin of the button, focus going back to the button that opened it', async () => {
        const button = open();

        press(drawer()?.querySelector('.admin-menu') ?? null);
        await settle();

        expect(drawer()).toBeNull();
        expect(document.activeElement).toBe(button);
    });

    it('opens from the bar\'s Menu button too, and hands the focus back to it on Escape', async () => {
        render('/admin', PAGE);

        const menu = host.querySelector<HTMLButtonElement>('.admin-bottom-bar__menu');

        menu?.focus();
        press(menu);

        expect(drawer()).not.toBeNull();
        expect(menu?.getAttribute('aria-expanded')).toBe('true');

        key(document.body, 'Escape');
        await settle();

        expect(drawer()).toBeNull();
        expect(document.activeElement).toBe(menu);
    });

    it('closes as the window widens past the wide breakpoint', () => {
        const wideQuery = `(min-width: ${wideWidth()})`;
        const heard = new Set<() => void>();
        let widened = false;
        const narrow = window.matchMedia;

        window.matchMedia = (query: string) =>
            ({
                matches: query === wideQuery ? widened : narrow(query).matches,
                media: query,
                addEventListener: (_type: string, listener: () => void) => heard.add(listener),
                removeEventListener: (_type: string, listener: () => void) => heard.delete(listener),
            }) as unknown as MediaQueryList;

        try {
            open();

            expect(drawer()).not.toBeNull();

            widened = true;
            act(() => {
                for (const listener of heard) {
                    listener();
                }
            });

            expect(drawer()).toBeNull();
            expect(host.querySelector('aside.admin-sidebar')).not.toBeNull();
            expect(host.querySelector('.admin-bottom-bar')).toBeNull();
        } finally {
            window.matchMedia = narrow;
        }
    });

    it('closes on a choice, focus going back to the button', async () => {
        const button = open();

        press(drawer()?.querySelector('a[href="/admin/bookings"]') ?? null);
        await settle();

        expect(drawer()).toBeNull();
        expect(document.activeElement).toBe(button);
        expect(button.getAttribute('aria-expanded')).toBe('false');
    });

    it('gives the focus to the next page\'s button where a choice of another screen replaces the page under the drawer', async () => {
        open();

        press(drawer()?.querySelector('a[href="/admin/bookings"]') ?? null);
        render('/admin/bookings', <PageHeader title="The next page" />);
        await settle();

        expect(drawer()).toBeNull();
        expect(host.querySelector('main h1')?.textContent).toBe('The next page');
        expect(document.activeElement).toBe(control());
        expect(control()?.getAttribute('aria-expanded')).toBe('false');
    });

    it('leaves the focus where it is on the next page where the drawer was closed some other way', async () => {
        open();

        key(document.body, 'Escape');
        await settle();
        document.body.focus();
        render('/admin/bookings', <PageHeader title="The next page" />);
        await settle();

        expect(document.activeElement).not.toBe(control());
    });

    it.each([
        ['Ctrl', { ctrlKey: true }],
        ['Cmd', { metaKey: true }],
        ['Shift', { shiftKey: true }],
    ])('leaves the focus where it is on the next page where the choice was the browser\'s, opened elsewhere with %s held', async (_key, held) => {
        const button = open();

        press(drawer()?.querySelector('a[href="/admin/bookings"]') ?? null, held);
        await settle();

        expect(drawer()).toBeNull();
        expect(document.activeElement).toBe(button);

        render('/admin/services', <PageHeader title="The next page" />);
        await settle();

        expect(host.querySelector('main h1')?.textContent).toBe('The next page');
        expect(document.activeElement).not.toBe(control());
    });

    it('closes on Escape, focus going back to the button', async () => {
        const button = open();

        key(document.body, 'Escape');
        await settle();

        expect(drawer()).toBeNull();
        expect(document.activeElement).toBe(button);
    });

    it('closes on a press both down and up on the backdrop, focus going back to the button', async () => {
        const button = open();
        const backdrop = document.querySelector('.admin-modal__backdrop');

        drag(backdrop, backdrop);
        await settle();

        expect(drawer()).toBeNull();
        expect(document.activeElement).toBe(button);
    });
});

describe('the shell source', () => {
    it('reads neither the role nor the permissions: the menu is rendered from the nav prop alone', () => {
        const directory = join(process.cwd(), 'resources/js/admin/shell');
        const sources = readdirSync(directory).filter((file) => file.endsWith('.tsx') && !file.endsWith('.test.tsx'));

        expect(sources.length).toBeGreaterThan(0);

        for (const file of sources) {
            // The comments say what the code must not read; the code is what is audited.
            const text = readFileSync(join(directory, file), 'utf8')
                .replace(/\/\*[\s\S]*?\*\//g, '')
                .replace(/\/\/.*$/gm, '');

            // role= is an ARIA attribute; what is refused is the user's role read as a value.
            expect(text, file).not.toMatch(/\bpermissions\b|\bentitlements\b|\.role\b|[{,]\s*role\s*[,}:]/);
        }
    });
});
