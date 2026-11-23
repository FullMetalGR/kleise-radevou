import { type KeyboardEvent, type ReactNode, useState } from 'react';
import { Button } from '@/components/ui/button';
import { CHECK_BOX } from '@/components/ui/check-field';
import { Field } from '@/components/ui/field';
import { FIELD, FIELD_HEIGHT, PRESSED_HOVER } from '@/components/ui/input';
import { MENU_OPTION, Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Segmented } from '@/components/ui/segmented';
import { Select } from '@/components/ui/select';
import { statusLabel } from '@/components/ui/status-chip';
import type { Choice } from '@/bookings/model';
import { useShell } from '@/lib/strings';
import { cn } from '@/lib/utils';
import { BADGE_ORDER, BlockBadge, badgeMeaning, InsideMark } from './Badge';
import { CHIP_OUTLINES, NOTED } from './Chip';
import { Chevron, NoteSheet, SelectChevron, Tick } from '@/components/glyphs';
import { Initials } from '@/components/initials';
import { type CalendarFilters, type CalendarOptions, CLOSED_DAY, COLOUR_BY_CHOICES, type ColourBy, idsOf, type Member, type Swatch, type View, type Who, whoSummary } from './model';
import { hueOf, type OwnColours } from '@/lib/hue';
import type { Calendar } from './useCalendar';
import { type CalendarKey, useCalendarWords } from './words';

/*
 * The Calendar's chrome around the grid: the toolbar and the filters, the two rows of the
 * controls card, and the legend at the grid card's foot. Each is a plain component over the
 * screen's state; the rules live in the model.
 * Every word is a key of the calendar section, a constant that held one holding its key.
 */

type ToolbarProps = {
    /** The views the screen offers, in order. */
    views: readonly View[];
    /** The screen's view and its moves, useCalendar's answer, handed over as one value. */
    calendar: Pick<Calendar, 'view' | 'setView' | 'goToday' | 'previous' | 'next'>;
    title: string;
    titleId: string;
    /** The screen's Add menu, closing the row where the viewer may add anything. */
    add?: ReactNode;
    /** The control that folds the filters away, on the title's line, where the screen folds them. */
    filters?: ReactNode;
};

/** Each view's word on the segmented control. */
const VIEW_LABELS: Record<View, CalendarKey> = { week: 'toolbar.views.week', day: 'toolbar.views.day', month: 'toolbar.views.month' };

/**
 * The controls card's first row: a segmented control of the views offered, Today, Previous and
 * Next as chevrons, the date title that names the region and is read out as it changes, then Add
 * at the row's far side. Today, Previous and Next are one group, so a narrow toolbar wraps them
 * together. The title asks the room of its longest date and takes what is left, which stands Add
 * at the far side beside it. On a row too short for all of it Add wraps first, to a line of its
 * own, and on a shorter one the title wraps with it; the screen hands Add in with the margin that
 * keeps it at the far side on either. Below the admin's breakpoint the view switch spans the row,
 * the moves and Add share the next, Add at its far end, and the title comes last on a line of its
 * own, sharing it with the filters' toggle where the screen hands one in, the title taking the
 * room the toggle leaves. A date never breaks: the title is never narrower than its longest word,
 * one whole date, so it breaks only between Week's two dates, and where even one date and the
 * toggle cannot share the line, the toggle wraps under the title rather than the page running
 * sideways.
 */
export function Toolbar({ views, calendar, title, titleId, add, filters }: ToolbarProps) {
    const words = useCalendarWords();
    const heading = (className: string) => (
        <h2 id={titleId} aria-live="polite" className={cn('admin-calendar-title min-w-0 grow text-section-title wrap-break-word', className)}>
            {title}
        </h2>
    );

    return (
        <div className="admin-calendar-toolbar flex flex-wrap items-center gap-3">
            <Segmented<View>
                label={words.text('toolbar.view')}
                options={views.map((value) => ({ value, label: words.text(VIEW_LABELS[value]) }))}
                value={calendar.view}
                onChange={calendar.setView}
                className="admin-calendar-view max-desktop:flex max-desktop:w-full max-desktop:*:flex-1"
            />
            <div className="admin-calendar-moves flex items-center gap-2">
                <Button className="admin-calendar-today" onClick={calendar.goToday}>
                    {words.text('toolbar.today')}
                </Button>
                <Button iconOnly icon={<Chevron to="previous" />} className="admin-calendar-previous" onClick={calendar.previous} aria-label={words.text('toolbar.previous')}>
                    {words.text('toolbar.previous')}
                </Button>
                <Button iconOnly icon={<Chevron to="next" />} className="admin-calendar-next" onClick={calendar.next} aria-label={words.text('toolbar.next')}>
                    {words.text('toolbar.next')}
                </Button>
            </div>
            {filters === undefined ? (
                heading('basis-80 max-desktop:order-last max-desktop:basis-full max-desktop:text-xl')
            ) : (
                <div className="admin-calendar-toolbar__line flex min-w-0 basis-full flex-wrap items-center gap-3 max-desktop:order-last">
                    {heading('basis-0 min-w-min max-desktop:text-xl')}
                    {filters}
                </div>
            )}
            {add}
        </div>
    );
}

