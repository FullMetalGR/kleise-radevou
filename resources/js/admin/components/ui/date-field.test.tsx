import { act, useState } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { TenantClock } from '@/lib/format';
import { DateField } from './date-field';
import { key, mount, press, settle, type Mounted } from './harness';
import { Modal } from './modal';
import { weekdayName } from '@/lib/names';
import { keyedShell } from '@/testing/keyed';

/*
 * The date field: the value in and out is a machine date and the empty string is a legal value
 * meaning no date, reachable again through Clear. The trigger spells the tenant's date format,
 * never the browser locale, and carries the raw value as its DOM value; a value it cannot parse
 * renders verbatim and is never rewritten. The popover's calendar, the date library's, starts on
 * the tenant's week start and takes focus on the chosen day, inside a modal as well. A pick, Clear
 * or Escape closes it and hands focus back to the trigger; a press outside closes it and leaves
 * focus on what was pressed; inside a modal one Escape peels the popover alone.
 *
 * Deterministic by construction: no geometry and no clock, the calendar's today passed in. The one
 * wait is the harness's single task, for what the popover defers to the next one: focus handed
 * back once it has unmounted, and its outside-press listener, which it adds a task after opening.
 */

const CLOCK: TenantClock = { timezone: 'Europe/Athens', dateFormat: 'd/m/Y', timeFormat: 'H:i' };

let view: Mounted;

beforeEach(() => {
    view = mount();
});

afterEach(() => view.unmount());

function From({ initial, weekStart = 1, onChange }: { initial: string; weekStart?: number; onChange?: (value: string) => void }) {
    const [value, setValue] = useState(initial);

    return (
        <DateField
            id="from"
            aria-label="From"
            value={value}
            clock={CLOCK}
            weekStart={weekStart}
            today="2026-09-24"
            onChange={(next) => {
                onChange?.(next);
                setValue(next);
            }}
        />
    );
}

const trigger = () => document.querySelector<HTMLButtonElement>('#from');
const popover = () => document.querySelector('.admin-date-field__popover');
const open = () => press(trigger());
const firstColumn = () => document.querySelector('.admin-date-field__popover [role="grid"] th[scope="col"]')?.getAttribute('aria-label');
const dialogs = () => [...document.querySelectorAll('[role="dialog"]')];

/**
 * A press on a focusable element as a browser delivers it: the pointer down, focus moving to the
 * element as the mouse goes down, then the release and the click.
 */
function pressMovingFocus(element: HTMLElement | null): void {
    const init = { bubbles: true, cancelable: true, button: 0 };

    act(() => {
        element?.dispatchEvent(new PointerEvent('pointerdown', init));
        element?.dispatchEvent(new MouseEvent('mousedown', init));
        element?.focus();
    });
    act(() => {
        element?.dispatchEvent(new PointerEvent('pointerup', init));
        element?.dispatchEvent(new MouseEvent('mouseup', init));
        element?.dispatchEvent(new MouseEvent('click', init));
    });
}

