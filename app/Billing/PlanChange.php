<?php

declare(strict_types=1);

namespace App\Billing;

use App\Infrastructure\Db\StaffRecords;
use App\Tenancy\Tenant;
use Illuminate\Database\DatabaseManager;
use Psr\Log\LoggerInterface;

/**
 * Change plan on the Billing screen: the subscription's items become the chosen plan's through
 * the port, and the sync applies the snapshot the provider answers with at once, so the redirect
 * back already carries the new set and the webhook that follows finds nothing to change. Adding
 * staff is immediate; removing it is refused before the provider is asked while more than one
 * active staff member exists, the implicit one counted, and with exactly one it goes through the
 * same way.
 *
 * The revision the sync is handed is the one read before the provider is asked, so a webhook's
 * newer snapshot committed meanwhile is never overwritten by this older answer: the sync answers
 * Stale and writes nothing, and the change then re-reads the row and asks the provider for the
 * subscription as it stands, applying that at the new revision. A row that moves again, or a
 * provider that cannot answer the re-read, leaves the change to the webhook, which the provider
 * sends for the change already made; the caller words that as on its way, never as a failure and
 * never as the floor.
 *
 * Both writes hand the sync the set the owner chose, the first and the re-read alike, since the
 * re-read is still the owner's change: what the change takes that the chosen set lacks is what
 * the owner asked to remove, and the sync records none of it, while what it takes that the chosen
 * set carries is recorded as any take is. So no banner follows an owner's own removal of staff. A
 * change the webhook lands in its place is a snapshot nobody here asked for and is recorded as
 * any other, so a banner still follows the removal where the change's own write failed or
 * answered Unresolved, or its re-read failed or met a row that moved again.
 *
 * The handler refuses what the screen does not offer, and in the screen's order: no subscriptions
 * row, a catalogue nobody can read, a price no catalogue plan carries, then a state that takes no
 * plan change, which is a first charge still pending or a subscription that has ended. A plan the
 * subscription is already on asks the provider nothing and answers no outcome, so nothing is
 * worded as changed.
 */
