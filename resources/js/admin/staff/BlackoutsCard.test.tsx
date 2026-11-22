import { act } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { mount, type Mounted, press } from '@/components/ui/harness';
import type { TenantClock } from '@/lib/format';
import { keyedShell, keyedStaff, WithShell, WithStaff } from '../testing/keyed';
import { BlackoutsCard, blackoutLabel, type ClosureWho, type Draft, type InFlight } from './BlackoutsCard';
import type { Blackout } from './model';

/*
 * The closures card on its own: an all-day closure prints the date alone and a partial one the
 * date and its window, under the tenant's formats with no conversion; Add is disabled with a
 * blank date; unticking Closed all day reveals Start and End; Delete hands back the row seen;
 * and while one action is in flight the control that started it is the one that shows busy,
 * every other control merely disabled with its reason in text. It renders inside the keyed
 * staff section, so every word it shows reads as its key.
 */

const staff = keyedStaff();

const CLOCK: TenantClock = { timezone: 'Pacific/Kiritimati', dateFormat: 'd/m/Y', timeFormat: 'H:i' };

const CHRISTMAS: Blackout = { date: '2026-12-25', until: null, start: null, end: null, reason: 'Christmas Day' };

const DENTIST: Blackout = { date: '2026-12-04', until: null, start: '14:00', end: '16:00', reason: null };

const ROWS: Blackout[] = [CHRISTMAS, DENTIST];

let view: Mounted;
let added: Draft | null;
let removed: Blackout | null;

function render(blackouts: Blackout[], inFlight: InFlight = null, who: ClosureWho | null = null): void {
    added = null;
    removed = null;
    view.render(
        <WithShell>
            <WithStaff>
                <BlackoutsCard title="card-title-token" blackouts={blackouts} clock={CLOCK} weekStart={1} granularity={5} inFlight={inFlight} onAdd={(draft) => (added = draft)} onRemove={(row) => (removed = row)} who={who} />
            </WithStaff>
        </WithShell>,
    );
}

function pick(date: string): void {
    press(document.querySelector('.admin-blackouts__date'));
    press(document.querySelector(`button[data-date="${date}"]`));
}

/** Whether a control shows busy: the spinner, aria-busy and the disabled attribute together. */
function busyState(element: Element | null | undefined): { busy: boolean; disabled: boolean; label: string | null | undefined } {
    return {
        busy: element?.getAttribute('aria-busy') === 'true' && element.querySelector('.admin-button__spinner') !== null,
        disabled: element instanceof HTMLButtonElement && element.disabled,
        label: element?.textContent,
    };
}

beforeEach(() => {
    vi.useFakeTimers({ now: new Date('2026-12-01T12:00:00Z') });
    view = mount();
});

afterEach(() => {
    view.unmount();
    vi.useRealTimers();
    vi.restoreAllMocks();
});

