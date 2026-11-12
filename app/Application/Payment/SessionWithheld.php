<?php

declare(strict_types=1);

namespace App\Application\Payment;

/**
 * Why a provider opened no page for a checkout where the customer must be told the reason
 * truly: beside the port's null, which says the provider answered nowhere to go and which the
 * hop reads as the page that could not be opened, the 502.
 */
enum SessionWithheld
{
    /**
     * The session recorded for the booking was completed at the provider: the customer paid,
     * or the payment is still being processed, and the notification that confirms the booking
     * has not landed yet. The hop opens nothing and sends the customer back to read the
     * booking, saying the payment is on its way.
     */
    case Completed;

    /**
     * The hold has too little time left for any session the provider would open: a session
     * after a move cleared the booking's session ends with the hold as it stands, and the
     * provider's shortest session no longer fits before that end. The hold may not be
     * lengthened to take one, so it can no longer be paid online.
     */
    case TooShort;
}
