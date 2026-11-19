import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { Card } from './card';
import { mount, type Mounted } from './harness';

/*
 * The admin's panel: a tile for content, a form card with a title row, and a lighter inner card
 * for a group inside another card. The first two are the surface at the large radius under the
 * card shadow, with a smaller radius and less room in the compact layout below the wide
 * breakpoint; the inner card is the smaller radius inside a line ring, with no shadow. A flush
 * card has no padding, for a table that runs to its edges. The card adds no hook class: each
 * place keeps its own.
 */

let view: Mounted;

beforeEach(() => {
    view = mount();
});

afterEach(() => view.unmount());

const card = () => view.host.querySelector('.probe');
const classes = () => [...(card()?.classList ?? [])];

/** A padding utility on any side, under any variant. */
const PADDING = /(^|:)p[xytrbl]?-/;

describe('the card', () => {
    it('is a tile by default: the surface at the large radius under the card shadow, with room inside', () => {
        view.render(<Card className="probe" />);

        expect(classes()).toEqual(expect.arrayContaining(['bg-card', 'rounded-tile', 'shadow-card', 'p-6.5']));
    });

    it('takes the compact radius and padding below the wide breakpoint, a tile and a form card alike', () => {
        for (const variant of ['tile', 'form'] as const) {
            view.render(<Card key={variant} variant={variant} className="probe" />);

            expect(classes()).toEqual(expect.arrayContaining(['max-wide:rounded-tile-compact', 'max-wide:p-5']));
        }
    });

    it('gives a form card the same surface with less room above its title row than at its sides and foot', () => {
        view.render(<Card variant="form" className="probe" />);

        expect(classes()).toEqual(expect.arrayContaining(['bg-card', 'rounded-tile', 'shadow-card', 'px-6.5', 'pt-6', 'pb-6.5']));
        expect(classes()).not.toContain('p-6.5');
    });

    it('draws an inner card at the smaller radius inside a line ring, with no shadow', () => {
        view.render(<Card variant="inner" className="probe" />);

        expect(classes()).toEqual(expect.arrayContaining(['bg-card', 'rounded-inner', 'ring-[1.5px]', 'ring-border']));
        expect(classes()).not.toContain('shadow-card');
        expect(classes()).not.toContain('rounded-tile');
    });

    it('leaves a flush card of any kind with no padding, for a table that runs to its edges', () => {
        for (const variant of ['tile', 'form', 'inner'] as const) {
            view.render(<Card key={variant} variant={variant} flush className="probe" />);

            expect(classes().filter((name) => PADDING.test(name))).toEqual([]);
        }
    });

    it('clips a flush card to its corner, so a filled head row of the table inside never draws past it', () => {
        for (const variant of ['tile', 'form', 'inner'] as const) {
            view.render(<Card key={variant} variant={variant} flush className="probe" />);

            expect(classes()).toContain('overflow-clip');
        }

        view.render(<Card className="probe" />);

        expect(classes()).not.toContain('overflow-clip');
    });

    it('lets a class its caller passes win over its own', () => {
        view.render(<Card className="probe p-3 rounded-none" />);

        expect(classes()).toEqual(expect.arrayContaining(['p-3', 'rounded-none']));
        expect(classes()).not.toContain('p-6.5');
        expect(classes()).not.toContain('rounded-tile');
    });

    it('is a section, so a card named by its heading is a region, and hands every other prop to it', () => {
        view.render(
            <Card className="probe" aria-labelledby="probe-head" data-kind="probe">
                <h2 id="probe-head">card-title-token</h2>
            </Card>,
        );

        expect(card()?.tagName).toBe('SECTION');
        expect(card()?.getAttribute('aria-labelledby')).toBe('probe-head');
        expect(card()?.getAttribute('data-kind')).toBe('probe');
        expect(card()?.querySelector('h2')?.textContent).toBe('card-title-token');
    });
});
