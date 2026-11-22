import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';

/*
 * The admin stylesheet keeps no rule for a hook class nothing writes. Every admin- class one of
 * its selectors names is written where a page is drawn from: in a source of the admin or a view
 * that loads its stylesheet, whole or as a stem ending in -- or __ that the source joins to a
 * value, or in a browser test, whose selector reads the hook off the page. A class a comment
 * names and no code writes is not written. A rule no source reaches is removed.
 */

const ROOT = process.cwd();

/** Every file under a folder whose name passes, at any depth. */
function filesUnder(directory: string, keep: (name: string) => boolean): string[] {
    return readdirSync(directory).flatMap((name) => {
        const path = join(directory, name);

        if (statSync(path).isDirectory()) {
            return filesUnder(path, keep);
        }

        return keep(name) ? [path] : [];
    });
}

/**
 * What a page is drawn from, one text a file: the admin's sources, its unit tests and the
 * language table it generates left out, the admin's views, the console's view, which loads the
 * admin's stylesheet, and the browser tests.
 */
function writers(): string[] {
    return [
        ...filesUnder(join(ROOT, 'resources/js/admin'), (name) => /\.tsx?$/.test(name) && !/\.test\.tsx?$/.test(name) && name !== 'lines.generated.ts'),
        ...filesUnder(join(ROOT, 'resources/views/admin'), (name) => name.endsWith('.php')),
        join(ROOT, 'resources/views/console/app.blade.php'),
        ...filesUnder(join(ROOT, 'tests/Browser'), (name) => name.endsWith('.php')),
    ].map((path) => readFileSync(path, 'utf8'));
}

/** A file's code with its comments dropped: block comments, whole-line // comments and Blade's. */
const code = (text: string) =>
    text
        .replace(/\/\*[\s\S]*?\*\//g, '')
        .replace(/\{\{--[\s\S]*?--\}\}/g, '')
        .replace(/^[ \t]*\/\/.*$/gm, '');

/** Each admin- class a selector of the stylesheet names, once, comments dropped. */
function hookClasses(css: string): string[] {
    const selectors = [...css.replace(/\/\*[\s\S]*?\*\//g, '').matchAll(/([^{};]+)\{/g)].map(([, selector]) => selector ?? '').join('\n');

    return [...new Set(selectors.match(/\.admin-[\w-]+/g) ?? [])].map((selector) => selector.slice(1));
}

const literal = (text: string) => text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

/** Whether a text writes a class whole, or as a stem ending in -- or __ that it joins to a value. */
function writes(text: string, name: string): boolean {
    if (new RegExp(`(?<![\\w-])${literal(name)}(?![\\w-])`).test(text)) {
        return true;
    }

    return [...name.matchAll(/--|__/g)].some((stem) => new RegExp(`(?<![\\w-])${literal(name.slice(0, (stem.index ?? 0) + 2))}(?:\\$\\{|['"\`])`).test(text));
}

/** The hook classes of a stylesheet that none of the texts writes in its code. */
function unwritten(css: string, texts: readonly string[]): string[] {
    const written = texts.map(code).join('\n');

    return hookClasses(css).filter((name) => !writes(written, name));
}

describe('the admin stylesheet', () => {
    it('names no hook class that no source writes and no browser test reads', () => {
        const css = readFileSync(join(ROOT, 'resources/js/admin/app.css'), 'utf8');

        expect(hookClasses(css)).not.toEqual([]);
        expect(unwritten(css, writers())).toEqual([]);
    });

    it('counts a class written whole or joined to a value from its stem as written, and one only a longer class begins with, or only a source\'s comment names, as not, and reads no hook out of a comment of the stylesheet', () => {
        // The planted classes are put together from parts, so they stand in no source as a whole.
        const planted = 'admin-plant' + 'ed';
        const css = [
            `.${planted} { color: red; }`,
            `.${planted}__part, .${planted}--wide:hover { color: red; }`,
            `/* .${planted}--noted { color: red; } */`,
            `.${planted}-gone > .${planted}--told { color: red; }`,
            `.${planted}-blocked, .${planted}-lined, .${planted}-bladed, .${planted}-linked { color: red; }`,
        ].join('\n');
        const text = [
            `<p className="${planted}"><b className="${planted}__part" /><i className={\`${planted}--\${size}\`} /></p><s className="${planted}-gone-too" />`,
            `{/* <p className="${planted}-blocked" /> */}`,
            `    // <p className="${planted}-lined" />`,
            `{{-- <p class="${planted}-bladed"> --}}`,
            `<a href="https://example.test" className="${planted}-linked" />`,
        ].join('\n');

        expect(hookClasses(css)).toEqual([planted, `${planted}__part`, `${planted}--wide`, `${planted}-gone`, `${planted}--told`, `${planted}-blocked`, `${planted}-lined`, `${planted}-bladed`, `${planted}-linked`]);
        expect(unwritten(css, [text])).toEqual([`${planted}-gone`, `${planted}-blocked`, `${planted}-lined`, `${planted}-bladed`]);
        expect(unwritten(css, [text, `'.${planted}-gone'`])).toEqual([`${planted}-blocked`, `${planted}-lined`, `${planted}-bladed`]);
    });
});
