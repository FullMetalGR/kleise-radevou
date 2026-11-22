import { wallClockOf } from '../../booking/time';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Modal } from '@/components/ui/modal';
import { Notice } from '@/components/ui/notice';
import { Spinner } from '@/components/ui/spinner';
import { StatusChip, statusLabel } from '@/components/ui/status-chip';
import { Table, TableBody, TableHead, TableRow, Td, Th } from '@/components/ui/table';
import { isoWeekday } from '@/lib/day';
import { formatAmount, formatCount, formatInstant, formatInstantDay, formatInstantTime, type TenantClock } from '@/lib/format';
import { unitFigure, weekdayName } from '@/lib/names';
import { type ShellWords, useShell } from '@/lib/strings';
import { SUPPORT_ACTOR, supportNamed } from '@/lib/support';
import { cn } from '@/lib/utils';
import { type Act, ActionBar, HALF_ON_PHONE } from './ActionBar';
import { MoveFields, MoveFooter, useMoveForm } from './MoveForm';
import { type Booking, CHANNEL_WORDS, customerName, type Detail, inWords, SOURCE_WORDS, startOf, type TrailEntry } from './model';
import { type BookingsKey, type BookingsWords, useBookings } from './words';

/*
 * The booking dialog. Its head holds the customer's name as the title, the booking's status chip
 * and the dialog's own round close. The body opens on the when panel: the day under its weekday,
 * the time from the first service's start to the last one's end with how long that is, and the
 * total. The time is struck through where the visit did not happen, and a request still awaiting
 * a decision sits on the attention fill. The contact facts follow, then what was booked as rows,
 * then the audit trail oldest first, each entry a dot on a rail. The email and phone show where
 * the payload carries them, which is the whitelist's decision for this viewer; a booking whose
 * customer was erased carries neither and is titled "Customer erased". Where the booking came
 * from, its source and the channel where one was tagged, the rejection reason and the customer's
 * note close the contact facts; the note arrives beside the booking, never in it. Every instant
 * converts to the tenant's zone.
 *
 * The footer is pinned outside the scrolling body, so a long trail cannot push the actions away.
 * It splits in two: on the left the action bar with exactly the actions the server allows this
 * viewer now, and no bar at all when that list is empty; on the right Move, where the server says
 * the booking is movable, and Close. A detail that failed to load or names no booking renders
 * inside the dialog, and so does a failure the screen reports over it, such as a close that could
 * not return to the list. Below the admin's breakpoint the dialog fills the viewport, the body
 * the one scroller, each service a block of labelled lines, and the footer's buttons two to a
 * line.
 *
 * Every word is a key of the bookings section the screen provides: the trail's actions, facts,
 * actors and hold classes stay machine values on the wire and only their display words are keys, a
 * value outside a table showing verbatim.
 */

export type DialogState = { kind: 'loading' } | { kind: 'error' } | { kind: 'detail'; detail: Detail };

type Props = {
    state: DialogState;
    staffShown: boolean;
    clock: TenantClock;
    /** The tenant's first day of the week, for the Move form's date field; Monday unless told. */
    weekStart?: number;
    onClose: () => void;
    /** A failure the screen reports over the dialog, in its words; null or absent when none. */
    failure?: string | null;
    /** The action whose visit is in flight, or null. */
    busy?: string | null;
    /** Where the action bar hands a press; absent, the dialog renders no bar. */
    onAct?: Act;
};

const ACTIONS: Readonly<Record<string, BookingsKey>> = {
    held: 'trail.actions.held',
    expired: 'trail.actions.expired',
    confirmed: 'trail.actions.confirmed',
    cancelled: 'trail.actions.cancelled',
    released: 'trail.actions.released',
    approved: 'trail.actions.approved',
    rejected: 'trail.actions.rejected',
    completed: 'trail.actions.completed',
    no_show: 'trail.actions.no_show',
    rescheduled: 'trail.actions.rescheduled',
    customer_erased: 'trail.actions.customer_erased',
    customer_exported: 'trail.actions.customer_exported',
};

