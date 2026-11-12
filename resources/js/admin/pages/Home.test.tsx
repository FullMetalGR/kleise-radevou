import { act, type ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { Booking, Detail } from '@/bookings/model';
import { mount, type Mounted, press } from '@/components/ui/harness';
import { type ActivitySentence, APPROVAL_INBOX, type HomeActivityRow, type HomeBooking } from '@/home/feeds';
import type { RangeFigures } from '@/home/figures';
import { formatAmount, formatCount, formatPercent } from '@/lib/format';
import { listOf, unitFigure } from '@/lib/names';
import { ShellProvider } from '@/lib/strings';
import { KEYED_BOOKINGS, KEYED_HOME, keyedHome, keyedShell } from '@/testing/keyed';
import { ToastProvider } from '@/toast/toasts';
import Home, { dayLink, type HomeDay } from './Home';

/*
 * Home's wiring: the title, the setup checklist with its book action opening the wizard with no
 * prefill, the range card choosing today, yesterday, this week or this month by a visit that reads
 * the range's figures alone, the six tiles each compared with the period before in one sentence per
 * kind of range, the footnote, the two Top tables, and the seven days as bars each linking to the
 * Calendar's Day on its date, the confirmed part in the theme colour and the awaiting share stacked
 * in amber, summed in the header strip. Rendered with the keyed home section, so every expectation
 * is read through the same table and no sentence is spelled here.
 *
 * The three feeds: Next up's and Awaiting approval's rows, Recent activity's whole sentences with
 * their names filled, the empty states, and a row opening the booking dialog on Home by a partial
 * reload of the detail alone, an approval posting as every screen's does and the redirect's page
 * followed by one more read of the detail for the booking still open. The framework is stood in for
 * by a router that records each visit.
 */

const home = keyedHome();

const inertia = vi.hoisted(() => ({ props: {} as Record<string, unknown>, get: vi.fn(), post: vi.fn(), listeners: new Map<string, (event: Event) => void>() }));

type Visit = { only?: string[]; preserveUrl?: boolean; preserveState?: boolean; replace?: boolean; onStart?: () => void; onFinish?: () => void };

const wizard = vi.hoisted(() => ({ open: vi.fn() }));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, className, children, ...rest }: { href: string; className?: string; children: ReactNode; 'aria-label'?: string }) => (
        <a href={href} className={className} aria-label={rest['aria-label']}>
            {children}
        </a>
    ),
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

vi.mock('@/wizard/NewBooking', () => ({ useNewBooking: () => wizard }));

const HOSTED = 'http://luna.localhost/';

const READY = { service: true, hours: true, link: true, booking: true, hosted_url: HOSTED };

type Fact = 'service' | 'hours' | 'link' | 'booking';

/** The demo's week: a rise, a fall, no change and a rise from nought. */
const FIGURES: RangeFigures = {
    range: { word: 'week', from: '2026-11-02', to: '2026-11-08' },
    before: { from: '2026-10-26', to: '2026-11-01' },
    tiles: {
        bookings: { value: 23, previous: 20, change: { kind: 'up', percent: 15 } },
        revenue: { value: { currency: 'EUR', minor: 114500 }, previous: { currency: 'EUR', minor: 105000 }, change: { kind: 'up', percent: 9 }, elsewhere: [] },
        occupancy: { value: 58.3, previous: 56.1, change: { kind: 'up', percent: 4 }, filled_minutes: 2975, offered_minutes: 5100 },
        new_customers: { value: 6, previous: 6, change: { kind: 'same', percent: 0 }, returning: 17 },
        cancelled: { value: 2, previous: 3, change: { kind: 'down', percent: -33 } },
        no_shows: { value: 1, previous: 0, change: { kind: 'from_zero', percent: null } },
    },
    top_services: [
        { id: 1, name: 'Haircut', bookings: 14, revenue: { currency: 'EUR', minor: 49000 } },
        { id: 2, name: 'Colour', bookings: 5, revenue: { currency: 'EUR', minor: 40000 } },
    ],
    top_staff: [
        { id: 3, name: 'Elena Papadaki', business: false, bookings: 13, revenue: { currency: 'EUR', minor: 73500 } },
        { id: 1, name: 'Luna Hair Studio', business: true, bookings: 10, revenue: { currency: 'EUR', minor: 41000 } },
    ],
};

/** The fixture's figures for another range word and dates, the tiles patched. */
function rangeFigures(word: RangeFigures['range']['word'], from: string, to: string, tiles: Partial<RangeFigures['tiles']> = {}): RangeFigures {
    return { ...FIGURES, range: { word, from, to }, tiles: { ...FIGURES.tiles, ...tiles } };
}

const DAYS: HomeDay[] = ['2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05', '2026-11-06', '2026-11-07', '2026-11-08'].map((date, index) => ({
    date,
    confirmed: [2, 0, 1, 0, 3, 0, 0][index] ?? 0,
    awaiting: [0, 0, 0, 0, 1, 1, 0][index] ?? 0,
}));

