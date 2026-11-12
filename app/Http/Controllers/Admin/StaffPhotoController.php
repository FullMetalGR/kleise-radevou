<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Billing\Feature;
use App\Http\AdminSession;
use App\Http\ErrorEnvelope;
use App\Http\FeatureGate;
use App\Http\Requests\StaffPhotoRequest;
use App\Http\Requests\StaffRequest;
use App\Infrastructure\Db\StaffRecords;
use App\Infrastructure\Storage\ImageRefused;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Tenant;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A staff member's photo on their detail page: an upload replacing whatever was there, and a
 * removal, each its own write apart from the member's Save for the logo's three reasons, under
 * the same permission, gate and feature as every staff write.
 *
 * The feature is asked first and the member second, both before the body is read: without the
 * staff feature, for an id no row holds and for the implicit member, which stands for the
 * business and has no face, each route answers not-found and stores nothing. The file, the row
 * and the file the row let go are StaffRecords::swapPhoto's, under the member's row lock and the
 * staff photo lock, so a member deleted after the check is not-found with nothing stored, and a
 * file another member holds is never deleted under them (P9F-L13-a).
 */
final readonly class StaffPhotoController
{
    /** The two flashes, keys of staff.php worded in the request's language. */
    public const string UPLOADED = 'admin/staff.flashes.photo_uploaded';

    public const string REMOVED = 'admin/staff.flashes.photo_removed';

    public function __construct(
        private CurrentTenant $current,
        private StaffRecords $staff,
        private FeatureGate $gate,
    ) {}

    public function store(int $id): RedirectResponse
    {
        $tenant = $this->member($id);
        $request = app(StaffPhotoRequest::class);

        try {
            $found = $this->staff->swapPhoto($tenant, $id, $request->photo());
        } catch (ImageRefused) {
            throw new HttpResponseException(ErrorEnvelope::for(ValidationException::withMessages([StaffPhotoRequest::FIELD => __(StaffPhotoRequest::TYPE)]))->toResponse());
        }

        if (! $found) {
            throw new NotFoundHttpException;
        }

        $request->session()->flash('flash', ['kind' => 'success', 'message' => StaffRequest::line(self::UPLOADED)]);

        return redirect()->back(fallback: AdminSession::at($request, StaffController::list().'/'.$id));
    }

    public function destroy(Request $request, int $id): RedirectResponse
    {
        if (! $this->staff->swapPhoto($this->member($id), $id, null)) {
            throw new NotFoundHttpException;
        }

        $request->session()->flash('flash', ['kind' => 'success', 'message' => StaffRequest::line(self::REMOVED)]);

        return redirect()->back(fallback: AdminSession::at($request, StaffController::list().'/'.$id));
    }

    /**
     * The tenant, once the feature is held and the id names a member who can have a photo.
     */
    private function member(int $id): Tenant
    {
        $this->gate->require(Feature::Staff);
        $tenant = $this->current->get();

        if (! $this->staff->takesPhoto($tenant, $id)) {
            throw new NotFoundHttpException;
        }

        return $tenant;
    }
}
