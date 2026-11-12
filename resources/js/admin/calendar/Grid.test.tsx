import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { type Language, localeOf } from '../../booking/language';
import { key, mount, type Mounted, press, settle } from '@/components/ui/harness';
import { keyedCalendar, keyedShell, keyedShifts, WithCalendar } from '@/testing/keyed';
import { Legend } from './Chrome';
import { CLOSURE_HERE, type ColumnActions, ColumnHead, EDIT_THIS_DAYS_HOURS, NEW_BOOKING_HERE, OPEN_STAFF_PAGE } from './ColumnHead';
import { Grid } from './Grid';
import { type Block, categoryHueOf, type Closure, type DayHours, type HoursWindow, type Colouring, type DayColumn, fillOf, GAP_TITLE, NO_CATEGORY, NEXT_WORKING_THIS_DAY, NO_HOURS_AHEAD, NOT_WORKING_THIS_DAY, OTHER_BOOKINGS, type StatusCount, statusHueOf, swatchesOf } from './model';
import { hueOf, NEUTRAL } from '@/lib/hue';

/*
 * The grid as rendered, proven against the DOM and the computed styles of the real time grid
 * rather than the library's options: a block per item with its own id, the fill mixed and never
 * alpha, the gap hatched inside a dashed border, the same member the same hue, the neutral grey
 * where the staff choice is not real, the two badges and the time range by their rules, the axis
 * at the granularity, the columns from the week start, and the scroll the wrapper carries.
 * Month, over the real day grid: six weeks from the week start, each day's cell the press,
 * holding a compact chip per status present with its count and no block and no control, a
 * pointer or a key on it handing the day to the screen. The column heads name each weekday by
 * ICU in the page's language, held to Intl's own output; each chip reads its status's whole
 * counted line and the payment badge the kit's word, through the keyed table; the calendar's own
 * words and a closure's read through the keyed calendar and shifts sections the grid sits in.
 */

const shell = keyedShell();

/** The calendar section and the shifts section beside it, keyed, which the grid and its heads read. */
const cal = keyedCalendar();

const shifts = keyedShifts();

/** Intl's short name of the weekday of a UTC date in a language. */
function intlWeekday(date: string, language: Language = 'en'): string {
    return new Intl.DateTimeFormat(localeOf(language), { weekday: 'short', timeZone: 'UTC' }).format(new Date(`${date}T00:00:00Z`));
}

/** The week of 2026-11-02, Monday to Sunday. */
const WEEK = ['2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05', '2026-11-06', '2026-11-07', '2026-11-08'];

const CLOCK = { timezone: 'Europe/Athens', dateFormat: 'd/m/Y', timeFormat: 'H:i' };

function block(overrides: Partial<Block>): Block {
    return { id: 'b1:0', kind: 'booking', uuid: 'b1', staffId: 2, start: '2026-11-03T10:00:00', end: '2026-11-03T11:00:00', minutes: 60, title: 'Ada North, Haircut', badge: null, category: null, status: 'confirmed', segment: 0, movable: false, ...overrides };
}

const ELENA = block({});
const NIKOS = block({ id: 'b2:0', uuid: 'b2', staffId: 4, start: '2026-11-04T10:00:00', end: '2026-11-04T10:30:00', minutes: 30, title: 'Bo South, Beard trim' });
const GAP = block({ id: 'b2:0:gap', kind: 'gap', uuid: 'b2', staffId: 4, start: '2026-11-04T10:30:00', end: '2026-11-04T11:00:00', minutes: 30, title: cal.text(GAP_TITLE) });
const REQUEST = block({ id: 'b3:0', uuid: 'b3', start: '2026-11-05T14:00:00', end: '2026-11-05T14:45:00', minutes: 45, title: 'Rita Request, Bridal trial', badge: 'awaiting_approval' });
const UNPAID = block({ id: 'b4:0', uuid: 'b4', staffId: 4, start: '2026-11-06T09:00:00', end: '2026-11-06T09:30:00', minutes: 30, title: 'Una Paid, Haircut', badge: 'awaiting_payment' });

/** Colour by Staff member on a tenant where the staff choice is real: today's rule. */
const STAFF: Colouring = { by: 'staff', palette: true, categories: [], own: [] };

let view: Mounted;

/** A render inside the keyed calendar and shifts sections, as the Calendar provides them. */
function show(node: ReactNode) {
    view.render(<WithCalendar>{node}</WithCalendar>);
}

/** A palette hex as the DOM spells a colour it parsed. */
function rgb(hex: string): string {
    return `rgb(${[1, 3, 5].map((at) => parseInt(hex.slice(at, at + 2), 16)).join(', ')})`;
}

function eventOf(id: string): HTMLElement {
    const element = view.host.querySelector<HTMLElement>(`.fc-event[data-block-id="${id}"]`) ?? [...view.host.querySelectorAll<HTMLElement>('.fc-event')].find((el) => el.dataset['blockId'] === id) ?? null;

    if (element === null) {
        throw new Error(`No block ${id} on the grid.`);
    }

    return element;
}

function render(props: Partial<Parameters<typeof Grid>[0]> = {}) {
    show(<Grid view="week" date="2026-11-03" blocks={[ELENA, NIKOS, GAP, REQUEST, UNPAID]} colouring={STAFF} granularity={15} weekStart={1} clock={CLOCK} onBlock={() => undefined} {...props} />);
}

beforeEach(() => {
    view = mount();
});

afterEach(() => view.unmount());

