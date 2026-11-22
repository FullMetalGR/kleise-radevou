import type { DateSelectArg, DayHeaderContentArg, EventClickArg, EventContentArg, EventDropArg, EventInput, EventMountArg, NowIndicatorContentArg, SlotLabelContentArg } from '@fullcalendar/core';
import type { EventLeaveArg, EventReceiveArg } from '@fullcalendar/interaction';
import interactionPlugin from '@fullcalendar/interaction';
import FullCalendar from '@fullcalendar/react';
import timeGridPlugin from '@fullcalendar/timegrid';
import { type CSSProperties, type ReactNode, useCallback, useEffect, useLayoutEffect, useRef } from 'react';
import { isoWeekday, utcDayOf } from '@/lib/day';
import { formatDate, formatTimeOfDay, type TenantClock } from '@/lib/format';
import { weekdayName } from '@/lib/names';
import { useShell } from '@/lib/strings';
import { useDesktop } from '@/lib/viewport';
import type { Language } from '../../booking/language';
import { BlockBadge, InsideMarker } from './Badge';
import { type Block, blockFillOf, blockHueOf, businessHoursOf, type ClosureBlock, closureBlocksOf, type Colouring, type DayColumn, dayAxisOf, type Drop, nextLineOf, scrollTimeOf, slotDurationOf, slotHeightOf, struckOf, TIMED_MINUTES, type View, wallOf } from './model';
import { useCalendarWords, useClosureWords } from './words';

/*
 * The calendar grid of Week and Day, over FullCalendar's time grid: its own toolbar off, because
 * the screen owns the view and the date, Week and Day and no other view, Month being a grid of its
 * own (Month), the axis stepping at the tenant's granularity, the columns from the tenant's week
 * start. The library is told it is in UTC and handed wall-clock strings with no zone, so it draws
 * every instant at the tenant's hour and knows no daylight saving of its own: a block at 04:30 on
 * the day the clocks go back sits at 04:30.
 *
 * A block's hue is what Colour by says: its member's, its service's category's or its status's chip
 * tone. Its fill is the hue mixed toward the surface, set on the element itself with a
 * full-saturation left border four pixels wide and the theme's text colour; a booking cancelled,
 * rejected, missed or expired wears the softer fill of the tertiary ink and its title struck through, and
 * one awaiting approval or payment its outline, the stylesheet's rule on its class; a gap is hatch
 * stripes in the hue over nothing, inside a dashed border. The body is the title, bold and
 * ellipsised, a badge before it
 * for a booking awaiting approval or awaiting payment alone, the marker for a booking a closure
 * holds before both, and the time range under it only when the block is at least forty-five
 * minutes long. The marker leads the line so that it is never the part a narrow column cuts off:
 * where the line overflows, the title's end is what goes. The body is a container, so
 * that the marker draws itself by the body's width, worded or a mark alone, sooner the mark where
 * a badge shares the line, and leaves the title its room. The scroll on every view or date change
 * is one hour before the day's earliest block or the fixed morning hour, computed by the model and
 * carried on the wrapper as data-scroll-time. From the desktop breakpoint the grid's box is bounded
 * by the window, and by the grid's own height, its slots' and what stands round them, so a short
 * span leaves no empty band under its last hour however the grid came to be drawn, and the slots
 * scroll inside it under the day header, which stays at its top; the box opens at that scroll.
 * Today's date in the day header sits on the highlight.
 *
 * Day as member columns, where the screen hands the columns: one time-grid instance per column side
 * by side in a row that scrolls sideways past a minimum column width, every instance on the same
 * step and the same axis, the shown members' earliest start to latest end that day and at least
 * 08:00 to 20:00, so their rows align under the row's one scroll, the axis labels drawn on the
 * first instance alone. From the desktop breakpoint the row is bounded by the window and scrolls
 * both ways, opening at the scroll time, each column's head pinned at its top over the blocks. The
 * row is named a scroller as the library names its own, which is how the library finds what to
 * scroll under a block held at an edge, so a drag reaches the hours past the row's fold. A block
 * there keeps a scroll margin of the heads' height and eight pixels above it, the stylesheet's
 * rule, so one the keyboard reaches is brought out from under the heads with room for its focus
 * ring; the margin is the block's and not a padding of the row's scroll, which a head's own
 * button could never clear, a focus on one then running the row to its top. Each instance's
 * business hours are the member's windows of the day, so the library hatches the time outside them, the whole column where there are none, its body then
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

/** The views the time grid draws. */
type GridView = Exclude<View, 'month'>;

