import { addDays, wallClockOf } from '../../booking/time';
import type { WizardKey, WizardWords } from '@/customers/words';
import { formatDate, formatTimeOfDay, type TenantClock } from '@/lib/format';

/*
 * The manual booking wizard's state and its transitions, as pure functions over one value: the
 * step, the ordered segments, the same-staff preference, the day, the chosen slot as the exact
 * string the availability answer carried, and the customer. The staleness rules of 4.3 are
 * transitions of this one state and never effect wiring: a change to the chain's shape or the
 * preference kills the chosen slot, a different day kills it, the same day again changes nothing,
 * and removing the only segment is refused with the state unchanged.
 *
 * The catalog is the shared prop the server hands a user who may book by hand: the active
 * services with the durations and processing tails the summary's end is computed from and the
 * active members who perform each, the active named members, and the roster count the one-value
 * rule reads, 6.1.
 *
 * No sentence is spelled here: a constant that held one holds its key of the wizard group of
 * lang/{locale}/admin/customers.php, and a function that words a value takes the wizard's words.
 */

export type CatalogService = { id: number; name: string; duration_min: number; processing_min: number; staff: number[] };

export type CatalogStaff = { id: number; name: string };

export type Catalog = { services: CatalogService[]; staff: CatalogStaff[]; members: number };

export type Segment = { service: number | null; staff: number | null };

type Customer = { name: string; email: string; phone: string };

export type Step = 0 | 1 | 2;

export type State = {
    step: Step;
    segments: Segment[];
    sameStaff: boolean;
    day: string;
    slot: string | null;
    customer: Customer;
};

/**
 * What a calendar slot opens the wizard with, 4: the day and the first segment's staff pin; a
 * customer's page's Book now opens it with the customer, the step then counted answered and
 * still editable; the top bar opens with none.
 */
export type Prefill = { date?: string; staffId?: number | null; customer?: Customer };

/** The strip's value line when a step has no answer yet, so its height never jumps, 4.1. */
export const NO_ANSWER = '\u00a0';

/** The three steps' names, in order, each a key. */
export const STEPS = ['steps.services', 'steps.when', 'steps.customer'] as const satisfies readonly WizardKey[];

export const NO_TIMES = 'no_times' satisfies WizardKey;

export const ANY_STAFF = 'any_staff' satisfies WizardKey;

export function initial(today: string, prefill: Prefill = {}): State {
    return {
        step: 0,
        segments: [{ service: null, staff: prefill.staffId ?? null }],
        sameStaff: false,
        day: prefill.date ?? today,
        slot: null,
        customer: prefill.customer === undefined ? { name: '', email: '', phone: '' } : { ...prefill.customer },
    };
}

/** The courtesy of 4.2: the first segment takes the first active service while it is unanswered, and is left alone once answered. */
export function courtesy(state: State, catalog: Catalog): State {
    const first = state.segments[0];
    const service = catalog.services[0];

    if (first === undefined || first.service !== null || service === undefined) {
        return state;
    }

    return { ...state, segments: [{ service: service.id, staff: performs(catalog, service.id, first.staff) ? first.staff : null }, ...state.segments.slice(1)] };
}

function performs(catalog: Catalog, service: number | null, staff: number | null): boolean {
    return staff === null || (catalog.services.find((each) => each.id === service)?.staff.includes(staff) ?? false);
}

/** A change to the chain's shape: the chosen slot dies. */
function reshaped(state: State, segments: Segment[]): State {
    return { ...state, segments, slot: null };
}

export function chooseService(state: State, index: number, service: number | null, catalog: Catalog): State {
    return reshaped(
        state,
        state.segments.map((segment, at) => (at === index ? { service, staff: performs(catalog, service, segment.staff) ? segment.staff : null } : segment)),
    );
}

export function chooseStaff(state: State, index: number, staff: number | null): State {
    return reshaped(
        state,
        state.segments.map((segment, at) => (at === index ? { ...segment, staff } : segment)),
    );
}

export function addSegment(state: State): State {
    return reshaped(state, [...state.segments, { service: null, staff: null }]);
}

/** Refused with the state unchanged when the chain holds one segment; a chain back to one drops the preference with the checkbox. */
export function removeSegment(state: State, index: number): State {
    if (state.segments.length < 2) {
        return state;
    }

    const segments = state.segments.filter((_, at) => at !== index);

    return { ...reshaped(state, segments), sameStaff: segments.length > 1 && state.sameStaff };
}

/** Toggling same-staff kills the chosen slot only. */
export function toggleSameStaff(state: State): State {
    return { ...state, sameStaff: !state.sameStaff, slot: null };
}

/** A different day kills the chosen slot; the same day again changes nothing. */
export function chooseDay(state: State, day: string): State {
    return day === state.day ? state : { ...state, day, slot: null };
}

export function chooseSlot(state: State, slot: string): State {
    return { ...state, slot };
}

export function editCustomer(state: State, field: keyof Customer, value: string): State {
    return { ...state, customer: { ...state.customer, [field]: value } };
}

function servicesAnswered(state: State): boolean {
    return state.segments.every((segment) => segment.service !== null);
}

/** Whether the customer step holds its answer, 4.4: a name and an email, each non-blank after trimming. */
export function customerAnswered(state: State): boolean {
    return state.customer.name.trim() !== '' && state.customer.email.trim() !== '';
}

/** Whether the current step's gate is open, 4.2 to 4.4: Continue on the first two, Create on the last. */
export function gateOpen(state: State): boolean {
    return state.step === 0 ? servicesAnswered(state) : state.step === 1 ? state.slot !== null : customerAnswered(state);
}

/** Forward travel is Continue's alone, and only through an open gate. */
export function advance(state: State): State {
    return gateOpen(state) && state.step < 2 ? { ...state, step: (state.step + 1) as Step } : state;
}

