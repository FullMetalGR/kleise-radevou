import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';
import { act } from 'react';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { desktopWidth, mount, type Mounted, onCompact, onPhone, wideWidth } from '@/components/ui/harness';
import { BREAKPOINT, useDesktop, useWide, WIDE } from './viewport';

/*
 * The admin's two breakpoints: tokens the stylesheet declares once, read off the root by name,
 * never a number spelled in a component. At or above the desktop one the admin is the desktop
 * layout it always was; below it, the phone layout. At or above the wide one the sidebar stands
 * beside the page; below it, the shell is compact. Where a token cannot be read or the
 * environment answers no media query, the answer is yes, so nothing that cannot measure the
 * viewport is ever handed the smaller layout by accident.
 */

let view: Mounted;

beforeEach(() => {
    view = mount();
});

afterEach(() => view.unmount());

function Probe() {
    return <p className="probe">{useDesktop() ? 'desktop' : 'phone'}</p>;
}

function WideProbe() {
    return <p className="wide-probe">{useWide() ? 'wide' : 'compact'}</p>;
}

const answer = () => view.host.querySelector('.probe')?.textContent;

const wideAnswer = () => view.host.querySelector('.wide-probe')?.textContent;

type Listener = () => void;

/** A media query that answers what it is told and tells its listeners when that changes. */
function stubQueries(matches: boolean): { queries: string[]; set: (next: boolean) => void; undo: () => void } {
    const queries: string[] = [];
    const listeners = new Set<Listener>();
    let current = matches;
    const original = window.matchMedia;

    window.matchMedia = (query: string) => {
        queries.push(query);

        return {
            get matches() {
                return current;
            },
            media: query,
            addEventListener: (_type: string, listener: Listener) => listeners.add(listener),
            removeEventListener: (_type: string, listener: Listener) => listeners.delete(listener),
        } as unknown as MediaQueryList;
    };
    document.documentElement.style.setProperty(BREAKPOINT, desktopWidth());
    document.documentElement.style.setProperty(WIDE, wideWidth());

    return {
        queries,
        set: (next) => {
            current = next;
            act(() => listeners.forEach((listener) => listener()));
        },
        undo: () => {
            window.matchMedia = original;
            document.documentElement.style.removeProperty(BREAKPOINT);
            document.documentElement.style.removeProperty(WIDE);
        },
    };
}

describe('the breakpoint', () => {
    it('answers desktop where the environment answers no media query', () => {
        view.render(<Probe />);

        expect(answer()).toBe('desktop');
    });

    it('answers desktop where the stylesheet has not declared the token', () => {
        const stub = stubQueries(false);
        document.documentElement.style.removeProperty(BREAKPOINT);

        view.render(<Probe />);

        expect(answer()).toBe('desktop');
        stub.undo();
    });

    it('asks for the width at or above the token\'s own value, read off the root by name', () => {
        const stub = stubQueries(true);

        view.render(<Probe />);

        expect(answer()).toBe('desktop');
        expect(stub.queries).toContain(`(min-width: ${desktopWidth()})`);
        stub.undo();
    });

    it('answers phone below it, and follows the viewport across the line both ways', () => {
        const stub = stubQueries(false);

        view.render(<Probe />);
        expect(answer()).toBe('phone');

        stub.set(true);
        expect(answer()).toBe('desktop');

        stub.set(false);
        expect(answer()).toBe('phone');
        stub.undo();
    });

    it('is what the harness puts the admin on for a phone test, and undone by it', () => {
        const undo = onPhone();

        view.render(<Probe />);
        expect(answer()).toBe('phone');

        undo();
        view.render(<Probe key="again" />);
        expect(answer()).toBe('desktop');
    });
});

describe('the wide breakpoint', () => {
    it('answers wide where the environment answers no media query, or the token is not on the root', () => {
        view.render(<WideProbe />);
        expect(wideAnswer()).toBe('wide');

        const stub = stubQueries(false);
        document.documentElement.style.removeProperty(WIDE);
        view.render(<WideProbe key="again" />);

        expect(wideAnswer()).toBe('wide');
        stub.undo();
    });

    it('asks for the width at or above its own token, read off the root by name, and follows the viewport across the line', () => {
        const stub = stubQueries(true);

        view.render(<WideProbe />);

        expect(wideAnswer()).toBe('wide');
        expect(stub.queries).toContain(`(min-width: ${wideWidth()})`);

        stub.set(false);
        expect(wideAnswer()).toBe('compact');
        stub.undo();
    });

    it('answers no for both on a phone, and desktop but not wide on the compact screen the harness stands in for', () => {
        const phone = onPhone();
        view.render(
            <>
                <Probe />
                <WideProbe />
            </>,
        );
        expect([answer(), wideAnswer()]).toEqual(['phone', 'compact']);
        phone();

        const compact = onCompact();
        view.render(
            <div key="compact">
                <Probe />
                <WideProbe />
            </div>,
        );
        expect([answer(), wideAnswer()]).toEqual(['desktop', 'compact']);
        compact();
    });
});

