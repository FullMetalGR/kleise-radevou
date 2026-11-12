import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { mount, type Mounted } from '@/components/ui/harness';
import { FigureLine, FigureTile, Strip } from './kit';

/*
 * The figures kit Home and Reports share: a range tile, its title, its figure, its comparison
 * and its quiet lines on the kit's card, and the header strip a card below the tiles opens
 * on.
 */

let view: Mounted;

beforeEach(() => {
    view = mount();
});

afterEach(() => view.unmount());

const q = (selector: string) => view.host.querySelector(selector);

describe('a range tile', () => {
    it('draws its title, its figure, its comparison and then its lines, named by the tile', () => {
        view.render(
            <FigureTile id="bookings" title="tile-title-token" value="23" change="tile-change-token">
                <FigureLine>tile-line-token</FigureLine>
            </FigureTile>,
        );

        const tile = q('section.admin-figure-tile.admin-figure-tile--bookings');

        expect(tile?.className.split(' ')).toEqual(expect.arrayContaining(['flex', 'flex-col', 'gap-1', 'rounded-xl', 'border', 'bg-card', 'p-4']));
        expect([...(tile?.children ?? [])].map((el) => [el.tagName, el.textContent])).toEqual([
            ['H2', 'tile-title-token'],
            ['P', '23'],
            ['P', 'tile-change-token'],
            ['P', 'tile-line-token'],
        ]);
        expect(tile?.getAttribute('aria-labelledby')).toBe(q('.admin-figure-tile__title')?.id);
        expect(q('.admin-figure-tile__title')?.className).toBe('admin-figure-tile__title text-sm font-medium text-muted-foreground');
        expect(q('.admin-figure-tile__value')?.className).toBe('admin-figure-tile__value text-2xl leading-8 font-semibold');
        expect(q('.admin-figure-tile__change')?.className).toBe('admin-figure-tile__change text-xs leading-4 text-muted-foreground');
        expect(q('.admin-figure-tile__line')?.className).toBe('admin-figure-tile__line text-xs leading-4 text-muted-foreground');
    });

    it('leaves out a figure or a comparison it is not given', () => {
        view.render(
            <FigureTile id="occupancy" title="tile-title-token" change={null}>
                <FigureLine>tile-line-token</FigureLine>
            </FigureTile>,
        );

        expect(q('.admin-figure-tile__value')).toBeNull();
        expect(q('.admin-figure-tile__change')).toBeNull();
        expect(q('.admin-figure-tile__line')?.textContent).toBe('tile-line-token');
    });
});

describe('a card\'s header strip', () => {
    it('holds the title at the left and what the card carries beside it at the right, above a rule', () => {
        view.render(<Strip titleId="top-title" title="strip-title-token" aside={<a href="/admin/bookings">strip-aside-token</a>} />);

        const strip = q('header.admin-card-strip');

        // The strip is 45px, 12px above and below a 20px line and the rule; the house h2 sits on a
        // 26px line, so the strip pins its 45px height rather than growing with it.
        expect(strip?.className.split(' ')).toEqual(expect.arrayContaining(['flex', 'items-center', 'justify-between', 'border-b', 'px-4', 'h-[45px]']));
        expect(strip?.className.split(' ')).not.toContain('py-3');
        expect(strip?.querySelector(':scope > h2#top-title.font-semibold')?.textContent).toBe('strip-title-token');
        expect(strip?.querySelector(':scope > a')?.textContent).toBe('strip-aside-token');
    });
});
