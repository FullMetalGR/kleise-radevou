import { Pagination } from '@/components/pagination';
import { RowSelectButton } from '@/components/ui/row-select-button';
import { StatusChip } from '@/components/ui/status-chip';
import { TableScroll } from '@/components/ui/table-scroll';
import { formatAmount, formatInstant, type TenantClock } from '@/lib/format';
import { cn } from '@/lib/utils';
import { type Booking, customerName, distinct, isVoid, startOf } from './model';
import { useBookings } from './words';

/*
 * The bookings table: Customer, Service, Staff, When, Status and Total, the panel head reading N
 * of M and the shared pagination inside the card. Selection is the only row action; a void row is
 * struck through and faded rather than recoloured. No sorting: the server's order. Below the
 * admin's breakpoint Service and Staff are not displayed, the Customer, When, Status and Total
 * standing, and the dialog a row opens names both. A booking whose customer was erased reads
 * "Customer erased" in the Customer cell. On the customer's own page the name is drawn plain, in
 * the body colour, still the row's button. Every word is a key of the bookings section, N of M
 * one whole line.
 */

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
        <section className="admin-card admin-bookings rounded-xl border" aria-labelledby="bookings-head">
            <header className="flex items-center justify-between border-b px-4 py-3">
                <h2 id="bookings-head" className="font-semibold">
                    {words.text('table.title')}
                </h2>
                <p className="admin-bookings__count text-sm text-muted-foreground">
                    {words.text('table.count', { count: String(rows.length), total: String(total) })}
                </p>
            </header>
            <TableScroll>
                <table className="admin-table w-full text-sm">
                    <thead>
                        <tr className="text-left">
                            <th className="px-4 py-2">{words.text('table.customer')}</th>
                            <th className="px-4 py-2 max-desktop:hidden">{words.text('table.service')}</th>
                            {staffShown && <th className="px-4 py-2 max-desktop:hidden">{words.text('table.staff')}</th>}
                            <th className="px-4 py-2">{words.text('table.when')}</th>
                            <th className="px-4 py-2">{words.text('table.status')}</th>
                            <th className="px-4 py-2 text-right">{words.text('table.total')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((booking) => (
                            <tr
                                key={booking.uuid}
                                className={cn('admin-bookings__row cursor-pointer border-t', isVoid(booking.status) && 'admin-row--void line-through opacity-60')}
                                onClick={() => onSelect(booking.uuid)}
                            >
                                <td className="px-4 py-2">
                                    <RowSelectButton
                                        name={customerName(booking, words)}
                                        selected={booking.uuid === selected}
                                        onSelect={() => onSelect(booking.uuid)}
                                        className={plainNames ? 'admin-row-select--plain text-foreground hover:no-underline' : undefined}
                                    />
                                </td>
                                <td className="admin-bookings__services px-4 py-2 max-desktop:hidden">{distinct(booking.items.map((item) => item.service_name)).join(', ')}</td>
                                {staffShown && <td className="admin-bookings__staff px-4 py-2 max-desktop:hidden">{distinct(booking.items.map((item) => item.staff_name)).join(', ')}</td>}
                                <td className="admin-bookings__when px-4 py-2 font-mono">{formatInstant(startOf(booking), clock)}</td>
                                <td className="px-4 py-2">
                                    <StatusChip status={booking.status} />
                                </td>
                                <td className="admin-bookings__total px-4 py-2 text-right font-mono">{formatAmount(booking.total_minor, booking.currency)}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </TableScroll>
            <Pagination page={page} total={total} pageSize={pageSize} onPage={onPage} />
        </section>
    );
}
