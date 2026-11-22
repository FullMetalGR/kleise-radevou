import { Head, router, usePage } from '@inertiajs/react';
import { type ReactNode, useEffect, useMemo, useRef, useState } from 'react';
import { BookingDialog } from '@/bookings/BookingDialog';
import { type Detail, staffShown } from '@/bookings/model';
import { BookingsProvider, type BookingsWords, bookingsWordsOf } from '@/bookings/words';
import { Filters, Legend, Toolbar } from '@/calendar/Chrome';
import { ClosureDialog } from '@/calendar/ClosureDialog';
import { useColourByWrite } from '@/calendar/colour-by';
import { DropConfirm, type Dropping, useDropping } from '@/calendar/dropping';
import { type ColumnActions, ColumnHead } from '@/calendar/ColumnHead';
import { Caret, Plus } from '@/components/glyphs';
import { Grid } from '@/calendar/Grid';
import { Month } from '@/calendar/Month';
import { staffWeekShown, weekDaysOf, type WeekRow, weekRowsOf } from '@/calendar/staff-week';
import { StaffWeek } from '@/calendar/StaffWeek';
import {
    applicableFilters,
    type Block,
    blocksOf,
    CALENDAR_FILTER_SHAPE,
    CALENDAR_MEMORY_KEY,
    CALENDAR_VIEWS,
    type CalendarBooking,
    type CalendarFilters,
    type CalendarOptions,
    type Chip,
    type ColourBy,
    type Colouring,
    colourOf,
    type Opening,
    cut,
    type DayColumn,
    dayColumnsOf,
    filtersSet,
    hoursOn,
    legendShown,
    type Member,
    monthChipsOf,
    NO_CALENDAR_FILTERS,
    rangeOf,
    shownRows,
    slotStaffOf,
    swatchesOf,
    titleOf,
    type View,
    type Who,
    weekAxisOf,
    type Window,
} from '@/calendar/model';
import { NEUTRAL, type OwnColours } from '@/lib/hue';
import { type Calendar as CalendarState, useCalendar } from '@/calendar/useCalendar';
import { type CalendarKey, CalendarProvider, type CalendarWords, calendarWordsOf, shiftWordsOf, useClosureWords } from '@/calendar/words';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { FiltersToggle } from '@/components/filters-toggle';
import { DropdownMenu, type MenuItem } from '@/components/ui/dropdown-menu';
import { Notice } from '@/components/ui/notice';
import { PageHeader } from '@/components/ui/page-header';
import { statusLabel } from '@/components/ui/status-chip';
import { clockOf } from '@/lib/format';
import { readMemory, writeMemory } from '@/lib/session-memory';
import { useShell } from '@/lib/strings';
import { useDesktop } from '@/lib/viewport';
import { DayEditor } from '@/shifts/DayEditor';
import { owningFailure } from '@/lib/visits';
import { useToasts } from '@/toast/toasts';
import type { ShiftWords } from '@/shifts/words';
import type { AdminStrings, BookingsStrings, CalendarStrings, SharedProps, ShiftsStrings } from '@/types';
import type { Prefill } from '@/wizard/model';
import { useNewBooking } from '@/wizard/NewBooking';

