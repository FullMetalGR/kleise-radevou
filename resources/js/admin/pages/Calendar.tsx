import { Head, router, usePage } from '@inertiajs/react';
import { type ReactNode, useEffect, useMemo, useRef, useState } from 'react';
import { BookingDialog } from '@/bookings/BookingDialog';
import { type Detail, staffShown } from '@/bookings/model';
import { BookingsProvider, type BookingsWords, bookingsWordsOf } from '@/bookings/words';
import { DesktopWeek, Filters, Legend, Toolbar } from '@/calendar/Chrome';
import { ClosureDialog } from '@/calendar/ClosureDialog';
import { useColourByWrite } from '@/calendar/colour-by';
import { DropConfirm, useDropping } from '@/calendar/dropping';
import { type ColumnActions, ColumnHead } from '@/calendar/ColumnHead';
import { Caret } from '@/components/glyphs';
import { Grid } from '@/calendar/Grid';
import {
    applicableFilters,
    type Block,
    blocksOf,
    CALENDAR_FILTER_SHAPE,
    CALENDAR_MEMORY_KEY,
    CALENDAR_VIEWS,
    type CalendarFilters,
    type CalendarOptions,
    type ColourBy,
    type Colouring,
    colourOf,
    type Opening,
    countsOf,
    cut,
    type DayColumn,
    dayColumnsOf,
    hoursOn,
    legendShown,
    type Member,
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
import { useCalendar } from '@/calendar/useCalendar';
import { type CalendarKey, CalendarProvider, type CalendarWords, calendarWordsOf, shiftWordsOf } from '@/calendar/words';
import { Button } from '@/components/ui/button';
import { DropdownMenu, type MenuItem } from '@/components/ui/dropdown-menu';
import { Notice } from '@/components/ui/notice';
import { statusLabel } from '@/components/ui/status-chip';
import { clockOf } from '@/lib/format';
import { readMemory, writeMemory } from '@/lib/session-memory';
import { useShell } from '@/lib/strings';
import { DayEditor } from '@/shifts/DayEditor';
import { owningFailure } from '@/lib/visits';
import { useToasts } from '@/toast/toasts';
import type { ShiftWords } from '@/shifts/words';
import type { AdminStrings, BookingsStrings, CalendarStrings, SharedProps, ShiftsStrings } from '@/types';
import type { Prefill } from '@/wizard/model';
import { useNewBooking } from '@/wizard/NewBooking';

/*
 * The Calendar screen: the title with Add, a primary menu, at its right; one controls card, the
 * toolbar over the filters; an error notice on failure; the grid's card, the grid with the legend
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
 * Month, the third view: the grid draws the counts per day and status the same cuts leave in place
 * of blocks, with no slot selection, the legend is not rendered, and a day pressed opens Day view
 * on it. The cut and the counts are computed once per window answer and filter change, and the
 * counts in Month alone.
 *
 * Colour by is the settings row's word in the shared props, every block coloured by its member, its
 * service's category or its status, the legend following; the filter card's select writes it, for a
 * viewer holding manageSettings alone.
 *
 * Below the admin's breakpoint, 1.6, Week stays a desktop view and says so: its notice in place of
 * the grid and the legend, over a control opening Day view on the date the screen holds.
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
 * A booking's block drags in Week and Day where the server says it is movable: the drop asks the
 * move's read for its verdict and a placeable one opens the Move form's confirmation
 * (calendar/dropping); the grid takes none in Month.
 */

type Props = SharedProps & { options: CalendarOptions; own: Member | null; pinned: boolean; window: Window; detail: Detail | null; opening?: Opening; colours: OwnColours; strings: AdminStrings & { calendar?: CalendarStrings; bookings?: BookingsStrings; shifts?: ShiftsStrings } };

/** What the closure dialog opens on: the Add menu's none, or a Day column's member and date. */
type Closing = { staffId?: number | null; date?: string };

/** The Calendar's address, which Home's day links open as well. */
export const CALENDAR_PATH = '/admin/calendar';

const TITLE_ID = 'calendar-title';

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

/** Where a slot hands the wizard its date and the column's member, else the one member shown (3.1); none without the wizard. */
function slotOf(bookable: boolean, members: readonly Member[], open: (prefill: Prefill) => void): ((date: string, staffId?: number) => void) | undefined {
    return bookable ? (date, staffId) => open({ date, staffId: staffId ?? slotStaffOf(members) }) : undefined;
}

/**
 * A Day column's menu actions: New booking here where the wizard is offered, Closure here and
 * Edit this day's hours for manageSettings with the staff feature (a member's closure is refused
 * without it, so the dialog would fall back to the whole business), Open staff page for a
 * viewBookings holder; none for a viewer pinned to Me, who sees the head alone.
 */
function columnActionsOf(props: Props, handlers: Required<Pick<ColumnActions, 'book' | 'close' | 'edit'>>): ColumnActions {
    if (props.pinned) {
        return {};
    }

    const staffs = props.permissions.includes('manageSettings') && props.entitlements.includes('staff');

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

export default function Calendar() {
    const { props } = usePage<Props>();
    const clock = clockOf(props);
    const shell = useShell();
    const words = calendarWordsOf(props.strings, shell.language);
    const bookingsWords = bookingsWordsOf(props.strings, shell.language);
    const shiftWords = shiftWordsOf(props.strings, shell.language);
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
    const colourWrite = useColourByWrite(words.text(COLOUR_BY_NOT_SAVED));
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

    const palette = staffShown(props.entitlements, props.options.members, null);
    const colours: Colouring = { by: props.settings.colour_by, palette, categories: props.options.categories, own: props.colours };

    const timezone = props.tenant.timezone;
    const members = props.window.members;
    // Every item of a shown member is a block, the implicit row's where the answer names it; All
    // staff shows every item.
    const ids = useMemo(() => shownRows(props.window), [props.window]);
    const kept = useMemo(() => cut(props.window.bookings, filters), [props.window.bookings, filters]);
    const blocks = blocksOf(kept, ids, timezone, words);
    const counts = useMemo(() => (calendar.view === 'month' ? countsOf(kept, ids, timezone) : undefined), [calendar.view, kept, ids, timezone]);
    const title = titleOf(calendar.view, calendar.date, props.settings.week_start, clock, words);
    const settles = props.permissions.includes('manageSettings');
    const adds = addItems(Boolean(props.catalog), settles, () => openWizard(), () => setClosing({}), words);
    const columns = columnsFor(calendar.view, pinned || palette, props.window, blocks, calendar.date);
    const day = calendar.date;
    // Week bounded as Day's axis is, by the shown members' hours that week and every block in it.
    const span = calendar.view === 'week' ? weekAxisOf(props.window, blocks, rangeOf('week', calendar.date, props.settings.week_start).from) : undefined;
    const slot = slotOf(Boolean(props.catalog), members, openWizard);
    const actions = columnActionsOf(props, {
        book: (member) => openWizard({ date: day, staffId: member.id }),
        close: (member) => setClosing({ staffId: member.id, date: day }),
        edit: setEditing,
    });

    return (
        <Words calendar={words} shifts={shiftWords} bookings={bookingsWords}>
            <Head title={words.text('page.title')} />
            <div className="admin-calendar-screen flex flex-col gap-4">
                <div className="admin-calendar-heading flex flex-wrap items-center justify-between gap-3">
                    <h1 className="text-2xl font-semibold">{words.text('page.title')}</h1>
                    {adds.length > 0 && (
                        <DropdownMenu
                            label={words.text('page.add')}
                            items={adds}
                            align="end"
                            className="w-56"
                            note={settles ? words.text('page.add_hint') : undefined}
                            trigger={
                                <Button variant="primary" className="admin-calendar-add">
                                    {words.text('page.add')}
                                    <Caret />
                                </Button>
                            }
                        />
                    )}
                </div>
                {pinned && own === null ? (
                    <Notice kind="info" className="admin-calendar-unlinked">
                        {words.text(UNLINKED)}
                    </Notice>
                ) : (
                    <>
                        <div className="admin-calendar-controls flex flex-col gap-3 rounded-xl border px-4 py-3">
                            <Toolbar views={CALENDAR_VIEWS} calendar={calendar} title={title} titleId={TITLE_ID} />
                            <Filters
                                filters={filters}
                                options={props.options}
                                staffShown={shown}
                                own={own}
                                pinned={pinned}
                                shown={members.map((member) => member.id)}
                                colours={props.colours}
                                onChange={change}
                                colourBy={{ value: props.settings.colour_by, allowed: props.permissions.includes('manageSettings'), busy: colourWrite.busy, onChange: colourWrite.write }}
                            />
                        </div>
                        {calendar.error && (
                            <Notice kind="error" className="admin-calendar-error">
                                {words.text(CALENDAR_LOAD_ERROR)}
                            </Notice>
                        )}
                        <section className="admin-calendar flex flex-col overflow-hidden rounded-xl border" aria-labelledby={TITLE_ID} aria-busy={calendar.loading}>
                            <DesktopWeek view={calendar.view} onDay={() => calendar.setView('day')}>
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
                                    counts={counts}
                                    span={span}
                                    onDay={calendar.showDay}
                                    columns={columns}
                                    onDrop={dropping.drop}
                                    head={(column) => <ColumnHead column={column} date={day} clock={clock} hue={column.member === null ? NEUTRAL : colourOf(column.member.id, palette, props.colours)} actions={actions} />}
                                />
                                {legendShown(colours, calendar.view) && (
                                    <Legend by={colours.by} swatches={swatchesOf(colours, members, blocks, (status) => statusLabel(status, shell), words)} day={columns.length > 0} hint={slot !== undefined} />
                                )}
                            </DesktopWeek>
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
