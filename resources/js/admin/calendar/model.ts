import { type Booking, type BookingItem, type Choice, staffShown } from '@/bookings/model';
import { type ChipShape, chipShapeOf } from '@/components/ui/status-chip';
import { dayKey, isoWeekday } from '@/lib/day';
import { hueOf, NEUTRAL, type OwnColours, paletteHue } from '@/lib/hue';
import { formatDate, formatTimeOfDay, type TenantClock } from '@/lib/format';
import { monthTitle, weekdayName } from '@/lib/names';
import type { Shape } from '@/lib/session-memory';
import type { ShiftSource } from '@/shifts/model';
import type { ShiftWords } from '@/shifts/words';
import type { CalendarKey, CalendarWords } from './words';
import { addDays, parseDay, parseInstant, wallClockOf } from '../../booking/time';

/*
 * The calendar's data as the server sends it, CalendarController, and the calendar's rules as
 * pure functions of it: the view and the date and their moves, the fetch window as the visible
 * range with an exclusive end, the client cuts, the blocks and gaps one window answer draws in
 * Week and Day and the counts per day and status it draws in Month, the hue as a stable
 * function of the staff id, and the initial scroll. Nothing here touches the grid library; the
 * grid renders what these say. A word is a key of the calendar section, read through the words
 * a function is handed, and a closure's the shifts section's.
 */

/** An item as the calendar receives it: the guest's shape, and the occupied range where the whitelist shows it to this viewer. */
export type CalendarItem = BookingItem & {
    /** The service's category, null where it has none; absent reads as none. */
    category?: string | null;
    block_start_utc?: string | null;
    block_end_utc?: string | null;
    processing_ends_utc?: string | null;
};

export type CalendarBooking = Omit<Booking, 'items'> & { items: CalendarItem[] };

export type Member = { id: number; name: string };

/** Whom the Calendar shows: the three presets and a picked set. */
export type Who = 'scheduled' | 'all' | 'me' | 'picked';

/** A window or a closure as the wire spells it: two wall-clock readings HH:MM, a whole-day closure's both null, never converted. */
export type HoursWindow = { start: string; end: string };

export type Closure = { start: string | null; end: string | null; reason: string | null; business: boolean };

/**
 * One member's day: the windows in force, the closures that reach them, and what the Shifts day
 * editor reads: the day's source and the windows the pattern gives that date, the override left
 * out, the server's resolver read.
 */
export type DayHours = { windows: HoursWindow[]; closures: Closure[]; source: ShiftSource; pattern: HoursWindow[] };

/** A member's next working day after the window, its date and windows, or null where none falls in the eight weeks scanned. */
export type NextWorking = { date: string; windows: HoursWindow[] } | null;

/**
 * The window as fetched: the range and Who read back as asked (staff the picked ids, empty for
 * every other mode), the members Who resolved to on the server, the implicit row's id where its
 * bookings are drawn though it is never listed (Scheduled staff and All staff), each member's
 * hours per date, the next working day of each member with no hours in the window, and every
 * booking in it. hours is keyed by the member's id and then the date, next_working by the
 * member's id; an empty answer may arrive as a list, so each is read by key alone.
 */
export type Window = {
    from: string;
    to: string;
    who: Who;
    staff: number[];
    members: Member[];
    implicit: number | null;
    hours: Record<string, Record<string, DayHours>> | [];
    next_working: Record<string, NextWorking> | [];
    bookings: CalendarBooking[];
};

/** categories: the catalogue's distinct categories, sorted, a category's hue its place among them. */
export type CalendarOptions = { statuses: string[]; staff: Member[]; services: Choice[]; categories: string[]; members: number };

export type View = 'week' | 'day' | 'month';

/** The Calendar's three views, in the order the toolbar offers them. */
export const CALENDAR_VIEWS: readonly View[] = ['day', 'week', 'month'];

/**
 * The view and the date a link opens the Calendar on, `?view=` and `?date=` as the server read
 * them through its tolerant reader, each null where the link named none it accepts. Read once,
 * on the first render, and remembered nowhere.
 */
export type Opening = { view: View | null; date: string | null };

/** The filter card: status and service cut on the client, Who sent, the staff list being the picked ids. */
export type CalendarFilters = { status: string; who: Who; staff: number[]; service: number | null };

/** A new browser starts on Scheduled staff. */
export const NO_CALENDAR_FILTERS: CalendarFilters = { status: '', who: 'scheduled', staff: [], service: null };

export const CALENDAR_MEMORY_KEY = 'kr.admin.calendar.filters';

/** The nine statuses in the order the server lists them for the status filter, which the month's chips follow. */
const STATUSES = ['pending', 'awaiting_approval', 'awaiting_payment', 'confirmed', 'completed', 'no_show', 'cancelled', 'rejected', 'expired'];

