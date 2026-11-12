<?php

declare(strict_types=1);

namespace App\Tenancy;

use Illuminate\Container\Attributes\Config;

/**
 * The one place a slug offered as input is judged. The console command that provisions a tenant
 * and the signup form both call this, and nothing else decides. The tenant resolver asks only the
 * reserved half: a reserved label on the wire is refused before any row is looked up.
 */
final readonly class SlugRules
{
    /**
     * @param  list<string>  $reserved
     */
    public function __construct(
        #[Config('tenancy.reserved_slugs')]
        private array $reserved,
    ) {}

    /**
     * @throws InvalidSlug
     */
    public function check(string $input): Slug
    {
        $slug = Slug::of($input);

        if ($this->isReserved($slug)) {
            throw InvalidSlug::because(SlugProblem::Reserved);
        }

        // The column is citext, so the comparison ignores case whatever the stored row holds. A row
        // still provisioning is a signup in progress, and its slug frees within a day unless its
        // owner verifies, so the person is told so rather than that it is taken.
        $holder = Tenant::query()->where('slug', $slug->value)->value('status');

        if ($holder !== null) {
            throw InvalidSlug::because($holder === TenantStatus::Provisioning ? SlugProblem::HeldBySignup : SlugProblem::Taken);
        }

        return $slug;
    }

    public function isReserved(Slug $slug): bool
    {
        return in_array($slug->value, $this->reserved, true);
    }
}
