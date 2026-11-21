import { useState } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { key, mount, press, type Mounted } from './harness';
import { RadioPills } from './radio-pills';

/*
 * The radio pills: one setting of a few short choices as a fieldset named by its legend, each
 * choice a native radio hidden inside its pill label, so the browser's own radio group gives one
 * tab stop and moves the choice on the arrow keys. The chosen pill is filled in the ink with the
 * page's colour for its words, the keyboard's focus is shown on the pill, and a held group
 * disables every radio.
 */

let view: Mounted;

beforeEach(() => {
    view = mount();
});

afterEach(() => view.unmount());

const OPTIONS = [
    { value: 'staff', label: 'staff-token' },
    { value: 'category', label: 'category-token' },
    { value: 'status', label: 'status-token' },
] as const;

type Choice = (typeof OPTIONS)[number]['value'];

function Harness({ disabled = false, onChange }: { disabled?: boolean; onChange?: (value: Choice) => void }) {
    const [value, setValue] = useState<Choice>('category');

    return (
        <RadioPills
            name="colour-by"
            legend="Colour by"
            value={value}
            options={OPTIONS}
            disabled={disabled}
            onChange={(next) => {
                setValue(next);
                onChange?.(next);
            }}
        />
    );
}

const radios = () => [...view.host.querySelectorAll<HTMLInputElement>('input[type="radio"]')];

const labels = () => [...view.host.querySelectorAll<HTMLLabelElement>('label')];

describe('the radio pills', () => {
    it('is one fieldset named by its legend', () => {
        view.render(<Harness />);

        const root = view.host.firstElementChild;

        expect(root?.tagName).toBe('FIELDSET');
        expect(root?.classList.contains('admin-radio-pills')).toBe(true);
        expect(root?.querySelector(':scope > legend')?.textContent).toBe('Colour by');
        expect(view.host.querySelectorAll('fieldset')).toHaveLength(1);
    });

    it('makes each choice a native radio of the one name, hidden inside its own label', () => {
        view.render(<Harness />);

        expect(radios().map((radio) => [radio.name, radio.value, radio.closest('label')?.textContent])).toEqual([
            ['colour-by', 'staff', 'staff-token'],
            ['colour-by', 'category', 'category-token'],
            ['colour-by', 'status', 'status-token'],
        ]);
        expect(radios().every((radio) => radio.classList.contains('sr-only'))).toBe(true);
        // The radios keep the browser's own order of focus: one tab stop for the group.
        expect(radios().filter((radio) => radio.hasAttribute('tabindex'))).toEqual([]);
    });

    it('fills the chosen pill in the ink with the page colour for its words, the chosen one alone', () => {
        view.render(<Harness />);

        expect(radios().map((radio) => radio.checked)).toEqual([false, true, false]);
        expect(labels().map((label) => ['bg-foreground', 'text-background'].every((look) => label.classList.contains(look)))).toEqual([false, true, false]);
    });

    it('shows the keyboard focus on the pill, the radio inside it being hidden', () => {
        view.render(<Harness />);

        for (const label of labels()) {
            expect([...label.classList].filter((look) => look.startsWith('has-focus-visible:'))).toEqual(expect.arrayContaining(['has-focus-visible:outline-3', 'has-focus-visible:outline-primary']));
        }
    });

    it('moves the choice on the arrow keys as native radios do: the key is left to the browser, and the change it fires chooses', () => {
        const chosen = vi.fn();
        view.render(<Harness onChange={chosen} />);

        const arrow = new KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true, cancelable: true });
        radios()[1]?.dispatchEvent(arrow);
        expect(arrow.defaultPrevented).toBe(false);
        key(radios()[1] ?? null, 'ArrowLeft');
        // Neither key chose anything: the part holds no key handler of its own.
        expect(chosen).not.toHaveBeenCalled();

        // The browser checks the next radio and fires its change; a click delivers the same.
        press(radios()[2] ?? null);

        expect(chosen).toHaveBeenLastCalledWith('status');
        expect(radios().map((radio) => radio.checked)).toEqual([false, false, true]);
        expect(labels().map((label) => label.classList.contains('bg-foreground'))).toEqual([false, false, true]);
    });

    it('disables every radio when held, and none otherwise', () => {
        view.render(<Harness disabled />);

        expect(radios().map((radio) => radio.disabled)).toEqual([true, true, true]);

        view.render(<Harness />);

        expect(radios().map((radio) => radio.disabled)).toEqual([false, false, false]);
    });

    it('draws what it is handed under the pills, inside the fieldset, and takes the place\'s own class', () => {
        view.render(
            <RadioPills name="colour-by" legend="Colour by" value="staff" options={OPTIONS} onChange={() => {}} className="place-hook">
                <p className="under-token">under</p>
            </RadioPills>,
        );

        const root = view.host.querySelector('fieldset');

        expect(root?.classList.contains('place-hook')).toBe(true);
        expect(root?.lastElementChild?.classList.contains('under-token')).toBe(true);
    });
});