type FiltersProps = {
    filters: CalendarFilters;
    options: CalendarOptions;
    /** Whether Who shows: the staff choice is real, or the viewer is pinned to their own row. */
    staffShown: boolean;
    /** The viewer's own staff row, which Me stands for, or null where none is linked. */
    own: Member | null;
    /** A viewer holding viewOwnCalendar alone: Who reads Me and cannot change. */
    pinned: boolean;
    onChange: (filters: CalendarFilters) => void;
    /** Colour by, the settings row's word, its write and whether the viewer may make it. */
    colourBy: ColourByControl;
    /** The members the window shows, whom a preset's boxes read as ticked. */
    shown: readonly number[];
    /** The members who picked their own colour on the Staff screen. */
    colours: OwnColours;
    /** The card's id, which the toggle folding it away names. */
    id?: string;
    /** Folded away behind that toggle. */
    hidden?: boolean;
};

/** Colour by as the filter row shows it. */
type ColourByControl = { value: ColourBy; allowed: boolean; busy: boolean; onChange: (value: ColourBy) => void };

/** The line describing Colour by for a viewer who may not change it. */
export const COLOUR_BY_LOCKED: CalendarKey = 'filters.colour_by_locked';

/** The line describing Colour by while the choice is being saved. */
export const COLOUR_BY_SAVING: CalendarKey = 'filters.colour_by_saving';

/** The line describing Colour by otherwise. */
export const COLOUR_BY_NOTE: CalendarKey = 'filters.colour_by_note';

function isColourBy(value: string): value is ColourBy {
    return COLOUR_BY_CHOICES.some((choice) => choice.value === value);
}

function idOf(value: string): number | null {
    return value === '' ? null : Number(value);
}

/** The line closing Who's popover, 3.1. */
export const WHO_NOTE: CalendarKey = 'who.note';

/** The heading over the members a viewer may pick one by one. */
export const WHO_PICK: CalendarKey = 'who.pick';

type Preset = { who: Exclude<Who, 'picked'>; label: CalendarKey };

/** The keys that move the focus through Who's popover, and where each sends it. */
const STEPS: Record<string, (at: number, count: number) => number> = {
    ArrowDown: (at, count) => (at + 1) % count,
    ArrowUp: (at, count) => (at <= 0 ? count - 1 : at - 1),
    Home: () => 0,
    End: (_, count) => count - 1,
};

/**
 * Down and Up move the focus through the popover's presets and boxes in order, wrapping at the
 * ends, Home and End to the first and the last; Tab keeps its own order beside them.
 */
function stepFocus(event: KeyboardEvent<HTMLElement>): void {
    const step = STEPS[event.key];
    const stops = [...event.currentTarget.querySelectorAll<HTMLElement>('button:not(:disabled), input:not(:disabled)')];

    if (step === undefined || stops.length === 0) {
        return;
    }

    event.preventDefault();
    stops[step(stops.findIndex((stop) => stop === document.activeElement), stops.length)]?.focus();
}

/** A rule between the popover's parts, as the menu draws its own. */
function Rule() {
    return <div className="my-1.5 border-t" aria-hidden="true" />;
}

/**
 * A Who row over the menu's row: its tick or box first and the words after it, filled while the
 * keyboard is on it, as a pick list's active row is, and never under the pointer: the arrows move
 * the focus and the pointer does not, so a fill for both would show two rows filled at once.
 */
const WHO_ROW = 'justify-start focus-visible:bg-accent focus-visible:outline-none has-focus-visible:bg-accent';

