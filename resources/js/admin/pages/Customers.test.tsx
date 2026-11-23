import { act } from 'react';
import { afterEach, beforeEach, describe, expect, expectTypeOf, it, vi } from 'vitest';
import { mount, press, type Mounted } from '@/components/ui/harness';
import { type Customer, NO_VISIT } from '@/customers/model';
import { KEYED_CUSTOMERS, keyedCustomers } from '@/testing/keyed';
import { ToastProvider } from '@/toast/toasts';
import type { CustomersStrings } from '@/types';
import { ADMIN_LINES_EN } from '../lines.generated';
import Customers, { LIST_ERROR, NO_CUSTOMERS, NO_CUSTOMERS_SENTENCE, NO_MATCH, OPEN_ERROR, SEARCH_HINT_CONTACT, SEARCH_HINT_NAME } from './Customers';

/*
 * The Customers screen, under the settledness gate: the rows with a row select button each once
 * there are rows; nothing at all while a search with no rows on screen is in flight or has
 * failed, the failure in the screen's own words; and the empty panel only once the list has
 * settled empty. The framework is stood in for by a router that records each visit and the
 * failure listeners registered around it. Every word is read through the keyed customers section,
 * never a sentence.
 */

const words = keyedCustomers();

/** A table's shape: every line a string, every group its own shape, nothing else. */
type Shape<T> = { -readonly [K in keyof T]: T[K] extends string ? string : Shape<T[K]> };

/**
 * The list's column heads as the table words them, the contact's two where shown: the first and
 * the last visit apart, then the one head that stands for both where they share a column, the two
 * read one above the other.
 */
function heads(contact: boolean): string[] {
    const leading = (contact ? (['table.name', 'table.email', 'table.phone'] as const) : (['table.name'] as const)).map((key) => words.text(key));

    return [...leading, words.text('table.first_visit'), words.text('table.last_visit'), `${words.text('table.first_visit')}${words.text('table.last_visit')}`, words.text('table.bookings')];
}

/** The utilities an element of the screen takes under one condition, the condition taken off, sorted. */
function under(selector: string, condition: string): string[] {
    return [...(q(selector)?.classList ?? [])]
        .filter((name) => name.startsWith(condition))
        .map((name) => name.slice(condition.length))
        .sort();
}

/**
 * The card condition under which a row turns into a block: a card narrower than 944px, the same
 * card in which the two visits share one column, so the shared column is drawn in the block rows
 * alone and never squeezed into the grid.
 */
const BLOCK_CARD = '@max-[59rem]:';

/** The card condition under which the two visits share one column: a card narrower than 944px. */
const SHARED_CARD = '@max-[59rem]:';

type Visit = { only?: string[]; replace?: boolean; preserveState?: boolean; onStart?: () => void; onSuccess?: () => void; onFinish?: () => void };

const inertia = vi.hoisted(() => ({
    props: {} as Record<string, unknown>,
    get: vi.fn(),
    listeners: new Map<string, (event: Event) => void>(),
}));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    usePage: () => ({ props: inertia.props }),
    router: {
        get: inertia.get,
        on: (name: string, listener: (event: Event) => void) => {
            inertia.listeners.set(name, listener);

            return () => inertia.listeners.delete(name);
        },
    },
}));

const CASEY: Customer = {
    name: 'Casey Customer',
    names: ['Casey Customer', 'Casey C.'],
    email: 'casey@example.com',
    phones: ['+30 210 2222222', '+30 210 1111111'],
    first_visit: '2026-11-03T08:00:00+00:00',
    last_visit: '2026-11-20T08:00:00+00:00',
    bookings: 3,
    upcoming: 0,
    completed: 1,
    no_shows: 1,
    cancelled: 1,
    url: '/admin/customers/casey%40example.com',
    tags: ['VIP', 'colour'],
    blocked: false,
};

const ADA: Customer = {
    name: 'Ada North',
    names: ['Ada North'],
    email: 'ada@example.test',
    phones: [],
    first_visit: '2026-12-01T08:00:00+00:00',
    last_visit: null,
    bookings: 1,
    upcoming: 1,
    completed: 0,
    no_shows: 0,
    cancelled: 0,
    url: '/admin/customers/ada%40example.test',
    tags: [],
    blocked: true,
};

/** Renders the screen under the toasts its download reports through, as the shell does. */
function show(): void {
    view.render(
        <ToastProvider>
            <Customers />
        </ToastProvider>,
    );
}

