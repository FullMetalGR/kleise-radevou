import { Head, Link, usePage } from '@inertiajs/react';
import { type ReactNode, useId, useState } from 'react';
import { Chevron } from '@/components/glyphs';
import { Initials } from '@/components/initials';
import { Button } from '@/components/ui/button';
import { Notice } from '@/components/ui/notice';
import { TableScroll } from '@/components/ui/table-scroll';
import { isoWeekday } from '@/lib/day';
import { clockOf, formatCount, formatDate, formatDayMonth, formatTimeOfDay, type TenantClock } from '@/lib/format';
import { hueOf, type OwnColours } from '@/lib/hue';
import { weekdayName } from '@/lib/names';
import { usePartialRead } from '@/lib/partial-read';
import { placed, stringsOf, useShell } from '@/lib/strings';
import { cn } from '@/lib/utils';
import { FeatureGone } from '@/shell/NarrowedBanner';
import { DayEditor } from '@/shifts/DayEditor';
import { hoursOf, type ShiftWords } from '@/shifts/words';
import { dateMinutes, datesOf, dayOf, memberMinutes, patternOf, type ShiftClosure, type ShiftDay, type ShiftMember, type ShiftPattern, type ShiftTotals, type ShiftWeek } from '@/shifts/model';
import { PatternEditor } from '@/shifts/PatternEditor';
import type { AdminStrings, SharedProps, ShiftsStrings } from '@/types';
import { addDays } from '../../booking/time';

/*
 * The Shifts screen: the heading and its sentence, beside them This week, the chevrons of the
 * previous and the next week around the range in bold in the tenant's date format; then the week
 * grid inside the kit's TableScroll. A row per member: the initials ringed in the member's hue, the
 * name linking to their staff page with the week's hours and the pattern's cadence beneath, and
 * Pattern at the right; then seven day columns from the tenant's week start, each headed by the
 * weekday's ICU short name with the day and month on one line and the day's total beneath. A cell's
 * pills are centred: each window bordered in mono, Not working dashed, a day edited alone ringed in
 * the theme's primary with This day only beneath, and a closure in the destructive colour linking
 * to the Staff page's closures; every total has the closures taken off (the server's). The card's
 * foot is the legend, a sample pill of each, and the footnote on the opening hours.
 *
 * This week, previous and next read the week again as a partial reload of the week's props, the URL
 * replaced so it carries ?week= and a reload or a bookmark lands on it; while it reads the controls
 * are busy and what is drawn is the server's last answer. A cell is a button opening the day editor
 * (shifts/DayEditor) where the staff feature is held, and the row head's Pattern button the pattern
 * editor (shifts/PatternEditor); a cell's name is what it shows, the edit wording its
 * description. Without the feature the cells are text and no Pattern button shows. The page then
 * reads on under the narrowing notice in the banner's words only where it lists named members, the
 * evidence that the feature was held, as the Staff list reads it: a tenant that never had the
 * feature lists the business's own row and is told nothing went. The notice is left to the banner
 * when the narrowing record already names the feature.
 *
 * Every word is a key of the shifts section, which ShiftsController names, and a weekday's name
 * comes from ICU in the shell's language (lib/names.ts).
 */

/** A listed member, and whether it is the business's own row, listed where no named member is active. */
type ListedMember = ShiftMember & { is_implicit: boolean };

type ShiftsProps = SharedProps & {
    strings: AdminStrings & { shifts: ShiftsStrings };
    members: ListedMember[];
    week: ShiftWeek;
    days: ShiftDay[];
    patterns: ShiftPattern[];
    totals: ShiftTotals;
    editable: boolean;
    /** The members who picked their own colour on the Staff screen, whose initials ring in it. */
    colours: OwnColours;
};

const SHIFTS = '/admin/shifts';

const STAFF = '/admin/staff';

/** What a move of week reads again: the week answered, its days and its totals. */
const READ_PROPS = ['week', 'days', 'totals'];

/** The member and the date the day editor is open on. */
type Editing = { member: ShiftMember; date: string };

function span(start: string, end: string, clock: TenantClock): string {
    return `${formatTimeOfDay(start, clock)}-${formatTimeOfDay(end, clock)}`;
}

/** A cell's pill: one window, the day off, or a closure. */
const PILL = 'admin-shifts__pill inline-flex w-full items-center justify-center rounded-lg border px-1.5 py-[5px] text-xs leading-4';

/** The pill's look per state, every colour a token of the theme: a window in mono, the day off dashed, a closure in the destructive colour. */
const PILL_LOOK = {
    window: 'bg-background font-mono group-hover:bg-muted',
    off: 'admin-shifts__pill--off border-dashed text-muted-foreground group-hover:bg-muted',
    closure: 'admin-shifts__pill--closure border-destructive/50 text-destructive hover:bg-destructive/10',
} as const;

