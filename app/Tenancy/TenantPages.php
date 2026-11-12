<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Site\SiteLanguage;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * The addresses of a tenant host's pages: every page outside the admin, the API and the static
 * files answers at its bare path, in the tenant default, and under /en and /el, each naming its
 * language, over the same ASCII path segments, never translated. The default's own prefix answers
 * the same page as the bare path and never redirects. The business page under a prefix is /en or
 * /el, as the site's Greek home is, with no trailing slash.
 *
 * A page's route is registered three times by register(), the bare one named for the page and each
 * twin named with its language's prefix, so manage, en.manage and el.manage, the site's el.
 * precedent (SiteLanguage::routeName) with English given a prefix of its own; every route declares
 * its own access as it did, and the twins carry the same declaration, limit and constraints, so a
 * prefix changes no route's access. A list that names a page route reads it through pageOf(), so
 * a twin is the page it twins.
 *
 * addresses() computes, for a request, where the language switch leads, the hreflang pair and the
 * canonical, on the site's precedent (ChromeComposer); nothing renders them yet.
 */
final class TenantPages
{
    /**
     * Registers the page routes the closure registers at their bare paths, then again under each
     * language's prefix, named with it.
     */
    public static function register(Closure $routes): void
    {
        $routes();

        foreach (SiteLanguage::cases() as $language) {
            Route::prefix($language->value)->name($language->value.'.')->group($routes);
        }
    }

    /**
     * A page route's name under a prefix, or its bare name for none.
     */
    public static function routeName(string $page, ?SiteLanguage $prefix): string
    {
        return $prefix === null ? $page : $prefix->value.'.'.$page;
    }

    /**
     * The three names a page answers under: the bare one and its two twins.
     *
     * @return list<string>
     */
    public static function names(string $page): array
    {
        return [$page, ...array_map(fn (SiteLanguage $language): string => self::routeName($page, $language), SiteLanguage::cases())];
    }

    /**
     * The page a route name names, its bare name whichever prefix it carries.
     */
    public static function pageOf(string $name): string
    {
        foreach (SiteLanguage::cases() as $language) {
            if (str_starts_with($name, $language->value.'.')) {
                return substr($name, strlen($language->value) + 1);
            }
        }

        return $name;
    }

    /**
     * The language a request path's prefix names, the path as the framework spells it with no
     * leading slash and the root as a lone slash, or null for a bare path.
     */
    public static function prefixOf(string $path): ?SiteLanguage
    {
        return SiteLanguage::tryFrom(explode('/', trim($path, '/'), 2)[0]);
    }

    /**
     * A page's path under a prefix: the bare path for none, else the path after the language's
     * segment, the root as /en or /el.
     */
    public static function uri(string $path, ?SiteLanguage $prefix): string
    {
        $bare = '/'.ltrim($path, '/');

        if ($prefix === null) {
            return $bare;
        }

        return '/'.$prefix->value.($bare === '/' ? '' : $bare);
    }

    /**
     * Where the request's switch leads, its hreflang pair and its canonical, for a tenant whose
     * default is the language given. The switch leads to the same path under the other
     * language's prefix, the default's explicit prefix included, the query string kept, a token
     * in it or in the path alike. The pair names the default's page by its bare path and the
     * other's by its prefix, and names the default's explicit prefix never; the canonical is the
     * request's language's address in the pair. The pair and the canonical drop the query string,
     * so no token travels in either.
     */
    public static function addresses(Request $request, SiteLanguage $default): PageAddresses
    {
        $prefix = self::prefixOf($request->path());
        $language = $prefix ?? $default;
        $path = '/'.ltrim($request->path(), '/');
        $bare = $prefix === null ? $path : (substr($path, strlen('/'.$prefix->value)) ?: '/');
        // The query as it came, not as the framework normalises it, so the switch keeps its order.
        $query = $request->server->get('QUERY_STRING');
        $query = is_string($query) && $query !== '' ? $query : null;
        $root = $request->getSchemeAndHttpHost();
        $address = fn (SiteLanguage $one): string => $root.self::uri($bare, $one === $default ? null : $one);
        $alternates = [];

        foreach (SiteLanguage::cases() as $one) {
            $alternates[$one->value] = $address($one);
        }

        return new PageAddresses(
            language: $language,
            switchHref: $root.self::uri($bare, $language->other()).($query === null ? '' : '?'.$query),
            alternates: $alternates,
            canonical: $address($language),
        );
    }
}