function propsWith(rows: Customer[], patch: { search?: string; page?: number; total?: number; searchable?: string[] } = {}): Record<string, unknown> {
    return {
        tenant: { name: 'Luna', slug: 'luna', timezone: 'Europe/Athens', currency: 'EUR' },
        auth: { user: { id: 1, name: 'Alex Owner', role: 'owner' } },
        flash: null,
        errors: {},
        permissions: ['viewBookings', 'manageBookings'],
        entitlements: [],
        subscription: { state: 'active' },
        settings: { granularity: 15, week_start: 1, date_format: 'd/m/Y', time_format: 'H:i', messages: [] },
        nav: [],
        filters: { search: patch.search ?? '', page: patch.page ?? 1 },
        searchable: patch.searchable ?? ['customer_name', 'customer_email'],
        customers: { rows, total: patch.total ?? rows.length, page: 1, page_size: 25 },
        strings: { shell: {}, customers: KEYED_CUSTOMERS },
    };
}

function q(selector: string): Element | null {
    return view.host.querySelector(selector);
}

/** What the list's one card holds, top to bottom, each part named by what it is. */
function cardHolds(): string[] {
    const parts: [string, (child: Element) => boolean][] = [
        ['bar', (child) => child.classList.contains('admin-customers-bar')],
        ['error', (child) => child.classList.contains('admin-list-error')],
        ['empty', (child) => child.classList.contains('admin-customers-empty')],
        ['table', (child) => child.tagName === 'TABLE'],
        ['pager', (child) => child.classList.contains('admin-pagination')],
    ];

    return [...(q('.admin-customers')?.children ?? [])].map((child) => parts.find(([, is]) => is(child))?.[0] ?? child.tagName);
}

/** Types into the search as a person does, through React's own value setter. */
function typeSearch(text: string): void {
    const input = q('#customers-search') as HTMLInputElement;
    const setter = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')?.set;

    act(() => {
        setter?.call(input, text);
        input.dispatchEvent(new Event('input', { bubbles: true }));
    });
}

function lastVisit(): Visit {
    return (inertia.get.mock.lastCall?.[2] ?? {}) as Visit;
}

/** The visit in flight fails as the framework reports it; answers whether a listener took it over. */
function fail(): boolean {
    const event = new Event('invalid', { cancelable: true });

    act(() => inertia.listeners.get('invalid')?.(event));

    return event.defaultPrevented;
}

let view: Mounted;

beforeEach(() => {
    inertia.get.mockReset();
    inertia.listeners.clear();
    view = mount();
});

afterEach(() => view.unmount());

