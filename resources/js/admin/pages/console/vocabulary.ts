/*
 * The console's words for the vocabularies its screens show, one map per vocabulary and one entry
 * per case, the list and the detail reading the same maps: the tenant's status, its subscription
 * state and the plan App\Platform\TenantPlan derives, floor and staff by the names the server
 * sends from the catalogue and trial, free and unlisted in the console's own words; and the
 * booking statuses the detail counts, typed as the machine's nine.
 */

/** The vocabulary of App\Tenancy\TenantStatus. */
export type TenantStatus = 'provisioning' | 'active' | 'suspended' | 'closing' | 'closed';

/** The vocabulary of App\Billing\SubscriptionState. */
export type SubscriptionState = 'active' | 'grace' | 'past_due' | 'canceled' | 'incomplete' | 'trial';

/** The vocabulary of App\Platform\TenantPlan. */
export type TenantPlan = 'trial' | 'free' | 'floor' | 'staff' | 'unlisted';

/** The vocabulary of App\Domain\Enum\BookingStatus, the nine the detail counts. */
export type BookingStatus = 'pending' | 'awaiting_approval' | 'awaiting_payment' | 'confirmed' | 'completed' | 'no_show' | 'cancelled' | 'rejected' | 'expired';

/** The plans of the vocabulary the console words itself; the other two are the catalogue's. */
type OwnPlan = 'trial' | 'free' | 'unlisted';

/** A catalogue plan of the vocabulary, floor or staff, shown by the name the server reads from the catalogue. */
type CataloguePlan = Exclude<TenantPlan, OwnPlan>;

/** The name the catalogue gives a plan, as the server sends it: floor as Solo, staff as Team. */
export type PlanName = { plan: CataloguePlan; name: string };

/** Each status in words, one per case of the vocabulary. */
export const STATUS_WORDS: Record<TenantStatus, string> = {
    provisioning: 'Provisioning',
    active: 'Active',
    suspended: 'Suspended',
    closing: 'Closing',
    closed: 'Closed',
};

/** Each subscription state in words, one per case of the vocabulary. */
export const STATE_WORDS: Record<SubscriptionState, string> = {
    active: 'Active',
    grace: 'Grace',
    past_due: 'Past due',
    canceled: 'Canceled',
    incomplete: 'Incomplete',
    trial: 'Trial',
};

/** Each plan the console words itself, one per case of the vocabulary the catalogue does not name. */
export const PLAN_WORDS: Record<OwnPlan, string> = {
    trial: 'Trial',
    free: 'Free',
    unlisted: 'Unlisted',
};

/** What a provisioning row's plan reads as: it has none until its build. */
export const NO_PLAN = 'None yet';

/**
 * A plan in words: the console's own word, or the name the server sends for a catalogue plan. A
 * catalogue plan the server names nothing for reads as its value; no row lists one, since the
 * server lists every paid row as unlisted where nobody can name the catalogue's plans.
 */
export function planWord(plan: TenantPlan, names: PlanName[]): string {
    switch (plan) {
        case 'trial':
        case 'free':
        case 'unlisted':
            return PLAN_WORDS[plan];
        default:
            return names.find((named) => named.plan === plan)?.name ?? plan;
    }
}
