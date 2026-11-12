import { act, useState } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { Combobox } from './combobox';
import { drag, key, mount, press, settle, type Mounted } from './harness';
import { Modal } from './modal';
import { keyedShell } from '@/testing/keyed';

/*
 * The combobox: a person's name shown, an id returned, and the value changed only when somebody
 * picks an option. Typing filters and nothing more. Closed it shows the chosen label, open the
 * query. Arrows open and wrap, Enter chooses, Escape closes and clears the query. Options are
 * chosen on mousedown, because the input's blur closes the list before a click could land. A
 * press outside is an abandoned search; a press on the input itself is not outside. Focus stays
 * in the input throughout. Inside a modal, Escape closes the list and leaves the modal open.
 * Clear shows while something is chosen.
 *
 * Deterministic by construction: no geometry and no clock. The one wait is the harness's single
 * task, for the outside-press listener the popover adds a task after it opens.
 */

const USERS = [
    { value: 7, label: 'Maria Papadopoulou' },
    { value: 9, label: 'Nikos Georgiou' },
    { value: 12, label: 'Eleni Markou' },
];

let view: Mounted;

beforeEach(() => {
    view = mount();
});

afterEach(() => view.unmount());

function Linked({ initial = null, onChange }: { initial?: number | null; onChange?: (value: number | null) => void }) {
    const [value, setValue] = useState<number | null>(initial);

    return (
        <Combobox
            id="linked-user"
            aria-label="combobox-label-token"
            options={USERS}
            value={value}
            onChange={(next) => {
                onChange?.(next);
                setValue(next);
            }}
        />
    );
}

const input = () => document.querySelector<HTMLInputElement>('#linked-user');
const options = () => [...document.querySelectorAll<HTMLElement>('[role="option"]')];
const highlighted = () => document.getElementById(input()?.getAttribute('aria-activedescendant') ?? '')?.textContent;
const clear = () => document.querySelector('.admin-combobox__clear');

function type(text: string): void {
    const field = input();

    if (field === null) {
        throw new Error('No input.');
    }

    act(() => {
        const setter = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')?.set;
        setter?.call(field, text);
        field.dispatchEvent(new Event('input', { bubbles: true }));
    });
}

function mousedown(element: Element | undefined): boolean {
    let proceeded = true;

    act(() => {
        proceeded = element?.dispatchEvent(new MouseEvent('mousedown', { bubbles: true, cancelable: true })) ?? true;
    });

    return proceeded;
}