type Props = {
    view: GridView;
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

const VIEWS: Record<GridView, string> = { week: 'timeGridWeek', day: 'timeGridDay' };

function pad(value: number): string {
    return String(value).padStart(2, '0');
}

/** A grid date's wall clock: the library holds it in UTC fields, since it was told it is in UTC. */
function timeOf(date: Date): string {
    return `${pad(date.getUTCHours())}:${pad(date.getUTCMinutes())}`;
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
        classNames: [
            block.kind === 'gap' ? 'admin-calendar-gap' : 'admin-calendar-block',
            ...(block.badge === null ? [] : [`admin-calendar-block--${block.badge}`]),
            ...(block.inside ? ['admin-calendar-block--inside'] : []),
            ...(block.kind === 'booking' && struckOf(block.status) ? ['admin-calendar-block--struck'] : []),
        ],
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
        style.backgroundColor = blockFillOf(hue, block.status);
        style.border = '0 solid transparent';
        style.borderLeft = `4px solid ${hue}`;
    }
}

/**
 * A block's body: the marker, the badge and the title, the time range under it on a block long
 * enough; a closure's words. The body is the container its pills are measured against: the marker
 * is worded and the badge drawn where the body has the width for them, and the title's line is
 * ellipsised at every width.
 */
function bodyOf(clock: TenantClock) {
    return (arg: EventContentArg) => {
        const extended = extendedOf(arg);

        if (extended.block === undefined) {
            return <div className="admin-calendar-closure__title text-xs">{extended.closure.title}</div>;
        }

        const { block } = extended;
        const pills = block.inside && block.badge !== null;
        const struck = block.kind === 'booking' && struckOf(block.status);

        return (
            <div className={`admin-calendar-block__body @container overflow-hidden ${block.kind === 'gap' ? 'text-xs leading-[14px] text-muted-foreground' : 'text-xs'}`}>
                <div className={`admin-calendar-block__title truncate ${block.kind === 'gap' ? '' : 'font-semibold'}${struck ? ' line-through' : ''}`}>
                    {block.inside && <InsideMarker beside={pills} />}
                    {block.badge !== null && <BlockBadge badge={block.badge} fitted />}
                    {block.title}
                </div>
                {block.minutes >= TIMED_MINUTES && (
                    <div className="admin-calendar-block__time text-xs leading-[14px] text-muted-foreground tabular-nums">
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
        plugins: [timeGridPlugin, interactionPlugin],
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

/** The wrapper's slot height, an hour sixty pixels whatever the step, which the stylesheet gives every slot. */
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
    const row = useRef<HTMLDivElement>(null);
    const desktop = useDesktop();

    // The row opens at the scroll time: the last slot of the first column at or before it is
    // brought up under the heads, or the row stays at the axis's start where the time is earlier.
    // A window grown to the desktop breakpoint opens there too: narrower, the row had no scroll
    // of its own to open at.
    useEffect(() => {
        const scroller = row.current;
        const lanes = [...(scroller?.querySelector('.admin-calendar-column')?.querySelectorAll<HTMLElement>('.fc-timegrid-slot-lane[data-time]') ?? [])];
        const lane = lanes.filter((slot) => (slot.dataset['time'] ?? '') <= scrollTime).at(-1);

        if (scroller === null || lane === undefined) {
            return;
        }

        const head = scroller.querySelector<HTMLElement>('.admin-calendar-column__head')?.offsetHeight ?? 0;
        scroller.scrollTop += lane.getBoundingClientRect().top - scroller.getBoundingClientRect().top - head;
    }, [scrollTime, date, desktop]);

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
            <div ref={row} className="admin-calendar-columns__row fc-scroller flex overflow-x-auto desktop:max-h-(--admin-calendar-box) desktop:overflow-y-auto">
                {columns.map((column, at) => {
                    const member = column.member;
                    const idle = column.hours !== null && column.hours.windows.length === 0;
                    const next = idle ? nextLineOf(column.next, clock, words) : null;
                    const closures = column.hours === null ? [] : closureBlocksOf(column.hours, date, clock, column.key, closed);

                    return (
                        <div
                            key={column.key}
                            className={`admin-calendar-column flex shrink grow flex-col ${at === 0 ? 'admin-calendar-column--axis min-w-74 desktop:min-w-71.5 basis-14' : 'admin-calendar-column--bare min-w-60 desktop:min-w-57.5 basis-0'} ${member === null ? 'admin-calendar-column--other' : ''}`}
                            data-column={column.key}
                            data-staff-id={member?.id}
                        >
                            <div className={`admin-calendar-column__head h-25 shrink-0 border-b bg-card desktop:sticky desktop:top-0 desktop:z-20 ${at === 0 ? 'pl-14' : ''}`}>
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

export function Grid({ view, date, blocks, colouring, granularity, weekStart, clock, onBlock, onSlot, span, columns, head, onDrop }: Props) {
    const shell = useShell();
    const desktop = useDesktop();
    const calendar = useRef<FullCalendar>(null);
    const box = useRef<HTMLDivElement>(null);
    const scrollTime = scrollTimeOf(blocks, date);
    const split = view === 'day' && columns !== undefined && columns.some((column) => column.member !== null);

    /*
     * The grid's own height, from the desktop breakpoint: everything round the slots' scroller, as
     * drawn, and the slots whole, carried as a variable the box takes the smaller of it and the
     * window's figure from. The slots are measured themselves and not the body round them, which
     * the library stretches to fill a scroller taller than they are, so the figure is the same
     * whatever height the box has when it is taken. A grid not laid out, hidden or in a test's
     * document, measures nothing.
     */
    const fit = useCallback(() => {
        const wrapper = box.current;
        const root = wrapper?.querySelector<HTMLElement>('.fc');
        const slots = wrapper?.querySelector<HTMLElement>('.fc-timegrid-slots');
        const scroller = slots?.closest<HTMLElement>('.fc-scroller');

        if (!desktop || !wrapper || !root || !slots || !scroller || root.offsetHeight === 0 || slots.offsetHeight === 0) {
            return;
        }

        const height = `${root.offsetHeight - scroller.clientHeight + slots.offsetHeight}px`;

        if (wrapper.style.getPropertyValue('--admin-calendar-fit') !== height) {
            wrapper.style.setProperty('--admin-calendar-fit', height);
            calendar.current?.getApi().updateSize();
        }
    }, [desktop]);

    useLayoutEffect(fit, [fit, view, date, span?.min, span?.max, split]);

    useEffect(() => {
        calendar.current?.getApi().changeView(VIEWS[view], date);
    }, [view, date]);

    useEffect(() => {
        calendar.current?.getApi().scrollToTime(scrollTime);
    }, [scrollTime, view, date]);

    /** A column's head: the weekday, ICU's short name in the page's language, and the date in the tenant's format, today's on the highlight. */
    const header = (arg: DayHeaderContentArg) => {
        const day = utcDayOf(arg.date);
        const weekday = weekdayName(isoWeekday(day), 'short', shell.language);

        return arg.isToday ? (
            <>
                {weekday} <span className="admin-calendar-grid__today rounded-full bg-spark px-1.5 py-0.5 text-spark-foreground tabular-nums">{formatDate(day, clock)}</span>
            </>
        ) : (
            `${weekday} ${formatDate(day, clock)}`
        );
    };

    const shared = sharedOf({ weekStart, granularity, scrollTime, clock, language: shell.language, onBlock, onDrop });
    const scale = scaleOf(granularity);

    if (split) {
        return <DayColumns columns={columns} date={date} colouring={colouring} clock={clock} shared={shared} scrollTime={scrollTime} scale={scale} onSlot={onSlot} head={head} onDrop={onDrop} />;
    }

    return (
        <div ref={box} className="admin-calendar-grid desktop:h-[min(var(--admin-calendar-box),var(--admin-calendar-fit,var(--admin-calendar-box)))]" data-scroll-time={scrollTime} style={scale}>
            <FullCalendar
                key={colouringKey(colouring)}
                ref={calendar}
                {...shared}
                // From the desktop breakpoint the wrapper's height bounds the grid, so the library
                // scrolls its slots inside it under the day header; below it the grid is as tall as
                // its slots.
                height={desktop ? '100%' : 'auto'}
                windowResize={fit}
                initialView={VIEWS[view]}
                initialDate={date}
                slotMinTime={view === 'week' && span !== undefined ? span.min : '00:00:00'}
                slotMaxTime={view === 'week' && span !== undefined ? span.max : '24:00:00'}
                nowIndicator={false}
                slotLabelContent={(arg: SlotLabelContentArg) => formatTimeOfDay(timeOf(arg.date), clock)}
                dayHeaderContent={header}
                events={blocks.map((block) => eventOf(block, colouring, onDrop !== undefined))}
                selectable={onSlot !== undefined}
                select={(arg: DateSelectArg) => onSlot?.(utcDayOf(arg.start))}
            />
        </div>
    );
}
