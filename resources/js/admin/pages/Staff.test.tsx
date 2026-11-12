import { act, type ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { mount, type Mounted, press } from '@/components/ui/harness';
import { COPIED, COPY_BOOKING_LINK, LINK_COPIED } from '@/lib/copy';
import { formatCount } from '@/lib/format';
import { unitFigure } from '@/lib/names';
import { listOf, weekdayName } from '@/lib/names';
import { BIO_LIMIT, type CatalogService, type ClosureLine, type StaffMember, type TenantUser, type View } from '@/staff/model';
import { BIO_HELP, bioCount, IMPLICIT_ONLINE_HELP, ONLINE_HELP as STAFF_ONLINE_HELP, PHOTO_HELP, TITLE_HELP } from '@/staff/StaffForm';
import { KEYED_STAFF, keyedShell, keyedStaff } from '@/testing/keyed';
import { ADMIN_LINES_EN } from '../lines.generated';
import { ToastProvider } from '@/toast/toasts';
import Staff from './Staff';

/*
 * The Staff screen over the framework stood in for by a recording router: the list and its
 * toggle with the implicit member as an ordinary row, the closures card, the detail page's
 * sections with the last two on a persisted row alone, the combobox changing the linked user on
 * a pick alone, a save sending the links replace-all and re-seeding from the answer, the
 * member's pattern a read-only line with the link to Shifts, a background refetch leaving an
 * edit alone, a window off the grid saved back unchanged, and the referenced conflict's inline
 * remedy leaving for the list. The page renders with the keyed staff section, so every word it
 * shows reads as its key.
 */

const shell = keyedShell();

const staff = keyedStaff();

/** ICU's figures in English, which the lines carry as their units, never a unit glued by hand. */
const hour = (value: number) => unitFigure(value, 'hour', 'en');

const figure = (hours: number, minutes: number) => staff.text('figure', { hours: hour(hours), minutes: unitFigure(minutes, 'minute', 'en') });

/** The photo's limit the page carries, its spelling a neutral token. */
const PHOTO_LIMIT = { megabytes: 8, spelled: 'spelled-token' };

type Visit = { onStart?: () => void; onFinish?: () => void; onSuccess?: (page: { props: Record<string, unknown> }) => void; data?: Record<string, unknown>; forceFormData?: boolean };

const inertia = vi.hoisted(() => ({
    props: {} as Record<string, unknown>,
    title: null as string | null,
    get: vi.fn(),
    post: vi.fn(),
    put: vi.fn(),
    delete: vi.fn(),
    listeners: new Map<string, (event: Event) => void>(),
}));

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title: string }) => {
        inertia.title = title;

        return null;
    },
    Link: ({ href, className, children }: { href: string; className?: string; children: ReactNode }) => (
        <a href={href} className={className}>
            {children}
        </a>
    ),
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

const LUNA: StaffMember = { id: 1, name: 'Luna Hair Studio', email: null, status: 'active', online: true, is_implicit: true, user_id: null, title: null, bio: null, service_ids: [], pattern: { cadence_weeks: 1, starts_on: '1999-12-27', ends_on: null, windows: [1, 2, 3, 4, 5].map((weekday) => ({ weekday, start: '09:00', end: '17:00' })) }, blackouts: [], colour: null, photo: null, hours: { minutes: 2400, weekly: true, closures: [] } };

const ELENA: StaffMember = {
    id: 2, name: 'Elena Papadaki', email: 'elena@luna.example', status: 'active', online: true, is_implicit: false, user_id: null, title: null, bio: null, service_ids: [7],
    pattern: {
        cadence_weeks: 2,
        starts_on: '2026-10-26',
        ends_on: null,
        windows: [
            { weekday: 1, start: '09:07', end: '13:00' },
            { weekday: 1, start: '14:00', end: '18:00' },
            { weekday: 3, start: '10:00', end: '16:00' },
        ],
    },
    blackouts: [{ date: '2026-12-04', until: null, start: '14:00', end: '16:00', reason: 'Dentist' }],
    colour: null,
    photo: null,
    hours: { minutes: 900, weekly: false, closures: [] },
};

const PHOTO = { name: 'staff-0123456789abcdef.png', url: 'http://luna.localhost:8000/images/staff-0123456789abcdef.png' };

const NIKOS: StaffMember = { ...ELENA, id: 3, name: 'Nikos Georgiou', email: null, status: 'inactive', service_ids: [], pattern: null, blackouts: [], hours: { minutes: 0, weekly: false, closures: [] } };

const SERVICES: CatalogService[] = [{ id: 7, name: 'Haircut', status: 'active' }, { id: 9, name: 'Perm', status: 'inactive' }];

const USERS: TenantUser[] = [{ id: 11, name: 'Alex Owner', email: 'owner@luna.example' }, { id: 12, name: 'Mel Manager', email: 'mel@luna.example' }];

const CLOSURES: ClosureLine[] = [{ staff_id: null, date: '2026-12-25', until: null, start: null, end: null, reason: 'Christmas Day' }, { staff_id: null, date: '2026-12-31', until: null, start: '14:00', end: '18:00', reason: null }];

/** The closures card's title without the feature, under the screen's heading (P8F-L05-a). */
const CLOSED_DAYS = staff.text('page.closed_days');

/** Elena's pattern as the read-only line spells it, every two weeks from its start. */
const ELENA_LINE = staff.text('pattern.cadence', { hours: figure(13, 53), weeks: formatCount(2, staff.locale), date: '26/10/2026' });

/** The line with the link to Shifts beside it. */
const ELENA_PATTERN = `${ELENA_LINE} ${staff.text('pattern.link')}`;

/** Elena's closure, its date and window through one line. */
const DENTIST = staff.text('closures.window', { date: '04/12/2026', time: '14:00', end: '16:00' });

function propsWith(view: View, members: StaffMember[] = [LUNA, ELENA, NIKOS], closures: ClosureLine[] = CLOSURES): Record<string, unknown> {
    return {
        tenant: { name: 'Luna', slug: 'luna', timezone: 'Europe/Athens', currency: 'EUR' },
        auth: { user: { id: 11, name: 'Alex Owner', role: 'owner' } },
        flash: null,
        errors: {},
        permissions: ['viewBookings', 'manageSettings'],
        entitlements: ['staff'],
        subscription: { state: 'active' },
        free: false,
        settings: { granularity: 5, week_start: 1, date_format: 'd/m/Y', time_format: 'H:i', colour_by: 'staff', messages: [] },
        nav: [],
        view,
        staff: members,
        services: SERVICES,
        users: USERS,
        closures,
        links: members.filter((member) => !member.is_implicit).map((member) => ({ id: member.id, link: `http://luna.localhost:8000/?staff=${member.id}` })),
        photo_limit: PHOTO_LIMIT,
        language: 'en',
        strings: { shell: {}, staff: KEYED_STAFF },
    };
}

function lastVisit(method: ReturnType<typeof vi.fn>): [string, Record<string, unknown>, Visit] {
    const call = method.mock.lastCall;

    if (call === undefined) {
        throw new Error('No visit was made.');
    }

    if (method === inertia.delete) {
        const options = call[1] as Visit;

        return [call[0] as string, options.data ?? {}, options];
    }

    return call as [string, Record<string, unknown>, Visit];
}

function start(visit: Visit): void {
    act(() => visit.onStart?.());
}

function fail(visit: Visit, response?: { status: number; data: unknown }): void {
    const event = response === undefined ? new CustomEvent('exception', { cancelable: true, detail: { exception: new Error('offline') } }) : new CustomEvent('invalid', { cancelable: true, detail: { response } });

    act(() => inertia.listeners.get(event.type)?.(event));
    act(() => visit.onFinish?.());
}

function succeed(visit: Visit, page: Record<string, unknown> = {}): void {
    act(() => visit.onSuccess?.({ props: page }));
    act(() => visit.onFinish?.());
}

function land(visit: Visit, props: Record<string, unknown>): void {
    render(props);
    succeed(visit);
}

function type(selector: string, value: string): void {
    const field = document.querySelector(selector);

    if (!(field instanceof HTMLInputElement) && !(field instanceof HTMLTextAreaElement)) {
        throw new Error(`No field at ${selector}.`);
    }

    act(() => {
        Object.getOwnPropertyDescriptor(Object.getPrototypeOf(field), 'value')?.set?.call(field, value);
        field.dispatchEvent(new Event('input', { bubbles: true }));
    });
}

