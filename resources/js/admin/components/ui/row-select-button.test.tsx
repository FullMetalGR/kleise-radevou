import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { mount, press, type Mounted } from './harness';
import { RowSelectButton } from './row-select-button';

/*
 * The row select button: a link-styled button in the identifying cell whose accessible name is the
 * row's name, marked current when selected, stopping propagation so a click selects once while the
 * row keeps its own click handler as a larger mouse target.
 */

let view: Mounted;

beforeEach(() => {
    view = mount();
});

afterEach(() => view.unmount());

function table(selected: boolean, onSelect: () => void) {
    return (
        <table>
            <tbody>
                <tr className="row" onClick={onSelect}>
                    <td>
                        <RowSelectButton name="Maria Papadopoulou" selected={selected} onSelect={onSelect} />
                    </td>
                    <td className="other">45 min</td>
                </tr>
            </tbody>
        </table>
    );
}

const button = () => view.host.querySelector('.admin-row-select');

describe('the row select button', () => {
    it('is named by the row and styled as a link', () => {
        view.render(table(false, () => {}));

        expect(button()?.tagName).toBe('BUTTON');
        expect(button()?.textContent).toBe('Maria Papadopoulou');
        expect(button()?.hasAttribute('aria-label')).toBe(false);
        expect(button()?.classList.contains('admin-button--link')).toBe(true);
    });

    it('is marked current when its row is selected, and not otherwise', () => {
        view.render(table(true, () => {}));
        expect(button()?.getAttribute('aria-current')).toBe('true');

        view.render(table(false, () => {}));
        expect(button()?.hasAttribute('aria-current')).toBe(false);
    });

    it('reaches the row handler exactly once on a click of the button, and the row still selects on its own', () => {
        const onSelect = vi.fn();

        view.render(table(false, onSelect));
        press(button());
        expect(onSelect).toHaveBeenCalledOnce();

        press(view.host.querySelector('.other'));
        expect(onSelect).toHaveBeenCalledTimes(2);
    });
});
