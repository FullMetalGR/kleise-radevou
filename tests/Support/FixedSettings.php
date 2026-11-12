<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Application\Dto\HoldTtls;
use App\Application\Repository\SettingsRepository;
use App\Domain\Availability\Granularity;

/**
 * A settings repository answering the lifetimes and the grid it was given.
 */
final readonly class FixedSettings implements SettingsRepository
{
    public function __construct(private int $checkoutMinutes = 15, private int $approvalHours = 48, private int $granularityMinutes = 5) {}

    public function holdTtls(): HoldTtls
    {
        return new HoldTtls($this->checkoutMinutes, $this->approvalHours);
    }

    public function granularity(): Granularity
    {
        return Granularity::minutes($this->granularityMinutes);
    }
}
