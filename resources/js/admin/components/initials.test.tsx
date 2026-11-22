import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { Initials, initialsOf } from './initials';
import { mount, type Mounted } from './ui/harness';

/*
 * A person's initials, which the Calendar's heads and Who rows and the Shifts rows ring in the
 * member's hue: the display face at 700, at three sizes for a member, the head's ring the widest,
 * and two for a customer with no ring. One with no ring, a customer's or a member's with no hue of
 * its own, sits on the theme's strong tint in the deepened theme colour.
 */

let view: Mounted;

beforeEach(() => {
    view = mount();
});

afterEach(() => view.unmount());

function drawn(): HTMLElement | null {
    return view.host.querySelector<HTMLElement>('span');
}

describe('initials', () => {
    it('are the first letters of the first and last words, upper-cased', () => {
        expect(initialsOf('Elena Papadaki')).toBe('EP');
        expect(initialsOf('  maria  ')).toBe('M');
        expect(initialsOf('Anna Maria Louka')).toBe('AL');
        expect(initialsOf('')).toBe('');
    });

    it('sets the letters in the display face at 700, hidden from assistive technology beside the name they repeat', () => {
        view.render(<Initials name="Elena Papadaki" hue="#cc79a7" size="row" />);

        expect(drawn()?.textContent).toBe('EP');
        expect(drawn()?.classList.contains('font-display')).toBe(true);
        expect(drawn()?.classList.contains('font-bold')).toBe(true);
        expect(drawn()?.getAttribute('aria-hidden')).toBe('true');
    });

    it('draws the three sizes, the head 46px with a 3px ring, a row 32px and a list 30px', () => {
        const sizes = (['head', 'row', 'list'] as const).map((size) => {
            view.render(<Initials name="Elena Papadaki" hue="#cc79a7" size={size} />);

            return [size, ['size-11.5', 'size-8', 'size-7.5'].find((name) => drawn()?.classList.contains(name)), drawn()?.style.boxShadow];
        });

        expect(sizes).toEqual([
            ['head', 'size-11.5', 'inset 0 0 0 3px #cc79a7'],
            ['row', 'size-8', 'inset 0 0 0 2px #cc79a7'],
            ['list', 'size-7.5', 'inset 0 0 0 2px #cc79a7'],
        ]);
    });

    it('draws a customer at two sizes on the strong tint with no ring, a list row 40px and a page head 76px, a hue or none', () => {
        const sizes = (['person', 'hero'] as const).flatMap((size) =>
            [undefined, '#cc79a7'].map((hue) => {
                view.render(<Initials name="Casey Customer" hue={hue} size={size} />);

                return [
                    size,
                    ['size-10', 'size-19'].find((name) => drawn()?.classList.contains(name)),
                    drawn()?.style.boxShadow,
                    drawn()?.classList.contains('bg-tint-strong'),
                    drawn()?.classList.contains('bg-card'),
                ];
            }),
        );

        expect(sizes).toEqual([
            ['person', 'size-10', '', true, false],
            ['person', 'size-10', '', true, false],
            ['hero', 'size-19', '', true, false],
            ['hero', 'size-19', '', true, false],
        ]);
    });

    it('without a hue sits on the strong tint in the deepened theme colour, with no ring', () => {
        view.render(<Initials name="Elena Papadaki" size="head" />);

        expect(drawn()?.classList.contains('bg-tint-strong')).toBe(true);
        expect(drawn()?.classList.contains('text-primary-deep')).toBe(true);
        expect(drawn()?.style.boxShadow).toBe('');
    });

    it('with a hue keeps its ring set inline, on the card', () => {
        view.render(<Initials name="Elena Papadaki" hue="#cc79a7" size="head" />);

        expect(drawn()?.style.boxShadow).toContain('#cc79a7');
        expect(drawn()?.classList.contains('bg-card')).toBe(true);
        expect(drawn()?.classList.contains('bg-tint-strong')).toBe(false);
    });

    it('outlines a ringed circle in forced colours, where the ring drawn by its shadow is dropped, and leaves a circle with no ring alone', () => {
        const forced = () => [...(drawn()?.classList ?? [])].filter((name) => name.startsWith('forced-colors:'));

        for (const size of ['head', 'row', 'list'] as const) {
            view.render(<Initials name="Elena Papadaki" hue="#cc79a7" size={size} />);
            expect(forced()).toEqual(['forced-colors:outline-2', 'forced-colors:-outline-offset-2']);
        }

        view.render(<Initials name="Elena Papadaki" size="head" />);
        expect(forced()).toEqual([]);

        view.render(<Initials name="Casey Moss" hue="#cc79a7" size="person" />);
        expect(forced()).toEqual([]);
    });
});
