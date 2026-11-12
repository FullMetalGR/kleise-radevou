<?php

declare(strict_types=1);

namespace App\Http;

use App\Application\Dto\Customer;
use App\Site\SiteLanguage;
use App\Tenancy\Settings;
use App\Tenancy\Tenant;

/**
 * The boot payload the booking app needs before its first render: the tenant's name, its
 * timezone, its currency, its slot granularity, its Important info, the one text the review step
 * carries above its action button, null where the owner left it empty, the tenant default
 * language and the request's language as the server resolved it, the language the app holds and
 * passes on every call. Seven keys, and both surfaces converge on them: the hosted page inlines
 * this, on the booking page and the manage page alike, and the embed fetches it from GET
 * /api/v1/config. Nothing about packaging, ever: an entitlement list in a public payload
 * publishes what the tenant bought to every scraper, so the shape is closed here rather than
 * built from the tenant row.
 */
final readonly class BootConfig
{
    public function __construct(
        public string $tenantName,
        public string $timezone,
        public string $currency,
        public int $granularityMin,
        public ?string $importantInfo,
        public SiteLanguage $defaultLanguage,
        public SiteLanguage $language,
    ) {}

    /**
     * @param  SiteLanguage  $defaultLanguage  the tenant default, Languages::tenantDefault
     * @param  SiteLanguage  $language  the request's language, Languages::ofRequest
     */
    public static function for(Tenant $tenant, Settings $settings, SiteLanguage $defaultLanguage, SiteLanguage $language): self
    {
        return new self($tenant->name, $tenant->timezone, $tenant->currency, $settings->granularityMin, $settings->profile->importantInfo, $defaultLanguage, $language);
    }

    /**
     * @return array{tenant_name: string, timezone: string, currency: string, granularity_min: int, important_info: string|null, default_language: string, language: string}
     */
    public function toArray(): array
    {
        return [
            'tenant_name' => $this->tenantName,
            'timezone' => $this->timezone,
            'currency' => $this->currency,
            'granularity_min' => $this->granularityMin,
            'important_info' => $this->importantInfo,
            'default_language' => $this->defaultLanguage->value,
            'language' => $this->language->value,
        ];
    }

    /**
     * The payload as the hosted page inlines it, inside a JSON script element. The characters
     * that could close that element or open a tag are escaped as JSON allows, so a tenant name
     * or an owner's text stays data whatever it contains.
     *
     * The business page alone, with a My bookings session open, inlines an eighth key beside the
     * seven: customer, the name, the email and the phone the details step starts from. It is never
     * part of toArray, so the API's config, which the embed reads, stays the seven keys on every
     * request, whatever cookie it carries.
     */
    public function toInlineJson(?Customer $customer = null): string
    {
        $inlined = $customer === null ? $this->toArray() : [...$this->toArray(), 'customer' => [
            'name' => $customer->name,
            'email' => $customer->email,
            'phone' => $customer->phone,
        ]];

        return json_encode($inlined, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    }
}
