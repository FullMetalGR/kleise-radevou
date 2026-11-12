<?php

declare(strict_types=1);

namespace App\Site;

/**
 * The site's two languages: English at the central host's root and Greek under /el, with the
 * same path segments in both, ASCII and never translated. A page's Greek route is its English
 * one's name with the prefix el., so site.pricing and el.site.pricing, signup and el.signup.
 * The value is the locale the translator reads lang/{value} for.
 *
 * Everything here is spelled from a path or a route name and nothing else: nothing reads the
 * browser's Accept-Language, and nothing redirects by it.
 */
enum SiteLanguage: string
{
    case English = 'en';
    case Greek = 'el';

    /**
     * The path segment the Greek pages live under and the prefix their route names carry.
     */
    public const string GREEK_PREFIX = 'el';

    /**
     * A page's path in this language: the English one as it is, the Greek one under /el, the
     * home's /el rather than /el/.
     */
    public function uri(string $uri): string
    {
        if ($this === self::English) {
            return $uri;
        }

        return '/'.self::GREEK_PREFIX.($uri === '/' ? '' : '/'.ltrim($uri, '/'));
    }

    /**
     * A page's route name in this language, from the English one.
     */
    public function routeName(string $page): string
    {
        return $this === self::English ? $page : self::GREEK_PREFIX.'.'.$page;
    }

    public function other(): self
    {
        return $this === self::English ? self::Greek : self::English;
    }

    /**
     * The language a route name is in: Greek for a name under el., English for any other.
     */
    public static function ofRouteName(string $name): self
    {
        return str_starts_with($name, self::GREEK_PREFIX.'.') ? self::Greek : self::English;
    }

    /**
     * The page a route name names, the English route's name, whichever language it is in.
     */
    public static function pageOf(string $name): string
    {
        return self::ofRouteName($name) === self::Greek ? substr($name, strlen(self::GREEK_PREFIX) + 1) : $name;
    }

    /**
     * The language of a request path as the framework spells it, no leading slash and the root
     * as a lone slash: Greek where the first segment is el, English everywhere else. The 404
     * reads it, having no route of its own to read it from.
     */
    public static function ofPath(string $path): self
    {
        $first = explode('/', trim($path, '/'), 2)[0];

        return $first === self::GREEK_PREFIX ? self::Greek : self::English;
    }
}
