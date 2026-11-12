<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Billing\EntitlementResolver;
use App\Http\AdminNav;
use App\Http\AdminSession;
use App\Http\AdminStrings;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetTenantLocale;
use App\Http\Requests\LoginRequest;
use App\Site\Demo;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The door: the sign-in form and the POST it makes, both public.
 *
 * The user is looked for by email within the resolved tenant and nowhere else, so an owner of one
 * business is a stranger at another even with the right password. Every refusal is the same one
 * sentence, whichever half was wrong, and opens no session, leaving a support session the browser
 * holds as it stood. A success ends any support session the browser held, its row's end written
 * and support_ended recorded, so it answers as the user who signed in, regenerates the session and
 * lands on where the visitor was going, or on the first entry of their rail.
 *
 * On the demo a refusal keeps nothing the visitor typed: the email goes back to the form as no
 * old input, so no sessions row of the demo holds it. Any other tenant's refusal flashes the
 * email back as the framework does, so the form comes back filled.
 *
 * No remember-me control and no password reset of its own: a password is set through the link the
 * console mails a user, SetPasswordController, which lands back here.
 */
final readonly class LoginController
{
    /** The one refusal of a pair, a key of lang/{en,el}/admin/shell.php read in the request's language. */
    public const string REFUSED = 'admin/shell.refusals.sign_in';

    /** The sign-in limit's refusal, a key of the same. */
    public const string TOO_MANY = 'admin/shell.refusals.too_many';

    /** The sign-out's flash, LogoutController's, a key of the same. */
    public const string SIGNED_OUT = 'admin/shell.flashes.signed_out';

    public function __construct(
        private AdminSession $session,
        private CurrentTenant $current,
        private EntitlementResolver $entitlements,
        private Demo $demo,
    ) {}

    public function show(Request $request): InertiaResponse|RedirectResponse
    {
        $principal = $this->session->principal($request);

        if ($principal !== null) {
            return redirect(AdminSession::at($request, AdminNav::home($principal->permissions, $this->entitlements->resolve($this->current->get()))));
        }

        return Inertia::render('auth/Login', ['refusal' => null]);
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $tenant = $this->current->get();

        if (! Auth::guard('web')->attempt(['tenant_id' => $tenant->id, 'email' => $request->email(), 'password' => $request->password()])) {
            $refusal = ['email' => __(self::REFUSED)];

            if ($this->demo->is($tenant)) {
                return redirect()->back()->withErrors($refusal);
            }

            throw ValidationException::withMessages($refusal);
        }

        $this->session->endSupport($request);
        $request->session()->regenerate();

        $principal = $this->session->principal($request);
        assert($principal !== null);

        return redirect()->intended(AdminSession::at($request, AdminNav::home($principal->permissions, $this->entitlements->resolve($tenant))));
    }

    /**
     * The answer past the sign-in limit: the form again at 429 with the refusal worded. The
     * limiter answers before tenant resolution has run and before the adapter shares, so the page
     * hands itself what the form reads, as the central adapter's hostProps() is handed to the
     * signup's limit: the refusal, the language and the shell's strings, all read in the language
     * SetTenantLocale::beforeResolution() sets, the one the tenant group would, the tenant default
     * of the tenant the host names for a visitor nobody signed in, English for a host naming none.
     *
     * @param  array<string, string>  $headers
     */
    public static function tooMany(Request $request, array $headers): Response
    {
        return app(SetTenantLocale::class)->beforeResolution($request, function () use ($request, $headers): Response {
            Inertia::setRootView(HandleInertiaRequests::ROOT_VIEW);

            $response = Inertia::render('auth/Login', [
                'refusal' => __(self::TOO_MANY),
                'language' => app()->getLocale(),
                'strings' => app(AdminStrings::class)->shell(),
            ])->toResponse($request)->setStatusCode(429);
            $response->headers->add($headers);

            return $response;
        });
    }
}
