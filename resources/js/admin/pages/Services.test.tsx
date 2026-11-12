import { act } from 'react';
import { afterEach, beforeEach, describe, expect, expectTypeOf, it, vi } from 'vitest';
import { localeOf } from '../../booking/language';
import { mount, type Mounted, press } from '@/components/ui/harness';
import { COPIED, COPY_BOOKING_LINK, LINK_COPIED, LINK_COPY_FAILED } from '@/lib/copy';
import { type Member, type Service, type View } from '@/services/model';
import { CATEGORY_HELP, ONLINE_HELP } from '@/services/ServiceForm';
import { NOT_ONLINE, ONLINE } from '@/services/ServicesTable';
import { saving } from '@/services/saving';
import { KEYED_SERVICES, keyedServices, keyedShell } from '@/testing/keyed';
import { ToastProvider } from '@/toast/toasts';
import type { ServicesStrings } from '@/types';
import { ADMIN_LINES_EN } from '../lines.generated';
import Services from './Services';

/*
 * The Services screen over the framework stood in for by a recording router: the empty-state
 * gate mid-load, the catalog's columns and toggle, the detail page's sections, the one client
 * gate, the folding, the save's sequence of writes, and the referenced conflict's inline remedy.
 * Every failure is the screen's own answer, never the framework's modal. Every word is read
 * through the keyed services section, and a server's worded detail is a neutral token, never a
 * sentence.
 */

const shell = keyedShell();

const words = keyedServices();

/** A table's shape: every line a string, every group its own shape, nothing else. */
type Shape<T> = { -readonly [K in keyof T]: T[K] extends string ? string : Shape<T[K]> };

type Visit = { onStart?: () => void; onFinish?: () => void; onSuccess?: (page: { props: Record<string, unknown> }) => void };

const inertia = vi.hoisted(() => ({
    props: {} as Record<string, unknown>,
    get: vi.fn(),
    post: vi.fn(),
    put: vi.fn(),
    delete: vi.fn(),
    listeners: new Map<string, (event: Event) => void>(),
}));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    usePage: () => ({ props: inertia.props }),
    router: {
        get: inertia.get,
        post: inertia.post,
        put: inertia.put,
        delete: inertia.delete,
        on: (name: string, listener: (event: Event) => void) => {
            inertia.listeners.set(name, listener);

            return () => inertia.listeners.delete(name);
        },
    },
}));

const HAIRCUT: Service = {
    id: 7, name: 'Haircut', description: 'Wash and cut.', duration_min: 45, processing_gap_min: 0, buffer_before_min: 0, buffer_after_min: 0,
    price_minor: 3500, currency: 'EUR', payment_mode: 'onsite', requires_approval: false, approval_hold_hours: null, on_approval_timeout: 'expire',
    cancel_window_hours: 24, reschedule_window_hours: 24, lead_time_min: 0, horizon_days: 60, status: 'active', category: null, online: true,
};

const COLOUR: Service = { ...HAIRCUT, id: 9, name: 'Colour', duration_min: 60, processing_gap_min: 30, price_minor: 8000, payment_mode: 'online' };

const PERM: Service = { ...HAIRCUT, id: 12, name: 'Perm', status: 'inactive' };

const ROSTER: Member[] = [
    { id: 1, name: 'Elena Papadaki', active: true, service_ids: [9], windows: [{ weekday: 1, start: '09:00', end: '13:00' }] },
    { id: 2, name: 'Nikos Georgiou', active: false, service_ids: [7, 9], windows: [] },
];

function propsWith(view: View, services: Service[], roster: Member[] = ROSTER, entitlements: string[] = ['staff'], categories: string[] = []): Record<string, unknown> {
    return {
        tenant: { name: 'Luna', slug: 'luna', timezone: 'Europe/Athens', currency: 'EUR' },
        auth: { user: { id: 1, name: 'Alex Owner', role: 'owner' } },
        flash: null,
        errors: {},
        permissions: ['viewBookings', 'manageSettings'],
        entitlements,
        subscription: { state: 'active' },
        settings: { granularity: 15, week_start: 1, date_format: 'd/m/Y', time_format: 'H:i', messages: [] },
        nav: [],
        view,
        services,
        categories,
        roster,
        links: services.map((service) => ({ id: service.id, link: `http://luna.localhost:8000/?service=${service.id}` })),
        strings: { shell: {}, services: KEYED_SERVICES },
    };
}

/** The last visit a method made: its url, its body (none for a delete) and its options. */
function lastVisit(method: ReturnType<typeof vi.fn>): [string, Record<string, unknown>, Visit] {
    const call = method.mock.lastCall;

    if (call === undefined) {
        throw new Error('No visit was made.');
    }

    return method === inertia.delete ? [call[0] as string, {}, call[1] as Visit] : (call as [string, Record<string, unknown>, Visit]);
}

