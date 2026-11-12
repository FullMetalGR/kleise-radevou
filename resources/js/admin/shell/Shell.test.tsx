import { readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { act, type MouseEvent, type ReactNode, useState } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { drag, key, onPhone, press, settle } from '@/components/ui/harness';
import { KEYED_SHELL, keyedShell, WithShell } from '@/testing/keyed';
import type { NavEntry } from '../types';
import { RAIL_LABEL } from './Rail';
import { BOUNDARY_NOTICE, BOUNDARY_REST } from './RouteBoundary';
import { MENU_LABEL, Shell } from './Shell';

/*
 * The shell and the boundary each page renders inside. Below the admin's breakpoint the rail
 * leaves its aside for a drawer on the modal primitive, opened by a control first in the top
 * bar: the same nav list with the same current entry, closing on a choice, on Escape and on the
 * backdrop, focus going back to the control. At or above it the shell is what it was: the rail
 * in its aside and no control. The framework's link is stood in for by an anchor that makes no
 * visit, as the real one makes its own. The shell renders inside the keyed table, its own words
 * read here through it, and the rail's labels are the server's, handed as the keyed table
 * spells them.
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
    { label: N.calendar, url: '/admin', group: N.groups.schedule, pending: null, current: true },
    { label: N.bookings, url: '/admin/bookings', group: N.groups.schedule, pending: null, current: false },
];

function Broken(): never {
    throw new TypeError('the grid had no rows');
}

function render(route: string, page: React.ReactNode, onToggleTheme = () => {}): void {
    act(() =>
        root.render(
            <WithShell>
                <Shell
                    route={route}
                    nav={NAV}
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
    it('renders the rail, the top bar and the page', () => {
        render('/admin', <h1>The page</h1>);

        expect(host.querySelector('nav')?.textContent).toContain(N.bookings);
        expect(host.querySelector('header')?.textContent).toContain('Luna Hair Studio');
        expect(host.querySelector('main h1')?.textContent).toBe('The page');
    });

    it('shows the boundary notice with the error\'s name, message and component stack, keeps the rail and the top bar working, and re-reports to the console', () => {
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

        render('/admin/bookings', <h1>Another page</h1>);
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
                    <Shell route="/admin" nav={NAV} business="Luna" userName="Alex" theme="light" onToggleTheme={() => {}} signingOut onSignOut={() => {}}>
                        <h1>The page</h1>
                    </Shell>
                </WithShell>,
            ),
        );

        const button = host.querySelector<HTMLButtonElement>('.admin-signout');

        expect(button?.disabled).toBe(true);
        expect(button?.textContent).toBe(shell.text('top_bar.signing_out'));
    });
});

describe('at or above the breakpoint', () => {
    it('keeps the rail in its aside beside the page and puts no control in the top bar', () => {
        render('/admin', <h1>The page</h1>);

        expect(host.querySelector(`aside.admin-aside ${RAIL}`)).not.toBeNull();
        expect(host.querySelector('.admin-menu')).toBeNull();
        expect(host.querySelector('header')?.firstElementChild?.classList.contains('admin-business')).toBe(true);
        expect(document.querySelectorAll('[role="dialog"]')).toHaveLength(0);
    });
});

describe('below the breakpoint', () => {
    let undo: () => void;

    beforeEach(() => {
        undo = onPhone();
    });

    afterEach(() => undo());

    const control = () => host.querySelector<HTMLButtonElement>('header .admin-menu');
    const drawer = () => document.querySelector('[role="dialog"]');

    function open(): HTMLButtonElement {
        render('/admin', <h1>The page</h1>);

        const button = control();

        if (button === null) {
            throw new Error('No control.');
        }

        button.focus();
        press(button);

        return button;
    }

    it('hides the rail and puts its control first in the top bar, the page still rendered', () => {
        render('/admin', <h1>The page</h1>);

        expect(host.querySelector('aside')).toBeNull();
        expect(host.querySelector('nav')).toBeNull();
        expect(host.querySelector('header')?.firstElementChild).toBe(control());
        expect(control()?.textContent).toBe(MENU);
        expect(control()?.getAttribute('aria-haspopup')).toBe('dialog');
        expect(control()?.getAttribute('aria-expanded')).toBe('false');
        expect(host.querySelector('header')?.textContent).toContain('Luna Hair Studio');
        expect(host.querySelector('main h1')?.textContent).toBe('The page');
    });

    it('carries the pending count the rail shows on the control, so a request waiting is seen with the drawer closed, and none for none', () => {
        const pending: NavEntry[] = [
            { label: N.calendar, url: '/admin', group: N.groups.schedule, pending: null, current: true },
            { label: N.bookings, url: '/admin/bookings', group: N.groups.schedule, pending: 3, current: false },
        ];
        act(() =>
            root.render(
                <WithShell>
                    <Shell route="/admin" nav={pending} business="Luna Hair Studio" userName="Owner" theme="light" onToggleTheme={() => {}} signingOut={false}>
                        <h1>The page</h1>
                    </Shell>
                </WithShell>,
            ),
        );

        expect(control()?.querySelector('.admin-menu-pending')?.textContent).toBe('3');
        expect(control()?.textContent).toBe(`${MENU}3`);

        press(control());

        expect(drawer()?.querySelector('a[href="/admin/bookings"] .admin-rail-pending')?.textContent).toBe('3');

        render('/admin', <h1>The page</h1>);

        expect(control()?.querySelector('.admin-menu-pending')).toBeNull();
        expect(control()?.textContent).toBe(MENU);
    });

    it('opens the rail as a drawer on the modal primitive, the same list with the same current entry', () => {
        open();

        expect(drawer()?.classList.contains('admin-modal__frame--drawer')).toBe(true);
        expect(drawer()?.querySelector('.admin-modal__title')?.textContent).toBe(MENU);
        expect([...(drawer()?.querySelectorAll(`${RAIL} a`) ?? [])].map((link) => link.getAttribute('href'))).toEqual(['/admin', '/admin/bookings']);
        expect(drawer()?.querySelector('[aria-current="page"]')?.getAttribute('href')).toBe('/admin');
        expect(control()?.getAttribute('aria-expanded')).toBe('true');
    });

    it('closes on a choice, focus going back to the control', async () => {
        const button = open();

        press(drawer()?.querySelector('a[href="/admin/bookings"]') ?? null);
        await settle();

        expect(drawer()).toBeNull();
        expect(document.activeElement).toBe(button);
        expect(button.getAttribute('aria-expanded')).toBe('false');
    });

    it('closes on Escape, focus going back to the control', async () => {
        const button = open();

        key(document.body, 'Escape');
        await settle();

        expect(drawer()).toBeNull();
        expect(document.activeElement).toBe(button);
    });

    it('closes on a press both down and up on the backdrop, focus going back to the control', async () => {
        const button = open();
        const backdrop = document.querySelector('.admin-modal__backdrop');

        drag(backdrop, backdrop);
        await settle();

        expect(drawer()).toBeNull();
        expect(document.activeElement).toBe(button);
    });
});

describe('the shell source', () => {
    it('reads neither the role nor the permissions: the rail is rendered from the nav prop alone', () => {
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
