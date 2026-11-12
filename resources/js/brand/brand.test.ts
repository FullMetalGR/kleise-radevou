import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

/*
 * The brand's tokens as the admin build wrote them: the one tokens file reaches both stylesheets,
 * the brand's custom properties are declared on the root alone, and the admin's own theme is
 * redefined nowhere but in the brand's scope, the class the console's shell and the signup's
 * guest layout wear, and in the tenant's named themes, each a light and a dark block of the
 * primary, its text and the ring keyed on the body's data-theme, neutral having none so that it
 * stays the root's own. The build wrote the files this reads, so npm run build runs before npm
 * run test:js, the order the gates keep.
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

    it('keeps every other token of the theme on the root and the dark theme alone', () => {
        for (const token of ['--background', '--foreground', '--card', '--secondary', '--muted', '--accent', '--destructive', '--border', '--input', '--radius']) {
            expect(declaring(ADMIN, token).filter((selector) => selector !== ':root' && selector !== '.dark')).toEqual([]);
        }
    });

    it('takes the brand\'s primary into the scope from the brand\'s tokens and nothing else', () => {
        const scope = rulesOf(ADMIN).find((rule) => rule.selector === '.brand-scope');

        expect(scope?.declarations).toContain('--primary:var(--brand-primary)');
        expect(scope?.declarations).toContain('--primary-foreground:var(--brand-primary-contrast)');
        expect(scope?.declarations).toContain('--ring:var(--brand-primary)');
    });
});
