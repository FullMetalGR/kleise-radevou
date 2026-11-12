<?php

declare(strict_types=1);

namespace App\Billing;

use RuntimeException;

/**
 * The billing catalogue in configuration cannot be read as plans. A fault on our side, so the
 * sync that meets it changes nothing on the tenant row and logs: a resolve that throws grants
 * what is already stored. Each constructor names what could not be read, and never a value
 * the provider's secrets hold.
 */
final class CatalogueUnreadable extends RuntimeException
{
    public static function noPlans(): self
    {
        return new self('The billing catalogue holds no list of plans.');
    }

    public static function noSuchPlan(string $key): self
    {
        return new self(sprintf('The billing catalogue has no plan "%s".', $key));
    }

    public static function notAMap(string $plan): self
    {
        return new self(sprintf('The billing plan "%s" is not a map.', $plan));
    }

    /**
     * A plan sold under nothing: its price unset or blank, which the environment leaves so until
     * somebody sets it. Refused rather than read as matching nothing, which would sell every
     * subscriber the floor without a word.
     */
    public static function noPrice(string $plan): self
    {
        return new self(sprintf('The billing plan "%1$s" is sold under no price identifier: billing.plans.%1$s.price is unset or blank in the environment.', $plan));
    }

    /**
     * A plan with no name to be shown by: config/billing.php is the names' one home, so a plan it
     * names nothing for is a deploy mistake, refused rather than shown by its key.
     */
    public static function noName(string $plan): self
    {
        return new self(sprintf('The billing plan "%s" carries no name to be shown by.', $plan));
    }

    public static function priceNotText(string $plan): self
    {
        return new self(sprintf('The billing plan "%s" names no price identifier as text.', $plan));
    }

    public static function sharedPrice(string $price): self
    {
        return new self(sprintf('Two billing plans are sold under the price "%s".', $price));
    }

    public static function noFeatureList(string $plan): self
    {
        return new self(sprintf('The billing plan "%s" grants no list of features.', $plan));
    }

    public static function unknownFeature(string $plan, string $feature): self
    {
        return new self(sprintf('The billing plan "%s" grants "%s", which is not a feature this build knows.', $plan, $feature));
    }

    public static function noDisplayPrice(string $plan): self
    {
        return new self(sprintf('The billing plan "%s" displays no whole amount in minor units with a currency.', $plan));
    }

    public static function graceLength(): self
    {
        return new self('The billing grace length is not a whole number of days above nought.');
    }
}
