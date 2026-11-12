import { weekdayName } from '@/lib/names';
import type { Language } from '../../booking/language';
import { decimalOf, minorDigitsOf } from '../../booking/money';
import type { ServicesKey, ServicesWords } from './words';

/*
 * The Services screen's data: a row as the server spells it, the roster the staff checklist
 * ticks, the form as a person edits it (every number a string until Save folds it), the patch
 * a save sends, and the per-member link writes a changed checklist sends after the patch.
 */

export type Service = {
    id: number;
    name: string;
    description: string;
    duration_min: number;
    processing_gap_min: number;
    buffer_before_min: number;
    buffer_after_min: number;
    price_minor: number;
    currency: string;
    payment_mode: string;
    requires_approval: boolean;
    approval_hold_hours: number | null;
    on_approval_timeout: string;
    cancel_window_hours: number;
    reschedule_window_hours: number;
    lead_time_min: number;
    horizon_days: number;
    status: string;
    category: string | null;
    online: boolean;
};

export type Window = { weekday: number; start: string; end: string };

export type Member = { id: number; name: string; active: boolean; service_ids: number[]; windows: Window[] };

export type View = { kind: 'list' } | { kind: 'create' } | { kind: 'detail'; id: number };

/** The form's fields, every number as the person wrote it; the price as a person writes one. */
export type Form = {
    name: string;
    description: string;
    duration_min: string;
    processing_gap_min: string;
    buffer_before_min: string;
    buffer_after_min: string;
    price: string;
    payment_mode: string;
    requires_approval: boolean;
    approval_hold_hours: string;
    on_approval_timeout: string;
    cancel_window_hours: string;
    reschedule_window_hours: string;
    lead_time_min: string;
    horizon_days: string;
    status: string;
    category: string;
    online: boolean;
};

export type Patch = Record<string, string | number | boolean | null>;

export type LinkWrite = { staffId: number; name: string; service_ids: number[] };

/** The form's three selects, each option's label the key of its words. */
export const PAYMENT_MODES = [
    { value: 'free', label: 'modes.free' },
    { value: 'online', label: 'modes.online' },
    { value: 'onsite', label: 'modes.onsite' },
] as const satisfies readonly { value: string; label: ServicesKey }[];

export const TIMEOUTS = [
    { value: 'expire', label: 'timeouts.expire' },
    { value: 'auto_approve', label: 'timeouts.auto_approve' },
] as const satisfies readonly { value: string; label: ServicesKey }[];

export const STATUSES = [
    { value: 'active', label: 'statuses.active' },
    { value: 'inactive', label: 'statuses.inactive' },
] as const satisfies readonly { value: string; label: ServicesKey }[];

/** A select's options with each label read in the page's language. */
export function optionsIn(options: readonly { value: string; label: ServicesKey }[], words: ServicesWords): { value: string; label: string }[] {
    return options.map((option) => ({ value: option.value, label: words.text(option.label) }));
}

/**
 * A service's state in the catalog's own word, the chip's label (Greek bends active and inactive
 * by the noun's gender, so the shell holds no word for either); an unknown state reads as sent.
 */
export function statusWord(status: string, words: ServicesWords): string {
    const option = STATUSES.find((one) => one.value === status);

    return option === undefined ? status : words.text(option.label);
}

/** A payment mode as the catalog names it in the page's language; an unknown mode reads as sent. */
export function paymentLabel(mode: string, words: ServicesWords): string {
    const option = PAYMENT_MODES.find((one) => one.value === mode);

    return option === undefined ? mode : words.text(option.label);
}

/** A window's weekday as the checklist names it: ICU's short name in the page's language. */
export function weekdayLabel(weekday: number, language: Language): string {
    return weekdayName(weekday, 'short', language);
}

/** The blank form of a new service, its granularity the smallest duration. */
export function blankForm(granularity: number): Form {
    return {
        name: '',
        description: '',
        duration_min: String(granularity),
        processing_gap_min: '0',
        buffer_before_min: '0',
        buffer_after_min: '0',
        price: '',
        payment_mode: 'onsite',
        requires_approval: false,
        approval_hold_hours: '',
        on_approval_timeout: 'expire',
        cancel_window_hours: '24',
        reschedule_window_hours: '24',
        lead_time_min: '0',
        horizon_days: '60',
        status: 'active',
        category: '',
        online: true,
    };
}

/** A persisted row as the form shows it, the price in the row's own currency and scale. */
export function formOf(service: Service): Form {
    return {
        name: service.name,
        description: service.description,
        duration_min: String(service.duration_min),
        processing_gap_min: String(service.processing_gap_min),
        buffer_before_min: String(service.buffer_before_min),
        buffer_after_min: String(service.buffer_after_min),
        price: decimalOf(service.price_minor, minorDigitsOf(service.currency)),
        payment_mode: service.payment_mode,
        requires_approval: service.requires_approval,
        approval_hold_hours: service.approval_hold_hours === null ? '' : String(service.approval_hold_hours),
        on_approval_timeout: service.on_approval_timeout,
        cancel_window_hours: String(service.cancel_window_hours),
        reschedule_window_hours: String(service.reschedule_window_hours),
        lead_time_min: String(service.lead_time_min),
        horizon_days: String(service.horizon_days),
        status: service.status,
        category: service.category ?? '',
        online: service.online,
    };
}

