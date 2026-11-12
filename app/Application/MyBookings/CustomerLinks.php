<?php

declare(strict_types=1);

namespace App\Application\MyBookings;

use DateTimeImmutable;

/**
 * Where a My bookings link is decided and kept, bound to one tenant by whoever built it: whether
 * an address has any booking here, and the row a minted link is written to.
 *
 * An address is compared as bookings.customer_email compares it, as citext, so the link opens on
 * exactly the bookings the page will list.
 */
interface CustomerLinks
{
    public function hasBookings(string $email): bool;

    /**
     * Writes a link's row, the hash of its token and never the token, and answers its id.
     */
    public function mint(string $email, string $tokenHash, DateTimeImmutable $createdAt, DateTimeImmutable $expiresAt): int;
}
