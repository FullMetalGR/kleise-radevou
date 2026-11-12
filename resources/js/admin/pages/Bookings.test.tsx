import { act } from 'react';
import { afterEach, beforeEach, describe, expect, expectTypeOf, it, vi } from 'vitest';
import { mount, press, type Mounted } from '@/components/ui/harness';
import { type Booking, type Detail, NO_FILTERS } from '@/bookings/model';
import { KEYED_BOOKINGS, keyedBookings, keyedShell } from '@/testing/keyed';
import type { BookingsStrings } from '@/types';
import { ADMIN_LINES_EN } from '../lines.generated';
import Bookings from './Bookings';

/*
 * The Bookings screen's visits: a visit that fails answers in the screen's own words and never
 * falls through to the framework's error modal. The framework is stood in for by a router that
 * records each visit and the failure listeners registered around it. The page carries the keyed
 * bookings section in its strings prop, as BookingListController names it, so every word reads as
 * its key.
 */

const shell = keyedShell();
const words = keyedBookings();

type Visit = { onStart?: () => void; onFinish?: () => void };

const inertia = vi.hoisted(() => ({
    props: {} as Record<string, unknown>,
    get: vi.fn(),
    post: vi.fn(),
    listeners: new Map<string, (event: Event) => void>(),
}));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    usePage: () => ({ props: inertia.props }),
    router: {
        get: inertia.get,
        post: inertia.post,
        on: (name: string, listener: (event: Event) => void) => {
            inertia.listeners.set(name, listener);

            return () => inertia.listeners.delete(name);
        },
    },
}));

const BOOKING: Booking = {
    uuid: 'b1',
    status: 'confirmed',
    customer_name: 'Ada North',
    total_minor: 3500,
    currency: 'EUR',
    items: [{ sort: 0, service_id: 1, service_name: 'Haircut', staff_id: 2, staff_name: 'Elena Papadaki', start: '2026-11-03T08:00:00+00:00', end: '2026-11-03T08:45:00+00:00', price_minor: 3500 }],
};

function propsWith(detail: Detail | null): Record<string, unknown> {
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
        filters: { ...NO_FILTERS, page: 1 },
        options: { statuses: ['confirmed'], staff: [], services: [], members: 1, searchable: ['customer_name', 'customer_email'] },
        bookings: { rows: [BOOKING], total: 30, page: 1, page_size: 25 },
        detail,
        strings: { shell: {}, bookings: KEYED_BOOKINGS },
    };
}

/**
 * The last visit fails as the framework reports it: started, an invalid response announced to
 * whoever listens, finished. Answers whether a listener took the failure over.
 */
function failLastVisit(): boolean {
    const visit = inertia.get.mock.lastCall?.[2] as Visit | undefined;
    const event = new Event('invalid', { cancelable: true });

    act(() => visit?.onStart?.());
    act(() => inertia.listeners.get('invalid')?.(event));
    act(() => visit?.onFinish?.());

    return event.defaultPrevented;
}

let view: Mounted;

beforeEach(() => {
    window.sessionStorage.clear();
    inertia.get.mockReset();
    inertia.post.mockReset();
    inertia.listeners.clear();
    view = mount();
});

afterEach(() => view.unmount());

/** A section's type as the generated module spells it, its lines strings. */
type Shape<T> = { -readonly [K in keyof T]: T[K] extends string ? string : Shape<T[K]> };

describe('the bookings section', () => {
    it('is typed as the keys of lang/en/admin/bookings.php, no more and no fewer', () => {
        expectTypeOf<Shape<typeof ADMIN_LINES_EN.bookings>>().toEqualTypeOf<BookingsStrings>();
        expect(Object.keys(ADMIN_LINES_EN.bookings)).toEqual(['page', 'table', 'filters', 'customer_erased', 'acting', 'actions', 'busy', 'reject', 'cancel', 'move', 'dialog', 'trail', 'validation', 'flashes']);
    });
});

