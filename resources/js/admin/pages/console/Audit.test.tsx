import { act } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { mount, press, type Mounted } from '@/components/ui/harness';
import { keyedShell } from '@/testing/keyed';
import Audit, { ACTION_WORDS, type AuditRow, AUDIT_LIST_ERROR, NO_ACTIONS, NO_TENANT_ACTIONS, SHOW_EVERY_TENANT, narrowedTo } from './Audit';

/*
 * The Audit screen: the rows as the server sent them, the action in words and the facts beside
 * it; the narrowing and the page as partial reloads of the list, the query carrying them; the
 * settledness gate, an empty panel only once the list has settled empty and a failed read said
 * in the screen's own words. The framework is stood in for by a router that records each visit
 * and the failure listeners registered around it.
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
    usePage: () => ({ props: inertia.props }),
    router: {
        get: inertia.get,
        on: (name: string, listener: (event: Event) => void) => {
            inertia.listeners.set(name, listener);

            return () => inertia.listeners.delete(name);
        },
    },
}));

const SIGNED_IN: AuditRow = { id: 1, at: 'Wednesday 14 October 2026 at 09:00', operator: 'Pat Operator', action: 'sign_in', tenant: null, facts: [] };

const SUSPENDED: AuditRow = {
    id: 2,
    at: 'Wednesday 14 October 2026 at 10:30',
    operator: 'Sam Operator',
    action: 'tenant_suspended',
    tenant: 'acme',
    facts: [
        { key: 'reason', value: 'Unpaid since March' },
        { key: 'features', value: ['staff', 'extra'] },
        { key: 'user', value: 12 },
    ],
};

function propsWith(rows: AuditRow[], patch: { tenant?: string | null; total?: number; page?: number } = {}): Record<string, unknown> {
    return {
        flash: null,
        operator: 'Pat Operator',
        errors: {},
        nav: [],
        zone: 'Europe/Athens',
        filters: { tenant: patch.tenant ?? null },
        entries: { rows, total: patch.total ?? rows.length, page: patch.page ?? 1, page_size: 50 },
    };
}

function q(selector: string): Element | null {
    return view.host.querySelector(selector);
}

function texts(selector: string): (string | null)[] {
    return [...view.host.querySelectorAll(selector)].map((element) => element.textContent);
}

function lastVisit(): Visit {
    return (inertia.get.mock.lastCall?.[2] ?? {}) as Visit;
}

/** The visit in flight fails as the framework reports it. */
function fail(): void {
    act(() => inertia.listeners.get('invalid')?.(new Event('invalid', { cancelable: true })));
}

let view: Mounted;

beforeEach(() => {
    inertia.get.mockReset();
    inertia.listeners.clear();
    view = mount();
});

afterEach(() => view.unmount());

