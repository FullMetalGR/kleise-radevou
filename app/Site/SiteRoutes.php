<?php

declare(strict_types=1);

namespace App\Site;

use App\Http\Middleware\PublicRoute;
use App\Http\Middleware\SetSiteLocale;
use Closure;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Route as RegisteredRoute;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use LogicException;

/**
 * The one helper that registers the site's routes, called from routes/central.php inside the
 * central host's domain group.
 *
 * A route is registered twice, the English one at its path and named for the page, the Greek one
 * under /el and named with the prefix el., each declaring public on the route itself, as every
 * central route declares its access, and carrying the locale middleware with its language. A page
 * of the site is such a pair answering GET, running without the session and naming the language
 * file its copy lives in, but for the contact page, which keeps the session its form needs; the
 * registry, SitePages, reads the pages back from the router, so the nav, the footer, the sitemap
 * and the surface walk list a page registered here with no edit to any of them.
 */
final class SiteRoutes
{
    /**
     * The key of a page route's action naming the language file its copy lives in, home for
     * lang/{en,el}/home.php: its title, its meta description and its heading are that file's
     * title, description and heading.
     */
    public const string COPY = 'site_copy';

    /**
     * The key of a page route's action listing the values its one parameter is served under, a
     * page served once per key, the verticals: the registry lists the page once per key, and
     * its copy lives under the key in its language file.
     */
    public const string KEYS = 'site_keys';

    /**
     * The key of a route's action naming the page its language switch leads to in place of the
     * route itself, with no parameter. The signup's four routes that answer the form with a
     * refusal, the two POSTs' 429 and the verification link's 410, name the form: what they
     * render is the form, and the link's one parameter is its token, which belongs in no href.
     * A route without it switches to its own twin, its parameters kept, where the router holds
     * one.
     */
    public const string SWITCH = 'site_switch';

    /**
     * The web group's session middleware, which a page of the site runs without: no session
     * started and none saved, so no session row and no cookie; no errors shared from a session
     * it does not have, and no cross-site token, whose cookie needs one. The cookies' encryption
     * and the route bindings of the group still run. KeepLinkTokensOutOfTheSession, which stands
     * in for the first in the web group, is a kind of it and is excluded with it.
     *
     * @var list<class-string>
     */
    public const array SESSION = [StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class];

    /**
     * The paths the central host's 404 leaves alone: those the error envelope answers on,
     * bootstrap/app.php, where a framework page or a site page would break the promise that a
     * caller there reads the envelope, and where a verb a route does not take stays a 405.
     */
    public const string FALLBACK_PATH = '(?!(?:api|admin/api|webhooks)/).*';

    /**
     * A route in both languages: the English route, then the Greek one. The signup's six routes
     * take their Greek twins through here, keeping the session they need and carrying the central
     * host's adapter after their locale.
     *
     * @param  string|array<int|string, mixed>|Closure  $action
     * @return list<RegisteredRoute>
     */
    public static function twin(string $method, string $uri, string|array|Closure $action, string $name): array
    {
        return array_map(
            fn (SiteLanguage $language): RegisteredRoute => Route::match([$method], $language->uri($uri), $action)
                ->middleware([PublicRoute::NAME, SetSiteLocale::NAME.':'.$language->value])
                ->name($language->routeName($name)),
            SiteLanguage::cases(),
        );
    }

    /**
     * A page of the site in both languages: a GET without the session, its copy in the language
     * file named. A page with a parameter names the keys it is served under, its parameter
     * constrained to them, so any other value reaches the 404; its copy for a key lives under
     * that key in its file. A page has one parameter at most, and one with a parameter and no
     * keys is refused here, since the registry could list it under no address at all.
     *
     * @param  string|array<int|string, mixed>|Closure  $action
     * @param  list<string>  $keys
     * @return list<RegisteredRoute>
     */
    public static function page(string $uri, string|array|Closure $action, string $name, string $copy, array $keys = []): array
    {
        $routes = self::twin('GET', $uri, $action, $name);

        foreach ($routes as $route) {
            $parameters = array_values(array_filter($route->parameterNames(), is_string(...)));

            if (count($parameters) !== ($keys === [] ? 0 : 1)) {
                throw new LogicException(sprintf('The page %s names keys for one parameter, or none for no parameter.', $name));
            }

            $route->withoutMiddleware(self::SESSION);
            $route->setAction(array_merge($route->action, [self::COPY => $copy], $keys === [] ? [] : [self::KEYS => $keys]));

            if ($keys !== []) {
                $route->whereIn($parameters, $keys);
            }
        }

        return $routes;
    }

    /**
     * A page of the site with a form, the contact page, in both languages: the GET and the POST it
     * posts to at the same address, named for the page and for the page with .send. Both keep the
     * web group whole, the session among it, for the cross-site token, the flash and the errors,
     * so neither is a page that runs without one. Each names the page's language file as a page
     * does, so the head, the header and the footer around what either renders are the page's: the
     * POST renders the page itself where it answers with no redirect, the limit's 429. The
     * registry lists the GET alone. The POST carries the middleware given, its limit, beside
     * public and its locale. A form's page has no parameter.
     *
     * @param  string|array<int|string, mixed>|Closure  $show
     * @param  string|array<int|string, mixed>|Closure  $send
     * @param  list<string>  $sendMiddleware
     * @return list<RegisteredRoute>
     */
    public static function form(string $uri, string|array|Closure $show, string|array|Closure $send, string $name, string $copy, array $sendMiddleware = []): array
    {
        $posts = self::twin('POST', $uri, $send, $name.'.send');
        $routes = [...self::twin('GET', $uri, $show, $name), ...$posts];

        foreach ($routes as $route) {
            if ($route->parameterNames() !== []) {
                throw new LogicException(sprintf('The form %s takes no parameter.', $name));
            }

            $route->setAction(array_merge($route->action, [self::COPY => $copy]));
        }

        foreach ($posts as $post) {
            $post->middleware($sendMiddleware);
        }

        return $routes;
    }

    /**
     * The central host's 404: a fallback on the host's domain group answering every GET path no
     * route names, but the error envelope's, with the site's 404 in the language of the path's
     * prefix, public and without the session as the pages are. Every other host keeps its own,
     * since the fallback carries the central host's domain.
     *
     * @param  string|array<int|string, mixed>|Closure  $action
     */
    public static function fallback(string|array|Closure $action): RegisteredRoute
    {
        return Route::fallback($action)
            ->where('fallbackPlaceholder', self::FALLBACK_PATH)
            ->middleware([PublicRoute::NAME, SetSiteLocale::NAME])
            ->withoutMiddleware(self::SESSION)
            ->name('not-found');
    }
}