describe('the customers screen', () => {
    it('renders each row with its most recent name as a link to the customer\'s page, the other names, the contact, the visits in the tenant\'s zone, No visit yet where none is passed, and the count, N of M in the head', () => {
        inertia.props = propsWith([ADA, CASEY], { total: 30 });
        show();

        expect([...view.host.querySelectorAll('a.admin-customers__name')].map((link) => [link.textContent, link.getAttribute('href')])).toEqual([
            ['Ada North', '/admin/customers/ada%40example.test'],
            ['Casey Customer', '/admin/customers/casey%40example.com'],
        ]);
        expect([...view.host.querySelectorAll('th')].map((cell) => cell.textContent)).toEqual(heads(true));
        expect(q('.admin-customers__count')?.textContent).toBe(words.text('table.count', { count: '2', total: '30' }));
        expect(view.host.querySelectorAll('.admin-customers__also')).toHaveLength(1);
        expect(q('.admin-customers__also')?.textContent).toBe(words.text('table.also_booked_as', { names: 'Casey C.' }));
        expect(q('.admin-customers__also')?.previousElementSibling?.classList.contains('admin-customers__name')).toBe(true);
        expect(view.host.querySelectorAll('.admin-customers__email')[1]?.textContent).toBe('casey@example.com');
        expect(view.host.querySelectorAll('.admin-customers__phones')[1]?.textContent).toBe('+30 210 2222222, +30 210 1111111');
        expect(view.host.querySelectorAll('.admin-customers__first')[1]?.textContent).toBe('03/11/2026 10:00');
        expect(view.host.querySelectorAll('.admin-customers__last')[1]?.textContent).toBe('20/11/2026 10:00');
        expect(view.host.querySelectorAll('.admin-customers__last')[0]?.textContent).toBe(words.text(NO_VISIT));
        // Dates and counts in the text face with tabular figures, the sentence quieter than a date.
        expect(q('.admin-customers table')?.classList.contains('tabular-nums')).toBe(true);
        expect([...view.host.querySelectorAll('.admin-customers *')].filter((element) => /mono/.test(element.getAttribute('class') ?? ''))).toEqual([]);
        expect(view.host.querySelectorAll('.admin-customers__last')[0]?.classList.contains('text-muted-foreground')).toBe(true);
        expect(view.host.querySelectorAll('.admin-customers__last')[1]?.classList.contains('text-muted-foreground')).toBe(false);
        expect(q('.admin-pagination__page')?.classList.contains('bg-foreground')).toBe(true);
        expect(q('.admin-pagination__page')?.classList.contains('text-background')).toBe(true);
        expect(view.host.querySelectorAll('.admin-customers__bookings')[1]?.textContent).toBe('3');
        expect(q('.admin-customers-empty')).toBeNull();
        expect(q('#customers-search')?.getAttribute('placeholder')).toBe(words.text(SEARCH_HINT_CONTACT));
    });

    it('draws a row\'s tags as chips under the name and the other names, and a Blocked chip where online booking is blocked for the address, nothing where it has neither', () => {
        inertia.props = propsWith([ADA, CASEY, { ...CASEY, name: 'Robin Regular', names: ['Robin Regular'], tags: [], blocked: false, url: '/admin/customers/robin%40example.test' }]);
        show();

        const cells = [...view.host.querySelectorAll('.admin-customers__row')].map((row) => row.querySelector('td'));
        const chips = (cell: Element | null | undefined) => [...(cell?.querySelectorAll('.admin-customers__chips > *') ?? [])].map((chip) => [chip.className.includes('admin-customers__blocked') ? 'blocked' : 'tag', chip.textContent]);

        expect(chips(cells[0])).toEqual([['blocked', words.text('table.blocked')]]);
        expect(chips(cells[1])).toEqual([
            ['tag', 'VIP'],
            ['tag', 'colour'],
        ]);
        expect(cells[1]?.querySelector('.admin-customers__chips')?.previousElementSibling?.classList.contains('admin-customers__also')).toBe(true);
        expect(cells[2]?.querySelector('.admin-customers__chips')).toBeNull();
    });

    it('leaves the email and phone columns out where the server withheld them, and searches the name alone', () => {
        const withheld: Customer = { name: CASEY.name, names: CASEY.names, first_visit: CASEY.first_visit, last_visit: CASEY.last_visit, bookings: CASEY.bookings, upcoming: 0, completed: 1, no_shows: 1, cancelled: 1, url: CASEY.url, tags: [], blocked: false };

        inertia.props = propsWith([withheld], { searchable: ['customer_name'] });
        show();

        expect([...view.host.querySelectorAll('th')].map((cell) => cell.textContent)).toEqual(heads(false));
        expect(q('.admin-customers__email')).toBeNull();
        expect(q('#customers-search')?.getAttribute('placeholder')).toBe(words.text(SEARCH_HINT_NAME));
    });

    it('opens the customer\'s page from the name\'s link once, by the router rather than the browser, and from the row as the larger target', () => {
        inertia.props = propsWith([ADA, CASEY]);
        show();

        const link = view.host.querySelectorAll('.admin-customers__name')[1] ?? null;
        const click = new MouseEvent('click', { bubbles: true, cancelable: true });
        act(() => {
            link?.dispatchEvent(click);
        });
        expect(click.defaultPrevented).toBe(true);
        expect(inertia.get).toHaveBeenCalledTimes(1);
        expect(inertia.get.mock.lastCall?.[0]).toBe('/admin/customers/casey%40example.com');

        press(view.host.querySelectorAll('.admin-customers__row')[0] ?? null);
        expect(inertia.get).toHaveBeenCalledTimes(2);
        expect(inertia.get.mock.lastCall?.[0]).toBe('/admin/customers/ada%40example.test');
    });

    it('leaves a modified or middle click on a name to the browser, opening nothing in the page', () => {
        inertia.props = propsWith([ADA, CASEY]);
        show();
        const link = view.host.querySelectorAll('.admin-customers__name')[1] ?? null;

        for (const init of [{ ctrlKey: true }, { metaKey: true }, { shiftKey: true }, { button: 1 }]) {
            const click = new MouseEvent('click', { bubbles: true, cancelable: true, ...init });
            act(() => {
                link?.dispatchEvent(click);
            });
            expect(click.defaultPrevented).toBe(false);
        }

        expect(inertia.get).not.toHaveBeenCalled();
    });

    it('answers a failed open in its own words, never through the framework modal', () => {
        inertia.props = propsWith([ADA]);
        show();

        press(q('.admin-customers__name'));
        const visit = lastVisit();

        act(() => visit.onStart?.());
        expect(fail()).toBe(true);
        act(() => visit.onFinish?.());

        expect(q('.admin-customers-open-error')?.textContent).toBe(words.text(OPEN_ERROR));
    });

    it('searches by a partial reload of the list alone with the URL replaced, and pages with the search kept', () => {
        inertia.props = propsWith([ADA, CASEY], { total: 30 });
        show();

        typeSearch('cas');
        expect(inertia.get.mock.lastCall?.[0]).toBe('/admin/customers');
        expect(inertia.get.mock.lastCall?.[1]).toEqual({ search: 'cas' });
        expect(lastVisit()).toMatchObject({ only: ['customers', 'filters'], replace: true, preserveState: true });

        press(q('.admin-pagination__next'));
        expect(inertia.get.mock.lastCall?.[1]).toEqual({ search: 'cas', page: '2' });

        typeSearch('');
        expect(inertia.get.mock.lastCall?.[1]).toEqual({});
    });

    it('keeps the search as typed where the server read it trimmed, and follows a later answer that reads another', () => {
        inertia.props = propsWith([ADA, CASEY]);
        show();

        typeSearch('cas ');
        const read = lastVisit();
        act(() => read.onStart?.());
        inertia.props = propsWith([CASEY], { search: 'cas' });
        show();
        act(() => read.onFinish?.());

        expect((q('#customers-search') as HTMLInputElement).value).toBe('cas ');

        inertia.props = propsWith([ADA], { search: 'ada' });
        show();

        expect((q('#customers-search') as HTMLInputElement).value).toBe('ada');
    });

    it('holds the empty panel back while a search over no rows is in flight, shows the list\'s error and no panel when it fails, and the panel once it has settled', () => {
        inertia.props = propsWith([], { search: 'zz' });
        show();

        expect(q('.admin-customers-empty')?.textContent).toBe(words.text(NO_MATCH));
        expect(cardHolds()).toEqual(['bar', 'empty']);
        expect(q('.admin-customers-empty')?.closest('section')).toBe(q('.admin-customers'));
        expect(q('.admin-customers__count')?.getAttribute('aria-live')).toBe('polite');
        expect(q('.admin-customers__count')?.textContent).toBe('');

        typeSearch('zzz');
        const searching = lastVisit();

        act(() => searching.onStart?.());
        expect(q('.admin-customers-empty')).toBeNull();
        expect(cardHolds()).toEqual(['bar']);
        expect(q('.admin-list-error')).toBeNull();

        expect(fail()).toBe(true);
        act(() => searching.onFinish?.());
        expect(q('.admin-list-error')?.textContent).toBe(words.text(LIST_ERROR));
        expect(q('.admin-list-error')?.getAttribute('role')).toBe('alert');
        expect(q('.admin-customers-empty')).toBeNull();
        // The search stays on screen above the error, so it can be changed to try again.
        expect(cardHolds()).toEqual(['bar', 'error']);

        typeSearch('');
        const settling = lastVisit();

        act(() => settling.onStart?.());
        expect(q('.admin-customers-empty')).toBeNull();

        inertia.props = propsWith([]);
        act(() => settling.onSuccess?.());
        act(() => settling.onFinish?.());
        show();

        expect(q('.admin-list-error')).toBeNull();
        expect(q('.admin-customers-empty')?.textContent).toBe(`${words.text(NO_CUSTOMERS)}${words.text(NO_CUSTOMERS_SENTENCE)}`);
        expect(cardHolds()).toEqual(['bar', 'empty']);
    });
});

