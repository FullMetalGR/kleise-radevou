import { act } from 'react';
import { afterEach, beforeEach, describe, expect, expectTypeOf, it, vi } from 'vitest';
import { ADMIN_LINES_EN } from '../lines.generated';
import { mount, press, type Mounted } from '@/components/ui/harness';
import { formatAmount, formatCount, formatPercent } from '@/lib/format';
import { ShellProvider } from '@/lib/strings';
import { monthName, unitFigure } from '@/lib/names';
import { currencyName, formatOccupancy, formatRate, hoursOf, type RangeFigures, type Report, type ReportFilters, type ReportOptions, type NamedRange } from '@/reports/model';
import { KEYED_REPORTS, keyedReports, keyedShell } from '@/testing/keyed';
import { ToastProvider } from '@/toast/toasts';
import type { ReportsStrings } from '@/types';
import Reports from './Reports';

/*
 * The Reports screen: the filter card, Range with its nine words and a custom range, Staff and
 * Service, and the range's line; the six tiles, each beside the period before in its kind of
 * range's sentence, and their footnote; then the cards and tables as built, each amount in its
 * own currency, an empty range reading zero. Every change of a filter is a partial reload of the
 * range, the filters, the tiles and the tables with the URL replaced and the scroll kept, busy
 * while in flight; a failed read is said in the screen's own words with what is on screen left
 * standing. Every word is a key of the reports section, rendered through the keyed table. The
 * framework is stood in for by a router that records each visit and the failure listeners
 * registered around it.
 */

const shell = keyedShell();

const words = keyedReports();

/** A signed change as the comparison sentence fills it, in the page's language. */
function signed(percent: number, locale: string = words.locale): string {
    return formatPercent(percent, locale, true);
}

type Shape<T> = { -readonly [K in keyof T]: T[K] extends string ? string : Shape<T[K]> };

type Visit = { only?: string[]; replace?: boolean; preserveState?: boolean; preserveScroll?: boolean; onStart?: () => void; onSuccess?: () => void; onFinish?: () => void };

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

const WEEK: NamedRange = { word: 'week', from: '2026-10-19', to: '2026-10-25' };

const ALL: ReportFilters = { staff: null, service: null };

const OPTIONS: ReportOptions = {
    staff: [
        { id: 4, name: 'Elena Papadaki' },
        { id: 5, name: 'Nikos Georgiou' },
    ],
    services: [
        { id: 7, name: 'Haircut' },
        { id: 8, name: 'Colour' },
    ],
};

const STATUSES = ['pending', 'awaiting_approval', 'awaiting_payment', 'confirmed', 'completed', 'no_show', 'cancelled', 'rejected', 'expired'];

const EUR_ZERO = [{ currency: 'EUR', minor: 0 }];

const READ = ['range', 'filters', 'figures', 'report'];

/** The fixture week: every kind of change, the occupancy's two amounts and the returning customers. */
function tilesOf(range: NamedRange = WEEK): RangeFigures {
    return {
        range,
        before: { from: '2026-10-12', to: '2026-10-18' },
        tiles: {
            bookings: { value: 23, previous: 20, change: { kind: 'up', percent: 15 } },
            revenue: { value: { currency: 'EUR', minor: 114500 }, previous: { currency: 'EUR', minor: 105000 }, change: { kind: 'up', percent: 9 }, elsewhere: [] },
            occupancy: { value: 58.3, previous: 56.1, change: { kind: 'up', percent: 4 }, filled_minutes: 2975, offered_minutes: 5100 },
            new_customers: { value: 6, previous: 6, change: { kind: 'same', percent: 0 }, returning: 17 },
            cancelled: { value: 2, previous: 3, change: { kind: 'down', percent: -33 } },
            no_shows: { value: 1, previous: 0, change: { kind: 'from_zero', percent: null } },
        },
    };
}

const DAYS = ['2026-10-19', '2026-10-20', '2026-10-21', '2026-10-22', '2026-10-23', '2026-10-24', '2026-10-25'];

/** The fixture week of Over time: bookings and euros per day. */
const OVER_TIME: Report['over_time'] = {
    unit: 'day',
    rows: DAYS.map((day, index) => ({ from: day, to: day, bookings: [0, 4, 5, 5, 4, 3, 2][index] ?? 0, revenue: { currency: 'EUR', minor: [0, 19000, 25500, 23500, 21000, 16000, 9500][index] ?? 0 } })),
};