/*
 * The Calendar screen: the title, the first row's New booking its primary button; one controls
 * card, the toolbar over the filters, Add a quieter menu at the toolbar's far side, round and named
 * below the admin's breakpoint; an error notice on failure; the grid's card, the grid with the legend
 * as its foot, a labelled region named by the toolbar's date title; and the footnote under it. Who
 * is the one server parameter: Scheduled staff by default, All staff, Me or a picked set, the
 * window answering the members it resolved to, whose blocks the grid draws and whom the legend
 * lists; status and service cut the fetched window here with no request. The filters are remembered
 * per tab in sessionStorage, and a remembered Who is not applied while its control is hidden, the
 * screen then showing All staff; the view and the date are not remembered: the screen opens on the
 * view and the date a link names, `?view=` and `?date=` read once by the server (the opening prop;
 * Home's seven days open a Day so), else on Day and the tenant's today. A block opens the booking
 * dialog as client state; an empty slot opens the wizard with that slot's date and the one member
 * shown, if one is, never the slot's own payload.
 *
 * A viewer holding viewOwnCalendar alone (the pinned prop) sees Who read-only on Me, their own row,
 * what My calendar showed: Approve and Reject on it where the server allows them, no wizard, since
 * the catalog is null; with no row linked, the sentence saying why in place of the grid.
 *
 * Month, the third view, is its own grid (calendar/Month): each day's bookings the same cuts leave,
 * as chips, and a day's date menu offering New booking where the wizard is, New closure where the
 * Add menu offers one, and Open day; from the breakpoint the legend under it names the chips'
 * marks. The chips are computed once per window answer and filter change, in Month alone.
 *
 * Week for a team is a grid of people by days (calendar/StaffWeek) where the staff choice is real,
 * the viewer is not pinned to their own row and the window answers two members or more: a row per
 * member, then the other bookings' row where the week has one, each day of a row its chips and its
 * date menu on that member, New booking and New closure prefilled with the member and the day, the
 * closure only where a member's own can be made (manageSettings with the staff feature): a tenant
 * with a roster and no such feature reads the grid and closes no one from it. Otherwise Week stays
 * the one time grid. Its rows are computed once per window answer and filter change, in that grid
 * alone.
 *
 * Colour by is the settings row's word in the shared props, every block coloured by its member, its
 * service's category or its status, the legend following; the filter card's select writes it, for a
 * viewer holding manageSettings alone.
 *
 * Below the admin's breakpoint every view fits the screen. A cell's line saying how many more
 * opens Day on its date, and from Week's grid of people first narrows Who to the row's member,
 * where the viewer may change Who; the filter card folds away behind a toggle on the title's line,
 * closed on load and wearing how many filters are set; the legend sits under Day and Week's time
 * grid alone, Month and the grid of people drawing none of the marks it names.
 *
 * Day as member columns: where the staff choice is real, or the viewer is pinned, Day is a column
 * per member the window answers, then the other bookings' column on a day that has one; where it is
 * not, Day stays the one time grid with no head and no menu. Each member's head carries the menu of
 * New booking here (the wizard on the member and the day, where the wizard is offered), Closure
 * here (the closure dialog on the member and the date, manageSettings with the staff feature, since
 * a member's closure is refused without it), Edit this day's hours (the Shifts day editor on the
 * member and the date, manageSettings with the staff feature) and Open staff page; a viewer pinned
 * to Me sees the head without it, and a column whose day is not yet read holds it disabled until
 * the read lands. The day editor's save redirects back, which lands every prop again, and the
 * screen re-reads the window it wants, as after a closure.
 *
 * A booking's block drags in Week and Day where the server says it is movable, and at desktop
 * width its chip in the grid of people and in Month, a chip below that width staying where it is:
 * the drop asks the move's read for its verdict and a placeable one opens the Move form's
 * confirmation (calendar/dropping), the same for each.
 */

type Props = SharedProps & { options: CalendarOptions; own: Member | null; pinned: boolean; window: Window; detail: Detail | null; opening?: Opening; colours: OwnColours; strings: AdminStrings & { calendar?: CalendarStrings; bookings?: BookingsStrings; shifts?: ShiftsStrings } };

/** What the closure dialog opens on: the Add menu's none, or a Day column's member and date. */
type Closing = { staffId?: number | null; date?: string };

/** The Calendar's address, which Home's day links open as well. */
export const CALENDAR_PATH = '/admin/calendar';

const TITLE_ID = 'calendar-title';

/** The filter card's id, which its toggle names below the breakpoint. */
const FILTERS_ID = 'calendar-filters';

/** Month's chips outside Month: none. */
const NO_CHIPS: ReadonlyMap<string, readonly Chip[]> = new Map();

/** The grid of people's rows outside it: none. */
const NO_ROWS: readonly WeekRow[] = [];

/** A failed window read, in words that hold in Week, Day and Month alike. */
export const CALENDAR_LOAD_ERROR: CalendarKey = 'page.load_error';

/** The toast where Colour by's write failed and the server worded nothing. */
const COLOUR_BY_NOT_SAVED: CalendarKey = 'page.colour_by_not_saved';

/** A pinned viewer whose sign-in names no staff row. */
export const UNLINKED: CalendarKey = 'page.unlinked';

