import { Fragment } from 'react';
import { Initials } from '@/components/initials';
import { Pagination } from '@/components/pagination';
import { Table, TableBody, TableHead, TableRow, Td, Th } from '@/components/ui/table';
import { formatInstant, type TenantClock } from '@/lib/format';
import { inPage } from '@/lib/in-page';
import { cn } from '@/lib/utils';
import { contactShown, type Customer, NO_VISIT } from './model';
import { TagChips } from './TagChips';
import { type CustomersKey, useCustomers } from './words';

/*
 * The customers table: Name, Email, Phone, First visit, Last visit and Bookings, then the shared
 * pagination; the screen's card holds it under its toolbar. The identifying cell is the customer's
 * initials, then the most recent name as a link to the customer's page, followed by the router
 * rather than the browser so a failed open is the screen's own, then the other names seen, then a
 * row of the profile's tags and, where online booking is blocked for the address, a Blocked chip.
 * The Email and Phone columns render only where the server sent them, the CONTACT rule deciding on
 * the server. A visit is an instant, spelled in the tenant's zone and formats; the last visit is
 * the latest passed in confirmed or completed, "No visit yet" in the muted ink where none is. The
 * booking count is a small soft chip. Selection is the only row action, and it opens the
 * customer's page. No sorting: the server's order, the latest booking first. Every word is a key of
 * the customers section; the names are the customers' own.
 *
 * The table sits in no sideways box, so at a wide window its head row stays at the top of the
 * window while the page scrolls through the list. Its head labels, the name, the email past its
 * floor and the phones wrap rather than widen it, each phone moving whole onto a line of its own,
 * breaking at its spaces where it is wider than the line and anywhere where it has none. Below the
 * wide breakpoint, and whenever the card is narrower than 936px, each row is a block: the initials
 * and the name with the booking count at its end, then the email, the phones and the visits
 * beneath, lined up with the name, the two visits sharing one cell, each date after its head as a
 * small label. No cell is labelled there but the visits; the head row leaves the screen but not the
 * reading order, and each part keeps its role, so a screen reader still reads a table with its
 * column headers. Each condition is written out in full, the same utilities under each. The width
 * is the first half rem at which a Greek table with a sixty-character name and email and a last
 * visit that has passed fits with at least two pixels to spare, its email and phones at their
 * floors and every other column at its narrowest: a visit column is never narrower than a date. A
 * narrower grid would have to share the visits in one column, where a Greek head and its date wrap
 * onto four lines or more and run every row tall, or hold each date whole and outgrow its card.
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

/**
 * What the block layout changes, part by part, each utility under both conditions. A cell needs
 * no display of its own there: a grid's child is drawn as a block whatever it says. The card
 * condition is the one that shares the visits, so the grid only ever draws them apart.
 */
const BLOCK = {
    table: 'max-wide:block @max-[58.5rem]:block',
    head: 'max-wide:sr-only @max-[58.5rem]:sr-only',
    body: 'max-wide:block @max-[58.5rem]:block',
    row: 'max-wide:grid max-wide:grid-cols-[minmax(0,1fr)_auto] max-wide:items-center max-wide:gap-x-2.5 max-wide:gap-y-1.5 max-wide:border-t max-wide:px-4 max-wide:py-3.5 max-wide:first:border-t-0 @max-[58.5rem]:grid @max-[58.5rem]:grid-cols-[minmax(0,1fr)_auto] @max-[58.5rem]:items-center @max-[58.5rem]:gap-x-2.5 @max-[58.5rem]:gap-y-1.5 @max-[58.5rem]:border-t @max-[58.5rem]:px-4 @max-[58.5rem]:py-3.5 @max-[58.5rem]:first:border-t-0',
    cell: 'max-wide:min-w-0 max-wide:border-t-0 max-wide:p-0 max-wide:first:pl-0 max-wide:last:pr-0 @max-[58.5rem]:min-w-0 @max-[58.5rem]:border-t-0 @max-[58.5rem]:p-0 @max-[58.5rem]:first:pl-0 @max-[58.5rem]:last:pr-0',
    /** A line beneath the name, indented by the initials and the gap after them. */
    beneath: 'max-wide:col-span-2 max-wide:ps-13.5 @max-[58.5rem]:col-span-2 @max-[58.5rem]:ps-13.5',
    bookings: 'max-wide:col-start-2 max-wide:row-start-1 @max-[58.5rem]:col-start-2 @max-[58.5rem]:row-start-1',
    /** The name and the other names break anywhere, so neither runs under the booking count. */
    anywhere: 'max-wide:wrap-anywhere @max-[58.5rem]:wrap-anywhere',
    /** The two visits side by side in a block row, wrapping onto two lines where they must. */
    visits: 'max-wide:flex-row max-wide:flex-wrap max-wide:gap-x-5 @max-[58.5rem]:flex-row @max-[58.5rem]:flex-wrap @max-[58.5rem]:gap-x-5',
};

