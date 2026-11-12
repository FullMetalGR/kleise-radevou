<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Application\Payment\PaymentLog;

/**
 * The payment layer's two lines in a unit test: each one written, kept in order, so a case
 * reads back what was said and how often.
 */
final class RecordingPaymentLog implements PaymentLog
{
    /** @var list<array{string, string, string}> each thrown check as [provider, class, message] */
    public array $threw = [];

    /** How many payments a platform connection opened with no fee configured. */
    public int $feeUnset = 0;

    public function checkThrew(string $provider, string $class, string $message): void
    {
        $this->threw[] = [$provider, $class, $message];
    }

    public function feeUnset(): void
    {
        $this->feeUnset++;
    }
}
