import { Head, router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { SettledList } from '@/components/settled-list';
import { useActs } from '@/bookings/acting';
import { BookingDialog, type DialogState } from '@/bookings/BookingDialog';
import { BookingsTable } from '@/bookings/BookingsTable';
import { FilterBar } from '@/bookings/FilterBar';
import { applicable, type Detail, FILTER_SHAPE, type Filters, isUnfiltered, type ListPage, MEMORY_KEY, NO_FILTERS, type Options, queryOf, staffShown } from '@/bookings/model';
import { type BookingsKey, BookingsProvider, bookingsWordsOf } from '@/bookings/words';
import { clockOf } from '@/lib/format';
import { usePartialRead } from '@/lib/partial-read';
import { readMemory, writeMemory } from '@/lib/session-memory';
import { owning } from '@/lib/visits';
import { useShell } from '@/lib/strings';
import type { BookingsStrings, SharedProps } from '@/types';

/*
 * The Bookings screen, with the booking dialog as a route of its own, 1.1: /admin/bookings/{uuid}
 * is this page with the dialog open. The filters live in the query string, which the server
 * reads, and in sessionStorage per tab, 1.4; a page loaded with no filter in its query restores
 * the remembered bar, the page number never. Every change is a partial reload of the list and its
 * query, the one read the customers screens make of theirs, usePartialRead, and nothing is
 * optimistic: the rows on screen are the server's last answer. Opening and closing the dialog are
 * visits of their own, since each moves to the other route and the browser's history keeps it,
 * where the list's read replaces the URL it is at. Every visit the page makes owns its failure: a
 * failed load shows the list's error notice, a failed open, close or action a notice inside the
 * dialog. An action's answer, a success or a refusal in the server's own sentence, arrives as the
 * flash the toaster shows; the actions themselves are posted the one way every screen with the
 * dialog posts them, useActs.
 */

type Props = SharedProps & {
    filters: Filters & { page: number };
    options: Options;
    bookings: ListPage;
    detail: Detail | null;
    strings: SharedProps['strings'] & { bookings?: BookingsStrings };
};

const LIST = '/admin/bookings';

const LIST_ERROR: BookingsKey = 'page.list_error';

/** What a filter change or a page reads again: the rows and the query they answer. */
const LIST_PROPS = ['bookings', 'filters'];

const CLOSE_ERROR: BookingsKey = 'page.close_error';

function sessionStorageOf(): Storage {
    return window.sessionStorage;
}

function barOf(filters: Props['filters']): Filters {
    return { from: filters.from, to: filters.to, status: filters.status, staff: filters.staff, service: filters.service, search: filters.search };
}

function search(): string {
    return window.location.search;
}

export default function Bookings() {
    const { props } = usePage<Props>();
    const clock = clockOf(props);
    const words = bookingsWordsOf(props.strings, useShell().language);
    const [bar, setBar] = useState<Filters>(() => barOf(props.filters));
    const list = usePartialRead(LIST_PROPS);
    const [opening, setOpening] = useState<DialogState | null>(null);
    const [closeFailed, setCloseFailed] = useState(false);
    const acts = useActs(words);
    const restored = useRef(false);
    const shown = staffShown(props.entitlements, props.options.members, bar.staff);

    const load = (filters: Filters, page: number) => list.read(window.location.pathname, queryOf(filters, page));

    const change = (filters: Filters) => {
        setBar(filters);
        writeMemory(MEMORY_KEY, filters, sessionStorageOf);
        load(filters, 1);
    };

    useEffect(() => {
        if (restored.current) {
            return;
        }

        restored.current = true;

        if (!isUnfiltered(barOf(props.filters))) {
            writeMemory(MEMORY_KEY, barOf(props.filters), sessionStorageOf);

            return;
        }

        const remembered = applicable(readMemory(MEMORY_KEY, FILTER_SHAPE, NO_FILTERS, sessionStorageOf), props.entitlements, props.options.members);

        if (!isUnfiltered(remembered)) {
            setBar(remembered);
            load(remembered, 1);
        }
    });

    const open = (uuid: string) => {
        const owned = owning(() => setOpening({ kind: 'error' }));

        setOpening({ kind: 'loading' });
        setCloseFailed(false);
        acts.clear();
        router.get(`${LIST}/${encodeURIComponent(uuid)}${search()}`, {}, { only: ['detail'], preserveScroll: true, ...owned });
    };

    const close = () => {
        const owned = owning(() => setCloseFailed(true));

        setOpening(null);
        setCloseFailed(false);
        acts.clear();
        router.get(`${LIST}${search()}`, {}, { only: ['detail'], preserveScroll: true, ...owned });
    };

    const dialog: DialogState | null = props.detail !== null ? { kind: 'detail', detail: props.detail } : opening;
    const rows = props.bookings.rows;

    return (
        <BookingsProvider words={words}>
            <Head title={words.text('page.title')} />
            <div className="admin-bookings-screen flex flex-col gap-4">
                <h1 className="text-2xl font-semibold">{words.text('page.title')}</h1>
                <FilterBar filters={bar} options={props.options} staffShown={shown} clock={clock} weekStart={props.settings.week_start} onChange={change} />
                <SettledList
                    count={rows.length}
                    state={{ loading: list.loading, error: list.error }}
                    errorSentence={words.text(LIST_ERROR)}
                    empty={<p className="admin-bookings-empty rounded-xl border p-6 text-center text-muted-foreground">{words.text('page.empty')}</p>}
                >
                    <BookingsTable
                        rows={rows}
                        total={props.bookings.total}
                        page={props.bookings.page}
                        pageSize={props.bookings.page_size}
                        staffShown={shown}
                        selected={props.detail?.state === 'found' ? props.detail.booking.uuid : null}
                        clock={clock}
                        onSelect={open}
                        onPage={(page) => load(bar, page)}
                    />
                </SettledList>
                {dialog !== null && (
                    <BookingDialog
                        state={dialog}
                        staffShown={shown}
                        clock={clock}
                        weekStart={props.settings.week_start}
                        onClose={close}
                        failure={closeFailed ? words.text(CLOSE_ERROR) : acts.failure}
                        busy={acts.busy}
                        onAct={props.detail?.state === 'found' ? acts.act(props.detail.booking.uuid) : undefined}
                    />
                )}
            </div>
        </BookingsProvider>
    );
}