const ADMIN = join(process.cwd(), 'resources/js/admin');

/*
 * A width asked of the viewport: an arbitrary breakpoint variant, or a media query written by
 * hand. A container variant, the same word behind an "@", asks how wide its own box is, which
 * says nothing of phone or desktop, so it is no breakpoint and is not counted.
 */
const VIEWPORT_QUESTION = /(?<!@)\b(?:max|min)-\[|matchMedia|@media/;

function sourcesUnder(directory: string): string[] {
    return readdirSync(directory).flatMap((name) => {
        const path = join(directory, name);

        if (statSync(path).isDirectory()) {
            return sourcesUnder(path);
        }

        return /\.tsx?$/.test(name) && !/\.test\.tsx?$/.test(name) ? [path] : [];
    });
}

/*
 * The stylesheet's two breakpoints: the phone switch useDesktop reads, and the wide switch useWide
 * reads, below which the shell and the page take their compact layout.
 */
const BREAKPOINTS = [BREAKPOINT, WIDE];

describe('the token', () => {
    it.each(BREAKPOINTS)('each breakpoint is declared once, in the stylesheet\'s static theme block, so the root carries it: %s', (token) => {
        const css = readFileSync(join(ADMIN, 'app.css'), 'utf8');
        const declared = css.match(new RegExp(`${token}\\s*:`, 'g')) ?? [];

        expect(declared).toHaveLength(1);
        expect(css).toMatch(new RegExp(`@theme static \\{[^}]*${token}\\s*:`));
    });

    it('is the only width any admin source asks a media query about: no arbitrary breakpoint variant, no media query of its own', () => {
        // The hooks ask the questions; the harness stands in for the answers in the tests.
        const exempt = [join('lib', 'viewport.ts'), join('components', 'ui', 'harness.tsx')];
        const offenders = sourcesUnder(ADMIN)
            .filter((path) => !exempt.some((name) => path.endsWith(name)))
            .filter((path) => VIEWPORT_QUESTION.test(readFileSync(path, 'utf8')))
            .map((path) => relative(ADMIN, path));

        expect(offenders).toEqual([]);
    });

    it('lets the hooks ask about the stylesheet\'s two breakpoints and nothing else: every token the hooks name is one of them', () => {
        const source = readFileSync(join(ADMIN, 'lib', 'viewport.ts'), 'utf8');
        const named = [...new Set(source.match(/--[a-z][a-z-]*/g) ?? [])];

        expect(named.sort()).toEqual([...BREAKPOINTS].sort());
        expect(source.match(/matchMedia\(/g)).toHaveLength(1);
        expect(source).toContain('`(min-width: ${width})`');
    });

    it('counts a breakpoint variant and a media query, and leaves a container variant alone', () => {
        // Each class is put together from parts, so the stylesheet never ships a rule for it.
        expect(VIEWPORT_QUESTION.test('max-[90' + '0px]:hidden')).toBe(true);
        expect(VIEWPORT_QUESTION.test('s' + 'm:min-[90' + '0px]:flex')).toBe(true);
        expect(VIEWPORT_QUESTION.test("window.matchMedia('(min-width: 900px)')")).toBe(true);
        expect(VIEWPORT_QUESTION.test('@media (min-width: 900px)')).toBe(true);
        expect(VIEWPORT_QUESTION.test('@max-[22' + '0px]:sr-only')).toBe(false);
        expect(VIEWPORT_QUESTION.test('@min-[22' + '0px]:inline')).toBe(false);
    });
});

/*
 * A class asked of one of the stock breakpoints, sm to 2xl, or of its max- or min- form, at the
 * start of a class or after another variant, whatever follows its colon but a space or a quote.
 * The admin answers to its own two breakpoints and to the width of a box; a stock one would be a
 * third line nothing else in the layout knows. A container variant, the same word behind an "@",
 * asks how wide its own box is and is not one; neither is an object's key, which a space follows.
 */
const STOCK_BREAKPOINT = /(?<![\w@-])(?:(?:max|min)-)?(?:sm|md|lg|xl|2xl):[^\s'"`]+/g;

const stockBreakpoints = (text: string) => [...text.matchAll(STOCK_BREAKPOINT)].map(([name]) => name);

/*
 * The same question asked in the stylesheet: a block under a stock breakpoint's variant, or a
 * stock-prefixed class in an @apply list.
 */
function stockInStylesheet(css: string): string[] {
    const variants = [...css.matchAll(/@variant\s+(?:(?:max|min)-)?(?:sm|md|lg|xl|2xl)(?![\w-])/g)].map(([rule]) => rule);
    const applied = [...css.matchAll(/@apply\s+([^;}]+)/g)].flatMap(([, list]) => stockBreakpoints(list ?? ''));

    return [...variants, ...applied];
}

describe('the stock breakpoints', () => {
    it('are asked by no admin source: the layout answers to its own two breakpoints and to the boxes it sits in', () => {
        const offenders = sourcesUnder(ADMIN).flatMap((path) => stockBreakpoints(readFileSync(path, 'utf8')).map((name) => `${relative(ADMIN, path)}: ${name}`));

        expect(offenders).toEqual([]);
    });

    it('are asked by no rule of the admin\'s stylesheet: no block under a stock variant, no stock class in an @apply list', () => {
        expect(stockInStylesheet(readFileSync(join(ADMIN, 'app.css'), 'utf8'))).toEqual([]);
    });

    it('counts a stock variant block and a stock class applied in the stylesheet, in their max- and min- forms, and leaves the admin\'s own variants and plain applied classes alone', () => {
        // Each name is put together from parts, so the stylesheet never ships a rule for it.
        const variants = ['@variant ' + 'm' + 'd', '@variant ' + 'max-s' + 'm', '@variant ' + 'min-2x' + 'l'];
        const applied = ['l' + 'g:flex', 'hover:x' + 'l:p-2'];
        const css = [
            ...variants.map((rule) => `.a { ${rule} { display: none; } }`),
            `.b { @apply inline-flex ${applied[0]} gap-1; }`,
            `.c { @apply ${applied[1]}; }`,
            '.d { @variant max-desktop { display: none; } @variant desktop { display: flex; } }',
            '.e { @apply max-w-full text-xs; }',
        ].join('\n');

        expect(stockInStylesheet(css)).toEqual([...variants, applied[0], 'x' + 'l:p-2']);
    });

    it('counts a stock breakpoint at the start of a class and after another variant, in its max- and min- forms, and leaves the admin\'s own, a container variant and an object\'s key alone', () => {
        // Each class is put together from parts, so the stylesheet never ships a rule for it.
        const planted = ['s' + 'm:flex', 'm' + 'd:hidden', 'max-l' + 'g:block', 'min-x' + 'l:grid', '2x' + 'l:p-4', 'm' + 'd:*:flex-auto', 'm' + 'd:**:p-2', 'l' + 'g:@container'];
        const kept = ['@x' + 'l:flex', '@max-m' + 'd:block', 'max-wide:block', 'desktop:px-6'];

        expect(stockBreakpoints(`<p className="${planted.join(' ')}" />`)).toEqual(planted);
        expect(stockBreakpoints(`<p className="hover:${planted[1]}" />`)).toEqual([planted[1]]);
        expect(stockBreakpoints(`<p className="${kept.join(' ')}" />`)).toEqual([]);
        expect(stockBreakpoints('const widths = { sm: 640, md: 768 };')).toEqual([]);
    });
});

/*
 * The sources whose fields sit in a form section's grid. The section is a size container, and
 * its grid splits into two columns by the card's own width. A field there spans or splits by
 * the card too: a span asked of the window would open a second column inside a card too narrow
 * for one, and a split asked of the window would ignore the card it sits in.
 */
const SECTION_GRIDS = [
    join('pages', 'Settings.tsx'),
    join('services', 'ServiceForm.tsx'),
    join('staff', 'StaffForm.tsx'),
    join('staff', 'StaffView.tsx'),
    ...['GalleryPhotos', 'ImageSlot', 'LinksCard', 'MapLine', 'OpeningHours', 'PaymentsSection', 'ProfileFields', 'ThemePicker', 'WeeklyEditor'].map((name) => join('settings', `${name}.tsx`)),
];

describe('the form grids', () => {
    it('a form inside a section asks its card, not the window: no source of a section\'s fields spans or splits by a window breakpoint', () => {
        const offenders = SECTION_GRIDS.filter((path) => /\bsm:(?:col-span|grid-cols)-/.test(readFileSync(join(ADMIN, path), 'utf8')));

        expect(offenders).toEqual([]);
    });
});
