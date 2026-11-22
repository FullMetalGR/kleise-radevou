import type { ComponentProps } from 'react';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { mount, type Mounted } from './harness';
import { Table, TableBody, TableHead, TableRow, Td, Th } from './table';

/*
 * The admin's data table. It sits in the sideways box every table shares, which is also a query
 * container, and takes the soft head row, roomier cells and a soft fill on the row under the
 * pointer. A rows table turns each row into a block below the wide breakpoint and whenever its box
 * is narrower than 672px: the head leaves the screen but not the reading order, and each cell
 * shows its column name on the left and its value on the right, a long value breaking inside its
 * line. A table of a name and a figure or two turns to rows below the wide breakpoint alone, never
 * by its box. A table whose grid needs a wider box turns to rows below the wide breakpoint and
 * whenever its box is narrower than 1024px. A table whose screen writes its own row layout sits in
 * no box at all, so its head can stay at the top of the window as the page scrolls. Those are the
 * four modes; none keeps its grid at every width by scrolling it. The grid breaks no word, whatever
 * the table, so a grid that still does not fit scrolls sideways in its box as a last resort, and
 * its first and last cells keep the card's own 26px inset.
 */

let view: Mounted;

beforeEach(() => {
    view = mount();
});

afterEach(() => view.unmount());

type Compact = ComponentProps<typeof Table>['compact'];

function sample(compact?: Compact): void {
    view.render(
        <Table compact={compact} className="probe">
            <TableHead>
                <TableRow>
                    <Th>Name</Th>
                    <Th className="text-right">Email</Th>
                </TableRow>
            </TableHead>
            <TableBody>
                <TableRow>
                    <Td label="Name" header>
                        row-name-token
                    </Td>
                    <Td label="Email" className="text-right">
                        row-email-token
                    </Td>
                </TableRow>
                <TableRow>
                    <Td label="Name" header>
                        second-name-token
                    </Td>
                    <Td label="Email" className="text-right">
                        second-email-token
                    </Td>
                </TableRow>
            </TableBody>
        </Table>,
    );
}

const one = (selector: string) => view.host.querySelector(selector);
const all = (selector: string) => [...view.host.querySelectorAll(selector)];
const classes = (element: Element | null | undefined) => [...(element?.classList ?? [])];
const BOTH = (utility: string) => [`max-wide:${utility}`, `@max-2xl:${utility}`];

/** Every class the table writes, on itself and on what it holds. */
const written = () => all('*').flatMap((element) => classes(element));

/** The parts the rows layout changes, and the utilities each writes under one condition. */
const PARTS = ['table', 'thead', 'tbody', 'tbody tr', 'tbody th', 'tbody td', '.admin-table__label'];

const under = (condition: string) =>
    PARTS.map((part) =>
        classes(one(part))
            .filter((name) => name.startsWith(condition))
            .map((name) => name.slice(condition.length))
            .sort(),
    );

