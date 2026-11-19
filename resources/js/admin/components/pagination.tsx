import { Button } from '@/components/ui/button';
import { useShell } from '@/lib/strings';

/*
 * The pagination of a paged table: a fixed page size, Previous and Next disabled at the ends,
 * inside the table's card rather than floating under it. The Bookings and Customers tables share
 * it, and the console's lists. Its words are the kit's, from the strings it is given, the page
 * line one whole sentence, :page and :last its two numbers.
 *
 * A rule parts it from the table above. Previous and Next are the small buttons, and the page line
 * between them is filled in ink, the one mark of where the reader stands. The fill takes a field's
 * rounding, half its height on one line and so a pill there; where the line wraps on a narrow
 * screen, its lines stay centred inside a rounded box, which a full rounding would swell into a
 * blob.
 */

type Props = {
    page: number;
    total: number;
    pageSize: number;
    onPage: (page: number) => void;
};

export function Pagination({ page, total, pageSize, onPage }: Props) {
    const shell = useShell();
    const last = Math.max(1, Math.ceil(total / pageSize));

    return (
        <footer className="admin-pagination flex items-center justify-between gap-3 border-t px-6 py-3.5 text-sm">
            <Button size="small" className="admin-pagination__previous" disabled={page <= 1} onClick={() => onPage(page - 1)}>
                {shell.text('kit.pagination.previous')}
            </Button>
            <span className="admin-pagination__page rounded-field bg-foreground px-3 py-1 text-center font-semibold text-background tabular-nums">
                {shell.text('kit.pagination.page', { page: String(page), last: String(last) })}
            </span>
            <Button size="small" className="admin-pagination__next" disabled={page >= last} onClick={() => onPage(page + 1)}>
                {shell.text('kit.pagination.next')}
            </Button>
        </footer>
    );
}
