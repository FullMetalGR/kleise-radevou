import type { DateSelectArg, DayCellContentArg, DayCellMountArg, DayHeaderContentArg, EventClickArg, EventContentArg, EventDropArg, EventInput, EventMountArg, NowIndicatorContentArg, SlotLabelContentArg } from '@fullcalendar/core';
import type { EventLeaveArg, EventReceiveArg } from '@fullcalendar/interaction';
import dayGridPlugin from '@fullcalendar/daygrid';
import interactionPlugin from '@fullcalendar/interaction';
import FullCalendar from '@fullcalendar/react';
import timeGridPlugin from '@fullcalendar/timegrid';
import { type CSSProperties, type KeyboardEvent, type MouseEvent, type ReactNode, useEffect, useRef } from 'react';
import { StatusChip } from '@/components/ui/status-chip';
import { isoWeekday, utcDayOf } from '@/lib/day';
import { formatDate, formatTimeOfDay, type TenantClock } from '@/lib/format';
import { weekdayName } from '@/lib/names';
import { useShell } from '@/lib/strings';
import type { Language } from '../../booking/language';
import { BlockBadge } from './Badge';
import { type Block, blockHueOf, businessHoursOf, type ClosureBlock, closureBlocksOf, type Colouring, type DayColumn, dayAxisOf, type Drop, fillOf, nextLineOf, scrollTimeOf, slotDurationOf, slotHeightOf, type StatusCount, TIMED_MINUTES, type View, wallOf } from './model';
import { useCalendarWords, useClosureWords } from './words';

/*
 * The calendar grid, over FullCalendar's time grid, and its day grid for Month: its own toolbar
 * off, because the screen owns the view and the date, Week, Day and Month and no other view, the
 * axis stepping at the tenant's granularity, the columns from the tenant's week start. The library
 * is told it is in UTC and handed wall-clock strings with no zone, so it draws every instant at the
 * tenant's hour and knows no daylight saving of its own: a block at 04:30 on the day the clocks go
 * back sits at 04:30.
 *
 * A block's hue is what Colour by says: its member's, its service's category's or its status's chip
 * tone. Its fill is the hue mixed toward the surface, set on the element itself with a
 * full-saturation left border and the theme's text colour; a gap is hatch stripes in the hue over
 * nothing, inside a dashed border. The body is the title, bold and ellipsised, a badge before it
 * for a booking awaiting approval or awaiting payment alone, and the time range under it only when
 * the block is at least forty-five minutes long. The scroll on every view or date change is one
 * hour before the day's earliest block or the fixed morning hour, computed by the model and carried
 * on the wrapper as data-scroll-time.
 *
 * Month draws no block and offers no slot selection. It is six weeks always, from the week
 * holding the month's first day, the range the screen reads. Each day's cell is the press: one
 * tab stop, a pointer press anywhere in it or Enter or Space on it hands the day to the screen.
 * The cell holds the day's number and a compact status chip per status present with its count,
 * nothing on a day with none, inside the day grid's own day-number element, an anchor with no
 * href while the library's navigation links are off and no control inside it, which is the one
 * place the library takes a day's content. The cell is the library's grid cell, named by that
 * element: the day in the tenant's date format and its chips.
 *
 * Day as member columns, where the screen hands the columns: one time-grid instance per column side
 * by side in a row that scrolls sideways past a minimum column width, every instance on the same
 * step and the same axis, the shown members' earliest start to latest end that day and at least
 * 08:00 to 20:00, so their rows align under the page's one scroll, the axis labels drawn on the
 * first instance alone. Each instance's business hours are the member's windows of the day, so the
 * library hatches the time outside them, the whole column where there are none, its body then
 * carrying the pill naming the next working day; its closures are drawn as background blocks with
 * their words, and its slot hands the member with the date. The screen draws each column's head.
 * The trailing column of the other bookings has no hatch, no closure and no slot. The now line
 * shows in Day.
 *
 * Dragging a booking, where the screen takes a drop: a block drags exactly where it is a booking's
 * and the server says the booking is movable, never a gap or a closure, and nothing resizes, a
 * duration being the services'. The drop snaps to the slots' step. Week and Day as the one grid
 * hand the drop with the new wall clock and no member; Day's columns hand a drop inside a column
 * the same way, and a drop onto another member's column with that member, the library's leave on
 * the one instance and receive on the other. The column of the other bookings gives a block away
 * but takes none. Every drop is put back at once, in each instance it touched: the grid draws what
 * the events prop says, so a block moves only when the server's answer, re-read, says it has.
 */

