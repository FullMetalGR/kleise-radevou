import { Head, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { List, Mail } from 'lucide-react';
import { BookingDialog } from '@/bookings/BookingDialog';
import { BookingsTable } from '@/bookings/BookingsTable';
import { type Detail, type ListPage, staffShown } from '@/bookings/model';
import { useOpenedDetail } from '@/bookings/opened-detail';
import { BookingsProvider, bookingsWordsOf } from '@/bookings/words';
import { Initials } from '@/components/initials';
import { SettledList } from '@/components/settled-list';
import { Breadcrumb } from '@/components/ui/breadcrumb';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { Notice } from '@/components/ui/notice';
import { PageHeader } from '@/components/ui/page-header';
import { CustomerDangerZone, type Erasure } from '@/customers/CustomerDangerZone';
import { CustomerBlockCard } from '@/customers/CustomerBlockCard';
import { CustomerExport } from '@/customers/CustomerExport';
import { CustomerGlance } from '@/customers/CustomerGlance';
import { CustomerNotesCard } from '@/customers/CustomerNotesCard';
import { CustomerProfileCard } from '@/customers/CustomerProfileCard';
import { type Block, bookingCustomer, type Customer, CUSTOMERS_TITLE, type Note, type Profile, type Spent } from '@/customers/model';
import { TagChips } from '@/customers/TagChips';
import { type CustomersKey, CustomersProvider, customersWordsOf, useCustomers } from '@/customers/words';
import { clockOf } from '@/lib/format';
import { usePartialRead } from '@/lib/partial-read';
import { owning } from '@/lib/visits';
import { useShell } from '@/lib/strings';
import { cn } from '@/lib/utils';
import type { BookingsStrings, CustomersStrings, SharedProps } from '@/types';
import { useNewBooking } from '@/wizard/NewBooking';

/*
 * A customer's page: a real route, headed by the most recent name the address booked under, then
 * that address's bookings through the Bookings table, in the Bookings screen's order and paged as
 * it is, the page in the URL. A row opens the booking dialog as the calendars open it: the page's
 * own state, asked for by a booking query parameter as a partial reload of the detail prop alone
 * with the URL preserved, so a reload lands on the page with no dialog. The dialog's actions post
 * the one way every screen with the dialog posts them, useActs; the server's redirect lands this
 * page again with every prop re-read, and the page then reads the detail again for the booking it
 * still has open, the one rule the calendars follow, with the dialog's state held by the hook they
 * hold theirs by, useOpenedDetail. Beside the heading, for a viewer holding manageBookings, Export,
 * which downloads the customer's data, then Book now, which opens the wizard with the customer's
 * most recent name, the email and the most recent phone filled in and the Customer step answered;
 * the route requires manageBookings, so the wizard's catalog is never null here.
 *
 * The name is the page's one heading, in the header row. Under it the hero card on the accent
 * fill: the customer's initials at their largest, then the address where the server showed it to
 * this viewer, then a row of the profile's tags and, where the address booked under other names,
 * "Also booked as" in the past chip's shape, the row left out where there is neither. Each chip
 * stands on the card's surface, so it still reads as a pill on the fill, and every chip wraps
 * inside the card, a tag with no space in it as well as the other names however many there are. The
 * hero never repeats the name. Then the cards, which ask the page's own width rather than the
 * window's: a main column of At a glance, the bookings and, for a viewer holding manageBookings,
 * the Notes card; and for the same viewer a side column at the right of it, from a page wide enough
 * for both, of the Profile card, mounted afresh whenever the server's profile changes so its fields
 * read what was stored, and the Online booking card, mounted afresh whenever the block changes so
 * its Reason field starts empty. A narrower page stands the side column's cards under the main
 * column's, and a viewer without the side column has the main one at the whole width. Last, for the
 * same viewer, the Danger zone that erases the customer, its dialog read off the erasure prop.
 * Nothing is optimistic, and every visit owns its failure. The page's own words are keys of the
 * customers section, which CustomerController names beside the bookings section the table and the
 * dialog read.
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

/**
 * The cards' two columns, drawn only where there is a side column to hold: the main one takes
 * what the side one's fixed width leaves, from a page wide enough for both.
 */
const SIDE_COLUMN = '@4xl:grid-cols-[minmax(0,1fr)_25rem]';

/** The profile as the server last stored it, the Profile card's key: a new answer mounts it afresh. */
function profileKey(profile: Profile): string {
    return JSON.stringify([profile.tags, profile.birthday, profile.language]);
}

/** The hero card under the heading: the initials, then the address and the chips where there are any. */
function CustomerHero({ customer }: { customer: Customer }) {
    const own = useCustomers();
    const others = customer.names.slice(1);
    const email = customer.email;
    const chips = customer.tags.length > 0 || others.length > 0;

    return (
        <Card className="admin-customer-hero flex min-w-0 items-center gap-5 bg-accent">
            <Initials name={customer.name} size="hero" />
            {(email !== undefined || chips) && (
                <div className="flex min-w-0 flex-col gap-2.5">
                    {email !== undefined && (
                        <p className="admin-customer-head__email flex min-w-0 items-center gap-2 text-[0.96875rem] font-semibold wrap-anywhere text-muted-foreground">
                            <Mail aria-hidden="true" className="size-4 shrink-0" />
                            {email}
                        </p>
                    )}
                    {chips && (
                        <div className="admin-customer-head__chips flex flex-wrap gap-1.5 *:bg-card *:wrap-anywhere">
                            <TagChips tags={customer.tags} />
                            {others.length > 0 && <span className="admin-chip admin-chip--past admin-customer-head__also whitespace-normal">{own.text(ALSO_BOOKED_AS, { names: others.join(', ') })}</span>}
                        </div>
                    )}
                </div>
            )}
        </Card>
    );
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

    return (
        <CustomersProvider words={own}>
            <BookingsProvider words={words}>
                <Head title={props.customer.name} />
                <div className="admin-customer-screen @container flex flex-col gap-4.5">
                    <div className="admin-customer-head flex flex-col">
                        <PageHeader
                            breadcrumb={<Breadcrumb label={own.text('customer.breadcrumb')} items={[{ label: own.text(CUSTOMERS_TITLE), href: LIST, onClick: leave }, { label: props.customer.name }]} />}
                            title={props.customer.name}
                            actions={
                                manage ? (
                                    <div className="admin-customer-head__actions flex flex-wrap gap-2">
                                        <CustomerExport url={props.customer.url} />
                                        <Button variant="primary" className="admin-customer-book" onClick={() => wizard.open({ customer: bookingCustomer(props.customer) })}>
                                            {own.text(BOOK_NOW_LABEL)}
                                        </Button>
                                    </div>
                                ) : undefined
                            }
                        />
                        <CustomerHero customer={props.customer} />
                    </div>
                    {leaveFailed && (
                        <Notice kind="error" className="admin-customer-leave-error">
                            {own.text(LEAVE_ERROR)}
                        </Notice>
                    )}
                    <div className={cn('admin-customer-cards grid grid-cols-1 items-start gap-4.5', manage && SIDE_COLUMN)}>
                        <div className="admin-customer-main flex min-w-0 flex-col gap-4.5">
                            <CustomerGlance customer={props.customer} spent={props.spent} clock={clock} />
                            <SettledList
                                count={rows.length}
                                state={{ loading: list.loading, error: list.error }}
                                errorSentence={own.text(BOOKINGS_ERROR)}
                                empty={
                                    <Card flush>
                                        <EmptyState className="admin-customer-empty" icon={<List />}>
                                            {own.text(BOOKINGS_EMPTY)}
                                        </EmptyState>
                                    </Card>
                                }
                            >
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
                            {manage && <CustomerNotesCard url={props.customer.url} notes={props.notes} clock={clock} />}
                        </div>
                        {manage && (
                            <div className="admin-customer-side flex min-w-0 flex-col gap-4.5">
                                <CustomerProfileCard key={profileKey(props.profile)} url={props.customer.url} profile={props.profile} earliest={props.earliest_birthday} clock={clock} weekStart={props.settings.week_start} />
                                <CustomerBlockCard key={props.block.blocked_at ?? 'open'} url={props.customer.url} block={props.block} clock={clock} />
                            </div>
                        )}
                    </div>
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
