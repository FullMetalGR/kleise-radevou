<?php

use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\SetTenantLocale;
use App\Tenancy\TenantUrls;
use Illuminate\Support\Facades\Route;

/*
 * The host split, and nothing else. A route registered in this file would answer on every host,
 * so every route lives in one of the three files below: central.php on the central host, where no
 * tenant is resolved; operator.php on the console's host, console. under the central host, where
 * no tenant is resolved either; and tenant.php on one subdomain of the central host, behind
 * tenant resolution. The central host is the application URL's, so development serves
 * acme.localhost:8000 and production acme.kleiseradevou.gr from the one setting that names the
 * deployment.
 *
 * The console's group is registered before the tenant group, so a path the console answers is
 * matched on the console's host before the tenant pattern is tried; its catch-all answers every
 * other GET there. Another verb on a path the console does not answer falls through to the tenant
 * group where a tenant route takes that verb on that path, and is refused there with a 404, the
 * console's label being reserved as every reserved label is; where no tenant route takes it, the
 * console's GET catch-all matches the path and the answer is a 405.
 */
$urls = app(TenantUrls::class);
$central = $urls->centralHost();

Route::domain($central)->group(base_path('routes/central.php'));

Route::domain($urls->consoleHost())->group(base_path('routes/operator.php'));

Route::domain('{tenant}.'.$central)
    ->middleware([ResolveTenant::class, SetTenantLocale::class])
    ->group(base_path('routes/tenant.php'));
