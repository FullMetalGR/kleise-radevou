<?php

use App\Infrastructure\Storage\ImageKind;
use App\Infrastructure\Storage\ImagePathRefused;
use App\Infrastructure\Storage\ImageRefused;
use App\Infrastructure\Storage\TenantImages;
use App\Tenancy\Tenant;
use Illuminate\Contracts\Filesystem\Factory as Disks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeDisks;
use Tests\Support\TemporaryFiles;

pest()->use(RefreshDatabase::class);

/*
 * The tenant image store: a file typed from its bytes, PNG, JPEG or WebP and nothing else,
 * scaled down to a longest edge, the caller's or the kind's own, and never enlarged, re-encoded
 * in its own format with no metadata kept, stored under the tenant's directory named by a hash
 * of the stored bytes, read and deleted there alone. The fixtures are drawn with GD as each
 * test runs, so no binary file is tracked; the EXIF one is a GD JPEG with an APP1 segment
 * written into it by hand, read back through ext-exif, which composer.json's require-dev and
 * the CI workflow declare.
 */

const EXIF_MARKER = 'KR-EXIF-MARKER';

function drawnImage(string $format, int $width, int $height): string
{
    $image = imagecreatetruecolor(max(1, $width), max(1, $height));
    imagefill($image, 0, 0, (int) imagecolorallocate($image, 200, 40, 90));
    imagefilledrectangle($image, 0, 0, intdiv($width, 2), intdiv($height, 2), (int) imagecolorallocate($image, 20, 120, 60));
    $path = TemporaryFiles::make('kr-image');

    match ($format) {
        'png' => imagepng($image, $path),
        'jpeg' => imagejpeg($image, $path),
        'webp' => imagewebp($image, $path),
        'gif' => imagegif($image, $path),
        default => throw new LogicException(sprintf('No fixture is drawn as %s.', $format)),
    };

    return $path;
}

/** A GD JPEG carrying an EXIF Artist tag in an APP1 segment written straight after the SOI. */
function exifImage(int $width, int $height): string
{
    $path = drawnImage('jpeg', $width, $height);
    $value = EXIF_MARKER."\0";
    $tiff = "II*\0".pack('V', 8).pack('v', 1).pack('vvVV', 0x013B, 2, strlen($value), 26).pack('V', 0).$value;
    $app1 = "Exif\0\0".$tiff;
    $bytes = (string) file_get_contents($path);
    file_put_contents($path, substr($bytes, 0, 2)."\xFF\xE1".pack('n', strlen($app1) + 2).$app1.substr($bytes, 2));

    return $path;
}

function imageUpload(string $path, string $name): UploadedFile
{
    return new UploadedFile($path, $name, null, null, true);
}

function imageTenant(string $slug = 'luna'): Tenant
{
    return new Tenant(['slug' => $slug, 'name' => ucfirst($slug), 'timezone' => 'Europe/Athens', 'currency' => 'EUR']);
}

/** @return array{0: int, 1: int, mime: string} */
function storedSize(string $path): array
{
    $size = getimagesizefromstring((string) Storage::disk(TenantImages::DISK)->get($path));

    if ($size === false) {
        throw new LogicException(sprintf('The stored file at %s is not an image.', $path));
    }

    return ['0' => $size[0], '1' => $size[1], 'mime' => $size['mime']];
}

beforeEach(function () {
    FakeDisks::fake(TenantImages::DISK);
});

it('stores each of the three types under the tenant directory, re-encoded in its own format and named by the hash of the stored bytes', function (string $format, string $mime, string $extension) {
    $path = app(TenantImages::class)->store(imageTenant(), ImageKind::Cover, imageUpload(drawnImage($format, 300, 200), 'photo.bin'));

    expect($path)->toMatch('/\Atenants\/luna\/cover-[0-9a-f]{16}\.'.$extension.'\z/');

    $stored = (string) Storage::disk(TenantImages::DISK)->get($path);

    expect(basename($path))->toBe('cover-'.substr(hash('xxh128', $stored), 0, 16).'.'.$extension)
        ->and(storedSize($path))->toBe(['0' => 300, '1' => 200, 'mime' => $mime])
        ->and(app(TenantImages::class)->read(imageTenant(), $path))->toBe(['contents' => $stored, 'mime' => $mime]);
})->with([
    'a PNG' => ['png', 'image/png', 'png'],
    'a JPEG' => ['jpeg', 'image/jpeg', 'jpg'],
    'a WebP' => ['webp', 'image/webp', 'webp'],
]);

it('names the file by the kind it stores', function (ImageKind $kind, string $prefix) {
    $path = app(TenantImages::class)->store(imageTenant('pier'), $kind, imageUpload(drawnImage('png', 40, 40), 'x.png'));

    expect($path)->toMatch('/\Atenants\/pier\/'.$prefix.'-[0-9a-f]{16}\.png\z/');
})->with([
    'the logo' => [ImageKind::Logo, 'logo'],
    'the cover' => [ImageKind::Cover, 'cover'],
    'a gallery photo' => [ImageKind::Gallery, 'gallery'],
    'a staff photo' => [ImageKind::StaffPhoto, 'staff'],
    'the map' => [ImageKind::Map, 'map'],
]);