const FACTS: Readonly<Record<string, BookingsKey>> = {
    status_from: 'trail.facts.status_from',
    status_to: 'trail.facts.status_to',
    hold_class: 'trail.facts.hold_class',
    hold_expires_at: 'trail.facts.hold_expires_at',
    item_count: 'trail.facts.item_count',
    starts_at: 'trail.facts.starts_at',
    moved_from: 'trail.facts.moved_from',
    staff_changed: 'trail.facts.staff_changed',
    overridden: 'trail.facts.overridden',
    customer_told: 'trail.facts.customer_told',
    paid_minor: 'trail.facts.paid',
};

/**
 * The facts of one entry as the trail lists them: the amount a payment took is one line, its
 * minor units formatted in the currency it was paid in at that currency's scale, and the
 * currency is no line of its own. An amount without its currency is listed as it came.
 */
function listedEntries(facts: Record<string, unknown>, locale: string): [string, unknown][] {
    const { paid_minor: paid, paid_currency: currency } = facts;
    const amount = typeof paid === 'number' && typeof currency === 'string' ? formatAmount(paid, currency, locale) : null;

    return Object.entries(facts).flatMap(([fact, value]): [string, unknown][] => {
        if (amount === null) {
            return [[fact, value]];
        }

        return fact === 'paid_currency' ? [] : [[fact, fact === 'paid_minor' ? amount : value]];
    });
}

/** A machine value's display word from a table of keys, verbatim where the table holds none. */
function wordOf(table: Readonly<Record<string, BookingsKey>>, value: string, words: BookingsWords): string {
    const key = Object.hasOwn(table, value) ? table[value] : undefined;

    return key === undefined ? value : words.text(key);
}

/**
 * Who acted, in the trail's words: a user by name, the raw actor where the user is gone, support
 * with the operator's name in brackets, or "Support" alone where the operator is gone.
 */
function actorOf(entry: TrailEntry, shell: ShellWords, words: BookingsWords): string {
    switch (entry.kind) {
        case 'user':
            return entry.name ?? entry.actor;
        case 'support':
            return entry.name === null ? shell.text(SUPPORT_ACTOR) : supportNamed(entry.name, shell);
        case 'customer':
            return words.text('trail.actors.customer');
        case 'system':
            return words.text('trail.actors.system');
        case 'job':
            return words.text('trail.actors.job');
        default:
            return entry.actor;
    }
}

const HOLD_CLASSES: Readonly<Record<string, BookingsKey>> = {
    checkout: 'trail.holds.checkout',
    approval: 'trail.holds.approval',
    payment: 'trail.holds.payment',
};

const STATUS_FACTS = new Set(['status_from', 'status_to']);

const INSTANT_FACTS = new Set(['hold_expires_at', 'starts_at', 'moved_from']);

/** One fact of a list a move by the business wrote, a map of its fields. */
type ListedFact = Readonly<Record<string, unknown>>;

function listedFacts(value: unknown): ListedFact[] {
    return Array.isArray(value) ? value.filter((item): item is ListedFact => typeof item === 'object' && item !== null) : [];
}

/**
 * A segment's place in the booking as a person counts it, from one, in the words' locale; null
 * for a fact that names no segment.
 */
function positionOf(fact: ListedFact, locale: string): string | null {
    return typeof fact.segment === 'number' ? formatCount(fact.segment + 1, locale) : null;
}

/**
 * A member a staff change names: by name, or null where the member is gone and the trail keeps no
 * name.
 */
function memberOf(fact: ListedFact, field: 'from' | 'to'): string | null {
    const name = fact[`${field}_name`];

    return typeof name === 'string' ? name : null;
}

/** A staff change's line: one whole line per case, both members named, either removed, or both. */
function staffChangeLine(change: ListedFact, position: string, words: BookingsWords): string {
    const from = memberOf(change, 'from');
    const to = memberOf(change, 'to');

    if (from === null) {
        return to === null ? words.text('trail.values.staff_changed_removed', { position }) : words.text('trail.values.staff_changed_from_removed', { position, to });
    }

    return to === null ? words.text('trail.values.staff_changed_to_removed', { position, from }) : words.text('trail.values.staff_changed', { position, from, to });
}

