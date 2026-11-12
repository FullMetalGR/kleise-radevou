import { act, type ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, expectTypeOf, it, vi } from 'vitest';
import { key, mount, type Mounted, onPhone, press, settle } from '@/components/ui/harness';
import type { Detail } from '@/bookings/model';
import type { Block, CalendarBooking, Colouring, DayColumn, DayHours, Drop, StatusCount, View, Window } from '@/calendar/model';
import { formatInstant } from '@/lib/format';
import { COLOUR_BY_LOCKED, COLOUR_BY_NOTE, COLOUR_BY_SAVING, LEGEND_CLOSURE, LEGEND_OUTSIDE, TO_DAY, WEEK_NEEDS_WIDTH, WHO_NOTE, WHO_PICK } from '@/calendar/Chrome';
import { CLOSURE_TITLE, WHOLE_BUSINESS } from '@/calendar/ClosureDialog';
import { CLOSURE_HERE, EDIT_THIS_DAYS_HOURS, NEW_BOOKING_HERE, OPEN_STAFF_PAGE } from '@/calendar/ColumnHead';
import { NOT_WORKING_THIS_DAY, OTHER_BOOKINGS } from '@/calendar/model';
import { weekdayName } from '@/lib/names';
import { KEYED_BOOKINGS, KEYED_CALENDAR, KEYED_SHIFTS, keyedBookings, keyedCalendar, keyedShell, keyedShifts } from '@/testing/keyed';
import type { CalendarStrings } from '@/types';
import { ADMIN_LINES_EN } from '../lines.generated';
import { ToastProvider } from '@/toast/toasts';
import Calendar, { CALENDAR_LOAD_ERROR, UNLINKED } from './Calendar';

/*
 * The Calendar screen's wiring: it opens on Week and the tenant's today, every move asks the server
 * for the visible range with an exclusive end and Who alone, `who` or the picked `staff` list, the
 * grid drawing the members the window answers and the legend listing them, status and service cut
 * on the client with no request, the filters restore per tab with Who among them and the view and
 * date never, a block opens the dialog as client state with a partial reload of the detail and the
 * URL untouched, an action's redirect is answered by one read of the window wanted and the booking
 * open together, and a slot opens the wizard with the date and the one member shown. A viewer
 * holding viewOwnCalendar alone is pinned to Me: Who read-only, the own row's blocks, the server's
 * actions, no slot selection, and the unlinked sentence in place of the grid where no row is
 * linked. The framework is stood in for by a recording router, and the grid by a stub that reports
 * what it was handed and offers a block and a slot to press, because the grid's own rendering is
 * proven over the real library in calendar/Grid.test.tsx. A link's view and date, the opening prop
 * the server read from `?view=` and `?date=`, seed the first render alone. Month: the third view
 * reads its six-week grid in one read, moves a month at a time, hands the grid the counts the
 * client cuts leave with no legend, opens Day view on a day pressed, and is never remembered; the
 * grid's own refusal of a slot selection in Month is proven in the browser, where a selection can
 * be made. The page carries the keyed calendar, bookings and shifts sections in its strings prop,
 * as CalendarController names them, so every word reads as its key and is read through the same
 * tables.
 */

const shell = keyedShell();
const cal = keyedCalendar();

/** A section's type as the generated module spells it, its lines strings. */
type Shape<T> = { -readonly [K in keyof T]: T[K] extends string ? string : Shape<T[K]> };

/** The screen as the shell holds it, under the toaster its writes report failures through. */
function Page() {
    return (
        <ToastProvider>
            <Calendar />
        </ToastProvider>
    );
}

type Visit = { onStart?: () => void; onSuccess?: () => void; onFinish?: () => void; only?: string[]; preserveUrl?: boolean };

const inertia = vi.hoisted(() => ({
    props: {} as Record<string, unknown>,
    get: vi.fn(),
    post: vi.fn(),
    put: vi.fn(),
    replace: vi.fn(),
    listeners: new Map<string, (event: Event) => void>(),
}));

const wizard = vi.hoisted(() => ({ open: vi.fn() }));

/** Where the stub grid's drop lands its first block: the wall clock and the column's member. */
const dropping = vi.hoisted(() => ({ start: '', staffId: null as number | null }));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
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
        replace: inertia.replace,
        on: (name: string, listener: (event: Event) => void) => {
            inertia.listeners.set(name, listener);

            return () => inertia.listeners.delete(name);
        },
    },
}));

vi.mock('@/wizard/NewBooking', () => ({ useNewBooking: () => wizard }));

type StubProps = {
    view: View;
    date: string;
    blocks: Block[];
    colouring: Colouring;
    onBlock: (uuid: string) => void;
    onSlot?: (date: string, staffId?: number) => void;
    counts?: ReadonlyMap<string, readonly StatusCount[]>;
    onDay?: (date: string) => void;
    span?: { min: string; max: string };
    columns?: readonly DayColumn[];
    head?: (column: DayColumn) => ReactNode;
    onDrop?: (drop: Drop) => void;
};

vi.mock('@/calendar/Grid', () => ({
    Grid: ({ view, date, blocks, colouring, onBlock, onSlot, counts, onDay, span, columns, head, onDrop }: StubProps) => (
        <div
            className="stub-grid"
            data-view={view}
            data-date={date}
            data-palette={String(colouring.palette)}
            data-colour-by={colouring.by}
            data-categories={colouring.categories.join('|')}
            data-own={colouring.own.map((member) => `${member.id}:${member.colour}`).join('|')}
            data-blocks={blocks.map((block) => block.id).join(' ')}
            data-counts={counts === undefined ? undefined : JSON.stringify(Object.fromEntries(counts))}
            data-span={span === undefined ? undefined : `${span.min}-${span.max}`}
        >
            <button type="button" className="stub-block" onClick={() => onBlock(blocks[0]?.uuid ?? '')} />
            {onDrop !== undefined && blocks[0] !== undefined && <button type="button" className="stub-drop" onClick={() => blocks[0] !== undefined && onDrop({ block: blocks[0], start: dropping.start, staffId: dropping.staffId })} />}
            {onSlot !== undefined && <button type="button" className="stub-slot" onClick={() => onSlot(date)} />}
            {[...(counts?.keys() ?? [])].map((day) => (
                <button key={day} type="button" className="stub-day" data-date={day} onClick={() => onDay?.(day)} />
            ))}
            {columns?.map((column) => (
                <div key={column.key} className="stub-column" data-column={column.key} data-blocks={column.blocks.map((block) => block.id).join(' ')} data-hours={JSON.stringify(column.hours)} data-next={JSON.stringify(column.next)}>
                    {head?.(column)}
                    {onSlot !== undefined && column.member !== null && <button type="button" className="stub-column-slot" onClick={() => onSlot(date, column.member?.id)} />}
                </div>
            ))}
        </div>
    ),
}));

const ELENA = 2;
const NIKOS = 4;

const CHAIN: CalendarBooking = {
    uuid: 'chain',
    status: 'confirmed',
    customer_name: 'Flo Chain',
    total_minor: 7000,
    currency: 'EUR',
    items: [
        { sort: 0, service_id: 1, service_name: 'Haircut', staff_id: ELENA, staff_name: 'Elena', start: '2026-11-03T08:00:00+00:00', end: '2026-11-03T08:45:00+00:00', price_minor: 3500 },
        { sort: 1, service_id: 3, service_name: 'Colour', staff_id: NIKOS, staff_name: 'Nikos', start: '2026-11-03T09:00:00+00:00', end: '2026-11-03T10:00:00+00:00', price_minor: 3500 },
    ],
};

const REQUEST: CalendarBooking = {
    uuid: 'request',
    status: 'awaiting_approval',
    customer_name: 'Rita Request',
    total_minor: 3500,
    currency: 'EUR',
    items: [{ sort: 0, service_id: 5, service_name: 'Bridal trial', staff_id: ELENA, staff_name: 'Elena', start: '2026-11-04T08:00:00+00:00', end: '2026-11-04T09:00:00+00:00', price_minor: 3500 }],
};

const ELENA_ROW = { id: ELENA, name: 'Elena Papadaki' };
const NIKOS_ROW = { id: NIKOS, name: 'Nikos Georgiou' };

const WINDOW: Window = { from: '2026-11-02', to: '2026-11-09', who: 'scheduled', staff: [], members: [ELENA_ROW, NIKOS_ROW], implicit: null, hours: [], next_working: [], bookings: [CHAIN, REQUEST] };

const NEXT_REQUEST: CalendarBooking = { ...REQUEST, uuid: 'next', customer_name: 'Nia Next', items: [{ ...(REQUEST.items[0] as CalendarBooking['items'][number]), start: '2026-11-11T08:00:00+00:00', end: '2026-11-11T09:00:00+00:00' }] };

const NEXT_WINDOW: Window = { from: '2026-11-09', to: '2026-11-16', who: 'picked', staff: [ELENA], members: [ELENA_ROW], implicit: null, hours: [], next_working: [], bookings: [NEXT_REQUEST] };

function propsWith(overrides: Record<string, unknown> = {}): Record<string, unknown> {
    return {
        tenant: { name: 'Luna', slug: 'luna', timezone: 'Europe/Athens', currency: 'EUR' },
        auth: { user: { id: 1, name: 'Alex Owner', role: 'owner' } },
        flash: null,
        errors: {},
        permissions: ['viewBookings', 'manageBookings'],
        entitlements: ['staff'],
        subscription: { state: 'active' },
        settings: { granularity: 15, week_start: 1, date_format: 'd/m/Y', time_format: 'H:i', colour_by: 'staff', messages: [] },
        nav: [],
        catalog: { services: [], staff: [], members: 3 },
        options: {
            statuses: ['pending', 'awaiting_approval', 'confirmed'],
            staff: [
                { id: ELENA, name: 'Elena Papadaki' },
                { id: NIKOS, name: 'Nikos Georgiou' },
            ],
            services: [
                { id: 1, name: 'Haircut', active: true },
                { id: 3, name: 'Colour', active: true },
                { id: 5, name: 'Bridal trial', active: false },
            ],
            categories: ['Colouring', 'Hair'],
            members: 3,
        },
        own: null,
        pinned: false,
        window: WINDOW,
        detail: null,
        // The walks below were written for Week and open on it by link; the bare opening is Day,
        // proven on its own.
        opening: { view: 'week', date: null },
        colours: [],
        strings: { shell: {}, calendar: KEYED_CALENDAR, bookings: KEYED_BOOKINGS, shifts: KEYED_SHIFTS },
        ...overrides,
    };
}

function grid(): HTMLElement {
    const element = view.host.querySelector<HTMLElement>('.stub-grid');

    if (element === null) {
        throw new Error('No grid on the page.');
    }

    return element;
}

/** The counts the grid was handed, by day, or null where it was handed none. */
function counts(): Record<string, StatusCount[]> | null {
    const json = grid().dataset['counts'];

    return json === undefined ? null : (JSON.parse(json) as Record<string, StatusCount[]>);
}

/** Day's title of a date: the weekday, Intl's long English name, before the date in the tenant's format. */
function dayTitle(date: string): string {
    return cal.text('toolbar.day_title', { weekday: new Intl.DateTimeFormat('en', { weekday: 'long', timeZone: 'UTC' }).format(new Date(`${date}T00:00:00Z`)), date: `${date.slice(8)}/${date.slice(5, 7)}/${date.slice(0, 4)}` });
}

