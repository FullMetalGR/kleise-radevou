import { act, type MouseEvent, type ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { mount, type Mounted, press } from '@/components/ui/harness';
import { KEYED_SHELL, keyedShell } from '@/testing/keyed';
import type { NavEntry } from '../types';
import { BottomBar, barEntries } from './BottomBar';

/*
 * The bar along the foot of a narrow screen: the menu's first group, four entries at most, each an
 * icon over its label, then the Menu button that opens the drawer with the whole menu. A first
 * group of fewer than two entries gives no bar, as one destination and the button beside it is no
 * bar at all. The framework's link is stood in for by an anchor that makes no visit.
 */

const shell = keyedShell();
const N = KEYED_SHELL.nav;

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

function entry(key: string, group: string, extra: Partial<NavEntry> = {}): NavEntry {
    return { key, label: key, url: `/admin/${key}`, group, pending: null, current: false, ...extra };
}

/** An owner's menu as the server sends it: the schedule first, then the business. */
const OWNER: NavEntry[] = [
    entry('home', N.groups.schedule, { label: N.home, url: '/admin', current: true }),
    entry('calendar', N.groups.schedule, { label: N.calendar }),
    entry('bookings', N.groups.schedule, { label: N.bookings, pending: 3 }),
    entry('shifts', N.groups.schedule, { label: N.shifts }),
    entry('services', N.groups.business, { label: N.services }),
    entry('team', N.groups.business, { label: N.team }),
];

let mounted: Mounted;

beforeEach(() => {
    mounted = mount();
});

afterEach(() => {
    mounted.unmount();
});

const bar = () => mounted.host.querySelector('nav.admin-bottom-bar');

describe('barEntries', () => {
    it('takes the first group\'s entries in the server\'s order: Home, Calendar, Bookings and Shifts for an owner', () => {
        expect(barEntries(OWNER).map((found) => found.key)).toEqual(['home', 'calendar', 'bookings', 'shifts']);
    });

    it('takes four at most where the first group holds more', () => {
        const five = ['a', 'b', 'c', 'd', 'e'].map((key) => entry(key, 'Platform'));

        expect(barEntries(five).map((found) => found.key)).toEqual(['a', 'b', 'c', 'd']);
    });

    it('gives none where the first group holds fewer than two entries, whatever follows it', () => {
        expect(barEntries([entry('calendar', 'Schedule'), entry('services', 'Business'), entry('team', 'Business')])).toEqual([]);
        expect(barEntries([])).toEqual([]);
    });
});

describe('the bottom bar', () => {
    it('lists the first group as links, the current one marked, then the Menu button that opens the drawer', () => {
        const onMenu = vi.fn();

        mounted.render(<BottomBar nav={OWNER} open={false} onMenu={onMenu} />);

        expect(bar()?.getAttribute('aria-label')).toBe(shell.text('nav.main'));
        expect([...(bar()?.querySelectorAll('a') ?? [])].map((link) => link.getAttribute('href'))).toEqual(['/admin', '/admin/calendar', '/admin/bookings', '/admin/shifts']);
        expect(bar()?.querySelector('[aria-current="page"]')?.getAttribute('href')).toBe('/admin');
        expect(bar()?.querySelectorAll('[aria-current]')).toHaveLength(1);

        const menu = [...(bar()?.querySelectorAll('a, button') ?? [])].at(-1);

        expect(menu?.tagName).toBe('BUTTON');
        expect(menu?.textContent).toBe(shell.text('nav.menu'));
        expect(menu?.getAttribute('aria-haspopup')).toBe('dialog');
        expect(menu?.getAttribute('aria-expanded')).toBe('false');
        expect(menu?.classList.contains('admin-menu-button')).toBe(false);

        press(menu ?? null);

        expect(onMenu).toHaveBeenCalledTimes(1);
    });

    it('says the drawer is open on its Menu button while it is', () => {
        mounted.render(<BottomBar nav={OWNER} open onMenu={() => {}} />);

        expect(bar()?.querySelector('.admin-bottom-bar__menu')?.getAttribute('aria-expanded')).toBe('true');
    });

    it('shows each label small under its icon, and the count an entry carries named for what it counts', () => {
        mounted.render(<BottomBar nav={OWNER} open={false} onMenu={() => {}} />);

        const labels = [...(bar()?.querySelectorAll('.admin-bottom-bar__label') ?? [])];

        expect(labels.map((label) => label.textContent)).toEqual([N.home, N.calendar, N.bookings, N.shifts, shell.text('nav.menu')]);
        expect(labels.every((label) => label.classList.contains('text-[10.5px]'))).toBe(true);

        const badge = bar()?.querySelector('a[href="/admin/bookings"] [role="img"]');

        expect(badge?.getAttribute('aria-label')).toBe(shell.choice('nav.pending', 3));
        expect(badge?.textContent).toBe('3');
        expect(bar()?.querySelectorAll('[role="img"]')).toHaveLength(1);
    });

    it('renders nothing where the first group gives no entries', () => {
        mounted.render(<BottomBar nav={[entry('calendar', 'Schedule', { current: true })]} open={false} onMenu={() => {}} />);

        expect(bar()).toBeNull();
    });
});

describe('the bottom bar as the page scrolls', () => {
    /** The page's place, set by hand as the test's document has no layout: a tall page in a short window. */
    let y = 0;

    function scrollTo(next: number): void {
        y = next;
        act(() => {
            window.dispatchEvent(new Event('scroll'));
        });
    }

    const carries = (name: string) => bar()?.classList.contains(name);

    beforeEach(() => {
        y = 0;
        Object.defineProperty(window, 'scrollY', { configurable: true, get: () => y });
        Object.defineProperty(window, 'innerHeight', { configurable: true, get: () => 800 });
        Object.defineProperty(document.documentElement, 'scrollHeight', { configurable: true, get: () => 3000 });
    });

    afterEach(() => {
        Reflect.deleteProperty(window, 'scrollY');
        Reflect.deleteProperty(window, 'innerHeight');
        Reflect.deleteProperty(document.documentElement, 'scrollHeight');
    });

    it('stands in place until the page scrolls down, then carries what moves it below the screen and what brings it back for the keyboard\'s focus alone, and stands again on a scroll up', () => {
        mounted.render(<BottomBar nav={OWNER} open={false} onMenu={() => {}} />);

        expect(carries('admin-bottom-bar--away')).toBe(false);
        expect(carries('translate-y-[150%]')).toBe(false);

        scrollTo(400);

        expect(carries('admin-bottom-bar--away')).toBe(true);
        expect(carries('translate-y-[150%]')).toBe(true);
        expect(carries('has-[:focus-visible]:translate-y-0')).toBe(true);
        // Nothing in it answers to any focus at all: that would hold the bar up after a press on
        // one of its entries, which keeps the focus the press gave it.
        expect([...(bar()?.classList ?? [])].filter((name) => name.startsWith('focus-within:'))).toEqual([]);

        scrollTo(390);

        expect(carries('admin-bottom-bar--away')).toBe(false);
        expect(carries('translate-y-[150%]')).toBe(false);
    });
});
