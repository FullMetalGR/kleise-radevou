<?php

declare(strict_types=1);

namespace App\Application\Dto;

use App\Application\Event\TransitionEvent;
use App\Domain\Audit\Actor;
use App\Domain\Audit\AuditAction;
use App\Domain\Booking\TransitionRefusal;
use App\Domain\Enum\BookingStatus;
use App\Domain\Enum\HoldClass;
use Closure;
use DateTimeImmutable;

/**
 * One guarded transition, as data: which booking, who is acting, what makes it allowed, how its
 * landing is found, what the trail calls it, and what is announced once it has committed.
 *
 * Described rather than coded because the guarded write is one sequence shared by seven
 * transitions and counting, and the sequence is nine steps with a lock order, a re-read, a
 * compare-and-set and a post-commit publish in it. A second transition written as a second copy
 * of that sequence is a second chance to get the order wrong; a second transition written as this
 * is a guard and a landing.
 *
 * Nothing here is a status to write or a value to write beside it. The landing is a function of
 * the re-read and of the clock, asked under the lock by the shared implementation in the
 * attempt that writes, so a transition cannot fix a target or take a stamp before the transaction
 * opens: an approval lands by the booking's own payment mode, which is known only on the
 * re-read, and the instant it records is the instant of the attempt that commits. A transition
 * whose landing depends on what was asked for and on nothing the row says, a cancel, an
 * outcome, states that as a function that reads neither argument.
 *
 * The guard is a function of the re-read and of now, and of nothing else. It cannot see the
 * request, the pre-read, or this description: what a caller believed is not evidence, and the
 * status found under the lock is the only account of the booking there is. The landing is asked
 * only once the guard has allowed, of the same re-read and the same now.
 *
 * The facts are what a transition says of itself to the trail, known before the transaction
 * opens. Today one transition states any: the paid transition says whether its notice named the
 * payment, and the amount the notice says was paid, in minor units and its currency. A
 * fact is written beside where the booking moved, never in its place: the shared implementation
 * drops any fact keyed status_from, status_to or hold_class and writes those three from the lock
 * and the landing.
 */
final readonly class Transition
{
    /**
     * @param  string  $uuid  the booking's public identifier
     * @param  Actor  $actor  who is acting, for the trail and the event
     * @param  Closure(LockedBooking, DateTimeImmutable): ?TransitionRefusal  $guard  the reason it is refused, or null to allow it
     * @param  Closure(LockedBooking, DateTimeImmutable): TransitionLanding  $landing  the status and the columns the compare-and-set writes, from the re-read and the attempt's own now
     * @param  AuditAction  $audit  what the trail calls it
     * @param  Closure(BookingStatus, BookingStatus, ?HoldClass, LockedBooking): TransitionEvent  $event  built from the status the lock found, the status it landed on and the class the lock found, published after the commit; the re-read itself is the fourth argument, for the one transition that carries something of the row on, the paid one's sealed manage token
     * @param  array<string, scalar>  $facts  what the trail says of this transition beside where it moved, keyed by AuditKey values, known before the transaction opens; the paid transition's whether its notice named the payment and the amount it says was paid, and nothing for every other
     */
    public function __construct(
        public string $uuid,
        public Actor $actor,
        public Closure $guard,
        public Closure $landing,
        public AuditAction $audit,
        public Closure $event,
        public array $facts = [],
    ) {}
}
