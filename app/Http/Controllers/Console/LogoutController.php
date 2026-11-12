<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Http\ConsoleSession;
use App\Platform\PlatformAudit;
use App\Platform\PlatformAuditAction;
use App\Platform\PlatformAuditEntry;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Signing out of the console: the session ends, its token is renewed, and the door says so in a
 * toast. Public, as the admin's sign-out is: whoever posts it leaves signed out, and a visitor who
 * was never signed in lands where they would have been sent anyway.
 *
 * An operator's sign-out is recorded as sign_out in the platform audit log, before the session
 * ends, while the guard still names who is leaving; a visitor's names nobody and records nothing.
 * A session the timeouts end is not a sign-out and is not recorded here.
 */
final readonly class LogoutController
{
    public function __construct(private ConsoleSession $session, private DatabaseManager $db) {}

    public function __invoke(Request $request): RedirectResponse
    {
        $operator = $this->session->operator($request);

        if ($operator !== null) {
            PlatformAudit::record($this->db->connection(), $operator->id, new PlatformAuditEntry(PlatformAuditAction::SignOut), null);
        }

        return $this->session->end($request, ['kind' => 'success', 'message' => LoginController::SIGNED_OUT]);
    }
}
