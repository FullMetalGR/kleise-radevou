import type { DateSelectArg, DayHeaderContentArg, EventClickArg, EventContentArg, EventDropArg, EventInput, EventMountArg, NowIndicatorContentArg, SlotLabelContentArg } from '@fullcalendar/core';
import type { EventDragStartArg, EventLeaveArg, EventReceiveArg } from '@fullcalendar/interaction';
import interactionPlugin from '@fullcalendar/interaction';
import FullCalendar from '@fullcalendar/react';
import timeGridPlugin from '@fullcalendar/timegrid';
import { type CSSProperties, type ReactNode, type RefObject, useCallback, useEffect, useLayoutEffect, useRef } from 'react';
import { isoWeekday, utcDayOf } from '@/lib/day';
import { formatDate, formatTimeOfDay, type TenantClock } from '@/lib/format';
import { weekdayName } from '@/lib/names';
import { useShell } from '@/lib/strings';
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
 * carried on the wrapper as data-scroll-time. At every width the grid's box is bounded by the
 * window, and by the grid's own height, its slots' and what stands round them, so a short span
 * leaves no empty band under its last hour however the grid came to be drawn, and the slots scroll
 * inside it under the day header, which stays at its top; the box opens at that scroll. Today's
 * day number in the day header sits on the highlight.
 *
 * Day as member columns, where the screen hands the columns: one time-grid instance per column side
 * by side in a row that scrolls sideways past a minimum column width, every instance on the same
 * step and the same axis, the shown members' earliest start to latest end that day and at least
 * 08:00 to 20:00, so their rows align under the row's one scroll, the axis labels drawn on the
 * first instance alone. At every width the row is bounded by the window and scrolls both ways,
 * opening at the scroll time, each column's head pinned at its top over the blocks. The row is
 * named a scroller as the library names its own, which is how the library finds what to scroll
 * under a selection dragged to an edge; a held block's drag is the row's own (useHeldScroll), the
 * name taken off the row while a block is pressed, since the library's band along the row's top
 * would lie under the heads. Either way a drag reaches the hours past the row's fold. A block there
 * keeps a scroll margin of the heads' height and eight pixels above it, the stylesheet's rule, so
 * one the keyboard reaches is brought out from under the heads with room for its focus ring; the
 * margin is the block's and not a padding of the row's scroll, which a head's own button could
 * never clear, a focus on one then running the row to its top. Each instance's business hours are
 * the member's windows of the day, so the library hatches the time outside them, the whole column
 * where there are none, its body then carrying the pill naming the next working day; its closures
 * are drawn as background blocks with their words, and its slot hands the member with the date. The
 * screen draws each column's head. The trailing column of the other bookings has no hatch, no
 * closure and no slot. The now line shows in Day.
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

/**
 * The longest block too short for its title under the block's padding. The grid gives a minute a
 * pixel and a block at least 15, and the library keeps the last of them clear under the block, so
 * a block of a quarter of an hour or less is 14 pixels tall. The title starts 4 pixels down, 3 of
 * the block's padding and 1 of the library's, and the small text's line is 16 pixels, so with two
 * pixels to spare under it the block needs 22 pixels, a block of 23 minutes. A block of 22 minutes
 * or less is marked short: its padding above and below goes, and its title, on the 14px line the
 * time under a longer block takes, is centred in it, filling the block at the least height.
 */
const SHORT_MINUTES = 22;

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
            ...(block.minutes <= SHORT_MINUTES ? ['admin-calendar-block--short'] : []),
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

        // A short gap's title takes the block's whole height, so its edge is dashed at the sides alone.
        if (block.minutes <= SHORT_MINUTES) {
            style.borderTopWidth = '0';
            style.borderBottomWidth = '0';
        }
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
                <div className={`admin-calendar-block__title truncate ${block.kind === 'gap' ? '' : 'font-semibold'}${block.minutes <= SHORT_MINUTES ? ' leading-[14px]' : ''}${struck ? ' line-through' : ''}`}>
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

/** The place, among a grid's slot lanes in time order, of the last at or before the time given; -1 where every lane is later. */
function scrollLaneOf(lanes: readonly HTMLElement[], time: string): number {
    const later = lanes.findIndex((lane) => (lane.dataset['time'] ?? '') > time);

    return (later === -1 ? lanes.length : later) - 1;
}

