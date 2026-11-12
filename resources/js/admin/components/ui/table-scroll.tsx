import type { ReactNode } from 'react';

/*
 * Every data table's frame: a full-width box that scrolls sideways, so a table wider than the
 * screen scrolls inside its card and the page never does. Where the table fits, which is every
 * desktop width, the box changes nothing. Every head cell of the table inside is set at weight
 * 600, so no table spells it.
 */
export function TableScroll({ children }: { children: ReactNode }) {
    return <div className="admin-table-scroll w-full overflow-x-auto [&_th]:font-semibold">{children}</div>;
}