describe('the data table', () => {
    it('a rows table writes each row utility under both conditions', () => {
        sample('rows');

        for (const part of [one('table'), one('tbody'), ...all('tbody tr')]) {
            expect(classes(part)).toEqual(expect.arrayContaining(BOTH('block')));
        }

        expect(classes(one('thead'))).toEqual(expect.arrayContaining(BOTH('sr-only')));

        for (const cell of all('tbody td, tbody th')) {
            expect(classes(cell)).toEqual(expect.arrayContaining(BOTH('flex')));
        }
    });

    it('is a rows table unless told otherwise', () => {
        sample();

        expect(classes(one('table'))).toEqual(expect.arrayContaining(BOTH('block')));
    });

    it('offers four modes, rows, rows below the wide breakpoint, rows in a box narrower than 1024px and the screen\'s own, and none that keeps its grid at every width by scrolling it sideways', () => {
        // @ts-expect-error no mode keeps its grid at every width by scrolling it sideways in its box
        const sideways = <Table compact="scroll">{null}</Table>;

        expect(sideways.props.compact).toBe('scroll');
    });

    it('a table that turns to rows below the wide breakpoint alone writes every utility of that condition and none of its box', () => {
        sample('rows');

        const belowWide = under('max-wide:');

        expect(belowWide.every((utilities) => utilities.length > 0)).toBe(true);
        expect(under('@max-2xl:')).toEqual(belowWide);

        sample('rows-below-wide');

        expect(under('max-wide:')).toEqual(belowWide);
        expect(written().filter((name) => name.startsWith('@max-'))).toEqual([]);
        expect(one('tbody td .admin-table__label')?.textContent).toBe('Email');
        expect(classes(one('tbody td .admin-table__label'))).toContain('hidden');
        expect(one('tbody td .admin-table__value')?.textContent).toBe('row-email-token');
    });

    it('a table whose grid needs a wider box writes every utility of the rows layout below the wide breakpoint and in a box narrower than 1024px, and none of the narrower box', () => {
        sample('rows');

        const belowWide = under('max-wide:');

        sample('rows-below-wider-box');

        expect(under('max-wide:')).toEqual(belowWide);
        expect(under('@max-5xl:')).toEqual(belowWide);
        expect(written().filter((name) => name.startsWith('@max-') && !name.startsWith('@max-5xl:'))).toEqual([]);
        expect(one('tbody td .admin-table__label')?.textContent).toBe('Email');
        expect(one('tbody td .admin-table__value')?.textContent).toBe('row-email-token');

        for (const other of ['rows', 'rows-below-wide', 'own'] as const) {
            sample(other);

            expect(written().filter((name) => name.startsWith('@max-5xl:'))).toEqual([]);
        }
    });

    it('the parts name their roles', () => {
        sample('rows');

        expect(one('table')?.getAttribute('role')).toBe('table');
        expect(one('thead')?.getAttribute('role')).toBe('rowgroup');
        expect(one('tbody')?.getAttribute('role')).toBe('rowgroup');
        expect(all('tr').map((row) => row.getAttribute('role'))).toEqual(['row', 'row', 'row']);
        expect(all('thead th').map((cell) => [cell.getAttribute('role'), cell.getAttribute('scope')])).toEqual([
            ['columnheader', 'col'],
            ['columnheader', 'col'],
        ]);

        const [name, email] = all('tbody tr:first-child > *');

        expect([name?.tagName, name?.getAttribute('role'), name?.getAttribute('scope')]).toEqual(['TH', 'rowheader', 'row']);
        expect([email?.tagName, email?.getAttribute('role')]).toEqual(['TD', 'cell']);
    });

    it('a cell carries its label as text', () => {
        sample('rows');

        const label = one('tbody td .admin-table__label');

        expect(label?.tagName).toBe('SPAN');
        expect(label?.textContent).toBe('Email');
        expect(label?.getAttribute('aria-hidden')).toBe('true');
        expect(classes(label)).toEqual(expect.arrayContaining(['hidden', ...BOTH('block')]));
        expect(one('tbody td .admin-table__value')?.textContent).toBe('row-email-token');
    });

    it('leaves a cell with no label without one, and in the rows layout puts its value at the end of its line, where an actions cell sits in the grid', () => {
        const actions = (compact?: Compact) =>
            view.render(
                <Table compact={compact}>
                    <TableBody>
                        <TableRow>
                            <Td label="Name">name-token</Td>
                            <Td className="text-right">actions-token</Td>
                        </TableRow>
                    </TableBody>
                </Table>,
            );
        const ends = (selector: string) => classes(one(`${selector} .admin-table__value`)).filter((name) => name.endsWith('ml-auto'));

        actions();

        expect(one('td:last-child .admin-table__label')).toBeNull();
        expect(one('td:last-child')?.textContent).toBe('actions-token');
        expect(ends('td:last-child')).toEqual(BOTH('ml-auto'));
        expect(ends('td:first-child')).toEqual([]);

        actions('rows-below-wide');

        expect(ends('td:last-child')).toEqual(['max-wide:ml-auto']);

        actions('rows-below-wider-box');

        expect(ends('td:last-child')).toEqual(['max-wide:ml-auto', '@max-5xl:ml-auto']);

        actions('own');

        expect(one('td:last-child')?.textContent).toBe('actions-token');
        expect(written().filter((name) => name.endsWith('ml-auto'))).toEqual([]);
    });

    it('the table sits in the sideways box', () => {
        sample('rows');

        const table = one('table');
        const box = table?.parentElement;

        expect(box?.tagName).toBe('DIV');
        expect(classes(box)).toEqual(expect.arrayContaining(['admin-table-scroll', '@container', 'overflow-x-auto']));
        expect(classes(table)).toEqual(expect.arrayContaining(['admin-table', 'probe']));
        expect(all('[class*="sticky"]')).toEqual([]);
    });

    it('a table whose screen writes its own rows sits in no sideways box, holds its head at the top of the window, and writes none of the rows layout', () => {
        sample('own');

        const table = one('table');

        expect(one('.admin-table-scroll')).toBeNull();
        expect(table?.parentElement).toBe(view.host);
        // The table is the containing block of what a cell positions absolutely, as the box is for the other modes.
        expect(classes(table)).toEqual(expect.arrayContaining(['admin-table', 'probe', 'relative']));
        expect(classes(one('thead'))).toEqual(expect.arrayContaining(['sticky', 'top-0']));
        expect(all('[class*="sticky"]')).toEqual([one('thead')]);
        expect(written().filter((name) => name.startsWith('max-wide:') || name.startsWith('@max-') || name.includes('anywhere'))).toEqual([]);
        expect(one('.admin-table__label')).toBeNull();
        expect(one('.admin-table__value')).toBeNull();
        expect(one('tbody td')?.textContent).toBe('row-email-token');
        expect(classes(one('tbody tr'))).toContain('hover:bg-muted/70');

        for (const other of ['rows', 'rows-below-wide', 'rows-below-wider-box'] as const) {
            sample(other);

            expect(all('[class*="sticky"]')).toEqual([]);
            expect(one('.admin-table-scroll')).not.toBeNull();
        }
    });

    it('a long value breaks inside its line in the rows layout alone, so the grid keeps whole words', () => {
        const breaks = () => written().filter((name) => name.includes('anywhere'));

        sample('rows');

        for (const cell of all('tbody td, tbody th')) {
            expect(classes(cell)).toEqual(expect.arrayContaining(BOTH('wrap-anywhere')));
        }

        expect(new Set(breaks())).toEqual(new Set(BOTH('wrap-anywhere')));

        sample('rows-below-wide');

        expect(new Set(breaks())).toEqual(new Set(['max-wide:wrap-anywhere']));

        sample('own');

        expect(breaks()).toEqual([]);
    });

    it('draws the soft head row, roomier cells and figures that line up, the first and last cells at the card\'s 26px inset', () => {
        sample('rows');

        expect(classes(one('table'))).toEqual(expect.arrayContaining(['text-body-sm', 'tabular-nums']));
        expect(classes(one('thead th'))).toEqual(
            expect.arrayContaining(['bg-muted', 'text-small', 'font-bold', 'text-muted-foreground', 'whitespace-nowrap', 'first:pl-6.5', 'last:pr-6.5']),
        );
        expect(classes(one('tbody td'))).toEqual(expect.arrayContaining(['border-t', 'px-4', 'py-2.5', 'font-medium', 'first:pl-6.5', 'last:pr-6.5']));
        expect(written().filter((name) => /^(?:first:pl|last:pr)-/.test(name) && !name.endsWith('-6.5'))).toEqual([]);
        expect(classes(one('tbody th'))).toEqual(expect.arrayContaining(['font-medium', 'text-left']));
    });

    it('fills a body row under the pointer, and never the head row', () => {
        sample('rows');

        expect(classes(one('tbody tr'))).toContain('hover:bg-muted/70');
        expect(classes(one('thead tr'))).not.toContain('hover:bg-muted/70');
    });

    it('lets a class its caller passes win over its own', () => {
        sample('rows');

        expect(classes(one('thead th:last-child'))).toContain('text-right');
        expect(classes(one('thead th:last-child'))).not.toContain('text-left');
    });
});
