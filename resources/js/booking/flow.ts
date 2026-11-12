import type { Notice } from './api';
import type { MountOptions } from './attributes';
import { serviceById, staffOf, type Catalog, type CatalogService } from './catalog';
import type { Booking } from './booking';
import type { Prefill } from './config';
import { EMPTY_DRAFT, type CustomerDraft } from './hold';
import type { LineKey } from './words';

/*
 * The state machine: the steps, the initial step from the mount attributes alone, the
 * forward targets of Continue, Back, and the flow's own state with what each event clears.
 * Pure functions over the flow's state, so its tables are tested as tables. The reducer
 * moves the state and nothing else: which moves are the customer's, and so land focus, is
 * the component's to know.
 *
 * The customer draft lives here rather than in the details form because a 409 unmounts that
 * form, and a customer who has just been told their slot was taken is the last person who
 * should be asked to type their email again.
 */

export type Step = 'service' | 'staff' | 'when' | 'details' | 'review' | 'done';

export interface Segment {
    readonly serviceId: number;
    /** Null is "no preference", which reaches the wire as an explicit null meaning any. */
    readonly staffId: number | null;
}

/**
 * All flow state, component-local and nothing else. The window is a count of whole availability
 * windows on from the tenant's today; the selected day is a tenant-local date as the
 * availability answer keys it; the chosen start is the wire value whole, kept byte-identical for
 * the hold. The conflict is a hold 409's notice, the server's sentence verbatim, or the app's
 * own where the answer carried none; the details notice is the details form's refusal; the
 * review notice is the confirm's. The draft is what the customer typed, and the booking is what
 * the server answered with, which the review and done steps read and nothing else does. The
 * expiry flag is the server's word that the hold is gone, which is what keeps the confirm button
 * disabled whatever the browser clock thinks. The recovering flag is up while a refused
 * confirm's re-read is out.
 *
 * The start is the step Back's table of 1.4 reads against: the one the mount attributes derived,
 * until a void of 1.6 makes the flow a bare mount's and it becomes service. The carried member
 * is the preselected staff member riding along without a service, seeded onto each newly picked
 * service that lists them: the one a void left, or the one a mount named alone, a staff link's
 * ?staff=; it is null for every other mount.
 */
export interface FlowState {
    readonly step: Step;
    readonly segments: readonly Segment[];
    readonly window: number;
    readonly selectedDay: string | null;
    readonly chosenStart: string | null;
    readonly conflict: Notice | null;
    readonly detailsNotice: Notice | null;
    readonly draft: CustomerDraft;
    readonly booking: Booking | null;
    readonly reviewNotice: Notice | null;
    readonly expired: boolean;
    readonly recovering: boolean;
    readonly start: Step;
    readonly carried: number | null;
}

type ReviewEvent =
    | { readonly type: 'confirmed'; readonly booking: Booking }
    | { readonly type: 'confirm-refused'; readonly notice: Notice; readonly expired: boolean }
    | { readonly type: 'recover'; readonly expired: boolean }
    | { readonly type: 'handed-off' }
    | { readonly type: 'expire' }
    | { readonly type: 'restart' };

export type FlowEvent =
    | ReviewEvent
    | { readonly type: 'go'; readonly step: Step }
    | { readonly type: 'append'; readonly service: Pick<CatalogService, 'id' | 'staff'> }
    | { readonly type: 'void'; readonly staffId: number | null }
    | { readonly type: 'remove'; readonly index: number }
    | { readonly type: 'choose'; readonly index: number; readonly staffId: number | null }
    | { readonly type: 'window'; readonly index: number }
    | { readonly type: 'select-day'; readonly day: string }
    | { readonly type: 'jump'; readonly index: number; readonly day: string }
    | { readonly type: 'pick'; readonly start: string }
    | { readonly type: 'draft'; readonly field: keyof CustomerDraft; readonly value: string }
    | { readonly type: 'held'; readonly booking: Booking }
    | { readonly type: 'conflict'; readonly notice: Notice }
    | { readonly type: 'refused'; readonly notice: Notice };