describe('the grid', () => {
    it('draws a block per item with its own id, and a gap beside its parent', () => {
        render();

        const ids = [...view.host.querySelectorAll<HTMLElement>('.fc-event')].map((el) => el.dataset['blockId']);

        expect(ids).toEqual(expect.arrayContaining(['b1:0', 'b2:0', 'b2:0:gap', 'b3:0', 'b4:0']));
        expect(new Set(ids).size).toBe(ids.length);
        expect(eventOf('b2:0:gap').classList.contains('admin-calendar-gap')).toBe(true);
        expect(eventOf('b2:0').classList.contains('admin-calendar-block')).toBe(true);
    });

    it('fills a block with the hue mixed toward the surface and never an alpha, borders it in the full hue on the left alone, three pixels, and hatches a gap in the hue inside a dashed border', () => {
        render();

        const elena = getComputedStyle(eventOf('b1:0'));
        const nikos = getComputedStyle(eventOf('b2:0'));
        const gap = getComputedStyle(eventOf('b2:0:gap'));

        expect(elena.backgroundColor).toBe(fillOf(hueOf(2, [])));
        expect(elena.backgroundColor).toContain('color-mix(');
        expect(elena.backgroundColor).not.toMatch(/rgba|opacity/);
        expect(eventOf('b1:0').style.borderLeft).toContain(rgb(hueOf(2, [])));
        expect(eventOf('b1:0').style.borderLeft).toContain('3px');
        expect(elena.color).toBe('var(--foreground)');
        expect(nikos.backgroundColor).toBe(fillOf(hueOf(4, [])));
        expect(nikos.backgroundColor).not.toBe(elena.backgroundColor);
        expect(['transparent', 'rgba(0, 0, 0, 0)']).toContain(gap.backgroundColor);
        expect(gap.backgroundImage).toContain('repeating-linear-gradient');
        expect(gap.backgroundImage).toContain(rgb(hueOf(4, [])));
        expect(eventOf('b2:0:gap').style.border).toContain('dashed');
        expect(eventOf('b2:0:gap').style.border).toContain(rgb(hueOf(4, [])));
        expect(eventOf('b2:0:gap').style.border).toContain('1px');
        expect(eventOf('b2:0:gap').style.backgroundImage).toContain('135deg');
        expect(eventOf('b2:0:gap').style.backgroundImage).toContain('40%');
        expect(eventOf('b1:0').style.borderTopWidth).toBe('0px');
        expect(eventOf('b1:0').style.borderRightWidth).toBe('0px');
    });

    it('wears one neutral grey on every block where the staff choice is not real', () => {
        render({ colouring: { ...STAFF, palette: false } });

        expect(getComputedStyle(eventOf('b1:0')).backgroundColor).toBe(fillOf(NEUTRAL));
        expect(getComputedStyle(eventOf('b2:0')).backgroundColor).toBe(fillOf(NEUTRAL));
        expect(eventOf('b2:0:gap').style.border).toContain(rgb(NEUTRAL));
    });

    it('badges a booking awaiting approval Pending and one awaiting payment in its chip\'s word before the title, and no other, and shows the time range on a sixty-minute block and not a thirty-minute one', () => {
        render();

        const badges = (id: string) => [...eventOf(id).querySelectorAll('.admin-calendar-badge')].map((badge) => badge.textContent);

        expect(badges('b3:0')).toEqual([cal.text('badges.pending')]);
        expect(badges('b4:0')).toEqual([shell.text('kit.statuses.awaiting_payment')]);
        expect(eventOf('b4:0').querySelector('.admin-calendar-block__title')?.textContent).toBe(`${shell.text('kit.statuses.awaiting_payment')}Una Paid, Haircut`);
        expect(eventOf('b4:0').querySelector('.admin-calendar-block__title')?.firstElementChild?.classList.contains('admin-calendar-badge--payment')).toBe(true);
        expect(eventOf('b3:0').querySelector('.admin-calendar-badge')?.classList.contains('admin-calendar-badge--approval')).toBe(true);
        expect(eventOf('b4:0').classList.contains('admin-calendar-block--awaiting_payment')).toBe(true);
        expect(badges('b1:0')).toEqual([]);
        expect(badges('b2:0')).toEqual([]);
        expect(badges('b2:0:gap')).toEqual([]);
        expect(eventOf('b1:0').querySelector('.admin-calendar-block__title')?.textContent).toBe('Ada North, Haircut');
        expect(eventOf('b1:0').querySelector('.admin-calendar-block__time')?.textContent).toBe('10:00-11:00');
        expect(eventOf('b3:0').querySelector('.admin-calendar-block__time')?.textContent).toBe('14:00-14:45');
        expect(eventOf('b2:0').querySelector('.admin-calendar-block__time')).toBeNull();
    });

    it('steps the slots at the granularity, labels the axis on the hour alone in the tenant time format, lays the week from the tenant week start, and shows one column in Day', () => {
        render({ granularity: 15 });

        const slots = [...view.host.querySelectorAll('.fc-timegrid-slot-lane[data-time]')].map((el) => el.getAttribute('data-time'));
        const labels = [...view.host.querySelectorAll('.fc-timegrid-slot-label-cushion')].map((el) => el.textContent);

        expect(slots.slice(0, 3)).toEqual(['00:00:00', '00:15:00', '00:30:00']);
        expect(slots).toHaveLength(96);
        expect(labels.filter((label) => label !== '')).toHaveLength(24);
        expect(labels.filter((label) => label !== '').slice(0, 2)).toEqual(['00:00', '01:00']);

        render({ granularity: 30 });

        const headers = [...view.host.querySelectorAll('.fc-col-header-cell-cushion')].map((el) => el.textContent);

        expect([...view.host.querySelectorAll('.fc-timegrid-slot-lane[data-time]')]).toHaveLength(48);
        expect(headers).toEqual(WEEK.map((day) => `${intlWeekday(day)} ${day.slice(8)}/11/2026`));

        render({ granularity: 30, view: 'day' });

        expect([...view.host.querySelectorAll('.fc-col-header-cell-cushion')].map((el) => el.textContent)).toEqual([`${intlWeekday('2026-11-03')} 03/11/2026`]);
        expect(view.host.querySelectorAll('.fc-event')).toHaveLength(1);

        render({ granularity: 30, view: 'week', weekStart: 7 });

        expect([...view.host.querySelectorAll('.fc-col-header-cell-cushion')].map((el) => el.textContent)[0]).toBe(`${intlWeekday('2026-11-01')} 01/11/2026`);
    });

    it('names the column heads\' weekdays by ICU in the page\'s language, the dates in the tenant format whatever the language', () => {
        view.unmount();
        view = mount('el');
        render();

        expect([...view.host.querySelectorAll('.fc-col-header-cell-cushion')].map((el) => el.textContent)).toEqual(WEEK.map((day) => `${intlWeekday(day, 'el')} ${day.slice(8)}/11/2026`));
        expect(intlWeekday('2026-11-02', 'el')).not.toBe(intlWeekday('2026-11-02', 'en'));
    });

    it('hands the library the page\'s language, so the labels it writes for assistive technology on Week\'s column heads and Month\'s weekday heads are Intl\'s in that language, and English\'s as before at en', () => {
        /** Intl's form of a UTC date in a language's code alone, the locale the grid hands the library. */
        const intlIn = (date: string, language: Language, options: Intl.DateTimeFormatOptions) => new Intl.DateTimeFormat(language, { ...options, timeZone: 'UTC' }).format(new Date(`${date}T00:00:00Z`));
        const longDate = { year: 'numeric', month: 'long', day: 'numeric' } as const;
        const labels = () => [...view.host.querySelectorAll('.fc-col-header-cell-cushion')].map((el) => el.getAttribute('aria-label'));

        view.unmount();
        view = mount('el');
        render();

        expect(labels()).toEqual(WEEK.map((day) => intlIn(day, 'el', longDate)));
        expect(labels()[0]).not.toBe(intlIn(WEEK[0] ?? '', 'en', longDate));

        render({ view: 'month' });

        expect(labels()).toEqual(WEEK.map((day) => intlIn(day, 'el', { weekday: 'long' })));
        expect(labels()[0]).not.toBe(intlIn(WEEK[0] ?? '', 'en', { weekday: 'long' }));

        view.unmount();
        view = mount('en');
        render();

        expect(labels()).toEqual(WEEK.map((day) => intlIn(day, 'en', longDate)));

        render({ view: 'month' });

        expect(labels()).toEqual(WEEK.map((day) => intlIn(day, 'en', { weekday: 'long' })));
    });

    it('bounds Week to the span the screen hands, every block of the week inside it, and keeps the scroll it carries', () => {
        render({ granularity: 30, span: { min: '08:00:00', max: '21:00:00' } });

        const lanes = [...view.host.querySelectorAll('.fc-timegrid-slot-lane[data-time]')].map((el) => el.getAttribute('data-time'));

        expect(lanes[0]).toBe('08:00:00');
        expect(lanes.at(-1)).toBe('20:30:00');
        expect(lanes).toHaveLength(26);
        expect(view.host.querySelector('.admin-calendar-grid')?.getAttribute('data-scroll-time')).toBe('09:00:00');

        render({ granularity: 30 });
        expect(view.host.querySelectorAll('.fc-timegrid-slot-lane[data-time]')).toHaveLength(48);
    });

    it('draws an hour sixty pixels tall whatever the step: each slot\'s height a variable on the grid, in Week, Day and Day\'s columns alike', () => {
        const height = () => view.host.querySelector<HTMLElement>('.admin-calendar-grid')?.style.getPropertyValue('--admin-calendar-slot-height');

        render({ granularity: 15 });
        expect(height()).toBe('15px');

        render({ granularity: 30, view: 'day' });
        expect(height()).toBe('30px');

        render({ granularity: 5, view: 'day', columns: [{ key: 'member-2', member: { id: 2, name: 'Elena Papadaki' }, hours: null, next: undefined, blocks: [] }] });
        expect(view.host.querySelector('.admin-calendar-columns')).not.toBeNull();
        expect(height()).toBe('5px');
    });

    it('carries the scroll one hour before the day shown\'s earliest block, clamped at midnight, and the morning hour on an empty day', () => {
        render();
        expect(view.host.querySelector('.admin-calendar-grid')?.getAttribute('data-scroll-time')).toBe('09:00:00');

        render({ blocks: [block({ start: '2026-11-03T00:30:00', end: '2026-11-03T01:00:00' })] });
        expect(view.host.querySelector('.admin-calendar-grid')?.getAttribute('data-scroll-time')).toBe('00:00:00');

        render({ blocks: [NIKOS] });
        expect(view.host.querySelector('.admin-calendar-grid')?.getAttribute('data-scroll-time')).toBe('08:00:00');
    });

    it('draws Month as six weeks from the tenant week start under the weekdays alone, each day\'s cell one tab stop named by its day and its chips, a compact chip per status present with its count, nothing on an empty day, no control in the cell, and no block', () => {
        const counts = new Map<string, StatusCount[]>([
            [
                '2026-11-03',
                [
                    { status: 'confirmed', count: 2 },
                    { status: 'awaiting_approval', count: 1 },
                ],
            ],
            ['2026-12-06', [{ status: 'cancelled', count: 1 }]],
        ]);
        render({ view: 'month', counts, onDay: () => undefined });

        const days = [...view.host.querySelectorAll<HTMLElement>('.admin-calendar-day')];
        const tuesday = days.find((day) => day.dataset['date'] === '2026-11-03');
        const chips = [...(tuesday?.querySelectorAll<HTMLElement>('.admin-chip') ?? [])];

        expect(days.map((day) => day.dataset['date'])).toHaveLength(42);
        expect(days[0]?.dataset['date']).toBe('2026-10-26');
        expect(days[41]?.dataset['date']).toBe('2026-12-06');
        expect(days.every((day) => day.tagName === 'TD' && day.getAttribute('role') === 'gridcell' && day.tabIndex === 0)).toBe(true);
        expect(view.host.querySelectorAll('.fc-daygrid-day button, .fc-daygrid-day [tabindex]')).toHaveLength(0);
        expect(tuesday?.querySelector('a.fc-daygrid-day-number')?.hasAttribute('href')).toBe(false);
        const label = document.getElementById(tuesday?.getAttribute('aria-labelledby') ?? '');
        expect(label?.classList.contains('fc-daygrid-day-number')).toBe(true);
        expect(label?.textContent).toBe(`03/11/20263${shell.choice('kit.counted.confirmed', 2)}${shell.choice('kit.counted.awaiting_approval', 1)}`);
        expect([...view.host.querySelectorAll('.fc-col-header-cell-cushion')].map((el) => el.textContent)).toEqual(WEEK.map((day) => intlWeekday(day)));
        expect(chips.map((chip) => [chip.dataset['status'], chip.textContent, chip.classList.contains('admin-chip--compact')])).toEqual([
            ['confirmed', shell.choice('kit.counted.confirmed', 2), true],
            ['awaiting_approval', shell.choice('kit.counted.awaiting_approval', 1), true],
        ]);
        expect(chips[0]?.classList.contains('admin-chip--live')).toBe(true);
        expect(chips[1]?.classList.contains('admin-chip--attention')).toBe(true);
        expect(tuesday?.querySelector('.admin-calendar-day__number')?.textContent).toBe('3');
        expect(tuesday?.textContent).toContain('03/11/2026');
        expect(days[41]?.querySelector('.admin-chip--void')?.textContent).toBe(shell.choice('kit.counted.cancelled', 1));
        expect(days.find((day) => day.dataset['date'] === '2026-11-04')?.querySelectorAll('.admin-chip')).toHaveLength(0);
        expect(days[0]?.classList.contains('admin-calendar-day--other')).toBe(true);
        expect(tuesday?.classList.contains('admin-calendar-day--other')).toBe(false);
        expect(view.host.querySelectorAll('.fc-event')).toHaveLength(0);

        render({ view: 'month', weekStart: 7, counts, onDay: () => undefined });

        expect(view.host.querySelector<HTMLElement>('.admin-calendar-day')?.dataset['date']).toBe('2026-11-01');
        expect(view.host.querySelector('.fc-col-header-cell-cushion')?.textContent).toBe(intlWeekday('2026-11-01'));

        render({ view: 'week', counts, onDay: () => undefined });

        expect(view.host.querySelectorAll('.admin-calendar-day')).toHaveLength(0);
        expect(view.host.querySelectorAll('.fc-event').length).toBeGreaterThan(0);
    });

    it('hands a press anywhere in a Month day\'s cell, and Enter or Space on the cell, to the screen with that day, and nothing else', () => {
        const onDay = vi.fn();
        render({ view: 'month', counts: new Map([['2026-11-03', [{ status: 'confirmed', count: 1 }]]]), onDay });

        const cell = (date: string) => view.host.querySelector(`.admin-calendar-day[data-date="${date}"]`);

        press(cell('2026-11-17'));
        press(cell('2026-11-03')?.querySelector('.admin-chip') ?? null);
        press(cell('2026-12-01')?.querySelector('.admin-calendar-day__number') ?? null);
        key(cell('2026-11-20'), 'Enter');
        key(cell('2026-11-21'), ' ');
        key(cell('2026-11-22'), 'a');
        key(cell('2026-11-23')?.querySelector('.admin-calendar-day__number') ?? null, 'Enter');
        press(view.host.querySelector('.fc-col-header-cell'));

        expect(onDay.mock.calls).toEqual([['2026-11-17'], ['2026-11-03'], ['2026-12-01'], ['2026-11-20'], ['2026-11-21']]);
    });

    it('hands a click on a block to the screen with the booking, a gap naming its parent', () => {
        const onBlock = vi.fn();
        render({ onBlock });

        press(eventOf('b1:0'));
        press(eventOf('b2:0:gap'));

        expect(onBlock.mock.calls).toEqual([['b1'], ['b2']]);
    });
});

