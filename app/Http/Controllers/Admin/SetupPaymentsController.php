<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\AdminSession;
use App\Http\Requests\PlatformConnectRequest;
use App\Http\Setup\SetupWizard;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * The Online payments step's way out to the provider and back. The step shows Settings' Payments
 * section whole and runs its writes on their own routes, save for the two that send the owner to
 * the provider's hosted onboarding: Connect through us and Continue onboarding. Those two run here,
 * Settings' own actions told to bring the owner back to RETURN rather than to Settings, so the
 * provider's way back is one of two fixed places the server picks by the route, never a value the
 * request carries. Each is refused with the 403 to anyone not in setup.
 *
 * RETURN, the provider's way back, lands on Home: with the wizard opened on Online payments for an
 * owner still in setup, and plain Home for anyone else, setup finished included. Whatever the
 * address carries beside its path is ignored.
 *
 * None of the three records setup as finished. The provider's notice can close the last open step
 * while the owner is away or about to press Continue; the write still lands, the way back still
 * lands on Home, and Home's answer is the one that says setup is complete.
 */
final readonly class SetupPaymentsController
{
    /** The provider's way back from the step's onboarding, under the admin prefix. */
    public const string RETURN = AdminSession::PREFIX.'/setup/payments/back';

    /** The step this way back opens the wizard on. */
    private const string STEP = 'payments';

    public function __construct(
        private CurrentTenant $current,
        private AdminSession $session,
        private SetupWizard $wizard,
        private PaymentsController $payments,
    ) {}

    public function connect(PlatformConnectRequest $request): Response
    {
        $this->refuseOutsideSetup($request);

        return $this->payments->connectReturningTo($request, self::RETURN);
    }

    public function resume(Request $request): Response
    {
        $this->refuseOutsideSetup($request);

        return $this->payments->resumeReturningTo($request, self::RETURN);
    }

    public function back(Request $request): RedirectResponse
    {
        if ($this->inSetup($request)) {
            $request->session()->flash(SetupWizard::OPEN_ON, self::STEP);
        }

        return redirect()->to(AdminSession::at($request, AdminSession::PREFIX));
    }

    private function refuseOutsideSetup(Request $request): void
    {
        if (! $this->inSetup($request)) {
            throw new AccessDeniedHttpException;
        }
    }

    private function inSetup(Request $request): bool
    {
        $viewer = $this->session->principal($request);

        return $viewer !== null && $this->wizard->progress($this->current->get(), $viewer) !== null;
    }
}