/** The toolbar's date title. */
function title(): string | null {
    return view.host.querySelector('.admin-calendar-title')?.textContent ?? null;
}

/** A view's word on the toolbar's segmented control, read through the keyed calendar section. */
function viewWord(view: View): string {
    return cal.text(`toolbar.views.${view}`);
}

/** The view whose option the toolbar's segmented control holds pressed. */
function pressed(): View | null {
    const word = view.host.querySelector('.admin-calendar-view [aria-pressed="true"]')?.textContent ?? null;

    return (['week', 'day', 'month'] as const).find((each) => viewWord(each) === word) ?? null;
}

/** Presses the option of the toolbar's segmented control for the view given. */
function choose(chosen: View): void {
    press([...view.host.querySelectorAll('.admin-calendar-view button')].find((el) => el.textContent === viewWord(chosen)) ?? null);
}

/** The six-week grid of November 2026 from luna's Monday week start. */
const NOVEMBER: Window = { ...WINDOW, from: '2026-10-26', to: '2026-12-07' };

/** The parameters and options of the last window read, or null when none was made. */
function lastRead(): { data: Record<string, string>; visit: Visit } | null {
    const call = inertia.get.mock.lastCall;

    return call === undefined ? null : { data: call[1] as Record<string, string>, visit: call[2] as Visit };
}

function select(selector: string, value: string): void {
    const element = view.host.querySelector<HTMLSelectElement>(selector);

    if (element === null) {
        throw new Error(`No ${selector} on the page.`);
    }

    act(() => {
        const setter = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'value')?.set;
        setter?.call(element, value);
        element.dispatchEvent(new Event('change', { bubbles: true }));
    });
}

/** The Who popover's content, or null while it is closed. */
function whoPopover(): HTMLElement | null {
    return document.body.querySelector<HTMLElement>('.admin-calendar-who__popover');
}

/** Opens Who's popover where it is closed. */
function openWho(): void {
    if (whoPopover() === null) {
        press(view.host.querySelector('#calendar-who'));
    }
}

/** Presses one of Who's presets, opening the popover first. */
function preset(who: string): void {
    openWho();
    press(document.body.querySelector(`.admin-calendar-who__preset[data-who="${who}"]`));
}

/** Ticks or clears one member's box under Who, opening the popover first. */
function tick(id: number): void {
    openWho();
    press(document.body.querySelector(`.admin-calendar-who__member[data-staff-id="${id}"] input`));
}

/** The summary Who's trigger shows. */
function whoShown(): string | null {
    return view.host.querySelector('#calendar-who')?.textContent ?? null;
}

/** The server answers the last read: the props change and the page re-renders over them. */
function answer(overrides: Record<string, unknown>): void {
    inertia.props = { ...inertia.props, ...overrides };
    view.render(<Page />);
}

/** The last visit fails as the framework reports it; answers whether the page took the failure over. */
function failLastVisit(): boolean {
    const visit = lastRead()?.visit;
    const event = new Event('invalid', { cancelable: true });

    act(() => visit?.onStart?.());
    act(() => inertia.listeners.get('invalid')?.(event));
    act(() => visit?.onFinish?.());

    return event.defaultPrevented;
}

let view: Mounted;

beforeEach(() => {
    // 22:30 UTC on Wednesday 4 November 2026 is 00:30 on Thursday the fifth in Athens.
    vi.useFakeTimers({ toFake: ['Date'] });
    vi.setSystemTime(Date.UTC(2026, 10, 4, 22, 30));
    window.sessionStorage.clear();
    inertia.get.mockReset();
    inertia.post.mockReset();
    inertia.put.mockReset();
    inertia.replace.mockReset();
    inertia.listeners.clear();
    wizard.open.mockReset();
    inertia.props = propsWith();
    view = mount();
});

afterEach(() => {
    view.unmount();
    vi.useRealTimers();
});

/** Every line of a table, by its dotted key. */
function linesOf(table: object, prefix = ''): [string, string][] {
    return Object.entries(table).flatMap(([name, value]): [string, string][] => (typeof value === 'string' ? [[`${prefix}${name}`, value]] : linesOf(value as object, `${prefix}${name}.`)));
}

describe('the calendar section', () => {
    it('is typed as the keys of lang/en/admin/calendar.php, no more and no fewer', () => {
        expectTypeOf<Shape<typeof ADMIN_LINES_EN.calendar>>().toEqualTypeOf<CalendarStrings>();
        expect(Object.keys(ADMIN_LINES_EN.calendar)).toEqual(['page', 'toolbar', 'filters', 'who', 'legend', 'badges', 'grid', 'day', 'closure', 'validation']);
    });

    it('leaves no English sentence of the file on the screen, its Day columns, its Add menu or its closure dialog, every word read from the section', async () => {
        const sentences = linesOf(ADMIN_LINES_EN.calendar).filter(([, line]) => line.includes(' ') && !line.includes(':'));
        const onScreen = () => document.body.textContent ?? '';

        inertia.props = propsWith({ permissions: ['viewBookings', 'manageBookings', 'manageSettings'], entitlements: ['staff'], opening: { view: 'day', date: '2026-11-05' } });
        view.render(<Page />);
        view.host.querySelector<HTMLElement>('.admin-calendar-add')?.focus();
        key(view.host.querySelector('.admin-calendar-add'), 'Enter');
        await settle();
        press(document.querySelector('.admin-calendar-add__closure'));
        await settle();

        expect(sentences.length).toBeGreaterThan(20);
        expect(sentences.filter(([, line]) => onScreen().includes(line)).map(([key]) => key)).toEqual([]);
        expect(onScreen()).toContain(cal.text('closure.title'));
    });
});