describe('dragging a booking', () => {
    const MOVABLE = { ...ELENA, movable: true };
    const MOVABLE_GAP = { ...GAP, movable: true };

    it('lets a block drag in Week exactly where the screen takes a drop and the server says the booking is movable, never a gap, and resizes nothing', () => {
        render({ blocks: [MOVABLE, NIKOS, MOVABLE_GAP], onDrop: () => undefined });

        expect(eventOf('b1:0').classList.contains('fc-event-draggable')).toBe(true);
        expect(eventOf('b2:0').classList.contains('fc-event-draggable')).toBe(false);
        expect(eventOf('b2:0:gap').classList.contains('fc-event-draggable')).toBe(false);
        expect(view.host.querySelectorAll('.fc-event-resizable')).toHaveLength(0);
    });

    it('lets nothing drag where the screen takes no drop, nor in Month', () => {
        render({ blocks: [MOVABLE] });
        expect(view.host.querySelectorAll('.fc-event-draggable')).toHaveLength(0);

        render({ view: 'month', blocks: [MOVABLE], onDrop: () => undefined });
        expect(view.host.querySelectorAll('.fc-event')).toHaveLength(0);
    });

    it('lets a movable block drag in Day as the one time grid', () => {
        render({ view: 'day', blocks: [MOVABLE], onDrop: () => undefined });

        expect(eventOf('b1:0').classList.contains('fc-event-draggable')).toBe(true);
    });
});