it('scales down to the longest edge keeping the aspect ratio, and never enlarges', function (ImageKind $kind, int $width, int $height, ?int $edge, int $storedWidth, int $storedHeight) {
    $path = app(TenantImages::class)->store(imageTenant(), $kind, imageUpload(drawnImage('jpeg', $width, $height), 'photo.jpg'), $edge);

    expect(storedSize($path))->toBe(['0' => $storedWidth, '1' => $storedHeight, 'mime' => 'image/jpeg']);
})->with([
    'a landscape over the edge' => [ImageKind::Gallery, 2400, 1200, 1600, 1600, 800],
    'a portrait over the edge' => [ImageKind::Gallery, 1000, 2000, 400, 200, 400],
    'one smaller than the edge, kept as it is' => [ImageKind::Gallery, 300, 200, 1600, 300, 200],
    'no edge given, the cover at its kind\'s 1600' => [ImageKind::Cover, 2400, 1200, null, 1600, 800],
    'no edge given, a gallery photo at its kind\'s 1600' => [ImageKind::Gallery, 1200, 2400, null, 800, 1600],
    'no edge given, a staff photo at its kind\'s 400' => [ImageKind::StaffPhoto, 1000, 2000, null, 200, 400],
    'no edge given, the logo kept at its own size' => [ImageKind::Logo, 2400, 1200, null, 2400, 1200],
    'no edge given, the map kept at its own size' => [ImageKind::Map, 640, 320, null, 640, 320],
]);

it('binds the edges to the kind in one place: 1600 for the cover and the gallery, 400 for the staff photo, none for the logo or the map Google draws at 640 by 320', function () {
    expect(array_map(fn (ImageKind $kind): ?int => $kind->longestEdge(), ImageKind::cases()))->toBe([null, 1600, 1600, 400, null]);
});

it('strips the metadata: a JPEG carrying EXIF is stored without it', function () {
    $original = exifImage(640, 480);

    expect(exif_read_data($original))->toHaveKey('Artist', EXIF_MARKER);

    $path = app(TenantImages::class)->store(imageTenant(), ImageKind::StaffPhoto, imageUpload($original, 'me.jpg'), 400);
    $stored = (string) Storage::disk(TenantImages::DISK)->get($path);

    expect($stored)->not->toContain(EXIF_MARKER)
        ->and($stored)->not->toContain("Exif\0\0")
        ->and(storedSize($path))->toBe(['0' => 400, '1' => 300, 'mime' => 'image/jpeg']);
});

it('refuses anything but a PNG, a JPEG or a WebP by its bytes, whatever the name says, and stores nothing', function (string $path, string $name) {
    expect(fn () => app(TenantImages::class)->store(imageTenant(), ImageKind::Cover, imageUpload($path, $name)))->toThrow(ImageRefused::class);
    expect(Storage::disk(TenantImages::DISK)->allFiles())->toBe([]);
})->with([
    'a GIF named as a PNG' => fn () => [drawnImage('gif', 40, 40), 'photo.png'],
    'an SVG named as a PNG' => function () {
        $path = TemporaryFiles::make('kr-image');
        file_put_contents($path, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

        return [$path, 'photo.png'];
    },
    'a PNG signature over bytes no decoder reads' => function () {
        $path = TemporaryFiles::make('kr-image');
        file_put_contents($path, "\x89PNG\r\n\x1a\n".str_repeat("\0", 64));

        return [$path, 'photo.png'];
    },
]);

it('deletes a stored file when told to, and deleting one that is gone is not an error', function () {
    $images = app(TenantImages::class);
    $path = $images->store(imageTenant(), ImageKind::Cover, imageUpload(drawnImage('png', 40, 40), 'x.png'));

    $images->delete(imageTenant(), $path);
    Storage::disk(TenantImages::DISK)->assertMissing($path);
    $images->delete(imageTenant(), $path);

    expect($images->read(imageTenant(), $path))->toBeNull();
});

it('reads and deletes only a file of the tenant\'s own directory, refusing any other path before the disk is touched', function (string $path) {
    $images = new TenantImages(new class implements Disks
    {
        public function disk($name = null): never
        {
            throw new RuntimeException('The store reached for the disk.');
        }
    });

    expect(fn () => $images->read(imageTenant('luna'), $path))->toThrow(ImagePathRefused::class)
        ->and(fn () => $images->delete(imageTenant('luna'), $path))->toThrow(ImagePathRefused::class);
})->with([
    'a .. segment climbing into another tenant\'s directory' => ['tenants/luna/../pier/logo-0123456789abcdef.png'],
    'a .. segment as the file name' => ['tenants/luna/..'],
    'an absolute path' => ['/tenants/luna/logo-0123456789abcdef.png'],
    'another tenant\'s directory' => ['tenants/pier/logo-0123456789abcdef.png'],
    'a directory the slug is a prefix of' => ['tenants/lunar/logo-0123456789abcdef.png'],
    'a backslash, which the disk reads as a separator' => ['tenants/luna/..\\pier\\logo-0123456789abcdef.png'],
    'a path below the directory' => ['tenants/luna/deeper/logo-0123456789abcdef.png'],
]);

it('leaves another tenant\'s file on the disk when a delete names it, by a .. segment or by its own path', function () {
    $images = app(TenantImages::class);
    $pier = $images->store(imageTenant('pier'), ImageKind::Logo, imageUpload(drawnImage('png', 40, 40), 'x.png'));

    expect(fn () => $images->delete(imageTenant('luna'), 'tenants/luna/../pier/'.basename($pier)))->toThrow(ImagePathRefused::class)
        ->and(fn () => $images->delete(imageTenant('luna'), $pier))->toThrow(ImagePathRefused::class)
        ->and(fn () => $images->read(imageTenant('luna'), $pier))->toThrow(ImagePathRefused::class);

    Storage::disk(TenantImages::DISK)->assertExists($pier);
});
