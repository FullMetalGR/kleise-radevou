<?php

declare(strict_types=1);

namespace App\Site;

use App\Tenancy\TenantProvisioner;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RegisteredRoute;

/**
 * Hands the site's layout its chrome, built from the request's route, so a page's controller
 * renders its own view and nothing else, and a page added later needs no layout edit.
 *
 * A route SiteRoutes::page registered is a page: its language is its route's, its title and meta
 * description its language file's, under the key for a page served once per key (a vertical's
 * under verticals.{key}), its canonical its own address, its alternates the pair's, and its
 * language switch the same page in the other language, the route's parameters kept. The nav and
 * the footer are SiteLinks', a vertical linked once per key. Any other route rendering the layout
 * has no page to name: its language is its path's, it carries noindex and neither a canonical nor
 * alternates, and its switch leads to the page its action names, else to its own twin with its
 * parameters kept where the router holds one, else to the other language's home, the 404's case.
 * The central root view, the signup's frame, is handed such a chrome and renders its header and
 * footer alone: the form and the pending page switch to themselves in the other language, and the
 * refusals the form answers at 429 and 410 to the form. Every string is read in the chrome's
 * language and fills :name with the public name, and the header's wordmark is that name beside
 * the brand's mark. The footer carries the entity line in the chrome's language on every page,
 * the 404 and the signup's frame included, and the consent banner in the chrome's language while
 * the analytics tag's id is set, on every page those two views render.
 */
final readonly class ChromeComposer
{
    public function __construct(
        private Request $request,
        private SitePages $pages,
        private Translator $translator,
        private Brand $brand,
        private LegalFrame $legal,
        private SiteLinks $links,
        private Analytics $analytics,
    ) {}

    public function compose(View $view): void
    {
        $view->with('chrome', $this->chrome());
    }

    private function chrome(): Chrome
    {
        $route = $this->request->route();
        $name = $route instanceof RegisteredRoute ? $route->getName() : null;

        if ($route instanceof RegisteredRoute && $name !== null && is_string($route->action[SiteRoutes::COPY] ?? null)) {
            return $this->pageChrome($route, $name);
        }

        $language = SiteLanguage::ofPath($this->request->path());

        return new Chrome(
            language: $language,
            name: $this->brand->name,
            mark: $this->brand->mark(),
            title: $this->text('site.not_found.title', $language),
            description: $this->text('site.not_found.description', $language),
            canonical: null,
            alternates: [],
            noindex: true,
            home: $this->pages->url(SitePages::HOME, $language),
            switchHref: $this->switchOf($route instanceof RegisteredRoute ? $route : null, $name, $language->other()),
            switchLabel: $this->text('site.language', $language->other()),
            nav: $this->nav($language),
            footer: $this->footer($language),
            entity: $this->legal->entity($language),
            consent: $this->analytics->banner($language),
        );
    }

    private function pageChrome(RegisteredRoute $route, string $name): Chrome
    {
        $language = SiteLanguage::ofRouteName($name);
        $page = SiteLanguage::pageOf($name);
        $parameters = self::parametersOf($route);
        $copy = $this->pages->copyOf($page, $parameters);

        return new Chrome(
            language: $language,
            name: $this->brand->name,
            mark: $this->brand->mark(),
            title: $this->text($copy.'.title', $language),
            description: $this->text($copy.'.description', $language),
            canonical: $this->pages->url($page, $language, $parameters),
            alternates: $this->pages->alternates($page, $parameters),
            noindex: false,
            home: $this->pages->url(SitePages::HOME, $language),
            switchHref: $this->pages->url($page, $language->other(), $parameters),
            switchLabel: $this->text('site.language', $language->other()),
            nav: $this->nav($language),
            footer: $this->footer($language),
            entity: $this->legal->entity($language),
            consent: $this->analytics->banner($language),
        );
    }

    /**
     * Where the language switch of a route that is no page leads, in the other language: the page
     * the route's action names, with no parameter; else the route's own twin with its parameters
     * kept, where the router holds one; else the home.
     */
    private function switchOf(?RegisteredRoute $route, ?string $name, SiteLanguage $other): string
    {
        if ($route !== null && $name !== null) {
            $named = $route->action[SiteRoutes::SWITCH] ?? null;
            $twin = is_string($named) ? $this->pages->url($named, $other) : $this->pages->twin($name, $other, self::parametersOf($route));

            if ($twin !== null) {
                return $twin;
            }
        }

        return $this->pages->url(SitePages::HOME, $other);
    }

    /**
     * A route's parameters as the path carried them, the strings among them.
     *
     * @return array<string, string>
     */
    private static function parametersOf(RegisteredRoute $route): array
    {
        $parameters = [];

        foreach ($route->originalParameters() as $key => $value) {
            if (is_string($value)) {
                $parameters[(string) $key] = $value;
            }
        }

        return $parameters;
    }

    /**
     * The header's nav: the pages SitePages::NAV names that the router holds, in its order.
     *
     * @return list<array{href: string, label: string}>
     */
    private function nav(SiteLanguage $language): array
    {
        $registered = $this->pages->names();

        return $this->links->to(array_values(array_filter(SitePages::NAV, fn (string $page): bool => in_array($page, $registered, true))), $language);
    }

    /**
     * The footer's links: every page but the home, in registration order, and a page served once
     * per key under each of its keys, so the six verticals each.
     *
     * @return list<array{href: string, label: string}>
     */
    private function footer(SiteLanguage $language): array
    {
        return $this->links->to(array_values(array_filter($this->pages->names(), fn (string $page): bool => $page !== SitePages::HOME)), $language);
    }

    /**
     * One line of the chrome in a language, the public name filled in as :name and the trial's
     * length in days as :count, the one count a page's head names.
     */
    private function text(string $key, SiteLanguage $language): string
    {
        $text = $this->translator->get($key, ['name' => $this->brand->name, 'count' => (string) TenantProvisioner::trialDays()], $language->value);

        return is_string($text) ? $text : $key;
    }
}
