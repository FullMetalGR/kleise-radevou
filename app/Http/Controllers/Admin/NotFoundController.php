<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\AdminNav;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * An unknown path under the admin prefix: a 404 page inside the shell rather than a blank panel.
 * It sits under the prefix alone, so a customer's mistyped booking URL still gets the host's
 * plain 404.
 *
 * It is also how a routed page answers a path naming nothing, a customer's page for an address
 * no booking was made under among them: the same page at the same status, the request marked so
 * the rail lights no entry, whichever route matched.
 */
final readonly class NotFoundController
{
    public function __invoke(Request $request): Response
    {
        $request->attributes->set(AdminNav::NO_ENTRY, true);

        return Inertia::render('NotFound')->toResponse($request)->setStatusCode(404);
    }
}
