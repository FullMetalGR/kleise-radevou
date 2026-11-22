import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import { cn } from './utils';

/*
 * The class joiner knows the admin's own scales by name: a text size, a corner radius, a shadow,
 * an easing and a face each conflict with another of their kind, the later one
 * winning, and never with a colour, which a size or a shadow by its name alone would read as.
 */
describe('the class joiner over the admin\'s own scales', () => {
    it('keeps a named text size beside a text colour, and the later of two sizes alone', () => {
        expect(cn('text-page-title', 'text-foreground')).toBe('text-page-title text-foreground');
        expect(cn('text-body', 'text-page-title')).toBe('text-page-title');
        expect(cn('text-sm', 'text-label')).toBe('text-label');
    });

    it('keeps the later of two corner radii, a named one against a stock one', () => {
        expect(cn('rounded-xl', 'rounded-tile')).toBe('rounded-tile');
        expect(cn('rounded-tile', 'rounded-field')).toBe('rounded-field');
    });

    it('keeps the later of two shadows, and a named shadow beside a shadow colour', () => {
        expect(cn('shadow-card', 'shadow-float')).toBe('shadow-float');
        expect(cn('shadow-sm', 'shadow-card')).toBe('shadow-card');
        expect(cn('shadow-card', 'shadow-black')).toBe('shadow-card shadow-black');
    });

    it('keeps the later of two easings', () => {
        expect(cn('ease-out', 'ease-soft')).toBe('ease-soft');
    });

    it('keeps the later of two faces, and a face beside a weight', () => {
        expect(cn('font-display', 'font-text')).toBe('font-text');
        expect(cn('font-display', 'font-semibold')).toBe('font-display font-semibold');
    });
});

/*
 * The scales the joiner has to be told the names of, each with the utility its names are spelled
 * behind and a stock value of its kind. The joiner knows the stock value by itself, so a name it
 * knows as the same kind takes the stock one's place, and a name it does not stays beside it.
 */
const SCALES = {
    text: { utility: 'text', stock: 'base' },
    radius: { utility: 'rounded', stock: 'none' },
    shadow: { utility: 'shadow', stock: 'none' },
    ease: { utility: 'ease', stock: 'linear' },
    container: { utility: 'max-w', stock: 'none' },
};

type Scale = keyof typeof SCALES;

const isScale = (word: string | undefined): word is Scale => word !== undefined && word in SCALES;

/*
 * A property declared in one of those scales: the scale, then the name. What a size carries
 * with it, its line, tracking and weight, hangs off the size's name by two hyphens and is no
 * name of its own; a property read inside a value is followed by no colon.
 */
const DECLARED = new RegExp(`(?<![\\w-])--(${Object.keys(SCALES).join('|')})-([a-z0-9]+(?:-[a-z0-9]+)*)\\s*:`, 'g');

/*
 * The names a stylesheet's theme blocks declare in those scales, each under its scale, in the
 * order written. Every theme block is read, whatever word follows the rule's name, and nothing
 * outside one: a property a selector sets can share a scale's prefix and names no utility.
 */
function themeNames(css: string): Array<[Scale, string]> {
    const blocks = css
        .replace(/\/\*[\s\S]*?\*\//g, '')
        .split(/@theme\b[^{;]*\{/)
        .slice(1)
        .map((rest) => {
            let depth = 1;
            let end = 0;

            while (end < rest.length && depth > 0) {
                depth += rest[end] === '{' ? 1 : rest[end] === '}' ? -1 : 0;
                end += 1;
            }

            return rest.slice(0, end);
        });

    return blocks.flatMap((block) => [...block.matchAll(DECLARED)].flatMap(([, scale, name]): Array<[Scale, string]> => (isScale(scale) && name !== undefined ? [[scale, name]] : [])));
}

/*
 * The names the joiner is told, each under its scale, read out of the lists in its own source.
 */
function joinerNames(source: string): Array<[Scale, string]> {
    const theme = /theme:\s*\{([\s\S]*?)\}/.exec(source)?.[1] ?? '';

    return [...theme.matchAll(/(\w+):\s*\[([^\]]*)\]/g)].flatMap(([, scale, list]) =>
        isScale(scale) ? [...(list ?? '').matchAll(/'([^']+)'/g)].map(([, name]): [Scale, string] => [scale, name ?? '']) : [],
    );
}

/*
 * The stylesheet's theme and the joiner agree. A name added to a theme block and not to the
 * joiner's list makes a utility the joiner misreads: it drops text-page-title beside
 * text-foreground as one colour after another, and keeps rounded-tile beside rounded-xl. A name
 * left in the joiner's list after its token went names a utility that draws nothing.
 */
describe('the class joiner and the stylesheet\'s theme blocks', () => {
    it('knows every text size, corner radius, shadow, easing and width the theme blocks name', () => {
        const named = themeNames(readFileSync(join(process.cwd(), 'resources/js/admin/app.css'), 'utf8'));
        const unknown = named
            .map(([scale, name]) => ({ stock: `${SCALES[scale].utility}-${SCALES[scale].stock}`, own: `${SCALES[scale].utility}-${name}` }))
            .filter(({ stock, own }) => cn(stock, own) !== own)
            .map(({ own }) => own);

        expect(named).not.toEqual([]);
        expect(unknown).toEqual([]);
    });

    it('is told no name the theme blocks do not declare: every name in its lists is a token of its scale', () => {
        const declared = new Set(themeNames(readFileSync(join(process.cwd(), 'resources/js/admin/app.css'), 'utf8')).map(([scale, name]) => `${scale}-${name}`));
        const told = joinerNames(readFileSync(join(process.cwd(), 'resources/js/admin/lib/utils.ts'), 'utf8')).map(([scale, name]) => `${scale}-${name}`);

        expect(told).not.toEqual([]);
        expect(told.filter((name) => !declared.has(name))).toEqual([]);
    });

    it('reads a name out of a plain and an inline block, and passes over a size\'s own line, a colour, a comment and a property outside the theme', () => {
        const css = `
            @theme inline { --color-card: var(--card); --radius-lg: var(--radius); --shadow-card: 0 0 0 1px var(--shadow-hairline); }
            @theme {
                /* --text-gone: 1rem; */
                --text-body: 1rem;
                --text-body--line-height: 1.45;
                --ease-soft: linear;
                --container-page: 80rem;
            }
            :root { --shadow-hairline: 5%; }
        `;

        expect(themeNames(css)).toEqual([
            ['radius', 'lg'],
            ['shadow', 'card'],
            ['text', 'body'],
            ['ease', 'soft'],
            ['container', 'page'],
        ]);
    });
});
