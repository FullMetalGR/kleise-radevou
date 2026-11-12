<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\AdminSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Signing out: the session ends, its token is renewed, and the sign-in page says so in a toast.
 * Public, as the table has it: whoever posts it leaves signed out, and a visitor who was never
 * signed in lands where they would have been sent anyway. The shell's Sign out and the support
 * banner's End session both post here.
 *
 * A support session's row is ended first: ended_at written as the clock's now and support_ended
 * recorded with the tenant in one transaction, before the session is invalidated; a session the
 * clock has lapsed records nothing. The session is then invalidated whole, the support key with
 * it, and the next request is the sign-in page.
 */
final readonly class LogoutController
{
    public function __construct(private AdminSession $session) {}

    public function __invoke(Request $request): RedirectResponse
    {
        $this->session->endSupport($request);
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $request->session()->flash('flash', ['kind' => 'success', 'message' => __(LoginController::SIGNED_OUT)]);

        return redirect(AdminSession::at($request, AdminSession::LOGIN));
    }
}
