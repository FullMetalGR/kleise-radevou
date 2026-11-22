import { Users } from 'lucide-react';
import { Fragment, useState } from 'react';
import { Initials } from '@/components/initials';
import { isoWeekday } from '@/lib/day';
import { formatTimeOfDay, type TenantClock } from '@/lib/format';
import { weekdayName } from '@/lib/names';
import { cn } from '@/lib/utils';
import { useDesktop } from '@/lib/viewport';
import { type Aside, CappedChips } from './CappedChips';
import { type ChipDrag, type Spot, TARGETED, useChipDrag } from './chip-drag';
import { DateMenu } from './DateMenu';
import { type ChipDrop, type Colouring, colourOf, dayTitleOf, type Member, NOT_WORKING_THIS_DAY, OTHER_BOOKINGS } from './model';
import type { ClosureLine, WeekCell, WeekRow } from './staff-week';
import { type CalendarKey, useCalendarWords } from './words';

/*
 * Week for a team, drawn: a head of the week's days, each a control opening Day view on its date,
 * over a row per member, their initials and name at its start, and the other bookings' row last
 * where the week has one (calendar/staff-week). A member's day lists its chips, the first six and
 * a line saying how many more, its closures with a time as grey lines among them; the rest of the
 * cell is its date menu, New booking and New closure on the member and the day, and Open day. A
 * day off, or a day closed over its hours, is hatched and still lists, opens and takes drops. The
 * other bookings' row has no menu, no hatch and no line, and takes no drop. Hovering a cell marks
 * its row's head and its day's head.
 *
 * A chip drags to another cell where the booking is movable: to another day it moves by whole
 * days, its time kept; to another member's row it hands that member every item it stands for.
 *
 * Below the breakpoint the grid fits the screen: the member column narrows to the initials, the
 * other bookings' row to a mark of its own, each name kept for a screen reader; a chip is its
 * start time alone and a closure line its start alone; the line saying how many more is
 * a plus and the figure, and opens Day on its date rather than a popover, narrowed to the row's
 * member where the caller can narrow. Nothing drags there.
 */

/** The most chips a day of a row shows before the line saying how many more. */
const CAP = 6;

/** The head of a day: it opens Day view on its date. */
export const TO_DAY: CalendarKey = 'grid.to_day';

type Props = {
    /** The week's seven days. */
    days: readonly string[];
    rows: readonly WeekRow[];
    /** The tenant's today. */
    today: string;
    colouring: Colouring;
    clock: TenantClock;
    onOpen: (uuid: string) => void;
    /** A new booking on the member and the day, where the viewer may add one. */
    onBook?: (date: string, staffId: number) => void;
    /** A new closure on the member and the day, where the viewer may add one. */
    onClosure?: (date: string, staffId: number) => void;
    onDay: (date: string) => void;
    /** Day on the date narrowed to the member, which a phone's line saying how many more opens where the caller can narrow. */
    onMember?: (date: string, staffId: number) => void;
    /** A chip dropped on another cell, where drags are handled. */
    onDrop?: (drop: ChipDrop) => void;
};

/** The cell under the pointer, by its row and its day. */
type Hover = { row: string; day: string } | null;

function DayHead({ date, today, marked, clock, onDay }: { date: string; today: string; marked: boolean; clock: TenantClock; onDay: (date: string) => void }) {
    const words = useCalendarWords();

    return (
        <button
            type="button"
            data-day={date}
            title={words.text(TO_DAY)}
            aria-current={date === today ? 'date' : undefined}
            className={cn(
                'admin-calendar-week__day flex min-w-0 cursor-pointer flex-col items-center gap-0.5 border-b border-l px-1 py-2.5 text-xs outline-none hover:bg-accent focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:ring-inset',
                marked && 'admin-calendar-week__day--marked bg-accent',
            )}
            onClick={() => onDay(date)}
        >
            <span aria-hidden="true" className="font-semibold text-muted-foreground">
                {weekdayName(isoWeekday(date), 'short', words.language)}
            </span>
            <span aria-hidden="true" className={cn('inline-flex size-6 items-center justify-center rounded-full font-medium tabular-nums', date === today && 'admin-calendar-week__today bg-spark text-spark-foreground')}>
                {Number(date.slice(8, 10))}
            </span>
            <span className="sr-only">{dayTitleOf(date, clock, words)}</span>
        </button>
    );
}

/** The other bookings' row's mark where the column is too narrow for its name: a round as wide as the initials, holding no one in particular. */
function NoOne() {
    return (
        <span aria-hidden="true" className="admin-calendar-week__no-one inline-flex size-8 shrink-0 items-center justify-center rounded-full bg-muted">
            <Users className="size-4" />
        </span>
    );
}

function RowHead({ row, colouring, marked, desktop }: { row: WeekRow; colouring: Colouring; marked: boolean; desktop: boolean }) {
    const words = useCalendarWords();
    const name = row.member === null ? words.text(OTHER_BOOKINGS) : row.member.name;

    return (
        <div
            data-row={row.key}
            className={cn(
                'admin-calendar-week__person flex min-w-0 items-center border-b py-2.5 text-sm font-semibold',
                desktop ? 'gap-2.5 pr-2 pl-4' : 'justify-center',
                row.member === null && 'text-muted-foreground',
                marked && 'admin-calendar-week__person--marked bg-accent',
            )}
        >
            {row.member !== null && <Initials name={row.member.name} hue={colourOf(row.member.id, colouring.palette, colouring.own)} size="row" />}
            {row.member === null && !desktop && <NoOne />}
            {desktop ? (
                <span className="truncate" title={name}>
                    {name}
                </span>
            ) : (
                <span className="sr-only">{name}</span>
            )}
        </div>
    );
}

