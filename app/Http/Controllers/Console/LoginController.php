<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Application\Clock;
use App\Http\AdminSession;
use App\Http\AdminStrings;
use App\Http\ConsoleSession;
use App\Http\Middleware\HandleConsoleInertiaRequests;
use App\Http\Requests\LoginRequest;
use App\Notifications\OperatorSignedIn;
use App\Platform\PlatformAudit;
use App\Platform\PlatformAuditAction;
use App\Platform\PlatformAuditEntry;
use App\Site\Brand;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The console's door: the sign-in form on the console's host and the POST it makes, both public,
 * in the admin door's shape.
 *
 * The pair is looked for among the operators alone, on the console guard, so a tenant user's email
 * and password open nothing here. Every refusal is the same one sentence on the email field,
 * whichever half was wrong, and opens no session. A success regenerates the session, records
 * sign_in in the platform audit log naming the operator, starts the session's two timeouts at the
 * clock's now beside the fingerprint of the password it signed in with, mails the operator the
 * sign-in notice naming that instant and the request's address, and lands on the page the door
 * turned the visitor away from, the one ConsoleSession::toDoor remembered, or on the console's
 * home where there was none. A refusal, and an attempt the limiter answered, records nothing and
 * mails nothing. No remember-me control and no password reset.
 *
 * The row is written once the session is regenerated and before the timeouts start: a write that
 * failed leaves a session carrying no instant, which the operator check reads as run out, so no
 * console session is usable that the log does not show opening.
 */
final readonly class LoginController
{
    public const string REFUSED = 'That email and password do not match an operator.';

    /**
     * The console's own sentences, worded as the admin's door words its own today and kept apart
     * from them, so a change to the admin's does not reach the console unseen.
     */
    public const string TOO_MANY = 'Too many sign-in attempts from this address. Please wait a minute and try again.';

    public const string SIGNED_OUT = 'You have signed out.';

    private const string NOBODY = 'A sign-in the console guard let through names an operator.';

    public function __construct(
        private ConsoleSession $session,
        private Clock $clock,
        private OperatorSignedIn $notice,
        private DatabaseManager $db,
    ) {}

    public function show(Request $request): InertiaResponse|RedirectResponse
    {
        if ($this->session->operator($request) !== null) {
            return redirect(AdminSession::at($request, ConsoleSession::HOME));
        }

        return Inertia::render('console/Login', ['refusal' => null]);
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        if (! Auth::guard(ConsoleSession::GUARD)->attempt(['email' => $request->email(), 'password' => $request->password()])) {
            throw ValidationException::withMessages(['email' => self::REFUSED]);
        }

        $request->session()->regenerate();
        $operator = $this->session->operator($request) ?? throw new LogicException(self::NOBODY);
        PlatformAudit::record($this->db->connection(), $operator->id, new PlatformAuditEntry(PlatformAuditAction::SignIn), null);
        $now = $this->clock->now();
        $this->session->open($request, $now, $operator);
        $this->notice->send($operator, $now, (string) $request->ip());

        return redirect()->intended(AdminSession::at($request, ConsoleSession::HOME));
    }

    /**
     * The answer past the console's sign-in limit: the form again at 429 with the refusal worded,
     * as the admin's door answers past its own. The limiter answers before the console's adapter
     * has run, so the page carries the sentence and no shared prop but the brand, which the door
     * names itself by, and the strings its form reads, the shell's in English as the adapter
     * shares them.
     *
     * @param  array<string, string>  $headers
     */
    public static function tooMany(Request $request, array $headers): Response
    {
        Inertia::setRootView(HandleConsoleInertiaRequests::ROOT_VIEW);

        $response = Inertia::render('console/Login', [
            'refusal' => self::TOO_MANY,
            'brand' => app(Brand::class)->prop(),
            'strings' => HandleConsoleInertiaRequests::stringsOf(app(AdminStrings::class)),
        ])->toResponse($request)->setStatusCode(429);
        $response->headers->add($headers);

        return $response;
    }
}
