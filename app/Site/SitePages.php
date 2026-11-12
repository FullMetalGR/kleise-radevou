<?php

declare(strict_types=1);

namespace App\Site;

use App\Tenancy\TenantUrls;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Routing\Router;
use LogicException;

/**
 * The site's page registry: the pages as the router holds them, every route SiteRoutes::page
 * registered, read back rather than listed, so a page registered later is in the nav, the footer,
 * the sitemap and the surface walk with no edit to any of them. A page is named by its English
 * route's name; its Greek route is the same name under el. A page with a parameter, the
 * verticals' template, is served once per key its route names, and each of those is listed as a
 * page of its own.
 *
 * Every address it spells is absolute on the central host, TenantUrls' origin, whatever host or
 * port the request came in on, as the alternates and the canonical must be.
 */
final readonly class SitePages
{
    public const string HOME = 'site.home';

    /**
     * The verticals' one template, served once per key of Vertical.
     */
    public const string VERTICAL = 'site.vertical';

    /**
     * The signup form's route, registered in both languages, so the sitemap lists it beside
     * the pages.
     */
    public const string SIGNUP = 'signup';

    /**
     * The cookie notice, which the consent banner links and the footer's cookie-settings control
     * is a link to where no script runs.
     */
    public const string COOKIES = 'site.cookies';

    /** The terms of service, which the signup form's box links. */
    public const string TERMS = 'site.terms';

    /** The privacy policy, which the signup form's box links. */
    public const string PRIVACY = 'site.privacy';

    /** The data processing agreement, the legal page beside the cookie notice. */
    public const string DPA = 'site.dpa';

    /**
     * The pages the header's nav links, in its order, each once its route exists; the footer
     * links every page but the home.
     */
    public const array NAV = ['site.features', 'site.pricing', 'site.faq', 'site.contact'];

    public function __construct(private Router $router, private UrlGenerator $url, private TenantUrls $urls) {}

    /**
     * Every page of the site by its English route's name, in registration order: every GET route
     * naming its copy, so a form's POST, which names its page's copy too, is never one.
     *
     * @return list<string>
     */
    public function names(): array
    {
        $names = [];

        foreach ($this->router->getRoutes()->getRoutes() as $route) {
            $name = $route->getName();

            if ($name !== null && in_array('GET', $route->methods(), true) && is_string($route->action[SiteRoutes::COPY] ?? null) && SiteLanguage::ofRouteName($name) === SiteLanguage::English) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * What the sitemap lists: every page, and every other route registered in both languages
     * named under site. or the signup form's own name, each by its English route's name in
     * registration order. The signup's other pages, the contact form's POST, the sitemap and the
     * 404 are never among them.
     *
     * @return list<string>
     */
    public function indexed(): array
    {
        $routes = $this->router->getRoutes();
        $names = [];

        foreach ($routes->getRoutes() as $route) {
            $name = $route->getName();

            if ($name === null || ! in_array('GET', $route->methods(), true) || SiteLanguage::ofRouteName($name) !== SiteLanguage::English) {
                continue;
            }

            if ((str_starts_with($name, 'site.') || $name === self::SIGNUP) && $routes->hasNamedRoute(SiteLanguage::Greek->routeName($name))) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * The parameter sets a page is served under, each one address in each language: a page with
     * no parameter has one, empty; a page served once per key, the verticals, has one per key in
     * the order its route lists them. Every route that is no page, the signup form among them,
     * has the one empty set. The footer, the sitemap and the surface walk list a page once per
     * set.
     *
     * @return non-empty-list<array<string, string>>
     */
    public function instances(string $page): array
    {
        $keys = $this->keysOf($page);

        if ($keys === null) {
            return [[]];
        }

        [$parameter, $values] = $keys;

        return array_map(fn (string $value): array => [$parameter => $value], $values);
    }

    /**
     * Where a page's copy lives for one of its parameter sets: the language file its route
     * names, and for a page served once per key, the key's entry in it, so verticals.barbers
     * for /for/barbers. Its title, its meta description and its heading are that entry's.
     *
     * @param  array<string, string>  $parameters
     */
    public function copyOf(string $page, array $parameters = []): string
    {
        $copy = $this->router->getRoutes()->getByName($page)?->action[SiteRoutes::COPY] ?? null;

        if (! is_string($copy)) {
            throw new LogicException(sprintf('%s is no page of the site.', $page));
        }

        $keys = $this->keysOf($page);

        if ($keys === null) {
            return $copy;
        }

        $key = $parameters[$keys[0]] ?? null;

        if ($key === null || ! in_array($key, $keys[1], true)) {
            throw new LogicException(sprintf('%s is served under none of the keys given.', $page));
        }

        return $copy.'.'.$key;
    }

    /**
     * The one parameter a page is served once per key of, and its keys in order, as its route
     * names them; null for a page with none.
     *
     * @return array{string, non-empty-list<string>}|null
     */
    private function keysOf(string $page): ?array
    {
        $route = $this->router->getRoutes()->getByName($page);
        $keys = $route?->action[SiteRoutes::KEYS] ?? null;

        if ($route === null || ! is_array($keys)) {
            return null;
        }

        $values = array_values(array_filter($keys, is_string(...)));
        $parameter = $route->parameterNames()[0] ?? null;

        if (! is_string($parameter) || $values === [] || count($values) !== count($keys)) {
            throw new LogicException(sprintf('%s names its keys for no parameter.', $page));
        }

        return [$parameter, $values];
    }

    /**
     * A page's address in a language.
     *
     * @param  array<string, string>  $parameters
     */
    public function url(string $page, SiteLanguage $language, array $parameters = []): string
    {
        return $this->urls->central($this->url->route($language->routeName($page), $parameters, false));
    }

    /**
     * The signup form's address for a page in a language, the one helper every signup action of
     * the site names it through: the form in that language, its Greek twin el.signup.
     */
    public function signup(SiteLanguage $language): string
    {
        return $this->url(self::SIGNUP, $language);
    }

    /**
     * The same route in a language, its parameters kept, for the language switch of a route that
     * is no page, the signup's: null for a route registered in one language alone, the 404's, one
     * route serving both languages' paths under one name, whose switch leads to the other
     * language's home.
     *
     * @param  array<string, string>  $parameters
     */
    public function twin(string $name, SiteLanguage $language, array $parameters = []): ?string
    {
        $page = SiteLanguage::pageOf($name);
        $routes = $this->router->getRoutes();

        if (! $routes->hasNamedRoute($page) || ! $routes->hasNamedRoute(SiteLanguage::Greek->routeName($page))) {
            return null;
        }

        return $this->url($page, $language, $parameters);
    }

    /**
     * A page's three alternates, by hreflang: the English address, the Greek one, and the
     * English one again as the default.
     *
     * @param  array<string, string>  $parameters
     * @return array<string, string>
     */
    public function alternates(string $page, array $parameters = []): array
    {
        $english = $this->url($page, SiteLanguage::English, $parameters);

        return ['en' => $english, 'el' => $this->url($page, SiteLanguage::Greek, $parameters), 'x-default' => $english];
    }
}
