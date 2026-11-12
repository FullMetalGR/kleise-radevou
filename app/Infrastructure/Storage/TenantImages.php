<?php

declare(strict_types=1);

namespace App\Infrastructure\Storage;

use App\Tenancy\Slug;
use App\Tenancy\Tenant;
use Illuminate\Contracts\Filesystem\Factory as Disks;
use Illuminate\Contracts\Filesystem\Filesystem;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Exceptions\ImageException;
use Intervention\Image\ImageManager;
use RuntimeException;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Mime\MimeTypes;

/**
 * The tenant's images on the public disk: the logo, the cover, the gallery and the staff photo
 * all land here the one way. A file is typed from its bytes and never its name, PNG, JPEG or
 * WebP and nothing else; scaled down to a longest edge, the caller's or else the kind's own
 * (ImageKind::longestEdge), keeping its aspect ratio and never enlarged; re-encoded in its own
 * format through GD, which writes pixels alone, so no EXIF, location or camera data survives;
 * and stored under the tenant's own directory named by its kind and a hash of the stored bytes,
 * so a replacement is a new file at a new URL and no browser holds the old one. The original is
 * never kept.
 *
 * The store records nothing. Its caller writes the path on the row that holds it once the file
 * has landed, so a row never names a file that is not there, and tells the store to delete the
 * file the row forgot. What a browser is served comes back through read(), by a path a row of
 * the tenant holds, which ImageLookup finds.
 *
 * Reading and deleting are bounded to the tenant's own directory here and not by the callers: a
 * path that is not the directory, a slash and one file name, so one with a `..` segment, an
 * absolute one or one under another tenant's directory, is refused before the disk is touched.
 */
final readonly class TenantImages
{
    public const string DISK = 'public';

    /**
     * A stored file's name, unanchored as a route's parameter pattern takes it: the characters
     * the store writes, not beginning with a dot. The three routes naming a file read it
     * (routes/tenant.php) and isOwn() anchors it, so the routes and the store agree on one
     * spelling.
     */
    public const string FILE_NAME = '[A-Za-z0-9_-][A-Za-z0-9._-]*';

    /** Where every tenant's directory sits on the disk, its slug following. */
    private const string DIRECTORY_PREFIX = 'tenants/';

    /** The three image types the uploads accept, as a mimes rule spells them. */
    public const string TYPES = 'png,jpg,jpeg,webp';

    /** The extension each type is stored under, keyed by the MIME type read from the bytes. */
    private const array EXTENSIONS = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];

    private ImageManager $images;

    public function __construct(private Disks $disks)
    {
        $this->images = new ImageManager(new Driver);
    }

    /**
     * Stores the file as its kind, scaled down to the longest edge given or, given none, to the
     * kind's own, and answers the stored path.
     *
     * @throws ImageRefused when the bytes are not one of the three types or do not decode
     */
    public function store(Tenant $tenant, ImageKind $kind, File $file, ?int $longestEdge = null): string
    {
        $source = $file->getRealPath();

        if ($source === false) {
            throw new ImageRefused('The upload has no file behind it.');
        }

        $mime = MimeTypes::getDefault()->guessMimeType($source);
        $extension = self::EXTENSIONS[$mime ?? ''] ?? null;

        if ($mime === null || $extension === null) {
            throw new ImageRefused(sprintf('A %s image is a PNG, a JPEG or a WebP, and these bytes read as %s.', $kind->value, $mime ?? 'nothing known'));
        }

        try {
            $image = $this->images->decodePath($source);
            $edge = $longestEdge ?? $kind->longestEdge();

            if ($edge !== null) {
                $image->scaleDown($edge, $edge);
            }

            $bytes = (string) $image->encodeUsingMediaType($mime);
        } catch (ImageException $e) {
            throw new ImageRefused(sprintf('The %s image reads as %s and could not be decoded.', $kind->value, $mime), previous: $e);
        }

        $directory = self::directory($tenant);
        $path = sprintf('%s/%s-%s.%s', $directory, $kind->value, substr(hash('xxh128', $bytes), 0, 16), $extension);

        if (! $this->disk()->put($path, $bytes)) {
            throw new RuntimeException(sprintf('The %s image could not be written at %s.', $kind->value, $path));
        }

        return $path;
    }

    /**
     * Deletes a stored file of the tenant's. One already gone is not an error: nothing names it
     * any more.
     *
     * @throws ImagePathRefused when the path is not a file of the tenant's own directory
     */
    public function delete(Tenant $tenant, string $path): void
    {
        $path = self::own($tenant, $path);
        $this->disk()->delete($path);
    }

    /**
     * Deletes every image a tenant stored, its whole directory, named by the slug the tenant held
     * when its images were stored: the drop's, after its commit, which reads the slug before its
     * statement nulls it. A directory already gone, or never made, is not an error. Answers
     * whether the disk deleted it; bounded to one tenant's directory by the slug, which a valid
     * Slug spells and nothing else can.
     */
    public function deleteAll(Slug $slug): bool
    {
        return $this->disk()->deleteDirectory(self::DIRECTORY_PREFIX.$slug->value);
    }

    /**
     * The bytes and the MIME type of the tenant's image at a stored path, or null when the file is
     * not on the disk any more.
     *
     * @return array{contents: string, mime: string}|null
     *
     * @throws ImagePathRefused when the path is not a file of the tenant's own directory
     */
    public function read(Tenant $tenant, string $path): ?array
    {
        $path = self::own($tenant, $path);
        $disk = $this->disk();

        if (! $disk->exists($path)) {
            return null;
        }

        $contents = $disk->get($path);
        $mime = $disk->mimeType($path);

        return $contents === null || $mime === false ? null : ['contents' => $contents, 'mime' => $mime];
    }

    /** The file name the URL carries, the last segment of the stored path. */
    public static function fileOf(string $path): string
    {
        return basename($path);
    }

    /** The tenant's own directory on the disk, named by the slug, which is immutable. */
    public static function directory(Tenant $tenant): string
    {
        return self::DIRECTORY_PREFIX.$tenant->slug;
    }

    /**
     * Whether a path is a file of the tenant's own directory as the store writes one: the
     * directory, a slash and one file name of the characters the routes accept, not beginning
     * with a dot. A `..` segment, an absolute path, a backslash, which the disk would read as a
     * separator, and another tenant's directory are none of them.
     */
    public static function isOwn(Tenant $tenant, string $path): bool
    {
        $prefix = self::directory($tenant).'/';

        return str_starts_with($path, $prefix) && preg_match('/\A'.self::FILE_NAME.'\z/', substr($path, strlen($prefix))) === 1;
    }

    /** @throws ImagePathRefused when the path is not a file of the tenant's own directory */
    private static function own(Tenant $tenant, string $path): string
    {
        return self::isOwn($tenant, $path) ? $path : throw ImagePathRefused::outside($tenant, $path);
    }

    private function disk(): Filesystem
    {
        return $this->disks->disk(self::DISK);
    }
}