describe('the customers list\'s layout', () => {
    it('holds the toolbar, the table and the pager in one flush card that is a query container, the head row held at the top of the window, the count after the search announced as it changes', () => {
        inertia.props = propsWith([ADA, CASEY], { total: 30 });
        show();

        const card = q('.admin-customers');

        expect(card?.tagName).toBe('SECTION');
        expect([...(card?.classList ?? [])]).toEqual(expect.arrayContaining(['admin-card', '@container', 'overflow-clip']));
        expect(card?.className).not.toMatch(/overflow-hidden/);
        expect(cardHolds()).toEqual(['bar', 'table', 'pager']);
        expect(view.host.querySelector('h2')).toBeNull();
        expect(q('.admin-customers .admin-table-scroll')).toBeNull();
        expect([...(q('.admin-customers thead')?.classList ?? [])]).toEqual(expect.arrayContaining(['sticky', 'top-0']));

        const [search, count, download] = [...(q('.admin-customers-bar')?.children ?? [])];

        expect(q('.admin-customers-bar')?.children).toHaveLength(3);
        expect(search?.querySelector('.admin-search-field input.admin-customers-search')).toBe(q('#customers-search'));
        expect(q('#customers-search')?.getAttribute('type')).toBe('search');
        expect(count?.classList.contains('admin-customers__count')).toBe(true);
        expect(count?.getAttribute('aria-live')).toBe('polite');
        expect(count?.textContent).toBe(words.text('table.count', { count: '2', total: '30' }));
        expect(count?.classList.contains('ms-auto')).toBe(true);
        expect(download?.classList.contains('admin-customers-download')).toBe(true);
        // In a narrow card the search takes the toolbar's whole width, the count and the download under it.
        expect(under('.admin-customers-bar > :first-child', '@max-2xl:')).toContain('basis-full');
        expect(under('.admin-customers__count', '@max-2xl:')).toContain('ms-0');
        expect(under('.admin-customers-download', '@max-2xl:')).toContain('ms-auto');

        inertia.props = propsWith([CASEY], { total: 1, search: 'casey' });
        show();

        expect(q('.admin-customers__count')).toBe(count);
        expect(count?.textContent).toBe(words.text('table.count', { count: '1', total: '1' }));
    });

    it('draws each customer\'s initials at the person size before the name, with no ring and hidden from assistive technology, and the booking count as a small soft chip with no chevron', () => {
        inertia.props = propsWith([ADA, CASEY]);
        show();

        const avatars = [...view.host.querySelectorAll('.admin-customers__row')].map((row) => row.querySelector('td')?.querySelector<HTMLElement>('[aria-hidden="true"]'));

        expect(avatars.map((avatar) => avatar?.textContent)).toEqual(['AN', 'CC']);

        for (const avatar of avatars) {
            expect(avatar?.classList.contains('size-10')).toBe(true);
            expect(avatar?.className).not.toMatch(/ring/);
            expect(avatar?.style.boxShadow).toBe('');
            expect(avatar?.nextElementSibling?.firstElementChild?.classList.contains('admin-customers__name')).toBe(true);
        }

        const chip = view.host.querySelectorAll('.admin-customers__bookings')[1]?.firstElementChild;

        expect(chip?.textContent).toBe('3');
        expect([...(chip?.classList ?? [])]).toEqual(expect.arrayContaining(['rounded-full', 'bg-muted', 'text-primary-deep', 'font-bold']));
        expect(q('.admin-customers__bookings svg')).toBeNull();
    });

    it('lays a row out as a block on a phone and in a card narrower than 944px, the same utilities under both conditions: the initials and the name with the booking count at its right, then the email, the phones and the visits beneath, lined up with the name', () => {
        inertia.props = propsWith([ADA, CASEY]);
        show();

        const parts = ['.admin-customers table', '.admin-customers thead', '.admin-customers tbody', '.admin-customers__row', '.admin-customers__row > :first-child', '.admin-customers__name', '.admin-customers__also', '.admin-customers__email', '.admin-customers__phones', '.admin-customers__bookings'];

        for (const part of parts) {
            expect(under(part, 'max-wide:').length).toBeGreaterThan(0);
            expect(under(part, BLOCK_CARD)).toEqual(under(part, 'max-wide:'));
        }

        // The shared visits column shows below the wide breakpoint and in a narrow card alike, in
        // the block rows, the condition that shows it being the block rows' own.
        expect(SHARED_CARD).toBe(BLOCK_CARD);
        expect(under('.admin-customers__visits', BLOCK_CARD)).toContain('table-cell');
        expect(under('.admin-customers__visits', 'max-wide:')).toEqual(under('.admin-customers__visits', BLOCK_CARD));

        expect(under('.admin-customers table', 'max-wide:')).toContain('block');
        expect(under('.admin-customers thead', 'max-wide:')).toContain('sr-only');
        expect(under('.admin-customers tbody', 'max-wide:')).toContain('block');
        expect(under('.admin-customers__row', 'max-wide:')).toEqual(expect.arrayContaining(['grid', 'grid-cols-[minmax(0,1fr)_auto]']));
        expect(under('.admin-customers__bookings', 'max-wide:')).toEqual(expect.arrayContaining(['col-start-2', 'row-start-1']));

        for (const beneath of ['.admin-customers__email', '.admin-customers__phones', '.admin-customers__visits']) {
            expect(under(beneath, 'max-wide:')).toEqual(expect.arrayContaining(['col-span-2', 'ps-13.5']));
        }

        expect([...view.host.querySelectorAll('.admin-customers__row:first-child > *')].map((cell) => cell.className.match(/admin-customers__\w+/)?.[0] ?? 'name')).toEqual([
            'name',
            'admin-customers__email',
            'admin-customers__phones',
            'admin-customers__first',
            'admin-customers__last',
            'admin-customers__visits',
            'admin-customers__bookings',
        ]);
        expect(q('.admin-table__label')).toBeNull();

        const conditions = [...view.host.querySelectorAll('.admin-customers *')].flatMap((element) => [...element.classList]).filter((name) => name.startsWith('@max-')).map((name) => name.split(':')[0]);

        // The toolbar's own condition and the block rows', which also shares the visits, and no other.
        expect(new Set(conditions)).toEqual(new Set(['@max-2xl', BLOCK_CARD.slice(0, -1), SHARED_CARD.slice(0, -1)]));
    });

    it('draws the first and the last visit apart in a wide card and in one shared column in a card narrower than 944px and in the block rows, each date after its head as a small label, one form at a time', () => {
        inertia.props = propsWith([ADA, CASEY]);
        show();

        const apart = ['thead th:nth-child(4)', 'thead th:nth-child(5)', '.admin-customers__first', '.admin-customers__last'];
        const shared = ['thead th:nth-child(6)', '.admin-customers__visits'];

        for (const selector of apart) {
            expect(under(selector, 'max-wide:')).toEqual(['hidden']);
            expect(under(selector, SHARED_CARD)).toEqual(['hidden']);
        }

        for (const selector of shared) {
            expect(q(selector)?.classList.contains('hidden')).toBe(true);
            expect(under(selector, 'max-wide:')).toContain('table-cell');
            expect(under(selector, SHARED_CARD)).toContain('table-cell');
        }

        // The shared head cell carries the condition for its display alone; the visits cell also
        // carries the block rows' utilities under it.
        expect(under('thead th:nth-child(6)', SHARED_CARD)).toEqual(['table-cell']);

        // The block rows carry the same condition, so only the parts it hides or shows count here.
        const narrow = [...view.host.querySelectorAll('.admin-customers *')].filter((element) => ['hidden', 'table-cell'].some((display) => element.classList.contains(SHARED_CARD + display)));

        expect(narrow).toEqual([...view.host.querySelectorAll('thead th:nth-child(4), thead th:nth-child(5), thead th:nth-child(6), .admin-customers__first, .admin-customers__last, .admin-customers__visits')]);
        expect([...(q('thead th:nth-child(6)')?.children ?? [])].map((line) => [line.textContent, line.classList.contains('block')])).toEqual([
            [words.text('table.first_visit'), true],
            [words.text('table.last_visit'), true],
        ]);

        const visits = (row: number) => [...(view.host.querySelectorAll('.admin-customers__visits')[row]?.firstElementChild?.children ?? [])].map((line) => [...line.children].map((part) => part.textContent));

        expect(visits(0)).toEqual([
            [words.text('table.first_visit'), '01/12/2026 10:00'],
            [words.text('table.last_visit'), words.text(NO_VISIT)],
        ]);
        expect(visits(1)).toEqual([
            [words.text('table.first_visit'), '03/11/2026 10:00'],
            [words.text('table.last_visit'), '20/11/2026 10:00'],
        ]);
        expect([...(q('.admin-customers__visits > * > * > :first-child')?.classList ?? [])]).toEqual(expect.arrayContaining(['text-small', 'text-muted-foreground']));
    });

    it('reads each visit\'s date in bold and its time in the plain weight, in both visit columns and the shared one, No visit yet left plain', () => {
        inertia.props = propsWith([ADA, CASEY]);
        show();

        const parts = (cell: Element | undefined) => [...(cell?.querySelectorAll('.admin-instant__day, .admin-instant__time') ?? [])].map((part) => [part.textContent, [...part.classList]]);
        const day = (text: string) => [text, ['admin-instant__day', 'font-bold']];
        const time = (text: string) => [text, ['admin-instant__time']];

        expect(parts(view.host.querySelectorAll('.admin-customers__first')[1])).toEqual([day('03/11/2026'), time('10:00')]);
        expect(parts(view.host.querySelectorAll('.admin-customers__last')[1])).toEqual([day('20/11/2026'), time('10:00')]);
        expect(parts(view.host.querySelectorAll('.admin-customers__visits')[1])).toEqual([day('03/11/2026'), time('10:00'), day('20/11/2026'), time('10:00')]);
        expect(parts(view.host.querySelectorAll('.admin-customers__last')[0])).toEqual([]);
        expect(parts(view.host.querySelectorAll('.admin-customers__visits')[0])).toEqual([day('01/12/2026'), time('10:00')]);
        expect(view.host.querySelectorAll('.admin-customers__first')[1]?.textContent).toBe('03/11/2026 10:00');
    });

    it('reads as a table with its column headers to a screen reader whatever the layout', () => {
        inertia.props = propsWith([ADA, CASEY]);
        show();

        expect(q('.admin-customers table')?.getAttribute('role')).toBe('table');
        expect([...view.host.querySelectorAll('.admin-customers thead, .admin-customers tbody')].map((group) => group.getAttribute('role'))).toEqual(['rowgroup', 'rowgroup']);
        expect([...view.host.querySelectorAll('thead th')].map((th) => [th.getAttribute('role'), th.getAttribute('scope')])).toEqual(Array.from({ length: 7 }, () => ['columnheader', 'col']));
        expect([...view.host.querySelectorAll('.admin-customers__row')].map((row) => row.getAttribute('role'))).toEqual(['row', 'row']);
        expect([...view.host.querySelectorAll('.admin-customers__row:first-child > *')].map((cell) => cell.getAttribute('role'))).toEqual(Array.from({ length: 7 }, () => 'cell'));
    });

    it('wraps the head labels, the names, the email and the phones rather than widening the table: the email anywhere past a floor of its own in the grid and never cut, the name between its words in the grid and anywhere in the block rows with the other names, each phone moved whole onto a line of its own, the column holding a floor in the grid, broken at its spaces where wider than the line and anywhere where it has none', () => {
        inertia.props = propsWith([{ ...CASEY, email: `${'a'.repeat(47)}@luna.example` }]);
        show();

        for (const head of view.host.querySelectorAll('thead th')) {
            expect(head.classList.contains('whitespace-normal')).toBe(true);
        }

        const classes = (element: Element | null | undefined, pattern: RegExp) => [...(element?.classList ?? [])].filter((name) => pattern.test(name)).sort();
        const wraps = (selector: string) => classes(q(selector), /(^|:)(wrap-|truncate|text-ellipsis|whitespace-)/);
        const anywhereInBlocks = [`${BLOCK_CARD}wrap-anywhere`, 'max-wide:wrap-anywhere'];

        expect(wraps('.admin-customers__email')).toEqual(['wrap-anywhere']);
        expect(q('.admin-customers__email')?.textContent).toHaveLength(60);
        // The floor holds in the grid alone; a block row lets the email take the line it has.
        expect(classes(q('.admin-customers__email'), /(^|:)min-w-/)).toEqual([`${BLOCK_CARD}min-w-0`, 'max-wide:min-w-0', 'min-w-52']);
        expect(wraps('.admin-customers__name')).toEqual([...anywhereInBlocks, 'whitespace-normal', 'wrap-break-word']);
        expect(q('.admin-customers__also')?.textContent).toBe(words.text('table.also_booked_as', { names: 'Casey C.' }));
        expect(wraps('.admin-customers__also')).toEqual(anywhereInBlocks);
        expect(wraps('.admin-customers__phones')).toEqual(['wrap-anywhere']);
        expect(classes(q('.admin-customers__phones'), /(^|:)min-w-/)).toEqual([`${BLOCK_CARD}min-w-0`, 'max-wide:min-w-0', 'min-w-42']);
        expect([...view.host.querySelectorAll('.admin-customers__phones > *')].map((phone) => [phone.textContent, classes(phone, /(^|:)(inline|block|wrap-|truncate|text-ellipsis|whitespace-)/)])).toEqual([
            ['+30 210 2222222', ['inline-block']],
            ['+30 210 1111111', ['inline-block']],
        ]);
    });
});

