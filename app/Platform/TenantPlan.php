<?php

declare(strict_types=1);

namespace App\Platform;

use App\Billing\CatalogueUnreadable;
use App\Billing\Plan;
use App\Billing\PlanCatalogue;
use App\Billing\PlanChange;
use App\Billing\SubscriptionState;
use App\Infrastructure\Db\SqlText;
use App\Tenancy\TenantStatus;
use Closure;

/**
 * The plan a tenant is on as the console lists it: derived from the tenant row and its
 * subscriptions row or none, never stored. The questions are asked in one order:
 *
 * - a provisioning row has no plan yet;
 * - trial while the subscription state is trial;
 * - free while the subscription state is active with no subscriptions row and no trial end, the
 *   shape tenant:provision writes and the console's free tenant takes, so a development tenant
 *   and a free tenant are one thing;
 * - otherwise the catalogue plan the stored price reference names, as the Billing screen reads it
 *   through PlanChange::currentOf, floor or staff;
 * - and unlisted where none does: a subscriptions row with no price reference, a price no plan
 *   names, a trial that ended unpaid, and a catalogue nobody can read, which answers unlisted
 *   rather than throwing, since a list of every tenant must not fall over on a deploy mistake the
 *   sync already logs. A catalogue plan the console has no word for lists as unlisted too.
 *
 * The derivation is written twice, once over a row's values for a screen to show and once as SQL
 * over the list's join for its filter to narrow by, and the two sit here together so a change to
 * one is made to the other; the console's feature suite holds them to one answer for every shape.
 * A reader of many rows, the list's page, parses the catalogue's plans once through plansOf() and
 * hands them to among() for each row and to sql() for its filter, since the catalogue builds every
 * plan afresh at each question.
 *
 * Floor and staff are shown by the names the catalogue gives them, Solo and Team from
 * config/billing.php, the one home, which names() hands the list and the detail; the console words
 * trial, free and unlisted itself.
 */
enum TenantPlan: string
{
    case Trial = 'trial';
    case Free = 'free';
    case Floor = 'floor';
    case Staff = 'staff';
    case Unlisted = 'unlisted';

    /**
     * The plan of a tenant row: its status, its subscription state, whether its trial end is set,
     * whether a subscriptions row exists beside it and its stored price reference.
     */
    public static function of(TenantStatus $status, SubscriptionState $state, bool $trialEnd, bool $subscriptionRow, ?string $planRef, PlanCatalogue $catalogue): ?self
    {
        return self::among($status, $state, $trialEnd, $subscriptionRow, $planRef, fn (): ?array => self::plansOf($catalogue));
    }

    /**
     * The plan of a tenant row, as of() derives it, over the catalogue's plans as the reader
     * holds them: asked for on a paid row alone, so trial, free and a provisioning row never read
     * the catalogue, and null where nobody can read it, which lists a paid row as unlisted.
     *
     * @param  Closure(): (list<Plan>|null)  $plans
     */
    public static function among(TenantStatus $status, SubscriptionState $state, bool $trialEnd, bool $subscriptionRow, ?string $planRef, Closure $plans): ?self
    {
        if ($status === TenantStatus::Provisioning) {
            return null;
        }

        if ($state === SubscriptionState::Trial) {
            return self::Trial;
        }

        if (self::free($state, $trialEnd, $subscriptionRow)) {
            return self::Free;
        }

        $read = $plans();

        return $read === null ? self::Unlisted : self::named(PlanChange::currentOf($read, $planRef)?->key);
    }

    /**
     * The catalogue's plans parsed once, or null where nobody can read the catalogue: a list of
     * every tenant must not fall over on a deploy mistake the sync already logs.
     *
     * @return list<Plan>|null
     */
    public static function plansOf(PlanCatalogue $catalogue): ?array
    {
        try {
            return $catalogue->plans();
        } catch (CatalogueUnreadable) {
            return null;
        }
    }

    /**
     * Whether a row past provisioning is in the free shape: the subscription state active, with
     * no subscriptions row and no trial end, exactly what tenant:provision and the console's
     * free tenant write and nothing else moves, since no billing write reaches a row with no
     * customer. The one predicate of the shape: the plan above reads it, the console's Change
     * features admits this shape alone, the Billing screen says it is free of charge on it, and
     * the admin's shared props tell the page so. A frozen state with no subscriptions row, which
     * no writer leaves, is billing's and never free: "nothing to pay" would hide the freeze.
     */
    public static function free(SubscriptionState $state, bool $trialEnd, bool $subscriptionRow): bool
    {
        return $state === SubscriptionState::Active && ! $subscriptionRow && ! $trialEnd;
    }

    /**
     * The same derivation as a CASE over the list's join, the tenant row as `tenant` and its
     * subscriptions row as `subscription`, with what it binds: each catalogue plan's price in the
     * catalogue's order, so the first plan whose price the reference holds answers, as currentOf
     * reads it, over the plans plansOf() parsed, none where nobody can read the catalogue. Null
     * for a provisioning row.
     *
     * @param  list<Plan>|null  $plans
     * @return array{0: string, 1: list<string>}
     */
    public static function sql(?array $plans): array
    {
        $plans ??= [];
        $priced = '';
        $prices = [];

        foreach ($plans as $plan) {
            $priced .= sprintf(" when ? = any(string_to_array(tenant.plan_ref, ',')) then %s", SqlText::literal(self::named($plan->key)));
            $prices[] = $plan->priceId;
        }

        return [
            sprintf(
                '(case when tenant.status = %s then null when tenant.subscription_state = %s then %s'
                .' when tenant.subscription_state = %s and subscription.tenant_id is null and tenant.trial_ends_at is null then %s%s else %s end)',
                SqlText::literal(TenantStatus::Provisioning),
                SqlText::literal(SubscriptionState::Trial),
                SqlText::literal(self::Trial),
                SqlText::literal(SubscriptionState::Active),
                SqlText::literal(self::Free),
                $priced,
                SqlText::literal(self::Unlisted),
            ),
            $prices,
        ];
    }

    /**
     * The names floor and staff are shown by, as the catalogue names them and in its order, for
     * the list's column and filter and the detail. Read from the catalogue's outlines, so a price
     * unset as in development leaves the names standing while every paid row lists as unlisted; a
     * catalogue nobody can name and a catalogue plan the console has no word for name nothing.
     *
     * @return list<array{plan: string, name: string}>
     */
    public static function names(PlanCatalogue $catalogue): array
    {
        try {
            $outlines = $catalogue->outlines();
        } catch (CatalogueUnreadable) {
            return [];
        }

        $names = [];

        foreach ($outlines as $outline) {
            $plan = self::named($outline->key);

            if ($plan !== self::Unlisted) {
                $names[] = ['plan' => $plan->value, 'name' => $outline->name];
            }
        }

        return $names;
    }

    /** The console's word for a catalogue plan by its key, unlisted for none or one it has no word for. */
    private static function named(?string $key): self
    {
        return match ($key) {
            'floor' => self::Floor,
            'staff' => self::Staff,
            default => self::Unlisted,
        };
    }
}