/**
 * Who: a trigger drawn as the field the selects beside it are, its face, ring, height and
 * chevron, summarising the chosen set, that opens the kit's popover, its rows the menu's: the
 * presets as buttons, the chosen one pressed, Scheduled staff, All staff and Me where the viewer
 * has an own row, the chosen one ticked; then "Or pick who to show" over a checkbox row per active
 * member, their initials ringed in their hue, the boxes ticked for the members drawn, the picked
 * set or under a preset those the window shows, so a box changed under a preset switches Who to
 * that set with the one change; then the line on what Scheduled staff and a staff member's own
 * sign-in show, which a pinned viewer, whose trigger is disabled, reads under the field instead.
 * The arrow keys move through the presets and the boxes (stepFocus). A preset pressed answers and
 * closes; a box ticked or cleared changes the set ticked and stays open, the last box cleared
 * reading Scheduled staff. Pinned, the trigger is disabled on Me.
 */
function WhoField({ filters, options, own, pinned, shown, colours, onChange }: Omit<FiltersProps, 'staffShown' | 'colourBy'>) {
    const words = useCalendarWords();
    const [open, setOpen] = useState(false);
    const presets: Preset[] = [{ who: 'scheduled', label: 'who.scheduled' }, { who: 'all', label: 'who.all' }, ...(own === null ? [] : [{ who: 'me' as const, label: 'who.me' as const }])];
    const who: Who = pinned ? 'me' : filters.who;
    const picked = who === 'picked' ? filters.staff : [];
    // The boxes mirror who is drawn: the picked set, or under a preset the members it shows.
    const ticked = who === 'picked' ? picked : shown;

    const choose = (next: Preset['who']) => {
        setOpen(false);
        onChange({ ...filters, who: next, staff: [] });
    };

    const toggle = (id: number, checked: boolean) => {
        const staff = idsOf(checked ? [...ticked, id] : ticked.filter((each) => each !== id));
        onChange(staff.length === 0 ? { ...filters, who: 'scheduled', staff: [] } : { ...filters, who: 'picked', staff });
    };

    return (
        <Field id="calendar-who" label={words.text('who.label')} help={pinned ? words.text(WHO_NOTE) : undefined} compact={true}>
            {(control) => (
                <Popover open={open && !pinned} onOpenChange={setOpen}>
                    <PopoverTrigger asChild>
                        <button type="button" {...control} disabled={pinned} aria-haspopup="dialog" className={cn('admin-calendar-who flex cursor-pointer items-center justify-between gap-2', FIELD, PRESSED_HOVER, FIELD_HEIGHT)}>
                            <span className="truncate">{whoSummary(who, picked, options.staff, words)}</span>
                            <span className="text-muted-foreground">
                                <SelectChevron />
                            </span>
                        </button>
                    </PopoverTrigger>
                    <PopoverContent className="admin-calendar-who__popover flex w-65 flex-col" aria-label={words.text('who.label')} onKeyDown={stepFocus}>
                        <div role="group" aria-label={words.text('who.label')} className="flex flex-col gap-0.5">
                            {presets.map((preset) => (
                                <button
                                    key={preset.who}
                                    type="button"
                                    aria-pressed={who === preset.who}
                                    data-who={preset.who}
                                    className={cn(MENU_OPTION, WHO_ROW, 'admin-calendar-who__preset aria-pressed:font-semibold')}
                                    onClick={() => choose(preset.who)}
                                >
                                    <span className="admin-calendar-who__tick flex w-4 shrink-0">{who === preset.who && <Tick />}</span>
                                    {words.text(preset.label)}
                                </button>
                            ))}
                        </div>
                        {options.staff.length > 0 && (
                            <>
                                <Rule />
                                <fieldset className="admin-calendar-who__pick flex flex-col gap-0.5">
                                    <legend className="admin-calendar-who__heading px-3.5 pt-2 pb-1.5 text-label text-muted-foreground">{words.text(WHO_PICK)}</legend>
                                    {options.staff.map((member: Member) => (
                                        <label key={member.id} className={cn(MENU_OPTION, WHO_ROW, 'admin-calendar-who__member')} data-staff-id={member.id}>
                                            <input type="checkbox" className={CHECK_BOX} checked={ticked.includes(member.id)} onChange={(event) => toggle(member.id, event.target.checked)} />
                                            <Initials name={member.name} hue={hueOf(member.id, colours)} size="list" />
                                            <span className="admin-calendar-who__name truncate">{member.name}</span>
                                        </label>
                                    ))}
                                </fieldset>
                            </>
                        )}
                        <Rule />
                        <p className="admin-calendar-who__note px-3.5 py-1.5 text-small text-muted-foreground">{words.text(WHO_NOTE)}</p>
                    </PopoverContent>
                </Popover>
            )}
        </Field>
    );
}

