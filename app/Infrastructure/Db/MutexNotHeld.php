<?php

declare(strict_types=1);

namespace App\Infrastructure\Db;

use App\Domain\Booking\LockKey;
use RuntimeException;

/**
 * The lock statement returned fewer rows than the key set has pairs. Under READ COMMITTED a lock
 * over N rows can come back short with no error raised, and a transaction that went on would
 * believe it holds mutexes it does not hold. Raised inside the transaction so the runner rolls
 * it back; not a driver error, so nothing retries or maps it.
 */
final class MutexNotHeld extends RuntimeException
{
    /**
     * @param  list<LockKey>  $missing
     */
    private function __construct(
        public readonly int $expected,
        public readonly int $returned,
        public readonly array $missing,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * @param  list<LockKey>  $missing  the pairs no returned row named
     */
    public static function short(int $expected, int $returned, array $missing): self
    {
        return new self($expected, $returned, $missing, sprintf(
            'The lock statement returned %d row%s for %d staff-day%s, so the transaction holds no mutex it can trust. Missing: %s.',
            $returned,
            $returned === 1 ? '' : 's',
            $expected,
            $expected === 1 ? '' : 's',
            $missing === [] ? 'none named' : implode(', ', array_map(fn (LockKey $key): string => $key->toString(), $missing)),
        ));
    }
}