/** The tables' week: every status, two currencies, two services, two members, three sources, two channels. */
const FIGURES: Report = {
    statuses: STATUSES.map((status) => ({ status, bookings: ({ confirmed: 2, completed: 3 } as Record<string, number>)[status] ?? 1 })),
    revenue: {
        confirmed: [
            { currency: 'EUR', minor: 3500 },
            { currency: 'USD', minor: 6000 },
        ],
        completed: [
            { currency: 'EUR', minor: 13000 },
            { currency: 'USD', minor: 6000 },
        ],
    },
    no_show: { no_show: 1, completed: 3 },
    services: [
        { id: 1, name: 'Haircut', bookings: 7, revenue: [{ currency: 'EUR', minor: 10500 }] },
        { id: 2, name: 'Beard trim', bookings: 0, revenue: EUR_ZERO },
        {
            id: 3,
            name: 'Colour',
            bookings: 6,
            revenue: [
                { currency: 'EUR', minor: 6000 },
                { currency: 'USD', minor: 12000 },
            ],
        },
    ],
    staff: [
        { id: 1, name: 'Luna Hair Studio', bookings: 5, revenue: [{ currency: 'EUR', minor: 9500 }] },
        { id: 2, name: 'Elena Papadaki', bookings: 8, revenue: [{ currency: 'EUR', minor: 7000 }] },
    ],
    sources: [
        { source: 'hosted', bookings: 6, revenue: [{ currency: 'EUR', minor: 13000 }] },
        { source: 'embed', bookings: 4, revenue: [{ currency: 'USD', minor: 6000 }] },
        { source: 'admin', bookings: 2, revenue: [{ currency: 'EUR', minor: 3500 }] },
    ],
    channels: [
        { channel: 'instagram', bookings: 3, revenue: [{ currency: 'EUR', minor: 3500 }] },
        { channel: 'qr', bookings: 2, revenue: [{ currency: 'EUR', minor: 9500 }] },
        { channel: null, bookings: 7, revenue: [{ currency: 'USD', minor: 6000 }] },
    ],
    cancelled_by: { customer: 2, business: 0, unrecorded: 0 },
    over_time: OVER_TIME,
    occupancy: [
        { id: 2, name: 'Elena Papadaki', booked_minutes: 1710, available_minutes: 2400, occupancy: 71.3 },
        { id: 3, name: 'Nikos Georgiou', booked_minutes: 1265, available_minutes: 2700, occupancy: 46.9 },
        { id: 4, name: 'Maria Ioannou', booked_minutes: 0, available_minutes: 0, occupancy: null },
    ],
};

/** An empty range as the server answers one: every count zero, every revenue line zero in the tenant's currency. */
const EMPTY: Report = {
    statuses: STATUSES.map((status) => ({ status, bookings: 0 })),
    revenue: { confirmed: EUR_ZERO, completed: EUR_ZERO },
    no_show: { no_show: 0, completed: 0 },
    services: [{ id: 1, name: 'Haircut', bookings: 0, revenue: EUR_ZERO }],
    staff: [{ id: 1, name: 'Luna Hair Studio', bookings: 0, revenue: EUR_ZERO }],
    sources: ['hosted', 'embed', 'admin'].map((source) => ({ source, bookings: 0, revenue: EUR_ZERO })),
    channels: ['instagram', 'facebook', 'google', 'qr', 'other', null].map((channel) => ({ channel, bookings: 0, revenue: EUR_ZERO })),
    cancelled_by: { customer: 0, business: 0, unrecorded: 0 },
    over_time: { unit: 'day', rows: DAYS.map((day) => ({ from: day, to: day, bookings: 0, revenue: { currency: 'EUR', minor: 0 } })) },
    occupancy: [],
};

type Given = { report?: Report; range?: NamedRange; filters?: ReportFilters; options?: ReportOptions; figures?: RangeFigures };

function propsWith({ report = FIGURES, range = WEEK, filters = ALL, options = OPTIONS, figures }: Given = {}): Record<string, unknown> {
    return {
        tenant: { name: 'Luna Hair Studio', slug: 'luna', timezone: 'Europe/Athens', currency: 'EUR' },
        auth: { user: { id: 1, name: 'Alex Owner', role: 'owner' } },
        flash: null,
        errors: {},
        permissions: ['viewBookings', 'manageBookings'],
        entitlements: ['staff'],
        subscription: { state: 'active' },
        settings: { granularity: 15, week_start: 1, date_format: 'd/m/Y', time_format: 'H:i', messages: [] },
        nav: [],
        language: 'en',
        strings: { shell: {}, reports: KEYED_REPORTS },
        range,
        filters,
        options,
        figures: figures ?? tilesOf(range),
        report,
    };
}

function q(selector: string): Element | null {
    return document.querySelector(selector);
}

function texts(selector: string): (string | null)[] {
    return [...document.querySelectorAll(selector)].map((element) => element.textContent);
}

/** An attribute of the first element a selector finds, null where there is no element or no attribute. */
function attribute(selector: string, name: string): string | null {
    return q(selector)?.getAttribute(name) ?? null;
}

/** The machine value a date field's trigger carries. */
function dateValue(selector: string): string | null {
    return (q(selector) as HTMLButtonElement | null)?.value ?? null;
}

