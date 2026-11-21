import { act } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { mount, type Mounted, press } from '@/components/ui/harness';
import { SectionTabs } from './SectionTabs';

/*
 * The row of links over a long page of sections: one link to each section's heading, named by it,
 * in the page's order, inside a navigation landmark of its own name. It sticks to the top of the
 * screen and scrolls sideways inside itself, never the page. The section that has crossed a line
 * 140px from the top is the current one, marked for the eye and for a screen reader, read once a
 * frame however many scroll events arrive in it; the current link is kept in view inside the row.
 * A press scrolls its heading into view and leaves the address as it was, so the page's history
 * gains no entry and nothing else on the page moves; the heading then takes the keyboard's focus,
 * as the target of a link does, so the next Tab goes on inside the section.
 */

const SECTIONS = [
    { id: 'general', title: 'general-token' },
    { id: 'booking-page', title: 'booking-page-token' },
    { id: 'booking-rules', title: 'booking-rules-token' },
];

let view: Mounted;
let frames: FrameRequestCallback[];

beforeEach(() => {
    view = mount();
    frames = [];
    vi.stubGlobal('requestAnimationFrame', (callback: FrameRequestCallback) => frames.push(callback));
    vi.stubGlobal('cancelAnimationFrame', () => undefined);
});

afterEach(() => {
    view.unmount();
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
});

/** The frames asked for so far, run in order inside act. */
function runFrames(): void {
    const due = frames;
    frames = [];
    act(() => due.forEach((callback) => callback(0)));
}

/** A rectangle of the given edges, as getBoundingClientRect answers. */
function box(edges: { top?: number; left?: number; right?: number }): DOMRect {
    const top = edges.top ?? 0;
    const left = edges.left ?? 0;
    const right = edges.right ?? left;

    return { top, left, right, bottom: top, width: right - left, height: 0, x: left, y: top, toJSON: () => ({}) };
}

function placeAt(element: Element | null, edges: { top?: number; left?: number; right?: number }): void {
    if (element === null) {
        throw new Error('Nothing to place.');
    }

    element.getBoundingClientRect = () => box(edges);
}

/**
 * The page under the row: each section a card named by its heading, as the page draws them, read
 * on arrival with the page at its top, every card below the line.
 */
function render(): void {
    view.render(
        <>
            <SectionTabs label="label-token" sections={SECTIONS} />
            {SECTIONS.map((section) => (
                <section key={section.id} className={`probe-${section.id}`} aria-labelledby={`section-${section.id}`}>
                    <h2 id={`section-${section.id}`}>{section.title}</h2>
                </section>
            ))}
        </>,
    );
    SECTIONS.forEach((section, at) => placeAt(view.host.querySelector(`.probe-${section.id}`), { top: 300 + at * 600 }));
    runFrames();
}

const nav = () => view.host.querySelector('nav.admin-settings-tabs');
const links = () => [...view.host.querySelectorAll<HTMLAnchorElement>('nav.admin-settings-tabs a')];
const current = () => links().filter((link) => link.getAttribute('aria-current') === 'true').map((link) => link.textContent);

function scrollTo(tops: number[]): void {
    SECTIONS.forEach((section, at) => placeAt(view.host.querySelector(`.probe-${section.id}`), { top: tops[at] }));
    act(() => window.dispatchEvent(new Event('scroll')));
}

