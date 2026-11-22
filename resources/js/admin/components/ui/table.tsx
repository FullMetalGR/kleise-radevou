import { createContext, useContext, type ComponentProps, type ReactNode } from 'react';
import { cn } from '@/lib/utils';
import { TableScroll } from './table-scroll';

/*
 * The admin's data table, in its sideways box. A soft head row over roomy cells, figures in
 * tabular digits, and a soft fill on the body row under the pointer. The grid breaks no word:
 * where it does not fit its box, the box scrolls sideways. A table has one of four modes, and its
 * first and last cells keep the card's own inset, so a column lines up with the card's title.
 *
 * A rows table has a second layout for where a grid has no room: below the wide breakpoint, and
 * whenever its own box is narrower than 672px, so a table in a narrow card beside the sidebar
 * reads too. There each row is a block and each cell a line with its column name on the left and
 * its value on the right, a long value breaking inside its line; a cell with no label, a row's
 * actions, puts its value at the end of its line, where it stands in the grid. The head row leaves
 * the screen while staying in the reading order. Each part names its role, so a screen reader still
 * reads a table whatever the parts display as. Every condition is written out in full, once, here;
 * outside this part only a card's header that keeps its table's inset reads them, the same ones as
 * its table.
 *
 * A rows-below-wide table takes that layout below the wide breakpoint alone and never by its
 * box. It is for a table of a name and a figure or two, which fits whatever card a wide window
 * gives it: in a card half as wide as the page it stays a grid.
 *
 * A rows-below-wider-box table takes that layout below the wide breakpoint and whenever its box
 * is narrower than 1024px. It is for a table whose grid is wide, a row of controls among its
 * columns, which the card beside the sidebar cannot hold in a narrower window.
 *
 * An own table is for a screen that writes its own narrow layout. It sits in no box at all, so
 * nothing between it and the window scrolls and its head row can stay at the top of the window
 * while the page scrolls through a long list; the table itself is positioned in the box's place.
 * It writes none of the rows layout: its screen spells every condition on the parts it passes.
 */

type Compact = 'rows' | 'rows-below-wide' | 'rows-below-wider-box' | 'own';

const CompactContext = createContext<Compact>('rows');

/** Whether a row sits in the head or the body: only a body row takes the fill under the pointer. */
const SectionContext = createContext<'head' | 'body'>('body');

const TABLE = 'admin-table w-full text-body-sm tabular-nums';

const HEAD_CELL =
    'bg-muted px-4 py-[11px] text-left align-middle text-small font-bold tracking-[0.01em] whitespace-nowrap text-muted-foreground first:pl-6.5 last:pr-6.5';

const BODY_ROW = 'hover:bg-muted/70 motion-safe:transition-colors motion-safe:duration-150';

const CELL = 'border-t px-4 py-2.5 text-left align-middle font-medium first:pl-6.5 last:pr-6.5';

const LABEL = 'admin-table__label hidden shrink-0 text-left text-label text-muted-foreground';

/**
 * What the rows layout changes, part by part: every utility once per condition, the window below
 * the wide breakpoint first, the box narrower than 672px second and the box narrower than 1024px
 * third. A value breaks inside a word here alone: in the grid the same rule would let a column
 * shrink below its longest word. A value with no label beside it takes the end of its line.
 */
