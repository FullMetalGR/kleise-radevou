import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { mount, type Mounted } from './harness';
import { TableScroll } from './table-scroll';

/*
 * Every data table's frame: a horizontal scroller around the table, so a table wider than the
 * screen scrolls inside its card and the page never does. The date picker's month grid is a
 * control laid out in a table, not a data table, and fits by construction.
 */

let view: Mounted;

beforeEach(() => {
    view = mount();
});

afterEach(() => view.unmount());

const ADMIN = join(process.cwd(), 'resources/js/admin');

function sourcesUnder(directory: string): string[] {
    return readdirSync(directory).flatMap((name) => {
        const path = join(directory, name);

        if (statSync(path).isDirectory()) {
            return sourcesUnder(path);
        }

        return name.endsWith('.tsx') && !name.endsWith('.test.tsx') ? [path] : [];
    });
}

const count = (text: string, pattern: RegExp) => (text.match(pattern) ?? []).length;

describe('the table scroller', () => {
    it('wraps what it is handed in a full-width box that scrolls sideways', () => {
        view.render(
            <TableScroll>
                <table className="wide" />
            </TableScroll>,
        );

        const box = view.host.querySelector('.admin-table-scroll');

        expect(box?.querySelector('table.wide')).not.toBeNull();
        expect([...(box?.classList ?? [])]).toEqual(expect.arrayContaining(['w-full', 'overflow-x-auto']));
    });

    it('sets every head cell of its table at weight 600', () => {
        view.render(
            <TableScroll>
                <table />
            </TableScroll>,
        );

        expect(view.host.querySelector('.admin-table-scroll')?.classList.contains('[&_th]:font-semibold')).toBe(true);
    });

    it('frames every data table in the admin, one scroller per table', () => {
        const unframed = sourcesUnder(ADMIN)
            .map((path) => ({ path, text: readFileSync(path, 'utf8') }))
            .filter(({ text }) => count(text, /<table\b/g) > 0 && count(text, /<table\b/g) !== count(text, /<TableScroll>/g))
            .map(({ path }) => relative(ADMIN, path));

        expect(unframed).toEqual([]);
    });
});
