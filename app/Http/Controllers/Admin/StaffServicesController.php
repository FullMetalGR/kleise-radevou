<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Billing\Feature;
use App\Http\AdminSession;
use App\Http\ErrorEnvelope;
use App\Http\FeatureGate;
use App\Http\Requests\StaffRequest;
use App\Http\Requests\StaffServicesRequest;
use App\Infrastructure\Db\ServiceRecords;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * PUT /admin/staff/{id}/services: the services one member performs, replaced whole. The service
 * page's Save sends one of these per member whose tick changed, after the service patch and one
 * at a time, each carrying that member's other services beside the one being ticked or cleared.
 * The Staff screen will send the same body from its own side.
 *
 * It answers a redirect back with no flash of its own: the save it is part of has already said
 * what happened, and the links arrive on the page through the reload the redirect causes. An id
 * naming no service of this tenant is a 400 in words, before the foreign key can make it a
 * query error; a member nobody has is a 404, and so is every member without the staff feature,
 * since per-service assignment is what the feature sells.
 */
final readonly class StaffServicesController
{
    /** The refusal's key in staff.php, worded in the request's language. */
    public const string UNKNOWN_SERVICE = 'admin/staff.refusals.unknown_service';

    public function __construct(
        private CurrentTenant $current,
        private ServiceRecords $services,
        private FeatureGate $gate,
    ) {}

    public function __invoke(int $id): RedirectResponse|JsonResponse
    {
        $this->gate->require(Feature::Staff);
        $request = app(StaffServicesRequest::class);
        $tenant = $this->current->get();
        $serviceIds = $request->serviceIds();

        if (! $this->services->allExist($tenant, $serviceIds)) {
            return (new ErrorEnvelope('invalid_request', 400, StaffRequest::line(self::UNKNOWN_SERVICE)))->toResponse();
        }

        if (! $this->services->replaceMemberServices($tenant, $id, $serviceIds)) {
            throw new NotFoundHttpException;
        }

        return redirect()->back(fallback: AdminSession::at($request, AdminSession::PREFIX.'/services'));
    }
}
