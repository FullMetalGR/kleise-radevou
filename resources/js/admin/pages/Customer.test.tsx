import { act } from 'react';
import { localeOf } from '../../booking/language';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { Booking, Detail } from '@/bookings/model';
import { mount, press, type Mounted } from '@/components/ui/harness';
import { ERASE_ERROR, ERASE_WHAT, type Erasure, ERASURE_COUNT, REFUSED_TITLE } from '@/customers/CustomerDangerZone';
import { EXPORT_ERROR, EXPORT_LABEL } from '@/customers/CustomerExport';
import { fileNameOf } from '@/lib/download';
import { type Block, type Customer, CUSTOMERS_TITLE, type Note, NO_VISIT, type Profile, type Spent } from '@/customers/model';
import { formatAmount } from '@/lib/format';
import { listOf } from '@/lib/names';
import { stringsOf } from '@/lib/strings';
import { KEYED_BOOKINGS, KEYED_CUSTOMERS, keyedBookings, keyedCustomers, keyedShell, WithShell } from '@/testing/keyed';
import type { CustomersStrings } from '@/types';
import { ADMIN_LINES_EN } from '../lines.generated';
import { ToastProvider } from '@/toast/toasts';
import CustomerPage, { BOOK_NOW_LABEL, BOOKINGS_EMPTY, BOOKINGS_ERROR, LEAVE_ERROR } from './Customer';

/*
 * A customer's page: the head, the address's bookings through the Bookings table, and the booking
 * dialog read as the calendars read it, by a partial reload of the detail alone with the URL
 * preserved; an action posts to the dialog's route, and the redirect's page, which lands with no
 * detail, is followed by one more read of the detail for the booking still open. Under the
 * bookings, the Danger zone: the card, the confirm naming the count, the refusal read off the
 * erasure prop, the busy state, and a refused POST's sentence. Beside the heading, the Export: the
 * button for manageBookings alone, busy while its fetch is on its way, the file handed to the
 * browser under the server's name, and a failure's toast. Under the head, the five tiles, and
 * beside Export its Book now, opening the wizard, stood in for by a recorder of each open, with
 * the customer. The framework is stood in for by a router that records each visit and the failure
 * listeners registered around it, and the network by a fetch the test answers by hand.
 */

const shell = keyedShell();

/** The bookings section the page carries beside the customer's words, as CustomerController names it. */
const words = keyedBookings();

/** The customers section the page reads its own words through. */
const own = keyedCustomers();

type Visit = { only?: string[]; preserveUrl?: boolean; preserveState?: boolean; replace?: boolean; onStart?: () => void; onSuccess?: () => void; onFinish?: () => void };

const inertia = vi.hoisted(() => ({
    props: {} as Record<string, unknown>,
    get: vi.fn(),
    post: vi.fn(),
    put: vi.fn(),
    delete: vi.fn(),
    reload: vi.fn(),
    listeners: new Map<string, (event: Event) => void>(),
}));

const wizard = vi.hoisted(() => ({ open: vi.fn() }));

vi.mock('@/wizard/NewBooking', () => ({ useNewBooking: () => wizard }));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    usePage: () => ({ props: inertia.props }),
    router: {
        get: inertia.get,
        post: inertia.post,
        put: inertia.put,
        delete: inertia.delete,
        reload: inertia.reload,
        on: (name: string, listener: (event: Event) => void) => {
            inertia.listeners.set(name, listener);

            return () => inertia.listeners.delete(name);
        },
    },
}));

const URL = '/admin/customers/casey%40example.com';

const CASEY: Customer = {
    name: 'Casey Customer',
    names: ['Casey Customer', 'Casey C.'],
    email: 'casey@example.com',
    phones: ['+30 210 7654321'],
    first_visit: '2026-11-03T08:00:00+00:00',
    last_visit: '2026-11-20T08:00:00+00:00',
    bookings: 2,
    upcoming: 1,
    completed: 3,
    no_shows: 0,
    cancelled: 1,
    url: URL,
    tags: ['VIP', 'colour'],
    blocked: false,
};

function booking(uuid: string, patch: Partial<Booking> = {}): Booking {
    return {
        uuid,
        status: 'confirmed',
        customer_name: 'Casey Customer',
        customer_email: 'casey@example.com',
        total_minor: 3500,
        currency: 'EUR',
        items: [{ sort: 0, service_id: 1, service_name: 'Haircut', staff_id: 2, staff_name: 'Elena Papadaki', start: '2026-11-20T08:00:00+00:00', end: '2026-11-20T08:45:00+00:00', price_minor: 3500 }],
        ...patch,
    };
}

const LATEST = booking('b2');

const FIRST = booking('b1', { customer_name: 'Casey C.', status: 'cancelled' });

function found(row: Booking, actions: string[]): Detail {
    return { state: 'found', booking: row, note: null, trail: [], actions };
}

const PROFILE: Profile = { tags: ['VIP', 'colour'], birthday: '1988-04-12', language: 'el' };

const NOTES: Note[] = [
    { id: 7, body: 'Prefers Elena. Allergic to ammonia colour.', author: 'Luna Owner', created_at: '2026-09-03T15:12:00+00:00' },
    { id: 3, body: 'Asked for a quote on a bridal package.', author: 'Elena Papadaki', created_at: '2026-07-14T08:40:00+00:00' },
];

const OPEN: Block = { blocked_at: null, reason: null };

function propsWith(detail: Detail | null, patch: { customer?: Customer; rows?: Booking[]; total?: number; permissions?: string[]; erasure?: Erasure; customers?: CustomersStrings; profile?: Profile; spent?: Spent[]; notes?: Note[]; block?: Block } = {}): Record<string, unknown> {
    const rows = patch.rows ?? [LATEST, FIRST];

    return {
        tenant: { name: 'Luna', slug: 'luna', timezone: 'Europe/Athens', currency: 'EUR' },
        auth: { user: { id: 1, name: 'Alex Owner', role: 'owner' } },
        flash: null,
        errors: {},
        permissions: patch.permissions ?? ['viewBookings', 'manageBookings'],
        entitlements: [],
        subscription: { state: 'active' },
        settings: { granularity: 15, week_start: 1, date_format: 'd/m/Y', time_format: 'H:i', messages: [] },
        nav: [],
        customer: patch.customer ?? CASEY,
        members: 1,
        bookings: { rows, total: patch.total ?? rows.length, page: 1, page_size: 25 },
        detail,
        erasure: patch.erasure ?? { refusal: null },
        profile: patch.profile ?? PROFILE,
        spent: patch.spent ?? [{ amount_minor: 123500, currency: 'EUR' }],
        earliest_birthday: '1900-01-01',
        notes: patch.notes ?? NOTES,
        block: patch.block ?? OPEN,
        strings: { shell: {}, bookings: KEYED_BOOKINGS, customers: patch.customers ?? KEYED_CUSTOMERS },
    };
}

