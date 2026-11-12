<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Application\Payment\PlatformConfiguration;
use App\Application\Payment\PlatformMode;
use SensitiveParameter;

/**
 * Platform mode's terms in a unit test: the keys and the fee the case gives, read as
 * configuration would be, and changed by the case between two asks. Nothing set by default, so
 * platform mode is offered by nobody and a platform connection answers the null provider.
 */
final class FixedPlatform implements PlatformConfiguration
{
    public function __construct(
        #[SensitiveParameter] private mixed $keys = [],
        private mixed $fee = null,
    ) {}

    public function charging(mixed $fee): self
    {
        $this->fee = $fee;

        return $this;
    }

    public function read(): PlatformMode
    {
        return PlatformMode::of($this->keys, $this->fee);
    }
}