const isPositive = (value: unknown): value is number => typeof value === 'number' && Number.isInteger(value) && value > 0;

const isId = (value: unknown): value is number | null => value === null || isPositive(value);

const WHO: readonly Who[] = ['scheduled', 'all', 'me', 'picked'];

/** The filter card's shape check: the view and the date are not fields, so neither is ever read back. */
export const CALENDAR_FILTER_SHAPE: Shape<CalendarFilters> = {
    status: (value): value is string => value === '' || (typeof value === 'string' && STATUSES.includes(value)),
    who: (value): value is Who => WHO.some((who) => who === value),
    staff: (value): value is number[] => Array.isArray(value) && value.every(isPositive),
    service: isId,
};

/** A list of ids as the wire and the memory keep it: sorted, without repeats. */
export function idsOf(ids: readonly number[]): number[] {
    return [...new Set(ids)].sort((a, b) => a - b);
}

/**
 * A remembered card made applicable: a Who behind a hidden control is dropped, Me where the
 * viewer has no own row reads Scheduled staff, and a picked set keeps the active members it
 * still names, an empty one reading Scheduled staff. Whether the choice is real is the one rule
 * the Bookings bar spells, and the palette and the legend follow the same answer.
 */
export function applicableFilters(remembered: CalendarFilters, entitlements: readonly string[], members: number, own: Member | null, active: readonly Member[]): CalendarFilters {
    if (!staffShown(entitlements, members, null)) {
        return { ...remembered, who: 'scheduled', staff: [] };
    }

    if (remembered.who === 'me' && own === null) {
        return { ...remembered, who: 'scheduled', staff: [] };
    }

    if (remembered.who !== 'picked') {
        return { ...remembered, staff: [] };
    }

    const kept = idsOf(remembered.staff.filter((id) => active.some((member) => member.id === id)));

    return kept.length === 0 ? { ...remembered, who: 'scheduled', staff: [] } : { ...remembered, staff: kept };
}

/**
 * The trigger's summary of the chosen set, 3.1: the preset's name, a picked member's name, or the
 * first picked member's name and how many more, in roster order. Each sentence is whole.
 */
export function whoSummary(who: Who, staff: readonly number[], active: readonly Member[], words: CalendarWords): string {
    if (who === 'all') {
        return words.text('who.all');
    }

    if (who === 'me') {
        return words.text('who.me');
    }

    const picked = who === 'picked' ? active.filter((member) => staff.includes(member.id)) : [];
    const first = picked[0];

    if (first === undefined) {
        return words.text('who.scheduled');
    }

    const others = picked.length - 1;

    return others === 0 ? first.name : words.choice('who.picked', others, { staff: first.name });
}

/** The rows whose items a window answer draws, 5.2: every row under All staff, else the members shown and the implicit row where the answer names it. */
export function shownRows(window: Pick<Window, 'who' | 'members' | 'implicit'>): number[] | null {
    return window.who === 'all' ? null : [...window.members.map((member) => member.id), ...(window.implicit === null ? [] : [window.implicit])];
}

/** The member a slot hands the wizard, 3.1: the one member shown where exactly one is, else none. */
export function slotStaffOf(members: readonly Member[]): number | null {
    return members.length === 1 ? (members[0]?.id ?? null) : null;
}


/** The first day of the week holding a date, from the tenant's week start, ISO numbered. */
function weekStartOf(date: string, weekStart: number): string {
    return addDays(date, -((isoWeekday(date) - weekStart + 7) % 7));
}

/** The days the month grid shows: six weeks always, so the grid and the read never disagree on its last row. */
const MONTH_GRID_DAYS = 42;

/** The first day of the month holding a date. */
function monthStartOf(date: string): string {
    const { year, month } = parseDay(date);

    return dayKey(year, month, 1);
}

/** The fetch window for a view and a date: the visible range, its end exclusive, 3.1. Month is the six weeks from the week holding the month's first day. */
export function rangeOf(view: View, date: string, weekStart: number): { from: string; to: string } {
    if (view === 'month') {
        const from = weekStartOf(monthStartOf(date), weekStart);

        return { from, to: addDays(from, MONTH_GRID_DAYS) };
    }

    const from = view === 'week' ? weekStartOf(date, weekStart) : date;

    return { from, to: addDays(from, view === 'week' ? 7 : 1) };
}

/**
 * A date a number of months on, by calendar arithmetic alone: the same day of the month where
 * the month has it, else its last day, so the thirty-first of January moves to the last of
 * February and never into March.
 */
function addMonths(date: string, months: number): string {
    const { year, month, day } = parseDay(date);
    const first = new Date(Date.UTC(year, month - 1 + months, 1));
    const last = new Date(Date.UTC(first.getUTCFullYear(), first.getUTCMonth() + 1, 0)).getUTCDate();

    return dayKey(first.getUTCFullYear(), first.getUTCMonth() + 1, Math.min(day, last));
}

