<?php

use App\Infrastructure\Db\SettingsReader;
use App\Infrastructure\Db\SetupChecklist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\LunaFixture;
use Tests\TestCase;

pest()->use(RefreshDatabase::class);

/*
 * The two setup markers on the settings row: when the opening hours were last confirmed
 * and when the booking link was last copied. Each is an instant the database writes as its
 * own now() on the write that marks it and nothing ever clears, and each is read as a
 * boolean, since the checklist asks whether, never when.
 */

beforeEach(function () {
    $this->withoutVite();
    seedDemo();
});

function markersOwner(): User
{
    return User::query()->where('email', 'owner@luna.example')->sole();
}

/**
 * @param  array<string, mixed>  $body
 * @return TestResponse<Response>
 */
function markersWrite(TestCase $test, User $user, string $method, string $path, array $body = []): TestResponse
{
    return $test->actingAs($user)->from(tenantUrl('luna', '/admin/settings'))->json($method, tenantUrl('luna', '/admin'.$path), $body, ['X-Inertia' => 'true']);
}

/**
 * Whether the hours marker is set, and whether it is later than it was an hour back. A write
 * runs inside the suite's transaction, where the database's now() is the transaction's start,
 * so a second stamp is seen by moving the first back an hour and watching it come forward.
 */
function hoursMarker(): ?string
{
    $value = settingsOf('luna')->selectRaw('hours_confirmed_at::text as at')->value('at');

    return is_string($value) ? $value : null;
}

function hoursMarkerMovedBack(): void
{
    settingsOf('luna')->update(['hours_confirmed_at' => DB::raw("hours_confirmed_at - interval '1 hour'")]);
}

function hoursMarkerIsNow(): bool
{
    return settingsOf('luna')->whereRaw('hours_confirmed_at = now()')->exists();
}

it('reads both markers as false on a tenant that has marked neither, and each as true once its column is written', function () {
    $reader = app(SettingsReader::class);

    expect(settingsOf('luna')->value('hours_confirmed_at'))->toBeNull()
        ->and(settingsOf('luna')->value('link_copied_at'))->toBeNull()
        ->and([$reader->read(LunaFixture::tenant())->hoursConfirmed, $reader->read(LunaFixture::tenant())->linkCopied])->toBe([false, false]);

    settingsOf('luna')->update(['hours_confirmed_at' => DB::raw('now()')]);
    expect([$reader->read(LunaFixture::tenant())->hoursConfirmed, $reader->read(LunaFixture::tenant())->linkCopied])->toBe([true, false]);

    settingsOf('luna')->update(['link_copied_at' => DB::raw('now()')]);
    expect([$reader->read(LunaFixture::tenant())->hoursConfirmed, $reader->read(LunaFixture::tenant())->linkCopied])->toBe([true, true]);
});

it('stamps the hours marker on a landed PUT /admin/opening-hours holding a window, never on an empty week, which leaves the checklist\'s hours item open, and never on a refused one', function () {
    $owner = markersOwner();

    markersWrite($this, $owner, 'PUT', '/opening-hours', ['rules' => 'Monday'])->assertStatus(400);
    expect(hoursMarker())->toBeNull();

    markersWrite($this, $owner, 'PUT', '/opening-hours', ['rules' => []])->assertRedirect(tenantUrl('luna', '/admin/settings'));
    expect(hoursMarker())->toBeNull()
        ->and(DB::table('tenant_luna.shift_patterns')->where('staff_id', implicitStaffId('tenant_luna'))->exists())->toBeFalse()
        ->and(app(SetupChecklist::class)->facts(LunaFixture::tenant())['hours'])->toBeFalse();

    markersWrite($this, $owner, 'PUT', '/opening-hours', ['rules' => [['weekday' => 1, 'start' => '09:00', 'end' => '17:00']]])->assertRedirect(tenantUrl('luna', '/admin/settings'));
    expect(hoursMarkerIsNow())->toBeTrue()
        ->and(app(SetupChecklist::class)->facts(LunaFixture::tenant())['hours'])->toBeTrue();

    hoursMarkerMovedBack();
    $before = hoursMarker();
    markersWrite($this, $owner, 'PUT', '/opening-hours', ['rules' => []])->assertRedirect(tenantUrl('luna', '/admin/settings'));
    expect(hoursMarker())->toBe($before)->not->toBeNull();

    markersWrite($this, $owner, 'PUT', '/opening-hours', [])->assertStatus(400);
    expect(hoursMarker())->toBe($before);
});

