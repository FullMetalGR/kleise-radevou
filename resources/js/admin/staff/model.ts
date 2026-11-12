import { formatCount, formatDate, formatTimeOfDay, type TenantClock } from '@/lib/format';
import type { StaffColour } from '@/lib/hue';
import { isoWeekday } from '@/lib/day';
import { listOf, unitFigure, weekdayName } from '@/lib/names';
import { type ShiftPattern, windowMinutes } from '@/shifts/model';
import type { StoredImage } from '@/settings/model';
import type { StaffKey, StaffWords } from './words';

/*
 * The Staff screen's data: a member as the server spells them, the tenant's users the linked-user
 * combobox picks from, a closure as the card prints it, the form as a person edits it, and the
 * patch a save sends: the row and the links, replace-all. A member's hours are their repeating
 * pattern, read here as stored and edited on Shifts alone: the page shows it as one read-only
 * line. The photo is the member's own write on its own two routes, so it is read from the row and
 * never sits in the form or the patch. The implicit member, who stands for the business, has no
 * face and shows no title or bio: its patch carries neither of the two, as the server ignores both
 * for that row (P9F-L35-b).
 */

/** A member's repeating pattern as stored, as Shifts reads it less the member and the minutes. */
export type StaffPattern = Omit<ShiftPattern, 'staff_id' | 'minutes'>;

/**
 * A closure as the cards list it: its first date and, for a run of consecutive dates with the same
 * hours and reason that the server folded into one line, its last, null for one date; the window,
 * both null all day; the tenant's own note.
 */
export type Blackout = { date: string; until: string | null; start: string | null; end: string | null; reason: string | null };

/** A line of the Staff screen's closures card: whose it is beside, a member's id or null for the whole business. */
export type ClosureLine = Blackout & { staff_id: number | null };

export type StaffMember = {
    id: number;
    name: string;
    email: string | null;
    status: string;
    online: boolean;
    is_implicit: boolean;
    user_id: number | null;
    title: string | null;
    bio: string | null;
    service_ids: number[];
    /** The member's pattern as stored, null where they have none. */
    pattern: StaffPattern | null;
    blackouts: Blackout[];
    /** The member's own calendar colour, or null for Automatic. */
    colour: StaffColour | null;
    /** The stored photo's name and the URL it is served at, never the stored path; null for none. */
    photo: StoredImage | null;
    /** Hours this week: the parts of the list's one sentence, the server's. */
    hours: StaffHours;
};

/**
 * Hours this week as the server reads it (ShiftRecords::hoursOf): whether the week is the
 * pattern's own, every week, and then the pattern's weekly minutes, else the week's minutes as
 * worked with closures subtracted; and the week's closures reaching the member, by date.
 */
export type StaffHours = { minutes: number; weekly: boolean; closures: readonly { date: string; start: string | null; end: string | null }[] };

export type TenantUser = { id: number; name: string; email: string };

export type CatalogService = { id: number; name: string; status: string };

export type View = { kind: 'list' } | { kind: 'create' } | { kind: 'detail'; id: number };

export type Form = {
    name: string;
    email: string;
    user_id: number | null;
    status: string;
    online: boolean;
    title: string;
    bio: string;
    service_ids: number[];
};

export type Patch = {
    name: string;
    email: string | null;
    user_id: number | null;
    status: string;
    online: boolean;
    /** Absent from the implicit member's patch, as the bio is. */
    title?: string | null;
    bio?: string | null;
    service_ids: number[];
};

/** The Status select's options, each label a key of staff.php read in the page's words. */
export const STATUSES = [
    { value: 'active', label: 'statuses.active' },
    { value: 'inactive', label: 'statuses.inactive' },
] as const satisfies readonly { value: string; label: StaffKey }[];

/**
 * A member's state in the Staff screen's own word, the chip's label (Greek bends active and
 * inactive by the noun's gender, so the shell holds no word for either); an unknown one reads as
 * sent.
 */
export function statusWord(status: string, words: StaffWords): string {
    const option = STATUSES.find((one) => one.value === status);

    return option === undefined ? status : words.text(option.label);
}

/** The server's caps on the title and the bio, in characters. */
export const TITLE_LIMIT = 60;

export const BIO_LIMIT = 500;

export function blankForm(): Form {
    return { name: '', email: '', user_id: null, status: 'active', online: true, title: '', bio: '', service_ids: [] };
}

/** A persisted row as the form shows it. */
export function formOf(member: StaffMember): Form {
    return {
        name: member.name,
        email: member.email ?? '',
        user_id: member.user_id,
        status: member.status,
        online: member.online,
        title: member.title ?? '',
        bio: member.bio ?? '',
        service_ids: [...member.service_ids],
    };
}

/** The one client gate: a name blank after trimming cannot be saved. */
export function canSave(form: Pick<Form, 'name'>): boolean {
    return form.name.trim() !== '';
}

/**
 * The patch a save sends: the email, the title and the bio null when blank, the links whole; for
 * the implicit member, no title and no bio at all. The flag is named on every
 * call, as the services' patchOf names its own, so no caller can drop it by leaving it out.
 */
export function patchOf(form: Form, implicit: boolean): Patch {
    return {
        name: form.name.trim(),
        email: orNull(form.email),
        user_id: form.user_id,
        status: form.status,
        online: form.online,
        ...(implicit ? {} : { title: orNull(form.title), bio: orNull(form.bio) }),
        service_ids: [...form.service_ids],
    };
}

