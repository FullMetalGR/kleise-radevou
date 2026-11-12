<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Site\Brand;
use App\Site\SiteLanguage;
use App\Tenancy\Languages;
use App\Tenancy\Tenant;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Contracts\View\Factory as ViewFactory;

/**
 * Where a platform mail in its recipient's language starts: the translator, the view factory and
 * the brand, bound to one language for one message.
 *
 * The language is the recipient's, to(), never the application's locale, which a request's route
 * sets and a command or a signup's POST may leave in the other language; so nothing here reads the
 * request, and a mail composed on the English signup for a Greek row is Greek. The recipient's
 * language is the resolver's, Languages::ofPlatformMail: the user's users.locale, else the tenant
 * default. The mails that compose through this are the tenant-facing ones; the operator's, the
 * console's sign-in notice and the contact form's message, stay plain English text and never come
 * here.
 */
final readonly class BrandedMails
{
    public function __construct(
        private Translator $translator,
        private ViewFactory $views,
        private Brand $brand,
        private Languages $languages,
    ) {}

    /**
     * A mail to one user of the tenant, in their language: $chosen is their users.locale, null
     * where they chose none, which reads the tenant default.
     */
    public function to(Tenant $tenant, ?SiteLanguage $chosen): BrandedMail
    {
        return $this->in($this->languages->ofPlatformMail($tenant, $chosen));
    }

    public function in(SiteLanguage $language): BrandedMail
    {
        return new BrandedMail($this->translator, $this->views, $this->brand, $language);
    }
}
