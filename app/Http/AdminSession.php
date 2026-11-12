<?php

declare(strict_types=1);

namespace App\Http;

use App\Models\User;
use App\Platform\SupportSessions;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Who is signed in to the admin of the tenant this request resolved: the principal, a signed-in
 * user of the tenant or the operator of an open support session, and nobody otherwise.
 *
 * The session cookie is host-only, so a session opened at one subdomain is never presented at
 * another. The user is still checked against the resolved tenant here, because a user belongs to
 * exactly one tenant and a session that somehow carried another tenant's user must read as
 * nobody rather than as a person who may act at this business. The support row is checked the
 * same way: a key naming another tenant's row reads as nobody.
 *
 * A support session lives in the browser's session as one key, the support_sessions row's id,
 * written by the open and by nothing else. The open ends whatever admin session the browser held
 * first, the web guard signed out before the session is regenerated, so the browser never falls
 * back into a user when support ends. The key never leaves a browser without its row's end: a
 * sign-out, a form sign-in and a second support link opened there each end the session the key
 * names before it goes, so the console's list of open sessions never shows one nobody is in, and
 * a sign-in then answers as the user who signed in. A key naming a row the clock has lapsed is
 * forgotten and records nothing.
 */
final readonly class AdminSession
{
    /**
     * The admin prefix: the hosted booking page owns the root.
     */
    public const string PREFIX = '/admin';

    public const string LOGIN = self::PREFIX.'/login';

    /** The session key the open writes the support_sessions row's id under. */
    public const string SUPPORT_KEY = 'admin.support_session';

    /** The request attribute the support read is kept under for the rest of the request. */
    private const string SUPPORT_READ = 'admin.support_principal';

    public function __construct(
        private CurrentTenant $current,
        private SupportSessions $support,
    ) {}

    /**
     * The principal this request acts as: the operator of the open support session the session
     * key names as a support principal, first; otherwise the web guard's user of this tenant as a
     * user principal; and null for anybody else. Read on every request rather than remembered,
     * because a role can change and a session end between two requests. The web guard is named,
     * as ConsoleSession names its own, rather than read as the default, which acting as an
     * operator in a test makes the console's.
     */
    public function principal(Request $request): ?Principal
    {
        $support = $this->supportSessionOf($request);

        if ($support !== null) {
            return $support;
        }

        $user = $request->user('web');

        return $user instanceof User && $user->tenant_id === $this->current->get()->id ? Principal::user($user) : null;
    }

    /**
     * The support principal of the session key: the row it names read with its operator in one
     * statement, a support principal while the row is open, unended and its expiry ahead of the
     * clock's now. Any other row, or a key holding anything but an id, forgets the key and answers
     * null, so the sign-in page answers this request and the next.
     *
     * The permission check, the controller and the shared props each ask for the principal, so
     * the answer is kept on the request for the rest of it: one read of the row a request, and one
     * answer to every reader of it.
     */
    public function supportSessionOf(Request $request): ?Principal
    {
        if ($request->attributes->has(self::SUPPORT_READ)) {
            $kept = $request->attributes->get(self::SUPPORT_READ);

            return $kept instanceof Principal ? $kept : null;
        }

        $principal = $this->readSupport($request);
        $request->attributes->set(self::SUPPORT_READ, $principal);

        return $principal;
    }

    /**
     * Writes the support session's row id into a session the open has just ended any admin
     * session in, regenerating it first: a new id for the cookie and a new cross-site token, the
     * stored session under the old id destroyed, so the session a user held there is gone from
     * the store as well as from the browser.
     */
    public function startSupport(Request $request, int $sessionId): void
    {
        $request->session()->regenerate(true);
        $request->session()->put(self::SUPPORT_KEY, $sessionId);
        $request->attributes->remove(self::SUPPORT_READ);
    }

    /**
     * Ends the support session the browser's session names and forgets its key: for a sign-out, a
     * sign-in about to answer as the user who signed in, and a second support link about to write
     * its own key. Where the row is open, its end is written and support_ended recorded in the
     * name of its operator, who is the one acting here; a key naming a row already ended or
     * lapsed ends nothing and records nothing, and a user's session and a visitor's hold no key
     * at all.
     */
    public function endSupport(Request $request): void
    {
        $principal = $this->supportSessionOf($request);
        $id = $principal?->supportSessionId();
        $operator = $principal?->actor()->supportOperatorId();

        if ($id !== null && $operator !== null) {
            $this->support->end($id, $operator);
        }

        if ($request->hasSession()) {
            $request->session()->forget(self::SUPPORT_KEY);
        }

        $request->attributes->set(self::SUPPORT_READ, null);
    }

    /**
     * The answer to a visitor who is not signed in here: the sign-in page, remembering where
     * they were going.
     */
    public function toSignIn(Request $request): RedirectResponse
    {
        return redirect()->guest(self::at($request, self::LOGIN));
    }

    /**
     * A path under this tenant's origin, spelled from the request's own host. Every admin URL is
     * on the subdomain the request came in on, and a root URL configured or forced elsewhere
     * names the central host, where no admin route answers.
     */
    public static function at(Request $request, string $path): string
    {
        return $request->getSchemeAndHttpHost().$path;
    }

    private function readSupport(Request $request): ?Principal
    {
        if (! $request->hasSession() || ! $request->session()->has(self::SUPPORT_KEY)) {
            return null;
        }

        $id = $request->session()->get(self::SUPPORT_KEY);
        $live = is_int($id) ? $this->support->live($this->current->get(), $id) : null;

        if ($live === null) {
            $request->session()->forget(self::SUPPORT_KEY);

            return null;
        }

        return Principal::support($live->id, $live->operatorId, $live->operatorName, $live->endsAt);
    }
}
