import type { ReactNode } from 'react';
import { act } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { carry, dragEvent, key, mount, type Mounted, press, settle } from '@/components/ui/harness';
import { keyedCalendar, WithCalendar } from '@/testing/keyed';
import { MORE } from './CappedChips';
import { TARGETED } from './chip-drag';
import { Legend, TO_DAY } from './Chrome';
import { NEW_BOOKING, NEW_CLOSURE, OPEN_DAY } from './DateMenu';
import { type Chip, CLOSED_DAY, type Colouring, dayTitleOf, NOT_WORKING_THIS_DAY, OTHER_BOOKINGS } from './model';
import type { ClosureLine, WeekCell, WeekRow } from './staff-week';
import { StaffWeek } from './StaffWeek';

/*
 * Week's grid of people as rendered: the head of the week's days, each opening Day view; a row per
 * member with their initials and name, and the other bookings' row; a member's day with its chips
 * up to six and the line saying how many more, its closures as lines among them counting for no
 * cap, its date menu on the member and the day, and the hatch of a day off; the hover marking a
 * cell's row and day; and a chip dragged to another day or another member's row. Every word reads
 * through the keyed calendar section.
 */

const cal = keyedCalendar();

const CLOCK = { timezone: 'Europe/Athens', dateFormat: 'd/m/Y', timeFormat: 'H:i' };

const STAFF: Colouring = { by: 'staff', palette: true, categories: [], own: [] };

const ELENA = { id: 2, name: 'Elena Papadaki' };

const NIKOS = { id: 4, name: 'Nikos Georgiou' };

const DAYS = ['2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05', '2026-11-06', '2026-11-07', '2026-11-08'];

function chip(uuid: string, start: string, overrides: Partial<Chip> = {}): Chip {
    return { uuid, start, customer: 'Ada North', status: 'confirmed', staffId: 2, category: null, badge: null, noted: false, inside: false, movable: true, segments: [0], ...overrides };
}

function line(start: string, end: string, reason: string | null): ClosureLine {
    return { key: `line-${start}`, start: `2026-11-03T${start}:00`, range: `${start} - ${end}`, reason, title: `Closed ${start}-${end}` };
}

function row(key: string, member: WeekRow['member'], cells: Partial<Record<string, Partial<WeekCell>>>): WeekRow {
    return { key, member, cells: DAYS.map((date) => ({ date, chips: [], lines: [], hatched: false, ...cells[date] })) };
}

/** Elena's Tuesday: eight bookings from 08:00 by the hour, the last two hidden; closed 09:00-10:00 and 13:00-15:00. */
const TUESDAY = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h'].map((uuid, at) => chip(uuid, `2026-11-03T${String(8 + at).padStart(2, '0')}:00:00`, { movable: uuid !== 'b' }));

const ROWS: WeekRow[] = [
    row('member-2', ELENA, {
        '2026-11-03': { chips: TUESDAY, lines: [line('13:00', '15:00', 'Dentist'), line('09:00', '10:00', null)] },
        '2026-11-07': { chips: [chip('sat', '2026-11-07T10:00:00')], hatched: true },
    }),
    row('member-4', NIKOS, { '2026-11-04': { chips: [chip('nik', '2026-11-04T11:00:00', { staffId: 4, segments: [1] })] } }),
    row('other', null, { '2026-11-05': { chips: [chip('imp', '2026-11-05T12:00:00', { staffId: 9 })] } }),
];

let view: Mounted;

function show(node: ReactNode) {
    view.render(<WithCalendar>{node}</WithCalendar>);
}

function render(props: Partial<Parameters<typeof StaffWeek>[0]> = {}) {
    show(<StaffWeek days={DAYS} rows={ROWS} today="2026-11-04" colouring={STAFF} clock={CLOCK} onOpen={() => undefined} onDay={() => undefined} onBook={() => undefined} onClosure={() => undefined} onDrop={() => undefined} {...props} />);
}

/** What a screen reader reads of an element: its text without the parts kept from readers. */
function heard(element: Element | null | undefined): string {
    return element === null || element === undefined ? '' : [...element.childNodes].map((node) => (node instanceof Element ? (node.getAttribute('aria-hidden') === 'true' ? '' : heard(node)) : (node.textContent ?? ''))).join('');
}

