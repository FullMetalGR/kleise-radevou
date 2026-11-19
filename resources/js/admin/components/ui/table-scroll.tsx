import type { ReactNode } from 'react';

/*
 * Every data table's frame: a full-width box that scrolls sideways, so a table wider than the
 * screen scrolls inside its card and the page never does. Where the table fits, the box changes
 * nothing. The box is a query container, so a table inside can lay itself out by the width of
 * its own box as well as by the screen's. It sets no type: each table spells its own.
 *
 * The box is positioned, so it is the containing block of whatever a cell positions absolutely,
 * a label kept for screen readers alone among them. Left unpositioned, such a label in a far
 * column is laid out against the page, past the box's clip, and widens the page by the table's
 * whole width.
 */
export function TableScroll({ children }: { children: ReactNode }) {
    return <div className="admin-table-scroll @container relative w-full overflow-x-auto">{children}</div>;
}