function start(visit: Visit): void {
    act(() => visit.onStart?.());
}

/** The visit fails as the framework reports it: an invalid response, or no response at all. */
function fail(visit: Visit, response?: { status: number; data: unknown }): boolean {
    const event = response === undefined ? new CustomEvent('exception', { cancelable: true, detail: { exception: new Error('offline') } }) : new CustomEvent('invalid', { cancelable: true, detail: { response } });

    act(() => inertia.listeners.get(event.type)?.(event));
    act(() => visit.onFinish?.());

    return event.defaultPrevented;
}

function succeed(visit: Visit, page: Record<string, unknown> = {}): void {
    act(() => visit.onSuccess?.({ props: page }));
    act(() => visit.onFinish?.());
}

/** A navigation the page made lands: the framework swaps the props, then reports success. */
function land(visit: Visit, props: Record<string, unknown>): void {
    render(props);
    succeed(visit);
}

function type(selector: string, text: string): void {
    const field = document.querySelector(selector);

    if (!(field instanceof HTMLInputElement || field instanceof HTMLTextAreaElement)) {
        throw new Error(`No field at ${selector}.`);
    }

    act(() => {
        const prototype = field instanceof HTMLInputElement ? HTMLInputElement.prototype : HTMLTextAreaElement.prototype;
        Object.getOwnPropertyDescriptor(prototype, 'value')?.set?.call(field, text);
        field.dispatchEvent(new Event('input', { bubbles: true }));
    });
}

function text(selector: string): string | null | undefined {
    return document.querySelector(selector)?.textContent;
}

function toast(): Element | null {
    return document.querySelector('.admin-toast');
}

let view: Mounted;

beforeEach(() => {
    inertia.get.mockReset();
    inertia.post.mockReset();
    inertia.put.mockReset();
    inertia.delete.mockReset();
    inertia.listeners.clear();
    saving.set(false);
    view = mount();
});

afterEach(() => view.unmount());

function render(props: Record<string, unknown>): void {
    inertia.props = props;
    view.render(
        <ToastProvider>
            <Services />
        </ToastProvider>,
    );
}

describe('the catalog', () => {
    it('renders the two sentences once settled and empty, neither the empty panel nor a spinner while in flight, and the error notice with no empty panel when errored', () => {
        render(propsWith({ kind: 'list' }, []));

        expect(text('.admin-services-empty')).toBe(`${words.text('page.none')}${words.text('page.none_sentence')}`);

        press(document.querySelector('.admin-services-new'));
        const [url, , visit] = lastVisit(inertia.get);
        expect(url).toBe('/admin/services/new');
        start(visit);

        expect(document.querySelector('.admin-services-empty')).toBeNull();
        expect(document.querySelector('.admin-spinner')).toBeNull();
        expect(document.querySelector('.admin-services')).toBeNull();

        expect(fail(visit)).toBe(true);
        expect(text('.admin-list-error')).toContain(words.text('page.list_error'));
        expect(document.querySelector('.admin-services-empty')).toBeNull();
    });

    it('shows every service with the ID column and the sub-line, hiding inactive rows until the toggle, whose count is the inactive number', () => {
        render(propsWith({ kind: 'list' }, [HAIRCUT, COLOUR, PERM]));

        const cells = () => [...document.querySelectorAll('.admin-services__id')].map((cell) => cell.textContent);

        expect(cells()).toEqual(['7', '9']);
        expect([...document.querySelectorAll('.admin-services__sub')].map((sub) => sub.textContent)).toEqual([words.text('table.minutes', { n: '45' }), words.choice('table.minutes_gap', 30, { n: '60' })]);
        expect(text('.admin-services__payment')).toBe(words.text('modes.onsite'));
        expect(text('.admin-services__duration')).toBe(words.text('table.minutes', { n: '45' }));
        expect(text('.admin-services__count')).toBe(words.choice('table.count', 2));
        expect(text('label[for="services-show-inactive"]')).toBe(words.text('page.show_inactive', { count: '1' }));

        press(document.querySelector('#services-show-inactive'));

        expect(cells()).toEqual(['7', '9', '12']);
        expect([...document.querySelectorAll('.admin-chip')].map((chip) => chip.textContent)).toEqual([words.text('statuses.active'), words.text('statuses.active'), words.text('statuses.inactive')]);

        press(document.querySelector('.admin-services__row:nth-child(3) .admin-row-select'));
        expect(lastVisit(inertia.get)[0]).toBe('/admin/services/12');
    });
});

