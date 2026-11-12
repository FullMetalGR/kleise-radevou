<?php

declare(strict_types=1);

namespace App\Infrastructure\Storage;

/**
 * What a tenant image is for: the logo, the profile's cover and gallery, a staff member's photo,
 * and the business page's map, which a job fetches from Google. The value prefixes the stored
 * file's name, so a name says which kind it is and the lookup can answer a route for some kinds
 * and not others.
 */
enum ImageKind: string
{
    case Logo = 'logo';
    case Cover = 'cover';
    case Gallery = 'gallery';
    case StaffPhoto = 'staff';
    case Map = 'map';

    /**
     * The longest edge, in pixels, the store scales the kind down to when its caller gives none:
     * 1600 for the cover and the gallery, 400 for the staff photo, and none for the logo, which
     * keeps its own size under its upload's size rule, or for the map, which Google draws at 640
     * by 320 already. This is the one place the numbers live, so the uploads that store these
     * kinds spell none.
     */
    public function longestEdge(): ?int
    {
        return match ($this) {
            self::Logo, self::Map => null,
            self::Cover, self::Gallery => 1600,
            self::StaffPhoto => 400,
        };
    }

    /**
     * The kind a stored file's name says it is, by the prefix the store writes before the hash;
     * null for a name the store never writes.
     */
    public static function ofFile(string $file): ?self
    {
        $prefix = strstr($file, '-', true);

        return $prefix === false ? null : self::tryFrom($prefix);
    }
}