function q(selector: string): Element | null {
    return document.body.querySelector(selector);
}

function lastGet(): [string, Record<string, string>, Visit] {
    return (inertia.get.mock.lastCall ?? []) as [string, Record<string, string>, Visit];
}

/** The visit in flight fails as the framework reports it; answers whether a listener took it over. */
function fail(visit: Visit): boolean {
    const event = new Event('invalid', { cancelable: true });

    act(() => visit.onStart?.());
    act(() => inertia.listeners.get('invalid')?.(event));
    act(() => visit.onFinish?.());

    return event.defaultPrevented;
}

let view: Mounted;

function render(): void {
    view.render(
        <ToastProvider>
            <CustomerPage />
        </ToastProvider>,
    );
}

beforeEach(() => {
    wizard.open.mockReset();
    inertia.get.mockReset();
    inertia.post.mockReset();
    inertia.put.mockReset();
    inertia.delete.mockReset();
    inertia.reload.mockReset();
    inertia.listeners.clear();
    view = mount();
});

afterEach(() => view.unmount());

describe('a customer\'s page', () => {
    it('is headed by the most recent name with the address under it, the bookings through the Bookings table, and reads nothing until asked', () => {
        inertia.props = propsWith(null, { total: 2 });
        render();

        expect(q('.admin-customer-head h1')?.textContent).toBe('Casey Customer');
        expect(q('.admin-customer-head__email')?.textContent).toBe('casey@example.com');
        expect([...view.host.querySelectorAll('.admin-customer-head__chips > *')].map((chip) => chip.textContent)).toEqual(['VIP', 'colour', own.text('table.also_booked_as', { names: 'Casey C.' })]);
        expect(q('.admin-customer-head__email + .admin-customer-head__chips')).not.toBeNull();
        expect(q('.admin-customer-head__also')?.classList.contains('admin-chip--past')).toBe(true);
        expect(q('.admin-breadcrumb__list')?.textContent).toBe(own.text(CUSTOMERS_TITLE));
        expect(q('.admin-breadcrumb__current')?.textContent).toBe('Casey Customer');
        expect([...view.host.querySelectorAll('.admin-bookings .admin-row-select')].map((button) => button.textContent)).toEqual(['Casey Customer', 'Casey C.']);
        // The customer's own name in plain text on their page.
        expect([...view.host.querySelectorAll('.admin-bookings .admin-row-select')].every((button) => button.classList.contains('admin-row-select--plain'))).toBe(true);
        expect(q('.admin-bookings__count')?.textContent).toBe(words.text('table.count', { count: '2', total: '2' }));
        expect(q('.admin-booking-dialog')).toBeNull();
        expect(inertia.get).not.toHaveBeenCalled();
    });

    it('shows At a glance as one card beside the head, its five figures in a row, the last visit as its day in the tenant\'s zone and format or No visit yet, and the Spent line with what Book now fills in', () => {
        inertia.props = propsWith(null, { customer: { ...CASEY, last_visit: '2026-11-19T22:30:00+00:00' }, spent: [{ amount_minor: 123500, currency: 'EUR' }, { amount_minor: 1200, currency: 'USD' }] });
        render();

        const figures = () => [...view.host.querySelectorAll('.admin-customer-glance__figure')].map((figure) => [figure.querySelector('span')?.textContent, figure.querySelector('strong')?.textContent]);

        expect(view.host.querySelectorAll('.admin-customer-glance')).toHaveLength(1);
        expect(q('.admin-customer-glance h2')?.textContent).toBe(own.text('tiles.title'));
        expect(q('.admin-customer-head + .admin-customer-cards > .admin-customer-glance:first-child')).not.toBeNull();
        expect(figures()).toEqual([
            [own.text('tiles.upcoming'), '1'],
            [own.text('tiles.completed'), '3'],
            [own.text('tiles.no_shows'), '0'],
            [own.text('tiles.cancelled'), '1'],
            [own.text('tiles.last_visit'), '20/11/2026'],
        ]);
        expect(q('.admin-customer-glance__spent')?.textContent).toBe(`${own.text('tiles.spent', { amount: listOf([formatAmount(123500, 'EUR', localeOf('en')), formatAmount(1200, 'USD', localeOf('en'))], 'en') })} ${own.text('tiles.book_now_help')}`);

        view.unmount();
        view = mount();
        inertia.props = propsWith(null, { customer: { ...CASEY, last_visit: null }, spent: [] });
        render();

        expect(figures()[4]).toEqual([own.text('tiles.last_visit'), own.text(NO_VISIT)]);
        expect(q('.admin-customer-glance__spent')?.textContent).toBe(`${own.text('tiles.spent_none')} ${own.text('tiles.book_now_help')}`);
    });

    it('spells At a glance\'s counts and amounts in the user\'s language, grouped as the language groups them, the amounts listed as it lists them', () => {
        const spent = [{ amount_minor: 123500, currency: 'EUR' }, { amount_minor: 1200, currency: 'USD' }];
        const counts = () => [...view.host.querySelectorAll('.admin-customer-glance__figure strong')].slice(0, 4).map((figure) => figure.textContent);

        inertia.props = propsWith(null, { customer: { ...CASEY, completed: 1234, upcoming: 12345 }, spent });
        view.render(
            <WithShell language="el">
                <ToastProvider>
                    <CustomerPage />
                </ToastProvider>
            </WithShell>,
        );

        const greek = new Intl.NumberFormat(localeOf('el'));

        expect(counts()).toEqual([greek.format(12345), greek.format(1234), greek.format(0), greek.format(1)]);
        expect(counts()[1]).not.toBe('1,234');
        expect(q('.admin-customer-glance__spent')?.textContent).toBe(
            `${own.text('tiles.spent', { amount: listOf([formatAmount(123500, 'EUR', localeOf('el')), formatAmount(1200, 'USD', localeOf('el'))], 'el') })} ${own.text('tiles.book_now_help')}`,
        );
        expect(q('.admin-customer-glance__spent')?.textContent).not.toContain(formatAmount(123500, 'EUR', localeOf('en')));
    });

    it('leaves the chips row out where the address has no tags and no other name', () => {
        inertia.props = propsWith(null, { customer: { ...CASEY, names: ['Casey Customer'], tags: [] } });
        render();

        expect(q('.admin-customer-head__chips')).toBeNull();
    });

    it('opens the wizard from Book now after Export with the most recent name, the email and the most recent phone, and with no phone as blank', () => {
        inertia.props = propsWith(null, { customer: { ...CASEY, phones: ['+30 210 7654321', '+30 210 1111111'] } });
        render();

        const book = q('.admin-customer-book');
        expect(book?.textContent).toBe(own.text(BOOK_NOW_LABEL));
        expect(book?.previousElementSibling?.classList.contains('admin-customer-export')).toBe(true);
        expect(wizard.open).not.toHaveBeenCalled();

        press(book);

        expect(wizard.open).toHaveBeenCalledTimes(1);
        expect(wizard.open).toHaveBeenLastCalledWith({ customer: { name: 'Casey Customer', email: 'casey@example.com', phone: '+30 210 7654321' } });

        view.unmount();
        view = mount();
        inertia.props = propsWith(null, { customer: { ...CASEY, phones: [] } });
        render();
        press(q('.admin-customer-book'));

        expect(wizard.open).toHaveBeenLastCalledWith({ customer: { name: 'Casey Customer', email: 'casey@example.com', phone: '' } });
        expect(inertia.get).not.toHaveBeenCalled();
    });

    it('leaves the address out of the head where the server withheld it', () => {
        const withheld: Customer = { name: CASEY.name, names: CASEY.names, first_visit: CASEY.first_visit, last_visit: CASEY.last_visit, bookings: CASEY.bookings, upcoming: 1, completed: 3, no_shows: 0, cancelled: 1, url: CASEY.url, tags: [], blocked: false };

        inertia.props = propsWith(null, { customer: withheld });
        render();

        expect(q('.admin-customer-head h1')?.textContent).toBe('Casey Customer');
        expect(q('.admin-customer-head__email')).toBeNull();
    });

    it('opens the dialog by a partial reload of the detail alone with the URL preserved, loading until the detail answers, and closes it with no visit', () => {
        inertia.props = propsWith(null);
        render();

        press(view.host.querySelector('.admin-bookings .admin-row-select'));

        const [url, query, visit] = lastGet();

        expect(inertia.get).toHaveBeenCalledTimes(1);
        expect(url).toBe(URL);
        expect(query).toEqual({ booking: 'b2' });
        expect(visit).toMatchObject({ only: ['detail'], preserveUrl: true, preserveState: true });
        expect(q(`.admin-booking-dialog [aria-label="${words.text('dialog.loading')}"]`)).not.toBeNull();

        inertia.props = propsWith(found(LATEST, ['cancel']));
        render();

        expect(q('.admin-modal__title')?.textContent).toBe('Casey Customer');
        expect(q('.admin-booking-action--cancel')).not.toBeNull();
        expect(view.host.querySelector('.admin-bookings .admin-row-select')?.getAttribute('aria-current')).toBe('true');
        expect(inertia.get).toHaveBeenCalledTimes(1);

        press(q('.admin-booking-close'));
        expect(q('.admin-booking-dialog')).toBeNull();
        expect(inertia.get).toHaveBeenCalledTimes(1);
    });

    it('answers a failed open inside the dialog, never through the framework modal', () => {
        inertia.props = propsWith(null);
        render();

        press(view.host.querySelector('.admin-bookings .admin-row-select'));
        expect(fail(lastGet()[2])).toBe(true);
        expect(q('.admin-booking-dialog [role="alert"]')?.textContent).toBe(words.text('dialog.load_error'));
    });

    it('posts an action to the dialog\'s route, changes nothing until the server answers, and reads the open booking again after the redirect lands the page with no detail', () => {
        inertia.props = propsWith(null);
        render();
        press(view.host.querySelector('.admin-bookings .admin-row-select'));
        inertia.props = propsWith(found(LATEST, ['cancel']));
        render();

        press(q('.admin-booking-action--cancel'));
        press(q('.admin-cancel-prompt__confirm'));

        const [url, body, visit] = (inertia.post.mock.lastCall ?? []) as [string, Record<string, string>, Visit];

        expect(url).toBe('/admin/bookings/b2/cancel');
        expect(body).toEqual({});
        act(() => visit.onStart?.());
        expect(q('.admin-booking-facts .admin-chip')?.textContent).toBe(shell.text('kit.statuses.confirmed'));
        expect(q('.admin-cancel-prompt__confirm')?.getAttribute('aria-busy')).toBe('true');

        // The redirect back lands this page with every prop re-read and no detail asked for.
        inertia.props = propsWith(null, { rows: [{ ...LATEST, status: 'cancelled' }, FIRST] });
        render();
        act(() => visit.onFinish?.());

        const [again, query, read] = lastGet();

        expect(inertia.get).toHaveBeenCalledTimes(2);
        expect(again).toBe(URL);
        expect(query).toEqual({ booking: 'b2' });
        expect(read).toMatchObject({ only: ['detail'], preserveUrl: true });
        expect(view.host.querySelector('.admin-bookings__row .admin-chip')?.textContent).toBe(shell.text('kit.statuses.cancelled'));

        inertia.props = propsWith(found({ ...LATEST, status: 'cancelled' }, []), { rows: [{ ...LATEST, status: 'cancelled' }, FIRST] });
        render();

        expect(q('.admin-booking-facts .admin-chip')?.textContent).toBe(shell.text('kit.statuses.cancelled'));
        expect(q('.admin-booking-actions')).toBeNull();
    });

    it('pages by a partial reload of the bookings alone, the page in the URL, and answers a failed page in its own words', () => {
        inertia.props = propsWith(null, { total: 30 });
        render();

        press(view.host.querySelector('.admin-pagination__next'));

        const [url, query, visit] = lastGet();

        expect(url).toBe(URL);
        expect(query).toEqual({ page: '2' });
        expect(visit).toMatchObject({ only: ['bookings'], replace: true, preserveState: true });
        expect(visit.preserveUrl).toBeUndefined();
        expect(fail(visit)).toBe(true);
        expect(q('.admin-list-error')?.textContent).toBe(own.text(BOOKINGS_ERROR));
    });

    it('renders the empty panel for a page past the last once settled', () => {
        inertia.props = propsWith(null, { rows: [], total: 2 });
        render();

        expect(q('.admin-customer-empty')?.textContent).toBe(own.text(BOOKINGS_EMPTY));
        expect(q('.admin-bookings')).toBeNull();
    });

    it('goes back to the list from the breadcrumb, a failure in its own words', () => {
        inertia.props = propsWith(null);
        render();

        press(q('.admin-breadcrumb__list'));
        expect(lastGet()[0]).toBe('/admin/customers');
        expect(fail(lastGet()[2])).toBe(true);
        expect(q('.admin-customer-leave-error')?.textContent).toBe(own.text(LEAVE_ERROR));
    });
});

