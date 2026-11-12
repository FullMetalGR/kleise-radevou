<?php

use App\Http\Controllers\Admin\CoverController;
use App\Http\Controllers\Admin\GalleryController;
use App\Http\Controllers\Admin\LogoController;
use App\Http\Requests\CoverRequest;
use App\Http\Requests\GalleryPhotoRequest;
use App\Http\Requests\ImageUploadRequest;
use App\Http\Requests\LogoRequest;
use App\Http\Requests\StaffPhotoRequest;
use App\Infrastructure\Db\SettingsReader;
use App\Infrastructure\Db\SettingsRecords;
use App\Infrastructure\Db\TextArray;
use App\Infrastructure\Storage\ImageKind;
use App\Infrastructure\Storage\TenantImages;
use App\Models\User;
use App\Site\SiteLanguage;
use App\Tenancy\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FakeDisks;
use Tests\Support\LunaFixture;
use Tests\Support\TemporaryFiles;
use Tests\TestCase;

pest()->use(RefreshDatabase::class);

/*
 * The business page's cover and gallery: each upload and removal its own write under
 * manageSettings and the gate (pinned with the logo's in ApiSurfaceTest, FreezeWalkTest and
 * SettingsScreenTest), every file through the tenant image store at its kind's own edge, the row
 * naming a file only once it has landed and the file a row forgot deleted after it, and every
 * held name served at GET /images/{file}. One case holds the four image uploads to one shape of
 * type refusal and one home of the missing file's sentence, the logo's and the staff photo's
 * requests with the two here (their uploads are SettingsScreenTest's and StaffPhotoTest's). None
 * of these writes enters the transaction runner, so the suite's transaction wraps each test.
 */

beforeEach(function () {
    $this->withoutVite();
    seedDemo();
    FakeDisks::fake(TenantImages::DISK);
});

function pictureOwner(): User
{
    return User::query()->where('email', 'owner@luna.example')->sole();
}

/**
 * @param  array<string, mixed>  $body
 * @return TestResponse<Response>
 */
function pictureWrite(TestCase $test, string $method, string $path, array $body = []): TestResponse
{
    return $test->actingAs(pictureOwner())->from(tenantUrl('luna', '/admin/settings'))->json($method, tenantUrl('luna', '/admin'.$path), $body, ['X-Inertia' => 'true']);
}

/**
 * The pixel size of a stored image, read from its bytes on the disk.
 *
 * @return array{0: int, 1: int}
 */
function pictureSize(string $path): array
{
    $size = getimagesizefromstring((string) Storage::disk(TenantImages::DISK)->get($path));
    assert($size !== false);

    return [$size[0], $size[1]];
}

/** The gallery's capacity spelled in words by ICU in a locale, as the full refusal names it. */
function galleryCapacityIn(string $locale): string
{
    $spelled = (new NumberFormatter($locale, NumberFormatter::SPELLOUT))->format(Settings::GALLERY_CAPACITY);
    assert(is_string($spelled));

    return $spelled;
}

/** @return list<string> */
function galleryOf(): array
{
    $literal = settingsOf('luna')->selectRaw('gallery_paths::text as paths')->value('paths');
    assert(is_string($literal));

    return TextArray::decode($literal);
}

it('stores the cover through the store at the cover\'s own edge, serves it from the tenant\'s origin, and replaces it on the next upload, deleting the file it replaced', function () {
    pictureWrite($this, 'POST', '/settings/cover', ['cover' => UploadedFile::fake()->image('cover.png', 3200, 1200)])
        ->assertRedirect(tenantUrl('luna', '/admin/settings'))
        ->assertSessionHas('flash', ['kind' => 'success', 'message' => adminLine(CoverController::UPLOADED)]);

    $path = settingsOf('luna')->value('cover_path');
    expect($path)->toMatch('/\Atenants\/luna\/cover-[0-9a-f]{16}\.png\z/');
    assert(is_string($path));
    expect(pictureSize($path))->toBe([ImageKind::Cover->longestEdge(), 600])
        ->and(app(SettingsReader::class)->read(LunaFixture::tenant())->coverPath)->toBe($path);

    $url = tenantUrl('luna', '/images/'.basename($path));
    expect($this->get($url)->assertOk()->assertHeader('Content-Type', 'image/png')->getContent())->toBe(Storage::disk(TenantImages::DISK)->get($path));

    pictureWrite($this, 'POST', '/settings/cover', ['cover' => UploadedFile::fake()->image('other.jpg', 800, 600)->size(CoverRequest::MAX_KILOBYTES)])->assertRedirect(tenantUrl('luna', '/admin/settings'));
    $replaced = settingsOf('luna')->value('cover_path');
    assert(is_string($replaced));
    expect($replaced)->toMatch('/\Atenants\/luna\/cover-[0-9a-f]{16}\.jpg\z/')
        ->and(pictureSize($replaced))->toBe([800, 600]);
    Storage::disk(TenantImages::DISK)->assertMissing($path);
    $this->get($url)->assertNotFound();
    $this->get(tenantUrl('luna', '/images/'.basename($replaced)))->assertOk();
});

