<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site;

use App\Site\Brand;
use App\Site\PricingSheet;
use App\Site\SiteLanguage;
use App\Site\SitePages;
use App\Tenancy\TenantProvisioner;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * The pricing page, at /pricing and /el/pricing: the plans' cards and the table of what each has,
 * as PricingSheet reads them from the plan catalogue once for the page in its language, which the
 * route's locale has set, then the questions about the price from pricing.php, the public name
 * filled in. Each card's signup action links the form the one helper names for the page's
 * language. No form and no script; a catalogue nobody can read answers this page at 200, never a
 * 500.
 */
final readonly class PricingController
{
    public function __invoke(Request $request, PricingSheet $sheet, SitePages $pages, Brand $brand, Factory $views): View
    {
        $language = SiteLanguage::ofPath($request->path());

        return $views->make('site.pricing', [
            ...$sheet->in($language),
            'name' => $brand->name,
            'signup' => $pages->signup($language),
            'trialDays' => (string) TenantProvisioner::trialDays(),
        ]);
    }
}
