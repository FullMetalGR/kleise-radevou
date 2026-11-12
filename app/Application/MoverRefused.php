<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Audit\Actor;
use LogicException;

/**
 * A move was asked for by an actor that moves nothing, or with choices its actor may not make.
 * Two people move a booking: the customer through their magic link, and the business, a
 * signed-in user or support acting for it in a session. The engine itself and a queued job are
 * neither, and the customer moves their booking as it was sold, the member, the masks and their
 * own message being the business's to change.
 *
 * Decided before anything is read: who asked and what they asked for are known from the call
 * alone, so the booking is not planned, not locked and not looked at, and no status stands
 * beside the answer. Nor is it a wire code. The routes that move a booking pass the customer or
 * the principal, and the customer's route passes no choices, so no request can earn this; a
 * caller that does is a caller written wrong, which is what LogicException says.
 */
final class MoverRefused extends LogicException
{
    public static function notAMover(string $uuid, Actor $actor): self
    {
        return new self(sprintf(
            'Booking %s is moved by its customer or by the business, and %s asked.',
            $uuid,
            (string) $actor,
        ));
    }

    public static function customerChose(string $uuid): self
    {
        return new self(sprintf(
            'Booking %s was moved by its customer with a choice only the business makes: another member, an accepted warning or the customer left untold.',
            $uuid,
        ));
    }
}
