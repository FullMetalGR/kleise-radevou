<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\AdminSession;
use App\Http\Principal;
use App\Tenancy\Permission;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * The authorization check of an admin route and the declaration that names it, in the manner of
 * the manage token: a route carrying this cannot be reached without the check having run. Each
 * admin route declares the one permission it needs, on the route itself and never on a group, so
 * the surface walk reads one answer on every route and a route registered later inherits no allow
 * it did not ask for.
 *
 * A declaration may name more than one permission, as the wizard's admin JSON endpoint's does: a
 * user holding any of them is admitted and a user holding none is refused. It is still one
 * declaration on the route, which is what the surface walk counts.
 *
 * A visitor who is not signed in at this tenant goes to the sign-in page, unless they asked for
 * JSON, as the wizard's availability read does: a redirect to an HTML page is no answer to a JSON
 * caller, who would follow it and read the sign-in page as an empty day, so that caller is
 * answered 401 as the error envelope, a sentence they can show. A signed-in user who lacks the
 * permission is answered 403. The permissions are the principal's: a user's derived from their
 * role here, on the server, and support's the owner's less manageBilling and viewOwnCalendar; the
 * client reads the resolved slugs and never the role.
 *
 * A scope a permission carries for one role, approveBookings for staff, is a fact about one
 * booking and is answered where the booking is known, not here.
 */
final readonly class RequiresPermission
{
    public const string NAME = 'permission';

    public function __construct(private AdminSession $session) {}

    /**
     * The middleware string a route declares: permission:viewBookings, or, for a route either of
     * two permissions opens, permission:manageBookings,viewOwnCalendar.
     */
    public static function of(Permission $permission, Permission ...$others): string
    {
        return self::NAME.':'.implode(',', array_map(fn (Permission $each): string => $each->value, [$permission, ...$others]));
    }

    /**
     * The permissions a declaration names, each one of the admin's permissions.
     *
     * @return non-empty-list<Permission>
     */
    public static function named(string $declaration): array
    {
        $slugs = explode(',', substr($declaration, strlen(self::NAME) + 1));

        return [Permission::from($slugs[0]), ...array_map(Permission::from(...), array_slice($slugs, 1))];
    }

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $permission, string ...$others): Response
    {
        return $this->answer($this->session->principal($request), $request, $next, [$permission, ...array_values($others)]);
    }

    /**
     * The answer for the principal the session read: the sign-in for nobody, the request let
     * through for a principal holding any one of the slugs, and the 403 for one holding none.
     *
     * @param  Closure(Request): Response  $next
     * @param  non-empty-list<string>  $slugs
     */
    private function answer(?Principal $principal, Request $request, Closure $next, array $slugs): Response
    {
        if ($principal === null) {
            if ($request->expectsJson()) {
                throw new AuthenticationException;
            }

            return $this->session->toSignIn($request);
        }

        foreach ($slugs as $slug) {
            if ($principal->holds(Permission::from($slug))) {
                return $next($request);
            }
        }

        throw new AccessDeniedHttpException;
    }
}
