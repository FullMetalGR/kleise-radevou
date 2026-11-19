import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

/*
 * The brand's tokens as the admin build wrote them: the one tokens file reaches both stylesheets,
 * the brand's custom properties are declared on the root alone, and the admin's own theme is
 * redefined nowhere but in the brand's scope, the class the console's shell and the signup's
 * guest layout wear, and in the tenant's named themes, each a light and a dark block of the
 * primary, its text and the ring keyed on the body's data-theme, neutral having none so that it
 * stays the root's own. Every other colour that follows the theme is worked out from the primary
 * by one rule declared on the root, on every theme's scope and on the brand's, so it is computed
 * wherever a primary is set and never inherited finished from the root's neutral one; the mode's
 * bases and percentages and the colours that mean the same in every theme sit on the root and the
 * dark root alone. The base the page and the card are mixed into is the one colour declared twice:
 * the mode's grey in the one rule, and the theme's own hue at the mode's lightness in a supports
 * rule after it, for a browser that works one colour out of another. The build wrote the files
 * this reads, so npm run build runs before npm run test:js, the order the gates keep.
 */

const BUILD = resolve(process.cwd(), 'public/build/admin');

type Manifest = Record<string, { file: string; css?: string[] }>;

const manifest = JSON.parse(readFileSync(resolve(BUILD, 'manifest.json'), 'utf8')) as Manifest;

function builtCss(file: string | undefined): string {
    if (file === undefined) {
        throw new Error('The admin build names no such stylesheet.');
    }

    return readFileSync(resolve(BUILD, file), 'utf8');
}

const ADMIN = builtCss(manifest['resources/js/admin/app.tsx']?.css?.[0]);
const SITE = builtCss(manifest['resources/js/site/site.css']?.file);

/** Every innermost rule of a built stylesheet, its prelude and its declarations, whatever at-rule holds it. */
function rulesOf(css: string): { selector: string; declarations: string }[] {
    return [...css.replace(/\/\*[\s\S]*?\*\//g, '').matchAll(/([^{}]+)\{([^{}]*)\}/g)].map((match) => ({
        selector: (match[1] ?? '').trim().replace(/"/g, ''),
        declarations: match[2] ?? '',
    }));
}

/** The distinct preludes of the rules declaring a custom property. */
function declaring(css: string, property: string): string[] {
    const pattern = new RegExp(`(^|;)\\s*${property}:`);

    return [...new Set(rulesOf(css).filter((rule) => pattern.test(rule.declarations)).map((rule) => rule.selector))].sort();
}

const BRAND_TOKENS = ['primary', 'primary-hover', 'primary-contrast', 'accent', 'ink', 'muted', 'line', 'paper', 'surface', 'font'].map((name) => `--brand-${name}`);

describe('the brand\'s tokens in both built stylesheets', () => {
    it('declares every brand token on the root alone, in the admin\'s stylesheet and the site\'s', () => {
        for (const css of [ADMIN, SITE]) {
            for (const token of BRAND_TOKENS) {
                expect(declaring(css, token)).toEqual([':root']);
            }
        }
    });

    it('carries the wordmark\'s look in both', () => {
        for (const css of [ADMIN, SITE]) {
            expect(css).toContain('.brand-wordmark{');
            expect(css).toContain('.brand-wordmark__mark{');
            expect(css).toContain('.brand-wordmark--small{');
        }
    });
});

/** The tenant's coloured themes and, for each, the light and the dark primary, its text and the ring. */
const THEMES: Record<string, { light: [string, string, string]; dark: [string, string, string] }> = {
    blue: { light: ['#2563eb', '#fff', '#2563eb'], dark: ['#60a5fa', '#172554', '#60a5fa'] },
    green: { light: ['#15803d', '#fff', '#15803d'], dark: ['#4ade80', '#052e16', '#4ade80'] },
    orange: { light: ['#c2410c', '#fff', '#c2410c'], dark: ['#fb923c', '#431407', '#fb923c'] },
    red: { light: ['#dc2626', '#fff', '#dc2626'], dark: ['#f87171', '#450a0a', '#f87171'] },
    rose: { light: ['#e11d48', '#fff', '#e11d48'], dark: ['#fb7185', '#4c0519', '#fb7185'] },
    violet: { light: ['#7c3aed', '#fff', '#7c3aed'], dark: ['#a78bfa', '#2e1065', '#a78bfa'] },
};