/**
 * The listed facts that name their segment, each with its place in the booking; one naming none,
 * which no move writes, is left out.
 */
function placedFacts(value: unknown, locale: string): { fact: ListedFact; position: string }[] {
    return listedFacts(value).flatMap((fact) => {
        const position = positionOf(fact, locale);

        return position === null ? [] : [{ fact, position }];
    });
}

/**
 * The facts only a move by the business writes: each service it gave to another member, each
 * warning it overrode, each its own line, and whether it told the customer, in words; null for
 * every other fact.
 */
function moveFactOf(fact: string, value: unknown, words: BookingsWords): string | string[] | null {
    switch (fact) {
        case 'customer_told':
            return typeof value === 'boolean' ? words.text(value ? 'trail.values.yes' : 'trail.values.no') : null;
        case 'staff_changed':
            return placedFacts(value, words.locale).map(({ fact: change, position }) => staffChangeLine(change, position, words));
        case 'overridden':
            return placedFacts(value, words.locale).map(({ fact: crossing, position }) => words.text(crossing.kind === 'closure' ? 'trail.values.closure' : 'trail.values.hours', { position }));
        default:
            return null;
    }
}

/**
 * A trail fact as what it carries: a status in its chip's words, a hold class in words or verbatim
 * when unknown, an instant on the tenant's clock, a business move's own facts in words, and
 * anything else as it came, a string unquoted.
 */
function factOf(fact: string, value: unknown, clock: TenantClock, shell: ShellWords, words: BookingsWords): string | string[] {
    const moved = moveFactOf(fact, value, words);

    if (moved !== null) {
        return moved;
    }

    if (typeof value !== 'string') {
        return JSON.stringify(value);
    }

    if (STATUS_FACTS.has(fact)) {
        return statusLabel(value, shell);
    }

    if (fact === 'hold_class') {
        return wordOf(HOLD_CLASSES, value, words);
    }

    return INSTANT_FACTS.has(fact) ? formatInstant(value, clock) : value;
}

/** The facts' labels for where a booking came from and what its customer wrote. */
export const SOURCE_LABEL: BookingsKey = 'dialog.facts.source';

export const CHANNEL_LABEL: BookingsKey = 'dialog.facts.channel';

export const NOTE_LABEL: BookingsKey = 'dialog.facts.note';


/**
 * The booking's status at the end of the title's row, its chip kept whole, with its term for a
 * screen reader alone. It is set down by the difference between the title's room above its line
 * and below it, so the chip's middle meets the line's rather than the room's.
 */
function BookingStatus({ status }: { status: string }) {
    const words = useBookings();

    return (
        <span className="admin-booking-status mt-2.5 shrink-0">
            <span className="sr-only">{words.text('dialog.facts.status')}</span>
            <StatusChip status={status} />
        </span>
    );
}

/** The statuses whose visit did not happen, whose time the when panel strikes through. */
const STRUCK = new Set(['cancelled', 'rejected', 'no_show']);

/** The booking's own end: its latest item's. */
function endOf(booking: Booking): string {
    return booking.items.reduce((latest, item) => (Date.parse(item.end) > Date.parse(latest) ? item.end : latest), booking.items[0]?.end ?? '');
}

/**
 * Whether every service of the booking starts and ends at an instant the tenant's clock can read,
 * and there is one at least: only then has the booking a day, a time and a length to show.
 */
function isTimed(booking: Booking, clock: TenantClock): boolean {
    try {
        booking.items.forEach((item) => [item.start, item.end].forEach((wire) => wallClockOf(wire, clock.timezone)));

        return booking.items.length > 0;
    } catch {
        return false;
    }
}

/** The long weekday of an instant in the tenant's zone. */
function weekdayOf(wire: string, clock: TenantClock, words: BookingsWords): string {
    return weekdayName(isoWeekday(wallClockOf(wire, clock.timezone).date), 'long', words.language);
}

