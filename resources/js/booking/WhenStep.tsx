import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { classify, type Notice } from './api';
import { dayShown, type Availability, type AvailabilityDay } from './availability';
import { BackIcon, OnwardIcon } from './icons';
import { NoticeRegion } from './NoticeRegion';
import { periodOf, periodsOf, type Period, type PeriodName } from './periods';
import { ZoneLine } from './Summary';
import { formatDay, formatDayFace, formatTime, wallClockOf } from './time';
import { useWords, type LineKey } from './words';

/*
 * Step when. The DOM order, strictly: the primary notice region, holding the conflict sentence if
 * one exists, else the availability error if there is no data at all, else nothing; a stale region
 * for a query that errored over data still present, so a 409 whose triggered refetch also failed
 * never leaves the conflict sentence over a silently unrefreshed list; a loading line while the
 * query is in flight; the "Select a date" caption over one line naming the tenant's time zone
 * (7.1); then the week row, then, once a day is shown, the "Pick a time" caption over the period
 * control and the grid for the selected day, or the empty-day sentence, or, when every day of the
 * week is empty, the hint. The row renders exactly the seven dates the answer carries, so the row
 * and the window are one number by construction, and the grid renders one period of one key's
 * list; nothing here computes a date. Day faces and times are formatted in the page's language
 * over the tenant's wall clock, the order and the hour cycle the machine's. Morning, Afternoon and
 * Evening stay the periods' identifiers and each shows its display name. A day with no starts is a
 * disabled button saying so in text (7.5), and an empty selected day is the sentence and no
 * buttons, never a blank panel. Picking a slot is the transition, reported as the wire value
 * whole; there is no Continue.
 *
 * The list and its two notices are exported as SlotList and useListNotices, because the reschedule
 * dialog of 6.5 draws the same list over its own read: the row, the period control, the grid and
 * the four sentences exist once.
 *
 * The arrows sit either side of the day list and render whatever the query's state, so that
 * pressing one keeps the focused control mounted while the next week loads; the one
 * exception, Previous unmounting on the first week, re-seats focus onto Next.
 *
 * Under the hint or an empty day's sentence, a Next available date button where there is a day to
 * go to: the first day of this week holding starts, which needs no request, else the later day the
 * public read named for an empty window. The press is the caller's to make into a move; the
 * reschedule dialog takes no such report and shows none.
 */

/**
 * What the step needs to know of the availability query: the data, kept across a failed
 * refetch, whether a request is in flight, and the error if any.
 */
export interface AvailabilityState {
    readonly data: Availability | undefined;
    readonly error: unknown;
    readonly isFetching: boolean;
}

interface WhenStepProps {
    readonly timezone: string;
    readonly availability: AvailabilityState;
    readonly window: number;
    readonly selectedDay: string | null;
    readonly chosenStart: string | null;
    /** The hold 409's notice, held in the flow's state, so it is one object until it changes. */
    readonly conflict: Notice | null;
    readonly onWindow: (index: number) => void;
    readonly onSelectDay: (day: string) => void;
    readonly onPick: (start: string) => void;
    readonly onNextAvailable: (day: string) => void;
}

export const STALE_TIMES_SENTENCE: LineKey = 'when.stale';

export const LOADING_TIMES_SENTENCE: LineKey = 'when.loading';

export const HINT_SENTENCE: LineKey = 'when.hint';

/** The caption over the week row. */
export const DATE_CAPTION: LineKey = 'when.date_caption';

/** The caption over the shown day's times, or its empty sentence. */
export const TIME_CAPTION: LineKey = 'when.time_caption';

export const EMPTY_DAY_SENTENCE: LineKey = 'when.empty_day';

/** The button under the hint or an empty day's sentence that goes to the next day with times. */
export const NEXT_AVAILABLE_LABEL: LineKey = 'when.next_available';

export const NO_TIMES: LineKey = 'when.no_times';

export const PERIODS_LABEL: LineKey = 'when.periods';

/** A period's count of times inside its button, read as a choice by the count. */
export const TIMES_COUNT: LineKey = 'when.times';

/** A day with no starts named in full, its :date the day as the page's language spells it. */
export const CLOSED_DAY: LineKey = 'when.closed_day';

/** The week row's two arrows, each named in words. */
export const PREVIOUS_WEEK_LABEL: LineKey = 'when.previous_week';

export const NEXT_WEEK_LABEL: LineKey = 'when.next_week';

/** Each period's display name; the period itself stays an identifier. */
export const PERIOD_NAMES: Readonly<Record<PeriodName, LineKey>> = {
    Morning: 'when.period.morning',
    Afternoon: 'when.period.afternoon',
    Evening: 'when.period.evening',
};

const STALE_NOTICE: Notice = { key: STALE_TIMES_SENTENCE, tone: 'polite' };