describe('the category and the online flag', () => {
    it('heads a Category column after Name and marks each row online or not beside its status', () => {
        render(propsWith({ kind: 'list' }, [{ ...HAIRCUT, category: 'Hair' }, { ...COLOUR, online: false }]));

        expect([...document.querySelectorAll('.admin-services thead th')].map((cell) => cell.textContent)).toEqual(
            (['table.id', 'table.name', 'table.category', 'table.duration', 'table.price', 'table.payment', 'table.status'] as const).map((head) => words.text(head)),
        );
        expect([...document.querySelectorAll('.admin-services__category')].map((cell) => cell.textContent)).toEqual(['Hair', '']);
        expect([...document.querySelectorAll('.admin-services__online')].map((mark) => mark.textContent)).toEqual([words.text(ONLINE), words.text(NOT_ONLINE)]);
    });

    it('renders Category with the catalogue\'s categories suggested and Offered online ticked by default in Basics, each with its help, and sends both', () => {
        render(propsWith({ kind: 'create' }, [HAIRCUT], ROSTER, ['staff'], ['Hair', 'Nails']));

        expect(document.querySelector('.admin-section--basics #service-category')?.getAttribute('list')).toBe('service-category-options');
        expect([...document.querySelectorAll('#service-category-options option')].map((option) => option.getAttribute('value'))).toEqual(['Hair', 'Nails']);
        expect(text('#service-category-help')).toBe(words.text(CATEGORY_HELP));
        expect(text('label[for="service-online"]')).toBe(words.text('form.online'));
        expect((document.querySelector('.admin-section--basics #service-online') as HTMLInputElement).checked).toBe(true);
        expect(document.querySelector('#service-online')?.getAttribute('aria-describedby')).toBe('service-online-help');
        expect(text('#service-online-help')).toBe(words.text(ONLINE_HELP));

        type('#service-name', 'Gel polish');
        type('#service-category', '  Nails ');
        press(document.querySelector('#service-online'));
        press(document.querySelector('.admin-service-save'));

        expect(lastVisit(inertia.post)[1]).toMatchObject({ name: 'Gel polish', category: 'Nails', online: false });
    });

    it('seeds a persisted row\'s category and flag and sends an emptied category as null', () => {
        render(propsWith({ kind: 'detail', id: 7 }, [{ ...HAIRCUT, category: 'Hair', online: false }]));

        expect((document.querySelector('#service-category') as HTMLInputElement).value).toBe('Hair');
        expect((document.querySelector('#service-online') as HTMLInputElement).checked).toBe(false);

        type('#service-category', '   ');
        press(document.querySelector('.admin-service-save'));

        expect(lastVisit(inertia.put)[1]).toMatchObject({ category: null, online: false });
    });
});

