import { Button } from '@/components/ui/button';
import { Modal } from '@/components/ui/modal';
import { Notice } from '@/components/ui/notice';
import { StatusChip, statusLabel } from '@/components/ui/status-chip';
import { TableScroll } from '@/components/ui/table-scroll';
import { formatAmount, formatCount, formatInstant, type TenantClock } from '@/lib/format';
import { type ShellWords, useShell } from '@/lib/strings';
import { SUPPORT_ACTOR, supportNamed } from '@/lib/support';
import { cn } from '@/lib/utils';
import { type Act, ActionBar } from './ActionBar';
import { MoveFields, MoveFooter, useMoveForm } from './MoveForm';
import { type Booking, CHANNEL_WORDS, customerName, type Detail, inWords, SOURCE_WORDS, type TrailEntry } from './model';
import { type BookingsKey, type BookingsWords, useBookings } from './words';

/*
 * The booking dialog: the customer's name as its title, the facts, what was booked, and the audit
 * trail oldest first. The email and phone show where the payload carries them, which is the
 * whitelist's decision for this viewer; a booking whose customer was erased carries neither and is
 * titled "Customer erased". Where the booking came from, its source and the channel where one was
 * tagged, and the customer's note where they wrote one, close the facts; the note arrives beside
 * the booking, never in it. Every instant converts to the tenant's zone. The footer is pinned
 * outside the scrolling body, so a long trail cannot push the actions away; it always holds Close,
 * and beside it the action bar with exactly the actions the server allows this viewer now, and no
 * bar at all when that list is empty. A detail that failed to load or names no booking renders
 * inside the dialog, and so does a failure the screen reports over it, such as a close that could
 * not return to the list. Below the admin's breakpoint it fills the viewport, the footer still
 * pinned and the body the one scroller, and the items table, its cells kept apart, scrolls sideways
 * inside it where it is wider than the screen.
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


/** One fact, its term and its value, or nothing at all where the booking carries no value. */
function Fact({ term, block, value, className }: { term: string; block: string; value: string | null | undefined; className?: string }) {
    if (value === undefined || value === null) {
        return null;
    }

    return (
        <>
            <dt>{term}</dt>
            <dd className={cn(`admin-booking-facts__${block}`, className)}>{value}</dd>
        </>
    );
}

function Facts({ booking, note }: { booking: Booking; note: string | null }) {
    const shell = useShell();
    const words = useBookings();

    return (
        <dl className="admin-booking-facts grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
            <dt>{words.text('dialog.facts.status')}</dt>
            <dd>
                <StatusChip status={booking.status} />
            </dd>
            <Fact term={words.text('dialog.facts.total')} block="total" className="font-mono" value={formatAmount(booking.total_minor, booking.currency)} />
            <Fact term={words.text('dialog.facts.email')} block="email" value={booking.customer_email} />
            <Fact term={words.text('dialog.facts.phone')} block="phone" value={booking.customer_phone} />
            <Fact term={words.text('dialog.facts.reason')} block="reason" value={booking.rejection_reason} />
            <Fact term={words.text(SOURCE_LABEL)} block="source" value={booking.source === null || booking.source === undefined ? null : inWords(SOURCE_WORDS, booking.source, shell)} />
            <Fact term={words.text(CHANNEL_LABEL)} block="channel" value={booking.channel === null || booking.channel === undefined ? null : inWords(CHANNEL_WORDS, booking.channel, shell)} />
            <Fact term={words.text(NOTE_LABEL)} block="note" className="whitespace-pre-line" value={note} />
        </dl>
    );
}

/**
 * A cell of the items table: a rem clear of the next and the last flush with the dialog's edge,
 * so at the phone's width two columns never run together, 1.6.
 */
const CELL = 'py-1 pr-4 last:pr-0';

