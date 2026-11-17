<?php

declare(strict_types=1);

namespace App\Http\Setup\Steps;

use App\Http\Setup\SetupStep;
use App\Setup\SetupProgress;
use App\Tenancy\Slug;
use App\Tenancy\Tenant;
use App\Tenancy\TenantUrls;

/**
 * The Booking link step: the hosted page's address with Copy and Open, and Finish. Copy here
 * records nothing and Finish posts the wizard's own route, so the step adds no write of its
 * own.
 */
final readonly class LinkStep implements SetupStep
{
    public function __construct(private TenantUrls $urls) {}

    public function sections(): array
    {
        return [];
    }

    /**
     * @return array{hosted_url: string}
     */
    public function data(Tenant $tenant, SetupProgress $progress): array
    {
        return ['hosted_url' => $this->urls->hostedPage(Slug::of($tenant->slug))];
    }

    public static function routes(): void {}
}