describe('the detail page', () => {
    it('renders the sections in order, Status and the Danger zone absent on the create route, the hold fields only once approval is required', () => {
        render(propsWith({ kind: 'create' }, [HAIRCUT]));

        expect([...document.querySelectorAll('.admin-section')].map((section) => section.className.split(' ')[1])).toEqual([
            'admin-section--basics', 'admin-section--timing', 'admin-section--staff', 'admin-section--rules', 'admin-section--payment',
        ]);
        expect(text('.admin-breadcrumb__current')).toBe(words.text('page.new'));
        expect(document.querySelector('#service-status')).toBeNull();
        expect(document.querySelector('.admin-chip')).toBeNull();
        expect(document.querySelector('.admin-section--danger')).toBeNull();
        expect(document.querySelector('#service-approval_hold_hours')).toBeNull();
        expect(document.querySelector('#service-timeout')).toBeNull();
        expect(text('label[for="service-price"]')).toBe(words.text('form.price', { currency: 'EUR' }));
        expect(document.querySelector('#service-duration_min')?.getAttribute('step')).toBe('15');
        expect(text('#service-duration_min-help')).toBe(words.text('form.duration_help', { n: '15' }));
        expect(document.querySelector('#service-horizon_days')?.getAttribute('max')).toBe('365');
        expect(document.querySelector('#service-lead_time_min')?.hasAttribute('max')).toBe(false);

        press(document.querySelector('#service-requires-approval'));
        expect(document.querySelector('#service-approval_hold_hours')).not.toBeNull();
        expect(document.querySelector('#service-timeout')).not.toBeNull();

        press(document.querySelector('#service-requires-approval'));
        expect(document.querySelector('#service-approval_hold_hours')).toBeNull();

        render(propsWith({ kind: 'detail', id: 7 }, [HAIRCUT]));
        expect(document.querySelector('#service-status')).not.toBeNull();
        expect(text('.admin-service-title .admin-chip')).toBe(words.text('statuses.active'));
        expect(document.querySelector('.admin-section--danger')).not.toBeNull();
        expect((document.querySelector('#service-price') as HTMLInputElement).value).toBe('35.00');
    });

    it('shows the staff section by the one-value rule, its meta counting the staged ticks and omitted while the roster loads, inactive members suffixed with their windows beside them', () => {
        render(propsWith({ kind: 'detail', id: 7 }, [HAIRCUT], [ROSTER[0] as Member], []));
        expect(document.querySelector('.admin-section--staff')).toBeNull();

        render(propsWith({ kind: 'detail', id: 7 }, [HAIRCUT], ROSTER, []));
        expect(document.querySelector('.admin-section--staff')).not.toBeNull();

        render(propsWith({ kind: 'create' }, [HAIRCUT], ROSTER, ['staff']));
        expect(text('.admin-section--staff .admin-section__meta')).toBe(words.choice('staff.ticked', 2, { ticked: '0' }));
        expect([...document.querySelectorAll('.admin-staff-checklist__name')].map((name) => name.textContent)).toEqual(['Elena Papadaki', words.text('staff.inactive', { staff: 'Nikos Georgiou' })]);
        expect([...document.querySelectorAll('.admin-staff-checklist__windows')].map((windows) => windows.textContent)).toEqual([
            words.text('staff.window', { weekday: new Intl.DateTimeFormat(localeOf('en'), { weekday: 'short', timeZone: 'UTC' }).format(new Date(Date.UTC(2024, 0, 1))), time: '09:00', end: '13:00' }),
            words.text('staff.no_hours'),
        ]);
        expect(document.querySelector('.admin-staff-checklist__link')?.getAttribute('href')).toBe('/admin/shifts');

        press(document.querySelector('#service-staff-2'));
        expect(text('.admin-section--staff .admin-section__meta')).toBe(words.choice('staff.ticked', 2, { ticked: '1' }));

        press(document.querySelector('.admin-breadcrumb__list'));
        start(lastVisit(inertia.get)[2]);
        expect(document.querySelector('.admin-section--staff')).not.toBeNull();
        expect(document.querySelector('.admin-section--staff .admin-section__meta')).toBeNull();
        expect(document.querySelector('.admin-service-form')).not.toBeNull();
    });

    it('words the staff checklist\'s link to the Shifts screen to edit the hours with the staff feature', () => {
        render(propsWith({ kind: 'detail', id: 7 }, [HAIRCUT], ROSTER, ['staff']));

        expect(document.querySelector('.admin-staff-checklist__link')?.getAttribute('href')).toBe('/admin/shifts');
        expect(text('.admin-staff-checklist__link')).toBe(words.text('staff.edit_hours'));
    });

    it('shows no link to the Shifts screen to a viewer without manageSettings, who cannot open it', () => {
        render({ ...propsWith({ kind: 'detail', id: 7 }, [HAIRCUT], ROSTER, ['staff']), permissions: ['viewBookings'] });
        expect(document.querySelector('.admin-staff-checklist__name')).not.toBeNull();
        expect(document.querySelector('.admin-staff-checklist__link')).toBeNull();

        render({ ...propsWith({ kind: 'detail', id: 9 }, [HAIRCUT, COLOUR], ROSTER, []), permissions: ['viewBookings'] });
        expect(document.querySelector('.admin-staff-checklist__link')).toBeNull();
    });

    it('resolves an id the settled list does not hold as the Back link over the warning', () => {
        render(propsWith({ kind: 'detail', id: 99 }, [HAIRCUT]));

        expect(text('.admin-detail-back')).toBe(words.text('page.back'));
        expect(document.querySelector('.admin-service-form')).toBeNull();

        press(document.querySelector('.admin-detail-back'));
        expect(lastVisit(inertia.get)[0]).toBe('/admin/services');
    });
});

