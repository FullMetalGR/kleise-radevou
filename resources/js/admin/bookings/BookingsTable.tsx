import { Instant } from '@/components/instant';
import { Pagination } from '@/components/pagination';
import { Card } from '@/components/ui/card';
import { RowSelectButton } from '@/components/ui/row-select-button';
import { StatusChip } from '@/components/ui/status-chip';
import { Table, TableBody, TableHead, TableRow, Td, Th } from '@/components/ui/table';
import { formatAmount, type TenantClock } from '@/lib/format';
import { cn } from '@/lib/utils';
import { AWAITING_APPROVAL, type Booking, customerName, distinct, isVoid, startOf } from './model';
import { useBookings } from './words';

/*
 * The bookings table: Customer, Service, Staff, When, Status and Total, the card's head reading
 * the title and N of M and the shared pagination inside the card. Selection is the only row
 * action; a void row is struck through and faded rather than recoloured, and a row awaiting
 * approval wears an amber bar at its left edge beside its chip. No sorting: the server's order. A
 * booking whose customer was erased reads "Customer erased" in the Customer cell. On the
 * customer's own page the name is drawn plain, in the body colour, still the row's button. When
 * reads the date in the muted ink and the time in bold a little after it; on a void row the date
 * keeps the body ink, since the row's fade already sets it apart and muting it as well would drop
 * it below a readable contrast. Every word is a key of the bookings section, N of M one whole line.
 *
 * The table sits in no sideways box, so at a wide window its head row stays at the top of the
 * window while the page scrolls through the list. Its head labels and the customer's name wrap
 * rather than widen it, so in Greek the grid needs about 740px with a long name and the longest
 * chip. Below the wide breakpoint, and whenever the card is narrower than 768px, so the card beside
 * the open sidebar at the narrowest desktop window and the list on a customer's page read the
 * same, each row is a block of four lines: the name with the total at its end, the services, the
 * staff, then the date with the status at its end. No cell is labelled there; the head row leaves
 * the screen but not the reading order, and each part keeps its role, so a screen reader still
 * reads a table with its column headers. A long name breaks inside its line rather than widening
 * the row. Both conditions are written out in full, the same utilities under each.
 */

/** What the block layout changes, part by part, each utility under both conditions. */
const BLOCK = {
    table: 'max-wide:block @max-3xl:block',
    head: 'max-wide:sr-only @max-3xl:sr-only',
    body: 'max-wide:block @max-3xl:block',
    row: 'max-wide:grid max-wide:grid-cols-[minmax(0,1fr)_auto] max-wide:items-center max-wide:gap-x-3 max-wide:gap-y-0.5 max-wide:border-t max-wide:px-[18px] max-wide:py-3 max-wide:first:border-t-0 @max-3xl:grid @max-3xl:grid-cols-[minmax(0,1fr)_auto] @max-3xl:items-center @max-3xl:gap-x-3 @max-3xl:gap-y-0.5 @max-3xl:border-t @max-3xl:px-[18px] @max-3xl:py-3 @max-3xl:first:border-t-0',
    cell: 'max-wide:block max-wide:min-w-0 max-wide:border-t-0 max-wide:p-0 max-wide:first:pl-0 max-wide:last:pr-0 @max-3xl:block @max-3xl:min-w-0 @max-3xl:border-t-0 @max-3xl:p-0 @max-3xl:first:pl-0 @max-3xl:last:pr-0',
    services: 'max-wide:col-span-2 max-wide:wrap-anywhere @max-3xl:col-span-2 @max-3xl:wrap-anywhere',
    staff: 'max-wide:col-span-2 max-wide:wrap-anywhere max-wide:text-muted-foreground @max-3xl:col-span-2 @max-3xl:wrap-anywhere @max-3xl:text-muted-foreground',
    when: 'max-wide:mt-1.5 @max-3xl:mt-1.5',
    status: 'max-wide:mt-1.5 max-wide:justify-self-end @max-3xl:mt-1.5 @max-3xl:justify-self-end',
    total: 'max-wide:col-start-2 max-wide:row-start-1 @max-3xl:col-start-2 @max-3xl:row-start-1',
    name: 'max-wide:wrap-anywhere @max-3xl:wrap-anywhere',
    inset: 'max-wide:px-[18px] @max-3xl:px-[18px]',
};

