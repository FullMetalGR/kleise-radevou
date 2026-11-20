import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { CHECK_BOX, CheckField } from './check-field';
import { mount, press, type Mounted } from './harness';

/*
 * The check field: a 20px box tinted by the theme colour with its label beside it, the label
 * pointing at the box by id. Where it is given help, a line under both hangs off
 * aria-describedby, drawn as every other field draws its help; given none, it draws no line and
 * the box points at none.
 */

let view: Mounted;

beforeEach(() => {
    view = mount();
});

afterEach(() => view.unmount());

const box = () => view.host.querySelector<HTMLInputElement>('input[type="checkbox"]');

describe('the check field', () => {
    it('draws a 20px box tinted by the theme colour, with the field\'s own focus outline', () => {
        view.render(<CheckField id="reminders" label="Send reminders" help="A day before." checked={false} onChange={() => {}} />);

        expect(box()?.classList.contains('size-5')).toBe(true);
        expect(box()?.classList.contains('accent-primary')).toBe(true);
        expect(box()?.classList.contains('focus-ring-field')).toBe(true);
    });

    it('spells the box once, for the check field and for a tick written by hand', () => {
        view.render(<CheckField id="reminders" label="Send reminders" help="A day before." checked={false} onChange={() => {}} />);

        expect(CHECK_BOX.split(' ')).toEqual(expect.arrayContaining(['size-5', 'shrink-0', 'accent-primary', 'focus-ring-field']));
        // A disabled box is faint as the browser paints it, and a ticked one must still read as ticked.
        expect(CHECK_BOX.split(' ').filter((name) => name.includes('opacity'))).toEqual([]);
        expect(box()?.className).toBe(`admin-check-field__tick ${CHECK_BOX}`);
    });

    it('points the label at the box and reads the help after it, the help drawn as a field\'s', () => {
        view.render(<CheckField id="reminders" label="Send reminders" help="A day before." checked={true} onChange={() => {}} />);

        const help = document.getElementById(box()?.getAttribute('aria-describedby') ?? '');

        expect(view.host.querySelector('label')?.getAttribute('for')).toBe('reminders');
        expect(view.host.querySelector('label')?.textContent).toBe('Send reminders');
        expect(help?.textContent).toBe('A day before.');
        expect(help?.classList.contains('admin-field__help')).toBe(true);
        expect(help?.classList.contains('text-label')).toBe(true);
        expect(box()?.checked).toBe(true);
    });

    it('puts the hook a place names on the box beside the box\'s own', () => {
        view.render(<CheckField id="inactive" label="Show inactive (2)" tickClassName="admin-list-toggle__tick" checked={false} onChange={() => {}} />);

        expect(box()?.className).toBe(`admin-check-field__tick ${CHECK_BOX} admin-list-toggle__tick`);
    });

    it('draws no help line and points the box at none when the place has nothing to explain', () => {
        view.render(<CheckField id="inactive" label="Show inactive (2)" checked={false} onChange={() => {}} />);

        expect(view.host.querySelector('.admin-field__help')).toBeNull();
        expect(view.host.querySelectorAll('p')).toHaveLength(0);
        expect(box()?.hasAttribute('aria-describedby')).toBe(false);
        expect(view.host.querySelector('label')?.getAttribute('for')).toBe('inactive');
    });

    it('hands the new state to its owner', () => {
        const onChange = vi.fn();
        view.render(<CheckField id="reminders" label="Send reminders" help="A day before." checked={false} onChange={onChange} />);

        press(box());

        expect(onChange).toHaveBeenCalledWith(true);
    });
});
