<?php

declare(strict_types=1);

namespace App\Application;

use App\Application\Dto\LockedBooking;
use App\Application\Dto\Transition;
use App\Application\Dto\TransitionLanding;
use App\Application\Event\BookingReleased;
use App\Application\Event\TransitionEvent;
use App\Domain\Audit\Actor;
use App\Domain\Audit\AuditAction;
use App\Domain\Booking\TransitionColumn;
use App\Domain\Booking\TransitionRefusal;
use App\Domain\Enum\BookingStatus;
use App\Domain\Enum\HoldClass;
use DateTimeImmutable;

/**
 * The customer hands a checkout hold back: the booking page is closing, or they asked to pick
 * another time. The slot goes back to the diary early rather than waiting out its TTL.
 *
 * It is a description handed to the shared transition implementation and not a sequence of its
 * own. The row it leaves is cancelled, like a cancel's, and the trail and the event say
 * released, so the notifications can tell the two apart by type: an abandoned checkout is not
 * news and a cancelled appointment is.
 *
 * The guard is the status and nothing else. No window: the cancellation policy measures back
 * from the first segment's start and was written for the manage view, where the customer is
 * looking at a booking made days ago; here they took the hold seconds ago, and a service whose
 * window is longer than its own lead time would otherwise refuse to take back a hold the
 * customer had only just been given.
 *
 * Every other state refuses, an approval request included. That is what lets a lodged request
 * survive the tab closing: the customer is waiting on a human, the owner has the decision in
 * front of them, and the page-hide release that fires when the customer looks at something else
 * must not throw the request away. The client's release is best effort and the server's answer
 * is authoritative, so a refusal here is a normal answer the booking app ignores.
 */
final readonly class ReleaseHold
{
    public function __construct(private GuardedWrite $writes) {}

    /**
     * The actor is the customer, always. A checkout hold belongs to the person in the booking
     * flow and nobody else can be in it; what the business does to a held booking is cancel it,
     * which is the other transition and says so in the trail.
     *
     * @throws BookingUnknown when no booking of this tenant carries the identifier
     * @throws TransitionRefused when the booking is not a checkout hold to give back
     * @throws WriteContended when the booking's items moved under the lock on every attempt
     */
    public function release(string $uuid): void
    {
        $actor = Actor::customer();

        $this->writes->apply(new Transition(
            $uuid,
            $actor,
            $this->guard(...),
            fn (): TransitionLanding => new TransitionLanding(BookingStatus::Cancelled, [
                TransitionColumn::HoldClass->value => null,
                TransitionColumn::HoldExpiresAt->value => null,
            ]),
            AuditAction::Released,
            fn (BookingStatus $from, BookingStatus $to, ?HoldClass $holdClass): TransitionEvent => new BookingReleased($uuid, $from, $to, $holdClass, $actor),
        ));
    }

    /**
     * Pending and nothing else.
     *
     * A pending hold whose expiry has passed is released rather than refused, and the answer is
     * the same act it would have been a minute earlier. The slot is free by the clock either
     * way, so nothing is taken from anybody; the sweeper simply has not reached the row yet.
     * The trail then says released, by the customer, which is a truer account of what happened
     * than an expiry the customer got in ahead of.
     */
    private function guard(LockedBooking $booking, DateTimeImmutable $now): ?TransitionRefusal
    {
        return $booking->status === BookingStatus::Pending ? null : TransitionRefusal::NotReleasable;
    }
}