const cell = (rowKey: string, date: string) => view.host.querySelector<HTMLElement>(`.admin-calendar-week__cell[data-row="${rowKey}"][data-date="${date}"]`);

const control = (rowKey: string, date: string) => cell(rowKey, date)?.querySelector<HTMLElement>('.admin-calendar-date') ?? null;

const head = (date: string) => view.host.querySelector<HTMLElement>(`.admin-calendar-week__day[data-day="${date}"]`);

const person = (rowKey: string) => view.host.querySelector<HTMLElement>(`.admin-calendar-week__person[data-row="${rowKey}"]`);

const chipIn = (rowKey: string, date: string, uuid: string) => cell(rowKey, date)?.querySelector<HTMLElement>(`.admin-calendar-chip[data-uuid="${uuid}"]`) ?? null;

const menuTitle = (name: string, date: string) => cal.text('day.menu', { staff: name, date: dayTitleOf(date, CLOCK, cal) });

beforeEach(() => {
    view = mount();
});

afterEach(() => view.unmount());

describe('the grid of people', () => {
    it('heads the week with its days, today marked, each opening Day view on its date', () => {
        const onDay = vi.fn();
        render({ onDay });

        expect([...view.host.querySelectorAll<HTMLElement>('.admin-calendar-week__day')].map((day) => day.dataset['day'])).toEqual(DAYS);
        expect(heard(head('2026-11-03'))).toBe(dayTitleOf('2026-11-03', CLOCK, cal));
        expect(head('2026-11-03')?.getAttribute('title')).toBe(cal.text(TO_DAY));
        expect(head('2026-11-04')?.getAttribute('aria-current')).toBe('date');
        expect(head('2026-11-04')?.querySelector('.admin-calendar-week__today')?.textContent).toBe('4');
        expect(head('2026-11-04')?.querySelector('.admin-calendar-week__today')?.className.split(' ')).toEqual(expect.arrayContaining(['bg-spark', 'text-spark-foreground']));
        expect(view.host.querySelectorAll('.bg-spark')).toHaveLength(1);
        expect(view.host.querySelectorAll('[aria-current="date"]')).toHaveLength(1);

        press(head('2026-11-06'));

        expect(onDay.mock.calls).toEqual([['2026-11-06']]);
    });

    it('draws a row per member with their initials and name, then the other bookings\' row, named so, with none', () => {
        render();

        expect([...view.host.querySelectorAll<HTMLElement>('.admin-calendar-week__person')].map((each) => each.textContent)).toEqual(['EPElena Papadaki', 'NGNikos Georgiou', cal.text(OTHER_BOOKINGS)]);
        expect(person('other')?.querySelector('[aria-hidden="true"]')).toBeNull();
        expect(view.host.querySelectorAll('.admin-calendar-week__cell')).toHaveLength(21);
    });

    it('lists a member\'s day up to six chips and how many more, its closure lines among them by start and counting for no cap', () => {
        render();

        const entries = [...(cell('member-2', '2026-11-03')?.querySelectorAll<HTMLElement>('.admin-calendar-chip, .admin-calendar-week__closure, .admin-calendar-more') ?? [])];
        expect(entries.map((entry) => entry.dataset['uuid'] ?? heard(entry))).toEqual([
            'a',
            'Closed 09:00-10:00',
            'b',
            'c',
            'd',
            'e',
            'Closed 13:00-15:00',
            'f',
            cal.choice(MORE, 2),
        ]);
    });

    it('prints a closure line as its range and its reason, read as its sentence', () => {
        render();

        const lines = [...(cell('member-2', '2026-11-03')?.querySelectorAll<HTMLElement>('.admin-calendar-week__closure') ?? [])];
        expect(lines.map((each) => [...each.querySelectorAll('[aria-hidden="true"]')].map((part) => part.textContent))).toEqual([['', '09:00 - 10:00'], ['', '13:00 - 15:00', 'Dentist']]);
        expect(lines.map((each) => heard(each))).toEqual(['Closed 09:00-10:00', 'Closed 13:00-15:00']);
    });

    it('opens the popover of a day\'s line titled by the member and the day, listing every chip and no closure', async () => {
        const onOpen = vi.fn();
        render({ onOpen });

        press(cell('member-2', '2026-11-03')?.querySelector('.admin-calendar-more') ?? null);
        await settle();

        const popover = document.querySelector<HTMLElement>('.admin-calendar-popover');
        expect(popover?.querySelector('.admin-calendar-popover__title')?.textContent).toBe(menuTitle('Elena Papadaki', '2026-11-03'));
        expect(popover?.querySelectorAll('.admin-calendar-chip')).toHaveLength(8);
        expect(popover?.querySelector('.admin-calendar-week__closure')).toBeNull();
        expect(popover?.querySelector('[draggable="true"]')).toBeNull();

        press(popover?.querySelector('.admin-calendar-chip[data-uuid="h"]') ?? null);
        await settle();

        expect(onOpen.mock.calls).toEqual([['h']]);
    });

    it('makes a member\'s day its date menu, named by the member and the day, offering what the viewer may add and Open day, each on the member and the day', async () => {
        const onBook = vi.fn();
        const onClosure = vi.fn();
        const onDay = vi.fn();
        render({ onBook, onClosure, onDay });

        expect(cell('member-2', '2026-11-03')?.querySelector('button')).toBe(control('member-2', '2026-11-03'));
        expect(heard(control('member-4', '2026-11-05'))).toBe(menuTitle('Nikos Georgiou', '2026-11-05'));

        const open = async (rowKey: string, date: string) => {
            control(rowKey, date)?.focus();
            key(control(rowKey, date), 'Enter');
            await settle();
        };

        await open('member-4', '2026-11-05');
        expect(document.querySelector('.admin-menu__title')?.textContent).toBe(menuTitle('Nikos Georgiou', '2026-11-05'));
        expect([...document.querySelectorAll('.admin-menu__item')].map((item) => item.textContent)).toEqual([NEW_BOOKING, NEW_CLOSURE, OPEN_DAY].map((name) => cal.text(name)));
        press(document.querySelector('.admin-calendar-date__book'));
        await settle();
        await open('member-2', '2026-11-06');
        press(document.querySelector('.admin-calendar-date__closure'));
        await settle();
        await open('member-2', '2026-11-02');
        press(document.querySelector('.admin-calendar-date__day'));
        await settle();

        expect([onBook.mock.calls, onClosure.mock.calls, onDay.mock.calls]).toEqual([[['2026-11-05', 4]], [['2026-11-06', 2]], [['2026-11-02']]]);
    });

    it('hatches a member\'s day off, naming it so, its chips and its menu kept', () => {
        render();

        expect(cell('member-2', '2026-11-07')?.classList.contains('admin-calendar-week__cell--hatched')).toBe(true);
        expect(cell('member-2', '2026-11-06')?.classList.contains('admin-calendar-week__cell--hatched')).toBe(false);
        expect(heard(control('member-2', '2026-11-07'))).toBe(`${menuTitle('Elena Papadaki', '2026-11-07')}, ${cal.text(NOT_WORKING_THIS_DAY)}`);
        expect(chipIn('member-2', '2026-11-07', 'sat')).not.toBeNull();
    });

    it('gives the other bookings\' row its chips alone: no menu, no hatch', () => {
        render();

        expect(control('other', '2026-11-05')).toBeNull();
        expect(chipIn('other', '2026-11-05', 'imp')).not.toBeNull();
        expect(view.host.querySelectorAll('.admin-calendar-week__cell[data-row="other"] .admin-calendar-date')).toHaveLength(0);
    });

    it('marks the row\'s head and the day\'s head of the cell under the pointer, and nothing once it leaves', () => {
        render();

        act(() => {
            cell('member-4', '2026-11-05')?.dispatchEvent(new PointerEvent('pointerover', { bubbles: true }));
        });

        expect(person('member-4')?.classList.contains('admin-calendar-week__person--marked')).toBe(true);
        expect(head('2026-11-05')?.classList.contains('admin-calendar-week__day--marked')).toBe(true);
        expect(view.host.querySelectorAll('.admin-calendar-week__person--marked, .admin-calendar-week__day--marked')).toHaveLength(2);

        act(() => {
            cell('member-4', '2026-11-05')?.dispatchEvent(new PointerEvent('pointerout', { bubbles: true }));
        });

        expect(view.host.querySelectorAll('.admin-calendar-week__person--marked, .admin-calendar-week__day--marked')).toHaveLength(0);
    });
});