/**
 * The controls card's second row, the filters, a grid of equal columns by the screen's own width:
 * four from the width at which a quarter of the row holds the longest value of any of them whole,
 * Who's in Greek, with two pixels to spare, two from the admin's breakpoint and one to a row below
 * it, each a full-height field with its label small and no line under any but where a control is
 * held: Who, only while the choice is real or the viewer is pinned, the note under it for the
 * pinned viewer alone; Status, Any status then the nine; Service, All services then the full
 * catalog, inactive rows suffixed; Colour by, the settings row's word, disabled without
 * manageSettings, with the line saying so, and while its write is in flight, with the line saying
 * that, its note on whom the colours reach its hover title. Below the breakpoint the screen may
 * fold the row away behind a toggle naming it by its id.
 */
export function Filters({ filters, options, staffShown, own, pinned, shown, colours, onChange, colourBy, id, hidden }: FiltersProps) {
    const shell = useShell();
    const words = useCalendarWords();
    const set = (patch: Partial<CalendarFilters>) => onChange({ ...filters, ...patch });
    // The line under Colour by only while it says why the select is held; the note on whom the
    // colours reach is its hover title.
    const colourLine = colourBy.busy ? words.text(COLOUR_BY_SAVING) : colourBy.allowed ? undefined : words.text(COLOUR_BY_LOCKED);

    return (
        <div role="group" id={id} hidden={hidden} className="admin-calendar-filters grid grid-cols-1 gap-3 desktop:grid-cols-2 @[67rem]:grid-cols-4" aria-label={words.text('filters.label')}>
            {staffShown && <WhoField filters={filters} options={options} own={own} pinned={pinned} shown={shown} colours={colours} onChange={onChange} />}
            <Field id="calendar-status" label={words.text('filters.status')} compact={true}>
                {(control) => (
                    <Select
                        {...control}
                        className="admin-calendar-status"
                        options={[{ value: '', label: words.text('filters.any_status') }, ...options.statuses.map((status) => ({ value: status, label: statusLabel(status, shell) }))]}
                        value={filters.status}
                        onChange={(status) => set({ status })}
                    />
                )}
            </Field>
            <Field id="calendar-service" label={words.text('filters.service')} compact={true}>
                {(control) => (
                    <Select
                        {...control}
                        className="admin-calendar-service"
                        options={[
                            { value: '', label: words.text('filters.all_services') },
                            ...options.services.map((service: Choice) => ({ value: String(service.id), label: service.active ? service.name : words.text('filters.inactive_service', { service: service.name }) })),
                        ]}
                        value={filters.service === null ? '' : String(filters.service)}
                        onChange={(value) => set({ service: idOf(value) })}
                    />
                )}
            </Field>
            <Field id="calendar-colour-by" label={words.text('filters.colour_by')} help={colourLine} compact={true}>
                {(control) => (
                    <Select
                        {...control}
                        className="admin-calendar-colour-by"
                        title={words.text(colourBy.allowed ? COLOUR_BY_NOTE : COLOUR_BY_LOCKED)}
                        options={COLOUR_BY_CHOICES.map((choice) => ({ value: choice.value, label: words.text(choice.label) }))}
                        value={colourBy.value}
                        disabled={!colourBy.allowed || colourBy.busy}
                        aria-busy={colourBy.busy ? true : undefined}
                        onChange={(value) => {
                            if (isColourBy(value) && value !== colourBy.value) {
                                colourBy.onChange(value);
                            }
                        }}
                    />
                )}
            </Field>
        </div>
    );
}

/** The swatches' list names, one per choice of Colour by. */
const LEGEND_NAMES: Record<ColourBy, CalendarKey> = { staff: 'legend.staff', category: 'legend.category', status: 'legend.status' };

/** Day's two further entries, 5.5, each beside what it marks. */
export const LEGEND_CLOSURE: CalendarKey = 'legend.closure';
export const LEGEND_OUTSIDE: CalendarKey = 'legend.outside';

/** A swatch's shape, every entry's alike. */
const SWATCH = 'admin-calendar-legend__swatch inline-block size-3.5 shrink-0 rounded-[0.3125rem]';

