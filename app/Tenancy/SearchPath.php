<?php

declare(strict_types=1);

namespace App\Tenancy;

/**
 * The search path that selects a tenant's schema, as the value a SET search_path takes.
 *
 * The extensions' schema is listed after the tenant's on purpose. citext and btree_gist live in
 * public, and PostgreSQL resolves operators through the search path at query time: with only the
 * tenant schema on the path, "email = 'x'" on a citext column silently falls back to the text
 * operator and compares case-sensitively, matching nothing. Listing public second keeps the
 * tenant schema first, which is what current_schema() reports and what every assertion checks.
 */
final readonly class SearchPath
{
    public const string EXTENSIONS_SCHEMA = 'public';

    public static function forSchema(string $schemaName): string
    {
        return sprintf('"%s", "%s"', $schemaName, self::EXTENSIONS_SCHEMA);
    }
}