describe('Colour by on the grid', () => {
    const HAIR = block({ category: 'Hair' });
    const COLOURING = block({ id: 'b2:0', uuid: 'b2', staffId: 4, start: '2026-11-04T10:00:00', end: '2026-11-04T11:00:00', category: 'Colouring', status: 'awaiting_approval' });
    const PLAIN = block({ id: 'b3:0', uuid: 'b3', staffId: 2, start: '2026-11-05T10:00:00', end: '2026-11-05T11:00:00', category: null, status: 'awaiting_payment' });
    const CATEGORIES = ['Colouring', 'Hair'];
    const hueOn = (id: string) => eventOf(id).style.getPropertyValue('--admin-block-hue');

    it('colours each block by its member under Staff member, the same member the same hue', () => {
        render({ blocks: [HAIR, COLOURING, PLAIN], colouring: { by: 'staff', palette: true, categories: CATEGORIES, own: [] } });

        expect([hueOn('b1:0'), hueOn('b2:0'), hueOn('b3:0')]).toEqual([hueOf(2, []), hueOf(4, []), hueOf(2, [])]);
    });

    it('colours each block by its service category under Service category, its place in the catalogue\'s sorted categories, a blank one grey, whoever performs it', () => {
        render({ blocks: [HAIR, COLOURING, PLAIN], colouring: { by: 'category', palette: false, categories: CATEGORIES, own: [] } });

        expect(hueOn('b1:0')).toBe(hueOf(1, []));
        expect(hueOn('b2:0')).toBe(hueOf(0, []));
        expect(hueOn('b3:0')).toBe(NEUTRAL);
        expect(getComputedStyle(eventOf('b1:0')).backgroundColor).toBe(fillOf(categoryHueOf('Hair', CATEGORIES)));
    });

    it('colours each block by its status under Status, the chips\' tones, and keeps the grey nowhere a status is known', () => {
        render({ blocks: [HAIR, COLOURING, PLAIN], colouring: { by: 'status', palette: true, categories: CATEGORIES, own: [] } });

        expect(hueOn('b1:0')).toBe(statusHueOf('confirmed'));
        expect(hueOn('b2:0')).toBe(statusHueOf('awaiting_approval'));
        expect(hueOn('b3:0')).toBe(statusHueOf('awaiting_payment'));
        expect(new Set([hueOn('b1:0'), hueOn('b2:0'), hueOn('b3:0')]).size).toBe(3);
        expect(eventOf('b1:0').style.borderLeft).toContain(statusHueOf('confirmed'));
    });

    it('keeps the Staff choice grey where the staff choice is not real, the other two coloured', () => {
        render({ blocks: [HAIR, COLOURING], colouring: { by: 'staff', palette: false, categories: CATEGORIES, own: [] } });
        expect([hueOn('b1:0'), hueOn('b2:0')]).toEqual([NEUTRAL, NEUTRAL]);

        render({ blocks: [HAIR, COLOURING], colouring: { by: 'status', palette: false, categories: CATEGORIES, own: [] } });
        expect([hueOn('b1:0'), hueOn('b2:0')]).toEqual([statusHueOf('confirmed'), statusHueOf('awaiting_approval')]);
    });

    describe('the legend following the choice', () => {
        const members = [
            { id: 2, name: 'Elena Papadaki' },
            { id: 4, name: 'Nikos Georgiou' },
        ];
        const name = (status: string) => `status ${status}`;
        const legend = (colouring: Colouring) => {
            show(<Legend by={colouring.by} swatches={swatchesOf(colouring, members, [HAIR, COLOURING, PLAIN], name, cal)} />);

            return [...view.host.querySelectorAll<HTMLElement>('.admin-calendar-legend__member')].map((item) => [item.textContent, item.querySelector<HTMLElement>('.admin-calendar-legend__swatch')?.style.backgroundColor]);
        };

        it('lists the members shown under Staff member', () => {
            expect(legend({ by: 'staff', palette: true, categories: CATEGORIES, own: [] })).toEqual([
                ['Elena Papadaki', rgb(hueOf(2, []))],
                ['Nikos Georgiou', rgb(hueOf(4, []))],
            ]);
            expect(view.host.querySelector('.admin-calendar-legend ul')?.getAttribute('aria-label')).toBe(cal.text('legend.staff'));
        });

        it('lists the categories drawn, sorted, the blank bucket last, under Service category', () => {
            expect(legend({ by: 'category', palette: true, categories: CATEGORIES, own: [] })).toEqual([
                ['Colouring', rgb(hueOf(0, []))],
                ['Hair', rgb(hueOf(1, []))],
                [cal.text(NO_CATEGORY), rgb(NEUTRAL)],
            ]);
        });

        it('lists the statuses drawn in the filter\'s order under Status, the badges after them', () => {
            expect(legend({ by: 'status', palette: true, categories: CATEGORIES, own: [] })).toEqual([
                ['status awaiting_approval', statusHueOf('awaiting_approval')],
                ['status awaiting_payment', statusHueOf('awaiting_payment')],
                ['status confirmed', statusHueOf('confirmed')],
            ]);
            expect(view.host.querySelectorAll('.admin-calendar-legend__badge')).toHaveLength(2);
        });

        it('draws the gap\'s entry after the swatches, Closure and Outside the member\'s hours in Day, and the hint on an empty slot last where a slot starts a booking', () => {
            show(<Legend by="staff" swatches={swatchesOf(STAFF, members, [], name, cal)} day={true} hint={true} />);

            const entries = [...view.host.querySelectorAll<HTMLElement>('.admin-calendar-legend li')].map((item) => item.className.match(/admin-calendar-legend__[a-z]+/)?.[0]);

            expect(entries).toEqual(['member', 'member', 'badge', 'badge', 'gap', 'closure', 'outside'].map((entry) => `admin-calendar-legend__${entry}`));
            expect(view.host.querySelector('.admin-calendar-legend__gap')?.textContent).toBe(cal.text('legend.gap'));
            expect(view.host.querySelector('.admin-calendar-legend__gap .admin-calendar-gap-swatch')).not.toBeNull();
            expect(view.host.querySelector('.admin-calendar-legend')?.lastElementChild?.classList.contains('admin-calendar-legend__hint')).toBe(true);
            expect(view.host.querySelector('.admin-calendar-legend__hint')?.textContent).toBe(cal.text('legend.hint'));

            show(<Legend by="staff" swatches={swatchesOf(STAFF, members, [], name, cal)} />);

            expect(view.host.querySelector('.admin-calendar-legend__gap')).not.toBeNull();
            expect(view.host.querySelector('.admin-calendar-legend__closure')).toBeNull();
            expect(view.host.querySelector('.admin-calendar-legend__hint')).toBeNull();
        });
    });
});

