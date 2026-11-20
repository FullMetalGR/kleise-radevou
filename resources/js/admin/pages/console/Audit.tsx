import { Head, usePage } from '@inertiajs/react';
import { ScrollText } from 'lucide-react';
import { Pagination } from '@/components/pagination';
import { SettledList } from '@/components/settled-list';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { PageHeader } from '@/components/ui/page-header';
import { Table, TableBody, TableHead, TableRow, Td, Th } from '@/components/ui/table';
import { usePartialRead } from '@/lib/partial-read';
import type { ConsoleProps } from '@/types';

/*
 * The Audit screen: every operator action the platform audit log holds, newest first, fifty a
 * page by the admin's pagination, inside the console's shell. Each row is the instant as the
 * server spelled it on the console's clock, which the top bar names once for the screen, the
 * operator's name, the action in words from the map below, one per case of the vocabulary, the
 * tenant's slug, and the payload's facts beside it.
 *
 * The narrowing to one tenant and the page live in the query string, which the server reads, and
 * nowhere else: a tenant's slug on a row narrows the list to it, and Show every tenant lifts it.
 * Every change is a partial reload of the list, and nothing is optimistic: the rows on screen are
 * the server's last answer, and a read that fails says so above them.
 */

/** The vocabulary of App\Platform\PlatformAuditAction, as the server sends each row's action. */
type AuditAction = 'sign_in' | 'sign_out' | 'tenant_suspended' | 'tenant_resumed' | 'tenant_created' | 'features_changed' | 'password_link_sent' | 'support_opened' | 'support_ended' | 'contact_archived' | 'tenant_closed' | 'tenant_reopened' | 'help_ticket_replied' | 'help_ticket_closed' | 'help_ticket_reopened';

/** The key list of App\Platform\PlatformAuditKey. */
type AuditKey = 'reason' | 'features' | 'user' | 'message' | 'ticket';

type Scalar = string | number | boolean;

type AuditFact = { key: AuditKey; value: Scalar | Scalar[] };

export type AuditRow = {
    id: number;
    /** The instant, spelled by the server on the console's clock, which the top bar names. */
    at: string;
    operator: string;
    action: AuditAction;
    /** The tenant's slug, or null for an action about no tenant. */
    tenant: string | null;
    /** The payload's facts, in the key list's order. */
    facts: AuditFact[];
};

type AuditPage = { rows: AuditRow[]; total: number; page: number; page_size: number };

type Props = ConsoleProps & {
    /** The tenant the list is narrowed to, by its slug, or null for every tenant. */
    filters: { tenant: string | null };
    entries: AuditPage;
};

const AUDIT = '/audit';

/** What a narrowing or a page reads again: the rows and the filter they answer. */
const LIST_PROPS = ['entries', 'filters'];

const AUDIT_TITLE = 'Audit';

/** Each action in words, one per case of the vocabulary. */
export const ACTION_WORDS: Record<AuditAction, string> = {
    sign_in: 'Signed in',
    sign_out: 'Signed out',
    tenant_suspended: 'Suspended a tenant',
    tenant_resumed: 'Resumed a tenant',
    tenant_created: 'Created a tenant',
    features_changed: 'Changed a tenant\'s features',
    password_link_sent: 'Sent a set-password link',
    support_opened: 'Opened a support session',
    support_ended: 'Ended a support session',
    contact_archived: 'Archived a contact message',
    tenant_closed: 'Closed a tenant',
    tenant_reopened: 'Reopened a tenant',
    help_ticket_replied: 'Replied to a help ticket',
    help_ticket_closed: 'Closed a help ticket',
    help_ticket_reopened: 'Reopened a help ticket',
};

/** Each payload key in words, one per case of the key list. */
const FACT_WORDS: Record<AuditKey, string> = {
    reason: 'Reason',
    features: 'Features',
    user: 'User',
    message: 'Message',
    ticket: 'Ticket',
};

const WHEN_HEADING = 'When';

const OPERATOR_HEADING = 'Operator';

const ACTION_HEADING = 'Action';

const TENANT_HEADING = 'Tenant';

const DETAILS_HEADING = 'Details';

/** What a list fact holding nothing reads as. */
const NONE = 'none';

export const NO_ACTIONS = 'No operator action is recorded yet.';

export const NO_TENANT_ACTIONS = 'No operator action on this tenant is recorded yet.';

export const AUDIT_LIST_ERROR = 'The audit log could not be loaded. Check your connection and try again.';

export const SHOW_EVERY_TENANT = 'Show every tenant';

