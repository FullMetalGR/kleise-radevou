<?php

declare(strict_types=1);

namespace App\Domain\Booking;

use Countable;
use InvalidArgumentException;
use OutOfBoundsException;

/**
 * The post-bump revision of every mutex row a transaction holds, keyed by staff-day. The lock
 * hands these back so the caller can key what it rebuilds for each staff-day by the revision
 * its own write produced.
 */
final readonly class Revisions implements Countable
{
    /** @var array<string, int> the pair as LockKey::toString() => its revision */
    private array $byPair;

    /**
     * @param  list<array{LockKey, int}>  $entries
     */
    public function __construct(array $entries)
    {
        $byPair = [];

        foreach ($entries as [$key, $revision]) {
            $pair = $key->toString();

            if ($revision < 0) {
                throw new InvalidArgumentException(sprintf('A revision is never negative; staff-day %s has %d.', $pair, $revision));
            }

            if (isset($byPair[$pair])) {
                throw new InvalidArgumentException(sprintf('Staff-day %s was given a revision twice.', $pair));
            }

            $byPair[$pair] = $revision;
        }

        $this->byPair = $byPair;
    }

    public function of(LockKey $key): int
    {
        return $this->byPair[$key->toString()]
            ?? throw new OutOfBoundsException(sprintf('No revision was locked for staff-day %s.', $key->toString()));
    }

    public function has(LockKey $key): bool
    {
        return isset($this->byPair[$key->toString()]);
    }

    /**
     * @return array<string, int> keyed by staff-day as LockKey::toString()
     */
    public function toArray(): array
    {
        return $this->byPair;
    }

    public function count(): int
    {
        return count($this->byPair);
    }
}
