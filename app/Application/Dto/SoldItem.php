<?php

declare(strict_types=1);

namespace App\Application\Dto;

use App\Domain\Booking\StaffBlock;
use App\Domain\Money\Money;
use DateTimeImmutable;

/**
 * One item of a booking as the read before a move finds it: what was sold, and where it is
 * sitting now.
 *
 * Five facts and each is read by a different part of the move or of the read that offers it. The
 * service and the member are what the new chain is planned from, the same services in the same
 * order with the sold member pinned and never re-picked. The price is what the new item carries,
 * taken from the row and not from the catalog, so a tenant who changed their price list since
 * cannot restate what a customer already agreed to pay. The contended range is what the staff-day
 * mutex rows the booking is leaving are computed from, buffers included, for the same reason the
 * guarded transitions' pre-read is the block range: a set computed from the customer's range would
 * name fewer staff-days than the booking occupies whenever a buffer crosses local midnight. The
 * start is where the customer's appointment begins now, which the per-booking availability read
 * drops from its answer: once the booking is left out of what is busy its own start always reads as
 * free, and a move to where it already is would be written as a move.
 *
 * There is no status here and no customer, deliberately. A move decides on the re-read under
 * the lock, as every transition does, and a pre-read that cannot carry a status cannot tempt
 * anybody into judging one.
 */
final readonly class SoldItem
{
    public function __construct(
        public int $serviceId,
        public int $staffId,
        public StaffBlock $block,
        public Money $price,
        public DateTimeImmutable $start,
    ) {}
}
