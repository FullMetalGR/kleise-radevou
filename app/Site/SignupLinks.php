<?php

declare(strict_types=1);

namespace App\Site;

use Illuminate\Contracts\Routing\UrlGenerator;

/**
 * The signup's addresses in a language. Every one is built from a route's name through
 * SiteLanguage::routeName, so the Greek twin under /el is whatever the router holds and no path
 * is spelled here. The pending page's and the verification link's are absolute on the central
 * host, as a redirect and the mail need them; the two POSTs' are paths, which a page posts to
 * on the host it was served from.
 */
final readonly class SignupLinks
{
    public const string FORM = SitePages::SIGNUP;

    public const string STORE = 'signup.store';

    public const string PENDING = 'signup.pending';

    public const string RESEND = 'signup.resend';

    public const string VERIFY = 'signup.verify';

    public const string VERIFY_MALFORMED = 'signup.verify.malformed';

    public function __construct(private SitePages $pages, private UrlGenerator $url) {}

    /**
     * The pending page of a slug in a language, where the form's POST, the click and the resend
     * send the person. The slug arrives as the path carried it, a string that is no slug
     * included: the resend sends such a one on to the page's 404 as it does any other.
     */
    public function pending(string $slug, SiteLanguage $language): string
    {
        return $this->pages->url(self::PENDING, $language, ['slug' => $slug]);
    }

    /**
     * The verification link in a language, the row's: the click's route carrying the token.
     */
    public function verify(string $token, SiteLanguage $language): string
    {
        return $this->pages->url(self::VERIFY, $language, ['token' => $token]);
    }

    /**
     * Where the form posts, in the language the form was served in.
     */
    public function storePath(SiteLanguage $language): string
    {
        return $this->url->route($language->routeName(self::STORE), [], false);
    }

    /**
     * The two legal pages the form's terms box links, the terms of service and the privacy
     * policy, in the language the form was served in.
     *
     * @return array{terms: string, privacy: string}
     */
    public function legal(SiteLanguage $language): array
    {
        return ['terms' => $this->pages->url(SitePages::TERMS, $language), 'privacy' => $this->pages->url(SitePages::PRIVACY, $language)];
    }

    /**
     * Where the pending page's Resend control posts for its slug, in the language the page was
     * served in.
     */
    public function resendPath(string $slug, SiteLanguage $language): string
    {
        return $this->url->route($language->routeName(self::RESEND), ['slug' => $slug], false);
    }
}
