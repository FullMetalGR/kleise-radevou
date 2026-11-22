import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { mount, type Mounted } from './harness';
import { TableScroll } from './table-scroll';

/*
 * Every data table's frame: a horizontal scroller around the table, so a table wider than the
 * screen scrolls inside its card and the page never does. The date picker's month grid is a
 * control laid out in a table, not a data table, and fits by construction. A table whose screen
 * writes its own narrow layout goes without the frame, so its head row can stay in view while the
 * page scrolls; the kit's one table element is still counted against the one frame it writes. The
 * frame is the table part's alone: no screen reaches for it to wrap a grid of its own.
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

        return /\.tsx?$/.test(name) && !/\.test\.tsx?$/.test(name) ? [path] : [];
    });
}

const count = (text: string, pattern: RegExp) => (text.match(pattern) ?? []).length;

/*
 * A source that reaches for the frame: it imports the frame's module, names the frame, or writes
 * the frame's hook class by hand.
 */
const REACHES_THE_FRAME = /\bTableScroll\b|\/table-scroll['"]|\badmin-table-scroll\b/;

describe('the table scroller', () => {
    it('wraps what it is handed in a full-width box that scrolls sideways and is positioned, so it holds what a cell places out of the flow', () => {
        view.render(
            <TableScroll>
                <table className="wide" />
            </TableScroll>,
        );

        const box = view.host.querySelector('.admin-table-scroll');

        expect(box?.querySelector('table.wide')).not.toBeNull();
        expect([...(box?.classList ?? [])]).toEqual(expect.arrayContaining(['relative', 'w-full', 'overflow-x-auto']));
    });

    it('is a query container, so the table inside can read how wide its own box is', () => {
        view.render(
            <TableScroll>
                <table />
            </TableScroll>,
        );

        expect(view.host.querySelector('.admin-table-scroll')?.classList.contains('@container')).toBe(true);
    });

    it('sets no type of its own, so each table spells the weight of its head cells', () => {
        view.render(
            <TableScroll>
                <table />
            </TableScroll>,
        );

        const box = [...(view.host.querySelector('.admin-table-scroll')?.classList ?? [])];

        expect(box.filter((name) => name.includes('font-'))).toEqual([]);
    });

    it('frames every data table in the admin, one scroller per table', () => {
        const unframed = sourcesUnder(ADMIN)
            .map((path) => ({ path, text: readFileSync(path, 'utf8') }))
            .filter(({ text }) => count(text, /<table\b/g) > 0 && count(text, /<table\b/g) !== count(text, /<TableScroll>/g))
            .map(({ path }) => relative(ADMIN, path));

        expect(unframed).toEqual([]);
    });

    it('is reached by the table part alone, so no screen wraps a grid in a sideways box of its own', () => {
        const frame = join('components', 'ui', 'table-scroll.tsx');
        const reaching = sourcesUnder(ADMIN)
            .filter((path) => !path.endsWith(frame) && REACHES_THE_FRAME.test(readFileSync(path, 'utf8')))
            .map((path) => relative(ADMIN, path));

        expect(reaching).toEqual([join('components', 'ui', 'table.tsx')]);
    });

    it('counts an import of the frame, its name and its hook class as a reach, and leaves the table part\'s own import alone', () => {
        expect(REACHES_THE_FRAME.test("import { TableScroll } from '@/components/ui/table-scroll';")).toBe(true);
        expect(REACHES_THE_FRAME.test("import * as frame from './table-scroll';")).toBe(true);
        expect(REACHES_THE_FRAME.test('<div className="admin-table-scroll overflow-x-auto">')).toBe(true);
        expect(REACHES_THE_FRAME.test("import { Table, Td } from '@/components/ui/table';")).toBe(false);
        expect(REACHES_THE_FRAME.test('<div className="admin-table">')).toBe(false);
    });
});