/**
 * How far the first hour's label among the slot lanes given, the first lane's row on, stands above
 * a line: an hour's label is centred on its slot's top line, half of it above the slot, so the
 * first label at or below a slot brought to the line stands that much behind whatever lies over
 * the line. Nothing where it stands clear or no row given carries a label.
 */
function labelRiseOf(lanes: readonly HTMLElement[], line: number): number {
    const label = lanes.map((lane) => lane.parentElement?.querySelector<HTMLElement>('.fc-timegrid-slot-label-cushion')).find((cushion) => cushion !== null && cushion !== undefined && cushion.textContent !== '');

    return label === undefined || label === null ? 0 : Math.max(0, line - label.getBoundingClientRect().top);
}

/** The library's own drag scroll: the band along a scroller's edge, in pixels, and the pace at the very edge, in pixels a second. */
const HELD_EDGE = 50;
const HELD_PACE = 300;

/** A block the pointer presses: a booking's or a gap's, not a closure's fill, which a press selects through. */
const PRESSED_BLOCK = '.fc-event:not(.fc-bg-event)';

/**
 * One edge a held block may scroll, the window's or the row's: how deep its band is, how far the
 * pointer lies inside it, whether its scroller can and may scroll that way, and which way.
 */
export type HeldEdge = { owner: 'window' | 'row'; depth: number; distance: number; can: boolean; axis: 'top' | 'left'; sign: 1 | -1 };

/**
 * The one edge a held block scrolls, as the library picks among its scrollers: the nearest edge
 * whose band holds the pointer and whose scroller can scroll that way, the first listed winning a
 * tie. The window's edges are listed first, so where the window's edge is as near as the row's,
 * the window wins and the row is left still.
 */
export function heldEdgeOf(edges: readonly HeldEdge[]): HeldEdge | undefined {
    return edges.reduce<HeldEdge | undefined>((best, edge) => (edge.can && edge.distance >= 0 && edge.distance <= edge.depth && (best === undefined || edge.distance < best.distance) ? edge : best), undefined);
}

/** Which ways the pointer has moved since a drag began. */
type Moved = { up: boolean; down: boolean; left: boolean; right: boolean };

/**
 * The four edges of one scroller seen in the window, the top's band as deep as given and the
 * other three as deep as the second figure, each able to scroll once the pointer has moved
 * toward it and while the scroller has room left that way.
 */
function edgesOf(
    owner: HeldEdge['owner'],
    scroller: Element,
    box: { top: number; left: number; height: number; width: number },
    [head, rest]: readonly [number, number],
    moved: Moved,
    x: number,
    y: number,
): HeldEdge[] {
    return [
        { owner, depth: head, distance: y - box.top, can: moved.up && scroller.scrollTop > 0, axis: 'top', sign: -1 },
        { owner, depth: rest, distance: box.top + box.height - y, can: moved.down && scroller.scrollTop < scroller.scrollHeight - scroller.clientHeight, axis: 'top', sign: 1 },
        { owner, depth: rest, distance: x - box.left, can: moved.left && scroller.scrollLeft > 0, axis: 'left', sign: -1 },
        { owner, depth: rest, distance: box.left + box.width - x, can: moved.right && scroller.scrollLeft < scroller.scrollWidth - scroller.clientWidth, axis: 'left', sign: 1 },
    ];
}

/**
 * The row of Day's columns scrolls itself under a held block, the library's own drag scroll kept
 * off it: the library scrolls an element only in a band 50px deep along each edge, and along the
 * row's top that band lies under the heads, so a block held over the heads' lower half, nearest
 * the slots, scrolled nothing. Here the band along the top is the heads' whole height, and the
 * foot and the sides keep the library's 50px. The pace is the library's: at most 300px a second
 * at the very edge, falling with the square of the distance to nothing at the band's inner side,
 * so the top's band, twice as deep, ramps over twice the distance. As the library does, an edge
 * scrolls only once the pointer has moved toward it since the drag began and while its scroller
 * can still scroll that way, the nearest such edge winning; the window's edges, which the library
 * still scrolls, are weighed too, and where one of them wins the row is left still. The scroll is
 * written whole pixels at a time onto the row's live offset, the fraction carried to the next
 * frame, so a slow pace still moves and a scroll from elsewhere is never undone. It starts with a
 * drag and stops, as the library's does, the moment the pointer is let go or the touch is lost,
 * before a block let go in no slot glides back.
 *
 * The library takes the scrollers it drives when a drag starts, after the press, a block's drag
 * once the pointer has moved or the touch been held. So a press on a block takes the row's
 * scroller name away first, leaving the row out of that drag, and the name is given back when the
 * press ends, is cancelled, or the drag stops. A press anywhere else in the row, which selects
 * slots, leaves the row the library's to scroll.
 */
