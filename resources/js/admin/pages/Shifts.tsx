import { Head, Link, usePage } from '@inertiajs/react';
import { type ReactNode, useId, useState } from 'react';
import { Chevron } from '@/components/glyphs';
import { Initials } from '@/components/initials';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Notice } from '@/components/ui/notice';
import { PageHeader } from '@/components/ui/page-header';
import { Spinner } from '@/components/ui/spinner';
import { Table, TableBody, TableHead, TableRow, Td, Th } from '@/components/ui/table';
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
import { addDays, todayIn } from '../../booking/time';

/*
 * The Shifts screen: the heading and its sentence, beside them This week, the chevrons of the
 * previous and the next week around the range in bold in the tenant's date format, the three kept
 * on one line where the bar wraps, and the shared spinner before the words while a week reads;
 * then the week grid in one card. A row per member: the initials ringed in the member's hue, the
 * name linking to their staff page with the week's hours and the pattern's cadence beneath, and
 * Pattern under them; then seven day columns from the tenant's week start, each headed by the
 * weekday's ICU short name with the day and month on one line and the day's total beneath,
 * today's head on the highlight.
 * A cell's pills are centred: each window a round-cornered pill inside a line, Not working in a
 * dashed line, a day edited alone in a line of the theme's colour with This day only beneath, and a
 * closure on the soft red linking to the Staff page's closures; every total has the closures taken
 * off (the server's). The card's foot is the legend, a sample pill of each, and the footnote on the
 * opening hours.
 *
 * The grid is a table of its own in the card, no sideways box around it, so its head row stays at
 * the top of the window while the page scrolls past the members. Its columns share the card's
 * width: the member's column is 230px, 200px in a card under 1024px, the seven days split the rest
 * evenly, and a long name wraps in its column rather than widening it. Below the wide breakpoint,
 * and whenever the card is narrower than 896px, so the card beside the open sidebar at a smaller
 * desktop window reads the same as a phone, each member becomes a block: the member's line, then
 * seven day rows, each the day's name at its start and the pills running to its end. The head row
 * leaves the screen but not the reading order, and every part keeps its role, so a screen reader
 * still reads a table with its column headers; the day's name in a row is hidden from it, as the
 * column header already names the day. Both conditions are written out in full, the same
 * utilities under each.
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

/**
 * What the block layout changes, part by part, each utility under both conditions: the window
 * below the wide breakpoint and the card narrower than 896px.
 */
const BLOCK = {
    table: 'max-wide:block @max-4xl:block',
    head: 'max-wide:sr-only @max-4xl:sr-only',
    body: 'max-wide:block @max-4xl:block',
    row: 'max-wide:block max-wide:border-t max-wide:py-4 max-wide:first:border-t-0 @max-4xl:block @max-4xl:border-t @max-4xl:py-4 @max-4xl:first:border-t-0',
    member: 'max-wide:block max-wide:border-t-0 max-wide:p-0 max-wide:pb-3 @max-4xl:block @max-4xl:border-t-0 @max-4xl:p-0 @max-4xl:pb-3',
    line: 'max-wide:grid-cols-[auto_minmax(0,1fr)_auto] max-wide:items-center @max-4xl:grid-cols-[auto_minmax(0,1fr)_auto] @max-4xl:items-center',
    pattern: 'max-wide:col-start-3 max-wide:row-start-1 max-wide:mt-0 @max-4xl:col-start-3 @max-4xl:row-start-1 @max-4xl:mt-0',
    cell: 'max-wide:flex max-wide:items-start max-wide:gap-3 max-wide:py-1.5 max-wide:pl-0 max-wide:text-left @max-4xl:flex @max-4xl:items-start @max-4xl:gap-3 @max-4xl:py-1.5 @max-4xl:pl-0 @max-4xl:text-left',
    day: 'max-wide:block @max-4xl:block',
    stack: 'max-wide:min-w-0 max-wide:flex-1 max-wide:items-stretch @max-4xl:min-w-0 @max-4xl:flex-1 @max-4xl:items-stretch',
    edit: 'max-wide:min-h-11 max-wide:flex-row max-wide:flex-wrap max-wide:items-center max-wide:gap-1.5 @max-4xl:min-h-11 @max-4xl:flex-row @max-4xl:flex-wrap @max-4xl:items-center @max-4xl:gap-1.5',
    pill: 'max-wide:w-auto max-wide:min-w-0 max-wide:flex-1 @max-4xl:w-auto @max-4xl:min-w-0 @max-4xl:flex-1',
    alone: 'max-wide:basis-full max-wide:text-left @max-4xl:basis-full @max-4xl:text-left',
};