/**
 * How long the booking runs, from its start to its end, as one figure with its unit and no word
 * joining two: whole hours in hours, anything else in minutes.
 */
function lengthOf(start: string, end: string, words: BookingsWords): string {
    const minutes = Math.round((Date.parse(end) - Date.parse(start)) / 60000);

    return minutes > 0 && minutes % 60 === 0 ? unitFigure(minutes / 60, 'hour', words.language) : unitFigure(minutes, 'minute', words.language);
}

/**
 * The when panel: an inner card of the day, the time and the total, three columns where the
 * dialog is wide; below that the day and the total share the first row and the time takes the
 * second, set a step smaller. A booking with no items shows its total alone, and so does one
 * with a start or an end that is no instant: the three are read off instants, and the items below
 * still show each time as it came.
 */
function When({ booking, clock }: { booking: Booking; clock: TenantClock }) {
    const words = useBookings();
    const start = startOf(booking);
    const end = endOf(booking);
    const timed = isTimed(booking, clock);

    return (
        <Card
            variant="inner"
            className={cn(
                'admin-booking-facts grid grid-cols-[1fr_auto] items-end gap-x-5 gap-y-3 px-5.5 py-4 @xl:grid-cols-[1.1fr_1.2fr_auto]',
                booking.status === 'awaiting_approval' && 'bg-attention-soft ring-attention-line',
            )}
        >
            {timed && (
                <p className="flex min-w-0 flex-col">
                    <span className="admin-booking-facts__weekday text-label text-muted-foreground">{weekdayOf(start, clock, words)}</span>
                    <span className="admin-booking-facts__date font-display text-tile-title tabular-nums">{formatInstantDay(start, clock)}</span>
                </p>
            )}
            {timed && (
                <p className="col-span-2 row-start-2 flex min-w-0 flex-col @xl:col-span-1 @xl:row-start-auto">
                    <span className={cn('admin-booking-facts__time font-display text-dialog-title tabular-nums @xl:text-page-title-compact', STRUCK.has(booking.status) && 'text-ink-tertiary line-through decoration-2')}>
                        {words.text('move.confirm.window', { start: formatInstantTime(start, clock), end: formatInstantTime(end, clock) })}
                    </span>
                    <span className="admin-booking-facts__length text-small text-muted-foreground">{lengthOf(start, end, words)}</span>
                </p>
            )}
            <p className="col-start-2 row-start-1 flex flex-col items-end text-right @xl:col-start-auto @xl:row-start-auto">
                <span className="text-label text-muted-foreground">{words.text('dialog.facts.total')}</span>
                <span className="admin-booking-facts__total font-display text-[1.625rem] leading-tight font-semibold tabular-nums">{formatAmount(booking.total_minor, booking.currency)}</span>
            </p>
        </Card>
    );
}

/**
 * One contact fact, its term over its value, or nothing at all where the booking carries no
 * value. A long one runs across the list on the soft fill.
 */
function Fact({ term, block, value, across = false, className }: { term: string; block: string; value: string | null | undefined; across?: boolean; className?: string }) {
    if (value === undefined || value === null) {
        return null;
    }

    return (
        <div className={cn('flex min-w-0 flex-col gap-0.5', across && 'rounded-notice bg-muted px-4 py-3 @xl:col-span-3')}>
            <dt className="text-label text-muted-foreground">{term}</dt>
            <dd className={cn(`admin-booking-facts__${block}`, 'font-semibold wrap-anywhere', className)}>{value}</dd>
        </div>
    );
}