describe('the calendar screen', () => {
    it('opens on Week and the tenant today whatever the machine date, titled by the week from the tenant week start, and reads nothing when the window carried is the one wanted', () => {
        view.render(<Page />);

        expect(grid().dataset['view']).toBe('week');
        expect(grid().dataset['date']).toBe('2026-11-05');
        expect(view.host.querySelector('.admin-calendar-title')?.textContent).toBe('02/11/2026 - 08/11/2026');
        expect(view.host.querySelector('.admin-calendar')?.getAttribute('aria-labelledby')).toBe('calendar-title');
        expect(view.host.querySelector('.admin-calendar-view [aria-pressed="true"]')?.textContent).toBe(viewWord('week'));
        expect(view.host.querySelector('h1')?.textContent).toBe(cal.text('page.title'));
        expect(view.host.querySelector('.admin-calendar-today')?.textContent).toBe(cal.text('toolbar.today'));
        expect(view.host.querySelector('.admin-calendar-previous')?.getAttribute('aria-label')).toBe(cal.text('toolbar.previous'));
        expect(view.host.querySelector('.admin-calendar-next')?.getAttribute('aria-label')).toBe(cal.text('toolbar.next'));
        expect([...view.host.querySelectorAll('.admin-calendar-filters label')].map((label) => label.textContent)).toEqual([cal.text('who.label'), cal.text('filters.status'), cal.text('filters.service'), cal.text('filters.colour_by')]);
        expect(view.host.querySelector('.admin-calendar-status option')?.textContent).toBe(cal.text('filters.any_status'));
        expect([...view.host.querySelectorAll('.admin-calendar-service option')].map((option) => option.textContent)).toEqual([cal.text('filters.all_services'), 'Haircut', 'Colour', cal.text('filters.inactive_service', { service: 'Bridal trial' })]);
        expect(view.host.querySelector('.admin-calendar-legend ul')?.getAttribute('aria-label')).toBe(cal.text('legend.staff'));
        expect(inertia.get).not.toHaveBeenCalled();
        expect(grid().dataset['blocks']).toBe('chain:0 chain:1 request:0');
        expect(grid().dataset['palette']).toBe('true');
        expect([...view.host.querySelectorAll('.admin-calendar-legend__member')].map((el) => el.textContent)).toEqual(['Elena Papadaki', 'Nikos Georgiou']);
        expect([...view.host.querySelectorAll('.admin-calendar-legend__badge')].map((el) => [el.querySelector('.admin-calendar-badge')?.textContent, el.textContent])).toEqual([
            [cal.text('badges.pending'), `${cal.text('badges.pending')}${shell.text('kit.statuses.awaiting_approval')}`],
            [shell.text('kit.statuses.awaiting_payment'), `${shell.text('kit.statuses.awaiting_payment')}${cal.text('badges.payment')}`],
        ]);
    });

    it('moves seven days in Week and one in Day, returns to the tenant today, and asks the server for each visible range with an exclusive end and the URL preserved', () => {
        view.render(<Page />);

        press(view.host.querySelector('.admin-calendar-next'));
        expect(lastRead()?.data).toEqual({ from: '2026-11-09', to: '2026-11-16', who: 'scheduled' });
        expect(lastRead()?.visit.only).toEqual(['window']);
        expect(lastRead()?.visit.preserveUrl).toBe(true);
        expect(inertia.get.mock.lastCall?.[0]).toBe('/admin/calendar');

        choose('day');
        expect(grid().dataset['view']).toBe('day');
        expect(lastRead()?.data).toEqual({ from: '2026-11-12', to: '2026-11-13', who: 'scheduled' });
        expect(view.host.querySelector('.admin-calendar-title')?.textContent).toBe(dayTitle('2026-11-12'));

        press(view.host.querySelector('.admin-calendar-previous'));
        expect(lastRead()?.data).toEqual({ from: '2026-11-11', to: '2026-11-12', who: 'scheduled' });

        press(view.host.querySelector('.admin-calendar-today'));
        expect(lastRead()?.data).toEqual({ from: '2026-11-05', to: '2026-11-06', who: 'scheduled' });
        expect(grid().dataset['date']).toBe('2026-11-05');
        expect(inertia.get).toHaveBeenCalledTimes(4);
    });

    it('sends Who to the server, a preset as its word and a picked set as the sorted ids, cuts status and service on the client with no request, a chain surviving the service cut on its second segment alone, and draws the members the window answers', () => {
        view.render(<Page />);

        expect(whoShown()).toBe(cal.text('who.scheduled'));
        expect(view.host.querySelector('#calendar-who-help')).toBeNull();
        expect(view.host.querySelector('#calendar-who')?.hasAttribute('aria-describedby')).toBe(false);

        // Under Scheduled staff both boxes read ticked, the two shown; clearing Elena's picks
        // Nikos.
        tick(ELENA);
        expect(lastRead()?.data).toEqual({ from: '2026-11-02', to: '2026-11-09', staff: String(NIKOS) });
        expect(whoShown()).toBe('Nikos Georgiou');
        // The window still answers both members, so both are drawn until the answer lands.
        expect(grid().dataset['blocks']).toBe('chain:0 chain:1 request:0');

        answer({ window: { ...WINDOW, who: 'picked', staff: [NIKOS], members: [NIKOS_ROW], bookings: [CHAIN] } });
        expect(grid().dataset['blocks']).toBe('chain:1');
        expect([...view.host.querySelectorAll('.admin-calendar-legend__member')].map((el) => el.textContent)).toEqual(['Nikos Georgiou']);
        expect(inertia.get).toHaveBeenCalledTimes(1);

        tick(ELENA);
        expect(lastRead()?.data).toEqual({ from: '2026-11-02', to: '2026-11-09', staff: `${ELENA},${NIKOS}` });
        expect(whoShown()).toBe(cal.choice('who.picked', 1, { staff: 'Elena Papadaki' }));
        answer({ window: { ...WINDOW, who: 'picked', staff: [ELENA, NIKOS] } });
        expect(inertia.get).toHaveBeenCalledTimes(2);

        preset('all');
        expect(whoPopover()).toBeNull();
        expect(whoShown()).toBe(cal.text('who.all'));
        expect(lastRead()?.data).toEqual({ from: '2026-11-02', to: '2026-11-09', who: 'all' });
        answer({ window: { ...WINDOW, who: 'all' } });
        expect(inertia.get).toHaveBeenCalledTimes(3);

        select('#calendar-service', '3');
        expect(grid().dataset['blocks']).toBe('chain:0 chain:1');
        select('#calendar-status', 'awaiting_approval');
        expect(grid().dataset['blocks']).toBe('');
        select('#calendar-service', '');
        expect(grid().dataset['blocks']).toBe('request:0');
        expect(inertia.get).toHaveBeenCalledTimes(3);
        expect(JSON.parse(window.sessionStorage.getItem('kr.admin.calendar.filters') ?? '')).toEqual({ status: 'awaiting_approval', who: 'all', staff: [], service: null });
    });

    it('offers the presets then a box per active member under Or pick who to show, Me only for a viewer with an own row, and the last box cleared reads Scheduled staff', () => {
        view.render(<Page />);
        openWho();

        expect([...document.body.querySelectorAll('.admin-calendar-who__preset')].map((el) => el.textContent)).toEqual([cal.text('who.scheduled'), cal.text('who.all')]);
        // Plain buttons, the one shown pressed, rather than a listbox no arrow key moves through.
        expect([...document.body.querySelectorAll('.admin-calendar-who__preset')].map((el) => [el.tagName, el.getAttribute('role'), el.getAttribute('aria-pressed')])).toEqual([
            ['BUTTON', null, 'true'],
            ['BUTTON', null, 'false'],
        ]);
        expect(whoPopover()?.querySelector('[role="listbox"], [role="option"]')).toBeNull();
        expect(whoPopover()?.querySelector('legend')?.textContent).toBe(cal.text(WHO_PICK));
        expect([...document.body.querySelectorAll('.admin-calendar-who__name')].map((el) => el.textContent)).toEqual(['Elena Papadaki', 'Nikos Georgiou']);

        // Under a preset the boxes mirror the members it shows: both, the window answering both.
        expect([...document.body.querySelectorAll<HTMLInputElement>('.admin-calendar-who__member input')].map((box) => box.checked)).toEqual([true, true]);

        tick(NIKOS);
        expect(whoPopover()).not.toBeNull();
        expect(lastRead()?.data).toEqual({ from: '2026-11-02', to: '2026-11-09', staff: String(ELENA) });
        expect([...document.body.querySelectorAll<HTMLInputElement>('.admin-calendar-who__member input')].map((box) => box.checked)).toEqual([true, false]);
        tick(ELENA);
        expect(whoShown()).toBe(cal.text('who.scheduled'));
        expect(inertia.get).toHaveBeenCalledTimes(1);

        view.unmount();
        view = mount();
        inertia.props = propsWith({ own: NIKOS_ROW });
        view.render(<Page />);
        preset('me');
        expect(whoShown()).toBe(cal.text('who.me'));
        expect(lastRead()?.data).toEqual({ from: '2026-11-02', to: '2026-11-09', who: 'me' });
        openWho();
        expect([...document.body.querySelectorAll('.admin-calendar-who__preset')].map((el) => el.textContent)).toEqual([cal.text('who.scheduled'), cal.text('who.all'), cal.text('who.me')]);
    });

    it('moves the focus through Who\'s presets and boxes with the arrow keys, wrapping at the ends, Home and End to the first and the last', () => {
        view.render(<Page />);
        openWho();
        const stops = [...(whoPopover()?.querySelectorAll<HTMLElement>('.admin-calendar-who__preset, .admin-calendar-who__member input') ?? [])];
        const key = (name: string) => document.activeElement?.dispatchEvent(new KeyboardEvent('keydown', { key: name, bubbles: true, cancelable: true }));
        const focused = () => stops.findIndex((stop) => stop === document.activeElement);

        expect(stops).toHaveLength(4);
        stops[0]?.focus();
        key('ArrowDown');
        expect(focused()).toBe(1);
        key('ArrowDown');
        expect(focused()).toBe(2);
        key('End');
        expect(focused()).toBe(3);
        key('ArrowDown');
        expect(focused()).toBe(0);
        key('ArrowUp');
        expect(focused()).toBe(3);
        key('Home');
        expect(focused()).toBe(0);
        expect(whoPopover()).not.toBeNull();
    });

    it('ticks under All staff the members the window shows, and a box ticked for one not shown switches Who to those shown and that member', () => {
        inertia.props = propsWith({ window: { ...WINDOW, who: 'all', members: [NIKOS_ROW] } });
        view.render(<Page />);
        preset('all');
        answer({ window: { ...WINDOW, who: 'all', members: [NIKOS_ROW] } });
        openWho();

        expect([...document.body.querySelectorAll<HTMLInputElement>('.admin-calendar-who__member input')].map((box) => box.checked)).toEqual([false, true]);

        tick(ELENA);
        expect(lastRead()?.data).toEqual({ from: '2026-11-02', to: '2026-11-09', staff: `${ELENA},${NIKOS}` });
        expect(whoShown()).toBe(cal.choice('who.picked', 1, { staff: 'Elena Papadaki' }));
    });

    it('restores the filters per tab with Who among them and never the view or the date, and does not send a remembered Who while the control is hidden, showing everyone instead', () => {
        window.sessionStorage.setItem('kr.admin.calendar.filters', JSON.stringify({ status: 'confirmed', who: 'picked', staff: [ELENA], service: null, view: 'day', date: '2026-01-01' }));
        inertia.props = propsWith({ entitlements: [], options: { ...(propsWith()['options'] as object), members: 1 } });
        view.render(<Page />);

        expect(view.host.querySelector('#calendar-who')).toBeNull();
        expect(view.host.querySelector<HTMLSelectElement>('#calendar-status')?.value).toBe('confirmed');
        expect(grid().dataset['view']).toBe('week');
        expect(grid().dataset['date']).toBe('2026-11-05');
        expect(grid().dataset['palette']).toBe('false');
        expect(view.host.querySelector('.admin-calendar-legend')).toBeNull();
        expect(lastRead()?.data).toEqual({ from: '2026-11-02', to: '2026-11-09', who: 'all' });

        view.unmount();
        view = mount();
        inertia.get.mockReset();
        inertia.props = propsWith();
        view.render(<Page />);

        expect(whoShown()).toBe('Elena Papadaki');
        expect(lastRead()?.data).toEqual({ from: '2026-11-02', to: '2026-11-09', staff: String(ELENA) });

        // A remembered staff value of the shape before Who reads as the default, Scheduled staff.
        window.sessionStorage.setItem('kr.admin.calendar.filters', JSON.stringify({ status: '', staff: ELENA, service: null }));
        view.unmount();
        view = mount();
        inertia.get.mockReset();
        view.render(<Page />);
        expect(whoShown()).toBe(cal.text('who.scheduled'));
        expect(inertia.get).not.toHaveBeenCalled();
    });

    it('opens the dialog on a block as client state, reading the detail alone with the URL preserved, shows it once the props carry it, and closes it without a visit', () => {
        view.render(<Page />);

        press(view.host.querySelector('.stub-block'));
        expect(inertia.get.mock.lastCall?.[0]).toBe('/admin/calendar');
        expect(lastRead()?.data).toEqual({ booking: 'chain' });
        expect(lastRead()?.visit.only).toEqual(['detail']);
        expect(lastRead()?.visit.preserveUrl).toBe(true);
        expect(document.body.querySelector('.admin-booking-dialog')).not.toBeNull();

        const detail: Detail = { state: 'found', booking: { ...CHAIN, customer_email: 'flo@example.test' }, note: null, trail: [], actions: ['cancel'] };
        answer({ detail });
        expect(document.body.querySelector('.admin-booking-dialog')?.textContent).toContain('Flo Chain');
        expect(document.body.querySelector('.admin-booking-action--cancel')).not.toBeNull();
        expect(inertia.get).toHaveBeenCalledTimes(1);

        press(document.body.querySelector('.admin-booking-close'));
        expect(document.body.querySelector('.admin-booking-dialog')).toBeNull();
        expect(inertia.get).toHaveBeenCalledTimes(1);
    });

    it('re-reads the window it wants and the open booking in one read when an action lands the default props back, whatever week and filter are shown, so the block and the dialog both show the server answer', () => {
        view.render(<Page />);
        press(view.host.querySelector('.admin-calendar-next'));
        answer({ window: { ...NEXT_WINDOW, who: 'scheduled', staff: [], members: [ELENA_ROW, NIKOS_ROW] } });
        tick(NIKOS);
        answer({ window: NEXT_WINDOW });
        expect(inertia.get).toHaveBeenCalledTimes(2);
        expect(grid().dataset['blocks']).toBe('next:0');

        press(view.host.querySelector('.stub-block'));
        expect(lastRead()?.data).toEqual({ booking: 'next' });
        answer({ detail: { state: 'found', booking: NEXT_REQUEST, note: null, trail: [], actions: ['approve', 'reject'] } });
        expect(inertia.get).toHaveBeenCalledTimes(3);
        expect(document.body.querySelector('.admin-booking-dialog')?.textContent).toContain('Nia Next');

        // The action's redirect lands this week, Scheduled staff and no detail: one read asks for
        // both.
        answer({ window: WINDOW, detail: null });
        expect(inertia.get).toHaveBeenCalledTimes(4);
        expect(lastRead()?.data).toEqual({ from: '2026-11-09', to: '2026-11-16', staff: String(ELENA), booking: 'next' });
        expect(lastRead()?.visit.only).toEqual(['window', 'detail']);
        expect(lastRead()?.visit.preserveUrl).toBe(true);
        expect(document.body.querySelector('.admin-booking-dialog')).not.toBeNull();
        expect(document.body.querySelector('.admin-booking-dialog')?.textContent).not.toContain('Nia Next');

        const approved: CalendarBooking = { ...NEXT_REQUEST, status: 'confirmed' };
        answer({ window: { ...NEXT_WINDOW, bookings: [approved] }, detail: { state: 'found', booking: approved, note: null, trail: [], actions: ['cancel'] } });
        expect(inertia.get).toHaveBeenCalledTimes(4);
        expect(grid().dataset['blocks']).toBe('next:0');
        expect(grid().dataset['date']).toBe('2026-11-12');
        expect(document.body.querySelector('.admin-booking-dialog .admin-chip')?.textContent).toBe(shell.text('kit.statuses.confirmed'));
        expect(document.body.querySelector('.admin-booking-action--approve')).toBeNull();
    });

    it('opens the wizard from a slot with the date and the one member shown, if one is, never the slot payload', () => {
        view.render(<Page />);

        press(view.host.querySelector('.stub-slot'));
        expect(wizard.open).toHaveBeenLastCalledWith({ date: '2026-11-05', staffId: null });

        tick(ELENA);
        answer({ window: { ...WINDOW, who: 'picked', staff: [ELENA], members: [ELENA_ROW] } });
        press(view.host.querySelector('.stub-slot'));
        expect(wizard.open).toHaveBeenLastCalledWith({ date: '2026-11-05', staffId: ELENA });
    });

    it('opens on the view and the date a link names, read once on the first render, then moves from there and never reads them again', () => {
        inertia.props = propsWith({ opening: { view: 'day', date: '2026-11-20' } });
        view.render(<Page />);

        expect(grid().dataset['view']).toBe('day');
        expect(grid().dataset['date']).toBe('2026-11-20');
        expect(title()).toBe(dayTitle('2026-11-20'));
        expect(lastRead()?.data).toEqual({ from: '2026-11-20', to: '2026-11-21', who: 'scheduled' });
        expect(inertia.get.mock.lastCall?.[0]).toBe('/admin/calendar');
        // The query leaves the address once read, the props kept, so a reload lands on Week and
        // today.
        expect(inertia.replace).toHaveBeenCalledTimes(1);
        expect(inertia.replace).toHaveBeenLastCalledWith({ url: '/admin/calendar', preserveState: true, preserveScroll: true });

        press(view.host.querySelector('.admin-calendar-next'));
        expect(grid().dataset['date']).toBe('2026-11-21');
        expect(inertia.replace).toHaveBeenCalledTimes(1);

        // A later answer carrying another opening changes nothing: it is the first render's alone.
        answer({ opening: { view: 'month', date: '2026-12-01' } });
        expect(grid().dataset['view']).toBe('day');
        expect(grid().dataset['date']).toBe('2026-11-21');
        expect(window.sessionStorage.getItem('kr.admin.calendar.filters') ?? '').not.toContain('2026-11-2');
    });

    it('opens on Day and the tenant today where the link names no view or date it accepts, reading the day, and on a named view at today or a named date in Day', () => {
        inertia.props = propsWith({ opening: { view: null, date: null } });
        view.render(<Page />);
        expect([grid().dataset['view'], grid().dataset['date']]).toEqual(['day', '2026-11-05']);
        expect(pressed()).toBe('day');
        expect(title()).toBe(dayTitle('2026-11-05'));
        // The props carry the default week, so the day is read once, the URL preserved.
        expect(lastRead()?.data).toEqual({ from: '2026-11-05', to: '2026-11-06', who: 'scheduled' });
        expect(inertia.get).toHaveBeenCalledTimes(1);
        expect(inertia.replace).not.toHaveBeenCalled();

        view.unmount();
        view = mount();
        inertia.get.mockReset();
        inertia.props = { ...propsWith(), opening: undefined };
        view.render(<Page />);
        expect([grid().dataset['view'], grid().dataset['date']]).toEqual(['day', '2026-11-05']);

        view.unmount();
        view = mount();
        inertia.props = propsWith({ opening: { view: 'month', date: null } });
        view.render(<Page />);
        expect([grid().dataset['view'], grid().dataset['date']]).toEqual(['month', '2026-11-05']);

        view.unmount();
        view = mount();
        inertia.props = propsWith({ opening: { view: null, date: '2026-11-18' } });
        view.render(<Page />);
        expect([grid().dataset['view'], grid().dataset['date']]).toEqual(['day', '2026-11-18']);
    });

    it('offers Week, Day and Month, Month reading the six weeks of its grid in one read with the URL preserved, titled by the month, with no legend', () => {
        view.render(<Page />);

        expect([...view.host.querySelectorAll('.admin-calendar-view button')].map((el) => el.textContent)).toEqual([cal.text('toolbar.views.day'), cal.text('toolbar.views.week'), cal.text('toolbar.views.month')]);

        choose('month');
        expect(grid().dataset['view']).toBe('month');
        expect(grid().dataset['date']).toBe('2026-11-05');
        expect(pressed()).toBe('month');
        expect(lastRead()?.data).toEqual({ from: '2026-10-26', to: '2026-12-07', who: 'scheduled' });
        expect(lastRead()?.visit).toMatchObject({ only: ['window'], preserveUrl: true });
        expect(inertia.get).toHaveBeenCalledTimes(1);
        expect(title()).toBe('November 2026');
        expect(view.host.querySelector('.admin-calendar-legend')).toBeNull();

        // The props still carry this week, so going back to Week reads nothing.
        choose('week');
        expect(grid().dataset['view']).toBe('week');
        expect(inertia.get).toHaveBeenCalledTimes(1);
        expect(view.host.querySelector('.admin-calendar-legend')).not.toBeNull();
    });

    it('moves a month at a time in Month and back to the tenant today, reading only a range the props do not already carry', () => {
        view.render(<Page />);
        choose('month');
        answer({ window: NOVEMBER });
        expect(inertia.get).toHaveBeenCalledTimes(1);

        press(view.host.querySelector('.admin-calendar-next'));
        expect(grid().dataset['date']).toBe('2026-12-05');
        expect(lastRead()?.data).toEqual({ from: '2026-11-30', to: '2027-01-11', who: 'scheduled' });
        expect(title()).toBe('December 2026');

        press(view.host.querySelector('.admin-calendar-previous'));
        press(view.host.querySelector('.admin-calendar-previous'));
        expect(grid().dataset['date']).toBe('2026-10-05');
        expect(lastRead()?.data).toEqual({ from: '2026-09-28', to: '2026-11-09', who: 'scheduled' });
        expect(title()).toBe('October 2026');
        expect(inertia.get).toHaveBeenCalledTimes(3);

        press(view.host.querySelector('.admin-calendar-today'));
        expect(grid().dataset['date']).toBe('2026-11-05');
        expect(title()).toBe('November 2026');
    });

    it('hands Month the counts per day and status, cut by status and service on the client with no request and by Who on the server', () => {
        view.render(<Page />);
        choose('month');
        answer({ window: NOVEMBER });
        expect(counts()).toEqual({ '2026-11-03': [{ status: 'confirmed', count: 1 }], '2026-11-04': [{ status: 'awaiting_approval', count: 1 }] });
        expect(grid().dataset['blocks']).toBe('chain:0 chain:1 request:0');

        select('#calendar-status', 'confirmed');
        expect(counts()).toEqual({ '2026-11-03': [{ status: 'confirmed', count: 1 }] });
        select('#calendar-status', '');
        select('#calendar-service', '5');
        expect(counts()).toEqual({ '2026-11-04': [{ status: 'awaiting_approval', count: 1 }] });
        select('#calendar-service', '');
        expect(inertia.get).toHaveBeenCalledTimes(1);

        tick(ELENA);
        expect(lastRead()?.data).toEqual({ from: '2026-10-26', to: '2026-12-07', staff: String(NIKOS) });
        answer({ window: { ...NOVEMBER, who: 'picked', staff: [NIKOS], members: [NIKOS_ROW], bookings: [CHAIN] } });
        expect(counts()).toEqual({ '2026-11-03': [{ status: 'confirmed', count: 1 }] });
        expect(inertia.get).toHaveBeenCalledTimes(2);
    });

    it('opens Day view on a day pressed in Month in one change, reading that day alone', () => {
        view.render(<Page />);
        choose('month');
        answer({ window: NOVEMBER });

        press(view.host.querySelector('.stub-day[data-date="2026-11-04"]'));
        expect(grid().dataset['view']).toBe('day');
        expect(grid().dataset['date']).toBe('2026-11-04');
        expect(pressed()).toBe('day');
        expect(title()).toBe(dayTitle('2026-11-04'));
        expect(lastRead()?.data).toEqual({ from: '2026-11-04', to: '2026-11-05', who: 'scheduled' });
        expect(inertia.get).toHaveBeenCalledTimes(2);
        expect(view.host.querySelector('.admin-calendar-legend')).not.toBeNull();
    });

    it('never remembers Month: a reload opens on Day and the tenant today', () => {
        view.render(<Page />);
        choose('month');
        press(view.host.querySelector('.admin-calendar-next'));
        expect(grid().dataset['view']).toBe('month');

        view.unmount();
        view = mount();
        inertia.get.mockReset();
        inertia.props = propsWith({ opening: { view: null, date: null } });
        view.render(<Page />);

        expect(grid().dataset['view']).toBe('day');
        expect(grid().dataset['date']).toBe('2026-11-05');
        expect(title()).toBe(dayTitle('2026-11-05'));
        expect(lastRead()?.data).toEqual({ from: '2026-11-05', to: '2026-11-06', who: 'scheduled' });
        expect(window.sessionStorage.getItem('kr.admin.calendar.filters') ?? '').not.toContain('month');
    });

    it('offers no slot selection to a viewer without the wizard, and answers a failed window read in its own words, which hold in every view', () => {
        inertia.props = propsWith({ catalog: null });
        view.render(<Page />);
        expect(view.host.querySelector('.stub-slot')).toBeNull();

        press(view.host.querySelector('.admin-calendar-next'));
        expect(failLastVisit()).toBe(true);
        expect(view.host.querySelector('.admin-calendar-error')?.textContent).toBe(cal.text(CALENDAR_LOAD_ERROR));

        for (const label of ['day', 'month'] as const) {
            choose(label);
            expect(failLastVisit()).toBe(true);
            expect(pressed()).toBe(label);
            expect(view.host.querySelector('.admin-calendar-error')?.textContent).toBe(cal.text(CALENDAR_LOAD_ERROR));
        }

        expect(cal.text(CALENDAR_LOAD_ERROR)).toBe('[page.load_error]');
    });
});

