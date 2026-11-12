import { type KeyboardEvent, type ReactNode, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Notice } from '@/components/ui/notice';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Segmented } from '@/components/ui/segmented';
import { Select } from '@/components/ui/select';
import { statusLabel } from '@/components/ui/status-chip';
import type { Choice } from '@/bookings/model';
import { useShell } from '@/lib/strings';
import { cn } from '@/lib/utils';
import { useDesktop } from '@/lib/viewport';
import { BADGE_ORDER, BlockBadge, badgeMeaning } from './Badge';
import { Caret, Chevron, Tick } from '@/components/glyphs';
import { Initials } from '@/components/initials';
import { type CalendarFilters, type CalendarOptions, COLOUR_BY_CHOICES, type ColourBy, idsOf, type Member, type Swatch, type View, type Who, whoSummary } from './model';
import { hueOf, type OwnColours } from '@/lib/hue';
import type { Calendar } from './useCalendar';
import { type CalendarKey, useCalendarWords } from './words';

/*
 * The Calendar's chrome around the grid: the toolbar and the filters, the two rows of the
 * controls card, the legend at the grid card's foot, and Week's notice below the admin's
 * breakpoint. Each is a plain component over the screen's state; the rules live in the model.
 * Every word is a key of the calendar section, a constant that held one holding its key.
 */

type ToolbarProps = {
    /** The views the screen offers, in order. */
    views: readonly View[];
    /** The screen's view and its moves, useCalendar's answer, handed over as one value. */
    calendar: Pick<Calendar, 'view' | 'setView' | 'goToday' | 'previous' | 'next'>;
    title: string;
    titleId: string;
};

/** Each view's word on the segmented control. */
const VIEW_LABELS: Record<View, CalendarKey> = { week: 'toolbar.views.week', day: 'toolbar.views.day', month: 'toolbar.views.month' };

/**
 * The controls card's first row: a segmented control of the views offered, Today, Previous and
 * Next as chevrons, then the date title that names the region. Today, Previous and Next are one
 * group, so a narrow toolbar wraps them together.
 */
export function Toolbar({ views, calendar, title, titleId }: ToolbarProps) {
    const words = useCalendarWords();

    return (
        <div className="admin-calendar-toolbar flex flex-wrap items-center gap-3">
            <Segmented<View> label={words.text('toolbar.view')} options={views.map((value) => ({ value, label: words.text(VIEW_LABELS[value]) }))} value={calendar.view} onChange={calendar.setView} className="admin-calendar-view" />
            <div className="admin-calendar-moves flex items-center gap-2">
                <Button className="admin-calendar-today" onClick={calendar.goToday}>
                    {words.text('toolbar.today')}
                </Button>
                <Button className="admin-calendar-previous w-8 px-0" onClick={calendar.previous} aria-label={words.text('toolbar.previous')}>
                    <Chevron to="previous" />
                </Button>
                <Button className="admin-calendar-next w-8 px-0" onClick={calendar.next} aria-label={words.text('toolbar.next')}>
                    <Chevron to="next" />
                </Button>
            </div>
            <h2 id={titleId} className="admin-calendar-title text-base font-semibold">
                {title}
            </h2>
        </div>
    );
}

