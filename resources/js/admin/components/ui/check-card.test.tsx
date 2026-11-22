import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { CHECK_CARDS, CheckCard } from './check-card';
import { mount, press, type Mounted } from './harness';

/*
 * The check card on its own: the card is the label around the live box, so a press anywhere on
 * it is a press on the box; the box is named by the name alone and described by the line under
 * it, where there is one; a chosen card shows it three ways; and each part carries the hook its
 * caller hands it.
 */

let view: Mounted;
let ticked: boolean[];

beforeEach(() => {
    view = mount();
    ticked = [];
});

afterEach(() => view.unmount());

const card = () => view.host.querySelector<HTMLLabelElement>('label');
const box = () => view.host.querySelector<HTMLInputElement>('input[type="checkbox"]');
const classes = (element: Element | null | undefined) => [...(element?.classList ?? [])];

function render(checked: boolean, description?: string, disabled?: boolean): void {
    view.render(
        <ul className={CHECK_CARDS}>
            <li>
                <CheckCard
                    id="pick-3"
                    name="Haircut"
                    description={description}
                    checked={checked}
                    disabled={disabled}
                    onChange={(value) => ticked.push(value)}
                    className="hook__row"
                    tickClassName="hook__tick"
                    nameClassName="hook__name"
                    descriptionClassName="hook__line"
                    markClassName="hook__mark"
                />
            </li>
        </ul>,
    );
}

describe('the check card', () => {
    it('wraps the live box in the card, ringed in the line colour and thicker in the theme colour on a tint once ticked', () => {
        render(false, 'Mon 09:00-13:00');

        expect(card()?.control).toBe(box());
        expect(box()?.id).toBe('pick-3');
        expect(box()?.hidden).toBe(false);
        expect(box()?.getAttribute('tabindex')).toBeNull();
        expect(classes(card())).toEqual(expect.arrayContaining(['hook__row', 'items-center', 'cursor-pointer', 'ring-[1.5px]', 'ring-border', 'has-checked:ring-2', 'has-checked:ring-primary', 'has-checked:bg-muted/60', 'has-enabled:hover:bg-muted']));
        expect(classes(box())).toEqual(expect.arrayContaining(['hook__tick', 'size-5', 'accent-primary', 'focus-ring-field']));
        expect(view.host.querySelector('label label')).toBeNull();
    });

    it('names the box by the name alone and describes it by the line under it, and points at no line where there is none', () => {
        render(false, 'Mon 09:00-13:00');

        expect(document.getElementById(box()?.getAttribute('aria-labelledby') ?? '')?.textContent).toBe('Haircut');
        expect(document.getElementById(box()?.getAttribute('aria-labelledby') ?? '')?.classList.contains('hook__name')).toBe(true);
        expect(document.getElementById(box()?.getAttribute('aria-describedby') ?? '')?.textContent).toBe('Mon 09:00-13:00');
        expect(document.getElementById(box()?.getAttribute('aria-describedby') ?? '')?.classList.contains('hook__line')).toBe(true);

        render(false);
        expect(box()?.hasAttribute('aria-describedby')).toBe(false);
        expect(view.host.querySelector('.hook__line')).toBeNull();
    });

    it('hands the new state on a press anywhere on the card, and marks a ticked card with a tick at its end', () => {
        render(false, 'Mon 09:00-13:00');
        expect(view.host.querySelector('.hook__mark svg')).toBeNull();

        press(view.host.querySelector('.hook__line'));
        expect(ticked).toEqual([true]);

        render(true, 'Mon 09:00-13:00');
        expect(box()?.checked).toBe(true);
        expect(view.host.querySelector('.hook__mark svg')).not.toBeNull();
        press(view.host.querySelector('.hook__name'));
        expect(ticked).toEqual([true, false]);
    });

    it('locks the box and reads as locked to the pointer while disabled', () => {
        render(true, undefined, true);

        expect(box()?.disabled).toBe(true);
        expect(classes(card())).toContain('has-disabled:cursor-not-allowed');
    });

    it('lays the cards on a grid of columns no narrower than the box they sit in allows', () => {
        render(false);

        expect(classes(view.host.querySelector('ul'))).toEqual(expect.arrayContaining(['grid', 'grid-cols-[repeat(auto-fill,minmax(min(17.5rem,100%),1fr))]']));
    });
});
