<?php

use App\Http\Controllers\Console\AuditController;
use App\Http\Controllers\Console\ClosingController;
use App\Http\Controllers\Console\LoginController;
use App\Http\Controllers\Console\LogoutController;
use App\Http\Controllers\Console\MessagesController;
use App\Http\Controllers\Console\NewTenantController;
use App\Http\Controllers\Console\NotFoundController;
use App\Http\Controllers\Console\PasswordLinkController;
use App\Http\Controllers\Console\SupportController;
use App\Http\Controllers\Console\SupportEndController;
use App\Http\Controllers\Console\SuspensionController;
use App\Http\Controllers\Console\TenantController;
use App\Http\Controllers\Console\TenantFeaturesController;
use App\Http\Controllers\Console\TenantsController;
use App\Http\Middleware\HandleConsoleInertiaRequests;
use App\Http\Middleware\PublicRoute;
use App\Http\Middleware\RequiresOperator;
use Illuminate\Support\Facades\Route;

/*
 * Routes on the console's host: console. under the central host, where the platform's operator
 * signs in and reads and acts on the tenants. Tenant resolution never runs here: CurrentTenant is
 * bound on no route of this file. A GET this file does not name, a tenant path among them, is the
 * console's own 404 for an operator and the door for anyone else. Another verb on such a path
 * falls through to the tenant group where a tenant route takes that verb on that path, which
 * refuses the reserved label with a 404, and is a 405 off the GET catch-all below where none does.
 *
 * Inertia over the session, rendered by the admin bundle from pages of its own under console/
 * through the host's own adapter, which hands the flash, the operator and the validation errors
 * and nothing a tenant page shares. There is no JSON endpoint here.
 *
 * Each route declares its one access, never the group: public on the door's three, operator, the
 * console guard's operator or the door, with the session's two timeouts and its password
 * fingerprint, on every screen and action behind it. Nothing here is gated by the subscription.
 * ConsoleSurfaceTest pins the surface verb by path, and a route added here is added there.
 */
Route::name('console.')->middleware(HandleConsoleInertiaRequests::class)->group(function (): void {
    Route::get('/login', [LoginController::class, 'show'])->middleware(PublicRoute::NAME)->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware(['throttle:console-login', PublicRoute::NAME])->name('login.attempt');
    Route::post('/logout', LogoutController::class)->middleware(PublicRoute::NAME)->name('logout');
    Route::get('/', TenantsController::class)->middleware(RequiresOperator::NAME)->name('tenants');
    // The free tenant's form before a tenant's detail, so /tenants/new is never read as a slug;
    // new is a reserved label besides, so no tenant can hold it.
    Route::get('/tenants/new', [NewTenantController::class, 'show'])->middleware(RequiresOperator::NAME)->name('tenants.new');
    Route::post('/tenants', [NewTenantController::class, 'store'])->middleware(RequiresOperator::NAME)->name('tenants.store');
    // A closed row's detail by its id, the drop having released its slug.
    Route::get('/tenants/closed/{id}', [TenantController::class, 'closed'])->middleware(RequiresOperator::NAME)->name('tenants.closed');
    Route::get('/tenants/{slug}', TenantController::class)->middleware(RequiresOperator::NAME)->name('tenants.show');
    Route::post('/tenants/{slug}/suspend', [SuspensionController::class, 'suspend'])->middleware(RequiresOperator::NAME)->name('tenants.suspend');
    Route::post('/tenants/{slug}/resume', [SuspensionController::class, 'resume'])->middleware(RequiresOperator::NAME)->name('tenants.resume');
    Route::post('/tenants/{slug}/close', [ClosingController::class, 'close'])->middleware(RequiresOperator::NAME)->name('tenants.close');
    Route::post('/tenants/{slug}/reopen', [ClosingController::class, 'reopen'])->middleware(RequiresOperator::NAME)->name('tenants.reopen');
    Route::post('/tenants/{slug}/features', TenantFeaturesController::class)->middleware(RequiresOperator::NAME)->name('tenants.features');
    Route::post('/tenants/{slug}/users/{id}/password-link', PasswordLinkController::class)->middleware(RequiresOperator::NAME)->name('tenants.users.password-link');
    Route::post('/tenants/{slug}/support', SupportController::class)->middleware(RequiresOperator::NAME)->name('tenants.support');
    Route::post('/support/{id}/end', SupportEndController::class)->middleware(RequiresOperator::NAME)->name('support.end');
    Route::get('/messages', [MessagesController::class, 'index'])->middleware(RequiresOperator::NAME)->name('messages');
    Route::post('/messages/{id}/archive', [MessagesController::class, 'archive'])->middleware(RequiresOperator::NAME)->name('messages.archive');
    Route::get('/audit', AuditController::class)->middleware(RequiresOperator::NAME)->name('audit');

    // Last: any other GET on this host is the console's 404 inside its shell for an operator and
    // the door for anyone else. Another verb falls through to the tenant group where a tenant route
    // takes it on that path, a 404 for the reserved label, and is a 405 off this catch-all where
    // none does.
    Route::get('/{path}', NotFoundController::class)->where('path', '.*')->middleware(RequiresOperator::NAME)->name('not-found');
});