/** Previous and Next: seven days in Week, one in Day and a month in Month. */
export function move(view: View, date: string, direction: -1 | 1): string {
    return view === 'month' ? addMonths(date, direction) : addDays(date, direction * (view === 'week' ? 7 : 1));
}

/**
 * The toolbar's date title: the week's first and last day in the tenant's date format; the day as
 * its weekday, ICU's long name in the page's language, before its date in the tenant's format,
 * one key holding the order; or the month's name and year, ICU's in the page's language, read off
 * the date shown with no conversion.
 */
export function titleOf(view: View, date: string, weekStart: number, clock: TenantClock, words: CalendarWords): string {
    if (view === 'month') {
        const { year, month } = parseDay(date);

        return monthTitle(year, month, words.language);
    }

    if (view === 'day') {
        return dayTitleOf(date, clock, words);
    }

    const { from, to } = rangeOf(view, date, weekStart);

    return `${formatDate(from, clock)} - ${formatDate(addDays(to, -1), clock)}`;
}

/** A day as Day's title and a column menu's title name it: the weekday by ICU, then the date in the tenant's format. */
export function dayTitleOf(date: string, clock: TenantClock, words: CalendarWords): string {
    return words.text('toolbar.day_title', { weekday: weekdayName(isoWeekday(date), 'long', words.language), date: formatDate(date, clock) });
}

/**
 * The client cuts: status and service narrow the fetched window with no request, a booking
 * surviving the service cut when any of its segments matches, so half a chain is never drawn.
 */
export function cut(bookings: readonly CalendarBooking[], filters: CalendarFilters): CalendarBooking[] {
    return bookings.filter(
        (booking) => (filters.status === '' || booking.status === filters.status) && (filters.service === null || booking.items.some((item) => item.service_id === filters.service)),
    );
}

/** The two statuses a block is badged for, 5.4: a request waiting on its approver, and an approved booking waiting on its payment. */
export type Badge = 'awaiting_approval' | 'awaiting_payment';

const BADGED: readonly Badge[] = ['awaiting_approval', 'awaiting_payment'];

/** The badge a booking's blocks carry, or null for every other status. */
function badgeOf(status: string): Badge | null {
    return BADGED.find((badge) => badge === status) ?? null;
}

/**
 * kind: a booking block per item, or a processing gap. start and end are the tenant's wall
 * clock with no zone, the only form the grid is handed. minutes is the block's length, for
 * the time-range rule. staffId is the item's, the gap inheriting its parent's. badge is the
 * booking's where it is one of the two badged statuses, and a gap's is always null.
 */
export type Block = {
    id: string;
    kind: 'booking' | 'gap';
    uuid: string;
    staffId: number;
    start: string;
    end: string;
    minutes: number;
    title: string;
    badge: Badge | null;
    /** The item's service category, null for none, and the booking's status: what Colour by reads besides the member. */
    category: string | null;
    status: string;
    /** The item's position in its booking's chain from 0, the segment a move by drag addresses beside the uuid. */
    segment: number;
    /** Whether this viewer may move the booking now, the server's BookingActions::movable, false where the payload says nothing. */
    movable: boolean;
};

export const GAP_TITLE: CalendarKey = 'grid.gap';

/** A block's title: the customer and the service, or the erased customer's own line with the service alone. */
function blockTitleOf(booking: Pick<Booking, 'customer_name'>, service: string, words: CalendarWords): string {
    return booking.customer_name === null ? words.text('grid.block_erased', { service }) : words.text('grid.block', { customer: booking.customer_name, service });
}

function pad(value: number): string {
    return String(value).padStart(2, '0');
}

/** A wire instant as the tenant's wall clock, spelled with no zone: what the grid, told it is in UTC, draws at that hour. */
export function wallOf(wire: string, timezone: string): string {
    const at = wallClockOf(wire, timezone);

    return `${at.date}T${pad(at.hour)}:${pad(at.minute)}:00`;
}

function minutesBetween(start: string, end: string): number {
    return Math.round((parseInstant(end) - parseInstant(start)) / 60000);
}

/**
 * The blocks one window answer draws, 5.2, for the members shown: a block per item over its
 * occupied range including buffers where the payload carries it, else over the customer's
 * range; a gap only where the processing end runs strictly past the end, spanning that
 * difference. Every item of a shown member becomes a block, and null, All staff, shows every
 * item. The selection is applied here regardless of whether the fetch was already narrowed, 5.5,
 * so it is a guarantee and not a payload optimisation.
 */
