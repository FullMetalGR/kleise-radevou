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

/** The list's column heads as the table words them, the contact's two where shown. */
function heads(contact: boolean): string[] {
    return (contact ? (['table.name', 'table.email', 'table.phone', 'table.first_visit', 'table.last_visit', 'table.bookings'] as const) : (['table.name', 'table.first_visit', 'table.last_visit', 'table.bookings'] as const)).map((key) => words.text(key));
}

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
        // The sentence in the body face, a date in mono.
        expect(view.host.querySelectorAll('.admin-customers__last')[0]?.classList.contains('font-mono')).toBe(false);
        expect(view.host.querySelectorAll('.admin-customers__last')[1]?.classList.contains('font-mono')).toBe(true);
        expect(q('.admin-pagination__page')?.classList.contains('text-muted-foreground')).toBe(true);
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

        typeSearch('zzz');
        const searching = lastVisit();

        act(() => searching.onStart?.());
        expect(q('.admin-customers-empty')).toBeNull();
        expect(q('.admin-customers')).toBeNull();
        expect(q('.admin-list-error')).toBeNull();

        expect(fail()).toBe(true);
        act(() => searching.onFinish?.());
        expect(q('.admin-list-error')?.textContent).toBe(words.text(LIST_ERROR));
        expect(q('.admin-list-error')?.getAttribute('role')).toBe('alert');
        expect(q('.admin-customers-empty')).toBeNull();

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
        expect(q('.admin-customers')).toBeNull();
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

    it('sits beside the search as a secondary button and fetches nothing until pressed', () => {
        inertia.props = propsWith([ADA, CASEY]);
        show();

        const button = q('.admin-customers-bar .admin-customers-download');

        expect(button?.textContent).toBe(words.text('csv.download'));
        expect(button?.previousElementSibling?.classList.contains('admin-customers-search')).toBe(true);
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
