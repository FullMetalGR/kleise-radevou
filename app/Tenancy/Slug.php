<?php

declare(strict_types=1);

namespace App\Tenancy;

/**
 * A tenant's subdomain label, validated and lowercased. The shape rules live here; the reserved
 * list and uniqueness, which need configuration and the database, live in SlugRules.
 */
final readonly class Slug
{
    /**
     * A DNS label allows 63 characters, but the schema name adds a seven-character prefix inside
     * PostgreSQL's 63-byte identifier limit, and an identifier past that limit is truncated
     * silently, so two long slugs could share a schema. The ceiling is set where both fit.
     */
    public const int MAX_LENGTH = 56;

    private const string SCHEMA_PREFIX = 'tenant_';

    private function __construct(public string $value) {}

    /**
     * Folds ASCII upper case to lower and refuses everything else the rules do not allow. Nothing
     * beyond that fold is normalised: a lookalike character is refused rather than mapped onto a
     * label that belongs to somebody else.
     *
     * @throws InvalidSlug
     */
    public static function of(string $input): self
    {
        $value = strtolower($input);

        if ($value === '') {
            throw InvalidSlug::because(SlugProblem::Empty);
        }

        if (preg_match('/[^a-z0-9-]/', $value) === 1) {
            throw InvalidSlug::because(SlugProblem::InvalidCharacter);
        }

        if (str_starts_with($value, '-')) {
            throw InvalidSlug::because(SlugProblem::LeadingHyphen);
        }

        if (str_ends_with($value, '-')) {
            throw InvalidSlug::because(SlugProblem::TrailingHyphen);
        }

        // Hyphens in the third and fourth positions mark a punycode label (RFC 5891), which a
        // browser would decode and display as something other than what was typed.
        if (substr($value, 2, 2) === '--') {
            throw InvalidSlug::because(SlugProblem::PunycodeForm);
        }

        if (strlen($value) > self::MAX_LENGTH) {
            throw InvalidSlug::because(SlugProblem::TooLong);
        }

        return new self($value);
    }

    /**
     * The PostgreSQL schema holding this tenant's data. Hyphens become underscores and the prefix
     * keeps a digit-led slug a valid identifier, keeps every tenant schema out of the names
     * PostgreSQL reserves for itself, and keeps the mapping one-to-one, since an underscore never
     * appears in a slug.
     */
    public function schemaName(): string
    {
        return self::SCHEMA_PREFIX.str_replace('-', '_', $this->value);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