function propsWith(overrides: Record<string, unknown> = {}): Record<string, unknown> {
    return {
        tenant: { name: 'Luna Hair Studio', slug: 'luna', timezone: 'Europe/Athens', currency: 'EUR' },
        auth: { user: { id: 1, name: 'Alex Owner', role: 'owner' } },
        flash: null,
        errors: {},
        language: 'en',
        strings: { home: KEYED_HOME, bookings: KEYED_BOOKINGS },
        permissions: ['viewBookings', 'manageBookings', 'manageSettings'],
        entitlements: ['staff'],
        subscription: { state: 'active' },
        settings: { granularity: 15, week_start: 1, date_format: 'd/m/Y', time_format: 'H:i', colour_by: 'staff', messages: [] },
        nav: [],
        catalog: { services: [], staff: [], members: 3 },
        range_figures: FIGURES,
        days: DAYS,
        next_up: [],
        awaiting: [],
        activity: [],
        members: 3,
        detail: null,
        ...overrides,
    };
}

/** Each item of the setup card by its fact, as done or open, with the address its link leads to. */
function setupItems(): Record<string, { done: string | undefined; href: string | null }> {
    return Object.fromEntries(
        [...view.host.querySelectorAll<HTMLElement>('.admin-setup__item')].map((item) => [item.dataset['fact'] ?? '', { done: item.dataset['done'], href: item.querySelector('a')?.getAttribute('href') ?? null }]),
    );
}

/** A tile's lines in order: its value, then its comparison, then its notes. */
function tile(id: string): string[] {
    return [...view.host.querySelectorAll(`.admin-figure-tile--${id} .admin-figure-tile__value, .admin-figure-tile--${id} .admin-figure-tile__change, .admin-figure-tile--${id} .admin-figure-tile__line`)].map((el) => el.textContent ?? '');
}

/** A signed change as the comparison sentence fills it. */
function signed(percent: number): string {
    return formatPercent(percent, home.locale, true);
}

/** A Top table's rows as name, bookings and revenue. */
function topRows(id: string): string[][] {
    return [...view.host.querySelectorAll(`.admin-home-top--${id} tbody tr`)].map((row) => [...row.querySelectorAll('td')].map((cell) => cell.textContent ?? ''));
}

let view: Mounted;

beforeEach(() => {
    wizard.open.mockReset();
    inertia.listeners.clear();
    inertia.get.mockReset();
    inertia.post.mockReset();
    inertia.props = propsWith();
    view = mount();
});

afterEach(() => {
    view.unmount();
});

