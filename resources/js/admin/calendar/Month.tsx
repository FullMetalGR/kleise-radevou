import { isoWeekday } from '@/lib/day';
import type { TenantClock } from '@/lib/format';
import { weekdayName } from '@/lib/names';
import { cn } from '@/lib/utils';
import { useDesktop } from '@/lib/viewport';
import { CappedChips } from './CappedChips';
import { type Spot, TARGETED, useChipDrag } from './chip-drag';
import { DateMenu, OPEN_DAY } from './DateMenu';
import { type Chip, type ChipDrop, CLOSED_DAY, type Colouring, dayTitleOf, monthDaysOf } from './model';
import { type CalendarWords, useCalendarWords } from './words';

/*
 * Month: six weeks always, from the week holding the month's first day, the range the screen
 * reads, under a head of the weekdays' short names. A day of another month is greyed and as full
 * as any; the tenant's today has its number marked; a day the business is closed all day is
 * hatched and lists its bookings and opens its menu still.
 *
 * A day lists its bookings as chips, the first two and a line saying how many more, and the rest
 * of the cell is the day's date menu, its one tab stop before the chips. Below the breakpoint the
 * chips are their start times alone and the line a plus and the figure, which opens Day on the
 * date rather than a popover; the day's number is a button opening Day too, where the menu offers
 * more than that, and part of the menu where the menu is itself Open day. There the foot of every
 * cell, a strip 24 pixels tall across it, is the menu's alone, busy day or empty: a padding under
 * the chips rather than a least height, which a busy day's chips would fill.
 *
 * At desktop width a chip drags to another day where the booking is movable and a drop is handled,
 * the drop moving it by whole days, its time and members kept (calendar/chip-drag).
 */

/** The most chips a Month day shows before the line saying how many more. */
const CAP = 2;

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

/** A cell's name: the day, then that the business is closed. */
function nameOf(day: Day, words: CalendarWords): string {
    return day.closed ? `${day.title}, ${words.text(CLOSED_DAY)}` : day.title;
}

/** A day as a drag reads it: the day alone, Month having no rows. */
function spotOf(day: Day): Spot {
    return { key: day.date, date: day.date, staffId: null };
}

/**
 * A day's number below the breakpoint, centred in a band as tall as a finger that is part of the
 * menu behind: where the menu offers more than Open day, a button opening Day, named so, drawn as
 * the pill and taking a press 10 pixels past each side of it, a touch area of 44 that the band
 * holds clear of the chips; else part of the menu, which a press on it reaches.
 */
function PhoneNumber({ day, opens, onDay }: { day: Day; opens: boolean; onDay: (date: string) => void }) {
    const words = useCalendarWords();

    return (
        <span className="flex h-11 items-center justify-center">
            {opens ? (
                <button type="button" className="admin-calendar-month__open focus-ring relative inline-flex cursor-pointer rounded-full after:absolute after:-inset-2.5" onClick={() => onDay(day.date)}>
                    <span className="sr-only">{`${words.text(OPEN_DAY)}, ${day.title}`}</span>
                    <DayNumber day={day} desktop={false} />
                </button>
            ) : (
                <DayNumber day={day} desktop={false} />
            )}
        </span>
    );
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
                            desktop && 'min-h-32',
                            day.other && 'admin-calendar-month__day--other bg-muted/40',
                            day.closed && 'admin-calendar-month__day--closed bg-(image:--admin-calendar-hatch)',
                            drag.over === day.date && TARGETED,
                        )}
                    >
                        <DateMenu
                            title={day.title}
                            name={nameOf(day, words)}
                            current={day.today}
                            onBook={onBook && (() => onBook(day.date))}
                            onClosure={onClosure && (() => onClosure(day.date))}
                            onDay={() => onDay(day.date)}
                            className="absolute inset-0 size-full"
                        />
                        <div className={cn('admin-calendar-month__body pointer-events-none relative flex min-w-0 flex-col [&_button]:pointer-events-auto', desktop ? 'gap-1.5 p-1.5' : 'gap-0.5 px-0.5 pt-0.5 pb-6')}>
                            {desktop ? <DayNumber day={day} desktop /> : <PhoneNumber day={day} opens={onBook !== undefined || onClosure !== undefined} onDay={onDay} />}
                            {day.chips.length > 0 && (
                                <CappedChips
                                    chips={day.chips}
                                    cap={CAP}
                                    title={day.title}
                                    colouring={colouring}
                                    clock={clock}
                                    onOpen={onOpen}
                                    carried={(chip) => drag.source(chip, spotOf(day))}
                                    short={!desktop}
                                    onMore={desktop ? undefined : () => onDay(day.date)}
                                />
                            )}
                        </div>
                    </li>
                ))}
            </ol>
        </div>
    );
}
