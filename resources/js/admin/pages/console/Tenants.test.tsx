import { act } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { mount, press, type Mounted } from '@/components/ui/harness';
import { ISO_WEEKDAYS, weekdayName } from '@/lib/names';
import { keyedShell } from '@/testing/keyed';
import Tenants, { NO_TENANTS, NO_TENANTS_MATCH, NO_TENANTS_ON_PAGE, NO_TENANTS_SENTENCE, RELEASED, TENANTS_LIST_ERROR, type TenantFilters, type TenantRow } from './Tenants';
import { NO_PLAN, PLAN_WORDS, type PlanName, planWord, STATE_WORDS, STATUS_WORDS } from './vocabulary';

/*
 * The tenant list: the rows as the server sent them, each slug a link to the tenant's detail and
 * each vocabulary value in words; every filter and the page a partial reload of the list with the
 * query carrying the whole state and nothing else, the bar holding what was asked and the rows
 * what the server answered; the settledness gate. The framework is stood in for by a router that
 * records each visit and the failure listeners registered around it. New tenant beside the
 * heading opens the free tenant's form. Floor and staff read by the names the server sends from
 * the catalogue, Solo and Team; trial, free and unlisted are the page's own words.
 */

const shell = keyedShell();

type Visit = { only?: string[]; replace?: boolean; preserveState?: boolean; onStart?: () => void; onSuccess?: () => void; onFinish?: () => void };

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

const LUNA: TenantRow = { slug: 'luna', detail: '/tenants/luna', name: 'Luna Hair Studio', status: 'active', plan: 'free', state: 'active', signed_up: 'Wednesday 14 October 2026 at 11:00' };

const PIER: TenantRow = { slug: 'pier', detail: '/tenants/pier', name: 'Pier Physiotherapy', status: 'suspended', plan: 'staff', state: 'past_due', signed_up: 'Tuesday 13 October 2026 at 10:30' };

const RESERVED: TenantRow = { slug: 'reserved', detail: '/tenants/reserved', name: 'Reserved Salon', status: 'provisioning', plan: null, state: 'incomplete', signed_up: 'Monday 12 October 2026 at 09:00' };

const NO_FILTERS: TenantFilters = { search: '', status: '', state: '', plan: '', from: '', to: '' };

/** The names the server reads from config/billing.php, in the catalogue's order. */
const PLAN_NAMES: PlanName[] = [
    { plan: 'floor', name: 'Solo' },
    { plan: 'staff', name: 'Team' },
];

function propsWith(rows: TenantRow[], patch: { filters?: Partial<TenantFilters>; total?: number; page?: number; planNames?: PlanName[] } = {}): Record<string, unknown> {
    return {
        flash: null,
        operator: 'Pat Operator',
        errors: {},
        nav: [],
        zone: 'Europe/Athens',
        plan_names: patch.planNames ?? PLAN_NAMES,
        filters: { ...NO_FILTERS, ...patch.filters },
        tenants: { rows, total: patch.total ?? rows.length, page: patch.page ?? 1, page_size: 25 },
    };
}

let view: Mounted;

function q(selector: string): Element | null {
    return view.host.querySelector(selector);
}

function texts(selector: string): (string | null)[] {
    return [...view.host.querySelectorAll(selector)].map((element) => element.textContent);
}

function lastVisit(): Visit {
    return (inertia.get.mock.lastCall?.[2] ?? {}) as Visit;
}

/** Types into the search as a person does, through React's own value setter. */
function typeSearch(text: string): void {
    const input = q('#tenants-search') as HTMLInputElement;
    const setter = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')?.set;

    act(() => {
        setter?.call(input, text);
        input.dispatchEvent(new Event('input', { bubbles: true }));
    });
}

/** Chooses an option of a select as a person does, through React's own value setter. */
function choose(selector: string, value: string): void {
    const select = q(selector) as HTMLSelectElement;
    const setter = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'value')?.set;

    act(() => {
        setter?.call(select, value);
        select.dispatchEvent(new Event('change', { bubbles: true }));
    });
}

/** The visit in flight fails as the framework reports it. */
function fail(): void {
    act(() => inertia.listeners.get('invalid')?.(new Event('invalid', { cancelable: true })));
}

beforeEach(() => {
    inertia.get.mockReset();
    inertia.listeners.clear();
    view = mount();
});

afterEach(() => view.unmount());