function value(selector: string): string {
    const field = document.querySelector(selector);

    return field instanceof HTMLInputElement || field instanceof HTMLTextAreaElement ? field.value : (field?.getAttribute('value') ?? '');
}

function chooseFile(selector: string, file: File): void {
    const input = document.querySelector(selector);

    if (!(input instanceof HTMLInputElement)) {
        throw new Error(`No file input at ${selector}.`);
    }

    Object.defineProperty(input, 'files', { value: [file], configurable: true });
    act(() => input.dispatchEvent(new Event('change', { bubbles: true })));
}

function text(selector: string): string | null | undefined {
    return document.querySelector(selector)?.textContent;
}

/** Every toast on screen, one text. */
function toasts(): string {
    return Array.from(document.querySelectorAll('.admin-toast')).map((toast) => toast.textContent).join(' ');
}

let view: Mounted;

function render(props: Record<string, unknown>): void {
    inertia.props = props;
    view.render(
        <ToastProvider>
            <Staff />
        </ToastProvider>,
    );
}

beforeEach(() => {
    view = mount();
    inertia.title = null;
    inertia.get.mockReset();
    inertia.post.mockReset();
    inertia.put.mockReset();
    inertia.delete.mockReset();
    inertia.listeners.clear();
});

afterEach(() => {
    view.unmount();
});

describe('the list', () => {
    it('shows ID, Name, Email and Status for active members, the implicit one an ordinary row, and brings inactive ones with their count on the toggle', () => {
        render(propsWith({ kind: 'list' }));

        expect(document.querySelectorAll('.admin-staff__row')).toHaveLength(2);
        expect(Array.from(document.querySelectorAll('.admin-staff__id')).map((cell) => cell.textContent)).toEqual(['1', '2']);
        expect(Array.from(document.querySelectorAll('.admin-staff__email')).map((cell) => cell.textContent)).toEqual(['', 'elena@luna.example']);
        expect(text('.admin-staff-toggle label')).toBe(staff.text('page.show_inactive', { count: formatCount(1, staff.locale) }));
        expect(text('.admin-staff')).toContain('Luna Hair Studio');

        act(() => document.querySelector<HTMLInputElement>('#staff-show-inactive')?.click());
        expect(document.querySelectorAll('.admin-staff__row')).toHaveLength(3);
        expect(text('.admin-staff')).toContain('Nikos Georgiou');
        // Each row's chip, active Elena's and inactive Nikos's, reads the section's word.
        const chips = Array.from(document.querySelectorAll('.admin-staff__row')).map((row) => row.querySelector('.admin-chip')?.textContent);
        expect(chips[1]).toBe(staff.text('statuses.active'));
        expect(chips[2]).toBe(staff.text('statuses.inactive'));
    });

    it('shows Hours this week beside each member, every week or this week, and opens Shifts from the button beside New staff member', () => {
        render(propsWith({ kind: 'list' }));

        expect(Array.from(document.querySelectorAll('.admin-staff thead th')).map((cell) => cell.textContent)).toEqual((['table.id', 'table.name', 'table.job_title', 'table.email', 'table.status', 'table.hours'] as const).map((key) => staff.text(key)));
        expect(Array.from(document.querySelectorAll('.admin-staff__hours')).map((cell) => cell.textContent)).toEqual([staff.text('table.hours_weekly', { hours: hour(40) }), staff.text('table.hours_this_week', { hours: hour(15) })]);
        expect(Array.from(document.querySelectorAll('.admin-staff__hours a.admin-staff__figure')).map((link) => [link.textContent, link.getAttribute('href')])).toEqual([
            [hour(40), '/admin/shifts'],
            [hour(15), '/admin/shifts'],
        ]);
        expect(text('.admin-staff__count')).toBe(staff.choice('table.count', 2));
        expect(text('.admin-staff h2')).toBe(staff.text('table.title'));
        expect(text('.admin-staff-shifts')).toBe(staff.text('page.shifts'));
        expect(text('.admin-staff-new')).toBe(staff.text('page.new'));
        expect(text('.admin-staff-screen h1')).toBe(staff.text('page.title'));
        expect(inertia.title).toBe(staff.text('page.title'));

        press(document.querySelector('.admin-staff-shifts'));
        expect(inertia.get.mock.lastCall?.[0]).toBe('/admin/shifts');
    });

    it('prints the closures under the title Closures in d/m/Y and H:i, sends an add as a POST and a removal as a DELETE carrying the date and window and no id', () => {
        render(propsWith({ kind: 'list' }));

        expect(text('.admin-blackouts h2')).toBe(staff.text('page.closures'));
        expect(Array.from(document.querySelectorAll('.admin-blackouts__when')).map((cell) => cell.textContent)).toEqual(['25/12/2026', staff.text('closures.window', { date: '31/12/2026', time: '14:00', end: '18:00' })]);

        press(document.querySelectorAll('.admin-blackouts__delete')[1] ?? null);
        const [url, body, removal] = lastVisit(inertia.delete);
        expect(url).toBe('/admin/blackouts');
        expect(body).toEqual({ date: '2026-12-31', until: null, start_time: '14:00', end_time: '18:00', reason: null });
        start(removal);
        expect(document.querySelectorAll('.admin-blackouts__delete')[1]?.getAttribute('aria-busy')).toBe('true');
        expect(document.querySelectorAll('.admin-blackouts__delete')[0]?.getAttribute('aria-busy')).toBeNull();
        expect(document.querySelector('.admin-blackouts__add')?.getAttribute('aria-busy')).toBeNull();
        expect(text('.admin-blackouts__wait')).toBe(staff.text('closures.wait_removing'));
        succeed(removal);
        expect(document.querySelector('.admin-blackouts__wait')).toBeNull();

        // The grid opens on the tenant's today; that day is picked, whatever the clock says.
        press(document.querySelector('.admin-blackouts__date'));
        const today = document.querySelector('button[data-date][aria-current="date"]');
        press(today);
        press(document.querySelector('.admin-blackouts__add'));
        const [addUrl, addBody, visit] = lastVisit(inertia.post);
        expect(addUrl).toBe('/admin/blackouts');
        expect(addBody).toEqual({ date: today?.getAttribute('data-date'), until: null, start_time: null, end_time: null, reason: null });
        start(visit);
        fail(visit, { status: 400, data: { code: 'invalid_request', data: { detail: 'closure-refusal-token' } } });
        expect(text('.admin-toast')).toContain('closure-refusal-token');

        // A closure write that reached no server toasts the page's own sentence.
        press(document.querySelector('.admin-blackouts__date'));
        press(document.querySelector('button[data-date][aria-current="date"]'));
        press(document.querySelector('.admin-blackouts__add'));
        const [, , offline] = lastVisit(inertia.post);
        start(offline);
        fail(offline);
        expect(toasts()).toContain(staff.text('page.closure_error'));
    });
});

/** A roster whose every row is inactive, the implicit one included: the shape a tenant deactivating everyone leaves. */
const ALL_INACTIVE: StaffMember[] = [{ ...LUNA, status: 'inactive' }, { ...ELENA, status: 'inactive' }, NIKOS];

const NO_ACTIVE = `${staff.text('page.none')}${staff.text('page.none_sentence')}`;

describe('the list over a roster whose every row is inactive', () => {
    it.each([
        ['with the staff feature', ['staff']],
        ['without the staff feature', []],
    ])('reads No active staff beside the toggle naming the count %s', (_label, entitlements) => {
        render({ ...propsWith({ kind: 'list' }, ALL_INACTIVE), entitlements, narrowed: [] });

        expect(document.querySelectorAll('.admin-staff__row')).toHaveLength(0);
        expect(text('.admin-staff-empty')).toBe(NO_ACTIVE);
        expect(text('.admin-staff-toggle label')).toBe(staff.text('page.show_inactive', { count: formatCount(3, staff.locale) }));
    });

    it.each([
        ['with the staff feature', ['staff']],
        ['without the staff feature', []],
    ])('lists every member once the toggle is ticked %s', (_label, entitlements) => {
        render({ ...propsWith({ kind: 'list' }, ALL_INACTIVE), entitlements, narrowed: [] });

        act(() => document.querySelector<HTMLInputElement>('#staff-show-inactive')?.click());
        expect(Array.from(document.querySelectorAll('.admin-staff__id')).map((cell) => cell.textContent)).toEqual(['1', '2', '3']);
        expect(document.querySelector('.admin-staff-empty')).toBeNull();
    });
});