const THEMED = ['--primary', '--primary-foreground', '--ring'];

/** The first value a rule of this prelude gives the property: the build's sRGB fallback, written before any wider-gamut form. */
function firstValue(selector: string, property: string): string | undefined {
    const pattern = new RegExp(`(?:^|;)\\s*${property}:([^;]+)`);

    for (const rule of rulesOf(ADMIN)) {
        const found = rule.selector === selector ? pattern.exec(rule.declarations) : null;

        if (found !== null) {
            return found[1]?.trim();
        }
    }

    return undefined;
}

/** The custom properties every rule of this prelude declares, in order of name. */
function propertiesOf(selector: string): string[] {
    const names = rulesOf(ADMIN)
        .filter((rule) => rule.selector === selector)
        .flatMap((rule) => rule.declarations.split(';').map((declaration) => declaration.split(':')[0]?.trim() ?? ''))
        .filter((name) => name.length > 0);

    return [...new Set(names)].sort();
}

/** A prelude's selectors, each on its own. */
function selectorsOf(prelude: string): string[] {
    return prelude.split(',').map((selector) => selector.trim());
}

/**
 * The admin's stylesheet as it is written. The build sets a plain fallback beside each mix for a
 * browser that cannot mix, in the same rule, so how many times a property is declared is read here.
 */
const ADMIN_SOURCE = readFileSync(resolve(process.cwd(), 'resources/js/admin/app.css'), 'utf8');

/** How many declarations of the property a stylesheet holds. */
function declarationCount(css: string, property: string): number {
    return rulesOf(css)
        .flatMap((rule) => rule.declarations.split(';'))
        .filter((declaration) => declaration.trim().startsWith(`${property}:`)).length;
}

/** A piece of a stylesheet with its white space taken out, so the source and the build read alike. */
function squeezed(css: string): string {
    return css.replace(/\s+/g, '');
}

/** The values the rules of a stylesheet give the property, in the order they are written. */
function valuesOf(css: string, property: string): string[] {
    const pattern = new RegExp(`(?:^|;)\\s*${property}:([^;]+)`);

    return rulesOf(css).flatMap((rule) => {
        const found = pattern.exec(rule.declarations);

        return found === null ? [] : [squeezed(found[1] ?? '')];
    });
}

/** Every supports rule of a stylesheet that holds plain rules alone: its condition and those rules. */
function supportsRulesOf(css: string): { condition: string; rules: { selector: string; declarations: string }[] }[] {
    const pattern = /@supports([^{]+)\{((?:[^{}]*\{[^{}]*\})*)\s*\}/g;

    return [...css.replace(/\/\*[\s\S]*?\*\//g, '').matchAll(pattern)].map((match) => ({
        condition: (match[1] ?? '').trim(),
        rules: rulesOf(match[2] ?? ''),
    }));
}

/** The selectors the one rule is declared on: the root, the body a theme is keyed on and the brand's scope. */
const ONE_RULE = [':root', '[data-theme]', '.brand-scope'];

/**
 * The base the page and the card are mixed into, and its two values: the mode's grey, and the
 * theme's primary brought to the mode's lightness with a share of its chroma and its own hue.
 */
const BASE = '--surface-base';
const BASE_PLAIN = squeezed('var(--mode-base)');
const BASE_TINTED = squeezed('oklch(from var(--primary) var(--mode-base-lightness) calc(c * var(--mode-base-tint)) h)');

