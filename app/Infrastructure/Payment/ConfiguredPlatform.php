<?php

declare(strict_types=1);

namespace App\Infrastructure\Payment;

use App\Application\Payment\PlatformConfiguration;
use App\Application\Payment\PlatformMode;
use Illuminate\Contracts\Config\Repository;

/**
 * Platform mode's terms read from configuration each time they are asked for:
 * payments.platform, the keys by provider, and payments.platform_fee, the fee. Never a tenant
 * row and never billing's configuration.
 */
final readonly class ConfiguredPlatform implements PlatformConfiguration
{
    public function __construct(private Repository $config) {}

    public function read(): PlatformMode
    {
        return PlatformMode::of($this->config->get('payments.platform'), $this->config->get('payments.platform_fee'));
    }
}
