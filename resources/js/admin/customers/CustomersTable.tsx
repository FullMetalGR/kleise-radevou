import { Pagination } from '@/components/pagination';
import { TableScroll } from '@/components/ui/table-scroll';
import { formatInstant, type TenantClock } from '@/lib/format';
import { inPage } from '@/lib/in-page';
import { cn } from '@/lib/utils';
import { contactShown, type Customer, CUSTOMERS_TITLE, NO_VISIT } from './model';
import { TagChips } from './TagChips';
import { type CustomersKey, useCustomers } from './words';

/*
 * The customers table: Name, Email, Phone, First visit, Last visit and Bookings, the panel head
 * reading N of M and the shared pagination inside the card. The identifying cell is the most recent
 * name as a link to the customer's page, followed by the router rather than the browser so a failed
 * open is the screen's own, then the other names seen, then a row of the profile's tags and, where
 * online booking is blocked for the address, a Blocked chip; the Email and Phone columns render
 * only where the server sent them, the CONTACT rule deciding on the server. A visit is an instant,
 * spelled in the tenant's zone and formats; the last visit is the latest passed in confirmed or
 * completed, "No visit yet" where none is. Selection is the only row action, and it opens the
 * customer's page. No sorting: the server's order, the latest booking first. Every word is a key of
 * the customers section; the names are the customers' own.
 */

const NAME_HEADING = 'table.name' satisfies CustomersKey;

const EMAIL_HEADING = 'table.email' satisfies CustomersKey;

const PHONE_HEADING = 'table.phone' satisfies CustomersKey;

const FIRST_VISIT_HEADING = 'table.first_visit' satisfies CustomersKey;

const LAST_VISIT_HEADING = 'table.last_visit' satisfies CustomersKey;

const BOOKINGS_HEADING = 'table.bookings' satisfies CustomersKey;

/** The other names an address booked under, the most recent first, under the row's name. */
const ALSO_BOOKED_AS = 'table.also_booked_as' satisfies CustomersKey;

/** The chip of an address whose online booking is blocked. */
const BLOCKED = 'table.blocked' satisfies CustomersKey;

type Props = {
    rows: Customer[];
    total: number;
    page: number;
    pageSize: number;
    clock: TenantClock;
    onSelect: (customer: Customer) => void;
    onPage: (page: number) => void;
};

export function CustomersTable({ rows, total, page, pageSize, clock, onSelect, onPage }: Props) {
    const contact = contactShown(rows);
    const words = useCustomers();

    return (
        <section className="admin-card admin-customers rounded-xl border" aria-labelledby="customers-head">
            <header className="flex items-center justify-between border-b px-4 py-3">
                <h2 id="customers-head" className="font-semibold">
                    {words.text(CUSTOMERS_TITLE)}
                </h2>
                <p className="admin-customers__count text-sm text-muted-foreground">{words.text('table.count', { count: String(rows.length), total: String(total) })}</p>
            </header>
            <TableScroll>
                <table className="admin-table w-full text-sm">
                    <thead>
                        <tr className="text-left">
                            <th className="px-4 py-2">{words.text(NAME_HEADING)}</th>
                            {contact && <th className="px-4 py-2">{words.text(EMAIL_HEADING)}</th>}
                            {contact && <th className="px-4 py-2">{words.text(PHONE_HEADING)}</th>}
                            <th className="px-4 py-2">{words.text(FIRST_VISIT_HEADING)}</th>
                            <th className="px-4 py-2">{words.text(LAST_VISIT_HEADING)}</th>
                            <th className="px-4 py-2 text-right">{words.text(BOOKINGS_HEADING)}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((customer) => (
                            <tr key={customer.url} className="admin-customers__row cursor-pointer border-t" onClick={() => onSelect(customer)}>
                                <td className="px-4 py-2 align-top">
                                    <div className="flex flex-col items-start gap-1">
                                        <a
                                            href={customer.url}
                                            className="admin-customers__name font-medium underline underline-offset-3"
                                            onClick={(event) => inPage(event, () => onSelect(customer))}
                                        >
                                            {customer.name}
                                        </a>
                                        {customer.names.length > 1 && <span className="admin-customers__also text-xs text-muted-foreground">{words.text(ALSO_BOOKED_AS, { names: customer.names.slice(1).join(', ') })}</span>}
                                        {(customer.tags.length > 0 || customer.blocked) && (
                                            <div className="admin-customers__chips flex flex-wrap gap-1">
                                                {customer.blocked && <span className="admin-chip admin-customers__blocked border-destructive/60 font-medium text-destructive">{words.text(BLOCKED)}</span>}
                                                <TagChips tags={customer.tags} />
                                            </div>
                                        )}
                                    </div>
                                </td>
                                {contact && <td className="admin-customers__email px-4 py-2 align-top">{customer.email ?? ''}</td>}
                                {contact && <td className="admin-customers__phones px-4 py-2 align-top font-mono">{(customer.phones ?? []).join(', ')}</td>}
                                <td className="admin-customers__first px-4 py-2 align-top font-mono">{formatInstant(customer.first_visit, clock)}</td>
                                <td className={cn('admin-customers__last px-4 py-2 align-top', customer.last_visit !== null && 'font-mono')}>{customer.last_visit === null ? words.text(NO_VISIT) : formatInstant(customer.last_visit, clock)}</td>
                                <td className="admin-customers__bookings px-4 py-2 align-top text-right font-mono">{customer.bookings}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </TableScroll>
            <Pagination page={page} total={total} pageSize={pageSize} onPage={onPage} />
        </section>
    );
}
