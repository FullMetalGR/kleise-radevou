import { act } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { mount, press, type Mounted } from '@/components/ui/harness';
import { keyedShell } from '@/testing/keyed';
import Tickets, { AWAITING_REPLY, NO_TICKETS, STATUS_WORDS, threadPath, type TicketSummary, TICKETS_LIST_ERROR } from './Tickets';

/*
 * The console's help tickets: each row as the server sent it, the subject a link to its thread,
 * the business a link to its console page, the newest message's instant, the status in words and
 * the Awaiting reply marker on an open ticket the business wrote last; the page as a partial reload
 * of the list, the query carrying it, nothing optimistic. The framework is stood in for by a router
 * that records each visit and the failure listeners registered around it.
 */

const shell = keyedShell();

type Visit = { only?: string[]; replace?: boolean; onStart?: () => void; onFinish?: () => void };

const inertia = vi.hoisted(() => ({
    props: {} as Record<string, unknown>,
    get: vi.fn(),
    listeners: new Map<string, (event: Event) => void>(),
}));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, className, children }: { href: string; className?: string; children?: unknown }) => (
        <a href={href} className={className}>
            {children as string}
        </a>
    ),
    usePage: () => ({ props: inertia.props }),
    router: {
        get: inertia.get,
        on: (name: string, listener: (event: Event) => void) => {
            inertia.listeners.set(name, listener);

            return () => inertia.listeners.delete(name);
        },
    },
}));

const WAITING: TicketSummary = {
    id: 12,
    subject: 'Moving a booking',
    tenant: { name: 'Luna Hair Studio', url: '/tenants/luna' },
    status: 'open',
    awaiting: true,
    last_message: 'Monday 16 November 2026 at 10:00',
};

const CLOSED: TicketSummary = {
    id: 9,
    subject: 'An old question',
    tenant: { name: 'Gone Salon', url: '/tenants/closed/4' },
    status: 'closed',
    awaiting: false,
    last_message: 'Sunday 15 November 2026 at 11:00',
};

function propsWith(rows: TicketSummary[], patch: { total?: number; page?: number } = {}): Record<string, unknown> {
    return {
        flash: null,
        operator: 'Pat Operator',
        errors: {},
        nav: [],
        zone: 'Europe/Athens',
        tickets: { rows, total: patch.total ?? rows.length, page: patch.page ?? 1, page_size: 25 },
    };
}

let view: Mounted;

function show(rows: TicketSummary[], patch: { total?: number; page?: number } = {}): void {
    inertia.props = propsWith(rows, patch);
    view.render(<Tickets />);
}

function q(selector: string): Element | null {
    return view.host.querySelector(selector);
}

function all(selector: string): Element[] {
    return [...view.host.querySelectorAll(selector)];
}

function texts(selector: string): (string | null)[] {
    return all(selector).map((element) => element.textContent);
}

function lastRead(): [string, Record<string, string>, Visit] {
    const call = inertia.get.mock.lastCall;

    if (call === undefined) {
        throw new Error('No read was made.');
    }

    return call as [string, Record<string, string>, Visit];
}

beforeEach(() => {
    inertia.get.mockReset();
    inertia.listeners.clear();
    view = mount();
});

afterEach(() => view.unmount());

describe('the tickets screen', () => {
    it('renders each ticket as the server sent it: the subject to its thread, the business to its page, the instant and the status, N of M in the head', () => {
        show([WAITING, CLOSED], { total: 30 });

        expect(q('h1')?.textContent).toBe('Tickets');
        expect(all('.admin-tickets__subject').map((link) => [link.textContent, link.getAttribute('href')])).toEqual([
            ['Moving a booking', '/tickets/12'],
            ['An old question', '/tickets/9'],
        ]);
        expect(all('.admin-tickets__tenant').map((link) => [link.textContent, link.getAttribute('href')])).toEqual([
            ['Luna Hair Studio', '/tenants/luna'],
            ['Gone Salon', '/tenants/closed/4'],
        ]);
        expect(texts('.admin-tickets__last')).toEqual([WAITING.last_message, CLOSED.last_message]);
        expect(texts('.admin-tickets__status')).toEqual(['Open', 'Closed']);
        expect(q('.admin-tickets__count')?.textContent).toBe('2 of 30');
        expect(q('.admin-pagination')?.textContent).toContain(shell.text('kit.pagination.page', { page: '1', last: '2' }));
    });

    it('marks the open ticket the business wrote last as awaiting a reply, and no other', () => {
        show([WAITING, CLOSED, { ...WAITING, id: 13, awaiting: false }]);

        expect(texts('.admin-tickets__awaiting')).toEqual([AWAITING_REPLY]);
        expect(all('.admin-tickets__row').map((row) => row.getAttribute('data-awaiting'))).toEqual(['true', 'false', 'false']);
        expect(q('.admin-tickets__row .admin-tickets__awaiting')?.closest('tr')?.querySelector('.admin-tickets__subject')?.textContent).toBe('Moving a booking');
    });

    it('words both statuses and builds a thread\'s address from its id', () => {
        expect(STATUS_WORDS).toEqual({ open: 'Open', closed: 'Closed' });
        expect(threadPath(41)).toBe('/tickets/41');
    });

    it('reads the next page as a partial reload of the list, and the first with the default left out', () => {
        show([WAITING], { total: 60 });

        press(q('.admin-pagination__next'));

        const [path, query, visit] = lastRead();

        expect(path).toBe('/tickets');
        expect(query).toEqual({ page: '2' });
        expect(visit.only).toEqual(['tickets']);
        expect(visit.replace).toBe(true);

        show([WAITING], { total: 60, page: 2 });
        press(q('.admin-pagination__previous'));

        expect(lastRead()[1]).toEqual({});
    });

    it('shows the empty panel once the list has settled empty', () => {
        show([]);

        expect(q('.admin-tickets-empty')?.textContent).toBe(NO_TICKETS);
        expect(q('.admin-tickets')).toBeNull();
    });

    it('says a failed read in the screen\'s own words above the tickets it keeps, and shows no empty panel', () => {
        show([WAITING], { total: 60 });

        press(q('.admin-pagination__next'));

        const [, , visit] = lastRead();

        act(() => visit.onStart?.());
        act(() => inertia.listeners.get('invalid')?.(new Event('invalid', { cancelable: true })));
        act(() => visit.onFinish?.());

        expect(q('.admin-list-error')?.textContent).toBe(TICKETS_LIST_ERROR);
        expect(texts('.admin-tickets__subject')).toEqual(['Moving a booking']);
        expect(q('.admin-tickets-empty')).toBeNull();
    });
});