/*
 * A viewer holding viewOwnCalendar alone, the staff role, pinned to Me: what My calendar showed,
 * on the Calendar. The server scopes the window to the own row whatever is sent, and the screen
 * sends Me.
 */
describe('the calendar pinned to the viewer\'s own row', () => {
    const PINNED_WINDOW: Window = { ...WINDOW, who: 'me', members: [ELENA_ROW] };

    function pinnedProps(overrides: Record<string, unknown> = {}): Record<string, unknown> {
        return propsWith({
            auth: { user: { id: 7, name: 'Elena Papadaki', role: 'staff' } },
            permissions: ['viewOwnCalendar', 'approveBookings'],
            catalog: null,
            own: ELENA_ROW,
            pinned: true,
            options: { ...(propsWith()['options'] as object), staff: [ELENA_ROW] },
            window: PINNED_WINDOW,
            ...overrides,
        });
    }

    it('shows Who read-only on Me with the note, draws the own row\'s blocks alone with no slot selection, offers Week, Day and Month, and reads Me with every move', () => {
        window.sessionStorage.setItem('kr.admin.calendar.filters', JSON.stringify({ status: '', who: 'all', staff: [], service: null }));
        inertia.props = pinnedProps();
        view.render(<Page />);

        expect(whoShown()).toBe(cal.text('who.me'));
        expect(view.host.querySelector<HTMLButtonElement>('#calendar-who')?.disabled).toBe(true);
        expect(view.host.querySelector('#calendar-who-help')?.textContent).toBe(cal.text(WHO_NOTE));
        expect(grid().dataset['blocks']).toBe('chain:0 request:0');
        expect(view.host.querySelector('.stub-slot')).toBeNull();
        expect(view.host.querySelector('.admin-setup')).toBeNull();
        expect([...view.host.querySelectorAll('.admin-calendar-legend__member')].map((el) => el.textContent)).toEqual(['Elena Papadaki']);
        expect([...view.host.querySelectorAll('.admin-calendar-view button')].map((el) => el.textContent)).toEqual([cal.text('toolbar.views.day'), cal.text('toolbar.views.week'), cal.text('toolbar.views.month')]);
        expect(view.host.querySelector('.admin-calendar-unlinked')).toBeNull();
        expect(inertia.get).not.toHaveBeenCalled();

        press(view.host.querySelector('#calendar-who'));
        expect(whoPopover()).toBeNull();

        press(view.host.querySelector('.admin-calendar-next'));
        expect(lastRead()?.data).toEqual({ from: '2026-11-09', to: '2026-11-16', who: 'me' });
        choose('day');
        expect(lastRead()?.data).toEqual({ from: '2026-11-12', to: '2026-11-13', who: 'me' });
    });

    it('opens the dialog over a block with the actions the server allows, Approve and Reject on the own row', () => {
        inertia.props = pinnedProps();
        view.render(<Page />);

        press(view.host.querySelector('.stub-block'));
        expect(lastRead()?.data).toEqual({ booking: 'chain' });

        answer({ detail: { state: 'found', booking: { ...CHAIN, status: 'awaiting_approval' }, note: null, trail: [], actions: ['approve', 'reject'] } satisfies Detail });

        expect(document.body.querySelector('.admin-booking-dialog')?.textContent).toContain('Flo Chain');
        expect(document.body.querySelector('.admin-booking-action--approve')).not.toBeNull();
        expect(document.body.querySelector('.admin-booking-action--reject')).not.toBeNull();
        expect(document.body.querySelector('.admin-booking-facts__email')).toBeNull();
    });

    it('shows the sentence saying why in place of the filters and the grid for a viewer with no linked row', () => {
        inertia.props = pinnedProps({ own: null, options: { ...(propsWith()['options'] as object), staff: [] }, window: { ...PINNED_WINDOW, members: [], bookings: [] } });
        view.render(<Page />);

        expect(view.host.querySelector('.admin-calendar-unlinked')?.textContent).toBe(cal.text(UNLINKED));
        expect(view.host.querySelector('.stub-grid')).toBeNull();
        expect(view.host.querySelector('.admin-calendar-filters')).toBeNull();
        expect(inertia.get).not.toHaveBeenCalled();
    });

    it('hands the grid no palette where the staff choice is not real, so the own row\'s blocks read in the neutral grey', () => {
        inertia.props = pinnedProps({ entitlements: [], options: { ...(propsWith()['options'] as object), staff: [ELENA_ROW], members: 1 } });
        view.render(<Page />);

        expect(grid().dataset['palette']).toBe('false');
    });

    describe('on a phone', () => {
        let undo: () => void;

        beforeEach(() => {
            undo = onPhone();
        });

        afterEach(() => undo());

        it('renders Week as the notice over a Day control in place of the grid, Week still offered, and the control opens Day view reading Me', () => {
            inertia.props = pinnedProps();
            view.render(<Page />);

            expect(view.host.querySelector('.stub-grid')).toBeNull();
            expect(view.host.querySelector('.admin-calendar-narrow [role="status"]')?.textContent).toBe(cal.text(WEEK_NEEDS_WIDTH));
            expect([...view.host.querySelectorAll('.admin-calendar-view button')].map((el) => el.textContent)).toEqual([cal.text('toolbar.views.day'), cal.text('toolbar.views.week'), cal.text('toolbar.views.month')]);

            press(view.host.querySelector('.admin-calendar-narrow__day'));

            expect(grid().dataset['view']).toBe('day');
            expect(grid().dataset['date']).toBe('2026-11-05');
            expect(lastRead()?.data).toEqual({ from: '2026-11-05', to: '2026-11-06', who: 'me' });
        });
    });
});