function orNull(text: string): string | null {
    return text.trim() === '' ? null : text.trim();
}

/** A text's length as the server counts it, in characters rather than UTF-16 units. */
export function characters(text: string): number {
    return Array.from(text).length;
}

/** A user as the combobox and the read-only page name them, the address beside the name. */
export function userLabel(user: TenantUser, words: StaffWords): string {
    return words.text('form.user_option', { user: user.name, address: user.email });
}

/** The users as the combobox lists them, the address telling two of a name apart. */
export function userOptions(users: readonly TenantUser[], words: StaffWords): { value: number; label: string }[] {
    return users.map((user) => ({ value: user.id, label: userLabel(user, words) }));
}

/**
 * The rows a search keeps: those whose name, email or title holds the words typed, case-blind,
 * in the browser over the rows the page already holds; a blank search keeps every row.
 */
export function searchRows<T extends Pick<StaffMember, 'name' | 'email' | 'title'>>(rows: readonly T[], query: string): T[] {
    const needle = query.trim().toLowerCase();

    return needle === '' ? [...rows] : rows.filter((row) => [row.name, row.email ?? '', row.title ?? ''].some((field) => field.toLowerCase().includes(needle)));
}

export function inactiveCount(rows: readonly { status: string }[]): number {
    return rows.filter((row) => row.status === 'inactive').length;
}

export function visibleRows<T extends { status: string }>(rows: readonly T[], showInactive: boolean): T[] {
    return rows.filter((row) => showInactive || row.status !== 'inactive');
}

/**
 * Whether the Staff screen without the staff feature renders its member list: while any row
 * other than the implicit one exists, an inactive one included. A roster holding the implicit
 * row alone is a floor, and the screen is the tenant-wide closures alone, because the implicit
 * member is never rendered while the feature is unentitled. A member sold before the feature
 * went stays readable whatever their status: the properly downgraded tenant deactivated them
 * first. With the feature the list is always rendered; this answers the floor's question, never
 * whether anything is read-only.
 */
export function membersShown(rows: readonly Pick<StaffMember, 'is_implicit'>[]): boolean {
    return rows.some((row) => !row.is_implicit);
}

/**
 * A count of minutes as the screen's figures spell it, as Shifts spells its own: a whole figure of
 * hours ICU's alone, "44h" in English, else ICU's hours and minutes in the language through one
 * key, "15h 30m" (staff.php figure). No unit is glued by hand.
 */
function figureOf(minutes: number, words: StaffWords): string {
    const whole = Math.floor(minutes / 60);
    const rest = minutes % 60;
    const hours = unitFigure(whole, 'hour', words.language);

    return rest === 0 ? hours : words.text('figure', { hours, minutes: unitFigure(rest, 'minute', words.language) });
}

/**
 * The member's pattern as the page's read-only line spells it: one whole line per case, the usual
 * week's hours and the cadence, every week or every :weeks weeks from the start in the tenant's
 * date format, or that there is none. Shifts edits it, which the link beside the line names.
 */
export function patternLine(pattern: StaffPattern | null, clock: TenantClock, words: StaffWords): string {
    if (pattern === null) {
        return words.text('pattern.none');
    }

    const hours = figureOf(windowMinutes(pattern.windows), words);

    return pattern.cadence_weeks === 1
        ? words.text('pattern.weekly', { hours })
        : words.text('pattern.cadence', { hours, weeks: formatCount(pattern.cadence_weeks, words.locale), date: formatDate(pattern.starts_on, clock) });
}

/** Hours this week's figure as the list spells it, linked to Shifts: the minutes in hours, and minutes where they are not whole. */
export function hoursFigure(hours: StaffHours, words: StaffWords): string {
    return figureOf(hours.minutes, words);
}

/**
 * Hours this week's lines, one whole key per case the column shows: the cadence, and the week's
 * closures or none.
 */
const HOURS_LINES = {
    weekly: { open: 'table.hours_weekly', closed: 'table.hours_weekly_closed' },
    thisWeek: { open: 'table.hours_this_week', closed: 'table.hours_this_week_closed' },
} as const satisfies Record<string, { open: StaffKey; closed: StaffKey }>;

/**
 * Hours this week's line: one whole key per case, every week where the week is the pattern's own
 * and this week otherwise, each with the week's closures where any reach the member, each its
 * weekday's short name and its window, or the weekday alone all day, joined as ICU joins a list in
 * the language. The figure is left as the line's :hours, which the table fills with its link to
 * Shifts.
 */
export function hoursLine(hours: StaffHours, clock: TenantClock, words: StaffWords): string {
    const lines = hours.weekly ? HOURS_LINES.weekly : HOURS_LINES.thisWeek;

    if (hours.closures.length === 0) {
        return words.text(lines.open);
    }

    const closures = hours.closures.map((closure) => {
        const day = weekdayName(isoWeekday(closure.date), 'short', words.language);

        return closure.start === null || closure.end === null ? day : words.text('table.closed_window', { day, time: formatTimeOfDay(closure.start, clock), end: formatTimeOfDay(closure.end, clock) });
    });

    return words.text(lines.closed, { closures: listOf(closures, words.language) });
}
