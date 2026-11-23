import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { mount, type Mounted } from '@/components/ui/harness';
import { type ChangeKind, FigureLine, FigureTile, Gauge, Strip } from './kit';

/*
 * The figures kit Home and Reports share: a range tile, its title, its figure in one of three
 * sizes, its comparison as a chip whose tone and arrow follow the change and whether more is
 * better, its quiet lines on the kit's card or on a fill, the decorative ring gauge, and the
 * header strip a card below the tiles opens on.
 */

let view: Mounted;

beforeEach(() => {
    view = mount();
});

afterEach(() => view.unmount());

const q = (selector: string) => view.host.querySelector(selector);

const classesOf = (element: Element | null | undefined): string[] => [...(element?.classList ?? [])];

describe('a range tile', () => {
    it('draws its title, its figure, its comparison and then its lines, named by the tile', () => {
        view.render(
            <FigureTile id="bookings" title="tile-title-token" value="23" change="tile-change-token">
                <FigureLine>tile-line-token</FigureLine>
            </FigureTile>,
        );

        const tile = q('section.admin-figure-tile.admin-figure-tile--bookings');

        expect(classesOf(tile)).toEqual(expect.arrayContaining(['flex', 'min-w-0', 'flex-col', 'gap-1.5', 'text-muted-foreground', 'bg-card', 'rounded-tile', 'shadow-card', 'p-6.5', 'max-wide:rounded-tile-compact', 'max-wide:p-5']));
        expect(classesOf(tile)).not.toContain('border');
        expect([...(tile?.children ?? [])].map((el) => [el.tagName, el.textContent])).toEqual([
            ['H2', 'tile-title-token'],
            ['P', '23'],
            ['P', 'tile-change-token'],
            ['P', 'tile-line-token'],
        ]);
        expect(tile?.getAttribute('aria-labelledby')).toBe(q('.admin-figure-tile__title')?.id);
        expect(q('.admin-figure-tile__title')?.className).toBe('admin-figure-tile__title text-body font-semibold');
        expect(q('.admin-figure-tile__value')?.className).toBe('admin-figure-tile__value font-display leading-[1.05] font-semibold tracking-[-0.05em] tabular-nums wrap-anywhere text-foreground text-[2.375rem]');
        expect(q('.admin-figure-tile__line')?.className).toBe('admin-figure-tile__line text-small');
    });

    it('leaves out a figure or a comparison it is not given', () => {
        view.render(
            <FigureTile id="occupancy" title="tile-title-token" change={null} kind="up" moreIsBetter>
                <FigureLine>tile-line-token</FigureLine>
            </FigureTile>,
        );

        expect(q('.admin-figure-tile__value')).toBeNull();
        expect(q('.admin-figure-tile__change')).toBeNull();
        expect(q('.admin-figure-tile__line')?.textContent).toBe('tile-line-token');
    });

    it('sets its figure at the hero, the large or the regular size, regular where none is named', () => {
        for (const [size, text] of [['hero', 'text-[length:min(4.25rem,18cqi)]'], ['large', 'text-[3.25rem]'], ['regular', 'text-[2.375rem]']] as const) {
            view.render(<FigureTile id="revenue" title="tile-title-token" value="23" size={size} />);

            expect(classesOf(q('.admin-figure-tile__value'))).toContain(text);
        }

        view.render(<FigureTile id="revenue" title="tile-title-token" value="23" />);
        expect(classesOf(q('.admin-figure-tile__value'))).toContain('text-[2.375rem]');
    });

    it('measures a hero figure by its own tile, so a long amount shrinks to the tile before it would break across two lines', () => {
        view.render(<FigureTile id="revenue" title="tile-title-token" value="23" size="hero" />);

        expect(classesOf(q('.admin-figure-tile'))).toContain('@container');
        expect(classesOf(q('.admin-figure-tile__value'))).toEqual(expect.arrayContaining(['text-[length:min(4.25rem,18cqi)]', 'max-desktop:text-[length:min(3.375rem,18cqi)]']));

        // A hero tile may stand taller than its figure needs; the figure still sits right under its
        // title, as every tile's does, and the room left falls at the tile's foot.
        expect(classesOf(q('.admin-figure-tile__value')).filter((name) => /^m[ty]-auto$/.test(name))).toEqual([]);
        expect(q('.admin-figure-tile__title')?.nextElementSibling?.classList.contains('admin-figure-tile__value')).toBe(true);

        view.render(<FigureTile id="bookings" title="tile-title-token" value="23" size="large" />);
        expect(classesOf(q('.admin-figure-tile'))).not.toContain('@container');
        expect(classesOf(q('.admin-figure-tile__value')).filter((name) => /^m[ty]-auto$/.test(name))).toEqual([]);
    });

    it('lays a tile on the strong fill or the attention fill in place of the surface, its words in the attention ink on the second', () => {
        view.render(<FigureTile id="revenue" title="tile-title-token" value="23" fill="strong" />);
        expect(classesOf(q('.admin-figure-tile'))).toContain('bg-tint-strong');
        expect(classesOf(q('.admin-figure-tile'))).not.toContain('bg-card');

        view.render(<FigureTile id="cancelled" title="tile-title-token" value="23" fill="attention" />);
        expect(classesOf(q('.admin-figure-tile'))).toEqual(expect.arrayContaining(['bg-attention-soft', 'text-attention-ink', 'inset-ring', 'inset-ring-attention-line']));
        expect(classesOf(q('.admin-figure-tile'))).not.toContain('bg-card');
        expect(classesOf(q('.admin-figure-tile'))).not.toContain('text-muted-foreground');
        expect(classesOf(q('.admin-figure-tile__value'))).not.toContain('text-foreground');
    });

    it('draws what it is given beside its figure, the figure first', () => {
        view.render(<FigureTile id="occupancy" title="tile-title-token" value="58%" aside={<span className="aside-token" />} />);

        const row = q('.admin-figure-tile > .admin-figure-tile__figure');

        expect(classesOf(row)).toEqual(expect.arrayContaining(['flex', 'items-center', 'justify-between']));
        expect([...(row?.children ?? [])].map((el) => el.className)).toEqual([q('.admin-figure-tile__value')?.className, 'aside-token']);
    });
});