/** Sets a select's value as a person's pick does. */
function choose(selector: string, value: string): void {
    const field = document.querySelector(selector);

    if (!(field instanceof HTMLSelectElement)) {
        throw new Error(`No select at ${selector}.`);
    }

    act(() => {
        field.value = value;
        field.dispatchEvent(new Event('change', { bubbles: true }));
    });
}

function texts(selector: string): (string | null)[] {
    return Array.from(document.querySelectorAll(selector)).map((element) => element.textContent);
}

describe('the list screen', () => {
    it('leads with the sentence under the title and filters the rows by name, email or title, case-blind, beside the inactive toggle', () => {
        render(propsWith({ kind: 'list' }, [LUNA, { ...ELENA, title: 'Senior stylist' }, NIKOS]));

        expect(text('.admin-staff-lead')).toBe(staff.text('page.lead'));
        expect(text('label[for="staff-search"]')).toBe(staff.text('page.search'));
        expect(document.querySelector('#staff-search')?.getAttribute('placeholder')).toBe(staff.text('page.search_placeholder'));
        expect(document.querySelector('#staff-search')?.getAttribute('type')).toBe('search');

        type('#staff-search', 'eLeNa');
        expect(texts('.admin-staff__id')).toEqual(['2']);
        expect(text('.admin-staff__count')).toBe(staff.choice('table.count', 1));
        type('#staff-search', 'SENIOR');
        expect(texts('.admin-staff__id')).toEqual(['2']);
        type('#staff-search', '@luna.EXAMPLE');
        expect(texts('.admin-staff__id')).toEqual(['2']);

        type('#staff-search', 'nikos');
        expect(texts('.admin-staff__id')).toEqual([]);
        expect(text('.admin-staff-no-match')).toBe(staff.text('page.no_match'));
        act(() => document.querySelector<HTMLInputElement>('#staff-show-inactive')?.click());
        expect(texts('.admin-staff__id')).toEqual(['3']);

        type('#staff-search', '');
        expect(texts('.admin-staff__id')).toEqual(['1', '2', '3']);
    });

    it('draws each name after a dot in the member\'s calendar colour as a link to their page, the Title column, and the hours figure linking to Shifts', () => {
        render(propsWith({ kind: 'list' }, [LUNA, { ...ELENA, title: 'Senior stylist', colour: 'purple' }, NIKOS]));

        expect(Array.from(document.querySelectorAll<HTMLElement>('.admin-staff__dot')).map((dot) => dot.style.backgroundColor)).toEqual(['rgb(86, 180, 233)', 'rgb(204, 121, 167)']);
        expect(Array.from(document.querySelectorAll('a.admin-staff__name')).map((link) => [link.textContent, link.getAttribute('href')])).toEqual([
            ['Luna Hair Studio', '/admin/staff/1'],
            ['Elena Papadaki', '/admin/staff/2'],
        ]);
        expect(texts('.admin-staff__title')).toEqual(['', 'Senior stylist']);
        expect(Array.from(document.querySelectorAll('a.admin-staff__figure')).map((link) => [link.textContent, link.getAttribute('href')])).toEqual([
            [hour(40), '/admin/shifts'],
            [hour(15), '/admin/shifts'],
        ]);

        press(document.querySelectorAll('a.admin-staff__name')[1] ?? null);
        expect(inertia.get.mock.lastCall?.[0]).toBe('/admin/staff/2');
    });

    it('leaves a modified or middle click on a name to the browser, opening nothing in the page', () => {
        render(propsWith({ kind: 'list' }, [LUNA, ELENA, NIKOS]));
        const link = document.querySelectorAll('a.admin-staff__name')[1] ?? null;

        for (const init of [{ ctrlKey: true }, { metaKey: true }, { shiftKey: true }, { button: 1 }]) {
            const click = new MouseEvent('click', { bubbles: true, cancelable: true, ...init });
            act(() => {
                link?.dispatchEvent(click);
            });
            expect(click.defaultPrevented).toBe(false);
        }

        expect(inertia.get).not.toHaveBeenCalled();
    });

    it('names the week\'s closures after the hours, a window by its weekday and times, a whole day by its weekday alone, joined as the language joins a list', () => {
        const closed = { ...LUNA, hours: { minutes: 2280, weekly: true, closures: [{ date: '2026-12-09', start: '14:00', end: '16:00' }, { date: '2026-12-11', start: null, end: null }] } };
        render(propsWith({ kind: 'list' }, [closed, ELENA, NIKOS]));

        const wednesday = staff.text('table.closed_window', { day: weekdayName(3, 'short', 'en'), time: '14:00', end: '16:00' });
        expect(text('.admin-staff__hours')).toBe(staff.text('table.hours_weekly_closed', { hours: hour(38), closures: listOf([wednesday, weekdayName(5, 'short', 'en')], 'en') }));
        expect(text('.admin-staff__hours a.admin-staff__figure')).toBe(hour(38));
    });

    it('lists every closure with its member or the whole business and its reason, a run folded into one line, and sends each write to its scope\'s route with Until', () => {
        const lines: ClosureLine[] = [
            { staff_id: 2, date: '2026-12-04', until: null, start: '14:00', end: '16:00', reason: 'Dentist' },
            { staff_id: null, date: '2026-12-24', until: '2027-01-02', start: null, end: null, reason: 'Christmas' },
            { staff_id: null, date: '2027-01-11', until: null, start: null, end: null, reason: null },
        ];
        render(propsWith({ kind: 'list' }, [LUNA, ELENA, NIKOS], lines));

        expect(texts('.admin-blackouts__when')).toEqual([staff.text('closures.window', { date: '04/12/2026', time: '14:00', end: '16:00' }), staff.text('closures.run', { date: '24/12/2026', until: '02/01/2027' }), '11/01/2027']);
        expect(texts('.admin-blackouts__who')).toEqual([
            staff.text('closures.who_reason', { who: 'Elena Papadaki', reason: 'Dentist' }),
            staff.text('closures.who_reason', { who: staff.text('closures.business'), reason: 'Christmas' }),
            staff.text('closures.business'),
        ]);
        expect(text('label[for="blackout-who"]')).toBe(staff.text('closures.who'));
        expect(texts('#blackout-who option')).toEqual([staff.text('closures.business'), 'Elena Papadaki']);
        expect(text('label[for="blackout-until"]')).toBe(staff.text('closures.until'));
        expect(text('#blackout-until-help')).toBe(staff.text('closures.until_help'));

        press(document.querySelectorAll('.admin-blackouts__delete')[0] ?? null);
        expect(lastVisit(inertia.delete).slice(0, 2)).toEqual(['/admin/staff/2/blackouts', { date: '2026-12-04', until: null, start_time: '14:00', end_time: '16:00', reason: 'Dentist' }]);
        press(document.querySelectorAll('.admin-blackouts__delete')[1] ?? null);
        expect(lastVisit(inertia.delete).slice(0, 2)).toEqual(['/admin/blackouts', { date: '2026-12-24', until: '2027-01-02', start_time: null, end_time: null, reason: 'Christmas' }]);

        choose('#blackout-who', '2');
        press(document.querySelector('.admin-blackouts__date'));
        const today = document.querySelector('button[data-date][aria-current="date"]')?.getAttribute('data-date');
        press(document.querySelector('button[data-date][aria-current="date"]'));
        press(document.querySelector('.admin-blackouts__until'));
        press(document.querySelector('button[data-date][aria-current="date"]'));
        type('#blackout-reason', ' Course ');
        press(document.querySelector('.admin-blackouts__add'));
        expect(lastVisit(inertia.post).slice(0, 2)).toEqual(['/admin/staff/2/blackouts', { date: today, until: today, start_time: null, end_time: null, reason: 'Course' }]);
    });

    it('offers Calendar colours to a holder of manageSettings: Colour bookings by on the settings route, and each active member\'s own colour, Automatic or one of the palette\'s seven', () => {
        render(propsWith({ kind: 'list' }, [LUNA, { ...ELENA, colour: 'purple' }, NIKOS]));

        expect(text('.admin-staff-colours h2')).toBe(staff.text('colours.title'));
        expect(text('.admin-staff-colours__by legend')).toBe(staff.text('colours.by'));
        expect(texts('.admin-staff-colours__by label')).toEqual((['staff', 'category', 'status'] as const).map((choice) => staff.text(`colours.by_choices.${choice}`)));
        expect(Array.from(document.querySelectorAll<HTMLInputElement>('input[name="staff-colour-by"]')).map((radio) => [radio.value, radio.checked])).toEqual([
            ['staff', true],
            ['category', false],
            ['status', false],
        ]);
        expect(texts('.admin-staff-colours__help')).toEqual([staff.text('colours.by_help'), staff.text('colours.members_help')]);

        act(() => document.querySelector<HTMLInputElement>('input[name="staff-colour-by"][value="category"]')?.click());
        expect(lastVisit(inertia.put).slice(0, 2)).toEqual(['/admin/settings/colour-by', { colour_by: 'category' }]);

        expect(texts('.admin-staff-colours__member legend')).toEqual(['Elena Papadaki']);
        expect(texts('.admin-staff-colours__member[data-staff="2"] label')).toEqual([staff.text('colours.automatic'), ...(['orange', 'sky_blue', 'green', 'yellow', 'blue', 'vermilion', 'purple'] as const).map((colour) => staff.text(`colours.names.${colour}`))]);
        expect(Array.from(document.querySelectorAll<HTMLElement>('.admin-staff-colours__member[data-staff="2"] .admin-staff-colours__swatch')).slice(1).map((swatch) => swatch.style.backgroundColor)).toEqual([
            'rgb(230, 159, 0)',
            'rgb(86, 180, 233)',
            'rgb(0, 158, 115)',
            'rgb(240, 228, 66)',
            'rgb(0, 114, 178)',
            'rgb(213, 94, 0)',
            'rgb(204, 121, 167)',
        ]);
        expect(document.querySelector<HTMLInputElement>('input[name="staff-colour-2"][value="purple"]')?.checked).toBe(true);

        act(() => document.querySelector<HTMLInputElement>('input[name="staff-colour-2"][value="green"]')?.click());
        expect(lastVisit(inertia.put).slice(0, 2)).toEqual(['/admin/staff/2/colour', { colour: 'green' }]);
        act(() => document.querySelector<HTMLInputElement>('input[name="staff-colour-2"][value=""]')?.click());
        expect(lastVisit(inertia.put).slice(0, 2)).toEqual(['/admin/staff/2/colour', { colour: null }]);

        // While a colour is saving the card says so and holds its controls.
        expect(document.querySelector('.admin-staff-colours__saving')).toBeNull();
        const visit = lastVisit(inertia.put)[2];
        act(() => visit.onStart?.());
        expect(text('.admin-staff-colours__saving')).toBe(staff.text('colours.saving'));
        expect(document.querySelector('.admin-staff-colours__saving')?.getAttribute('role')).toBe('status');
        expect(document.querySelector<HTMLInputElement>('input[name="staff-colour-2"][value="green"]')?.disabled).toBe(true);
        act(() => visit.onFinish?.());
        expect(document.querySelector('.admin-staff-colours__saving')).toBeNull();

        render({ ...propsWith({ kind: 'list' }), permissions: ['viewBookings'] });
        expect(document.querySelector('.admin-staff-colours')).toBeNull();
        render(withoutFeature({ kind: 'list' }));
        expect(document.querySelector('.admin-staff-colours')).toBeNull();
    });

    it('keeps a member\'s closures card out of their form, so its Add sends the closure and Save the member, each from its own form', () => {
        render(propsWith({ kind: 'detail', id: 2 }));

        expect(document.querySelector('.admin-blackouts')?.closest('form')).toBeNull();
        expect(document.querySelector('.admin-blackouts form')).not.toBeNull();
        expect(document.querySelector('#blackout-who, #exception-who')).toBeNull();
        expect(text('label[for="exception-until"]')).toBe(staff.text('closures.until'));

        press(document.querySelector('.admin-blackouts__date'));
        press(document.querySelector('button[data-date][aria-current="date"]'));
        press(document.querySelector('.admin-blackouts__add'));
        expect(lastVisit(inertia.post)[0]).toBe('/admin/staff/2/blackouts');
        expect(inertia.put).not.toHaveBeenCalled();

        press(document.querySelector('.admin-staff-save'));
        expect(lastVisit(inertia.put)[0]).toBe('/admin/staff/2');
    });
});

