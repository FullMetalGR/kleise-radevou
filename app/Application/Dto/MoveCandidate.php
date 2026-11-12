<?php

declare(strict_types=1);

namespace App\Application\Dto;

use App\Domain\Booking\MaskCrossing;
use DateTimeImmutable;

/**
 * One start a business move may take: the instant, and what of the members' hours and the
 * closures the chain would cross there, each segment and kind once in segment order, none where
 * it fits. A crossing marks a start; it never removes one.
 */
final readonly class MoveCandidate
{
    /**
     * @param  list<MaskCrossing>  $crossings
     */
    public function __construct(public DateTimeImmutable $start, public array $crossings) {}
}
