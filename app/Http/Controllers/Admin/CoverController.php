<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\AdminSession;
use App\Http\ErrorEnvelope;
use App\Http\Requests\CoverRequest;
use App\Http\Requests\SettingsRequest;
use App\Infrastructure\Db\SettingsRecords;
use App\Infrastructure\Storage\ImageKind;
use App\Infrastructure\Storage\ImageRefused;
use App\Infrastructure\Storage\TenantImages;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The business page's cover on the Settings screen: an upload replacing whatever was there, and
 * a removal, each its own write apart from the settings Save for the logo's three reasons, and
 * under the same permission and gate on its routes.
 *
 * The file goes through the tenant image store at the cover's own edge and lands before the row
 * names it; the file it replaced goes once the row has forgotten it, as LogoController does.
 */
final readonly class CoverController
{
    /** The writes' flashes, keys of settings.php's images group, in the request's language. */
    public const string UPLOADED = 'admin/settings.images.flashes.cover_uploaded';

    public const string REMOVED = 'admin/settings.images.flashes.cover_removed';

    public function __construct(
        private CurrentTenant $current,
        private TenantImages $images,
        private SettingsRecords $settings,
    ) {}

    public function store(CoverRequest $request): RedirectResponse
    {
        $tenant = $this->current->get();

        try {
            $path = $this->images->store($tenant, ImageKind::Cover, $request->cover());
        } catch (ImageRefused) {
            throw new HttpResponseException(ErrorEnvelope::for(ValidationException::withMessages([CoverRequest::FIELD => __(CoverRequest::TYPE)]))->toResponse());
        }

        $previous = $this->settings->setCoverPath($tenant, $path);

        if ($previous !== null && $previous !== $path) {
            $this->images->delete($tenant, $previous);
        }

        $request->session()->flash('flash', ['kind' => 'success', 'message' => SettingsRequest::line(self::UPLOADED)]);

        return redirect()->back(fallback: AdminSession::at($request, SettingsController::path()));
    }

    public function destroy(Request $request): RedirectResponse
    {
        $tenant = $this->current->get();
        $previous = $this->settings->setCoverPath($tenant, null);

        if ($previous !== null) {
            $this->images->delete($tenant, $previous);
        }

        $request->session()->flash('flash', ['kind' => 'success', 'message' => SettingsRequest::line(self::REMOVED)]);

        return redirect()->back(fallback: AdminSession::at($request, SettingsController::path()));
    }
}
