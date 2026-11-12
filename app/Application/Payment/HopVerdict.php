<?php

declare(strict_types=1);

namespace App\Application\Payment;

/**
 * Where the pay hop sends the customer: on to the provider's page, to the manage page, to the
 * page saying the hold has ended, or to the page saying the payment page could not be opened,
 * either because the provider answered nowhere to go (Unopened) or because the hop's write lost
 * a race on a hold still live and payable (LostRace): a staff-day held past the lock timeout,
 * retries exhausted, a move under the lock on every attempt, or a concurrent first press that
 * recorded its session first; or, where no provider works, to the on-site settlement, which the
 * caller makes through the shared guarded write whether or not a session was recorded.
 *
 * Two more are the provider's reasons for opening nothing, each told to the customer as it is
 * (SessionWithheld): the recorded session was completed and its payment is on its way, so back
 * to read the booking with that said (Arriving); or the hold is too short for any session the
 * provider would open, so it can no longer be paid online (Closed), a page of its own and never
 * the 502 of a provider that answered nowhere to go.
 */
enum HopVerdict
{
    case Provider;
    case Manage;
    case Ended;
    case Unopened;
    case LostRace;
    case OnSite;
    case Arriving;
    case Closed;

    /**
     * The one mapping of a booking's payment state as its row stands onto the hop's answer before
     * any provider is asked: no row, or a hold that lapsed, was released, was cancelled or was
     * rejected, is Ended; a live payable hold goes on, Provider; anything else has nothing to
     * pay and goes back to be read, Manage. The hop's first read and its re-read after a lost
     * race read it, the on-site settlement's re-read reads it, and so do the payment link's
     * status page's words, so no road holds a second idea of what a state answers.
     */
    public static function of(?CheckoutState $state): self
    {
        return match (true) {
            $state === null || $state->ended() => self::Ended,
            $state->payable() => self::Provider,
            default => self::Manage,
        };
    }
}