type Props = {
    view: View;
    date: string;
    blocks: readonly Block[];
    /**
     * What the blocks are coloured by: the member, grey where the staff choice is not real,
     * the service's category or the booking's status.
     */
    colouring: Colouring;
    granularity: number | null | undefined;
    weekStart: number;
    clock: TenantClock;
    onBlock: (uuid: string) => void;
    /**
     * Where an empty slot's date is handed on a click or a drag in Week and Day, with the column's
     * member in Day's member columns; absent, the grid offers no slot selection.
     */
    onSlot?: (date: string, staffId?: number) => void;
    /** The counts per tenant day Month draws, countsOf's answer; Week and Day draw blocks instead. */
    counts?: ReadonlyMap<string, readonly StatusCount[]>;
    /** Where a Month day's press hands that day. */
    onDay?: (date: string) => void;
    /** Week's span, from weekAxisOf; absent, the whole day. */
    span?: { min: string; max: string };
    /**
     * Day's member columns; absent, empty or with no member column among them, Day is the one
     * time grid.
     */
    columns?: readonly DayColumn[];
    /** A Day column's head, the screen's. */
    head?: (column: DayColumn) => ReactNode;
    /** Where a drop of a booking's block is handed; absent, nothing drags. */
    onDrop?: (drop: Drop) => void;
};

const VIEWS: Record<View, string> = { week: 'timeGridWeek', day: 'timeGridDay', month: 'dayGridMonth' };

function pad(value: number): string {
    return String(value).padStart(2, '0');
}

/** A grid date's wall clock: the library holds it in UTC fields, since it was told it is in UTC. */
function timeOf(date: Date): string {
    return `${pad(date.getUTCHours())}:${pad(date.getUTCMinutes())}`;
}

/** The class a Month day's cell carries, the press's target. */
const DAY = 'admin-calendar-day';

/** The Month day a press landed in, read off its cell, or null for a press outside every day. */
function dayPressed(target: EventTarget): string | null {
    return target instanceof Element ? (target.closest<HTMLElement>(`.${DAY}`)?.dataset['date'] ?? null) : null;
}

/** Enter or Space on a Month day's cell, the cell itself holding the focus, as a button takes them. */
function dayKeyed(event: KeyboardEvent): string | null {
    return (event.key === 'Enter' || event.key === ' ') && event.target instanceof HTMLElement && event.target.classList.contains(DAY) ? (event.target.dataset['date'] ?? null) : null;
}

/** A wall clock as the grid's readings spell one, read off a date the library holds in UTC fields. */
function wallOfDate(date: Date): string {
    return date.toISOString().slice(0, 19);
}

function eventOf(block: Block, colouring: Colouring, draggable: boolean): EventInput {
    return {
        id: block.id,
        startEditable: draggable && block.kind === 'booking' && block.movable,
        durationEditable: false,
        title: block.title,
        start: block.start,
        end: block.end,
        classNames: [block.kind === 'gap' ? 'admin-calendar-gap' : 'admin-calendar-block', ...(block.badge === null ? [] : [`admin-calendar-block--${block.badge}`])],
        extendedProps: { block, hue: blockHueOf(block, colouring) },
    };
}

/** A closure on a Day column, drawn behind the blocks with its words. */
function closureEventOf(closure: ClosureBlock): EventInput {
    return { id: closure.id, title: closure.title, start: closure.start, end: closure.end, display: 'background', editable: false, classNames: ['admin-calendar-closure'], extendedProps: { closure } };
}

type Extended = { block: Block; hue: string } | { closure: ClosureBlock; block?: never };

function extendedOf(arg: { event: { extendedProps: Record<string, unknown> } }): Extended {
    return arg.event.extendedProps as Extended;
}

function blockOf(arg: { event: { extendedProps: Record<string, unknown> } }): { block: Block; hue: string } | null {
    const extended = extendedOf(arg);

    return extended.block === undefined ? null : extended;
}

/** The colours on the element itself, so they are the element's computed style and not a rule the theme could lose. */
function paint(arg: EventMountArg): void {
    const painted = blockOf(arg);

    if (painted === null) {
        return;
    }

    const { block, hue } = painted;
    const style = arg.el.style;

    arg.el.dataset['blockId'] = block.id;
    style.setProperty('--admin-block-hue', hue);
    style.color = 'var(--foreground)';

    if (block.kind === 'gap') {
        style.backgroundColor = 'transparent';
        style.backgroundImage = `repeating-linear-gradient(135deg, color-mix(in srgb, ${hue} 40%, transparent) 0 4px, transparent 4px 8px)`;
        style.border = `1px dashed ${hue}`;
    } else {
        style.backgroundColor = fillOf(hue);
        style.border = '0 solid transparent';
        style.borderLeft = `3px solid ${hue}`;
    }
}

