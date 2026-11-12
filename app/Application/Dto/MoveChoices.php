<?php

declare(strict_types=1);

namespace App\Application\Dto;

use InvalidArgumentException;

/**
 * What a move by the business asks for beside the new start: which segments go to another
 * member, whether the warning about the member's hours or a closure was accepted, and whether
 * the customer is told. The defaults are the customer's own move, which asks for none of the
 * three: every member as sold, no warning accepted, and the customer told, so a move that names
 * nothing here is the move the customer could have made.
 *
 * The members are keyed by the segment's position in the chain, from zero, and never by an item
 * id: a move deletes the items and inserts them again, so an id names a row that is gone after
 * the first move.
 */
final readonly class MoveChoices
{
    /**
     * @param  array<int, int>  $staff  the member to give each named segment to, by position in the chain
     * @param  bool  $warningAccepted  whether the confirmation's warning about hours or a closure was accepted
     * @param  bool  $tellCustomer  whether the customer is sent the moved message
     */
    public function __construct(
        public array $staff = [],
        public bool $warningAccepted = false,
        public bool $tellCustomer = true,
    ) {
        foreach ($staff as $segment => $staffId) {
            if ($segment < 0 || $staffId < 1) {
                throw new InvalidArgumentException(sprintf('A segment is given to a member by a position from zero and a staff id from one, not %d and %d.', $segment, $staffId));
            }
        }
    }

    /**
     * Whether these are the defaults, the only choices the customer's own move may carry.
     */
    public function areTheCustomers(): bool
    {
        return $this->staff === [] && ! $this->warningAccepted && $this->tellCustomer;
    }
}
