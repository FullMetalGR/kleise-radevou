<?php

declare(strict_types=1);

namespace App\Infrastructure\Db;

use App\Application\Dto\HoldTtls;
use App\Application\Repository\SettingsRepository;
use App\Domain\Availability\Granularity;
use App\Tenancy\Tenant;

/**
 * The settings port of one tenant over the settings reader: one row read by schema, outside
 * any transaction, the columns a use case needs handed on. Built per tenant rather than
 * resolved from the container, so a use case holding one can never read another tenant's row.
 */
final readonly class PostgresSettingsRepository implements SettingsRepository
{
    public function __construct(
        private SettingsReader $reader,
        private Tenant $tenant,
    ) {}

    public function holdTtls(): HoldTtls
    {
        $settings = $this->reader->read($this->tenant);

        return new HoldTtls($settings->checkoutTtlMin, $settings->approvalTtlHours);
    }

    public function granularity(): Granularity
    {
        return Granularity::minutes($this->reader->read($this->tenant)->granularityMin);
    }
}
