import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { mount, type Mounted } from '@/components/ui/harness';
import { Arcs } from './arcs';

/*
 * The arcs: four rings about one point in a card's corner, decoration alone. Their box is placed
 * by two edges and a width the caller gives, their tone a theme colour and their strength how
 * strongly it is drawn; their layer fills the card, clips them to its rounded box, sits under
 * what the card holds and takes no press.
 */

let view: Mounted;

beforeEach(() => {
    view = mount();
});

afterEach(() => view.unmount());

const q = (selector: string) => view.host.querySelector(selector);

const classesOf = (element: Element | null | undefined): string[] => [...(element?.classList ?? [])];

describe('the arcs', () => {
    it('draws four rings about one point near the corner of a 200 by 200 box, unfilled, in the current colour at 1.2 wide', () => {
        view.render(<Arcs edges={{ right: -80, top: -90 }} width={300} tone="attention" strength={35} />);

        const svg = q('.admin-arcs svg');

        expect(svg?.getAttribute('viewBox')).toBe('0 0 200 200');
        expect(svg?.getAttribute('fill')).toBe('none');
        expect(svg?.getAttribute('stroke')).toBe('currentColor');
        expect(svg?.getAttribute('stroke-width')).toBe('1.2');
        expect([...(svg?.querySelectorAll('circle') ?? [])].map((circle) => [circle.getAttribute('cx'), circle.getAttribute('cy'), circle.getAttribute('r')])).toEqual([
            ['150', '150', '40'],
            ['150', '150', '74'],
            ['150', '150', '108'],
            ['150', '150', '142'],
        ]);
    });

    it('is hidden from assistive technology and takes no press, on a layer filling the card that clips it to the card\'s rounded box beneath what the card holds', () => {
        view.render(<Arcs edges={{ right: -80, top: -90 }} width={300} tone="attention" strength={35} />);

        const layer = q('.admin-arcs');

        expect(layer?.getAttribute('aria-hidden')).toBe('true');
        expect(classesOf(layer)).toEqual(expect.arrayContaining(['pointer-events-none', 'absolute', 'inset-0', 'overflow-hidden', 'rounded-[inherit]', '-z-10']));
        expect(classesOf(q('.admin-arcs svg'))).toContain('absolute');
        expect(layer?.textContent).toBe('');
    });

    it('places its square box by the two edges and the width given, and draws it in the tone given at the strength given', () => {
        view.render(<Arcs edges={{ right: -70, bottom: -100 }} width={340} tone="primary" strength={16} />);

        const svg = q('.admin-arcs svg') as SVGElement | null;

        expect([svg?.style.right, svg?.style.bottom, svg?.style.top, svg?.style.left]).toEqual(['-70px', '-100px', '', '']);
        expect([svg?.style.width, svg?.style.height]).toEqual(['340px', '340px']);
        expect(svg?.style.opacity).toBe('0.16');
        expect(classesOf(svg).filter((name) => name.startsWith('text-'))).toEqual(['text-primary']);

        view.render(<Arcs edges={{ right: -60, bottom: -80 }} width={280} tone="positive" strength={25} />);
        expect(classesOf(q('.admin-arcs svg')).filter((name) => name.startsWith('text-'))).toEqual(['text-positive']);
        expect((q('.admin-arcs svg') as SVGElement | null)?.style.opacity).toBe('0.25');

        view.render(<Arcs edges={{ right: -80, top: -90 }} width={300} tone="attention" strength={35} />);
        expect(classesOf(q('.admin-arcs svg')).filter((name) => name.startsWith('text-'))).toEqual(['text-attention']);
        expect([(q('.admin-arcs svg') as SVGElement | null)?.style.top, (q('.admin-arcs svg') as SVGElement | null)?.style.right]).toEqual(['-90px', '-80px']);
    });

    it('draws the rings at three quarters of the strength given in dark mode, where the theme\'s light colour on a near-black fill would otherwise pull a light word\'s contrast down, and at the strength given in light mode', () => {
        view.render(<Arcs edges={{ right: -90, bottom: -110 }} width={400} tone="primary" strength={22} />);

        const layer = q('.admin-arcs');
        const svg = q('.admin-arcs svg') as SVGElement | null;

        expect(classesOf(layer).filter((name) => name.includes('opacity'))).toEqual(['dark:opacity-75']);
        expect(classesOf(svg).filter((name) => name.includes('opacity'))).toEqual([]);
        expect(svg?.style.opacity).toBe('0.22');
    });
});
