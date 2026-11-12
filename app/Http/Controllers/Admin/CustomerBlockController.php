<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\AdminSession;
use App\Http\CustomerPayload;
use App\Http\Requests\CustomerBlockRequest;
use App\Http\Requests\StaffRequest;
use App\Infrastructure\Db\CustomerProfiles;
use App\Infrastructure\Db\CustomerRecords;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * PUT and DELETE /admin/customers/{email}/block: the Online booking card of a customer's page.
 * PUT blocks the address from booking online with the reason the staff gave, or none, stamped
 * with the Clock's instant, the row made where none is and the profile's fields left as they
 * stand; DELETE lifts the block, clearing the instant and the reason. The hold then refuses the
 * address with one sentence, and the admin's wizard still books it. Under manageBookings and
 * outside the subscription gate, as the profile's save is. The address is matched as the page's
 * route matches it, and one no booking was made under is not-found. Each answers a redirect back
 * to the page with its flash. No trail row is written: the trail is the bookings', and a block is
 * about no booking; the row's own instant says when.
 */
final readonly class CustomerBlockController
{
    /** The flashes, keys of customers.php worded in the request's language. */
    public const string BLOCKED = 'admin/customers.flashes.blocked';

    public const string UNBLOCKED = 'admin/customers.flashes.unblocked';

    public function __construct(
        private CurrentTenant $current,
        private CustomerRecords $customers,
        private CustomerProfiles $profiles,
    ) {}

    public function update(CustomerBlockRequest $request, string $email): RedirectResponse
    {
        $this->known($email);
        $this->profiles->block($this->current->get(), $email, $request->reason());

        return self::back($request, $email, self::BLOCKED);
    }

    public function destroy(Request $request, string $email): RedirectResponse
    {
        $this->known($email);
        $this->profiles->unblock($this->current->get(), $email);

        return self::back($request, $email, self::UNBLOCKED);
    }

    private function known(string $email): void
    {
        if ($this->customers->one($this->current->get(), $email) === null) {
            throw new NotFoundHttpException;
        }
    }

    private static function back(Request $request, string $email, string $flash): RedirectResponse
    {
        $request->session()->flash('flash', ['kind' => 'success', 'message' => StaffRequest::line($flash)]);

        return redirect()->back(fallback: AdminSession::at($request, CustomerPayload::url($email)));
    }
}
