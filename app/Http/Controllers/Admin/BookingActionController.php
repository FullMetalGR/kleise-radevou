<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\BookingUnknown;
use App\Application\ChainUnavailable;
use App\Application\NoSuchSegment;
use App\Application\StaffNotEligible;
use App\Application\TransitionRefused;
use App\Application\UnacceptedWarning;
use App\Application\WriteContended;
use App\Domain\Booking\SegmentConflict;
use App\Domain\Enum\BookingOutcome;
use App\Http\AdminSession;
use App\Http\ApproverScope;
use App\Http\ErrorEnvelope;
use App\Http\MoveRefusal;
use App\Http\Principal;
use App\Http\Requests\MoveRequest;
use App\Http\Requests\RejectRequest;
use App\Infrastructure\BookingTransitionFactory;
use App\Infrastructure\Db\Busy;
use App\Tenancy\CurrentTenant;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use LogicException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * The booking dialog's five actions under /admin: ordinary form posts, each calling its use case
 * with the principal's actor, the signed-in user's or support's, and answering an Inertia redirect
 * back with a flash. None of them is gated by the subscription: the booking lifecycle never is.
 *
 * Approve and reject declare approveBookings on the route, which alone is enough, and run the rest
 * of the approver checks here on every press, because a role can change and a staff row be
 * reassigned between the render and the press: the principal is read back by the session on this
 * request, and ApproverScope holds one without manageBookings to their own staff row. A press
 * outside that scope is a 403, the same answer the route gives a missing permission. Cancel,
 * complete and no-show declare manageBookings, which carries no scope.
 *
 * What the use case answers is the flash. Success is a sentence saying what happened, a key of
 * lang/{locale}/admin/bookings.php's flashes worded in the request's language, the user's; a
 * guard's refusal, a booking nobody has and a write that could not take its locks are the sentence
 * the wire already words for each, ErrorEnvelope, resolved there, as an error flash the toaster
 * keeps until it is dismissed. The dialog stays open because the redirect goes back to the page
 * that asked, and the server's answer reaches it through the reload rather than through anything
 * the client assumed.
 *
 * The move is the sixth, under manageBookings as cancel is and answering its success as cancel
 * does. Its refusals are the manual booking's way rather than a flash, the envelope at its status,
 * MoveRefusal's code with what it names beside it (the segment, the rule, the crossings), because
 * the Move form and the drag have something better to do with them than show a sentence: put the
 * block back, name the member and the closure, ask again with the warning accepted. A staff
 * viewer, the one viewer pinned to their own calendar, holds no manageBookings and so never
 * reaches it, as they never reach cancel.
 */
final readonly class BookingActionController
{
    public const string APPROVED = 'admin/bookings.flashes.approved';

    public const string REJECTED = 'admin/bookings.flashes.rejected';

    public const string CANCELLED = 'admin/bookings.flashes.cancelled';

    public const string COMPLETED = 'admin/bookings.flashes.completed';

    public const string NO_SHOW = 'admin/bookings.flashes.no_show';

    public const string MOVED = 'admin/bookings.flashes.moved';

    public function __construct(
        private CurrentTenant $current,
        private AdminSession $session,
        private ApproverScope $scope,
        private BookingTransitionFactory $transitions,
    ) {}

    public function approve(Request $request, string $uuid): RedirectResponse
    {
        $principal = $this->decider($request, $uuid);

        return $this->answer($request, $uuid, self::APPROVED, fn () => $this->transitions->approve($this->current->get())->approve($uuid, $principal->actor()));
    }

    public function reject(RejectRequest $request, string $uuid): RedirectResponse
    {
        $principal = $this->decider($request, $uuid);
        $reason = $request->reason();

        return $this->answer($request, $uuid, self::REJECTED, fn () => $this->transitions->reject($this->current->get())->reject($uuid, $principal->actor(), $reason));
    }

    public function cancel(Request $request, string $uuid): RedirectResponse
    {
        $principal = $this->signedIn($request);

        return $this->answer($request, $uuid, self::CANCELLED, fn () => $this->transitions->cancel($this->current->get())->cancel($uuid, $principal->actor()));
    }

    public function complete(Request $request, string $uuid): RedirectResponse
    {
        $principal = $this->signedIn($request);

        return $this->answer($request, $uuid, self::COMPLETED, fn () => $this->transitions->markOutcome($this->current->get())->mark($uuid, BookingOutcome::Completed, $principal->actor()));
    }

    public function noShow(Request $request, string $uuid): RedirectResponse
    {
        $principal = $this->signedIn($request);

        return $this->answer($request, $uuid, self::NO_SHOW, fn () => $this->transitions->markOutcome($this->current->get())->mark($uuid, BookingOutcome::NoShow, $principal->actor()));
    }

    /**
     * Moves the booking to the start given, the members given by segment position, as the
     * principal: the redirect back with the success flash, or the refusal's envelope.
     */
    public function move(MoveRequest $request, string $uuid): RedirectResponse|JsonResponse
    {
        $principal = $this->signedIn($request);

        try {
            $this->transitions->reschedule($this->current->get())->reschedule($uuid, $principal->actor(), $request->start(), $request->choices());
        } catch (BookingUnknown|ChainUnavailable|StaffNotEligible|NoSuchSegment|SegmentConflict|UnacceptedWarning|TransitionRefused|WriteContended|Busy $refused) {
            return MoveRefusal::for($refused)->toResponse();
        }

        $line = __(self::MOVED);
        $request->session()->flash('flash', ['kind' => 'success', 'message' => is_string($line) ? $line : self::MOVED]);

        return redirect()->back(fallback: AdminSession::at($request, AdminSession::PREFIX.'/bookings/'.$uuid));
    }

    /**
     * The principal, when they may decide this booking: the permissions and the staff scope,
     * asked now and not at render.
     */
    private function decider(Request $request, string $uuid): Principal
    {
        $principal = $this->signedIn($request);

        if (! $this->scope->admitsPrincipal($this->current->get(), $uuid, $principal)) {
            throw new AccessDeniedHttpException;
        }

        return $principal;
    }

    /**
     * The route's permission middleware has already answered a request with no principal at
     * this tenant, so a null here is a route declared without one.
     */
    private function signedIn(Request $request): Principal
    {
        return $this->session->principal($request) ?? throw new LogicException('A booking action ran without a principal; its route declares no permission.');
    }

    /**
     * Runs the act and answers the redirect back with its flash, the success key worded in the
     * request's language; a key the file lacks reads as the key.
     */
    private function answer(Request $request, string $uuid, string $success, Closure $act): RedirectResponse
    {
        try {
            $act();
            $line = __($success);
            $flash = ['kind' => 'success', 'message' => is_string($line) ? $line : $success];
        } catch (TransitionRefused|BookingUnknown|WriteContended|Busy $refused) {
            $flash = ['kind' => 'error', 'message' => ErrorEnvelope::for($refused)->detail];
        }

        $request->session()->flash('flash', $flash);

        return redirect()->back(fallback: AdminSession::at($request, AdminSession::PREFIX.'/bookings/'.$uuid));
    }
}