/** A block's body: the badge and the title, the time range under it on a block long enough; a closure's words. */
function bodyOf(clock: TenantClock) {
    return (arg: EventContentArg) => {
        const extended = extendedOf(arg);

        if (extended.block === undefined) {
            return <div className="admin-calendar-closure__title text-xs">{extended.closure.title}</div>;
        }

        const { block } = extended;

        return (
            <div className={`admin-calendar-block__body overflow-hidden ${block.kind === 'gap' ? 'text-[11px] leading-[14px] text-muted-foreground' : 'text-xs'}`}>
                <div className={`admin-calendar-block__title truncate ${block.kind === 'gap' ? '' : 'font-semibold'}`}>
                    {block.badge !== null && <BlockBadge badge={block.badge} />}
                    {block.title}
                </div>
                {block.minutes >= TIMED_MINUTES && (
                    <div className="admin-calendar-block__time text-[11px] leading-[14px] text-muted-foreground">
                        {formatTimeOfDay(block.start.slice(11, 16), clock)}-{formatTimeOfDay(block.end.slice(11, 16), clock)}
                    </div>
                )}
            </div>
        );
    };
}

/** The now line's pill at the axis, the tenant's time; the line itself carries nothing. */
function nowOf(clock: TenantClock) {
    return (arg: NowIndicatorContentArg) => (arg.isAxis ? <span className="admin-calendar-now">{formatTimeOfDay(timeOf(arg.date), clock)}</span> : null);
}

/**
 * What every instance shares, so Day's columns step, label and paint as the one grid does: the
 * slots at the tenant's granularity, so a click or a drag lands on a bookable start, labelled on
 * the hour alone; their height is the stylesheet's, from the variable the wrapper carries. The
 * library's now is the tenant's wall clock, the instant spelled with no zone, since the library is
 * told it is in UTC. The library's own names of a date, a day header's label for assistive
 * technology, are ICU's in the page's language: its locale is the language's code alone, so no
 * locale table of the library's is bundled. Not localeOf's locale, which lib/names.ts hands Intl
 * and which is the machine's own where it speaks the language: at en the code alone is to the
 * letter what the library took before this option was passed, where a machine's region would
 * reorder the day and the month of the labels it writes; the names in el are the same.
 */
function sharedOf({ weekStart, granularity, scrollTime, clock, language, onBlock, onDrop }: { weekStart: number; granularity: number | null | undefined; scrollTime: string; clock: TenantClock; language: Language; onBlock: (uuid: string) => void; onDrop?: (drop: Drop) => void }) {
    return {
        locale: language,
        editable: onDrop !== undefined,
        eventDurationEditable: false,
        /** A drop inside one instance: put back at once, then handed with the new wall clock and no member. */
        eventDrop: (arg: EventDropArg) => {
            arg.revert();
            const painted = blockOf(arg);

            if (painted !== null && arg.event.start !== null) {
                onDrop?.({ block: painted.block, start: wallOfDate(arg.event.start), staffId: null });
            }
        },
        plugins: [timeGridPlugin, dayGridPlugin, interactionPlugin],
        headerToolbar: false as const,
        allDaySlot: false,
        timeZone: 'UTC',
        now: () => wallOf(new Date().toISOString(), clock.timezone),
        firstDay: weekStart % 7,
        slotDuration: slotDurationOf(granularity),
        slotLabelInterval: '01:00',
        nowIndicatorContent: nowOf(clock),
        scrollTime,
        scrollTimeReset: true,
        height: 'auto',
        eventContent: bodyOf(clock),
        eventDidMount: paint,
        eventClick: (arg: EventClickArg) => {
            const painted = blockOf(arg);

            if (painted !== null) {
                onBlock(painted.block.uuid);
            }
        },
        unselectAuto: true,
    };
}

type Shared = ReturnType<typeof sharedOf>;

/** The wrapper's slot height, an hour sixty pixels whatever the step (5.1), which the stylesheet gives every slot. */
function scaleOf(granularity: number | null | undefined): CSSProperties {
    return { '--admin-calendar-slot-height': slotHeightOf(granularity) } as CSSProperties;
}