it('refuses a cover that is not one of the three types or is over eight megabytes with its sentence, and stores nothing', function (UploadedFile $file, string $sentence) {
    pictureWrite($this, 'POST', '/settings/cover', ['cover' => $file])->assertStatus(400)->assertJsonPath('data.detail', uploadLine($sentence));

    expect(settingsOf('luna')->value('cover_path'))->toBeNull()
        ->and(Storage::disk(TenantImages::DISK)->allFiles())->toBe([]);
})->with([
    'an SVG' => [UploadedFile::fake()->createWithContent('cover.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'), CoverRequest::TYPE],
    'a GIF' => [UploadedFile::fake()->image('cover.gif', 40, 40), CoverRequest::TYPE],
    'a kilobyte over eight megabytes' => [UploadedFile::fake()->image('cover.png', 40, 40)->size(CoverRequest::MAX_KILOBYTES + 1), CoverRequest::SIZE],
]);

it('refuses a cover whose bytes begin as a PNG and do not decode with the type sentence, and stores nothing', function () {
    $path = TemporaryFiles::make('kr-cover');
    file_put_contents($path, "\x89PNG\r\n\x1a\n".str_repeat("\0", 64));

    pictureWrite($this, 'POST', '/settings/cover', ['cover' => new UploadedFile($path, 'cover.png', 'image/png', null, true)])
        ->assertStatus(400)->assertJsonPath('data.detail', uploadLine(CoverRequest::TYPE));

    expect(Storage::disk(TenantImages::DISK)->allFiles())->toBe([]);
});

it('refuses an upload naming no file on both routes', function () {
    pictureWrite($this, 'POST', '/settings/cover')->assertStatus(400)->assertJsonPath('data.detail', uploadLine(CoverRequest::MISSING));
    pictureWrite($this, 'POST', '/settings/gallery')->assertStatus(400)->assertJsonPath('data.detail', uploadLine(GalleryPhotoRequest::MISSING));
});

it('words the four image uploads\' type refusals in one shape, each spelled whole once in lang/en and nowhere under app/, and the missing file\'s sentence once, named on the requests\' base', function () {
    $requests = [LogoRequest::class, CoverRequest::class, GalleryPhotoRequest::class, StaffPhotoRequest::class];
    $keys = [LogoRequest::TYPE, CoverRequest::TYPE, GalleryPhotoRequest::TYPE, StaffPhotoRequest::TYPE];
    $types = array_map(fn (string $key): string => adminLine($key), $keys);
    $file = (string) file_get_contents(lang_path('en/admin/shell.php'));
    $source = implode("\n", array_map(fn (SplFileInfo $file): string => (string) file_get_contents($file->getPathname()), File::allFiles(app_path())));
    $sentences = [...$types, adminLine(ImageUploadRequest::MISSING)];

    // One shape: the four end on one run of whole words, the longest they share, and what comes
    // before it is each upload's own name, the name its size sentence opens with; every line read
    // through the translator, none spelled here.
    $tail = array_reduce(array_slice($types, 1), function (string $tail, string $type): string {
        while (! str_ends_with($type, $tail)) {
            $tail = substr($tail, 1);
        }

        return $tail;
    }, $types[0]);
    $tail = str_starts_with($tail, ' ') ? $tail : (string) strstr($tail, ' ');
    $names = array_map(fn (string $type): string => substr($type, 0, strlen($type) - strlen($tail)), $types);
    $sizes = array_map(fn (string $key): string => uploadLine($key), [LogoRequest::SIZE, CoverRequest::SIZE, GalleryPhotoRequest::SIZE, StaffPhotoRequest::SIZE]);

    expect($tail)->not->toBe('')
        ->and(array_unique($names))->toHaveCount(4)
        ->and(array_map(fn (string $name, string $size): bool => $name !== '' && str_starts_with($size, $name.' '), $names, $sizes))->toBe(array_fill(0, 4, true))
        ->and(array_map(fn (string $sentence): int => substr_count($file, "'".$sentence."'"), $sentences))->toBe([1, 1, 1, 1, 1])
        ->and(array_map(fn (string $sentence): int => substr_count($source, $sentence), $sentences))->toBe([0, 0, 0, 0, 0])
        ->and(array_map(fn (string $request): string => (new ReflectionClassConstant($request, 'MISSING'))->getDeclaringClass()->getName(), $requests))->toBe(array_fill(0, 4, ImageUploadRequest::class));
});