/**
 * Which form the visits take: two columns of their own in a wide card, or one shared column in the
 * block rows, which a card narrower than 936px draws. One form shows at a time.
 */
const VISITS = {
    apart: 'max-wide:hidden @max-[58.5rem]:hidden',
    shared: 'hidden max-wide:table-cell @max-[58.5rem]:table-cell',
};

/** A head label wraps onto a second line rather than widening its column. */
const HEAD = 'whitespace-normal';

/**
 * The customer's name wraps between its words at every width. In the grid a word longer than its
 * column breaks only where it must, so the column never shrinks below its longest word; in the
 * block rows it breaks anywhere, so the name never runs under the booking count.
 */
const NAME = cn('whitespace-normal wrap-break-word', BLOCK.anywhere);

/**
 * The email breaks anywhere and is never cut, but in the grid its column keeps 13rem, padding
 * included, so an address of about twenty-two characters stays on one line; only what runs past
 * that breaks. A block row drops the floor and gives the email the line it has.
 */
const EMAIL = 'min-w-52 wrap-anywhere';

/**
 * Each phone moves whole onto a line of its own and breaks at its spaces where it is wider than
 * the line, anywhere only where it has none, so no phone runs past its line. In the grid the
 * column keeps 10.5rem, padding included, so a phone of about sixteen characters stays whole
 * however hard a long name or email squeezes the table; a block row drops the floor.
 */
const PHONES = 'min-w-42 wrap-anywhere';

type Props = {
    rows: Customer[];
    total: number;
    page: number;
    pageSize: number;
    clock: TenantClock;
    onSelect: (customer: Customer) => void;
    onPage: (page: number) => void;
};

/**
 * The identifying cell's content: the initials, then the name as the link to the customer's page,
 * the other names seen under it, and the tags with the Blocked chip.
 */
function Who({ customer, onSelect }: { customer: Customer; onSelect: (customer: Customer) => void }) {
    const words = useCustomers();

    return (
        <div className="flex items-center gap-3.5">
            <Initials name={customer.name} size="person" />
            <div className="flex min-w-0 flex-col items-start gap-1">
                <a
                    href={customer.url}
                    className={cn('admin-customers__name focus-ring rounded-sm font-display font-semibold text-foreground underline-offset-4 hover:underline', NAME)}
                    onClick={(event) => inPage(event, () => onSelect(customer))}
                >
                    {customer.name}
                </a>
                {customer.names.length > 1 && <span className={cn('admin-customers__also text-small text-muted-foreground', BLOCK.anywhere)}>{words.text(ALSO_BOOKED_AS, { names: customer.names.slice(1).join(', ') })}</span>}
                {(customer.tags.length > 0 || customer.blocked) && (
                    <div className="admin-customers__chips flex flex-wrap gap-1">
                        {customer.blocked && <span className="admin-chip admin-customers__blocked border-destructive/60 font-medium text-destructive">{words.text(BLOCKED)}</span>}
                        <TagChips tags={customer.tags} />
                    </div>
                )}
            </div>
        </div>
    );
}

