import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { SettledList } from '@/components/settled-list';
import { Field } from '@/components/ui/field';
import { TextInput } from '@/components/ui/input';
import { Notice } from '@/components/ui/notice';
import { CustomersDownload } from '@/customers/CustomersDownload';
import { CustomersTable } from '@/customers/CustomersTable';
import { type Customer, type CustomerPage, type CustomerQuery, customerQuery, CUSTOMERS_TITLE } from '@/customers/model';
import { type CustomersKey, CustomersProvider, customersWordsOf } from '@/customers/words';
import { clockOf } from '@/lib/format';
import { keepTyped, useAnsweredBar, usePartialRead } from '@/lib/partial-read';
import { useShell } from '@/lib/strings';
import { owning } from '@/lib/visits';
import type { CustomersStrings, SharedProps } from '@/types';

/*
 * The Customers screen: one row per address the bookings know, read-only, under the settledness
 * gate. The search and the page live in the query string, which the server reads, and nowhere
 * else: a reload or a bookmark lands on the same answer and the screen remembers nothing of its
 * own; the search box follows each answer once it lands, kept as typed where the server read it
 * trimmed. Every change is a partial reload of the list, and nothing is optimistic: the rows on
 * screen are the server's last answer. A row opens the customer's page, a real route. Every visit
 * owns its failure. Beside the search, Download CSV takes the list under the search answered.
 * Every word is a key of the customers section, which CustomerController names beside the
 * shell's.
 */

type Props = SharedProps & {
    filters: CustomerQuery;
    /** The customer columns the search reads for this viewer, the server's whitelist decision. */
    searchable: string[];
    customers: CustomerPage;
    /** The customers section beside the shell's. */
    strings: SharedProps['strings'] & { customers?: CustomersStrings };
};

const LIST = '/admin/customers';

/** What a search or a page reads again: the rows and the query they answer. */
const LIST_PROPS = ['customers', 'filters'];

export const SEARCH_LABEL = 'page.search' satisfies CustomersKey;

export const SEARCH_HINT_CONTACT = 'page.hint_contact' satisfies CustomersKey;

export const SEARCH_HINT_NAME = 'page.hint_name' satisfies CustomersKey;

export const NO_CUSTOMERS = 'page.none' satisfies CustomersKey;

export const NO_CUSTOMERS_SENTENCE = 'page.none_sentence' satisfies CustomersKey;

export const NO_MATCH = 'page.no_match' satisfies CustomersKey;

export const LIST_ERROR = 'page.list_error' satisfies CustomersKey;

export const OPEN_ERROR = 'page.open_error' satisfies CustomersKey;

export default function Customers() {
    const { props } = usePage<Props>();
    const clock = clockOf(props);
    const words = customersWordsOf(props.strings, useShell().language);
    const list = usePartialRead(LIST_PROPS);
    const [search, setSearch] = useAnsweredBar(props.filters, props.filters.search, list.loading, (typed) => keepTyped(typed, props.filters.search));
    const [openFailed, setOpenFailed] = useState(false);
    const load = (text: string, page: number) => list.read(LIST, customerQuery(text, page));

    const open = (customer: Customer) => {
        const owned = owning(() => setOpenFailed(true));

        setOpenFailed(false);
        router.get(customer.url, {}, owned);
    };

    const rows = props.customers.rows;
    const searched = props.filters.search !== '';

    return (
        <CustomersProvider words={words}>
            <Head title={words.text(CUSTOMERS_TITLE)} />
            <div className="admin-customers-screen flex flex-col gap-4">
                <h1 className="text-2xl font-semibold">{words.text(CUSTOMERS_TITLE)}</h1>
                <div className="admin-customers-bar flex flex-wrap items-end justify-between gap-3">
                    <Field id="customers-search" label={words.text(SEARCH_LABEL)} className="admin-customers-search w-full max-w-sm">
                        {(control) => (
                            <TextInput
                                {...control}
                                type="search"
                                value={search}
                                placeholder={words.text(props.searchable.includes('customer_email') ? SEARCH_HINT_CONTACT : SEARCH_HINT_NAME)}
                                onChange={(event) => {
                                    setSearch(event.target.value);
                                    load(event.target.value, 1);
                                }}
                            />
                        )}
                    </Field>
                    <CustomersDownload search={props.filters.search} />
                </div>
                {openFailed && (
                    <Notice kind="error" className="admin-customers-open-error">
                        {words.text(OPEN_ERROR)}
                    </Notice>
                )}
                <SettledList
                    count={rows.length}
                    state={{ loading: list.loading, error: list.error }}
                    errorSentence={words.text(LIST_ERROR)}
                    empty={
                        <div className="admin-customers-empty rounded-xl border p-6 text-center">
                            {searched ? (
                                <p className="text-muted-foreground">{words.text(NO_MATCH)}</p>
                            ) : (
                                <>
                                    <p className="font-medium">{words.text(NO_CUSTOMERS)}</p>
                                    <p className="text-muted-foreground">{words.text(NO_CUSTOMERS_SENTENCE)}</p>
                                </>
                            )}
                        </div>
                    }
                >
                    <CustomersTable rows={rows} total={props.customers.total} page={props.customers.page} pageSize={props.customers.page_size} clock={clock} onSelect={open} onPage={(page) => load(search, page)} />
                </SettledList>
            </div>
        </CustomersProvider>
    );
}