describe('Home', () => {
    it('is titled Home, then the card, the range, the tiles, the footnote, the feeds, the Top tables, the activity and the days in that order', () => {
        inertia.props = propsWith({ setup: { ...READY, booking: false } });
        view.render(<Home />);

        expect(view.host.querySelector('h1')?.textContent).toBe(home.text('title'));

        const order = ['.admin-setup', '.admin-home-range', '.admin-home-figures', '.admin-home-footnote', '.admin-home-next', '.admin-home-awaiting', '.admin-home-top--services', '.admin-home-top--staff', '.admin-home-activity', '.admin-home-days'];
        const parts = order.map((selector) => view.host.querySelector(selector));
        expect(parts.map((part, index) => (part === null ? order[index] : null)).filter((missing) => missing !== null)).toEqual([]);
        parts.slice(1).forEach((part, index) => expect(parts[index]?.compareDocumentPosition(part as Node)).toBe(Node.DOCUMENT_POSITION_FOLLOWING));

        // Side by side in pairs: each pair in one grid of its own.
        expect(parts[4]?.parentElement).toBe(parts[5]?.parentElement);
        expect(parts[6]?.parentElement).toBe(parts[7]?.parentElement);
        expect(parts[8]?.parentElement).toBe(parts[9]?.parentElement);
        expect(view.host.querySelector('.admin-home-footnote')?.textContent).toBe(home.text('footnote'));
    });

    it('offers the four ranges in one group, the server\'s range pressed, its line beneath with its two dates or its one day', () => {
        view.render(<Home />);

        const group = view.host.querySelector('.admin-home-range [role="group"]');
        expect(group?.getAttribute('aria-label')).toBe(home.text('range.label'));
        const options = [...view.host.querySelectorAll<HTMLButtonElement>('.admin-home-range button')];
        expect(options.map((option) => option.textContent)).toEqual([home.text('range.today'), home.text('range.yesterday'), home.text('range.week'), home.text('range.month')]);
        expect(options.map((option) => option.getAttribute('aria-pressed'))).toEqual(['false', 'false', 'true', 'false']);
        expect(view.host.querySelector('.admin-home-range__line')?.textContent).toBe(home.text('range.line.week', { from: '02/11/2026', to: '08/11/2026' }));

        inertia.props = propsWith({ range_figures: rangeFigures('today', '2026-11-02', '2026-11-02') });
        view.render(<Home />);
        expect([...view.host.querySelectorAll('.admin-home-range button')].map((option) => option.getAttribute('aria-pressed'))).toEqual(['true', 'false', 'false', 'false']);
        expect(view.host.querySelector('.admin-home-range__line')?.textContent).toBe(home.text('range.line.today', { date: '02/11/2026' }));

        inertia.props = propsWith({ range_figures: rangeFigures('yesterday', '2026-11-01', '2026-11-01') });
        view.render(<Home />);
        expect(view.host.querySelector('.admin-home-range__line')?.textContent).toBe(home.text('range.line.yesterday', { date: '01/11/2026' }));

        inertia.props = propsWith({ range_figures: rangeFigures('month', '2026-11-01', '2026-11-30') });
        view.render(<Home />);
        expect(view.host.querySelector('.admin-home-range__line')?.textContent).toBe(home.text('range.line.month', { from: '01/11/2026', to: '30/11/2026' }));
    });

    it('chooses a range by a visit to Home that names it in the address, this week by none, keeping the scroll and reading the range\'s figures alone', () => {
        view.render(<Home />);

        press(view.host.querySelectorAll('.admin-home-range button')[3] ?? null);
        expect(inertia.get).toHaveBeenCalledTimes(1);
        const [url, query, visit] = lastGet();
        expect(url).toBe('/admin');
        expect(query).toEqual({ range: 'month' });
        expect(visit).toMatchObject({ only: ['range_figures'], preserveScroll: true, preserveState: true });

        press(view.host.querySelectorAll('.admin-home-range button')[0] ?? null);
        expect(lastGet()[1]).toEqual({ range: 'today' });

        inertia.props = propsWith({ range_figures: rangeFigures('month', '2026-11-01', '2026-11-30') });
        view.render(<Home />);
        press(view.host.querySelectorAll('.admin-home-range button')[2] ?? null);
        expect(lastGet()[0]).toBe('/admin');
        expect(lastGet()[1]).toEqual({});

        // The range already shown asks for nothing.
        press(view.host.querySelectorAll('.admin-home-range button')[3] ?? null);
        expect(inertia.get).toHaveBeenCalledTimes(3);
    });

    it('marks the range card busy while a range reads and owns a failed read, saying so on Home in its own sentence', () => {
        view.render(<Home />);
        const card = () => view.host.querySelector('.admin-home-range');

        press(view.host.querySelectorAll('.admin-home-range button')[3] ?? null);
        const visit = lastGet()[2];
        act(() => visit.onStart?.());
        expect(card()?.getAttribute('aria-busy')).toBe('true');

        const failed = new Event('exception', { cancelable: true });
        act(() => inertia.listeners.get('exception')?.(failed));
        act(() => visit.onFinish?.());
        expect(failed.defaultPrevented).toBe(true);
        expect(card()?.getAttribute('aria-busy')).toBe('false');
        expect(view.host.querySelector('.admin-home-range__error')?.textContent).toBe(home.text('range.error'));
        expect(inertia.listeners.size).toBe(0);
    });

    it('draws the six tiles, each value with its comparison to the week before: a rise, a fall, no change and a rise from nought, and the two notes', () => {
        view.render(<Home />);

        expect([...view.host.querySelectorAll('.admin-figure-tile h2')].map((el) => el.textContent)).toEqual([
            home.text('tiles.bookings'),
            home.text('tiles.revenue'),
            home.text('tiles.occupancy'),
            home.text('tiles.new_customers'),
            home.text('tiles.cancelled'),
            home.text('tiles.no_shows'),
        ]);
        expect(tile('bookings')).toEqual(['23', home.text('change.week.by', { change: signed(15) })]);
        expect(tile('revenue')).toEqual([formatAmount(114500, 'EUR', home.locale), home.text('change.week.by', { change: signed(9) })]);
        expect(tile('occupancy')).toEqual([
            formatPercent(58.3, home.locale),
            home.text('change.week.by', { change: signed(4) }),
            home.text('tiles.filled', { filled: home.text('tiles.duration', { hours: unitFigure(49, 'hour', 'en'), minutes: unitFigure(35, 'minute', 'en') }), offered: unitFigure(85, 'hour', 'en') }),
        ]);
        expect(tile('new_customers')).toEqual(['6', home.text('change.week.same'), home.choice('tiles.returning', 17, { count: formatCount(17, home.locale) })]);
        expect(tile('cancelled')).toEqual(['2', home.text('change.week.by', { change: signed(-33) })]);
        expect(tile('no_shows')).toEqual(['1', home.text('change.week.from_zero')]);
        expect(signed(-33)).toBe('-33%');
    });

    it('compares a day with the day before and a month with the month before, a fall the server rounded to nought still a fall', () => {
        const fallen = { cancelled: { value: 199, previous: 200, change: { kind: 'down' as const, percent: 0 } } };

        for (const [word, kind] of [['today', 'day'], ['yesterday', 'day'], ['month', 'month']] as const) {
            inertia.props = propsWith({ range_figures: rangeFigures(word, '2026-11-01', '2026-11-01', fallen) });
            view.render(<Home />);

            expect(tile('bookings')[1]).toBe(home.text(`change.${kind}.by`, { change: signed(15) }));
            expect(tile('new_customers')[1]).toBe(home.text(`change.${kind}.same`));
            expect(tile('no_shows')[1]).toBe(home.text(`change.${kind}.from_zero`));
            expect(tile('cancelled')[1]).toBe(home.text(`change.${kind}.by`, { change: signed(-0) }));
        }
        expect(signed(-0)).toBe('-0%');
    });

    it('reads nought against nought as no change, and occupancy with no hours as a phrase with no comparison, one with a period missing with none, one past a hundred as it is', () => {
        const none = { kind: 'none' as const, percent: null };
        inertia.props = propsWith({
            range_figures: rangeFigures('week', '2026-11-02', '2026-11-08', {
                bookings: { value: 0, previous: 0, change: none },
                revenue: { value: { currency: 'EUR', minor: 0 }, previous: { currency: 'EUR', minor: 0 }, change: none, elsewhere: [{ currency: 'USD', minor: 1000 }] },
                occupancy: { value: null, previous: 40, change: none, filled_minutes: 45, offered_minutes: 0 },
            }),
        });
        view.render(<Home />);

        expect(tile('bookings')).toEqual(['0', home.text('change.week.same')]);
        expect(tile('revenue')).toEqual([formatAmount(0, 'EUR', home.locale), home.text('change.week.same'), formatAmount(1000, 'USD', home.locale)]);
        expect(tile('occupancy')).toEqual([home.text('tiles.no_hours')]);

        inertia.props = propsWith({ range_figures: rangeFigures('week', '2026-11-02', '2026-11-08', { occupancy: { value: 0, previous: 0, change: none, filled_minutes: 0, offered_minutes: 600 } }) });
        view.render(<Home />);
        expect(tile('occupancy')).toEqual([formatPercent(0, home.locale), home.text('change.week.same'), home.text('tiles.filled', { filled: unitFigure(0, 'hour', 'en'), offered: unitFigure(10, 'hour', 'en') })]);

        inertia.props = propsWith({ range_figures: rangeFigures('today', '2026-11-02', '2026-11-02', { occupancy: { value: 112.5, previous: null, change: none, filled_minutes: 135, offered_minutes: 120 } }) });
        view.render(<Home />);
        expect(tile('occupancy')).toEqual([
            formatPercent(112.5, home.locale),
            home.text('tiles.filled', { filled: home.text('tiles.duration', { hours: unitFigure(2, 'hour', 'en'), minutes: unitFigure(15, 'minute', 'en') }), offered: unitFigure(2, 'hour', 'en') }),
        ]);
    });

    it('lists Top services and Top staff by name, bookings and revenue, the business\'s own row by the name it is given, and says so in place of a table where nothing was counted', () => {
        view.render(<Home />);

        expect(view.host.querySelector('#home-top-services-title')?.textContent).toBe(home.text('top.services'));
        expect(view.host.querySelector('#home-top-staff-title')?.textContent).toBe(home.text('top.staff'));
        expect([...view.host.querySelectorAll('.admin-home-top--services th')].map((el) => el.textContent)).toEqual([home.text('top.name'), home.text('top.bookings'), home.text('top.revenue')]);
        expect(topRows('services')).toEqual([
            ['Haircut', '14', formatAmount(49000, 'EUR', home.locale)],
            ['Colour', '5', formatAmount(40000, 'EUR', home.locale)],
        ]);
        expect(topRows('staff')).toEqual([
            ['Elena Papadaki', '13', formatAmount(73500, 'EUR', home.locale)],
            ['Luna Hair Studio', '10', formatAmount(41000, 'EUR', home.locale)],
        ]);
        expect(view.host.querySelectorAll('.admin-home-top .admin-table-scroll')).toHaveLength(2);

        inertia.props = propsWith({ range_figures: { ...FIGURES, top_services: [], top_staff: [] } });
        view.render(<Home />);
        expect(view.host.querySelectorAll('.admin-home-top table')).toHaveLength(0);
        expect(texts('.admin-home-top__empty')).toEqual([home.text('top.empty'), home.text('top.empty')]);
    });

    it('carries no link to Reports and none of the old week\'s tiles', () => {
        view.render(<Home />);

        expect(view.host.querySelector('.admin-home-reports')).toBeNull();
        expect(view.host.querySelectorAll('.admin-figure-tile')).toHaveLength(6);
    });

    it('draws a bar per day, each linking to the Calendar Day on its date, named by the short weekday and the day of the month with its count above', () => {
        view.render(<Home />);

        const days = [...view.host.querySelectorAll<HTMLElement>('.admin-home-day')];
        expect(days.map((day) => [day.dataset['date'], day.dataset['confirmed'], day.dataset['awaiting']])).toEqual(DAYS.map((day) => [day.date, String(day.confirmed), String(day.awaiting)]));
        expect(days.map((day) => day.querySelector('a')?.getAttribute('href'))).toEqual(DAYS.map((day) => `/admin/calendar?view=day&date=${day.date}`));
        expect(dayLink('2026-11-06')).toBe('/admin/calendar?view=day&date=2026-11-06');
        expect(texts('.admin-home-day__count')).toEqual(['2', '0', '1', '0', '4', '1', '0']);

        const monday = home.text('days.day', { weekday: weekday(2026, 11, 2), day: '2' });
        expect(days[0]?.querySelector('.admin-home-day__name')?.textContent).toBe(monday);
        expect(days[0]?.querySelector('a')?.getAttribute('aria-label')).toBe(home.choice('days.link', 2, { day: monday, count: formatCount(2, home.locale) }));
        expect(days[1]?.querySelector('a')?.getAttribute('aria-label')).toContain('[days.link');
    });

    it('stacks the awaiting share in amber on the confirmed part in the theme colour, the tallest day the full bar, an empty day a line at nought', () => {
        view.render(<Home />);

        const days = [...view.host.querySelectorAll<HTMLElement>('.admin-home-day')];
        const part = (index: number, which: 'confirmed' | 'awaiting') => days[index]?.querySelector<HTMLElement>(`.admin-home-day__${which}`) ?? null;

        // The tallest day, four, fills the bar: three confirmed over one awaiting.
        expect(days[4]?.querySelector<HTMLElement>('.admin-home-day__bar')?.style.height).toBe('100%');
        expect(part(4, 'confirmed')?.style.flexGrow).toBe('3');
        expect(part(4, 'awaiting')?.style.flexGrow).toBe('1');
        expect(part(4, 'confirmed')?.className).toContain('bg-primary');
        expect(part(4, 'awaiting')?.className).toContain('bg-amber-400');
        expect(days[0]?.querySelector<HTMLElement>('.admin-home-day__bar')?.style.height).toBe('50%');
        expect(part(0, 'awaiting')).toBeNull();
        expect(part(5, 'confirmed')).toBeNull();
        expect(days[1]?.querySelector('.admin-home-day__bar')).toBeNull();
        expect(days[1]?.querySelector('.admin-home-day__none')).not.toBeNull();
    });

    it('sums the week in its header strip, the awaiting share named where there is one, and says what the colours mean', () => {
        view.render(<Home />);

        // One whole sentence per case the strip shows: several awaiting, one plain sentence read as
        // text, the week then holding two bookings or more.
        expect(view.host.querySelector('#home-days-title')?.closest('header')?.querySelector('.admin-home-days__summary')?.textContent).toBe(
            home.text('days.summary.many_awaiting', { count: formatCount(8, home.locale), awaiting: formatCount(2, home.locale) }),
        );
        expect(texts('.admin-home-days__footnote')).toEqual([home.text('days.footnote')]);

        // One awaiting, among several bookings and as the week's one booking.
        inertia.props = propsWith({ days: DAYS.map((day, index) => ({ ...day, awaiting: index === 4 ? 1 : 0 })) });
        view.render(<Home />);
        expect(texts('.admin-home-days__summary')).toEqual([home.choice('days.summary.one_awaiting', 7, { count: formatCount(7, home.locale), awaiting: formatCount(1, home.locale) })]);

        inertia.props = propsWith({ days: DAYS.map((day, index) => ({ ...day, confirmed: 0, awaiting: index === 4 ? 1 : 0 })) });
        view.render(<Home />);
        expect(texts('.admin-home-days__summary')).toEqual([home.choice('days.summary.one_awaiting', 1, { count: formatCount(1, home.locale), awaiting: formatCount(1, home.locale) })]);

        inertia.props = propsWith({ days: DAYS.map((day) => ({ ...day, awaiting: 0 })) });
        view.render(<Home />);
        expect(texts('.admin-home-days__summary')).toEqual([home.choice('days.bookings', 6, { count: formatCount(6, home.locale) })]);

        inertia.props = propsWith({ days: DAYS.map((day) => ({ ...day, confirmed: 0, awaiting: 0 })) });
        view.render(<Home />);
        expect(texts('.admin-home-days__summary')).toEqual([home.choice('days.bookings', 0, { count: formatCount(0, home.locale) })]);
        expect(view.host.querySelectorAll('.admin-home-day__bar')).toHaveLength(0);
        expect(view.host.querySelectorAll('.admin-home-day__none')).toHaveLength(7);
    });

    it('spells every count, amount and percentage by Intl in the user\'s language, el and en each its own forms', () => {
        const greek = keyedHome('el');
        const big = rangeFigures('week', '2026-11-02', '2026-11-08', {
            bookings: { value: 1250, previous: 100, change: { kind: 'up', percent: 1150 } },
            revenue: { value: { currency: 'EUR', minor: 12345600 }, previous: { currency: 'EUR', minor: 105000 }, change: { kind: 'up', percent: 9 }, elsewhere: [{ currency: 'USD', minor: 250000 }] },
            new_customers: { value: 2400, previous: 2400, change: { kind: 'same', percent: 0 }, returning: 1700 },
        });
        const figures = { ...big, top_services: [{ id: 1, name: 'Haircut', bookings: 1400, revenue: { currency: 'EUR', minor: 4900000 } }], top_staff: [] };
        const days = DAYS.map((day, index) => ({ ...day, confirmed: index === 0 ? 1200 : 0, awaiting: index === 0 ? 1100 : 0 }));
        const shown = (locale: string): string[] => [
            formatCount(1250, locale),
            home.text('change.week.by', { change: formatPercent(1150, locale, true) }),
            formatAmount(12345600, 'EUR', locale),
            formatAmount(250000, 'USD', locale),
            formatPercent(58.3, locale),
            formatCount(2400, locale),
            home.choice('tiles.returning', 1700, { count: formatCount(1700, locale) }),
            formatCount(1400, locale),
            formatAmount(4900000, 'EUR', locale),
            formatCount(2300, locale),
            home.text('days.summary.many_awaiting', { count: formatCount(2300, locale), awaiting: formatCount(1100, locale) }),
        ];
        const read = (): string[] => [
            ...tile('bookings'),
            ...tile('revenue').filter((_, index) => index !== 1),
            tile('occupancy')[0] ?? '',
            ...tile('new_customers').filter((_, index) => index !== 1),
            ...(topRows('services')[0] ?? []).slice(1),
            texts('.admin-home-day__count')[0] ?? '',
            texts('.admin-home-days__summary')[0] ?? '',
        ];

        inertia.props = propsWith({ range_figures: figures, days });
        view.render(
            <ShellProvider strings={null} language="el">
                <Home />
            </ShellProvider>,
        );
        const el = read();
        expect(el).toEqual(shown(greek.locale));

        view.render(<Home />);
        const en = read();
        expect(en).toEqual(shown(home.locale));
        expect(el[0]).not.toBe(en[0]);
        expect(el[1]).not.toBe(en[1]);
        expect(el[2]).not.toBe(en[2]);
        expect(el[4]).not.toBe(en[4]);
    });

    it('shows the setup card while any fact is false, a tick or an open circle per fact, each fact open alone in turn', () => {
        for (const fact of ['service', 'hours', 'link', 'booking'] satisfies Fact[]) {
            inertia.props = propsWith({ setup: { ...READY, [fact]: false } });
            view.render(<Home />);

            expect(view.host.querySelector('#setup-title')?.textContent).toBe(home.text('setup.title'));
            expect(Object.fromEntries(Object.entries(setupItems()).map(([key, item]) => [key, item.done]))).toEqual({ service: 'true', hours: 'true', link: 'true', booking: 'true', [fact]: 'false' });
            expect(view.host.querySelectorAll('.admin-setup__mark--done')).toHaveLength(3);
            expect(view.host.querySelectorAll('.admin-setup__mark--open')).toHaveLength(1);
            expect(view.host.querySelector('.admin-setup__mark--open')?.textContent).toBe(home.text('setup.open'));
        }
    });

    it('links each fact to where it is done: New service, the Shifts screen while the staff feature holds the hours, the Booking page section, and the wizard opened on press with no prefill beside the hosted page', () => {
        inertia.props = propsWith({ setup: { service: false, hours: false, link: false, booking: false, hosted_url: HOSTED } });
        view.render(<Home />);

        expect(Object.fromEntries(Object.entries(setupItems()).map(([key, item]) => [key, item.href]))).toEqual({
            service: '/admin/services/new',
            hours: '/admin/shifts',
            link: '/admin/settings#section-booking-page',
            booking: HOSTED,
        });
        expect([...view.host.querySelectorAll('.admin-setup__link')].map((el) => el.textContent)).toEqual([home.text('setup.service'), home.text('setup.hours'), home.text('setup.link')]);
        expect(view.host.querySelector('.admin-setup__item[data-fact="booking"] a')?.getAttribute('target')).toBe('_blank');
        expect(view.host.querySelector('.admin-setup__hosted')?.textContent).toBe(home.text('setup.hosted'));

        press(view.host.querySelector('.admin-setup__book'));
        expect(wizard.open).toHaveBeenCalledTimes(1);
        expect(wizard.open).toHaveBeenLastCalledWith();
    });

    it('links the hours to Settings without the staff feature, and offers the hosted page alone to a viewer without the wizard', () => {
        inertia.props = propsWith({ entitlements: [], catalog: null, setup: { ...READY, hours: false, booking: false } });
        view.render(<Home />);

        expect(setupItems()['hours']?.href).toBe('/admin/settings#section-opening-hours');
        expect(setupItems()['booking']?.href).toBe(HOSTED);
        expect(view.host.querySelector('.admin-setup__book')).toBeNull();
        expect(view.host.querySelector('.admin-setup__hosted')?.textContent).toBe(home.text('setup.booking'));
    });

    it('renders no setup card once all four facts are true, and none when the props carry no setup, the tiles and the days still drawn', () => {
        inertia.props = propsWith({ setup: READY });
        view.render(<Home />);
        expect(view.host.querySelector('.admin-setup')).toBeNull();
        expect(view.host.querySelector('.admin-home-figures')).not.toBeNull();

        inertia.props = propsWith();
        view.render(<Home />);
        expect(view.host.querySelector('.admin-setup')).toBeNull();
        expect(view.host.querySelectorAll('.admin-home-day')).toHaveLength(7);
    });
});