export function blocksOf(bookings: readonly CalendarBooking[], staff: readonly number[] | null, timezone: string, words: CalendarWords): Block[] {
    const blocks: Block[] = [];

    for (const booking of bookings) {
        for (const item of booking.items) {
            if (staff !== null && !staff.includes(item.staff_id)) {
                continue;
            }

            const start = item.block_start_utc ?? item.start;
            const end = item.block_end_utc ?? item.end;

            blocks.push({
                id: `${booking.uuid}:${item.sort}`,
                kind: 'booking',
                uuid: booking.uuid,
                staffId: item.staff_id,
                start: wallOf(start, timezone),
                end: wallOf(end, timezone),
                minutes: minutesBetween(start, end),
                title: blockTitleOf(booking, item.service_name, words),
                badge: badgeOf(booking.status),
                category: item.category ?? null,
                status: booking.status,
                segment: item.sort,
                movable: booking.movable === true,
            });

            const processing = item.processing_ends_utc;

            if (typeof processing === 'string' && parseInstant(processing) > parseInstant(item.end)) {
                blocks.push({
                    id: `${booking.uuid}:${item.sort}:gap`,
                    kind: 'gap',
                    uuid: booking.uuid,
                    staffId: item.staff_id,
                    start: wallOf(item.end, timezone),
                    end: wallOf(processing, timezone),
                    minutes: minutesBetween(item.end, processing),
                    title: words.text(GAP_TITLE),
                    badge: null,
                    category: item.category ?? null,
                    status: booking.status,
                    segment: item.sort,
                    movable: booking.movable === true,
                });
            }
        }
    }

    return blocks;
}

/** One chip of a month day: a status and how many bookings that day hold it. */
export type StatusCount = { status: string; count: number };

/**
 * The counts the Month view draws: per tenant day, a count per status present, the chips in the
 * order the status filter lists the nine and a status the client does not know after them. A
 * booking counts once, on the tenant's wall-clock day its first item starts, however many items it
 * has and wherever they fall. The status and service cuts are the caller's, cut() as for the
 * blocks; the members shown are applied here as blocksOf applies them, a booking counting while any
 * item sits on one of their rows, which is the read's own narrowing, so it is a guarantee and never
 * a second answer. A day with nothing has no entry.
 */
export function countsOf(bookings: readonly CalendarBooking[], staff: readonly number[] | null, timezone: string): Map<string, StatusCount[]> {
    const tally = new Map<string, Map<string, number>>();

    for (const booking of bookings) {
        const first = booking.items[0];

        if (first === undefined || (staff !== null && !booking.items.some((item) => staff.includes(item.staff_id)))) {
            continue;
        }

        const day = wallClockOf(first.start, timezone).date;
        const statuses = tally.get(day) ?? new Map<string, number>();
        statuses.set(booking.status, (statuses.get(booking.status) ?? 0) + 1);
        tally.set(day, statuses);
    }

    // A status the client does not know ranks after the nine.
    const rank = (status: string) => {
        const at = STATUSES.indexOf(status);

        return at === -1 ? STATUSES.length : at;
    };

    return new Map(
        [...tally].map(([day, statuses]) => [
            day,
            [...statuses]
                .map(([status, count]) => ({ status, count }))
                .sort((a, b) => rank(a.status) - rank(b.status)),
        ]),
    );
}

/** A block's fill: the hue mixed toward the surface, never applied as alpha, so hour rules do not bleed through and two blocks never stack into a third colour. */
export function fillOf(hue: string): string {
    return `color-mix(in oklab, ${hue} 18%, var(--card))`;
}

/** The hue a block wears: the member's own while the palette is in play, their picked colour or the spread's, else the neutral grey. */
export function colourOf(staffId: number, palette: boolean, own: OwnColours): string {
    return palette ? hueOf(staffId, own) : NEUTRAL;
}

/** How every grid colours its blocks: the settings row's one word. */
export type ColourBy = 'staff' | 'category' | 'status';

/** The choices of Colour by, in the order the filter card offers them, each label a key of the calendar section. */
export const COLOUR_BY_CHOICES: readonly { value: ColourBy; label: CalendarKey }[] = [
    { value: 'staff', label: 'filters.colour_by_choices.staff' },
    { value: 'category', label: 'filters.colour_by_choices.category' },
    { value: 'status', label: 'filters.colour_by_choices.status' },
];

/** The legend's name for the bucket every service with no category falls in. */
export const NO_CATEGORY: CalendarKey = 'legend.no_category';

/**
 * What the grid colours by: the choice, whether the staff choice is real (the palette in play,
 * 5.3), the catalogue's distinct categories sorted, which a category's hue is read off, and the
 * members who picked their own colour on the Staff screen.
 */
export type Colouring = { by: ColourBy; palette: boolean; categories: readonly string[]; own: OwnColours };

/**
 * A category's hue, 5.3: the palette's spread a member on Automatic is drawn by, over its place in the
 * catalogue's distinct categories sorted, so a category keeps its hue whatever window is read;
 * the blank bucket, and a category the catalogue no longer lists, the neutral grey.
 */
