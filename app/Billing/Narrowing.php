<?php

declare(strict_types=1);

namespace App\Billing;

use App\Infrastructure\Db\TextArray;
use App\Tenancy\Tenant;
use InvalidArgumentException;

/**
 * The narrowing record as the floor screens read it: the slugs a resolve took away, from the
 * tenant row, filtered to the features this build knows so an arbitrary string never reaches an
 * admin screen. A plain slug list rather than an Entitlements, so it can name a feature whose
 * prerequisites are gone, and read whatever the subscription state: the record describes the
 * subscription, not the reader, and grace never writes one because grace keeps the whole set.
 *
 * ofLiteral() is the one decoder of the narrowed column: the screens read a tenant model through
 * it and the subscription sync the row it locked.
 */
final class Narrowing
{
    /**
     * @return list<string>
     */
    public static function of(Tenant $tenant): array
    {
        return self::ofLiteral($tenant->getAttributes()['narrowed'] ?? null);
    }

    /**
     * The column's text[] literal as the database hands it back: a value that is not one, or
     * none, names nothing, and each known feature is named once.
     *
     * @return list<string>
     */
    public static function ofLiteral(mixed $literal): array
    {
        try {
            $stored = is_string($literal) ? TextArray::decode($literal) : [];
        } catch (InvalidArgumentException) {
            return [];
        }

        $slugs = [];
        foreach ($stored as $slug) {
            if (Feature::tryFrom($slug) !== null && ! in_array($slug, $slugs, true)) {
                $slugs[] = $slug;
            }
        }

        return $slugs;
    }
}