/**
 * What the review step says once its hold has been handed off to a checkout: the reason the
 * button stays disabled, which a page brought back from the browser's history shows too.
 */
export const HANDED_OFF_SENTENCE: LineKey = 'review.handed_off';

/**
 * The table of 1.2. A mount naming both seeds that pairing unvalidated, because the catalog
 * has not loaded yet.
 */
export function initialStep(options: MountOptions): Step {
    if (options.serviceId === null) {
        return 'service';
    }

    return options.staffId === null ? 'staff' : 'when';
}

export function initialSegments(options: MountOptions): Segment[] {
    return options.serviceId === null ? [] : [{ serviceId: options.serviceId, staffId: options.staffId }];
}

/**
 * The draft the flow starts from, 2.4 and 1.5: the hosted page's prefill under a My bookings
 * session, the name, the email and the phone, the note left empty; or nothing typed at all. Seeded
 * once, into the initial state, and from then on it is the customer's draft like any other:
 * editable, and surviving a 409, a restart and a Back as 1.5 says.
 */
export function initialDraft(prefill: Prefill | null): CustomerDraft {
    return prefill === null ? EMPTY_DRAFT : { ...EMPTY_DRAFT, name: prefill.name, email: prefill.email, phone: prefill.phone ?? '' };
}

export function initialFlowState(options: MountOptions, prefill: Prefill | null = null): FlowState {
    return {
        step: initialStep(options),
        segments: initialSegments(options),
        window: 0,
        selectedDay: null,
        chosenStart: null,
        conflict: null,
        detailsNotice: null,
        draft: initialDraft(prefill),
        booking: null,
        reviewNotice: null,
        expired: false,
        recovering: false,
        start: initialStep(options),
        carried: options.serviceId === null ? options.staffId : null,
    };
}

/**
 * The judgement of 1.6: the mount named a service and no catalog row has that id. The flow asks
 * it once, of the first catalog answer, and never again.
 */
export function preselectionVoid(options: MountOptions, catalog: Catalog): boolean {
    return options.serviceId !== null && serviceById(catalog, options.serviceId) === undefined;
}

/**
 * The table of 1.5: picking a slot clears the conflict notice and the details notice, a hold
 * 409 clears the chosen slot and replaces the conflict notice, and nothing else ever resets.
 * The chain, the selected day, the window, the chosen start and the whole customer draft
 * survive every move between steps. The window never goes before today. A jump, the Next
 * available date press of 2.3, moves the window and the selected day together and nothing
 * else, so a day in another week is selected as its week arrives.
 *
 * A hold's answer is routed by the returned status alone: pending is a booking to review, and
 * any other status is the outcome itself and goes straight to done. A pending booking clears
 * the expiry flag with it, because that hold is a new one.
 *
 * A confirm's answer is the settled booking, and done reads its status and nothing else. A
 * refused confirm first starts a recovery (4.4), which raises the recovering flag; the
 * recovery ends either as that same settled answer, when the re-read found the booking
 * settled, or as the refusal. Either end lowers the flag. The refusal leaves everything
 * standing and renders the server's sentence beside the summary.
 *
 * A refusal that said the hold is gone raises the expiry flag the moment it arrives, as its
 * recovery starts and not when it ends (4.2), so the countdown follows it while the re-read is
 * still out; the countdown reaching zero raises it too: one flag, two ways up, and whichever
 * raised it the confirm stays disabled whatever the browser clock says afterwards. Nothing
 * lowers it but a new hold or "Pick another time".
 *
 * A hand-off sets the review notice to the sentence saying why the button stays disabled.
 *
 * "Pick another time" clears the held booking, the expiry flag, the recovering flag, the
 * chosen slot and the review notice, and lands on when; the chain, the day, the window and the
 * draft all survive it, so the customer re-picks from where they were rather than from the
 * start.
 *
 * A dead preselection clears the segments and nothing else, and makes the flow a bare mount's:
 * it starts over at service, Back reads the table as a mount that named nothing, and the
 * preselected member rides along, carried onto a newly picked service only if it lists them.
 */
