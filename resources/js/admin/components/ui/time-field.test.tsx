import { act, useState } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { TenantClock } from '@/lib/format';
import { key, mount, press, settle, type Mounted } from './harness';
import { Modal } from './modal';
import { TimeField, timesOfDay } from './time-field';

/*
 * The time field. The value in and out is a machine time, empty meaning none, the trigger
 * spelling the tenant's time format. The popover lists every time of the day at the tenant's
 * granularity. A stored value off that grid is displayed and kept, never snapped: spliced in at
 * its place and marked selected, so re-picking it is an exact no-op. Typing jumps the highlight
 * and filters nothing, searching the display labels first and then the machine values. The list
 * opens in the shared popover, takes focus, inside a modal as well, and hands it back to the
 * trigger on close; inside a modal one Escape peels the list alone.
 *
 * Deterministic by construction: no geometry and no clock. A typed character carries the time it
 * was typed at, so the type-ahead's one-second window is read from the event and never from how
 * long the run took; the one wait is the harness's single task, for the focus the popover hands
 * back once it has unmounted.
 */

const H24: TenantClock = { timezone: 'Europe/Athens', dateFormat: 'd/m/Y', timeFormat: 'H:i' };
const H12: TenantClock = { ...H24, timeFormat: 'g:i A' };

let view: Mounted;

beforeEach(() => {
    view = mount();
});

afterEach(() => view.unmount());

function Start({ initial, clock = H24, onChange }: { initial: string; clock?: TenantClock; onChange?: (value: string) => void }) {
    const [value, setValue] = useState(initial);

    return (
        <TimeField
            id="start"
            aria-label="Start"
            value={value}
            clock={clock}
            granularity={5}
            onChange={(next) => {
                onChange?.(next);
                setValue(next);
            }}
        />
    );
}

const trigger = () => document.querySelector<HTMLButtonElement>('#start');
const listbox = () => document.querySelector<HTMLElement>('[role="listbox"]');
const options = () => [...document.querySelectorAll<HTMLElement>('[role="option"]')];
const option = (value: string) => document.querySelector<HTMLElement>(`[role="option"][data-value="${value}"]`);
const highlighted = () => document.getElementById(listbox()?.getAttribute('aria-activedescendant') ?? '')?.textContent;
const dialogs = () => document.querySelectorAll('[role="dialog"]');

/** A character typed on the list at a given moment, in milliseconds, inside act. */
function type(character: string, at: number): void {
    const event = new KeyboardEvent('keydown', { key: character, bubbles: true, cancelable: true });

    Object.defineProperty(event, 'timeStamp', { value: at });
    act(() => listbox()?.dispatchEvent(event));
}

describe('the times of a day', () => {
    it('holds every time of the day at the granularity, 288 at five minutes', () => {
        const times = timesOfDay(5, '');

        expect(times).toHaveLength(288);
        expect(times[0]).toBe('00:00');
        expect(times[1]).toBe('00:05');
        expect(times[287]).toBe('23:55');
        expect(timesOfDay(15, '')).toHaveLength(96);
    });

    it('splices a kept value off the grid into its chronological place', () => {
        const times = timesOfDay(5, '09:07');

        expect(times).toHaveLength(289);
        expect(times.slice(times.indexOf('09:05'), times.indexOf('09:05') + 3)).toEqual(['09:05', '09:07', '09:10']);
    });

    it('keeps a value spelled with seconds exactly as it came, in place of the grid time it names', () => {
        const times = timesOfDay(5, '09:05:00');

        expect(times).toHaveLength(288);
        expect(times).toContain('09:05:00');
        expect(times).not.toContain('09:05');
    });

    it('splices nothing for an empty value or one that is not a time', () => {
        expect(timesOfDay(5, 'noon')).toHaveLength(288);
        expect(timesOfDay(5, '24:00')).toHaveLength(288);
    });
});

