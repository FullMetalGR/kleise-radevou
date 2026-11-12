import { act } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { type Language, localeOf } from '../../../booking/language';
import { dayKey } from '@/lib/day';
import { keyedShell } from '@/testing/keyed';
import { DayCalendar } from './day-calendar';
import { key, mount, press, type Mounted } from './harness';

/*
 * The date field's popover and the manual booking wizard's inline day step, one control over the
 * date library. Weeks start on the tenant's week start, ISO 1 for Monday to 7 for Sunday; no day
 * is disabled, because an admin backdates by design; single-select and required, so a day can be
 * moved and never unselected. It knows nothing about the field around it: a selected date, a
 * week start, today and a change handler receiving the machine date. Its names are ICU's in the
 * language of the strings it is given, held here to Intl's own output in that language, never
 * the library's English, and its two turning controls are named by the kit's words. A day is
 * reached by the data-date its button carries, or by role and name.
 */

/** Intl's own spelling of a UTC date in a language, the expectation every name is held to. */
function spelled(language: Language, options: Intl.DateTimeFormatOptions, date: string): string {
    return new Intl.DateTimeFormat(localeOf(language), { ...options, timeZone: 'UTC' }).format(new Date(`${date}T00:00:00Z`));
}

const MONDAYS = ['2024-01-01', '2024-01-02', '2024-01-03', '2024-01-04', '2024-01-05', '2024-01-06', '2024-01-07'];

let view: Mounted;

beforeEach(() => {
    view = mount();
});

afterEach(() => view.unmount());

const day = (date: string) => document.querySelector<HTMLButtonElement>(`button[data-date="${date}"]`);
const days = () => [...document.querySelectorAll<HTMLButtonElement>('button[data-date]')];
const heads = () => [...document.querySelectorAll('[role="grid"] th[scope="col"]')];
const grid = () => document.querySelector('[role="grid"]');
const turner = (name: string) => document.querySelector<HTMLButtonElement>(`button[aria-label="${name}"]`);
const selected = () => [...document.querySelectorAll('[role="gridcell"][aria-selected="true"]')];

/** Picks an option of a native select as a person does. */
function choose(select: HTMLSelectElement | null, value: string): void {
    const setter = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'value')?.set;

    act(() => {
        if (select !== null) {
            setter?.call(select, value);
            select.dispatchEvent(new Event('change', { bubbles: true }));
        }
    });
}

/** The column, 0 to 6, a day sits in. */
function column(date: string): number {
    const cell = day(date)?.closest('td');

    return cell === null || cell === undefined ? -1 : [...(cell.parentElement?.children ?? [])].indexOf(cell);
}