describe('the detail page', () => {
    it('renders Basics, Services performed with the inactive service marked and tickable, the pattern\'s read-only line linking to Shifts and no weekly editor, and Exceptions and Danger zone on a persisted row alone', () => {
        render(propsWith({ kind: 'detail', id: 2 }));

        expect(text('.admin-breadcrumb__current')).toBe('Elena Papadaki');
        expect(value('#staff-name')).toBe('Elena Papadaki');
        expect(text('label[for="staff-service-9"]')).toBe(staff.text('form.inactive_service', { service: 'Perm' }));
        expect(text('.admin-breadcrumb__list')).toBe(staff.text('form.list'));
        expect(document.querySelector('.admin-breadcrumb')?.getAttribute('aria-label')).toBe(staff.text('form.breadcrumb'));
        expect(Array.from(document.querySelectorAll('.admin-section h2')).map((heading) => heading.textContent)).toEqual([
            staff.text('form.basics'),
            staff.text('form.services'),
            staff.text('form.weekly'),
            staff.text('danger.title'),
        ]);
        expect((['name', 'email', 'user', 'status', 'title', 'bio', 'photo'] as const).map((field) => text(`label[for="staff-${field}"]`))).toEqual(
            (['form.name', 'form.email', 'form.user', 'form.status', 'form.title', 'form.bio', 'form.photo'] as const).map((key) => staff.text(key)),
        );
        expect(text('#staff-email-help')).toBe(staff.text('form.email_help'));
        expect(text('#staff-user-help')).toBe(staff.text('form.user_help'));
        expect(Array.from(document.querySelectorAll('#staff-status option')).map((option) => option.textContent)).toEqual([staff.text('statuses.active'), staff.text('statuses.inactive')]);
        expect(text('.admin-staff-save')).toBe(staff.text('form.save'));
        expect(text('.admin-staff-delete')).toBe(staff.text('danger.delete'));
        expect(inertia.title).toBe(staff.text('page.detail_title'));
        expect(document.querySelector('#staff-service-7')).toHaveProperty('checked', true);
        expect(document.querySelector('.admin-weekly')).toBeNull();
        expect(text('.admin-section--weekly .admin-staff-pattern')).toBe(ELENA_PATTERN);
        expect(text('.admin-blackouts h2')).toBe(staff.text('page.exceptions'));
        expect(text('.admin-blackouts__when')).toBe(DENTIST);
        expect(document.querySelector('.admin-section--danger')).not.toBeNull();
        expect(document.querySelector('.admin-section--weekly a.admin-staff-shifts-link')?.getAttribute('href')).toBe('/admin/shifts');

        render(propsWith({ kind: 'detail', id: 1 }));
        expect(text('.admin-staff-pattern__line')).toBe(staff.text('pattern.weekly', { hours: hour(40) }));
        render(propsWith({ kind: 'detail', id: 3 }));
        expect(text('.admin-staff-pattern__line')).toBe(staff.text('pattern.none'));

        render(propsWith({ kind: 'detail', id: 2 }));
        act(() => document.querySelector<HTMLInputElement>('#staff-service-9')?.click());
        expect(document.querySelector('#staff-service-9')).toHaveProperty('checked', true);

        render(propsWith({ kind: 'create' }));
        expect(text('.admin-breadcrumb__current')).toBe(staff.text('page.new'));
        expect(inertia.title).toBe(staff.text('page.new'));
        expect(document.querySelector('.admin-blackouts')).toBeNull();
        expect(document.querySelector('.admin-section--danger')).toBeNull();
        expect(document.querySelector('.admin-staff-save')).toHaveProperty('disabled', true);
        expect(text('.admin-save-bar__reason')).toBe(staff.text('form.needs_name'));
    });

    it('renders Offered online in Basics ticked by default with help naming the person, seeds a member kept off the page unticked, and sends the flag', () => {
        render(propsWith({ kind: 'create' }));

        expect((document.querySelector('.admin-section--basics #staff-online') as HTMLInputElement).checked).toBe(true);
        expect(text('label[for="staff-online"]')).toBe(staff.text('form.online'));
        expect(document.querySelector('#staff-online')?.getAttribute('aria-describedby')).toBe('staff-online-help');
        expect(text('#staff-online-help')).toBe(staff.text(STAFF_ONLINE_HELP));

        render(propsWith({ kind: 'detail', id: 2 }, [LUNA, { ...ELENA, online: false }, NIKOS]));
        expect((document.querySelector('#staff-online') as HTMLInputElement).checked).toBe(false);

        press(document.querySelector('#staff-online'));
        press(document.querySelector('.admin-staff-save'));
        expect(lastVisit(inertia.put)[1]).toMatchObject({ online: true });
    });

    it('renders Title and Bio in Basics with their help and the bio counted, sends them trimmed and blank as null, and re-seeds them from the answer', () => {
        render(propsWith({ kind: 'detail', id: 2 }, [LUNA, { ...ELENA, title: 'Senior stylist', bio: 'Ten years at the chair.' }, NIKOS]));

        expect(text('label[for="staff-title"]')).toBe(staff.text('form.title'));
        expect(value('.admin-section--basics #staff-title')).toBe('Senior stylist');
        expect(document.querySelector('#staff-title')?.getAttribute('aria-describedby')).toBe('staff-title-help');
        expect(text('#staff-title-help')).toBe(staff.text(TITLE_HELP));
        expect(text('label[for="staff-bio"]')).toBe(staff.text('form.bio'));
        expect(document.querySelector('.admin-section--basics textarea#staff-bio')).not.toBeNull();
        expect(value('#staff-bio')).toBe('Ten years at the chair.');
        expect(text('#staff-bio-help')).toBe(staff.text(BIO_HELP));
        expect(text('.admin-staff-bio__count')).toBe(bioCount(23, staff));
        expect(bioCount(23, staff)).toBe(staff.choice('form.bio_count', 23, { max: String(BIO_LIMIT) }));
        expect(bioCount(1, staff)).toBe(staff.choice('form.bio_count', 1, { max: String(BIO_LIMIT) }));
        expect(document.querySelector('#staff-bio')?.getAttribute('aria-describedby')).toBe('staff-bio-help staff-bio-count');
        expect(text('#staff-bio-count')).toBe(bioCount(23, staff));

        type('#staff-bio', 'Ten years at the chair. Ask about balayage.  ');
        expect(text('.admin-staff-bio__count')).toBe(bioCount(45, staff));
        type('#staff-title', '  ');
        press(document.querySelector('.admin-staff-save'));

        const [, body, visit] = lastVisit(inertia.put);
        expect(body).toMatchObject({ title: null, bio: 'Ten years at the chair. Ask about balayage.' });

        start(visit);
        land(visit, propsWith({ kind: 'detail', id: 2 }, [LUNA, { ...ELENA, title: null, bio: 'Ten years at the chair. Ask about balayage.' }, NIKOS]));
        expect(value('#staff-title')).toBe('');
        expect(value('#staff-bio')).toBe('Ten years at the chair. Ask about balayage.');

        render(propsWith({ kind: 'create' }));
        expect(value('#staff-title')).toBe('');
        expect(text('.admin-staff-bio__count')).toBe(bioCount(0, staff));
    });

    it('offers the photo control on a persisted member alone, never on the create view or the implicit member', () => {
        render(propsWith({ kind: 'detail', id: 2 }));

        expect(text('label[for="staff-photo"]')).toBe(staff.text('form.photo'));
        expect(document.querySelector('.admin-section--basics .admin-staff-photo input[type="file"]#staff-photo')).not.toBeNull();
        expect(text('#staff-photo-help')).toBe(staff.choice(PHOTO_HELP, PHOTO_LIMIT.megabytes, { n: PHOTO_LIMIT.spelled }));
        expect(text('.admin-staff-photo__none')).toBe(staff.text('page.photo_none'));
        expect(text('.admin-staff-photo-upload')).toBe(staff.text('page.photo_upload'));
        expect(document.querySelector('.admin-staff-photo-remove')).toBeNull();

        render(propsWith({ kind: 'create' }));
        expect(document.querySelector('.admin-staff-photo')).toBeNull();
        expect(document.querySelector('#staff-photo')).toBeNull();

        render(propsWith({ kind: 'detail', id: 1 }));
        expect(document.querySelector('.admin-staff-photo')).toBeNull();
    });

    it('withholds Title and Bio on the implicit member as the photo is, says what unticking Offered online does for it, and sends neither', () => {
        render(propsWith({ kind: 'detail', id: 1 }, [{ ...LUNA, title: 'Kept as stored', bio: 'Kept as stored.' }, ELENA, NIKOS]));

        expect(document.querySelector('#staff-title')).toBeNull();
        expect(document.querySelector('#staff-bio')).toBeNull();
        expect(document.querySelector('.admin-staff-bio__count')).toBeNull();
        expect(document.querySelector('#staff-photo')).toBeNull();
        expect(text('label[for="staff-online"]')).toBe(staff.text('form.online'));
        expect(text('#staff-online-help')).toBe(staff.text(IMPLICIT_ONLINE_HELP));

        type('#staff-name', 'Luna Hair & Beauty');
        press(document.querySelector('.admin-staff-save'));
        const body = lastVisit(inertia.put)[1];
        expect(body).toMatchObject({ name: 'Luna Hair & Beauty', online: true });
        expect(body).not.toHaveProperty('title');
        expect(body).not.toHaveProperty('bio');

        render(propsWith({ kind: 'detail', id: 2 }));
        expect(text('#staff-online-help')).toBe(staff.text(STAFF_ONLINE_HELP));
        expect(document.querySelector('#staff-title')).not.toBeNull();
    });

    it('uploads a chosen photo as its own write, busy alone with Save held, shows the answer, and leaves an edit in progress alone', () => {
        render(propsWith({ kind: 'detail', id: 2 }));
        type('#staff-title', 'Colourist');

        const file = new File(['png bytes'], 'elena.png', { type: 'image/png' });
        chooseFile('#staff-photo', file);
        press(document.querySelector('.admin-staff-photo-upload'));

        const [url, body, visit] = lastVisit(inertia.post);
        expect(url).toBe('/admin/staff/2/photo');
        expect(body).toEqual({ photo: file });
        expect(visit.forceFormData).toBe(true);

        start(visit);
        expect(document.querySelector('.admin-staff-photo-upload')?.getAttribute('aria-busy')).toBe('true');
        expect(document.querySelector('.admin-staff-save')).toHaveProperty('disabled', true);
        expect(text('.admin-save-bar__reason')).toBe(staff.text('page.wait_photo_upload'));
        expect(document.querySelector('.admin-staff-save')?.getAttribute('aria-busy')).toBeNull();

        land(visit, propsWith({ kind: 'detail', id: 2 }, [LUNA, { ...ELENA, photo: PHOTO }, NIKOS]));
        expect(document.querySelector('.admin-staff-photo__image')?.getAttribute('src')).toBe(PHOTO.url);
        expect(text('.admin-staff-photo__name')).toBe(PHOTO.name);
        expect(text('.admin-staff-photo-upload')).toBe(staff.text('page.photo_replace'));
        expect(text('.admin-staff-photo-remove')).toBe(staff.text('page.photo_remove'));
        expect(document.querySelector<HTMLButtonElement>('.admin-staff-photo-upload')?.disabled).toBe(true);
        expect(document.querySelector('.admin-staff-save')).toHaveProperty('disabled', false);
        expect(value('#staff-title')).toBe('Colourist');
    });

    it('removes the photo as its own DELETE, holds the photo while Save is in flight, and toasts a refusal in its sentence', () => {
        render(propsWith({ kind: 'detail', id: 2 }, [LUNA, { ...ELENA, photo: PHOTO }, NIKOS]));

        press(document.querySelector('.admin-staff-save'));
        const [, , saving] = lastVisit(inertia.put);
        start(saving);
        expect(document.querySelector<HTMLButtonElement>('.admin-staff-photo-remove')?.disabled).toBe(true);
        expect(text('.admin-staff-photo__wait')).toBe(staff.text('page.wait_save'));
        expect(document.querySelector('.admin-staff-save')?.textContent).toBe(staff.text('form.saving'));
        succeed(saving);

        press(document.querySelector('.admin-staff-photo-remove'));
        const [url, , visit] = lastVisit(inertia.delete);
        expect(url).toBe('/admin/staff/2/photo');
        start(visit);
        expect(document.querySelector('.admin-staff-photo-remove')?.getAttribute('aria-busy')).toBe('true');
        expect(text('.admin-save-bar__reason')).toBe(staff.text('page.wait_photo_remove'));

        fail(visit, { status: 403, data: { code: 'subscription_required', data: { detail: 'subscription-refusal-token' } } });
        expect(text('.admin-toast')).toContain('subscription-refusal-token');
        expect(document.querySelector('.admin-staff-photo__image')).not.toBeNull();
        expect(document.querySelector('.admin-staff-save')).toHaveProperty('disabled', false);
    });

    it('withholds Delete on the implicit member, the Danger zone saying why, while the rest of the page stays editable', () => {
        render(propsWith({ kind: 'detail', id: 1 }));

        expect(text('.admin-breadcrumb__current')).toBe('Luna Hair Studio');
        expect(document.querySelector('.admin-section--danger')).not.toBeNull();
        expect(document.querySelector('.admin-staff-delete')).toBeNull();
        expect(text('.admin-staff-implicit')).toBe(staff.text('refusals.implicit'));
        expect(text('.admin-section--danger h2')).toBe(staff.text('danger.title'));
        expect(text('.admin-blackouts h2')).toBe(staff.text('page.exceptions'));
        expect(document.querySelector('.admin-staff-save')).toHaveProperty('disabled', false);

        render(propsWith({ kind: 'detail', id: 2 }));
        expect(document.querySelector('.admin-staff-delete')).not.toBeNull();
        expect(document.querySelector('.admin-staff-implicit')).toBeNull();
    });

    it('lists the tenant users in the combobox from the props, changes the linked user on a pick alone, and Clear unlinks', () => {
        render(propsWith({ kind: 'detail', id: 2 }));

        type('#staff-user', 'Mel');
        expect(Array.from(document.querySelectorAll('.admin-combobox__option')).map((option) => option.textContent)).toEqual([staff.text('form.user_option', { user: 'Mel Manager', address: 'mel@luna.example' })]);
        press(document.querySelector('.admin-staff-save'));
        expect(lastVisit(inertia.put)[1].user_id).toBeNull();

        act(() => document.querySelector('.admin-combobox__option')?.dispatchEvent(new MouseEvent('mousedown', { bubbles: true, cancelable: true })));
        press(document.querySelector('.admin-staff-save'));
        expect(lastVisit(inertia.put)[1].user_id).toBe(12);

        press(document.querySelector('.admin-combobox__clear'));
        press(document.querySelector('.admin-staff-save'));
        expect(lastVisit(inertia.put)[1].user_id).toBeNull();
    });

    it('sends the links replace-all and no week, re-seeds from the answer once landed, and leaves an edit alone on a background refetch', () => {
        render(propsWith({ kind: 'detail', id: 2 }));

        act(() => document.querySelector<HTMLInputElement>('#staff-service-9')?.click());
        press(document.querySelector('.admin-staff-save'));

        const [url, body, visit] = lastVisit(inertia.put);
        expect(url).toBe('/admin/staff/2');
        expect(body).toEqual({ name: 'Elena Papadaki', email: 'elena@luna.example', user_id: null, status: 'active', online: true, title: null, bio: null, service_ids: [7, 9] });

        start(visit);
        expect(document.querySelector('.admin-staff-save')).toHaveProperty('disabled', true);
        const normalised = { ...ELENA, service_ids: [9, 7] };
        land(visit, propsWith({ kind: 'detail', id: 2 }, [LUNA, normalised, NIKOS]));
        expect(document.querySelector('#staff-service-9')).toHaveProperty('checked', true);

        type('#staff-name', 'Elena P.');
        render(propsWith({ kind: 'detail', id: 2 }, [LUNA, { ...normalised, email: 'changed@luna.example' }, NIKOS]));
        expect(value('#staff-name')).toBe('Elena P.');
        expect(value('#staff-email')).toBe('elena@luna.example');
    });

    it('keeps the edits when the server refuses, its sentence an error toast', () => {
        render(propsWith({ kind: 'detail', id: 2 }));

        type('#staff-name', 'Elena P.');
        press(document.querySelector('.admin-staff-save'));

        const [, body, visit] = lastVisit(inertia.put);
        expect(body.name).toBe('Elena P.');
        expect(body).not.toHaveProperty('rules');

        start(visit);
        fail(visit, { status: 400, data: { code: 'invalid_request', data: { detail: 'email-refusal-token' } } });
        expect(text('.admin-toast')).toContain('email-refusal-token');
        expect(value('#staff-name')).toBe('Elena P.');
    });

    it('creates through POST /admin/staff with the links, and renders the referenced conflict inline whose remedy patches the status and leaves for the list', () => {
        render(propsWith({ kind: 'create' }));
        type('#staff-name', 'Maria');
        act(() => document.querySelector<HTMLInputElement>('#staff-service-7')?.click());
        expect(text('.admin-staff-pattern__line')).toBe(staff.text('pattern.none'));
        press(document.querySelector('.admin-staff-save'));
        expect(lastVisit(inertia.post)[0]).toBe('/admin/staff');
        expect(lastVisit(inertia.post)[1]).toEqual({ name: 'Maria', email: null, user_id: null, status: 'active', online: true, title: null, bio: null, service_ids: [7] });

        render(propsWith({ kind: 'detail', id: 2 }));
        press(document.querySelector('.admin-staff-delete'));
        const [, , remove] = lastVisit(inertia.delete);
        start(remove);
        fail(remove, { status: 409, data: { code: 'referenced', data: { detail: 'referenced-refusal-token' } } });
        expect(text('.admin-staff-conflict')).toContain('referenced-refusal-token');
        expect(text('.admin-staff-deactivate')).toBe(staff.text('danger.deactivate'));
        expect(document.querySelector('.admin-toast')).toBeNull();

        press(document.querySelector('.admin-staff-deactivate'));
        const [url, body, visit] = lastVisit(inertia.put);
        expect(url).toBe('/admin/staff/2');
        expect(body).toEqual({ status: 'inactive' });
        start(visit);
        succeed(visit);
        expect(inertia.get.mock.lastCall?.[0]).toBe('/admin/staff');
    });
});