/** A select's options as [value, label]. */
function optionsOf(selector: string): [string, string | null][] {
    return [...document.querySelectorAll(`${selector} option`)].map((option) => [(option as HTMLOptionElement).value, option.textContent]);
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

function lastVisit(): Visit {
    return (inertia.get.mock.lastCall?.[2] ?? {}) as Visit;
}

/** The visit in flight fails as the framework reports it; answers whether a listener took it over. */
function fail(): boolean {
    const event = new Event('invalid', { cancelable: true });

    act(() => inertia.listeners.get('invalid')?.(event));

    return event.defaultPrevented;
}

/** A tile's lines by its name: title, value, then every line under the value. */
function tile(name: string): (string | null)[] {
    return texts(`.admin-figure-tile--${name} > *`);
}

/** The page as the layout holds it, inside the toasts a download's failure is said through. */
function Page() {
    return (
        <ToastProvider>
            <Reports />
        </ToastProvider>
    );
}

let view: Mounted;

beforeEach(() => {
    inertia.get.mockReset();
    inertia.listeners.clear();
    view = mount();
});

afterEach(() => view.unmount());

describe('the reports section\'s type', () => {
    it('is the keys of lang/en/admin/reports.php, no more and no fewer', () => {
        expectTypeOf<Shape<typeof ADMIN_LINES_EN.reports>>().toEqualTypeOf<ReportsStrings>();
        expect(Object.keys(ADMIN_LINES_EN.reports)).toEqual(['page_title', 'filters', 'tiles', 'compare', 'over_time', 'tables', 'occupancy', 'csv']);
    });
});

describe('the filter card', () => {
    it('offers Range\'s nine words and a custom range, Staff and Service with All first, Download CSV at the row\'s end, and states the range', () => {
        inertia.props = propsWith();
        view.render(<Page />);

        expect(q('h1')?.textContent).toBe(words.text('page_title'));
        expect(texts('.admin-reports-filters label')).toEqual([words.text('filters.range'), words.text('filters.staff'), words.text('filters.service')]);
        expect(optionsOf('#reports-range')).toEqual((['today', 'yesterday', 'week', 'last_7_days', 'last_30_days', 'month', 'last_month', 'year', 'custom'] as const).map((word) => [word, words.text(`filters.words.${word}`)]));
        expect((q('#reports-range') as HTMLSelectElement).value).toBe('week');
        expect(optionsOf('#reports-staff')).toEqual([
            ['', words.text('filters.all_staff')],
            ['4', 'Elena Papadaki'],
            ['5', 'Nikos Georgiou'],
        ]);
        expect(optionsOf('#reports-service')).toEqual([
            ['', words.text('filters.all_services')],
            ['7', 'Haircut'],
            ['8', 'Colour'],
        ]);
        expect(q('.admin-reports-custom')).toBeNull();
        expect(q('.admin-reports-filters__stated')?.textContent).toBe(words.text('filters.stated.week', { from: '19/10/2026', to: '25/10/2026' }));
        expect(q('.admin-reports-filters .admin-reports-download')?.textContent).toBe(words.text('filters.download'));
        expect(q('.admin-reports-filters .admin-reports-download')?.className).toContain('ml-auto');
        expect(q('.admin-reports-loading')).toBeNull();
    });

    it('leaves Staff out where no member but the business is offered, and seeds every control from the server\'s answer', () => {
        inertia.props = propsWith({ options: { staff: [], services: OPTIONS.services }, filters: { staff: null, service: 8 }, range: { word: 'last_month', from: '2026-09-01', to: '2026-09-30' } });
        view.render(<Page />);

        expect(q('#reports-staff')).toBeNull();
        expect((q('#reports-service') as HTMLSelectElement).value).toBe('8');
        expect((q('#reports-range') as HTMLSelectElement).value).toBe('last_month');
        expect(q('.admin-reports-filters__stated')?.textContent).toBe(words.text('filters.stated.last_month', { from: '01/09/2026', to: '30/09/2026' }));
    });

    it('reads a word at once by a partial reload of the four props, the URL replaced and the scroll kept, this week the bare screen', () => {
        inertia.props = propsWith({ filters: { staff: 4, service: null } });
        view.render(<Page />);

        choose('#reports-range', 'last_month');
        expect(inertia.get).toHaveBeenCalledTimes(1);
        expect(inertia.get.mock.lastCall?.[0]).toBe('/admin/reports');
        expect(inertia.get.mock.lastCall?.[1]).toEqual({ range: 'last_month', staff: '4' });
        expect(lastVisit()).toMatchObject({ only: READ, replace: true, preserveState: true, preserveScroll: true });

        const month = lastVisit();
        act(() => month.onStart?.());
        expect(q('.admin-reports-loading')?.textContent).toBe(words.text('filters.loading'));
        expect(q('.admin-reports')?.getAttribute('aria-busy')).toBe('true');
        expect(q('.admin-reports-tiles')?.getAttribute('aria-busy')).toBe('true');

        const lastMonth: NamedRange = { word: 'last_month', from: '2026-09-01', to: '2026-09-30' };
        inertia.props = propsWith({ report: EMPTY, range: lastMonth, filters: { staff: 4, service: null } });
        act(() => month.onSuccess?.());
        act(() => month.onFinish?.());
        view.render(<Page />);

        expect(q('.admin-reports-loading')).toBeNull();
        expect(q('.admin-reports-filters__stated')?.textContent).toBe(words.text('filters.stated.last_month', { from: '01/09/2026', to: '30/09/2026' }));
        expect((q('#reports-range') as HTMLSelectElement).value).toBe('last_month');

        choose('#reports-range', 'week');
        expect(inertia.get.mock.lastCall?.[1]).toEqual({ staff: '4' });
    });

    it('reads a member and a service at once, keeping the range, and All as no filter', () => {
        const year: NamedRange = { word: 'year', from: '2026-01-01', to: '2026-12-31' };
        inertia.props = propsWith({ range: year });
        view.render(<Page />);

        choose('#reports-staff', '5');
        expect(inertia.get.mock.lastCall?.[1]).toEqual({ range: 'year', staff: '5' });
        expect(lastVisit()).toMatchObject({ only: READ, preserveScroll: true });

        inertia.props = propsWith({ range: year, filters: { staff: 5, service: null } });
        view.render(<Page />);
        choose('#reports-service', '7');
        expect(inertia.get.mock.lastCall?.[1]).toEqual({ range: 'year', staff: '5', service: '7' });

        choose('#reports-staff', '');
        expect(inertia.get.mock.lastCall?.[1]).toEqual({ range: 'year' });
    });

    it('opens Custom range on From and To seeded with the range on screen, reads nothing until Apply, and applies the dates with the filters, Apply busy while in flight', () => {
        inertia.props = propsWith({ filters: { staff: null, service: 7 } });
        view.render(<Page />);

        choose('#reports-range', 'custom');
        expect(inertia.get).not.toHaveBeenCalled();
        expect((q('#reports-range') as HTMLSelectElement).value).toBe('custom');
        expect([dateValue('#reports-from'), dateValue('#reports-to')]).toEqual(['2026-10-19', '2026-10-25']);
        expect(texts('.admin-reports-custom label')).toEqual([words.text('filters.from'), words.text('filters.to')]);

        press(q('#reports-to'));
        press(q('button[data-date="2026-10-22"]'));
        expect(dateValue('#reports-to')).toBe('2026-10-22');

        press(q('.admin-reports-apply'));
        expect(inertia.get.mock.lastCall).toEqual(['/admin/reports', { range: 'custom', from: '2026-10-19', to: '2026-10-22', service: '7' }, expect.objectContaining({ only: READ, replace: true })]);

        const applying = lastVisit();
        act(() => applying.onStart?.());
        expect(attribute('.admin-reports-apply', 'aria-busy')).toBe('true');
        expect(texts('.admin-reports-apply, .admin-reports-loading')).toEqual([words.text('filters.applying'), words.text('filters.loading')]);

        const custom: NamedRange = { word: 'custom', from: '2026-10-19', to: '2026-10-22' };
        inertia.props = propsWith({ range: custom, filters: { staff: null, service: 7 } });
        act(() => applying.onSuccess?.());
        act(() => applying.onFinish?.());
        view.render(<Page />);

        expect(q('.admin-reports-filters__stated')?.textContent).toBe(words.text('filters.stated.custom', { from: '19/10/2026', to: '22/10/2026' }));
        expect(dateValue('#reports-to')).toBe('2026-10-22');
        expect(attribute('.admin-reports-apply', 'aria-busy')).toBeNull();
    });

    it('says why a custom pair cannot be read, the To before the From or more than 366 days, and reads nothing', () => {
        inertia.props = propsWith({ range: { word: 'custom', from: '2026-10-19', to: '2026-10-25' } });
        view.render(<Page />);

        press(q('#reports-from'));
        press(q('button[data-date="2026-10-28"]'));
        press(q('.admin-reports-apply'));
        expect(inertia.get).not.toHaveBeenCalled();
        expect(q('.admin-reports-custom .admin-field__error')?.textContent).toBe(words.text('filters.to_before_from'));
        expect(attribute('#reports-to', 'aria-invalid')).toBe('true');

        inertia.props = propsWith({ range: { word: 'custom', from: '2025-01-01', to: '2026-10-25' } });
        view.render(<Page />);
        press(q('.admin-reports-apply'));
        expect(inertia.get).not.toHaveBeenCalled();
        expect(q('.admin-reports-custom .admin-field__error')?.textContent).toBe(words.text('filters.too_long', { count: formatCount(366, words.locale) }));
    });

    it('answers a failed read in its own words, never through the framework modal, what is on screen left standing', () => {
        inertia.props = propsWith();
        view.render(<Page />);

        choose('#reports-range', 'month');
        const month = lastVisit();

        act(() => month.onStart?.());
        expect(fail()).toBe(true);
        act(() => month.onFinish?.());

        expect(q('.admin-reports-error')?.textContent).toBe(words.text('filters.error'));
        expect(q('.admin-reports-error')?.getAttribute('role')).toBe('alert');
        expect(q('.admin-reports-loading')).toBeNull();
        expect(q('.admin-reports-filters__stated')?.textContent).toBe(words.text('filters.stated.week', { from: '19/10/2026', to: '25/10/2026' }));
        expect(tile('bookings')[1]).toBe('23');

        choose('#reports-range', 'month');
        const again = lastVisit();

        act(() => again.onStart?.());
        act(() => again.onSuccess?.());
        act(() => again.onFinish?.());

        expect(q('.admin-reports-error')).toBeNull();
    });
});

describe('the six tiles', () => {
    it('show each figure beside the week before in its own sentence, occupancy with its hours and New customers with the returning, then the footnote', () => {
        inertia.props = propsWith();
        view.render(<Page />);

        expect(texts('.admin-figure-tile h2')).toEqual([words.text('tiles.bookings'), words.text('tiles.revenue'), words.text('tiles.occupancy'), words.text('tiles.new_customers'), words.text('tiles.cancelled'), words.text('tiles.no_shows')]);
        expect(tile('bookings')).toEqual([words.text('tiles.bookings'), '23', words.text('compare.week.by', { change: signed(15) })]);
        expect(tile('revenue')).toEqual([words.text('tiles.revenue'), formatAmount(114500, 'EUR', words.locale), words.text('compare.week.by', { change: signed(9) })]);
        expect(tile('occupancy')).toEqual([
            words.text('tiles.occupancy'),
            '58.3%',
            words.text('compare.week.by', { change: signed(4) }),
            words.text('tiles.occupancy_of', { filled: words.text('tiles.hours_minutes', { hours: unitFigure(49, 'hour', 'en'), minutes: unitFigure(35, 'minute', 'en') }), offered: unitFigure(85, 'hour', 'en') }),
        ]);
        expect(tile('new_customers')).toEqual([words.text('tiles.new_customers'), '6', words.text('compare.week.same'), words.choice('tiles.returning', 17)]);
        expect(tile('cancelled')).toEqual([words.text('tiles.cancelled'), '2', words.text('compare.week.by', { change: signed(-33) })]);
        expect(tile('no_shows')).toEqual([words.text('tiles.no_shows'), '1', words.text('compare.week.from_zero')]);
        expect(q('.admin-reports-tiles-note')?.textContent).toBe(words.text('tiles.note'));
    });

    it('words every comparison by the range the server answered: a custom range by its days', () => {
        const custom: NamedRange = { word: 'custom', from: '2026-10-05', to: '2026-10-14' };
        inertia.props = propsWith({ range: custom });
        view.render(<Page />);

        expect(tile('bookings')[2]).toBe(words.choice('compare.custom.by', 10, { change: signed(15) }));
        expect(tile('no_shows')[2]).toBe(words.choice('compare.custom.from_zero', 10));
    });

    it('reads no change where both are zero, a short phrase and no comparison where no hours are offered, no comparison where the period before offered none, over 100% as it is, and another currency quietly beneath Revenue', () => {
        const base = tilesOf();
        const figures: RangeFigures = {
            ...base,
            tiles: {
                ...base.tiles,
                bookings: { value: 0, previous: 0, change: { kind: 'none', percent: null } },
                revenue: { ...base.tiles.revenue, elsewhere: [{ currency: 'USD', minor: 6000 }] },
                occupancy: { value: null, previous: 40, change: { kind: 'none', percent: null }, filled_minutes: 45, offered_minutes: 0 },
            },
        };
        inertia.props = propsWith({ figures });
        view.render(<Page />);

        expect(tile('bookings')).toEqual([words.text('tiles.bookings'), '0', words.text('compare.week.same')]);
        expect(tile('occupancy')).toEqual([words.text('tiles.occupancy'), words.text('tiles.no_hours')]);
        expect(tile('revenue')).toEqual([words.text('tiles.revenue'), formatAmount(114500, 'EUR', words.locale), words.text('compare.week.by', { change: signed(9) }), words.text('tiles.elsewhere', { amount: formatAmount(6000, 'USD', words.locale) })]);

        inertia.props = propsWith({ figures: { ...base, tiles: { ...base.tiles, occupancy: { value: 112.5, previous: null, change: { kind: 'none', percent: null }, filled_minutes: 540, offered_minutes: 480 } } } });
        view.render(<Page />);

        expect(tile('occupancy')).toEqual([words.text('tiles.occupancy'), '112.5%', words.text('tiles.occupancy_of', { filled: unitFigure(9, 'hour', 'en'), offered: unitFigure(8, 'hour', 'en') })]);

        inertia.props = propsWith({ figures: { ...base, tiles: { ...base.tiles, occupancy: { value: 0, previous: 0, change: { kind: 'none', percent: null }, filled_minutes: 0, offered_minutes: 480 } } } });
        view.render(<Page />);

        expect(tile('occupancy')[1]).toBe('0.0%');
        expect(tile('occupancy')[2]).toBe(words.text('compare.week.same'));
    });
});

describe('every figure in the user\'s language', () => {
    /** The page as a Greek user reads it: the shell's language el, the keyed section's words. */
    function GreekPage() {
        return (
            <ShellProvider strings={null} language="el">
                <Page />
            </ShellProvider>
        );
    }

    /** The fixture week grown past a thousand everywhere a count or an amount stands. */
    function large(): { figures: RangeFigures; report: Report } {
        const base = tilesOf();

        return {
            figures: {
                ...base,
                tiles: {
                    ...base.tiles,
                    bookings: { value: 1250, previous: 100, change: { kind: 'up', percent: 1150 } },
                    revenue: { ...base.tiles.revenue, value: { currency: 'EUR', minor: 12345600 }, elsewhere: [{ currency: 'USD', minor: 250000 }] },
                    new_customers: { value: 2400, previous: 2400, change: { kind: 'same', percent: 0 }, returning: 1700 },
                },
            },
            report: {
                ...FIGURES,
                statuses: STATUSES.map((status) => ({ status, bookings: status === 'confirmed' ? 4321 : 1 })),
                no_show: { no_show: 1200, completed: 2400 },
                cancelled_by: { customer: 1500, business: 0, unrecorded: 0 },
                staff: [{ id: 2, name: 'Elena Papadaki', bookings: 1800, revenue: [{ currency: 'EUR', minor: 900000 }] }],
                over_time: { unit: 'day', rows: [{ from: '2026-10-19', to: '2026-10-19', bookings: 1250, revenue: { currency: 'EUR', minor: 12345600 } }] },
            },
        };
    }

    /** Every figure the page shows, by where it stands. */
    function figures(): (string | null)[] {
        return [
            ...tile('bookings').slice(1),
            ...tile('revenue').slice(1),
            ...tile('occupancy').slice(1, 3),
            ...tile('new_customers').slice(1),
            q('.admin-reports-statuses__count')?.textContent ?? null,
            ...texts('.admin-reports-revenue__confirmed .admin-reports__amount'),
            q('.admin-reports-no-show__rate')?.textContent ?? null,
            q('.admin-reports-no-show__no-shows')?.textContent ?? null,
            q('.admin-reports-cancelled-by dd')?.textContent ?? null,
            ...texts('.admin-reports-staff td').slice(1),
            ...texts('.admin-reports-over-time tbody td').slice(2),
            q('.admin-reports-over-time__revenue svg')?.getAttribute('aria-label') ?? null,
        ];
    }

    it('spells counts, money, percentages, the rate and the comparisons by Intl in the user\'s language, never the machine\'s, so el and en read their own forms', () => {
        const { figures: tiles, report } = large();
        const greek = keyedReports('el');
        const shown = (locale: string): (string | null)[] => [
            formatCount(1250, locale),
            words.text('compare.week.by', { change: signed(1150, locale) }),
            formatAmount(12345600, 'EUR', locale),
            words.text('compare.week.by', { change: signed(9, locale) }),
            words.text('tiles.elsewhere', { amount: formatAmount(250000, 'USD', locale) }),
            formatOccupancy(58.3, locale),
            words.text('compare.week.by', { change: signed(4, locale) }),
            formatCount(2400, locale),
            words.text('compare.week.same'),
            words.choice('tiles.returning', 1700, { count: formatCount(1700, locale) }),
            formatCount(4321, locale),
            formatAmount(3500, 'EUR', locale),
            formatAmount(6000, 'USD', locale),
            formatRate(1 / 3, locale),
            formatCount(1200, locale),
            formatCount(1500, locale),
            formatCount(1800, locale),
            formatAmount(900000, 'EUR', locale),
            formatCount(1250, locale),
            formatAmount(12345600, 'EUR', locale),
            words.text('over_time.chart_revenue', {
                chart: words.text('over_time.revenue.day'),
                currency: currencyName('EUR', locale),
                from: '19/10/2026',
                to: '19/10/2026',
                values: new Intl.ListFormat(locale, { style: 'narrow', type: 'unit' }).format([formatAmount(12345600, 'EUR', locale)]),
            }),
        ];

        inertia.props = propsWith({ figures: tiles, report });
        view.render(<GreekPage />);
        const el = figures();

        expect(el).toEqual(shown(greek.locale));

        view.render(<Page />);
        const en = figures();

        expect(en).toEqual(shown(words.locale));
        // The two languages part on every grouped figure, every amount, the rate and every signed
        // change.
        expect(el[0]).not.toBe(en[0]);
        expect(el[1]).not.toBe(en[1]);
        expect(el[2]).not.toBe(en[2]);
        expect(el[13]).not.toBe(en[13]);
    });
});

describe('the cards and tables', () => {
    it('render every figure in its order after Over time, each amount in its own currency, and the closing footnote', () => {
        inertia.props = propsWith();
        view.render(<Page />);

        expect(texts('.admin-reports h2')).toEqual([
            words.text('over_time.title'),
            words.text('tables.statuses'),
            words.text('tables.revenue'),
            words.text('tables.no_show_rate'),
            words.text('tables.cancelled_by'),
            words.text('occupancy.title'),
            words.text('tables.by_service'),
            words.text('tables.by_staff'),
            words.text('tables.by_source'),
            words.text('tables.by_channel'),
        ]);
        // The five statuses always shown first, in their order, then the other four, each
        // above nought here.
        expect(texts('.admin-reports-statuses .admin-chip')).toEqual((['confirmed', 'completed', 'awaiting_approval', 'cancelled', 'no_show', 'awaiting_payment', 'pending', 'rejected', 'expired'] as const).map((status) => shell.text(`kit.statuses.${status}`)));
        expect(texts('.admin-reports-statuses__count')).toEqual(['2', '3', '1', '1', '1', '1', '1', '1', '1']);
        // The status cards' titles take the house card title, no size of their own.
        expect([...document.querySelectorAll('#reports-statuses-head, #reports-revenue-head, #reports-cancelled-by-head, #reports-no-show-head')].map((head) => head.classList.contains('text-base'))).toEqual([false, false, false, false]);

        expect(texts('.admin-reports-revenue dt')).toEqual([words.text('tables.confirmed'), words.text('tables.completed')]);
        expect(texts('.admin-reports-revenue__confirmed .admin-reports__amount')).toEqual([formatAmount(3500, 'EUR', words.locale), formatAmount(6000, 'USD', words.locale)]);
        expect(texts('.admin-reports-revenue__completed .admin-reports__amount')).toEqual([formatAmount(13000, 'EUR', words.locale), formatAmount(6000, 'USD', words.locale)]);

        expect(q('.admin-reports-no-show__rate')?.textContent).toBe(formatRate(0.25, words.locale));
        expect(q('.admin-reports-no-show__empty')).toBeNull();
        expect(texts('.admin-reports-no-show dt')).toEqual([words.text('tables.no_shows'), words.text('tables.completed')]);
        expect(q('.admin-reports-no-show__no-shows')?.textContent).toBe('1');
        expect(q('.admin-reports-no-show__completed')?.textContent).toBe('3');

        expect(texts('.admin-reports-cancelled-by dt')).toEqual([words.text('tables.by_customer'), words.text('tables.by_business')]);
        expect(texts('.admin-reports-cancelled-by dd')).toEqual(['2', '0']);

        expect(texts('.admin-reports-services th')).toEqual([words.text('tables.service'), words.text('tables.bookings'), words.text('tables.revenue_head')]);
        expect(texts('.admin-reports-services .admin-reports__label')).toEqual(['Haircut', 'Beard trim', 'Colour']);
        expect(texts('.admin-reports-services .admin-reports__revenue')).toEqual([formatAmount(10500, 'EUR', words.locale), formatAmount(0, 'EUR', words.locale), `${formatAmount(6000, 'EUR', words.locale)}${formatAmount(12000, 'USD', words.locale)}`]);
        expect(texts('.admin-reports-staff th')).toEqual([words.text('tables.staff'), words.text('tables.bookings'), words.text('tables.revenue_head')]);
        expect(texts('.admin-reports-staff .admin-reports__bookings')).toEqual(['5', '8']);
        expect(texts('.admin-reports-sources th')).toEqual([words.text('tables.source'), words.text('tables.bookings'), words.text('tables.revenue_head')]);
        expect(texts('.admin-reports-sources .admin-reports__label')).toEqual([shell.text('shared.sources.hosted'), shell.text('shared.sources.embed'), shell.text('shared.sources.admin')]);
        expect(texts('.admin-reports-channels th')).toEqual([words.text('tables.channel'), words.text('tables.bookings'), words.text('tables.revenue_head')]);
        expect(texts('.admin-reports-channels .admin-reports__label')).toEqual([shell.text('shared.channels.instagram'), shell.text('shared.channels.qr'), words.text('tables.no_channel')]);

        expect(q('.admin-reports-note')?.textContent).toBe(words.text('tables.note'));
        expect(q('.admin-reports > :last-child')?.className).toContain('admin-reports-note');
        expect(q('.admin-reports')?.getAttribute('aria-busy')).toBe('false');
        expect(q('.admin-reports-error')).toBeNull();
    });

    it('draws the status cards as kit cards, their title inside, and Over time and the By tables with a header strip, every table in its scroll box', () => {
        inertia.props = propsWith();
        view.render(<Page />);

        for (const name of ['statuses', 'revenue', 'no-show', 'cancelled-by']) {
            const card = q(`.admin-reports-${name}`);

            expect(card?.className.split(' ')).toEqual(expect.arrayContaining(['rounded-xl', 'border', 'bg-card', 'p-4', 'gap-2']));
            expect(card?.querySelector(':scope > h2')).not.toBeNull();
        }

        for (const name of ['over-time', 'services', 'staff', 'sources', 'channels']) {
            const card = q(`.admin-reports-${name}`);

            expect(card?.className.split(' ')).toEqual(expect.arrayContaining(['rounded-xl', 'border', 'bg-card']));
            expect(card?.querySelector(':scope > header.admin-card-strip.border-b > h2')).not.toBeNull();
            expect(card?.querySelector('table')?.closest('.admin-table-scroll')).not.toBeNull();
        }
    });

    it('reads zero everywhere over an empty range in the same cards and tables, the five always-shown statuses alone, the rate\'s empty sentence in the card\'s own type', () => {
        inertia.props = propsWith({ report: EMPTY });
        view.render(<Page />);

        expect(texts('.admin-reports-statuses .admin-chip')).toEqual((['confirmed', 'completed', 'awaiting_approval', 'cancelled', 'no_show'] as const).map((status) => shell.text(`kit.statuses.${status}`)));
        expect(texts('.admin-reports-statuses__count')).toEqual(['0', '0', '0', '0', '0']);
        expect(texts('.admin-reports-revenue .admin-reports__amount')).toEqual([formatAmount(0, 'EUR', words.locale), formatAmount(0, 'EUR', words.locale)]);
        expect(q('.admin-reports-no-show__rate')).toBeNull();
        expect(q('.admin-reports-no-show__empty')?.textContent).toBe(words.text('tables.no_completed'));
        expect(q('.admin-reports-no-show__empty')?.className).not.toMatch(/text-2xl|font-semibold/);
        expect(texts('.admin-reports-cancelled-by dd')).toEqual(['0', '0']);
        expect(texts('.admin-reports-channels .admin-reports__revenue')).toEqual(Array(6).fill(formatAmount(0, 'EUR', words.locale)));
        expect(q('.admin-reports-over-time rect')).toBeNull();
        expect(texts('.admin-reports-over-time tbody tr')).toHaveLength(7);
        expect(q('.admin-list-empty, .admin-reports-empty')).toBeNull();
    });

    it('adds Not recorded under Cancelled by only where a cancelled booking has no trail', () => {
        inertia.props = propsWith({ report: { ...FIGURES, cancelled_by: { customer: 1, business: 2, unrecorded: 3 } } });
        view.render(<Page />);

        expect(texts('.admin-reports-cancelled-by dt')).toEqual([words.text('tables.by_customer'), words.text('tables.by_business'), words.text('tables.unrecorded')]);
        expect(texts('.admin-reports-cancelled-by dd')).toEqual(['1', '2', '3']);
    });

    it('spells no sentence of its own: every line on the screen is a key of the section, a name or a figure', () => {
        inertia.props = propsWith();
        view.render(<Page />);

        const english = [...Object.values(ADMIN_LINES_EN.reports.tables), ADMIN_LINES_EN.reports.over_time.title, ...Object.values(ADMIN_LINES_EN.reports.over_time.bookings), ...Object.values(ADMIN_LINES_EN.reports.over_time.revenue)];

        expect(english.filter((line) => document.body.textContent?.includes(line))).toEqual([]);
    });
});

describe('Over time', () => {
    it('draws bookings and revenue per day as bars in the theme\'s colour, a bar for each day above nought, and the table of From, To, Bookings and Revenue', () => {
        inertia.props = propsWith();
        view.render(<Page />);

        expect(texts('.admin-reports-over-time figcaption')).toEqual([words.text('over_time.bookings.day'), words.text('over_time.revenue.day')]);
        expect(document.querySelectorAll('.admin-reports-over-time__bookings rect')).toHaveLength(6);
        expect(document.querySelectorAll('.admin-reports-over-time__revenue rect')).toHaveLength(6);
        expect([...document.querySelectorAll('.admin-reports-over-time rect')].every((bar) => bar.getAttribute('class')?.includes('fill-primary'))).toBe(true);
        // Five a day at most: the axis steps by two to six; revenue by a hundred to three hundred.
        expect(texts('.admin-reports-over-time__bookings .admin-reports-axis')).toEqual(['0', '2', '4', '6']);
        expect(texts('.admin-reports-over-time__revenue .admin-reports-axis')).toEqual(['0', '100', '200', '300']);
        expect(texts('.admin-reports-over-time__bookings .admin-reports-tick')).toEqual(['19', '20', '21', '22', '23', '24', '25']);
        expect(q('.admin-reports-over-time__bookings svg')?.getAttribute('aria-label')).toBe(
            words.text('over_time.chart', { chart: words.text('over_time.bookings.day'), from: '19/10/2026', to: '25/10/2026', values: new Intl.ListFormat(words.locale, { style: 'narrow', type: 'unit' }).format(['0', '4', '5', '5', '4', '3', '2']) }),
        );

        expect(texts('.admin-reports-over-time th')).toEqual([words.text('over_time.from'), words.text('over_time.to'), words.text('over_time.bookings_head'), words.text('over_time.revenue_head')]);
        expect(texts('.admin-reports-over-time tbody tr:nth-child(2) td')).toEqual(['20/10/2026', '20/10/2026', '4', formatAmount(19000, 'EUR', words.locale)]);
        expect(texts('.admin-reports-over-time tbody tr')).toHaveLength(7);
    });

    it('heads the charts per week and per month as the buckets are, a week by its first day and a month by its name', () => {
        inertia.props = propsWith({
            report: {
                ...FIGURES,
                over_time: {
                    unit: 'week',
                    rows: [
                        { from: '2026-09-10', to: '2026-09-13', bookings: 1, revenue: { currency: 'EUR', minor: 3500 } },
                        { from: '2026-09-14', to: '2026-09-20', bookings: 0, revenue: { currency: 'EUR', minor: 0 } },
                    ],
                },
            },
        });
        view.render(<Page />);

        expect(texts('.admin-reports-over-time figcaption')).toEqual([words.text('over_time.bookings.week'), words.text('over_time.revenue.week')]);
        expect(texts('.admin-reports-over-time__bookings .admin-reports-tick')).toEqual(['10/09', '14/09']);
        expect(texts('.admin-reports-over-time tbody tr:first-child td')).toEqual(['10/09/2026', '13/09/2026', '1', formatAmount(3500, 'EUR', words.locale)]);

        inertia.props = propsWith({
            report: {
                ...FIGURES,
                over_time: {
                    unit: 'month',
                    rows: ['01', '02', '03'].map((month) => ({ from: `2026-${month}-01`, to: `2026-${month}-28`, bookings: 2, revenue: { currency: 'EUR', minor: 100 } })),
                },
            },
        });
        view.render(<Page />);

        expect(texts('.admin-reports-over-time figcaption')).toEqual([words.text('over_time.bookings.month'), words.text('over_time.revenue.month')]);
        expect(texts('.admin-reports-over-time__bookings .admin-reports-tick')).toEqual([monthName(1, 'en'), monthName(2, 'en'), monthName(3, 'en')]);
    });

    it('labels every bucket where there is room and every few where there is not', () => {
        const day = (index: number): string => `2026-10-${String(index + 1).padStart(2, '0')}`;

        inertia.props = propsWith({
            report: { ...FIGURES, over_time: { unit: 'day', rows: Array.from({ length: 31 }, (_, index) => ({ from: day(index), to: day(index), bookings: 1, revenue: { currency: 'EUR', minor: 100 } })) } },
        });
        view.render(<Page />);

        expect(texts('.admin-reports-over-time__bookings .admin-reports-tick')).toEqual(['1', '3', '5', '7', '9', '11', '13', '15', '17', '19', '21', '23', '25', '27', '29', '31']);
        expect(texts('.admin-reports-over-time tbody tr')).toHaveLength(31);
    });
});

describe('Download CSV', () => {
    let fetched: ReturnType<typeof vi.fn>;

    beforeEach(() => {
        fetched = vi.fn(() => new Promise<Response>(() => undefined));
        vi.stubGlobal('fetch', fetched);
    });

    afterEach(() => vi.unstubAllGlobals());

    it('fetches the CSV of the range and the filters on screen, busy while it is on its way', () => {
        inertia.props = propsWith({ range: { word: 'custom', from: '2026-10-05', to: '2026-10-14' }, filters: { staff: 4, service: null } });
        view.render(<Page />);

        expect(fetched).not.toHaveBeenCalled();

        press(q('.admin-reports-download'));

        expect(fetched.mock.lastCall?.[0]).toBe('/admin/reports/csv?range=custom&from=2026-10-05&to=2026-10-14&staff=4');
        expect(q('.admin-reports-download')?.getAttribute('aria-busy')).toBe('true');
    });
});

describe('Occupancy by staff', () => {
    it('sits between the status row and the By tables as a card with a header strip over Staff, Booked, Available and Occupancy, a row a member', () => {
        inertia.props = propsWith();
        view.render(<Page />);

        const card = q('.admin-reports-occupancy');

        expect(card?.previousElementSibling?.querySelector('.admin-reports-statuses')).not.toBeNull();
        expect(card?.nextElementSibling?.querySelector('.admin-reports-services')).not.toBeNull();
        expect(card?.querySelector(':scope > header.admin-card-strip > h2')?.textContent).toBe(words.text('occupancy.title'));
        expect(card?.querySelector('table')?.closest('.admin-table-scroll')).not.toBeNull();
        expect(texts('.admin-reports-occupancy th')).toEqual([words.text('tables.staff'), words.text('occupancy.booked'), words.text('occupancy.available'), words.text('tiles.occupancy')]);
        expect(texts('.admin-reports-occupancy tbody tr:nth-child(1) td')).toEqual(['Elena Papadaki', hoursOf(1710, words), hoursOf(2400, words), '71.3%']);
        expect(texts('.admin-reports-occupancy tbody tr:nth-child(2) td')).toEqual(['Nikos Georgiou', hoursOf(1265, words), hoursOf(2700, words), '46.9%']);
        expect(texts('.admin-reports-occupancy tbody tr:nth-child(3) td')).toEqual(['Maria Ioannou', hoursOf(0, words), hoursOf(0, words), words.text('occupancy.no_hours')]);
    });

    it('names the currency in the revenue chart\'s spoken label', () => {
        inertia.props = propsWith();
        view.render(<Page />);

        expect(q('.admin-reports-over-time__revenue svg')?.getAttribute('aria-label')).toBe(
            words.text('over_time.chart_revenue', {
                chart: words.text('over_time.revenue.day'),
                currency: currencyName('EUR', words.locale),
                from: '19/10/2026',
                to: '25/10/2026',
                values: new Intl.ListFormat(words.locale, { style: 'narrow', type: 'unit' }).format([0, 19000, 25500, 23500, 21000, 16000, 9500].map((minor) => formatAmount(minor, 'EUR', words.locale))),
            }),
        );
    });
});

