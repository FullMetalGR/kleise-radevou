<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\AdminSession;
use App\Platform\SupportSessions;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The support session's link, on the tenant's own host under the admin prefix: GET
 * /admin/support/{token}, public, the token being the credential, neither gated by the subscription
 * nor under a limit, as no page of this host is.
 *
 * A token that claims its row, SupportSessions::claim, ends whatever admin session the browser
 * held: a support session there has its row ended and support_ended recorded, and the web guard is
 * signed out so the browser can never fall back into a user when support ends. It then regenerates
 * the session with the row's id under the support key and lands on Home, support holding
 * viewBookings, where AdminSession answers the support principal. Every other token, opened, ended,
 * lapsed, past its five minutes, unknown, tampered, another tenant's or of the wrong shape, answers
 * the sign-in page with the error flash and opens nothing, leaving any session there was as it
 * stood. The token's shape is the route's to judge: a path that is not 64 hex characters reaches
 * malformed(), which answers as for a link nobody holds.
 *
 * The URL carries a credential, so the session never keeps it as its previous one
 * (KeepLinkTokensOutOfTheSession), and every answer here is a redirect naming no token. Nothing
 * is mailed: the tenant is not told when a session starts.
 */
final readonly class SupportController
{
    /** The refusal of a link nobody holds, a key of lang/{en,el}/admin/shell.php read in the request's language. */
    public const string INVALID = 'admin/shell.refusals.support_link';

    public function __construct(
        private SupportSessions $sessions,
        private AdminSession $session,
        private CurrentTenant $current,
    ) {}

    public function open(Request $request, string $token): RedirectResponse
    {
        $id = $this->sessions->claim($this->current->get(), $token);

        if ($id === null) {
            return self::invalid($request);
        }

        $this->session->endSupport($request);
        Auth::guard('web')->logout();
        $this->session->startSupport($request, $id);

        return redirect(AdminSession::at($request, AdminSession::PREFIX));
    }

    /**
     * A link whose token is not of the shape a link carries: no row can hold it, so it answers
     * exactly as a dead one does.
     */
    public function malformed(Request $request): RedirectResponse
    {
        return self::invalid($request);
    }

    private static function invalid(Request $request): RedirectResponse
    {
        $request->session()->flash('flash', ['kind' => 'error', 'message' => __(self::INVALID)]);

        return redirect(AdminSession::at($request, AdminSession::LOGIN));
    }
}
