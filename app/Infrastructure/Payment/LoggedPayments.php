<?php

declare(strict_types=1);

namespace App\Infrastructure\Payment;

use App\Application\Payment\PaymentLog;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The payment layer's two lines on the application's log: a thrown connection test at warning
 * and a platform payment opened with no fee configured at error. What arrives is already struck
 * of every credential, and a log that cannot be written is swallowed here, so neither line
 * fails the test or the checkout it reports on.
 */
final readonly class LoggedPayments implements PaymentLog
{
    public const string CHECK_THREW = 'A payment connection test threw instead of answering; it is landed as a failed check';

    public const string FEE_UNSET = 'A platform connection opened a payment while no platform fee is configured; it carries no fee until the fee is set, at zero at launch';

    public function __construct(private LoggerInterface $log) {}

    public function checkThrew(string $provider, string $class, string $message): void
    {
        try {
            $this->log->warning(self::CHECK_THREW, ['provider' => $provider, 'exception' => $class, 'message' => $message]);
        } catch (Throwable) {
            // A log that cannot be written leaves the check landed as it is.
        }
    }

    public function feeUnset(): void
    {
        try {
            $this->log->error(self::FEE_UNSET);
        } catch (Throwable) {
            // A log that cannot be written opens the payment all the same.
        }
    }
}
