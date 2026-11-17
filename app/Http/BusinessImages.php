<?php

declare(strict_types=1);

namespace App\Http;

use App\Infrastructure\Storage\TenantImages;
use App\Tenancy\MapState;
use App\Tenancy\Settings;
use App\Tenancy\Slug;
use App\Tenancy\TenantUrls;

/**
 * The business page's stored images as the admin shows them: the logo, any other stored image
 * such as the cover or a gallery photo, and the map. Settings reads them for its Booking page
 * section and the setup wizard's Business profile step for its own, so the two show one shape.
 */
final readonly class BusinessImages
{
    public function __construct(private TenantUrls $urls) {}

    /**
     * The logo on its own route, or null where none is stored.
     *
     * @return array{name: string, url: string}|null
     */
    public function logo(Slug $slug, Settings $settings): ?array
    {
        $path = $settings->brandLogoPath;

        return $path === null ? null : ['name' => TenantImages::fileOf($path), 'url' => $this->urls->logo($slug, TenantImages::fileOf($path))];
    }

    /**
     * The cover, or null where none is stored.
     *
     * @return array{name: string, url: string}|null
     */
    public function cover(Slug $slug, Settings $settings): ?array
    {
        return $settings->coverPath === null ? null : $this->image($slug, $settings->coverPath);
    }

    /**
     * One stored image as the screen shows it: the file name the removal route names and the URL
     * the page loads it from.
     *
     * @return array{name: string, url: string}
     */
    public function image(Slug $slug, string $path): array
    {
        $file = TenantImages::fileOf($path);

        return ['name' => $file, 'url' => $this->urls->image($slug, $file)];
    }

    /**
     * The map as the screen's type has it: ready with its stored image, or any other state with
     * none. A ready row naming no path, which only a hand's write leaves, reads as none, so a ready
     * state never crosses with a null image.
     *
     * @return array{state: string, image: array{name: string, url: string}|null}
     */
    public function map(Slug $slug, Settings $settings): array
    {
        if ($settings->mapState === MapState::Ready) {
            return $settings->mapPath === null
                ? ['state' => MapState::None->value, 'image' => null]
                : ['state' => MapState::Ready->value, 'image' => $this->image($slug, $settings->mapPath)];
        }

        return ['state' => $settings->mapState->value, 'image' => null];
    }
}
