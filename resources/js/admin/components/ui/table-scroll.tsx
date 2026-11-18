import type { ReactNode } from 'react';

/*
 * Every data table's frame: a full-width box that scrolls sideways, so a table wider than the
 * screen scrolls inside its card and the page never does. Where the table fits, which is every
 * desktop width, the box changes nothing. Every head cell of the table inside is set at weight
 * 600, so no table spells it.
 *
 * The box is positioned, so it is the containing block of whatever a cell positions absolutely,
 * a label kept for screen readers alone among them. Left unpositioned, such a label in a far
 * column is laid out against the page, past the box's clip, and widens the page by the table's
 * whole width.
 */
export function TableScroll({ children }: { children: ReactNode }) {
    return <div className="admin-table-scroll relative w-full overflow-x-auto [&_th]:font-semibold">{children}</div>;
}
