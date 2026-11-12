<?php

declare(strict_types=1);

namespace App\Billing;

/**
 * The set of features a tenant has. The same value gates a feature, is what a checkout builds
 * and is what an invoice line reads.
 *
 * One general predicate and no named ones: navigation and gates loop over data, and a named
 * predicate such as hasStaff() would force each of them into a match.
 *
 * A slug this build does not recognise is dropped on the way in. An unknown plan buys the floor
 * and nothing more, because granting everything to an identifier nobody deployed fails in the
 * direction that costs money.
 */
final readonly class Entitlements
{
    /**
     * @param  list<Feature>  $features
     */
    private function __construct(private array $features) {}

    public static function all(): self
    {
        return new self(Feature::cases());
    }

    public static function none(): self
    {
        return new self([]);
    }

    /**
     * @param  iterable<string>  $slugs
     */
    public static function fromSlugs(iterable $slugs): self
    {
        $features = [];

        foreach ($slugs as $slug) {
            $feature = Feature::tryFrom($slug);

            if ($feature !== null && ! in_array($feature, $features, true)) {
                $features[] = $feature;
            }
        }

        return new self($features);
    }

    public function allows(Feature $feature): bool
    {
        return in_array($feature, $this->features, true);
    }

    /**
     * The slugs in the order the enum declares its cases, so two equal sets spell alike.
     *
     * @return list<string>
     */
    public function toSlugs(): array
    {
        $slugs = [];

        foreach (Feature::cases() as $feature) {
            if ($this->allows($feature)) {
                $slugs[] = $feature->value;
            }
        }

        return $slugs;
    }
}
