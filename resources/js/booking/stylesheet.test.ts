import { existsSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';
import { installStylesheet, STYLESHEET_ATTRIBUTE } from './stylesheet';

/*
 * The containment audit, over the built stylesheet: every selector starts with the prefixed
 * class, no bare element selector exists, no declaration is marked important, and the eight
 * custom properties are the theming surface. The build wrote the file this reads, so npm run
 * build runs before npm run test:js, the order the gates keep. The audit is written here, and
 * tested here on fixtures, so a stylesheet that broke a rule could not pass by breaking the
 * audit's parser too.
 */

const PREFIX = '.kr-booking';
const BUILT_CSS = resolve(process.cwd(), 'public/build/embed.css');
const BUILT_JS = resolve(process.cwd(), 'public/build/embed.js');
const TOKENS = ['primary', 'primary-foreground', 'ring', 'radius', 'spacing', 'error', 'success', 'focus'].map((name) => `--kr-${name}`);

/**
 * The seven themes' light primaries: neutral on the bare mount, every other keyed on the
 * mount's data-theme.
 */
const PRIMARIES = { neutral: '#171717', blue: '#2563eb', green: '#15803d', orange: '#c2410c', red: '#dc2626', rose: '#e11d48', violet: '#7c3aed' } as const;

interface Rule {
    readonly selectors: string[];
    readonly declarations: string;
}

function stripComments(css: string): string {
    return css.replace(/\/\*[\s\S]*?\*\//g, '');
}

/**
 * Every style rule, prelude split on commas, with at-rule blocks descended into. The
 * stylesheet uses no nesting and no strings with braces, and the audit refuses both rather
 * than parse them.
 */
function rulesOf(css: string): Rule[] {
    const rules: Rule[] = [];
    const stack: Array<'at' | 'rule'> = [];
    let buffer = '';

    for (const char of stripComments(css)) {
        if (char === '{') {
            const prelude = buffer.trim();

            if (stack.at(-1) === 'rule') {
                throw new Error(`nested block inside a rule at "${prelude}"; the audit does not parse nesting.`);
            }

            if (prelude.startsWith('@')) {
                stack.push('at');
            } else {
                stack.push('rule');
                rules.push({ selectors: prelude.split(',').map((selector) => selector.trim()), declarations: '' });
            }

            buffer = '';
        } else if (char === '}') {
            if (stack.pop() === 'rule') {
                const rule = rules.at(-1);

                if (rule !== undefined) {
                    rules[rules.length - 1] = { ...rule, declarations: buffer.trim() };
                }
            }

            buffer = '';
        } else {
            buffer += char;
        }
    }

    if (stack.length > 0 || buffer.trim() !== '') {
        throw new Error('unbalanced braces or trailing text in the stylesheet.');
    }

    return rules;
}

/**
 * The compound selectors of one selector: what sits between combinators.
 */
function compoundsOf(selector: string): string[] {
    return selector
        .replace(/\[[^\]]*\]/g, (attribute) => attribute.replace(/\s/g, ''))
        .split(/\s*[>+~]\s*|\s+/)
        .filter((compound) => compound !== '');
}

function audit(css: string): string[] {
    const findings: string[] = [];

    if (/!\s*important/i.test(stripComments(css))) {
        findings.push('a declaration is marked !important');
    }

    for (const rule of rulesOf(css)) {
        for (const selector of rule.selectors) {
            const compounds = compoundsOf(selector);

            if (compounds.length === 0) {
                findings.push(`"${selector}" is an empty selector`);
            }

            for (const compound of compounds) {
                if (!compound.startsWith(PREFIX)) {
                    findings.push(`"${selector}": "${compound}" does not start with ${PREFIX}`);
                }
            }
        }
    }

    return findings;
}

/**
 * Every custom property of the prefix declared on the bare mount, in the order first declared, a
 * token outside TOKENS included, so a stray or a leftover one fails the surface's pin.
 */
function tokensDeclaredOnTheMount(css: string): string[] {
    const declared = new Set<string>();

    for (const rule of rulesOf(css)) {
        if (rule.selectors.includes(PREFIX)) {
            for (const match of rule.declarations.matchAll(/(--kr-[a-z-]+)\s*:/g)) {
                declared.add(match[1] ?? '');
            }
        }
    }

    return [...declared];
}

/**
 * The classes whose pressed state the stylesheet styles: every compound carrying an aria-pressed
 * attribute selector, reduced to its leading class. Quotes and case are the minifier's to choose,
 * so neither is read.
 */
function pressedStyled(css: string): string[] {
    const styled = new Set<string>();

    for (const rule of rulesOf(css)) {
        for (const selector of rule.selectors) {
            for (const compound of compoundsOf(selector)) {
                if (/\[aria-pressed/i.test(compound)) {
                    styled.add(compound.replace(/[[:].*$/, ''));
                }
            }
        }
    }

    return [...styled].sort();
}

describe('the audit itself', () => {
    it('accepts prefixed selectors, combinators, states and attribute selectors', () => {
        expect(audit('.kr-booking{color:red}.kr-booking__a > .kr-booking__b,.kr-booking__c:focus-visible{margin:0}@media (min-width:40em){.kr-booking__d[aria-busy="true"]::before{opacity:.5}}')).toEqual([]);
    });

    it('catches a selector that does not start with the prefix', () => {
        expect(audit('.other{color:red}')).toEqual(['".other": ".other" does not start with .kr-booking']);
    });

    it('catches a bare element selector anywhere in a selector', () => {
        expect(audit('.kr-booking p{margin:0}')).toEqual(['".kr-booking p": "p" does not start with .kr-booking']);
        expect(audit('.kr-booking > button{margin:0}')).toHaveLength(1);
        expect(audit('.kr-booking *{margin:0}')).toHaveLength(1);
        expect(audit('.kr-booking, h2{margin:0}')).toHaveLength(1);
    });

    it('catches an important declaration', () => {
        expect(audit('.kr-booking{color:red !important}')).toEqual(['a declaration is marked !important']);
        expect(audit('.kr-booking{color:red ! IMPORTANT}')).toHaveLength(1);
    });

    it('finds the tokens declared on the mount element', () => {
        expect(tokensDeclaredOnTheMount('.kr-booking{--kr-primary-foreground:#fff;--kr-ring:#000;--kr-focus:var(--kr-ring)}')).toEqual(['--kr-primary-foreground', '--kr-ring', '--kr-focus']);
        expect(tokensDeclaredOnTheMount('.kr-booking__x{--kr-ring:#000}')).toEqual([]);
    });
});

/**
 * Every at-rule block of a stylesheet, its prelude and the rules inside it, read as rulesOf reads
 * the whole sheet. The stylesheet nests nothing deeper than one at-rule.
 */
function atRulesOf(css: string): { readonly prelude: string; readonly rules: Rule[] }[] {
    const blocks: { prelude: string; rules: Rule[] }[] = [];
    const text = stripComments(css);

    for (let at = text.indexOf('@'); at !== -1; at = text.indexOf('@', at + 1)) {
        const open = text.indexOf('{', at);
        let depth = 0;
        let end = open;

        for (; end < text.length; end += 1) {
            if (text.charAt(end) === '{') {
                depth += 1;
            } else if (text.charAt(end) === '}') {
                depth -= 1;

                if (depth === 0) {
                    break;
                }
            }
        }

        blocks.push({ prelude: text.slice(at, open).trim(), rules: rulesOf(text.slice(open + 1, end)) });
        at = end;
    }

    return blocks;
}

/**
 * Every declaration the stylesheet gives one selector outside its at-rules, the rules naming it
 * joined in order, spaces dropped, as one string: what the cascade gives it at a desktop's width.
 */
function declarationsOutside(css: string): (selector: string) => string {
    const text = stripComments(css);
    let outside = '';
    let depth = 0;
    let inAt = false;

    for (let at = 0; at < text.length; at += 1) {
        const char = text.charAt(at);

        if (char === '@' && depth === 0) {
            inAt = true;
        }

        if (!inAt) {
            outside += char;
        }

        if (char === '{') {
            depth += 1;
        } else if (char === '}') {
            depth -= 1;

            if (depth === 0) {
                inAt = false;
            }
        }
    }

    const rules = rulesOf(outside);

    return (selector) =>
        rules
            .filter((rule) => rule.selectors.includes(selector))
            .map((rule) => rule.declarations.replace(/,\s+/g, ','))
            .join(';');
}

/** The declarations a block gives one selector, as one string, or undefined where it gives none. */
function declarationsFor(rules: readonly Rule[], selector: string): string | undefined {
    return rules.find((rule) => rule.selectors.includes(selector))?.declarations;
}

describe('the built stylesheet', () => {
    it('exists because the build ran first', () => {
        expect(existsSync(BUILT_CSS), `${BUILT_CSS} is missing; run npm run build before npm run test:js.`).toBe(true);
    });

    it('has every selector under the prefixed class, no bare element selector and no important', () => {
        expect(audit(readFileSync(BUILT_CSS, 'utf8'))).toEqual([]);
    });

    it('reads the pressed state styled per class, on fixtures', () => {
        expect(pressedStyled('.kr-booking__day[aria-pressed=true],.kr-booking__x:hover{color:red}.kr-booking__choice[aria-pressed="true"]:focus{margin:0}')).toEqual(['.kr-booking__choice', '.kr-booking__day']);
    });

    it('styles a pressed state on the chips, the service cards, staff choices, week-row days and periods', () => {
        expect(pressedStyled(readFileSync(BUILT_CSS, 'utf8'))).toEqual(['.kr-booking__chip', '.kr-booking__choice', '.kr-booking__day', '.kr-booking__period', '.kr-booking__service']);
    });

    it('reads the at-rule blocks and what each gives a selector, on fixtures', () => {
        const blocks = atRulesOf('.kr-booking{margin:0}@media (max-width:640px){.kr-booking__a{position:sticky;bottom:0}.kr-booking__b{display:block}}');

        expect(blocks.map((block) => block.prelude)).toEqual(['@media (max-width:640px)']);
        expect(declarationsFor(blocks[0]?.rules ?? [], '.kr-booking__a')).toBe('position:sticky;bottom:0');
        expect(declarationsFor(blocks[0]?.rules ?? [], '.kr-booking__c')).toBeUndefined();
    });

    it('holds one media query, at or under 640 pixels, that makes the summary card the sticky bottom bar, and no animation', () => {
        const blocks = atRulesOf(readFileSync(BUILT_CSS, 'utf8'));
        const [phone] = blocks;
        const bar = declarationsFor(phone?.rules ?? [], '.kr-booking__estimate')?.replace(/\s/g, '') ?? '';

        expect(blocks.map((block) => block.prelude.replace(/\s/g, ''))).toEqual(['@media(max-width:640px)']);
        expect(bar).toContain('position:sticky');
        expect(bar).toContain('bottom:0');
        expect(declarationsFor(phone?.rules ?? [], '.kr-booking__layout')?.replace(/\s/g, '')).toBe('display:block');
        expect(readFileSync(BUILT_CSS, 'utf8')).not.toMatch(/@keyframes|animation/);
    });

    it('hides the bar while the card is empty and keeps the crumb on one line that scrolls sideways, at a phone\'s width alone', () => {
        const [phone] = atRulesOf(readFileSync(BUILT_CSS, 'utf8'));
        const trail = declarationsFor(phone?.rules ?? [], '.kr-booking__crumbs')?.replace(/\s/g, '') ?? '';
        const sheet = declarationsOutside(readFileSync(BUILT_CSS, 'utf8'));

        expect(declarationsFor(phone?.rules ?? [], '.kr-booking__estimate--empty')?.replace(/\s/g, '')).toBe('display:none');
        expect(trail).toContain('flex-wrap:nowrap');
        expect(trail).toContain('overflow-x:auto');
        expect(trail).toContain('white-space:nowrap');
        expect(sheet('.kr-booking__estimate--empty')).toBe('');
        expect(sheet('.kr-booking__crumbs')).toContain('flex-wrap:wrap');
    });

    it('lays the staff choices out as a grid of cards and the times as a grid of pills, the day and the slot pills shared by the reschedule dialog', () => {
        const sheet = declarationsOutside(readFileSync(BUILT_CSS, 'utf8'));

        expect(sheet('.kr-booking__people')).toContain('grid-template-columns:repeat(auto-fill,minmax(220px,1fr))');
        expect(stripComments(readFileSync(BUILT_CSS, 'utf8'))).toMatch(/\.kr-booking__choice\[aria-pressed="?true"?\]\{[^}]*border:2px solid var\(--kr-primary\)/);
        expect(sheet('.kr-booking__grid')).toContain('grid-template-columns:repeat(auto-fill,minmax(5.25rem,1fr))');
        expect(sheet('.kr-booking__slot')).toContain('min-height:3rem');
        expect(sheet('.kr-booking__day')).toContain('min-height:4.75rem');
        expect(sheet('.kr-booking__previous')).toContain('border-radius:50%');
        expect(sheet('.kr-booking__confirm')).toContain('width:100%');
    });

    it('lays the step and the summary card out in two columns where the frame is wide enough, the card sticky beside the step, and the step alone centred on review and done', () => {
        const sheet = declarationsOutside(readFileSync(BUILT_CSS, 'utf8'));

        expect(sheet('.kr-booking__layout')).toContain('flex-wrap:wrap');
        expect(sheet('.kr-booking__layout')).toContain('gap:40px');
        expect(sheet('.kr-booking__main')).toMatch(/(^|;)flex:(1 1 )?480px(;|$)/);
        expect(sheet('.kr-booking__main')).toContain('min-width:0');
        expect(sheet('.kr-booking__estimate')).toContain('flex:0 0 360px');
        expect(sheet('.kr-booking__estimate')).toContain('position:sticky');
        expect(sheet('.kr-booking__estimate')).toContain('top:24px');
        expect(sheet('.kr-booking__layout--single .kr-booking__main')).toContain('max-width:720px');
    });

    it('draws the frame with no border, the controls as pills and the cards on the theming radius, whose default is 0.75rem', () => {
        const css = readFileSync(BUILT_CSS, 'utf8');
        const sheet = declarationsOutside(css);

        expect(sheet('.kr-booking')).toContain('--kr-radius:.75rem');
        expect(sheet('.kr-booking__frame')).not.toMatch(/(^|;)border/);
        expect(sheet('.kr-booking__frame')).not.toMatch(/(^|;)padding/);
        // The frame paints its own canvas, so the flow reads the same on a dark host page.
        expect(sheet('.kr-booking__frame')).toContain('background:#fff');
        expect(sheet('.kr-booking__frame')).toContain('color:#111827');

        for (const pill of ['.kr-booking__button', '.kr-booking__chip']) {
            expect(sheet(pill), pill).toContain('border-radius:999px');
        }

        for (const card of ['.kr-booking__service', '.kr-booking__estimate', '.kr-booking__card']) {
            expect(sheet(card), card).toContain('border-radius:var(--kr-radius)');
        }
    });

    it('tints the error and the success notices as panels in their own colours', () => {
        const sheet = declarationsOutside(readFileSync(BUILT_CSS, 'utf8'));

        expect(sheet('.kr-booking__notice--error')).toContain('color-mix(in srgb,var(--kr-error) 8%,');
        expect(sheet('.kr-booking__notice--error')).toContain('color:var(--kr-error)');
        expect(sheet('.kr-booking__notice--success')).toContain('color-mix(in srgb,var(--kr-success) 8%,');
        expect(sheet('.kr-booking__notice--success')).toContain('color:var(--kr-success)');
    });

    it('draws the focus outline in the focus colour on every control it styles', () => {
        const sheet = declarationsOutside(readFileSync(BUILT_CSS, 'utf8'));

        for (const control of ['.kr-booking__button', '.kr-booking__chip', '.kr-booking__service', '.kr-booking__crumb', '.kr-booking__back', '.kr-booking__remove', '.kr-booking__input', '.kr-booking__frame']) {
            expect(sheet(`${control}:focus-visible`), control).toContain('outline:2px solid var(--kr-focus)');
        }
    });

    it('sets no font family, draws no picture through a URL and imports nothing: the host page\'s font, the markup\'s icons', () => {
        const css = stripComments(readFileSync(BUILT_CSS, 'utf8'));

        expect(css).not.toMatch(/font-family/);
        expect(css).not.toMatch(/url\(/);
        expect(css).not.toMatch(/@import|@font-face/);
    });

    it('declares the eight custom properties on the mount element as the theming surface', () => {
        expect(tokensDeclaredOnTheMount(readFileSync(BUILT_CSS, 'utf8'))).toEqual(TOKENS);
    });

    it('reads the theme off the mount\'s data-theme: neutral on the bare mount, a missing attribute included, and each other theme\'s light primary, text on it and ring keyed on the attribute', () => {
        const css = stripComments(readFileSync(BUILT_CSS, 'utf8'));
        const declarations = (selector: RegExp): string =>
            rulesOf(css)
                .filter((rule) => rule.selectors.some((candidate) => selector.test(candidate)))
                .map((rule) => rule.declarations)
                .join(';');

        expect(declarations(/^\.kr-booking$/)).toContain(`--kr-primary:${PRIMARIES.neutral}`);
        // No accent alias: the primary is read by its own name everywhere.
        expect(css).not.toContain('--kr-accent');
        expect(declarations(/^\.kr-booking$/)).toContain('--kr-focus:var(--kr-ring)');

        for (const [theme, primary] of Object.entries(PRIMARIES).filter(([theme]) => theme !== 'neutral')) {
            const block = declarations(new RegExp(`^\\.kr-booking\\[data-theme="?${theme}"?\\]$`));

            expect(block, theme).toContain(`--kr-primary:${primary}`);
            expect(block, theme).toContain(`--kr-ring:${primary}`);
            expect(block, theme).toMatch(/--kr-primary-foreground:#fff(fff)?(;|$)/);
        }
    });

    it('paints the text on the primary in the theme\'s own token', () => {
        expect(stripComments(readFileSync(BUILT_CSS, 'utf8'))).toMatch(/\.kr-booking__button--primary\{[^}]*background:var\(--kr-primary\);[^}]*color:var\(--kr-primary-foreground\)|\.kr-booking__button--primary\{[^}]*color:var\(--kr-primary-foreground\)[^}]*background:var\(--kr-primary\)/);
    });

    it('rides inside the one script a tenant loads', () => {
        const script = readFileSync(BUILT_JS, 'utf8');

        expect(script).toContain('.kr-booking__frame');
        expect(script).toContain('--kr-primary:#171717');
    });
});

describe('installStylesheet', () => {
    it('appends the stylesheet to head once, found by attribute rather than by module state', () => {
        document.head.innerHTML = '';

        installStylesheet(document, '.kr-booking{margin:0}');
        installStylesheet(document, '.kr-booking{margin:1px}');

        const styles = document.head.querySelectorAll(`style[${STYLESHEET_ATTRIBUTE}]`);

        expect(styles).toHaveLength(1);
        expect(styles[0]?.textContent).toBe('.kr-booking{margin:0}');
    });
});
