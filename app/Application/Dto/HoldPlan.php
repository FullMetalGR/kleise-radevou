<?php

declare(strict_types=1);

namespace App\Application\Dto;

use App\Application\PlacedChain;
use App\Domain\Booking\StaffBlock;
use App\Domain\Enum\ApprovalTimeout;
use App\Domain\Enum\BookingStatus;
use App\Domain\Enum\HoldClass;
use App\Domain\Enum\PaymentMode;
use App\Domain\Money\Money;
use InvalidArgumentException;

/**
 * What the protocol takes: the customer, the items in segment order with everything decided,
 * and the columns the booking copies from the chain, its status and hold class with the
 * lifetime of the hold, the payment mode, whether the owner approves it, and what a timeout
 * does, and beside them the intake the request carried, written with the row. The planner
 * builds one from the catalog; the protocol re-validates it under lock and writes it or nothing.
 *
 * The items, their rules and their total are PlacedChain's, which is what the two writes that
 * place a chain both read: two items contending with each other on one staff member are refused
 * as the plan is built, and the total is summed in the currency of the first item, so a chain
 * mixing two currencies is refused before it reaches a transaction. A plan is that plus what the
 * booking row copies from the chain.
 */
final readonly class HoldPlan
{
    /**
     * The items as the write protocol reads them, which is where their rules live. It is public
     * because it is what the protocol takes: the hold hands this very object to the steps that
     * lock, reap, re-validate and warm, rather than building a second one over the same items.
     * A chain is proven once, when the plan is made, and the object that proved it is the one
     * the write reads; two of them would run the self-contention check and the currency sum
     * twice and would let one invariant be established against data the other never saw.
     */
    public PlacedChain $chain;

    /** @var non-empty-list<HoldItem> in segment order */
    public array $items;

    public Money $total;

    /**
     * @param  list<HoldItem>  $items  in segment order
     * @param  int  $ttlMinutes  how long after the hold the booking expires
     */
    public function __construct(
        public Customer $customer,
        array $items,
        public BookingStatus $status,
        public HoldClass $holdClass,
        public int $ttlMinutes,
        public PaymentMode $paymentMode,
        public bool $requiresApproval,
        public ApprovalTimeout $onApprovalTimeout,
        public Intake $intake,
    ) {
        if ($items === []) {
            throw new InvalidArgumentException('A hold has at least one item.');
        }

        if ($ttlMinutes < 1) {
            throw new InvalidArgumentException(sprintf('A hold lives at least one minute, not %d.', $ttlMinutes));
        }

        if (! $status->isHeld()) {
            throw new InvalidArgumentException(sprintf('A hold lands in a held status, not %s.', $status->value));
        }

        $this->chain = new PlacedChain($items);
        $this->items = $this->chain->items;
        $this->total = $this->chain->total();
    }

    /**
     * Every item's block range, in segment order, so a segment index into one is an index into
     * the other.
     *
     * @return non-empty-list<StaffBlock>
     */
    public function blocks(): array
    {
        return $this->chain->blocks();
    }
}