describe('the time field', () => {
    it('stands 32px high, the text input\'s height', () => {
        view.render(<Start initial="" />);

        expect(trigger()?.classList.contains('h-8')).toBe(true);
    });

    it('renders an empty trigger for the empty value and the tenant format otherwise, the machine value in the DOM', () => {
        view.render(<Start initial="" />);
        expect(trigger()?.textContent).toBe('');
        expect(trigger()?.value).toBe('');

        view.render(<Start key="later" initial="14:30" clock={H12} />);
        expect(trigger()?.textContent).toBe('2:30 PM');
        expect(trigger()?.value).toBe('14:30');
    });

    it('opens a listbox of every time of the day at the granularity, focused, the trigger expanded', () => {
        view.render(<Start initial="" />);
        expect(trigger()?.getAttribute('aria-haspopup')).toBe('listbox');
        expect(trigger()?.getAttribute('aria-expanded')).toBe('false');

        press(trigger());

        expect(options()).toHaveLength(288);
        expect(document.activeElement).toBe(listbox());
        expect(trigger()?.getAttribute('aria-expanded')).toBe('true');
        expect(listbox()?.closest('.admin-popover')).not.toBeNull();
    });

    it('closes on a second press of the trigger, changing nothing', () => {
        const onChange = vi.fn();
        view.render(<Start initial="09:00" onChange={onChange} />);

        press(trigger());
        press(trigger());

        expect(listbox()).toBeNull();
        expect(onChange).not.toHaveBeenCalled();
    });

    it('keeps a stored value off the grid: spliced between its neighbours, selected, and an exact no-op when re-picked', async () => {
        const onChange = vi.fn();
        view.render(<Start initial="09:07" onChange={onChange} />);

        expect(trigger()?.textContent).toBe('09:07');

        press(trigger());
        const values = options().map((each) => each.getAttribute('data-value'));
        const at = values.indexOf('09:07');

        expect(options()).toHaveLength(289);
        expect(values.slice(at - 1, at + 2)).toEqual(['09:05', '09:07', '09:10']);
        expect(option('09:07')?.getAttribute('aria-selected')).toBe('true');
        expect(highlighted()).toBe('09:07');

        press(option('09:07'));
        await settle();

        expect(onChange).not.toHaveBeenCalled();
        expect(trigger()?.value).toBe('09:07');
        expect(listbox()).toBeNull();
        expect(document.activeElement).toBe(trigger());
    });

    it('moves a value onto the grid only when a different time is picked, and hands focus back', async () => {
        const onChange = vi.fn();
        view.render(<Start initial="09:07" onChange={onChange} />);

        press(trigger());
        press(option('09:10'));
        await settle();

        expect(onChange).toHaveBeenCalledWith('09:10');
        expect(trigger()?.value).toBe('09:10');
        expect(document.activeElement).toBe(trigger());
    });

    it('reaches the kept value from the keyboard and picks with Enter', () => {
        const onChange = vi.fn();
        view.render(<Start initial="09:07" onChange={onChange} />);

        press(trigger());
        key(listbox(), 'ArrowDown');
        expect(highlighted()).toBe('09:10');

        key(listbox(), 'ArrowUp');
        key(listbox(), 'ArrowUp');
        expect(highlighted()).toBe('09:05');

        key(listbox(), 'Enter');
        expect(onChange).toHaveBeenCalledWith('09:05');
        expect(listbox()).toBeNull();
    });

    it('jumps on typing through the display labels first: "9" on a twelve-hour tenant is nine in the morning', () => {
        view.render(<Start initial="" clock={H12} />);
        press(trigger());

        type('9', 1000);

        expect(highlighted()).toBe('9:00 AM');
        expect(options()).toHaveLength(288);
    });

    it('falls back to the machine values: "14" on a twelve-hour tenant is two in the afternoon', () => {
        view.render(<Start initial="" clock={H12} />);
        press(trigger());

        type('1', 1000);
        type('4', 1500);

        expect(highlighted()).toBe('2:00 PM');
        expect(options()).toHaveLength(288);
    });

    it('starts the search afresh after a second without typing', () => {
        view.render(<Start initial="" clock={H12} />);
        press(trigger());

        type('1', 1000);
        type('4', 2000);

        expect(highlighted()).toBe('4:00 AM');
    });

    it('lands "9" on 09:00 on a twenty-four-hour tenant, the leading zero unread', () => {
        view.render(<Start initial="" />);
        press(trigger());

        type('9', 1000);

        expect(highlighted()).toBe('09:00');
    });

    it('closes on Escape and hands focus back to the trigger, changing nothing', async () => {
        const onChange = vi.fn();
        view.render(<Start initial="09:00" onChange={onChange} />);

        press(trigger());
        key(listbox(), 'Escape');
        await settle();

        expect(listbox()).toBeNull();
        expect(document.activeElement).toBe(trigger());
        expect(onChange).not.toHaveBeenCalled();
    });

    it('inside a modal, opens with focus on the list, one Escape closes it and leaves the modal open, and the next closes the modal', async () => {
        const onClose = vi.fn();
        view.render(
            <Modal open title="modal-title-token" onClose={onClose}>
                <Start initial="09:00" />
            </Modal>,
        );

        press(trigger());

        expect(document.activeElement).toBe(listbox());

        key(document.activeElement, 'Escape');
        await settle();

        expect(listbox()).toBeNull();
        expect(dialogs()).toHaveLength(1);
        expect(document.activeElement).toBe(trigger());
        expect(onClose).not.toHaveBeenCalled();

        key(trigger(), 'Escape');

        expect(onClose).toHaveBeenCalledOnce();
    });
});