/**
 * A day column's room: an 8px gap before it, 6px in a card under 1024px, none at its end, and the
 * day's pills from the top of the row.
 */
const DAY_ROOM = 'px-0 pl-2 py-3 text-center align-top last:pr-0 @max-5xl:pl-1.5';

/**
 * The head row's lower edge, a line in the rule's colour that each head cell draws on the pixel
 * row just below it. At rest it lies on the first row's own rule, so the edge stays one line;
 * once the head is held at the window's top as the week scrolls, it travels with the head, where
 * a border would stay behind with the table's grid. The browser paints no such line under a held
 * head's cells in a table whose borders collapse, so the table keeps its cells' borders separate
 * with no space between them, which draws every rule as before: each sits on a cell, or, in the
 * block layout, on a row drawn as a block.
 */
const HEAD_LINE = 'shadow-[0_1px_0_var(--border)]';

/**
 * A cell's pill: one window, the day off, or a closure, in figures of one width at the label's
 * size, smaller in a card under 1024px. A word wider than the pill, such as a long one in a
 * closure's reason, breaks inside it rather than running past the column.
 */
const PILL = 'admin-shifts__pill inline-flex w-full items-center justify-center rounded-event px-1 py-2 text-label font-semibold tabular-nums wrap-anywhere @max-5xl:text-[0.78125rem]';

/**
 * The pill's look per state, every colour a token of the theme: a window inside a line, the day
 * off in a dashed line, a closure on the soft red with its line in the destructive colour, a day
 * changed alone in a 2px line of the theme's primary. A drawn border takes its width off the
 * padding, so every pill keeps one height.
 */
const LOOK = {
    window: 'bg-card inset-ring-[1.5px] inset-ring-border',
    off: 'admin-shifts__pill--off border-[1.5px] border-dashed border-ink-tertiary text-muted-foreground',
    closure: 'admin-shifts__pill--closure bg-destructive-soft font-bold text-destructive inset-ring-[1.5px] inset-ring-destructive/40',
    alone: 'admin-shifts__pill--alone inset-ring-2 inset-ring-primary',
    offAlone: 'admin-shifts__pill--alone border-2 border-primary',
} as const;

/**
 * A day off's pill in a cell, its 1.5px border taken off the padding; 2px where the day was changed
 * alone. The legend's sample of it takes its 1.5px off the sample's padding the same way.
 */
const OFF_ROOM = { line: 'px-[0.15625rem] py-[0.40625rem]', alone: 'px-[0.125rem] py-[0.375rem]', sample: 'px-[0.28125rem] py-[0.03125rem]' } as const;

/** The legend's sample of a pill, at the legend's size. */
const SAMPLE = 'admin-shifts__legend-sample inline-flex rounded-event px-1.5 py-0.5 font-semibold tabular-nums';

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

/** A day's pills, a window each and the day off one, lined in the theme's colour where the day was changed alone, This day only beneath. */
function DayBody({ day, words, clock }: { day: ShiftDay; words: ShiftWords; clock: TenantClock }) {
    const alone = day.source === 'override';

    return (
        <>
            {day.windows.length === 0 ? (
                <span className={cn('admin-shifts__off', PILL, LOOK.off, OFF_ROOM.line, 'group-hover:bg-muted', alone && [LOOK.offAlone, OFF_ROOM.alone], BLOCK.pill)}>{words.text('page.not_working')}</span>
            ) : (
                day.windows.map((shift) => (
                    <span key={`${shift.start}-${shift.end}`} className={cn('admin-shifts__window', PILL, LOOK.window, 'group-hover:bg-muted motion-safe:transition-colors', alone && LOOK.alone, BLOCK.pill)}>
                        {span(shift.start, shift.end, clock)}
                    </span>
                ))
            )}
            {alone && <span className={cn('admin-shifts__alone text-xs text-muted-foreground', BLOCK.alone)}>{words.text('page.this_day_only')}</span>}
        </>
    );
}

type CellProps = { member: ShiftMember; day: ShiftDay; head: string; words: ShiftWords; clock: TenantClock; onEdit: (() => void) | null };

