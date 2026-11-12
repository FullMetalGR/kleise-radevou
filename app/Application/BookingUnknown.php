<?php

declare(strict_types=1);

namespace App\Application;

use RuntimeException;

/**
 * No booking of this tenant carries that identifier, either when the key set was read or when
 * the row was locked.
 *
 * A caller reaching a transition has usually been let through by a credential that had to read
 * the booking to check itself, so this is the booking disappearing in between, which nothing in
 * v1 does. It is still the honest answer rather than an assertion, because the alternative is a
 * lock statement asserting a row count and saying something about the protocol when the truth
 * is about the booking. The API answers it exactly as it answers a wrong credential, so the
 * route stays no kind of existence oracle.
 */
final class BookingUnknown extends RuntimeException
{
    public static function named(string $uuid): self
    {
        return new self(sprintf('No booking of this tenant is identified by %s.', $uuid));
    }
}