describe('the combobox', () => {
    it('stands 32px high, the text input\'s height', () => {
        view.render(<Linked initial={9} />);

        expect(input()?.classList.contains('h-8')).toBe(true);
    });

    it('is a combobox showing the chosen label while closed', () => {
        view.render(<Linked initial={9} />);

        expect(input()?.getAttribute('role')).toBe('combobox');
        expect(input()?.getAttribute('aria-expanded')).toBe('false');
        expect(input()?.value).toBe('Nikos Georgiou');
    });

    it('filters as the query is typed and never changes the value', () => {
        const onChange = vi.fn();
        view.render(<Linked initial={9} onChange={onChange} />);

        type('mar');

        expect(input()?.value).toBe('mar');
        expect(input()?.getAttribute('aria-expanded')).toBe('true');
        expect(options().map((option) => option.textContent)).toEqual(['Maria Papadopoulou', 'Eleni Markou']);
        expect(onChange).not.toHaveBeenCalled();
    });

    it('chooses on mousedown, keeping focus in the input, and closes', () => {
        const onChange = vi.fn();
        view.render(<Linked onChange={onChange} />);

        type('eleni');
        const proceeded = mousedown(options()[0]);

        expect(proceeded).toBe(false);
        expect(onChange).toHaveBeenCalledWith(12);
        expect(options()).toHaveLength(0);
        expect(input()?.value).toBe('Eleni Markou');
    });

    it('closes on blur, which is why a click on an option would never land', () => {
        const onChange = vi.fn();
        view.render(<Linked onChange={onChange} />);

        input()?.focus();
        type('eleni');
        expect(options()).toHaveLength(1);

        act(() => input()?.blur());

        expect(options()).toHaveLength(0);
        expect(onChange).not.toHaveBeenCalled();
    });

    it('opens on ArrowDown, wraps from the last option to the first, and chooses on Enter', () => {
        const onChange = vi.fn();
        view.render(<Linked onChange={onChange} />);

        key(input(), 'ArrowDown');
        expect(input()?.getAttribute('aria-expanded')).toBe('true');
        expect(highlighted()).toBe('Maria Papadopoulou');

        key(input(), 'ArrowDown');
        key(input(), 'ArrowDown');
        expect(highlighted()).toBe('Eleni Markou');

        key(input(), 'ArrowDown');
        expect(highlighted()).toBe('Maria Papadopoulou');

        key(input(), 'ArrowUp');
        expect(highlighted()).toBe('Eleni Markou');

        key(input(), 'Enter');
        expect(onChange).toHaveBeenCalledWith(12);
        expect(options()).toHaveLength(0);
    });

    it('opens with the chosen option highlighted', () => {
        view.render(<Linked initial={9} />);

        key(input(), 'ArrowDown');

        expect(highlighted()).toBe('Nikos Georgiou');
        expect(document.querySelector('[aria-selected="true"]')?.textContent).toBe('Nikos Georgiou');
    });

    it('closes on Escape and clears the query, leaving the value', () => {
        const onChange = vi.fn();
        view.render(<Linked initial={9} onChange={onChange} />);

        type('mar');
        key(input(), 'Escape');

        expect(options()).toHaveLength(0);
        expect(input()?.value).toBe('Nikos Georgiou');
        expect(onChange).not.toHaveBeenCalled();

        key(input(), 'ArrowDown');
        expect(options()).toHaveLength(3);
    });

    it('keeps focus in the input while the list opens and after it closes', () => {
        view.render(<Linked initial={9} />);

        input()?.focus();
        key(input(), 'ArrowDown');
        expect(options()).toHaveLength(3);
        expect(document.activeElement).toBe(input());

        key(input(), 'Escape');
        expect(options()).toHaveLength(0);
        expect(document.activeElement).toBe(input());
    });

    it('inside a modal, Escape closes the list and leaves the modal open, and the next closes the modal', () => {
        const onClose = vi.fn();
        view.render(
            <Modal open title="modal-title-token" onClose={onClose}>
                <Linked initial={9} />
            </Modal>,
        );

        input()?.focus();
        type('mar');
        key(input(), 'Escape');

        expect(options()).toHaveLength(0);
        expect(input()?.value).toBe('Nikos Georgiou');
        expect(document.activeElement).toBe(input());
        expect(onClose).not.toHaveBeenCalled();

        key(input(), 'Escape');

        expect(onClose).toHaveBeenCalledOnce();
    });

    it('treats a press outside as an abandoned search, showing what is still chosen', async () => {
        const onChange = vi.fn();
        view.render(
            <>
                <Linked initial={9} onChange={onChange} />
                <p className="elsewhere">Elsewhere</p>
            </>,
        );

        type('mar');
        await settle();
        drag(document.querySelector('.elsewhere'), document.querySelector('.elsewhere'));

        expect(options()).toHaveLength(0);
        expect(input()?.value).toBe('Nikos Georgiou');
        expect(onChange).not.toHaveBeenCalled();
    });

    it('keeps the search on a press in the input itself, which is not outside', async () => {
        view.render(<Linked initial={9} />);

        type('mar');
        await settle();
        drag(input(), input());

        expect(options()).toHaveLength(2);
        expect(input()?.value).toBe('mar');
    });

    it('shows Clear only while something is chosen, and Clear empties the value', () => {
        const onChange = vi.fn();
        view.render(<Linked onChange={onChange} />);

        expect(clear()).toBeNull();

        key(input(), 'ArrowDown');
        key(input(), 'Enter');
        expect(clear()?.textContent).toBe(keyedShell().text('kit.combobox.clear'));

        press(clear());

        expect(onChange).toHaveBeenLastCalledWith(null);
        expect(clear()).toBeNull();
        expect(input()?.value).toBe('');
    });

    it('says so when nothing matches', () => {
        view.render(<Linked />);

        type('zz');

        expect(options()).toHaveLength(0);
        expect(document.querySelector('.admin-combobox__empty')?.textContent).toBe(keyedShell().text('kit.combobox.no_matches'));
    });
});
