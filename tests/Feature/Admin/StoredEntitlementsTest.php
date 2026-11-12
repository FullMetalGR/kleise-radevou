<?php

use App\Billing\EntitlementResolver;
use App\Billing\StoredEntitlementResolver;
use App\Http\Controllers\Admin\BlackoutController;
use App\Models\User;
use App\Tenancy\Tenant;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\LunaFixture;
use Tests\Support\WeeklyHours;
use Tests\TestCase;

pest()->use(RefreshDatabase::class);

/*
 * The entitlement set as the tenant row says it: the resolver bound in the container reads the
 * row alone, every admin screen reads its answer through the shared props, and the narrowing
 * record reaches every signed-in page for the shell's banner. Without the staff feature the
 * staff writes, the per-member links and the per-member closures answer not-found as the
 * create route does, while the tenant-wide closures and every read answer on. No test here
 * binds a resolver: each answer is the row's, through the real binding.
 */

beforeEach(function () {
    $this->withoutVite();
    provisionTenant('luna', ['name' => 'Luna Hair Studio', 'ownerEmail' => 'owner@luna.example']);
});

/**
 * @param  array<string, string|null>  $columns
 */
function storedRow(array $columns): void
{
    DB::table('tenants')->where('slug', 'luna')->update($columns);
}

function storedOwner(): User
{
    return User::query()->where('email', 'owner@luna.example')->sole();
}

function storedImplicit(): int
{
    $id = DB::table('tenant_luna.staff')->where('is_implicit', true)->value('id');

    return is_int($id) ? $id : throw new LogicException('luna has no implicit staff member.');
}

/**
 * @param  array<mixed>  $body
 * @return TestResponse<Response>
 */
function storedWrite(TestCase $test, string $method, string $path, array $body = [], bool $inertia = true): TestResponse
{
    $headers = $inertia ? ['X-Inertia' => 'true', 'Accept' => 'text/html, application/xhtml+xml'] : ['Accept' => 'application/json'];

    return $test->actingAs(storedOwner())->from(tenantUrl('luna', '/admin/staff'))
        ->call($method, tenantUrl('luna', '/admin'.$path), $body, [], [], collect($headers)->mapWithKeys(fn (string $value, string $name): array => ['HTTP_'.strtoupper(str_replace('-', '_', $name)) => $value])->all());
}

it('binds the resolver that reads the row, and the stub is gone', function () {
    expect(get_class(app(EntitlementResolver::class)))->toBe(StoredEntitlementResolver::class)
        ->and(class_exists('App\\Billing\\EveryFeatureResolver'))->toBeFalse();
});