/** The contact facts: three columns where the dialog is wide and one below, the reason and the note across them. */
function Contact({ booking, note }: { booking: Booking; note: string | null }) {
    const shell = useShell();
    const words = useBookings();

    return (
        <dl className="admin-booking-contact grid grid-cols-1 gap-x-5 gap-y-3 @xl:grid-cols-3">
            <Fact term={words.text('dialog.facts.email')} block="email" value={booking.customer_email} />
            <Fact term={words.text('dialog.facts.phone')} block="phone" value={booking.customer_phone} />
            <Fact term={words.text(SOURCE_LABEL)} block="source" value={booking.source === null || booking.source === undefined ? null : inWords(SOURCE_WORDS, booking.source, shell)} />
            <Fact term={words.text(CHANNEL_LABEL)} block="channel" value={booking.channel === null || booking.channel === undefined ? null : inWords(CHANNEL_WORDS, booking.channel, shell)} />
            <Fact term={words.text('dialog.facts.reason')} block="reason" across value={booking.rejection_reason} />
            <Fact term={words.text(NOTE_LABEL)} block="note" across className="whitespace-pre-line" value={note} />
        </dl>
    );
}

/** A section's heading inside the dialog's body. */
const HEADING = 'mb-2.5 font-display text-lg font-semibold';

/**
 * What was booked: the shared table, a grid where the dialog is wide, and below that each service
 * a block of lines, every value beside its column's name.
 */
