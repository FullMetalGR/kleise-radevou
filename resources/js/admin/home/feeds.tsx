import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { AWAITING_APPROVAL, NO_FILTERS, queryOf } from '@/bookings/model';
import { Card } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { StatusChip } from '@/components/ui/status-chip';
import { formatCount, formatInstant, type TenantClock } from '@/lib/format';
import { listOf } from '@/lib/names';
import { type KeysOf, useShell } from '@/lib/strings';
import { Strip } from '@/figures/kit';
import type { HomeStrings } from '@/types';
import type { HomeWords } from './words';

/*
 * Home's three feeds: Next up, the next five live bookings, a card under a header strip;
 * Awaiting approval, the requests on a live hold, on the attention fill, its title beside the
 * rail's pending number drawn large, each request a ticket on the surface, and a link to the
 * Bookings list's Approval inbox at its foot; and Recent activity, the trail's last five rows in
 * two columns where the screen is wide, each the whole sentence the server named, with All
 * bookings in its strip. Each row: who or what at the left, the instant in tabular figures in the
 * tenant's formats at the right, the booking's services with its members beneath, a request still
 * awaiting approval chipped. A row naming a booking opens the booking dialog on Home through
 * onOpen. Every sentence is a key of the home section; the names in them are data, a list of them
 * joined as ICU joins one in the user's language.
 */

/** A booking as Next up, Awaiting approval and To mark list it, HomePayload::booking. */
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
export function customerOf(customer: string | null, words: HomeWords): string {
    return customer ?? words.text('customer_erased');
}

function Feed({ id, title, aside, children }: { id: string; title: string; aside?: ReactNode; children: ReactNode }) {
    return (
        <Card flush className={`admin-home-feed admin-home-${id}`} aria-labelledby={`home-${id}-title`}>
            <Strip titleId={`home-${id}-title`} title={title} aside={aside} />
            {children}
        </Card>
    );
}

function Empty({ id, className, children }: { id: string; className?: string; children: ReactNode }) {
    return <EmptyState className={[`admin-home-${id}__empty py-8`, className].filter(Boolean).join(' ')}>{children}</EmptyState>;
}

/** A list that is its card's foot takes the card's lower corners, for its last row to take from it. */
const FOOT = 'rounded-b-[inherit]';

/**
 * A row's look: under a rule in a card's list, or a ticket of its own on the surface. A ruled row
 * runs to the edges of a card that clips, where the house ring, standing clear of its control,
 * would be cut: its keyboard outline is the same one drawn inside the row's own edge. Under the
 * keyboard the last row takes the lower corners its list took from the card and the row's button
 * takes the row's, so the outline at the foot follows the corner instead of being cut by it. The
 * corners are the keyboard's alone: a card stretched past its list holds its last row above its
 * foot, so a pointer's fill is square, and the card cuts it where the row is the foot.
 */
const LOOKS = {
    ruled: { item: 'border-t first:border-t-0 last:has-focus-visible:rounded-b-[inherit]', room: 'px-4 py-2.5', press: 'hover:bg-muted focus-visible:rounded-[inherit] focus-visible:outline-3 focus-visible:-outline-offset-3 focus-visible:outline-primary' },
    ticket: { item: 'rounded-inner bg-card text-foreground', room: 'px-4.5 py-3.5', press: 'focus-ring rounded-inner hover:bg-muted' },
} as const;

/**
 * A row: what at the left and when, in tabular figures, at the right on one line, the lines
 * beneath it muted; a row naming a booking is a button opening it.
 */
function Row({ className, look = 'ruled', item = '', uuid, onOpen, children }: { className: string; look?: keyof typeof LOOKS; item?: string; uuid: string | null; onOpen: (uuid: string) => void; children: ReactNode }) {
    const body = `flex w-full flex-col gap-0.5 text-left text-body-sm ${LOOKS[look].room}`;

    return (
        <li className={[className, LOOKS[look].item, item].filter(Boolean).join(' ')} data-booking={uuid ?? undefined}>
            {uuid === null ? (
                <div className={body}>{children}</div>
            ) : (
                <button type="button" className={`${className}-open ${body} ${LOOKS[look].press}`} onClick={() => onOpen(uuid)}>
                    {children}
                </button>
            )}
        </li>
    );
}

/** The row's first line: what at the left, the instant in tabular figures at the right. */
function Head({ id, what, when, children }: { id: string; what: ReactNode; when: ReactNode; children?: ReactNode }) {
    return (
        <span className="flex items-start justify-between gap-3">
            <span className="flex min-w-0 flex-wrap items-center gap-2">
                {what}
                {children}
            </span>
            <span className={`admin-home-${id}__when shrink-0 tabular-nums`}>{when}</span>
        </span>
    );
}

type BookingFeed = { rows: HomeBooking[]; clock: TenantClock; words: HomeWords; staffShown: boolean; onOpen: (uuid: string) => void };

