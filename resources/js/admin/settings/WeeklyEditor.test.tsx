import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { type Language, localeOf } from '../../booking/language';
import { mount, type Mounted, press } from '@/components/ui/harness';
import type { TenantClock } from '@/lib/format';
import { keyedShell } from '@/testing/keyed';
import { DEFAULT_WINDOW, type WeeklyWindow } from '@/shifts/model';
import { WeeklyEditor } from './WeeklyEditor';

/*
 * The weekly editor on its own: seven day rows laid by the editor's own width, a closed day, the
 * windows of a split day in order, Add window appending the default window on that day, Remove
 * dropping one window and no other, a window off the grid shown as stored in its time field, and
 * a refusal's sentence on a line of its own across the window it names.
 * Its words are the shell's shared ones, read through the keyed table, and each day is named by
 * ICU in the page's language, held to Intl's own output.
 */

const shell = keyedShell();

/** Intl's long name of an ISO weekday in a language, read off the first week of 2024. */
function intlWeekday(weekday: number, language: Language): string {
    return new Intl.DateTimeFormat(localeOf(language), { weekday: 'long', timeZone: 'UTC' }).format(new Date(Date.UTC(2024, 0, weekday)));
}

const CLOCK: TenantClock = { timezone: 'Europe/Athens', dateFormat: 'd/m/Y', timeFormat: 'H:i' };

let view: Mounted;
let received: WeeklyWindow[] | null;

function render(rules: WeeklyWindow[], refusals?: Record<number, string>): void {
    received = null;
    view.render(<WeeklyEditor rules={rules} onChange={(next) => (received = next)} clock={CLOCK} granularity={5} refusals={refusals} />);
}

function day(weekday: number): Element {
    const card = document.querySelector(`.admin-weekly__day[data-weekday="${weekday}"]`);

    if (card === null) {
        throw new Error(`No card for weekday ${weekday}.`);
    }

    return card;
}

beforeEach(() => {
    view = mount();
});

afterEach(() => {
    view.unmount();
    vi.restoreAllMocks();
});