export function categoryHueOf(category: string | null, categories: readonly string[]): string {
    const at = category === null ? -1 : categories.indexOf(category);

    return at === -1 ? NEUTRAL : paletteHue(at);
}

/**
 * The status chips' hues, one per chip family, status-chip.tsx's mapping: the stylesheet's tokens
 * the chips paint with (app.css, --admin-status-*), live emerald, attention amber, provisional
 * sky, void and past two greys, each spelled there alone.
 */
const SHAPE_HUES: Record<ChipShape, string> = {
    live: 'var(--admin-status-live)',
    attention: 'var(--admin-status-attention)',
    provisional: 'var(--admin-status-provisional)',
    void: 'var(--admin-status-void)',
    past: 'var(--admin-status-past)',
};

/** A status's hue, its chip's tone, or the neutral grey for a status the chip does not know. */
export function statusHueOf(status: string): string {
    const shape = chipShapeOf(status);

    return shape === null ? NEUTRAL : SHAPE_HUES[shape];
}

/** The hue a block wears under Colour by: its member's (grey where the staff choice is not real), its category's or its status's. */
export function blockHueOf(block: Pick<Block, 'staffId' | 'category' | 'status'>, colouring: Colouring): string {
    switch (colouring.by) {
        case 'category':
            return categoryHueOf(block.category, colouring.categories);
        case 'status':
            return statusHueOf(block.status);
        default:
            return colourOf(block.staffId, colouring.palette, colouring.own);
    }
}

/** One swatch of the legend: what it stands for, its hue, and the key that tells it from the others. */
export type Swatch = { key: string; label: string; hue: string; staffId?: number };

/**
 * The legend's swatches: under Staff member the members shown, while the palette is in play,
 * else none; under Service category the categories the blocks drawn carry, sorted, the blank
 * bucket last; under Status the statuses the blocks drawn hold, in the order the status filter
 * lists them, each named as its chip names it.
 */
export function swatchesOf(colouring: Colouring, members: readonly Member[], blocks: readonly Block[], statusName: (status: string) => string, words: CalendarWords): Swatch[] {
    if (colouring.by === 'staff') {
        return colouring.palette ? members.map((member) => ({ key: `staff-${member.id}`, label: member.name, hue: hueOf(member.id, colouring.own), staffId: member.id })) : [];
    }

    if (colouring.by === 'category') {
        const named = [...new Set(blocks.flatMap((block) => (block.category === null ? [] : [block.category])))].sort();
        const blank = blocks.some((block) => block.category === null);

        return [
            ...named.map((category) => ({ key: `category-${category}`, label: category, hue: categoryHueOf(category, colouring.categories) })),
            ...(blank ? [{ key: 'category-none', label: words.text(NO_CATEGORY), hue: NEUTRAL }] : []),
        ];
    }

    const held = new Set(blocks.map((block) => block.status));
    const known = STATUSES.filter((status) => held.has(status));
    const unknown = [...held].filter((status) => !STATUSES.includes(status)).sort();

    return [...known, ...unknown].map((status) => ({ key: `status-${status}`, label: statusName(status), hue: statusHueOf(status) }));
}

/** Whether the legend is drawn, 5.5: never in Month; under Staff member only while the palette is in play; under the other two always. */
export function legendShown(colouring: Colouring, view: View): boolean {
    return view !== 'month' && (colouring.by !== 'staff' || colouring.palette);
}

/** The hour an empty day opens at, with no margin subtracted. */
export const MORNING = '08:00:00';

const MARGIN_MINUTES = 60;

/**
 * The initial scroll, 5.1: one hour before the earliest block of the day shown, clamped to that
 * day's own midnight so a block at 00:30 cannot resolve to the previous day, and a fixed morning
 * hour when the day holds nothing.
 */
export function scrollTimeOf(blocks: readonly Block[], dayShown: string): string {
    let earliest: number | null = null;

    for (const block of blocks) {
        if (block.start.slice(0, 10) !== dayShown) {
            continue;
        }

        const minutes = Number(block.start.slice(11, 13)) * 60 + Number(block.start.slice(14, 16));
        earliest = earliest === null ? minutes : Math.min(earliest, minutes);
    }

    if (earliest === null) {
        return MORNING;
    }

    const at = Math.max(0, earliest - MARGIN_MINUTES);

    return `${pad(Math.floor(at / 60))}:${pad(at % 60)}:00`;
}

const FALLBACK_GRANULARITY = 30;

function stepOf(granularity: number | null | undefined): number {
    return typeof granularity === 'number' && Number.isInteger(granularity) && granularity > 0 ? granularity : FALLBACK_GRANULARITY;
}

/** The slots' step, 5.1: the tenant's granularity, or thirty minutes where it is unset, so a click or a drag lands on a bookable start. */
export function slotDurationOf(granularity: number | null | undefined): string {
    const minutes = stepOf(granularity);

    return `${pad(Math.floor(minutes / 60))}:${pad(minutes % 60)}:00`;
}

