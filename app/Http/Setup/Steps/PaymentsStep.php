<?php

declare(strict_types=1);

namespace App\Http\Setup\Steps;

use App\Http\Controllers\Admin\PaymentsController;
use App\Http\Controllers\Admin\SetupPaymentsController;
use App\Http\Middleware\RequiresActiveSubscription;
use App\Http\Middleware\RequiresPermission;
use App\Http\PaymentsSection;
use App\Http\Setup\SetupStep;
use App\Setup\SetupProgress;
use App\Tenancy\Permission;
use App\Tenancy\Tenant;
use Illuminate\Support\Facades\Route;

/**
 * The Online payments step: Settings' Payments section whole, its prop and its words, so every
 * state and refusal reads as it does there. Its writes run on Settings' own routes, but for the
 * two that send the owner to the provider's onboarding, which run on the step's routes to bring
 * the owner back into the wizard (SetupPaymentsController), and the return itself. Skippable, as
 * a business may take payment at its own premises alone.
 */
final readonly class PaymentsStep implements SetupStep
{
    public function __construct(private PaymentsSection $payments) {}

    public function sections(): array
    {
        return [PaymentsController::SECTION];
    }

    /**
     * @return array<string, mixed>
     */
    public function data(Tenant $tenant, SetupProgress $progress): array
    {
        return $this->payments->prop($tenant);
    }

    /**
     * The two onboarding writes, the owner's under the subscription gate as their Settings twins
     * are configuration writes; and the provider's way back, a read open to whoever may see
     * Home, where it lands.
     */
    public static function routes(): void
    {
        Route::post('/setup/payments/platform', [SetupPaymentsController::class, 'connect'])->middleware([RequiresPermission::of(Permission::ManageBilling), RequiresActiveSubscription::NAME])->name('setup.payments.platform');
        Route::post('/setup/payments/platform/resume', [SetupPaymentsController::class, 'resume'])->middleware([RequiresPermission::of(Permission::ManageBilling), RequiresActiveSubscription::NAME])->name('setup.payments.platform.resume');
        Route::get('/setup/payments/back', [SetupPaymentsController::class, 'back'])->middleware(RequiresPermission::of(Permission::ViewBookings, Permission::ViewOwnCalendar))->name('setup.payments.back');
    }
}
