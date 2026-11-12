<?php

declare(strict_types=1);

namespace App\Application\Dto;

use InvalidArgumentException;

/**
 * An instant a transition writes that no application clock reads: the database's own now() at
 * the statement that writes it, plus a whole number of hours.
 *
 * It exists for the one expiry a landing writes, the payment hold an approval leaves behind.
 * Every hold's expiry is compared against the server's now(), and every other hold's expiry is
 * written as now() plus an interval in the statement that creates it, so an expiry stated as
 * the application's now plus a TTL would be the one the two clocks disagree on. The store
 * spells it in the assignment, never as a bound instant.
 */
final readonly class ServerNowPlus
{
    /**
     * @throws InvalidArgumentException for an interval that is not positive
     */
    public function __construct(public int $hours)
    {
        if ($hours < 1) {
            throw new InvalidArgumentException(sprintf('An expiry from the server\'s now is at least an hour away; %d was given.', $hours));
        }
    }
}