/** A day changed alone: its pill ringed in the theme's primary. */
const ALONE = 'admin-shifts__pill--alone border-primary ring-1 ring-primary';

/** The legend's sample of a pill: a chip, round, at the legend's size. */
const SAMPLE = 'admin-shifts__legend-sample inline-flex rounded-full border px-1.5 leading-4';

function rowHead(minutes: number, pattern: ShiftPattern | undefined, words: ShiftWords, clock: TenantClock): string {
    const hours = hoursOf(minutes, words);

    if (pattern === undefined) {
        return words.text('page.row_none', { hours });
    }

    return pattern.cadence_weeks === 1 ? words.text('page.row_weekly', { hours }) : words.text('page.row_cadence', { hours, weeks: formatCount(pattern.cadence_weeks, words.locale), from: formatDate(pattern.starts_on, clock) });
}

function closureLine(closure: ShiftClosure, words: ShiftWords, clock: TenantClock): string {
    if (closure.start === null || closure.end === null) {
        return closure.reason === null ? words.text('page.closed_all_day') : words.text('page.closed_all_day_reason', { reason: closure.reason });
    }

    const values = { start: formatTimeOfDay(closure.start, clock), end: formatTimeOfDay(closure.end, clock) };

    return closure.reason === null ? words.text('page.closed', values) : words.text('page.closed_reason', { ...values, reason: closure.reason });
}

/** A day's pills, a window each and the day off one, ringed where the day was changed alone, This day only beneath. */
function DayBody({ day, words, clock }: { day: ShiftDay; words: ShiftWords; clock: TenantClock }) {
    const alone = day.source === 'override' ? ALONE : '';

    return (
        <>
            {day.windows.length === 0 ? (
                <span className={cn('admin-shifts__off', PILL, PILL_LOOK.off, alone)}>{words.text('page.not_working')}</span>
            ) : (
                day.windows.map((shift) => (
                    <span key={`${shift.start}-${shift.end}`} className={cn('admin-shifts__window', PILL, PILL_LOOK.window, alone)}>
                        {span(shift.start, shift.end, clock)}
                    </span>
                ))
            )}
            {day.source === 'override' && <span className="admin-shifts__alone text-[11px] leading-[14px] text-muted-foreground">{words.text('page.this_day_only')}</span>}
        </>
    );
}

type CellProps = { member: ShiftMember; day: ShiftDay; words: ShiftWords; clock: TenantClock; onEdit: (() => void) | null };

function Cell({ member, day, words, clock, onEdit }: CellProps) {
    const described = useId();

    return (
        <td className="admin-shifts__cell px-2 py-2.5 text-center align-top" data-date={day.date} data-source={day.source}>
            <div className="flex flex-col items-center gap-1">
                {onEdit === null ? (
                    <div className="admin-shifts__day flex w-full flex-col items-center gap-1">
                        <DayBody day={day} words={words} clock={clock} />
                    </div>
                ) : (
                    <>
                        <button
                            type="button"
                            className="admin-shifts__day admin-shifts__edit group flex w-full flex-col items-center gap-1 rounded-lg focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                            aria-describedby={described}
                            onClick={onEdit}
                        >
                            <DayBody day={day} words={words} clock={clock} />
                        </button>
                        <span id={described} className="admin-shifts__edit-what sr-only">
                            {words.text('page.edit_day', { name: member.name, date: formatDate(day.date, clock) })}
                        </span>
                    </>
                )}
                {/* Two closures may share their times and reason (a member's and the tenant's, or two rows);
                the server's order is fixed and the list is replaced whole from the props, so the
                position keys each one. */}
                {day.closures.map((closure, position) => (
                    <Link key={position} href={closure.business ? STAFF : `${STAFF}/${member.id}`} className={cn('admin-shifts__closure', PILL, PILL_LOOK.closure)} data-business={closure.business ? 'true' : 'false'}>
                        {closureLine(closure, words, clock)}
                    </Link>
                ))}
            </div>
        </td>
    );
}

type MemberRowProps = {
    member: ShiftMember;
    /** The member's hue, their own colour or the spread's. */
    hue: string;
    dates: readonly string[];
    days: readonly ShiftDay[];
    minutes: number;
    pattern: ShiftPattern | undefined;
    words: ShiftWords;
    clock: TenantClock;
    /** Whether the staff feature is held: the cells are buttons and the Pattern button shows. */
    editable: boolean;
    onEditDay: (date: string) => void;
    onEditPattern: () => void;
};