it('stamps the hours marker on no staff create or update, a body naming a week refused and the member\'s pattern as it was, since a member\'s hours are set on Shifts', function () {
    $owner = markersOwner();
    $member = ['name' => 'Maria Ioannou', 'email' => 'maria@luna.example', 'user_id' => null, 'status' => 'active', 'service_ids' => []];
    $elena = LunaFixture::id('staff', 'Elena Papadaki');
    $pattern = DB::table('tenant_luna.shift_patterns')->where('staff_id', $elena)->value('windows');

    markersWrite($this, $owner, 'POST', '/staff', $member)->assertRedirect();
    $maria = DB::table('tenant_luna.staff')->where('name', 'Maria Ioannou')->value('id');

    expect($maria)->not->toBeNull()
        ->and(DB::table('tenant_luna.shift_patterns')->where('staff_id', $maria)->exists())->toBeFalse()
        ->and(hoursMarker())->toBeNull();

    markersWrite($this, $owner, 'PUT', '/staff/'.$elena, ['name' => 'Elena P.'])->assertRedirect();
    markersWrite($this, $owner, 'PUT', '/staff/'.$elena, ['rules' => [['weekday' => 2, 'start' => '09:00', 'end' => '13:00']]])->assertStatus(400);

    expect(hoursMarker())->toBeNull()
        ->and(DB::table('tenant_luna.shift_patterns')->where('staff_id', $elena)->value('windows'))->toBe($pattern);
});

it('stamps the link marker on a body-less POST /admin/settings/link-copied, answering a redirect back with no flash, each time', function () {
    $response = markersWrite($this, markersOwner(), 'POST', '/settings/link-copied');

    $response->assertRedirect(tenantUrl('luna', '/admin/settings'))->assertSessionMissing('flash');
    expect(settingsOf('luna')->whereRaw('link_copied_at = now()')->exists())->toBeTrue()
        ->and(app(SettingsReader::class)->read(LunaFixture::tenant())->linkCopied)->toBeTrue();

    settingsOf('luna')->update(['link_copied_at' => DB::raw("link_copied_at - interval '1 hour'")]);
    markersWrite($this, markersOwner(), 'POST', '/settings/link-copied', ['ignored' => 'anything'])->assertRedirect(tenantUrl('luna', '/admin/settings'));
    expect(settingsOf('luna')->whereRaw('link_copied_at = now()')->exists())->toBeTrue()
        ->and(hoursMarker())->toBeNull();
});

it('opens the link marker to a manager, refuses a staff user, and records the press on a lapsed tenant, since it configures nothing', function () {
    $manager = User::query()->findOrFail(addTenantUser('manager@luna.example', 'manager'));
    markersWrite($this, $manager, 'POST', '/settings/link-copied')->assertRedirect(tenantUrl('luna', '/admin/settings'));

    $staff = User::query()->findOrFail(addTenantUser('staff@luna.example', 'staff'));
    settingsOf('luna')->update(['link_copied_at' => null]);
    markersWrite($this, $staff, 'POST', '/settings/link-copied')->assertForbidden();
    expect(settingsOf('luna')->value('link_copied_at'))->toBeNull();

    DB::table('tenants')->where('slug', 'luna')->update(['subscription_state' => 'past_due']);
    markersWrite($this, markersOwner(), 'POST', '/settings/link-copied')->assertRedirect(tenantUrl('luna', '/admin/settings'));
    expect(settingsOf('luna')->value('link_copied_at'))->not->toBeNull();
});
