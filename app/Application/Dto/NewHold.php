<?php

declare(strict_types=1);

namespace App\Application\Dto;

use App\Domain\Enum\ApprovalTimeout;
use App\Domain\Enum\BookingStatus;
use App\Domain\Enum\HoldClass;
use App\Domain\Enum\PaymentMode;
use App\Domain\Money\Money;
use InvalidArgumentException;

/**
 * Everything step 6 writes, assembled by the use case inside the transaction: the container's
 * columns, the items in segment order, and the intake, whose source and channel are columns of
 * the row and whose note is booking_meta's row beside it, written in the same transaction. The
 * row's uuid and its expiry are the store's to produce, the one from its generator and the other
 * from its clock, so neither is here.
 *
 * The class and the lifetime are both present on a held landing and both absent on a settled
 * one, Landing: a booking confirmed outright has nothing to lapse, and the store writes its
 * expiry as null from the null lifetime.
 */
final readonly class NewHold
{
    /**
     * @param  int|null  $ttlMinutes  how long after the hold the booking expires; null with no class, on a settled landing
     * @param  string  $manageTokenHash  the hash only; the plain token never reaches the store
     * @param  Money  $total  re-summed from the items, never taken from the caller
     * @param  non-empty-list<HoldItem>  $items  in segment order
     */
    public function __construct(
        public Customer $customer,
        public BookingStatus $status,
        public ?HoldClass $holdClass,
        public ?int $ttlMinutes,
        public PaymentMode $paymentMode,
        public bool $requiresApproval,
        public ApprovalTimeout $onApprovalTimeout,
        public string $manageTokenHash,
        public Money $total,
        public array $items,
        public Intake $intake,
    ) {
        if ($status->isHeld() !== ($holdClass !== null && $ttlMinutes !== null)) {
            throw new InvalidArgumentException(sprintf('A booking landing on %s %s a hold class and a lifetime.', $status->value, $status->isHeld() ? 'needs' : 'carries no'));
        }
    }
}
