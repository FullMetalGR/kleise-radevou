import { useEffect, useRef, useState } from 'react';
import { useBookings } from '@/bookings/words';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { CHECK_BOX } from '@/components/ui/check-field';
import { Confirm } from '@/components/ui/confirm';
import { EmptyState } from '@/components/ui/empty-state';
import { Strip } from '@/figures/kit';
import { isoWeekday } from '@/lib/day';
import { formatCount, formatDate, formatInstantTime, type TenantClock } from '@/lib/format';
import { weekdayName } from '@/lib/names';
import { customerOf, type HomeBooking, whatOf } from './feeds';
import { type Marking, type Outcome, type Selection, useMarking, useSelection } from './marking';
import type { HomeWords } from './words';

/*
 * To mark: every confirmed booking whose time is over and which nobody has marked completed or a
 * no-show, under its day, Today first, then older days latest first, each older one its long
 * weekday and its date in the tenant's format; the count of every one in the header strip, its
 * whole sentence the accessible text, and, where the server listed fewer than it counted, a line
 * saying so under the list. The list scrolls inside the card, keeping room at its top and its foot
 * for the outline of a control the keyboard brings into view; the bulk controls sit under it, out
 * of the scroll, so they stay in reach.
 *
 * A row's text opens the booking dialog as Home's other rows do; its Completed and No-show mark it
 * at once with no dialog. A row's checkbox and its day's select it for the bulk marks, which ask
 * once before they run. A viewer who may not manage bookings is shown the rows alone: no
 * checkbox, no button and no bulk control. Below the breakpoint a row stacks, its two marks side
 * by side under its text and one under the other where the line cannot hold both in a language of
 * longer words, so neither runs past the list that cuts it; the bulk buttons take the full width,
 * a label too long for a narrow phone wrapping inside its button.
 *
 * While a mark is in flight a row's text and the bulk buttons do nothing, as the marks do nothing:
 * a booking opened then would interrupt the mark's visit and lose its answer, the row left listed
 * though the server has marked it, and a bulk mark asked for then could not post, its question
 * left open over rows that may have gone. So the question always counts what is listed and posted.
 *
 * The question hands focus back to the button that asked it. Where the mark took that button away,
 * the last selected row gone or the list empty, the card takes the focus, not the page, and shows
 * the house outline while it holds it.
 */

/** A day of To mark: its date, whether it is the tenant's today, and its rows in order. */
export type ToMarkDay = { date: string; today: boolean; bookings: HomeBooking[] };

/** To mark as HomePayload::toMark answers it. */
export type ToMarkList = { count: number; thank_you: boolean; days: ToMarkDay[] };

/** A bulk mark asked for and not yet confirmed. */
type Asking = { outcome: Outcome; uuids: string[] };

type Rows = {
    manage: boolean;
    clock: TenantClock;
    words: HomeWords;
    staffShown: boolean;
    onOpen: (uuid: string) => void;
    selection: Selection;
    marking: Marking;
};

/** Today, or the long weekday and the date in the tenant's format. */
function headingOf(day: ToMarkDay, clock: TenantClock, words: HomeWords): string {
    if (day.today) {
        return words.text('to_mark.today');
    }

    return words.text('to_mark.day', { weekday: weekdayName(isoWeekday(day.date), 'long', words.language), date: formatDate(day.date, clock) });
}

function Count({ count, words }: { count: number; words: HomeWords }) {
    if (count === 0) {
        return null;
    }

    const figure = formatCount(count, words.locale);

    return (
        <span className="admin-home-to-mark__count text-body-sm font-semibold tabular-nums">
            <span aria-hidden="true">{figure}</span>
            <span className="sr-only">{words.choice('to_mark.count', count, { count: figure })}</span>
        </span>
    );
}

/** A day's checkbox: ticked when every row is, mixed when some are. */
function DayTick({ uuids, selection, label, describedBy }: { uuids: string[]; selection: Selection; label: string; describedBy: string }) {
    const box = useRef<HTMLInputElement>(null);
    const ticked = uuids.filter(selection.has).length;
    const mixed = ticked > 0 && ticked < uuids.length;

    useEffect(() => {
        if (box.current !== null) {
            box.current.indeterminate = mixed;
        }
    }, [mixed]);

    return (
        <input
            ref={box}
            type="checkbox"
            className={`admin-home-to-mark__day-tick ${CHECK_BOX}`}
            checked={ticked === uuids.length}
            aria-label={label}
            aria-describedby={describedBy}
            onChange={(event) => selection.set(uuids, event.target.checked)}
        />
    );
}

