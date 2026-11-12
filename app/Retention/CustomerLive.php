<?php

declare(strict_types=1);

namespace App\Retention;

use RuntimeException;

/**
 * The erasure's refusal while a booking of the address is live: a hold of any class, a request
 * awaiting a decision, a booking awaiting payment, or a confirmed booking with a segment still
 * ahead of the clock. The owner cancels them first, so the refusal carries their count, which
 * CustomerController words as one choice key of lang/{locale}/admin/customers.php in the
 * request's language, the sentence the customer's page shows before any press. It is answered as
 * a 409 and the transaction that found it has written nothing.
 *
 * The message is a developer line for the log: it names the count and nothing of the person, no
 * address, no name.
 */
final class CustomerLive extends RuntimeException
{
    public const string CODE = 'live_bookings';

    public function __construct(public readonly int $live)
    {
        parent::__construct(sprintf('The customer has %d live bookings and is not erased.', $live));
    }
}
