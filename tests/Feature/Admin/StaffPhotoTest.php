<?php

use App\Billing\EntitlementResolver;
use App\Billing\Entitlements;
use App\Http\Controllers\Admin\StaffPhotoController;
use App\Http\Requests\StaffPhotoRequest;
use App\Infrastructure\Storage\ImageKind;
use App\Infrastructure\Storage\TenantImages;
use App\Models\User;
use App\Site\SiteLanguage;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FakeDisks;
use Tests\Support\LunaFixture;
use Tests\TestCase;

pest()->use(RefreshDatabase::class);

/*
 * A face and a line for each member: the title and the bio on the staff write, landing trimmed
 * and an empty one as null; and the photo on its own two routes under the staff writes'
 * permission, gate and feature (pinned with the other staff writes in ApiSurfaceTest,
 * FreezeWalkTest, StaffScreenTest and StoredEntitlementsTest), through the tenant image store
 * at the staff photo's own edge, the row naming a file only once it has landed, the file no row
 * names any longer deleted after it, and every held name served at GET /images/{file}. None of
 * these writes enters the transaction runner, so the suite's transaction wraps each test.
 */

beforeEach(function () {
    $this->withoutVite();
    seedDemo();
    FakeDisks::fake(TenantImages::DISK);
});

/**
 * @param  array<string, mixed>  $body
 * @return TestResponse<Response>
 */
function faceWrite(TestCase $test, string $method, string $path, array $body = []): TestResponse
{
    $owner = User::query()->where('email', 'owner@luna.example')->sole();

    return $test->actingAs($owner)->from(tenantUrl('luna', '/admin/staff'))->json($method, tenantUrl('luna', '/admin'.$path), $body, ['X-Inertia' => 'true']);
}

/**
 * The pixel size of a stored image, read from its bytes on the disk.
 *
 * @return array{0: int, 1: int}
 */
function faceSize(string $path): array
{
    $size = getimagesizefromstring((string) Storage::disk(TenantImages::DISK)->get($path));
    assert($size !== false);

    return [$size[0], $size[1]];
}

function faceOf(int $id): ?string
{
    $path = DB::table('tenant_luna.staff')->where('id', $id)->value('photo_path');

    return is_string($path) ? $path : null;
}

