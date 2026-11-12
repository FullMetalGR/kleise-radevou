import { Button } from '@/components/ui/button';
import { useShell } from '@/lib/strings';

/*
 * The pagination of a paged table: a fixed page size, Previous and Next disabled at the ends,
 * inside the table's card rather than floating under it. The Bookings and Customers tables share
 * it, and the console's lists. Its words are the kit's, from the strings it is given, the page
 * line one whole sentence, :page and :last its two numbers.
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
        <footer className="admin-pagination flex items-center justify-between border-t px-4 py-3 text-sm">
            <Button className="admin-pagination__previous" disabled={page <= 1} onClick={() => onPage(page - 1)}>
                {shell.text('kit.pagination.previous')}
            </Button>
            <span className="admin-pagination__page text-muted-foreground">{shell.text('kit.pagination.page', { page: String(page), last: String(last) })}</span>
            <Button className="admin-pagination__next" disabled={page >= last} onClick={() => onPage(page + 1)}>
                {shell.text('kit.pagination.next')}
            </Button>
        </footer>
    );
}