/** A member's row: the head with the initials ringed in the member's hue, the name, the week's hours beneath it and the Pattern button at the right, then the week's cells. */
function MemberRow({ member, hue, dates, days, minutes, pattern, words, clock, editable, onEditDay, onEditPattern }: MemberRowProps) {
    return (
        <tr className="admin-shifts__row border-t" data-staff={member.id}>
            <th scope="row" className="admin-shifts__member py-2.5 pr-2 pl-4 text-left align-middle font-normal">
                <div className="flex items-center gap-2.5">
                    <Initials name={member.name} hue={hue} size="row" className="admin-shifts__initials" />
                    <span className="flex min-w-0 flex-col gap-0.5">
                        <Link href={`${STAFF}/${member.id}`} className="admin-shifts__name font-medium underline-offset-4 hover:underline">
                            {member.name}
                        </Link>
                        <span className="admin-shifts__row-head text-xs text-muted-foreground">{rowHead(minutes, pattern, words, clock)}</span>
                    </span>
                    {editable && (
                        <Button variant="tertiary" className="admin-shifts__pattern ml-auto px-2" aria-label={words.text('page.edit_pattern', { name: member.name })} onClick={onEditPattern}>
                            {words.text('page.pattern')}
                        </Button>
                    )}
                </div>
            </th>
            {dates.map((date) => (
                <Cell key={date} member={member} day={dayOf(days, member.id, date)} words={words} clock={clock} onEdit={editable ? () => onEditDay(date) : null} />
            ))}
        </tr>
    );
}

function WeekBar({ week, last, loading, words, clock, onRead }: { week: ShiftWeek; last: string; loading: boolean; words: ShiftWords; clock: TenantClock; onRead: (query: Record<string, string>) => void }) {
    return (
        <div className="admin-shifts__bar flex flex-wrap items-center gap-2">
            <Button className="admin-shifts__this-week" disabled={loading} onClick={() => onRead({})}>
                {words.text('page.this_week')}
            </Button>
            <Button className="admin-shifts__previous w-8 px-0" disabled={loading} aria-label={words.text('page.previous')} onClick={() => onRead({ week: addDays(week.from, -7) })}>
                <Chevron to="previous" />
            </Button>
            <h2 className="admin-shifts__week text-base font-semibold" aria-busy={loading ? true : undefined}>
                {words.text('page.week', { from: formatDate(week.from, clock), to: formatDate(last, clock) })}
            </h2>
            <Button className="admin-shifts__next w-8 px-0" disabled={loading} aria-label={words.text('page.next')} onClick={() => onRead({ week: addDays(week.from, 7) })}>
                <Chevron to="next" />
            </Button>
            {loading && <span className="admin-shifts__loading text-sm text-muted-foreground">{words.text('page.loading')}</span>}
        </div>
    );
}

/** A legend line's words around its sample pill. */
function legendWords(words: string): ReactNode {
    return <span className="admin-shifts__legend-words">{words}</span>;
}

/**
 * The grid card's foot: for a day changed alone, a day off and a closure, one whole line each
 * whose :sample is its sample pill, placed where the line puts it, the sample window in the
 * tenant's time format; the footnote on the opening hours at the right, under them where the row
 * runs out.
 */
function Legend({ words, clock }: { words: ShiftWords; clock: TenantClock }) {
    return (
        <div className="admin-shifts__legend flex flex-wrap items-center gap-x-4 gap-y-2 border-t px-4 py-3 text-xs leading-4">
            <ul className="flex flex-wrap items-center gap-x-4 gap-y-2">
                <li>{placed(words.text('page.legend_override'), { sample: <span className={cn(SAMPLE, ALONE)}>{span('12:00', '20:00', clock)}</span> }, legendWords)}</li>
                <li>{placed(words.text('page.legend_off'), { sample: <span className={cn(SAMPLE, 'admin-shifts__pill--off border-dashed text-muted-foreground')}>{words.text('page.not_working')}</span> }, legendWords)}</li>
                <li>
                    {placed(words.text('page.legend_closure'), { sample: <span className={cn(SAMPLE, 'admin-shifts__pill--closure border-destructive/50 text-destructive')}>{words.text('page.legend_closed')}</span> }, legendWords)}
                </li>
            </ul>
            <p className="admin-shifts__footnote ml-auto text-muted-foreground">{words.text('page.footnote')}</p>
        </div>
    );
}

type EditorsProps = {
    props: ShiftsProps;
    dates: readonly string[];
    editing: Editing | null;
    patterning: ShiftMember | null;
    words: ShiftWords;
    clock: TenantClock;
    onClose: () => void;
};