/** The refusal the server words, a neutral token: the page shows it as it came. */
const LIVE = 'the-server-refusal-for-live-bookings';

function lastPost(): [string, Record<string, string>, Visit] {
    return (inertia.post.mock.lastCall ?? []) as [string, Record<string, string>, Visit];
}

/** The visit in flight fails with the response given, as the framework reports a non-page answer. */
function refuse(visit: Visit, response: unknown): void {
    const event = new CustomEvent('invalid', { cancelable: true, detail: { response } });

    act(() => visit.onStart?.());
    act(() => inertia.listeners.get('invalid')?.(event));
    act(() => visit.onFinish?.());
}

describe('a customer\'s Danger zone', () => {
    it('sits under the bookings with the sentence saying what goes and what stays and Erase this customer, for a viewer holding manageBookings alone', () => {
        inertia.props = propsWith(null);
        render();

        const zone = q('.admin-customer-danger');
        const table = q('.admin-bookings');

        expect(zone?.querySelector('h2')?.textContent).toBe(own.text('erasure.title'));
        expect(zone?.querySelector('.admin-customer-danger__what')?.textContent).toBe(own.text(ERASE_WHAT));
        expect(zone?.querySelector('.admin-customer-erase')?.textContent).toBe(own.text('erasure.erase'));
        expect(zone?.querySelector('.admin-customer-erase')?.className).toContain('admin-button--destructive');
        expect(table !== null && zone !== null && (table.compareDocumentPosition(zone) & Node.DOCUMENT_POSITION_FOLLOWING) !== 0).toBe(true);
        expect(q('.admin-erase-prompt')).toBeNull();

        inertia.props = propsWith(null, { permissions: ['viewBookings', 'approveBookings'] });
        render();

        expect(q('.admin-customer-danger')).toBeNull();
        expect(q('.admin-customer-erase')).toBeNull();
    });

    it('opens the confirm naming the count of bookings that lose the person, focus on Keep it, and closes it with no visit', () => {
        inertia.props = propsWith(null);
        render();
        press(q('.admin-customer-erase'));

        expect(q('.admin-modal__title')?.textContent).toBe(own.text('erasure.confirm_title'));
        expect(q('.admin-erase-prompt__count')?.textContent).toBe(own.choice(ERASURE_COUNT, 2));
        expect(document.activeElement?.className).toContain('admin-erase-prompt__keep');
        expect(q('.admin-erase-prompt__confirm')?.textContent).toBe(own.text('erasure.confirm'));

        press(q('.admin-erase-prompt__keep'));

        expect(q('.admin-erase-prompt')).toBeNull();
        expect(inertia.post).not.toHaveBeenCalled();

        inertia.props = propsWith(null, { customer: { ...CASEY, bookings: 1 } });
        render();
        press(q('.admin-customer-erase'));

        expect(q('.admin-erase-prompt__count')?.textContent).toBe(own.choice(ERASURE_COUNT, 1));
    });

    it('words the confirm\'s body as one choice by the count, the file\'s own case at one booking and at more than one', () => {
        const english = stringsOf<CustomersStrings>(ADMIN_LINES_EN.customers, 'en');

        for (const bookings of [1, 2, 5]) {
            view.unmount();
            view = mount();
            inertia.props = propsWith(null, { customer: { ...CASEY, bookings }, customers: ADMIN_LINES_EN.customers });
            render();
            press(q('.admin-customer-erase'));

            expect(q('.admin-erase-prompt__count')?.textContent).toBe(english.choice(ERASURE_COUNT, bookings));
        }

        expect(english.choice(ERASURE_COUNT, 1)).not.toBe(english.choice(ERASURE_COUNT, 2));
        expect(english.choice(ERASURE_COUNT, 1)).not.toContain('|');
        expect(english.choice(ERASURE_COUNT, 5)).toContain('5');
    });

    it('posts the erasure under the customer\'s page once confirmed, busy and undismissable until the server answers, nothing on the page changed meanwhile', () => {
        inertia.props = propsWith(null);
        render();
        press(q('.admin-customer-erase'));
        press(q('.admin-erase-prompt__confirm'));

        const [url, body, visit] = lastPost();

        expect(inertia.post).toHaveBeenCalledTimes(1);
        expect(url).toBe(`${URL}/erase`);
        expect(body).toEqual({});

        act(() => visit.onStart?.());

        expect(q('.admin-erase-prompt__confirm')?.getAttribute('aria-busy')).toBe('true');
        expect(q('.admin-erase-prompt__confirm')?.textContent).toContain(own.text('erasure.busy'));
        expect(q('.admin-erase-prompt__keep')?.hasAttribute('disabled')).toBe(true);
        expect(q('.admin-erase-prompt__pending')?.textContent).toBe(own.text('erasure.pending'));
        expect([...view.host.querySelectorAll('.admin-bookings .admin-row-select')].map((button) => button.textContent)).toEqual(['Casey Customer', 'Casey C.']);

        press(q('.admin-erase-prompt__keep'));
        expect(q('.admin-erase-prompt')).not.toBeNull();

        act(() => visit.onFinish?.());

        expect(q('.admin-erase-prompt__confirm')?.getAttribute('aria-busy')).toBeNull();
        expect(inertia.reload).not.toHaveBeenCalled();
    });

    it('shows the server\'s refusal in place of the confirm while a booking is live: the sentence naming the count, nothing to confirm, and Close', () => {
        inertia.props = propsWith(null, { erasure: { refusal: LIVE } });
        render();
        press(q('.admin-customer-erase'));

        expect(q('.admin-modal__title')?.textContent).toBe(own.text(REFUSED_TITLE));
        expect(q('.admin-erase-refused__sentence')?.textContent).toBe(LIVE);
        expect(q('.admin-erase-prompt__confirm')).toBeNull();

        press(q('.admin-erase-refused__close'));

        expect(q('.admin-erase-refused')).toBeNull();
        expect(inertia.post).not.toHaveBeenCalled();
    });

    it('answers a refused POST with its sentence in an error toast, the dialog left open, then reads the erasure prop again, never the framework modal', () => {
        inertia.props = propsWith(null);
        render();
        press(q('.admin-customer-erase'));
        press(q('.admin-erase-prompt__confirm'));
        refuse(lastPost()[2], { status: 409, data: { code: 'live_bookings', message: 'live_bookings', data: { status: 409, detail: LIVE } } });

        expect(q('.admin-toast')?.getAttribute('role')).toBe('alert');
        expect(q('.admin-toast')?.textContent).toContain(LIVE);
        expect(q('.admin-erase-prompt')).not.toBeNull();
        expect(inertia.reload).toHaveBeenCalledTimes(1);
        expect(inertia.reload.mock.lastCall?.[0]).toMatchObject({ only: ['erasure'] });

        // The re-read lands the refusal, and the open dialog now says what the server says.
        inertia.props = propsWith(null, { erasure: { refusal: LIVE } });
        render();

        expect(q('.admin-erase-refused__sentence')?.textContent).toBe(LIVE);
    });

    it('words a failed POST with no answer in its own sentence', () => {
        inertia.props = propsWith(null);
        render();
        press(q('.admin-customer-erase'));
        press(q('.admin-erase-prompt__confirm'));

        expect(fail(lastPost()[2])).toBe(true);
        expect(q('.admin-toast')?.textContent).toContain(own.text(ERASE_ERROR));
    });
});