/*
 * Without the staff feature: the screen stays readable and offers no control the server refuses.
 * The list keeps its rows and the tenant-wide closures card, which still adds and removes; New
 * staff member, Save, Delete and the per-member closures form are gone, and every member's hours
 * and closures read as stored under the banner's own sentence. On a floor whose roster holds the
 * implicit row alone the screen is the closures card alone under the heading Closures; any other
 * row beside it, an inactive one included, gives the read-only list, still headed Staff, since a
 * sold member stays readable (6.5). With the feature, the cases above are the proof.
 */

const STAFF_GONE = shell.text('banners.features.staff.gone', { billing: shell.text('banners.billing') });

/**
 * The sentence for a free tenant, whose plan is the console's and which has no Billing to add it
 * on.
 */
const STAFF_GONE_FREE = shell.text('banners.features.staff.gone_free');

const THREE_ACTIVE: StaffMember[] = [LUNA, ELENA, { ...NIKOS, status: 'active' }];

/** The roster a refused-then-honoured downgrade leaves: the implicit member active, every other member deactivated before the feature went. */
const DOWNGRADED: StaffMember[] = [LUNA, { ...ELENA, status: 'inactive' }, NIKOS];

function withoutFeature(view: View, members: StaffMember[] = THREE_ACTIVE, extra: Record<string, unknown> = {}): Record<string, unknown> {
    return { ...propsWith(view, members), entitlements: [], narrowed: [], permissions: ['viewBookings', 'manageSettings', 'manageBilling'], ...extra };
}

