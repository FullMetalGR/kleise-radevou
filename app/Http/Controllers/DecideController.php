<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\TransitionRefused;
use App\Application\WriteContended;
use App\Domain\Audit\Actor;
use App\Domain\Enum\Decision;
use App\Http\ApprovalWindows;
use App\Http\DecisionPages;
use App\Http\PresentedLink;
use App\Http\Requests\DecisionRequest;
use App\Infrastructure\BookingTransitionFactory;
use App\Infrastructure\Db\BookingReader;
use App\Infrastructure\Db\Busy;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * POST /decide/{uuid}/{decision} on the tenant subdomain: the one human click that decides.
 *
 * **It decides as the user the link was minted for.** That is what makes a decision link worth
 * minting per person: the audit row and approved_by name who said yes, and a link somebody
 * forwarded still decides as the person it was sent to rather than as whoever opened it. No
 * session is read for an actor here: the signature is the whole authentication.
 *
 * It calls ApproveBooking and RejectBooking, the use cases, and adds nothing of its own: the
 * guard, the lock, the audit row and the event are theirs, and the admin's booking actions call
 * the same two.
 *
 * **Losing a race is not an error here.** A decision that arrives after somebody else's, after a
 * reschedule has moved the booking's items out from under it, or after the window closed, answers
 * the outcome page saying where the booking stands now, which is the same page a replayed link
 * gets and the same page this one answers with when it succeeds. A staff-day held past the lock
 * timeout (Busy) is a race lost the same way. Retries exhausted is not caught here: it reaches the
 * handler and is reported, as it is at every route but the pay hop's. From the owner's side
 * pressing a link twice is not a mistake worth a stack trace, and a second press that said
 * something different would be telling them their click went wrong when the booking is exactly as
 * they wanted it. The error envelope is no answer for either: it renders for api/*, admin/api/*
 * and webhooks/* alone (bootstrap/app.php), and these are pages, which get none.
 */
final class DecideController extends Controller
{
    public function __invoke(
        DecisionRequest $request,
        PresentedLink $presented,
        CurrentTenant $current,
        BookingReader $bookings,
        ApprovalWindows $windows,
        BookingTransitionFactory $transitions,
        DecisionPages $pages,
    ): Response {
        $tenant = $current->get();
        $link = $presented->get();
        $booking = $bookings->snapshot($tenant, $link->uuid);

        if ($booking === null) {
            throw new NotFoundHttpException;
        }

        if ($windows->of($tenant, $booking, $link)->isOpen) {
            $actor = Actor::user($link->userId);

            try {
                match ($link->decision) {
                    Decision::Approve => $transitions->approve($tenant)->approve($link->uuid, $actor),
                    Decision::Reject => $transitions->reject($tenant)->reject($link->uuid, $actor, $request->reason()),
                };
            } catch (TransitionRefused|WriteContended|Busy) {
                // The three ways a decision can lose a race, and all of them lose politely here. A
                // refusal is somebody else having settled the booking between the read above and
                // the lock below it. A contended write is somebody else having moved its items off
                // the staff-days the write locked, twice, which is a reschedule landing while the
                // owner pressed the button. Busy is a staff-day held past the lock timeout, whose
                // one log line the runner already wrote. None is worth a stack trace to a person
                // who clicked a link in their mail, and none wrote anything. What the booking is
                // now is read back with everything else.
            }
        }

        // Read again rather than describing what was just written: the page has to say where
        // the booking stands, and after a transition that is the row and not the intention.
        // The window is asked again of that re-read for the same reason: a window computed
        // from the read above would describe a request this POST has just settled as still
        // waiting. What the second asking costs is ApprovalWindows': nothing once the row is
        // settled, since a settled status answers before any statement, and one or two
        // single-column selects for a request the POST left waiting.
        $decided = $bookings->snapshot($tenant, $link->uuid);

        if ($decided === null) {
            throw new NotFoundHttpException;
        }

        return $pages->outcome($tenant, $decided, $link, $windows->of($tenant, $decided, $link));
    }
}