/** The pixels one hour takes on Week's and Day's axis, whatever the step. */
const HOUR_PIXELS = 60;

/** A slot's height, 5.1: an hour is sixty pixels whatever the step, so a slot is its minutes' share of them. */
export function slotHeightOf(granularity: number | null | undefined): string {
    return `${(stepOf(granularity) * HOUR_PIXELS) / 60}px`;
}

/*
 * Day as member columns: one column per member the window answers, each with that member's hours
 * of the day, their next working day where they have none, and their blocks; then, with at least
 * one member column, one trailing column for the bookings drawn on a row with no column of its
 * own (the implicit row's, an inactive member's under All staff), on a day that has such a
 * booking alone, so no booking drops out of Day. The implicit member is never a column, and a Day
 * with no member column is the one time grid, never a lone column of the other bookings. A date
 * the window does not cover, the window still holding the range before while the new one is read,
 * is not yet read: each member's column has no hours and no next working day until the window
 * covering it lands, so no head, hatch or pill says what the window has not answered.
 */

/**
 * A Day column: a member's, or the trailing column of the other bookings, whose member and hours
 * are null; a member's column whose hours are null is one whose day is not yet read.
 */
export type DayColumn = { key: string; member: Member | null; hours: DayHours | null; next: NextWorking | undefined; blocks: Block[] };

/** The trailing column's head. */
export const OTHER_BOOKINGS: CalendarKey = 'day.other_bookings';

const NO_DAY: DayHours = { windows: [], closures: [], source: 'none', pattern: [] };

/** A member's hours of a date from the window, an empty day where the answer names none. */
export function hoursOn(window: Pick<Window, 'hours'>, staffId: number, date: string): DayHours {
    const hours = Array.isArray(window.hours) ? undefined : window.hours[String(staffId)];

    return hours?.[date] ?? NO_DAY;
}

/** A member's next working day from the window: undefined where the answer names no entry, the member having hours in it. */
function nextOf(window: Pick<Window, 'next_working'>, staffId: number): NextWorking | undefined {
    return Array.isArray(window.next_working) ? undefined : window.next_working[String(staffId)];
}

/** Whether a block reaches the date shown: it starts before the next midnight and ends after this one. */
function reaches(block: Block, date: string): boolean {
    return block.start < `${addDays(date, 1)}T00:00:00` && block.end > `${date}T00:00:00`;
}

/**
 * Day's columns for a date: one per member the window answers, then the other bookings' where any
 * is drawn that day; none where the window answers no member, Day then being the one time grid.
 */
export function dayColumnsOf(window: Pick<Window, 'from' | 'to' | 'members' | 'hours' | 'next_working'>, blocks: readonly Block[], date: string): DayColumn[] {
    if (window.members.length === 0) {
        return [];
    }

    // The window's to is the day after its last, as rangeOf spells it.
    const read = window.from <= date && date < window.to;
    const shown = blocks.filter((block) => reaches(block, date));
    const ids = window.members.map((member) => member.id);
    const columns: DayColumn[] = window.members.map((member) => ({
        key: `member-${member.id}`,
        member,
        hours: read ? hoursOn(window, member.id, date) : null,
        next: read ? nextOf(window, member.id) : undefined,
        blocks: shown.filter((block) => block.staffId === member.id),
    }));
    const others = shown.filter((block) => !ids.includes(block.staffId));

    return others.length === 0 ? columns : [...columns, { key: 'other', member: null, hours: null, next: undefined, blocks: others }];
}

/** The hours the Day axis spans at least, 08:00 to 20:00. */
const AXIS_FROM = 8 * 60;
const AXIS_TO = 20 * 60;
const DAY_END = 24 * 60;

function minutesOf(reading: string): number {
    return Number(reading.slice(0, 2)) * 60 + Number(reading.slice(3, 5));
}

/** A block's wall-clock minutes on the date shown: its start clamped to the day's midnight, its end to the next. */
function blockSpan(block: Block, date: string): [number, number] {
    const start = block.start.slice(0, 10) < date ? 0 : minutesOf(block.start.slice(11, 16));
    const end = block.end.slice(0, 10) > date ? DAY_END : minutesOf(block.end.slice(11, 16));

    return [start, end];
}

/**
 * Day's one axis: from the shown members' earliest start to their latest end that day, at least
 * 08:00 to 20:00, rounded out to the hour; a block or a closure outside the hours widens it too,
 * so nothing drawn falls off the axis.
 */