final readonly class PlanChange
{
    public const string REREAD_FAILED = 'A plan change landed at the billing provider and the re-read after the row moved failed; the webhook applies it';

    public function __construct(
        private DatabaseManager $db,
        private PlanCatalogue $catalogue,
        private BillingProvider $provider,
        private SubscriptionSync $sync,
        private StaffRecords $staff,
        private LoggerInterface $log,
    ) {}

    /**
     * Changes the tenant's subscription to the plan the key names. The outcome is null where the
     * subscription is already on that plan and the provider was not asked. The plan it moved from
     * is answered beside the plan it moved to, so a caller wording the change compares the two
     * rather than reading the plan asked for alone.
     *
     * @return array{from: Plan, plan: Plan, outcome: SyncOutcome|null}
     *
     * @throws PlanChangeRefused before the provider is asked
     * @throws CatalogueUnreadable before the provider is asked
     * @throws BillingProviderFailed when the provider could not change the plan; nothing is written
     */
    public function change(Tenant $tenant, string $key): array
    {
        $row = SubscriptionRow::of($this->db, $tenant);

        if ($row === null || $row->subscriptionId === null) {
            throw PlanChangeRefused::noSubscription();
        }

        $plans = $this->catalogue->plans();
        $current = self::currentOf($plans, $tenant->plan_ref) ?? throw PlanChangeRefused::unlisted();
        $refused = self::refusalIn(SubscriptionState::from($tenant->subscription_state));

        if ($refused !== null) {
            throw $refused;
        }

        $plan = self::planOf($plans, $key);

        if ($plan->key === $current->key) {
            return ['from' => $current, 'plan' => $plan, 'outcome' => null];
        }

        $refused = self::downgradeRefusal($plan, $this->staff->activeCount($tenant));

        if ($refused !== null) {
            throw $refused;
        }

        $outcome = $this->sync->apply($this->provider->changePlan($row->subscriptionId, $plan), $row->revision, $plan->features);

        if ($outcome === SyncOutcome::Stale) {
            $outcome = $this->reread($tenant, $row->subscriptionId, $plan->features);
        }

        return ['from' => $current, 'plan' => $plan, 'outcome' => $outcome];
    }

    /**
     * Why a subscription in this state takes no plan change, or null when it takes one: active,
     * grace and past due do, the billing routes being the way back. A trial has no subscription
     * to change, the plan being chosen at the checkout that ends it, so it is refused as a row
     * naming none is.
     */
    public static function refusalIn(SubscriptionState $state): ?PlanChangeRefused
    {
        return match ($state) {
            SubscriptionState::Active, SubscriptionState::Grace, SubscriptionState::PastDue => null,
            SubscriptionState::Incomplete => PlanChangeRefused::chargePending(),
            SubscriptionState::Canceled => PlanChangeRefused::ended(),
            SubscriptionState::Trial => PlanChangeRefused::noSubscription(),
        };
    }

    /**
     * Why moving to this plan is refused with this many active staff rows, or null when it is
     * not: a plan without the staff feature takes one active member, the implicit one counted.
     * The screen's note beside Remove Staff and the handler read this one threshold.
     */
    public static function downgradeRefusal(Plan $target, int $activeStaff): ?PlanChangeRefused
    {
        return self::featureSetRefusal($target->features, $activeStaff);
    }

    /**
     * The same threshold over a feature set rather than a plan: the console's Change features on
     * a free tenant, which has no plan, reads it.
     */
    public static function featureSetRefusal(Entitlements $target, int $activeStaff): ?PlanChangeRefused
    {
        return self::fits($target, $activeStaff) ? null : PlanChangeRefused::downgrade($activeStaff);
    }

    /**
     * The same threshold at the checkout, where a plan is chosen rather than changed to, worded
     * for the state the choice is offered in: a trial, which pauses nothing, or canceled, where
     * setup changes are paused and staff cannot be deactivated. The screen's sentence beside
     * the refused plan and the checkout's own refusal read this one.
     */
    public static function choiceRefusal(Plan $target, int $activeStaff, SubscriptionState $state): ?PlanChangeRefused
    {
        return self::fits($target->features, $activeStaff) ? null : PlanChangeRefused::unchoosable($target->name, $activeStaff, ! $state->isActive());
    }

    /**
     * Whether a feature set takes this many active staff rows: one with the staff feature takes
     * any number, one without it a single member, the implicit one counted.
     */
    private static function fits(Entitlements $target, int $activeStaff): bool
    {
        return $target->allows(Feature::Staff) || $activeStaff <= 1;
    }

    /**
     * The catalogue plan the tenant's plan_ref names, the sync having written the price
     * identifiers of the subscription's items there, or null when none of them is a plan's.
     *
     * @param  list<Plan>  $plans
     */
    public static function currentOf(array $plans, ?string $planRef): ?Plan
    {
        $prices = explode(',', $planRef ?? '');

        foreach ($plans as $plan) {
            if (in_array($plan->priceId, $prices, true)) {
                return $plan;
            }
        }

        return null;
    }

    /**
     * The catalogue plan the key names. The catalogue is read by the caller, which meets
     * CatalogueUnreadable there; a key no plan carries is PlanChangeRefused::unknownPlan().
     *
     * @param  list<Plan>  $plans
     *
     * @throws PlanChangeRefused
     */
    private static function planOf(array $plans, string $key): Plan
    {
        foreach ($plans as $plan) {
            if ($plan->key === $key) {
                return $plan;
            }
        }

        throw PlanChangeRefused::unknownPlan();
    }

    private function reread(Tenant $tenant, string $subscriptionId, Entitlements $chosen): SyncOutcome
    {
        $moved = SubscriptionRow::of($this->db, $tenant);

        if ($moved === null) {
            return SyncOutcome::Stale;
        }

        try {
            return $this->sync->refresh($subscriptionId, $moved->revision, $chosen);
        } catch (BillingProviderFailed $cause) {
            $this->log->warning(self::REREAD_FAILED, ['tenant' => $tenant->slug, 'message' => $cause->getMessage()]);

            return SyncOutcome::Stale;
        }
    }
}
