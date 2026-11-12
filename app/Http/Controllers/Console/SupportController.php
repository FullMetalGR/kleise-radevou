<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Http\ConsoleSession;
use App\Platform\SupportRefused;
use App\Platform\SupportSessions;
use App\Platform\TenantLookup;
use App\Tenancy\Slug;
use App\Tenancy\TenantUrls;
use Illuminate\Http\Request;
use Inertia\Inertia;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The console's Open as support: POST /tenants/{slug}/support under operator, with no body,
 * offered on the detail of an active or a closing tenant. It answers an Inertia location to the
 * tenant host's link, TenantUrls::supportLink, which the browser follows as a full-page visit to
 * the tenant's own origin, where the link opens the session; the token rides that answer once and
 * nothing else. A suspended or a provisioning row is refused with its sentence as an error flash
 * on the redirect back to the detail, writing nothing. The slug is read as the detail reads it,
 * and a slug naming no tenant answers the console's 404 inside its shell.
 *
 * What the press writes is App\Platform\SupportSessions's: the row and support_opened.
 */
final readonly class SupportController
{
    public function __construct(
        private SupportSessions $sessions,
        private TenantLookup $tenants,
        private ConsoleSession $session,
        private TenantUrls $urls,
        private NotFoundController $notFound,
    ) {}

    public function __invoke(Request $request, string $slug): Response
    {
        $tenant = $this->tenants->bySlug($slug);

        if ($tenant === null) {
            return ($this->notFound)($request);
        }

        $operator = $this->session->operator($request) ?? throw new LogicException('A console write ran without an operator; its route declares no operator.');

        try {
            $token = $this->sessions->open($operator, $tenant);
        } catch (SupportRefused $refused) {
            $request->session()->flash('flash', ['kind' => 'error', 'message' => $refused->getMessage()]);

            return redirect()->to($this->urls->console('/tenants/'.$tenant->slug));
        }

        return Inertia::location($this->urls->supportLink(Slug::of($tenant->slug), $token));
    }
}