/** The modal open on the page, the day editor or the pattern editor, or none. */
function Editors({ props, dates, editing, patterning, words, clock, onClose }: EditorsProps) {
    if (editing !== null) {
        const day = dayOf(props.days, editing.member.id, editing.date);

        return (
            <DayEditor
                key={`${editing.member.id} ${editing.date}`}
                member={editing.member}
                date={editing.date}
                windows={day.windows}
                source={day.source}
                patternWindows={day.pattern}
                clock={clock}
                granularity={props.settings.granularity}
                words={words}
                onClose={onClose}
            />
        );
    }

    return patterning === null ? null : (
        <PatternEditor
            key={patterning.id}
            member={patterning}
            pattern={patternOf(props.patterns, patterning.id)}
            weekdays={dates.map(isoWeekday)}
            weekFrom={props.week.from}
            clock={clock}
            weekStart={props.settings.week_start}
            granularity={props.settings.granularity}
            words={words}
            onClose={onClose}
        />
    );
}

export default function Shifts() {
    const { props } = usePage<ShiftsProps>();
    const shell = useShell();
    const clock = clockOf(props);
    const words = stringsOf<ShiftsStrings>(props.strings.shifts, shell.language);
    const read = usePartialRead(READ_PROPS);
    const [editing, setEditing] = useState<Editing | null>(null);
    const [patterning, setPatterning] = useState<ShiftMember | null>(null);
    const close = () => {
        setEditing(null);
        setPatterning(null);
    };
    const dates = datesOf(props.week);
    const last = dates[dates.length - 1] ?? props.week.from;

    return (
        <>
            <Head title={words.text('page.title')} />
            <div className="admin-shifts-screen flex flex-col gap-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex flex-col gap-1">
                        <h1 className="text-2xl font-semibold">{words.text('page.title')}</h1>
                        <p className="admin-shifts__lead text-muted-foreground">{words.text('page.lead')}</p>
                    </div>
                    <WeekBar week={props.week} last={last} loading={read.loading} words={words} clock={clock} onRead={(query) => read.read(SHIFTS, query)} />
                </div>
                {!props.editable && props.members.some((member) => !member.is_implicit) && !props.narrowed.includes('staff') && (
                    <Notice kind="info" className="admin-shifts-readonly">
                        <FeatureGone slug="staff" manageBilling={props.permissions.includes('manageBilling')} free={props.free} block="admin-shifts-readonly" />
                    </Notice>
                )}
                {read.error && (
                    <Notice kind="error" className="admin-shifts-error">
                        {words.text('page.read_error')}
                    </Notice>
                )}
                {props.members.length === 0 ? (
                    <p className="admin-shifts__empty text-muted-foreground">{words.text('page.empty')}</p>
                ) : (
                    <section className="admin-card admin-shifts rounded-xl border">
                        <TableScroll>
                            <table className="admin-table w-full min-w-[54rem] table-fixed border-collapse text-sm">
                                <thead>
                                    <tr className="border-b">
                                        <th className="admin-shifts__member-head w-[220px] py-2.5 pr-2 pl-4 text-left align-top font-semibold">{words.text('page.member')}</th>
                                        {dates.map((date) => (
                                            <th key={date} className="admin-shifts__head px-2 py-2.5 text-center align-top font-semibold" data-date={date}>
                                                <span className="flex flex-col items-center gap-0.5">
                                                    <span className="admin-shifts__day-name">{words.text('page.day_head', { weekday: weekdayName(isoWeekday(date), 'short', shell.language), date: formatDayMonth(date, clock) })}</span>
                                                    <span className="admin-shifts__day-total text-xs font-normal text-muted-foreground">{hoursOf(dateMinutes(props.totals, date), words)}</span>
                                                </span>
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    {props.members.map((member) => (
                                        <MemberRow
                                            key={member.id}
                                            member={member}
                                            hue={hueOf(member.id, props.colours)}
                                            dates={dates}
                                            days={props.days}
                                            minutes={memberMinutes(props.totals, member.id)}
                                            pattern={patternOf(props.patterns, member.id)}
                                            words={words}
                                            clock={clock}
                                            editable={props.editable}
                                            onEditDay={(date) => setEditing({ member, date })}
                                            onEditPattern={() => setPatterning(member)}
                                        />
                                    ))}
                                </tbody>
                            </table>
                        </TableScroll>
                        <Legend words={words} clock={clock} />
                    </section>
                )}
            </div>
            {props.editable && <Editors props={props} dates={dates} editing={editing} patterning={patterning} words={words} clock={clock} onClose={close} />}
        </>
    );
}