function Items({ booking, staffShown, clock }: { booking: Booking; staffShown: boolean; clock: TenantClock }) {
    const words = useBookings();

    return (
        <section aria-labelledby="booking-items-head">
            <h3 id="booking-items-head" className="mt-4 mb-2 font-semibold">
                {words.text('dialog.items.title')}
            </h3>
            <TableScroll>
                <table className="admin-booking-items w-full text-sm">
                    <thead>
                        <tr className="text-left">
                            <th className={CELL}>{words.text('dialog.items.service')}</th>
                            {staffShown && <th className={CELL}>{words.text('dialog.items.staff')}</th>}
                            <th className={CELL}>{words.text('dialog.items.start')}</th>
                            <th className={CELL}>{words.text('dialog.items.end')}</th>
                            <th className={cn(CELL, 'text-right')}>{words.text('dialog.items.price')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {booking.items.map((item) => (
                            <tr key={item.sort} className="admin-booking-items__row border-t">
                                <td className={CELL}>{item.service_name}</td>
                                {staffShown && <td className={CELL}>{item.staff_name ?? ''}</td>}
                                <td className={cn(CELL, 'font-mono')}>{formatInstant(item.start, clock)}</td>
                                <td className={cn(CELL, 'font-mono')}>{formatInstant(item.end, clock)}</td>
                                <td className={cn(CELL, 'text-right font-mono')}>{formatAmount(item.price_minor, booking.currency)}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </TableScroll>
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

function Trail({ trail, clock }: { trail: TrailEntry[]; clock: TenantClock }) {
    const shell = useShell();
    const words = useBookings();

    return (
        <section aria-labelledby="booking-trail-head">
            <h3 id="booking-trail-head" className="mt-4 mb-2 font-semibold">
                {words.text('dialog.history')}
            </h3>
            {trail.length === 0 ? (
                <p className="admin-booking-trail__none text-sm text-muted-foreground">{words.text('dialog.no_history')}</p>
            ) : (
                <ol className="admin-booking-trail flex flex-col gap-2 text-sm">
                    {trail.map((entry, index) => (
                        <li key={index} className="admin-booking-trail__entry">
                            <span className="font-mono">{formatInstant(entry.at, clock)}</span> <strong>{wordOf(ACTIONS, entry.action, words)}</strong>{' '}
                            <span className="admin-booking-trail__actor">{actorOf(entry, shell, words)}</span>
                            {Object.keys(entry.facts).length > 0 && (
                                <dl className="admin-booking-trail__facts ml-4 grid grid-cols-[auto_1fr] gap-x-3 text-muted-foreground">
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
 * The detail's footer: the action bar where the viewer may act, Move where the server says the
 * booking is movable, and Close.
 */
function DetailFooter({ actions, movable, busy, onAct, onMove, onClose }: { actions: string[]; movable: boolean; busy: string | null; onAct: Act | undefined; onMove: () => void; onClose: () => void }) {
    const words = useBookings();
    const bar = actions.length > 0 && onAct !== undefined;

    return (
        <>
            {bar && <ActionBar actions={actions} busy={busy} onAct={onAct} />}
            {movable && (
                <Button className={cn('admin-booking-move', !bar && 'mr-auto')} disabled={busy !== null} onClick={onMove}>
                    {words.text('move.open')}
                </Button>
            )}
            <Button className="admin-booking-close" onClick={onClose}>
                {words.text('dialog.close')}
            </Button>
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
        <Modal open title={found === null ? words.text('dialog.title') : customerName(found.booking, words)} onClose={onClose} footer={footer} variant="filling" className="admin-booking-dialog max-w-2xl">
            {said !== null && <Notice kind="error">{said}</Notice>}
            {state.kind === 'loading' && <span className="admin-spinner" role="status" aria-label={words.text('dialog.loading')} />}
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
                <>
                    <Facts booking={found.booking} note={found.note} />
                    <Items booking={found.booking} staffShown={staffShown} clock={clock} />
                    <Trail trail={found.trail} clock={clock} />
                </>
            )}
        </Modal>
    );
}