/** The colours worked out from the theme's primary, the surfaces' base and the page's tint to the scrim, and the void status, which reads the secondary ink. */
const FOLLOWING = [
    BASE,
    '--background',
    '--card',
    '--popover',
    '--muted',
    '--secondary',
    '--accent',
    '--tint-strong',
    '--foreground',
    '--card-foreground',
    '--popover-foreground',
    '--secondary-foreground',
    '--accent-foreground',
    '--muted-foreground',
    '--ink-tertiary',
    '--primary-deep',
    '--border',
    '--input',
    '--shade',
    '--scrim',
    '--admin-status-void',
];

/** The colours that mean the same in every theme, each its light and its dark value. */
const FIXED: Record<string, [string, string]> = {
    '--destructive': ['#c3262e', '#ff8a90'],
    '--destructive-foreground': ['#fff', '#2a0509'],
    '--destructive-soft': ['#fde6e6', '#3c1d22'],
    '--attention': ['#f2a20c', '#f2a20c'],
    '--attention-soft': ['#fff5dc', '#34280c'],
    '--attention-soft-strong': ['#ffe9b5', '#4a3710'],
    '--attention-line': ['#f2d58a', '#6b4e12'],
    '--attention-ink': ['#6b4200', '#ffd98a'],
    '--positive': ['#0e9f6e', '#2bc48a'],
    '--positive-soft': ['#daf5e7', '#0f3a2a'],
    '--positive-ink': ['#065f3a', '#7be2b2'],
    '--spark': ['#cdf64b', '#cdf64b'],
    '--spark-foreground': ['#1b2b00', '#1b2b00'],
    '--now-line': ['#d92d3a', '#ff6b74'],
    '--neutral-mark': ['#7a88a6', '#8e9bba'],
};

/**
 * The mode's bases, the lightness the tinted base sits at with the share of the primary's chroma
 * it keeps, and how much primary each colour of the one rule takes, light and dark.
 */
const MODE: Record<string, [string, string]> = {
    '--mode-base': ['#fff', '#0a0a0a'],
    '--mode-base-lightness': ['1', '.145'],
    '--mode-base-tint': ['0', '.24'],
    '--mode-ink-base': ['#0a0a0a', '#fafafa'],
    '--mode-deep-base': ['black', 'white'],
    '--mix-page': ['6%', '8%'],
    '--mix-surface': ['0%', '14%'],
    '--mix-soft': ['10%', '12%'],
    '--mix-strong': ['18%', '26%'],
    '--mix-ink': ['15%', '15%'],
    '--mix-deep': ['80%', '65%'],
    '--mix-line-ink': ['10%', '0%'],
    '--mix-line-primary': ['0%', '21%'],
    '--shadow-hairline': ['5%', '30%'],
    '--shadow-card-strength': ['22%', '65%'],
};