/** The marks blocks wear: the two badges, the closure's mark and the gap's hatch. */
function BlockMarks() {
    const shell = useShell();
    const words = useCalendarWords();

    return (
        <>
            {BADGE_ORDER.map((badge) => (
                <li key={badge} className="admin-calendar-legend__badge flex items-center" data-badge={badge}>
                    <BlockBadge badge={badge} />
                    {badgeMeaning(badge, shell, words)}
                </li>
            ))}
            <li className="admin-calendar-legend__inside flex items-center">
                <InsideMark />
                {words.text('badges.closure')}
            </li>
            <li className="admin-calendar-legend__gap flex items-center gap-1.5">
                <span className={cn(SWATCH, 'admin-calendar-gap-swatch')} aria-hidden="true" />
                {words.text('legend.gap')}
            </li>
        </>
    );
}

/** The marks chips wear, and the hatch of a day the business is closed where the hatch means nothing else. */
function ChipMarks({ closed }: { closed: boolean }) {
    const shell = useShell();
    const words = useCalendarWords();

    return (
        <>
            {BADGE_ORDER.map((badge) => (
                <li key={badge} className="admin-calendar-legend__badge flex items-center gap-1.5" data-badge={badge}>
                    <span className={cn(SWATCH, 'bg-card', CHIP_OUTLINES[badge])} aria-hidden="true" />
                    {badgeMeaning(badge, shell, words)}
                </li>
            ))}
            <li className="admin-calendar-legend__noted flex items-center gap-1.5">
                <NoteSheet />
                {words.text(NOTED)}
            </li>
            <li className="admin-calendar-legend__inside flex items-center">
                <InsideMark />
                {words.text('badges.closure')}
            </li>
            {closed && (
                <li className="admin-calendar-legend__closed flex items-center gap-1.5">
                    <span className={cn(SWATCH, 'admin-calendar-hatch')} aria-hidden="true" />
                    {words.text(CLOSED_DAY)}
                </li>
            )}
        </>
    );
}

type LegendProps = { by: ColourBy; swatches: readonly Swatch[]; chips?: boolean; day?: boolean; hint?: boolean };

/**
 * The legend, as the grid card's foot: one swatch per member shown, per category or per status as
 * Colour by says, from the same hue functions the blocks and the chips use; then the marks. Where
 * the grid draws blocks, the two block badges each beside what it marks, the mark of a booking
 * inside a closure as a narrow block draws it, beside its words, and the gap's hatch; where it
 * draws chips, the outline each badge's chip wears, the note's mark, the closure's mark and the
 * hatch of a day the business is closed. In Day's member columns and Week's grid of people then
 * Closure and Outside the member's hours, each drawn as the columns and the rows draw it, the
 * hatch named once; and, where an empty slot starts a booking, the line saying so at the end. The
 * screen decides whether it is drawn.
 */
export function Legend({ by, swatches, chips = false, day = false, hint = false }: LegendProps) {
    const words = useCalendarWords();

    return (
        <div className="admin-calendar-legend flex flex-wrap items-center gap-x-4 gap-y-2 border-t px-5.5 pt-3.5 pb-4 text-xs" data-colour-by={by}>
            <ul className="contents" aria-label={words.text(LEGEND_NAMES[by])}>
                {swatches.map((swatch) => (
                    <li key={swatch.key} className="admin-calendar-legend__member flex items-center gap-1.5" data-swatch={swatch.key} data-staff-id={swatch.staffId}>
                        <span className={SWATCH} style={{ backgroundColor: swatch.hue }} aria-hidden="true" />
                        {swatch.label}
                    </li>
                ))}
            </ul>
            <ul className="contents" aria-label={words.text('legend.badges')}>
                {chips ? <ChipMarks closed={!day} /> : <BlockMarks />}
            </ul>
            {day && (
                <ul className="contents" aria-label={words.text('legend.closures')}>
                    <li className="admin-calendar-legend__closure flex items-center gap-1.5">
                        <span className={cn(SWATCH, 'admin-calendar-closure-swatch')} aria-hidden="true" />
                        {words.text(LEGEND_CLOSURE)}
                    </li>
                    <li className="admin-calendar-legend__outside flex items-center gap-1.5">
                        <span className={cn(SWATCH, 'admin-calendar-hatch')} aria-hidden="true" />
                        {words.text(LEGEND_OUTSIDE)}
                    </li>
                </ul>
            )}
            {hint && <p className="admin-calendar-legend__hint text-muted-foreground desktop:ml-auto">{words.text('legend.hint')}</p>}
        </div>
    );
}
