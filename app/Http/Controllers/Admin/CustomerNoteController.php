<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\AdminSession;
use App\Http\CustomerPayload;
use App\Http\Requests\CustomerNoteRequest;
use App\Http\Requests\StaffRequest;
use App\Infrastructure\Db\CustomerNotes;
use App\Infrastructure\Db\CustomerRecords;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use LogicException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * POST /admin/customers/{email}/notes and DELETE /admin/customers/{email}/notes/{id}: the Notes
 * card of a customer's page, a note added under the signed-in principal's actor and name, the
 * user's or the support operator's, and a note of the address removed. Under manageBookings and
 * outside the subscription gate, as the profile's save is: what the business keeps about its
 * customer is no configuration of the product. The address is matched as the page's route
 * matches it, and one no booking was made under is not-found, as its page is; so is a note that
 * is not the address's own. Each answers a redirect back to the page with its flash, which
 * re-reads every prop. No trail row is written: the trail is the bookings', and a note is about
 * no booking.
 */
final readonly class CustomerNoteController
{
    /** The flashes, keys of customers.php worded in the request's language. */
    public const string ADDED = 'admin/customers.flashes.note_added';

    public const string REMOVED = 'admin/customers.flashes.note_removed';

    public function __construct(
        private CurrentTenant $current,
        private AdminSession $session,
        private CustomerRecords $customers,
        private CustomerNotes $notes,
    ) {}

    public function store(CustomerNoteRequest $request, string $email): RedirectResponse
    {
        $tenant = $this->current->get();
        $this->known($email);
        $principal = $this->session->principal($request) ?? throw new LogicException('A note was written without a principal; its route declares a permission.');

        $this->notes->add($tenant, $email, $request->body(), $principal->actor(), $principal->name);

        return self::back($request, $email, self::ADDED);
    }

    public function destroy(Request $request, string $email, string $id): RedirectResponse
    {
        $this->known($email);

        if (preg_match('/^[1-9][0-9]{0,17}$/', $id) !== 1 || ! $this->notes->remove($this->current->get(), $email, (int) $id)) {
            throw new NotFoundHttpException;
        }

        return self::back($request, $email, self::REMOVED);
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
