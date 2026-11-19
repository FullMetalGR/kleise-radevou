import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { mount, type Mounted } from '@/components/ui/harness';
import { Mark, splitEnding } from './Mark';
import MARK from './mark.svg';

/*
 * The platform's mark in a business's admin: the artwork drawn from its one file through an image,
 * decoration beside the name it stands with, then the name in the display face, a domain ending
 * set lighter in the tertiary ink. Bare, the name stays for a screen reader alone.
 */

let mounted: Mounted;

beforeEach(() => {
    mounted = mount();
});

afterEach(() => {
    mounted.unmount();
});

describe('splitEnding', () => {
    it('splits a domain ending off the name, and leaves a name without one whole', () => {
        expect(splitEnding('kleiseradevou.gr')).toEqual(['kleiseradevou', '.gr']);
        expect(splitEnding('Acme')).toEqual(['Acme', '']);
        expect(splitEnding('Example Bookings')).toEqual(['Example Bookings', '']);
    });
});

describe('Mark', () => {
    it('draws the artwork from its file at 40px, decoration with an empty alt', () => {
        mounted.render(<Mark name="kleiseradevou.gr" />);

        const image = mounted.host.querySelector<HTMLImageElement>('.admin-mark img');

        expect(image?.getAttribute('src')).toBe(MARK);
        expect(MARK).toMatch(/^data:image\/svg\+xml,|mark\.svg$/);
        expect(image?.getAttribute('alt')).toBe('');
        expect([image?.getAttribute('width'), image?.getAttribute('height')]).toEqual(['40', '40']);
        expect(image?.classList.contains('size-10')).toBe(true);
        expect(image?.classList.contains('shrink-0')).toBe(true);
    });

    it('sets the name in the display face beside it, its domain ending at 500 in the tertiary ink', () => {
        mounted.render(<Mark name="kleiseradevou.gr" />);

        const name = mounted.host.querySelector('.admin-mark__name');
        const ending = name?.querySelector('.admin-mark__ending');

        expect(name?.textContent).toBe('kleiseradevou.gr');
        expect(name?.classList.contains('font-display')).toBe(true);
        expect(name?.classList.contains('font-bold')).toBe(true);
        expect(name?.classList.contains('sr-only')).toBe(false);
        expect(ending?.textContent).toBe('.gr');
        expect(ending?.classList.contains('text-ink-tertiary')).toBe(true);
        expect(ending?.classList.contains('font-medium')).toBe(true);
    });

    it('draws no ending where the name has none', () => {
        mounted.render(<Mark name="Acme" />);

        expect(mounted.host.querySelector('.admin-mark__name')?.textContent).toBe('Acme');
        expect(mounted.host.querySelector('.admin-mark__ending')).toBeNull();
    });

    it('keeps the name for a screen reader alone when bare', () => {
        mounted.render(<Mark name="kleiseradevou.gr" bare />);

        const name = mounted.host.querySelector('.admin-mark__name');

        expect(name?.textContent).toBe('kleiseradevou.gr');
        expect(name?.classList.contains('sr-only')).toBe(true);
    });
});