describe('the booking link', () => {
    it('copies a persisted service\'s link as the server spelled it from its title row, and records the press once the clipboard write lands', async () => {
        const writeText = vi.fn(() => Promise.resolve());
        Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true });
        render(propsWith({ kind: 'detail', id: 9 }, [HAIRCUT, COLOUR]));

        const button = document.querySelector('.admin-service-title .admin-booking-link__copy');
        expect(button?.textContent).toBe(shell.text(COPY_BOOKING_LINK));

        press(button);
        expect(writeText).toHaveBeenCalledWith('http://luna.localhost:8000/?service=9');
        expect(inertia.post).not.toHaveBeenCalled();
        await act(async () => {
            await Promise.resolve();
        });

        expect(text('.admin-booking-link__copied')).toBe(shell.text(COPIED));
        expect(inertia.post).toHaveBeenCalledWith(LINK_COPIED, {}, { async: true, preserveState: true, preserveScroll: true });
    });

    it('offers no copy while creating, and says where the link is when the clipboard refuses it, recording nothing', async () => {
        render(propsWith({ kind: 'create' }, [HAIRCUT]));
        expect(document.querySelector('.admin-booking-link__copy')).toBeNull();

        Object.defineProperty(navigator, 'clipboard', { value: { writeText: vi.fn(() => Promise.reject(new Error('denied'))) }, configurable: true });
        render(propsWith({ kind: 'detail', id: 7 }, [HAIRCUT]));
        press(document.querySelector('.admin-booking-link__copy'));
        await act(async () => {
            await Promise.resolve();
        });

        expect(text('.admin-booking-link__copied')).toBe(`${shell.text(LINK_COPY_FAILED)}http://luna.localhost:8000/?service=7`);
        expect(inertia.post).not.toHaveBeenCalled();
    });

    it('copies without recording for a viewer who may not record the press', async () => {
        Object.defineProperty(navigator, 'clipboard', { value: { writeText: vi.fn(() => Promise.resolve()) }, configurable: true });
        render({ ...propsWith({ kind: 'detail', id: 7 }, [HAIRCUT]), permissions: ['viewBookings'] });
        press(document.querySelector('.admin-booking-link__copy'));
        await act(async () => {
            await Promise.resolve();
        });

        expect(text('.admin-booking-link__copied')).toBe(shell.text(COPIED));
        expect(inertia.post).not.toHaveBeenCalled();
    });
});

