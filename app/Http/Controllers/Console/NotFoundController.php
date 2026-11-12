<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Http\ConsoleNav;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * An unknown path on the console's host: the console's own 404 page inside its shell for a
 * signed-in operator, as the admin's is inside the admin's. Registered last and under operator, so
 * anyone else is sent to the door before reaching it.
 *
 * It is also how a routed page answers a path naming nothing, a slug no tenant holds among them,
 * each console controller holding it by injection as the admin's do: the same page at the same
 * status, the request marked so the rail lights no entry whichever route matched, a tenant's
 * detail named under console.tenants included.
 */
final readonly class NotFoundController
{
    public function __invoke(Request $request): Response
    {
        $request->attributes->set(ConsoleNav::NO_ENTRY, true);

        return Inertia::render('console/NotFound')->toResponse($request)->setStatusCode(404);
    }
}