it('reads each size as a choice by its megabytes, the figure spelled in words in the language the line is read in, Greek for a user at el, the logo\'s one megabyte read through the choice\'s first case and the cover\'s eight through its second', function () {
    expect(uploadLine(LogoRequest::SIZE))->toBe(adminChoice(LogoRequest::SIZE, 1, ['n' => 'one']))
        ->and(uploadLine(CoverRequest::SIZE))->toBe(adminChoice(CoverRequest::SIZE, 8, ['n' => 'eight']))
        ->and(adminChoice(LogoRequest::SIZE, 1, ['n' => 'one']))->not->toBe(adminChoice(LogoRequest::SIZE, 2, ['n' => 'one']));

    // The Greek line's two forms as the file holds them, each with its condition taken off: the
    // logo's one megabyte reads the first and the cover's eight the second.
    $forms = fn (string $key): array => array_map(fn (string $form): string => trim((string) preg_replace('/^\s*[{\[][^}\]]*[}\]]/', '', $form)), explode('|', adminLine($key, [], SiteLanguage::Greek)));
    $spelled = fn (int $figure): string => (string) (new NumberFormatter(SiteLanguage::Greek->value, NumberFormatter::SPELLOUT))->format($figure);

    expect($forms(LogoRequest::SIZE))->toHaveCount(2)
        ->and(uploadLine(LogoRequest::SIZE, SiteLanguage::Greek))->toBe(strtr($forms(LogoRequest::SIZE)[0], [':n' => $spelled(1)]))
        ->and(uploadLine(CoverRequest::SIZE, SiteLanguage::Greek))->toBe(strtr($forms(CoverRequest::SIZE)[1], [':n' => $spelled(8)]))
        ->and(uploadLine(CoverRequest::SIZE, SiteLanguage::Greek))->not->toBe(uploadLine(CoverRequest::SIZE));

    User::query()->where('email', 'owner@luna.example')->update(['locale' => SiteLanguage::Greek->value]);

    pictureWrite($this, 'POST', '/settings/logo', ['logo' => UploadedFile::fake()->image('logo.png', 40, 40)->size(LogoRequest::MAX_KILOBYTES + 1)])
        ->assertStatus(400)->assertJsonPath('data.detail', uploadLine(LogoRequest::SIZE, SiteLanguage::Greek));
    pictureWrite($this, 'POST', '/settings/cover', ['cover' => UploadedFile::fake()->image('cover.png', 40, 40)->size(CoverRequest::MAX_KILOBYTES + 1)])
        ->assertStatus(400)->assertJsonPath('data.detail', uploadLine(CoverRequest::SIZE, SiteLanguage::Greek));
});

