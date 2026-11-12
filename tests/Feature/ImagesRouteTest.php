<?php

use App\Http\ServedImages;
use App\Infrastructure\Db\TextArray;
use App\Infrastructure\Storage\ImageKind;
use App\Infrastructure\Storage\ImageLookup;
use App\Infrastructure\Storage\TenantImages;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Psr\Log\LoggerInterface;
use Tests\Support\FakeDisks;
use Tests\Support\SeamLog;

pest()->use(RefreshDatabase::class);

/*
 * GET /images/{file} at the tenant origin: the cover, the gallery and the staff photo served
 * under a name a row of that tenant holds, found through the store's lookup, with the logo
 * route's year of cache, its ETag and its nosniff. The cover and the gallery are held by the
 * settings row's own columns and the staff photo by the staff rows' photo_path, whose readers
 * AppServiceProvider registers. The lookup's own contract is held on an instance of its own: a
 * kind registered with a reader of held names is served, any other name is not, only a path under
 * the tenant's own directory is ever served, and the logo stays the logo route's alone. One case
 * holds the three routes naming a file, this one, the logo's and the gallery's removal, to the
 * store's one file-name pattern, TenantImages::FILE_NAME, a name led by a dot answering 404 on
 * the two reads and 405 on the removal, which the admin's catch-all answers once no DELETE route
 * matches.
 */

beforeEach(function () {
    FakeDisks::fake(TenantImages::DISK);
    $this->luna = provisionTenant('luna')->tenant;
    $this->pier = provisionTenant('pier')->tenant;
});

/** A drawn PNG stored for the tenant as the kind, by the store; answers the stored path. */
function heldImage(Tenant $tenant, ImageKind $kind): string
{
    return app(TenantImages::class)->store($tenant, $kind, UploadedFile::fake()->image('photo.png', 60, 40));
}

/**
 * Holds, for each tenant, the paths listed under its slug as the kind, on the columns the
 * provider's readers read: the cover, the gallery and the map on the settings row, and each
 * staff photo on a member of its own.
 *
 * @param  array<string, list<string>>  $held
 */
function holdImages(ImageKind $kind, array $held): void
{
    foreach ($held as $slug => $paths) {
        if ($kind === ImageKind::StaffPhoto) {
            foreach ($paths as $index => $path) {
                DB::table('tenant_'.$slug.'.staff')->insert(['name' => 'Pictured '.$index, 'status' => 'active', 'photo_path' => $path]);
            }

            continue;
        }

        settingsOf($slug)->update(match ($kind) {
            ImageKind::Cover => ['cover_path' => $paths[0] ?? null],
            ImageKind::Map => ['map_path' => $paths[0] ?? null],
            default => ['gallery_paths' => TextArray::encode($paths)],
        });
    }
}

it('serves a name a registered kind holds for the tenant, with the logo route\'s year of cache, its ETag and nosniff, and a 304 on that ETag', function (ImageKind $kind) {
    $path = heldImage($this->luna, $kind);
    holdImages($kind, ['luna' => [$path]]);
    $url = tenantUrl('luna', '/images/'.basename($path));

    $served = $this->get($url)->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('Cache-Control', 'max-age='.ServedImages::MAX_AGE.', public')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    expect($served->getContent())->toBe(Storage::disk(TenantImages::DISK)->get($path))
        ->and($served->headers->get('ETag'))->toBe('"'.hash('xxh128', (string) $served->getContent()).'"');

    $this->get($url, ['If-None-Match' => (string) $served->headers->get('ETag')])->assertStatus(304);
})->with([
    'the cover' => [ImageKind::Cover],
    'a gallery photo' => [ImageKind::Gallery],
    'a staff photo' => [ImageKind::StaffPhoto],
    'the map' => [ImageKind::Map],
]);

it('holds the lookup\'s contract: a kind registered with a reader of held names is served, a name of a kind no reader is registered for is not', function () {
    $cover = heldImage($this->luna, ImageKind::Cover);
    $gallery = heldImage($this->luna, ImageKind::Gallery);
    holdImages(ImageKind::Cover, ['luna' => [$cover]]);
    $lookup = new ImageLookup;
    $lookup->register(ImageKind::Cover, fn (Tenant $tenant): array => $tenant->slug === 'luna' ? [$cover] : []);

    expect($lookup->find($this->luna, ImageKind::Cover, basename($cover)))->toBe($cover)
        ->and($lookup->find($this->luna, ImageKind::Gallery, basename($gallery)))->toBeNull()
        ->and($lookup->find($this->luna, ImageKind::Cover, basename($gallery)))->toBeNull();

    $this->get(tenantUrl('luna', '/images/'.basename($cover)))->assertOk();
    $this->get(tenantUrl('luna', '/images/'.basename($gallery)))->assertNotFound();
    Storage::disk(TenantImages::DISK)->assertExists($gallery);
});

