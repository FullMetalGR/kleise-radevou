<?php

declare(strict_types=1);

namespace App\Application\Repository;

use App\Application\Dto\HoldTtls;
use App\Domain\Availability\Granularity;

/**
 * The port a use case reads the tenant's settings through, bound to one tenant by whoever built
 * it. As much of the row as a use case needs: the hold lifetimes for now, the rest as the use
 * cases that read them land.
 */
interface SettingsRepository
{
    public function holdTtls(): HoldTtls;

    /**
     * The grid every start of this tenant lies on. A use case that needs to know where the slots
     * fall without asking what is free reads it here: the availability port answers it too, on
     * the side of a mask read, and a move is placing a chain it already knows the staff of and
     * has nothing to ask the masks before the lock.
     */
    public function granularity(): Granularity;
}