describe('the closures card', () => {
    it('prints an all-day closure as the date alone and a partial one with its window, no zone conversion in either', () => {
        render(ROWS);

        expect(Array.from(document.querySelectorAll('.admin-blackouts__when')).map((cell) => cell.textContent)).toEqual(['25/12/2026', staff.text('closures.window', { date: '04/12/2026', time: '14:00', end: '16:00' })]);
        expect(document.querySelector('.admin-blackouts__reason')?.textContent).toBe('Christmas Day');
        expect(blackoutLabel({ date: '2026-01-02', until: null, start: '09:07', end: '10:00', reason: null }, CLOCK, staff)).toBe(staff.text('closures.window', { date: '02/01/2026', time: '09:07', end: '10:00' }));
        expect(blackoutLabel({ date: 'someday', until: null, start: null, end: null, reason: null }, CLOCK, staff)).toBe('someday');
        expect(blackoutLabel({ date: '2026-12-24', until: '2027-01-02', start: null, end: null, reason: null }, CLOCK, staff)).toBe(staff.text('closures.run', { date: '24/12/2026', until: '02/01/2027' }));
        expect(blackoutLabel({ date: '2026-12-24', until: '2026-12-26', start: '14:00', end: '16:00', reason: null }, CLOCK, staff)).toBe(
            staff.text('closures.window', { date: staff.text('closures.run', { date: '24/12/2026', until: '26/12/2026' }), time: '14:00', end: '16:00' }),
        );
    });

    it('disables Add while the date is blank, hides Start and End while Closed all day is ticked, and hands the draft to onAdd once a date is picked', () => {
        render([]);

        const add = document.querySelector('.admin-blackouts__add');
        expect(add).toHaveProperty('disabled', true);
        expect(document.querySelector('.admin-blackouts__start')).toBeNull();
        expect(document.querySelector('.admin-blackouts__none')?.textContent).toBe(staff.text('closures.none'));
        expect(document.querySelector('.admin-blackouts__wait')).toBeNull();

        pick('2026-12-24');
        expect(add).toHaveProperty('disabled', false);
        press(add);
        expect(added).toEqual({ staff_id: null, date: '2026-12-24', until: null, start: null, end: null, reason: null });

        const tick = document.querySelector<HTMLInputElement>('.admin-blackouts__all-day-tick');
        // The all-day tick is the check field's box.
        expect(['size-5', 'accent-primary', 'focus-ring-field'].every((look) => tick?.classList.contains(look))).toBe(true);
        act(() => tick?.click());
        expect(document.querySelector('.admin-blackouts__start')?.getAttribute('value')).toBe('09:00');
        expect(document.querySelector('.admin-blackouts__end')?.getAttribute('value')).toBe('17:00');
        press(add);
        expect(added).toEqual({ staff_id: null, date: '2026-12-24', until: null, start: '09:00', end: '17:00', reason: null });
    });

    it('labels the add form through the staff section, every field and the all-day tick by its key', () => {
        render([]);
        act(() => document.querySelector<HTMLInputElement>('.admin-blackouts__all-day-tick')?.click());

        const labels = Array.from(document.querySelectorAll('.admin-blackouts__form label')).map((label) => label.textContent);
        expect(labels).toEqual(expect.arrayContaining((['closures.date', 'closures.until', 'closures.reason', 'closures.all_day', 'closures.start', 'closures.end'] as const).map((key) => staff.text(key))));
        expect(document.querySelector('.admin-blackouts__form')?.textContent).toContain(keyedShell().text('shared.closure.reason_help'));
    });

    it('hands the row seen to onRemove, whole or partial', () => {
        render(ROWS);

        const buttons = document.querySelectorAll('.admin-blackouts__delete');
        press(buttons[1] ?? null);
        expect(removed).toEqual(DENTIST);
        press(buttons[0] ?? null);
        expect(removed).toEqual(CHRISTMAS);
    });

    it('shows the pressed Delete alone as busy while a removal is in flight, the other Delete and Add merely disabled with the reason in text', () => {
        render(ROWS, { action: 'remove', blackout: DENTIST });

        const buttons = document.querySelectorAll('.admin-blackouts__delete');
        expect(busyState(buttons[1])).toEqual({ busy: true, disabled: true, label: staff.text('closures.deleting') });
        expect(busyState(buttons[0])).toEqual({ busy: false, disabled: true, label: staff.text('closures.delete') });
        expect(busyState(document.querySelector('.admin-blackouts__add'))).toEqual({ busy: false, disabled: true, label: staff.text('closures.add') });
        expect(document.querySelector('.admin-blackouts__wait')?.textContent).toBe(staff.text('closures.wait_removing'));
        expect(document.querySelector('.admin-blackouts__wait')?.getAttribute('role')).toBe('status');

        press(buttons[0] ?? null);
        press(buttons[1] ?? null);
        expect(removed).toBeNull();
    });

    it('shows Add alone as busy while an add is in flight, every Delete merely disabled with the reason in text', () => {
        render(ROWS, { action: 'add' });

        const buttons = document.querySelectorAll('.admin-blackouts__delete');
        expect(busyState(document.querySelector('.admin-blackouts__add'))).toEqual({ busy: true, disabled: true, label: staff.text('closures.adding') });
        expect(busyState(buttons[0])).toEqual({ busy: false, disabled: true, label: staff.text('closures.delete') });
        expect(busyState(buttons[1])).toEqual({ busy: false, disabled: true, label: staff.text('closures.delete') });
        expect(document.querySelector('.admin-blackouts__wait')?.textContent).toBe(staff.text('closures.wait_adding'));

        press(buttons[0] ?? null);
        expect(removed).toBeNull();
        pick('2026-12-24');
        press(document.querySelector('.admin-blackouts__add'));
        expect(added).toBeNull();
    });

    it('hands an Until with the draft, and none once it is cleared', () => {
        render([]);

        pick('2026-12-24');
        press(document.querySelector('.admin-blackouts__until'));
        press(document.querySelector('button[data-date="2026-12-27"]'));
        press(document.querySelector('.admin-blackouts__add'));
        expect(added).toEqual({ staff_id: null, date: '2026-12-24', until: '2026-12-27', start: null, end: null, reason: null });
        expect(document.querySelector('#blackout-until-help')?.textContent).toBe(staff.text('closures.until_help'));

        press(document.querySelector('.admin-blackouts__until'));
        press(document.querySelector('.admin-date-field__clear'));
        press(document.querySelector('.admin-blackouts__add'));
        expect(added).toEqual({ staff_id: null, date: '2026-12-24', until: null, start: null, end: null, reason: null });
    });

    it('offers Who over the whole business and the members given, naming each line\'s member or the business beside its reason, and hands the member picked with the draft', () => {
        const lines = [{ ...DENTIST, staff_id: 2, reason: 'Dentist' }, { ...CHRISTMAS, staff_id: null }, { ...DENTIST, date: '2026-12-30', staff_id: null, reason: null }];
        render(lines, null, { offered: [{ id: 2, name: 'Elena Papadaki' }], names: new Map([[2, 'Elena Papadaki']]) });

        expect(Array.from(document.querySelectorAll('#blackout-who option')).map((option) => option.textContent)).toEqual([staff.text('closures.business'), 'Elena Papadaki']);
        expect(Array.from(document.querySelectorAll('.admin-blackouts__who')).map((line) => line.textContent)).toEqual([
            staff.text('closures.who_reason', { who: 'Elena Papadaki', reason: 'Dentist' }),
            staff.text('closures.who_reason', { who: staff.text('closures.business'), reason: 'Christmas Day' }),
            staff.text('closures.business'),
        ]);

        const select = document.querySelector<HTMLSelectElement>('#blackout-who');
        act(() => {
            if (select !== null) {
                select.value = '2';
                select.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });
        pick('2026-12-24');
        press(document.querySelector('.admin-blackouts__add'));
        expect(added).toEqual({ staff_id: 2, date: '2026-12-24', until: null, start: null, end: null, reason: null });
    });
});

describe('the closures card\'s look', () => {
    const classes = (element: Element | null | undefined) => [...(element?.classList ?? [])];
    const texts = (selector: string) => Array.from(document.querySelectorAll(selector)).map((element) => element.textContent);

    it('is a card named by its heading, holding the closures as a table that reads as labelled rows where it has no room', () => {
        render(ROWS);

        const card = document.querySelector('.admin-blackouts');
        expect(card?.tagName).toBe('SECTION');
        expect(classes(card)).toEqual(expect.arrayContaining(['bg-card', 'rounded-tile', 'shadow-card', '@container']));
        // No panel spelled by hand is left: no border of its own and no radius but the card's.
        expect(classes(card).filter((look) => /^(border|p-\d|rounded-(?!tile))/.test(look))).toEqual([]);
        expect(document.getElementById(card?.getAttribute('aria-labelledby') ?? '')?.textContent).toBe('card-title-token');

        const table = document.querySelector('.admin-blackouts__list');
        expect(table?.tagName).toBe('TABLE');
        expect(table?.getAttribute('role')).toBe('table');
        expect(classes(table)).toEqual(expect.arrayContaining(['admin-table', 'max-wide:block', '@max-2xl:block']));
        expect(texts('.admin-blackouts__list th[scope="col"]')).toEqual([staff.text('closures.date'), staff.text('closures.reason_column'), staff.text('closures.delete')]);
        expect(document.querySelector('.admin-blackouts__list th[scope="col"]:last-child > .sr-only')).not.toBeNull();

        const rows = Array.from(document.querySelectorAll('.admin-blackouts__row'));
        expect(rows.map((row) => row.tagName)).toEqual(['TR', 'TR']);
        expect(rows.map((row) => Array.from(row.querySelectorAll('.admin-table__label')).map((label) => label.textContent))).toEqual([
            [staff.text('closures.date'), staff.text('closures.reason_column')],
            [staff.text('closures.date'), staff.text('closures.reason_column')],
        ]);
        // The dates name their row; Delete stays in it as the row's last cell.
        expect(rows.map((row) => row.querySelector('th[scope="row"] .admin-blackouts__when')?.textContent)).toEqual(['25/12/2026', staff.text('closures.window', { date: '04/12/2026', time: '14:00', end: '16:00' })]);
        expect(rows.map((row) => row.querySelector('td:last-child .admin-blackouts__delete')?.textContent)).toEqual([staff.text('closures.delete'), staff.text('closures.delete')]);
        expect(rows.map((row) => row.querySelector('.admin-blackouts__reason')?.textContent ?? null)).toEqual(['Christmas Day', null]);
    });

    it('gives each Delete a touch area 44 pixels tall below the wide breakpoint and leaves it bare text above it', () => {
        render(ROWS);

        const deletes = Array.from(document.querySelectorAll('.admin-blackouts__delete'));
        expect(deletes).toHaveLength(2);

        for (const action of deletes) {
            expect(classes(action)).toEqual(expect.arrayContaining(['admin-button--link', 'h-auto', 'p-0', 'items-center', 'max-wide:min-h-11']));
            expect(classes(action).filter((look) => look.includes('min-h-'))).toEqual(['max-wide:min-h-11']);
        }
    });

    it('heads the reason column Who on the list, each line naming its member or the business', () => {
        const lines = [{ ...DENTIST, staff_id: 2, reason: 'Dentist' }];
        render(lines, null, { offered: [{ id: 2, name: 'Elena Papadaki' }], names: new Map([[2, 'Elena Papadaki']]) });

        expect(texts('.admin-blackouts__list th[scope="col"]')).toEqual([staff.text('closures.date'), staff.text('closures.who'), staff.text('closures.delete')]);
        expect(texts('.admin-blackouts__row .admin-table__label')).toEqual([staff.text('closures.date'), staff.text('closures.who')]);
        expect(texts('.admin-blackouts__row .admin-blackouts__who')).toEqual([staff.text('closures.who_reason', { who: 'Elena Papadaki', reason: 'Dentist' })]);
    });

    it('lays the add form out by the card\'s width, Who and Reason across it, and draws Closed all day as a check field', () => {
        render([], null, { offered: [{ id: 2, name: 'Elena Papadaki' }], names: new Map([[2, 'Elena Papadaki']]) });

        const form = document.querySelector('.admin-blackouts__form');
        expect(classes(form)).toEqual(expect.arrayContaining(['grid', '@xl:grid-cols-2']));
        // Nothing under the card lays itself out by the window's own breakpoints.
        expect(Array.from(document.querySelectorAll('.admin-blackouts, .admin-blackouts *')).flatMap((element) => classes(element).filter((look) => /^(max-)?desktop:/.test(look)))).toEqual([]);
        expect(document.querySelector('#blackout-who')?.closest('.admin-field')?.classList.contains('@xl:col-span-2')).toBe(true);
        expect(document.querySelector('#blackout-reason')?.closest('.admin-field')?.classList.contains('@xl:col-span-2')).toBe(true);
        expect(classes(document.querySelector('.admin-blackouts__all-day'))).toContain('admin-check-field');
        expect(document.querySelector('.admin-blackouts__all-day .admin-check-field__tick')).toBe(document.querySelector('#blackout-all-day.admin-blackouts__all-day-tick'));
        expect(document.querySelector('label[for="blackout-all-day"]')?.textContent).toBe(staff.text('closures.all_day'));
    });
});