it('removes the cover: the path cleared, the file gone, its URL 404, and a second removal a no-op', function () {
    pictureWrite($this, 'POST', '/settings/cover', ['cover' => UploadedFile::fake()->image('cover.png', 120, 40)])->assertRedirect();
    $path = settingsOf('luna')->value('cover_path');
    assert(is_string($path));

    pictureWrite($this, 'DELETE', '/settings/cover')
        ->assertRedirect(tenantUrl('luna', '/admin/settings'))
        ->assertSessionHas('flash', ['kind' => 'success', 'message' => adminLine(CoverController::REMOVED)]);

    expect(settingsOf('luna')->value('cover_path'))->toBeNull();
    Storage::disk(TenantImages::DISK)->assertMissing($path);
    $this->get(tenantUrl('luna', '/images/'.basename($path)))->assertNotFound();

    pictureWrite($this, 'DELETE', '/settings/cover')->assertRedirect(tenantUrl('luna', '/admin/settings'));
    expect(settingsOf('luna')->value('cover_path'))->toBeNull();
});

it('appends gallery photos in the order added, each at the gallery\'s own edge and served from the tenant\'s origin, an unheld name 404', function () {
    foreach ([[2400, 1800], [300, 200], [500, 900]] as [$width, $height]) {
        pictureWrite($this, 'POST', '/settings/gallery', ['photo' => UploadedFile::fake()->image('photo.png', $width, $height)])
            ->assertRedirect(tenantUrl('luna', '/admin/settings'))
            ->assertSessionHas('flash', ['kind' => 'success', 'message' => adminLine(GalleryController::ADDED)]);
    }

    $held = galleryOf();
    expect($held)->toHaveCount(3)
        ->and(array_map(pictureSize(...), $held))->toBe([[ImageKind::Gallery->longestEdge(), 1200], [300, 200], [500, 900]])
        ->and(app(SettingsReader::class)->read(LunaFixture::tenant())->galleryPaths)->toBe($held);

    foreach ($held as $path) {
        expect($path)->toMatch('/\Atenants\/luna\/gallery-[0-9a-f]{16}\.png\z/');
        $this->get(tenantUrl('luna', '/images/'.basename($path)))->assertOk()->assertHeader('Content-Type', 'image/png');
    }

    $this->get(tenantUrl('luna', '/images/gallery-0000000000000000.png'))->assertNotFound();
    $this->get(tenantUrl('pier', '/images/'.basename($held[0])))->assertNotFound();
});

it('refuses a ninth photo with the gallery\'s sentence in the envelope\'s 400 and stores nothing', function () {
    $images = app(TenantImages::class);
    $eight = array_map(fn (int $at): string => $images->store(LunaFixture::tenant(), ImageKind::Gallery, UploadedFile::fake()->image('photo.png', 10 + $at, 10)), range(1, Settings::GALLERY_CAPACITY));
    settingsOf('luna')->update(['gallery_paths' => TextArray::encode($eight)]);

    pictureWrite($this, 'POST', '/settings/gallery', ['photo' => UploadedFile::fake()->image('ninth.png', 40, 40)])
        ->assertStatus(400)->assertJsonPath('data.detail', adminLine(GalleryController::FULL, ['capacity' => galleryCapacityIn('en')]));

    expect(galleryOf())->toBe($eight)
        ->and(Storage::disk(TenantImages::DISK)->allFiles())->toHaveCount(Settings::GALLERY_CAPACITY);
});

it('re-counts the gallery under the row\'s lock, so an append that lost the last place writes nothing and answers the gallery it found', function () {
    $full = array_map(fn (int $at): string => sprintf('tenants/luna/gallery-%016d.png', $at), range(1, Settings::GALLERY_CAPACITY));
    settingsOf('luna')->update(['gallery_paths' => TextArray::encode($full)]);

    expect(app(SettingsRecords::class)->addGalleryPath(LunaFixture::tenant(), 'tenants/luna/gallery-ffffffffffffffff.png'))->toBe($full)
        ->and(galleryOf())->toBe($full);
});

it('refuses the same photo twice, keeping the one it holds and its file', function () {
    $file = UploadedFile::fake()->image('photo.png', 60, 40);
    pictureWrite($this, 'POST', '/settings/gallery', ['photo' => $file])->assertRedirect();
    $held = galleryOf();

    pictureWrite($this, 'POST', '/settings/gallery', ['photo' => $file])->assertStatus(400)->assertJsonPath('data.detail', adminLine(GalleryController::ALREADY_HELD));

    expect(galleryOf())->toBe($held);
    Storage::disk(TenantImages::DISK)->assertExists($held[0]);
});

