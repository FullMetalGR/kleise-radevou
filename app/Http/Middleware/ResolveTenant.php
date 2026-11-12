<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Tenancy\CurrentTenant;
use App\Tenancy\InvalidSlug;
use App\Tenancy\Languages;
use App\Tenancy\Slug;
use App\Tenancy\SlugRules;
use App\Tenancy\Tenant;
use App\Tenancy\TenantStatus;
use App\Tenancy\TenantUrls;
use Closure;
use Illuminate\Container\Attributes\Config;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Tenant resolution: on every tenant route and on no central one.
 *
 * The host names the tenant: the label directly under the central host, port stripped. A
 * reserved label, a malformed one, an unknown slug and a tenant still provisioning all end at
 * one 404 thrown from one line, so whatever a scanner asks it gets one answer, in status,
 * headers and body alike. Nothing here redirects: a redirect would say which labels the platform
 * keeps. One reserved label is served, the demo tenant's: read from site.demo_slug, the label
 * demo:reset alone builds a tenant under, and looked up as any slug is, so the demo answers 404
 * as a provisioning row while it rebuilds. A suspended tenant gets the static page on every
 * route and nothing else. A closing tenant resolves as an active one until its schema is
 * dropped, because it may reopen.
 *
 * The row is loaded once, here, and bound for exactly this request. The schema is not switched
 * here: that happens inside each transaction, in the runner, because a search path set on the
 * connection would ride a pooled connection into another tenant's request.
 */
final readonly class ResolveTenant
{
    public function __construct(
        private TenantUrls $urls,
        private SlugRules $slugRules,
        private CurrentTenant $current,
        private ResponseFactory $responses,
        private Languages $languages,
        #[Config('site.demo_slug')]
        private string $demoSlug,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->tenantOf($request->getHost());

        if ($tenant === null) {
            throw new NotFoundHttpException;
        }

        if ($tenant->status === TenantStatus::Suspended) {
            return $this->suspended($request, $tenant);
        }

        // The router matched the host on a {tenant} parameter; the route sees the tenant, not the
        // label.
        $request->route()?->forgetParameter('tenant');
        $this->current->bind($tenant);

        try {
            return $next($request);
        } finally {
            $this->current->release();
        }
    }

    /**
     * What a suspended tenant's host answers on every route: the static page at 503, naming no
     * tenant. Public for the one answer given before this middleware runs, as tenantOf is, so a
     * limit's answer on a suspended host is this page too and never a second one.
     *
     * Its lang is the request's language, read here from the path and the row, since the page
     * is answered before the tenant is bound and before SetTenantLocale runs; its words are
     * read in that language too.
     */
    public function suspended(Request $request, Tenant $tenant): Response
    {
        return $this->responses->view('tenancy.suspended', [
            'language' => $this->languages->ofRequest($request, $tenant)->value,
        ], Response::HTTP_SERVICE_UNAVAILABLE);
    }

    /**
     * The tenant the host names, or null for every host that names none. The row is looked up
     * only for a well-formed label that is not reserved, the demo's excepted, and a row still
     * provisioning counts as absent: its schema does not exist yet and its slug is held for a
     * signup in flight, or, the demo's, dropped while its rebuild runs.
     *
     * Public for the one answer given before this middleware runs: the framework sorts a route's
     * throttle ahead of it, so a limit's answer that is a tenant's page, My bookings' refusal,
     * finds its tenant here by the same rule.
     */
    public function tenantOf(string $host): ?Tenant
    {
        $label = $this->urls->labelOf($host);

        if ($label === null) {
            return null;
        }

        try {
            $slug = Slug::of($label);
        } catch (InvalidSlug) {
            return null;
        }

        if ($this->slugRules->isReserved($slug) && $slug->value !== $this->demoSlug) {
            return null;
        }

        $tenant = Tenant::query()->where('slug', $slug->value)->first();

        if ($tenant === null || $tenant->status === TenantStatus::Provisioning) {
            return null;
        }

        return $tenant;
    }
}
