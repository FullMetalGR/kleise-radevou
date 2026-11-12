<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site;

use App\Site\Brand;
use App\Tenancy\TenantProvisioner;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;

/**
 * The FAQ, at /faq and /el/faq: faq.php's questions in the page's language, which the route's
 * locale has set, a list rendered as headings over their answers, every answer in the HTML as
 * served, the public name filled in wherever the copy names it. No form and no script.
 */
final readonly class FaqController
{
    public function __invoke(Brand $brand, Factory $views): View
    {
        return $views->make('site.faq', ['name' => $brand->name, 'trialDays' => (string) TenantProvisioner::trialDays()]);
    }
}
