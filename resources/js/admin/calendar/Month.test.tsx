import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { carry, dragEvent, key, mount, type Mounted, onPhone, press, settle } from '@/components/ui/harness';
import { keyedCalendar, keyedShell, WithCalendar } from '@/testing/keyed';
import { badgeMeaning } from './Badge';
import { MORE } from './CappedChips';
import { Legend } from './Chrome';
import { CHIP_OUTLINES, NOTED } from './Chip';
import { NEW_BOOKING, NEW_CLOSURE, OPEN_DAY } from './DateMenu';
import { type Chip, CLOSED_DAY, type Colouring, dayTitleOf } from './model';
import { BOOKING_COUNT, Month } from './Month';

/*
 * Month as rendered: six weeks from the tenant's week start under the weekdays' short names, each
 * day's chips up to two and the line saying how many more, the day's date menu behind them, a
 * closed day hatched and still full, another month's days greyed and full, today's number marked;
 * below the breakpoint a day's number and count, the whole cell the menu. Then the legend under
 * chips, naming their marks. Every word reads through the keyed sections.
 */

const shell = keyedShell();

const cal = keyedCalendar();

const CLOCK = { timezone: 'Europe/Athens', dateFormat: 'd/m/Y', timeFormat: 'H:i' };

const STAFF: Colouring = { by: 'staff', palette: true, categories: [], own: [] };

function chip(uuid: string, start: string, overrides: Partial<Chip> = {}): Chip {
    return { uuid, start, customer: 'Ada North', status: 'confirmed', staffId: 2, category: null, badge: null, noted: false, inside: false, movable: false, segments: [0], ...overrides };
}

const TUESDAY = ['a', 'b', 'c', 'd'].map((uuid, at) => chip(uuid, `2026-11-03T1${at}:00:00`));

const CHIPS = new Map<string, Chip[]>([
    ['2026-11-03', TUESDAY],
    ['2026-11-04', [chip('e', '2026-11-04T09:00:00')]],
    ['2026-12-06', [chip('f', '2026-12-06T12:00:00')]],
]);

/** Intl's short name of the weekday of a UTC date in English. */
function intlWeekday(date: string): string {
    return new Intl.DateTimeFormat('en', { weekday: 'short', timeZone: 'UTC' }).format(new Date(`${date}T00:00:00Z`));
}

/** What a screen reader reads of an element: its text without the parts kept from readers. */
function heard(element: Element | null | undefined): string {
    return element === null || element === undefined ? '' : [...element.childNodes].map((node) => (node instanceof Element ? (node.getAttribute('aria-hidden') === 'true' ? '' : heard(node)) : (node.textContent ?? ''))).join('');
}

let view: Mounted;

function show(node: ReactNode) {
    view.render(<WithCalendar>{node}</WithCalendar>);
}

function render(props: Partial<Parameters<typeof Month>[0]> = {}) {
    show(<Month date="2026-11-03" weekStart={1} today="2026-11-04" chips={CHIPS} closedDays={[]} colouring={STAFF} clock={CLOCK} onOpen={() => undefined} onDay={() => undefined} onBook={() => undefined} {...props} />);
}

const cell = (date: string) => view.host.querySelector<HTMLElement>(`.admin-calendar-month__day[data-date="${date}"]`);

const control = (date: string) => cell(date)?.querySelector<HTMLElement>('.admin-calendar-date') ?? null;

const title = (date: string) => dayTitleOf(date, CLOCK, cal);

async function openMenu(date: string) {
    control(date)?.focus();
    key(control(date), 'Enter');
    await settle();
}

beforeEach(() => {
    view = mount();
});

afterEach(() => view.unmount());