/** A row's two marks, the dialog's own words; the pressed one busy while it is in flight. */
function RowMarks({ uuid, marking }: { uuid: string; marking: Marking }) {
    const words = useBookings();
    const busy = marking.busy !== null && marking.busy.uuid === uuid ? marking.busy.action : null;

    return (
        <span className="admin-home-to-mark__marks flex shrink-0 gap-2 max-desktop:w-full max-desktop:flex-wrap max-desktop:pl-7">
            <Button className="admin-home-to-mark__complete max-desktop:flex-1" busy={busy === 'complete'} busyLabel={words.text('busy.complete')} onClick={() => marking.markOne(uuid, 'complete')}>
                {words.text('actions.complete')}
            </Button>
            <Button className="admin-home-to-mark__no-show max-desktop:flex-1" busy={busy === 'no-show'} busyLabel={words.text('busy.no_show')} onClick={() => marking.markOne(uuid, 'no-show')}>
                {words.text('actions.no_show')}
            </Button>
        </span>
    );
}

function Row({ booking, manage, clock, words, staffShown, onOpen, selection, marking }: Rows & { booking: HomeBooking }) {
    const time = formatInstantTime(booking.starts_at, clock);
    const label = booking.customer === null ? words.text('to_mark.select_erased', { time }) : words.text('to_mark.select_booking', { customer: booking.customer, time });

    return (
        <li className="admin-home-to-mark__row flex items-center gap-3 border-t px-4 py-2 max-desktop:flex-wrap" data-booking={booking.uuid}>
            {manage && (
                <input
                    type="checkbox"
                    className={`admin-home-to-mark__tick ${CHECK_BOX}`}
                    checked={selection.has(booking.uuid)}
                    aria-label={label}
                    onChange={(event) => selection.set([booking.uuid], event.target.checked)}
                />
            )}
            <button type="button" className="admin-home-to-mark__open focus-ring flex min-w-0 flex-1 flex-col rounded-md text-left text-sm hover:bg-muted" onClick={() => onOpen(booking.uuid)}>
                <span className="flex items-baseline gap-2">
                    <span className="admin-home-to-mark__time tabular-nums">{time}</span>
                    <span className="admin-home-to-mark__customer font-medium">{customerOf(booking.customer, words)}</span>
                </span>
                <span className="admin-home-to-mark__what text-muted-foreground">{whatOf(booking.services, booking.staff, staffShown, words)}</span>
            </button>
            {manage && <RowMarks uuid={booking.uuid} marking={marking} />}
        </li>
    );
}

/** A day under its heading, the first day's heading on the strip's own rule. */
function Day({ day, first, ...rows }: Rows & { day: ToMarkDay; first: boolean }) {
    const headingId = `home-to-mark-${day.date}`;
    const uuids = day.bookings.map((booking) => booking.uuid);

    return (
        <section className="admin-home-to-mark__day" data-date={day.date} aria-labelledby={headingId}>
            <div className={`flex items-center gap-3 bg-muted/40 px-4 py-1.5 ${first ? '' : 'border-t'}`}>
                {rows.manage && <DayTick uuids={uuids} selection={rows.selection} label={rows.words.text('to_mark.select_day')} describedBy={headingId} />}
                <h3 id={headingId} className="admin-home-to-mark__heading text-sm font-medium">
                    {headingOf(day, rows.clock, rows.words)}
                </h3>
            </div>
            <ol className="flex flex-col">
                {day.bookings.map((booking) => (
                    <Row key={booking.uuid} booking={booking} {...rows} />
                ))}
            </ol>
        </section>
    );
}

/**
 * The bulk controls: the two marks of the selection beside its count while a row is selected,
 * and the rest marked completed whenever a row is listed, whatever is selected. Below the admin's
 * breakpoint each takes the card's full width and lets a long label wrap inside it.
 */
function Bulk({ listed, selected, words, onAsk }: { listed: string[]; selected: string[]; words: HomeWords; onAsk: (asking: Asking, from: HTMLElement) => void }) {
    return (
        <div className="admin-home-to-mark__bulk flex flex-wrap items-center gap-2 border-t px-4 py-3 max-desktop:flex-col max-desktop:items-stretch">
            {selected.length > 0 && (
                <>
                    <span className="admin-home-to-mark__selected text-sm text-muted-foreground" aria-live="polite">
                        {words.choice('to_mark.selected', selected.length, { count: formatCount(selected.length, words.locale) })}
                    </span>
                    <Button wrap className="admin-home-to-mark__mark-completed max-desktop:w-full" onClick={(event) => onAsk({ outcome: 'completed', uuids: selected }, event.currentTarget)}>
                        {words.text('to_mark.mark_completed')}
                    </Button>
                    <Button wrap className="admin-home-to-mark__mark-no-show max-desktop:w-full" onClick={(event) => onAsk({ outcome: 'no_show', uuids: selected }, event.currentTarget)}>
                        {words.text('to_mark.mark_no_show')}
                    </Button>
                </>
            )}
            <Button wrap className="admin-home-to-mark__mark-rest max-desktop:w-full desktop:ml-auto" onClick={(event) => onAsk({ outcome: 'completed', uuids: listed }, event.currentTarget)}>
                {words.text('to_mark.mark_rest')}
            </Button>
        </div>
    );
}

