import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { FiltersToggle } from './filters-toggle';
import { mount, type Mounted, press } from './ui/harness';

/*
 * The button that folds a screen's filters away on a phone: its label handed in, the card it opens
 * named by id, whether that card is open said to assistive technology, and a count of the filters
 * set, drawn only above zero and read out by the name handed in with it.
 */

let view: Mounted;

beforeEach(() => {
    view = mount();
});

afterEach(() => view.unmount());

/** What a screen reader reads of an element: its text without the parts kept from readers. */
function heard(element: Element | null | undefined): string {
    return element === null || element === undefined ? '' : [...element.childNodes].map((node) => (node instanceof Element ? (node.getAttribute('aria-hidden') === 'true' ? '' : heard(node)) : (node.textContent ?? ''))).join('');
}

function toggle(): HTMLButtonElement | null {
    return view.host.querySelector<HTMLButtonElement>('button.admin-filters-toggle');
}

describe('the filters toggle', () => {
    it('is a button of the label handed in, naming the card it controls and saying whether that card is open', () => {
        view.render(<FiltersToggle open={false} onToggle={() => undefined} controls="calendar-filters" label="Filters" count={0} countName="" />);

        expect(toggle()?.getAttribute('type')).toBe('button');
        expect(heard(toggle())).toBe('Filters');
        expect(toggle()?.getAttribute('aria-controls')).toBe('calendar-filters');
        expect(toggle()?.getAttribute('aria-expanded')).toBe('false');

        view.render(<FiltersToggle open onToggle={() => undefined} controls="calendar-filters" label="Filters" count={0} countName="" />);

        expect(toggle()?.getAttribute('aria-expanded')).toBe('true');
    });

    it('is the kit\'s 44px button and hands a press to the caller', () => {
        const onToggle = vi.fn();
        view.render(<FiltersToggle open={false} onToggle={onToggle} controls="calendar-filters" label="Filters" count={0} countName="" />);

        expect(toggle()?.classList.contains('admin-button')).toBe(true);
        expect(toggle()?.classList.contains('h-11')).toBe(true);

        press(toggle());

        expect(onToggle).toHaveBeenCalledTimes(1);
    });

    it('draws no count where no filter is set, and the count with its name read out where some are', () => {
        view.render(<FiltersToggle open={false} onToggle={() => undefined} controls="calendar-filters" label="Filters" count={0} countName="none active" />);

        expect(toggle()?.querySelector('.admin-filters-toggle__count')).toBeNull();
        expect(toggle()?.textContent).not.toContain('none active');

        view.render(<FiltersToggle open={false} onToggle={() => undefined} controls="calendar-filters" label="Filters" count={2} countName="2 active" />);

        const count = toggle()?.querySelector('.admin-filters-toggle__count');
        expect(count?.textContent).toBe('2');
        expect(count?.getAttribute('aria-hidden')).toBe('true');
        expect(count?.classList.contains('text-xs')).toBe(true);
        expect(heard(toggle())).toBe('Filters, 2 active');
    });
});