it('hands every page the entitlements the row holds under the state it caches', function (string $state, string $stored, array $slugs) {
    storedRow(['subscription_state' => $state, 'entitlements' => $stored, 'grace_ends_at' => $state === 'grace' ? '2026-10-01 09:00:00+00' : null]);

    $this->actingAs(storedOwner())->get(tenantUrl('luna', '/admin'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('entitlements', $slugs));
})->with([
    'active with staff' => ['active', '{staff}', ['staff']],
    'grace keeps the whole set' => ['grace', '{staff}', ['staff']],
    'past_due is the floor' => ['past_due', '{staff}', []],
    'canceled is the floor' => ['canceled', '{staff}', []],
    'incomplete is the floor' => ['incomplete', '{staff}', []],
    'an unknown slug beside staff is dropped' => ['active', '{staff,nonsense}', ['staff']],
    'an unknown slug alone is the floor' => ['active', '{nonsense}', []],
]);

it('resolves from the row already loaded and issues no query of its own', function () {
    storedRow(['entitlements' => '{staff}']);
    $tenant = Tenant::query()->where('slug', 'luna')->sole();
    $statements = [];
    DB::listen(function (QueryExecuted $query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    $slugs = app(EntitlementResolver::class)->resolve($tenant)->toSlugs();

    expect($slugs)->toBe(['staff'])->and($statements)->toBe([]);
});

it('hands every signed-in page the narrowing record filtered to the slugs this build knows', function (string $path) {
    storedRow(['narrowed' => '{nonsense,staff}']);

    $this->actingAs(storedOwner())->get(tenantUrl('luna', $path))
        ->assertInertia(fn (Assert $page) => $page->where('narrowed', ['staff']));
})->with(['/admin', '/admin/bookings', '/admin/services', '/admin/staff', '/admin/settings', '/admin/nowhere']);

it('hands an empty narrowing record for a record naming only unknown slugs and for none', function () {
    storedRow(['narrowed' => '{nonsense}']);
    $this->actingAs(storedOwner())->get(tenantUrl('luna', '/admin'))
        ->assertInertia(fn (Assert $page) => $page->where('narrowed', []));

    storedRow(['narrowed' => '{}']);
    $this->actingAs(storedOwner())->get(tenantUrl('luna', '/admin'))
        ->assertInertia(fn (Assert $page) => $page->where('narrowed', []));
});

it('hands the sign-in page no narrowing record', function () {
    storedRow(['narrowed' => '{staff}']);

    $this->get(tenantUrl('luna', '/admin/login'))
        ->assertInertia(fn (Assert $page) => $page->component('auth/Login')->missing('narrowed'));
});

it('answers not-found on the staff writes and the per-member closures without the staff feature, for an Inertia caller and a JSON one, writing nothing', function (string $method, string $path, array $body) {
    $implicit = storedImplicit();
    DB::table('tenant_luna.blackouts')->insert(['staff_id' => $implicit, 'date' => '2026-12-24']);
    $before = [DB::table('tenant_luna.staff')->orderBy('id')->get()->all(), DB::table('tenant_luna.blackouts')->orderBy('id')->get()->all(), DB::table('tenant_luna.service_staff')->count()];
    $path = str_replace('{id}', (string) $implicit, $path);

    storedWrite($this, $method, $path, $body)->assertNotFound();
    storedWrite($this, $method, $path, $body, inertia: false)->assertNotFound();

    expect([DB::table('tenant_luna.staff')->orderBy('id')->get()->all(), DB::table('tenant_luna.blackouts')->orderBy('id')->get()->all(), DB::table('tenant_luna.service_staff')->count()])->toEqual($before);
})->with([
    'a new member' => ['POST', '/staff', ['name' => 'Elena Papadaki']],
    'a new member, a body that would not validate' => ['POST', '/staff', []],
    'a member saved' => ['PUT', '/staff/{id}', ['name' => 'Luna Front Desk, renamed']],
    'a member saved, a body that would not validate' => ['PUT', '/staff/{id}', ['status' => 'maybe']],
    'a member deleted' => ['DELETE', '/staff/{id}', []],
    'the services a member performs' => ['PUT', '/staff/{id}/services', ['service_ids' => []]],
    'a photo uploaded' => ['POST', '/staff/{id}/photo', []],
    'a photo removed' => ['DELETE', '/staff/{id}/photo', []],
    'a closure for a member added' => ['POST', '/staff/{id}/blackouts', ['date' => '2026-12-25']],
    'a closure for a member removed' => ['DELETE', '/staff/{id}/blackouts', ['date' => '2026-12-24']],
]);

it('adds a tenant-wide closure without the staff feature', function () {
    storedWrite($this, 'POST', '/blackouts', ['date' => '2026-12-25'])->assertRedirect(tenantUrl('luna', '/admin/staff'));

    expect(session('flash'))->toBe(['kind' => 'success', 'message' => adminLine(BlackoutController::ADDED)])
        ->and(DB::table('tenant_luna.blackouts')->whereNull('staff_id')->pluck('date')->all())->toBe(['2026-12-25']);
});

it('removes a tenant-wide closure without the staff feature', function () {
    DB::table('tenant_luna.blackouts')->insert(['staff_id' => null, 'date' => '2026-12-25']);

    storedWrite($this, 'DELETE', '/blackouts', ['date' => '2026-12-25'])->assertRedirect(tenantUrl('luna', '/admin/staff'));

    expect(session('flash'))->toBe(['kind' => 'success', 'message' => adminLine(BlackoutController::REMOVED)])
        ->and(DB::table('tenant_luna.blackouts')->count())->toBe(0);
});

it('answers the Staff screen\'s reads without the staff feature', function (string $path, bool $detail) {
    $implicit = storedImplicit();
    $view = $detail ? ['kind' => 'detail', 'id' => $implicit] : ['kind' => 'list'];

    $this->actingAs(storedOwner())->get(tenantUrl('luna', str_replace('{id}', (string) $implicit, $path)))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Staff')->where('entitlements', [])->where('view', $view));
})->with([
    'the list' => ['/admin/staff', false],
    "a member's page" => ['/admin/staff/{id}', true],
]);

it('answers the new staff member page not-found without the staff feature', function () {
    $this->actingAs(storedOwner())->get(tenantUrl('luna', '/admin/staff/new'))->assertNotFound();
});

it('hands the Staff screen of a floor tenant a roster of one row, its implicit member, active, beside the tenant-wide closures', function (string $path) {
    DB::table('tenant_luna.blackouts')->insert(['staff_id' => null, 'date' => '2026-12-25']);
    $implicit = storedImplicit();

    $this->actingAs(storedOwner())->get(tenantUrl('luna', str_replace('{id}', (string) $implicit, $path)))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Staff')
            ->where('entitlements', [])
            ->has('staff', 1)
            ->where('staff.0.id', $implicit)
            ->where('staff.0.is_implicit', true)
            ->where('staff.0.status', 'active')
            ->where('staff.0.blackouts', [])
            ->has('closures', 1)
            ->where('closures.0.staff_id', null)
            ->where('closures.0.date', '2026-12-25'));
})->with([
    'the list reached by URL' => ['/admin/staff'],
    "the implicit member's page reached by URL" => ['/admin/staff/{id}'],
]);

it('hands the Staff screen of a tenant that lost the feature every member deactivated before it went, with their hours and closures, beside the implicit one', function (string $path) {
    storedRow(['entitlements' => '{}', 'narrowed' => '{staff}']);
    $implicit = storedImplicit();
    $elena = DB::table('tenant_luna.staff')->insertGetId(['name' => 'Elena Papadaki', 'status' => 'inactive', 'sort' => 2]);
    $nikos = DB::table('tenant_luna.staff')->insertGetId(['name' => 'Nikos Georgiou', 'status' => 'inactive', 'sort' => 3]);
    WeeklyHours::add('tenant_luna', $elena, 1, '09:07', '13:00');
    DB::table('tenant_luna.blackouts')->insert(['staff_id' => $elena, 'date' => '2026-12-04', 'start_time' => '14:00', 'end_time' => '16:00', 'reason' => 'Dentist']);
    $view = str_contains($path, '{id}') ? ['kind' => 'detail', 'id' => $elena] : ['kind' => 'list'];

    $this->actingAs(storedOwner())->get(tenantUrl('luna', str_replace('{id}', (string) $elena, $path)))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Staff')
            ->where('entitlements', [])
            ->where('narrowed', ['staff'])
            ->where('view', $view)
            ->has('staff', 3)
            ->where('staff.0.id', $implicit)
            ->where('staff.0.is_implicit', true)
            ->where('staff.1.id', $elena)
            ->where('staff.1.status', 'inactive')
            ->where('staff.1.pattern.windows', [['weekday' => 1, 'start' => '09:07', 'end' => '13:00']])
            ->where('staff.1.blackouts', [['date' => '2026-12-04', 'until' => null, 'start' => '14:00', 'end' => '16:00', 'reason' => 'Dentist']])
            ->where('staff.2.id', $nikos)
            ->where('staff.2.status', 'inactive'));
})->with([
    'the list reached by URL' => ['/admin/staff'],
    "an inactive member's page reached by URL" => ['/admin/staff/{id}'],
]);

it('answers the staff writes and the per-member closures as usual with the feature on the row', function () {
    storedRow(['entitlements' => '{staff}']);
    $implicit = storedImplicit();

    $this->actingAs(storedOwner())->get(tenantUrl('luna', '/admin/staff/new'))->assertOk();
    storedWrite($this, 'POST', '/staff', ['name' => 'Elena Papadaki'])->assertRedirect();
    $elena = LunaFixture::id('staff', 'Elena Papadaki');
    storedWrite($this, 'PUT', '/staff/'.$elena, ['name' => 'Elena P.'])->assertRedirect();
    storedWrite($this, 'PUT', '/staff/'.$elena.'/services', ['service_ids' => []])->assertRedirect();
    storedWrite($this, 'POST', '/staff/'.$implicit.'/blackouts', ['date' => '2026-12-25'])->assertRedirect();
    storedWrite($this, 'DELETE', '/staff/'.$implicit.'/blackouts', ['date' => '2026-12-25'])->assertRedirect();
    storedWrite($this, 'DELETE', '/staff/'.$elena)->assertRedirect(tenantUrl('luna', '/admin/staff'));

    expect(DB::table('tenant_luna.staff')->pluck('id')->all())->toBe([$implicit])
        ->and(DB::table('tenant_luna.blackouts')->count())->toBe(0);
});

it('hands Settings the roster of three active members on a tenant that lost the feature', function () {
    storedRow(['entitlements' => '{}', 'narrowed' => '{staff}']);
    foreach (['Elena Papadaki', 'Nikos Georgiou'] as $position => $name) {
        DB::table('tenant_luna.staff')->insert(['name' => $name, 'status' => 'active', 'sort' => $position + 2]);
    }

    $this->actingAs(storedOwner())->get(tenantUrl('luna', '/admin/settings'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('entitlements', [])->where('narrowed', ['staff'])->has('roster', 3));
});

it('hands Settings the implicit member alone on a tenant that never had the feature', function () {
    $this->actingAs(storedOwner())->get(tenantUrl('luna', '/admin/settings'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('entitlements', [])->where('narrowed', [])->has('roster', 1)->where('roster.0.id', storedImplicit()));
});