describe('the booking link', () => {
    it('copies a named member\'s link as the server spelled it from the title row of the form and of the read-only page, recording the press', async () => {
        const writeText = vi.fn(() => Promise.resolve());
        Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true });

        for (const props of [propsWith({ kind: 'detail', id: 2 }), withoutFeature({ kind: 'detail', id: 2 })]) {
            inertia.post.mockReset();
            writeText.mockClear();
            render(props);

            // The title row's chip, the form's then the read-only page's, reads the section's word.
            expect(text('.admin-staff-title .admin-chip')).toBe(staff.text('statuses.active'));
            const button = document.querySelector('.admin-staff-title .admin-booking-link__copy');
            expect(button?.textContent).toBe(shell.text(COPY_BOOKING_LINK));
            press(button);
            expect(writeText).toHaveBeenCalledWith('http://luna.localhost:8000/?staff=2');
            await act(async () => {
                await Promise.resolve();
            });

            expect(text('.admin-booking-link__copied')).toBe(shell.text(COPIED));
            expect(inertia.post).toHaveBeenCalledWith(LINK_COPIED, {}, { async: true, preserveState: true, preserveScroll: true });
        }
    });

    it('offers no copy for the implicit member, who stands for the business, nor while creating', () => {
        render(propsWith({ kind: 'detail', id: 1 }));
        expect(document.querySelector('.admin-booking-link__copy')).toBeNull();

        render(propsWith({ kind: 'create' }));
        expect(document.querySelector('.admin-booking-link__copy')).toBeNull();
    });
});

