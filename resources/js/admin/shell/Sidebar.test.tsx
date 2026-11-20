import { act, type MouseEvent, type ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { mount, type Mounted, press } from '@/components/ui/harness';
import { KEYED_SHELL, keyedShell } from '@/testing/keyed';
import type { NavEntry } from '../types';
import { COLLAPSED_KEY, readCollapsed, writeCollapsed } from './collapsed';
import { Sidebar } from './Sidebar';

/*
 * The sidebar the shell stands beside the page from the wide breakpoint: the brand at its head, the
 * business's chip, the menu in the server's order under its group labels, and the button at its
 * foot that folds it to a strip of icons and back. The choice is remembered on the device, and
 * where the browser refuses to store it the sidebar starts open and the button still works. The
 * framework's link is stood in for by an anchor that makes no visit.
 */

const N = KEYED_SHELL.nav;

const shell = keyedShell();

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

let view: Mounted;

beforeEach(() => {
    view = mount();
});

afterEach(() => {
    view.unmount();
    vi.restoreAllMocks();
    window.localStorage.clear();
});

const NAV: NavEntry[] = [
    { key: 'home', label: N.home, url: '/admin', group: N.groups.schedule, pending: null, current: false },
    { key: 'calendar', label: N.calendar, url: '/admin/calendar', group: N.groups.schedule, pending: null, current: true },
    { key: 'bookings', label: N.bookings, url: '/admin/bookings', group: N.groups.schedule, pending: 4, current: false },
    { key: 'services', label: N.services, url: '/admin/services', group: N.groups.business, pending: null, current: false },
];

function render(business: string | null = 'Luna Hair Studio'): void {
    view.render(<Sidebar nav={NAV} brand={(collapsed) => <span className="brand">{collapsed ? 'small' : 'whole'}</span>} business={business} />);
}

const sidebar = () => view.host.querySelector('aside.admin-sidebar');
const toggle = () => view.host.querySelector('.admin-sidebar-toggle');
const labels = () => [...view.host.querySelectorAll('.admin-rail-entry__label')];
const groupLabels = () => [...view.host.querySelectorAll('.admin-rail-group')];

function focus(element: Element | null): void {
    if (!(element instanceof HTMLElement)) {
        throw new Error('Nothing to focus.');
    }

    act(() => element.focus());
}

describe('the sidebar, open', () => {
    it('renders the brand whole at its head, then the business\'s chip, then the menu in the server\'s order under its group labels', () => {
        render();

        expect(sidebar()?.querySelector('.brand')?.textContent).toBe('whole');
        expect(groupLabels().map((label) => label.textContent)).toEqual([N.groups.schedule, N.groups.business]);
        expect(labels().map((label) => label.textContent)).toEqual([N.home, N.calendar, N.bookings, N.services]);
        expect([...view.host.querySelectorAll('.admin-rail-entry')].map((entry) => entry.getAttribute('href'))).toEqual(['/admin', '/admin/calendar', '/admin/bookings', '/admin/services']);

        const parts = [sidebar()?.querySelector('.brand'), sidebar()?.querySelector('.admin-business'), sidebar()?.querySelector('nav.admin-rail')];
        expect(parts.every((part) => part !== null && part !== undefined)).toBe(true);
        expect(parts[0]?.compareDocumentPosition(parts[1] as Node)).toBe(Node.DOCUMENT_POSITION_FOLLOWING);
        expect(parts[1]?.compareDocumentPosition(parts[2] as Node)).toBe(Node.DOCUMENT_POSITION_FOLLOWING);
    });

    it('marks the current entry for a screen reader and fills its icon\'s circle with the theme colour, the others left plain', () => {
        render();

        const entries = [...view.host.querySelectorAll('.admin-rail-entry')];
        const current = entries.filter((entry) => entry.getAttribute('aria-current') === 'page');
        const circles = entries.map((entry) => entry.querySelector('.admin-rail-entry__icon'));

        expect(current.map((entry) => entry.getAttribute('href'))).toEqual(['/admin/calendar']);
        expect(circles.map((circle) => circle?.classList.contains('bg-primary') && circle.classList.contains('text-primary-foreground'))).toEqual([false, true, false, false]);
    });

    it('shows the Bookings count as a badge whose accessible name says what it counts', () => {
        render();

        const badges = [...view.host.querySelectorAll('.admin-rail-pending')];

        expect(badges.map((badge) => badge.textContent)).toEqual(['4']);
        expect(badges[0]?.getAttribute('role')).toBe('img');
        expect(badges[0]?.getAttribute('aria-label')).toBe(shell.choice('nav.pending', 4));
        expect(badges[0]?.closest('a')?.getAttribute('href')).toBe('/admin/bookings');
    });

    it('carries the business\'s name in its chip, cut where it runs long and whole in its title, and no chip where there is no business', () => {
        render('A business whose name runs on well past the room the chip has');

        const chip = view.host.querySelector('.admin-business');
        const name = chip?.querySelector('.admin-business__name');

        expect(name?.textContent).toBe('A business whose name runs on well past the room the chip has');
        expect(name?.classList.contains('truncate')).toBe(true);
        expect(chip?.getAttribute('title')).toBe('A business whose name runs on well past the room the chip has');

        view.render(<Sidebar nav={NAV} brand={() => null} business={null} />);

        expect(view.host.querySelector('.admin-business')).toBeNull();
        expect(view.host.querySelectorAll('.admin-rail-entry')).toHaveLength(4);
    });

    it('keeps the aside\'s older hook class beside its own, so a screen that must hold no aside can still say so', () => {
        render();

        expect(sidebar()?.classList.contains('admin-aside')).toBe(true);
    });

    it('ends with a button that collapses it, open as it says', () => {
        render();

        const button = toggle();

        expect(button?.tagName.toLowerCase()).toBe('button');
        expect(button?.getAttribute('type')).toBe('button');
        expect(button?.getAttribute('aria-expanded')).toBe('true');
        expect(button?.textContent).toBe(shell.text('nav.collapse'));
        expect(sidebar()?.lastElementChild?.lastElementChild?.contains(button as Node)).toBe(true);
    });
});

describe('the sidebar, collapsed', () => {
    it('folds on a press: the labels kept for a screen reader alone, the group labels turned to rules, the brand small, the choice written', () => {
        render();

        press(toggle());

        expect(toggle()?.getAttribute('aria-expanded')).toBe('false');
        expect(toggle()?.textContent).toBe(shell.text('nav.expand'));
        expect(labels().map((label) => label.classList.contains('sr-only'))).toEqual([true, true, true, true]);
        expect(labels().map((label) => label.textContent)).toEqual([N.home, N.calendar, N.bookings, N.services]);
        expect(groupLabels().map((label) => label.classList.contains('sr-only'))).toEqual([true, true]);
        expect(groupLabels()[1]?.parentElement?.classList.contains('border-t')).toBe(true);
        expect(sidebar()?.querySelector('.brand')?.textContent).toBe('small');
        expect(view.host.querySelector('.admin-business__name')?.classList.contains('sr-only')).toBe(true);
        expect(readCollapsed(() => window.localStorage)).toBe(true);
    });

    it('shows each entry\'s label in a tooltip on keyboard focus, and the count at the icon\'s corner', () => {
        render();
        press(toggle());

        focus(view.host.querySelector('a[href="/admin/services"]'));
        expect(document.querySelector('.admin-tooltip')?.textContent).toContain(N.services);

        focus(view.host.querySelector('a[href="/admin/bookings"]'));
        expect(document.querySelector('.admin-tooltip')?.textContent).toContain(N.bookings);

        const badge = view.host.querySelector('.admin-rail-pending');
        expect(badge?.textContent).toBe('4');
        expect(badge?.classList.contains('absolute')).toBe(true);
        expect(badge?.getAttribute('aria-label')).toBe(shell.choice('nav.pending', 4));
    });

    it('keeps the focus on its button through a fold and an opening from the keyboard, the button the same element in both states', () => {
        render();

        const button = toggle();
        focus(button);
        press(button);

        expect(document.activeElement).toBe(toggle());
        expect(toggle()).toBe(button);
        expect(toggle()?.getAttribute('aria-expanded')).toBe('false');

        press(toggle());

        expect(document.activeElement).toBe(toggle());
        expect(toggle()).toBe(button);
        expect(toggle()?.getAttribute('aria-expanded')).toBe('true');
    });

    it('keeps every entry the same element through a fold and an opening, so one that holds the focus keeps it', () => {
        render();

        const entries = [...view.host.querySelectorAll('.admin-rail-entry')];
        focus(entries[3] ?? null);

        const same = () => [...view.host.querySelectorAll('.admin-rail-entry')].map((entry, index) => entry === entries[index]);

        press(toggle());

        expect(same()).toEqual([true, true, true, true]);
        expect(document.activeElement).toBe(entries[3]);

        press(toggle());

        expect(same()).toEqual([true, true, true, true]);
        expect(document.activeElement).toBe(entries[3]);
    });

    it('shows no tooltip while it is open, an entry and the button both reached by the keyboard', () => {
        render();

        focus(view.host.querySelector('a[href="/admin/services"]'));
        expect(document.querySelector('.admin-tooltip')).toBeNull();

        focus(toggle());
        expect(document.querySelector('.admin-tooltip')).toBeNull();
    });

    it('opens again on the next press, and writes that too', () => {
        render();

        press(toggle());
        press(toggle());

        expect(toggle()?.getAttribute('aria-expanded')).toBe('true');
        expect(labels().some((label) => label.classList.contains('sr-only'))).toBe(false);
        expect(readCollapsed(() => window.localStorage)).toBe(false);
    });

    it('starts collapsed where the device remembers it so', () => {
        writeCollapsed(true, () => window.localStorage);

        render();

        expect(window.localStorage.getItem(COLLAPSED_KEY)).not.toBeNull();
        expect(toggle()?.getAttribute('aria-expanded')).toBe('false');
        expect(sidebar()?.querySelector('.brand')?.textContent).toBe('small');
    });

    it('starts open where the browser refuses the storage, and still folds and opens for the visit', () => {
        vi.spyOn(window, 'localStorage', 'get').mockImplementation(() => {
            throw new DOMException('The operation is insecure.', 'SecurityError');
        });

        render();
        expect(toggle()?.getAttribute('aria-expanded')).toBe('true');

        press(toggle());
        expect(toggle()?.getAttribute('aria-expanded')).toBe('false');

        press(toggle());
        expect(toggle()?.getAttribute('aria-expanded')).toBe('true');
    });
});
