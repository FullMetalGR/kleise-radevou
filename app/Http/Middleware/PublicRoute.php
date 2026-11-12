<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The declaration of a route anyone may reach, and it declares rather than enforces: there is
 * nobody to identify and nothing to refuse, so this passes every request through untouched and
 * the class exists to be found on the route. That is the opposite of its neighbour, where the
 * declaration is the guard itself, and the difference is worth stating rather than blurring.
 *
 * What makes a marker with no behaviour load-bearing is the test that walks the tenant router
 * and fails on a route declaring neither access nor both. Every route declares an authorization
 * check and nothing is allowed implicitly; a route open by design says so in the same place a
 * guarded route says what guards it, and the walk then reads one answer on every route rather
 * than an absence it would have to interpret.
 *
 * What it declares is that anyone may read a tenant's booking page, list their services, ask
 * when they are free and hold a slot, exactly as anyone may walk past the salon and read the
 * price list. The protections on that surface are the rate limits and the fact that every write
 * is re-validated under lock, neither of which is a credential.
 *
 * It is carried on the route and never on a group. A group declaration would hand its access to
 * the next route registered inside it, and a route that inherits an allow it never asked for is
 * the implicit allow the rule forbids.
 */
final readonly class PublicRoute
{
    public const string NAME = 'public';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }
}
