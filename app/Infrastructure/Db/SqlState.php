<?php

declare(strict_types=1);

namespace App\Infrastructure\Db;

use PDOException;
use Psr\Log\LogLevel;
use Throwable;

/**
 * The error table of the write protocol, as code: the SQLSTATEs a booking transaction can end
 * with, and for each one whether it is retried, which typed failure it surfaces as, and how loudly
 * the runner reports it. Detection is by code alone, never by message text: the message is
 * whatever language the cluster's lc_messages selects, and a correctness path may not depend on a
 * locale.
 *
 * Two states retry, and only two. 55P03 is left out on purpose: a lock timeout is contention
 * somebody should see, and a blind retry would hide a rising rate behind slower requests.
 */
enum SqlState: string
{
    case SerializationFailure = '40001';
    case DeadlockDetected = '40P01';
    case LockNotAvailable = '55P03';
    case QueryCanceled = '57014';
    case ExclusionViolation = '23P01';
    case UniqueViolation = '23505';
    case CardinalityViolation = '21000';
    case InFailedSqlTransaction = '25P02';

    private const string PATTERN = '/^[0-9A-Z]{5}$/';

    /**
     * The state an exception carries when it is one of the table's; null for any other error.
     */
    public static function of(Throwable $exception): ?self
    {
        $code = self::codeOf($exception);

        return $code === null ? null : self::tryFrom($code);
    }

    /**
     * The first SQLSTATE in the chain, outermost first. PDO carries it in errorInfo and the
     * framework's QueryException copies it into the code as well; a domain exception wrapping
     * either is reached through getPrevious().
     */
    public static function codeOf(Throwable $exception): ?string
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof PDOException) {
                $info = $current->errorInfo[0] ?? null;

                if (is_string($info) && preg_match(self::PATTERN, $info) === 1) {
                    return $info;
                }
            }

            $code = (string) $current->getCode();

            if (preg_match(self::PATTERN, $code) === 1) {
                return $code;
            }
        }

        return null;
    }

    public function retries(): bool
    {
        return $this === self::SerializationFailure || $this === self::DeadlockDetected;
    }

    /**
     * The typed failure the runner surfaces once this state has ended the transaction for good,
     * which for the two retried states means after the last attempt.
     */
    public function failure(PDOException $cause, string $tenant, int $attempts): TransactionFailure
    {
        return match ($this) {
            self::SerializationFailure, self::DeadlockDetected => RetriesExhausted::after($attempts, $this, $tenant, $cause),
            self::LockNotAvailable => Busy::on($tenant, $cause),
            self::QueryCanceled => BudgetExceeded::on($tenant, $cause),
            self::ExclusionViolation => ExclusionViolation::on($tenant, $cause),
            self::UniqueViolation => UniqueViolation::on($tenant, $cause),
            self::CardinalityViolation => CardinalityViolation::on($tenant, $cause),
            self::InFailedSqlTransaction => AbortedTransactionReused::on($tenant, $cause),
        };
    }

    /**
     * The PSR log level the runner reports the failure at. The log is the counter and the
     * alarm: busy at notice is the count a rising contention rate shows up in, a blown budget
     * alerts at critical, and a protocol hole at alert, one level louder, because the
     * constraint just caught what the locks were meant to make impossible.
     */
    public function logLevel(): string
    {
        return match ($this) {
            self::SerializationFailure, self::DeadlockDetected => LogLevel::WARNING,
            self::LockNotAvailable => LogLevel::NOTICE,
            self::QueryCanceled => LogLevel::CRITICAL,
            self::ExclusionViolation, self::UniqueViolation => LogLevel::ALERT,
            self::CardinalityViolation, self::InFailedSqlTransaction => LogLevel::ERROR,
        };
    }
}