function useHeldScroll(row: RefObject<HTMLDivElement | null>): { start: (arg: EventDragStartArg) => void; stop: () => void } {
    const halt = useRef<(() => void) | null>(null);

    const start = useCallback(
        (arg: EventDragStartArg) => {
            halt.current?.();
            const scroller = row.current;

            if (scroller === null) {
                return;
            }

            const first = 'touches' in arg.jsEvent ? (arg.jsEvent as unknown as TouchEvent).touches[0] : arg.jsEvent;
            let x = first?.clientX ?? 0;
            let y = first?.clientY ?? 0;
            const moved: Moved = { up: false, down: false, left: false, right: false };
            let carried = { top: 0, left: 0 };
            let last = performance.now();
            let frame = 0;

            const track = (left: number, top: number) => {
                moved.up ||= top < y;
                moved.down ||= top > y;
                moved.left ||= left < x;
                moved.right ||= left > x;
                x = left;
                y = top;
            };
            const mouse = (event: MouseEvent) => track(event.clientX, event.clientY);
            const touch = (event: TouchEvent) => {
                const point = event.touches[0];

                if (point !== undefined) {
                    track(point.clientX, point.clientY);
                }
            };
            const step = (now: number) => {
                const seconds = (now - last) / 1000;
                last = now;
                const box = scroller.getBoundingClientRect();
                const left = box.left + scroller.clientLeft;
                const top = box.top + scroller.clientTop;
                const band = scroller.querySelector<HTMLElement>('.admin-calendar-column__head')?.offsetHeight ?? HELD_EDGE;
                const own = edgesOf('row', scroller, { top, left, height: scroller.clientHeight, width: scroller.clientWidth }, [band, HELD_EDGE], moved, x, y);
                // The pointer must lie inside the row for any of its edges to count, as the library asks of each scroller.
                const inside = own.every((edge) => edge.distance >= 0);
                const view = document.documentElement;
                const edge = heldEdgeOf([
                    ...edgesOf('window', document.scrollingElement ?? view, { top: 0, left: 0, height: view.clientHeight, width: view.clientWidth }, [HELD_EDGE, HELD_EDGE], moved, x, y),
                    ...own.map((each) => ({ ...each, can: inside && each.can })),
                ]);

                if (edge === undefined || edge.owner === 'window') {
                    carried = { top: 0, left: 0 };
                } else {
                    const ease = (edge.depth - edge.distance) / edge.depth;
                    const due = carried[edge.axis] + edge.sign * ease * ease * HELD_PACE * seconds;
                    const whole = Math.trunc(due);
                    carried = { top: 0, left: 0, [edge.axis]: due - whole };

                    if (edge.axis === 'top') {
                        scroller.scrollTop += whole;
                    } else {
                        scroller.scrollLeft += whole;
                    }
                }

                frame = requestAnimationFrame(step);
            };

            const end = () => halt.current?.();

            document.addEventListener('mousemove', mouse);
            document.addEventListener('touchmove', touch, { passive: true });
            document.addEventListener('mouseup', end);
            document.addEventListener('touchend', end);
            document.addEventListener('touchcancel', end);
            frame = requestAnimationFrame(step);
            halt.current = () => {
                cancelAnimationFrame(frame);
                document.removeEventListener('mousemove', mouse);
                document.removeEventListener('touchmove', touch);
                document.removeEventListener('mouseup', end);
                document.removeEventListener('touchend', end);
                document.removeEventListener('touchcancel', end);
                halt.current = null;
            };
        },
        [row],
    );

    const stop = useCallback(() => {
        halt.current?.();
        row.current?.classList.add('fc-scroller');
    }, [row]);

    useEffect(() => {
        const scroller = row.current;

        if (scroller === null) {
            return undefined;
        }

        const named = () => scroller.classList.add('fc-scroller');
        const press = (event: PointerEvent) => {
            if (event.target instanceof Element && event.target.closest(PRESSED_BLOCK) !== null) {
                scroller.classList.remove('fc-scroller');
            }
        };

        scroller.addEventListener('pointerdown', press, true);
        document.addEventListener('pointerup', named);
        document.addEventListener('pointercancel', named);

        return () => {
            halt.current?.();
            scroller.removeEventListener('pointerdown', press, true);
            document.removeEventListener('pointerup', named);
            document.removeEventListener('pointercancel', named);
            named();
        };
    }, [row]);

    return { start, stop };
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
    const held = useHeldScroll(row);

    // The row opens at the scroll time: the last slot of the first column at or before it is
    // brought up under the heads, or the row stays at the axis's start where the time is earlier.
    // The first hour's label at or below that slot rises above its own slot by half its height;
    // the row stops short by as much of it as would stand behind the heads, so the first label in
    // sight stands whole under them, and the slot, a little lower, stays in sight.
    useEffect(() => {
        const scroller = row.current;
        const lanes = [...(scroller?.querySelector('.admin-calendar-column')?.querySelectorAll<HTMLElement>('.fc-timegrid-slot-lane[data-time]') ?? [])];
        const at = scrollLaneOf(lanes, scrollTime);
        const lane = lanes[at];

        if (scroller === null || lane === undefined) {
            return;
        }

        const line = scroller.getBoundingClientRect().top + (scroller.querySelector<HTMLElement>('.admin-calendar-column__head')?.offsetHeight ?? 0);
        scroller.scrollTop += lane.getBoundingClientRect().top - line;
        // The label's rise is read once the row has scrolled and taken off rounded up to a whole
        // pixel: the row keeps its offset in whole pixels, so a fraction would be dropped and leave
        // the label's top under the heads.
        scroller.scrollTop -= Math.ceil(labelRiseOf(lanes.slice(at), line));
    }, [scrollTime, date]);

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
            <div ref={row} className="admin-calendar-columns__row fc-scroller flex max-h-(--admin-calendar-box) overflow-x-auto overflow-y-auto">
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
                            <div className={`admin-calendar-column__head sticky top-0 z-20 h-25 shrink-0 border-b bg-card ${at === 0 ? 'pl-14' : ''}`}>
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
                                    eventDragStart={held.start}
                                    eventDragStop={held.stop}
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
    const calendar = useRef<FullCalendar>(null);
    const box = useRef<HTMLDivElement>(null);
    const scrollTime = scrollTimeOf(blocks, date);
    const split = view === 'day' && columns !== undefined && columns.some((column) => column.member !== null);

    /*
     * The grid's own height, at every width: everything round the slots' scroller, as drawn, and
     * the slots whole, carried as a variable the box takes the smaller of it and the window's
     * figure from. The slots are measured themselves and not the body round them, which the library
     * stretches to fill a scroller taller than they are, so the figure is the same whatever height
     * the box has when it is taken. A grid not laid out, hidden or in a test's document, measures
     * nothing.
     */
    const fit = useCallback(() => {
        const wrapper = box.current;
        const root = wrapper?.querySelector<HTMLElement>('.fc');
        const slots = wrapper?.querySelector<HTMLElement>('.fc-timegrid-slots');
        const scroller = slots?.closest<HTMLElement>('.fc-scroller');

        if (!wrapper || !root || !slots || !scroller || root.offsetHeight === 0 || slots.offsetHeight === 0) {
            return;
        }

        const height = `${root.offsetHeight - scroller.clientHeight + slots.offsetHeight}px`;

        if (wrapper.style.getPropertyValue('--admin-calendar-fit') !== height) {
            wrapper.style.setProperty('--admin-calendar-fit', height);
            calendar.current?.getApi().updateSize();
        }
    }, []);

    /*
     * The day heads are this page's own markup, which the library lays into the head row only once
     * its own drawing is done, after this effect has run; until then the row is short of a line.
     * So the figure is taken again on the next frame, with the heads in.
     */
    useLayoutEffect(() => {
        fit();
        const again = requestAnimationFrame(fit);

        return () => cancelAnimationFrame(again);
    }, [fit, view, date, span?.min, span?.max, split]);

    /*
     * The library tells of a window's resize before it has laid the heads out again, and a head
     * whose column crosses its narrow width changes height as it turns to its smaller size, so the
     * figure is taken again on the next frame as well, with the heads at their new height.
     */
    const refit = useCallback(() => {
        fit();
        requestAnimationFrame(fit);
    }, [fit]);

    useEffect(() => {
        calendar.current?.getApi().changeView(VIEWS[view], date);
    }, [view, date]);

    /*
     * The library brings the slot of the scroll time to its scroller's top, a pixel past the slot's
     * top line, where an hour's label, centred on that line, stands half behind the day header.
     * Once the library has scrolled, the scroller is brought up by as much of the first hour's
     * label at or below that slot as stands above its top, so the label stands whole and the slot,
     * a little lower, stays in sight. It runs on the frame after the library sets its dates, by
     * when its scroll on opening or on a step to other dates is done, and straight after this grid
     * asks it for the scroll time. Only a scroll the library has just made is moved, the slot
     * standing at the scroller's top where the library leaves it, so a scroll of the admin's own
     * is kept and a second run moves nothing.
     */
    const lift = useCallback(() => {
        const scroller = [...(box.current?.querySelectorAll<HTMLElement>('.fc-scroller') ?? [])].find((each) => each.querySelector('.fc-timegrid-body') !== null);
        const lanes = [...(scroller?.querySelectorAll<HTMLElement>('.fc-timegrid-slot-lane[data-time]') ?? [])];
        const at = scrollLaneOf(lanes, scrollTime);
        const lane = lanes[at];

        if (scroller === undefined || lane === undefined) {
            return;
        }

        const line = scroller.getBoundingClientRect().top + scroller.clientTop;

        if (Math.abs(lane.getBoundingClientRect().top - line) <= 2.5) {
            scroller.scrollTop -= Math.ceil(labelRiseOf(lanes.slice(at), line));
        }
    }, [scrollTime]);

    useEffect(() => {
        calendar.current?.getApi().scrollToTime(scrollTime);
        lift();
    }, [scrollTime, view, date, lift]);

    /**
     * A column's head: the weekday, ICU's short name in the page's language, over the day of the
     * month, large in the display face on a full pill, today's on the highlight. The head is a
     * container: narrower than 116px, as Week's seven columns are on a phone, the same two lines
     * stand at a smaller size. The pill is never narrower than it is tall, its line and its padding
     * above and below, so a day of one digit stands on a round pill. The number is drawn from an
     * attribute and kept from readers, and the date in the tenant's format stands after the weekday
     * for readers alone, so the head reads as the weekday and the whole date at every width.
     */
    const header = (arg: DayHeaderContentArg) => {
        const day = utcDayOf(arg.date);
        const weekday = weekdayName(isoWeekday(day), 'short', shell.language);
        const date = formatDate(day, clock);
        const number = [
            'admin-calendar-grid__day min-w-[calc(1lh+2px)] rounded-full px-2.5 py-px text-center font-display text-tile-title tabular-nums after:content-[attr(data-day)] @max-[116px]:px-1 @max-[116px]:text-base',
            arg.isToday ? 'admin-calendar-grid__today bg-spark text-spark-foreground' : 'text-foreground',
        ].join(' ');

        return (
            <span className="admin-calendar-grid__head @container block">
                <span className="flex flex-col items-center gap-0.5">
                    <span className="admin-calendar-grid__weekday text-label @max-[116px]:text-xs">{weekday}</span>{' '}
                    <span className="sr-only">{date}</span>
                    <span aria-hidden="true" data-day={Number(day.slice(8, 10))} className={number} />
                </span>
            </span>
        );
    };

    const shared = sharedOf({ weekStart, granularity, scrollTime, clock, language: shell.language, onBlock, onDrop });
    const scale = scaleOf(granularity);

    if (split) {
        return <DayColumns columns={columns} date={date} colouring={colouring} clock={clock} shared={shared} scrollTime={scrollTime} scale={scale} onSlot={onSlot} head={head} onDrop={onDrop} />;
    }

    return (
        <div ref={box} className="admin-calendar-grid h-[min(var(--admin-calendar-box),var(--admin-calendar-fit,var(--admin-calendar-box)))]" data-scroll-time={scrollTime} style={scale}>
            <FullCalendar
                key={colouringKey(colouring)}
                ref={calendar}
                {...shared}
                // The wrapper's height bounds the grid at every width, so the library scrolls its
                // slots inside it under the day header and the page never carries the hours.
                height="100%"
                windowResize={refit}
                datesSet={() => requestAnimationFrame(lift)}
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