it('refuses a gallery photo that is not one of the three types or is over eight megabytes with its sentence, and stores nothing', function (UploadedFile $file, string $sentence) {
    pictureWrite($this, 'POST', '/settings/gallery', ['photo' => $file])->assertStatus(400)->assertJsonPath('data.detail', uploadLine($sentence));

    expect(galleryOf())->toBe([])
        ->and(Storage::disk(TenantImages::DISK)->allFiles())->toBe([]);
})->with([
    'an SVG' => [UploadedFile::fake()->createWithContent('photo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'), GalleryPhotoRequest::TYPE],
    'a GIF' => [UploadedFile::fake()->image('photo.gif', 40, 40), GalleryPhotoRequest::TYPE],
    'a kilobyte over eight megabytes' => [UploadedFile::fake()->image('photo.png', 40, 40)->size(GalleryPhotoRequest::MAX_KILOBYTES + 1), GalleryPhotoRequest::SIZE],
]);

it('words the cover\'s and the gallery\'s refusals in Greek for a user at el: no file named, a cover and a gallery photo of no allowed type, and a gallery photo over its bound, and stores nothing', function () {
    User::query()->where('email', 'owner@luna.example')->update(['locale' => SiteLanguage::Greek->value]);
    $keys = [GalleryPhotoRequest::MISSING, CoverRequest::TYPE, GalleryPhotoRequest::TYPE, GalleryPhotoRequest::SIZE];

    pictureWrite($this, 'POST', '/settings/gallery')
        ->assertStatus(400)->assertJsonPath('data.detail', uploadLine($keys[0], SiteLanguage::Greek));
    pictureWrite($this, 'POST', '/settings/cover', ['cover' => UploadedFile::fake()->image('cover.gif', 40, 40)])
        ->assertStatus(400)->assertJsonPath('data.detail', uploadLine($keys[1], SiteLanguage::Greek));
    pictureWrite($this, 'POST', '/settings/gallery', ['photo' => UploadedFile::fake()->image('photo.gif', 40, 40)])
        ->assertStatus(400)->assertJsonPath('data.detail', uploadLine($keys[2], SiteLanguage::Greek));
    pictureWrite($this, 'POST', '/settings/gallery', ['photo' => UploadedFile::fake()->image('photo.png', 40, 40)->size(GalleryPhotoRequest::MAX_KILOBYTES + 1)])
        ->assertStatus(400)->assertJsonPath('data.detail', uploadLine($keys[3], SiteLanguage::Greek));

    expect(array_map(fn (string $key): bool => uploadLine($key, SiteLanguage::Greek) !== uploadLine($key), $keys))->toBe([true, true, true, true])
        ->and(galleryOf())->toBe([])
        ->and(Storage::disk(TenantImages::DISK)->allFiles())->toBe([]);
});

it('removes a held photo by its name, deleting its file and keeping the others in order, and answers 404 for a name the gallery does not hold', function () {
    foreach ([60, 70, 80] as $width) {
        pictureWrite($this, 'POST', '/settings/gallery', ['photo' => UploadedFile::fake()->image('photo.png', $width, 40)])->assertRedirect();
    }

    [$first, $second, $third] = galleryOf();

    pictureWrite($this, 'DELETE', '/settings/gallery/'.basename($second))
        ->assertRedirect(tenantUrl('luna', '/admin/settings'))
        ->assertSessionHas('flash', ['kind' => 'success', 'message' => adminLine(GalleryController::REMOVED)]);

    expect(galleryOf())->toBe([$first, $third]);
    Storage::disk(TenantImages::DISK)->assertMissing($second);
    $this->get(tenantUrl('luna', '/images/'.basename($second)))->assertNotFound();

    pictureWrite($this, 'DELETE', '/settings/gallery/'.basename($second))->assertNotFound();
    pictureWrite($this, 'DELETE', '/settings/gallery/gallery-0000000000000000.png')->assertNotFound();
    pictureWrite($this, 'POST', '/settings/cover', ['cover' => UploadedFile::fake()->image('cover.png', 60, 40)])->assertRedirect();
    $cover = settingsOf('luna')->value('cover_path');
    assert(is_string($cover));
    pictureWrite($this, 'DELETE', '/settings/gallery/'.basename($cover))->assertNotFound();

    expect(galleryOf())->toBe([$first, $third]);
    Storage::disk(TenantImages::DISK)->assertExists($first);
    Storage::disk(TenantImages::DISK)->assertExists($cover);
});