/** A feed row: a Haircut on Elena for the customer named, at the start given. */
function feedRow(uuid: string, patch: Partial<HomeBooking> = {}): HomeBooking {
    return { uuid, status: 'confirmed', customer: 'Maria Rossi', services: ['Haircut'], staff: ['Elena Papadaki'], starts_at: '2026-11-02T08:00:00+00:00', hold_expires_at: null, ...patch };
}

function activityRow(sentence: ActivitySentence, patch: Partial<HomeActivityRow> = {}): HomeActivityRow {
    return { sentence, customer: 'Maria Rossi', staff: null, at: '2026-11-01T09:00:00+00:00', booking: 'b1', starts_at: '2026-11-04T08:00:00+00:00', status: 'confirmed', services: ['Haircut'], members: ['Elena Papadaki'], ...patch };
}

function texts(selector: string): string[] {
    return [...view.host.querySelectorAll(selector)].map((el) => el.textContent ?? '');
}

function q(selector: string): Element | null {
    return document.body.querySelector(selector);
}

function lastGet(): [string, Record<string, string>, Visit] {
    return (inertia.get.mock.lastCall ?? []) as [string, Record<string, string>, Visit];
}

/** The short weekday ICU names a date by in English. */
function weekday(year: number, month: number, day: number): string {
    return new Intl.DateTimeFormat(home.locale, { weekday: 'short', timeZone: 'UTC' }).format(new Date(Date.UTC(year, month - 1, day)));
}

