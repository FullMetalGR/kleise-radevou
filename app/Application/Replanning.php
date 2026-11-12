<?php

declare(strict_types=1);

namespace App\Application;

use App\Application\Dto\HoldItem;
use App\Domain\Chain\Chain;
use Closure;
use DateTimeImmutable;

/**
 * A booking's chain placed on the catalog once, HoldPlanner::replanning, and the planner's own
 * placement of it at any start asked: the move's rules, the lead time, the horizon, the grid and
 * a start already passed, asked exactly as the move asks them, so a read asking a run of starts
 * moves with the planner whenever its rules do.
 */
final readonly class Replanning
{
    /**
     * @param  Closure(DateTimeImmutable): non-empty-list<HoldItem>  $at
     */
    public function __construct(public Chain $chain, private Closure $at) {}

    /**
     * The items the move would insert at that start, in chain order.
     *
     * @return non-empty-list<HoldItem>
     *
     * @throws ChainUnavailable when the planner refuses the start, naming its rule
     */
    public function at(DateTimeImmutable $start): array
    {
        return ($this->at)($start);
    }
}