const NEXT_SELECTOR = '.kr-booking__next';

/** How many blank times stand in for the grid while a window's first read is out. */
const SKELETON_SLOTS = [0, 1, 2, 3, 4, 5];

export function WhenStep({ timezone, availability, window, selectedDay, chosenStart, conflict, onWindow, onSelectDay, onPick, onNextAvailable }: WhenStepProps): ReactNode {
    // The conflict notice is the flow state's own object and changes only with a new 409:
    // neither the refetch that answers, which hands down a new availability object, nor the one
    // that fails, which hands down a fresh error, may mint a new notice under a sentence the
    // customer has already heard.
    const { primary, stale } = useListNotices(availability, conflict);

    return (
        <div className="kr-booking__step" aria-busy={availability.isFetching}>
            <NoticeRegion notice={primary} />
            <NoticeRegion notice={stale} />
            <SlotList
                timezone={timezone}
                availability={availability}
                window={window}
                selectedDay={selectedDay}
                chosenStart={chosenStart}
                onWindow={onWindow}
                onSelectDay={onSelectDay}
                onPick={onPick}
                onNextAvailable={onNextAvailable}
            />
        </div>
    );
}

/**
 * The two notices over a slot list, 2.3's first two regions: the primary one holds the lead
 * notice the caller hands down if there is one, else the read's failure when there is no list
 * at all to show, classified by 4.1; the stale one says a failed refetch left the list on
 * screen unrefreshed. The when step's lead is its conflict sentence and the reschedule
 * dialog's is its move's progress or refusal, so both lists announce a failed read the same
 * way.
 *
 * A fresh Notice object is a fresh announcement, and a list under a held lead notice
 * refetches at once (4.3), so the caller hands the lead down memoised or held in state, and
 * the failure is memoised on the failure itself.
 */
export function useListNotices(availability: AvailabilityState, lead: Notice | null): { readonly primary: Notice | null; readonly stale: Notice | null } {
    const { data, error } = availability;
    const failed = error !== null && error !== undefined;
    const empty = data === undefined;
    const failureNotice = useMemo<Notice | null>(() => (failed && empty ? classify(error) : null), [failed, empty, error]);

    return { primary: lead ?? failureNotice, stale: failed && !empty ? STALE_NOTICE : null };
}

interface SlotListProps {
    readonly timezone: string;
    readonly availability: AvailabilityState;
    readonly window: number;
    readonly selectedDay: string | null;
    /** The start the flow holds, which the reschedule dialog has none of. */
    readonly chosenStart?: string | null;
    readonly onWindow: (index: number) => void;
    readonly onSelectDay: (day: string) => void;
    readonly onPick: (start: string) => void;
    /** Where the Next available date press is reported; without it the list offers none. */
    readonly onNextAvailable?: (day: string) => void;
}

/**
 * The slot list itself, one implementation for the when step and the reschedule dialog: the
 * loading line while a read is in flight, the date caption over the line naming the tenant's
 * time zone (7.1), the week row, then under the time caption the period control and the grid
 * for the selected day, or the empty-day sentence, or, with no caption, the hint for a week
 * whose every day is empty. It knows nothing of which read fed it. While a read is out with
 * nothing to show yet, a grid of blank times stands where the grid will be, hidden from
 * assistive technology, which hears the loading line; a list already held is never covered by
 * it.
 *
 * The selection is the caller's, and this list keeps it filled (2.3): when an answer carries
 * no selected day, the first day holding starts is reported from an effect, and rendered in
 * the same pass as the day shown, so no frame shows the hint while that report is on its way.
 * A day the answer carries stays selected, even one a refetch emptied. The report moves no
 * focus; only the customer's own presses do (7.4).
 */
export function SlotList({ timezone, availability, window, selectedDay, chosenStart = null, onWindow, onSelectDay, onPick, onNextAvailable }: SlotListProps): ReactNode {
    const { text } = useWords();
    const { data, isFetching } = availability;
    const day = dayShown(data, selectedDay);
    const shown = day?.date ?? null;
    const { periods, chosen, choose } = usePeriod(day, timezone, chosenStart);

    useEffect(() => {
        if (shown !== null && shown !== selectedDay) {
            onSelectDay(shown);
        }
    }, [shown, selectedDay, onSelectDay]);

    return (
        <>
            {isFetching && <p className="kr-booking__notice kr-booking__loading">{text(LOADING_TIMES_SENTENCE)}</p>}
            <p className="kr-booking__caption">{text(DATE_CAPTION)}</p>
            <ZoneLine timezone={timezone} />
            <WeekRow days={data?.days} window={window} selectedDay={shown} onWindow={onWindow} onSelectDay={onSelectDay} />
            {day !== undefined && <p className="kr-booking__caption">{text(TIME_CAPTION)}</p>}
            {data !== undefined && day === undefined && <p className="kr-booking__hint">{text(HINT_SENTENCE)}</p>}
            {day !== undefined && day.starts.length === 0 && <p className="kr-booking__empty">{text(EMPTY_DAY_SENTENCE)}</p>}
            <NextAvailable data={data} day={day} onNextAvailable={onNextAvailable} />
            <GridSkeleton availability={availability} />
            {periods.length > 1 && <PeriodControl periods={periods} chosen={chosen} onChoose={choose} />}
            {chosen !== undefined && <SlotGrid starts={chosen.starts} timezone={timezone} onPick={onPick} />}
        </>
    );
}

