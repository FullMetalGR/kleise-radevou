<?php

declare(strict_types=1);

namespace App\Application\Dto;

use App\Domain\Availability\SegmentShape;
use App\Domain\Chain\ChainSegment;
use App\Domain\Enum\ApprovalTimeout;
use App\Domain\Enum\PaymentMode;
use App\Domain\Money\Money;
use InvalidArgumentException;

/**
 * An active service as the engine sees it, the columns the catalog withholds: its shape, the
 * processing gap that follows it, its lead time and horizon, the staff members who may perform
 * it, the active assigned ones with the implicit member among them, and what the hold copies
 * onto the booking: the price in the service's own currency, the payment mode, whether the
 * owner approves each request, the approval window when the service names one, and what a
 * timeout does to the request. The catalog's public view is CatalogService, a different read
 * for a different caller; this one exists so that a chain can be built from a customer's
 * choices and held from the same row.
 */
final readonly class BookableService
{
    /**
     * @var list<int> ascending
     */
    public array $staffIds;

    /**
     * @param  list<int>  $staffIds  the active staff members assigned to it, in any order, the implicit one included
     * @param  int|null  $approvalHoldHours  the service's own approval window, or null for the tenant's default
     */
    public function __construct(
        public int $id,
        public SegmentShape $shape,
        public int $processingGapMin,
        public int $leadTimeMin,
        public int $horizonDays,
        array $staffIds,
        public Money $price,
        public PaymentMode $paymentMode,
        public bool $requiresApproval,
        public ?int $approvalHoldHours,
        public ApprovalTimeout $onApprovalTimeout,
    ) {
        if ($id < 1) {
            throw new InvalidArgumentException(sprintf('A service id is a positive integer, not %d.', $id));
        }

        if ($price->minor < 0) {
            throw new InvalidArgumentException(sprintf('A price is zero or more minor units, not %d.', $price->minor));
        }

        if ($approvalHoldHours !== null && $approvalHoldHours < 1) {
            throw new InvalidArgumentException(sprintf('An approval window is null or at least one hour, not %d.', $approvalHoldHours));
        }

        $seen = [];

        foreach ($staffIds as $staffId) {
            if (isset($seen[$staffId])) {
                throw new InvalidArgumentException(sprintf('A service names each staff member once, not %d twice.', $staffId));
            }

            $seen[$staffId] = true;
        }

        sort($staffIds);

        $this->staffIds = $staffIds;
    }

    public function performedBy(int $staffId): bool
    {
        return in_array($staffId, $this->staffIds, true);
    }

    /**
     * The service as one segment of a chain, carrying the customer's pick, which the segment
     * refuses unless it performs the service; the caller checks first and answers politely.
     */
    public function segment(?int $pickedStaffId): ChainSegment
    {
        return new ChainSegment($this->shape, $this->processingGapMin, $this->staffIds, $pickedStaffId, $this->leadTimeMin, $this->horizonDays);
    }
}
