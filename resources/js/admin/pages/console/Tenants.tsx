import { Head, Link, usePage } from '@inertiajs/react';
import { Building2 } from 'lucide-react';
import { Pagination } from '@/components/pagination';
import { SettledList } from '@/components/settled-list';
import { Button, buttonLook } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { DateField } from '@/components/ui/date-field';
import { PageHeader } from '@/components/ui/page-header';
import { Field } from '@/components/ui/field';
import { TextInput } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { Table, TableBody, TableHead, TableRow, Td, Th } from '@/components/ui/table';
import type { TenantClock } from '@/lib/format';
import { keepTyped, useAnsweredBar, usePartialRead } from '@/lib/partial-read';
import { cn } from '@/lib/utils';
import type { ConsoleProps } from '@/types';
import { NO_PLAN, PLAN_WORDS, type PlanName, planWord, STATE_WORDS, STATUS_WORDS, type SubscriptionState, type TenantPlan, type TenantStatus } from './vocabulary';

/*
 * The tenant list, the console's home: every tenant newest signup first, twenty-five a page by the
 * admin's pagination, inside the console's shell. Each row is the slug, a link to the tenant's
 * detail at the path the server sent, a closed row's slug reading Released since the drop released
 * it, the name, the status, the plan the server derived, the subscription state, each in words from
 * the console's vocabulary maps, one per case, and the signup instant as the server spelled it on
 * the console's clock, which the shell names. Floor and staff are the catalogue's plans and read by
 * the names the server sends from it, Solo and Team from config/billing.php, in the column and the
 * filter alike; trial, free and unlisted are the page's own words.
 *
 * The filter bar holds what was asked: a search over the slug and the name, a select each for the
 * status, the subscription state and the plan, and two dates read on the console's clock, To
 * inclusive; once a read lands it is brought into line with what the server read, a pair of dates
 * the server dropped cleared and the search kept as typed. The query string is the list's whole
 * state, which the server reads, and nothing is kept in the tab's memory. Every change is a partial
 * reload of the list on its first page, and nothing is optimistic: the rows on screen are the
 * server's last answer, Clear stands while the server's answer names a filter, and a read that
 * fails says so above the rows it keeps. New tenant beside the heading opens the free tenant's
 * form.
 *
 * The bar's card is a query container and lays its six filters out by its own width, not the
 * window's: one column, then two, then three, and all six in one row once the card is wide.
 */

export type TenantRow = {
    /** The slug, or null on a closed row, whose slug the drop released. */
    slug: string | null;
    /** The path of the tenant's detail: by its slug, or by its id on a closed row. */
    detail: string;
    name: string;
    status: TenantStatus;
    /** The plan the server derived, or null while the row is provisioning. */
    plan: TenantPlan | null;
    state: SubscriptionState;
    /** The signup instant, spelled by the server on the console's clock. */
    signed_up: string;
};

/** The filters as the server read them, an unset one the empty string; the two dates YYYY-MM-DD. */
export type TenantFilters = { search: string; status: string; state: string; plan: string; from: string; to: string };

type TenantPage = { rows: TenantRow[]; total: number; page: number; page_size: number };

type Props = ConsoleProps & {
    filters: TenantFilters;
    /** The catalogue's plans by name, in its order; none where nobody can name them. */
    plan_names: PlanName[];
    tenants: TenantPage;
};

const LIST = '/';

/** What a filter or a page reads again: the rows and the filters they answer. */
const LIST_PROPS = ['tenants', 'filters'];

const TENANTS_TITLE = 'Tenants';

/** The free tenant's form. */
const NEW_TENANT_PATH = '/tenants/new';

const NEW_TENANT = 'New tenant';

const NO_FILTERS: TenantFilters = { search: '', status: '', state: '', plan: '', from: '', to: '' };

/** The console's clock for its date fields: the zone the server shares, and the house's spellings. */
function consoleClock(zone: string): TenantClock {
    return { timezone: zone, dateFormat: 'd/m/Y', timeFormat: 'H:i' };
}

/** The console's week, which no setting moves, starts on a Monday. */
const WEEK_START = 1;

