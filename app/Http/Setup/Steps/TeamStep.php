<?php

declare(strict_types=1);

namespace App\Http\Setup\Steps;

use App\Http\Controllers\Admin\SetupStepsController;
use App\Http\Controllers\Admin\StaffController;
use App\Http\Middleware\RequiresActiveSubscription;
use App\Http\Middleware\RequiresPermission;
use App\Http\Setup\SetupStep;
use App\Infrastructure\Db\ServiceRecords;
use App\Infrastructure\Db\StaffRecords;
use App\Setup\SetupProgress;
use App\Tenancy\Permission;
use App\Tenancy\Tenant;
use Illuminate\Support\Facades\Route;

/**
 * The Team step, only with the staff feature: the staff members and a short form adding one. The
 * list names each member in any status, in the roster's order, with the services they perform,
 * but never the built-in member who stands for the business; the form's name and its services
 * are the Staff screen's, read in its words, picked from every service the business has.
 */
final readonly class TeamStep implements SetupStep
{
    public function __construct(private StaffRecords $staff, private ServiceRecords $services) {}

    public function sections(): array
    {
        return [StaffController::SECTION];
    }

    /** @return array<string, mixed> */
    public function data(Tenant $tenant, SetupProgress $progress): array
    {
        $members = [];

        foreach ($this->staff->all($tenant) as $member) {
            if (! $member['is_implicit']) {
                $members[] = ['id' => $member['id'], 'name' => $member['name'], 'service_ids' => $member['service_ids']];
            }
        }

        return [
            'members' => $members,
            'services' => array_map(fn (array $row): array => array_intersect_key($row, array_flip(['id', 'name', 'status'])), $this->services->all($tenant)),
        ];
    }

    /**
     * POST /admin/setup/team, the step's add: the Staff screen's create, its body and its rules.
     */
    public static function routes(): void
    {
        Route::post('/setup/team', [SetupStepsController::class, 'member'])
            ->middleware([RequiresPermission::of(Permission::ManageBilling), RequiresActiveSubscription::NAME])
            ->name('setup.team');
    }
}
