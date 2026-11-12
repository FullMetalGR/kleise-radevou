<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\AdminSession;
use App\Site\SiteLanguage;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Languages;
use App\Tenancy\Tenant;
use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The language a tenant host's request is answered in: the application's locale for the length of
 * the request, the request's language as the resolver answers it, the prefix of a page's path, the
 * API's lang, else the tenant default. It runs on the tenant group after ResolveTenant, which binds
 * the tenant it reads, so every sentence read through the translator later in the request is read
 * in it.
 *
 * Under /admin the request's language is the user's: the principal the session answers, a signed-in
 * user's users.locale else the tenant default, and the tenant default where nobody is signed in or
 * a support session, which has no user, acts; so the admin's sentences, its flashes, refusals and
 * validation, are read in the language of whoever is reading them. The principal is the one
 * AdminSession answers every later reader of the request.
 *
 * Nothing here reads Accept-Language and nothing redirects by it, as on the site. The locale is put
 * back once the response is built, as SetSiteLocale puts it back, so it belongs to this request and
 * to no later one in the same process; within() is the same for an answer given before this runs,
 * the My bookings link's limit, whose refusal is a page of the tenant's, and beforeResolution() for
 * a limit whose answer is the envelope, the API's and the admin's two, which finds the tenant
 * itself.
 */
final readonly class SetTenantLocale
{
    public function __construct(
        private Application $app,
        private Languages $languages,
        private CurrentTenant $current,
        private AdminSession $session,
        private ResolveTenant $resolver,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        return $this->within($request, $this->current->get(), fn (): Response => $next($request));
    }

    /**
     * Runs the step with the locale set to the request's language at the tenant given, and puts
     * the locale back after it.
     *
     * @template T
     *
     * @param  Closure(): T  $step
     * @return T
     */
    public function within(Request $request, Tenant $tenant, Closure $step): mixed
    {
        return $this->in($this->languageOf($request, $tenant), $step);
    }

    /**
     * Runs the step of an answer given before tenant resolution, a limit's, which the framework
     * sorts ahead of every middleware it does not know (AppServiceProvider), with the locale set
     * as the tenant group would set it: the tenant the host names, found by resolution's own rule
     * and bound for the step alone, so that under /admin the signed-in user is read as the
     * session names them, else the tenant default, and elsewhere the API's lang, else the tenant
     * default. A host naming no tenant answers in English.
     *
     * @template T
     *
     * @param  Closure(): T  $step
     * @return T
     */
    public function beforeResolution(Request $request, Closure $step): mixed
    {
        $tenant = $this->resolver->tenantOf($request->getHost());

        if ($tenant === null) {
            return $this->in(SiteLanguage::English, $step);
        }

        $this->current->bind($tenant);

        try {
            return $this->within($request, $tenant, $step);
        } finally {
            $this->current->release();
        }
    }

    /**
     * Runs the step with the locale set to the language given, and puts the locale back after it.
     *
     * @template T
     *
     * @param  Closure(): T  $step
     * @return T
     */
    private function in(SiteLanguage $language, Closure $step): mixed
    {
        $previous = $this->app->getLocale();
        $this->app->setLocale($language->value);

        try {
            return $step();
        } finally {
            $this->app->setLocale($previous);
        }
    }

    /**
     * The request's language: the user's under the admin's prefix, else the resolver's answer for
     * the request.
     */
    private function languageOf(Request $request, Tenant $tenant): SiteLanguage
    {
        if ($request->is(ltrim(AdminSession::PREFIX, '/'), ltrim(AdminSession::PREFIX, '/').'/*')) {
            return $this->languages->ofUser($tenant, $this->session->principal($request)?->chosenLanguage());
        }

        return $this->languages->ofRequest($request, $tenant);
    }
}
