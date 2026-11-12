<?php

declare(strict_types=1);

namespace App\Application\Event;

/**
 * The owner said no to a request and the commit has happened: the slot is back in the diary and
 * the customer is owed the news.
 *
 * The reason, where the owner gave one, is not on the record. It is the owner's note to their
 * own staff, it may name the customer, and the notifications read it from the booking row under
 * the rules that apply to a booking's own columns rather than from an event that has already
 * been queued, logged and retried.
 */
final readonly class BookingRejected implements TransitionEvent
{
    use CarriesTheTransition;
}