const FILE = 'customer-luna-2026-11-02.json';

/** A file the export answers, as the server sends it. */
function exported(): Response {
    return new Response('{"business": "Luna"}', { status: 200, headers: { 'Content-Type': 'application/json; charset=UTF-8', 'Content-Disposition': `attachment; filename=${FILE}` } });
}

/** Lets the fetch's answer and every step after it run, inside act. */
async function settle(): Promise<void> {
    for (let turn = 0; turn < 3; turn++) {
        await act(async () => {
            await new Promise((resolve) => setTimeout(resolve, 0));
        });
    }
}

describe('a customer\'s Export', () => {
    let answer: (response: Response) => void;
    let refuse: (failure: Error) => void;
    let fetched: ReturnType<typeof vi.fn>;
    let saved: { href: string; download: string }[];
    const created = vi.fn((_file: Blob) => 'blob:export');
    const revoked = vi.fn();

    beforeEach(() => {
        fetched = vi.fn(
            () =>
                new Promise<Response>((resolve, reject) => {
                    answer = resolve;
                    refuse = reject;
                }),
        );
        vi.stubGlobal('fetch', fetched);
        saved = [];
        created.mockClear();
        revoked.mockClear();
        Object.defineProperty(globalThis.URL, 'createObjectURL', { value: created, configurable: true });
        Object.defineProperty(globalThis.URL, 'revokeObjectURL', { value: revoked, configurable: true });
        vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function click(this: HTMLAnchorElement) {
            saved.push({ href: this.getAttribute('href') ?? '', download: this.download });
        });
    });

    afterEach(() => {
        vi.unstubAllGlobals();
        vi.restoreAllMocks();
    });

    it('sits in the head beside the heading as a secondary button for a viewer holding manageBookings alone, and fetches nothing until pressed', () => {
        inertia.props = propsWith(null);
        render();

        const button = q('.admin-customer-head .admin-customer-export');

        expect(button?.textContent).toBe(own.text(EXPORT_LABEL));
        expect(button?.className).toContain('admin-button--secondary');
        expect(button?.className).not.toContain('admin-button--destructive');
        expect(fetched).not.toHaveBeenCalled();

        inertia.props = propsWith(null, { permissions: ['viewBookings', 'approveBookings'] });
        render();

        expect(q('.admin-customer-export')).toBeNull();
        expect(q('.admin-customer-book')).toBeNull();
        expect(q('.admin-customer-head h1')?.textContent).toBe('Casey Customer');
    });

    it('is busy while the request is on its way, asking for JSON, then hands the file to the browser under the name the server gave it', async () => {
        inertia.props = propsWith(null);
        render();
        press(q('.admin-customer-export'));

        expect(fetched).toHaveBeenCalledTimes(1);
        expect(fetched.mock.lastCall?.[0]).toBe(`${URL}/export`);
        expect(fetched.mock.lastCall?.[1]).toMatchObject({ headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        expect(q('.admin-customer-export')?.getAttribute('aria-busy')).toBe('true');
        expect(q('.admin-customer-export')?.textContent).toContain(own.text('export.busy'));
        expect(q('.admin-customer-export')?.hasAttribute('disabled')).toBe(true);

        // A second press while busy asks nothing more.
        press(q('.admin-customer-export'));
        expect(fetched).toHaveBeenCalledTimes(1);
        expect(saved).toEqual([]);

        answer(exported());
        await settle();

        expect(created).toHaveBeenCalledTimes(1);
        expect(await created.mock.lastCall?.[0].text()).toBe('{"business": "Luna"}');
        expect(saved).toEqual([{ href: 'blob:export', download: FILE }]);
        expect(revoked).toHaveBeenCalledWith('blob:export');
        expect(document.body.querySelector('a[download]')).toBeNull();
        expect(q('.admin-customer-export')?.getAttribute('aria-busy')).toBeNull();
        expect(q('.admin-customer-export')?.textContent).toBe(own.text(EXPORT_LABEL));
        expect(q('.admin-toast')).toBeNull();
    });

    it('answers a refusal with the envelope\'s sentence where the server worded one and its own otherwise, an error toast that stays, saving nothing', async () => {
        inertia.props = propsWith(null);
        render();

        press(q('.admin-customer-export'));
        answer(new Response(JSON.stringify({ code: 'busy', message: 'busy', data: { status: 503, detail: 'Too many people are booking right now. Please try again.' } }), { status: 503, headers: { 'Content-Type': 'application/json' } }));
        await settle();

        expect(q('.admin-toast')?.getAttribute('role')).toBe('alert');
        expect(q('.admin-toast')?.textContent).toContain('Too many people are booking right now. Please try again.');
        expect(q('.admin-customer-export')?.getAttribute('aria-busy')).toBeNull();

        press(q('.admin-customer-export'));
        answer(new Response(JSON.stringify({ message: 'Not Found' }), { status: 404, headers: { 'Content-Type': 'application/json' } }));
        await settle();

        expect([...document.body.querySelectorAll('.admin-toast')].map((toast) => toast.textContent).at(-1)).toContain(own.text(EXPORT_ERROR));
        expect(saved).toEqual([]);
        expect(created).not.toHaveBeenCalled();
    });

    it('saves nothing from an answer that is not the JSON file, a sign-in page among them, nor from no answer at all, and says so in its own sentence', async () => {
        inertia.props = propsWith(null);
        render();

        press(q('.admin-customer-export'));
        answer(new Response('<!doctype html><html><body><h1>Sign in</h1></body></html>', { status: 200, headers: { 'Content-Type': 'text/html; charset=UTF-8' } }));
        await settle();

        expect(q('.admin-toast')?.textContent).toContain(own.text(EXPORT_ERROR));

        press(q('.admin-customer-export'));
        refuse(new TypeError('Failed to fetch'));
        await settle();

        expect(document.body.querySelectorAll('.admin-toast')).toHaveLength(2);
        expect([...document.body.querySelectorAll('.admin-toast')].map((toast) => toast.textContent).at(-1)).toContain(own.text(EXPORT_ERROR));
        expect(saved).toEqual([]);
        expect(q('.admin-customer-export')?.getAttribute('aria-busy')).toBeNull();
    });

    it('reads the file name off the disposition, quoted or not, and none where it names none', () => {
        expect(fileNameOf(`attachment; filename=${FILE}`)).toBe(FILE);
        expect(fileNameOf(`attachment; filename="${FILE}"`)).toBe(FILE);
        expect(fileNameOf('attachment')).toBeNull();
        expect(fileNameOf(null)).toBeNull();
    });
});

/** The Profile card's last save, as the page sent it. */
function lastPut(): [string, Record<string, unknown>, Visit] {
    return (inertia.put.mock.lastCall ?? []) as [string, Record<string, unknown>, Visit];
}

/** Sets a text field's value as a person types it, through React's own value setter. */
function typeInto(selector: string, text: string): void {
    const input = q(selector) as HTMLInputElement;
    const setter = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')?.set;

    act(() => {
        setter?.call(input, text);
        input.dispatchEvent(new Event('input', { bubbles: true }));
    });
}

/** Picks an option of a native select as a person does. */
function choose(selector: string, value: string): void {
    const select = q(selector) as HTMLSelectElement;
    const setter = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'value')?.set;

    act(() => {
        setter?.call(select, value);
        select.dispatchEvent(new Event('change', { bubbles: true }));
    });
}

