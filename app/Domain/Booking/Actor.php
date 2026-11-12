<?php

declare(strict_types=1);

namespace App\Domain\Booking;

use App\Domain\Audit\Actor as AuditActor;

/**
 * Who is asking the cancellation policy: the customer through the magic link, or a signed-in
 * admin. The policy binds the customer to the per-service windows and the admin to the status
 * rule alone, so the two must arrive as different values rather than as a flag the caller could
 * forget to pass.
 *
 * The same two sides decide who a service's lead time and booking horizon bind: the customer
 * alone. The business places a booking, by hand or by a move, at any future start on the grid;
 * a start already passed stays refused to both.
 *
 * Admin asks both questions. The business moves bookings as well as cancelling them, and the
 * window binds neither of its acts.
 */
enum Actor: string
{
    case Customer = 'customer';
    case Admin = 'admin';

    /**
     * Which side of the window the actor who is acting stands on. The trail's vocabulary is
     * wider than this one, five shapes against two, because a trail records who did it and a
     * policy only asks whether a window binds them: a signed-in user, support acting in a
     * session, a queued job and the engine acting on its own are all the business, and only the
     * guest through the magic link is the customer.
     *
     * Derived rather than passed beside the trail's actor, because two arguments naming the
     * same person are two chances to name two different people, and the one that decides
     * whether a rule applies is the one a caller would get wrong.
     */
    public static function behind(AuditActor $actor): self
    {
        return $actor->isCustomer() ? self::Customer : self::Admin;
    }

    /**
     * Whether a service's lead time and booking horizon bind this side: the customer's, and never
     * the business's. The one place the rule is written; the planner and the availability reads
     * ask the chain, which asks here.
     */
    public function isBoundByLeadTimeAndHorizon(): bool
    {
        return $this === self::Customer;
    }
}
