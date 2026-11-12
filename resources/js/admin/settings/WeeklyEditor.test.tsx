import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { type Language, localeOf } from '../../booking/language';
import { mount, type Mounted, press } from '@/components/ui/harness';
import type { TenantClock } from '@/lib/format';
import { keyedShell } from '@/testing/keyed';
import { DEFAULT_WINDOW, type WeeklyWindow } from '@/shifts/model';
import { WeeklyEditor } from './WeeklyEditor';

/*
 * The weekly editor on its own: seven day cards, a closed day, the rows of a split day in
 * order, Add window appending the default window on that day, Remove dropping one row and no
 * other, and a window off the grid shown as stored in its time field. Its words are the shell's
 * shared ones, read through the keyed table, and each day is named by ICU in the page's language,
 * held to Intl's own output.
 */

const shell = keyedShell();

/** Intl's long name of an ISO weekday in a language, read off the first week of 2024. */
function intlWeekday(weekday: number, language: Language): string {
    return new Intl.DateTimeFormat(localeOf(language), { weekday: 'long', timeZone: 'UTC' }).format(new Date(Date.UTC(2024, 0, weekday)));
}

const CLOCK: TenantClock = { timezone: 'Europe/Athens', dateFormat: 'd/m/Y', timeFormat: 'H:i' };

let view: Mounted;
let received: WeeklyWindow[] | null;

function render(rules: WeeklyWindow[]): void {
    received = null;
    view.render(<WeeklyEditor rules={rules} onChange={(next) => (received = next)} clock={CLOCK} granularity={5} />);
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

    it('shows a window stored off the five-minute grid as stored, spelled in the tenant time format, with its machine value intact', () => {
        render([{ weekday: 2, start: '09:07', end: '13:00' }]);

        const start = day(2).querySelector('.admin-weekly__start');
        expect(start?.getAttribute('value')).toBe('09:07');
        expect(start?.textContent).toBe('09:07');
        expect(received).toBeNull();
    });
});
