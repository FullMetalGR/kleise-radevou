<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\AdminSession;
use App\Http\ErrorEnvelope;
use App\Http\Principal;
use App\Platform\AccountClose;
use App\Platform\CloseRefused;
use App\Platform\ClosingWindow;
use App\Platform\OwnerClose;
use App\Site\SiteLanguage;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Permission;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Closing the account from the Settings Danger zone: POST /admin/settings/close under
 * manageSettings and never the subscription gate, since the way back must stay open to a lapsed
 * owner too and a closing configures nothing. No body.
 *
 * A support principal is refused with a 403 before anything is read: an operator closes an
 * account from the console alone, where the close takes a reason and leaves an audit row, and
 * this road takes neither.
 *
 * AccountClose, the one writer the console's close shares, writes the close for the owner,
 * OwnerClose, cancels the provider subscription and mails the owners; a row already closing, or
 * not open, is the envelope at 409 with the refusal's sentence in the request's language, as the
 * customer's erasure answers its own. Nothing after the close's commit raises, so a close that
 * stands answers as one. A landed close flashes the day the account closes in the tenant's clock
 * and lands on the Billing screen, where the way back is, for a viewer holding manageBilling; a
 * viewer without it, a manager, is sent back to Settings instead of to a screen they would be
 * refused.
 */
final readonly class AccountCloseController
{
    public function __construct(
        private CurrentTenant $current,
        private AdminSession $session,
        private AccountClose $close,
        private OwnerClose $owner,
        private Translator $translator,
    ) {}

    public function __invoke(Request $request): RedirectResponse|JsonResponse
    {
        $principal = $this->session->principal($request);

        if ($principal?->kind === Principal::SUPPORT) {
            throw new AccessDeniedHttpException;
        }

        $tenant = $this->current->get();

        try {
            $closedAt = $this->close->close($tenant, $this->owner);
        } catch (CloseRefused $refused) {
            return (new ErrorEnvelope($refused->reason, 409, $refused->sentence()))->toResponse();
        }

        $request->session()->flash('flash', ['kind' => 'success', 'message' => ClosingWindow::closed(ClosingWindow::dayOf($closedAt, $tenant->zone(), SiteLanguage::from($this->translator->getLocale())))]);
        $billing = $principal?->holds(Permission::ManageBilling) === true;

        return redirect()->to(AdminSession::at($request, $billing ? BillingController::PATH : SettingsController::path()));
    }
}
