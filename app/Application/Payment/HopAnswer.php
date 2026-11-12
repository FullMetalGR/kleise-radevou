<?php

declare(strict_types=1);

namespace App\Application\Payment;

/**
 * The pay hop's answer: the verdict, and the provider's page where the verdict is Provider.
 */
final readonly class HopAnswer
{
    private function __construct(public HopVerdict $verdict, public ?string $url = null) {}

    public static function provider(string $url): self
    {
        return new self(HopVerdict::Provider, $url);
    }

    public static function of(HopVerdict $verdict): self
    {
        return new self($verdict);
    }
}
