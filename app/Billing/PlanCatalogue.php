<?php

declare(strict_types=1);

namespace App\Billing;

use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use Illuminate\Container\Attributes\Config;
use InvalidArgumentException;

/**
 * The plans the platform sells and the grace length, as config/billing.php holds them: each plan
 * names the name it is shown by, Solo or Team, the provider price it is sold under, read from the
 * environment, the feature slugs it grants and the price the Billing screen and the site's
 * pricing page display for it. It is handed those two keys and nothing else of the billing
 * configuration, so no refusal it words can carry the provider's secrets.
 *
 * The names live here alone: the Billing screen, the console's plan column and the pricing page
 * read them through Plan, or through PlanOutline where the plans cannot be sold from, and nothing
 * derives a plan's name from its features.
 *
 * It holds the configuration as it was handed over and reads it at every question, so a
 * catalogue nobody can read is refused at the question, where the sync catches it and changes
 * nothing, and never at boot. It refuses rather than guesses: a feature slug this build does not
 * know is a deploy mistake, and filtering it out would sell a plan short without a word. A plan
 * whose price the environment leaves unset or blank is refused the same way, since reading it
 * as matching nothing would resolve every subscriber of that plan to the floor in silence.
 *
 * A price identifier no plan names contributes nothing. An unknown plan is the floor and never
 * the whole product, because granting everything to an identifier nobody deployed fails in the
 * direction that costs money.
 */
final readonly class PlanCatalogue
{
    public function __construct(
        #[Config('billing.plans')] private mixed $plans,
        #[Config('billing.grace_days')] private mixed $graceDays,
    ) {}

    /**
     * @return list<Plan>
     *
     * @throws CatalogueUnreadable
     */
    public function plans(): array
    {
        if (! is_array($this->plans) || $this->plans === []) {
            throw CatalogueUnreadable::noPlans();
        }

        $read = [];
        $prices = [];

        foreach ($this->plans as $key => $plan) {
            $plan = self::planOf((string) $key, $plan);

            if (in_array($plan->priceId, $prices, true)) {
                throw CatalogueUnreadable::sharedPrice($plan->priceId);
            }

            $prices[] = $plan->priceId;
            $read[] = $plan;
        }

        return $read;
    }

    /**
     * Every plan in outline, its key, its name and its features, read without the price it is
     * sold under or the amount it displays: what a page names the plans by while plans() refuses
     * a price identifier unset as in development, so the site's pricing page shows its cards with
     * no amount and the console still names the plans. A plan that cannot be named is refused as
     * plans() refuses it, so whatever this refuses plans() refuses too.
     *
     * @return list<PlanOutline>
     *
     * @throws CatalogueUnreadable
     */
    public function outlines(): array
    {
        if (! is_array($this->plans) || $this->plans === []) {
            throw CatalogueUnreadable::noPlans();
        }

        $read = [];

        foreach ($this->plans as $key => $plan) {
            $read[] = self::outlineOf((string) $key, $plan);
        }

        return $read;
    }

    /**
     * @throws CatalogueUnreadable
     */
    public function plan(string $key): Plan
    {
        foreach ($this->plans() as $plan) {
            if ($plan->key === $key) {
                return $plan;
            }
        }

        throw CatalogueUnreadable::noSuchPlan($key);
    }

    /**
     * The features a subscription's line items buy: the union of the plans their prices name.
     * A price no plan names contributes nothing, and neither does a line of nought.
     *
     * @param  list<SnapshotItem>  $items
     *
     * @throws CatalogueUnreadable
     * @throws MalformedSnapshot
     */
    public function entitlementsFor(array $items): Entitlements
    {
        $plans = $this->plans();
        $slugs = [];

        foreach ($items as $position => $item) {
            if ($item->priceId === '' || $item->quantity < 0) {
                throw MalformedSnapshot::line($position + 1);
            }

            foreach ($plans as $plan) {
                if ($item->quantity > 0 && $plan->priceId === $item->priceId) {
                    array_push($slugs, ...$plan->features->toSlugs());
                }
            }
        }

        return Entitlements::fromSlugs($slugs);
    }

    /**
     * How many days a failed charge's grace window runs.
     *
     * @throws CatalogueUnreadable
     */
    public function graceDays(): int
    {
        if (! is_int($this->graceDays) || $this->graceDays < 1) {
            throw CatalogueUnreadable::graceLength();
        }

        return $this->graceDays;
    }

    private static function planOf(string $key, mixed $plan): Plan
    {
        if (! is_array($plan)) {
            throw CatalogueUnreadable::notAMap($key);
        }

        $outline = self::outlineOf($key, $plan);
        $price = $plan['price'] ?? null;

        if ($price === null || $price === '') {
            throw CatalogueUnreadable::noPrice($key);
        }

        if (! is_string($price)) {
            throw CatalogueUnreadable::priceNotText($key);
        }

        return new Plan($key, $outline->name, $price, $outline->features, self::displayOf($key, $plan['display'] ?? null));
    }

    /**
     * A plan's key, name and features, the part every reader of the catalogue needs, refused
     * where it is not a map, names nothing to be shown by, or grants a feature this build does
     * not know.
     */
    private static function outlineOf(string $key, mixed $plan): PlanOutline
    {
        if (! is_array($plan)) {
            throw CatalogueUnreadable::notAMap($key);
        }

        $name = $plan['name'] ?? null;

        if (! is_string($name) || trim($name) === '') {
            throw CatalogueUnreadable::noName($key);
        }

        return new PlanOutline($key, $name, self::featuresOf($key, $plan['features'] ?? null));
    }

    private static function featuresOf(string $key, mixed $features): Entitlements
    {
        if (! is_array($features) || ! array_is_list($features)) {
            throw CatalogueUnreadable::noFeatureList($key);
        }

        $slugs = [];

        foreach ($features as $slug) {
            if (! is_string($slug) || Feature::tryFrom($slug) === null) {
                throw CatalogueUnreadable::unknownFeature($key, is_scalar($slug) ? (string) $slug : get_debug_type($slug));
            }

            $slugs[] = $slug;
        }

        return Entitlements::fromSlugs($slugs);
    }

    private static function displayOf(string $key, mixed $display): Money
    {
        $amount = is_array($display) ? ($display['amount'] ?? null) : null;
        $currency = is_array($display) ? ($display['currency'] ?? null) : null;

        try {
            if (is_int($amount) && $amount >= 0 && is_string($currency)) {
                return new Money($amount, Currency::of($currency));
            }
        } catch (InvalidArgumentException) {
            // Falls through to the refusal below: a currency that is not three capitals.
        }

        throw CatalogueUnreadable::noDisplayPrice($key);
    }
}