it('words the image writes\' six flashes and two refusals in the user\'s language, Greek for a user at el and English for a colleague at en, the full gallery\'s capacity spelled in it', function (SiteLanguage $language) {
    User::query()->where('email', 'owner@luna.example')->update(['locale' => $language->value]);
    $flash = fn (string $key): array => ['kind' => 'success', 'message' => adminLine($key, [], $language)];

    pictureWrite($this, 'POST', '/settings/logo', ['logo' => UploadedFile::fake()->image('logo.png', 120, 40)])->assertSessionHas('flash', $flash(LogoController::UPLOADED));
    pictureWrite($this, 'DELETE', '/settings/logo')->assertSessionHas('flash', $flash(LogoController::REMOVED));
    pictureWrite($this, 'POST', '/settings/cover', ['cover' => UploadedFile::fake()->image('cover.png', 1600, 600)])->assertSessionHas('flash', $flash(CoverController::UPLOADED));
    pictureWrite($this, 'DELETE', '/settings/cover')->assertSessionHas('flash', $flash(CoverController::REMOVED));
    $file = UploadedFile::fake()->image('photo.png', 60, 40);
    pictureWrite($this, 'POST', '/settings/gallery', ['photo' => $file])->assertSessionHas('flash', $flash(GalleryController::ADDED));
    pictureWrite($this, 'POST', '/settings/gallery', ['photo' => $file])->assertStatus(400)->assertJsonPath('data.detail', adminLine(GalleryController::ALREADY_HELD, [], $language));
    pictureWrite($this, 'DELETE', '/settings/gallery/'.basename(galleryOf()[0]))->assertSessionHas('flash', $flash(GalleryController::REMOVED));

    $images = app(TenantImages::class);
    settingsOf('luna')->update(['gallery_paths' => TextArray::encode(array_map(fn (int $at): string => $images->store(LunaFixture::tenant(), ImageKind::Gallery, UploadedFile::fake()->image('photo.png', 10 + $at, 10)), range(1, Settings::GALLERY_CAPACITY)))]);
    pictureWrite($this, 'POST', '/settings/gallery', ['photo' => UploadedFile::fake()->image('ninth.png', 40, 40)])
        ->assertStatus(400)->assertJsonPath('data.detail', adminLine(GalleryController::FULL, ['capacity' => galleryCapacityIn($language->value)], $language));

    $keys = [LogoController::UPLOADED, LogoController::REMOVED, CoverController::UPLOADED, CoverController::REMOVED, GalleryController::ADDED, GalleryController::REMOVED, GalleryController::ALREADY_HELD];

    foreach ($keys as $key) {
        expect(adminLine($key, [], SiteLanguage::Greek))->not->toBe(adminLine($key, [], SiteLanguage::English), $key);
    }

    expect(adminLine(GalleryController::FULL, ['capacity' => galleryCapacityIn('el')], SiteLanguage::Greek))
        ->not->toBe(adminLine(GalleryController::FULL, ['capacity' => galleryCapacityIn('en')], SiteLanguage::English));

    // The seven and the full gallery's refusal are every key of the file's images group.
    $group = __('admin/settings.images', [], 'en');
    assert(is_array($group));
    $leaves = array_merge(...array_map(function (int|string $sub, mixed $lines): array {
        assert(is_array($lines));

        return array_map(fn (int|string $key): string => 'admin/settings.images.'.$sub.'.'.$key, array_keys($lines));
    }, array_keys($group), $group));
    expect(collect([...$keys, GalleryController::FULL])->sort()->values()->all())->toBe(collect($leaves)->sort()->values()->all());
})->with(['a user at el' => [SiteLanguage::Greek], 'a user at en' => [SiteLanguage::English]]);
