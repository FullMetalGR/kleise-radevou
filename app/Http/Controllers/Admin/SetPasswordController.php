<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\AdminSession;
use App\Http\Requests\PasswordRule;
use App\Http\Requests\SetPasswordRequest;
use App\Platform\PasswordLinks;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * The set-password link's page, on the tenant's own host under the admin prefix: GET
 * /admin/set-password/{token} renders auth/SetPassword from the admin bundle outside the shell, one
 * password field and a Set password button, and its POST sets the password where the link is live.
 * Both public, the token being the credential, and neither gated by the subscription nor under a
 * limit, as no page of this host is.
 *
 * One row moved lands on the sign-in page with the success flash, the request's own session
 * regenerated, its values kept under a new id and its old id destroyed: the set has deleted every
 * sessions row of the user, and the browser that used the link keeps whatever session it held as a
 * fresh one. Every other link, used, lapsed, replaced, unknown, tampered, another tenant's or of
 * the wrong shape, answers the sign-in page with the expired sentence as an error flash and changes
 * nothing; so does the page itself for a dead link, which never renders a form that cannot set. The
 * token's shape is the route's to judge on the GET: a path that is not 64 hex characters reaches
 * malformed(), which answers as for a link nobody holds. The POST reads any segment and the link's
 * own check refuses a wrong shape.
 *
 * The URL carries a credential, so the page is no-store, which keeps it out of every shared cache
 * and off the disk of a device the mail was read on, and no-referrer, which keeps the token out of
 * the Referer header of anything the page loads or posts; the session never keeps the URL as its
 * previous one (KeepLinkTokensOutOfTheSession). No prop carries the token: the form posts to the
 * URL the page was opened at. The page's one prop of its own is floor, PasswordRule's floor, which
 * its help line names.
 *
 * Its words are the shell's set_password group, its two flashes the shell's flashes, worded here in
 * the request's language as the sign-in page's are: with nobody signed in, the tenant default,
 * never the link's user's own choice, so the page and the sign-in page it lands on agree.
 */
final readonly class SetPasswordController
{
    /**
     * Holds a key: the flash the sign-in page shows once a password is set, shell.php's flashes.
     */
    public const string SET = 'admin/shell.flashes.password_set';

    /**
     * Holds a key: the flash the sign-in page shows for a link not live, shell.php's flashes.
     */
    public const string EXPIRED = 'admin/shell.flashes.link_expired';

    public function __construct(
        private PasswordLinks $links,
        private CurrentTenant $current,
    ) {}

    public function show(Request $request, string $token): Response
    {
        if (! $this->links->live($this->current->get(), $token)) {
            return self::expired($request);
        }

        $response = Inertia::render('auth/SetPassword', ['floor' => PasswordRule::FLOOR_CHARACTERS])->toResponse($request);
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }

    /**
     * A link whose token is not of the shape a link carries: no user can hold it, so it answers
     * exactly as a dead one does.
     */
    public function malformed(Request $request): RedirectResponse
    {
        return self::expired($request);
    }

    public function store(SetPasswordRequest $request, string $token): RedirectResponse
    {
        if (! $this->links->set($this->current->get(), $token, $request->password())) {
            return self::expired($request);
        }

        $request->session()->regenerate(true);
        $request->session()->flash('flash', ['kind' => 'success', 'message' => __(self::SET)]);

        return redirect(AdminSession::at($request, AdminSession::LOGIN));
    }

    private static function expired(Request $request): RedirectResponse
    {
        $request->session()->flash('flash', ['kind' => 'error', 'message' => __(self::EXPIRED)]);

        return redirect(AdminSession::at($request, AdminSession::LOGIN));
    }
}
