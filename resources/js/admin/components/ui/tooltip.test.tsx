import { act } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { key, mount, type Mounted } from './harness';
import { Tooltip } from './tooltip';

/*
 * The tooltip a control with no visible words of its own wears: its label shows beside it on
 * keyboard focus, as on hover, and goes on Escape. The child stays the element a press and the
 * keyboard reach, the tooltip adding nothing to the tab order. Held shut it opens nothing and
 * keeps nothing. Hover is the library's and is walked in the browser.
 */

let view: Mounted;

beforeEach(() => {
    view = mount();
});

afterEach(() => {
    vi.useRealTimers();
    view.unmount();
});

function focus(element: Element | null): void {
    if (!(element instanceof HTMLElement)) {
        throw new Error('Nothing to focus.');
    }

    act(() => element.focus());
}

/** The pointer coming over an element and staying for the time given, on the test's own clock. */
function rest(element: Element | null, ms: number): void {
    act(() => {
        element?.dispatchEvent(new MouseEvent('pointermove', { bubbles: true }));
    });
    act(() => {
        vi.advanceTimersByTime(ms);
    });
}

/** The pointer leaving an element for the page behind it. */
function leave(element: Element | null): void {
    act(() => {
        element?.dispatchEvent(new MouseEvent('pointerout', { bubbles: true, relatedTarget: document.body }));
    });
}

const surface = () => document.querySelector('.admin-tooltip');

/** A tooltip on a link, held shut or let go. */
const held = (disabled: boolean) => (
    <Tooltip label="Bookings" disabled={disabled}>
        <a href="/admin/bookings" className="child">
            B
        </a>
    </Tooltip>
);

describe('the tooltip', () => {
    it('shows nothing until its child is reached', () => {
        view.render(
            <Tooltip label="Bookings">
                <a href="/admin/bookings" className="child">
                    B
                </a>
            </Tooltip>,
        );

        expect(surface()).toBeNull();
        expect(view.host.querySelector('a.child')?.getAttribute('href')).toBe('/admin/bookings');
    });

    it('shows its label on keyboard focus of the child, at the side asked, the right by default, named to a screen reader', () => {
        view.render(
            <>
                <Tooltip label="Bookings">
                    <a href="/admin/bookings" className="child">
                        B
                    </a>
                </Tooltip>
                <Tooltip label="Settings" side="top">
                    <button type="button" className="other">
                        S
                    </button>
                </Tooltip>
            </>,
        );

        focus(view.host.querySelector('.child'));

        expect(surface()?.textContent).toContain('Bookings');
        expect(surface()?.getAttribute('data-side')).toBe('right');
        expect(document.querySelector('[role="tooltip"]')?.textContent).toBe('Bookings');

        focus(view.host.querySelector('.other'));

        expect(surface()?.textContent).toContain('Settings');
        expect(surface()?.getAttribute('data-side')).toBe('top');
    });

    it('shows nothing and describes its child by nothing while it is held shut, and nothing still when it is let go over a child that kept the focus, the label coming with the next focus, the child the same element throughout', () => {
        view.render(held(true));

        const child = view.host.querySelector('.child');
        focus(child);

        expect(surface()).toBeNull();
        expect(child?.hasAttribute('aria-describedby')).toBe(false);
        expect(child?.getAttribute('data-state')).toBe('closed');

        view.render(held(false));

        expect(view.host.querySelector('.child')).toBe(child);
        expect(document.activeElement).toBe(child);
        expect(surface()).toBeNull();
        expect(child?.hasAttribute('aria-describedby')).toBe(false);

        act(() => (child as HTMLElement).blur());
        focus(child);

        expect(surface()?.textContent).toContain('Bookings');
        expect(child?.getAttribute('aria-describedby')).toBe(document.querySelector('[role="tooltip"]')?.id);

        view.render(held(true));

        expect(surface()).toBeNull();
        expect(child?.hasAttribute('aria-describedby')).toBe(false);
        expect(view.host.querySelector('.child')).toBe(child);
        expect(document.activeElement).toBe(child);

        view.render(held(false));

        expect(surface()).toBeNull();
    });

    it('opens nothing while it is held shut: a pointer that rested on its child and left leaves no label to show when it is let go, and the next rest waits the whole pause', () => {
        vi.useFakeTimers();
        view.render(held(true));

        const child = view.host.querySelector('.child');
        rest(child, 400);

        expect(surface()).toBeNull();

        leave(child);
        view.render(held(false));

        expect(surface()).toBeNull();
        expect(child?.hasAttribute('aria-describedby')).toBe(false);
        expect(child?.getAttribute('data-state')).toBe('closed');

        rest(child, 299);

        expect(surface()).toBeNull();

        act(() => {
            vi.advanceTimersByTime(1);
        });

        expect(surface()?.textContent).toContain('Bookings');
    });

    it('goes on Escape, focus staying on the child', () => {
        view.render(
            <Tooltip label="Bookings">
                <a href="/admin/bookings" className="child">
                    B
                </a>
            </Tooltip>,
        );

        const child = view.host.querySelector('.child');
        focus(child);
        expect(surface()).not.toBeNull();

        key(child, 'Escape');

        expect(surface()).toBeNull();
        expect(document.activeElement).toBe(child);
    });
});