/** The screen's three sections for everything under it: its own, the shifts section beside it and the dialog's bookings section. */
function Words({ calendar, shifts, bookings, children }: { calendar: CalendarWords; shifts: ShiftWords; bookings: BookingsWords; children: ReactNode }) {
    return (
        <CalendarProvider calendar={calendar} shifts={shifts}>
            <BookingsProvider words={bookings}>{children}</BookingsProvider>
        </CalendarProvider>
    );
}

function sessionStorageOf(): Storage {
    return window.sessionStorage;
}

/** Add's items, 3.1: New booking where the wizard is offered, Closure for manageSettings. */
function addItems(bookable: boolean, settles: boolean, onBook: () => void, onClose: () => void, words: CalendarWords): MenuItem[] {
    return [
        ...(bookable ? [{ key: 'booking', label: words.text('page.add_booking'), className: 'admin-calendar-add__booking', onSelect: onBook }] : []),
        ...(settles ? [{ key: 'closure', label: words.text('page.add_closure'), className: 'admin-calendar-add__closure', onSelect: onClose }] : []),
    ];
}

/**
 * Day's member columns only where the staff choice is real, or for the pinned viewer's own
 * row, and only with at least one member column; none in Week and Month.
 */
function columnsFor(view: View, real: boolean, window: Window, blocks: Block[], date: string): DayColumn[] {
    return view === 'day' && real ? dayColumnsOf(window, blocks, date) : [];
}

/** Where a slot hands the wizard its date and the column's member, else the one member shown; none without the wizard. */
function slotOf(bookable: boolean, members: readonly Member[], open: (prefill: Prefill) => void): ((date: string, staffId?: number) => void) | undefined {
    return bookable ? (date, staffId) => open({ date, staffId: staffId ?? slotStaffOf(members) }) : undefined;
}

/** Whether a member's own closure can be made: manageSettings with the staff feature, refused without it, so the dialog would fall back to the whole business. */
function closesMembers(props: Props): boolean {
    return props.permissions.includes('manageSettings') && props.entitlements.includes('staff');
}

/** The closure a cell of the people's grid offers, a member's own: none where one cannot be made, however the grid came to show. */
function memberClosureOf(props: Props, onClosure: SurfaceProps['onClosure']): SurfaceProps['onClosure'] {
    return closesMembers(props) ? onClosure : undefined;
}

/**
 * Whether the legend is drawn: where the colouring names one, and below the breakpoint only under
 * the views that draw a booking whole, Day and Week's time grid. Month's and the grid of people's
 * chips are cut to their start times there, drawing none of the marks the legend names.
 */
function legendDrawn(colours: Colouring, celled: boolean, desktop: boolean): boolean {
    return legendShown(colours) && (desktop || !celled);
}

/**
 * A Day column's menu actions: New booking here where the wizard is offered, Closure here and
 * Edit this day's hours where a member's closure can be made, Open staff page for a viewBookings
 * holder; none for a viewer pinned to Me, who sees the head alone.
 */
function columnActionsOf(props: Props, handlers: Required<Pick<ColumnActions, 'book' | 'close' | 'edit'>>): ColumnActions {
    if (props.pinned) {
        return {};
    }

    const staffs = closesMembers(props);

    return {
        ...(props.catalog ? { book: handlers.book } : {}),
        ...(staffs ? { close: handlers.close, edit: handlers.edit } : {}),
        ...(props.permissions.includes('viewBookings') ? { staffPage: (member: Member) => `/admin/staff/${member.id}` } : {}),
    };
}

/** The Shifts day editor on the Calendar: the member's day as the window carries it, in the shifts section's words. */
function CalendarDayEditor({ member, date, props, words, onClose }: { member: Member; date: string; props: Props; words: ShiftWords; onClose: () => void }) {
    const hours = hoursOn(props.window, member.id, date);

    return (
        <DayEditor
            member={member}
            date={date}
            windows={hours.windows}
            source={hours.source}
            patternWindows={hours.pattern}
            clock={clockOf(props)}
            granularity={props.settings.granularity}
            words={words}
            onClose={onClose}
        />
    );
}