/**
 * Picks a birthday on the Birthday field's calendar as a person reaches one decades back: opens
 * it, chooses the year and the month (0 for January) from its dropdowns, presses the day.
 */
function pickBirthday(year: string, month: string, date: string): void {
    press(q('#customer-birthday'));
    choose(`.admin-date-field__popover select[aria-label="${shell.text('kit.month_grid.year')}"]`, year);
    choose(`.admin-date-field__popover select[aria-label="${shell.text('kit.month_grid.month')}"]`, month);
    press(q(`.admin-date-field__popover button[data-date="${date}"]`));
}

describe('a customer\'s Profile', () => {
    it('reaches a birthday decades back by the year and month dropdowns, from the earliest the server takes to today, and saves it as a machine date', () => {
        inertia.props = propsWith(null, { profile: { tags: [], birthday: null, language: null } });
        render();

        press(q('#customer-birthday'));
        const years = [...document.body.querySelectorAll<HTMLOptionElement>(`.admin-date-field__popover select[aria-label="${shell.text('kit.month_grid.year')}"] option`)].map((option) => option.value);
        expect(years[0]).toBe('1900');
        expect(Number(years.at(-1))).toBeGreaterThanOrEqual(2026);
        press(q('#customer-birthday'));

        pickBirthday('1942', '5', '1942-06-15');
        expect(q('#customer-birthday')?.textContent).toBe('15/06/1942');
        press(q('.admin-customer-profile__save'));

        expect(lastPut()[1]).toEqual({ tags: '', birthday: '1942-06-15', language: null });
    });

    it('sits beside At a glance with Tags and its help, Birthday on the admin\'s date field and Preferred language of three choices, each filled from the profile, and Save', () => {
        inertia.props = propsWith(null);
        render();

        const card = q('.admin-customer-cards > .admin-customer-profile');

        expect(card).not.toBeNull();
        expect(card?.previousElementSibling?.classList.contains('admin-customer-glance')).toBe(true);
        expect(card?.querySelector('h2')?.textContent).toBe(own.text('profile.title'));
        expect(q('label[for="customer-tags"]')?.textContent).toBe(own.text('profile.tags'));
        expect((q('#customer-tags') as HTMLInputElement).value).toBe('VIP, colour');
        expect(q('#customer-tags-help')?.textContent).toBe(own.text('profile.tags_help'));
        expect(q('label[for="customer-birthday"]')?.textContent).toBe(own.text('profile.birthday'));
        // The admin's own date field in the tenant's date format, never the browser's native
        // control.
        expect(q('#customer-birthday')?.tagName).toBe('BUTTON');
        expect(q('#customer-birthday')?.classList.contains('admin-date-field__trigger')).toBe(true);
        expect((q('#customer-birthday') as HTMLButtonElement).value).toBe('1988-04-12');
        expect(q('#customer-birthday')?.textContent).toBe('12/04/1988');
        expect(q('label[for="customer-language"]')?.textContent).toBe(own.text('profile.language'));
        expect([...view.host.querySelectorAll('#customer-language option')].map((option) => [(option as HTMLOptionElement).value, option.textContent])).toEqual([
            ['', own.text('profile.not_recorded')],
            ['el', own.text('profile.greek')],
            ['en', own.text('profile.english')],
        ]);
        expect((q('#customer-language') as HTMLSelectElement).value).toBe('el');
        expect(q('.admin-customer-profile__save')?.textContent).toBe(own.text('profile.save'));
        expect(inertia.put).not.toHaveBeenCalled();
    });

    it('reads an address with no profile as empty fields and Not recorded', () => {
        inertia.props = propsWith(null, { profile: { tags: [], birthday: null, language: null } });
        render();

        expect((q('#customer-tags') as HTMLInputElement).value).toBe('');
        expect((q('#customer-birthday') as HTMLButtonElement).value).toBe('');
        expect(q('#customer-birthday')?.textContent).toBe('');
        expect((q('#customer-language') as HTMLSelectElement).value).toBe('');
    });

    it('saves the whole form in one request to the customer\'s profile route, the tags as typed, busy until the server answers, nothing on the page changed meanwhile', () => {
        inertia.props = propsWith(null);
        render();

        typeInto('#customer-tags', 'VIP, colour, regular');
        pickBirthday('1979', '11', '1979-12-31');
        expect(q('#customer-birthday')?.textContent).toBe('31/12/1979');
        choose('#customer-language', '');
        press(q('.admin-customer-profile__save'));

        expect(inertia.put).toHaveBeenCalledTimes(1);
        const [url, body, visit] = lastPut();
        expect(url).toBe(`${URL}/profile`);
        expect(body).toEqual({ tags: 'VIP, colour, regular', birthday: '1979-12-31', language: null });
        expect(visit).toMatchObject({ preserveScroll: true, preserveState: true });

        act(() => visit.onStart?.());
        expect(q('.admin-customer-profile__save')?.textContent).toBe(own.text('profile.saving'));
        expect((q('.admin-customer-profile__save') as HTMLButtonElement).disabled).toBe(true);
        expect([...view.host.querySelectorAll('.admin-customer-head__chips .admin-tag')].map((chip) => chip.textContent)).toEqual(['VIP', 'colour']);

        act(() => visit.onSuccess?.());
        act(() => visit.onFinish?.());
        expect((q('.admin-customer-profile__save') as HTMLButtonElement).disabled).toBe(false);
    });

    it('follows the profile the redirect lands, the fields reading what the server stored', () => {
        inertia.props = propsWith(null);
        render();
        typeInto('#customer-tags', ' VIP ,, vip, regular ');
        press(q('.admin-customer-profile__save'));

        inertia.props = propsWith(null, { profile: { tags: ['VIP', 'regular'], birthday: null, language: 'en' }, customer: { ...CASEY, tags: ['VIP', 'regular'] } });
        render();

        expect((q('#customer-tags') as HTMLInputElement).value).toBe('VIP, regular');
        expect((q('#customer-language') as HTMLSelectElement).value).toBe('en');
        expect([...view.host.querySelectorAll('.admin-customer-head__chips .admin-tag')].map((chip) => chip.textContent)).toEqual(['VIP', 'regular']);
    });

    it('answers a refused save with the server\'s sentence in an error toast, the typed words kept, and a save with no answer in its own sentence', () => {
        inertia.props = propsWith(null);
        render();
        typeInto('#customer-tags', 'a, b, c, d, e, f, g, h, i, j, k');
        press(q('.admin-customer-profile__save'));
        refuse(lastPut()[2], { status: 400, data: { code: 'validation_failed', message: 'validation_failed', data: { status: 400, detail: 'tags-refusal-token' } } });

        expect(q('.admin-toast')?.textContent).toContain('tags-refusal-token');
        expect((q('#customer-tags') as HTMLInputElement).value).toBe('a, b, c, d, e, f, g, h, i, j, k');

        press(q('.admin-customer-profile__save'));
        expect(fail(lastPut()[2])).toBe(true);
        expect(document.body.textContent).toContain(own.text('profile.error'));
    });

    it('is not drawn for a viewer without manageBookings', () => {
        inertia.props = propsWith(null, { permissions: ['viewBookings'] });
        render();

        expect(q('.admin-customer-profile')).toBeNull();
        expect(q('.admin-customer-glance')).not.toBeNull();
    });
});