describe('Day as member columns', () => {
    const DAY = '2026-11-03';
    const ELENA_ROW = { id: 2, name: 'Elena Papadaki' };
    const NIKOS_ROW = { id: 4, name: 'Nikos Georgiou' };
    const MARIA_ROW = { id: 6, name: 'Maria Lambrou' };
    const SPLIT = [
        { start: '09:00', end: '13:00' },
        { start: '14:00', end: '18:00' },
    ];
    const DAY_HOURS = (windows: HoursWindow[], closures: Closure[] = []): DayHours => ({ windows, closures, source: 'pattern', pattern: windows });
    const ELENA_DAY = block({ id: 'e:0', uuid: 'e', staffId: 2, start: `${DAY}T10:00:00`, end: `${DAY}T11:00:00` });
    const NIKOS_DAY = block({ id: 'n:0', uuid: 'n', staffId: 4, start: `${DAY}T12:00:00`, end: `${DAY}T12:30:00`, minutes: 30, title: 'Bo South, Beard trim' });
    const OTHER_DAY = block({ id: 'o:0', uuid: 'o', staffId: 1, start: `${DAY}T07:00:00`, end: `${DAY}T07:45:00`, minutes: 45, title: 'Ivy Implicit, Haircut' });

    /*
     * The library places its fills, the hatch of non-business time and the background events, by
     * the slats' measured coordinates, and measures them only on an element that is not hidden,
     * one whose offsetHeight is not zero (timegrid's TimeColsSlats.updateSizing). jsdom lays out
     * nothing, so every element is given a height here; every coordinate still reads zero, which
     * is all a fill's presence needs. The now line, which needs real coordinates, stays unproven.
     */
    const offsetHeight = Object.getOwnPropertyDescriptor(HTMLElement.prototype, 'offsetHeight');

    beforeEach(() => {
        Object.defineProperty(HTMLElement.prototype, 'offsetHeight', { configurable: true, get: () => 10 });
    });

    afterEach(() => {
        if (offsetHeight !== undefined) {
            Object.defineProperty(HTMLElement.prototype, 'offsetHeight', offsetHeight);
        }
    });

    function columnsOf(): DayColumn[] {
        return [
            { key: 'member-2', member: ELENA_ROW, hours: DAY_HOURS(SPLIT, [{ start: '14:00', end: '16:00', reason: 'Dentist', business: false }]), next: undefined, blocks: [ELENA_DAY] },
            { key: 'member-4', member: NIKOS_ROW, hours: DAY_HOURS([{ start: '10:00', end: '21:00' }]), next: undefined, blocks: [NIKOS_DAY] },
            { key: 'member-6', member: MARIA_ROW, hours: DAY_HOURS([]), next: { date: '2026-11-05', windows: [{ start: '10:00', end: '15:00' }] }, blocks: [] },
            { key: 'other', member: null, hours: null, next: undefined, blocks: [OTHER_DAY] },
        ];
    }

    function day(props: Partial<Parameters<typeof Grid>[0]> = {}) {
        render({ view: 'day', date: DAY, blocks: [ELENA_DAY, NIKOS_DAY, OTHER_DAY], columns: columnsOf(), head: (column) => <span className="stub-head">{column.member?.name ?? cal.text(OTHER_BOOKINGS)}</span>, ...props });
    }

    const columns = () => [...view.host.querySelectorAll<HTMLElement>('.admin-calendar-column')];

    const labels = (at: number) => [...(columns()[at]?.querySelectorAll('.fc-timegrid-slot-label-cushion') ?? [])].map((el) => el.textContent);
    const lanes = (at: number) => [...(columns()[at]?.querySelectorAll('.fc-timegrid-slot-lane[data-time]') ?? [])].map((el) => el.getAttribute('data-time'));
    const ids = (at: number) => [...(columns()[at]?.querySelectorAll<HTMLElement>('.fc-event:not(.fc-bg-event)') ?? [])].map((el) => el.dataset['blockId']);
    // The hatch and the background events are fills, which the library draws once its slats are
    // measured.
    const nonBusiness = (at: number) => columns()[at]?.querySelectorAll('.fc-non-business').length ?? 0;
    const closures = (at: number) => [...(columns()[at]?.querySelectorAll('.fc-bg-event.admin-calendar-closure') ?? [])].map((el) => el.textContent);

    it('draws one time-grid instance per column side by side, every one on the one axis from the earliest start to the latest end rounded to the hour, the labels on the first alone', () => {
        day();

        expect(columns().map((column) => column.dataset['column'])).toEqual(['member-2', 'member-4', 'member-6', 'other']);
        expect(view.host.querySelectorAll('.admin-calendar-column .fc')).toHaveLength(4);
        expect(view.host.querySelector('.admin-calendar-columns__row')?.classList.contains('overflow-x-auto')).toBe(true);
        // The first column carries the 56px axis, so its minimum is the others' and the axis's.
        expect(columns()[0]?.classList.contains('min-w-62')).toBe(true);
        expect(columns().slice(1).every((column) => column.classList.contains('min-w-48'))).toBe(true);
        // The other bookings' 07:00 and Nikos's 21:00 widen the axis past 08:00 to 20:00.
        expect(view.host.querySelector('.admin-calendar-columns')?.getAttribute('data-axis')).toBe('07:00:00-21:00:00');

        for (const at of [0, 1, 2, 3]) {
            expect(lanes(at)[0]).toBe('07:00:00');
            expect(lanes(at)).toHaveLength(56);
        }

        expect(labels(0).filter((label) => label !== '').slice(0, 2)).toEqual(['07:00', '08:00']);
        expect([1, 2, 3].flatMap(labels).every((label) => label === '')).toBe(true);
        expect(view.host.querySelectorAll('.admin-calendar-column .fc-col-header')).toHaveLength(0);
        expect(view.host.querySelectorAll('.stub-head')).toHaveLength(4);
        expect([...view.host.querySelectorAll('.stub-head')].map((head) => head.textContent)).toEqual(['Elena Papadaki', 'Nikos Georgiou', 'Maria Lambrou', cal.text(OTHER_BOOKINGS)]);
    });

    it('spans at least 08:00 to 20:00 where the hours and the blocks sit inside them', () => {
        day({ columns: [{ key: 'member-2', member: ELENA_ROW, hours: DAY_HOURS(SPLIT), next: undefined, blocks: [ELENA_DAY] }] });

        expect(view.host.querySelector('.admin-calendar-columns')?.getAttribute('data-axis')).toBe('08:00:00-20:00:00');
        expect(lanes(0)[0]).toBe('08:00:00');
    });

    it('hatches each column outside its member\'s windows of the day, the whole column for a member with none, and nothing on the other bookings\' column', () => {
        day();

        // The axis runs 07:00 to 21:00: Elena's split day leaves 07-09, 13-14 and 18-21 outside,
        // Nikos's 10-21 leaves 07-10.
        expect(nonBusiness(0)).toBe(3);
        expect(nonBusiness(1)).toBe(1);
        // Business hours on another weekday alone: the Tuesday shown is one hatch, top to bottom.
        expect(nonBusiness(2)).toBe(1);
        expect(nonBusiness(3)).toBe(0);
        expect(columns().every((column) => column.querySelector('[data-business-hours], [data-closures]') === null)).toBe(true);
    });

    it('draws each member\'s blocks on their own column as before and the other bookings on the trailing one, with the member\'s closures in their words behind them', () => {
        day();

        expect(ids(0)).toEqual(['e:0']);
        expect(ids(1)).toEqual(['n:0']);
        expect(ids(2)).toEqual([]);
        expect(ids(3)).toEqual(['o:0']);
        expect(getComputedStyle(eventOf('e:0')).backgroundColor).toBe(fillOf(hueOf(2, [])));
        expect(eventOf('o:0').querySelector('.admin-calendar-block__time')?.textContent).toBe('07:00-07:45');
        expect(closures(0)).toEqual([shifts.text('page.closed_reason', { start: '14:00', end: '16:00', reason: 'Dentist' })]);
        expect([1, 2, 3].map(closures)).toEqual([[], [], []]);
    });

    it('draws every case of closure in its own words, the tenant-wide ones on each column they reach', () => {
        const wide: Closure = { start: null, end: null, reason: 'Christmas Day', business: true };
        const [elena, nikos] = columnsOf();

        if (elena === undefined || nikos === undefined) {
            throw new Error('The fixture lost its columns.');
        }

        day({
            columns: [
                { ...elena, hours: DAY_HOURS(SPLIT, [{ start: '14:00', end: '16:00', reason: null, business: false }, wide]) },
                { ...nikos, hours: DAY_HOURS([{ start: '10:00', end: '21:00' }], [{ start: null, end: null, reason: null, business: false }, wide]) },
            ],
        });

        expect(closures(0)).toEqual([shifts.text('page.closed', { start: '14:00', end: '16:00' }), shifts.text('page.closed_all_day_reason', { reason: 'Christmas Day' })]);
        expect(closures(1)).toEqual([shifts.text('page.closed_all_day'), shifts.text('page.closed_all_day_reason', { reason: 'Christmas Day' })]);
    });

    it('moves every column to the date shown on Previous, Next or Today, drawing the new day\'s blocks, hatch and closures', () => {
        day();
        const next = '2026-11-04';
        const moved = block({ id: 'e2:0', uuid: 'e2', staffId: 2, start: `${next}T15:00:00`, end: `${next}T16:00:00` });
        const [elena, nikos, maria] = columnsOf();

        if (elena === undefined || nikos === undefined || maria === undefined) {
            throw new Error('The fixture lost its columns.');
        }

        day({
            date: next,
            blocks: [moved],
            columns: [
                { ...elena, hours: DAY_HOURS([{ start: '12:00', end: '20:00' }], [{ start: '17:00', end: '18:00', reason: 'Training', business: false }]), blocks: [moved] },
                { ...nikos, hours: DAY_HOURS([]), next: null, blocks: [] },
                { ...maria, hours: DAY_HOURS([{ start: '10:00', end: '15:00' }]), blocks: [] },
            ],
        });

        expect(columns().map((column) => column.querySelector<HTMLElement>('.fc-timegrid-col[data-date]')?.dataset['date'])).toEqual([next, next, next]);
        expect(ids(0)).toEqual(['e2:0']);
        expect([1, 2].map(ids)).toEqual([[], []]);
        expect(closures(0)).toEqual([shifts.text('page.closed_reason', { start: '17:00', end: '18:00', reason: 'Training' })]);
        // 08:00-20:00: Elena outside before 12:00 alone, Nikos whole, Maria before 10:00 and after
        // 15:00.
        expect([0, 1, 2].map(nonBusiness)).toEqual([1, 1, 2]);
        expect([...view.host.querySelectorAll('.admin-calendar-column__next')].map((pill) => pill.closest<HTMLElement>('.admin-calendar-column')?.dataset['column'])).toEqual(['member-4']);
    });

    it('draws a column whose date the window does not yet cover with no hatch, no closure and no pill until its hours land', () => {
        const [elena, nikos, maria] = columnsOf();

        if (elena === undefined || nikos === undefined || maria === undefined) {
            throw new Error('The fixture lost its columns.');
        }

        day({ columns: [{ ...elena, hours: null }, { ...nikos, hours: null }, { ...maria, hours: null, next: undefined }] });

        expect([0, 1, 2].map(nonBusiness)).toEqual([0, 0, 0]);
        expect([0, 1, 2].map(closures)).toEqual([[], [], []]);
        expect(view.host.querySelector('.admin-calendar-column__next')).toBeNull();
        expect(columns().map((column) => column.classList.contains('admin-calendar-column--other'))).toEqual([false, false, false]);
    });

    it('carries the pill naming the next working day on a column with no hours, or the eight weeks with none, and on no other column', () => {
        day();

        expect([...view.host.querySelectorAll('.admin-calendar-column__next')].map((pill) => pill.closest<HTMLElement>('.admin-calendar-column')?.dataset['column'])).toEqual(['member-6']);
        expect(view.host.querySelector('.admin-calendar-column__next')?.textContent).toBe(
            cal.text(NEXT_WORKING_THIS_DAY, { weekday: new Intl.DateTimeFormat('en', { weekday: 'long', timeZone: 'UTC' }).format(new Date('2026-11-05T00:00:00Z')), date: '05/11/2026', hours: '10:00-15:00' }),
        );

        day({ columns: columnsOf().map((column) => (column.key === 'member-6' ? { ...column, next: null } : column)) });

        expect(view.host.querySelector('.admin-calendar-column__next')?.textContent).toBe(cal.text(NO_HOURS_AHEAD));
    });

    it('lets a movable booking\'s block drag on every member\'s column and the other bookings\' column where the screen takes a drop, never a gap, a closure or a block the server says cannot move, and resizes nothing', () => {
        const movable = { ...ELENA_DAY, movable: true };
        const other = { ...OTHER_DAY, movable: true };
        const fixed = columnsOf();
        const handed: DayColumn[] = fixed.map((column) => (column.key === 'member-2' ? { ...column, blocks: [movable] } : column.key === 'other' ? { ...column, blocks: [other] } : column));

        day({ blocks: [movable, NIKOS_DAY, other], columns: handed, onDrop: () => undefined });

        expect(eventOf('e:0').classList.contains('fc-event-draggable')).toBe(true);
        expect(eventOf('o:0').classList.contains('fc-event-draggable')).toBe(true);
        expect(eventOf('n:0').classList.contains('fc-event-draggable')).toBe(false);
        expect(view.host.querySelectorAll('.admin-calendar-closure.fc-event-draggable')).toHaveLength(0);
        expect(view.host.querySelectorAll('.fc-event-resizable')).toHaveLength(0);

        day({ blocks: [movable, NIKOS_DAY, other], columns: handed });

        expect(view.host.querySelectorAll('.fc-event-draggable')).toHaveLength(0);
    });

    it('stays the one time grid in Day with no columns handed, and in Week and Month whatever is handed', () => {
        render({ view: 'day', date: DAY, blocks: [ELENA_DAY] });
        expect(view.host.querySelectorAll('.admin-calendar-column')).toHaveLength(0);
        expect(view.host.querySelectorAll('.fc')).toHaveLength(1);

        render({ view: 'day', date: DAY, blocks: [ELENA_DAY], columns: [] });
        expect(view.host.querySelectorAll('.fc')).toHaveLength(1);

        render({ columns: columnsOf() });
        expect(view.host.querySelectorAll('.admin-calendar-column')).toHaveLength(0);
        expect(view.host.querySelectorAll('.fc-col-header-cell-cushion')).toHaveLength(7);
    });
});