/*
 * Below the admin's breakpoint: Week stays a desktop view and says so, the notice in place of
 * the grid and the legend over a control opening Day view on the date the screen holds, while
 * the segmented control still offers Week; Day and Month are the grid as on the desktop.
 */
describe('the calendar screen on a phone', () => {
    let undo: () => void;

    beforeEach(() => {
        undo = onPhone();
    });

    afterEach(() => undo());

    it('reads nothing on open where the props carry the day it opens on, as the server serves its first window, with no link and with a link naming Day at a date', () => {
        inertia.props = propsWith({ opening: { view: null, date: null }, window: { ...WINDOW, from: '2026-11-05', to: '2026-11-06' } });
        view.render(<Page />);
        expect([grid().dataset['view'], grid().dataset['date']]).toEqual(['day', '2026-11-05']);
        expect(inertia.get).not.toHaveBeenCalled();

        view.unmount();
        view = mount();
        inertia.props = propsWith({ opening: { view: 'day', date: '2026-11-18' }, window: { ...WINDOW, from: '2026-11-18', to: '2026-11-19' } });
        view.render(<Page />);
        expect([grid().dataset['view'], grid().dataset['date']]).toEqual(['day', '2026-11-18']);
        expect(inertia.get).not.toHaveBeenCalled();
    });

    it('opens on Day with no link, so the first paint is the grid and never the notice', () => {
        inertia.props = propsWith({ opening: { view: null, date: null } });
        view.render(<Page />);

        expect(pressed()).toBe('day');
        expect(view.host.querySelector('.admin-calendar-narrow')).toBeNull();
        expect(view.host.querySelector('.stub-grid')).not.toBeNull();
    });

    it('renders Week as the notice over a Day control in place of the grid and the legend, the segmented control still offering Week', () => {
        view.render(<Page />);

        expect(view.host.querySelector('.stub-grid')).toBeNull();
        expect(view.host.querySelector('.admin-calendar-legend')).toBeNull();
        expect(view.host.querySelector('.admin-calendar-narrow [role="status"]')?.textContent).toBe(cal.text(WEEK_NEEDS_WIDTH));
        expect(view.host.querySelector('.admin-calendar-narrow__day')?.textContent).toBe(cal.text(TO_DAY));
        expect([...view.host.querySelectorAll('.admin-calendar-view button')].map((el) => el.textContent)).toEqual([cal.text('toolbar.views.day'), cal.text('toolbar.views.week'), cal.text('toolbar.views.month')]);
        expect(pressed()).toBe('week');
        expect(title()).toBe('02/11/2026 - 08/11/2026');
        expect(view.host.querySelector('.admin-calendar-filters')).not.toBeNull();
    });

    it('opens Day view on the date the screen holds from the notice\'s control, the grid and the legend back in place', () => {
        view.render(<Page />);

        press(view.host.querySelector('.admin-calendar-narrow__day'));

        expect(view.host.querySelector('.admin-calendar-narrow')).toBeNull();
        expect(grid().dataset['view']).toBe('day');
        expect(grid().dataset['date']).toBe('2026-11-05');
        expect(pressed()).toBe('day');
        expect(title()).toBe(dayTitle('2026-11-05'));
        expect(lastRead()?.data).toEqual({ from: '2026-11-05', to: '2026-11-06', who: 'scheduled' });
        expect(view.host.querySelector('.admin-calendar-legend')).not.toBeNull();
    });

    it('draws Day and Month as the grid, and Week chosen again as the notice', () => {
        view.render(<Page />);

        choose('day');
        expect(grid().dataset['view']).toBe('day');

        choose('month');
        answer({ window: NOVEMBER });
        expect(grid().dataset['view']).toBe('month');
        expect(counts()).not.toBeNull();

        choose('week');
        expect(view.host.querySelector('.stub-grid')).toBeNull();
        expect(view.host.querySelector('.admin-calendar-narrow')).not.toBeNull();
    });
});

describe('the screen\'s composition', () => {
    it('holds the toolbar over the filters in one card, the grid with the legend at its foot in the next, and the footnote under it', () => {
        view.render(<Page />);

        const controls = view.host.querySelector<HTMLElement>('.admin-calendar-controls');
        const card = view.host.querySelector<HTMLElement>('.admin-calendar');

        expect([...(controls?.children ?? [])].map((child) => child.classList[0])).toEqual(['admin-calendar-toolbar', 'admin-calendar-filters']);
        expect(card?.querySelector('.admin-calendar-toolbar')).toBeNull();
        expect(card?.lastElementChild?.classList.contains('admin-calendar-legend')).toBe(true);
        expect(card?.nextElementSibling?.classList.contains('admin-calendar-footnote')).toBe(true);
        expect(card?.nextElementSibling?.textContent).toBe(cal.text('page.footnote'));
        expect(view.host.querySelector('.admin-calendar-legend__hint')?.textContent).toBe(cal.text('legend.hint'));
        expect(view.host.querySelector('.admin-calendar-legend__gap')?.textContent).toBe(cal.text('legend.gap'));
    });

    it('hands Week the span of the shown members\' hours that week with an hour each side, the whole day where none has hours, and Day and Month none', () => {
        const hours = { windows: [{ start: '09:00', end: '17:00' }], closures: [], source: 'pattern', pattern: [] };
        inertia.props = propsWith({ window: { ...WINDOW, hours: { [String(ELENA)]: { '2026-11-03': hours }, [String(NIKOS)]: { '2026-11-06': { ...hours, windows: [{ start: '12:00', end: '19:30' }] } } } } });
        view.render(<Page />);

        expect(grid().dataset['view']).toBe('week');
        expect(grid().dataset['span']).toBe('08:00:00-21:00:00');

        choose('month');
        expect(grid().dataset['span']).toBeUndefined();

        view.unmount();
        view = mount();
        inertia.props = propsWith();
        view.render(<Page />);
        expect(grid().dataset['span']).toBe('00:00:00-24:00:00');
    });

    it('offers the views as Day, Week and Month, and titles Day by its weekday before its date', () => {
        view.render(<Page />);

        expect([...view.host.querySelectorAll('.admin-calendar-view button')].map((el) => el.textContent)).toEqual([viewWord('day'), viewWord('week'), viewWord('month')]);
        expect(view.host.querySelectorAll('.admin-calendar-previous svg, .admin-calendar-next svg')).toHaveLength(2);
        choose('day');

        expect(title()).toBe(dayTitle('2026-11-05'));
        expect(title()).toContain('Thursday');
    });

    it('draws Who as a button with a caret over the presets with a tick on the chosen one, the members with their initials, then the note, and no line under the field', () => {
        view.render(<Page />);

        expect(view.host.querySelector('#calendar-who')?.tagName).toBe('BUTTON');
        expect(view.host.querySelector('#calendar-who svg')).not.toBeNull();
        expect(view.host.querySelector('#calendar-who')?.closest('.admin-field')?.querySelector('.admin-field__help')).toBeNull();
        openWho();

        const popover = whoPopover();
        const parts = [...(popover?.children ?? [])].map((child) => child.getAttribute('role') ?? child.tagName.toLowerCase());

        expect(parts).toEqual(['group', 'div', 'fieldset', 'div', 'p']);
        expect([...(popover?.querySelectorAll('.admin-calendar-who__preset') ?? [])].map((option) => option.querySelector('svg') !== null)).toEqual([true, false]);
        expect([...(popover?.querySelectorAll('.admin-calendar-who__member') ?? [])].map((row) => [row.querySelector('input')?.type, row.querySelector('[aria-hidden="true"]')?.textContent])).toEqual([
            ['checkbox', 'EP'],
            ['checkbox', 'NG'],
        ]);
        expect(popover?.lastElementChild?.textContent).toBe(cal.text(WHO_NOTE));
    });
});