/** A day's pills in a column, the same in a cell that edits and one that only reads. */
const DAY = cn('admin-shifts__day flex w-full flex-col items-center gap-1', BLOCK.edit);

/**
 * A member's day. In the block layout the cell is a row led by the day's name, the column head's
 * words, which a screen reader skips as the column header names the day already.
 */
function Cell({ member, day, head, words, clock, onEdit }: CellProps) {
    const described = useId();

    return (
        <Td className={cn('admin-shifts__cell', DAY_ROOM, BLOCK.cell)} data-date={day.date} data-source={day.source}>
            <span aria-hidden="true" className={cn('admin-shifts__cell-day hidden w-24 shrink-0 py-3 text-left text-sm font-bold', BLOCK.day)}>
                {head}
            </span>
            <div className={cn('flex flex-col items-center gap-1', BLOCK.stack)}>
                {onEdit === null ? (
                    <div className={DAY}>
                        <DayBody day={day} words={words} clock={clock} />
                    </div>
                ) : (
                    <>
                        <button type="button" className={cn('admin-shifts__edit group rounded-event focus-ring', DAY)} aria-describedby={described} onClick={onEdit}>
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
                    <Link key={position} href={closure.business ? STAFF : `${STAFF}/${member.id}`} className={cn('admin-shifts__closure', PILL, LOOK.closure, 'focus-ring hover:bg-destructive/15')} data-business={closure.business ? 'true' : 'false'}>
                        {closureLine(closure, words, clock)}
                    </Link>
                ))}
            </div>
        </Td>
    );
}

