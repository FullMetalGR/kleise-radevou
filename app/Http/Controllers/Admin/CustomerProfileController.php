<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\AdminSession;
use App\Http\CustomerPayload;
use App\Http\Requests\CustomerProfileRequest;
use App\Http\Requests\StaffRequest;
use App\Infrastructure\Db\CustomerProfiles;
use App\Infrastructure\Db\CustomerRecords;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * PUT /admin/customers/{email}/profile: the Profile card of a customer's page, its tags,
 * birthday and preferred language written whole in one request, the row made where none is and
 * the block left as it stands. Under manageBookings, the permission both customer pages sit
 * under, and outside the subscription gate: what the business keeps about its customer is no
 * configuration of the product, as the erasure and the export are not. The address is matched as
 * the page's route matches it, and one no booking was made under is not-found, as its page is.
 * It answers a redirect back to the page with its flash, which re-reads every prop.
 */
final readonly class CustomerProfileController
{
    /** The flash, a key of customers.php worded in the request's language. */
    public const string SAVED = 'admin/customers.flashes.profile_saved';

    public function __construct(
        private CurrentTenant $current,
        private CustomerRecords $customers,
        private CustomerProfiles $profiles,
    ) {}

    public function __invoke(CustomerProfileRequest $request, string $email): RedirectResponse
    {
        $tenant = $this->current->get();

        if ($this->customers->one($tenant, $email) === null) {
            throw new NotFoundHttpException;
        }

        $this->profiles->save($tenant, $email, $request->tags(), $request->birthday(), $request->language());
        $request->session()->flash('flash', ['kind' => 'success', 'message' => StaffRequest::line(self::SAVED)]);

        return redirect()->back(fallback: AdminSession::at($request, CustomerPayload::url($email)));
    }
}
