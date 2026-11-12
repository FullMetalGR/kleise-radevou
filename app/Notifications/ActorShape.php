<?php

declare(strict_types=1);

namespace App\Notifications;

/**
 * Which of the five shapes of actor did something to a booking, as WhoActed tells them apart for
 * a notice to the business: each is its own whole sentence in the notice's language, never a
 * phrase spliced in as a sentence's subject.
 */
enum ActorShape
{
    /** The customer, by the name on the booking. */
    case Customer;

    /** A user of the business, by the name on their central row. */
    case User;

    /** A user the tenant no longer has, said as that rather than as a number nobody can place. */
    case RemovedUser;

    /** The platform's support in a session, by the platform's public name. */
    case Support;

    /** The engine acting on its own, or a job, which is nobody at the business. */
    case System;
}
