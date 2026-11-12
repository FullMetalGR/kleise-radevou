<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\ChainUnavailable;
use App\Application\ServiceNotBookable;
use App\Application\StaffNotEligible;
use App\Application\WriteContended;
use App\Domain\Booking\SegmentConflict;
use App\Http\AdminSession;
use App\Http\ApiLines;
use App\Http\ErrorEnvelope;
use App\Http\Requests\NewBookingRequest;
use App\Infrastructure\Db\Busy;
use App\Infrastructure\Db\Pool;
use App\Infrastructure\HoldBookingFactory;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Languages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use LogicException;

/**
 * POST /admin/bookings: the manual booking. The chain the wizard sends is planned from the
 * catalog by the planner a hold uses and written under the same protocol, landing confirmed
 * directly with no hold, the trail row the principal's act, the signed-in user's or support's,
 * and the customer's confirmation and the business's notice going out after the commit. Not
 * gated by the subscription: the booking lifecycle never is.
 *
 * Success is an ordinary form post's answer, a redirect back with a success flash: the wizard
 * closes on it, and the list, the detail and the calendar arrive re-read through the reload, so
 * nothing is optimistic. A refusal is the error envelope answered as itself, the way a
 * configuration write's validation refusal is answered, AdminWriteRequest: the wizard shows its
 * detail as an error toast, a conflict naming the failing segment, and stays open with every
 * answer intact. A 409 here is normal: the availability read is advisory and this write is the
 * authority.
 *
 * The lead time and the horizon do not bind the business, so the one rule of the planner's clock
 * left to refuse a start here is that it has already passed. That refusal says so in its own
 * sentence, the move's, and names its rule beside the segment, so the wizard does not tell an
 * operator who picked a time a minute ago that the time is simply unavailable.
 */
final readonly class NewBookingController
{
    /** The success flash, a key of customers.php's flashes worded in the request's language. */
    public const string CREATED = 'admin/customers.flashes.booked';

    /** The sentence for a start already passed, the move's own (api.php's move group). */
    private const string PASSED = 'api.move.past';

    public function __construct(
        private CurrentTenant $current,
        private AdminSession $session,
        private HoldBookingFactory $bookings,
        private Languages $languages,
    ) {}

    public function __invoke(NewBookingRequest $request): RedirectResponse|JsonResponse
    {
        $principal = $this->session->principal($request) ?? throw new LogicException('The manual booking ran without a principal; its route declares no permission.');
        $tenant = $this->current->get();

        try {
            // The booking's language is the tenant default at its create, never the staff
            // member's own.
            $this->bookings->for($tenant, Pool::Whole)->book($request->toHoldRequest($this->languages->tenantDefault($tenant)), $principal->actor());
        } catch (ChainUnavailable $refused) {
            return ($refused->rule === ChainUnavailable::PAST ? self::passed($refused) : ErrorEnvelope::for($refused))->toResponse();
        } catch (ServiceNotBookable|StaffNotEligible|SegmentConflict|WriteContended|Busy $refused) {
            return ErrorEnvelope::for($refused)->toResponse();
        }

        $line = __(self::CREATED);
        $request->session()->flash('flash', ['kind' => 'success', 'message' => is_string($line) ? $line : self::CREATED]);

        return redirect()->back(fallback: AdminSession::at($request, AdminSession::PREFIX.'/bookings'));
    }

    /** A start already passed: unavailable as any refused start is, in the move's sentence for it, with its rule. */
    private static function passed(ChainUnavailable $refused): ErrorEnvelope
    {
        return new ErrorEnvelope('unavailable', 409, ApiLines::line(self::PASSED), ['segment' => $refused->segment, 'rule' => $refused->rule]);
    }
}
