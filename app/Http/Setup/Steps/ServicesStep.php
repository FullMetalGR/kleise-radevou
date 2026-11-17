<?php

declare(strict_types=1);

namespace App\Http\Setup\Steps;

use App\Http\Controllers\Admin\ServiceController;
use App\Http\Controllers\Admin\SetupStepsController;
use App\Http\Middleware\RequiresActiveSubscription;
use App\Http\Middleware\RequiresPermission;
use App\Http\Setup\SetupStep;
use App\Infrastructure\Db\ServiceRecords;
use App\Setup\SetupProgress;
use App\Tenancy\Permission;
use App\Tenancy\Tenant;
use Illuminate\Support\Facades\Route;

/**
 * The Services step: the business's services and a short form adding one. The list names each
 * service in any status, in the catalog's order, with its duration and its price in its own
 * currency; the form's fields and the other fields' blank values are the Services screen's, read
 * in its words.
 */
final readonly class ServicesStep implements SetupStep
{
    /** What the list shows of each service. */
    private const array COLUMNS = ['id', 'name', 'duration_min', 'price_minor', 'currency'];

    public function __construct(private ServiceRecords $services) {}

    public function sections(): array
    {
        return [ServiceController::SECTION];
    }

    /** @return array<string, mixed> */
    public function data(Tenant $tenant, SetupProgress $progress): array
    {
        return ['services' => array_map(fn (array $row): array => array_intersect_key($row, array_flip(self::COLUMNS)), $this->services->all($tenant))];
    }

    /**
     * POST /admin/setup/services, the step's add: the Services screen's create, its body and its
     * rules.
     */
    public static function routes(): void
    {
        Route::post('/setup/services', [SetupStepsController::class, 'service'])
            ->middleware([RequiresPermission::of(Permission::ManageBilling), RequiresActiveSubscription::NAME])
            ->name('setup.services');
    }
}