describe('a chip dragged in the grid of people', () => {
    it('drags a movable chip shown in a cell, never one the server keeps still', () => {
        render();

        expect(chipIn('member-2', '2026-11-03', 'a')?.getAttribute('draggable')).toBe('true');
        expect(chipIn('member-2', '2026-11-03', 'b')?.hasAttribute('draggable')).toBe(false);
    });

    it('hands on the chip and the day where it lands on another day of its row, and the row\'s member where it lands on another row', () => {
        const onDrop = vi.fn();
        render({ onDrop });

        expect(carry(chipIn('member-2', '2026-11-03', 'a'), cell('member-2', '2026-11-05'))).toBe(true);
        expect(carry(chipIn('member-4', '2026-11-04', 'nik'), control('member-2', '2026-11-04'))).toBe(true);
        expect(carry(chipIn('other', '2026-11-05', 'imp'), cell('member-4', '2026-11-06'))).toBe(true);

        expect(onDrop.mock.calls).toEqual([
            [{ chip: TUESDAY[0], date: '2026-11-05', staffId: null }],
            [{ chip: expect.objectContaining({ uuid: 'nik' }), date: '2026-11-04', staffId: 2 }],
            [{ chip: expect.objectContaining({ uuid: 'imp' }), date: '2026-11-06', staffId: 4 }],
        ]);
    });

    it('takes no drop on the chip\'s own cell nor on the other bookings\' row', () => {
        const onDrop = vi.fn();
        render({ onDrop });

        expect(carry(chipIn('member-2', '2026-11-03', 'a'), cell('member-2', '2026-11-03'))).toBe(false);
        expect(carry(chipIn('member-2', '2026-11-03', 'a'), cell('other', '2026-11-04'))).toBe(false);
        expect(onDrop).not.toHaveBeenCalled();
    });

    it('shows the cell under the held chip would take it, and stops once the chip leaves it or lands', () => {
        render();
        const target = cell('member-4', '2026-11-02');
        const marked = () => [...view.host.querySelectorAll('.admin-calendar-week__cell')].filter((each) => each.classList.contains(TARGETED.split(' ')[0] ?? ''));

        dragEvent(chipIn('member-2', '2026-11-03', 'a'), 'dragstart');
        dragEvent(target, 'dragover');
        expect(marked()).toEqual([target]);

        dragEvent(target, 'dragleave', target?.querySelector('.admin-calendar-date') ?? null);
        expect(marked()).toEqual([target]);

        dragEvent(target, 'dragleave', cell('member-4', '2026-11-03'));
        expect(marked()).toEqual([]);

        dragEvent(target, 'dragover');
        dragEvent(target, 'drop');
        expect(marked()).toEqual([]);
    });

    it('drags nothing where no drop is handled', () => {
        render({ onDrop: undefined });

        expect(view.host.querySelector('[draggable="true"]')).toBeNull();
    });
});

describe('the legend under the grid of people', () => {
    it('names the chips\' marks, then Closure and Outside the member\'s hours, the hatch named once', () => {
        show(<Legend by="staff" swatches={[]} chips day />);

        expect(view.host.querySelector('.admin-calendar-legend__noted')).not.toBeNull();
        expect(view.host.querySelector('.admin-calendar-legend__closure')).not.toBeNull();
        expect(view.host.querySelector('.admin-calendar-legend__outside')).not.toBeNull();
        expect(view.host.querySelector('.admin-calendar-legend__closed')).toBeNull();
        expect(view.host.textContent).not.toContain(cal.text(CLOSED_DAY));
    });
});
