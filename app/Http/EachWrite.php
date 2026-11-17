<?php

declare(strict_types=1);

namespace App\Http;

use App\Application\BookingUnknown;
use App\Application\TransitionRefused;
use App\Application\WriteContended;
use App\Infrastructure\Db\Busy;
use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Throwable;

/**
 * One booking's write in a run of them over many bookings, where each is its own guarded write
 * and one that does not go through must not stop the rest. A write that went through answers
 * null; one that did not answers why, and the run counts it as left and goes on.
 *
 * A guard's refusal and a booking nobody has mean the booking changed meanwhile; a write that
 * could not take its locks means it was busy. A write that fails any other way is handed to the
 * exception handler before it is counted as left, so one broken write never leaves the rest
 * undone with nobody told.
 */
final readonly class EachWrite
{
    public function __construct(private ExceptionHandler $handler) {}

    /**
     * @param  Closure(): mixed  $write
     */
    public function attempt(Closure $write): ?LeftBecause
    {
        try {
            $write();
        } catch (TransitionRefused|BookingUnknown) {
            return LeftBecause::Changed;
        } catch (WriteContended|Busy) {
            return LeftBecause::Busy;
        } catch (Throwable $failure) {
            $this->handler->report($failure);

            return LeftBecause::Failed;
        }

        return null;
    }
}