function Items({ booking, staffShown, clock }: { booking: Booking; staffShown: boolean; clock: TenantClock }) {
    const words = useBookings();
    const service = words.text('dialog.items.service');
    const staff = words.text('dialog.items.staff');
    const start = words.text('dialog.items.start');
    const end = words.text('dialog.items.end');
    const price = words.text('dialog.items.price');

    return (
        <section aria-labelledby="booking-items-head">
            <h3 id="booking-items-head" className={HEADING}>
                {words.text('dialog.items.title')}
            </h3>
            <Card variant="inner" flush>
                <Table className="admin-booking-items">
                    <TableHead>
                        <TableRow>
                            <Th>{service}</Th>
                            {staffShown && <Th>{staff}</Th>}
                            <Th>{start}</Th>
                            <Th>{end}</Th>
                            <Th className="text-right">{price}</Th>
                        </TableRow>
                    </TableHead>
                    <TableBody>
                        {booking.items.map((item) => (
                            <TableRow key={item.sort} className="admin-booking-items__row">
                                <Td label={service}>{item.service_name}</Td>
                                {staffShown && <Td label={staff}>{item.staff_name ?? ''}</Td>}
                                <Td label={start}>{formatInstant(item.start, clock)}</Td>
                                <Td label={end}>{formatInstant(item.end, clock)}</Td>
                                <Td label={price} className="text-right">
                                    {formatAmount(item.price_minor, booking.currency)}
                                </Td>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </Card>
        </section>
    );
}

/**
 * A trail fact's value: one line, or a list's lines each on its own, never joined into one.
 */
function FactValue({ value }: { value: string | string[] }) {
    if (typeof value === 'string') {
        return value;
    }

    return value.map((line, index) => (
        <span key={index} className="admin-booking-trail__line block">
            {line}
        </span>
    ));
}

/**
 * The audit trail oldest first: each entry a dot on a rail drawn down its left, its action in
 * bold, then when it happened and who did it, and under them the facts it recorded.
 */
function Trail({ trail, clock }: { trail: TrailEntry[]; clock: TenantClock }) {
    const shell = useShell();
    const words = useBookings();

    return (
        <section aria-labelledby="booking-trail-head">
            <h3 id="booking-trail-head" className={HEADING}>
                {words.text('dialog.history')}
            </h3>
            {trail.length === 0 ? (
                <p className="admin-booking-trail__none text-small text-muted-foreground">{words.text('dialog.no_history')}</p>
            ) : (
                <ol className="admin-booking-trail relative flex flex-col gap-3.5 text-small before:absolute before:top-2 before:bottom-2 before:left-1 before:w-0.5 before:bg-border">
                    {trail.map((entry, index) => (
                        <li key={index} className="admin-booking-trail__entry relative pl-6">
                            <span aria-hidden="true" className="admin-booking-trail__dot absolute top-1.5 left-0 size-2.5 rounded-full bg-primary ring-4 ring-card" />
                            <strong className="admin-booking-trail__action font-semibold">{wordOf(ACTIONS, entry.action, words)}</strong>{' '}
                            <span className="text-muted-foreground tabular-nums">{formatInstant(entry.at, clock)}</span>{' '}
                            <span className="admin-booking-trail__actor text-muted-foreground">{actorOf(entry, shell, words)}</span>
                            {Object.keys(entry.facts).length > 0 && (
                                <dl className="admin-booking-trail__facts mt-1 grid grid-cols-[auto_1fr] gap-x-3 text-muted-foreground">
                                    {listedEntries(entry.facts, words.locale).map(([fact, value]) => (
                                        <div key={fact} className="contents">
                                            <dt>{wordOf(FACTS, fact, words)}</dt>
                                            <dd>
                                                <FactValue value={factOf(fact, value, clock, shell, words)} />
                                            </dd>
                                        </div>
                                    ))}
                                </dl>
                            )}
                        </li>
                    ))}
                </ol>
            )}
        </section>
    );
}

/**
 * The detail's footer in two groups: the action bar on the left where the viewer may act, and on
 * the right Move, where the server says the booking is movable, and Close. Below the breakpoint
 * each group takes the footer's width and its buttons go two to a line.
 */
function DetailFooter({ actions, movable, busy, onAct, onMove, onClose }: { actions: string[]; movable: boolean; busy: string | null; onAct: Act | undefined; onMove: () => void; onClose: () => void }) {
    const words = useBookings();
    const bar = actions.length > 0 && onAct !== undefined;

    return (
        <>
            {bar && <ActionBar actions={actions} busy={busy} onAct={onAct} />}
            <div className="admin-booking-neutral flex gap-2 max-desktop:w-full">
                {movable && (
                    <Button wrap className={cn('admin-booking-move', HALF_ON_PHONE)} disabled={busy !== null} onClick={onMove}>
                        {words.text('move.open')}
                    </Button>
                )}
                <Button wrap className={cn('admin-booking-close', HALF_ON_PHONE)} onClick={onClose}>
                    {words.text('dialog.close')}
                </Button>
            </div>
        </>
    );
}

export function BookingDialog({ state, staffShown, clock, weekStart = 1, onClose, failure = null, busy = null, onAct }: Props) {
    const words = useBookings();
    const found = state.kind === 'detail' && state.detail.state === 'found' ? state.detail : null;
    const move = useMoveForm({ booking: found?.booking ?? null, clock });
    const footer = move.moving ? (
        <MoveFooter form={move} />
    ) : (
        <DetailFooter actions={found?.actions ?? []} movable={found?.booking.movable === true} busy={busy} onAct={onAct} onMove={move.begin} onClose={onClose} />
    );
    const said = failure ?? move.failure;

    return (
        <Modal
            open
            title={found === null ? words.text('dialog.title') : customerName(found.booking, words)}
            aside={found === null ? undefined : <BookingStatus status={found.booking.status} />}
            onClose={onClose}
            footer={footer}
            variant="filling"
            className="admin-booking-dialog"
        >
            {said !== null && <Notice kind="error">{said}</Notice>}
            {state.kind === 'loading' && <Spinner label={words.text('dialog.loading')} />}
            {state.kind === 'error' && <Notice kind="error">{words.text('dialog.load_error')}</Notice>}
            {state.kind === 'detail' && state.detail.state === 'missing' && (
                <div className="flex flex-col gap-3">
                    <Button variant="link" className="admin-booking-back self-start" onClick={onClose}>
                        {words.text('dialog.back')}
                    </Button>
                    <Notice kind="warning" className="admin-booking-missing">
                        {words.text('dialog.missing')}
                    </Notice>
                </div>
            )}
            {found !== null && move.moving && <MoveFields form={move} booking={found.booking} staffShown={staffShown} clock={clock} weekStart={weekStart} />}
            {found !== null && !move.moving && (
                <div className="@container flex flex-col gap-5">
                    <When booking={found.booking} clock={clock} />
                    <Contact booking={found.booking} note={found.note} />
                    <Items booking={found.booking} staffShown={staffShown} clock={clock} />
                    <Trail trail={found.trail} clock={clock} />
                </div>
            )}
        </Modal>
    );
}