/** A closure with a time among a day's chips: a grey line of its range and its reason, read as its whole sentence; cut short, its start alone, which a day of a phone's week has room for. */
function ClosureMark({ line, clock, short }: { line: ClosureLine; clock: TenantClock; short: boolean }) {
    return (
        <p className={cn('admin-calendar-week__closure pointer-events-auto flex min-w-0 items-center gap-1 rounded-sm bg-muted text-xs leading-4 text-muted-foreground', short ? 'justify-center overflow-hidden px-0' : 'px-1')} title={line.title}>
            {!short && <span className="admin-calendar-closure-swatch size-2.5 shrink-0 rounded-[2px]" aria-hidden="true" />}
            <span aria-hidden="true" className={cn('shrink-0 tabular-nums', short && 'tracking-tight')}>
                {short ? formatTimeOfDay(line.start.slice(11, 16), clock) : line.range}
            </span>
            {line.reason !== null && !short && (
                <span aria-hidden="true" className="truncate">
                    {line.reason}
                </span>
            )}
            <span className="sr-only">{line.title}</span>
        </p>
    );
}

type CellProps = Pick<Props, 'colouring' | 'clock' | 'onOpen' | 'onBook' | 'onClosure' | 'onDay' | 'onMember'> & { row: WeekRow; cell: WeekCell; drag: ChipDrag; onHover: (hover: Hover) => void; desktop: boolean };

/** Where a phone's line saying how many more goes: Day on the row's member where the caller can narrow to them, else Day alone. */
function moreOf(row: WeekRow, date: string, onDay: Props['onDay'], onMember: Props['onMember']): () => void {
    const { member } = row;

    return member !== null && onMember !== undefined ? () => onMember(date, member.id) : () => onDay(date);
}

/** A day of a row: its date menu where the row is a member's, its chips with its closure lines, its hatch, and the drop it takes. */
function Cell({ row, cell, colouring, clock, onOpen, onBook, onClosure, onDay, onMember, drag, onHover, desktop }: CellProps) {
    const words = useCalendarWords();
    const { member } = row;
    const spot: Spot = { key: `${row.key}:${cell.date}`, date: cell.date, staffId: member?.id ?? null };
    const day = dayTitleOf(cell.date, clock, words);
    const title = words.text('day.menu', { staff: member?.name ?? words.text(OTHER_BOOKINGS), date: day });
    const asides: Aside[] = cell.lines.map((line) => ({ key: line.key, start: line.start, node: <ClosureMark line={line} clock={clock} short={!desktop} /> }));

    return (
        <div
            data-row={row.key}
            data-date={cell.date}
            {...(member === null || !desktop ? {} : drag.target(spot))}
            className={cn(
                'admin-calendar-week__cell relative min-h-20 min-w-0 border-b border-l',
                cell.hatched && 'admin-calendar-week__cell--hatched bg-(image:--admin-calendar-hatch)',
                drag.over === spot.key && TARGETED,
            )}
            onPointerEnter={() => onHover({ row: row.key, day: cell.date })}
            onPointerLeave={() => onHover(null)}
        >
            {member !== null && (
                <DateMenu
                    title={title}
                    name={cell.hatched ? `${title}, ${words.text(NOT_WORKING_THIS_DAY)}` : title}
                    onBook={onBook && (() => onBook(cell.date, member.id))}
                    onClosure={onClosure && (() => onClosure(cell.date, member.id))}
                    onDay={() => onDay(cell.date)}
                    className="absolute inset-0 size-full"
                />
            )}
            <div className={cn('pointer-events-none relative min-w-0 [&_button]:pointer-events-auto', desktop ? 'p-1' : 'p-0.5')}>
                {cell.chips.length + asides.length > 0 && (
                    <CappedChips
                        chips={cell.chips}
                        cap={CAP}
                        title={title}
                        colouring={colouring}
                        clock={clock}
                        onOpen={onOpen}
                        carried={(chip) => drag.source(chip, spot)}
                        asides={asides}
                        short={!desktop}
                        onMore={desktop ? undefined : moreOf(row, cell.date, onDay, onMember)}
                    />
                )}
            </div>
        </div>
    );
}

export function StaffWeek({ days, rows, today, colouring, clock, onOpen, onBook, onClosure, onDay, onMember, onDrop }: Props) {
    const desktop = useDesktop();
    const drag = useChipDrag(desktop ? onDrop : undefined);
    const [hover, setHover] = useState<Hover>(null);

    return (
        <div className={cn('admin-calendar-week grid bg-card', desktop ? 'grid-cols-[minmax(8rem,13.75rem)_repeat(7,minmax(0,1fr))]' : 'grid-cols-[2.75rem_repeat(7,minmax(0,1fr))]')}>
            <div className="border-b" aria-hidden="true" />
            {days.map((date) => (
                <DayHead key={date} date={date} today={today} marked={hover?.day === date} clock={clock} onDay={onDay} />
            ))}
            {rows.map((row) => (
                <Fragment key={row.key}>
                    <RowHead row={row} colouring={colouring} marked={hover?.row === row.key} desktop={desktop} />
                    {row.cells.map((cell) => (
                        <Cell key={cell.date} row={row} cell={cell} colouring={colouring} clock={clock} onOpen={onOpen} onBook={onBook} onClosure={onClosure} onDay={onDay} onMember={onMember} drag={drag} onHover={setHover} desktop={desktop} />
                    ))}
                </Fragment>
            ))}
        </div>
    );
}
