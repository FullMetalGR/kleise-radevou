<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central;

use App\Http\SignupStrings;
use App\Notifications\Welcome;
use App\Site\SignupLinks;
use App\Tenancy\SignupVerification;
use App\Tenancy\TenantUrls;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The verification link's door: a plain GET from the owner's mail on the central host. A link
 * inside its 24 hours on a row still provisioning builds the schema as a trial, sends the owner
 * the welcome email once the build has committed, and sends the person to the pending page,
 * which sends them on to the sign-in page; the link of an owner already verified, its row built
 * by this link before or by a click racing this one, sends to the sign-in page whenever it is
 * clicked and sends no welcome; every other link, expired, replaced by a resend, swept, unknown
 * or tampered, answers the signup form with one sentence at 410, signup.php's expired in the
 * request's language, and builds nothing. The token's shape is the route's to judge: a path that
 * is not 64 hex characters reaches malformed(), which answers as for a link nobody holds. The
 * pending page the click sends on to is the row's language's, whichever language's path was
 * clicked; the 410 has no row and answers in its path's.
 *
 * The welcome goes through the platform's seam, which never throws: a transport that fails is
 * the seam's logged error, and the click answers as it would have.
 */
final readonly class SignupVerifyController
{
    /** The link's token as the route admits it: 32 bytes as lowercase hex. */
    public const string TOKEN = '[0-9a-f]{64}';

    public function __construct(
        private SignupVerification $verification,
        private TenantUrls $urls,
        private SignupLinks $links,
        private SignupStrings $strings,
        private Welcome $welcome,
    ) {}

    public function __invoke(Request $request, string $token): Response
    {
        $verified = $this->verification->verify($token);

        if ($verified === null) {
            return SignupController::refused($request, $this->strings->sentence('expired'), 410);
        }

        if ($verified->trial !== null) {
            $this->welcome->send($verified->trial->tenant, $verified->trial->owner);
        }

        return new RedirectResponse($verified->built
            ? $this->links->pending($verified->slug->value, $verified->locale)
            : $this->urls->signIn($verified->slug));
    }

    /**
     * A link whose token is not of the shape a link carries: no owner can hold it, so it answers
     * exactly as an unknown one does.
     */
    public function malformed(Request $request): Response
    {
        return SignupController::refused($request, $this->strings->sentence('expired'), 410);
    }
}
