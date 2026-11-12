<?php

declare(strict_types=1);

namespace App\Site;

use App\Billing\CatalogueUnreadable;
use App\Billing\Feature;
use App\Billing\Plan;
use App\Billing\PlanCatalogue;
use App\Billing\PlanOutline;
use App\Domain\Money\Money;
use Illuminate\Contracts\Translation\Translator;
use Psr\Log\LoggerInterface;

/**
 * The pricing page's plans: the plan catalogue read once for a page, so the page cannot show a
 * price billing does not charge and a changed catalogue changes the page with no edit to it.
 *
 * Each card, in the catalogue's order, carries the plan's key, its name as config/billing.php
 * gives it (Solo or Team), its monthly amount from the catalogue's display money spelled in the
 * page's language, and what it includes: the floor's lines of pricing.php, which every plan has,
 * then a line for each feature any plan grants, with it or without it. The table is drawn from the
 * same plans, a column each, its rows the floor's lines, included in every column, then one row
 * for each feature any plan grants, in Feature's order: no row is a list of the view's.
 *
 * A catalogue that cannot be sold from, a price identifier unset as in development, answers with
 * the plans in outline, the cards and the table, and no amount, and the failure is logged once
 * with the catalogue's own sentence, which never carries a secret: a marketing page down is worse
 * than a page without its price. A catalogue whose plans cannot even be named answers no card and
 * no table, logged the same once.
 */
final readonly class PricingSheet
{
    public const string CATALOGUE_LOGGED = 'The pricing page could not read the plan catalogue';

    public function __construct(
        private PlanCatalogue $catalogue,
        private Translator $translator,
        private LoggerInterface $log,
    ) {}

    /**
     * The cards and the table in a language.
     *
     * @return array{cards: list<array{key: string, name: string, amount: string|null, includes: list<string>}>, columns: list<string>, rows: list<array{label: string, cells: list<string>}>}
     */
    public function in(SiteLanguage $language): array
    {
        $plans = $this->plans();
        $features = self::granted($plans);
        $floor = $this->lines('pricing.includes', $language);
        $included = $this->text('pricing.table.included', $language);

        return [
            'cards' => array_map(fn (array $plan): array => [
                'key' => $plan[0]->key,
                'name' => $plan[0]->name,
                'amount' => $plan[1] === null ? null : SiteMoney::format($plan[1], $language),
                'includes' => [...$floor, ...array_map(fn (Feature $feature): string => $this->text(self::featureKey($feature, $plan[0]->features->allows($feature) ? 'with' : 'without'), $language), $features)],
            ], $plans),
            'columns' => array_map(fn (array $plan): string => $plan[0]->name, $plans),
            'rows' => $plans === [] ? [] : [
                ...array_map(fn (string $line): array => ['label' => $line, 'cells' => array_fill(0, count($plans), $included)], $floor),
                ...array_map(fn (Feature $feature): array => [
                    'label' => $this->text(self::featureKey($feature, 'label'), $language),
                    'cells' => array_map(fn (array $plan): string => $this->text(self::featureKey($feature, $plan[0]->features->allows($feature) ? 'has' : 'lacks'), $language), $plans),
                ], $features),
            ],
        ];
    }

    /**
     * The catalogue's plans in its order, each in outline beside its display price, or beside no
     * price where the catalogue cannot be sold from; the failure logged once.
     *
     * @return list<array{0: PlanOutline, 1: Money|null}>
     */
    private function plans(): array
    {
        try {
            return array_map(fn (Plan $plan): array => [$plan->outline(), $plan->price], $this->catalogue->plans());
        } catch (CatalogueUnreadable $cause) {
            $this->log->error(self::CATALOGUE_LOGGED, ['message' => $cause->getMessage()]);
        }

        try {
            return array_map(fn (PlanOutline $plan): array => [$plan, null], $this->catalogue->outlines());
        } catch (CatalogueUnreadable) {
            return [];
        }
    }

    /**
     * Every feature some plan grants, in Feature's order.
     *
     * @param  list<array{0: PlanOutline, 1: Money|null}>  $plans
     * @return list<Feature>
     */
    private static function granted(array $plans): array
    {
        return array_values(array_filter(Feature::cases(), function (Feature $feature) use ($plans): bool {
            foreach ($plans as $plan) {
                if ($plan[0]->features->allows($feature)) {
                    return true;
                }
            }

            return false;
        }));
    }

    private static function featureKey(Feature $feature, string $part): string
    {
        return 'pricing.features.'.$feature->value.'.'.$part;
    }

    private function text(string $key, SiteLanguage $language): string
    {
        $text = $this->translator->get($key, [], $language->value);

        return is_string($text) ? $text : $key;
    }

    /**
     * @return list<string>
     */
    private function lines(string $key, SiteLanguage $language): array
    {
        $lines = $this->translator->get($key, [], $language->value);

        return is_array($lines) ? array_values(array_filter($lines, is_string(...))) : [];
    }
}