const ROWS = {
    table: ['max-wide:block', '@max-2xl:block', '@max-5xl:block'],
    head: ['max-wide:sr-only', '@max-2xl:sr-only', '@max-5xl:sr-only'],
    body: ['max-wide:block', '@max-2xl:block', '@max-5xl:block'],
    row: [
        'max-wide:block max-wide:border-t max-wide:px-[18px] max-wide:py-3 max-wide:first:border-t-0',
        '@max-2xl:block @max-2xl:border-t @max-2xl:px-[18px] @max-2xl:py-3 @max-2xl:first:border-t-0',
        '@max-5xl:block @max-5xl:border-t @max-5xl:px-[18px] @max-5xl:py-3 @max-5xl:first:border-t-0',
    ],
    cell: [
        'max-wide:flex max-wide:items-center max-wide:justify-between max-wide:gap-3.5 max-wide:border-t-0 max-wide:px-0 max-wide:py-1 max-wide:text-right max-wide:wrap-anywhere max-wide:first:pl-0 max-wide:last:pr-0',
        '@max-2xl:flex @max-2xl:items-center @max-2xl:justify-between @max-2xl:gap-3.5 @max-2xl:border-t-0 @max-2xl:px-0 @max-2xl:py-1 @max-2xl:text-right @max-2xl:wrap-anywhere @max-2xl:first:pl-0 @max-2xl:last:pr-0',
        '@max-5xl:flex @max-5xl:items-center @max-5xl:justify-between @max-5xl:gap-3.5 @max-5xl:border-t-0 @max-5xl:px-0 @max-5xl:py-1 @max-5xl:text-right @max-5xl:wrap-anywhere @max-5xl:first:pl-0 @max-5xl:last:pr-0',
    ],
    label: ['max-wide:block', '@max-2xl:block', '@max-5xl:block'],
    end: ['max-wide:ml-auto', '@max-2xl:ml-auto', '@max-5xl:ml-auto'],
} as const;

type Part = keyof typeof ROWS;

/**
 * What a part takes of the rows layout in a table's mode: the window's condition with the narrow
 * box's, the window's with the wider box's, the window's alone, or nothing.
 */
function rowsOf(compact: Compact, part: Part): string | false {
    const [belowWide, narrowBox, widerBox] = ROWS[part];

    if (compact === 'own') {
        return false;
    }

    if (compact === 'rows-below-wider-box') {
        return `${belowWide} ${widerBox}`;
    }

    return compact === 'rows' ? `${belowWide} ${narrowBox}` : belowWide;
}

const useRows = (part: Part) => rowsOf(useContext(CompactContext), part);

export function Table({ compact = 'rows', className, children }: { compact?: Compact; className?: string; children: ReactNode }) {
    const own = compact === 'own';
    const table = (
        <table role="table" className={cn(TABLE, own ? 'relative' : rowsOf(compact, 'table'), className)}>
            {children}
        </table>
    );

    return <CompactContext.Provider value={compact}>{own ? table : <TableScroll>{table}</TableScroll>}</CompactContext.Provider>;
}

export function TableHead({ className, ...rest }: ComponentProps<'thead'>) {
    const held = useContext(CompactContext) === 'own' && 'sticky top-0 z-10';

    return (
        <SectionContext.Provider value="head">
            <thead role="rowgroup" className={cn(useRows('head'), held, className)} {...rest} />
        </SectionContext.Provider>
    );
}

export function TableBody({ className, ...rest }: ComponentProps<'tbody'>) {
    return (
        <SectionContext.Provider value="body">
            <tbody role="rowgroup" className={cn(useRows('body'), className)} {...rest} />
        </SectionContext.Provider>
    );
}

export function TableRow({ className, ...rest }: ComponentProps<'tr'>) {
    const body = useContext(SectionContext) === 'body';
    const rows = useRows('row');

    return <tr role="row" className={cn(body && BODY_ROW, body && rows, className)} {...rest} />;
}

export function Th({ className, ...rest }: ComponentProps<'th'>) {
    return <th scope="col" role="columnheader" className={cn(HEAD_CELL, className)} {...rest} />;
}

/**
 * A body cell. Its label is the column name it shows beside its value in the rows layout, the
 * same words as its head cell; screen readers take the name from the head cell instead, so the
 * label is hidden from them. A header cell is the one that names its row.
 */
export function Td({ label, header = false, className, children, ...rest }: ComponentProps<'td'> & { label?: string; header?: boolean }) {
    const rows = useRows('cell');
    const labelled = useRows('label');
    const end = useRows('end');
    const look = cn(CELL, rows, className);
    const content = rows ? (
        <>
            {label !== undefined && (
                <span aria-hidden="true" className={cn(LABEL, labelled)}>
                    {label}
                </span>
            )}
            <div className={cn('admin-table__value min-w-0', label === undefined && end)}>{children}</div>
        </>
    ) : (
        children
    );

    if (header) {
        return (
            <th scope="row" role="rowheader" className={look} {...rest}>
                {content}
            </th>
        );
    }

    return (
        <td role="cell" className={look} {...rest}>
            {content}
        </td>
    );
}
