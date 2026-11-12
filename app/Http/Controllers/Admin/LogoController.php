<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\AdminSession;
use App\Http\ErrorEnvelope;
use App\Http\Requests\LogoRequest;
use App\Http\Requests\SettingsRequest;
use App\Infrastructure\Db\SettingsRecords;
use App\Infrastructure\Storage\ImageKind;
use App\Infrastructure\Storage\ImageRefused;
use App\Infrastructure\Storage\TenantImages;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Tenant;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The logo's two actions on the Settings screen: an upload replacing whatever was there, and a
 * removal. Each is its own write with its own button and its own toast, not a field of the
 * settings patch: a megabyte that fails to land should not fail the currency, and the settings
 * save stays the one JSON body it is pinned to be. Both sit behind manageSettings and the
 * subscription gate, declared on their routes.
 *
 * The file goes through the tenant image store as every image does, at its own size with no
 * edge given, and lands before the settings row names it, so the row never points at a file
 * that is not there; the file it replaced goes once the row has forgotten it. Bytes that claim
 * one of the three types and do not decode are refused with the type sentence, as a GIF is.
 */
final readonly class LogoController
{
    /** The writes' flashes, keys of settings.php's images group, in the request's language. */
    public const string UPLOADED = 'admin/settings.images.flashes.logo_uploaded';

    public const string REMOVED = 'admin/settings.images.flashes.logo_removed';

    public function __construct(
        private CurrentTenant $current,
        private TenantImages $images,
        private SettingsRecords $settings,
    ) {}

    public function store(LogoRequest $request): RedirectResponse
    {
        $tenant = $this->current->get();

        try {
            $path = $this->images->store($tenant, ImageKind::Logo, $request->logo());
        } catch (ImageRefused) {
            throw new HttpResponseException(ErrorEnvelope::for(ValidationException::withMessages([LogoRequest::FIELD => __(LogoRequest::TYPE)]))->toResponse());
        }

        $this->forget($tenant, $this->settings->setLogoPath($tenant, $path), $path);
        $request->session()->flash('flash', ['kind' => 'success', 'message' => SettingsRequest::line(self::UPLOADED)]);

        return redirect()->back(fallback: AdminSession::at($request, SettingsController::path()));
    }

    public function destroy(Request $request): RedirectResponse
    {
        $tenant = $this->current->get();
        $this->forget($tenant, $this->settings->setLogoPath($tenant, null), null);
        $request->session()->flash('flash', ['kind' => 'success', 'message' => SettingsRequest::line(self::REMOVED)]);

        return redirect()->back(fallback: AdminSession::at($request, SettingsController::path()));
    }

    /**
     * Deletes the file the row named before this write, once the row no longer names it. Nothing
     * to remove is not an error: the row already says what the tenant asked for.
     */
    private function forget(Tenant $tenant, ?string $previous, ?string $current): void
    {
        if ($previous !== null && $previous !== $current) {
            $this->images->delete($tenant, $previous);
        }
    }
}
