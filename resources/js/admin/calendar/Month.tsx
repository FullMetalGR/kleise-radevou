import { isoWeekday } from '@/lib/day';
import { formatCount, type TenantClock } from '@/lib/format';
import { weekdayName } from '@/lib/names';
import { cn } from '@/lib/utils';
import { useDesktop } from '@/lib/viewport';
import { CappedChips } from './CappedChips';
import { type Spot, TARGETED, useChipDrag } from './chip-drag';
import { DateMenu } from './DateMenu';
import { type Chip, type ChipDrop, CLOSED_DAY, type Colouring, dayTitleOf, monthDaysOf } from './model';
import { type CalendarKey, type CalendarWords, useCalendarWords } from './words';

/*
 * Month: six weeks always, from the week holding the month's first day, the range the screen
 * reads, under a head of the weekdays' short names. A day of another month is greyed and as full
 * as any; the tenant's today has its number marked; a day the business is closed all day is
 * hatched and lists its bookings and opens its menu still.
 *
 * At desktop width a day lists its bookings as chips, the first two and a line saying how many
 * more, and the rest of the cell is the day's date menu, its one tab stop before the chips. Below
 * the breakpoint a cell is too narrow for a chip: it holds the day's number and how many bookings
 * it has, and the whole cell is the date menu.
 *
 * At desktop width a chip drags to another day where the booking is movable and a drop is handled,
 * the drop moving it by whole days, its time and members kept (calendar/chip-drag).
 */

/** The most chips a Month day shows before the line saying how many more. */
const CAP = 2;

export const BOOKING_COUNT: CalendarKey = 'cells.count';

type Props = {
    /** Any date of the month shown. */
    date: string;
    weekStart: number;
    /** The tenant's today. */
    today: string;
    /** Each tenant day's chips in their order, monthChipsOf's answer. */
    chips: ReadonlyMap<string, readonly Chip[]>;
    /** The days the business is closed all day. */
    closedDays: readonly string[];
    colouring: Colouring;
    clock: TenantClock;
    onOpen: (uuid: string) => void;
    /** A new booking on the day, where the viewer may add one. */
    onBook?: (date: string) => void;
    /** A new closure on the day, where the viewer may add one. */
    onClosure?: (date: string) => void;
    onDay: (date: string) => void;
    /** A chip dropped on another day, where drags are handled. */
    onDrop?: (drop: ChipDrop) => void;
};

type Day = { date: string; title: string; chips: readonly Chip[]; other: boolean; closed: boolean; today: boolean };

/** A cell's name: the day, then that the business is closed, then how many bookings where the cell says so and the day has any. */
function nameOf(day: Day, words: CalendarWords, counted: boolean): string {
    return [day.title, day.closed ? words.text(CLOSED_DAY) : null, counted && day.chips.length > 0 ? words.choice(BOOKING_COUNT, day.chips.length) : null].filter((part) => part !== null).join(', ');
}

/** A day as a drag reads it: the day alone, Month having no rows. */
function spotOf(day: Day): Spot {
    return { key: day.date, date: day.date, staffId: null };
}

/** A day's number in its pill, 28px from the desktop breakpoint, today's on the highlight. */
function DayNumber({ day, desktop }: { day: Day; desktop: boolean }) {
    return (
        <span
            aria-hidden="true"
            className={cn(
                'admin-calendar-month__number inline-flex items-center justify-center rounded-full tabular-nums',
                desktop ? 'size-7 text-sm font-semibold' : 'size-6 text-xs font-medium',
                day.other && 'text-muted-foreground/60',
                day.today && 'admin-calendar-month__number--today bg-spark text-spark-foreground',
            )}
        >
            {Number(day.date.slice(8, 10))}
        </span>
    );
}

export function Month({ date, weekStart, today, chips, closedDays, colouring, clock, onOpen, onBook, onClosure, onDay, onDrop }: Props) {
    const words = useCalendarWords();
    const desktop = useDesktop();
    const drag = useChipDrag(desktop ? onDrop : undefined);
    const month = date.slice(0, 7);
    const days: Day[] = monthDaysOf(date, weekStart).map((day) => ({
        date: day,
        title: dayTitleOf(day, clock, words),
        chips: chips.get(day) ?? [],
        other: day.slice(0, 7) !== month,
        closed: closedDays.includes(day),
        today: day === today,
    }));

    return (
        <div className="admin-calendar-month bg-card">
            <div className="admin-calendar-month__head grid grid-cols-7 border-b" aria-hidden="true">
                {days.slice(0, 7).map((day) => (
                    <span key={day.date} className="px-1.5 py-1 text-xs font-medium text-muted-foreground desktop:px-3 desktop:py-2 desktop:font-semibold">
                        {weekdayName(isoWeekday(day.date), 'short', words.language)}
                    </span>
                ))}
            </div>
            <ol className="grid grid-cols-7">
                {days.map((day) => (
                    <li
                        key={day.date}
                        data-date={day.date}
                        {...(desktop ? drag.target(spotOf(day)) : {})}
                        className={cn(
                            'admin-calendar-month__day relative min-w-0 border-b border-l nth-[7n+1]:border-l-0 nth-last-[-n+7]:border-b-0',
                            desktop ? 'min-h-32' : 'min-h-14',
                            day.other && 'admin-calendar-month__day--other bg-muted/40',
                            day.closed && 'admin-calendar-month__day--closed bg-(image:--admin-calendar-hatch)',
                            drag.over === day.date && TARGETED,
                        )}
                    >
                        <DateMenu
                            title={day.title}
                            name={nameOf(day, words, !desktop)}
                            current={day.today}
                            onBook={onBook && (() => onBook(day.date))}
                            onClosure={onClosure && (() => onClosure(day.date))}
                            onDay={() => onDay(day.date)}
                            className={desktop ? 'absolute inset-0 size-full' : 'flex size-full flex-col items-center gap-0.5 p-1'}
                        >
                            {!desktop && <DayNumber day={day} desktop={false} />}
                            {!desktop && day.chips.length > 0 && (
                                <span aria-hidden="true" className="admin-calendar-month__count text-xs font-semibold text-muted-foreground tabular-nums">
                                    {formatCount(day.chips.length, words.locale)}
                                </span>
                            )}
                        </DateMenu>
                        {desktop && (
                            <div className="pointer-events-none relative flex min-w-0 flex-col gap-1.5 p-1.5 [&_button]:pointer-events-auto">
                                <DayNumber day={day} desktop />
                                {day.chips.length > 0 && <CappedChips chips={day.chips} cap={CAP} title={day.title} colouring={colouring} clock={clock} onOpen={onOpen} carried={(chip) => drag.source(chip, spotOf(day))} />}
                            </div>
                        )}
                    </li>
                ))}
            </ol>
        </div>
    );
}
