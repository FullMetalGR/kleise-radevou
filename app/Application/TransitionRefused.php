<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Booking\TransitionRefusal;
use App\Domain\Enum\BookingStatus;
use RuntimeException;

/**
 * A guard refused the transition on what the re-read under the lock found. The transaction
 * rolls back with it, so no status was written, no blocking flag cleared, no audit row left and
 * no event published: a refusal is a booking that did not move.
 *
 * It carries the machine reason the API answers with and the status the lock found, which is
 * what a log needs to explain a refusal that surprised somebody. Neither reaches the customer:
 * they are shown one sentence, and the booking app re-reads the booking rather than reasoning
 * about the status through an error body.
 */
final class TransitionRefused extends RuntimeException
{
    private function __construct(
        public readonly TransitionRefusal $reason,
        public readonly BookingStatus $status,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function because(string $uuid, TransitionRefusal $reason, BookingStatus $status): self
    {
        return new self($reason, $status, sprintf(
            'The transition on booking %s was refused as %s: the lock found it %s.',
            $uuid,
            $reason->value,
            $status->value,
        ));
    }
}
