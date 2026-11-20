import { act } from 'react';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { mount, type Mounted } from '@/components/ui/harness';
import { useHiddenOnScroll } from './scroll';

/*
 * Whether the bottom bar steps out of the way: it goes as the page scrolls down past its first
 * stretch and comes back as soon as it scrolls up, and it stays near the page's end, where the last
 * controls are, and on a page too short to scroll at all. A small move either way is a hand's
 * tremor and changes nothing. The page's height and position are set here by hand, as the test's
 * document has no layout.
 */

let mounted: Mounted;
let page = { y: 0, height: 3000, viewport: 800 };

function Probe() {
    return <p data-hidden={String(useHiddenOnScroll())} />;
}

const hidden = () => mounted.host.querySelector('p')?.getAttribute('data-hidden');

function scrollTo(y: number): void {
    page = { ...page, y };
    act(() => {
        window.dispatchEvent(new Event('scroll'));
    });
}

beforeEach(() => {
    page = { y: 0, height: 3000, viewport: 800 };
    Object.defineProperty(window, 'scrollY', { configurable: true, get: () => page.y });
    Object.defineProperty(window, 'innerHeight', { configurable: true, get: () => page.viewport });
    Object.defineProperty(document.documentElement, 'scrollHeight', { configurable: true, get: () => page.height });
    mounted = mount();
    mounted.render(<Probe />);
});

afterEach(() => {
    mounted.unmount();
    Reflect.deleteProperty(window, 'scrollY');
    Reflect.deleteProperty(window, 'innerHeight');
    Reflect.deleteProperty(document.documentElement, 'scrollHeight');
});

describe('useHiddenOnScroll', () => {
    it('is shown at the top of the page and stays so for a scroll down inside the first stretch', () => {
        expect(hidden()).toBe('false');

        scrollTo(100);

        expect(hidden()).toBe('false');
    });

    it('hides on a scroll down past the first stretch and shows again on a scroll up', () => {
        scrollTo(400);

        expect(hidden()).toBe('true');

        scrollTo(390);

        expect(hidden()).toBe('false');
    });

    it('changes nothing for a move of a few pixels either way', () => {
        scrollTo(400);
        scrollTo(397);

        expect(hidden()).toBe('true');

        scrollTo(390);

        expect(hidden()).toBe('false');

        scrollTo(394);

        expect(hidden()).toBe('false');
    });

    it('shows near the page\'s end, where the last controls are, even on a scroll down', () => {
        scrollTo(1500);

        expect(hidden()).toBe('true');

        scrollTo(page.height - page.viewport - 40);

        expect(hidden()).toBe('false');
    });

    it('never hides on a page too short to scroll', () => {
        page = { ...page, height: 700 };
        scrollTo(300);

        expect(hidden()).toBe('false');
    });
});