describe('the admin\'s own theme', () => {
    it('is redefined only by the root, the dark theme, the brand\'s scope and the tenant\'s coloured themes, so wherever the admin\'s theme applies its colours are its own', () => {
        const themes = Object.keys(THEMES).flatMap((theme) => [`.dark [data-theme=${theme}]`, `[data-theme=${theme}]`]);

        for (const token of THEMED) {
            expect(declaring(ADMIN, token)).toEqual(['.brand-scope', '.dark', '.dark .brand-scope', ':root', ...themes].sort());
        }
    });

    it('keeps neutral the root\'s own values, today\'s, with no block of its own', () => {
        expect(THEMED.map((token) => firstValue(':root', token))).toEqual(['#171717', '#fafafa', '#a1a1a1']);
        expect(THEMED.map((token) => firstValue('.dark', token))).toEqual(['#e5e5e5', '#171717', '#737373']);
        expect(rulesOf(ADMIN).filter((rule) => rule.selector.includes('neutral'))).toEqual([]);
    });

    it.each(Object.entries(THEMES))('paints %s in its light and its dark values of the three tokens', (theme, values) => {
        expect(THEMED.map((token) => firstValue(`[data-theme=${theme}]`, token))).toEqual(values.light);
        expect(THEMED.map((token) => firstValue(`.dark [data-theme=${theme}]`, token))).toEqual(values.dark);
    });

    it('a theme declares its three tokens and nothing else', () => {
        const blocks = Object.keys(THEMES).flatMap((theme) => [`[data-theme=${theme}]`, `.dark [data-theme=${theme}]`]);

        expect(blocks).toHaveLength(12);

        for (const block of blocks) {
            expect(propertiesOf(block)).toEqual([...THEMED].sort());
        }
    });

    it('the tokens that follow the theme live in one rule, each declared once but the base, which a supports rule declares again', () => {
        for (const token of FOLLOWING) {
            for (const css of [ADMIN, ADMIN_SOURCE]) {
                expect([...new Set(declaring(css, token).map((prelude) => selectorsOf(prelude).join()))]).toEqual([ONE_RULE.join()]);
            }

            expect(declarationCount(ADMIN_SOURCE, token)).toBe(token === BASE ? 2 : 1);
        }
    });

    it('the base is the mode\'s grey first, then the theme\'s hue at the mode\'s lightness where a browser works one colour out of another', () => {
        for (const css of [ADMIN, ADMIN_SOURCE]) {
            expect(valuesOf(css, BASE)).toEqual([BASE_PLAIN, BASE_TINTED]);

            const holding = supportsRulesOf(css).filter((supports) => supports.rules.some((rule) => rule.declarations.includes(`${BASE}:`)));

            expect(holding.map((supports) => squeezed(supports.condition))).toEqual([squeezed('(color: oklch(from red 0.5 calc(c * 0.5) h))')]);
            expect(holding[0]?.rules.map((rule) => selectorsOf(rule.selector))).toEqual([ONE_RULE]);
            expect(holding[0]?.rules.map((rule) => squeezed(rule.declarations).replace(/;$/, ''))).toEqual([`${BASE}:${BASE_TINTED}`]);
        }

        expect(squeezed(ADMIN_SOURCE)).toContain(squeezed(`${BASE}: var(--mode-base); --background: color-mix(in oklab, var(--primary) var(--mix-page), var(${BASE}));`));
        expect(squeezed(ADMIN_SOURCE)).toContain(squeezed(`--card: color-mix(in oklab, var(--primary) var(--mix-surface), var(${BASE}));`));
    });

    it('the fixed tokens and the mode properties are declared on the root and the dark root alone', () => {
        for (const token of [...Object.keys(FIXED), ...Object.keys(MODE)]) {
            expect(declaring(ADMIN, token)).toEqual(['.dark', ':root']);
        }

        expect(declaring(ADMIN, '--radius')).toEqual([':root']);
    });

    it('keeps the fixed tokens one value per mode, whatever the theme', () => {
        expect(Object.keys(FIXED)).toHaveLength(15);

        for (const [token, values] of Object.entries(FIXED)) {
            expect([firstValue(':root', token), firstValue('.dark', token)]).toEqual(values);
        }
    });

    it('no rule on the root alone reads the theme', () => {
        const reads = ['--primary', ...FOLLOWING].map((token) => `var(${token})`);
        const alone = rulesOf(ADMIN).filter((rule) => rule.selector === ':root' || rule.selector === '.dark');

        expect(alone.length).toBeGreaterThan(0);

        for (const rule of alone) {
            expect(reads.filter((read) => rule.declarations.includes(read))).toEqual([]);
        }
    });

    it('gives each mode property its light value on the root and its dark one on the dark root: the bases, the tinted base\'s lightness and share of chroma, how much primary each colour takes and the shadows\' strengths', () => {
        for (const [property, values] of Object.entries(MODE)) {
            expect([firstValue(':root', property), firstValue('.dark', property)]).toEqual(values);
        }
    });

    it('writes the calendar\'s now pill, which the destructive red fills, in the ink made for that red, so its time reads on the dark mode\'s pale red too', () => {
        const pill = rulesOf(ADMIN).filter((rule) => rule.selector === '.admin-calendar-now');

        expect(pill).toHaveLength(1);
        expect(squeezed(pill[0]?.declarations ?? '')).toContain('background-color:var(--destructive);');
        expect(squeezed(pill[0]?.declarations ?? '')).toMatch(/(^|;)color:var\(--destructive-foreground\)(;|$)/);
    });

    it('builds the link\'s deepened primary and the destructive primary\'s ink as rules that name their tokens, so a utility the button writes is never left without a rule', () => {
        for (const [selector, token] of [
            ['.text-primary-deep', '--primary-deep'],
            ['.text-destructive-foreground', '--destructive-foreground'],
        ]) {
            const rules = rulesOf(ADMIN).filter((rule) => rule.selector === selector);

            expect(rules).toHaveLength(1);
            expect(squeezed(rules[0]?.declarations ?? '')).toMatch(new RegExp(`(^|;)color:var\\(${token}\\)(;|$)`));
        }
    });

    it('builds the focus ring as a 3px outline of the theme colour on keyboard focus alone, and draws the icon set\'s strokes a little lighter than its own', () => {
        const ring = rulesOf(ADMIN).filter((rule) => rule.selector === '.focus-ring:focus-visible');

        expect(ring).toHaveLength(1);
        expect(squeezed(ring[0]?.declarations ?? '')).toMatch(/(^|;)outline:3pxsolidvar\(--primary\)(;|$)/);
        expect(squeezed(ring[0]?.declarations ?? '')).toMatch(/(^|;)outline-offset:2px(;|$)/);

        const stroke = rulesOf(ADMIN).filter((rule) => rule.selector === '.lucide');

        expect(stroke).toHaveLength(1);
        expect(squeezed(stroke[0]?.declarations ?? '')).toMatch(/(^|;)stroke-width:1\.8(px)?(;|$)/);
    });

    it('builds the field\'s focus ring as the same outline held 1px off the control', () => {
        const ring = rulesOf(ADMIN).filter((rule) => rule.selector === '.focus-ring-field:focus-visible');

        expect(ring).toHaveLength(1);
        expect(squeezed(ring[0]?.declarations ?? '')).toMatch(/(^|;)outline:3pxsolidvar\(--primary\)(;|$)/);
        expect(squeezed(ring[0]?.declarations ?? '')).toMatch(/(^|;)outline-offset:1px(;|$)/);
    });

    it('draws the day calendar\'s days as 40px circles, the chosen one filled and today ringed in the primary, the month in the display face', () => {
        const rules = rulesOf(ADMIN);
        const block = (selector: string): string => squeezed(rules.filter((rule) => rule.selector.split(',').includes(selector)).map((rule) => rule.declarations).join(';'));
        const calendar = block('.admin-day-calendar');

        expect(calendar).toMatch(/(^|;)--rdp-day_button-height:2\.5rem(;|$)/);
        expect(calendar).toMatch(/(^|;)--rdp-day_button-width:2\.5rem(;|$)/);
        expect(calendar).toMatch(/(^|;)--rdp-day_button-border-radius:50%(;|$)/);
        expect(block('.admin-day-calendar .rdp-selected .rdp-day_button')).toMatch(/(^|;)background-color:var\(--primary\)(;|$)/);
        expect(block('.admin-day-calendar .admin-day-calendar__today:not(.rdp-outside) .rdp-day_button')).toMatch(/(^|;)box-shadow:inset0001\.5pxvar\(--primary\)(;|$)/);
        expect(block('.admin-day-calendar .rdp-month_caption')).toMatch(/(^|;)font-family:var\(--font-display\)(;|$)/);
        expect(rules.filter((rule) => rule.selector.startsWith('.admin-day-calendar')).some((rule) => rule.declarations.includes('--font-mono'))).toBe(false);
    });

    it('takes the brand\'s primary into the scope from the brand\'s tokens and nothing else', () => {
        const scope = rulesOf(ADMIN).find((rule) => rule.selector === '.brand-scope');

        expect(scope?.declarations).toContain('--primary:var(--brand-primary)');
        expect(scope?.declarations).toContain('--primary-foreground:var(--brand-primary-contrast)');
        expect(scope?.declarations).toContain('--ring:var(--brand-primary)');
    });
});
