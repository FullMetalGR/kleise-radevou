<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Site\SiteLanguage;
use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The language a site route answers in: the application's locale for the length of the request,
 * from the route. SiteRoutes carries it on every route it registers with that route's language,
 * site-locale:en or site-locale:el; the central host's 404, which has no page to name a
 * language, carries it bare and is read from its path, Greek under /el.
 *
 * Nothing here reads Accept-Language and nothing redirects by it: a Greek browser asking for /
 * reads the English page. The locale is put back once the response is built, every view of it
 * rendered by then, so it belongs to this request and to no later one in the same process.
 */
final readonly class SetSiteLocale
{
    public const string NAME = 'site-locale';

    public function __construct(private Application $app) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, ?string $language = null): Response
    {
        $previous = $this->app->getLocale();
        $this->app->setLocale(($language === null ? SiteLanguage::ofPath($request->path()) : SiteLanguage::from($language))->value);

        try {
            return $next($request);
        } finally {
            $this->app->setLocale($previous);
        }
    }
}
