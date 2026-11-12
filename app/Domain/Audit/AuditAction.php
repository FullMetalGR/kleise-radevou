<?php

declare(strict_types=1);

namespace App\Domain\Audit;

/**
 * What the row records. One case per transition that writes a trail, and the value is what the
 * action column holds: a hold taken, a hold the reap of the write protocol's step 4 let go, a
 * hold settled by the customer, a booking given up, a checkout hold handed back mid-flow, the
 * owner's two answers to an approval request, the two ways an appointment ends, and a booking
 * moved to another time.
 *
 * Cancelled and Released land on the same status and are still two actions, because the trail
 * is an account of what was done and not a second copy of the status column: a customer leaving
 * the booking page and a customer cancelling their appointment are different acts, and only the
 * trail will still know which it was once both rows read cancelled.
 *
 * Rescheduled is the one action here whose row leaves the status where it found it. What moved
 * is the time, and the payload is what says so: the start the booking left and the start it
 * took. A trail that recorded the status alone would say nothing happened.
 *
 * Approved and Confirmed are two actions for the same reason and not by oversight. Both can
 * leave a row reading confirmed, and what the owner did is not what the customer did; the status
 * column cannot say which, and an approval may not even land there.
 *
 * CustomerErased is no transition at all: the business erased the customer the booking was made
 * for, one row per booking of the address, the status where it was and the payload empty. The
 * trail is how the business can show it acted on the request, and it names the booking and the
 * user and nothing of the person, as every row here does.
 *
 * CustomerExported is no transition either: the business exported the customer's data from their
 * page, one row per booking the document held, the status where it was and the payload empty, so
 * the trail shows that a person's data left the system and when, and still names nothing of the
 * person.
 */
enum AuditAction: string
{
    case Held = 'held';

    case Expired = 'expired';

    case Confirmed = 'confirmed';

    case Cancelled = 'cancelled';

    case Released = 'released';

    case Approved = 'approved';

    case Rejected = 'rejected';

    case Completed = 'completed';

    case NoShow = 'no_show';

    case Rescheduled = 'rescheduled';

    case CustomerErased = 'customer_erased';

    case CustomerExported = 'customer_exported';
}