describe('a Day column\'s head', () => {
    const ELENA_ROW = { id: 2, name: 'Elena Papadaki' };
    const WORKING: DayColumn = { key: 'member-2', member: ELENA_ROW, hours: { windows: [{ start: '09:00', end: '13:00' }, { start: '14:00', end: '18:00' }], closures: [], source: 'pattern', pattern: [] }, next: undefined, blocks: [] };
    const IDLE: DayColumn = { ...WORKING, hours: { windows: [], closures: [], source: 'none', pattern: [] } };
    const OTHER: DayColumn = { key: 'other', member: null, hours: null, next: undefined, blocks: [] };
    const trigger = () => view.host.querySelector<HTMLElement>('.admin-calendar-head__menu');
    const HUE = '#cc79a7';
    /** The menu's title: the name, then the day as Day's title names it, its weekday first. */
    const TITLE = cal.text('day.menu', { staff: 'Elena Papadaki', date: cal.text('toolbar.day_title', { weekday: new Intl.DateTimeFormat('en', { weekday: 'long', timeZone: 'UTC' }).format(new Date('2026-11-03T00:00:00Z')), date: '03/11/2026' }) });

    function head(column: DayColumn, actions: ColumnActions) {
        show(<ColumnHead column={column} date="2026-11-03" clock={CLOCK} hue={HUE} actions={actions} />);
    }

    async function open() {
        trigger()?.focus();
        key(trigger(), 'Enter');
        await settle();
    }

    afterEach(async () => {
        await settle();
    });

    it('carries the initials ringed in the member\'s hue, the name and the day\'s hours, or Not working this day', () => {
        head(WORKING, {});

        expect(view.host.querySelector('.admin-calendar-head__initials')?.textContent).toBe('EP');
        expect(view.host.querySelector<HTMLElement>('.admin-calendar-head__initials')?.style.boxShadow).toContain(HUE);
        expect(view.host.querySelector('.admin-calendar-head__hours')?.classList.contains('font-mono')).toBe(true);
        expect(view.host.querySelector('.admin-calendar-head__name')?.textContent).toBe('Elena Papadaki');
        expect(view.host.querySelector('.admin-calendar-head__hours')?.textContent).toBe('09:00-13:00, 14:00-18:00');

        head(IDLE, {});
        expect(view.host.querySelector('.admin-calendar-head__hours')?.textContent).toBe(cal.text(NOT_WORKING_THIS_DAY));
    });

    it('heads a member\'s column whose day is not yet read by the initials and the name alone, no hours line', () => {
        head({ ...WORKING, hours: null }, { book: vi.fn() });

        expect(view.host.querySelector('.admin-calendar-head__name')?.textContent).toBe('Elena Papadaki');
        expect(view.host.querySelector('.admin-calendar-head__initials')?.textContent).toBe('EP');
        expect(view.host.querySelector('.admin-calendar-head__hours')).toBeNull();
        expect(view.host.textContent).not.toContain(cal.text(NOT_WORKING_THIS_DAY));
        expect(view.host.textContent).not.toContain(cal.text(OTHER_BOOKINGS));
    });

    it('is itself the menu\'s button, the initials, the name and the hours its face and no button of its own beside them', () => {
        head(WORKING, { book: vi.fn() });

        const button = trigger();

        expect(button?.tagName).toBe('BUTTON');
        expect(button?.classList.contains('admin-calendar-head')).toBe(true);
        expect(button?.getAttribute('aria-haspopup')).toBe('menu');
        expect(button?.getAttribute('aria-expanded')).toBe('false');
        expect(button?.querySelector('.admin-calendar-head__name')?.textContent).toBe('Elena Papadaki');
        expect(button?.querySelector('.admin-calendar-head__hours')).not.toBeNull();
        expect(view.host.querySelectorAll('button')).toHaveLength(1);
    });

    it('opens the menu under the head titled with the name and the day, its weekday first, on the four actions, each wired with the member', async () => {
        const book = vi.fn();
        const close = vi.fn();
        const edit = vi.fn();
        head(WORKING, { book, close, edit, staffPage: (member) => `/admin/staff/${member.id}` });

        await open();

        const menu = document.querySelector<HTMLElement>('.admin-menu');
        expect(trigger()?.getAttribute('aria-expanded')).toBe('true');
        expect(menu?.getAttribute('aria-label')).toBe(TITLE);
        // 240px, never the column's width.
        expect(menu?.className.split(' ')).toEqual(expect.arrayContaining(['w-60', 'min-w-60']));
        expect(menu?.querySelector('.admin-menu__title')?.textContent).toBe(TITLE);
        expect([...document.querySelectorAll('.admin-menu__item')].map((item) => item.textContent)).toEqual([NEW_BOOKING_HERE, CLOSURE_HERE, EDIT_THIS_DAYS_HOURS, OPEN_STAFF_PAGE].map((key) => cal.text(key)));
        expect(document.querySelector('.admin-calendar-column__staff')?.getAttribute('href')).toBe('/admin/staff/2');

        press(document.querySelector('.admin-calendar-column__book'));
        await settle();
        await open();
        press(document.querySelector('.admin-calendar-column__close'));
        await settle();
        await open();
        press(document.querySelector('.admin-calendar-column__edit'));

        expect(book.mock.calls).toEqual([[ELENA_ROW]]);
        expect(close.mock.calls).toEqual([[ELENA_ROW]]);
        expect(edit.mock.calls).toEqual([[ELENA_ROW]]);
    });

    it('leaves out an item its viewer may not take, not disabled, and the button where none is left', async () => {
        head(WORKING, { book: vi.fn(), staffPage: (member) => `/admin/staff/${member.id}` });
        await open();
        expect([...document.querySelectorAll('.admin-menu__item')].map((item) => item.textContent)).toEqual([NEW_BOOKING_HERE, OPEN_STAFF_PAGE].map((key) => cal.text(key)));
        key(document.activeElement, 'Escape');
        await settle();

        head(WORKING, {});
        expect(trigger()).toBeNull();
        expect(view.host.querySelector('.admin-calendar-head__name')?.textContent).toBe('Elena Papadaki');
    });

    it('heads the other bookings\' column by its name alone, with no menu and no hours', () => {
        head(OTHER, { book: vi.fn() });

        expect(view.host.querySelector('.admin-calendar-head__name')?.textContent).toBe(cal.text(OTHER_BOOKINGS));
        expect(trigger()).toBeNull();
        expect(view.host.querySelector('.admin-calendar-head__hours')).toBeNull();
    });
});
