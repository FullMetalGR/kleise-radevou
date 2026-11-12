<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Billing\Feature;
use App\Http\AdminSession;
use App\Http\FeatureGate;
use App\Http\Requests\BlackoutRequest;
use App\Http\Requests\StaffRequest;
use App\Infrastructure\Db\BlackoutRecords;
use App\Infrastructure\Db\StaffRecords;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The closures: POST and DELETE /admin/blackouts for the tenant-wide ones, and the same pair
 * under /admin/staff/{id}/blackouts for one member's. A removal names the date and the window
 * the operator saw, and one that matches nothing is a 404, since what they saw is no longer
 * there to remove. Either body may carry an Until: an add writes one row per date from Date to
 * Until, a removal takes every such row of the run the screen folded into one line. Each answers
 * a redirect back with a flash of its own, because each is a press of its own on the card.
 *
 * The per-member pair answers not-found without the staff feature, as the Staff screen's writes
 * do, and the tenant-wide pair stays on the floor: closing the business for a day is not a
 * feature.
 */
final readonly class BlackoutController
{
    /** The two flashes, keys of staff.php worded in the request's language. */
    public const string ADDED = 'admin/staff.flashes.closure_added';

    public const string REMOVED = 'admin/staff.flashes.closure_removed';

    public function __construct(
        private CurrentTenant $current,
        private BlackoutRecords $blackouts,
        private StaffRecords $staff,
        private FeatureGate $gate,
    ) {}

    public function store(BlackoutRequest $request): RedirectResponse
    {
        return $this->add($request, null);
    }

    public function destroy(BlackoutRequest $request): RedirectResponse
    {
        return $this->remove($request, null);
    }

    public function storeForMember(int $id): RedirectResponse
    {
        $this->gate->require(Feature::Staff);

        return $this->add(app(BlackoutRequest::class), $this->member($id));
    }

    public function destroyForMember(int $id): RedirectResponse
    {
        $this->gate->require(Feature::Staff);

        return $this->remove(app(BlackoutRequest::class), $this->member($id));
    }

    private function add(BlackoutRequest $request, ?int $staffId): RedirectResponse
    {
        [$start, $end] = $request->window();
        $this->blackouts->add($this->current->get(), $staffId, $request->day(), $request->until(), $start, $end, $request->reason());
        $request->session()->flash('flash', ['kind' => 'success', 'message' => StaffRequest::line(self::ADDED)]);

        return redirect()->back(fallback: AdminSession::at($request, StaffController::list()));
    }

    private function remove(BlackoutRequest $request, ?int $staffId): RedirectResponse
    {
        [$start, $end] = $request->window();

        if ($this->blackouts->remove($this->current->get(), $staffId, $request->day(), $request->until(), $start, $end) === 0) {
            throw new NotFoundHttpException;
        }

        $request->session()->flash('flash', ['kind' => 'success', 'message' => StaffRequest::line(self::REMOVED)]);

        return redirect()->back(fallback: AdminSession::at($request, StaffController::list()));
    }

    private function member(int $id): int
    {
        if (! $this->staff->exists($this->current->get(), $id)) {
            throw new NotFoundHttpException;
        }

        return $id;
    }
}