/** What the plan filter offers, in the vocabulary's order: trial and free, the catalogue's plans by name, then unlisted. */
function planChoices(names: PlanName[]): Record<string, string> {
    return { trial: PLAN_WORDS.trial, free: PLAN_WORDS.free, ...Object.fromEntries(names.map(({ plan, name }) => [plan, name])), unlisted: PLAN_WORDS.unlisted };
}

/** What a closed row's slug reads as: the drop released it for a new signup. */
export const RELEASED = 'Released';

export const NO_TENANTS = 'No tenants yet';

export const NO_TENANTS_SENTENCE = 'Every business that signs up or is provisioned appears here.';

export const NO_TENANTS_MATCH = 'No tenants match these filters.';

export const NO_TENANTS_ON_PAGE = 'No tenants on this page.';

export const TENANTS_LIST_ERROR = 'The tenants could not be loaded. Check your connection and change a filter to try again.';

const CLEAR = 'Clear filters';

const CLEARING = 'Clearing filters';

const HEADINGS = ['Slug', 'Name', 'Status', 'Plan', 'Subscription', 'Signed up'] as const;

const [SLUG_HEADING, NAME_HEADING, STATUS_HEADING, PLAN_HEADING, STATE_HEADING, SIGNED_UP_HEADING] = HEADINGS;

function choices<T extends string>(words: Record<T, string>, any: string): { value: string; label: string }[] {
    return [{ value: '', label: any }, ...Object.entries<string>(words).map(([value, label]) => ({ value, label }))];
}

/** The query string of the filters and a page, the unset ones and the first page left out, so the whole list is the bare screen. */
function tenantQuery(filters: TenantFilters, page: number): Record<string, string> {
    const query: Record<string, string> = {};

    for (const [key, value] of Object.entries(filters)) {
        if (value !== '') {
            query[key] = value;
        }
    }

    if (page > 1) {
        query['page'] = String(page);
    }

    return query;
}

/**
 * The bar as the server's answer settles it: the search as typed where the server read it
 * trimmed, and every other field as the server read it, a pair of dates it dropped among them.
 */
function answered(bar: TenantFilters, served: TenantFilters): TenantFilters {
    return { ...served, search: keepTyped(bar.search, served.search) };
}

function filtered(filters: TenantFilters): boolean {
    return Object.values(filters).some((value) => value !== '');
}

