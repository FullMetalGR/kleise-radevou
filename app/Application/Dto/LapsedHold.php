<?php

declare(strict_types=1);

namespace App\Application\Dto;

use App\Domain\Enum\ApprovalTimeout;
use App\Domain\Enum\BookingStatus;
use App\Domain\Enum\HoldClass;
use App\Domain\Enum\PaymentMode;

/**
 * One booking the sweeper's selection found in a held state past its expiry, as it stands under
 * the row lock the selection took.
 *
 * It is not a ReapedHold. The reap of the write protocol's step 4 hands back what it has already
 * expired, so what it names is settled; this names a row the sweep has locked and not yet
 * decided, and the decision needs one fact the reap never has to read, because the reap excludes
 * the rows it applies to: the timeout policy copied onto the booking at hold time.
 *
 * The status and the hold class are what the trail and the acknowledgement rule read: "expired
 * from awaiting_approval on an approval hold" is the customer's answer, and "expired from
 * pending on a checkout hold" is an abandoned basket nobody is told about.
 */
final readonly class LapsedHold
{
    public function __construct(
        public string $uuid,
        public BookingStatus $status,
        public ?HoldClass $holdClass,
        public ApprovalTimeout $onApprovalTimeout,
        public PaymentMode $paymentMode,
    ) {}

    /**
     * Whether the clock has already decided this one in the owner's favour: an approval request
     * whose service auto-approves on timeout.
     *
     * Such a request goes on blocking past its expiry, which is why no reap may let it go and
     * why the sweep is the only thing that settles it. Every other lapsed hold is free by time
     * the moment its expiry passes, and the sweep is only writing down what is already true.
     */
    public function grantedByTheClock(): bool
    {
        return $this->status === BookingStatus::AwaitingApproval
            && $this->onApprovalTimeout === ApprovalTimeout::AutoApprove;
    }
}
