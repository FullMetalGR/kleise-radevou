<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site;

use App\Site\Brand;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;

/**
 * The features page, at /features and /el/features: the product's features in groups, each a
 * heading over short paragraphs, every sentence from features.php in the page's language, which
 * the route's locale has set, the public name filled in wherever the copy names it. No form and
 * no script.
 */
final readonly class FeaturesController
{
    public function __invoke(Brand $brand, Factory $views): View
    {
        return $views->make('site.features', ['name' => $brand->name]);
    }
}
