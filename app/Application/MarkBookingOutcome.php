<?php

declare(strict_types=1);

namespace App\Application;

use App\Application\Dto\LockedBooking;
use App\Application\Dto\Transition;
use App\Application\Dto\TransitionLanding;
use App\Application\Event\BookingCompleted;
use App\Application\Event\BookingNoShow;
use App\Application\Event\TransitionEvent;
use App\Domain\Audit\Actor;
use App\Domain\Booking\TransitionRefusal;
use App\Domain\Enum\BookingOutcome;
use App\Domain\Enum\BookingStatus;
use App\Domain\Enum\HoldClass;
use DateTimeImmutable;

/**
 * The owner says how an appointment ended: confirmed to completed, or to no_show.
 *
 * It is a description handed to the shared transition implementation and not a sequence of its
 * own, so the locking, the re-read, the compare-and-set, the trail and the post-commit event
 * are the ones every transition runs.
 *
 * One use case with the outcome as its argument rather than two, because the guard is the same
 * question either way and two copies of it is two places for it to stop being the same. What
 * the outcome decides is the status, the action in the trail and the record that goes out.
 *
 * Nothing else on the row moves. The booking is confirmed, so there is no hold to end and
 * nothing to stamp; the compare-and-set writes the status alone and the items stop blocking,
 * because an appointment that is over occupies nothing.
 */
final readonly class MarkBookingOutcome
{
    public function __construct(private GuardedWrite $writes) {}

    /**
     * @param  BookingOutcome  $outcome  whether it happened or nobody came
     * @param  Actor  $actor  the signed-in user saying so. Whether somebody turned up is not a fact a clock can establish, so nothing schedules this and no link decides it; which user may is a permission checked by the caller.
     *
     * @throws BookingUnknown when no booking of this tenant carries the identifier
     * @throws TransitionRefused when the booking is not confirmed, or has not started yet
     * @throws WriteContended when the booking's items moved under the lock on every attempt
     */
    public function mark(string $uuid, BookingOutcome $outcome, Actor $actor): void
    {
        $this->writes->apply(new Transition(
            $uuid,
            $actor,
            $this->guard(...),
            fn (): TransitionLanding => new TransitionLanding($outcome->status()),
            $outcome->audit(),
            fn (BookingStatus $from, BookingStatus $to, ?HoldClass $holdClass): TransitionEvent => match ($outcome) {
                BookingOutcome::Completed => new BookingCompleted($uuid, $from, $to, $holdClass, $actor),
                BookingOutcome::NoShow => new BookingNoShow($uuid, $from, $to, $holdClass, $actor),
            },
        ));
    }

    /**
     * Confirmed, and started.
     *
     * The status question is asked first, so a booking that was never confirmed is told that
     * and not that it is early: a cancelled booking is not going to become markable by waiting,
     * and an answer that says it might would be false.
     *
     * The start is the first segment's own and not the end of the chain: the owner marks a
     * booking that has begun, and a chain still running is one they can say the customer did
     * not turn up for. The comparison is against the transition's own now, the clock every
     * guard here is handed, and the boundary is half-open like every other range in this
     * codebase: a booking whose start is this very instant has started.
     *
     * It refuses by the clock alone and still answers 409 rather than the 403 of a closed
     * policy window. No window was configured, nobody lacks a permission, and the same user may
     * do it the moment the start passes; it is a guard refusing a transition.
     */
    private function guard(LockedBooking $booking, DateTimeImmutable $now): ?TransitionRefusal
    {
        return self::refusal($booking->status, $booking->firstStart(), $now);
    }

    /**
     * The guard on the facts it reads: the status, the first segment's start and the clock. It
     * is public so that the admin's list of the actions allowed now asks this function rather
     * than spelling the rule a second time; under the lock it is asked of the re-read.
     */
    public static function refusal(BookingStatus $status, DateTimeImmutable $firstStart, DateTimeImmutable $now): ?TransitionRefusal
    {
        if ($status !== BookingStatus::Confirmed) {
            return TransitionRefusal::NotConfirmed;
        }

        return $firstStart <= $now ? null : TransitionRefusal::TooEarly;
    }
}