describe('without the staff feature', () => {
    it('lists the three active members with no New staff member under the sentence, Billing linked for manageBilling', () => {
        render(withoutFeature({ kind: 'list' }));

        expect(document.querySelector('.admin-staff-new')).toBeNull();
        expect(document.querySelector('.admin-staff-shifts')).toBeNull();
        expect(Array.from(document.querySelectorAll('.admin-staff__id')).map((cell) => cell.textContent)).toEqual(['1', '2', '3']);
        expect(text('.admin-staff-readonly__line')).toBe(STAFF_GONE);
        expect(document.querySelector('.admin-staff-readonly')?.getAttribute('role')).toBe('status');
        expect(document.querySelector('a.admin-staff-readonly__billing')?.getAttribute('href')).toBe('/admin/billing');
    });

    it('keeps the tenant-wide closures card removing a closure beside the read-only list, the row busy while the DELETE is in flight', () => {
        render(withoutFeature({ kind: 'list' }));

        press(document.querySelectorAll('.admin-blackouts__delete')[0] ?? null);
        const [url, body, removal] = lastVisit(inertia.delete);
        expect(url).toBe('/admin/blackouts');
        expect(body).toEqual({ date: '2026-12-25', until: null, start_time: null, end_time: null, reason: 'Christmas Day' });
        start(removal);
        expect(document.querySelectorAll('.admin-blackouts__delete')[0]?.getAttribute('aria-busy')).toBe('true');
    });

    it('keeps the tenant-wide closures card adding a closure beside the read-only list', () => {
        render(withoutFeature({ kind: 'list' }));

        press(document.querySelector('.admin-blackouts__date'));
        const today = document.querySelector('button[data-date][aria-current="date"]');
        press(today);
        press(document.querySelector('.admin-blackouts__add'));
        const [addUrl, addBody] = lastVisit(inertia.post);
        expect(addUrl).toBe('/admin/blackouts');
        expect(addBody).toEqual({ date: today?.getAttribute('data-date'), until: null, start_time: null, end_time: null, reason: null });
    });

    it('names platform support and links nothing on a free tenant, whose plan is the console\'s, on the list and on a member\'s page', () => {
        for (const view of [{ kind: 'list' }, { kind: 'detail', id: 2 }] as View[]) {
            render(withoutFeature(view, THREE_ACTIVE, { free: true }));

            expect(text('.admin-staff-readonly__line')).toBe(STAFF_GONE_FREE);
            expect(document.querySelector('.admin-staff-readonly a')).toBeNull();
            expect(document.querySelector('.admin-staff-readonly__billing')).toBeNull();
        }
    });

    it('names Billing in plain text for a user without manageBilling', () => {
        render(withoutFeature({ kind: 'list' }, THREE_ACTIVE, { permissions: ['viewBookings', 'manageSettings'] }));

        expect(text('.admin-staff-readonly__line')).toBe(STAFF_GONE);
        expect(document.querySelector('.admin-staff-readonly a')).toBeNull();
        expect(document.querySelector('.admin-staff-readonly__billing')?.tagName).toBe('SPAN');
    });

    it('leaves the sentence to the banner when the narrowing record already names the feature, the list still read-only', () => {
        render(withoutFeature({ kind: 'list' }, THREE_ACTIVE, { narrowed: ['staff'] }));

        expect(document.querySelector('.admin-staff-readonly')).toBeNull();
        expect(document.querySelectorAll('.admin-staff__row')).toHaveLength(3);
        expect(document.querySelector('.admin-staff-new')).toBeNull();
    });

    it('shows a member\'s page with no Save, no Delete and no closures form, the hours and closures as stored under the sentence', () => {
        render(withoutFeature({ kind: 'detail', id: 2 }));

        expect(text('.admin-breadcrumb__current')).toBe('Elena Papadaki');
        expect(text('.admin-staff-view .admin-staff-readonly__line')).toBe(STAFF_GONE);
        expect(text('.admin-staff-view__email')).toBe('elena@luna.example');
        expect(text('.admin-staff-view__user')).toBe(staff.text('view.none'));
        expect(Array.from(document.querySelectorAll('.admin-staff-view__service')).map((service) => service.textContent)).toEqual(['Haircut']);
        expect(document.querySelector('.admin-staff-view__day')).toBeNull();
        expect(text('.admin-staff-view .admin-staff-pattern')).toBe(ELENA_PATTERN);
        expect(text('.admin-staff-view__closure')).toBe(`${DENTIST} Dentist`);
        expect(document.querySelector('.admin-section--weekly a.admin-staff-shifts-link')?.getAttribute('href')).toBe('/admin/shifts');
        expect(Array.from(document.querySelectorAll('.admin-staff-view dt')).map((label) => label.textContent)).toEqual(
            (['view.email', 'view.user', 'view.online', 'view.title', 'view.bio', 'view.photo'] as const).map((key) => staff.text(key)),
        );
        expect(Array.from(document.querySelectorAll('.admin-staff-view .admin-section h2')).map((heading) => heading.textContent)).toEqual([
            staff.text('form.basics'),
            staff.text('form.services'),
            staff.text('form.weekly'),
            staff.text('page.exceptions'),
        ]);
        expect(document.querySelector('.admin-staff-save')).toBeNull();
        expect(document.querySelector('.admin-staff-delete')).toBeNull();
        expect(document.querySelector('.admin-blackouts')).toBeNull();
        // No control that would write the member; the one button is Copy booking link, which
        // configures nothing.
        expect(document.querySelectorAll('.admin-staff-detail input, .admin-staff-detail select, .admin-staff-detail textarea, .admin-staff-detail button:not(.admin-booking-link__copy)')).toHaveLength(0);
        expect(text('.admin-staff-title .admin-booking-link__copy')).toBe(shell.text(COPY_BOOKING_LINK));
    });

    it('shows a named member\'s presentation read-only in Basics, Offered online, Title, Bio and the photo, each as stored', () => {
        render(withoutFeature({ kind: 'detail', id: 2 }, [LUNA, { ...ELENA, online: false, title: 'Senior stylist', bio: 'Ten years at the chair.\nAsk about balayage.', photo: PHOTO }, NIKOS]));

        expect(text('.admin-staff-view__online')).toBe(staff.text('view.no'));
        expect(text('.admin-staff-view__title')).toBe('Senior stylist');
        expect(text('.admin-staff-view__bio')).toBe('Ten years at the chair.\nAsk about balayage.');
        expect(document.querySelector('.admin-staff-view__photo img')?.getAttribute('src')).toBe(PHOTO.url);
        expect(text('.admin-staff-view__photo')).toBe(PHOTO.name);

        render(withoutFeature({ kind: 'detail', id: 3 }));
        expect(text('.admin-staff-view__online')).toBe(staff.text('view.yes'));
        expect(text('.admin-staff-view__title')).toBe(staff.text('view.none'));
        expect(text('.admin-staff-view__bio')).toBe(staff.text('view.none'));
        expect(text('.admin-staff-view__photo')).toBe(staff.text('view.none'));
        expect(document.querySelector('.admin-staff-view__photo img')).toBeNull();
    });

    it('withholds Title, Bio and the photo on the implicit member\'s read-only page as its form does, keeping Offered online', () => {
        render(withoutFeature({ kind: 'detail', id: 1 }, [{ ...LUNA, online: false, title: 'Kept as stored', bio: 'Kept as stored.' }, ELENA, NIKOS]));

        expect(text('.admin-staff-view__online')).toBe(staff.text('view.no'));
        expect(document.querySelector('.admin-staff-view__title')).toBeNull();
        expect(document.querySelector('.admin-staff-view__bio')).toBeNull();
        expect(document.querySelector('.admin-staff-view__photo')).toBeNull();
        expect(document.body.textContent).not.toContain('Kept as stored');
    });

    it('shows the implicit member\'s page with its empty states, no closure and no service on record, and no control', () => {
        render(withoutFeature({ kind: 'detail', id: 1 }));

        expect(text('.admin-breadcrumb__current')).toBe('Luna Hair Studio');
        expect(text('.admin-section--exceptions .admin-staff-view__none')).toBe(staff.text('view.no_closures'));
        expect(document.querySelector('.admin-staff-view__closures')).toBeNull();
        expect(text('.admin-section--services .admin-staff-view__none')).toBe(staff.text('view.none'));
        expect(document.querySelector('.admin-staff-view__services')).toBeNull();
        expect(document.querySelectorAll('.admin-staff-detail input, .admin-staff-detail button')).toHaveLength(0);
    });

    it('sends the breadcrumb\'s Back press on a member\'s read-only page to the list', () => {
        render(withoutFeature({ kind: 'detail', id: 1 }));

        press(document.querySelector('.admin-breadcrumb__list'));
        expect(lastVisit(inertia.get)[0]).toBe('/admin/staff');
    });

    it('shows the tenant-wide closures and no member on a floor whose roster holds the implicit row alone, on the list and on its page reached by URL', () => {
        render(withoutFeature({ kind: 'list' }, [LUNA]));
        expect(document.querySelector('.admin-staff-screen--floor')).not.toBeNull();
        expect(document.querySelector('.admin-staff')).toBeNull();
        expect(document.querySelector('.admin-staff-toggle')).toBeNull();
        expect(document.querySelector('.admin-staff-new')).toBeNull();
        expect(document.querySelector('.admin-staff-readonly')).toBeNull();
        expect(text('.admin-blackouts h2')).toBe(CLOSED_DAYS);
        expect(document.body.textContent).not.toContain('Luna Hair Studio');

        render(withoutFeature({ kind: 'detail', id: 1 }, [LUNA]));
        expect(document.querySelector('.admin-staff-screen--floor')).not.toBeNull();
        expect(document.querySelector('.admin-staff-view')).toBeNull();
        expect(document.body.textContent).not.toContain('Luna Hair Studio');
        expect(text('.admin-blackouts h2')).toBe(CLOSED_DAYS);
    });

    it('heads the floor\'s screen Closures in its heading and its title, on the list and on its page reached by URL', () => {
        render(withoutFeature({ kind: 'list' }, [LUNA]));
        expect(text('.admin-staff-screen--floor h1')).toBe(staff.text('page.floor_title'));
        expect(inertia.title).toBe(staff.text('page.floor_title'));

        inertia.title = null;
        render(withoutFeature({ kind: 'detail', id: 1 }, [LUNA]));
        expect(text('.admin-staff-screen--floor h1')).toBe(staff.text('page.floor_title'));
        expect(inertia.title).toBe(staff.text('page.floor_title'));
    });

    it('keeps the Staff heading and title over the read-only list', () => {
        render(withoutFeature({ kind: 'list' }, DOWNGRADED, { narrowed: ['staff'] }));

        expect(text('.admin-staff-screen h1')).toBe(staff.text('page.title'));
        expect(inertia.title).toBe(staff.text('page.title'));
    });

    it('adds a tenant-wide closure from the floor\'s closures card', () => {
        render(withoutFeature({ kind: 'list' }, [LUNA]));

        press(document.querySelector('.admin-blackouts__date'));
        const today = document.querySelector('button[data-date][aria-current="date"]');
        press(today);
        press(document.querySelector('.admin-blackouts__add'));
        expect(lastVisit(inertia.post)[0]).toBe('/admin/blackouts');
        expect(lastVisit(inertia.post)[1]).toEqual({ date: today?.getAttribute('data-date'), until: null, start_time: null, end_time: null, reason: null });
    });

    it('lists the implicit member beside members deactivated before the feature went, read-only, the inactive ones behind the toggle', () => {
        render(withoutFeature({ kind: 'list' }, DOWNGRADED, { narrowed: ['staff'] }));

        expect(document.querySelector('.admin-staff-screen--floor')).toBeNull();
        expect(document.querySelector('.admin-staff-new')).toBeNull();
        expect(Array.from(document.querySelectorAll('.admin-staff__id')).map((cell) => cell.textContent)).toEqual(['1']);
        expect(text('.admin-staff-toggle label')).toBe(staff.text('page.show_inactive', { count: formatCount(2, staff.locale) }));
        expect(text('.admin-blackouts h2')).toBe(CLOSED_DAYS);

        act(() => document.querySelector<HTMLInputElement>('#staff-show-inactive')?.click());
        expect(Array.from(document.querySelectorAll('.admin-staff__id')).map((cell) => cell.textContent)).toEqual(['1', '2', '3']);
    });

    it('shows a member deactivated before the feature went on their own page reached by URL, their hours and closures as stored and no control', () => {
        render(withoutFeature({ kind: 'detail', id: 2 }, DOWNGRADED, { narrowed: ['staff'] }));

        expect(document.querySelector('.admin-staff-screen--floor')).toBeNull();
        expect(text('.admin-breadcrumb__current')).toBe('Elena Papadaki');
        expect(text('.admin-staff-title .admin-chip')).toBe(staff.text('statuses.inactive'));
        expect(text('.admin-staff-view .admin-staff-pattern__line')).toBe(ELENA_LINE);
        expect(text('.admin-staff-view__closure')).toBe(`${DENTIST} Dentist`);
        // No control that would write the member; the one button is Copy booking link, which
        // configures nothing.
        expect(document.querySelectorAll('.admin-staff-detail input, .admin-staff-detail select, .admin-staff-detail textarea, .admin-staff-detail button:not(.admin-booking-link__copy)')).toHaveLength(0);
        expect(text('.admin-staff-title .admin-booking-link__copy')).toBe(shell.text(COPY_BOOKING_LINK));
    });
});