/** A visit's last call on the given router method, as the page sent it. */
function lastOf(method: 'post' | 'delete' | 'put'): [string, Record<string, unknown>, Visit] {
    return (inertia[method].mock.lastCall ?? []) as [string, Record<string, unknown>, Visit];
}

/** The router's delete takes the URL and the options alone. */
function lastDelete(): [string, Visit] {
    return (inertia.delete.mock.lastCall ?? []) as [string, Visit];
}

/** Sets a textarea's value as a person types it, through React's own value setter. */
function typeArea(selector: string, text: string): void {
    const area = q(selector) as HTMLTextAreaElement;
    const setter = Object.getOwnPropertyDescriptor(HTMLTextAreaElement.prototype, 'value')?.set;

    act(() => {
        setter?.call(area, text);
        area.dispatchEvent(new Event('input', { bubbles: true }));
    });
}

describe('a customer\'s Notes', () => {
    it('sits after Profile with New note, its help, Add note, and the notes beneath newest first as the server sent them, each its text, its author and day and time in the tenant\'s zone, and Remove', () => {
        inertia.props = propsWith(null);
        render();

        const card = q('.admin-customer-cards > .admin-customer-notes');

        expect(card).not.toBeNull();
        expect(card?.previousElementSibling?.classList.contains('admin-customer-profile')).toBe(true);
        expect(card?.querySelector('h2')?.textContent).toBe(own.text('notes.title'));
        expect(q('label[for="customer-note"]')?.textContent).toBe(own.text('notes.new'));
        expect(q('#customer-note')?.tagName).toBe('TEXTAREA');
        expect(q('#customer-note-help')?.textContent).toBe(own.text('notes.help'));
        expect(q('.admin-customer-notes__add')?.textContent).toBe(own.text('notes.add'));
        expect([...view.host.querySelectorAll('.admin-customer-note')].map((note) => [
            note.querySelector('.admin-customer-note__body')?.textContent,
            note.querySelector('.admin-customer-note__byline')?.textContent,
            note.querySelector('.admin-customer-note__remove')?.textContent,
        ])).toEqual([
            ['Prefers Elena. Allergic to ammonia colour.', own.text('notes.byline', { author: 'Luna Owner', when: '03/09/2026 18:12' }), own.text('notes.remove')],
            ['Asked for a quote on a bridal package.', own.text('notes.byline', { author: 'Elena Papadaki', when: '14/07/2026 11:40' }), own.text('notes.remove')],
        ]);
        // Each Remove named apart for a screen reader by the note's author and time.
        expect([...view.host.querySelectorAll('.admin-customer-note__remove')].map((button) => button.getAttribute('aria-label'))).toEqual([
            own.text('notes.remove_label', { author: 'Luna Owner', when: '03/09/2026 18:12' }),
            own.text('notes.remove_label', { author: 'Elena Papadaki', when: '14/07/2026 11:40' }),
        ]);
    });

    it('draws no list where the address has no note', () => {
        inertia.props = propsWith(null, { notes: [] });
        render();

        expect(q('.admin-customer-notes')).not.toBeNull();
        expect(q('.admin-customer-note')).toBeNull();
    });

    it('adds the note as typed to the customer\'s notes route, busy until the server answers, and empties the field once it lands', () => {
        inertia.props = propsWith(null);
        render();

        typeArea('#customer-note', 'Prefers mornings.');
        press(q('.admin-customer-notes__add'));

        expect(inertia.post).toHaveBeenCalledTimes(1);
        const [url, body, visit] = lastOf('post');
        expect(url).toBe(`${URL}/notes`);
        expect(body).toEqual({ body: 'Prefers mornings.' });
        expect(visit).toMatchObject({ preserveScroll: true, preserveState: true });

        act(() => visit.onStart?.());
        expect(q('.admin-customer-notes__add')?.textContent).toBe(own.text('notes.adding'));
        expect([...view.host.querySelectorAll('.admin-customer-note')]).toHaveLength(2);

        act(() => visit.onSuccess?.());
        act(() => visit.onFinish?.());
        expect((q('#customer-note') as HTMLTextAreaElement).value).toBe('');
    });

    it('answers a refused note with the server\'s sentence in an error toast, the typed text kept, and one with no answer in its own sentence', () => {
        inertia.props = propsWith(null);
        render();
        typeArea('#customer-note', 'x');
        press(q('.admin-customer-notes__add'));
        refuse(lastOf('post')[2], { status: 400, data: { code: 'invalid_request', message: 'invalid_request', data: { status: 400, detail: 'note-refusal-token' } } });

        expect(q('.admin-toast')?.textContent).toContain('note-refusal-token');
        expect((q('#customer-note') as HTMLTextAreaElement).value).toBe('x');

        press(q('.admin-customer-notes__add'));
        expect(fail(lastOf('post')[2])).toBe(true);
        expect(document.body.textContent).toContain(own.text('notes.add_error'));
    });

    it('asks before Remove and deletes the note by its id on the confirm, nothing removed until the server answers; Keep it sends nothing', () => {
        inertia.props = propsWith(null);
        render();

        press(view.host.querySelectorAll('.admin-customer-note__remove')[1] ?? null);
        expect(q('.admin-note-remove')).not.toBeNull();
        expect(q('.admin-note-remove')?.textContent).toContain(own.text('notes.confirm_body'));
        expect(inertia.delete).not.toHaveBeenCalled();

        press(q('.admin-note-remove__confirm'));
        expect(inertia.delete).toHaveBeenCalledTimes(1);
        const [url, visit] = lastDelete();
        expect(url).toBe(`${URL}/notes/3`);

        act(() => visit.onStart?.());
        expect([...view.host.querySelectorAll('.admin-customer-note')]).toHaveLength(2);
        act(() => visit.onSuccess?.());
        act(() => visit.onFinish?.());
        expect(q('.admin-note-remove')).toBeNull();
    });

    it('is not drawn for a viewer without manageBookings', () => {
        inertia.props = propsWith(null, { permissions: ['viewBookings'] });
        render();

        expect(q('.admin-customer-notes')).toBeNull();
        expect(q('.admin-customer-block')).toBeNull();
    });
});