describe('the weekly editor', () => {
    it('names each day by ICU in the page\'s language, Greek as well as English', () => {
        view.unmount();
        view = mount('el');
        render([]);

        expect([...document.querySelectorAll('.admin-weekly__day h3')].map((heading) => heading.textContent)).toEqual([1, 2, 3, 4, 5, 6, 7].map((weekday) => intlWeekday(weekday, 'el')));
        expect(intlWeekday(1, 'el')).not.toBe(intlWeekday(1, 'en'));
    });

    it('renders seven day cards Monday first, a day with no windows reading Closed, and a split day as two rows in order', () => {
        render([{ weekday: 1, start: '14:00', end: '18:00' }, { weekday: 1, start: '09:00', end: '13:00' }]);

        expect(document.querySelectorAll('.admin-weekly__day')).toHaveLength(7);
        expect(day(1).querySelector('h3')?.textContent).toBe(intlWeekday(1, 'en'));
        expect(day(7).querySelector('h3')?.textContent).toBe(intlWeekday(7, 'en'));
        expect(day(2).querySelector('.admin-weekly__closed')?.textContent).toBe(shell.text('shared.weekly.closed'));
        expect(day(1).querySelector('.admin-weekly__add')?.textContent).toBe(shell.text('shared.weekly.add'));
        expect(day(1).querySelector('.admin-weekly__remove')?.textContent).toBe(shell.text('shared.weekly.remove'));
        expect([...day(1).querySelectorAll('label')].slice(0, 2).map((label) => label.textContent)).toEqual([shell.text('shared.weekly.start'), shell.text('shared.weekly.end')]);
        expect(day(1).querySelector('.admin-weekly__closed')).toBeNull();
        expect(Array.from(day(1).querySelectorAll('.admin-weekly__start')).map((trigger) => trigger.getAttribute('value'))).toEqual(['14:00', '09:00']);
        expect(Array.from(day(1).querySelectorAll('.admin-weekly__end')).map((trigger) => trigger.getAttribute('value'))).toEqual(['18:00', '13:00']);
    });

    it('appends the default window on the day whose Add window was pressed, and removes one row leaving the others', () => {
        render([{ weekday: 1, start: '09:00', end: '13:00' }, { weekday: 3, start: '10:00', end: '16:00' }]);

        press(day(3).querySelector('.admin-weekly__add'));
        expect(received).toEqual([{ weekday: 1, start: '09:00', end: '13:00' }, { weekday: 3, start: '10:00', end: '16:00' }, { weekday: 3, ...DEFAULT_WINDOW }]);

        press(day(1).querySelector('.admin-weekly__remove'));
        expect(received).toEqual([{ weekday: 3, start: '10:00', end: '16:00' }]);
    });

    it('lays the week as a list of day rows asking the editor\'s own width, the name, the windows and Add window side by side where it is wide and stacked where it is not, no window breakpoint left', () => {
        render([{ weekday: 1, start: '09:00', end: '13:00' }, { weekday: 1, start: '14:00', end: '18:00' }]);
        const editor = document.querySelector('.admin-weekly');
        const classes = (element: Element | null | undefined) => [...(element?.classList ?? [])];

        // The editor is the container its rows ask, so a row reads the same wherever the editor
        // is drawn, and nothing in it asks the window.
        expect(classes(editor)).toEqual(expect.arrayContaining(['@container', 'flex', 'flex-col', 'divide-y']));
        const everyClass = [editor, ...(editor?.querySelectorAll('*') ?? [])].flatMap((element) => classes(element));
        expect(everyClass.filter((name) => /(?:^|:)(?:max-)?(?:sm|md|lg|xl|2xl|desktop|wide):/.test(name))).toEqual([]);

        // The days are the editor's own children, Monday to Sunday.
        expect([...(editor?.children ?? [])].map((child) => child.getAttribute('data-weekday'))).toEqual(['1', '2', '3', '4', '5', '6', '7']);

        // A row is one column until the editor holds the line, then three: the name, the windows
        // under each other, and Add window on the first window's line.
        for (const weekday of [1, 2]) {
            const row = day(weekday);
            expect(classes(row)).toEqual(expect.arrayContaining(['grid', 'gap-y-2', '@3xl:grid-cols-[8rem_minmax(0,1fr)_auto]', '@3xl:items-end', '@3xl:gap-x-4']));
            expect(classes(row).filter((name) => name.startsWith('grid-cols-'))).toEqual([]);
            expect(classes(row.querySelector('h3'))).toEqual(expect.arrayContaining(['@3xl:col-start-1', '@3xl:row-start-1']));
            expect(classes(row.querySelector('.admin-weekly__add'))).toEqual(expect.arrayContaining(['justify-self-start', '@3xl:col-start-3', '@3xl:row-start-1']));
            expect(row.firstElementChild?.tagName).toBe('H3');
            expect(row.lastElementChild?.classList.contains('admin-weekly__add')).toBe(true);
        }

        expect([...day(1).querySelectorAll('.admin-weekly__window')].map((line) => classes(line))).toEqual([expect.arrayContaining(['flex', 'flex-wrap', '@3xl:col-start-2']), expect.arrayContaining(['flex', 'flex-wrap', '@3xl:col-start-2'])]);
        expect(classes(day(2).querySelector('.admin-weekly__closed'))).toEqual(expect.arrayContaining(['@3xl:col-start-2', '@3xl:row-start-1']));

        // Each window keeps its two named time fields and its Remove.
        const ids = [...day(1).querySelectorAll('.admin-weekly__start, .admin-weekly__end')].map((trigger) => trigger.id);
        expect(ids).toEqual(['weekly-1-0-start', 'weekly-1-0-end', 'weekly-1-1-start', 'weekly-1-1-end']);
        expect(ids.map((id) => document.querySelector(`label[for="${id}"]`)?.textContent)).toEqual([shell.text('shared.weekly.start'), shell.text('shared.weekly.end'), shell.text('shared.weekly.start'), shell.text('shared.weekly.end')]);
        expect(day(1).querySelectorAll('.admin-weekly__remove')).toHaveLength(2);

        // Below the one line a field shares what the window's line has left beside Remove, never
        // narrower than the time it shows and never wider than 8rem, so Remove stays beside the
        // two on a phone; from the one line it is 8rem and nothing else.
        const fields = ids.map((id) => classes(document.getElementById(id)?.closest('.admin-field')));
        expect(fields).toEqual(ids.map(() => expect.arrayContaining(['min-w-max', 'max-w-32', 'flex-1', '@3xl:w-32', '@3xl:flex-none'])));
        expect(fields.flatMap((names) => names.filter((name) => /^(?:w|basis)-/.test(name)))).toEqual([]);
    });

    it('shows a refusal on a line of its own across the window it names, under the fields and Remove, the Start field pointing at it, and leaves every other window as it was', () => {
        render([{ weekday: 1, start: '09:00', end: '13:00' }, { weekday: 1, start: '14:00', end: '10:00' }], { 1: 'refused-window' });
        const classes = (element: Element | null | undefined) => [...(element?.classList ?? [])];
        const [first, second] = [...day(1).querySelectorAll('.admin-weekly__window')];
        const sentence = second?.querySelector('.admin-field__error');

        expect(sentence?.textContent).toBe('refused-window');
        expect(sentence?.id).toBe('weekly-1-1-start-error');

        // The sentence is the window's last part, after Remove and inside neither field, so it
        // has the window's width and Start, End and Remove keep one level.
        expect(sentence?.closest('.admin-field')).toBeNull();
        expect(sentence?.parentElement?.parentElement).toBe(second);
        expect(second?.lastElementChild).toBe(sentence?.parentElement);
        expect(second?.children).toHaveLength(4);
        expect(second?.children[2]?.classList.contains('admin-weekly__remove')).toBe(true);
        expect(classes(sentence?.parentElement)).toEqual(expect.arrayContaining(['basis-full', '@3xl:col-span-full']));

        // Start points at the sentence and is marked; End is neither.
        const start = document.getElementById('weekly-1-1-start');
        const end = document.getElementById('weekly-1-1-end');
        expect(start?.getAttribute('aria-describedby')).toBe('weekly-1-1-start-error');
        expect(start?.getAttribute('aria-invalid')).toBe('true');
        expect(end?.hasAttribute('aria-describedby')).toBe(false);
        expect(end?.hasAttribute('aria-invalid')).toBe(false);

        // From the one line the refused window takes two of the row's own lines, its fields on
        // the first and the sentence on the second, so the day's name and Add window stay level
        // with the first window's fields whichever window is refused.
        expect(classes(second)).toEqual(expect.arrayContaining(['flex', 'flex-wrap', '@3xl:col-start-2', '@3xl:row-span-2', '@3xl:grid', '@3xl:grid-rows-subgrid', '@3xl:grid-cols-[auto_auto_auto_minmax(0,1fr)]']));

        // A window with no refusal carries no line, no mark and one line of the row.
        expect(first?.querySelector('.admin-field__error')).toBeNull();
        expect(first?.children).toHaveLength(3);
        expect(document.getElementById('weekly-1-0-start')?.hasAttribute('aria-describedby')).toBe(false);
        expect(document.getElementById('weekly-1-0-start')?.hasAttribute('aria-invalid')).toBe(false);
        expect(classes(first).filter((name) => /row-span|subgrid|grid/.test(name))).toEqual([]);

        // A blank sentence is no refusal.
        render([{ weekday: 1, start: '09:00', end: '13:00' }], { 0: ' ' });
        expect(document.querySelector('.admin-field__error')).toBeNull();
        expect(document.getElementById('weekly-1-0-start')?.hasAttribute('aria-invalid')).toBe(false);
        expect(day(1).querySelector('.admin-weekly__window')?.children).toHaveLength(3);
    });

    it('shows a window stored off the five-minute grid as stored, spelled in the tenant time format, with its machine value intact', () => {
        render([{ weekday: 2, start: '09:07', end: '13:00' }]);

        const start = day(2).querySelector('.admin-weekly__start');
        expect(start?.getAttribute('value')).toBe('09:07');
        expect(start?.textContent).toBe('09:07');
        expect(received).toBeNull();
    });
});
