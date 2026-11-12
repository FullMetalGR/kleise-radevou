<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Billing\Feature;
use App\Http\AdminSession;
use App\Http\FeatureGate;
use App\Http\Requests\StaffColourRequest;
use App\Http\Requests\StaffRequest;
use App\Infrastructure\Db\StaffRecords;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * PUT /admin/staff/{id}/colour: a member's own calendar colour from the Staff screen's Calendar
 * colours card, one word of the palette or null for Automatic, which every grid and the Shifts
 * screen draw the member in from the next read. A route of its own, as Colour by's is, because
 * the member's save takes the whole row and the card sends this word alone. It answers a redirect
 * back with its flash.
 *
 * Without the staff feature it answers not-found as the Staff screen's writes do, asked before
 * the body is read, and a member nobody has is not-found too; manageSettings and the subscription
 * gate are declared on its route.
 */
final readonly class StaffColourController
{
    /** The flash, a key of staff.php worded in the request's language. */
    public const string SAVED = 'admin/staff.flashes.colour_saved';

    public function __construct(
        private CurrentTenant $current,
        private StaffRecords $staff,
        private FeatureGate $gate,
    ) {}

    public function __invoke(int $id): RedirectResponse
    {
        $this->gate->require(Feature::Staff);
        $request = app(StaffColourRequest::class);

        if (! $this->staff->setColour($this->current->get(), $id, $request->colour())) {
            throw new NotFoundHttpException;
        }

        $request->session()->flash('flash', ['kind' => 'success', 'message' => StaffRequest::line(self::SAVED)]);

        return redirect()->back(fallback: AdminSession::at($request, StaffController::list()));
    }
}