describe('saving', () => {
    it('disables Save while the name is blank after trimming, folds the price and unreadable numbers, sends no currency when editing, and keeps every edit when the server refuses', () => {
        render(propsWith({ kind: 'detail', id: 7 }, [HAIRCUT]));

        const save = () => document.querySelector('.admin-service-save') as HTMLButtonElement;

        type('#service-name', '   ');
        expect(save().disabled).toBe(true);
        expect(text('.admin-save-bar__reason')).toBe(words.text('form.needs_name'));

        // A number input reads letters as no value at all, which is what the fold sends as zero.
        type('#service-name', 'Haircut');
        type('#service-price', '12,50');
        type('#service-processing_gap_min', 'abc');
        type('#service-buffer_after_min', '');
        expect(save().disabled).toBe(false);

        press(save());
        const [url, body, visit] = lastVisit(inertia.put);
        expect(url).toBe('/admin/services/7');
        expect(body).toMatchObject({ name: 'Haircut', price_minor: 1250, processing_gap_min: 0, buffer_after_min: 0, status: 'active', duration_min: 45 });
        expect(body).not.toHaveProperty('currency');

        start(visit);
        expect(save().getAttribute('aria-busy')).toBe('true');
        expect(text('.admin-service-save')).toBe(words.text('form.saving'));

        expect(fail(visit, { status: 400, data: { code: 'invalid_request', message: 'invalid_request', data: { status: 400, detail: 'DETAIL_INVALID' } } })).toBe(true);
        expect(toast()?.getAttribute('role')).toBe('alert');
        expect(toast()?.textContent).toContain('DETAIL_INVALID');
        expect((document.querySelector('#service-price') as HTMLInputElement).value).toBe('12,50');
        expect((document.querySelector('#service-buffer_after_min') as HTMLInputElement).value).toBe('');
        expect(save().getAttribute('aria-busy')).toBeNull();
    });

    it('creates with the tenant\'s currency, then writes each changed staff link one at a time from the row it landed on, and an untouched checklist writes none', () => {
        render(propsWith({ kind: 'create' }, [HAIRCUT]));

        type('#service-name', 'Shave');
        press(document.querySelector('#service-staff-1'));
        press(document.querySelector('.admin-service-save'));

        const [url, body, visit] = lastVisit(inertia.post);
        expect(url).toBe('/admin/services');
        expect(body).toMatchObject({ name: 'Shave', currency: 'EUR', duration_min: 15, price_minor: 0 });
        expect(body).not.toHaveProperty('status');
        expect(inertia.put).not.toHaveBeenCalled();

        start(visit);
        succeed(visit, propsWith({ kind: 'detail', id: 42 }, [HAIRCUT]));

        const [linkUrl, linkBody, linkVisit] = lastVisit(inertia.put);
        expect(linkUrl).toBe('/admin/staff/1/services');
        expect(linkBody).toEqual({ service_ids: [9, 42] });
        expect(saving.get()).toBe(true);

        succeed(linkVisit);
        expect(inertia.put).toHaveBeenCalledTimes(1);
        expect(saving.get()).toBe(false);

        render(propsWith({ kind: 'detail', id: 7 }, [HAIRCUT]));
        press(document.querySelector('.admin-service-save'));
        const [, , edit] = lastVisit(inertia.put);
        start(edit);
        succeed(edit);
        expect(inertia.put).toHaveBeenCalledTimes(2);
        expect(saving.get()).toBe(false);
    });

    it('starts a new service ticked to the roster\'s one row, which the server links it to, so an untouched checklist sends nothing and an untick is sent', () => {
        const alone: Member[] = [{ id: 1, name: 'Luna Studio', active: true, service_ids: [7], windows: [] }];
        const landed: Member[] = [{ ...alone[0]!, service_ids: [7, 42] }];

        render(propsWith({ kind: 'create' }, [HAIRCUT], alone, ['staff']));
        expect([...document.querySelectorAll<HTMLInputElement>('.admin-staff-checklist__tick')].map((tick) => [tick.checked, tick.disabled])).toEqual([[true, false]]);

        type('#service-name', 'Shave');
        press(document.querySelector('.admin-service-save'));
        const [, , kept] = lastVisit(inertia.post);
        start(kept);
        succeed(kept, propsWith({ kind: 'detail', id: 42 }, [HAIRCUT], landed, ['staff']));

        expect(inertia.put).not.toHaveBeenCalled();
        expect(saving.get()).toBe(false);

        render(propsWith({ kind: 'list' }, [HAIRCUT], alone, ['staff']));
        render(propsWith({ kind: 'create' }, [HAIRCUT], alone, ['staff']));
        type('#service-name', 'Beard trim');
        press(document.querySelector('#service-staff-1'));
        press(document.querySelector('.admin-service-save'));
        const [, , unticked] = lastVisit(inertia.post);
        start(unticked);
        succeed(unticked, propsWith({ kind: 'detail', id: 43 }, [HAIRCUT], [{ ...alone[0]!, service_ids: [7, 43] }], ['staff']));

        const [linkUrl, linkBody] = lastVisit(inertia.put);
        expect(linkUrl).toBe('/admin/staff/1/services');
        expect(linkBody).toEqual({ service_ids: [7] });
    });

    it('says which member\'s links did not land when a later write fails, the patch before it kept', () => {
        render(propsWith({ kind: 'detail', id: 7 }, [HAIRCUT]));

        press(document.querySelector('#service-staff-1'));
        press(document.querySelector('#service-staff-2'));
        press(document.querySelector('.admin-service-save'));

        const [, , patch] = lastVisit(inertia.put);
        start(patch);
        succeed(patch);

        const [firstUrl, firstBody, first] = lastVisit(inertia.put);
        expect(firstUrl).toBe('/admin/staff/1/services');
        expect(firstBody).toEqual({ service_ids: [9, 7] });
        start(first);
        succeed(first);

        const [secondUrl, secondBody, second] = lastVisit(inertia.put);
        expect(secondUrl).toBe('/admin/staff/2/services');
        expect(secondBody).toEqual({ service_ids: [9] });
        start(second);
        expect(fail(second)).toBe(true);

        expect(toast()?.textContent).toContain(words.text('page.links_failed', { staff: 'Nikos Georgiou', reason: words.text('page.save_error') }));
        expect(saving.get()).toBe(false);
    });
});

