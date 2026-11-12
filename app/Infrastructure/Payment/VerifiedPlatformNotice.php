<?php

declare(strict_types=1);

namespace App\Infrastructure\Payment;

use App\Application\Payment\PlatformNotice;

/**
 * A notification on the platform's endpoint whose signature the platform's own secret vouched
 * for, handed from the route's access check to its controller.
 */
final readonly class VerifiedPlatformNotice
{
    public function __construct(
        public string $provider,
        public PlatformNotice $notice,
    ) {}
}
