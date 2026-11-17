import { Head, Link, usePage } from '@inertiajs/react';
import { Pagination } from '@/components/pagination';
import { SettledList } from '@/components/settled-list';
import { StatusChip } from '@/components/ui/status-chip';
import { TableScroll } from '@/components/ui/table-scroll';
import { usePartialRead } from '@/lib/partial-read';
import type { ConsoleProps } from '@/types';

/*
 * The console's help tickets: every business's questions to the platform in one list, open before
 * closed and then newest activity first, the order the server reads them in, twenty-five a page by
 * the admin's pagination, inside the console's shell. Each row is the subject, a link to the
 * thread, the business by name, a link to its page on this console, the newest message's instant
 * as the server spelled it on the console's clock, which the top bar names once for the screen,
 * and the status in words, marked Awaiting reply while the ticket is open and the business wrote
 * last.
 *
 * The page lives in the query string, which the server reads, and nowhere else: a page is a
 * partial reload of the list, and nothing is optimistic, the rows on screen being the server's
 * last answer, a read that fails saying so above them.
 */

/** The statuses of App\Help\HelpStatus. */
type TicketStatus = 'open' | 'closed';

/** A ticket as the list's rows and the thread carry it. */
export type TicketSummary = {
    id: number;
    subject: string;
    /** The business by name, and its page on this console. */
    tenant: { name: string; url: string };
    status: TicketStatus;
    /** Open, with the business having written last. */
    awaiting: boolean;
    /** The newest message's instant, spelled by the server on the console's clock, which the top bar names. */
    last_message: string;
};

type TicketsPage = { rows: TicketSummary[]; total: number; page: number; page_size: number };

type Props = ConsoleProps & {
    tickets: TicketsPage;
};

const TICKETS = '/tickets';

/** What a page reads again: the rows alone. */
const LIST_PROPS = ['tickets'];

const TICKETS_TITLE = 'Tickets';

const HEADINGS = ['Subject', 'Business', 'Last message', 'Status'];

/** Each status in words, one per case. */
export const STATUS_WORDS: Record<TicketStatus, string> = {
    open: 'Open',
    closed: 'Closed',
};

export const AWAITING_REPLY = 'Awaiting reply';

export const NO_TICKETS = 'No business has asked for help yet.';

export const TICKETS_LIST_ERROR = 'The tickets could not be loaded. Check your connection and try again.';

/** A thread's address on this console. */
export function threadPath(id: number): string {
    return `${TICKETS}/${id}`;
}

/**
 * A ticket's status in words under the class named, and beside it, while the ticket awaits a
 * reply, the chip saying so; the list and the thread name their own.
 */
export function TicketStatusWords({ ticket, name }: { ticket: TicketSummary; name: string }) {
    return (
        <span className="inline-flex flex-wrap items-center gap-2">
            <span className={`${name}__status`}>{STATUS_WORDS[ticket.status]}</span>
            {ticket.awaiting && <StatusChip status="awaiting_reply" label={AWAITING_REPLY} className={`${name}__awaiting`} />}
        </span>
    );
}

/** The query string of a page, the first left out, so the first page is the bare screen. */
function ticketsQuery(page: number): Record<string, string> {
    return page > 1 ? { page: String(page) } : {};
}

function TicketRow({ ticket }: { ticket: TicketSummary }) {
    return (
        <tr className="admin-tickets__row border-t" data-awaiting={ticket.awaiting ? 'true' : 'false'}>
            <td className="px-4 py-2">
                <Link href={threadPath(ticket.id)} className="admin-tickets__subject font-medium underline-offset-4 hover:underline">
                    {ticket.subject}
                </Link>
            </td>
            <td className="px-4 py-2">
                <Link href={ticket.tenant.url} className="admin-tickets__tenant underline-offset-4 hover:underline">
                    {ticket.tenant.name}
                </Link>
            </td>
            <td className="admin-tickets__last px-4 py-2">{ticket.last_message}</td>
            <td className="px-4 py-2">
                <TicketStatusWords ticket={ticket} name="admin-tickets" />
            </td>
        </tr>
    );
}

export default function Tickets() {
    const { props } = usePage<Props>();
    const list = usePartialRead(LIST_PROPS);
    const rows = props.tickets.rows;

    return (
        <>
            <Head title={TICKETS_TITLE} />
            <div className="admin-tickets-screen flex flex-col gap-4">
                <h1 className="text-2xl font-semibold">{TICKETS_TITLE}</h1>
                <SettledList
                    count={rows.length}
                    state={{ loading: list.loading, error: list.error }}
                    errorSentence={TICKETS_LIST_ERROR}
                    empty={
                        <div className="admin-tickets-empty rounded-xl border p-6 text-center">
                            <p className="text-muted-foreground">{NO_TICKETS}</p>
                        </div>
                    }
                >
                    <section className="admin-card admin-tickets rounded-xl border" aria-labelledby="tickets-head">
                        <header className="flex items-center justify-between border-b px-4 py-3">
                            <h2 id="tickets-head" className="font-semibold">
                                {TICKETS_TITLE}
                            </h2>
                            <p className="admin-tickets__count text-sm text-muted-foreground">
                                {rows.length} of {props.tickets.total}
                            </p>
                        </header>
                        <TableScroll>
                            <table className="admin-table w-full text-sm">
                                <thead>
                                    <tr className="text-left">
                                        {HEADINGS.map((heading) => (
                                            <th key={heading} className="px-4 py-2">
                                                {heading}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    {rows.map((ticket) => (
                                        <TicketRow key={ticket.id} ticket={ticket} />
                                    ))}
                                </tbody>
                            </table>
                        </TableScroll>
                        <Pagination page={props.tickets.page} total={props.tickets.total} pageSize={props.tickets.page_size} onPage={(page) => list.read(TICKETS, ticketsQuery(page))} />
                    </section>
                </SettledList>
            </div>
        </>
    );
}