describe('the danger zone', () => {
    it('answers referenced with the inline warning beside the button and no toast, and the remedy patches the status, clearing the notice', () => {
        render(propsWith({ kind: 'detail', id: 7 }, [HAIRCUT]));

        press(document.querySelector('.admin-service-delete'));
        const [url, , visit] = lastVisit(inertia.delete);
        expect(url).toBe('/admin/services/7');
        start(visit);
        expect(text('.admin-service-delete')).toBe(words.text('danger.deleting'));

        expect(fail(visit, { status: 409, data: { code: 'referenced', message: 'referenced', data: { status: 409, detail: 'DETAIL_REFERENCED' } } })).toBe(true);
        expect(text('.admin-section--danger .admin-service-conflict')).toContain('DETAIL_REFERENCED');
        expect(text('.admin-service-deactivate')).toBe(words.text('danger.deactivate'));
        expect(document.querySelector('.admin-service-conflict')?.getAttribute('role')).toBe('status');
        expect(toast()).toBeNull();
        expect(document.querySelector('.admin-service-form')).not.toBeNull();

        press(document.querySelector('.admin-service-deactivate'));
        const [patchUrl, patchBody, patch] = lastVisit(inertia.put);
        expect(patchUrl).toBe('/admin/services/7');
        expect(patchBody).toEqual({ status: 'inactive' });
        start(patch);
        expect(text('.admin-service-deactivate')).toBe(words.text('danger.deactivating'));
        succeed(patch);
        expect(document.querySelector('.admin-service-conflict')).toBeNull();
    });

    it('shows a referenced notice on the row that earned it alone: not on another row, not on the create route, and not on the row itself once left and reopened', () => {
        const catalog = [HAIRCUT, COLOUR];
        const conflict = { status: 409, data: { code: 'referenced', message: 'referenced', data: { status: 409, detail: 'DETAIL_REFERENCED' } } };
        const leave = (): void => {
            press(document.querySelector('.admin-breadcrumb__list'));
            const [url, , visit] = lastVisit(inertia.get);
            expect(url).toBe('/admin/services');
            start(visit);
            land(visit, propsWith({ kind: 'list' }, catalog));
        };
        const open = (row: number, view: View): void => {
            press(document.querySelector(`.admin-services__row:nth-child(${row}) .admin-row-select`));
            const [, , visit] = lastVisit(inertia.get);
            start(visit);
            land(visit, propsWith(view, catalog));
        };

        render(propsWith({ kind: 'detail', id: 7 }, catalog));
        press(document.querySelector('.admin-service-delete'));
        const [, , visit] = lastVisit(inertia.delete);
        start(visit);
        expect(fail(visit, conflict)).toBe(true);
        expect(document.querySelector('.admin-service-conflict')).not.toBeNull();

        leave();
        open(2, { kind: 'detail', id: 9 });
        expect(text('.admin-breadcrumb__current')).toBe('Colour');
        expect(document.querySelector('.admin-service-delete')).not.toBeNull();
        expect(document.querySelector('.admin-service-conflict')).toBeNull();
        expect(document.querySelector('.admin-service-deactivate')).toBeNull();

        leave();
        press(document.querySelector('.admin-services-new'));
        const [createUrl, , toCreate] = lastVisit(inertia.get);
        expect(createUrl).toBe('/admin/services/new');
        start(toCreate);
        land(toCreate, propsWith({ kind: 'create' }, catalog));
        expect(document.querySelector('.admin-section--danger')).toBeNull();
        expect(document.querySelector('.admin-service-conflict')).toBeNull();

        leave();
        open(1, { kind: 'detail', id: 7 });
        expect(text('.admin-breadcrumb__current')).toBe('Haircut');
        expect(document.querySelector('.admin-service-delete')).not.toBeNull();
        expect(document.querySelector('.admin-service-conflict')).toBeNull();
        expect(toast()).toBeNull();
    });

    it('answers any other delete failure as an error toast with the form intact', () => {
        render(propsWith({ kind: 'detail', id: 7 }, [HAIRCUT]));

        press(document.querySelector('.admin-service-delete'));
        const [, , visit] = lastVisit(inertia.delete);
        start(visit);
        expect(fail(visit, { status: 409, data: { code: 'busy', message: 'busy', data: { status: 409, detail: 'DETAIL_BUSY' } } })).toBe(true);

        expect(toast()?.textContent).toContain('DETAIL_BUSY');
        expect(document.querySelector('.admin-service-conflict')).toBeNull();
        expect(document.querySelector('.admin-service-form')).not.toBeNull();
    });
});

/*
 * The staff checklist without the staff feature: the one-value rule's rows half still shows who
 * performs the service, and changing that needs the feature, so the ticks are shown and cannot
 * be changed, the link to the Shifts screen stays, and a save sends no staff link. With the
 * feature, the saving cases above are the proof that a changed tick is sent as before.
 */