describe('the date field', () => {
    it('stands 32px high, the text input\'s height', () => {
        view.render(<From initial="2026-11-03" />);

        expect(trigger()?.classList.contains('h-8')).toBe(true);
    });

    it('renders an empty trigger for the empty value', () => {
        view.render(<From initial="" />);

        expect(trigger()?.textContent).toBe('');
        expect(trigger()?.value).toBe('');
    });

    it('spells the value in the tenant date format and keeps the machine value in the DOM', () => {
        view.render(<From initial="2026-11-03" />);

        expect(trigger()?.textContent).toBe('03/11/2026');
        expect(trigger()?.value).toBe('2026-11-03');
    });

    it('renders a value it cannot parse verbatim and never rewrites it', () => {
        const onChange = vi.fn();
        view.render(<From initial="2026-02-30" onChange={onChange} />);

        expect(trigger()?.textContent).toBe('2026-02-30');
        expect(trigger()?.value).toBe('2026-02-30');

        open();
        key(document.activeElement, 'Escape');

        expect(onChange).not.toHaveBeenCalled();
        expect(trigger()?.value).toBe('2026-02-30');
    });

    it('opens a dialog named for choosing a date, the trigger expanded, and a second press closes it', () => {
        view.render(<From initial="2026-11-03" />);
        expect(trigger()?.getAttribute('aria-haspopup')).toBe('dialog');
        expect(trigger()?.getAttribute('aria-expanded')).toBe('false');

        open();

        expect(popover()?.getAttribute('role')).toBe('dialog');
        expect(popover()?.getAttribute('aria-label')).toBe(keyedShell().text('kit.date_field.choose'));
        expect(popover()?.classList.contains('admin-popover')).toBe(true);
        expect(trigger()?.getAttribute('aria-expanded')).toBe('true');

        open();

        expect(popover()).toBeNull();
    });

    it('opens a grid whose first column is the tenant week start', () => {
        view.render(<From initial="2026-11-03" weekStart={1} />);
        open();

        expect(firstColumn()).toBe(weekdayName(1, 'long', 'en'));

        view.unmount();
        view = mount();
        view.render(<From initial="2026-11-03" weekStart={7} />);
        open();

        expect(firstColumn()).toBe(weekdayName(7, 'long', 'en'));
    });

    it('opens with focus on the chosen day', () => {
        view.render(<From initial="2026-11-03" />);
        open();

        expect(document.activeElement?.getAttribute('data-date')).toBe('2026-11-03');
    });

    it('returns the machine date of the day picked, closes and hands focus back', async () => {
        const onChange = vi.fn();
        view.render(<From initial="" onChange={onChange} />);

        open();
        press(document.querySelector('[data-date="2026-09-17"]'));
        await settle();

        expect(onChange).toHaveBeenCalledWith('2026-09-17');
        expect(popover()).toBeNull();
        expect(trigger()?.textContent).toBe('17/09/2026');
        expect(document.activeElement).toBe(trigger());
    });

    it('returns the empty string from Clear, the empty value reachable again, and hands focus back', async () => {
        const onChange = vi.fn();
        view.render(<From initial="2026-11-03" onChange={onChange} />);

        open();
        expect(document.querySelector('.admin-date-field__clear')?.textContent).toBe(keyedShell().text('kit.date_field.clear'));
        press(document.querySelector('.admin-date-field__clear'));
        await settle();

        expect(onChange).toHaveBeenCalledWith('');
        expect(popover()).toBeNull();
        expect(trigger()?.textContent).toBe('');
        expect(trigger()?.value).toBe('');
        expect(document.activeElement).toBe(trigger());
    });

    it('closes on Escape inside the popover and hands focus back to the trigger, changing nothing', async () => {
        const onChange = vi.fn();
        view.render(<From initial="2026-11-03" onChange={onChange} />);

        open();
        key(document.activeElement, 'Escape');
        await settle();

        expect(popover()).toBeNull();
        expect(document.activeElement).toBe(trigger());
        expect(onChange).not.toHaveBeenCalled();
    });

    it('closes on a press outside, leaving focus on what was pressed and the value as it was', async () => {
        const onChange = vi.fn();
        view.render(
            <>
                <From initial="2026-11-03" onChange={onChange} />
                <button type="button" className="elsewhere">
                    Elsewhere
                </button>
            </>,
        );

        open();
        await settle();
        const elsewhere = document.querySelector<HTMLElement>('.elsewhere');

        pressMovingFocus(elsewhere);
        await settle();

        expect(popover()).toBeNull();
        expect(document.activeElement).toBe(elsewhere);
        expect(onChange).not.toHaveBeenCalled();
    });

    it('inside a modal, opens with focus on the chosen day, one Escape closes the popover and leaves the modal open, and the next closes the modal', async () => {
        const onClose = vi.fn();
        view.render(
            <Modal open title="Add a closure" onClose={onClose}>
                <From initial="2026-11-03" />
            </Modal>,
        );

        open();

        expect(document.activeElement?.getAttribute('data-date')).toBe('2026-11-03');
        expect(popover()?.contains(document.activeElement)).toBe(true);

        key(document.activeElement, 'Escape');
        await settle();

        expect(popover()).toBeNull();
        expect(dialogs()).toHaveLength(1);
        expect(document.activeElement).toBe(trigger());
        expect(onClose).not.toHaveBeenCalled();

        key(trigger(), 'Escape');

        expect(onClose).toHaveBeenCalledOnce();
    });
});