type SurfaceProps = {
    props: Props;
    calendar: CalendarState;
    kept: readonly CalendarBooking[];
    ids: readonly number[] | null;
    blocks: readonly Block[];
    colours: Colouring;
    columns: readonly DayColumn[];
    slot: ((date: string, staffId?: number) => void) | undefined;
    onClosure: ((date: string, staffId?: number) => void) | undefined;
    head: (column: DayColumn) => ReactNode;
    dropping: Pick<Dropping, 'drop' | 'dropChip'>;
    words: CalendarWords;
    /** Day on the date narrowed to the member, where the viewer may change Who. */
    onMember?: (date: string, staffId: number) => void;
};

/**
 * The card's content: Month, Week's grid of people, or the time grid of Week and Day, with the
 * legend at its foot where it is drawn (legendDrawn); Month's chips and the people's rows computed
 * here, once per window answer and filter change.
 */
function Surface({ props, calendar, kept, ids, blocks, colours, columns, slot, onClosure, head, dropping, words, onMember }: SurfaceProps) {
    const shell = useShell();
    const desktop = useDesktop();
    const closed = useClosureWords();
    const clock = clockOf(props);
    const { timezone, dateFormat, timeFormat } = clock;
    const month = calendar.view === 'month';
    const people = staffWeekShown(calendar.view, props.pinned, colours.palette, props.window.members);
    const chips = useMemo(() => (month ? monthChipsOf(kept, ids, props.tenant.timezone, props.window) : NO_CHIPS), [month, kept, ids, props.tenant.timezone, props.window]);
    const days = weekDaysOf(calendar.date, props.settings.week_start);
    const rows = useMemo(
        () => (people ? weekRowsOf(props.window, kept, weekDaysOf(calendar.date, props.settings.week_start), timezone, { timezone, dateFormat, timeFormat }, closed) : NO_ROWS),
        [people, props.window, kept, calendar.date, props.settings.week_start, timezone, dateFormat, timeFormat, closed],
    );
    // The views drawing chips in cells: their legend names the chips' marks, and no slot starts a booking.
    const celled = month || people;
    // Week bounded as Day's axis is, by the shown members' hours that week and every block in it.
    const span = calendar.view === 'week' ? weekAxisOf(props.window, blocks, rangeOf('week', calendar.date, props.settings.week_start).from) : undefined;

    return (
        <>
            {calendar.view === 'month' ? (
                <Month
                    date={calendar.date}
                    weekStart={props.settings.week_start}
                    today={calendar.today}
                    chips={chips}
                    closedDays={props.window.closed_days ?? []}
                    colouring={colours}
                    clock={clock}
                    onOpen={calendar.open}
                    onBook={slot}
                    onClosure={onClosure}
                    onDay={calendar.showDay}
                    onDrop={dropping.dropChip}
                />
            ) : people ? (
                <StaffWeek
                    days={days}
                    rows={rows}
                    today={calendar.today}
                    colouring={colours}
                    clock={clock}
                    onOpen={calendar.open}
                    onBook={slot}
                    onClosure={memberClosureOf(props, onClosure)}
                    onDay={calendar.showDay}
                    onMember={onMember}
                    onDrop={dropping.dropChip}
                />
            ) : (
                <Grid
                    view={calendar.view}
                    date={calendar.date}
                    blocks={blocks}
                    colouring={colours}
                    granularity={props.settings.granularity}
                    weekStart={props.settings.week_start}
                    clock={clock}
                    onBlock={calendar.open}
                    onSlot={slot}
                    span={span}
                    columns={columns}
                    onDrop={dropping.drop}
                    head={head}
                />
            )}
            {legendDrawn(colours, celled, desktop) && (
                <Legend
                    by={colours.by}
                    swatches={swatchesOf(colours, props.window.members, celled ? [...[...chips.values()].flat(), ...rows.flatMap((row) => row.cells.flatMap((cell) => cell.chips))] : blocks, (status) => statusLabel(status, shell), words)}
                    chips={celled}
                    day={columns.length > 0 || people}
                    hint={slot !== undefined && !celled}
                />
            )}
        </>
    );
}

type ControlsProps = {
    props: Props;
    calendar: CalendarState;
    title: string;
    adds: MenuItem[];
    settles: boolean;
    filters: CalendarFilters;
    /** Whether Who's control is drawn. */
    shown: boolean;
    /** Whether Who is the viewer's to change: its control drawn and the viewer not pinned. */
    whoFree: boolean;
    onChange: (next: CalendarFilters) => void;
    words: CalendarWords;
};