export function reduce(state: FlowState, event: FlowEvent): FlowState {
    switch (event.type) {
        case 'go':
            return { ...state, step: event.step };
        case 'append':
            return { ...state, segments: [...state.segments, { serviceId: event.service.id, staffId: carriedOnto(state.carried, event.service) }] };
        case 'void':
            return { ...state, step: 'service', segments: [], start: 'service', carried: event.staffId };
        case 'remove':
            return { ...state, segments: state.segments.filter((_segment, at) => at !== event.index) };
        case 'choose':
            return { ...state, segments: state.segments.map((segment, at) => (at === event.index ? { ...segment, staffId: event.staffId } : segment)) };
        case 'window':
            return { ...state, window: Math.max(0, event.index) };
        case 'select-day':
            return { ...state, selectedDay: event.day };
        case 'jump':
            return { ...state, window: Math.max(0, event.index), selectedDay: event.day };
        case 'pick':
            return { ...state, step: 'details', chosenStart: event.start, conflict: null, detailsNotice: null };
        case 'draft':
            return { ...state, draft: withField(state.draft, event.field, event.value) };
        case 'held':
            return { ...state, step: event.booking.status === 'pending' ? 'review' : 'done', booking: event.booking, expired: false };
        case 'conflict':
            return { ...state, step: 'when', chosenStart: null, conflict: event.notice };
        case 'refused':
            return { ...state, detailsNotice: event.notice };
        default:
            return reduceReview(state, event);
    }
}

/**
 * The events of the review step and its way out, split from reduce to keep each switch short.
 */
function reduceReview(state: FlowState, event: ReviewEvent): FlowState {
    switch (event.type) {
        // The confirm's answer carries no pays_at_venue, so the hold's is kept on what it settled.
        case 'confirmed':
            return { ...state, step: 'done', booking: state.booking?.paysAtVenue ? { ...event.booking, paysAtVenue: true } : event.booking, recovering: false };
        case 'confirm-refused':
            return { ...state, reviewNotice: event.notice, expired: state.expired || event.expired, recovering: false };
        case 'recover':
            return { ...state, recovering: true, expired: state.expired || event.expired };
        case 'handed-off':
            return { ...state, reviewNotice: { key: HANDED_OFF_SENTENCE, tone: 'polite' } };
        case 'expire':
            return { ...state, expired: true };
        case 'restart':
            return { ...state, step: 'when', booking: null, expired: false, recovering: false, chosenStart: null, reviewNotice: null };
    }
}

/**
 * Whether the review step offers "Pick another time": once the hold has expired, or a notice
 * exists, or a recovery is in flight. The one expression, in the one place. The third case is
 * what keeps a refusal from being a dead end while its re-read hangs, whether or not the hold
 * has a countdown. A hand-off is covered by the second, because it sets a notice.
 */
export function restartOffered(state: Pick<FlowState, 'expired' | 'reviewNotice' | 'recovering'>): boolean {
    return state.expired || state.reviewNotice !== null || state.recovering;
}

/**
 * The hold the review step is showing, as the page-hide release needs it: its identifier and its
 * deadline, and nothing more. Never the whole booking, whose checkout url carries the manage
 * token; null on every step but review.
 */
export function underReview(state: Pick<FlowState, 'step' | 'booking'>): Pick<Booking, 'uuid' | 'holdExpiresAt'> | null {
    return state.step === 'review' && state.booking !== null ? { uuid: state.booking.uuid, holdExpiresAt: state.booking.holdExpiresAt } : null;
}