describe('the staff section', () => {
    const { validation: _validation, refusals: _refusals, flashes: _flashes, ...screen } = ADMIN_LINES_EN.staff;
    const english = Object.values(screen)
        .flatMap((group) => (typeof group === 'string' ? [group] : Object.values(group)))
        .filter((line) => !/:[a-z_]+/.test(line));
    const left = (): string[] => english.filter((line) => (view.host.textContent ?? '').includes(line) || view.host.innerHTML.includes(`"${line}"`));

    it('holds the groups the screen and the server read, in the file\'s order', () => {
        expect(Object.keys(ADMIN_LINES_EN.staff)).toEqual(['page', 'table', 'figure', 'pattern', 'statuses', 'form', 'danger', 'view', 'closures', 'colours', 'validation', 'refusals', 'flashes']);
    });

    it('leaves no English line of the screen on the list, the form, the read-only page or the floor, every word read from the section', () => {
        render(propsWith({ kind: 'list' }));
        expect(left()).toEqual([]);

        render(propsWith({ kind: 'detail', id: 2 }, [LUNA, { ...ELENA, photo: PHOTO }, NIKOS]));
        act(() => document.querySelector<HTMLInputElement>('.admin-blackouts__all-day-tick')?.click());
        expect(left()).toEqual([]);

        render(propsWith({ kind: 'detail', id: 1 }));
        expect(left()).toEqual([]);

        render(propsWith({ kind: 'create' }));
        expect(left()).toEqual([]);

        render(withoutFeature({ kind: 'detail', id: 3 }));
        expect(left()).toEqual([]);

        render(withoutFeature({ kind: 'list' }, [LUNA]));
        expect(left()).toEqual([]);
        expect(text('.admin-staff-screen--floor h1')).toBe(staff.text('page.floor_title'));
    });

    it('toasts the page\'s own sentences where a write reached no server', () => {
        render(propsWith({ kind: 'detail', id: 2 }));
        press(document.querySelector('.admin-staff-save'));
        const [, , saving] = lastVisit(inertia.put);
        start(saving);
        fail(saving);
        expect(toasts()).toContain(staff.text('page.save_error'));

        render(propsWith({ kind: 'detail', id: 2 }));
        press(document.querySelector('.admin-staff-delete'));
        const [, , removing] = lastVisit(inertia.delete);
        start(removing);
        expect(text('.admin-staff-delete')).toBe(staff.text('danger.deleting'));
        fail(removing);
        expect(toasts()).toContain(staff.text('page.delete_error'));
    });
});