/**
 * The controls card: the toolbar over the filters. Below the breakpoint Add is a round plus and the
 * filters fold away behind their button on the title's line, the card closed until the button
 * opens it and the button wearing how many filters are set; from the breakpoint the card is always
 * open and there is no button.
 */
function Controls({ props, calendar, title, adds, settles, filters, shown, whoFree, onChange, words }: ControlsProps) {
    const desktop = useDesktop();
    const [unfolded, setUnfolded] = useState(false);
    const colourWrite = useColourByWrite(words.text(COLOUR_BY_NOT_SAVED));
    const set = filtersSet(filters, whoFree);
    const folding = desktop ? {} : { id: FILTERS_ID, hidden: !unfolded };

    return (
        <Card className="admin-calendar-controls flex flex-col gap-4">
            <Toolbar
                views={CALENDAR_VIEWS}
                calendar={calendar}
                title={title}
                titleId={TITLE_ID}
                add={
                    adds.length > 0 ? (
                        <DropdownMenu
                            label={words.text('page.add')}
                            items={adds}
                            align="end"
                            className="max-w-72"
                            note={settles ? words.text('page.add_hint') : undefined}
                            trigger={
                                <Button icon={<Plus />} iconOnly={!desktop} className="admin-calendar-add ms-auto">
                                    {words.text('page.add')}
                                    {desktop && <Caret />}
                                </Button>
                            }
                        />
                    ) : undefined
                }
                filters={
                    desktop ? undefined : (
                        <FiltersToggle
                            open={unfolded}
                            onToggle={() => setUnfolded((open) => !open)}
                            controls={FILTERS_ID}
                            label={words.text('filters.label')}
                            count={set}
                            countName={words.choice('filters.active', set)}
                        />
                    )
                }
            />
            <Filters
                {...folding}
                filters={filters}
                options={props.options}
                staffShown={shown}
                own={props.own}
                pinned={props.pinned}
                shown={props.window.members.map((member) => member.id)}
                colours={props.colours}
                onChange={onChange}
                colourBy={{ value: props.settings.colour_by, allowed: settles, busy: colourWrite.busy, onChange: colourWrite.write }}
            />
        </Card>
    );
}