describe('the tenant list', () => {
    it('renders each row as the server sent it, the slug a link to the tenant\'s detail and each value in words, N of M in the head', () => {
        inertia.props = propsWith([LUNA, PIER, RESERVED], { total: 40 });
        view.render(<Tenants />);

        expect(q('h1')?.textContent).toBe('Tenants');
        expect(texts('th')).toEqual(['Slug', 'Name', 'Status', 'Plan', 'Subscription', 'Signed up']);
        expect(texts('.admin-tenants__slug')).toEqual(['luna', 'pier', 'reserved']);
        expect([...view.host.querySelectorAll('.admin-tenants__slug')].map((link) => link.getAttribute('href'))).toEqual(['/tenants/luna', '/tenants/pier', '/tenants/reserved']);
        expect(texts('.admin-tenants__name')).toEqual(['Luna Hair Studio', 'Pier Physiotherapy', 'Reserved Salon']);
        expect(texts('.admin-tenants__status')).toEqual(['Active', 'Suspended', 'Provisioning']);
        expect(texts('.admin-tenants__plan')).toEqual(['Free', 'Team', NO_PLAN]);
        expect(texts('.admin-tenants__state')).toEqual(['Active', 'Past due', 'Incomplete']);
        expect(texts('.admin-tenants__signed-up')).toEqual([LUNA.signed_up, PIER.signed_up, RESERVED.signed_up]);
        expect(q('.admin-tenants__count')?.textContent).toBe('3 of 40');
        expect(q('.admin-pagination')?.textContent).toContain(shell.text('kit.pagination.page', { page: '1', last: '2' }));
    });

    it('links a closed row, whose slug the drop released, by the path the server sent, its slug read as released', () => {
        const closed: TenantRow = { slug: null, detail: '/tenants/closed/12', name: 'Gone Salon', status: 'closed', plan: 'free', state: 'active', signed_up: 'Monday 5 October 2026 at 09:00' };
        inertia.props = propsWith([LUNA, closed]);
        view.render(<Tenants />);

        expect(texts('.admin-tenants__slug')).toEqual(['luna', RELEASED]);
        expect([...view.host.querySelectorAll('.admin-tenants__slug')].map((link) => link.getAttribute('href'))).toEqual(['/tenants/luna', '/tenants/closed/12']);
        expect(texts('.admin-tenants__status')).toEqual(['Active', 'Closed']);
    });

    it('offers New tenant beside the heading, a link to the free tenant\'s form', () => {
        inertia.props = propsWith([LUNA]);
        view.render(<Tenants />);

        expect(q('.admin-tenants-new')?.textContent).toBe('New tenant');
        expect(q('.admin-tenants-new')?.getAttribute('href')).toBe('/tenants/new');
    });

    it('words every status, state and plan of the vocabulary, one word each', () => {
        expect(Object.keys(STATUS_WORDS)).toEqual(['provisioning', 'active', 'suspended', 'closing', 'closed']);
        expect(new Set(Object.values(STATUS_WORDS)).size).toBe(5);
        expect(Object.keys(STATE_WORDS)).toEqual(['active', 'grace', 'past_due', 'canceled', 'incomplete', 'trial']);
        expect(Object.keys(PLAN_WORDS)).toEqual(['trial', 'free', 'unlisted']);
        expect(new Set(Object.values(STATE_WORDS)).size).toBe(6);
        expect(new Set(Object.values(PLAN_WORDS)).size).toBe(3);
        expect((['trial', 'free', 'floor', 'staff', 'unlisted'] as const).map((plan) => planWord(plan, PLAN_NAMES))).toEqual(['Trial', 'Free', 'Solo', 'Team', 'Unlisted']);
    });

    it('words floor and staff by the names the server sends, a renamed plan followed in the column and the filter', () => {
        inertia.props = propsWith([PIER, { ...LUNA, plan: 'floor' }], { planNames: [{ plan: 'floor', name: 'Starter' }, { plan: 'staff', name: 'Studio' }] });
        view.render(<Tenants />);

        expect(texts('.admin-tenants__plan')).toEqual(['Studio', 'Starter']);
        expect(texts('#tenants-plan option')).toEqual(['Any plan', 'Trial', 'Free', 'Starter', 'Studio', 'Unlisted']);
    });

    it('offers trial, free and unlisted alone where the server names no catalogue plan, a catalogue nobody can name', () => {
        inertia.props = propsWith([LUNA], { planNames: [] });
        view.render(<Tenants />);

        expect(texts('#tenants-plan option')).toEqual(['Any plan', 'Trial', 'Free', 'Unlisted']);
        expect(planWord('floor', [])).toBe('floor');
    });

    it('offers every word as a filter after an Any choice, holding what the server read', () => {
        inertia.props = propsWith([PIER], { filters: { status: 'suspended', state: 'past_due', plan: 'staff', search: 'pier', from: '2026-10-01', to: '2026-10-14' } });
        view.render(<Tenants />);

        expect(texts('#tenants-status option')).toEqual(['Any status', 'Provisioning', 'Active', 'Suspended', 'Closing', 'Closed']);
        expect(texts('#tenants-state option')).toEqual(['Any state', 'Active', 'Grace', 'Past due', 'Canceled', 'Incomplete', 'Trial']);
        expect(texts('#tenants-plan option')).toEqual(['Any plan', 'Trial', 'Free', 'Solo', 'Team', 'Unlisted']);
        expect((q('#tenants-status') as HTMLSelectElement).value).toBe('suspended');
        expect((q('#tenants-state') as HTMLSelectElement).value).toBe('past_due');
        expect((q('#tenants-plan') as HTMLSelectElement).value).toBe('staff');
        expect((q('#tenants-search') as HTMLInputElement).value).toBe('pier');
        expect(q('#tenants-from')?.getAttribute('value')).toBe('2026-10-01');
        expect(q('#tenants-from')?.textContent).toBe('01/10/2026');
        expect(q('#tenants-to')?.getAttribute('value')).toBe('2026-10-14');
    });

    it('reads the list again as a partial reload on the first page for a search, the query its whole state', () => {
        inertia.props = propsWith([LUNA, PIER], { filters: { status: 'active' }, page: 2, total: 30 });
        view.render(<Tenants />);

        typeSearch('pier');

        expect(inertia.get).toHaveBeenCalledOnce();
        expect(inertia.get.mock.lastCall?.[0]).toBe('/');
        expect(inertia.get.mock.lastCall?.[1]).toEqual({ search: 'pier', status: 'active' });
        expect(lastVisit().only).toEqual(['tenants', 'filters']);
        expect(lastVisit().replace).toBe(true);
        expect(lastVisit().preserveState).toBe(true);
    });

    it('narrows by status, state and plan, each choice joining the others in the query, Any lifting it', () => {
        inertia.props = propsWith([LUNA, PIER]);
        view.render(<Tenants />);

        choose('#tenants-status', 'active');
        expect(inertia.get.mock.lastCall?.[1]).toEqual({ status: 'active' });

        choose('#tenants-state', 'trial');
        expect(inertia.get.mock.lastCall?.[1]).toEqual({ status: 'active', state: 'trial' });

        choose('#tenants-plan', 'unlisted');
        expect(inertia.get.mock.lastCall?.[1]).toEqual({ status: 'active', state: 'trial', plan: 'unlisted' });

        choose('#tenants-status', '');
        expect(inertia.get.mock.lastCall?.[1]).toEqual({ state: 'trial', plan: 'unlisted' });
    });

    it('narrows by a date picked in the From and To fields, as a calendar date', () => {
        inertia.props = propsWith([LUNA], { filters: { from: '2026-10-01', to: '2026-10-31' } });
        view.render(<Tenants />);

        press(q('#tenants-from'));
        press(document.querySelector('[data-date="2026-10-05"]'));

        expect(inertia.get.mock.lastCall?.[1]).toEqual({ from: '2026-10-05', to: '2026-10-31' });
    });

    it.each(['#tenants-from', '#tenants-to'])('opens its date field %s on the console\'s week, Monday first, named in English', (field) => {
        inertia.props = propsWith([LUNA], { filters: { from: '2026-10-01', to: '2026-10-31' } });
        view.render(<Tenants />);

        press(q(field));

        const heads = [...document.querySelectorAll('.admin-date-field__popover [role="grid"] th[scope="col"]')].map((head) => head.getAttribute('aria-label'));

        expect(heads).toEqual(ISO_WEEKDAYS.map((weekday) => weekdayName(weekday, 'long', 'en')));
        expect(document.querySelector('button[data-date="2026-10-05"]')?.closest('td')?.cellIndex).toBe(0);
    });

    it('brings the bar into line with the server\'s reading once its read lands: a pair of dates the server dropped cleared, the other filters kept', () => {
        inertia.props = propsWith([LUNA], { filters: { from: '2026-10-20', to: '2026-10-31' } });
        view.render(<Tenants />);

        press(q('#tenants-to'));
        press(document.querySelector('[data-date="2026-10-05"]'));
        choose('#tenants-status', 'active');
        const read = lastVisit();
        act(() => read.onStart?.());

        // The server reads a From after its To as no dates at all, and answers the filters so.
        inertia.props = propsWith([LUNA, PIER], { filters: { status: 'active' } });
        view.render(<Tenants />);
        expect((q('#tenants-to') as HTMLButtonElement).value).toBe('2026-10-05');

        act(() => read.onFinish?.());

        expect((q('#tenants-from') as HTMLButtonElement).value).toBe('');
        expect((q('#tenants-to') as HTMLButtonElement).value).toBe('');
        expect((q('#tenants-status') as HTMLSelectElement).value).toBe('active');
    });

    it('keeps the search as typed, its spaces included, where the server read it trimmed, and takes a later answer that differs', () => {
        inertia.props = propsWith([LUNA]);
        view.render(<Tenants />);

        typeSearch('luna ');
        const read = lastVisit();
        act(() => read.onStart?.());
        inertia.props = propsWith([LUNA], { filters: { search: 'luna' } });
        view.render(<Tenants />);
        act(() => read.onFinish?.());

        expect((q('#tenants-search') as HTMLInputElement).value).toBe('luna ');

        // A visit that lands another reading, an action's redirect back among them, is followed.
        inertia.props = propsWith([PIER], { filters: { search: 'pier' } });
        view.render(<Tenants />);

        expect((q('#tenants-search') as HTMLInputElement).value).toBe('pier');
    });

    it('reads the next page with every filter kept, and the first page with none in the query', () => {
        inertia.props = propsWith([LUNA], { filters: { plan: 'free' }, total: 60 });
        view.render(<Tenants />);

        press(q('.admin-pagination__next'));
        expect(inertia.get.mock.lastCall?.[1]).toEqual({ plan: 'free', page: '2' });

        inertia.props = propsWith([LUNA], { filters: { plan: 'free' }, total: 60, page: 2 });
        view.render(<Tenants />);

        press(q('.admin-pagination__previous'));
        expect(inertia.get.mock.lastCall?.[1]).toEqual({ plan: 'free' });
    });

    it('clears every filter at once, busy while the list reads, and offers no Clear where the server read none', () => {
        inertia.props = propsWith([PIER], { filters: { search: 'pier', status: 'suspended' } });
        view.render(<Tenants />);

        press(q('.admin-tenants-clear'));

        expect(inertia.get.mock.lastCall?.[1]).toEqual({});
        expect((q('#tenants-search') as HTMLInputElement).value).toBe('');
        expect((q('#tenants-status') as HTMLSelectElement).value).toBe('');

        act(() => lastVisit().onStart?.());
        expect(q('.admin-tenants-clear')?.getAttribute('aria-busy')).toBe('true');
        act(() => lastVisit().onFinish?.());

        view.unmount();
        view = mount();
        inertia.props = propsWith([LUNA, PIER]);
        view.render(<Tenants />);

        expect(q('.admin-tenants-clear')).toBeNull();
    });

    it('shows the empty panel once the list has settled empty: none at all, none matching, none on a page past the last', () => {
        inertia.props = propsWith([]);
        view.render(<Tenants />);

        expect(q('.admin-tenants-empty')?.textContent).toBe(`${NO_TENANTS}${NO_TENANTS_SENTENCE}`);
        expect(q('.admin-tenants')).toBeNull();

        view.unmount();
        view = mount();
        inertia.props = propsWith([], { filters: { search: 'zzz' } });
        view.render(<Tenants />);

        expect(q('.admin-tenants-empty')?.textContent).toBe(NO_TENANTS_MATCH);

        view.unmount();
        view = mount();
        inertia.props = propsWith([], { total: 30, page: 3 });
        view.render(<Tenants />);

        expect(q('.admin-tenants-empty')?.textContent).toBe(NO_TENANTS_ON_PAGE);
    });

    it('says a failed read in the screen\'s own words above the rows it keeps, and shows no empty panel', () => {
        inertia.props = propsWith([LUNA], { total: 60 });
        view.render(<Tenants />);

        press(q('.admin-pagination__next'));
        act(() => lastVisit().onStart?.());
        fail();
        act(() => lastVisit().onFinish?.());

        expect(q('.admin-list-error')?.textContent).toBe(TENANTS_LIST_ERROR);
        expect(texts('.admin-tenants__slug')).toEqual(['luna']);
        expect(q('.admin-tenants-empty')).toBeNull();
    });
});
