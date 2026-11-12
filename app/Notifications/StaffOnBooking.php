<?php

declare(strict_types=1);

namespace App\Notifications;

/**
 * A staff row carrying an item of one booking: the row's own address where it has one, the
 * central user it is linked to where there is one, and whether it is the implicit row standing
 * for the business itself.
 *
 * All three are nullable in the ordinary case. Most staff members never sign in, most have no
 * address of their own, and every tenant has exactly one implicit row.
 */
final readonly class StaffOnBooking
{
    public function __construct(
        public int $id,
        public ?string $email,
        public ?int $userId,
        public bool $isImplicit,
    ) {}
}