it('refuses a second reader for a kind, so a kind is read one way, the provider\'s cover and gallery included', function () {
    $lookup = new ImageLookup;
    $lookup->register(ImageKind::Cover, fn (Tenant $tenant): array => []);

    expect(fn () => $lookup->register(ImageKind::Cover, fn (Tenant $tenant): array => []))->toThrow(LogicException::class)
        ->and(fn () => app(ImageLookup::class)->register(ImageKind::Cover, fn (Tenant $tenant): array => []))->toThrow(LogicException::class)
        ->and(fn () => app(ImageLookup::class)->register(ImageKind::Gallery, fn (Tenant $tenant): array => []))->toThrow(LogicException::class);
});

it('answers 404 for a name no row holds, for a held name asked under another tenant\'s origin, and for a name of no kind the store writes', function () {
    $cover = heldImage($this->luna, ImageKind::Cover);
    holdImages(ImageKind::Cover, ['luna' => [$cover]]);

    $this->get(tenantUrl('luna', '/images/cover-0000000000000000.png'))->assertNotFound();
    $this->get(tenantUrl('pier', '/images/'.basename($cover)))->assertNotFound();
    $this->get(tenantUrl('luna', '/images/'.str_replace('cover-', 'banner-', basename($cover))))->assertNotFound();
    $this->get(tenantUrl('luna', '/images/'.str_replace('cover-', '', basename($cover))))->assertNotFound();
});

it('reads the file name of the three routes naming one by the store\'s own pattern, spelled once, a name led by a dot answering 404 on the two reads and 405 on the gallery\'s removal', function () {
    $routes = app('router')->getRoutes();

    foreach (['logo', 'images', 'admin.settings.gallery.destroy'] as $name) {
        expect($routes->getByName($name)?->wheres)->toBe(['file' => TenantImages::FILE_NAME]);
    }

    expect(TenantImages::isOwn($this->luna, 'tenants/luna/cover-0123456789abcdef.png'))->toBeTrue()
        ->and(TenantImages::isOwn($this->luna, 'tenants/luna/.cover-0123456789abcdef.png'))->toBeFalse();
    $this->get(tenantUrl('luna', '/images/.cover-0123456789abcdef.png'))->assertNotFound();
    $this->get(tenantUrl('luna', '/logo/.logo-0123456789abcdef.png'))->assertNotFound();
    $this->json('DELETE', tenantUrl('luna', '/admin/settings/gallery/.gallery-0123456789abcdef.png'))->assertStatus(405);
});

it('answers 404 for the logo\'s own name, which the logo route alone serves', function () {
    $logo = heldImage($this->luna, ImageKind::Logo);
    settingsOf('luna')->update(['brand_logo_path' => $logo]);

    $this->get(tenantUrl('luna', '/logo/'.basename($logo)))->assertOk();
    $this->get(tenantUrl('luna', '/images/'.basename($logo)))->assertNotFound();
});

it('serves only a path under the tenant\'s own directory: a row holding another tenant\'s file, or a path climbing out of its own, is not served', function () {
    $photo = heldImage($this->luna, ImageKind::Gallery);
    $file = basename($photo);
    holdImages(ImageKind::Gallery, ['pier' => [$photo, 'tenants/pier/../luna/'.$file, '/'.$photo]]);

    $this->get(tenantUrl('pier', '/images/'.$file))->assertNotFound();
    expect(app(ImageLookup::class)->find($this->pier, ImageKind::Gallery, $file))->toBeNull();
});

it('answers 404 and says so in the log when a held name is not on the disk', function () {
    $log = new SeamLog;
    app()->instance(LoggerInterface::class, $log);
    holdImages(ImageKind::Gallery, ['luna' => ['tenants/luna/gallery-0123456789abcdef.png']]);

    $this->get(tenantUrl('luna', '/images/gallery-0123456789abcdef.png'))->assertNotFound();

    expect(array_column($log->errors(), 'message'))->toBe(['The gallery image of tenant "luna" is recorded at tenants/luna/gallery-0123456789abcdef.png and is not on the public disk.']);
});