describe('the Add menu', () => {
    const add = () => view.host.querySelector<HTMLElement>('.admin-calendar-add');
    const menuItems = () => [...document.querySelectorAll<HTMLElement>('.admin-menu__item')].map((item) => item.querySelector('.admin-menu__label')?.textContent);

    async function openAdd(): Promise<void> {
        add()?.focus();
        key(add(), 'Enter');
        await settle();
    }

    async function openClosure(): Promise<void> {
        await openAdd();
        press(document.querySelector('.admin-calendar-add__closure'));
        await settle();
    }

    /** Picks the tenant's today in the dialog's date field, whatever the clock says. */
    function pickToday(): string | null {
        press(document.querySelector('.admin-closure__date'));
        const today = document.querySelector('button[data-date][aria-current="date"]');
        press(today);

        return today?.getAttribute('data-date') ?? null;
    }

    function lastPost(): [string, Record<string, unknown>, Visit] {
        const call = inertia.post.mock.calls.at(-1) as [string, Record<string, unknown>, Visit] | undefined;

        if (call === undefined) {
            throw new Error('Nothing was posted.');
        }

        return call;
    }

    it('sits beside the title, a menu of New booking and Closure for a viewer holding both', async () => {
        inertia.props = propsWith({ permissions: ['viewBookings', 'manageBookings', 'manageSettings'] });
        view.render(<Page />);

        expect(add()?.closest('.admin-calendar-heading')?.querySelector('h1')?.textContent).toBe(cal.text('page.title'));
        expect(add()?.textContent).toBe(cal.text('page.add'));
        await openAdd();

        expect(menuItems()).toEqual([cal.text('page.add_booking'), cal.text('page.add_closure')]);
    });

    it('is a primary button with a caret at the title\'s far side, its menu closing on the line about what a closure is where Closure is offered alone', async () => {
        inertia.props = propsWith({ permissions: ['viewBookings', 'manageBookings', 'manageSettings'] });
        view.render(<Page />);

        expect(add()?.classList.contains('admin-button--primary')).toBe(true);
        expect(add()?.querySelector('svg')).not.toBeNull();
        expect(add()?.closest('.admin-calendar-heading')?.classList.contains('justify-between')).toBe(true);
        expect(add()?.closest('.admin-calendar-heading')?.lastElementChild).toBe(add());
        await openAdd();

        expect(document.querySelector('.admin-menu')?.lastElementChild?.textContent).toBe(cal.text('page.add_hint'));
        key(document.activeElement, 'Escape');
        await settle();

        inertia.props = propsWith({ permissions: ['viewBookings', 'manageBookings'] });
        view.render(<Page />);
        await openAdd();

        expect(document.querySelector('.admin-menu__note')).toBeNull();
    });

    it('offers New booking alone without manageSettings, and Closure alone where no wizard is offered', async () => {
        view.render(<Page />);
        await openAdd();
        expect(menuItems()).toEqual([cal.text('page.add_booking')]);
        key(document.activeElement, 'Escape');
        await settle();

        inertia.props = propsWith({ permissions: ['viewBookings', 'manageSettings'], catalog: null });
        view.render(<Page />);
        await openAdd();
        expect(menuItems()).toEqual([cal.text('page.add_closure')]);
    });

    it('is not drawn for a viewer who may do neither', () => {
        inertia.props = propsWith({ permissions: ['viewBookings'], catalog: null });
        view.render(<Page />);

        expect(add()).toBeNull();
    });

    it('opens the wizard with no prefill from New booking', async () => {
        view.render(<Page />);
        await openAdd();

        press(document.querySelector('.admin-calendar-add__booking'));

        expect(wizard.open).toHaveBeenCalledOnce();
        expect(wizard.open).toHaveBeenCalledWith();
    });

    it('posts a closure of the whole business, all day, with the body the closures card sends', async () => {
        inertia.props = propsWith({ permissions: ['viewBookings', 'manageBookings', 'manageSettings'] });
        view.render(<Page />);
        await openClosure();

        expect(document.querySelector('.admin-modal__title')?.textContent).toBe(cal.text(CLOSURE_TITLE));
        const who = document.querySelector<HTMLSelectElement>('.admin-closure__who');
        expect([...(who?.options ?? [])].map((option) => option.textContent)).toEqual([cal.text(WHOLE_BUSINESS), 'Elena Papadaki', 'Nikos Georgiou']);
        expect(document.querySelector<HTMLButtonElement>('.admin-closure__add')?.disabled).toBe(true);
        expect(document.querySelector<HTMLInputElement>('.admin-closure__all-day')?.checked).toBe(true);
        expect(document.querySelector('.admin-closure__start')).toBeNull();

        const date = pickToday();
        press(document.querySelector('.admin-closure__add'));

        const [url, body, visit] = lastPost();
        expect(url).toBe('/admin/blackouts');
        expect(body).toEqual({ date, until: null, start_time: null, end_time: null, reason: null });

        act(() => visit.onStart?.());
        expect(document.querySelector('.admin-closure__add')?.getAttribute('aria-busy')).toBe('true');
        act(() => visit.onSuccess?.());
        act(() => visit.onFinish?.());
        expect(document.querySelector('.admin-closure')).toBeNull();
    });

    it('posts a member closure with its window and reason to the member path', async () => {
        inertia.props = propsWith({ permissions: ['viewBookings', 'manageSettings'], catalog: null });
        view.render(<Page />);
        await openClosure();

        const who = document.querySelector<HTMLSelectElement>('.admin-closure__who');
        act(() => {
            if (who !== null) {
                who.value = String(NIKOS);
                who.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });
        const date = pickToday();
        press(document.querySelector('.admin-closure__all-day'));
        expect(document.querySelector('.admin-closure__start')).not.toBeNull();
        const reason = document.querySelector<HTMLInputElement>('.admin-closure__reason');
        act(() => {
            if (reason !== null) {
                Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')?.set?.call(reason, '  Training  ');
                reason.dispatchEvent(new Event('input', { bubbles: true }));
            }
        });
        press(document.querySelector('.admin-closure__add'));

        const [url, body] = lastPost();
        expect(url).toBe(`/admin/staff/${NIKOS}/blackouts`);
        expect(body).toEqual({ date, until: null, start_time: '09:00', end_time: '17:00', reason: 'Training' });
    });

    it('offers the whole business alone on a tenant without the staff feature', async () => {
        inertia.props = propsWith({ permissions: ['viewBookings', 'manageSettings'], entitlements: [], catalog: null });
        view.render(<Page />);
        await openClosure();

        const who = document.querySelector<HTMLSelectElement>('.admin-closure__who');
        expect([...(who?.options ?? [])].map((option) => option.textContent)).toEqual([cal.text(WHOLE_BUSINESS)]);
    });

    it('keeps the dialog open with the refusal toasted', async () => {
        inertia.props = propsWith({ permissions: ['viewBookings', 'manageSettings'], catalog: null });
        view.render(<Page />);
        await openClosure();
        pickToday();
        press(document.querySelector('.admin-closure__add'));

        const [, , visit] = lastPost();
        const event = new CustomEvent('invalid', { cancelable: true, detail: { response: { status: 400, data: { code: 'invalid_request', data: { detail: 'closure-refusal-token' } } } } });
        act(() => visit.onStart?.());
        act(() => inertia.listeners.get('invalid')?.(event));
        act(() => visit.onFinish?.());

        expect(document.querySelector('.admin-closure')).not.toBeNull();
        expect(document.querySelector('.admin-toast')?.textContent).toContain('closure-refusal-token');
    });
});

describe('Colour by on the filter card', () => {
    const select = () => view.host.querySelector<HTMLSelectElement>('.admin-calendar-colour-by');
    const help = () => view.host.querySelector('#calendar-colour-by-help')?.textContent ?? null;
    const legendNames = () => [...view.host.querySelectorAll('.admin-calendar-legend__member')].map((el) => el.textContent);

    function choose(value: string): void {
        act(() => {
            const element = select();

            if (element !== null) {
                element.value = value;
                element.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });
    }

    it('offers the three choices on the setting\'s word, and hands the grid the choice with the catalogue\'s categories', () => {
        inertia.props = propsWith({ permissions: ['viewBookings', 'manageBookings', 'manageSettings'], settings: { granularity: 15, week_start: 1, date_format: 'd/m/Y', time_format: 'H:i', colour_by: 'category', messages: [] } });
        view.render(<Page />);

        expect([...(select()?.options ?? [])].map((option) => [option.value, option.textContent])).toEqual([
            ['staff', cal.text('filters.colour_by_choices.staff')],
            ['category', cal.text('filters.colour_by_choices.category')],
            ['status', cal.text('filters.colour_by_choices.status')],
        ]);
        expect(select()?.value).toBe('category');
        expect(select()?.disabled).toBe(false);
        // The note on whom the colours reach is the select's hover title, no line under it.
        expect(help()).toBeNull();
        expect(select()?.title).toBe(cal.text(COLOUR_BY_NOTE));
        expect(grid().dataset['colourBy']).toBe('category');
        expect(grid().dataset['categories']).toBe('Colouring|Hair');
    });

    it('hands the grid the members who picked their own colour on the Staff screen', () => {
        inertia.props = propsWith({ colours: [{ id: 2, colour: 'purple' }] });
        view.render(<Page />);

        expect(grid().dataset['own']).toBe('2:purple');
    });

    it('posts the one word on change, busy until the server answers, and nothing optimistic', () => {
        inertia.props = propsWith({ permissions: ['viewBookings', 'manageBookings', 'manageSettings'] });
        view.render(<Page />);

        choose('status');

        expect(inertia.put).toHaveBeenCalledOnce();
        const [url, body, visit] = inertia.put.mock.calls[0] as [string, Record<string, unknown>, Visit];
        expect(url).toBe('/admin/settings/colour-by');
        expect(body).toEqual({ colour_by: 'status' });
        act(() => visit.onStart?.());
        expect(select()?.disabled).toBe(true);
        expect(select()?.getAttribute('aria-busy')).toBe('true');
        expect(help()).toBe(cal.text(COLOUR_BY_SAVING));
        expect(grid().dataset['colourBy']).toBe('staff');

        act(() => visit.onFinish?.());
        answer({ settings: { granularity: 15, week_start: 1, date_format: 'd/m/Y', time_format: 'H:i', colour_by: 'status', messages: [] } });
        expect(select()?.disabled).toBe(false);
        expect(grid().dataset['colourBy']).toBe('status');
    });

    it('is disabled with its reason for a viewer without manageSettings, who still sees the colours', () => {
        inertia.props = propsWith({ settings: { granularity: 15, week_start: 1, date_format: 'd/m/Y', time_format: 'H:i', colour_by: 'status', messages: [] } });
        view.render(<Page />);

        expect(select()?.disabled).toBe(true);
        expect(help()).toBe(cal.text(COLOUR_BY_LOCKED));
        expect(select()?.getAttribute('aria-describedby')).toBe('calendar-colour-by-help');
        expect(grid().dataset['colourBy']).toBe('status');
    });

    it('lists the statuses drawn in the legend under Status, and draws it on a tenant whose staff choice is not real', () => {
        const floor = { entitlements: [], options: { ...(propsWith().options as Record<string, unknown>), staff: [], members: 1 } };
        inertia.props = propsWith({ ...floor, settings: { granularity: 15, week_start: 1, date_format: 'd/m/Y', time_format: 'H:i', colour_by: 'status', messages: [] } });
        view.render(<Page />);

        expect(grid().dataset['palette']).toBe('false');
        expect(legendNames()).toEqual([shell.text('kit.statuses.awaiting_approval'), shell.text('kit.statuses.confirmed')]);

        inertia.props = propsWith(floor);
        view.render(<Page />);
        expect(view.host.querySelector('.admin-calendar-legend')).toBeNull();
    });
});

describe('Day as member columns', () => {
    const THURSDAY = '2026-11-05';
    const IMPLICIT = 1;
    const SPLIT = [
        { start: '09:00', end: '13:00' },
        { start: '14:00', end: '18:00' },
    ];
    const ELENA_HOURS: DayHours = { windows: SPLIT, closures: [{ start: '14:00', end: '15:00', reason: 'Dentist', business: false }], source: 'pattern', pattern: SPLIT };
    const NIKOS_HOURS: DayHours = { windows: [], closures: [], source: 'none', pattern: [] };
    const NIKOS_NEXT = { date: '2026-11-07', windows: [{ start: '10:00', end: '19:00' }] };
    const ELENA_THURSDAY: CalendarBooking = { ...REQUEST, uuid: 'thu', status: 'confirmed', items: [{ ...(REQUEST.items[0] as CalendarBooking['items'][number]), start: '2026-11-05T08:00:00+00:00', end: '2026-11-05T09:00:00+00:00' }] };
    const IMPLICIT_THURSDAY: CalendarBooking = { ...ELENA_THURSDAY, uuid: 'implicit', items: [{ ...(ELENA_THURSDAY.items[0] as CalendarBooking['items'][number]), staff_id: IMPLICIT }] };
    const DAY_WINDOW: Window = {
        from: THURSDAY,
        to: '2026-11-06',
        who: 'scheduled',
        staff: [],
        members: [ELENA_ROW, NIKOS_ROW],
        implicit: IMPLICIT,
        hours: { [String(ELENA)]: { [THURSDAY]: ELENA_HOURS }, [String(NIKOS)]: { [THURSDAY]: NIKOS_HOURS } },
        next_working: { [String(NIKOS)]: NIKOS_NEXT },
        bookings: [ELENA_THURSDAY, IMPLICIT_THURSDAY],
    };
    const shifts = keyedShifts();
    const EVERYTHING = ['viewBookings', 'manageBookings', 'manageSettings'];

    const columns = () => [...view.host.querySelectorAll<HTMLElement>('.stub-column')];
    const column = (key: string) => view.host.querySelector<HTMLElement>(`.stub-column[data-column="${key}"]`);
    const menuButton = (key: string) => column(key)?.querySelector<HTMLElement>('.admin-calendar-head__menu') ?? null;
    const menuItems = () => [...document.querySelectorAll<HTMLElement>('.admin-menu__item')].map((item) => item.querySelector('.admin-menu__label')?.textContent);

    function onDay(overrides: Record<string, unknown> = {}): void {
        inertia.props = propsWith({ opening: { view: 'day', date: THURSDAY }, window: DAY_WINDOW, permissions: EVERYTHING, ...overrides });
        view.render(<Page />);
    }

    async function openMenu(key: string): Promise<void> {
        const button = menuButton(key);
        button?.focus();
        act(() => {
            button?.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));
        });
        await settle();
    }

    async function pick(item: string): Promise<void> {
        press(document.querySelector(item));
        await settle();
    }

    it('draws Day as a column per member the window answers with their hours and next working day, then the other bookings\' column, reading nothing more', () => {
        onDay();

        expect(grid().dataset['view']).toBe('day');
        expect(inertia.get).not.toHaveBeenCalled();
        expect(columns().map((element) => element.dataset['column'])).toEqual([`member-${ELENA}`, `member-${NIKOS}`, 'other']);
        expect(JSON.parse(column(`member-${ELENA}`)?.dataset['hours'] ?? 'null')).toEqual(ELENA_HOURS);
        expect(column(`member-${ELENA}`)?.dataset['blocks']).toBe('thu:0');
        expect(JSON.parse(column(`member-${NIKOS}`)?.dataset['next'] ?? 'null')).toEqual(NIKOS_NEXT);
        // The implicit row's booking has no column of its own, so it goes in the trailing one.
        expect(column('other')?.dataset['blocks']).toBe('implicit:0');
        expect(column('other')?.textContent).toBe(cal.text(OTHER_BOOKINGS));
        expect(column(`member-${ELENA}`)?.querySelector('.admin-calendar-head__hours')?.textContent).toBe('09:00-13:00, 14:00-18:00');
        expect(column(`member-${NIKOS}`)?.querySelector('.admin-calendar-head__hours')?.textContent).toBe(cal.text(NOT_WORKING_THIS_DAY));
    });

    it('adds Closure and Outside the member\'s hours to the legend in Day, and not in Week', () => {
        onDay();
        expect([...view.host.querySelectorAll(`.admin-calendar-legend ul[aria-label="${cal.text('legend.closures')}"] li`)].map((item) => item.textContent)).toEqual([cal.text(LEGEND_CLOSURE), cal.text(LEGEND_OUTSIDE)]);
        expect(view.host.querySelector('.admin-calendar-legend__outside .admin-calendar-hatch')).not.toBeNull();

        choose('week');
        expect(view.host.querySelector('.admin-calendar-legend')).not.toBeNull();
        expect(view.host.querySelector('.admin-calendar-legend__closure')).toBeNull();
        expect(view.host.querySelector('.admin-calendar-legend__outside')).toBeNull();
    });

    it('leaves out the other bookings\' column on a day with none, and hands Week and Month no columns', () => {
        onDay({ window: { ...DAY_WINDOW, bookings: [ELENA_THURSDAY] } });
        expect(columns().map((element) => element.dataset['column'])).toEqual([`member-${ELENA}`, `member-${NIKOS}`]);

        choose('week');
        expect(grid().dataset['view']).toBe('week');
        expect(columns()).toHaveLength(0);

        choose('month');
        expect(columns()).toHaveLength(0);
    });

    it('keeps Day the one time grid with no head and no menu where the staff choice is not real', () => {
        onDay({ entitlements: [], options: { ...(propsWith().options as Record<string, unknown>), members: 1 }, window: { ...DAY_WINDOW, members: [], hours: [], next_working: [], bookings: [IMPLICIT_THURSDAY] } });

        expect(grid().dataset['view']).toBe('day');
        expect(grid().dataset['blocks']).toBe('implicit:0');
        expect(columns()).toHaveLength(0);
        expect(view.host.querySelector('.admin-calendar-head')).toBeNull();
    });

    it('opens each member\'s menu titled with the name and the date on the four actions for a viewer holding them all', async () => {
        onDay();
        await openMenu(`member-${ELENA}`);

        expect(document.querySelector('.admin-menu__title')?.textContent).toBe(cal.text('day.menu', { staff: 'Elena Papadaki', date: dayTitle('2026-11-05') }));
        expect(menuItems()).toEqual([NEW_BOOKING_HERE, CLOSURE_HERE, EDIT_THIS_DAYS_HOURS, OPEN_STAFF_PAGE].map((key) => cal.text(key)));
        expect(document.querySelector('.admin-calendar-column__staff')?.getAttribute('href')).toBe(`/admin/staff/${ELENA}`);
        expect(menuButton('other')).toBeNull();
    });

    it('disables a member\'s menu while the column\'s day is not yet read, so Edit this day\'s hours cannot open on the window read before', async () => {
        onDay({ window: { ...DAY_WINDOW, from: '2026-11-12', to: '2026-11-13' } });

        expect(column(`member-${ELENA}`)?.querySelector('.admin-calendar-head__hours')).toBeNull();
        expect(menuButton(`member-${ELENA}`)?.hasAttribute('disabled')).toBe(true);

        await openMenu(`member-${ELENA}`);
        expect(menuItems()).toEqual([]);
        expect(document.querySelector('.admin-calendar-column__edit')).toBeNull();
    });

    it('starts the wizard on the member and the day from New booking here, and from a slot of the column', async () => {
        onDay();
        await openMenu(`member-${NIKOS}`);
        await pick('.admin-calendar-column__book');

        expect(wizard.open).toHaveBeenLastCalledWith({ date: THURSDAY, staffId: NIKOS });

        press(column(`member-${ELENA}`)?.querySelector('.stub-column-slot') ?? null);
        expect(wizard.open).toHaveBeenLastCalledWith({ date: THURSDAY, staffId: ELENA });
    });

    it('opens the closure dialog on the member and the date from Closure here', async () => {
        onDay();
        await openMenu(`member-${NIKOS}`);
        await pick('.admin-calendar-column__close');

        expect(document.querySelector('.admin-modal__title')?.textContent).toBe(cal.text(CLOSURE_TITLE));
        expect(document.querySelector<HTMLSelectElement>('.admin-closure__who')?.value).toBe(String(NIKOS));
        expect(document.querySelector<HTMLButtonElement>('.admin-closure__add')?.disabled).toBe(false);

        press(document.querySelector('.admin-closure__add'));
        const call = inertia.post.mock.calls.at(-1) as [string, Record<string, unknown>] | undefined;
        expect(call?.[0]).toBe(`/admin/staff/${NIKOS}/blackouts`);
        expect(call?.[1]).toEqual({ date: THURSDAY, until: null, start_time: null, end_time: null, reason: null });
    });

    it('mounts the Shifts day editor on the member and the date from Edit today\'s hours, its save putting that date\'s windows, and re-reads the Day window when the redirect lands the default props', async () => {
        onDay();
        await openMenu(`member-${ELENA}`);
        await pick('.admin-calendar-column__edit');

        expect(document.querySelector('.admin-modal__title')?.textContent).toBe(shifts.text('editor.title', { name: 'Elena Papadaki', weekday: weekdayName(4, 'long', 'en'), date: '05/11/2026' }));
        expect([...document.querySelectorAll<HTMLElement>('.admin-shift-day__window')]).toHaveLength(2);
        expect(document.querySelector('.admin-shift-day__back')).toBeNull();

        press(document.querySelector('.admin-shift-day__save'));
        const call = inertia.put.mock.calls.at(-1) as [string, Record<string, unknown>, Visit] | undefined;
        expect(call?.[0]).toBe(`/admin/shifts/${ELENA}/days/${THURSDAY}`);
        expect(call?.[1]).toEqual({ windows: SPLIT });

        act(() => call?.[2].onStart?.());
        expect(document.querySelector('.admin-shift-day__save')?.getAttribute('aria-busy')).toBe('true');
        act(() => call?.[2].onSuccess?.());
        act(() => call?.[2].onFinish?.());
        expect(document.querySelector('.admin-shift-day')).toBeNull();

        // The write redirects back, landing the default week; the screen asks for its Day again.
        answer({ window: WINDOW });
        expect(lastRead()?.data).toMatchObject({ from: THURSDAY, to: '2026-11-06' });
        expect(lastRead()?.visit.only).toEqual(['window']);

        const moved: DayHours = { ...ELENA_HOURS, windows: [{ start: '11:00', end: '15:00' }], source: 'override' };
        answer({ window: { ...DAY_WINDOW, hours: { [String(ELENA)]: { [THURSDAY]: moved }, [String(NIKOS)]: { [THURSDAY]: NIKOS_HOURS } } } });
        expect(JSON.parse(column(`member-${ELENA}`)?.dataset['hours'] ?? 'null')).toEqual(moved);
        expect(column(`member-${ELENA}`)?.querySelector('.admin-calendar-head__hours')?.textContent).toBe('11:00-15:00');
    });

    it('leaves out each item its viewer may not take: the wizard\'s without the catalog, the closure\'s and the editor\'s without manageSettings, and both without the staff feature', async () => {
        onDay({ permissions: ['viewBookings', 'manageBookings'] });
        await openMenu(`member-${ELENA}`);
        expect(menuItems()).toEqual([NEW_BOOKING_HERE, OPEN_STAFF_PAGE].map((key) => cal.text(key)));
        key(document.activeElement, 'Escape');
        await settle();

        onDay({ catalog: null });
        await openMenu(`member-${ELENA}`);
        expect(menuItems()).toEqual([CLOSURE_HERE, EDIT_THIS_DAYS_HOURS, OPEN_STAFF_PAGE].map((key) => cal.text(key)));
        key(document.activeElement, 'Escape');
        await settle();

        // The staff feature lost but several members kept (6.1's rows half): a member's closure is
        // refused without the feature.
        onDay({ catalog: null, entitlements: [] });
        await openMenu(`member-${ELENA}`);
        expect(menuItems()).toEqual([cal.text(OPEN_STAFF_PAGE)]);
    });

    it('keeps Day the one time grid, its slot opening the wizard, where Day has no member column, though bookings on a row with no column are drawn', () => {
        onDay({ window: { ...DAY_WINDOW, members: [], hours: [], next_working: [], bookings: [IMPLICIT_THURSDAY] } });

        expect(grid().dataset['view']).toBe('day');
        expect(grid().dataset['blocks']).toBe('implicit:0');
        expect(columns()).toHaveLength(0);
        expect(view.host.querySelector('.admin-calendar-head')).toBeNull();

        press(view.host.querySelector('.stub-slot'));
        expect(wizard.open).toHaveBeenLastCalledWith({ date: THURSDAY, staffId: null });
    });

    it('heads each column by the name alone, with no hours line and no column idle, while the window still holds the day before', () => {
        onDay();
        press(view.host.querySelector('.admin-calendar-next'));

        expect(grid().dataset['date']).toBe('2026-11-06');
        expect(lastRead()?.data).toMatchObject({ from: '2026-11-06', to: '2026-11-07' });
        expect(columns().map((element) => element.dataset['column'])).toEqual([`member-${ELENA}`, `member-${NIKOS}`]);
        expect(columns().map((element) => element.dataset['hours'])).toEqual(['null', 'null']);
        expect(columns().map((element) => element.dataset['next'])).toEqual([undefined, undefined]);
        expect([...view.host.querySelectorAll('.admin-calendar-head__name')].map((name) => name.textContent)).toEqual(['Elena Papadaki', 'Nikos Georgiou']);
        expect(view.host.querySelector('.admin-calendar-head__hours')).toBeNull();
        expect(view.host.textContent).not.toContain(cal.text(NOT_WORKING_THIS_DAY));
    });

    it('shows a viewer pinned to Me their own column\'s head without the menu', () => {
        onDay({ pinned: true, own: ELENA_ROW, permissions: ['viewOwnCalendar'], catalog: null, window: { ...DAY_WINDOW, who: 'me', members: [ELENA_ROW], implicit: null, next_working: [], bookings: [ELENA_THURSDAY] } });

        expect(columns().map((element) => element.dataset['column'])).toEqual([`member-${ELENA}`]);
        expect(column(`member-${ELENA}`)?.querySelector('.admin-calendar-head__name')?.textContent).toBe('Elena Papadaki');
        expect(menuButton(`member-${ELENA}`)).toBeNull();
    });
});