/**
 * The bulk mark's one question: the count in the title, that it cannot be undone, and, for
 * Completed where the tenant sends the thank-you email, that the customers get it. Not drawn as
 * destructive: a mark is the booking's ordinary end.
 */
function BulkConfirm({ asking, thankYou, words, busy, onConfirm, onDismiss }: { asking: Asking | null; thankYou: boolean; words: HomeWords; busy: boolean; onConfirm: () => void; onDismiss: () => void }) {
    const bookingsWords = useBookings();
    const outcome = asking?.outcome ?? 'completed';
    const count = asking?.uuids.length ?? 0;
    const completed = outcome === 'completed';

    return (
        <Confirm
            open={asking !== null}
            title={words.choice(`to_mark.confirm.${outcome}`, count, { count: formatCount(count, words.locale) })}
            name="admin-home-to-mark-confirm"
            confirmLabel={words.text(completed ? 'to_mark.confirm.mark_completed' : 'to_mark.confirm.mark_no_show')}
            keepLabel={words.text('to_mark.confirm.back')}
            busyLabel={bookingsWords.text(completed ? 'busy.complete' : 'busy.no_show')}
            pending={words.text('to_mark.confirm.pending')}
            busy={busy}
            destructive={false}
            onConfirm={onConfirm}
            onDismiss={onDismiss}
        >
            <p className="admin-home-to-mark-confirm__undone text-sm">{words.text('to_mark.confirm.undone')}</p>
            {completed && thankYou && <p className="admin-home-to-mark-confirm__thanks text-sm">{words.choice('to_mark.confirm.thanks', count)}</p>}
        </Confirm>
    );
}

type Card = { list: ToMarkList; manage: boolean; clock: TenantClock; words: HomeWords; staffShown: boolean; onOpen: (uuid: string) => void };

export function ToMark({ list, manage, clock, words, staffShown, onOpen }: Card) {
    const listed = list.days.flatMap((day) => day.bookings.map((booking) => booking.uuid));
    const selection = useSelection(listed);
    const marking = useMarking();
    const [asking, setAsking] = useState<Asking | null>(null);
    const card = useRef<HTMLElement>(null);
    const asker = useRef<HTMLElement | null>(null);
    const idle = marking.busy === null;

    const open = (uuid: string) => {
        if (idle) {
            onOpen(uuid);
        }
    };

    const ask = (next: Asking, from: HTMLElement) => {
        if (idle) {
            asker.current = from;
            setAsking(next);
        }
    };

    const confirm = () => {
        if (asking !== null) {
            marking.markMany(asking.uuids, asking.outcome, () => setAsking(null));
        }
    };

    useEffect(() => {
        if (asking !== null) {
            return;
        }

        if (asker.current !== null && !asker.current.isConnected) {
            card.current?.focus();
        }

        asker.current = null;
    }, [asking]);

    const rows: Rows = { manage, clock, words, staffShown, onOpen: open, selection, marking };

    return (
        <Card flush ref={card} tabIndex={-1} className="admin-home-to-mark focus-ring" aria-labelledby="home-to-mark-title">
            <Strip titleId="home-to-mark-title" title={words.text('to_mark.title')} aside={<Count count={list.count} words={words} />} />
            {listed.length === 0 ? (
                <EmptyState className="admin-home-to-mark__empty py-8">{words.text('to_mark.empty')}</EmptyState>
            ) : (
                <>
                    <div className="admin-home-to-mark__list max-h-[420px] scroll-py-2 overflow-y-auto">
                        {list.days.map((day, index) => (
                            <Day key={day.date} day={day} first={index === 0} {...rows} />
                        ))}
                    </div>
                    {list.count > listed.length && (
                        <p className="admin-home-to-mark__showing border-t px-4 py-2 text-sm text-muted-foreground">
                            {words.text('to_mark.showing', { listed: formatCount(listed.length, words.locale), count: formatCount(list.count, words.locale) })}
                        </p>
                    )}
                    {manage && <Bulk listed={listed} selected={selection.selected} words={words} onAsk={ask} />}
                </>
            )}
            {manage && <BulkConfirm asking={asking} thankYou={list.thank_you} words={words} busy={marking.busy !== null && marking.busy.uuid === null} onConfirm={confirm} onDismiss={() => setAsking(null)} />}
        </Card>
    );
}