export function dayAxisOf(columns: readonly DayColumn[], date: string): { min: string; max: string } {
    let from = AXIS_FROM;
    let to = AXIS_TO;

    const reach = (start: number, end: number) => {
        from = Math.min(from, start);
        to = Math.max(to, end);
    };

    for (const column of columns) {
        for (const window of column.hours?.windows ?? []) {
            reach(minutesOf(window.start), minutesOf(window.end));
        }

        for (const closure of column.hours?.closures ?? []) {
            if (closure.start !== null && closure.end !== null) {
                reach(minutesOf(closure.start), minutesOf(closure.end));
            }
        }

        for (const block of column.blocks) {
            reach(...blockSpan(block, date));
        }
    }

    return roundedOut(from, to);
}

function hour(minutes: number): string {
    return `${pad(Math.floor(minutes / 60))}:00:00`;
}

/** A span in minutes as the library's two readings, rounded out to the hour inside the day. */
function roundedOut(from: number, to: number): { min: string; max: string } {
    return { min: hour(Math.max(0, Math.floor(from / 60) * 60)), max: hour(Math.min(DAY_END, Math.ceil(to / 60) * 60)) };
}

/** The margin Week's span keeps before the earliest hour worked and after the latest. */
const WEEK_MARGIN = 60;

/**
 * Week's span, bounded as Day's axis is: from an hour before the earliest hour any shown member
 * works in the seven days from the week's first to an hour after the latest, rounded out to the
 * hour; the whole day where nobody shown has hours that week. Every block of the week widens it,
 * a block past midnight to the day's end, so none falls off the grid.
 */
export function weekAxisOf(window: Pick<Window, 'members' | 'hours'>, blocks: readonly Block[], weekFrom: string): { min: string; max: string } {
    const days = [0, 1, 2, 3, 4, 5, 6].map((offset) => addDays(weekFrom, offset));
    let from = DAY_END;
    let to = 0;

    for (const member of window.members) {
        for (const day of days) {
            for (const hours of hoursOn(window, member.id, day).windows) {
                from = Math.min(from, minutesOf(hours.start) - WEEK_MARGIN);
                to = Math.max(to, minutesOf(hours.end) + WEEK_MARGIN);
            }
        }
    }

    if (from >= to) {
        return { min: hour(0), max: hour(DAY_END) };
    }

    for (const day of days) {
        for (const block of blocks.filter((each) => reaches(each, day))) {
            const [start, end] = blockSpan(block, day);
            from = Math.min(from, start);
            to = Math.max(to, end);
        }
    }

    return roundedOut(from, to);
}

/** A grid's business hours, the library's shape: the weekday 0 for Sunday and two wall-clock readings. */
export type BusinessHours = { daysOfWeek: number[]; startTime: string; endTime: string };

/**
 * A member's business hours on a date: their windows, so the grid hatches the time outside
 * them; a day with none is business hours on another weekday alone, so the whole day shown is
 * hatched.
 */
export function businessHoursOf(hours: DayHours, date: string): BusinessHours[] {
    const weekday = isoWeekday(date) % 7;

    if (hours.windows.length === 0) {
        return [{ daysOfWeek: [(weekday + 1) % 7], startTime: '00:00', endTime: '24:00' }];
    }

    return hours.windows.map((window) => ({ daysOfWeek: [weekday], startTime: window.start, endTime: window.end }));
}

function windowsLine(windows: readonly HoursWindow[], clock: TenantClock): string {
    return windows.map((window) => `${formatTimeOfDay(window.start, clock)}-${formatTimeOfDay(window.end, clock)}`).join(', ');
}

/** A member's head with no hours on the date shown, whichever date it is. */
export const NOT_WORKING_THIS_DAY: CalendarKey = 'day.not_working';

/** A column head's hours: the day's windows, or the sentence saying there are none. */
export function headHoursOf(hours: DayHours, clock: TenantClock, words: CalendarWords): string {
    return hours.windows.length === 0 ? words.text(NOT_WORKING_THIS_DAY) : windowsLine(hours.windows, clock);
}

export const NO_HOURS_AHEAD: CalendarKey = 'day.no_hours_ahead';

/** The pill's sentence of the next working day, read on any date shown. */
export const NEXT_WORKING_THIS_DAY: CalendarKey = 'day.next_working';

/**
 * The pill of a column with no hours: the member's next working day, its weekday by ICU in the
 * page's language and its date in the tenant's format, and its windows; the sentence saying
 * none falls in the eight weeks scanned where the answer is null; and no pill where the window
 * names no entry, the member having hours in it.
 */
export function nextLineOf(next: NextWorking | undefined, clock: TenantClock, words: CalendarWords): string | null {
    if (next === undefined) {
        return null;
    }

    if (next === null) {
        return words.text(NO_HOURS_AHEAD);
    }

    return words.text(NEXT_WORKING_THIS_DAY, { weekday: weekdayName(isoWeekday(next.date), 'long', words.language), date: formatDate(next.date, clock), hours: windowsLine(next.windows, clock) });
}

/** A closure drawn on a Day column: its range on the date shown and its words. */
export type ClosureBlock = { id: string; title: string; start: string; end: string };