/**
 * A head label wraps onto a second line in this table rather than widening its column, so the
 * grid fits the card beside the open sidebar in either language.
 */
const HEAD = 'whitespace-normal';

/**
 * The customer's name wraps between its words at every width. In the grid a word longer than its
 * column breaks only where it must, so the column never shrinks below its longest word; in the
 * block rows it breaks anywhere, so the name never runs over the total.
 */
const NAME = cn('whitespace-normal wrap-break-word', BLOCK.name);

type Props = {
    rows: Booking[];
    total: number;
    page: number;
    pageSize: number;
    staffShown: boolean;
    /** Whether the customer's name is drawn in the body colour rather than as a link, as on the customer's own page, where every row is theirs. */
    plainNames?: boolean;
    selected: string | null;
    clock: TenantClock;
    onSelect: (uuid: string) => void;
    onPage: (page: number) => void;
};

export function BookingsTable({ rows, total, page, pageSize, staffShown, plainNames = false, selected, clock, onSelect, onPage }: Props) {
    const words = useBookings();

    return (
        <Card flush className="admin-card admin-bookings @container" aria-labelledby="bookings-head">
            <header className={cn('flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1 border-b px-6.5 pt-5 pb-3.5', BLOCK.inset)}>
                <h2 id="bookings-head" className="text-section-title font-semibold">
                    {words.text('table.title')}
                </h2>
                <p className="admin-bookings__count text-body-sm text-muted-foreground">
                    {words.text('table.count', { count: String(rows.length), total: String(total) })}
                </p>
            </header>
            <Table compact="own" className={BLOCK.table}>
                <TableHead className={BLOCK.head}>
                    <TableRow>
                        <Th className={HEAD}>{words.text('table.customer')}</Th>
                        <Th className={HEAD}>{words.text('table.service')}</Th>
                        {staffShown && <Th className={HEAD}>{words.text('table.staff')}</Th>}
                        <Th className={HEAD}>{words.text('table.when')}</Th>
                        <Th className={HEAD}>{words.text('table.status')}</Th>
                        <Th className={cn('text-right', HEAD)}>{words.text('table.total')}</Th>
                    </TableRow>
                </TableHead>
                <TableBody className={BLOCK.body}>
                    {rows.map((booking) => (
                        <TableRow
                            key={booking.uuid}
                            className={cn(
                                'admin-bookings__row cursor-pointer',
                                BLOCK.row,
                                isVoid(booking.status) && 'admin-row--void line-through opacity-60',
                                booking.status === AWAITING_APPROVAL && 'admin-row--attention shadow-[inset_4px_0_0_var(--attention)]',
                            )}
                            onClick={() => onSelect(booking.uuid)}
                        >
                            <Td className={BLOCK.cell}>
                                <RowSelectButton
                                    name={customerName(booking, words)}
                                    selected={booking.uuid === selected}
                                    onSelect={() => onSelect(booking.uuid)}
                                    className={cn(NAME, plainNames && 'admin-row-select--plain text-foreground enabled:hover:no-underline')}
                                />
                            </Td>
                            <Td className={cn('admin-bookings__services', BLOCK.cell, BLOCK.services)}>{distinct(booking.items.map((item) => item.service_name)).join(', ')}</Td>
                            {staffShown && <Td className={cn('admin-bookings__staff', BLOCK.cell, BLOCK.staff)}>{distinct(booking.items.map((item) => item.staff_name)).join(', ')}</Td>}
                            <Td className={cn('admin-bookings__when', BLOCK.cell, BLOCK.when)}>
                                <Instant wire={startOf(booking)} clock={clock} day={isVoid(booking.status) ? undefined : 'text-muted-foreground'} time="ms-1 font-bold" />
                            </Td>
                            <Td className={cn('admin-bookings__status', BLOCK.cell, BLOCK.status)}>
                                <StatusChip status={booking.status} />
                            </Td>
                            <Td className={cn('admin-bookings__total text-right font-bold', BLOCK.cell, BLOCK.total)}>{formatAmount(booking.total_minor, booking.currency)}</Td>
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
            <Pagination page={page} total={total} pageSize={pageSize} onPage={onPage} />
        </Card>
    );
}