describe('a drop on the calendar', () => {
    const bookings = keyedBookings();
    const CLOCK = { timezone: 'Europe/Athens', dateFormat: 'd/m/Y', timeFormat: 'H:i' };
    // The chain's first block sits at 10:00 Athens on the third; an hour later, onto Nikos's
    // column.
    const TO = '2026-11-03T09:00:00+00:00';
    const SEGMENTS = [
        { segment: 0, service_id: 1, service_name: 'Haircut', staff_id: ELENA, staff_name: 'Elena Papadaki', members: [ELENA_ROW, NIKOS_ROW] },
        { segment: 1, service_id: 3, service_name: 'Colour', staff_id: NIKOS, staff_name: 'Nikos Georgiou', members: [NIKOS_ROW] },
    ];

    let fetched: ReturnType<typeof vi.fn>;
    let reply: () => Response | Promise<Response>;

    const verdict = (body: Record<string, unknown>, status = 200) => new Response(JSON.stringify({ start: TO, segments: SEGMENTS, ...body }), { status });

    beforeEach(() => {
        dropping.start = '2026-11-03T11:00:00';
        dropping.staffId = NIKOS;
        reply = () => verdict({ verdict: { placeable: true, crossings: [] } });
        fetched = vi.fn(async () => reply());
        vi.stubGlobal('fetch', fetched);
    });

    afterEach(() => vi.unstubAllGlobals());

    const one = (selector: string) => document.body.querySelector(selector);
    const toasts = () => [...document.body.querySelectorAll('.admin-toast')].map((toast) => toast.querySelector('p, span')?.textContent ?? toast.textContent);

    async function drop(): Promise<void> {
        view.render(<Page />);
        press(view.host.querySelector('.stub-drop'));
        await settle();
    }

    function lastPost(): { url: string; body: Record<string, unknown>; visit: Visit } {
        const call = inertia.post.mock.lastCall as [string, Record<string, unknown>, Visit] | undefined;

        if (call === undefined) {
            throw new Error('Nothing was posted.');
        }

        return { url: call[0], body: call[1], visit: call[2] };
    }

    function refuse(status: number, code: string, detail: string, data: Record<string, unknown> = {}): void {
        const { visit } = lastPost();
        const event = new CustomEvent('invalid', { cancelable: true, detail: { response: { status, data: { code, message: code, data: { status, detail, ...data } } } } });

        act(() => visit.onStart?.());
        act(() => inertia.listeners.get('invalid')?.(event));
        act(() => visit.onFinish?.());
    }

    it('asks the read for the verdict on the booking\'s new start with the segment the drop gave another member, before anything is written', async () => {
        await drop();

        const [path = '', query = ''] = String(fetched.mock.calls[0]?.[0] ?? '').split('?');
        const params = new URLSearchParams(query);

        expect(fetched).toHaveBeenCalledTimes(1);
        expect(path).toBe('/admin/api/bookings/chain/move');
        expect(params.get('start')).toBe(TO);
        expect(params.get('staff[0]')).toBe(String(NIKOS));
        expect(params.has('date')).toBe(false);
        expect(inertia.post).not.toHaveBeenCalled();
    });

    it('asks nothing for a drop that changes neither the time nor the member', async () => {
        dropping.start = '2026-11-03T10:00:00';
        dropping.staffId = null;
        await drop();

        expect(fetched).not.toHaveBeenCalled();
        expect(one('.admin-move-confirm')).toBeNull();
    });

    it('opens no confirmation where the verdict is not placeable, the read refused or nothing came back, and toasts the server\'s sentence or the screen\'s own', async () => {
        reply = () => verdict({ verdict: { placeable: false, code: 'overlap', status: 409, detail: 'That time was just taken. Please pick another.', segment: 0 } });
        await drop();
        expect(one('.admin-move-confirm')).toBeNull();
        expect(toasts().at(-1)).toContain('That time was just taken. Please pick another.');

        reply = () => new Response(JSON.stringify({ code: 'staff_not_eligible', message: 'staff_not_eligible', data: { status: 400, detail: 'Nikos does not give this service.' } }), { status: 400 });
        press(view.host.querySelector('.stub-drop'));
        await settle();
        expect(one('.admin-move-confirm')).toBeNull();
        expect(toasts().at(-1)).toContain('Nikos does not give this service.');

        reply = () => Promise.reject(new TypeError('Failed to fetch'));
        press(view.host.querySelector('.stub-drop'));
        await settle();
        expect(one('.admin-move-confirm')).toBeNull();
        expect(toasts().at(-1)).toContain(bookings.text('move.not_moved'));
        expect(inertia.post).not.toHaveBeenCalled();
    });

    it('opens the Move form\'s confirmation on a placeable verdict, with from and to, the member change by name and the crossings by name', async () => {
        reply = () => verdict({ verdict: { placeable: true, crossings: [{ segment: 0, staff_id: NIKOS, kind: 'hours' }] } });
        await drop();

        expect(one('.admin-move-confirm__from-to')?.textContent).toBe(bookings.text('move.confirm.from_to', { from: formatInstant('2026-11-03T08:00:00+00:00', CLOCK), to: formatInstant(TO, CLOCK) }));
        expect([...document.body.querySelectorAll('.admin-move-confirm__change')].map((line) => line.textContent)).toEqual([bookings.text('move.confirm.member', { service: 'Haircut', from: 'Elena Papadaki', to: 'Nikos Georgiou' })]);
        expect([...document.body.querySelectorAll('.admin-move-confirm__crossing')].map((line) => line.textContent)).toEqual([bookings.text('move.confirm.hours', { service: 'Haircut', staff: 'Nikos Georgiou' })]);
    });

    it('writes nothing on Keep it', async () => {
        await drop();
        press(one('.admin-move-confirm__keep'));
        await settle();

        expect(one('.admin-move-confirm')).toBeNull();
        expect(inertia.post).not.toHaveBeenCalled();
    });

    it('posts the move as the Move form does on confirm, the warning accepted exactly when shown, and closes once it lands', async () => {
        await drop();
        press(one('.admin-move-confirm__confirm'));

        expect(lastPost().url).toBe('/admin/bookings/chain/move');
        expect(lastPost().body).toEqual({ start: TO, staff: { 0: NIKOS }, warning_accepted: false, tell_customer: true });

        act(() => lastPost().visit.onSuccess?.());
        await settle();
        expect(one('.admin-move-confirm')).toBeNull();
    });

    it('puts the write\'s unaccepted warning into the open confirmation for one more confirm', async () => {
        await drop();
        press(one('.admin-move-confirm__confirm'));
        refuse(409, 'unaccepted_warning', 'The time crosses something.', { crossings: [{ segment: 1, staff_id: NIKOS, kind: 'closure' }] });

        expect(one('.admin-move-confirm')).not.toBeNull();
        expect([...document.body.querySelectorAll('.admin-move-confirm__crossing')].map((line) => line.textContent)).toEqual([bookings.text('move.confirm.closure', { service: 'Colour', staff: 'Nikos Georgiou' })]);
        press(one('.admin-move-confirm__confirm'));
        expect(inertia.post).toHaveBeenCalledTimes(2);
        expect(lastPost().body).toEqual({ start: TO, staff: { 0: NIKOS }, warning_accepted: true, tell_customer: true });
    });

    it('closes the confirmation on any other refusal and toasts the server\'s sentence, or the screen\'s own where none came back', async () => {
        await drop();
        press(one('.admin-move-confirm__confirm'));
        refuse(409, 'overlap', 'That time was just taken. Please pick another.', { segment: 0 });
        await settle();

        expect(one('.admin-move-confirm')).toBeNull();
        expect(toasts().at(-1)).toContain('That time was just taken. Please pick another.');

        press(view.host.querySelector('.stub-drop'));
        await settle();
        press(one('.admin-move-confirm__confirm'));
        const { visit } = lastPost();
        act(() => visit.onStart?.());
        act(() => inertia.listeners.get('exception')?.(new Event('exception', { cancelable: true })));
        act(() => visit.onFinish?.());
        await settle();

        expect(one('.admin-move-confirm')).toBeNull();
        expect(toasts().at(-1)).toContain(bookings.text('move.not_moved'));
    });
});