export default function Tenants() {
    const { props } = usePage<Props>();
    const list = usePartialRead(LIST_PROPS);
    const [filters, setFilters] = useAnsweredBar<TenantFilters>(props.filters, props.filters, list.loading, (bar) => answered(bar, props.filters));
    const clock = consoleClock(props.zone);
    const rows = props.tenants.rows;

    const load = (next: TenantFilters, page: number) => list.read(LIST, tenantQuery(next, page));

    const set = (patch: Partial<TenantFilters>) => {
        const next = { ...filters, ...patch };

        setFilters(next);
        load(next, 1);
    };

    const clear = () => {
        setFilters(NO_FILTERS);
        load(NO_FILTERS, 1);
    };

    const emptySentence = props.tenants.total > 0 ? NO_TENANTS_ON_PAGE : filtered(props.filters) ? NO_TENANTS_MATCH : null;

    return (
        <>
            <Head title={TENANTS_TITLE} />
            <div className="admin-tenants-screen flex flex-col gap-4">
                <PageHeader
                    title={TENANTS_TITLE}
                    actions={
                        <Link href={NEW_TENANT_PATH} className={cn('admin-tenants-new', buttonLook('primary'))}>
                            {NEW_TENANT}
                        </Link>
                    }
                />
                <Card className="admin-filter-bar admin-tenants-filters @container flex flex-col gap-3" aria-label="Filters">
                    <div className="admin-filter-bar__fields grid grid-cols-1 gap-3 @xl:grid-cols-2 @3xl:grid-cols-3 @5xl:grid-cols-6">
                        <Field id="tenants-search" label="Search">
                            {(control) => <TextInput {...control} type="search" value={filters.search} placeholder="Slug or name" onChange={(event) => set({ search: event.target.value })} />}
                        </Field>
                        <Field id="tenants-status" label="Status">
                            {(control) => <Select {...control} options={choices(STATUS_WORDS, 'Any status')} value={filters.status} onChange={(status) => set({ status })} />}
                        </Field>
                        <Field id="tenants-state" label="Subscription">
                            {(control) => <Select {...control} options={choices(STATE_WORDS, 'Any state')} value={filters.state} onChange={(state) => set({ state })} />}
                        </Field>
                        <Field id="tenants-plan" label="Plan">
                            {(control) => <Select {...control} options={choices(planChoices(props.plan_names), 'Any plan')} value={filters.plan} onChange={(plan) => set({ plan })} />}
                        </Field>
                        <Field id="tenants-from" label="Signed up from">
                            {(control) => <DateField {...control} className="admin-tenants-from" value={filters.from} clock={clock} weekStart={WEEK_START} onChange={(from) => set({ from })} />}
                        </Field>
                        <Field id="tenants-to" label="Signed up to">
                            {(control) => <DateField {...control} className="admin-tenants-to" value={filters.to} clock={clock} weekStart={WEEK_START} onChange={(to) => set({ to })} />}
                        </Field>
                    </div>
                    {filtered(props.filters) && (
                        <div className="flex">
                            <Button variant="link" className="admin-tenants-clear" busy={list.loading} busyLabel={CLEARING} onClick={clear}>
                                {CLEAR}
                            </Button>
                        </div>
                    )}
                </Card>
                <SettledList
                    count={rows.length}
                    state={{ loading: list.loading, error: list.error }}
                    errorSentence={TENANTS_LIST_ERROR}
                    empty={
                        <Card flush>
                            <EmptyState className="admin-tenants-empty" icon={<Building2 />}>
                                {emptySentence === null ? (
                                    <>
                                        <p className="text-foreground">{NO_TENANTS}</p>
                                        <p>{NO_TENANTS_SENTENCE}</p>
                                    </>
                                ) : (
                                    emptySentence
                                )}
                            </EmptyState>
                        </Card>
                    }
                >
                    <Card flush className="admin-card admin-tenants" aria-labelledby="tenants-head">
                        <header className="flex items-center justify-between border-b px-4 py-3">
                            <h2 id="tenants-head" className="font-semibold">
                                {TENANTS_TITLE}
                            </h2>
                            <p className="admin-tenants__count text-sm text-muted-foreground">
                                {rows.length} of {props.tenants.total}
                            </p>
                        </header>
                        <Table>
                            <TableHead>
                                <TableRow>
                                    {HEADINGS.map((heading) => (
                                        <Th key={heading}>{heading}</Th>
                                    ))}
                                </TableRow>
                            </TableHead>
                            <TableBody>
                                {rows.map((row) => (
                                    <TableRow key={row.detail} className="admin-tenants__row">
                                        <Td label={SLUG_HEADING} header>
                                            <Link href={row.detail} className="admin-tenants__slug font-medium underline-offset-4 hover:underline">
                                                {row.slug ?? RELEASED}
                                            </Link>
                                        </Td>
                                        <Td label={NAME_HEADING} className="admin-tenants__name">
                                            {row.name}
                                        </Td>
                                        <Td label={STATUS_HEADING} className="admin-tenants__status">
                                            {STATUS_WORDS[row.status]}
                                        </Td>
                                        <Td label={PLAN_HEADING} className="admin-tenants__plan">
                                            {row.plan === null ? NO_PLAN : planWord(row.plan, props.plan_names)}
                                        </Td>
                                        <Td label={STATE_HEADING} className="admin-tenants__state">
                                            {STATE_WORDS[row.state]}
                                        </Td>
                                        <Td label={SIGNED_UP_HEADING} className="admin-tenants__signed-up">
                                            {row.signed_up}
                                        </Td>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                        <Pagination page={props.tenants.page} total={props.tenants.total} pageSize={props.tenants.page_size} onPage={(page) => load(filters, page)} />
                    </Card>
                </SettledList>
            </div>
        </>
    );
}
