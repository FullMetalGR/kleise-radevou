<?php

declare(strict_types=1);

namespace App\Http;

use App\Application\BookingUnknown;
use App\Application\Checkpoint;
use App\Application\Dto\BookingAsRead;
use App\Application\Dto\BookingRecord;
use App\Application\TransitionRefused;
use App\Application\WriteContended;
use App\Domain\Enum\BookingStatus;
use App\Infrastructure\BookingTransitionFactory;
use App\Infrastructure\Db\Busy;
use App\Notifications\ClosureCause;
use App\Notifications\ClosureCauses;
use App\Tenancy\Tenant;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Throwable;

/**
 * The cancel of every booking inside a closure just added, one at a time, each through the same
 * transition the admin's own buttons run: a request is rejected, any other booking cancelled by
 * the business. Each is its own guarded write, and the closure is already written and stays, so
 * a booking that does not go through is left as it is and answered among those left, and the
 * rest go through.
 *
 * Each transition is held to the booking as the closure's own transaction read it. Between that
 * read and the booking's turn somebody may have moved it out of the closure, which leaves its
 * status as it was, or paid for it; the transition asks under the booking's lock whether it is
 * still the booking read, the same status and every service on the same member over the same
 * time, and refuses when it is not. A booking is left, then, when its transition refuses it,
 * when it is no longer there, and when its locks could not be had.
 *
 * It is left as well when its write fails in any other way, and that failure is handed to the
 * exception handler: one booking's broken write must not leave the rest untouched under a
 * closure already written, with nobody told which bookings went.
 *
 * Around each transition the closure is named as the cause, so the customer is told in the
 * closure's words in place of the usual ones.
 */
final readonly class ClosureCancellations
{
    /** Reached once the closure is written and before the first booking is cancelled. */
    public const string BEGIN = 'closure.cancelling';

    public function __construct(
        private BookingTransitionFactory $transitions,
        private ClosureCauses $causes,
        private ExceptionHandler $handler,
    ) {}

    /**
     * Cancels or rejects each booking as the principal and answers those left as they were.
     *
     * @param  list<BookingRecord>  $bookings
     * @return list<BookingRecord>
     */
    public function run(Tenant $tenant, Principal $principal, array $bookings, ?string $reason): array
    {
        Checkpoint::reach(self::BEGIN);

        $actor = $principal->actor();
        $cause = new ClosureCause($reason);
        $skipped = [];

        foreach ($bookings as $booking) {
            $uuid = $booking->snapshot->uuid;

            try {
                $this->causes->during($uuid, $cause, function () use ($tenant, $booking, $uuid, $actor, $reason): void {
                    $asRead = BookingAsRead::of($booking);

                    if ($booking->snapshot->status === BookingStatus::AwaitingApproval) {
                        $this->transitions->reject($tenant)->reject($uuid, $actor, $reason, $asRead);
                    } else {
                        $this->transitions->cancel($tenant)->cancel($uuid, $actor, $asRead);
                    }
                });
            } catch (TransitionRefused|BookingUnknown|WriteContended|Busy) {
                $skipped[] = $booking;
            } catch (Throwable $failure) {
                $this->handler->report($failure);
                $skipped[] = $booking;
            }
        }

        return $skipped;
    }
}