/**
 * A closure's title: its sentence by whether it is timed and carries a reason, one whole sentence
 * per case (no sentence from fragments), its placeholders filled. The sentences are the Shifts
 * week's own for the same closure, read from the shifts section the screen carries, so each is
 * spelled once.
 */
function closureTitleOf(closure: Closure, clock: TenantClock, closed: ShiftWords): string {
    if (closure.start !== null && closure.end !== null) {
        const range = { start: formatTimeOfDay(closure.start, clock), end: formatTimeOfDay(closure.end, clock) };

        return closure.reason === null ? closed.text('page.closed', range) : closed.text('page.closed_reason', { ...range, reason: closure.reason });
    }

    return closure.reason === null ? closed.text('page.closed_all_day') : closed.text('page.closed_all_day_reason', { reason: closure.reason });
}

/** A closure's span on the date shown: its two readings, the end of the day spelled 24:00 the next midnight; a whole-day closure the day. */
function closureSpanOf(closure: Closure, date: string): { start: string; end: string } {
    const midnight = `${addDays(date, 1)}T00:00:00`;

    if (closure.start !== null && closure.end !== null) {
        return { start: `${date}T${closure.start}:00`, end: closure.end === '24:00' ? midnight : `${date}T${closure.end}:00` };
    }

    return { start: `${date}T00:00:00`, end: midnight };
}

/**
 * The closures a column draws: each closure of the member's day, the tenant-wide ones among them,
 * as "Closed 14:00-16:00, Dentist", a whole-day closure spanning the day as "Closed all day", each
 * case its own sentence.
 */
export function closureBlocksOf(hours: DayHours, date: string, clock: TenantClock, key: string, closed: ShiftWords): ClosureBlock[] {
    return hours.closures.map((closure, at) => ({ id: `${key}:closure:${at}`, title: closureTitleOf(closure, clock, closed), ...closureSpanOf(closure, date) }));
}

/*
 * A drop on the grid as a move: the block moved by a delta on the wall clock, so the booking's
 * new start is its current start, read on the tenant's wall clock, plus that delta, the whole
 * chain moving by it; where the block landed on another member's column, its segment is given to
 * that member. The new start crosses back to an instant in the tenant's zone, so a drag across a
 * change of the clocks keeps the hour dropped on.
 */

/** What the grid hands on a drop: the block, the wall clock it now starts at, and the column's member where the column changed. */
export type Drop = { block: Block; start: string; staffId: number | null };

/** A drop as the move's read and write take it: the booking, its new start as an instant, and the segments that change member. */
export type DropMove = { uuid: string; start: string; staff: Record<number, number> };

/** A wall clock with no zone as UTC milliseconds, the arithmetic the grid's readings take. */
function wallMillis(wall: string): number {
    return Date.parse(`${wall.slice(0, 19)}Z`);
}

/** An hour in milliseconds, the most a change of the clocks moves them here. */
const HOUR_MS = 3_600_000;

/** A tenant wall clock as the instant it names, spelled as the move's read spells one; the earlier reading where the clocks repeat an hour. */
function instantOfWall(wall: string, timezone: string): string {
    const target = wallMillis(wall);
    let guess = target;

    // Two corrections reach the offset in force at the wall time, whichever side of a change it
    // falls.
    for (let round = 0; round < 2; round++) {
        guess += target - wallMillis(wallOf(new Date(guess).toISOString(), timezone));
    }

    // Where the clocks repeat the hour, the same wall time an hour earlier is the earlier reading.
    const earlier = guess - HOUR_MS;

    if (wallMillis(wallOf(new Date(earlier).toISOString(), timezone)) === target) {
        guess = earlier;
    }

    return `${new Date(guess).toISOString().slice(0, 19)}+00:00`;
}

/** The move a drop makes, or null where it changed neither the time nor the member. */
export function moveOfDrop(booking: Pick<CalendarBooking, 'uuid' | 'items'>, drop: Drop, timezone: string): DropMove | null {
    const delta = wallMillis(drop.start) - wallMillis(drop.block.start);
    const staff: Record<number, number> = drop.staffId !== null && drop.staffId !== drop.block.staffId ? { [drop.block.segment]: drop.staffId } : {};
    const first = booking.items.reduce<string | null>((earliest, item) => (earliest === null || parseInstant(item.start) < parseInstant(earliest) ? item.start : earliest), null);

    if (first === null || (delta === 0 && Object.keys(staff).length === 0)) {
        return null;
    }

    const wall = new Date(wallMillis(wallOf(first, timezone)) + delta).toISOString().slice(0, 19);

    return { uuid: booking.uuid, start: instantOfWall(wall, timezone), staff };
}

/** A block at least this long shows its time range under the title, 5.4. */
export const TIMED_MINUTES = 45;
