import { Head, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { BookingDialog } from '@/bookings/BookingDialog';
import { BookingsTable } from '@/bookings/BookingsTable';
import { type Detail, type ListPage, staffShown } from '@/bookings/model';
import { useOpenedDetail } from '@/bookings/opened-detail';
import { BookingsProvider, bookingsWordsOf } from '@/bookings/words';
import { SettledList } from '@/components/settled-list';
import { Button } from '@/components/ui/button';
import { Notice } from '@/components/ui/notice';
import { CustomerDangerZone, type Erasure } from '@/customers/CustomerDangerZone';
import { CustomerBlockCard } from '@/customers/CustomerBlockCard';
import { CustomerExport } from '@/customers/CustomerExport';
import { CustomerGlance } from '@/customers/CustomerGlance';
import { CustomerNotesCard } from '@/customers/CustomerNotesCard';
import { CustomerProfileCard } from '@/customers/CustomerProfileCard';
import { type Block, bookingCustomer, type Customer, CUSTOMERS_TITLE, type Note, type Profile, type Spent } from '@/customers/model';
import { TagChips } from '@/customers/TagChips';
import { type CustomersKey, CustomersProvider, customersWordsOf } from '@/customers/words';
import { clockOf } from '@/lib/format';
import { usePartialRead } from '@/lib/partial-read';
import { owning } from '@/lib/visits';
import { useShell } from '@/lib/strings';
import type { BookingsStrings, CustomersStrings, SharedProps } from '@/types';
import { useNewBooking } from '@/wizard/NewBooking';

/*
 * A customer's page: a real route, headed by the most recent name the address booked under with
 * the address under it where the server showed it to this viewer, then that address's bookings
 * through the Bookings table, in the Bookings screen's order and paged as it is, the page in the
 * URL. A row opens the booking dialog as the calendars open it: the page's own state, asked for by
 * a booking query parameter as a partial reload of the detail prop alone with the URL preserved,
 * so a reload lands on the page with no dialog. The dialog's actions post the one way every screen
 * with the dialog posts them, useActs; the server's redirect lands this page again with every prop
 * re-read, and the page then reads the detail again for the booking it still has open, the one
 * rule the calendars follow, with the dialog's state held by the hook they hold theirs by,
 * useOpenedDetail. Beside the heading, for a viewer holding manageBookings, Export, which
 * downloads the customer's data; under the bookings, for the same viewer, the Danger zone that
 * erases the customer, its dialog read off the erasure prop. Under the head, the strip "At a
 * glance" of five tiles off the customer's row, and beside Export, for the same viewer, Book now,
 * which opens the wizard with the customer's most recent name, the email and the most recent phone
 * filled in and the Customer step answered; the route requires manageBookings, so the wizard's
 * catalog is never null here. The head: the name, the address beneath, then a row of the profile's
 * tags and, where the address booked under other names, "Also booked as" in the past chip's shape;
 * at the right Export, then Book now. Under it a grid of cards, At a glance with its five figures
 * and the Spent line and, for the same viewer, the Profile card, mounted afresh whenever the
 * server's profile changes so its fields read what was stored; then, for the same viewer, the
 * Notes card and the Online booking card, the latter mounted afresh whenever the block changes so
 * its Reason field starts empty. Nothing is optimistic, and every visit owns its failure. The
 * page's own words are keys of the customers section, which CustomerController names beside the
 * bookings section the table and the dialog read.
 */

type Props = SharedProps & {
    customer: Customer;
    /** How many staff rows the tenant has, the rows half of the one-value rule. */
    members: number;
    bookings: ListPage;
    detail: Detail | null;
    /** Whether an erasure would be refused now, and the sentence it would be refused with. */
    erasure: Erasure;
    /** What the Profile card edits. */
    profile: Profile;
    /**
     * The earliest birthday the server takes, a machine date, where Birthday's calendar
     * starts.
     */
    earliest_birthday: string;
    /** What the address's visits came to in each currency, the Spent line. */
    spent: Spent[];
    /** The staff's notes on the address, newest first. */
    notes: Note[];
    /** The block on the address's online booking. */
    block: Block;
    /** The bookings section beside the shell's, which the table and the dialog read. */
    strings: SharedProps['strings'] & { bookings?: BookingsStrings; customers?: CustomersStrings };
};

const LIST = '/admin/customers';

/** What a page of the bookings reads again. */
const BOOKINGS_PROPS = ['bookings'];

export const BOOKINGS_ERROR = 'customer.bookings_error' satisfies CustomersKey;

export const BOOKINGS_EMPTY = 'customer.bookings_empty' satisfies CustomersKey;

export const BOOK_NOW_LABEL = 'customer.book_now' satisfies CustomersKey;

export const LEAVE_ERROR = 'customer.leave_error' satisfies CustomersKey;

/** The other names the address booked under, as the list's name cell says them. */
const ALSO_BOOKED_AS = 'table.also_booked_as' satisfies CustomersKey;

/** The profile as the server last stored it, the Profile card's key: a new answer mounts it afresh. */
function profileKey(profile: Profile): string {
    return JSON.stringify([profile.tags, profile.birthday, profile.language]);
}

export default function CustomerPage() {
    const { props } = usePage<Props>();
    const clock = clockOf(props);
    const language = useShell().language;
    const words = bookingsWordsOf(props.strings, language);
    const own = customersWordsOf(props.strings, language);
    const shown = staffShown(props.entitlements, props.members, null);
    const list = usePartialRead(BOOKINGS_PROPS);
    const [leaveFailed, setLeaveFailed] = useState(false);
    const opening = useOpenedDetail(props.detail, words);
    const wizard = useNewBooking();
    const { opened, stale, failed, acts } = opening;

    useEffect(() => {
        if (opened === null || !stale) {
            return;
        }

        const owned = owning(failed);

        router.get(props.customer.url, { booking: opened }, { only: ['detail'], preserveState: true, preserveScroll: true, preserveUrl: true, replace: true, ...owned });
    }, [stale, opened, props.customer.url]);

    const page = (to: number) => list.read(props.customer.url, to > 1 ? { page: String(to) } : {});

    const leave = () => {
        const owned = owning(() => setLeaveFailed(true));

        setLeaveFailed(false);
        router.get(LIST, {}, owned);
    };

    const dialog = opening.dialog;
    const rows = props.bookings.rows;
    const manage = props.permissions.includes('manageBookings');
    const others = props.customer.names.slice(1);

    return (
        <CustomersProvider words={own}>
            <BookingsProvider words={words}>
                <Head title={props.customer.name} />
                <div className="admin-customer-screen flex flex-col gap-4">
                    <nav className="admin-breadcrumb text-sm text-muted-foreground" aria-label={own.text('customer.breadcrumb')}>
                        <a
                            href={LIST}
                            className="admin-breadcrumb__list underline"
                            onClick={(event) => {
                                event.preventDefault();
                                leave();
                            }}
                        >
                            {own.text(CUSTOMERS_TITLE)}
                        </a>
                        <span aria-hidden="true"> / </span>
                        <span className="admin-breadcrumb__current">{props.customer.name}</span>
                    </nav>
                    {leaveFailed && (
                        <Notice kind="error" className="admin-customer-leave-error">
                            {own.text(LEAVE_ERROR)}
                        </Notice>
                    )}
                    <header className="admin-customer-head flex flex-col gap-1">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <h1 className="text-2xl font-semibold">{props.customer.name}</h1>
                            {manage && (
                                <div className="admin-customer-head__actions flex flex-wrap gap-2">
                                    <CustomerExport url={props.customer.url} />
                                    <Button variant="primary" className="admin-customer-book" onClick={() => wizard.open({ customer: bookingCustomer(props.customer) })}>
                                        {own.text(BOOK_NOW_LABEL)}
                                    </Button>
                                </div>
                            )}
                        </div>
                        {props.customer.email !== undefined && <p className="admin-customer-head__email text-muted-foreground">{props.customer.email}</p>}
                        {(props.customer.tags.length > 0 || others.length > 0) && (
                            <div className="admin-customer-head__chips flex flex-wrap gap-1">
                                <TagChips tags={props.customer.tags} />
                                {others.length > 0 && <span className="admin-chip admin-chip--past admin-customer-head__also">{own.text(ALSO_BOOKED_AS, { names: others.join(', ') })}</span>}
                            </div>
                        )}
                    </header>
                    <div className="admin-customer-cards grid grid-cols-[repeat(auto-fit,minmax(min(440px,100%),1fr))] gap-4">
                        <CustomerGlance customer={props.customer} spent={props.spent} clock={clock} />
                        {manage && <CustomerProfileCard key={profileKey(props.profile)} url={props.customer.url} profile={props.profile} earliest={props.earliest_birthday} clock={clock} weekStart={props.settings.week_start} />}
                        {manage && <CustomerNotesCard url={props.customer.url} notes={props.notes} clock={clock} />}
                        {manage && <CustomerBlockCard key={props.block.blocked_at ?? 'open'} url={props.customer.url} block={props.block} clock={clock} />}
                    </div>
                    <SettledList count={rows.length} state={{ loading: list.loading, error: list.error }} errorSentence={own.text(BOOKINGS_ERROR)} empty={<p className="admin-customer-empty rounded-xl border p-6 text-center text-muted-foreground">{own.text(BOOKINGS_EMPTY)}</p>}>
                        <BookingsTable
                            rows={rows}
                            total={props.bookings.total}
                            page={props.bookings.page}
                            pageSize={props.bookings.page_size}
                            staffShown={shown}
                            plainNames={true}
                            selected={opened}
                            clock={clock}
                            onSelect={opening.open}
                            onPage={page}
                        />
                    </SettledList>
                    {manage && <CustomerDangerZone url={props.customer.url} bookings={props.customer.bookings} erasure={props.erasure} />}
                    {dialog !== null && (
                        <BookingDialog
                            state={dialog}
                            staffShown={shown}
                            clock={clock}
                            weekStart={props.settings.week_start}
                            onClose={opening.close}
                            failure={acts.failure}
                            busy={acts.busy}
                            onAct={props.detail?.state === 'found' && props.detail.booking.uuid === opened ? acts.act(props.detail.booking.uuid) : undefined}
                        />
                    )}
                </div>
            </BookingsProvider>
        </CustomersProvider>
    );
}
