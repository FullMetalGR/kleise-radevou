import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
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

    it('draws a locked, ticked box itself in the theme colour at full strength, the browser\'s own disabled paint being too faint to read as ticked', () => {
        const locked = CHECK_BOX.split(' ').filter((name) => name.startsWith('disabled:checked:'));

        expect(locked).toEqual(
            expect.arrayContaining([
                'disabled:checked:appearance-none',
                'disabled:checked:bg-primary',
                'disabled:checked:before:bg-primary-foreground',
                'disabled:checked:before:size-3',
            ]),
        );
        expect(CHECK_BOX.split(' ')).toContain('disabled:cursor-not-allowed');
        // A box the person can still change keeps the browser's own paint in the theme colour.
        expect(CHECK_BOX.split(' ').filter((name) => /^(checked:|enabled:)/.test(name))).toEqual([]);
    });

    it('hands a locked, ticked box back to the browser in forced colours, its drawn tick hidden, where a fill of its own would be repainted as the page', () => {
        expect(CHECK_BOX.split(' ').filter((name) => name.startsWith('forced-colors:'))).toEqual(['forced-colors:disabled:checked:appearance-auto', 'forced-colors:disabled:checked:before:hidden']);
    });

    it('lets the native box follow the mode: the stylesheet declares a light scheme on the root and a dark one under the dark mode', () => {
        const sheet = readFileSync(resolve(process.cwd(), 'resources/js/admin/app.css'), 'utf8').replace(/\/\*[\s\S]*?\*\//g, '');
        const first = (selector: string) => new RegExp(`(?:^|\\n)${selector} \\{([^{}]*)\\}`).exec(sheet)?.[1] ?? '';

        expect(first(':root')).toMatch(/(^|;)\s*color-scheme: light;/);
        expect(first('\\.dark')).toMatch(/(^|;)\s*color-scheme: dark;/);
    });

    it('hands the new state to its owner', () => {
        const onChange = vi.fn();
        view.render(<CheckField id="reminders" label="Send reminders" help="A day before." checked={false} onChange={onChange} />);

        press(box());

        expect(onChange).toHaveBeenCalledWith(true);
    });
});
