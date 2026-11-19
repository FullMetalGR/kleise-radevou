import { act, type MouseEvent, type ReactNode } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { press } from '@/components/ui/harness';
import { KEYED_SHELL, keyedShell, WithShell } from '@/testing/keyed';
import type { NavEntry } from '../types';
import { RAIL_LABEL, Rail } from './Rail';

/*
 * The rail, rendered from the nav prop and nothing else. In the drawer a press on an entry is also
 * handed to whoever opened it, so the drawer closes on a choice. The framework's link is stood in
 * for by an anchor that makes no visit, as the real one makes its own. The labels and groups are
 * the server's, handed here as the keyed table spells them; the rail's own name is the shell's,
 * read through the same table. Each entry carries the one word the server names it by, which
 * chooses its icon and keys its row.
 */

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

function entry(key: string, label: string, url: string, group: string, current = false, pending: number | null = null): NavEntry {
    return { key, label, url, group, pending, current };
}

function render(nav: NavEntry[], onChoose?: () => void): void {
    act(() =>
        root.render(
            <WithShell>
                <Rail nav={nav} onChoose={onChoose} />
            </WithShell>,
        ),
    );
}

const labels = () => [...host.querySelectorAll('a')].map((link) => link.querySelector('span')?.textContent);

describe('the rail', () => {
    it('renders group headings when more than one group exists', () => {
        render([
            entry('calendar', N.calendar, '/admin', N.groups.schedule, true),
            entry('bookings', N.bookings, '/admin/bookings', N.groups.schedule, false, 3),
            entry('services', N.services, '/admin/services', 'Setup'),
        ]);

        expect([...host.querySelectorAll('h2')].map((heading) => heading.textContent)).toEqual([N.groups.schedule, 'Setup']);
        expect(labels()).toEqual([N.calendar, N.bookings, N.services]);
    });

    it('renders no heading when there is one group', () => {
        render([entry('calendar', N.calendar, '/admin', N.groups.schedule, true), entry('bookings', N.bookings, '/admin/bookings', N.groups.schedule)]);

        expect(host.querySelectorAll('h2')).toHaveLength(0);
        expect(labels()).toEqual([N.calendar, N.bookings]);
        expect(host.querySelector('nav')?.getAttribute('aria-label')).toBe(keyedShell().text(RAIL_LABEL));
    });

    it('marks exactly the entry the server marked current with aria-current="page"', () => {
        render([entry('calendar', N.calendar, '/admin', N.groups.schedule), entry('bookings', N.bookings, '/admin/bookings', N.groups.schedule, true)]);

        const current = host.querySelectorAll('[aria-current="page"]');

        expect(current).toHaveLength(1);
        expect(current[0]?.getAttribute('href')).toBe('/admin/bookings');
    });

    it('renders a staff user\'s rail as the Calendar alone, with no heading', () => {
        render([entry('calendar', N.calendar, '/admin', N.groups.schedule, true)]);

        expect(labels()).toEqual([N.calendar]);
        expect(host.querySelectorAll('h2')).toHaveLength(0);
        expect(host.querySelectorAll('[aria-current="page"]')).toHaveLength(1);
    });

    it('shows a pending count where the entry carries one and none where it is null', () => {
        render([entry('calendar', N.calendar, '/admin', N.groups.schedule, true), entry('bookings', N.bookings, '/admin/bookings', N.groups.schedule, false, 4)]);

        expect([...host.querySelectorAll('.admin-rail-pending')].map((badge) => badge.textContent)).toEqual(['4']);
    });

    it('renders every entry it is handed, adding no gate of its own', () => {
        render([entry('billing', N.billing, '/admin/billing', 'Account', true)]);

        expect(labels()).toEqual([N.billing]);
    });

    it('hands a press on any entry to onChoose, the current one included, once each', () => {
        const chose = vi.fn();

        render([entry('calendar', N.calendar, '/admin', N.groups.schedule, true), entry('bookings', N.bookings, '/admin/bookings', N.groups.schedule)], chose);

        press(host.querySelector('a[href="/admin/bookings"]'));
        expect(chose).toHaveBeenCalledOnce();

        press(host.querySelector('a[aria-current="page"]'));
        expect(chose).toHaveBeenCalledTimes(2);
    });

    it('needs no onChoose where the rail stands in its aside', () => {
        render([entry('calendar', N.calendar, '/admin', N.groups.schedule, true)]);

        expect(() => press(host.querySelector('a'))).not.toThrow();
    });

    it('draws each entry\'s icon before its label, hidden from assistive technology, a plain circle for a word it does not know', () => {
        render([
            entry('calendar', N.calendar, '/admin', N.groups.schedule, true),
            entry('bookings', N.bookings, '/admin/bookings', N.groups.schedule, false, 2),
            entry('later', 'Later', '/admin/later', N.groups.schedule),
        ]);

        const links = [...host.querySelectorAll('a')];
        const icons = links.map((link) => link.firstElementChild);

        expect(icons.map((icon) => icon?.classList.contains('admin-rail-entry__icon'))).toEqual([true, true, true]);
        expect(icons.map((icon) => icon?.tagName.toLowerCase())).toEqual(['svg', 'svg', 'svg']);
        expect([icons[0]?.classList.contains('lucide-calendar'), icons[1]?.classList.contains('lucide-list'), icons[2]?.classList.contains('lucide-circle')]).toEqual([true, true, true]);
        expect(icons.map((icon) => icon?.getAttribute('aria-hidden'))).toEqual(['true', 'true', 'true']);
        expect(icons.map((icon) => icon?.nextElementSibling?.textContent)).toEqual([N.calendar, N.bookings, 'Later']);
        expect(links.map((link) => link.textContent)).toEqual([N.calendar, `${N.bookings}2`, 'Later']);
    });

    it('keys each row by the entry\'s word, so two entries at one address stand side by side', () => {
        const report = vi.spyOn(console, 'error').mockImplementation(() => {});

        render([entry('staff', N.staff, '/admin/staff', N.groups.business), entry('closures', N.closures, '/admin/staff', N.groups.business)]);

        expect(labels()).toEqual([N.staff, N.closures]);
        expect(report).not.toHaveBeenCalled();
        report.mockRestore();
    });
});
