import { act, type ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { Booking, Detail } from '@/bookings/model';
import { mount, type Mounted, press, settle } from '@/components/ui/harness';
import { type ActivitySentence, APPROVAL_INBOX, type HomeActivityRow, type HomeBooking } from '@/home/feeds';
import type { RangeFigures } from '@/home/figures';
import type { ToMarkList } from '@/home/ToMark';
import { formatAmount, formatCount, formatPercent } from '@/lib/format';
import { listOf, unitFigure } from '@/lib/names';
import { ShellProvider } from '@/lib/strings';
import { NOT_DONE } from '@/lib/visits';
import type { Setup } from '@/setup/model';
import { KEYED_BOOKINGS, KEYED_HOME, KEYED_SETUP, keyedHome, keyedSetup, keyedShell } from '@/testing/keyed';
import { ToastProvider } from '@/toast/toasts';
import Home, { dayLink, type HomeDay } from './Home';

/*
 * Home's wiring: the title, the range card choosing today, yesterday, this week or this month by
 * a visit that reads the range's figures alone, the six tiles each compared with the period before in one sentence per
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
 *
 * Setup on Home, for an owner the answer carries it to: the banner above the title with its count
 * and Continue; the wizard coming up by itself on the step the answer Home is first drawn with
 * names, that step then spent in the page the browser keeps, and the record that it came up, made
 * once and made again where it did not land; Continue opening it on the first open step; the close
 * handing focus to Continue; and, once an answer comes back with no setup while the wizard is up,
 * the wizard closing, with the toast only where that answer says setup was finished just now.
 */

const home = keyedHome();

const inertia = vi.hoisted(() => ({ props: {} as Record<string, unknown>, get: vi.fn(), post: vi.fn(), replace: vi.fn(), listeners: new Map<string, (event: Event) => void>() }));

type Visit = { only?: string[]; preserveUrl?: boolean; preserveState?: boolean; replace?: boolean; onStart?: () => void; onSuccess?: () => void; onFinish?: () => void };

/** A visit that rewrites the kept page in place, its props made from the page's own. */
type Rewrite = { preserveState?: boolean; preserveScroll?: boolean; props: (props: Record<string, unknown>) => Record<string, unknown> };

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
        replace: inertia.replace,
        on: (name: string, listener: (event: Event) => void) => {
            inertia.listeners.set(name, listener);

            return () => inertia.listeners.delete(name);
        },
    },
}));

const HOSTED = 'http://luna.localhost/';

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
        strings: { home: KEYED_HOME, bookings: KEYED_BOOKINGS, setup: KEYED_SETUP },
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
        to_mark: { count: 0, thank_you: true, days: [] },
        members: 3,
        detail: null,
        ...overrides,
    };
}

/** Setup open with the staff feature, Services done and Team skipped, the wizard already up once. */
function setupOf(patch: Partial<Setup> = {}): Setup {
    return {
        steps: [
            { key: 'profile', done: false, skipped: false },
            { key: 'services', done: true, skipped: false },
            { key: 'team', done: false, skipped: true },
            { key: 'hours', done: false, skipped: false },
            { key: 'payments', done: false, skipped: false },
            { key: 'link', done: false, skipped: false },
        ],
        opened: true,
        opens: null,
        data: { link: { hosted_url: HOSTED } },
        ...patch,
    };
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
    inertia.listeners.clear();
    inertia.get.mockReset();
    inertia.post.mockReset();
    inertia.replace.mockReset();
    inertia.props = propsWith();
    view = mount();
});

afterEach(() => {
    view.unmount();
});

