import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { mount, type Mounted } from './harness';
import { SaveBar } from './save-bar';

/*
 * The bar that holds a form's Save: a pill on the surface, a little see-through and blurring what
 * passes under it, that stays a short way above the bottom of the screen while the form scrolls
 * and rests after the form's last control at its end. From the wide breakpoint it is as wide as
 * what it holds. Its rounding is a fixed one, the pill's around one row, so the bar is a rounded
 * card and not a tall capsule once the line in it wraps. The line that says why Save is
 * unavailable sits in it as a status, so a screen reader hears it, and breaks a run of letters
 * too long for its column; with no reason there is no line.
 */

let view: Mounted;

beforeEach(() => {
    view = mount();
});

afterEach(() => view.unmount());

const bar = () => view.host.querySelector('.admin-save-bar');

describe('the save bar', () => {
    it('is a pill around one row that floats a short way above the bottom of the screen, as wide as what it holds from the wide breakpoint, its rounding fixed so it is never a tall capsule', () => {
        view.render(
            <SaveBar>
                <button type="submit">Save</button>
            </SaveBar>,
        );

        expect(bar()?.tagName).toBe('DIV');
        expect([...(bar()?.classList ?? [])]).toEqual(expect.arrayContaining(['sticky', 'bottom-3.5', 'rounded-tile', 'shadow-float', 'bg-card/94', 'backdrop-blur-sm', 'wide:w-fit']));
        expect(bar()?.classList.contains('rounded-full')).toBe(false);
    });

    it('says why Save is unavailable in a status line after what it holds', () => {
        view.render(
            <SaveBar reason="A name is needed.">
                <button type="submit">Save</button>
            </SaveBar>,
        );

        const reason = bar()?.querySelector('p.admin-save-bar__reason');

        expect(reason?.getAttribute('role')).toBe('status');
        expect(reason?.textContent).toBe('A name is needed.');
        // The line may shrink below its longest word, and breaks that word when it has.
        expect([...(reason?.classList ?? [])]).toEqual(expect.arrayContaining(['min-w-0', 'wrap-break-word']));
        expect(bar()?.firstElementChild?.tagName).toBe('BUTTON');
        expect(bar()?.lastElementChild).toBe(reason);
    });

    it('draws no line with no reason', () => {
        for (const reason of [null, undefined]) {
            view.render(
                <SaveBar reason={reason}>
                    <button type="submit">Save</button>
                </SaveBar>,
            );

            expect(bar()?.querySelector('p')).toBeNull();
            expect(bar()?.textContent).toBe('Save');
        }
    });
});