describe('a range tile\'s change chip', () => {
    const KINDS: ChangeKind[] = ['up', 'down', 'same', 'from_zero'];

    it('keeps its words and is green only for a rise or a rise from nought in a figure where more is better, neutral for every other pair and never red', () => {
        for (const kind of KINDS) {
            for (const moreIsBetter of [true, false]) {
                view.render(<FigureTile id="bookings" title="tile-title-token" value="23" change="tile-change-token" kind={kind} moreIsBetter={moreIsBetter} />);

                const chip = q('p.admin-figure-tile__change');
                const positive = moreIsBetter && (kind === 'up' || kind === 'from_zero');

                expect(chip?.textContent).toBe('tile-change-token');
                expect(chip?.getAttribute('data-tone')).toBe(positive ? 'positive' : 'neutral');
                expect(classesOf(chip)).toEqual(expect.arrayContaining(positive ? ['bg-positive-soft', 'text-positive-ink'] : ['bg-muted', 'text-muted-foreground']));
                expect(chip?.className).not.toMatch(/destructive/);
            }
        }

        view.render(<FigureTile id="cancelled" title="tile-title-token" value="2" change="tile-change-token" kind="down" moreIsBetter />);
        expect(q('p.admin-figure-tile__change')?.getAttribute('data-tone')).toBe('neutral');

        view.render(<FigureTile id="cancelled" title="tile-title-token" value="2" change="tile-change-token" kind="up" />);
        expect(q('p.admin-figure-tile__change')?.getAttribute('data-tone')).toBe('neutral');
    });

    it('points an arrow hidden from assistive technology up for a rise and a rise from nought, down for a fall, and draws none for no change', () => {
        const arrows = (kind: ChangeKind | null) => {
            view.render(<FigureTile id="bookings" title="tile-title-token" value="23" change="tile-change-token" kind={kind} moreIsBetter />);

            return [...(q('p.admin-figure-tile__change')?.querySelectorAll('svg') ?? [])].map((svg) => [svg.getAttribute('aria-hidden'), svg.getAttribute('data-direction')]);
        };

        expect(arrows('up')).toEqual([['true', 'up']]);
        expect(arrows('from_zero')).toEqual([['true', 'up']]);
        expect(arrows('down')).toEqual([['true', 'down']]);
        expect(arrows('same')).toEqual([]);
        expect(arrows(null)).toEqual([]);
    });

    it('sits on the surface where the tile has a fill, so the neutral chip still stands out from it', () => {
        for (const fill of ['strong', 'attention'] as const) {
            view.render(<FigureTile id="revenue" title="tile-title-token" value="23" change="tile-change-token" kind="same" fill={fill} />);

            expect(classesOf(q('p.admin-figure-tile__change'))).toEqual(expect.arrayContaining(['bg-card', 'text-muted-foreground']));
        }
    });
});

