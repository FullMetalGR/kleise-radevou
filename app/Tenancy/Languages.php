<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Application\Notification\Recipient;
use App\Infrastructure\Db\SettingsReader;
use App\Site\SiteLanguage;
use Illuminate\Http\Request;

/**
 * The resolver of the four languages: each answered here and nowhere else, each a SiteLanguage, en
 * or el and nothing else.
 *
 * The tenant default is settings.language where the tenant chose one in Settings, else
 * tenants.locale, the language its signup was made in; a tenant with no schema to read, one still
 * provisioning or one the drop closed, answers tenants.locale. The request's language on a tenant
 * host is its path's prefix, /en or /el (TenantPages), else, on the API, the lang query parameter,
 * else the tenant default; a lang naming neither language reads as absent. The central host keeps
 * SetSiteLocale's language from the route, and the console answers en, each with no reader here.
 * The user's language is users.locale where the user chose one, else the tenant default: the admin
 * answers a signed-in user in it, and a page with nobody signed in or a support session, which has
 * no user, in the tenant default. The booking's language is bookings.language, written once at the
 * hold and never updated.
 *
 * A platform mail to a user of the tenant, the BrandedMails, is in its recipient's language, the
 * user's language as above, a recipient who chose none or has no user reading the tenant default.
 */
final readonly class Languages
{
    /** What the API's language parameter is called in a query string. */
    public const string QUERY = 'lang';

    public function __construct(
        private SettingsReader $settings,
    ) {}

    public function tenantDefault(Tenant $tenant): SiteLanguage
    {
        if (in_array($tenant->status, TenantStatus::schemaless(), true)) {
            return $tenant->locale;
        }

        return $this->settings->language($tenant) ?? $tenant->locale;
    }

    /**
     * The language a request to the tenant's host is answered in: the language it names, else the
     * tenant default.
     */
    public function ofRequest(Request $request, Tenant $tenant): SiteLanguage
    {
        return $this->namedBy($request) ?? $this->tenantDefault($tenant);
    }

    /**
     * The language a request to the tenant's host names: the prefix its path names, or on the API
     * the lang parameter; null where it names none, the tenant default then answering it. A link
     * a request mints for the page it leads to carries this as its prefix, bare where it is null,
     * so the page answers in the language the request was answered in (the hold's checkout_url).
     */
    public function namedBy(Request $request): ?SiteLanguage
    {
        $prefix = TenantPages::prefixOf($request->path());

        if ($prefix !== null) {
            return $prefix;
        }

        if (! $request->is('api/*')) {
            return null;
        }

        $asked = $request->query(self::QUERY);

        return is_string($asked) ? SiteLanguage::tryFrom($asked) : null;
    }

    /**
     * The language a user of the tenant reads the admin in, and the business's mails to them:
     * $chosen, their users.locale, where they chose one, else the tenant default. $chosen is
     * null for a user who chose none and where no user stands behind the request, a page with
     * nobody signed in or a support session, and for a business message to a staff row's own
     * address, which no user holds.
     */
    public function ofUser(Tenant $tenant, ?SiteLanguage $chosen): SiteLanguage
    {
        return $chosen ?? $this->tenantDefault($tenant);
    }

    /**
     * The language a platform mail to one user of the tenant is written in: the recipient's
     * language, $chosen, their users.locale, else the tenant default, which for a row with no
     * schema, a signup's reservation before its build, is tenants.locale.
     */
    public function ofPlatformMail(Tenant $tenant, ?SiteLanguage $chosen): SiteLanguage
    {
        return $this->ofUser($tenant, $chosen);
    }

    /**
     * The language a booking was made in, from its bookings.language: en where the column holds
     * anything its CHECK does not admit, which no row can. The column was written at the hold
     * from the request's language, and a booking keeps the language it was made in whatever the
     * tenant says later. Static, since it reads nothing but the stored code, so the static
     * templates of a booking's messages read their language here from the snapshot that carries
     * it.
     */
    public static function ofBooking(string $stored): SiteLanguage
    {
        return self::ofCode($stored);
    }

    /**
     * The language a business message is written in, from the code its recipient carries: the
     * answer ofUser gave when BusinessRecipients named them, a user's own or, for a staff row's
     * address and a user who chose none, the tenant default; en for a code that is neither, which
     * no recipient carries. Static, as ofBooking is, since it reads nothing but the code, so the
     * static templates of the business's messages read their language here from the recipient each
     * is written for.
     */
    public static function ofRecipient(Recipient $to): SiteLanguage
    {
        return self::ofCode($to->language);
    }

    private static function ofCode(string $code): SiteLanguage
    {
        return SiteLanguage::tryFrom($code) ?? SiteLanguage::English;
    }
}