/** A finished step is a live button back to itself; the current and the unreached are not. */
export function reopen(state: State, step: Step): State {
    return step < state.step ? { ...state, step } : state;
}

/** The strip's running answers, 4.1, and the machine date for the date button's DOM value. */
export function stripValues(state: State, catalog: Catalog, clock: TenantClock, words: WizardWords): [string, string, string] {
    const first = catalog.services.find((service) => service.id === state.segments[0]?.service);
    const more = state.segments.length - 1;
    const day = formatDate(state.day, clock);
    const time = state.slot === null ? null : timeLabel(state.slot, clock);

    return [
        first === undefined ? NO_ANSWER : more > 0 ? words.choice('first_more', more, { service: first.name }) : first.name,
        state.step === 0 && state.slot === null ? NO_ANSWER : time === null ? day : words.text('when', { date: day, time }),
        state.customer.name.trim() === '' ? NO_ANSWER : state.customer.name.trim(),
    ];
}

/** Whether the staff choice is real, 6.1: the feature entitled, or more than one staff row, or a pin already held. */
export function staffChoiceReal(entitlements: readonly string[], catalog: Catalog, state: State): boolean {
    return entitlements.includes('staff') || catalog.members > 1 || state.segments.some((segment) => segment.staff !== null);
}

/** The active members who perform a segment's service, in catalog order. */
export function performersOf(catalog: Catalog, service: number | null): CatalogStaff[] {
    const ids = catalog.services.find((each) => each.id === service)?.staff ?? [];

    return catalog.staff.filter((member) => ids.includes(member.id));
}

/** The chain in the create endpoint's shape: staff_id always present, null for no preference. */
export function itemsOf(state: State): Item[] {
    return state.segments.flatMap((segment) => (segment.service === null ? [] : [{ service_id: segment.service, staff_id: segment.staff }]));
}

/** The availability query for the day, or null until every segment names a service. */
export function availabilityQuery(state: State): string | null {
    if (!servicesAnswered(state)) {
        return null;
    }

    const query = new URLSearchParams({ items: JSON.stringify(itemsOf(state)), from: state.day, to: addDays(state.day, 1), same_staff: state.sameStaff ? 'true' : 'false' });

    return query.toString();
}

export type Slot = { wire: string; label: string };

/** A period of the day the times are grouped by: an identifier, displayed through its key. */
export type Period = 'morning' | 'afternoon' | 'evening';

export type Group = { name: Period; slots: Slot[] };

/** A period's display key. */
export function periodKey(period: Period): WizardKey {
    return `periods.${period}`;
}

/** A slot's label: the wall-clock time in the tenant's zone, in the tenant's time format. */
export function timeLabel(wire: string, clock: TenantClock): string {
    const wall = wallClockOf(wire, clock.timezone);

    return formatTimeOfDay(`${String(wall.hour).padStart(2, '0')}:${String(wall.minute).padStart(2, '0')}`, clock);
}

/** The starts grouped by the tenant's clock, splitting at noon and at five, empty groups omitted. */
export function groupsOf(starts: readonly string[], clock: TenantClock): Group[] {
    const groups: Group[] = [
        { name: 'morning', slots: [] },
        { name: 'afternoon', slots: [] },
        { name: 'evening', slots: [] },
    ];

    for (const wire of starts) {
        const hour = wallClockOf(wire, clock.timezone).hour;

        groups[hour < 12 ? 0 : hour < 17 ? 1 : 2]?.slots.push({ wire, label: timeLabel(wire, clock) });
    }

    return groups.filter((group) => group.slots.length > 0);
}

function onGrid(minutes: number, granularity: number): number {
    const step = Number.isInteger(granularity) && granularity > 0 ? granularity : 5;

    return Math.ceil(minutes / step) * step;
}

/**
 * The chain's customer-facing length in minutes, 4.4: each value rounded up to the granularity
 * grid, each segment advancing by its duration plus its processing tail, the last segment's tail
 * excluded because the customer leaves before the chair frees up.
 */
function spanMinutes(state: State, catalog: Catalog, granularity: number): number {
    const services = state.segments.flatMap((segment) => catalog.services.filter((service) => service.id === segment.service));

    return services.reduce((sum, service, index) => sum + onGrid(service.duration_min, granularity) + (index < services.length - 1 ? onGrid(service.processing_min, granularity) : 0), 0);
}

/** The end of the appointment as a wire instant, the start plus the span. */
export function endOf(start: string, state: State, catalog: Catalog, granularity: number): string {
    return new Date(Date.parse(start) + spanMinutes(state, catalog, granularity) * 60_000).toISOString();
}

/** One Service line per segment, naming the member where the choice was real and the service alone where it never was. */
export function serviceLines(state: State, catalog: Catalog, staffReal: boolean, words: WizardWords): string[] {
    return state.segments.flatMap((segment) => {
        const service = catalog.services.find((each) => each.id === segment.service);

        if (service === undefined) {
            return [];
        }

        const member = catalog.staff.find((each) => each.id === segment.staff);

        return [staffReal ? words.text('summary.service_staff', { service: service.name, staff: member?.name ?? words.text(ANY_STAFF) }) : service.name];
    });
}

export type Item = { service_id: number; staff_id: number | null };

export type CreateBody = { customer_name: string; customer_email: string; customer_phone: string; items: Item[]; start: string | null; same_staff: boolean };

/** The create endpoint's body: the customer, the ordered segments and the start verbatim. */
export function bodyOf(state: State): CreateBody {
    return {
        customer_name: state.customer.name,
        customer_email: state.customer.email,
        customer_phone: state.customer.phone,
        items: itemsOf(state),
        start: state.slot,
        same_staff: state.sameStaff,
    };
}
