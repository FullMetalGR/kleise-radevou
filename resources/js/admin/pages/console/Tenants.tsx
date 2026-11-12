import { Head, Link, usePage } from '@inertiajs/react';
import { Pagination } from '@/components/pagination';
import { SettledList } from '@/components/settled-list';
import { Button } from '@/components/ui/button';
import { DateField } from '@/components/ui/date-field';
import { Field } from '@/components/ui/field';
import { TextInput } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { TableScroll } from '@/components/ui/table-scroll';
import type { TenantClock } from '@/lib/format';
import { keepTyped, useAnsweredBar, usePartialRead } from '@/lib/partial-read';
import type { ConsoleProps } from '@/types';
import { NO_PLAN, PLAN_WORDS, type PlanName, planWord, STATE_WORDS, STATUS_WORDS, type SubscriptionState, type TenantPlan, type TenantStatus } from './vocabulary';

/*
 * The tenant list, the console's home: every tenant newest signup first, twenty-five a page by the
 * admin's pagination, inside the console's shell. Each row is the slug, a link to the tenant's
 * detail at the path the server sent, a closed row's slug reading Released since the drop released
 * it, the name, the status, the plan the server derived, the subscription state, each in words from
 * the console's vocabulary maps, one per case, and the signup instant as the server spelled it on
 * the console's clock, which the top bar names. Floor and staff are the catalogue's plans and read
 * by the names the server sends from it, Solo and Team from config/billing.php, in the column and
 * the filter alike; trial, free and unlisted are the page's own words.
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

const HEADINGS = ['Slug', 'Name', 'Status', 'Plan', 'Subscription', 'Signed up'];

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
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <h1 className="text-2xl font-semibold">{TENANTS_TITLE}</h1>
                    <Link href={NEW_TENANT_PATH} className="admin-tenants-new inline-flex h-8 items-center rounded-lg bg-primary px-3 text-sm font-medium text-primary-foreground hover:bg-primary/85">
                        {NEW_TENANT}
                    </Link>
                </div>
                <section className="admin-filter-bar admin-tenants-filters flex flex-col gap-3 rounded-xl border p-4" aria-label="Filters">
                    <div className="admin-filter-bar__fields grid grid-cols-1 gap-3 desktop:grid-cols-3 xl:grid-cols-6">
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
                </section>
                <SettledList
                    count={rows.length}
                    state={{ loading: list.loading, error: list.error }}
                    errorSentence={TENANTS_LIST_ERROR}
                    empty={
                        <div className="admin-tenants-empty rounded-xl border p-6 text-center">
                            {emptySentence === null ? (
                                <>
                                    <p className="font-medium">{NO_TENANTS}</p>
                                    <p className="text-muted-foreground">{NO_TENANTS_SENTENCE}</p>
                                </>
                            ) : (
                                <p className="text-muted-foreground">{emptySentence}</p>
                            )}
                        </div>
                    }
                >
                    <section className="admin-card admin-tenants rounded-xl border" aria-labelledby="tenants-head">
                        <header className="flex items-center justify-between border-b px-4 py-3">
                            <h2 id="tenants-head" className="font-semibold">
                                {TENANTS_TITLE}
                            </h2>
                            <p className="admin-tenants__count text-sm text-muted-foreground">
                                {rows.length} of {props.tenants.total}
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
                                    {rows.map((row) => (
                                        <tr key={row.detail} className="admin-tenants__row border-t">
                                            <td className="px-4 py-2">
                                                <Link href={row.detail} className="admin-tenants__slug font-medium underline-offset-4 hover:underline">
                                                    {row.slug ?? RELEASED}
                                                </Link>
                                            </td>
                                            <td className="admin-tenants__name px-4 py-2">{row.name}</td>
                                            <td className="admin-tenants__status px-4 py-2">{STATUS_WORDS[row.status]}</td>
                                            <td className="admin-tenants__plan px-4 py-2">{row.plan === null ? NO_PLAN : planWord(row.plan, props.plan_names)}</td>
                                            <td className="admin-tenants__state px-4 py-2">{STATE_WORDS[row.state]}</td>
                                            <td className="admin-tenants__signed-up px-4 py-2">{row.signed_up}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </TableScroll>
                        <Pagination page={props.tenants.page} total={props.tenants.total} pageSize={props.tenants.page_size} onPage={(page) => load(filters, page)} />
                    </section>
                </SettledList>
            </div>
        </>
    );
}
