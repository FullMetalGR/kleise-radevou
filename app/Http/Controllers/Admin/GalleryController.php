<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\AdminLines;
use App\Http\AdminSession;
use App\Http\ErrorEnvelope;
use App\Http\Requests\GalleryPhotoRequest;
use App\Http\Requests\SettingsRequest;
use App\Infrastructure\Db\SettingsReader;
use App\Infrastructure\Db\SettingsRecords;
use App\Infrastructure\Storage\ImageKind;
use App\Infrastructure\Storage\ImageRefused;
use App\Infrastructure\Storage\TenantImages;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Settings;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The business page's gallery on the Settings screen: one photo added after the others, and one
 * removed by its file name, each its own write apart from the settings Save for the logo's
 * reasons, under the same permission and gate on its routes.
 *
 * The gallery holds Settings::GALLERY_CAPACITY photos. A full gallery refuses the next
 * before anything is stored, and the append re-counts under the row's lock, so an upload that
 * lost the last place to another deletes the file it stored and answers the same refusal. The
 * same photo uploaded twice stores the same bytes under the same name, and is refused rather
 * than held twice, since one removal would then take the file from under the other.
 */
final readonly class GalleryController
{
    /** The writes' flashes, keys of settings.php's images group, in the request's language. */
    public const string ADDED = 'admin/settings.images.flashes.photo_added';

    public const string REMOVED = 'admin/settings.images.flashes.photo_removed';

    /** The refusals, the full one naming the capacity as :capacity, spelled in words by ICU. */
    public const string FULL = 'admin/settings.images.refusals.full';

    public const string ALREADY_HELD = 'admin/settings.images.refusals.already_held';

    public function __construct(
        private CurrentTenant $current,
        private TenantImages $images,
        private SettingsReader $reader,
        private SettingsRecords $settings,
    ) {}

    public function store(GalleryPhotoRequest $request): RedirectResponse
    {
        $tenant = $this->current->get();

        if (count($this->reader->read($tenant)->galleryPaths) >= Settings::GALLERY_CAPACITY) {
            self::refuse(self::fullSentence());
        }

        try {
            $path = $this->images->store($tenant, ImageKind::Gallery, $request->photo());
        } catch (ImageRefused) {
            self::refuse(__(GalleryPhotoRequest::TYPE));
        }

        $held = $this->settings->addGalleryPath($tenant, $path);

        if (in_array($path, $held, true)) {
            self::refuse(SettingsRequest::line(self::ALREADY_HELD));
        }

        if (count($held) >= Settings::GALLERY_CAPACITY) {
            $this->images->delete($tenant, $path);
            self::refuse(self::fullSentence());
        }

        $request->session()->flash('flash', ['kind' => 'success', 'message' => SettingsRequest::line(self::ADDED)]);

        return redirect()->back(fallback: AdminSession::at($request, SettingsController::path()));
    }

    public function destroy(Request $request, string $file): RedirectResponse
    {
        $tenant = $this->current->get();
        $removed = $this->settings->removeGalleryPhoto($tenant, $file) ?? throw new NotFoundHttpException;
        $this->images->delete($tenant, $removed);
        $request->session()->flash('flash', ['kind' => 'success', 'message' => SettingsRequest::line(self::REMOVED)]);

        return redirect()->back(fallback: AdminSession::at($request, SettingsController::path()));
    }

    /** The full gallery's refusal, the capacity spelled in the language the key is read in. */
    private static function fullSentence(): string
    {
        return SettingsRequest::line(self::FULL, ['capacity' => AdminLines::spelled(self::FULL, Settings::GALLERY_CAPACITY)]);
    }

    /** Answers the envelope's 400 with the sentence under the upload's field. */
    private static function refuse(string $sentence): never
    {
        throw new HttpResponseException(ErrorEnvelope::for(ValidationException::withMessages([GalleryPhotoRequest::FIELD => $sentence]))->toResponse());
    }
}