describe('the staff checklist without the staff feature', () => {
    it('shows the ticks as linked and read-only with a line saying why, a press changing nothing', () => {
        render(propsWith({ kind: 'detail', id: 9 }, [HAIRCUT, COLOUR], ROSTER, []));

        const ticks = [...document.querySelectorAll<HTMLInputElement>('.admin-staff-checklist__tick')];
        expect(ticks.map((tick) => [tick.id, tick.checked, tick.disabled])).toEqual([
            ['service-staff-1', true, true],
            ['service-staff-2', true, true],
        ]);
        expect(text('.admin-staff-checklist__readonly')).toBe(words.text('staff.read_only'));

        press(document.querySelector('#service-staff-1'));
        expect(document.querySelector('#service-staff-1')).toHaveProperty('checked', true);
        expect(text('.admin-section--staff .admin-section__meta')).toBe(words.choice('staff.ticked', 2, { ticked: '2' }));
    });

    it('keeps the link to the Shifts screen, worded to see the hours rather than edit them', () => {
        render(propsWith({ kind: 'detail', id: 9 }, [HAIRCUT, COLOUR], ROSTER, []));

        expect(document.querySelector('.admin-staff-checklist__link')?.getAttribute('href')).toBe('/admin/shifts');
        expect(text('.admin-staff-checklist__link')).toBe(words.text('staff.see_hours'));
    });

    it('saves an edited service with no staff link sent, a pressed tick included', () => {
        render(propsWith({ kind: 'detail', id: 9 }, [HAIRCUT, COLOUR], ROSTER, []));

        press(document.querySelector('#service-staff-1'));
        type('#service-name', 'Colour and gloss');
        press(document.querySelector('.admin-service-save'));
        const [url, , visit] = lastVisit(inertia.put);
        expect(url).toBe('/admin/services/9');
        start(visit);
        succeed(visit);
        expect(inertia.put).toHaveBeenCalledTimes(1);
        expect(saving.get()).toBe(false);
    });

    it('shows the ticks read-only and unticked on the create route', () => {
        render(propsWith({ kind: 'create' }, [HAIRCUT], ROSTER, []));

        expect([...document.querySelectorAll<HTMLInputElement>('.admin-staff-checklist__tick')].map((tick) => [tick.checked, tick.disabled])).toEqual([
            [false, true],
            [false, true],
        ]);
    });

    it('sends no staff link on a create the server answers with a roster already linking the new row, the links gate alone standing between them', () => {
        render(propsWith({ kind: 'create' }, [HAIRCUT], ROSTER, []));

        type('#service-name', 'Shave');
        press(document.querySelector('.admin-service-save'));
        const [, , visit] = lastVisit(inertia.post);
        start(visit);
        // The landed roster links Elena to the new row while the checklist staged nobody: with
        // the feature that difference is a write, without it nothing is sent.
        succeed(visit, propsWith({ kind: 'detail', id: 42 }, [HAIRCUT], [{ ...ROSTER[0]!, service_ids: [9, 42] }], []));

        expect(inertia.put).not.toHaveBeenCalled();
        expect(saving.get()).toBe(false);
    });

    it('creates a service and sends no staff link once it lands, a pressed tick included', () => {
        render(propsWith({ kind: 'create' }, [HAIRCUT], ROSTER, []));

        press(document.querySelector('#service-staff-1'));
        type('#service-name', 'Shave');
        press(document.querySelector('.admin-service-save'));
        const [url, , visit] = lastVisit(inertia.post);
        expect(url).toBe('/admin/services');
        start(visit);
        succeed(visit, propsWith({ kind: 'detail', id: 42 }, [HAIRCUT], ROSTER, []));
        expect(inertia.put).not.toHaveBeenCalled();
        expect(saving.get()).toBe(false);
    });
});

describe('the services section', () => {
    it('is typed as the keys of lang/en/admin/services.php, no more and no fewer', () => {
        expectTypeOf<Shape<typeof ADMIN_LINES_EN.services>>().toEqualTypeOf<ServicesStrings>();
        expect(Object.keys(ADMIN_LINES_EN.services)).toEqual(['page', 'table', 'modes', 'timeouts', 'statuses', 'form', 'staff', 'danger', 'validation', 'refusals', 'flashes']);
    });

    it('leaves no English line of the screen on the catalog or the form, every word read from the section', () => {
        const { validation: _validation, refusals: _refusals, flashes: _flashes, ...screen } = ADMIN_LINES_EN.services;
        const english = Object.values(screen).flatMap((group) => Object.values(group)).filter((line) => !/:[a-z_]+/.test(line));
        const left = (): string[] => english.filter((line) => (view.host.textContent ?? '').includes(line) || view.host.innerHTML.includes(`"${line}"`));

        render(propsWith({ kind: 'list' }, [HAIRCUT, COLOUR, PERM]));
        expect(left()).toEqual([]);
        expect(view.host.textContent).toContain(words.text('page.title'));

        render(propsWith({ kind: 'detail', id: 7 }, [HAIRCUT], ROSTER, ['staff']));
        press(document.querySelector('#service-requires-approval'));
        expect(left()).toEqual([]);
        expect(view.host.textContent).toContain(words.text('danger.title'));
        expect([...document.querySelectorAll('#service-payment-mode option')].map((option) => option.textContent)).toEqual([words.text('modes.free'), words.text('modes.online'), words.text('modes.onsite')]);
        expect([...document.querySelectorAll('#service-timeout option')].map((option) => option.textContent)).toEqual([words.text('timeouts.expire'), words.text('timeouts.auto_approve')]);
        expect([...document.querySelectorAll('#service-status option')].map((option) => option.textContent)).toEqual([words.text('statuses.active'), words.text('statuses.inactive')]);
    });
});