describe('a customer\'s Online booking', () => {
    it('sits after Notes saying the address can book online, with Reason, its help, and Block online booking', () => {
        inertia.props = propsWith(null);
        render();

        const card = q('.admin-customer-cards > .admin-customer-block');

        expect(card?.previousElementSibling?.classList.contains('admin-customer-notes')).toBe(true);
        expect(card?.querySelector('h2')?.textContent).toBe(own.text('block.title'));
        expect(q('.admin-customer-block__state')?.textContent).toBe(own.text('block.open'));
        expect(q('label[for="customer-block-reason"]')?.textContent).toBe(own.text('block.reason'));
        expect(q('#customer-block-reason')?.tagName).toBe('TEXTAREA');
        expect(q('#customer-block-reason-help')?.textContent).toBe(own.text('block.reason_help'));
        expect(q('.admin-customer-block__block')?.textContent).toBe(own.text('block.block'));
        expect(q('.admin-customer-block__unblock')).toBeNull();
    });

    it('asks before blocking and puts the reason as typed, or null for none, to the block route on the confirm', () => {
        inertia.props = propsWith(null);
        render();

        typeArea('#customer-block-reason', 'Three no-shows');
        press(q('.admin-customer-block__block'));
        expect(q('.admin-block-prompt')?.textContent).toContain(own.text('block.confirm_body'));
        expect(inertia.put).not.toHaveBeenCalled();

        press(q('.admin-block-prompt__confirm'));
        const [url, body, visit] = lastOf('put');
        expect(url).toBe(`${URL}/block`);
        expect(body).toEqual({ reason: 'Three no-shows' });

        act(() => visit.onStart?.());
        act(() => visit.onSuccess?.());
        act(() => visit.onFinish?.());

        typeArea('#customer-block-reason', '');
        press(q('.admin-customer-block__block'));
        press(q('.admin-block-prompt__confirm'));
        expect(lastOf('put')[1]).toEqual({ reason: null });
    });

    it('says since when the address is blocked, in the tenant\'s zone, and the reason beneath, or that none was recorded, with Unblock deleting the block', () => {
        inertia.props = propsWith(null, { block: { blocked_at: '2026-11-01T09:00:00+00:00', reason: 'Three no-shows' }, customer: { ...CASEY, blocked: true } });
        render();

        expect(q('.admin-customer-block__state')?.textContent).toBe(own.text('block.since', { when: '01/11/2026 11:00' }));
        expect(q('.admin-customer-block__reason')?.textContent).toBe('Three no-shows');
        expect(q('#customer-block-reason')).toBeNull();
        expect(q('.admin-customer-block__block')).toBeNull();
        expect(q('.admin-customer-block__unblock')?.textContent).toBe(own.text('block.unblock'));

        press(q('.admin-customer-block__unblock'));
        const [url, visit] = lastDelete();
        expect(url).toBe(`${URL}/block`);
        act(() => visit.onStart?.());
        expect(q('.admin-customer-block__unblock')?.textContent).toBe(own.text('block.unblocking'));

        inertia.props = propsWith(null, { block: { blocked_at: '2026-11-01T09:00:00+00:00', reason: null } });
        render();
        expect(q('.admin-customer-block__reason')?.textContent).toBe(own.text('block.no_reason'));
    });

    it('answers a block with no answer in its own sentence', () => {
        inertia.props = propsWith(null, { block: { blocked_at: '2026-11-01T09:00:00+00:00', reason: null } });
        render();

        press(q('.admin-customer-block__unblock'));
        expect(fail(lastDelete()[1])).toBe(true);
        expect(document.body.textContent).toContain(own.text('block.error'));
    });
});

