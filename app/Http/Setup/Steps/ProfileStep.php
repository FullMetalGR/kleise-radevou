<?php

declare(strict_types=1);

namespace App\Http\Setup\Steps;

use App\Http\BusinessImages;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\SetupStepsController;
use App\Http\CountryNames;
use App\Http\Middleware\RequiresActiveSubscription;
use App\Http\Middleware\RequiresPermission;
use App\Http\Requests\SetupProfileRequest;
use App\Http\Setup\SetupStep;
use App\Infrastructure\Db\SettingsReader;
use App\Setup\SetupProgress;
use App\Tenancy\Permission;
use App\Tenancy\Slug;
use App\Tenancy\Tenant;
use Illuminate\Support\Facades\Route;

/**
 * The Business profile step: About, the address and the phone, with the logo and the cover. Its
 * fields, its images and their uploads are the Settings screen's, read in its words; its save
 * writes those fields alone. The panel is handed the fields' stored values, the logo, the cover,
 * the map, the two images' largest sizes and the countries the select names.
 */
final readonly class ProfileStep implements SetupStep
{
    public function __construct(private SettingsReader $reader, private BusinessImages $images, private CountryNames $countries) {}

    public function sections(): array
    {
        return [SettingsController::SECTION];
    }

    /** @return array<string, mixed> */
    public function data(Tenant $tenant, SetupProgress $progress): array
    {
        $settings = $this->reader->read($tenant);
        $slug = Slug::of($tenant->slug);
        $limits = SettingsController::limits();

        return [
            'values' => array_intersect_key($settings->profile->toArray(), array_flip(SetupProfileRequest::FIELDS)),
            'logo' => $this->images->logo($slug, $settings),
            'cover' => $this->images->cover($slug, $settings),
            'map' => $this->images->map($slug, $settings),
            'limits' => ['logo' => $limits['logo'], 'cover' => $limits['cover']],
            'countries' => $this->countries->in(),
        ];
    }

    /**
     * PUT /admin/setup/profile, the step's save. The logo's and the cover's uploads are the
     * Settings screen's own routes.
     */
    public static function routes(): void
    {
        Route::put('/setup/profile', [SetupStepsController::class, 'profile'])
            ->middleware([RequiresPermission::of(Permission::ManageBilling), RequiresActiveSubscription::NAME])
            ->name('setup.profile');
    }
}
