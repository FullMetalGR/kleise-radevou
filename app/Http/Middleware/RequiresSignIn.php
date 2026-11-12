<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\AdminSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The declaration of an admin route that any signed-in user of this tenant may reach and that
 * needs no permission: the 404 page inside the shell, which shows nothing but the rail the user
 * already has, and the top bar's language control, which writes the signed-in user's own language
 * and no business's. It is a check, not a label: a visitor with no principal here, neither a user
 * of this tenant nor an open support session, goes to the sign-in page, as on every admin route
 * but the door.
 */
final readonly class RequiresSignIn
{
    public const string NAME = 'signed-in';

    public function __construct(private AdminSession $session) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        return $this->session->principal($request) === null ? $this->session->toSignIn($request) : $next($request);
    }
}