it('writes the title and the bio on create and on edit, trimmed, an empty one landing as null, and hands them to the screen', function () {
    $elena = LunaFixture::id('staff', 'Elena Papadaki');

    faceWrite($this, 'POST', '/staff', ['name' => 'Maria Ioannou', 'title' => '  Senior stylist ', 'bio' => str_repeat('b', 500)])->assertRedirect();
    faceWrite($this, 'PUT', '/staff/'.$elena, ['title' => 'Colourist', 'bio' => 'Twelve years of balayage.'])->assertRedirect(tenantUrl('luna', '/admin/staff'));

    expect(DB::table('tenant_luna.staff')->where('name', 'Maria Ioannou')->first(['title', 'bio']))->toEqual((object) ['title' => 'Senior stylist', 'bio' => str_repeat('b', 500)])
        ->and(DB::table('tenant_luna.staff')->where('id', $elena)->first(['title', 'bio']))->toEqual((object) ['title' => 'Colourist', 'bio' => 'Twelve years of balayage.']);

    $this->actingAs(User::query()->where('email', 'owner@luna.example')->sole())->get(tenantUrl('luna', '/admin/staff'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('staff.1', fn (Collection $row): bool => $row['id'] === $elena && $row['title'] === 'Colourist' && $row['bio'] === 'Twelve years of balayage.' && $row['photo'] === null));

    faceWrite($this, 'PUT', '/staff/'.$elena, ['title' => '', 'bio' => '   '])->assertRedirect(tenantUrl('luna', '/admin/staff'));

    expect(DB::table('tenant_luna.staff')->where('id', $elena)->first(['title', 'bio']))->toEqual((object) ['title' => null, 'bio' => null]);
});

it('ignores the title and the bio on the implicit member, who stands for the business and shows neither, leaving a title and a bio stored before as they were, and writes the rest of the save', function () {
    $implicit = implicitStaffId('tenant_luna');
    DB::table('tenant_luna.staff')->where('id', $implicit)->update(['title' => 'Owner', 'bio' => 'Stored before the rule.']);

    faceWrite($this, 'PUT', '/staff/'.$implicit, ['name' => 'Luna Hair & Beauty', 'online' => false, 'title' => 'Senior stylist', 'bio' => 'Ten years at the chair.'])->assertRedirect(tenantUrl('luna', '/admin/staff'));
    faceWrite($this, 'PUT', '/staff/'.$implicit, ['title' => '', 'bio' => null])->assertRedirect(tenantUrl('luna', '/admin/staff'));

    expect(DB::table('tenant_luna.staff')->where('id', $implicit)->first(['name', 'online', 'title', 'bio']))->toEqual((object) ['name' => 'Luna Hair & Beauty', 'online' => false, 'title' => 'Owner', 'bio' => 'Stored before the rule.']);
});

it('stores the photo through the store at the staff photo\'s own edge, serves it, hands it to the screen, and replaces it on the next upload, deleting the file it replaced', function () {
    $elena = LunaFixture::id('staff', 'Elena Papadaki');

    faceWrite($this, 'POST', '/staff/'.$elena.'/photo', ['photo' => UploadedFile::fake()->image('elena.png', 1200, 1600)])
        ->assertRedirect(tenantUrl('luna', '/admin/staff'))
        ->assertSessionHas('flash', ['kind' => 'success', 'message' => adminLine(StaffPhotoController::UPLOADED)]);

    $path = faceOf($elena);
    expect($path)->toMatch('/\Atenants\/luna\/staff-[0-9a-f]{16}\.png\z/');
    assert(is_string($path));
    expect(faceSize($path))->toBe([300, ImageKind::StaffPhoto->longestEdge()]);

    $url = tenantUrl('luna', '/images/'.basename($path));
    expect($this->get($url)->assertOk()->assertHeader('Content-Type', 'image/png')->getContent())->toBe(Storage::disk(TenantImages::DISK)->get($path));
    $this->actingAs(User::query()->where('email', 'owner@luna.example')->sole())->get(tenantUrl('luna', '/admin/staff/'.$elena))
        ->assertInertia(fn (Assert $page) => $page->where('staff.1.photo', ['name' => basename($path), 'url' => $url]));

    faceWrite($this, 'POST', '/staff/'.$elena.'/photo', ['photo' => UploadedFile::fake()->image('other.jpg', 200, 100)->size(StaffPhotoRequest::MAX_KILOBYTES)])->assertRedirect();
    $replaced = faceOf($elena);
    assert(is_string($replaced));
    expect($replaced)->toMatch('/\Atenants\/luna\/staff-[0-9a-f]{16}\.jpg\z/')
        ->and(faceSize($replaced))->toBe([200, 100]);
    Storage::disk(TenantImages::DISK)->assertMissing($path);
    $this->get($url)->assertNotFound();
});

it('words the upload\'s flash in the user\'s language', function (SiteLanguage $language) {
    User::query()->where('email', 'owner@luna.example')->update(['locale' => $language->value]);

    faceWrite($this, 'POST', '/staff/'.LunaFixture::id('staff', 'Elena Papadaki').'/photo', ['photo' => UploadedFile::fake()->image('elena.png', 400, 400)])
        ->assertRedirect(tenantUrl('luna', '/admin/staff'))
        ->assertSessionHas('flash', ['kind' => 'success', 'message' => adminLine(StaffPhotoController::UPLOADED, [], $language)]);
})->with(['a user at en' => [SiteLanguage::English], 'a user at el' => [SiteLanguage::Greek]]);

it('removes the photo and its file, a second removal a no-op, and keeps a file another member still holds', function () {
    $elena = LunaFixture::id('staff', 'Elena Papadaki');
    $nikos = LunaFixture::id('staff', 'Nikos Georgiou');
    $same = UploadedFile::fake()->image('same.png', 80, 80);

    faceWrite($this, 'POST', '/staff/'.$elena.'/photo', ['photo' => $same])->assertRedirect();
    faceWrite($this, 'POST', '/staff/'.$nikos.'/photo', ['photo' => $same])->assertRedirect();
    $shared = faceOf($elena);
    assert(is_string($shared));
    expect(faceOf($nikos))->toBe($shared);

    faceWrite($this, 'DELETE', '/staff/'.$elena.'/photo')->assertRedirect()->assertSessionHas('flash', ['kind' => 'success', 'message' => adminLine(StaffPhotoController::REMOVED)]);
    expect(faceOf($elena))->toBeNull();
    Storage::disk(TenantImages::DISK)->assertExists($shared);
    $this->get(tenantUrl('luna', '/images/'.basename($shared)))->assertOk();

    faceWrite($this, 'DELETE', '/staff/'.$nikos.'/photo')->assertRedirect();
    faceWrite($this, 'DELETE', '/staff/'.$nikos.'/photo')->assertRedirect();
    expect(faceOf($nikos))->toBeNull()
        ->and(Storage::disk(TenantImages::DISK)->allFiles())->toBe([]);
});

it('deletes a member\'s photo with the member', function () {
    $id = DB::table('tenant_luna.staff')->insertGetId(['name' => 'Short stay', 'status' => 'active']);
    faceWrite($this, 'POST', '/staff/'.$id.'/photo', ['photo' => UploadedFile::fake()->image('stay.png', 50, 50)])->assertRedirect();
    $path = faceOf($id);
    assert(is_string($path));

    faceWrite($this, 'DELETE', '/staff/'.$id)->assertRedirect();

    Storage::disk(TenantImages::DISK)->assertMissing($path);
});

it('answers not-found on both routes for an unknown id and for the implicit member, storing nothing', function (string $method) {
    foreach ([999999, implicitStaffId('tenant_luna')] as $id) {
        faceWrite($this, $method, '/staff/'.$id.'/photo', ['photo' => UploadedFile::fake()->image('x.png', 40, 40)])->assertNotFound();
    }

    expect(Storage::disk(TenantImages::DISK)->allFiles())->toBe([])
        ->and(DB::table('tenant_luna.staff')->whereNotNull('photo_path')->count())->toBe(0);
})->with(['POST', 'DELETE']);

it('answers not-found on both routes for a member without the staff feature, storing nothing', function (string $method) {
    app()->instance(EntitlementResolver::class, new class implements EntitlementResolver
    {
        public function resolve(Tenant $tenant): Entitlements
        {
            return Entitlements::none();
        }
    });

    faceWrite($this, $method, '/staff/'.LunaFixture::id('staff', 'Elena Papadaki').'/photo', ['photo' => UploadedFile::fake()->image('x.png', 40, 40)])->assertNotFound();

    expect(Storage::disk(TenantImages::DISK)->allFiles())->toBe([]);
})->with(['POST', 'DELETE']);

it('refuses a photo that is missing, not one of the three types, over eight megabytes or not decodable with its sentence, and stores nothing', function (?UploadedFile $file, string $sentence) {
    faceWrite($this, 'POST', '/staff/'.LunaFixture::id('staff', 'Elena Papadaki').'/photo', $file === null ? [] : ['photo' => $file])
        ->assertStatus(400)->assertJsonPath('data.detail', uploadLine($sentence));

    expect(Storage::disk(TenantImages::DISK)->allFiles())->toBe([])
        ->and(DB::table('tenant_luna.staff')->whereNotNull('photo_path')->count())->toBe(0);
})->with([
    'no file' => [null, StaffPhotoRequest::MISSING],
    'an SVG' => [fn () => UploadedFile::fake()->createWithContent('x.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>'), StaffPhotoRequest::TYPE],
    'a GIF' => [fn () => UploadedFile::fake()->image('x.gif', 40, 40), StaffPhotoRequest::TYPE],
    'a kilobyte over eight megabytes' => [fn () => UploadedFile::fake()->image('x.png', 40, 40)->size(StaffPhotoRequest::MAX_KILOBYTES + 1), StaffPhotoRequest::SIZE],
    'PNG bytes that do not decode' => [fn () => UploadedFile::fake()->createWithContent('x.png', "\x89PNG\r\n\x1a\n".str_repeat("\0", 64)), StaffPhotoRequest::TYPE],
]);

it('words a staff photo\'s type and size refusals in Greek for a user at el, and stores nothing', function () {
    User::query()->where('email', 'owner@luna.example')->update(['locale' => SiteLanguage::Greek->value]);
    $path = '/staff/'.LunaFixture::id('staff', 'Elena Papadaki').'/photo';
    $keys = [StaffPhotoRequest::TYPE, StaffPhotoRequest::SIZE];

    faceWrite($this, 'POST', $path, ['photo' => UploadedFile::fake()->image('x.gif', 40, 40)])
        ->assertStatus(400)->assertJsonPath('data.detail', uploadLine($keys[0], SiteLanguage::Greek));
    faceWrite($this, 'POST', $path, ['photo' => UploadedFile::fake()->image('x.png', 40, 40)->size(StaffPhotoRequest::MAX_KILOBYTES + 1)])
        ->assertStatus(400)->assertJsonPath('data.detail', uploadLine($keys[1], SiteLanguage::Greek));

    expect(array_map(fn (string $key): bool => uploadLine($key, SiteLanguage::Greek) !== uploadLine($key), $keys))->toBe([true, true])
        ->and(Storage::disk(TenantImages::DISK)->allFiles())->toBe([]);
});