export default function Calendar() {
    const { props } = usePage<Props>();
    const clock = clockOf(props);
    const shell = useShell();
    const words = calendarWordsOf(props.strings, shell.language);
    const bookingsWords = bookingsWordsOf(props.strings, shell.language);
    // Held from render to render, the people's rows being worded in them and built once per answer.
    const shiftWords = useMemo(() => shiftWordsOf(props.strings, shell.language), [props.strings, shell.language]);
    const [filters, setFilters] = useState<CalendarFilters>(NO_CALENDAR_FILTERS);
    const restored = useRef(false);
    const pinned = props.pinned;
    const own = props.own;
    // 6.1: shown while the choice is real, and never hidden while it holds a value but the default.
    const shown = pinned || staffShown(props.entitlements, props.options.members, null) || filters.who !== 'scheduled';
    // 6.2: a Who behind a hidden control is not applied, and the screen shows everyone.
    const who: Who = pinned ? 'me' : shown ? filters.who : 'all';
    const calendar = useCalendar({ path: CALENDAR_PATH, linked: props.opening, timezone: props.tenant.timezone, weekStart: props.settings.week_start, who, staff: filters.staff, window: props.window, detail: props.detail, words: bookingsWords });
    const { open: openWizard } = useNewBooking();
    const [closing, setClosing] = useState<Closing | null>(null);
    const [editing, setEditing] = useState<Member | null>(null);
    const dropping = useDropping({ bookings: props.window.bookings, timezone: props.tenant.timezone, words: bookingsWords });

    useEffect(() => {
        if (restored.current) {
            return;
        }

        restored.current = true;
        setFilters(applicableFilters(readMemory(CALENDAR_MEMORY_KEY, CALENDAR_FILTER_SHAPE, NO_CALENDAR_FILTERS, sessionStorageOf), props.entitlements, props.options.members, own, props.options.staff));
    });

    const change = (next: CalendarFilters) => {
        setFilters(next);
        writeMemory(CALENDAR_MEMORY_KEY, next, sessionStorageOf);
    };

    // Who is the viewer's to change where its control is drawn and they are not pinned to their
    // own row.
    const whoFree = shown && !pinned;
    const showMember = (date: string, staffId: number) => {
        change({ ...filters, who: 'picked', staff: [staffId] });
        calendar.showDay(date);
    };

    const palette = staffShown(props.entitlements, props.options.members, null);
    const colours: Colouring = { by: props.settings.colour_by, palette, categories: props.options.categories, own: props.colours };

    const timezone = props.tenant.timezone;
    const members = props.window.members;
    // Every item of a shown member is a block, the implicit row's where the answer names it; All
    // staff shows every item.
    const ids = useMemo(() => shownRows(props.window), [props.window]);
    const kept = useMemo(() => cut(props.window.bookings, filters), [props.window.bookings, filters]);
    const blocks = blocksOf(kept, ids, timezone, words, props.window);
    const title = titleOf(calendar.view, calendar.date, props.settings.week_start, clock, words);
    const settles = props.permissions.includes('manageSettings');
    const adds = addItems(Boolean(props.catalog), settles, () => openWizard(), () => setClosing({}), words);
    const columns = columnsFor(calendar.view, pinned || palette, props.window, blocks, calendar.date);
    const day = calendar.date;
    const slot = slotOf(Boolean(props.catalog), members, openWizard);
    const actions = columnActionsOf(props, {
        book: (member) => openWizard({ date: day, staffId: member.id }),
        close: (member) => setClosing({ staffId: member.id, date: day }),
        edit: setEditing,
    });

    return (
        <Words calendar={words} shifts={shiftWords} bookings={bookingsWords}>
            <Head title={words.text('page.title')} />
            <div className="admin-calendar-screen @container flex flex-col gap-4">
                <PageHeader className="admin-calendar-heading" title={words.text('page.title')} />
                {pinned && own === null ? (
                    <Notice kind="info" className="admin-calendar-unlinked">
                        {words.text(UNLINKED)}
                    </Notice>
                ) : (
                    <>
                        <Controls props={props} calendar={calendar} title={title} adds={adds} settles={settles} filters={filters} shown={shown} whoFree={whoFree} onChange={change} words={words} />
                        {calendar.error && (
                            <Notice kind="error" className="admin-calendar-error">
                                {words.text(CALENDAR_LOAD_ERROR)}
                            </Notice>
                        )}
                        <section className="admin-calendar flex flex-col overflow-hidden rounded-xl border" aria-labelledby={TITLE_ID} aria-busy={calendar.loading}>
                            <Surface
                                props={props}
                                calendar={calendar}
                                kept={kept}
                                ids={ids}
                                blocks={blocks}
                                colours={colours}
                                columns={columns}
                                slot={slot}
                                onClosure={settles ? (date, staffId) => setClosing({ date, staffId }) : undefined}
                                dropping={dropping}
                                head={(column) => <ColumnHead column={column} date={day} clock={clock} hue={column.member === null ? NEUTRAL : colourOf(column.member.id, palette, props.colours)} actions={actions} />}
                                words={words}
                                onMember={whoFree ? showMember : undefined}
                            />
                        </section>
                        <p className="admin-calendar-footnote text-xs text-muted-foreground">{words.text('page.footnote')}</p>
                    </>
                )}
                {closing !== null && (
                    <ClosureDialog
                        onClose={() => setClosing(null)}
                        members={props.entitlements.includes('staff') ? members : []}
                        clock={clock}
                        weekStart={props.settings.week_start}
                        granularity={props.settings.granularity}
                        prefill={closing}
                        staffShown={palette}
                    />
                )}
                <DropConfirm dropping={dropping} clock={clock} />
                {editing !== null && <CalendarDayEditor member={editing} date={day} props={props} words={shiftWords} onClose={() => setEditing(null)} />}
                {calendar.dialog !== null && (
                    <BookingDialog
                        state={calendar.dialog}
                        staffShown={palette}
                        clock={clock}
                        weekStart={props.settings.week_start}
                        onClose={calendar.close}
                        failure={calendar.acts.failure}
                        busy={calendar.acts.busy}
                        onAct={calendar.dialog.kind === 'detail' && calendar.dialog.detail.state === 'found' ? calendar.acts.act(calendar.dialog.detail.booking.uuid) : undefined}
                    />
                )}
            </div>
        </Words>
    );
}
