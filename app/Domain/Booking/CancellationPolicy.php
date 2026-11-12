<?php

declare(strict_types=1);

namespace App\Domain\Booking;

use App\Domain\Enum\BookingStatus;
use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * The two decisions the server makes before a guarded transition: may this booking be cancelled
 * now, and may it be rescheduled now. Built from what the transaction re-read under lock, never
 * from what the request carried, and asked with now as an argument, because the policy owns no
 * clock.
 *
 * The status rule is the one the booking app's affordances mirror, and both matches are
 * exhaustive with no default arm, so a tenth status fails analysis rather than silently
 * allowing or refusing.
 *
 * Whether the hold is still live arrives as the server's verdict rather than as an expiry to
 * compare. The comparison is made inside the query, against the database's own now(), and a
 * policy comparing an instant here would be asking a different clock: the customer's, if the
 * instant travelled through a request, or the web server's, which drifts from the database's.
 * One boolean, decided where the read decides it.
 *
 * It is the expiry comparison alone and deliberately not the whole blocking predicate. The
 * predicate's third limb counts an auto-approving approval request as occupying its slot past
 * its window, which is a statement about what is booked and not about what may be moved; the
 * booking app asks for an expiry in the future and its affordance mirrors this guard, which it
 * could not do for that limb, since a service's timeout policy is not in the guest view. So the
 * narrower question is the one asked here: a request the clock has already granted still blocks
 * its slot and is still not reschedulable, which leaves cancelling as the customer's way out of
 * it.
 */
final readonly class CancellationPolicy
{
    /**
     * @var non-empty-list<ServiceWindows>
     */
    public array $windows;

    /**
     * @param  bool  $holdIsLive  the server's answer to hold_expires_at > now(), read only when the status is a held one
     * @param  DateTimeImmutable  $firstStart  the first segment's start instant
     * @param  list<ServiceWindows>  $windows  each segment's service windows, one per segment, read from the catalog at decision time
     */
    public function __construct(
        public BookingStatus $status,
        public bool $holdIsLive,
        public DateTimeImmutable $firstStart,
        array $windows,
    ) {
        if ($windows === []) {
            throw new InvalidArgumentException('A booking has at least one segment, so the policy takes at least one segment\'s windows.');
        }

        $this->windows = $windows;
    }

    public function mayCancel(Actor $actor, DateTimeImmutable $now): PolicyDecision
    {
        return $this->decide(self::cancelRefusalByStatus($this->status), $actor, $now, $this->largestHours(fn (ServiceWindows $segment): int => $segment->cancelHours));
    }

    /**
     * The status half of mayCancel, which is the whole of it for the business: a cancel reaches
     * every state still on the books and no settled one. Public because the admin's list of the
     * actions allowed now asks it for a booking it has not locked, and asking the same function
     * is what keeps that list from growing a second idea of what is cancellable.
     */
    public static function cancelRefusalByStatus(BookingStatus $status): ?TransitionRefusal
    {
        return match ($status) {
            BookingStatus::Pending,
            BookingStatus::AwaitingApproval,
            BookingStatus::AwaitingPayment,
            BookingStatus::Confirmed => null,
            BookingStatus::Completed,
            BookingStatus::NoShow,
            BookingStatus::Cancelled,
            BookingStatus::Rejected,
            BookingStatus::Expired => TransitionRefusal::NotCancellable,
        };
    }

    /**
     * Asked for the customer and for the business. The status rule is
     * rescheduleRefusalByStatus's; the window binds the customer alone, as it does for the
     * cancel.
     */
    public function mayReschedule(Actor $actor, DateTimeImmutable $now): PolicyDecision
    {
        return $this->decide(self::rescheduleRefusalByStatus($this->status, $this->holdIsLive, $actor), $actor, $now, $this->largestHours(fn (ServiceWindows $segment): int => $segment->rescheduleHours));
    }

    /**
     * The status half of mayReschedule, which is the whole of it for the business: a confirmed
     * booking, and a held one whose hold is still live. A pending checkout hold is the customer
     * still inside the booking flow, so the business never moves one, live or lapsed; the
     * customer moves their own live one. Public because the admin's movable fact asks it for a
     * booking it has not locked, and asking the same function is what keeps that fact from
     * growing a second idea of what is movable.
     */
    public static function rescheduleRefusalByStatus(BookingStatus $status, bool $holdIsLive, Actor $actor): ?TransitionRefusal
    {
        return match ($status) {
            BookingStatus::Confirmed => null,
            BookingStatus::Pending => match ($actor) {
                Actor::Customer => $holdIsLive ? null : TransitionRefusal::HoldLapsed,
                Actor::Admin => TransitionRefusal::NotReschedulable,
            },
            BookingStatus::AwaitingApproval,
            BookingStatus::AwaitingPayment => $holdIsLive ? null : TransitionRefusal::HoldLapsed,
            BookingStatus::Completed,
            BookingStatus::NoShow,
            BookingStatus::Cancelled,
            BookingStatus::Rejected,
            BookingStatus::Expired => TransitionRefusal::NotReschedulable,
        };
    }

    /**
     * The status rule first, then the window for the actor it binds. The window closes that
     * many hours of elapsed time before the first segment's start: at the limit the customer
     * is still allowed, past it they are not. Elapsed, not wall-clock, so the limit is the
     * same number of seconds away on a clock-change day as on any other; a DateInterval of
     * hours is applied as elapsed time, where a relative "-24 hours" moves the wall clock.
     * Instants compare with their microseconds, whatever zone each is spelled in.
     */
    private function decide(?TransitionRefusal $byStatus, Actor $actor, DateTimeImmutable $now, int $hours): PolicyDecision
    {
        if ($byStatus !== null) {
            return PolicyDecision::refused($this->status, $byStatus);
        }

        $bound = match ($actor) {
            Actor::Customer => true,
            Actor::Admin => false,
        };

        if ($bound && $now > $this->firstStart->sub(new DateInterval(sprintf('PT%dH', $hours)))) {
            return PolicyDecision::refused($this->status, TransitionRefusal::WindowClosed);
        }

        return PolicyDecision::allowed($this->status);
    }

    /**
     * @param  callable(ServiceWindows): int  $hours
     */
    private function largestHours(callable $hours): int
    {
        return max(array_map($hours, $this->windows));
    }
}