describe('the day calendar', () => {
    it.each(['en', 'el'] as const)('names the weekdays, the month and each day by ICU in %s, and its turning controls by the kit', (language) => {
        view.unmount();
        view = mount(language);
        view.render(<DayCalendar selected="2026-11-03" weekStart={1} today="2026-09-24" onChange={() => {}} />);

        const shell = keyedShell(language);
        const title = spelled(language, { month: 'long', year: 'numeric' }, '2026-11-01');

        expect(document.querySelector('[role="status"]')?.textContent).toBe(title);
        expect(grid()?.getAttribute('aria-label')).toBe(title);
        expect(heads().map((head) => head.textContent)).toEqual(MONDAYS.map((monday) => spelled(language, { weekday: 'short' }, monday)));
        expect(heads().map((head) => head.getAttribute('aria-label'))).toEqual(MONDAYS.map((monday) => spelled(language, { weekday: 'long' }, monday)));
        expect(day('2026-11-03')?.getAttribute('aria-label')).toBe(spelled(language, { dateStyle: 'full' }, '2026-11-03'));
        expect(turner(shell.text('kit.month_grid.previous'))).not.toBeNull();
        expect(turner(shell.text('kit.month_grid.next'))).not.toBeNull();
    });

    it.each(['en', 'el'] as const)('reaches years back by its month and year dropdowns where given an earliest day, named by ICU in %s and by the kit', (language) => {
        const picked: string[] = [];

        view.unmount();
        view = mount(language);
        view.render(<DayCalendar selected="" weekStart={1} today="2026-09-24" earliest="1900-01-01" onChange={(date) => picked.push(date)} />);

        const shell = keyedShell(language);
        const month = document.querySelector<HTMLSelectElement>(`select[aria-label="${shell.text('kit.month_grid.month')}"]`);
        const year = document.querySelector<HTMLSelectElement>(`select[aria-label="${shell.text('kit.month_grid.year')}"]`);
        const years = [...(year?.options ?? [])].map((option) => option.textContent);

        expect([...(month?.options ?? [])].map((option) => option.textContent)).toEqual([1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12].map((at) => spelled(language, { month: 'long' }, `2026-${String(at).padStart(2, '0')}-01`)));
        expect([years[0], years.at(-1), years.length]).toEqual([spelled(language, { year: 'numeric' }, '1900-01-01'), spelled(language, { year: 'numeric' }, '2026-01-01'), 127]);

        choose(year, '1950');
        choose(month, '2');
        press(day('1950-03-14'));

        expect(picked).toEqual(['1950-03-14']);
    });

    it('disables the days after today where given an earliest day, today itself pressable', () => {
        const picked: string[] = [];
        view.render(<DayCalendar selected="" weekStart={1} today="2026-09-24" earliest="1900-01-01" onChange={(date) => picked.push(date)} />);

        expect(day('2026-09-25')?.disabled).toBe(true);
        expect(day('2026-09-24')?.disabled).toBe(false);

        press(day('2026-09-25'));
        press(day('2026-09-24'));

        expect(picked).toEqual(['2026-09-24']);
    });

    it('keeps its caption a title with no dropdown where given no earliest day', () => {
        view.render(<DayCalendar selected="2026-11-03" weekStart={1} today="2026-09-24" onChange={() => {}} />);

        expect(document.querySelectorAll('select')).toHaveLength(0);
    });

    it('speaks no English of the library\'s own in Greek', () => {
        view.unmount();
        view = mount('el');
        view.render(<DayCalendar selected="2026-09-24" weekStart={1} today="2026-09-24" onChange={() => {}} />);

        const labels = [...document.querySelectorAll('[aria-label]')].map((element) => element.getAttribute('aria-label') ?? '');

        expect(labels.filter((label) => /Today|selected|Go to|Month|Week/.test(label))).toEqual([]);
        expect(day('2026-09-24')?.getAttribute('aria-label')).toBe(spelled('el', { dateStyle: 'full' }, '2026-09-24'));
    });

    it('starts its weeks on Monday under week start 1 and on Sunday under 7', () => {
        view.render(<DayCalendar selected="2026-11-03" weekStart={1} today="2026-09-24" onChange={() => {}} />);

        expect(heads()[0]?.getAttribute('aria-label')).toBe(spelled('en', { weekday: 'long' }, MONDAYS[0] ?? ''));
        expect(column('2026-11-01')).toBe(6);
        expect(column('2026-11-02')).toBe(0);

        view.render(<DayCalendar selected="2026-11-03" weekStart={7} today="2026-09-24" onChange={() => {}} />);

        expect(heads().map((head) => head.getAttribute('aria-label'))).toEqual([MONDAYS[6], ...MONDAYS.slice(0, 6)].map((date) => spelled('en', { weekday: 'long' }, date ?? '')));
        expect(column('2026-11-01')).toBe(0);
    });

    it('starts on Monday for a week start it cannot read', () => {
        view.render(<DayCalendar selected="2026-11-03" weekStart={0} today="2026-09-24" onChange={() => {}} />);

        expect(column('2026-11-02')).toBe(0);
    });

    it('shows the selected date month, every day of it, none disabled, the chosen one selected', () => {
        view.render(<DayCalendar selected="2026-11-03" weekStart={1} today="2026-09-24" onChange={() => {}} />);

        expect(days()).toHaveLength(30);
        expect(days().some((button) => button.disabled)).toBe(false);
        expect(selected()).toHaveLength(1);
        expect(selected()[0]?.contains(day('2026-11-03'))).toBe(true);
    });

    it('disables no past day either: an admin backdates by design', () => {
        view.render(<DayCalendar selected="2020-02-10" weekStart={1} today="2026-09-24" onChange={() => {}} />);

        expect(days()).toHaveLength(29);
        expect(days().some((button) => button.disabled || button.getAttribute('aria-disabled') === 'true')).toBe(false);
    });

    it('hands the change handler the machine date of the day pressed', () => {
        const onChange = vi.fn();
        view.render(<DayCalendar selected="2026-11-03" weekStart={1} today="2026-09-24" onChange={onChange} />);

        press(day('2026-11-17'));

        expect(onChange).toHaveBeenCalledWith('2026-11-17');
    });

    it('cannot be unselected: pressing the chosen day again changes nothing', () => {
        const onChange = vi.fn();
        view.render(<DayCalendar selected="2026-11-03" weekStart={1} today="2026-09-24" onChange={onChange} />);

        press(day('2026-11-03'));

        expect(onChange).not.toHaveBeenCalled();
        expect(selected()[0]?.contains(day('2026-11-03'))).toBe(true);
    });

    it('opens on the month of today when nothing is selected, and marks today', () => {
        view.render(<DayCalendar selected="" weekStart={1} today="2026-09-24" onChange={() => {}} />);

        expect(days()).toHaveLength(30);
        expect(day('2026-09-24')?.getAttribute('aria-current')).toBe('date');
        expect(document.querySelectorAll('[aria-current="date"]')).toHaveLength(1);
        expect(selected()).toHaveLength(0);
    });

    it('opens on the month of today for a value that is not a date', () => {
        view.render(<DayCalendar selected="2026-02-30" weekStart={1} today="2026-09-24" onChange={() => {}} />);

        expect(day('2026-09-24')).not.toBeNull();
        expect(selected()).toHaveLength(0);
    });

    it('follows a selection moved from outside to its month', () => {
        view.render(<DayCalendar selected="2026-11-03" weekStart={1} today="2026-09-24" onChange={() => {}} />);
        view.render(<DayCalendar selected="2027-01-20" weekStart={1} today="2026-09-24" onChange={() => {}} />);

        expect(day('2027-01-20')).not.toBeNull();
        expect(selected()[0]?.contains(day('2027-01-20'))).toBe(true);
    });

    it('steps a month back and on', () => {
        view.render(<DayCalendar selected="2026-01-15" weekStart={1} today="2026-09-24" onChange={() => {}} />);

        const shell = keyedShell('en');

        press(turner(shell.text('kit.month_grid.previous')));
        expect(day('2025-12-31')).not.toBeNull();

        press(turner(shell.text('kit.month_grid.next')));
        press(turner(shell.text('kit.month_grid.next')));
        expect(day('2026-02-28')).not.toBeNull();
    });

    it('marks no day as today for a today it cannot read, never the browser\'s own', () => {
        const browserToday = new Date();
        const shown = dayKey(browserToday.getFullYear(), browserToday.getMonth() + 1, 15);

        view.render(<DayCalendar selected={shown} weekStart={1} today="" onChange={() => {}} />);

        expect(day(shown)).not.toBeNull();
        expect(document.querySelectorAll('[aria-current="date"]')).toHaveLength(0);
        expect(document.querySelectorAll('[data-today]')).toHaveLength(0);
    });

    it.each(['en', 'el'] as const)('carries the shell\'s locale as its language in %s', (language) => {
        view.unmount();
        view = mount(language);
        view.render(<DayCalendar selected="2026-11-03" weekStart={1} today="2026-09-24" onChange={() => {}} />);

        expect(document.querySelector('.admin-day-calendar')?.getAttribute('lang')).toBe(localeOf(language));
    });

    // The zone's offset in January, in getTimezoneOffset's minutes west of UTC, so each case first
    // proves its zone took hold; Auckland, ahead of UTC, catches a day read off the UTC fields.
    it.each([
        ['America/New_York', 300, '2026-11-03', '2026-11-04'],
        ['America/Santiago', 180, '2026-09-06', '2026-09-07'],
        ['America/Santiago', 180, '2026-09-05', '2026-09-06'],
        ['Pacific/Auckland', -780, '2026-09-27', '2026-09-28'],
        ['Pacific/Auckland', -780, '2026-11-03', '2026-11-04'],
    ])('keeps each day its own under the browser zone %s (offset %i): %s shown, selected and today, and %s handed on', (zone, january, date, next) => {
        const was = process.env['TZ'];
        process.env['TZ'] = zone;

        try {
            expect(new Date(Date.UTC(2026, 0, 15, 12)).getTimezoneOffset()).toBe(january);

            const onChange = vi.fn();
            view.render(<DayCalendar selected={date} weekStart={1} today={date} onChange={onChange} />);

            expect(day(date)?.textContent).toBe(String(Number(date.slice(8))));
            expect(day(date)?.closest('td')?.getAttribute('data-day')).toBe(date);
            expect(selected()).toHaveLength(1);
            expect(selected()[0]?.contains(day(date))).toBe(true);
            expect(day(date)?.getAttribute('aria-current')).toBe('date');

            press(day(next));
            expect(onChange).toHaveBeenLastCalledWith(next);

            view.render(<DayCalendar selected={next} weekStart={1} today={date} onChange={onChange} />);
            press(day(date));
            expect(onChange).toHaveBeenLastCalledWith(date);
        } finally {
            if (was === undefined) {
                delete process.env['TZ'];
            } else {
                process.env['TZ'] = was;
            }
        }
    });

    it('holds one tab stop, the chosen day, and moves focus with the arrows across a month edge', () => {
        view.render(<DayCalendar selected="2026-11-30" weekStart={1} today="2026-09-24" onChange={() => {}} />);

        const stops = () => [...document.querySelectorAll('button[data-date][tabindex="0"]')].map((button) => button.getAttribute('data-date'));

        expect(stops()).toEqual(['2026-11-30']);

        act(() => day('2026-11-30')?.focus());
        key(day('2026-11-30'), 'ArrowRight');
        expect(document.activeElement).toBe(day('2026-12-01'));

        key(day('2026-12-01'), 'ArrowUp');
        expect(document.activeElement).toBe(day('2026-11-24'));
    });
});