/**
 * The carried member if the service lists them, else no preference.
 */
function carriedOnto(carried: number | null, service: Pick<CatalogService, 'staff'>): number | null {
    return carried !== null && service.staff.some((person) => person.id === carried) ? carried : null;
}

/**
 * One field of the draft replaced, spelled out field by field so that a fifth one is a
 * compile error here rather than a key the form writes and nothing reads.
 */
function withField(draft: CustomerDraft, field: keyof CustomerDraft, value: string): CustomerDraft {
    switch (field) {
        case 'name':
            return { ...draft, name: value };
        case 'email':
            return { ...draft, email: value };
        case 'phone':
            return { ...draft, phone: value };
        case 'note':
            return { ...draft, note: value };
    }
}

/**
 * Whether any segment's service lists staff, as the catalog answered. False until it has.
 */
export function anySegmentListsStaff(segments: readonly Segment[], catalog: Catalog | undefined): boolean {
    return catalog !== undefined && segments.some((segment) => staffOf(catalog, segment.serviceId).length > 0);
}

/**
 * Where Continue on the service step goes, 1.3: staff, or when once the catalog has answered
 * and no segment's service lists any staff.
 */
export function afterService(segments: readonly Segment[], catalog: Catalog | undefined): Step {
    return catalog !== undefined && !anySegmentListsStaff(segments, catalog) ? 'when' : 'staff';
}

/**
 * Back's table, read against the step the flow started at, which is what the mount
 * attributes decided until a void made it service: a start at staff means the service was
 * preselected, a start at when means both were. Staff were a real choice when some segment's
 * service lists staff and the staff step was shown, which a mount naming both never does.
 */
export function backTargetOf(step: Step, segments: readonly Segment[], catalog: Catalog | undefined, initial: Step): Step | null {
    switch (step) {
        case 'staff':
            return initial === 'service' ? 'service' : null;
        case 'when':
            if (initial !== 'when' && anySegmentListsStaff(segments, catalog)) {
                return 'staff';
            }

            return initial === 'service' ? 'service' : null;
        case 'details':
            return 'when';
        case 'service':
        case 'review':
        case 'done':
            return null;
    }
}

/** A step the strip can show: every step before review. */
export type StripName = Exclude<Step, 'review' | 'done'>;

/** One step of the strip of 1.4 and where the customer stands to it. */
export interface StripStep {
    readonly step: StripName;
    readonly status: 'finished' | 'current' | 'ahead';
}

/** The strip's steps in journey order. */
const STRIP_ORDER: readonly StripName[] = ['service', 'staff', 'when', 'details'];

/** Whether a step is one the crumb and the summary card show on: every step before review. */
export function onStrip(step: Step): step is StripName {
    return step !== 'review' && step !== 'done';
}

/**
 * The strip, in the shape of the wizard's: this journey's steps before review, in order. The
 * finished ones are exactly the steps Back reaches from here, read off Back's table one
 * target at a time, so a press on one goes where pressing Back would and nowhere Back could
 * not; then the current one; then the ones ahead, staff among them only once some segment's
 * service lists staff. Review and done show no strip.
 */
export function stripOf(step: Step, segments: readonly Segment[], catalog: Catalog | undefined, initial: Step): readonly StripStep[] {
    if (!onStrip(step)) {
        return [];
    }

    const finished: StripStep[] = [];

    for (let back = backTargetOf(step, segments, catalog, initial); back !== null && onStrip(back); back = backTargetOf(back, segments, catalog, initial)) {
        finished.unshift({ step: back, status: 'finished' });
    }

    const ahead = STRIP_ORDER.slice(STRIP_ORDER.indexOf(step) + 1).filter((later) => later !== 'staff' || anySegmentListsStaff(segments, catalog));

    return [...finished, { step, status: 'current' }, ...ahead.map((later): StripStep => ({ step: later, status: 'ahead' }))];
}
