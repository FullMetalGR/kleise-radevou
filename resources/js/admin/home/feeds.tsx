import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { AWAITING_APPROVAL, NO_FILTERS, queryOf } from '@/bookings/model';
import { StatusChip } from '@/components/ui/status-chip';
import { formatInstant, type TenantClock } from '@/lib/format';
import { listOf } from '@/lib/names';
import type { KeysOf } from '@/lib/strings';
import { Strip } from '@/figures/kit';
import type { HomeStrings } from '@/types';
import type { HomeWords } from './words';

/*
 * Home's three feeds: Next up, the next five live bookings; Awaiting approval, the requests on a
 * live hold, the rail's pending number in the attention chip and a link to the Bookings list's
 * Approval inbox in its header strip; and Recent activity, the trail's last five rows, each the
 * whole sentence the server named, with All bookings in its strip. Each row: who or what at the
 * left, the instant in mono in the tenant's formats at the right, the booking's services with
 * its members beneath, a request still awaiting approval chipped. A row naming a booking opens
 * the booking dialog on Home through onOpen. Every sentence is a key of the home section; the
 * names in them are data, a list of them joined as ICU joins one in the user's language.
 */

/** A booking as Next up and Awaiting approval list it, HomePayload::booking. */
export type HomeBooking = {
    uuid: string;
    status: string;
    customer: string | null;
    services: string[];
    staff: string[];
    starts_at: string;
    hold_expires_at: string | null;
};

/** A key a trail row's sentence is spelled by: any of the activity group's but its own words. */
export type ActivitySentence = `activity.${Exclude<KeysOf<HomeStrings['activity']>, 'title' | 'empty' | 'all' | 'line'>}`;

/**
 * A trail row as Recent activity lists it, HomeActivity::row: the sentence's key and its names,
 * and the booking's own line beneath it, its status, its services and its named members.
 */
export type HomeActivityRow = {
    sentence: ActivitySentence;
    customer: string | null;
    staff: string | null;
    at: string;
    booking: string | null;
    starts_at: string | null;
    status: string | null;
    services: string[];
    members: string[];
};

/** The Bookings list, all of it. */
const ALL_BOOKINGS = '/admin/bookings';

/** The Bookings list on its Approval inbox, the status filter alone set, as the list spells it. */
export const APPROVAL_INBOX = `${ALL_BOOKINGS}?${new URLSearchParams(queryOf({ ...NO_FILTERS, status: AWAITING_APPROVAL }, 1)).toString()}`;

/** The customer's name, or the erased line where the customer was erased. */
function customerOf(customer: string | null, words: HomeWords): string {
    return customer ?? words.text('customer_erased');
}

function Feed({ id, title, aside, children }: { id: string; title: string; aside?: ReactNode; children: ReactNode }) {
    return (
        <section className={`admin-home-feed admin-home-${id} rounded-xl border`} aria-labelledby={`home-${id}-title`}>
            <Strip titleId={`home-${id}-title`} title={title} aside={aside} />
            {children}
        </section>
    );
}

function Empty({ id, children }: { id: string; children: ReactNode }) {
    return <p className={`admin-home-${id}__empty px-4 py-3 text-sm text-muted-foreground`}>{children}</p>;
}

/**
 * A row: what at the left and when, in mono, at the right on one line, the lines beneath it
 * muted; a row naming a booking is a button opening it.
 */
function Row({ className, uuid, onOpen, children }: { className: string; uuid: string | null; onOpen: (uuid: string) => void; children: ReactNode }) {
    const body = 'flex w-full flex-col px-4 py-2 text-left text-sm';

    return (
        <li className={`${className} border-t first:border-t-0`} data-booking={uuid ?? undefined}>
            {uuid === null ? (
                <div className={body}>{children}</div>
            ) : (
                <button type="button" className={`${className}-open ${body} hover:bg-muted`} onClick={() => onOpen(uuid)}>
                    {children}
                </button>
            )}
        </li>
    );
}

/** The row's first line: what at the left, the instant in mono at the right. */
function Head({ id, what, when, children }: { id: string; what: ReactNode; when: ReactNode; children?: ReactNode }) {
    return (
        <span className="flex items-start justify-between gap-3">
            <span className="flex min-w-0 flex-wrap items-center gap-2">
                {what}
                {children}
            </span>
            <span className={`admin-home-${id}__when shrink-0 font-mono`}>{when}</span>
        </span>
    );
}

type BookingFeed = { rows: HomeBooking[]; clock: TenantClock; words: HomeWords; staffShown: boolean; onOpen: (uuid: string) => void };

/**
 * The services, with the members where the staff choice is real and one is named, as one line,
 * each list joined as ICU joins one in the user's language.
 */
function whatOf(services: string[], staff: string[], staffShown: boolean, words: HomeWords): string {
    const named = listOf(services, words.language);

    return staffShown && staff.length > 0 ? words.text('with', { services: named, staff: listOf(staff, words.language) }) : named;
}

