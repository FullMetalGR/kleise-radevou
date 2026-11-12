<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\BootConfig;
use App\Http\Controllers\Controller;
use App\Infrastructure\Db\SettingsReader;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Languages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/config: the boot payload the embed fetches before its first render, since it
 * has nothing inlined. Exactly the seven keys BootConfig closes, and nothing about packaging.
 * Cross-origin by design: the embed runs on the tenant's own domain, and config/cors.php says
 * why the answer is permissive.
 *
 * The embed asks it with the host page's language as lang, where the page names English or
 * Greek, and the language it answers is the one the embed then holds: the lang asked, else
 * the tenant default.
 *
 * Public by design: no key and no token on the read surface.
 */
final class ConfigController extends Controller
{
    public function __invoke(Request $request, CurrentTenant $current, SettingsReader $settings, Languages $languages): JsonResponse
    {
        $tenant = $current->get();

        return new JsonResponse(BootConfig::for($tenant, $settings->read($tenant), $languages->tenantDefault($tenant), $languages->ofRequest($request, $tenant))->toArray());
    }
}