describe('Home', () => {
    it('is titled Home under the setup banner, then To mark, the range, the tiles, the footnote, the feeds, the Top tables, the activity and the days in that order', () => {
        inertia.props = propsWith({ setup: setupOf() });
        renderHome();

        expect(view.host.querySelector('h1')?.textContent).toBe(home.text('title'));
        expect(view.host.querySelector('.admin-setup-banner')?.compareDocumentPosition(view.host.querySelector('h1') as Node)).toBe(Node.DOCUMENT_POSITION_FOLLOWING);

        const order = ['.admin-setup-banner', '.admin-home-to-mark', '.admin-home-range', '.admin-home-figures', '.admin-home-footnote', '.admin-home-next', '.admin-home-awaiting', '.admin-home-top--services', '.admin-home-top--staff', '.admin-home-activity', '.admin-home-days'];
        const parts = order.map((selector) => view.host.querySelector(selector));
        expect(parts.map((part, index) => (part === null ? order[index] : null)).filter((missing) => missing !== null)).toEqual([]);
        parts.slice(1).forEach((part, index) => expect(parts[index]?.compareDocumentPosition(part as Node)).toBe(Node.DOCUMENT_POSITION_FOLLOWING));

        // Side by side in pairs: each pair in one grid of its own.
        expect(parts[5]?.parentElement).toBe(parts[6]?.parentElement);
        expect(parts[7]?.parentElement).toBe(parts[8]?.parentElement);
        expect(parts[9]?.parentElement).toBe(parts[10]?.parentElement);
        expect(view.host.querySelector('.admin-home-footnote')?.textContent).toBe(home.text('footnote'));
    });

    it('offers the four ranges in one group, the server\'s range pressed, its line beneath with its two dates or its one day', () => {
        renderHome();

        const group = view.host.querySelector('.admin-home-range [role="group"]');
        expect(group?.getAttribute('aria-label')).toBe(home.text('range.label'));
        const options = [...view.host.querySelectorAll<HTMLButtonElement>('.admin-home-range button')];
        expect(options.map((option) => option.textContent)).toEqual([home.text('range.today'), home.text('range.yesterday'), home.text('range.week'), home.text('range.month')]);
        expect(options.map((option) => option.getAttribute('aria-pressed'))).toEqual(['false', 'false', 'true', 'false']);
        expect(view.host.querySelector('.admin-home-range__line')?.textContent).toBe(home.text('range.line.week', { from: '02/11/2026', to: '08/11/2026' }));

        inertia.props = propsWith({ range_figures: rangeFigures('today', '2026-11-02', '2026-11-02') });
        renderHome();
        expect([...view.host.querySelectorAll('.admin-home-range button')].map((option) => option.getAttribute('aria-pressed'))).toEqual(['true', 'false', 'false', 'false']);
        expect(view.host.querySelector('.admin-home-range__line')?.textContent).toBe(home.text('range.line.today', { date: '02/11/2026' }));

        inertia.props = propsWith({ range_figures: rangeFigures('yesterday', '2026-11-01', '2026-11-01') });
        renderHome();
        expect(view.host.querySelector('.admin-home-range__line')?.textContent).toBe(home.text('range.line.yesterday', { date: '01/11/2026' }));

        inertia.props = propsWith({ range_figures: rangeFigures('month', '2026-11-01', '2026-11-30') });
        renderHome();
        expect(view.host.querySelector('.admin-home-range__line')?.textContent).toBe(home.text('range.line.month', { from: '01/11/2026', to: '30/11/2026' }));
    });

    it('chooses a range by a visit to Home that names it in the address, this week by none, keeping the scroll and reading the range\'s figures alone', () => {
        renderHome();

        press(view.host.querySelectorAll('.admin-home-range button')[3] ?? null);
        expect(inertia.get).toHaveBeenCalledTimes(1);
        const [url, query, visit] = lastGet();
        expect(url).toBe('/admin');
        expect(query).toEqual({ range: 'month' });
        expect(visit).toMatchObject({ only: ['range_figures'], preserveScroll: true, preserveState: true });

        press(view.host.querySelectorAll('.admin-home-range button')[0] ?? null);
        expect(lastGet()[1]).toEqual({ range: 'today' });

        inertia.props = propsWith({ range_figures: rangeFigures('month', '2026-11-01', '2026-11-30') });
        renderHome();
        press(view.host.querySelectorAll('.admin-home-range button')[2] ?? null);
        expect(lastGet()[0]).toBe('/admin');
        expect(lastGet()[1]).toEqual({});

        // The range already shown asks for nothing.
        press(view.host.querySelectorAll('.admin-home-range button')[3] ?? null);
        expect(inertia.get).toHaveBeenCalledTimes(3);
    });

    it('marks the range card busy while a range reads and owns a failed read, saying so on Home in its own sentence', () => {
        renderHome();
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
        renderHome();

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
            renderHome();

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
        renderHome();

        expect(tile('bookings')).toEqual(['0', home.text('change.week.same')]);
        expect(tile('revenue')).toEqual([formatAmount(0, 'EUR', home.locale), home.text('change.week.same'), formatAmount(1000, 'USD', home.locale)]);
        expect(tile('occupancy')).toEqual([home.text('tiles.no_hours')]);

        inertia.props = propsWith({ range_figures: rangeFigures('week', '2026-11-02', '2026-11-08', { occupancy: { value: 0, previous: 0, change: none, filled_minutes: 0, offered_minutes: 600 } }) });
        renderHome();
        expect(tile('occupancy')).toEqual([formatPercent(0, home.locale), home.text('change.week.same'), home.text('tiles.filled', { filled: unitFigure(0, 'hour', 'en'), offered: unitFigure(10, 'hour', 'en') })]);

        inertia.props = propsWith({ range_figures: rangeFigures('today', '2026-11-02', '2026-11-02', { occupancy: { value: 112.5, previous: null, change: none, filled_minutes: 135, offered_minutes: 120 } }) });
        renderHome();
        expect(tile('occupancy')).toEqual([
            formatPercent(112.5, home.locale),
            home.text('tiles.filled', { filled: home.text('tiles.duration', { hours: unitFigure(2, 'hour', 'en'), minutes: unitFigure(15, 'minute', 'en') }), offered: unitFigure(2, 'hour', 'en') }),
        ]);
    });

    it('lists Top services and Top staff by name, bookings and revenue, the business\'s own row by the name it is given, and says so in place of a table where nothing was counted', () => {
        renderHome();

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
        renderHome();
        expect(view.host.querySelectorAll('.admin-home-top table')).toHaveLength(0);
        expect(texts('.admin-home-top__empty')).toEqual([home.text('top.empty'), home.text('top.empty')]);
    });

    it('carries no link to Reports and none of the old week\'s tiles', () => {
        renderHome();

        expect(view.host.querySelector('.admin-home-reports')).toBeNull();
        expect(view.host.querySelectorAll('.admin-figure-tile')).toHaveLength(6);
    });

    it('draws a bar per day, each linking to the Calendar Day on its date, named by the short weekday and the day of the month with its count above', () => {
        renderHome();

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
        renderHome();

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
        renderHome();

        // One whole sentence per case the strip shows: several awaiting, one plain sentence read as
        // text, the week then holding two bookings or more.
        expect(view.host.querySelector('#home-days-title')?.closest('header')?.querySelector('.admin-home-days__summary')?.textContent).toBe(
            home.text('days.summary.many_awaiting', { count: formatCount(8, home.locale), awaiting: formatCount(2, home.locale) }),
        );
        expect(texts('.admin-home-days__footnote')).toEqual([home.text('days.footnote')]);

        // One awaiting, among several bookings and as the week's one booking.
        inertia.props = propsWith({ days: DAYS.map((day, index) => ({ ...day, awaiting: index === 4 ? 1 : 0 })) });
        renderHome();
        expect(texts('.admin-home-days__summary')).toEqual([home.choice('days.summary.one_awaiting', 7, { count: formatCount(7, home.locale), awaiting: formatCount(1, home.locale) })]);

        inertia.props = propsWith({ days: DAYS.map((day, index) => ({ ...day, confirmed: 0, awaiting: index === 4 ? 1 : 0 })) });
        renderHome();
        expect(texts('.admin-home-days__summary')).toEqual([home.choice('days.summary.one_awaiting', 1, { count: formatCount(1, home.locale), awaiting: formatCount(1, home.locale) })]);

        inertia.props = propsWith({ days: DAYS.map((day) => ({ ...day, awaiting: 0 })) });
        renderHome();
        expect(texts('.admin-home-days__summary')).toEqual([home.choice('days.bookings', 6, { count: formatCount(6, home.locale) })]);

        inertia.props = propsWith({ days: DAYS.map((day) => ({ ...day, confirmed: 0, awaiting: 0 })) });
        renderHome();
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
                <ToastProvider>
                    <Home />
                </ToastProvider>
            </ShellProvider>,
        );
        const el = read();
        expect(el).toEqual(shown(greek.locale));

        renderHome();
        const en = read();
        expect(en).toEqual(shown(home.locale));
        expect(el[0]).not.toBe(en[0]);
        expect(el[1]).not.toBe(en[1]);
        expect(el[2]).not.toBe(en[2]);
        expect(el[4]).not.toBe(en[4]);
    });

    it('says in the banner how many of the steps that apply are done or skipped, with Continue, the wizard not up', () => {
        const words = keyedSetup();
        inertia.props = propsWith({ setup: setupOf() });
        renderHome();

        expect(view.host.querySelector('.admin-setup-banner')?.getAttribute('aria-label')).toBe(words.text('title'));
        expect(view.host.querySelector('.admin-setup-banner__progress')?.textContent).toBe(words.text('banner.progress', { done: formatCount(2, words.locale), total: formatCount(6, words.locale) }));
        expect(view.host.querySelector('.admin-setup-banner__continue')?.textContent).toBe(words.text('banner.continue'));
        expect(q('.admin-setup')).toBeNull();

        inertia.props = propsWith({ setup: setupOf({ steps: setupOf().steps.filter((fact) => fact.key !== 'team') }) });
        renderHome();
        expect(view.host.querySelector('.admin-setup-banner__progress')?.textContent).toBe(words.text('banner.progress', { done: formatCount(1, words.locale), total: formatCount(5, words.locale) }));
    });

    it('opens the wizard from Continue on the first step still open, recording nothing where it has come up before', () => {
        const words = keyedSetup();
        inertia.props = propsWith({ setup: setupOf({ steps: setupOf().steps.map((fact) => (fact.key === 'profile' ? { ...fact, done: true } : fact)) }) });
        renderHome();

        press(view.host.querySelector('.admin-setup-banner__continue'));

        expect(q('.admin-setup')).not.toBeNull();
        expect(q('.admin-setup__name')?.textContent).toBe(words.text('steps.hours.name'));
        expect(q('.admin-setup__step[aria-current="step"]')?.getAttribute('data-step')).toBe('hours');
        expect(inertia.post).not.toHaveBeenCalled();
    });

    it('comes up by itself on the step the answer names and records once that it came up, by a post that keeps the page and runs beside any other visit', () => {
        const words = keyedSetup();
        inertia.props = propsWith({ setup: setupOf({ opened: false, opens: 'profile' }) });
        renderHome();

        expect(q('.admin-setup__name')?.textContent).toBe(words.text('steps.profile.name'));
        expect(inertia.post).toHaveBeenCalledTimes(1);
        const [url, body, visit] = lastPost();
        expect([url, body]).toEqual(['/admin/setup/opened', {}]);
        expect(visit).toMatchObject({ async: true, preserveState: true, preserveScroll: true });

        renderHome();
        expect(inertia.post).toHaveBeenCalledTimes(1);
    });

    it('takes the step to open on from the answer it is first drawn with alone, a later answer naming one neither moving the wizard nor bringing it up', () => {
        const current = () => q('.admin-setup__step[aria-current="step"]')?.getAttribute('data-step');
        inertia.props = propsWith({ setup: setupOf({ opens: 'profile' }) });
        renderHome();

        press(q('.admin-setup__step[data-step="hours"]'));
        expect(current()).toBe('hours');

        inertia.props = propsWith({ setup: setupOf({ opens: 'link' }) });
        renderHome();
        expect(current()).toBe('hours');

        press(q('.admin-setup__close'));
        expect(q('.admin-setup')).toBeNull();

        inertia.props = propsWith({ setup: setupOf({ opens: 'profile' }) });
        renderHome();
        expect(q('.admin-setup')).toBeNull();
    });

    it('spends the step to open on in the page the browser keeps, every other prop left the object it was, so that page restored does not bring the wizard up again', () => {
        inertia.props = propsWith({ setup: setupOf({ opens: 'profile' }) });
        renderHome();

        expect(inertia.replace).toHaveBeenCalledTimes(1);
        const [rewrite] = inertia.replace.mock.lastCall as [Rewrite];
        expect(rewrite).toMatchObject({ preserveState: true, preserveScroll: true });

        const before = inertia.props;
        const after = rewrite.props(before);
        expect(after.setup).toEqual({ ...(before.setup as Setup), opens: null });
        expect(Object.keys(after)).toEqual(Object.keys(before));
        for (const name of Object.keys(before).filter((each) => each !== 'setup')) {
            expect([name, after[name] === before[name]]).toEqual([name, true]);
        }

        const bare = propsWith();
        expect(rewrite.props(bare)).toBe(bare);

        inertia.replace.mockClear();
        inertia.props = propsWith({ setup: setupOf() });
        renderHome();
        expect(inertia.replace).not.toHaveBeenCalled();
    });

    it('records that it came up again at the next step where the record did not land, and no more once one has', () => {
        inertia.props = propsWith({ setup: setupOf({ opened: false, opens: 'profile' }) });
        renderHome();
        expect(inertia.post).toHaveBeenCalledTimes(1);

        act(() => lastPost()[2].onFinish?.());
        expect(inertia.post).toHaveBeenCalledTimes(1);

        press(q('.admin-setup__step[data-step="hours"]'));
        expect(inertia.post).toHaveBeenCalledTimes(2);
        expect(lastPost()[0]).toBe('/admin/setup/opened');

        act(() => {
            lastPost()[2].onSuccess?.();
            lastPost()[2].onFinish?.();
        });
        press(q('.admin-setup__step[data-step="link"]'));
        expect(inertia.post).toHaveBeenCalledTimes(2);
    });

    it('closes the wizard by its close button and hands the focus to Continue, the banner left in place', async () => {
        inertia.props = propsWith({ setup: setupOf({ opened: false, opens: 'profile' }) });
        renderHome();

        press(q('.admin-setup__close'));
        await settle();

        expect(q('.admin-setup')).toBeNull();
        expect(document.activeElement).toBe(view.host.querySelector('.admin-setup-banner__continue'));
    });

    it('closes the wizard and says setup is complete in a toast, once, when the answer that says setup was finished just now comes back with no setup while it is up', () => {
        const words = keyedSetup();
        const said = () => [...document.body.querySelectorAll('.admin-toast[data-kind="success"] p')].map((line) => line.textContent);
        inertia.props = propsWith({ setup: setupOf({ opens: 'link' }) });
        renderHome();
        expect(q('.admin-setup')).not.toBeNull();

        inertia.props = propsWith({ setup_finished: true });
        renderHome();

        expect(q('.admin-setup')).toBeNull();
        expect(view.host.querySelector('.admin-setup-banner')).toBeNull();
        expect(said()).toEqual([words.text('complete')]);

        inertia.props = propsWith();
        renderHome();
        expect(said()).toEqual([words.text('complete')]);
    });

    it('closes the wizard and says nothing when an answer comes back with no setup and no word that it was finished just now', () => {
        inertia.props = propsWith({ setup: setupOf({ opens: 'link' }) });
        renderHome();
        expect(q('.admin-setup')).not.toBeNull();

        inertia.props = propsWith();
        renderHome();

        expect(q('.admin-setup')).toBeNull();
        expect(view.host.querySelector('.admin-setup-banner')).toBeNull();
        expect(document.body.querySelector('.admin-toast')).toBeNull();
    });

    it('says nothing where setup was finished with the wizard not up, in front of the banner alone or of a page first drawn with no setup', () => {
        inertia.props = propsWith({ setup: setupOf() });
        renderHome();
        expect(q('.admin-setup')).toBeNull();

        inertia.props = propsWith({ setup_finished: true });
        renderHome();
        expect(view.host.querySelector('.admin-setup-banner')).toBeNull();
        expect(document.body.querySelector('.admin-toast')).toBeNull();

        view.render(<p />);
        renderHome();
        expect(document.body.querySelector('.admin-toast')).toBeNull();
    });

    it('draws neither banner nor wizard and says nothing when the answer carries no setup, the tiles and the days still drawn', () => {
        inertia.props = propsWith();
        renderHome();

        expect(view.host.querySelector('.admin-setup-banner')).toBeNull();
        expect(q('.admin-setup')).toBeNull();
        expect(document.body.querySelector('.admin-toast')).toBeNull();
        expect(view.host.querySelector('.admin-home-figures')).not.toBeNull();
        expect(view.host.querySelectorAll('.admin-home-day')).toHaveLength(7);
        expect(inertia.post).not.toHaveBeenCalled();
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
            nav: [{ key: 'bookings', label: 'Bookings', url: '/admin/bookings', group: 'Schedule', pending: 7, current: false }],
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

    it('lets the Awaiting approval strip give way on a narrow phone, the title first, then the link, the chip\'s label last, and its card go no wider than its column', () => {
        inertia.props = propsWith({
            nav: [{ key: 'bookings', label: 'Bookings', url: '/admin/bookings', group: 'Schedule', pending: 7, current: false }],
            awaiting: [feedRow('r1', { status: 'awaiting_approval', starts_at: '2026-11-05T08:00:00+00:00', hold_expires_at: '2026-11-03T16:00:00+00:00' })],
        });
        renderHome();

        const card = view.host.querySelector('.admin-home-awaiting');
        const aside = view.host.querySelector('header .admin-home-awaiting__aside');
        const has = (element: Element | null | undefined, names: string[]) => names.filter((name) => !element?.classList.contains(name));

        expect(has(card, ['[&>header>h2]:shrink-[9999]'])).toEqual([]);
        expect(card?.querySelector(':scope > header > h2')?.id).toBe('home-awaiting-title');
        expect(has(aside, ['flex', 'min-w-0'])).toEqual([]);
        expect([...(aside?.children ?? [])].map((child) => child.className.split(' ').find((name) => name.startsWith('admin-home-awaiting__')))).toEqual(['admin-home-awaiting__count', 'admin-home-awaiting__all']);
        expect(has(aside?.querySelector('.admin-home-awaiting__count'), ['min-w-0', 'admin-chip--compact'])).toEqual([]);
        expect(has(aside?.querySelector('.admin-home-awaiting__all'), ['grow', 'basis-0'])).toEqual([]);
        expect(has(card?.parentElement, ['grid', 'grid-cols-1', 'lg:grid-cols-2'])).toEqual([]);
        expect(view.host.querySelector('.admin-home-next')?.classList.contains('[&>header>h2]:shrink-[9999]')).toBe(false);
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

/*
 * To mark's rows: two today, then two on Saturday the 31st, one of them an erased customer's, on
 * luna's clock in Athens, two hours ahead of UTC in November.
 */
function toMark(patch: Partial<ToMarkList> = {}): ToMarkList {
    return {
        count: 4,
        thank_you: true,
        days: [
            { date: '2026-11-02', today: true, bookings: [feedRow('t1', { customer: 'Maria P.', starts_at: '2026-11-02T06:00:00+00:00' }), feedRow('t2', { customer: 'Nikos K.', starts_at: '2026-11-02T07:15:00+00:00' })] },
            { date: '2026-10-31', today: false, bookings: [feedRow('o1', { customer: 'Eleni D.', starts_at: '2026-10-31T07:30:00+00:00' }), feedRow('o2', { customer: null, starts_at: '2026-10-31T12:00:00+00:00' })] },
        ],
        ...patch,
    };
}

function toMarkCard(): Element | null {
    return view.host.querySelector('.admin-home-to-mark');
}

/** A row's own control by its booking: the checkbox, the text that opens it, or one of its marks. */
function rowPart(uuid: string, part: 'tick' | 'open' | 'complete' | 'no-show'): Element | null {
    return view.host.querySelector(`.admin-home-to-mark__row[data-booking="${uuid}"] .admin-home-to-mark__${part}`);
}

function dayTick(date: string): HTMLInputElement | null {
    return view.host.querySelector<HTMLInputElement>(`.admin-home-to-mark__day[data-date="${date}"] .admin-home-to-mark__day-tick`);
}

function lastPost(): [string, Record<string, unknown>, Visit] {
    return (inertia.post.mock.lastCall ?? []) as [string, Record<string, unknown>, Visit];
}

describe('Home\'s To mark', () => {
    it('lists each day under its heading, Today first, an older one by its long weekday and its date in the tenant\'s format, each row its start on the tenant\'s clock, the customer, and the services with the member', () => {
        inertia.props = propsWith({ to_mark: toMark() });
        renderHome();

        expect(toMarkCard()?.querySelector('h2, h3')?.textContent).toBe(home.text('to_mark.title'));
        expect(texts('.admin-home-to-mark__heading')).toEqual([home.text('to_mark.today'), home.text('to_mark.day', { weekday: 'Saturday', date: '31/10/2026' })]);
        expect(texts('.admin-home-to-mark__time')).toEqual(['08:00', '09:15', '09:30', '14:00']);
        expect(texts('.admin-home-to-mark__customer')).toEqual(['Maria P.', 'Nikos K.', 'Eleni D.', home.text('customer_erased')]);
        expect(texts('.admin-home-to-mark__what')[0]).toBe(home.text('with', { services: 'Haircut', staff: 'Elena Papadaki' }));
        expect(view.host.querySelector('.admin-home-to-mark__showing')).toBeNull();
    });

    it('sits under the title and above the range card', () => {
        inertia.props = propsWith({ to_mark: toMark() });
        renderHome();

        expect(view.host.querySelector('h1')?.compareDocumentPosition(toMarkCard() as Node)).toBe(Node.DOCUMENT_POSITION_FOLLOWING);
        expect(toMarkCard()?.compareDocumentPosition(view.host.querySelector('.admin-home-range') as Node)).toBe(Node.DOCUMENT_POSITION_FOLLOWING);
    });

    it('shows the count beside the title with its whole sentence as the accessible text, and names each checkbox for its booking', () => {
        inertia.props = propsWith({ to_mark: toMark() });
        renderHome();

        const count = view.host.querySelector('.admin-home-to-mark__count');

        expect(count?.querySelector('[aria-hidden="true"]')?.textContent).toBe('4');
        expect(count?.querySelector('.sr-only')?.textContent).toBe(home.choice('to_mark.count', 4, { count: '4' }));
        expect(rowPart('t1', 'tick')?.getAttribute('aria-label')).toBe(home.text('to_mark.select_booking', { customer: 'Maria P.', time: '08:00' }));
        expect(rowPart('o2', 'tick')?.getAttribute('aria-label')).toBe(home.text('to_mark.select_erased', { time: '14:00' }));
        expect(dayTick('2026-10-31')?.getAttribute('aria-label')).toBe(home.text('to_mark.select_day'));
        expect(dayTick('2026-10-31')?.getAttribute('aria-describedby')).toBe(view.host.querySelector('.admin-home-to-mark__day[data-date="2026-10-31"] .admin-home-to-mark__heading')?.id);
    });

    it('says Nothing to mark with no count, no list and no bulk control when nothing is waiting', () => {
        renderHome();

        expect(texts('.admin-home-to-mark__empty')).toEqual([home.text('to_mark.empty')]);
        expect(view.host.querySelector('.admin-home-to-mark__count')).toBeNull();
        expect(view.host.querySelector('.admin-home-to-mark__list')).toBeNull();
        expect(view.host.querySelector('.admin-home-to-mark__bulk')).toBeNull();
    });

    it('says how many it shows of how many there are where the server listed fewer than it counted', () => {
        inertia.props = propsWith({ to_mark: toMark({ count: 240 }) });
        renderHome();

        expect(texts('.admin-home-to-mark__showing')).toEqual([home.text('to_mark.showing', { listed: '4', count: '240' })]);
        expect(view.host.querySelector('.admin-home-to-mark__count .sr-only')?.textContent).toBe(home.choice('to_mark.count', 240, { count: '240' }));
    });

    it('marks a row at once by the dialog\'s own route with no dialog, the pressed button busy and every other press refused until the server answers', () => {
        inertia.props = propsWith({ to_mark: toMark() });
        renderHome();

        press(rowPart('t1', 'complete'));

        const [url, body, visit] = lastPost();

        expect(inertia.post).toHaveBeenCalledTimes(1);
        expect(url).toBe('/admin/bookings/t1/complete');
        expect(body).toEqual({});
        expect(visit).toMatchObject({ preserveScroll: true, preserveState: true });
        expect(q('.admin-booking-dialog')).toBeNull();
        expect(q('.admin-home-to-mark-confirm')).toBeNull();

        act(() => visit.onStart?.());
        expect(rowPart('t1', 'complete')?.getAttribute('aria-busy')).toBe('true');
        expect(rowPart('t1', 'complete')?.textContent).toBe(KEYED_BOOKINGS.busy.complete);
        press(rowPart('o1', 'no-show'));
        expect(inertia.post).toHaveBeenCalledTimes(1);

        // The redirect back lands Home with the row no longer listed.
        const [, ...rest] = toMark().days[0]?.bookings ?? [];
        inertia.props = propsWith({ to_mark: toMark({ count: 3, days: [{ date: '2026-11-02', today: true, bookings: rest }, ...toMark().days.slice(1)] }) });
        renderHome();
        act(() => visit.onFinish?.());

        expect(view.host.querySelector('.admin-home-to-mark__row[data-booking="t1"]')).toBeNull();
        press(rowPart('o1', 'no-show'));
        expect(lastPost()[0]).toBe('/admin/bookings/o1/no-show');
    });

    it('selects a row by its checkbox and a day by its own, the day\'s reading mixed while some of its rows are, the selection\'s two marks shown beside its count only while a row is selected', () => {
        inertia.props = propsWith({ to_mark: toMark() });
        renderHome();

        expect(view.host.querySelector('.admin-home-to-mark__mark-completed')).toBeNull();
        expect(view.host.querySelector('.admin-home-to-mark__mark-no-show')).toBeNull();
        expect(view.host.querySelector('.admin-home-to-mark__selected')).toBeNull();
        expect(texts('.admin-home-to-mark__mark-rest')).toEqual([home.text('to_mark.mark_rest')]);

        press(rowPart('o1', 'tick'));

        expect(dayTick('2026-10-31')?.indeterminate).toBe(true);
        expect(dayTick('2026-10-31')?.checked).toBe(false);
        expect(texts('.admin-home-to-mark__selected')).toEqual([home.choice('to_mark.selected', 1, { count: '1' })]);
        expect(texts('.admin-home-to-mark__mark-completed')).toEqual([home.text('to_mark.mark_completed')]);
        expect(texts('.admin-home-to-mark__mark-no-show')).toEqual([home.text('to_mark.mark_no_show')]);

        press(dayTick('2026-10-31'));

        expect(dayTick('2026-10-31')?.indeterminate).toBe(false);
        expect(dayTick('2026-10-31')?.checked).toBe(true);
        expect((rowPart('o2', 'tick') as HTMLInputElement).checked).toBe(true);
        expect(dayTick('2026-11-02')?.checked).toBe(false);
        expect(texts('.admin-home-to-mark__selected')).toEqual([home.choice('to_mark.selected', 2, { count: '2' })]);

        press(dayTick('2026-10-31'));

        expect((rowPart('o1', 'tick') as HTMLInputElement).checked).toBe(false);
        expect(view.host.querySelector('.admin-home-to-mark__mark-completed')).toBeNull();
        expect(view.host.querySelector('.admin-home-to-mark__selected')).toBeNull();
    });

    it('asks once before marking the selection as no-shows, the count in the title and that it cannot be undone, then posts the outcome and the rows in the list\'s order, keeping the rows still listed selected', () => {
        inertia.props = propsWith({ to_mark: toMark() });
        renderHome();
        press(rowPart('o2', 'tick'));
        press(rowPart('t1', 'tick'));
        press(rowPart('t2', 'tick'));

        press(view.host.querySelector('.admin-home-to-mark__mark-no-show'));

        expect(inertia.post).not.toHaveBeenCalled();
        expect(q('.admin-modal__title')?.textContent).toBe(home.choice('to_mark.confirm.no_show', 3, { count: '3' }));
        expect(q('.admin-home-to-mark-confirm__undone')?.textContent).toBe(home.text('to_mark.confirm.undone'));
        expect(q('.admin-home-to-mark-confirm__thanks')).toBeNull();
        expect(q('.admin-home-to-mark-confirm__keep')?.textContent).toBe(home.text('to_mark.confirm.back'));
        expect(q('.admin-home-to-mark-confirm__confirm')?.textContent).toBe(home.text('to_mark.confirm.mark_no_show'));

        press(q('.admin-home-to-mark-confirm__confirm'));

        const [url, body, visit] = lastPost();

        expect(inertia.post).toHaveBeenCalledTimes(1);
        expect(url).toBe('/admin/bookings/mark');
        expect(body).toEqual({ outcome: 'no_show', bookings: ['t1', 't2', 'o2'] });
        act(() => visit.onStart?.());
        expect(q('.admin-home-to-mark-confirm__confirm')?.getAttribute('aria-busy')).toBe('true');
        expect(q('.admin-home-to-mark-confirm__pending')?.textContent).toBe(home.text('to_mark.confirm.pending'));

        // Two of the three went; the one the server still lists stays selected.
        inertia.props = propsWith({ to_mark: toMark({ count: 2, days: [{ date: '2026-11-02', today: true, bookings: [feedRow('t2', { customer: 'Nikos K.', starts_at: '2026-11-02T07:15:00+00:00' })] }, { date: '2026-10-31', today: false, bookings: [feedRow('o1', { customer: 'Eleni D.', starts_at: '2026-10-31T07:30:00+00:00' })] }] }) });
        renderHome();
        act(() => visit.onFinish?.());

        expect(q('.admin-home-to-mark-confirm')).toBeNull();
        expect((rowPart('t2', 'tick') as HTMLInputElement).checked).toBe(true);
        expect((rowPart('o1', 'tick') as HTMLInputElement).checked).toBe(false);
        expect(texts('.admin-home-to-mark__selected')).toEqual([home.choice('to_mark.selected', 1, { count: '1' })]);
    });

    it('marks the rest as completed, whatever is selected, saying each customer gets the thank-you where the tenant sends it, and Back marks nothing', () => {
        inertia.props = propsWith({ to_mark: toMark() });
        renderHome();
        press(rowPart('o1', 'tick'));

        press(view.host.querySelector('.admin-home-to-mark__mark-rest'));

        expect(q('.admin-modal__title')?.textContent).toBe(home.choice('to_mark.confirm.completed', 4, { count: '4' }));
        expect(q('.admin-home-to-mark-confirm__thanks')?.textContent).toBe(home.choice('to_mark.confirm.thanks', 4));
        expect(q('.admin-home-to-mark-confirm__confirm')?.textContent).toBe(home.text('to_mark.confirm.mark_completed'));

        press(q('.admin-home-to-mark-confirm__keep'));

        expect(q('.admin-home-to-mark-confirm')).toBeNull();
        expect(inertia.post).not.toHaveBeenCalled();

        press(view.host.querySelector('.admin-home-to-mark__mark-rest'));
        press(q('.admin-home-to-mark-confirm__confirm'));

        expect(lastPost().slice(0, 2)).toEqual(['/admin/bookings/mark', { outcome: 'completed', bookings: ['t1', 't2', 'o1', 'o2'] }]);
    });

    it('says nothing of the thank-you where the tenant has it switched off, and asks of one booking in the singular', () => {
        inertia.props = propsWith({ to_mark: toMark({ count: 1, thank_you: false, days: [{ date: '2026-11-02', today: true, bookings: [feedRow('t1')] }] }) });
        renderHome();

        press(view.host.querySelector('.admin-home-to-mark__mark-rest'));

        expect(q('.admin-modal__title')?.textContent).toBe(home.choice('to_mark.confirm.completed', 1, { count: '1' }));
        expect(q('.admin-home-to-mark-confirm__undone')).not.toBeNull();
        expect(q('.admin-home-to-mark-confirm__thanks')).toBeNull();
    });

    it('shows a viewer without manageBookings the rows alone: no checkbox, no mark and no bulk control, the row still opening the booking', () => {
        inertia.props = propsWith({ to_mark: toMark(), permissions: ['viewBookings'] });
        renderHome();

        expect(texts('.admin-home-to-mark__customer')).toHaveLength(4);
        expect(toMarkCard()?.querySelectorAll('input')).toHaveLength(0);
        expect([...(toMarkCard()?.querySelectorAll('button') ?? [])].every((button) => button.classList.contains('admin-home-to-mark__open'))).toBe(true);
        expect(view.host.querySelector('.admin-home-to-mark__bulk')).toBeNull();
        expect(q('.admin-home-to-mark-confirm')).toBeNull();

        press(rowPart('t1', 'open'));

        expect(lastGet().slice(0, 2)).toEqual(['/admin', { booking: 't1' }]);
    });

    it('opens the booking dialog from a row\'s text by the partial reload Home\'s other rows use', () => {
        inertia.props = propsWith({ to_mark: toMark() });
        renderHome();

        press(rowPart('o1', 'open'));

        const [url, query, visit] = lastGet();

        expect(url).toBe('/admin');
        expect(query).toEqual({ booking: 'o1' });
        expect(visit).toMatchObject({ only: ['detail'], preserveUrl: true });
        expect(q('.admin-booking-dialog')).not.toBeNull();
        expect(inertia.post).not.toHaveBeenCalled();
    });

    it('holds a row\'s text while a mark is in flight, opening no booking and reading no detail until the server answers', () => {
        inertia.props = propsWith({ to_mark: toMark() });
        renderHome();

        press(rowPart('t1', 'complete'));

        const [, , visit] = lastPost();

        act(() => visit.onStart?.());
        press(rowPart('o1', 'open'));
        press(rowPart('t1', 'open'));

        expect(inertia.get).not.toHaveBeenCalled();
        expect(q('.admin-booking-dialog')).toBeNull();

        act(() => visit.onFinish?.());
        press(rowPart('o1', 'open'));

        expect(lastGet().slice(0, 2)).toEqual(['/admin', { booking: 'o1' }]);
        expect(q('.admin-booking-dialog')).not.toBeNull();
    });

    it('holds the three bulk buttons while a row\'s mark is in flight, asking nothing, then asks of the rows the server still lists and posts those', () => {
        inertia.props = propsWith({ to_mark: toMark() });
        renderHome();
        press(rowPart('t2', 'tick'));

        press(rowPart('t1', 'complete'));

        const [, , visit] = lastPost();

        act(() => visit.onStart?.());
        ['mark-completed', 'mark-no-show', 'mark-rest'].forEach((name) => {
            press(view.host.querySelector(`.admin-home-to-mark__${name}`));
            expect(q('.admin-home-to-mark-confirm')).toBeNull();
        });

        // The redirect back lands Home with the marked row gone.
        const [, ...rest] = toMark().days[0]?.bookings ?? [];
        inertia.props = propsWith({ to_mark: toMark({ count: 3, days: [{ date: '2026-11-02', today: true, bookings: rest }, ...toMark().days.slice(1)] }) });
        renderHome();
        act(() => visit.onFinish?.());

        press(view.host.querySelector('.admin-home-to-mark__mark-rest'));
        expect(q('.admin-modal__title')?.textContent).toBe(home.choice('to_mark.confirm.completed', 3, { count: '3' }));

        press(q('.admin-home-to-mark-confirm__confirm'));

        expect(inertia.post).toHaveBeenCalledTimes(2);
        expect(lastPost().slice(0, 2)).toEqual(['/admin/bookings/mark', { outcome: 'completed', bookings: ['t2', 'o1', 'o2'] }]);
    });

    it('says a bulk mark the server refused in the server\'s sentence and a row\'s mark that never arrived in the shell\'s, each in an error toast, the question closed and every row left listed with its marks live', () => {
        inertia.props = propsWith({ to_mark: toMark() });
        renderHome();

        const said = () => [...document.body.querySelectorAll('.admin-toast[data-kind="error"] p')].map((line) => line.textContent);

        press(view.host.querySelector('.admin-home-to-mark__mark-rest'));
        press(q('.admin-home-to-mark-confirm__confirm'));

        const refused = lastPost()[2];
        const refusal = new CustomEvent('invalid', { cancelable: true, detail: { response: { status: 400, data: { code: 'invalid_request', data: { detail: 'mark-refusal-token' } } } } });

        act(() => refused.onStart?.());
        act(() => inertia.listeners.get('invalid')?.(refusal));
        act(() => refused.onFinish?.());

        expect(refusal.defaultPrevented).toBe(true);
        expect(said()).toEqual(['mark-refusal-token']);
        expect(q('.admin-home-to-mark-confirm')).toBeNull();
        expect(inertia.listeners.size).toBe(0);

        press(rowPart('t1', 'no-show'));

        const lost = lastPost()[2];
        const offline = new CustomEvent('exception', { cancelable: true, detail: { exception: new Error('offline') } });

        act(() => lost.onStart?.());
        act(() => inertia.listeners.get('exception')?.(offline));
        act(() => lost.onFinish?.());

        expect(offline.defaultPrevented).toBe(true);
        expect(said()).toEqual(['mark-refusal-token', keyedShell().text(NOT_DONE)]);
        expect(view.host.querySelectorAll('.admin-home-to-mark__row')).toHaveLength(4);
        expect(rowPart('t1', 'no-show')?.getAttribute('aria-busy')).toBeNull();

        press(rowPart('t1', 'no-show'));
        expect(inertia.post).toHaveBeenCalledTimes(3);
    });

    it('leaves the focus to the bulk button that asked where it is still there, and hands it to the card where the mark took that button away', async () => {
        inertia.props = propsWith({ to_mark: toMark() });
        renderHome();

        const pressFocused = (name: string): HTMLElement | null => {
            const button = view.host.querySelector<HTMLElement>(`.admin-home-to-mark__${name}`);

            button?.focus();
            press(button);

            return button;
        };

        const rest = pressFocused('mark-rest');

        press(q('.admin-home-to-mark-confirm__keep'));
        await settle();

        expect(document.activeElement).toBe(rest);

        press(dayTick('2026-11-02'));
        pressFocused('mark-completed');
        press(q('.admin-home-to-mark-confirm__confirm'));

        const [, , visit] = lastPost();

        act(() => visit.onStart?.());

        // The redirect back lands Home with today's two gone, and the selection's buttons with them.
        inertia.props = propsWith({ to_mark: toMark({ count: 2, days: toMark().days.slice(1) }) });
        renderHome();
        act(() => visit.onFinish?.());
        await settle();

        expect(q('.admin-home-to-mark-confirm')).toBeNull();
        expect(view.host.querySelector('.admin-home-to-mark__mark-completed')).toBeNull();
        expect(document.activeElement).toBe(toMarkCard());
    });

    it('gives a row the classes that stack it below the breakpoint, its two marks side by side under its text, and the bulk buttons the ones that take the full width and let a long label wrap inside its button, the one breakpoint\'s variants alone', () => {
        inertia.props = propsWith({ to_mark: toMark() });
        renderHome();
        press(rowPart('t1', 'tick'));

        const classesOf = (element: Element | null | undefined): string[] => [...(element?.classList ?? [])];
        const row = view.host.querySelector('.admin-home-to-mark__row[data-booking="t1"]');

        expect(classesOf(row)).toContain('max-desktop:flex-wrap');
        expect(classesOf(row?.querySelector('.admin-home-to-mark__marks'))).toContain('max-desktop:w-full');
        expect(classesOf(rowPart('t1', 'complete'))).toContain('max-desktop:flex-1');
        expect(classesOf(rowPart('t1', 'no-show'))).toContain('max-desktop:flex-1');
        expect(classesOf(view.host.querySelector('.admin-home-to-mark__bulk'))).toEqual(expect.arrayContaining(['max-desktop:flex-col', 'max-desktop:items-stretch']));
        ['mark-completed', 'mark-no-show', 'mark-rest'].forEach((name) => expect(classesOf(view.host.querySelector(`.admin-home-to-mark__${name}`))).toEqual(expect.arrayContaining(['max-desktop:w-full', 'max-desktop:h-auto', 'max-desktop:min-h-11', 'max-desktop:max-w-full', 'max-desktop:whitespace-normal'])));
    });
});
