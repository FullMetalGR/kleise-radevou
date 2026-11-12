<?php

declare(strict_types=1);

namespace App\Application\Dto;

use App\Domain\Booking\ServiceWindows;
use App\Domain\Booking\StaffBlock;
use DateTimeImmutable;

/**
 * One item of a booking as the re-read under the lock found it.
 *
 * Three facts, and each is read by a different step. The contended range is what the staff-day
 * mutex rows stand for, so step 5 recomputes the key set from it. The start is the customer's
 * own, buffers excluded, because a policy window is measured back from when the appointment
 * begins and not from when the member starts setting up for it. The windows are the service's,
 * read from the catalog by the same statement that read the row, because the policy reads them
 * at decision time rather than from a copy taken when the booking was made: an owner who
 * shortens their cancellation window means it for the bookings already in the diary.
 *
 * They travel together rather than as three lists beside each other, since three lists can come
 * back different lengths and nothing would say which item lost its window.
 */
final readonly class LockedItem
{
    public function __construct(
        public StaffBlock $block,
        public DateTimeImmutable $start,
        public ServiceWindows $windows,
    ) {}
}