/** Lets the fetch's answer and every step after it run, inside act. */
async function settle(): Promise<void> {
    for (let turn = 0; turn < 3; turn++) {
        await act(async () => {
            await new Promise((resolve) => setTimeout(resolve, 0));
        });
    }
}

describe('the list\'s Download CSV', () => {
    let refuse: (failure: Error) => void;
    let fetched: ReturnType<typeof vi.fn>;

    beforeEach(() => {
        fetched = vi.fn(
            () =>
                new Promise<Response>((_resolve, reject) => {
                    refuse = reject;
                }),
        );
        vi.stubGlobal('fetch', fetched);
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('sits last in the toolbar after the search and the count, a secondary button with its icon before the words, and fetches nothing until pressed', () => {
        inertia.props = propsWith([ADA, CASEY]);
        show();

        const button = q('.admin-customers-bar .admin-customers-download');

        expect(button?.textContent).toBe(words.text('csv.download'));
        expect(button?.classList.contains('admin-button--secondary')).toBe(true);
        expect(button?.firstElementChild?.classList.contains('admin-button__icon')).toBe(true);
        expect(button?.firstElementChild?.getAttribute('aria-hidden')).toBe('true');
        expect(button?.firstElementChild?.querySelector('svg')).not.toBeNull();
        expect(button?.previousElementSibling?.classList.contains('admin-customers__count')).toBe(true);
        expect(button?.previousElementSibling?.previousElementSibling?.querySelector('.admin-customers-search')).toBe(q('#customers-search'));
        expect(fetched).not.toHaveBeenCalled();
    });

    it('asks for the whole list where no search narrows it, and for the search the screen answered, never its page', () => {
        inertia.props = propsWith([ADA, CASEY], { page: 2 });
        show();
        press(q('.admin-customers-download'));

        expect(fetched.mock.lastCall?.[0]).toBe('/admin/customers/csv');

        view.unmount();
        view = mount();
        inertia.props = propsWith([CASEY], { search: 'casey & co', page: 3 });
        show();
        press(q('.admin-customers-download'));

        expect(fetched.mock.lastCall?.[0]).toBe('/admin/customers/csv?search=casey+%26+co');
        expect(fetched.mock.lastCall?.[1]).toMatchObject({ credentials: 'same-origin' });
    });

    it('is busy while the file is on its way and words a failure in an error toast', async () => {
        inertia.props = propsWith([CASEY], { search: 'casey' });
        show();
        press(q('.admin-customers-download'));

        expect(q('.admin-customers-download')?.getAttribute('aria-busy')).toBe('true');
        expect(q('.admin-customers-download')?.textContent).toContain(words.text('csv.downloading'));

        refuse(new TypeError('offline'));
        await settle();

        expect(q('.admin-customers-download')?.getAttribute('aria-busy')).toBeNull();
        expect(q('.admin-toast')?.textContent).toContain(words.text('csv.download_error'));
    });
});

describe('the customers section', () => {
    it('is typed as the keys of lang/en/admin/customers.php, no more and no fewer', () => {
        expectTypeOf<Shape<typeof ADMIN_LINES_EN.customers>>().toEqualTypeOf<CustomersStrings>();
        expect(Object.keys(ADMIN_LINES_EN.customers)).toEqual(['page', 'table', 'csv', 'customer', 'tiles', 'profile', 'notes', 'block', 'export', 'erasure', 'wizard', 'validation', 'refusals', 'flashes']);
    });

    it('leaves no English line of the list on the screen, every word read from the section', () => {
        const english = [...Object.values(ADMIN_LINES_EN.customers.page), ...Object.values(ADMIN_LINES_EN.customers.table)].filter((line) => !/:[a-z_]+/.test(line));

        inertia.props = propsWith([ADA, CASEY], { total: 30 });
        show();

        expect(english.filter((line) => (view.host.textContent ?? '').includes(line) || view.host.innerHTML.includes(`"${line}"`))).toEqual([]);
        expect(view.host.textContent).toContain(words.text('page.title'));
    });
});
