<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Booking\MaskCrossing;
use InvalidArgumentException;
use RuntimeException;

/**
 * A move by the business lands outside a member's working hours or on a closure, and the
 * warning that says so was not accepted. The masks rebuilt under the lock said it, so the
 * transaction rolled back with it and the booking is where it was; the same move with the
 * warning accepted goes through, and nothing but these two kinds is ever overridable.
 *
 * It carries every crossing, each segment by its position in the chain, its member and which of
 * the two it crosses, so a confirmation can name them all at once rather than one per attempt.
 */
final class UnacceptedWarning extends RuntimeException
{
    /**
     * @param  non-empty-list<MaskCrossing>  $crossings
     */
    private function __construct(
        public readonly array $crossings,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * @param  list<MaskCrossing>  $crossings
     */
    public static function at(string $uuid, array $crossings): self
    {
        if ($crossings === []) {
            throw new InvalidArgumentException('A warning names at least one crossing.');
        }

        return new self($crossings, sprintf(
            'Booking %s would move across %s, and the warning was not accepted.',
            $uuid,
            implode(', ', array_map(fn (MaskCrossing $crossing): string => sprintf('segment %d (%s, staff member %d)', $crossing->segment, $crossing->kind->value, $crossing->staffId), $crossings)),
        ));
    }
}