/**
 * The day Next available date goes to, or null where it renders none: nothing while no answer
 * has come or while the day shown holds starts; else the first day of this week holding starts,
 * which needs no request, else the later day the answer named for an empty window.
 */
function nextDayOf(data: Availability | undefined, day: AvailabilityDay | undefined): string | null {
    if (data === undefined || (day !== undefined && day.starts.length > 0)) {
        return null;
    }

    return data.days.find((candidate) => candidate.starts.length > 0)?.date ?? data.nextAvailableFrom ?? null;
}

interface NextAvailableProps {
    readonly data: Availability | undefined;
    readonly day: AvailabilityDay | undefined;
    readonly onNextAvailable: ((day: string) => void) | undefined;
}

/**
 * The Next available date button under the hint or an empty day's sentence, where there is a day
 * to go to and a caller taking the report; nothing otherwise. It is never disabled.
 */
function NextAvailable({ data, day, onNextAvailable }: NextAvailableProps): ReactNode {
    const { text } = useWords();
    const target = nextDayOf(data, day);

    if (target === null || onNextAvailable === undefined) {
        return null;
    }

    return (
        <button type="button" className="kr-booking__button kr-booking__jump" value={target} onClick={() => onNextAvailable(target)}>
            {text(NEXT_AVAILABLE_LABEL)}
        </button>
    );
}

/**
 * A grid of blank times where the grid will be, while a read is out with nothing to show yet:
 * hidden from assistive technology, which hears the loading line above it, and never over a list
 * already held. No animation.
 */
function GridSkeleton({ availability }: { readonly availability: AvailabilityState }): ReactNode {
    if (!availability.isFetching || availability.data !== undefined) {
        return null;
    }

    return (
        <div className="kr-booking__skeleton kr-booking__skeleton--grid" aria-hidden="true">
            {SKELETON_SLOTS.map((slot) => (
                <span key={slot} className="kr-booking__blank" />
            ))}
        </div>
    );
}

interface PeriodChoice {
    readonly day: string | null;
    readonly name: PeriodName | null;
}

/**
 * The shown day's starts by period and the one chosen, held here rather than by the caller.
 * On mount it is the period of the chosen start when that start lies on the shown day, so
 * Back from details shows the tab of the time picked; on a change of shown day, and when a
 * refetch leaves the chosen period empty, it is the first period holding starts. Either reset
 * is state stored from an earlier render and set during this one, so no frame shows the grid
 * of a period that is gone.
 */
function usePeriod(day: AvailabilityDay | undefined, timezone: string, chosenStart: string | null): { readonly periods: readonly Period[]; readonly chosen: Period | undefined; readonly choose: (name: PeriodName) => void } {
    const shown = day?.date ?? null;
    const periods = useMemo(() => periodsOf(day?.starts ?? [], timezone), [day, timezone]);
    const [choice, setChoice] = useState<PeriodChoice>(() => ({ day: shown, name: chosenStart !== null && wallClockOf(chosenStart, timezone).date === shown ? periodOf(chosenStart, timezone) : null }));
    const kept = choice.day === shown ? periods.find((period) => period.name === choice.name) : undefined;

    if (choice.day !== shown || (choice.name !== null && kept === undefined)) {
        setChoice({ day: shown, name: null });
    }

    return { periods, chosen: kept ?? periods[0], choose: (name: PeriodName) => setChoice({ day: shown, name }) };
}

interface PeriodControlProps {
    readonly periods: readonly Period[];
    readonly chosen: Period | undefined;
    readonly onChoose: (name: PeriodName) => void;
}

/**
 * Morning, Afternoon and Evening as a group of real buttons, each by its display name, the
 * chosen one stated in aria-pressed (7.5), each naming its count as secondary text inside it, so
 * the count is part of its name. A press changes the grid under it and moves no focus.
 */
function PeriodControl({ periods, chosen, onChoose }: PeriodControlProps): ReactNode {
    const { text, choice } = useWords();

    return (
        <div className="kr-booking__periods" role="group" aria-label={text(PERIODS_LABEL)}>
            {periods.map((period) => (
                <button key={period.name} type="button" className="kr-booking__period" aria-pressed={period.name === chosen?.name} onClick={() => onChoose(period.name)}>
                    <span className="kr-booking__period-name">{text(PERIOD_NAMES[period.name])}</span> <span className="kr-booking__count">{choice(TIMES_COUNT, period.starts.length)}</span>
                </button>
            ))}
        </div>
    );
}