describe('Month', () => {
    it('draws six weeks from the tenant week start under the weekdays\' short names', () => {
        render();

        const days = [...view.host.querySelectorAll<HTMLElement>('.admin-calendar-month__day')].map((day) => day.dataset['date']);
        expect(days).toHaveLength(42);
        expect([days[0], days[41]]).toEqual(['2026-10-26', '2026-12-06']);
        expect([...view.host.querySelectorAll('.admin-calendar-month__head span')].map((el) => el.textContent)).toEqual(['2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05', '2026-11-06', '2026-11-07', '2026-11-08'].map(intlWeekday));

        render({ weekStart: 7 });

        expect(view.host.querySelector<HTMLElement>('.admin-calendar-month__day')?.dataset['date']).toBe('2026-11-01');
        expect(view.host.querySelector('.admin-calendar-month__head span')?.textContent).toBe(intlWeekday('2026-11-01'));
    });

    it('lists a day\'s chips in their order, the first two and how many more, and nothing on an empty day', () => {
        render();

        expect([...(cell('2026-11-03')?.querySelectorAll<HTMLElement>('.admin-calendar-chip') ?? [])].map((el) => el.dataset['uuid'])).toEqual(['a', 'b']);
        expect(cell('2026-11-03')?.querySelector('.admin-calendar-more')?.textContent).toBe(cal.choice(MORE, 2));
        expect(cell('2026-11-04')?.querySelectorAll('.admin-calendar-chip')).toHaveLength(1);
        expect(cell('2026-11-04')?.querySelector('.admin-calendar-more')).toBeNull();
        expect(cell('2026-11-05')?.querySelectorAll('button')).toHaveLength(1);
    });

    it('opens a chip\'s booking, and the popover of a day\'s line titled by the day', async () => {
        const onOpen = vi.fn();
        render({ onOpen });

        press(cell('2026-11-03')?.querySelector('.admin-calendar-chip[data-uuid="b"]') ?? null);
        press(cell('2026-11-03')?.querySelector('.admin-calendar-more') ?? null);
        await settle();

        const popover = document.querySelector<HTMLElement>('.admin-calendar-popover');
        expect(popover?.querySelector('.admin-calendar-popover__title')?.textContent).toBe(title('2026-11-03'));
        expect(popover?.querySelectorAll('.admin-calendar-chip')).toHaveLength(4);
        press(popover?.querySelector('.admin-calendar-chip[data-uuid="d"]') ?? null);
        await settle();

        expect(onOpen.mock.calls).toEqual([['b'], ['d']]);
    });

    it('makes each day\'s cell one tab stop before its chips, named by the day, its menu offering what the viewer may add and Open day, each wired with the day', async () => {
        const onBook = vi.fn();
        const onClosure = vi.fn();
        const onDay = vi.fn();
        render({ onBook, onClosure, onDay });

        const buttons = [...(cell('2026-11-03')?.querySelectorAll('button') ?? [])];
        expect(buttons[0]).toBe(control('2026-11-03'));
        expect(heard(control('2026-11-03'))).toBe(title('2026-11-03'));

        await openMenu('2026-11-17');
        expect(document.querySelector('.admin-menu__title')?.textContent).toBe(title('2026-11-17'));
        expect([...document.querySelectorAll('.admin-menu__item')].map((item) => item.textContent)).toEqual([NEW_BOOKING, NEW_CLOSURE, OPEN_DAY].map((name) => cal.text(name)));
        press(document.querySelector('.admin-calendar-date__book'));
        await settle();
        await openMenu('2026-12-01');
        press(document.querySelector('.admin-calendar-date__closure'));
        await settle();
        await openMenu('2026-10-26');
        press(document.querySelector('.admin-calendar-date__day'));
        await settle();

        expect([onBook.mock.calls, onClosure.mock.calls, onDay.mock.calls]).toEqual([[['2026-11-17']], [['2026-12-01']], [['2026-10-26']]]);
    });

    it('opens the day at once where the viewer may add neither a booking nor a closure', async () => {
        const onDay = vi.fn();
        render({ onBook: undefined, onDay });

        press(control('2026-11-20'));
        await settle();

        expect(document.querySelector('.admin-menu')).toBeNull();
        expect(onDay.mock.calls).toEqual([['2026-11-20']]);
    });

    it('hatches a day the business is closed all day, naming it so, its chips and its menu kept', async () => {
        render({ closedDays: ['2026-11-03', '2026-11-08'] });

        expect(cell('2026-11-03')?.classList.contains('admin-calendar-month__day--closed')).toBe(true);
        expect(cell('2026-11-04')?.classList.contains('admin-calendar-month__day--closed')).toBe(false);
        expect(heard(control('2026-11-08'))).toBe(`${title('2026-11-08')}, ${cal.text(CLOSED_DAY)}`);
        expect(cell('2026-11-03')?.querySelectorAll('.admin-calendar-chip')).toHaveLength(2);

        await openMenu('2026-11-03');
        expect(document.querySelectorAll('.admin-menu__item').length).toBeGreaterThan(0);
    });

    it('greys another month\'s days and keeps them full, and marks the tenant\'s today', () => {
        render();

        expect(cell('2026-12-06')?.classList.contains('admin-calendar-month__day--other')).toBe(true);
        expect(cell('2026-12-06')?.querySelectorAll('.admin-calendar-chip')).toHaveLength(1);
        expect(cell('2026-11-03')?.classList.contains('admin-calendar-month__day--other')).toBe(false);
        expect(cell('2026-11-04')?.querySelector('.admin-calendar-month__number--today')?.textContent).toBe('4');
        expect(control('2026-11-04')?.getAttribute('aria-current')).toBe('date');
        expect(view.host.querySelectorAll('.admin-calendar-month__number--today')).toHaveLength(1);
    });

    it('drags a movable chip shown in a day to another day, handing on the chip and the day alone, and takes no drop on its own day', async () => {
        const onDrop = vi.fn();
        const moving = chip('m', '2026-11-04T09:00:00', { movable: true });
        render({ onDrop, chips: new Map([...CHIPS, ['2026-11-04', [moving]]]) });

        expect(cell('2026-11-03')?.querySelector('[draggable="true"]')).toBeNull();

        const held = cell('2026-11-04')?.querySelector('.admin-calendar-chip[data-uuid="m"]') ?? null;
        expect(held?.getAttribute('draggable')).toBe('true');
        expect(carry(held, cell('2026-11-04'))).toBe(false);
        expect(carry(held, control('2026-11-20'))).toBe(true);
        expect(onDrop.mock.calls).toEqual([[{ chip: moving, date: '2026-11-20', staffId: null }]]);

        press(cell('2026-11-03')?.querySelector('.admin-calendar-more') ?? null);
        await settle();

        expect(document.querySelector('.admin-calendar-popover [draggable="true"]')).toBeNull();
    });
});