/**
 * The services, with the members where the staff choice is real and one is named, as one line,
 * each list joined as ICU joins one in the user's language.
 */
export function whatOf(services: string[], staff: string[], staffShown: boolean, words: HomeWords): string {
    const named = listOf(services, words.language);

    return staffShown && staff.length > 0 ? words.text('with', { services: named, staff: listOf(staff, words.language) }) : named;
}

export function NextUp({ rows, clock, words, staffShown, onOpen }: BookingFeed) {
    return (
        <Feed id="next" title={words.text('next_up.title')}>
            {rows.length === 0 ? (
                <Empty id="next">{words.text('next_up.empty')}</Empty>
            ) : (
                <ol className={`flex flex-col ${FOOT}`}>
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
    const shell = useShell();

    // The head holds the title and, where something waits, the figure; where the card is short of
    // room the figure wraps under the title rather than either giving way.
    return (
        <Card className="admin-home-feed admin-home-awaiting flex flex-col gap-4 bg-attention-soft text-attention-ink inset-ring inset-ring-attention-line" aria-labelledby="home-awaiting-title">
            <header className="flex flex-wrap items-center justify-between gap-x-4 gap-y-1">
                <h2 id="home-awaiting-title" className="text-tile-title">
                    {words.text('awaiting.title')}
                </h2>
                {pending > 0 && (
                    <p className="admin-home-awaiting__count">
                        <span aria-hidden="true" className="font-display text-[4.25rem] leading-[0.9] font-semibold tracking-[-0.06em] tabular-nums max-desktop:text-[3.5rem]">
                            {formatCount(pending, words.locale)}
                        </span>
                        <span className="sr-only">{shell.choice('kit.counted.awaiting_approval', pending)}</span>
                    </p>
                )}
            </header>
            {rows.length === 0 ? (
                <Empty id="awaiting" className="text-attention-ink">
                    {words.text('awaiting.empty')}
                </Empty>
            ) : (
                <ol className="flex flex-col gap-2.5">
                    {rows.map((booking) => (
                        <Row key={booking.uuid} className="admin-home-awaiting__row" look="ticket" uuid={booking.uuid} onOpen={onOpen}>
                            <Head id="awaiting" what={<span className="admin-home-awaiting__customer font-semibold">{customerOf(booking.customer, words)}</span>} when={formatInstant(booking.starts_at, clock)} />
                            <span className="admin-home-awaiting__what text-muted-foreground">{whatOf(booking.services, booking.staff, staffShown, words)}</span>
                            {booking.hold_expires_at !== null && (
                                <span className="admin-home-awaiting__decide mt-2 border-t border-dashed border-attention-line pt-2 text-small font-semibold text-attention-ink">
                                    {words.text('awaiting.decide_by', { end: formatInstant(booking.hold_expires_at, clock) })}
                                </span>
                            )}
                        </Row>
                    ))}
                </ol>
            )}
            <Link href={APPROVAL_INBOX} className="admin-home-awaiting__all focus-ring mt-auto self-start rounded-inner text-body-sm font-semibold underline underline-offset-2">
                {words.text('awaiting.see_all')}
            </Link>
        </Card>
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

/**
 * The foot of the list in two columns: the last row shares one lower corner with the card, the
 * left one in the left column and the right one in the right, where the row before it holds the
 * left. As in one column, a row takes its corner under the keyboard alone.
 */
const TWO_COLUMN_FOOT = '@3xl:odd:last:has-focus-visible:rounded-br-none @3xl:even:last:has-focus-visible:rounded-bl-none @3xl:odd:nth-last-2:has-focus-visible:rounded-bl-[inherit]';

export function Activity({ rows, clock, words, staffShown, onOpen }: { rows: HomeActivityRow[]; clock: TenantClock; words: HomeWords; staffShown: boolean; onOpen: (uuid: string) => void }) {
    const aside = (
        <Link href={ALL_BOOKINGS} className="admin-home-activity__all focus-ring rounded-inner text-body-sm underline">
            {words.text('activity.all')}
        </Link>
    );

    return (
        <Feed id="activity" title={words.text('activity.title')} aside={aside}>
            {rows.length === 0 ? (
                <Empty id="activity">{words.text('activity.empty')}</Empty>
            ) : (
                <ol className={`grid grid-cols-1 @3xl:grid-cols-2 @3xl:gap-x-10 ${FOOT}`}>
                    {rows.map((row, index) => {
                        const line = lineOf(row, clock, words, staffShown);

                        return (
                            <Row key={`${row.at}-${index}`} className="admin-home-activity__row" item={`@3xl:nth-2:border-t-0 ${TWO_COLUMN_FOOT}`} uuid={row.booking} onOpen={onOpen}>
                                <Head
                                    id="activity"
                                    what={<span className="admin-home-activity__sentence font-medium">{sentenceOf(row, words)}</span>}
                                    when={
                                        <time className="admin-home-activity__at tabular-nums" dateTime={row.at}>
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