interface WeekRowProps {
    readonly days: readonly AvailabilityDay[] | undefined;
    readonly window: number;
    readonly selectedDay: string | null;
    readonly onWindow: (index: number) => void;
    readonly onSelectDay: (day: string) => void;
}

/**
 * The week row: Previous week, one day per key the answer carries and no other, the selected
 * one stated in aria-pressed, then Next week. Previous is absent on the first week: the window
 * never goes before today.
 */
function WeekRow({ days, window, selectedDay, onWindow, onSelectDay }: WeekRowProps): ReactNode {
    const root = useRef<HTMLDivElement>(null);
    const seatNext = useRef(false);

    // Previous unmounts on reaching the first week; the focus it held lands on next.
    useEffect(() => {
        if (seatNext.current) {
            seatNext.current = false;
            root.current?.querySelector<HTMLElement>(NEXT_SELECTOR)?.focus();
        }
    });

    function previous(): void {
        if (window === 1) {
            seatNext.current = true;
        }

        onWindow(window - 1);
    }

    return (
        <div className="kr-booking__week" ref={root}>
            {window > 0 && <WeekArrow className="kr-booking__previous" name={PREVIOUS_WEEK_LABEL} back onPress={previous} />}
            {days !== undefined && (
                <ul className="kr-booking__list kr-booking__days">
                    {days.map((candidate) => (
                        <li key={candidate.date} className="kr-booking__item">
                            <DayButton day={candidate} pressed={candidate.date === selectedDay} onSelect={onSelectDay} />
                        </li>
                    ))}
                </ul>
            )}
            <WeekArrow className="kr-booking__next" name={NEXT_WEEK_LABEL} onPress={() => onWindow(window + 1)} />
        </div>
    );
}

interface WeekArrowProps {
    readonly className: string;
    readonly name: LineKey;
    /** Whether the arrow points back, to the week before. */
    readonly back?: boolean;
    readonly onPress: () => void;
}

/**
 * An arrow of the row, a round control named in words: the chevron is an inline drawing hidden
 * from assistive technology, and the words are kept beside it for it and hidden from sight, so
 * the name and the text are the words alone.
 */
function WeekArrow({ className, name, back = false, onPress }: WeekArrowProps): ReactNode {
    const { text } = useWords();

    return (
        <button type="button" className={`kr-booking__button ${className}`} onClick={onPress}>
            {back ? <BackIcon /> : <OnwardIcon />}
            <span className="kr-booking__arrow-label">{text(name)}</span>
        </button>
    );
}

interface DayButtonProps {
    readonly day: AvailabilityDay;
    readonly pressed: boolean;
    readonly onSelect: (day: string) => void;
}

/**
 * One day: the short weekday over the day of the month, named by the full date so a screen
 * reader hears the month too. A day with no starts is a real disabled button saying so in
 * text, in its face and in its name.
 */
function DayButton({ day, pressed, onSelect }: DayButtonProps): ReactNode {
    const { text, locale } = useWords();
    const face = formatDayFace(day.date, locale);
    const named = formatDay(day.date, locale);
    const open = day.starts.length > 0;

    return (
        <button
            type="button"
            className="kr-booking__day"
            value={day.date}
            aria-pressed={pressed}
            aria-label={open ? named : text(CLOSED_DAY, { date: named })}
            disabled={!open}
            onClick={() => onSelect(day.date)}
        >
            <span className="kr-booking__weekday">{face.weekday}</span>
            <span className="kr-booking__date">{face.date}</span>
            {!open && <span className="kr-booking__no-times">{text(NO_TIMES)}</span>}
        </button>
    );
}

interface SlotGridProps {
    readonly starts: readonly string[];
    readonly timezone: string;
    readonly onPick: (start: string) => void;
}

/**
 * One button per start of the chosen period, carrying the wire value whole and labelled with
 * the tenant's hour in the page's language. The labels are resolved once per list, since a day at
 * a five-minute interval holds a few hundred and every one goes through the zone.
 */
function SlotGrid({ starts, timezone, onPick }: SlotGridProps): ReactNode {
    const { locale } = useWords();
    const slots = useMemo(() => starts.map((start) => ({ start, label: formatTime(wallClockOf(start, timezone), locale) })), [starts, timezone, locale]);

    return (
        <ul className="kr-booking__list kr-booking__grid">
            {slots.map((slot) => (
                <li key={slot.start} className="kr-booking__item">
                    <button type="button" className="kr-booking__slot" value={slot.start} onClick={() => onPick(slot.start)}>
                        {slot.label}
                    </button>
                </li>
            ))}
        </ul>
    );
}