/** The one client gate, 3.3: a name that is blank after trimming cannot be saved. */
export function canSave(form: Pick<Form, 'name'>): boolean {
    return form.name.trim() !== '';
}

/** A numeric field folds unreadable input to zero, 3.3; a fraction folds to its whole part. */
export function foldNumber(text: string): number {
    const value = Number(text.trim());

    return Number.isFinite(value) && text.trim() !== '' ? Math.trunc(value) : 0;
}

/**
 * A price as a person writes it, folded to minor units by the currency's scale: "12,50" and
 * "12.50" are both 1250 in EUR, "12" is 1200, "1.5" in JPY is 1, and anything unreadable is 0.
 */
export function minorOf(text: string, currency: string): number {
    const digits = minorDigitsOf(currency);
    const match = /^\s*(\d+)(?:[.,](\d*))?\s*$/.exec(text);

    if (match === null) {
        return 0;
    }

    const whole = match[1] ?? '0';
    const fraction = ((match[2] ?? '') + '0'.repeat(digits)).slice(0, digits);

    return Number(whole + fraction);
}

/**
 * The service patch a save sends: every field folded, the price by the scale of the currency
 * the row is (or will be) denominated in, the category trimmed and an empty one as null; the
 * currency itself only when creating, since a row keeps what it is denominated in, and the
 * status only on a persisted row, 3.3.
 */
export function patchOf(form: Form, currency: string, creating: boolean): Patch {
    const patch: Patch = {
        name: form.name.trim(),
        description: form.description,
        duration_min: foldNumber(form.duration_min),
        processing_gap_min: foldNumber(form.processing_gap_min),
        buffer_before_min: foldNumber(form.buffer_before_min),
        buffer_after_min: foldNumber(form.buffer_after_min),
        price_minor: minorOf(form.price, currency),
        payment_mode: form.payment_mode,
        requires_approval: form.requires_approval,
        approval_hold_hours: form.requires_approval && form.approval_hold_hours.trim() !== '' ? foldNumber(form.approval_hold_hours) : null,
        on_approval_timeout: form.on_approval_timeout,
        cancel_window_hours: foldNumber(form.cancel_window_hours),
        reschedule_window_hours: foldNumber(form.reschedule_window_hours),
        lead_time_min: foldNumber(form.lead_time_min),
        horizon_days: foldNumber(form.horizon_days),
        category: form.category.trim() === '' ? null : form.category.trim(),
        online: form.online,
    };

    if (creating) {
        patch.currency = currency;
    } else {
        patch.status = form.status;
    }

    return patch;
}

/** The furthest ahead a service may be booked, in days: the column's CHECK and the write's refusal past it. */
export const HORIZON_MAX_DAYS = 365;

/**
 * The members a new service's checklist starts ticked: the roster's one row where it is the only
 * one, which is the implicit member since that row is never deleted, because the server links a
 * service created on such a roster to it (ServiceRecords::insert). An untouched checklist then
 * matches the roster the create lands on and sends nothing that would undo the link; an untick
 * is the owner's word and is sent. A longer roster starts with nobody ticked.
 */
export function createLinks(roster: readonly Member[]): number[] {
    const [only] = roster;

    return roster.length === 1 && only !== undefined ? [only.id] : [];
}

/** The members whose links name the service: the checklist's ticks as the server holds them. */
export function linkedMembers(roster: readonly Member[], serviceId: number): number[] {
    return roster.filter((member) => member.service_ids.includes(serviceId)).map((member) => member.id);
}

/**
 * One replace-all write per member whose tick changed, each carrying that member's other
 * services beside this one, so a save of one service never loses what else a member performs.
 * An untouched checklist yields none.
 */
export function linkWrites(staged: readonly number[], roster: readonly Member[], serviceId: number): LinkWrite[] {
    const writes: LinkWrite[] = [];

    for (const member of roster) {
        const ticked = staged.includes(member.id);
        const linked = member.service_ids.includes(serviceId);

        if (ticked === linked) {
            continue;
        }

        const others = member.service_ids.filter((id) => id !== serviceId);
        writes.push({ staffId: member.id, name: member.name, service_ids: ticked ? [...others, serviceId] : others });
    }

    return writes;
}

/** The grey sub-line under a name: the duration, then the gap when non-zero, a whole line each. */
export function subLine(service: Pick<Service, 'duration_min' | 'processing_gap_min'>, words: ServicesWords): string {
    const n = String(service.duration_min);

    return service.processing_gap_min > 0 ? words.choice('table.minutes_gap', service.processing_gap_min, { n }) : words.text('table.minutes', { n });
}

export function inactiveCount(rows: readonly Pick<Service, 'status'>[]): number {
    return rows.filter((row) => row.status === 'inactive').length;
}

export function visibleRows<T extends Pick<Service, 'status'>>(rows: readonly T[], showInactive: boolean): T[] {
    return rows.filter((row) => showInactive || row.status !== 'inactive');
}