describe('the bookings screen', () => {
    it('heads itself, its list and the empty list in the bookings section\'s words, each as its key', () => {
        inertia.props = { ...propsWith(null), bookings: { rows: [], total: 0, page: 1, page_size: 25 } };
        view.render(<Bookings />);

        expect(view.host.querySelector('h1')?.textContent).toBe(words.text('page.title'));
        expect(view.host.querySelector('.admin-bookings-empty')?.textContent).toBe(words.text('page.empty'));
        expect(view.host.querySelector('.admin-approval-inbox')?.textContent).toBe(words.text('filters.inbox'));
    });

    it('reads a page of the list and its query by one partial reload at the screen\'s own path, the URL replaced', () => {
        inertia.props = propsWith(null);
        view.render(<Bookings />);

        press(view.host.querySelector('.admin-pagination__next'));

        expect(inertia.get.mock.lastCall?.[0]).toBe(window.location.pathname);
        expect(inertia.get.mock.lastCall?.[1]).toStrictEqual({ page: '2' });
        expect(inertia.get.mock.lastCall?.[2]).toMatchObject({ only: ['bookings', 'filters'], preserveState: true, preserveScroll: true, replace: true });
    });

    it('answers a failed page load in its own words', () => {
        inertia.props = propsWith(null);
        view.render(<Bookings />);

        press(view.host.querySelector('.admin-pagination__next'));
        expect(failLastVisit()).toBe(true);
        expect(view.host.querySelector('.admin-list-error')?.textContent).toContain(words.text('page.list_error'));
    });

    it('answers a failed open inside the dialog', () => {
        inertia.props = propsWith(null);
        view.render(<Bookings />);

        press(view.host.querySelector('.admin-bookings__row'));
        expect(failLastVisit()).toBe(true);
        expect(document.body.querySelector('.admin-booking-dialog [role="alert"]')?.textContent).toBe(words.text('dialog.load_error'));
    });

    it('answers a failed close inside the dialog it could not close, never through the framework modal', () => {
        inertia.props = propsWith({ state: 'found', booking: BOOKING, note: null, trail: [], actions: [] });
        view.render(<Bookings />);

        press(document.body.querySelector('.admin-booking-close'));
        expect(inertia.get.mock.lastCall?.[0]).toBe('/admin/bookings');
        expect(failLastVisit()).toBe(true);
        expect(document.body.querySelector('.admin-booking-dialog [role="alert"]')?.textContent).toBe(words.text('page.close_error'));
    });

    it('changes nothing on a press until the server answers: the booking stays as it was read and the pressed action shows busy', () => {
        inertia.props = propsWith({ state: 'found', booking: { ...BOOKING, status: 'awaiting_approval' }, note: null, trail: [], actions: ['approve', 'reject'] });
        view.render(<Bookings />);

        press(document.body.querySelector('.admin-booking-action--approve'));

        const [url, body, visit] = (inertia.post.mock.lastCall ?? []) as [string, Record<string, string>, Visit];

        expect(url).toBe('/admin/bookings/b1/approve');
        expect(body).toEqual({});

        // The answer is held: the visit has started and has not finished.
        act(() => visit.onStart?.());

        expect(document.body.querySelector('.admin-booking-facts .admin-chip')?.textContent).toBe(shell.text('kit.statuses.awaiting_approval'));
        expect(document.body.querySelector('.admin-booking-action--approve')?.getAttribute('aria-busy')).toBe('true');

        // A second press while the first is in flight sends nothing.
        press(document.body.querySelector('.admin-booking-action--reject'));
        press(document.body.querySelector('.admin-reject-prompt__confirm'));
        expect(inertia.post).toHaveBeenCalledTimes(1);

        act(() => visit.onFinish?.());
        expect(document.body.querySelector('.admin-booking-action--approve')?.hasAttribute('aria-busy')).toBe(false);
    });

    it('answers a failed action inside the dialog and keeps it open', () => {
        inertia.props = propsWith({ state: 'found', booking: { ...BOOKING, status: 'awaiting_approval' }, note: null, trail: [], actions: ['approve'] });
        view.render(<Bookings />);

        press(document.body.querySelector('.admin-booking-action--approve'));

        const visit = inertia.post.mock.lastCall?.[2] as Visit | undefined;
        const event = new Event('invalid', { cancelable: true });

        act(() => visit?.onStart?.());
        act(() => inertia.listeners.get('invalid')?.(event));
        act(() => visit?.onFinish?.());

        expect(event.defaultPrevented).toBe(true);
        expect(document.body.querySelector('.admin-booking-dialog [role="alert"]')?.textContent).toBe(words.text('acting.error'));
    });

    it('answers a refused reject inside the dialog in the refusal\'s own sentence, the envelope\'s detail', () => {
        inertia.props = propsWith({ state: 'found', booking: { ...BOOKING, status: 'awaiting_approval' }, note: null, trail: [], actions: ['reject'] });
        view.render(<Bookings />);

        press(document.body.querySelector('.admin-booking-action--reject'));
        press(document.body.querySelector('.admin-reject-prompt__confirm'));

        const visit = inertia.post.mock.lastCall?.[2] as Visit | undefined;
        const detail = 'the refusal\'s detail';
        const refusal = { code: 'invalid_request', message: 'invalid_request', data: { status: 400, detail } };
        const event = new CustomEvent('invalid', { cancelable: true, detail: { response: { status: 400, data: refusal } } });

        act(() => visit?.onStart?.());
        act(() => inertia.listeners.get('invalid')?.(event));
        act(() => visit?.onFinish?.());

        expect(event.defaultPrevented).toBe(true);
        expect(document.body.querySelector('.admin-booking-dialog [role="alert"]')?.textContent).toBe(detail);
    });

    it('answers a press outside the viewer\'s scope inside the dialog in its own sentence, the framework\'s 403 carrying none', () => {
        inertia.props = propsWith({ state: 'found', booking: { ...BOOKING, status: 'awaiting_approval' }, note: null, trail: [], actions: ['approve'] });
        view.render(<Bookings />);

        press(document.body.querySelector('.admin-booking-action--approve'));

        const visit = inertia.post.mock.lastCall?.[2] as Visit | undefined;
        const event = new CustomEvent('invalid', { cancelable: true, detail: { response: { status: 403, data: '<!DOCTYPE html><title>Forbidden</title>' } } });

        act(() => visit?.onStart?.());
        act(() => inertia.listeners.get('invalid')?.(event));
        act(() => visit?.onFinish?.());

        expect(document.body.querySelector('.admin-booking-dialog [role="alert"]')?.textContent).toBe(words.text('acting.forbidden'));
    });
});
