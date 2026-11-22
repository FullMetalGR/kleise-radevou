import { Fragment, useState } from 'react';
import { Initials } from '@/components/initials';
import { isoWeekday } from '@/lib/day';
import type { TenantClock } from '@/lib/format';
import { weekdayName } from '@/lib/names';
import { cn } from '@/lib/utils';
import { type Aside, CappedChips } from './CappedChips';
import { type ChipDrag, type Spot, TARGETED, useChipDrag } from './chip-drag';
import { TO_DAY } from './Chrome';
import { DateMenu } from './DateMenu';
import { type ChipDrop, type Colouring, colourOf, dayTitleOf, type Member, NOT_WORKING_THIS_DAY, OTHER_BOOKINGS } from './model';
import type { ClosureLine, WeekCell, WeekRow } from './staff-week';
import { useCalendarWords } from './words';

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
 */

/** The most chips a day of a row shows before the line saying how many more. */
const CAP = 6;

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

function RowHead({ row, colouring, marked }: { row: WeekRow; colouring: Colouring; marked: boolean }) {
    const words = useCalendarWords();
    const name = row.member === null ? words.text(OTHER_BOOKINGS) : row.member.name;

    return (
        <div data-row={row.key} className={cn('admin-calendar-week__person flex min-w-0 items-center gap-2.5 border-b py-2.5 pr-2 pl-4 text-sm font-semibold', row.member === null && 'text-muted-foreground', marked && 'admin-calendar-week__person--marked bg-accent')}>
            {row.member !== null && <Initials name={row.member.name} hue={colourOf(row.member.id, colouring.palette, colouring.own)} size="row" />}
            <span className="truncate" title={name}>
                {name}
            </span>
        </div>
    );
}

/** A closure with a time among a day's chips: a grey line of its range and its reason, read as its whole sentence. */
function ClosureMark({ line }: { line: ClosureLine }) {
    return (
        <p className="admin-calendar-week__closure pointer-events-auto flex min-w-0 items-center gap-1 rounded-sm bg-muted px-1 text-xs leading-4 text-muted-foreground" title={line.title}>
            <span className="admin-calendar-closure-swatch size-2.5 shrink-0 rounded-[2px]" aria-hidden="true" />
            <span aria-hidden="true" className="shrink-0 tabular-nums">
                {line.range}
            </span>
            {line.reason !== null && (
                <span aria-hidden="true" className="truncate">
                    {line.reason}
                </span>
            )}
            <span className="sr-only">{line.title}</span>
        </p>
    );
}

type CellProps = Pick<Props, 'colouring' | 'clock' | 'onOpen' | 'onBook' | 'onClosure' | 'onDay'> & { row: WeekRow; cell: WeekCell; drag: ChipDrag; onHover: (hover: Hover) => void };

/** A day of a row: its date menu where the row is a member's, its chips with its closure lines, its hatch, and the drop it takes. */
function Cell({ row, cell, colouring, clock, onOpen, onBook, onClosure, onDay, drag, onHover }: CellProps) {
    const words = useCalendarWords();
    const { member } = row;
    const spot: Spot = { key: `${row.key}:${cell.date}`, date: cell.date, staffId: member?.id ?? null };
    const day = dayTitleOf(cell.date, clock, words);
    const title = words.text('day.menu', { staff: member?.name ?? words.text(OTHER_BOOKINGS), date: day });
    const asides: Aside[] = cell.lines.map((line) => ({ key: line.key, start: line.start, node: <ClosureMark line={line} /> }));

    return (
        <div
            data-row={row.key}
            data-date={cell.date}
            {...(member === null ? {} : drag.target(spot))}
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
            <div className="pointer-events-none relative min-w-0 p-1 [&_button]:pointer-events-auto">
                {cell.chips.length + asides.length > 0 && <CappedChips chips={cell.chips} cap={CAP} title={title} colouring={colouring} clock={clock} onOpen={onOpen} carried={(chip) => drag.source(chip, spot)} asides={asides} />}
            </div>
        </div>
    );
}

export function StaffWeek({ days, rows, today, colouring, clock, onOpen, onBook, onClosure, onDay, onDrop }: Props) {
    const drag = useChipDrag(onDrop);
    const [hover, setHover] = useState<Hover>(null);

    return (
        <div className="admin-calendar-week grid grid-cols-[minmax(8rem,13.75rem)_repeat(7,minmax(0,1fr))] bg-card">
            <div className="border-b" aria-hidden="true" />
            {days.map((date) => (
                <DayHead key={date} date={date} today={today} marked={hover?.day === date} clock={clock} onDay={onDay} />
            ))}
            {rows.map((row) => (
                <Fragment key={row.key}>
                    <RowHead row={row} colouring={colouring} marked={hover?.row === row.key} />
                    {row.cells.map((cell) => (
                        <Cell key={cell.date} row={row} cell={cell} colouring={colouring} clock={clock} onOpen={onOpen} onBook={onBook} onClosure={onClosure} onDay={onDay} drag={drag} onHover={setHover} />
                    ))}
                </Fragment>
            ))}
        </div>
    );
}