export function NextUp({ rows, clock, words, staffShown, onOpen }: BookingFeed) {
    return (
        <Feed id="next" title={words.text('next_up.title')}>
            {rows.length === 0 ? (
                <Empty id="next">{words.text('next_up.empty')}</Empty>
            ) : (
                <ol className="flex flex-col">
                    {rows.map((booking) => (
                        <Row key={booking.uuid} className="admin-home-next__row" uuid={booking.uuid} onOpen={onOpen}>
                            <Head id="next" what={<span className="admin-home-next__customer font-medium">{customerOf(booking.customer, words)}</span>} when={formatInstant(booking.starts_at, clock)}>
                                {booking.status !== 'confirmed' && <StatusChip status={booking.status} />}
                            </Head>
                            <span className="admin-home-next__what text-muted-foreground">{whatOf(booking.services, booking.staff, staffShown, words)}</span>
                        </Row>
                    ))}
                </ol>
            )}
        </Feed>
    );
}

export function Awaiting({ rows, clock, words, staffShown, onOpen, pending }: BookingFeed & { pending: number }) {
    const aside = (
        <span className="flex items-center gap-3">
            {pending > 0 && <StatusChip status="awaiting_approval" count={pending} className="admin-home-awaiting__count" />}
            <Link href={APPROVAL_INBOX} className="admin-home-awaiting__all text-sm underline">
                {words.text('awaiting.see_all')}
            </Link>
        </span>
    );

    return (
        <Feed id="awaiting" title={words.text('awaiting.title')} aside={aside}>
            {rows.length === 0 ? (
                <Empty id="awaiting">{words.text('awaiting.empty')}</Empty>
            ) : (
                <ol className="flex flex-col">
                    {rows.map((booking) => (
                        <Row key={booking.uuid} className="admin-home-awaiting__row" uuid={booking.uuid} onOpen={onOpen}>
                            <Head id="awaiting" what={<span className="admin-home-awaiting__customer font-medium">{customerOf(booking.customer, words)}</span>} when={formatInstant(booking.starts_at, clock)} />
                            <span className="admin-home-awaiting__what text-muted-foreground">{whatOf(booking.services, booking.staff, staffShown, words)}</span>
                            {booking.hold_expires_at !== null && (
                                <span className="admin-home-awaiting__decide text-xs text-muted-foreground">{words.text('awaiting.decide_by', { end: formatInstant(booking.hold_expires_at, clock) })}</span>
                            )}
                        </Row>
                    ))}
                </ol>
            )}
        </Feed>
    );
}

/**
 * A row's sentence, its names filled: the customer's and the acting person's. An erased
 * customer's row comes with a whole sentence of its own under activity.erased, which names no
 * customer, so nothing stands in the customer's place.
 */
function sentenceOf(row: HomeActivityRow, words: HomeWords): string {
    return words.text(row.sentence, { customer: row.customer ?? '', staff: row.staff ?? '' });
}

/** The booking's own line beneath a row: its services with the members, and its first start. */
function lineOf(row: HomeActivityRow, clock: TenantClock, words: HomeWords, staffShown: boolean): string | null {
    if (row.starts_at === null) {
        return null;
    }

    const start = formatInstant(row.starts_at, clock);
    const booking = whatOf(row.services, row.members, staffShown, words);

    return booking === '' ? start : words.text('activity.line', { booking, start });
}

export function Activity({ rows, clock, words, staffShown, onOpen }: { rows: HomeActivityRow[]; clock: TenantClock; words: HomeWords; staffShown: boolean; onOpen: (uuid: string) => void }) {
    const aside = (
        <Link href={ALL_BOOKINGS} className="admin-home-activity__all text-sm underline">
            {words.text('activity.all')}
        </Link>
    );

    return (
        <Feed id="activity" title={words.text('activity.title')} aside={aside}>
            {rows.length === 0 ? (
                <Empty id="activity">{words.text('activity.empty')}</Empty>
            ) : (
                <ol className="flex flex-col">
                    {rows.map((row, index) => {
                        const line = lineOf(row, clock, words, staffShown);

                        return (
                            <Row key={`${row.at}-${index}`} className="admin-home-activity__row" uuid={row.booking} onOpen={onOpen}>
                                <Head
                                    id="activity"
                                    what={<span className="admin-home-activity__sentence font-medium">{sentenceOf(row, words)}</span>}
                                    when={
                                        <time className="admin-home-activity__at font-mono" dateTime={row.at}>
                                            {formatInstant(row.at, clock)}
                                        </time>
                                    }
                                />
                                {(line !== null || row.status === 'awaiting_approval') && (
                                    <span className="text-muted-foreground">
                                        {line !== null && <span className="admin-home-activity__line">{line}</span>}
                                        {row.status === 'awaiting_approval' && <StatusChip status="awaiting_approval" className="admin-chip--compact ml-2" />}
                                    </span>
                                )}
                            </Row>
                        );
                    })}
                </ol>
            )}
        </Feed>
    );
}
