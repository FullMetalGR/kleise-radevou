<?php

declare(strict_types=1);

namespace App\Application\Payment;

/**
 * How an inbound notification is verified: the header the provider signs its
 * notification in. The verification itself is the adapter's and fails closed.
 */
final readonly class WebhookSignature
{
    private function __construct(public string $header) {}

    public static function signedHeader(string $header): self
    {
        return new self($header);
    }
}
