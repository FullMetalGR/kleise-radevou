<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Booking\ManageToken;
use App\Http\ManagedBooking;
use App\Http\ManageTokenGuard;
use App\Http\MissingManageToken;
use App\Http\MyBookingsCookie;
use App\Tenancy\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The authorization check of a guest route, and the declaration that names it: the customer
 * presents the one credential they hold, the guard decides, and a route carrying this cannot be
 * reached any other way. Declaring it and enforcing it are the same act here on purpose, because
 * a label that only says what should have happened is not a check.
 *
 * Where the token travels: the JSON body on a POST, and the token query parameter on a GET or a
 * DELETE, which have no body. The magic link already carries the token in its URL, so the query
 * form adds nothing to what a log or a referrer holds that the link did not.
 *
 * The second credential: where a request presents no token, the My bookings session its cookie
 * names, live and opened for the address the booking was made under. It is consulted only where
 * no token is presented, so a request carrying both is judged by the token alone, and the cookie
 * is read as the framework decrypted it, never printed and never bound. The booking app sends it
 * only from the list mode on the tenant's own origin; the embed sends no cookie at all.
 *
 * The session is a same-site credential (P9F-L17-a): it is read on no request the browser marks
 * cross-site in its Sec-Fetch-Site header. The cookie is SameSite Lax, so a top-level navigation
 * from another site carries it, and GET /pay/{uuid} writes (it opens a session at the provider,
 * records payment_ref, or settles on site); a page elsewhere linking to it must not act on the
 * customer's session. Such a request presents nothing the guard takes and is answered as one
 * carrying no credential. A request the browser marks same-origin, same-site or none, or marks
 * not at all, is read as before, and a token is judged wherever the request came from, so the
 * minted link the embed sends the customer to from the tenant's own site still opens the hop.
 *
 * A request carrying neither a token nor the cookie is a malformed request rather than a failed
 * credential, and answers 400 invalid_request with a sentence of its own, the embed's contract as
 * it always was. Every failed credential is the guard's one refusal, answered 404 not_found with
 * a body that does not vary: an expired token, a wrong token, a token for another booking, an
 * unknown booking, and a cookie naming no live session or a session for another address are one
 * answer.
 *
 * The booking the guard read is bound for the rest of the request, so the controller behind it
 * answers the row the credential was checked against rather than reading it again. The token it
 * accepted is bound with it, or null where the session admitted the request: a verified request
 * is one of the two moments the plaintext exists, and the confirmation's email link has nowhere
 * else to read one from.
 */
final readonly class RequiresManageToken
{
    public const string NAME = 'manage-token';

    /**
     * What the token is called in both of its transports, and the route parameter naming the
     * booking it belongs to.
     */
    public const string TOKEN = 'token';

    public const string UUID = 'uuid';

    /** The fetch metadata header a browser says a request's origin relative to the target in. */
    public const string FETCH_SITE = 'Sec-Fetch-Site';

    public function __construct(
        private ManageTokenGuard $guard,
        private CurrentTenant $current,
        private ManagedBooking $managed,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->current->get();
        $uuid = self::uuidOf($request);
        $presented = self::tokenOf($request);

        if ($presented !== null) {
            $booking = $this->guard->booking($tenant, $uuid, $presented);
        } else {
            $session = self::sessionOf($request) ?? throw MissingManageToken::notPresented();
            $booking = $this->guard->bookingOfSession($tenant, $uuid, $session);
        }

        if ($booking === null) {
            throw new NotFoundHttpException;
        }

        $this->managed->bind($booking, $presented);

        try {
            return $next($request);
        } finally {
            $this->managed->release();
        }
    }

    /**
     * The My bookings session the cookie names, on a request the browser did not mark cross-site;
     * null on one it did, whatever cookie it carries.
     */
    private static function sessionOf(Request $request): ?string
    {
        return $request->headers->get(self::FETCH_SITE) === 'cross-site' ? null : MyBookingsCookie::read($request);
    }

    /**
     * The booking the path names. A route registered without the parameter would ask the guard
     * about nothing at all, which is a refusal rather than a crash, since the guard treats any
     * string that is not a uuid as naming no booking.
     */
    private static function uuidOf(Request $request): string
    {
        $uuid = $request->route(self::UUID);

        return is_string($uuid) ? $uuid : '';
    }

    /**
     * The token as this method carries it, or null where the request carries none. The body is
     * read as JSON because this API speaks JSON on every route. Only the transport the method
     * has is read: each method has one place to put the token, so a POST is read from its body
     * and a copy somebody also put in its query string is not a second chance. That is this
     * guard reading where it was told to look, not a rule about stray parameters.
     */
    private static function tokenOf(Request $request): ?ManageToken
    {
        $token = $request->isMethod(Request::METHOD_POST)
            ? $request->json(self::TOKEN)
            : $request->query(self::TOKEN);

        return is_string($token) && $token !== '' ? ManageToken::presented($token) : null;
    }
}