describe('the ring gauge', () => {
    it('is decoration hidden from assistive technology, its arc over its track the percent given, a hundred at most', () => {
        const arc = (percent: number) => {
            view.render(<Gauge percent={percent} />);

            return q('svg.admin-figure-tile__gauge circle:last-child')?.getAttribute('stroke-dasharray');
        };

        expect(arc(140)).toBe('100 100');
        expect(arc(58.3)).toBe('58.3 100');
        expect(q('svg.admin-figure-tile__gauge')?.getAttribute('aria-hidden')).toBe('true');
        expect(q('svg.admin-figure-tile__gauge')?.querySelectorAll('circle')).toHaveLength(2);
        expect(classesOf(q('svg.admin-figure-tile__gauge'))).toContain('size-20');
    });

    it('draws its track and no arc at nought, where an arc of no length would still leave its round cap as a dot at the top of the ring', () => {
        for (const percent of [0, -5]) {
            view.render(<Gauge percent={percent} />);

            const circles = [...(q('svg.admin-figure-tile__gauge')?.querySelectorAll('circle') ?? [])];

            expect(circles).toHaveLength(1);
            expect(classesOf(circles[0])).toContain('stroke-muted');
            expect(circles[0]?.getAttribute('stroke-dasharray')).toBeNull();
        }
    });
});

describe('a card\'s header strip', () => {
    it('holds the title at the left and what the card carries beside it at the right, above a rule, the two on two lines where a narrow card has no room for both', () => {
        view.render(<Strip titleId="top-title" title="strip-title-token" aside={<a href="/admin/bookings">strip-aside-token</a>} />);

        const strip = q('header.admin-card-strip');

        // The strip is 45px on one line: the house h2 sits on a 26px line, so 8px above and below
        // it and the rule fall short and the floor holds it at 45. A title that wraps in a narrow
        // card, or an aside pushed under it, grows the strip rather than spilling out of it.
        expect(strip?.className.split(' ')).toEqual(expect.arrayContaining(['flex', 'flex-wrap', 'items-center', 'justify-between', 'border-b', 'px-4', 'py-2', 'min-h-[45px]']));
        // No fixed height of any size: the floor alone holds the strip.
        expect(strip?.className.split(' ').filter((name) => name.startsWith('h-'))).toEqual([]);
        expect(strip?.className.split(' ')).not.toContain('py-3');
        expect(strip?.querySelector(':scope > h2#top-title.font-semibold')?.textContent).toBe('strip-title-token');
        expect(strip?.querySelector(':scope > a')?.textContent).toBe('strip-aside-token');
    });
});