export function CustomersTable({ rows, total, page, pageSize, clock, onSelect, onPage }: Props) {
    const contact = contactShown(rows);
    const words = useCustomers();

    return (
        <>
            <Table compact="own" className={BLOCK.table}>
                <TableHead className={BLOCK.head}>
                    <TableRow>
                        <Th className={HEAD}>{words.text(NAME_HEADING)}</Th>
                        {contact && <Th className={HEAD}>{words.text(EMAIL_HEADING)}</Th>}
                        {contact && <Th className={HEAD}>{words.text(PHONE_HEADING)}</Th>}
                        <Th className={cn(HEAD, VISITS.apart)}>{words.text(FIRST_VISIT_HEADING)}</Th>
                        <Th className={cn(HEAD, VISITS.apart)}>{words.text(LAST_VISIT_HEADING)}</Th>
                        <Th className={cn(HEAD, VISITS.shared)}>
                            <span className="block">{words.text(FIRST_VISIT_HEADING)}</span>
                            <span className="block">{words.text(LAST_VISIT_HEADING)}</span>
                        </Th>
                        <Th className={cn('text-right', HEAD)}>{words.text(BOOKINGS_HEADING)}</Th>
                    </TableRow>
                </TableHead>
                <TableBody className={BLOCK.body}>
                    {rows.map((customer) => {
                        const last = customer.last_visit === null ? words.text(NO_VISIT) : formatInstant(customer.last_visit, clock);
                        const unvisited = customer.last_visit === null && 'text-muted-foreground';

                        return (
                            <TableRow key={customer.url} className={cn('admin-customers__row cursor-pointer', BLOCK.row)} onClick={() => onSelect(customer)}>
                                <Td className={BLOCK.cell}>
                                    <Who customer={customer} onSelect={onSelect} />
                                </Td>
                                {contact && <Td className={cn('admin-customers__email', EMAIL, BLOCK.cell, BLOCK.beneath)}>{customer.email ?? ''}</Td>}
                                {contact && (
                                    <Td className={cn('admin-customers__phones', PHONES, BLOCK.cell, BLOCK.beneath)}>
                                        {(customer.phones ?? []).map((phone, at) => (
                                            <Fragment key={phone}>
                                                {at > 0 && ', '}
                                                <span className="inline-block">{phone}</span>
                                            </Fragment>
                                        ))}
                                    </Td>
                                )}
                                <Td className={cn('admin-customers__first', VISITS.apart)}>{formatInstant(customer.first_visit, clock)}</Td>
                                <Td className={cn('admin-customers__last', VISITS.apart, unvisited)}>{last}</Td>
                                <Td className={cn('admin-customers__visits', VISITS.shared, BLOCK.cell, BLOCK.beneath)}>
                                    <div className={cn('flex flex-col gap-0.5', BLOCK.visits)}>
                                        <span className="flex flex-wrap items-baseline gap-x-2">
                                            <span className="text-small font-semibold text-muted-foreground">{words.text(FIRST_VISIT_HEADING)}</span>
                                            <span>{formatInstant(customer.first_visit, clock)}</span>
                                        </span>
                                        <span className="flex flex-wrap items-baseline gap-x-2">
                                            <span className="text-small font-semibold text-muted-foreground">{words.text(LAST_VISIT_HEADING)}</span>
                                            <span className={cn(unvisited) || undefined}>{last}</span>
                                        </span>
                                    </div>
                                </Td>
                                <Td className={cn('admin-customers__bookings text-right', BLOCK.cell, BLOCK.bookings)}>
                                    <span className="inline-grid h-7 min-w-7.5 place-items-center rounded-full bg-muted px-2.25 font-bold text-primary-deep">{customer.bookings}</span>
                                </Td>
                            </TableRow>
                        );
                    })}
                </TableBody>
            </Table>
            <Pagination page={page} total={total} pageSize={pageSize} onPage={onPage} />
        </>
    );
}