function booking(uuid: string, status: string): Booking {
    return {
        uuid,
        status,
        customer_name: 'Maria Rossi',
        customer_email: 'maria@example.com',
        total_minor: 3500,
        currency: 'EUR',
        items: [{ sort: 0, service_id: 1, service_name: 'Haircut', staff_id: 2, staff_name: 'Elena Papadaki', start: '2026-11-05T08:00:00+00:00', end: '2026-11-05T08:45:00+00:00', price_minor: 3500 }],
    };
}

function found(row: Booking, actions: string[]): Detail {
    return { state: 'found', booking: row, note: null, trail: [], actions };
}

function renderHome(): void {
    view.render(
        <ToastProvider>
            <Home />
        </ToastProvider>,
    );
}

describe('Home\'s feeds', () => {
    it('lists Next up in the server\'s order under its header strip: the customer, the date and time in mono at the right, the services with the member beneath, a provisional status chipped', () => {
        inertia.props = propsWith({
            next_up: [
                feedRow('b1'),
                feedRow('b2', { status: 'awaiting_payment', customer: null, services: ['Haircut', 'Colour'], starts_at: '2026-11-04T16:30:00+00:00' }),
                feedRow('b3', { services: ['Colour'], staff: [] }),
            ],
        });
        renderHome();

        expect(view.host.querySelector('#home-next-title')?.textContent).toBe(home.text('next_up.title'));
        expect(view.host.querySelector('#home-next-title')?.parentElement?.tagName).toBe('HEADER');
        expect(texts('.admin-home-next__customer')).toEqual(['Maria Rossi', home.text('customer_erased'), 'Maria Rossi']);
        expect(texts('.admin-home-next__when')).toEqual(['02/11/2026 10:00', '04/11/2026 18:30', '02/11/2026 10:00']);
        expect(view.host.querySelector('.admin-home-next__when')?.className).toContain('font-mono');
        expect(view.host.querySelector('.admin-home-next__customer')?.compareDocumentPosition(view.host.querySelector('.admin-home-next__when') as Node)).toBe(Node.DOCUMENT_POSITION_FOLLOWING);
        expect(texts('.admin-home-next__what')).toEqual([
            home.text('with', { services: 'Haircut', staff: 'Elena Papadaki' }),
            home.text('with', { services: listOf(['Haircut', 'Colour'], 'en'), staff: 'Elena Papadaki' }),
            'Colour',
        ]);
        expect(view.host.querySelectorAll('.admin-home-next__row .admin-chip')).toHaveLength(1);
    });

    it('names no member where the staff choice is not real, and says so when nothing is coming up', () => {
        inertia.props = propsWith({ entitlements: [], members: 1, next_up: [feedRow('b1')] });
        renderHome();
        expect(texts('.admin-home-next__what')).toEqual(['Haircut']);

        inertia.props = propsWith();
        renderHome();
        expect(texts('.admin-home-next__empty')).toEqual([home.text('next_up.empty')]);
        expect(texts('.admin-home-awaiting__empty')).toEqual([home.text('awaiting.empty')]);
        expect(texts('.admin-home-activity__empty')).toEqual([home.text('activity.empty')]);
        expect(view.host.querySelector('.admin-home-awaiting__count')).toBeNull();
    });

    it('lists Awaiting approval with Decide by on the tenant\'s clock, the rail\'s pending number in the attention chip and See all requests to the Approval inbox', () => {
        inertia.props = propsWith({
            nav: [{ label: 'Bookings', url: '/admin/bookings', group: 'Schedule', pending: 7, current: false }],
            awaiting: [feedRow('r1', { status: 'awaiting_approval', starts_at: '2026-11-05T08:00:00+00:00', hold_expires_at: '2026-11-03T16:00:00+00:00' })],
        });
        renderHome();

        expect(view.host.querySelector('#home-awaiting-title')?.textContent).toBe(home.text('awaiting.title'));
        expect(texts('.admin-home-awaiting__customer')).toEqual(['Maria Rossi']);
        expect(texts('.admin-home-awaiting__when')).toEqual(['05/11/2026 10:00']);
        expect(view.host.querySelector('.admin-home-awaiting__when')?.className).toContain('font-mono');
        expect(texts('.admin-home-awaiting__what')).toEqual([home.text('with', { services: 'Haircut', staff: 'Elena Papadaki' })]);
        expect(texts('.admin-home-awaiting__decide')).toEqual([home.text('awaiting.decide_by', { end: '03/11/2026 18:00' })]);

        const chip = view.host.querySelector('header .admin-home-awaiting__count');
        expect(chip?.className).toContain('admin-chip--attention');
        expect(chip?.className).toContain('admin-chip--compact');
        expect(chip?.querySelector('.admin-chip__count')?.textContent).toBe(formatCount(7, home.locale));
        expect(chip?.textContent).toBe(keyedShell().choice('kit.counted.awaiting_approval', 7));
        expect(view.host.querySelector('.admin-home-awaiting__all')?.getAttribute('href')).toBe(APPROVAL_INBOX);
        expect(APPROVAL_INBOX).toBe('/admin/bookings?status=awaiting_approval');
        expect(view.host.querySelector('.admin-home-awaiting__all')?.textContent).toBe(home.text('awaiting.see_all'));
    });

    it('spells each Recent activity row as its one whole sentence with the names filled, when on the tenant\'s clock and the booking\'s start, a row with no booking opening nothing', () => {
        inertia.props = propsWith({
            activity: [
                activityRow('activity.approved.user', { staff: 'Nikos Owner' }),
                activityRow('activity.erased.confirmed.paid', { customer: null, status: 'awaiting_approval' }),
                activityRow('activity.customer_exported', { customer: null, booking: null, starts_at: null, status: null, services: [], members: [] }),
            ],
        });
        renderHome();

        expect(view.host.querySelector('#home-activity-title')?.textContent).toBe(home.text('activity.title'));
        expect(view.host.querySelector('header .admin-home-activity__all')?.getAttribute('href')).toBe('/admin/bookings');
        expect(view.host.querySelector('.admin-home-activity__all')?.textContent).toBe(home.text('activity.all'));
        expect(texts('.admin-home-activity__sentence')).toEqual([
            home.text('activity.approved.user', { staff: 'Nikos Owner', customer: 'Maria Rossi' }),
            home.text('activity.erased.confirmed.paid'),
            home.text('activity.customer_exported'),
        ]);
        expect(texts('.admin-home-activity__at')).toEqual(['01/11/2026 11:00', '01/11/2026 11:00', '01/11/2026 11:00']);
        expect(view.host.querySelector('.admin-home-activity__at')?.className).toContain('font-mono');
        expect(texts('.admin-home-activity__line')).toEqual([
            home.text('activity.line', { booking: home.text('with', { services: 'Haircut', staff: 'Elena Papadaki' }), start: '04/11/2026 10:00' }),
            home.text('activity.line', { booking: home.text('with', { services: 'Haircut', staff: 'Elena Papadaki' }), start: '04/11/2026 10:00' }),
        ]);
        // The row whose booking still awaits approval carries the attention chip, the others none.
        expect([...view.host.querySelectorAll('.admin-home-activity__row')].map((row) => row.querySelector('.admin-chip--attention') !== null)).toEqual([false, true, false]);
        expect(view.host.querySelectorAll('.admin-home-activity__row-open')).toHaveLength(2);
        expect(view.host.querySelectorAll('.admin-home-activity__row')[2]?.querySelector('button')).toBeNull();
    });

    it('opens the booking dialog from a row by a partial reload of the detail alone with the URL preserved, and closes it with no visit', () => {
        inertia.props = propsWith({ awaiting: [feedRow('r1', { status: 'awaiting_approval' })] });
        renderHome();

        press(view.host.querySelector('.admin-home-awaiting__row-open'));

        const [url, query, visit] = lastGet();

        expect(inertia.get).toHaveBeenCalledTimes(1);
        expect(url).toBe('/admin');
        expect(query).toEqual({ booking: 'r1' });
        expect(visit).toMatchObject({ only: ['detail'], preserveUrl: true, preserveState: true, replace: true });
        expect(q('.admin-booking-dialog')).not.toBeNull();

        inertia.props = propsWith({ awaiting: [feedRow('r1', { status: 'awaiting_approval' })], detail: found(booking('r1', 'awaiting_approval'), ['approve', 'reject', 'cancel']) });
        renderHome();

        expect(q('.admin-modal__title')?.textContent).toBe('Maria Rossi');
        expect(q('.admin-booking-action--approve')).not.toBeNull();
        expect(inertia.get).toHaveBeenCalledTimes(1);

        press(q('.admin-booking-close'));
        expect(q('.admin-booking-dialog')).toBeNull();
        expect(inertia.get).toHaveBeenCalledTimes(1);
    });

    it('approves from the dialog as the Bookings page does, busy until the server answers, then reads the open booking again on the page the redirect lands', () => {
        const request = feedRow('r1', { status: 'awaiting_approval' });
        inertia.props = propsWith({ awaiting: [request] });
        renderHome();
        press(view.host.querySelector('.admin-home-awaiting__row-open'));
        inertia.props = propsWith({ awaiting: [request], detail: found(booking('r1', 'awaiting_approval'), ['approve', 'reject', 'cancel']) });
        renderHome();

        press(q('.admin-booking-action--approve'));

        const [url, body, visit] = (inertia.post.mock.lastCall ?? []) as [string, Record<string, string>, Visit];

        expect(url).toBe('/admin/bookings/r1/approve');
        expect(body).toEqual({});
        act(() => visit.onStart?.());
        expect(q('.admin-booking-action--approve')?.getAttribute('aria-busy')).toBe('true');
        expect(view.host.querySelectorAll('.admin-home-awaiting__row')).toHaveLength(1);

        // The redirect back lands Home with every prop re-read and no detail asked for.
        inertia.props = propsWith({ activity: [activityRow('activity.approved.user', { staff: 'Alex Owner', booking: 'r1' })] });
        renderHome();
        act(() => visit.onFinish?.());

        const [again, query, read] = lastGet();

        expect(inertia.get).toHaveBeenCalledTimes(2);
        expect(again).toBe('/admin');
        expect(query).toEqual({ booking: 'r1' });
        expect(read).toMatchObject({ only: ['detail'], preserveUrl: true });
        expect(view.host.querySelectorAll('.admin-home-awaiting__row')).toHaveLength(0);
        expect(texts('.admin-home-activity__sentence')).toEqual([home.text('activity.approved.user', { staff: 'Alex Owner', customer: 'Maria Rossi' })]);
    });
});