describe('the section tabs', () => {
    it('are a navigation landmark of their own name, one link to each section\'s heading in the page\'s order, named by its heading', () => {
        render();

        expect(nav()?.getAttribute('aria-label')).toBe('label-token');
        expect(links().map((link) => link.textContent)).toEqual(['general-token', 'booking-page-token', 'booking-rules-token']);
        expect(links().map((link) => link.getAttribute('href'))).toEqual(['#section-general', '#section-booking-page', '#section-booking-rules']);
        expect(nav()?.querySelectorAll('ul > li > a')).toHaveLength(3);
        expect(nav()?.querySelectorAll('[role="tab"], [role="tablist"]')).toHaveLength(0);
    });

    it('mark the first section current on arrival, filled in the ink for the eye and aria-current for a screen reader, the others plain', () => {
        render();

        expect(current()).toEqual(['general-token']);
        const [first, ...rest] = links();
        expect(['bg-foreground', 'text-background'].every((name) => first?.classList.contains(name))).toBe(true);
        expect(rest.every((link) => !link.classList.contains('bg-foreground') && link.classList.contains('text-muted-foreground') && !link.hasAttribute('aria-current'))).toBe(true);
    });

    it('stick to the top of the screen above the page and under the save bar\'s layer, and scroll sideways inside their own pill', () => {
        render();
        const list = nav()?.querySelector('ul');

        expect(['sticky', 'top-3', 'z-8'].every((name) => nav()?.classList.contains(name))).toBe(true);
        expect(['flex', 'overflow-x-auto', 'rounded-full', 'bg-background/92', 'backdrop-blur-sm', 'p-2'].every((name) => list?.classList.contains(name))).toBe(true);
        // Each tab is 38px high, keeps its words on one line and never shrinks, so the row runs
        // past its pill and scrolls instead of squeezing the words.
        expect(links().every((link) => ['h-9.5', 'flex-none', 'whitespace-nowrap', 'rounded-full', 'focus-ring'].every((name) => link.classList.contains(name)))).toBe(true);
    });

    it('keep a link the row scrolls to 8px inside its edge, so the link\'s focus outline is drawn whole', () => {
        render();

        expect(nav()?.querySelector('ul')?.classList.contains('scroll-px-2')).toBe(true);
    });

    it('answer a touch 44px tall on each link drawn at 38px, on a layer of its own reaching 3px past the link, half the gap to its neighbour', () => {
        render();
        const list = nav()?.querySelector('ul');

        expect(links().every((link) => ['relative', 'h-9.5', 'after:absolute', 'after:-inset-0.75'].every((name) => link.classList.contains(name)))).toBe(true);
        // 6px between links and 8px around them: two layers meet and never overlap, and the row
        // clips none.
        expect(['gap-1.5', 'p-2'].every((name) => list?.classList.contains(name))).toBe(true);
    });

    it('follow the scroll: the last section whose card has reached a line 140px from the top is current, read once a frame', () => {
        render();

        scrollTo([-300, 140, 700]);
        window.dispatchEvent(new Event('scroll'));
        window.dispatchEvent(new Event('scroll'));
        expect(frames).toHaveLength(1);
        expect(current()).toEqual(['general-token']);
        runFrames();
        expect(current()).toEqual(['booking-page-token']);

        scrollTo([-900, -400, 139]);
        runFrames();
        expect(current()).toEqual(['booking-rules-token']);

        scrollTo([200, 800, 1400]);
        runFrames();
        expect(current()).toEqual(['general-token']);
    });

    it('bring the current link into view inside the row when the scroll marks one past its edge', () => {
        render();
        const list = nav()?.querySelector('ul');

        if (!(list instanceof HTMLElement)) {
            throw new Error('No list.');
        }

        placeAt(list, { left: 0, right: 300 });
        placeAt(links()[2] ?? null, { left: 340, right: 420 });
        scrollTo([-900, -400, 100]);
        runFrames();

        expect(current()).toEqual(['booking-rules-token']);
        expect(list.scrollLeft).toBe(128);
    });

    it('bring a link the keyboard reaches half out of the row in whole, 8px inside its edge, and leave a link a pointer focuses where it stands under the pointer', () => {
        render();
        const list = nav()?.querySelector('ul');
        const link = links()[2];

        if (!(list instanceof HTMLElement) || link === undefined) {
            throw new Error('No row.');
        }

        placeAt(list, { left: 0, right: 300 });
        placeAt(link, { left: 280, right: 360 });

        // A pointer's focus draws no outline.
        const outlined = vi.spyOn(link, 'matches').mockReturnValue(false);
        act(() => link.focus());
        expect(list.scrollLeft).toBe(0);

        // The keyboard's focus draws one, 5px past the link on every side.
        act(() => link.blur());
        outlined.mockImplementation((selector: string): boolean => selector === ':focus-visible');
        act(() => link.focus());
        expect(list.scrollLeft).toBe(68);
    });

    it('scroll a pressed link\'s heading into view and leave the address and the page\'s history alone', () => {
        render();
        const heading = view.host.querySelector('#section-booking-rules');
        const into = vi.fn();
        const pushed = vi.spyOn(window.history, 'pushState');

        if (heading === null) {
            throw new Error('No heading.');
        }

        heading.scrollIntoView = into;
        const before = window.location.href;
        const click = new MouseEvent('click', { bubbles: true, cancelable: true });
        act(() => links()[2]?.dispatchEvent(click));

        expect(click.defaultPrevented).toBe(true);
        expect(into).toHaveBeenCalledWith({ block: 'start' });
        expect(window.location.href).toBe(before);
        expect(pushed).not.toHaveBeenCalled();
    });

    it('hand a pressed link\'s heading the keyboard\'s focus after the scroll, with no scroll of its own, and leave the heading no stop of the Tab key once the focus moves on', () => {
        render();
        const heading = view.host.querySelector<HTMLElement>('#section-booking-rules');
        const into = vi.fn();

        if (heading === null) {
            throw new Error('No heading.');
        }

        heading.scrollIntoView = into;
        const focus = vi.spyOn(heading, 'focus');
        press(links()[2] ?? null);

        expect(document.activeElement).toBe(heading);
        expect(focus).toHaveBeenCalledWith({ preventScroll: true });
        expect(into.mock.invocationCallOrder[0]).toBeLessThan(focus.mock.invocationCallOrder[0] ?? 0);
        // A heading takes the focus for the jump alone: reachable by the script, never by Tab.
        expect(heading.getAttribute('tabindex')).toBe('-1');

        act(() => heading.blur());
        expect(heading.hasAttribute('tabindex')).toBe(false);
    });

    it('mark a pressed link current at once and hold it through the frame its jump scrolls, though the page ends before its card reaches the line', () => {
        render();
        const heading = view.host.querySelector('#section-booking-rules');

        if (heading === null) {
            throw new Error('No heading.');
        }

        heading.scrollIntoView = vi.fn();
        press(links()[2] ?? null);
        expect(current()).toEqual(['booking-rules-token']);

        // The jump's own scroll, the cards still below the line where the page can go no further.
        act(() => window.dispatchEvent(new Event('scroll')));
        runFrames();
        expect(current()).toEqual(['booking-rules-token']);

        // The reader's next scroll is read from the line again.
        act(() => window.dispatchEvent(new Event('scroll')));
        runFrames();
        expect(current()).toEqual(['general-token']);
    });

    it('leave a press with a key held to the browser, which opens the link elsewhere', () => {
        render();
        const heading = view.host.querySelector('#section-general');
        const into = vi.fn();

        if (heading === null) {
            throw new Error('No heading.');
        }

        heading.scrollIntoView = into;
        const click = new MouseEvent('click', { bubbles: true, cancelable: true, ctrlKey: true });
        links()[0]?.addEventListener('click', (event) => event.preventDefault(), { once: true });
        act(() => links()[0]?.dispatchEvent(click));

        expect(into).not.toHaveBeenCalled();
        expect(document.activeElement).not.toBe(heading);
        press(links()[0] ?? null);
        expect(into).toHaveBeenCalledTimes(1);
    });
});
