<?php

declare(strict_types=1);

namespace App\Http;

use App\Infrastructure\Db\SqlText;
use App\Platform\Operator;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Container\Attributes\Config;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Who is signed in to the platform's console: AdminSession's twin on the console's host. The
 * console guard is a session guard over the operators provider alone, so a tenant user's session,
 * were its cookie ever presented here, names nobody; the cookie is host-only besides, so a session
 * opened at a tenant is never presented here at all.
 *
 * It also keeps the two instants the session's timeouts are judged by: when the door opened the
 * session and when an operator check last admitted a request on it, each as UTC text with its
 * offset written out, under keys the console owns. A key that is missing or holds what the
 * spelling cannot read answers null, and the check treats that as a session run out.
 *
 * And the fingerprint of the operator's password from the sign-in: an HMAC-SHA256 of the
 * password's hash under the application key, never the hash and never the password, as the
 * framework's AuthenticateSession keeps one for the default guard. The operator check compares it
 * with the row the guard has already read on every request, so a password set by
 * console:set-operator-password ends every console session of that operator at its next request,
 * with no read of the sessions table and no second query. A new application key ends them all the
 * same way.
 */
final readonly class ConsoleSession
{
    /**
     * The guard config/auth.php names beside the admin's web.
     */
    public const string GUARD = 'console';

    public const string LOGIN = '/login';

    public const string HOME = '/';

    /** When the door opened the session, the eight-hour ceiling's start. */
    public const string SIGNED_IN_AT = 'console.signed_in_at';

    /** When the last request on the session was admitted, the thirty idle minutes' start. */
    public const string LAST_SEEN_AT = 'console.last_seen_at';

    /** The fingerprint of the operator's password as it stood at the sign-in. */
    public const string PASSWORD = 'console.password';

    public function __construct(
        private AuthFactory $auth,
        #[Config('app.key')] private string $key,
    ) {}

    public function operator(Request $request): ?Operator
    {
        $operator = $this->auth->guard(self::GUARD)->user();

        return $operator instanceof Operator ? $operator : null;
    }

    /**
     * The answer to a visitor with no operator session: the door, on the request's own host,
     * remembering the page asked for so the sign-in lands there, as the admin's
     * AdminSession::toSignIn remembers its own. A page is a GET: a write is no page to come back
     * to, so the sign-in after one lands on the home. The page is this request's own URL on this
     * host, so the sign-in never lands anywhere else.
     */
    public function toDoor(Request $request): RedirectResponse
    {
        if ($request->isMethod('GET') && ! $request->expectsJson()) {
            redirect()->setIntendedUrl($request->fullUrl());
        }

        return redirect(AdminSession::at($request, self::LOGIN));
    }

    /**
     * Starts both counts on a session the door has just regenerated, at the instant given, and
     * keeps the fingerprint of the password the operator signed in with.
     */
    public function open(Request $request, DateTimeImmutable $at, Operator $operator): void
    {
        $request->session()->put([
            self::SIGNED_IN_AT => SqlText::instant($at),
            self::LAST_SEEN_AT => SqlText::instant($at),
            self::PASSWORD => $this->fingerprint($operator),
        ]);
    }

    /**
     * The fingerprint of the operator's password as their row holds it now: an HMAC of the hash
     * under the application key, which reveals neither the hash nor the password.
     */
    public function fingerprint(Operator $operator): string
    {
        return hash_hmac('sha256', $operator->getAuthPassword(), $this->key);
    }

    /**
     * Whether the fingerprint the session carries is the operator's password as it stands: null
     * where the session carries none, which the check reads as a session run out.
     */
    public function passwordUnchanged(Request $request, Operator $operator): ?bool
    {
        $held = $request->session()->get(self::PASSWORD);

        return is_string($held) ? hash_equals($this->fingerprint($operator), $held) : null;
    }

    /**
     * Restarts the idle count from the instant given, on a request the operator check admitted.
     */
    public function seen(Request $request, DateTimeImmutable $at): void
    {
        $request->session()->put(self::LAST_SEEN_AT, SqlText::instant($at));
    }

    public function signedInAt(Request $request): ?DateTimeImmutable
    {
        return $this->instant($request, self::SIGNED_IN_AT);
    }

    public function lastSeenAt(Request $request): ?DateTimeImmutable
    {
        return $this->instant($request, self::LAST_SEEN_AT);
    }

    /**
     * Ends the session whoever holds it, the sign-out's and a lapse's one way out: the guard signed
     * out, the session invalidated and its token renewed, and the door on the request's own host
     * with the flash given, remembering the page a lapsed GET asked for as toDoor does.
     *
     * @param  array{kind: string, message: string}  $flash
     */
    public function end(Request $request, array $flash): RedirectResponse
    {
        $this->auth->guard(self::GUARD)->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $request->session()->flash('flash', $flash);

        return $this->toDoor($request);
    }

    private function instant(Request $request, string $key): ?DateTimeImmutable
    {
        $text = $request->session()->get($key);
        $instant = is_string($text) ? DateTimeImmutable::createFromFormat(SqlText::INSTANT, $text, new DateTimeZone('UTC')) : false;

        return $instant === false ? null : $instant;
    }
}
