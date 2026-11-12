<?php

declare(strict_types=1);

namespace App\Application\Event;

/**
 * A payment landed for a booking it did not confirm, and the event that said so is marked: the
 * hold it was meant for lapsed, was released or was cancelled, the booking was settled on site
 * at the pay hop, or another payment confirmed it. The owner is told that the payment bought
 * nothing and that refunding it is theirs to do in their provider's dashboard.
 *
 * It moves nothing, which the shared shape says plainly: from and to are the one status the
 * payment webhook read after the paid transition refused, the hold class is null because the
 * payment settled no hold, and the actor is the payment notification's, as the paid
 * transition's trail names it. No transaction commits under it; the webhook announces it after
 * the write that marks the event, only where that write marked it, so once per event, and
 * outside any transaction, as a use case announces after its commit.
 *
 * It carries neither the payment's id nor anything the notice said: a listener re-reads the
 * booking, and what the owner looks for at the provider is the booking it names.
 */
final readonly class BookingPaidLate implements TransitionEvent
{
    use CarriesTheTransition;
}