/** The key that draws a grid afresh on a change of Colour by, since the colours are painted as a block mounts. */
function colouringKey(colouring: Colouring): string {
    return `${colouring.by}:${String(colouring.palette)}:${colouring.categories.join('|')}`;
}

type DayColumnsProps = {
    onDrop?: (drop: Drop) => void;
    columns: readonly DayColumn[];
    date: string;
    colouring: Colouring;
    clock: TenantClock;
    shared: Shared;
    scrollTime: string;
    scale: CSSProperties;
    onSlot?: (date: string, staffId?: number) => void;
    head?: (column: DayColumn) => ReactNode;
};

/**
 * Day as member columns, one time-grid instance per column. Each instance is keyed by the date
 * as well as the column and the colouring: the library reads initialDate once, at construction,
 * so a step to another day draws every instance afresh on it, its blocks, hatch and closures.
 */
function DayColumns({ columns, date, colouring, clock, shared, scrollTime, scale, onSlot, head, onDrop }: DayColumnsProps) {
    const words = useCalendarWords();
    const closed = useClosureWords();
    const axis = dayAxisOf(columns, date);
    // The source instance's undo of a drop onto another column: its leave fires before the event
    // leaves it, so the undo runs once the other instance has received it.
    const leaving = useRef<(() => void) | null>(null);

    /** A drop onto another member's column: put back in both instances, then handed with the member. */
    const received = (member: DayColumn['member']) => (arg: EventReceiveArg) => {
        arg.revert();
        leaving.current?.();
        leaving.current = null;
        const painted = blockOf(arg);

        if (member !== null && painted !== null && arg.event.start !== null) {
            onDrop?.({ block: painted.block, start: wallOfDate(arg.event.start), staffId: member.id });
        }
    };

    return (
        <div className="admin-calendar-grid admin-calendar-columns" data-scroll-time={scrollTime} data-axis={`${axis.min}-${axis.max}`} style={scale}>
            <div className="admin-calendar-columns__row flex overflow-x-auto">
                {columns.map((column, at) => {
                    const member = column.member;
                    const idle = column.hours !== null && column.hours.windows.length === 0;
                    const next = idle ? nextLineOf(column.next, clock, words) : null;
                    const closures = column.hours === null ? [] : closureBlocksOf(column.hours, date, clock, column.key, closed);

                    return (
                        <div
                            key={column.key}
                            className={`admin-calendar-column flex shrink grow flex-col ${at === 0 ? 'admin-calendar-column--axis min-w-62 basis-14' : 'admin-calendar-column--bare min-w-48 basis-0'} ${member === null ? 'admin-calendar-column--other' : ''}`}
                            data-column={column.key}
                            data-staff-id={member?.id}
                        >
                            <div className={`admin-calendar-column__head h-25 border-b ${at === 0 ? 'pl-14' : ''}`}>
                                <div className="h-full border-l">{head?.(column)}</div>
                            </div>
                            <div className="admin-calendar-column__body relative">
                                <FullCalendar
                                    key={`${column.key}:${date}:${colouringKey(colouring)}`}
                                    {...shared}
                                    initialView="timeGridDay"
                                    initialDate={date}
                                    dayHeaders={false}
                                    slotMinTime={axis.min}
                                    slotMaxTime={axis.max}
                                    // The now line is drawn by the slats' measured coordinates, so
                                    // jsdom never shows it and no component test asserts it.
                                    nowIndicator={true}
                                    nowIndicatorContent={at === 0 ? shared.nowIndicatorContent : () => null}
                                    businessHours={column.hours === null ? false : businessHoursOf(column.hours, date)}
                                    slotLabelContent={(arg: SlotLabelContentArg) => (at === 0 ? formatTimeOfDay(timeOf(arg.date), clock) : '')}
                                    events={[...closures.map(closureEventOf), ...column.blocks.map((block) => eventOf(block, colouring, onDrop !== undefined))]}
                                    droppable={onDrop !== undefined && member !== null}
                                    eventAllow={member === null ? () => false : undefined}
                                    eventLeave={(arg: EventLeaveArg) => {
                                        leaving.current = arg.revert;
                                    }}
                                    eventReceive={received(member)}
                                    selectable={onSlot !== undefined && member !== null}
                                    select={(arg: DateSelectArg) => {
                                        if (member !== null) {
                                            onSlot?.(utcDayOf(arg.start), member.id);
                                        }
                                    }}
                                />
                                {next !== null && (
                                    <div className={`pointer-events-none absolute inset-y-0 right-0 z-10 flex items-center justify-center px-2 ${at === 0 ? 'left-14' : 'left-0'}`}>
                                        <p className="admin-calendar-column__next rounded-full border bg-card px-3 py-1 text-center text-muted-foreground">{next}</p>
                                    </div>
                                )}
                            </div>
                        </div>
                    );
                })}
            </div>
        </div>
    );
}