describe('Month below the breakpoint', () => {
    let undo: () => void;

    beforeEach(() => {
        undo = onPhone();
    });

    afterEach(() => undo());

    it('holds a day\'s number and how many bookings it has, no chip, the whole cell its menu, named with the count where the day has any', async () => {
        const onBook = vi.fn();
        render({ onBook });

        expect(view.host.querySelectorAll('.admin-calendar-chip, .admin-calendar-more')).toHaveLength(0);
        expect(cell('2026-11-03')?.querySelectorAll('button')).toHaveLength(1);
        expect(cell('2026-11-03')?.querySelector('.admin-calendar-month__count')?.textContent).toBe('4');
        expect(heard(control('2026-11-03'))).toBe(`${title('2026-11-03')}, ${cal.choice(BOOKING_COUNT, 4)}`);
        expect(control('2026-11-03')?.querySelector('.admin-calendar-month__number')?.textContent).toBe('3');
        // A day with no booking shows no count and is named by its weekday and date alone, closed or not.
        expect(cell('2026-11-05')?.querySelector('.admin-calendar-month__count')).toBeNull();
        expect(heard(control('2026-11-05'))).toBe(title('2026-11-05'));

        render({ onBook, closedDays: ['2026-11-05'] });
        expect(heard(control('2026-11-05'))).toBe(`${title('2026-11-05')}, ${cal.text(CLOSED_DAY)}`);
        render({ onBook });

        await openMenu('2026-11-03');
        press(document.querySelector('.admin-calendar-date__book'));

        expect(onBook.mock.calls).toEqual([['2026-11-03']]);
    });

    it('writes the count as the page\'s language groups its digits', () => {
        render({ chips: new Map([['2026-11-03', Array.from({ length: 1234 }, (_, at) => chip(`n${at}`, '2026-11-03T10:00:00'))]]) });

        expect(cell('2026-11-03')?.querySelector('.admin-calendar-month__count')?.textContent).toBe('1,234');
    });

    it('drags nothing, a cell too narrow for a chip, and asks for nothing where a chip picked up above the breakpoint is let go below it', () => {
        const onDrop = vi.fn();
        const chips = new Map([['2026-11-04', [chip('m', '2026-11-04T09:00:00', { movable: true })]]]);
        render({ onDrop, chips });

        expect(view.host.querySelector('[draggable="true"]')).toBeNull();

        // The one way a held chip meets a narrow cell: the window narrowing under the drag.
        undo();
        render({ onDrop, chips });
        dragEvent(cell('2026-11-04')?.querySelector('.admin-calendar-chip[data-uuid="m"]') ?? null, 'dragstart');
        undo = onPhone();
        render({ onDrop, chips });
        dragEvent(cell('2026-11-20'), 'dragover');
        dragEvent(cell('2026-11-20'), 'drop');

        expect(onDrop).not.toHaveBeenCalled();
    });
});

describe('the legend under chips', () => {
    it('names the outline each badge\'s chip wears, the note\'s mark, the closure\'s mark and a closed day\'s hatch, and no gap', () => {
        show(<Legend by="staff" swatches={[]} chips />);

        const badges = [...view.host.querySelectorAll<HTMLElement>('.admin-calendar-legend__badge')];
        expect(badges.map((entry) => [entry.dataset['badge'], entry.textContent])).toEqual([
            ['awaiting_approval', badgeMeaning('awaiting_approval', shell, cal)],
            ['awaiting_payment', badgeMeaning('awaiting_payment', shell, cal)],
        ]);
        expect(badges[1]?.querySelector('.admin-calendar-legend__swatch')?.className.split(' ')).toEqual(expect.arrayContaining(CHIP_OUTLINES.awaiting_payment.split(' ')));
        expect(view.host.querySelector('.admin-calendar-legend__noted')?.textContent).toBe(cal.text(NOTED));
        expect(view.host.querySelector('.admin-calendar-legend__inside')?.textContent).toBe(`!${cal.text('badges.closure')}`);
        expect(view.host.querySelector('.admin-calendar-legend__closed')?.textContent).toBe(cal.text(CLOSED_DAY));
        expect(view.host.querySelector('.admin-calendar-legend__gap')).toBeNull();

        show(<Legend by="staff" swatches={[]} />);

        expect(view.host.querySelector('.admin-calendar-legend__gap')).not.toBeNull();
        expect(view.host.querySelector('.admin-calendar-legend__noted, .admin-calendar-legend__closed')).toBeNull();
    });
});