const SHOWING_EVERY_TENANT = 'Showing every tenant';

/** Says the list is narrowed, naming the tenant. */
export function narrowedTo(slug: string): string {
    return `Showing the actions on ${slug} only.`;
}

function scalarText(value: Scalar): string {
    if (typeof value === 'boolean') {
        return value ? 'yes' : 'no';
    }

    return String(value);
}

/** A fact as the Details cell reads it: its key in words, then its value, a list joined. */
function factText(fact: AuditFact): string {
    const value = Array.isArray(fact.value) ? (fact.value.length === 0 ? NONE : fact.value.map(scalarText).join(', ')) : scalarText(fact.value);

    return `${FACT_WORDS[fact.key]}: ${value}`;
}

/** The query string of a narrowing and a page, the defaults left out, so the first page of every tenant is the bare screen. */
function auditQuery(tenant: string | null, page: number): Record<string, string> {
    const query: Record<string, string> = {};

    if (tenant !== null) {
        query['tenant'] = tenant;
    }

    if (page > 1) {
        query['page'] = String(page);
    }

    return query;
}

export default function Audit() {
    const { props } = usePage<Props>();
    const list = usePartialRead(LIST_PROPS);
    const tenant = props.filters.tenant;
    const rows = props.entries.rows;
    const load = (narrowed: string | null, page: number) => list.read(AUDIT, auditQuery(narrowed, page));

    return (
        <>
            <Head title={AUDIT_TITLE} />
            <div className="admin-audit-screen flex flex-col gap-4">
                <PageHeader title={AUDIT_TITLE} />
                {tenant !== null && (
                    <div className="admin-audit-narrowed flex flex-wrap items-center gap-3 text-sm">
                        <p>{narrowedTo(tenant)}</p>
                        <Button className="admin-audit-every" busy={list.loading} busyLabel={SHOWING_EVERY_TENANT} onClick={() => load(null, 1)}>
                            {SHOW_EVERY_TENANT}
                        </Button>
                    </div>
                )}
                <SettledList
                    count={rows.length}
                    state={{ loading: list.loading, error: list.error }}
                    errorSentence={AUDIT_LIST_ERROR}
                    empty={
                        <Card flush>
                            <EmptyState className="admin-audit-empty" icon={<ScrollText />}>
                                {tenant === null ? NO_ACTIONS : NO_TENANT_ACTIONS}
                            </EmptyState>
                        </Card>
                    }
                >
                    <Card flush className="admin-card admin-audit" aria-labelledby="audit-head">
                        <header className="flex items-center justify-between border-b px-4 py-3">
                            <h2 id="audit-head" className="font-semibold">
                                {AUDIT_TITLE}
                            </h2>
                            <p className="admin-audit__count text-sm text-muted-foreground">
                                {rows.length} of {props.entries.total}
                            </p>
                        </header>
                        <Table>
                            <TableHead>
                                <TableRow>
                                    <Th>{WHEN_HEADING}</Th>
                                    <Th>{OPERATOR_HEADING}</Th>
                                    <Th>{ACTION_HEADING}</Th>
                                    <Th>{TENANT_HEADING}</Th>
                                    <Th>{DETAILS_HEADING}</Th>
                                </TableRow>
                            </TableHead>
                            <TableBody>
                                {rows.map((row) => (
                                    <TableRow key={row.id} className="admin-audit__row">
                                        <Td label={WHEN_HEADING} header className="admin-audit__at">
                                            {row.at}
                                        </Td>
                                        <Td label={OPERATOR_HEADING} className="admin-audit__operator">
                                            {row.operator}
                                        </Td>
                                        <Td label={ACTION_HEADING} className="admin-audit__action">
                                            {ACTION_WORDS[row.action]}
                                        </Td>
                                        <Td label={TENANT_HEADING}>
                                            {row.tenant !== null && (
                                                <Button variant="link" className="admin-audit__tenant" onClick={() => load(row.tenant, 1)}>
                                                    {row.tenant}
                                                </Button>
                                            )}
                                        </Td>
                                        <Td label={DETAILS_HEADING} className="admin-audit__facts">
                                            {row.facts.map((fact) => (
                                                <span key={fact.key} className="admin-audit__fact block">
                                                    {factText(fact)}
                                                </span>
                                            ))}
                                        </Td>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                        <Pagination page={props.entries.page} total={props.entries.total} pageSize={props.entries.page_size} onPage={(page) => load(tenant, page)} />
                    </Card>
                </SettledList>
            </div>
        </>
    );
}