export function Grid({ view, date, blocks, colouring, granularity, weekStart, clock, onBlock, onSlot, counts, onDay, span, columns, head, onDrop }: Props) {
    const shell = useShell();
    const calendar = useRef<FullCalendar>(null);
    const scrollTime = scrollTimeOf(blocks, date);
    const month = view === 'month';
    const split = view === 'day' && columns !== undefined && columns.some((column) => column.member !== null);

    useEffect(() => {
        calendar.current?.getApi().changeView(VIEWS[view], date);
    }, [view, date]);

    useEffect(() => {
        calendar.current?.getApi().scrollToTime(scrollTime);
    }, [scrollTime, view, date]);

    /**
     * A column's head: the weekday, ICU's short name in the page's language, and the date in the
     * tenant's format, or the weekday alone over Month's six rows.
     */
    const header = (arg: DayHeaderContentArg) => {
        const weekday = weekdayName(isoWeekday(utcDayOf(arg.date)), 'short', shell.language);

        return month ? weekday : `${weekday} ${formatDate(utcDayOf(arg.date), clock)}`;
    };

    /** A Month day's content, inside the library's day-number element: no control of its own, since the cell is the press. */
    const cell = (arg: DayCellContentArg) => {
        const day = utcDayOf(arg.date);

        return (
            <>
                <span className="sr-only">{formatDate(day, clock)}</span>
                <span className="admin-calendar-day__number" aria-hidden="true">
                    {arg.date.getUTCDate()}
                </span>
                {(counts?.get(day) ?? []).map(({ status, count }) => (
                    <StatusChip key={status} status={status} count={count} />
                ))}
            </>
        );
    };

    /** A pointer press anywhere in a Month day's cell, the cell's own padding included. */
    const pointed = (event: MouseEvent) => {
        const day = dayPressed(event.target);

        if (day !== null) {
            onDay?.(day);
        }
    };

    /** Enter or Space on the focused cell; Space would otherwise scroll the page. */
    const keyed = (event: KeyboardEvent) => {
        const day = dayKeyed(event);

        if (day !== null) {
            event.preventDefault();
            onDay?.(day);
        }
    };

    const shared = sharedOf({ weekStart, granularity, scrollTime, clock, language: shell.language, onBlock, onDrop: month ? undefined : onDrop });
    const scale = scaleOf(granularity);

    if (split) {
        return <DayColumns columns={columns} date={date} colouring={colouring} clock={clock} shared={shared} scrollTime={scrollTime} scale={scale} onSlot={onSlot} head={head} onDrop={onDrop} />;
    }

    return (
        <div className="admin-calendar-grid" data-scroll-time={scrollTime} style={scale} onClick={month ? pointed : undefined} onKeyDown={month ? keyed : undefined}>
            <FullCalendar
                key={colouringKey(colouring)}
                ref={calendar}
                {...shared}
                initialView={VIEWS[view]}
                initialDate={date}
                fixedWeekCount={true}
                slotMinTime={view === 'week' && span !== undefined ? span.min : '00:00:00'}
                slotMaxTime={view === 'week' && span !== undefined ? span.max : '24:00:00'}
                nowIndicator={false}
                slotLabelContent={(arg: SlotLabelContentArg) => formatTimeOfDay(timeOf(arg.date), clock)}
                dayHeaderContent={header}
                dayCellContent={month ? cell : undefined}
                dayCellClassNames={(arg) => (arg.view.type === VIEWS.month ? [DAY, ...(arg.isOther ? [`${DAY}--other`] : [])] : [])}
                dayCellDidMount={(arg: DayCellMountArg) => {
                    if (arg.view.type === VIEWS.month) {
                        arg.el.tabIndex = 0;
                    }
                }}
                events={month ? [] : blocks.map((block) => eventOf(block, colouring, onDrop !== undefined))}
                selectable={onSlot !== undefined && !month}
                select={(arg: DateSelectArg) => onSlot?.(utcDayOf(arg.start))}
            />
        </div>
    );
}