type MemberRowProps = {
    member: ShiftMember;
    /** The member's hue, their own colour or the spread's. */
    hue: string;
    dates: readonly string[];
    /** Each date's column head words, which lead its day row in the block layout. */
    heads: readonly string[];
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

/**
 * A member's row: the head with the initials ringed in the member's hue, the name, the week's
 * hours beneath it and the Pattern button under them, at the line's end in the block layout; then
 * the week's cells. A long name breaks inside its column rather than widening it.
 */
function MemberRow({ member, hue, dates, heads, days, minutes, pattern, words, clock, editable, onEditDay, onEditPattern }: MemberRowProps) {
    return (
        <TableRow className={cn('admin-shifts__row hover:bg-transparent', BLOCK.row)} data-staff={member.id}>
            <Td header className={cn('admin-shifts__member px-0 py-3 align-top font-semibold first:pl-0', BLOCK.member)}>
                <div className={cn('grid grid-cols-[auto_minmax(0,1fr)] items-start gap-x-3', BLOCK.line)}>
                    <Initials name={member.name} hue={hue} size="head" className="admin-shifts__initials" />
                    <span className="flex min-w-0 flex-col gap-0.5">
                        <Link href={`${STAFF}/${member.id}`} className="admin-shifts__name font-display text-base wrap-anywhere underline-offset-4 hover:underline">
                            {member.name}
                        </Link>
                        <span className="admin-shifts__row-head text-small text-muted-foreground">{rowHead(minutes, pattern, words, clock)}</span>
                    </span>
                    {editable && (
                        <Button variant="tertiary" size="small" className={cn('admin-shifts__pattern col-start-2 mt-2 justify-self-start', BLOCK.pattern)} aria-label={words.text('page.edit_pattern', { name: member.name })} onClick={onEditPattern}>
                            {words.text('page.pattern')}
                        </Button>
                    )}
                </div>
            </Td>
            {dates.map((date, at) => (
                <Cell key={date} member={member} day={dayOf(days, member.id, date)} head={heads[at] ?? ''} words={words} clock={clock} onEdit={editable ? () => onEditDay(date) : null} />
            ))}
        </TableRow>
    );
}

function WeekBar({ week, last, loading, words, clock, onRead }: { week: ShiftWeek; last: string; loading: boolean; words: ShiftWords; clock: TenantClock; onRead: (query: Record<string, string>) => void }) {
    return (
        <div className="admin-shifts__bar flex flex-wrap items-center gap-2">
            <Button size="small" className="admin-shifts__this-week" disabled={loading} onClick={() => onRead({})}>
                {words.text('page.this_week')}
            </Button>
            <div className="admin-shifts__step flex min-w-0 items-center gap-2">
                <Button iconOnly size="small" icon={<Chevron to="previous" />} className="admin-shifts__previous" disabled={loading} aria-label={words.text('page.previous')} onClick={() => onRead({ week: addDays(week.from, -7) })}>
                    {words.text('page.previous')}
                </Button>
                <h2 className="admin-shifts__week min-w-0 text-base font-semibold" aria-busy={loading ? true : undefined}>
                    {words.text('page.week', { from: formatDate(week.from, clock), to: formatDate(last, clock) })}
                </h2>
                <Button iconOnly size="small" icon={<Chevron to="next" />} className="admin-shifts__next" disabled={loading} aria-label={words.text('page.next')} onClick={() => onRead({ week: addDays(week.from, 7) })}>
                    {words.text('page.next')}
                </Button>
            </div>
            {loading && (
                <span className="admin-shifts__loading text-sm text-muted-foreground">
                    <Spinner size="inline" className="mr-2" />
                    {words.text('page.loading')}
                </span>
            )}
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
        <div className="admin-shifts__legend flex flex-wrap items-center gap-x-4 gap-y-2 border-t px-[1.125rem] py-3 text-small">
            <ul className="flex flex-wrap items-center gap-x-4 gap-y-2">
                <li>{placed(words.text('page.legend_override'), { sample: <span className={cn(SAMPLE, LOOK.window, LOOK.alone)}>{span('12:00', '20:00', clock)}</span> }, legendWords)}</li>
                <li>{placed(words.text('page.legend_off'), { sample: <span className={cn(SAMPLE, LOOK.off, OFF_ROOM.sample)}>{words.text('page.not_working')}</span> }, legendWords)}</li>
                <li>{placed(words.text('page.legend_closure'), { sample: <span className={cn(SAMPLE, LOOK.closure)}>{words.text('page.legend_closed')}</span> }, legendWords)}</li>
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
    const heads = dates.map((date) => words.text('page.day_head', { weekday: weekdayName(isoWeekday(date), 'short', shell.language), date: formatDayMonth(date, clock) }));
    const today = todayIn(clock.timezone, Date.now());

    return (
        <>
            <Head title={words.text('page.title')} />
            <div className="admin-shifts-screen flex flex-col gap-4">
                <PageHeader
                    title={words.text('page.title')}
                    lead={<span className="admin-shifts__lead">{words.text('page.lead')}</span>}
                    actions={<WeekBar week={props.week} last={last} loading={read.loading} words={words} clock={clock} onRead={(query) => read.read(SHIFTS, query)} />}
                    quiet={false}
                />
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
                    <Card flush className="admin-card admin-shifts @container">
                        <Table compact="own" className={cn('mx-[1.125rem] w-[calc(100%-2.25rem)] table-fixed border-separate border-spacing-0', BLOCK.table)}>
                            <TableHead className={BLOCK.head}>
                                <TableRow>
                                    <Th className={cn('admin-shifts__member-head w-[14.375rem] bg-card px-0 py-3 align-top font-semibold whitespace-normal first:pl-0 @max-5xl:w-[12.5rem]', HEAD_LINE)}>{words.text('page.member')}</Th>
                                    {dates.map((date, at) => (
                                        <Th key={date} className={cn('admin-shifts__head bg-card tracking-normal whitespace-normal text-foreground', HEAD_LINE, DAY_ROOM)} data-date={date} aria-current={date === today ? 'date' : undefined}>
                                            <span className="flex flex-col items-center gap-0.5">
                                                <span className={cn('admin-shifts__day-name rounded-full px-2 font-display text-body font-semibold @max-5xl:px-1.5 @max-5xl:text-small', date === today && 'bg-spark text-spark-foreground')}>{heads[at]}</span>
                                                <span className="admin-shifts__day-total text-small font-normal text-muted-foreground">{hoursOf(dateMinutes(props.totals, date), words)}</span>
                                            </span>
                                        </Th>
                                    ))}
                                </TableRow>
                            </TableHead>
                            <TableBody className={BLOCK.body}>
                                {props.members.map((member) => (
                                    <MemberRow
                                        key={member.id}
                                        member={member}
                                        hue={hueOf(member.id, props.colours)}
                                        dates={dates}
                                        heads={heads}
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
                            </TableBody>
                        </Table>
                        <Legend words={words} clock={clock} />
                    </Card>
                )}
            </div>
            {props.editable && <Editors props={props} dates={dates} editing={editing} patterning={patterning} words={words} clock={clock} onClose={close} />}
        </>
    );
}