type FiltersProps = {
    filters: CalendarFilters;
    options: CalendarOptions;
    /** Whether Who shows: the staff choice is real (6.1), or the viewer is pinned to their own row. */
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

/** A rule between the popover's parts. */
function Rule() {
    return <div className="my-1 border-t" aria-hidden="true" />;
}

/**
 * Who: a trigger summarising the chosen set, with a caret, that opens the kit's popover: the
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
        <Field id="calendar-who" label={words.text('who.label')} help={pinned ? words.text(WHO_NOTE) : undefined} compact={true} className="desktop:w-55">
            {(control) => (
                <Popover open={open && !pinned} onOpenChange={setOpen}>
                    <PopoverTrigger asChild>
                        <Button {...control} disabled={pinned} aria-haspopup="dialog" className="admin-calendar-who w-full justify-between font-normal">
                            <span className="truncate">{whoSummary(who, picked, options.staff, words)}</span>
                            <Caret />
                        </Button>
                    </PopoverTrigger>
                    <PopoverContent className="admin-calendar-who__popover flex w-65 flex-col p-1.5 text-sm" aria-label={words.text('who.label')} onKeyDown={stepFocus}>
                        <div role="group" aria-label={words.text('who.label')} className="flex flex-col gap-0.5">
                            {presets.map((preset) => (
                                <button
                                    key={preset.who}
                                    type="button"
                                    aria-pressed={who === preset.who}
                                    data-who={preset.who}
                                    className="admin-calendar-who__preset flex items-center gap-2 rounded-md px-2 py-1.5 text-left hover:bg-muted aria-pressed:font-semibold"
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
                                    <legend className="admin-calendar-who__heading px-2 py-1.5 text-xs text-muted-foreground">{words.text(WHO_PICK)}</legend>
                                    {options.staff.map((member: Member) => (
                                        <label key={member.id} className="admin-calendar-who__member flex cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 hover:bg-muted" data-staff-id={member.id}>
                                            <input type="checkbox" className="size-4 accent-primary" checked={ticked.includes(member.id)} onChange={(event) => toggle(member.id, event.target.checked)} />
                                            <Initials name={member.name} hue={hueOf(member.id, colours)} size="list" />
                                            <span className="admin-calendar-who__name truncate">{member.name}</span>
                                        </label>
                                    ))}
                                </fieldset>
                            </>
                        )}
                        <Rule />
                        <p className="admin-calendar-who__note px-2 py-1.5 text-xs text-muted-foreground">{words.text(WHO_NOTE)}</p>
                    </PopoverContent>
                </Popover>
            )}
        </Field>
    );
}

/**
 * The controls card's second row, the filters, side by side at or above the admin's breakpoint and
 * one to a row below it (1.6), each label small and no line under any but where a control is held:
 * Who, only while the choice is real or the viewer is pinned, the note under it for the pinned
 * viewer alone; Status, Any status then the nine; Service, All services then the full catalog,
 * inactive rows suffixed; Colour by, the settings row's word, disabled without manageSettings, with
 * the line saying so, and while its write is in flight, with the line saying that, its note on whom
 * the colours reach its hover title.
 */
export function Filters({ filters, options, staffShown, own, pinned, shown, colours, onChange, colourBy }: FiltersProps) {
    const shell = useShell();
    const words = useCalendarWords();
    const set = (patch: Partial<CalendarFilters>) => onChange({ ...filters, ...patch });
    // The line under Colour by only while it says why the select is held; the note on whom the
    // colours reach is its hover title.
    const colourLine = colourBy.busy ? words.text(COLOUR_BY_SAVING) : colourBy.allowed ? undefined : words.text(COLOUR_BY_LOCKED);

    return (
        <div role="group" className="admin-calendar-filters flex flex-col gap-3 desktop:flex-row desktop:flex-wrap desktop:items-end" aria-label={words.text('filters.label')}>
            {staffShown && <WhoField filters={filters} options={options} own={own} pinned={pinned} shown={shown} colours={colours} onChange={onChange} />}
            <Field id="calendar-status" label={words.text('filters.status')} compact={true} className="desktop:w-45">
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
            <Field id="calendar-service" label={words.text('filters.service')} compact={true} className="desktop:w-50">
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
            <Field id="calendar-colour-by" label={words.text('filters.colour_by')} help={colourLine} compact={true} className="desktop:w-45">
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
const SWATCH = 'admin-calendar-legend__swatch inline-block size-3 shrink-0 rounded-[3px]';

/**
 * The legend, as the grid card's foot: one swatch per member shown, per category or per status as
 * Colour by says, from the same hue functions the blocks use, then the two block badges each
 * beside what it marks and the gap's hatch; in Day's member columns then Closure and Outside the
 * member's hours, each drawn as the columns draw it; and, where an empty slot starts a booking,
 * the line saying so at the end. The screen decides whether it is drawn.
 */
export function Legend({ by, swatches, day = false, hint = false }: { by: ColourBy; swatches: readonly Swatch[]; day?: boolean; hint?: boolean }) {
    const shell = useShell();
    const words = useCalendarWords();

    return (
        <div className="admin-calendar-legend flex flex-wrap items-center gap-x-4 gap-y-2 border-t px-4 py-3 text-xs" data-colour-by={by}>
            <ul className="contents" aria-label={words.text(LEGEND_NAMES[by])}>
                {swatches.map((swatch) => (
                    <li key={swatch.key} className="admin-calendar-legend__member flex items-center gap-1.5" data-swatch={swatch.key} data-staff-id={swatch.staffId}>
                        <span className={SWATCH} style={{ backgroundColor: swatch.hue }} aria-hidden="true" />
                        {swatch.label}
                    </li>
                ))}
            </ul>
            <ul className="contents" aria-label={words.text('legend.badges')}>
                {BADGE_ORDER.map((badge) => (
                    <li key={badge} className="admin-calendar-legend__badge flex items-center" data-badge={badge}>
                        <BlockBadge badge={badge} />
                        {badgeMeaning(badge, shell, words)}
                    </li>
                ))}
                <li className="admin-calendar-legend__gap flex items-center gap-1.5">
                    <span className={cn(SWATCH, 'admin-calendar-gap-swatch')} aria-hidden="true" />
                    {words.text('legend.gap')}
                </li>
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

/** The notice for Week below the admin's breakpoint. */
export const WEEK_NEEDS_WIDTH: CalendarKey = 'grid.week_needs_width';

/** The control under it, opening Day view on the date the screen holds. */
export const TO_DAY: CalendarKey = 'grid.to_day';

/**
 * Week is a desktop view: what this wraps, the grid and the legend under it, renders in every view
 * at or above the admin's breakpoint and in Day and Month below it, while Week below it renders
 * the notice in their place, over a control opening Day view; the segmented control still offers
 * Week. The Calendar wraps its grid in it, so the page carries no branch itself.
 */
export function DesktopWeek({ view, onDay, children }: { view: View; onDay: () => void; children: ReactNode }) {
    const desktop = useDesktop();
    const words = useCalendarWords();

    if (view !== 'week' || desktop) {
        return children;
    }

    return (
        <div className="admin-calendar-narrow flex flex-col items-start gap-3 p-4">
            <Notice kind="info" className="self-stretch">
                {words.text(WEEK_NEEDS_WIDTH)}
            </Notice>
            <Button className="admin-calendar-narrow__day" onClick={onDay}>
                {words.text(TO_DAY)}
            </Button>
        </div>
    );
}