describe('the Audit screen', () => {
    it('renders each row: the instant as the server spelled it, the operator, the action in words, the tenant and the facts, N of M in the head', () => {
        inertia.props = propsWith([SUSPENDED, SIGNED_IN], { total: 75 });
        view.render(<Audit />);

        expect(q('h1')?.textContent).toBe('Audit');
        expect(texts('th')).toEqual(['When', 'Operator', 'Action', 'Tenant', 'Details']);
        expect(texts('.admin-audit__at')).toEqual([SUSPENDED.at, SIGNED_IN.at]);
        expect(texts('.admin-audit__operator')).toEqual(['Sam Operator', 'Pat Operator']);
        expect(texts('.admin-audit__action')).toEqual(['Suspended a tenant', 'Signed in']);
        expect(texts('.admin-audit__tenant')).toEqual(['acme']);
        expect(texts('.admin-audit__fact')).toEqual(['Reason: Unpaid since March', 'Features: staff, extra', 'User: 12']);
        expect(q('.admin-audit__count')?.textContent).toBe('2 of 75');
        expect(q('.admin-pagination')?.textContent).toContain(shell.text('kit.pagination.page', { page: '1', last: '2' }));
    });

    it('words every action of the vocabulary, one sentence each', () => {
        expect(Object.keys(ACTION_WORDS)).toEqual(['sign_in', 'sign_out', 'tenant_suspended', 'tenant_resumed', 'tenant_created', 'features_changed', 'password_link_sent', 'support_opened', 'support_ended', 'contact_archived', 'tenant_closed', 'tenant_reopened']);
        expect(new Set(Object.values(ACTION_WORDS)).size).toBe(12);
        expect(ACTION_WORDS.sign_in).toBe('Signed in');
        expect(ACTION_WORDS.sign_out).toBe('Signed out');
        expect(ACTION_WORDS.contact_archived).toBe('Archived a contact message');
        expect(ACTION_WORDS.tenant_closed).toBe('Closed a tenant');
        expect(ACTION_WORDS.tenant_reopened).toBe('Reopened a tenant');
    });

    it('words an archived contact message\'s fact as the message\'s id', () => {
        inertia.props = propsWith([{ id: 5, at: SIGNED_IN.at, operator: 'Pat Operator', action: 'contact_archived', tenant: null, facts: [{ key: 'message', value: 31 }] }]);
        view.render(<Audit />);

        expect(texts('.admin-audit__action')).toEqual(['Archived a contact message']);
        expect(texts('.admin-audit__fact')).toEqual(['Message: 31']);
        expect(q('.admin-audit__tenant')).toBeNull();
    });

    it('spells an empty list fact as none and a boolean as yes or no', () => {
        inertia.props = propsWith([{ ...SUSPENDED, facts: [{ key: 'features', value: [] }] }, { ...SIGNED_IN, id: 3, facts: [{ key: 'reason', value: true }] }]);
        view.render(<Audit />);

        expect(texts('.admin-audit__fact')).toEqual(['Features: none', 'Reason: yes']);
    });

    it('reads the next page as a partial reload of the list, the narrowing kept in the query', () => {
        inertia.props = propsWith([SUSPENDED], { tenant: 'acme', total: 120 });
        view.render(<Audit />);

        press(q('.admin-pagination__next'));

        expect(inertia.get).toHaveBeenCalledOnce();
        expect(inertia.get.mock.lastCall?.[0]).toBe('/audit');
        expect(inertia.get.mock.lastCall?.[1]).toEqual({ tenant: 'acme', page: '2' });
        expect(lastVisit().only).toEqual(['entries', 'filters']);
        expect(lastVisit().replace).toBe(true);
    });

    it('narrows the list to a row\'s tenant from its slug, on the first page', () => {
        inertia.props = propsWith([SUSPENDED, SIGNED_IN], { total: 2 });
        view.render(<Audit />);

        press(q('.admin-audit__tenant'));

        expect(inertia.get.mock.lastCall?.[0]).toBe('/audit');
        expect(inertia.get.mock.lastCall?.[1]).toEqual({ tenant: 'acme' });
    });

    it('says a narrowed list is narrowed and lifts it with Show every tenant, busy while the list reads', () => {
        inertia.props = propsWith([SUSPENDED], { tenant: 'acme' });
        view.render(<Audit />);

        expect(q('.admin-audit-narrowed p')?.textContent).toBe(narrowedTo('acme'));
        expect(q('.admin-audit-every')?.textContent).toBe(SHOW_EVERY_TENANT);

        press(q('.admin-audit-every'));

        expect(inertia.get.mock.lastCall?.[1]).toEqual({});

        act(() => lastVisit().onStart?.());

        expect(q('.admin-audit-every')?.getAttribute('aria-busy')).toBe('true');

        act(() => lastVisit().onFinish?.());

        expect(q('.admin-audit-every')?.getAttribute('aria-busy')).toBeNull();
    });

    it('shows no narrowing line for the whole list', () => {
        inertia.props = propsWith([SIGNED_IN]);
        view.render(<Audit />);

        expect(q('.admin-audit-narrowed')).toBeNull();
    });

    it('shows the empty panel once the list has settled empty, in words for the whole list and for one tenant', () => {
        inertia.props = propsWith([]);
        view.render(<Audit />);

        expect(q('.admin-audit-empty')?.textContent).toBe(NO_ACTIONS);
        expect(q('.admin-audit')).toBeNull();

        inertia.props = propsWith([], { tenant: 'acme' });
        view.render(<Audit />);

        expect(q('.admin-audit-empty')?.textContent).toBe(NO_TENANT_ACTIONS);
    });

    it('says a failed read in the screen\'s own words above the rows it keeps, and shows no empty panel', () => {
        inertia.props = propsWith([SIGNED_IN], { total: 60 });
        view.render(<Audit />);

        press(q('.admin-pagination__next'));
        act(() => lastVisit().onStart?.());
        fail();
        act(() => lastVisit().onFinish?.());

        expect(q('.admin-list-error')?.textContent).toBe(AUDIT_LIST_ERROR);
        expect(texts('.admin-audit__operator')).toEqual(['Pat Operator']);
        expect(q('.admin-audit-empty')).toBeNull();
    });
});
