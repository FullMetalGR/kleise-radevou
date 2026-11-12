<?php

declare(strict_types=1);

namespace App\Application\Dto;

use App\Domain\Enum\BookingStatus;
use App\Domain\Enum\HoldClass;

/**
 * One hold the reap of the write protocol's step 4 let go, as it stood before it was expired.
 *
 * The status it was in and the class it held are read before the update rather than after,
 * because both are what the trail and the acknowledgement rule need: "expired from awaiting
 * approval on an approval hold" is the customer's answer, and "expired from pending on a
 * checkout hold" is an abandoned basket nobody is told about.
 */
final readonly class ReapedHold
{
    public function __construct(
        public string $uuid,
        public BookingStatus $status,
        public ?HoldClass $holdClass,
    ) {}
}
