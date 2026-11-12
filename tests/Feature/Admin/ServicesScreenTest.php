<?php

use App\Domain\Chain\Chain;
use App\Http\Controllers\Admin\ServiceController;
use App\Http\Controllers\Admin\StaffServicesController;
use App\Http\Middleware\RequiresActiveSubscription;
use App\Http\Requests\ServiceRequest;
use App\Http\Requests\StaffRequest;
use App\Http\ServicePayload;
use App\Infrastructure\Db\ServiceReferenced;
use App\Models\User;
use App\Site\SiteLanguage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\LunaFixture;
use Tests\TestCase;

pest()->use(RefreshDatabase::class);

/*
 * The Services screen and its writes under /admin: one page for the catalog, the create route
 * and the detail route, every one carrying the whole catalog; the writes behind manageSettings
 * and the subscription gate; the form request's sentences; the delete guard's 409; the
 * per-member links route; and the public catalog seeing what the admin did. None of these
 * writes enters the transaction runner, so the suite's transaction wraps each test.
 */

beforeEach(function () {
    $this->withoutVite();
    seedDemo();
});

function catalogOwner(): User
{
    return User::query()->where('email', 'owner@luna.example')->sole();
}

function catalogUser(string $role): User
{
    return User::query()->findOrFail(addTenantUser($role.'@luna.example', $role));
}

function haircut(): int
{
    return LunaFixture::id('services', 'Haircut');
}

/**
 * A new service's body as the form sends it: every field, the tenant's currency among them.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function newService(array $overrides = []): array
{
    return [
        'name' => 'Hot towel shave', 'description' => 'Shown on the booking page.', 'duration_min' => 30,
        'processing_gap_min' => 10, 'buffer_before_min' => 0, 'buffer_after_min' => 5, 'price_minor' => 2500,
        'currency' => 'EUR', 'payment_mode' => 'onsite', 'requires_approval' => false, 'approval_hold_hours' => null,
        'on_approval_timeout' => 'expire', 'cancel_window_hours' => 24, 'reschedule_window_hours' => 24,
        'lead_time_min' => 0, 'horizon_days' => 60, ...$overrides,
    ];
}

/**
 * @param  array<string, mixed>  $body
 * @return TestResponse<Response>
 */
function catalogWrite(TestCase $test, User $user, string $method, string $path, array $body = []): TestResponse
{
    return $test->actingAs($user)->from(tenantUrl('luna', '/admin/services'))->json($method, tenantUrl('luna', '/admin'.$path), $body, ['X-Inertia' => 'true']);
}

/** The highest sort in the catalog apart from one row. */
function catalogTopSort(int $except): int
{
    $sort = DB::table('tenant_luna.services')->where('id', '!=', $except)->max('sort');
    assert(is_int($sort));

    return $sort;
}

/**
 * @return list<string>
 */
function publicCatalogNames(TestCase $test): array
{
    $services = $test->get(tenantUrl('luna', '/api/v1/services'))->assertOk()->json('services');
    assert(is_array($services));

    return array_values(array_map(fn (mixed $row): string => is_array($row) && is_string($row['name'] ?? null) ? $row['name'] : '', $services));
}

it('renders the catalog whole on every one of the three routes, the inactive row included, with the view naming the route', function () {
    $owner = catalogOwner();

    $this->actingAs($owner)->get(tenantUrl('luna', '/admin/services'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component(ServiceController::PAGE)
            ->where('view', ['kind' => 'list'])
            ->has('services', 5)
            ->where('services.0.name', 'Haircut')
            ->where('services.4', fn (Collection $row): bool => $row['name'] === 'Perm' && $row['status'] === 'inactive' && $row->keys()->all() === ServicePayload::ADMIN)
            ->where('services.2.processing_gap_min', 30)
            ->where('services.2.price_minor', 8000)
            ->where('services.2.currency', 'EUR'));

    $this->actingAs($owner)->get(tenantUrl('luna', '/admin/services/new'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('view', ['kind' => 'create'])->has('services', 5));

    $this->actingAs($owner)->get(tenantUrl('luna', '/admin/services/'.haircut()))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('view', ['kind' => 'detail', 'id' => haircut()])->has('services', 5));

    // An unknown id is the list settled without that row: the page renders and the client resolves
    // notFound.
    $this->actingAs($owner)->get(tenantUrl('luna', '/admin/services/999999'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('view', ['kind' => 'detail', 'id' => 999999]));

    // A path that is neither an id nor new is no service.
    $this->actingAs($owner)->get(tenantUrl('luna', '/admin/services/abc'))->assertNotFound();
});

it('answers a service id too long to be a whole number at the router, never a server error: the pages not-found, a write as any write to a path no route takes', function () {
    $owner = catalogOwner();
    $before = DB::table('tenant_luna.services')->count();

    $this->actingAs($owner)->get(tenantUrl('luna', '/admin/services/99999999999999999999'))->assertNotFound();
    catalogWrite($this, $owner, 'PUT', '/services/99999999999999999999', ['name' => 'Nobody'])->assertStatus(405);
    catalogWrite($this, $owner, 'DELETE', '/services/99999999999999999999')->assertStatus(405);
    $this->get(tenantUrl('luna', '/api/v1/services/99999999999999999999'))->assertNotFound();

    expect(DB::table('tenant_luna.services')->count())->toBe($before);
});

it('carries the services section beside the shell\'s on each of the three routes, in the request\'s language', function () {
    $owner = catalogOwner();
    $strings = ['shell' => __('admin/shell', [], 'en'), ServiceController::SECTION => __('admin/services', [], 'en')];

    foreach (['/admin/services', '/admin/services/new', '/admin/services/'.haircut()] as $path) {
        $this->actingAs($owner)->get(tenantUrl('luna', $path))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component(ServiceController::PAGE)->where('strings', $strings));
    }
});

it('words the flashes, the refusal and the validation sentences in the user\'s language: a user at el reads services.php\'s Greek, a colleague at en its English', function () {
    User::query()->where('email', 'owner@luna.example')->update(['locale' => SiteLanguage::Greek->value]);
    $owner = catalogOwner();
    $manager = catalogUser('manager');
    $manager->forceFill(['locale' => SiteLanguage::English->value])->save();
    $flash = fn (string $key, SiteLanguage $language) => expect(session('flash'))->toBe(['kind' => 'success', 'message' => adminLine($key, [], $language)]);

    catalogWrite($this, $owner, 'POST', '/services', newService())->assertRedirect();
    $flash(ServiceController::CREATED, SiteLanguage::Greek);
    catalogWrite($this, $owner, 'PUT', '/services/'.haircut(), ['name' => 'Haircut'])->assertRedirect();
    $flash(ServiceController::SAVED, SiteLanguage::Greek);
    catalogWrite($this, $owner, 'PUT', '/services/'.haircut(), ['status' => 'inactive'])->assertRedirect();
    $flash(ServiceController::DEACTIVATED, SiteLanguage::Greek);
    catalogWrite($this, $owner, 'PUT', '/services/'.haircut(), ['status' => 'active'])->assertRedirect();
    $flash(ServiceController::REACTIVATED, SiteLanguage::Greek);

    // A service saved with an empty name, a duration out of range, one off the grid and a
    // horizon past its ceiling.
    catalogWrite($this, $owner, 'PUT', '/services/'.haircut(), ['name' => ''])
        ->assertStatus(400)->assertJsonPath('data.detail', adminLine('admin/services.validation.name', [], SiteLanguage::Greek));
    catalogWrite($this, $owner, 'PUT', '/services/'.haircut(), ['duration_min' => 0])
        ->assertStatus(400)->assertJsonPath('data.detail', adminLine('admin/services.validation.duration', [], SiteLanguage::Greek));
    catalogWrite($this, $owner, 'PUT', '/services/'.haircut(), ['duration_min' => 47])
        ->assertStatus(400)->assertJsonPath('data.detail', adminLine('admin/services.validation.duration_grid', ['n' => '5'], SiteLanguage::Greek));
    catalogWrite($this, $owner, 'PUT', '/services/'.haircut(), ['horizon_days' => 366])
        ->assertStatus(400)->assertJsonPath('data.detail', adminLine(ServiceRequest::HORIZON_TOO_FAR, ['n' => (string) Chain::MAX_HORIZON_DAYS], SiteLanguage::Greek));

    LunaFixture::booking('cancelled', service: 'Haircut');
    catalogWrite($this, $owner, 'DELETE', '/services/'.haircut())
        ->assertStatus(409)->assertJsonPath('code', ServiceReferenced::CODE)->assertJsonPath('data.detail', adminLine(ServiceController::REFERENCED, [], SiteLanguage::Greek));
    catalogWrite($this, $owner, 'DELETE', '/services/'.LunaFixture::id('services', 'Perm'))->assertRedirect();
    $flash(ServiceController::DELETED, SiteLanguage::Greek);

    // The colleague at en, the same tenant, reads the English of the same requests.
    catalogWrite($this, $manager, 'PUT', '/services/'.haircut(), ['name' => ''])
        ->assertStatus(400)->assertJsonPath('data.detail', adminLine('admin/services.validation.name'));
    catalogWrite($this, $manager, 'DELETE', '/services/'.haircut())
        ->assertStatus(409)->assertJsonPath('data.detail', adminLine(ServiceController::REFERENCED));
    catalogWrite($this, $manager, 'PUT', '/services/'.haircut(), ['name' => 'Haircut'])->assertRedirect();
    $flash(ServiceController::SAVED, SiteLanguage::English);

    expect(adminLine(ServiceController::SAVED, [], SiteLanguage::Greek))->not->toBe(adminLine(ServiceController::SAVED))
        ->and(adminLine('admin/services.validation.name', [], SiteLanguage::Greek))->not->toBe(adminLine('admin/services.validation.name'));
});

it('carries the roster with the services each member performs and their weekly windows', function () {
    $this->actingAs(catalogOwner())->get(tenantUrl('luna', '/admin/services/'.haircut()))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('roster', 3)
            ->where('roster.1.name', 'Elena Papadaki')
            ->where('roster.1.active', true)
            ->where('roster.1.service_ids', fn (Collection $ids): bool => $ids->contains(haircut()) && $ids->contains(LunaFixture::id('services', 'Bridal trial')) && ! $ids->contains(LunaFixture::id('services', 'Beard trim')))
            ->where('roster.1.windows.0', fn (Collection $window): bool => $window['weekday'] === 1 && $window['start'] === '09:00' && $window['end'] === '13:00')
            ->where('roster.2.name', 'Nikos Georgiou'));
});

it('opens the reads to a manager and refuses them to a staff user', function () {
    $manager = catalogUser('manager');
    $haircut = haircut();
    $this->actingAs($manager)->get(tenantUrl('luna', '/admin/services'))->assertOk();
    $this->actingAs($manager)->get(tenantUrl('luna', '/admin/services/'.$haircut))->assertOk();
    catalogWrite($this, $manager, 'PUT', '/services/'.$haircut, ['name' => 'Cut by a manager'])->assertRedirect(tenantUrl('luna', '/admin/services'));
    expect(DB::table('tenant_luna.services')->where('id', $haircut)->value('name'))->toBe('Cut by a manager');

    $staff = catalogUser('staff');
    $this->actingAs($staff)->get(tenantUrl('luna', '/admin/services'))->assertForbidden();
    $this->actingAs($staff)->get(tenantUrl('luna', '/admin/services/new'))->assertForbidden();
    $this->actingAs($staff)->get(tenantUrl('luna', '/admin/services/'.$haircut))->assertForbidden();
    catalogWrite($this, $staff, 'POST', '/services', newService())->assertForbidden();
});

it('refuses every write on a past_due tenant with subscription_required naming the state, while the list and the detail page still render', function () {
    DB::table('tenants')->where('slug', 'luna')->update(['subscription_state' => 'past_due']);
    $owner = catalogOwner();

    foreach ([['POST', '/services', newService()], ['PUT', '/services/'.haircut(), ['name' => 'Cut']], ['DELETE', '/services/'.haircut(), []], ['PUT', '/staff/'.LunaFixture::id('staff', 'Elena Papadaki').'/services', ['service_ids' => []]]] as [$method, $path, $body]) {
        catalogWrite($this, $owner, $method, $path, $body)->assertForbidden()
            ->assertJsonPath('code', 'subscription_required')
            ->assertJsonPath('data.state', 'past_due')
            ->assertJsonPath('data.detail', adminLine(RequiresActiveSubscription::DETAIL));
    }

    expect(DB::table('tenant_luna.services')->where('id', haircut())->value('name'))->toBe('Haircut');
    $this->actingAs($owner)->get(tenantUrl('luna', '/admin/services'))->assertOk();
    $this->actingAs($owner)->get(tenantUrl('luna', '/admin/services/'.haircut()))->assertOk();
});

it('creates a service in the currency sent, lands on its page with the flash, sorts it last, and shows it on the public catalog', function () {
    $response = catalogWrite($this, catalogOwner(), 'POST', '/services', newService());

    $id = DB::table('tenant_luna.services')->where('name', 'Hot towel shave')->value('id');
    assert(is_int($id));
    $response->assertRedirect(tenantUrl('luna', '/admin/services/'.$id));
    expect(session('flash'))->toBe(['kind' => 'success', 'message' => adminLine(ServiceController::CREATED)])
        ->and((array) DB::table('tenant_luna.services')->where('id', $id)->first())->toMatchArray([
            'currency' => 'EUR', 'status' => 'active', 'processing_gap_min' => 10, 'buffer_after_min' => 5, 'price_minor' => 2500, 'description' => 'Shown on the booking page.',
        ])
        ->and(DB::table('tenant_luna.services')->where('id', $id)->value('sort'))->toBe(catalogTopSort($id) + 1)
        ->and(publicCatalogNames($this))->toContain('Hot towel shave');
});

it('edits a row without touching its currency: a row created under EUR keeps EUR after the currency of the business changes, and a body naming a currency is refused', function () {
    DB::table('tenants')->where('slug', 'luna')->update(['currency' => 'USD']);
    $haircut = haircut();

    catalogWrite($this, catalogOwner(), 'PUT', '/services/'.$haircut, ['price_minor' => 4000, 'name' => 'Haircut and finish'])
        ->assertRedirect(tenantUrl('luna', '/admin/services'));

    expect(session('flash'))->toBe(['kind' => 'success', 'message' => adminLine(ServiceController::SAVED)])
        ->and((array) DB::table('tenant_luna.services')->where('id', $haircut)->first())->toMatchArray(['currency' => 'EUR', 'price_minor' => 4000, 'name' => 'Haircut and finish', 'duration_min' => 45]);

    catalogWrite($this, catalogOwner(), 'PUT', '/services/'.$haircut, ['currency' => 'USD'])
        ->assertStatus(400)->assertJsonPath('code', 'invalid_request')->assertJsonPath('data.detail', adminLine('admin/services.validation.currency_fixed'));
    catalogWrite($this, catalogOwner(), 'PUT', '/services/999999', ['name' => 'Nobody'])->assertNotFound();
});

it('patches the status alone, the remedy of 9.2, wording the flash for it, and the public catalog drops the row', function () {
    catalogWrite($this, catalogOwner(), 'PUT', '/services/'.haircut(), ['status' => 'inactive'])->assertRedirect(tenantUrl('luna', '/admin/services'));

    expect(session('flash'))->toBe(['kind' => 'success', 'message' => adminLine(ServiceController::DEACTIVATED)])
        ->and(DB::table('tenant_luna.services')->where('id', haircut())->value('status'))->toBe('inactive')
        ->and(DB::table('tenant_luna.services')->where('id', haircut())->value('price_minor'))->toBe(3500)
        ->and(publicCatalogNames($this))->not->toContain('Haircut');
});

it('refuses a bad field with that field\'s own sentence at 400, read through the translator by its key, the duration off the granularity grid among them', function (array $body, string $key, array $values) {
    /** @var array<string, mixed> $body */
    /** @var array<string, string> $values */
    catalogWrite($this, catalogOwner(), 'PUT', '/services/'.haircut(), $body)
        ->assertStatus(400)->assertJsonPath('code', 'invalid_request')->assertJsonPath('data.detail', adminLine($key, $values));

    expect(DB::table('tenant_luna.services')->where('id', haircut())->value('duration_min'))->toBe(45);
})->with([
    // Luna's granularity is five minutes, the horizon's ceiling Chain::MAX_HORIZON_DAYS and the
    // category's longest ServiceRequest::CATEGORY_MAX, each filled into its sentence.
    'duration off the grid' => [['duration_min' => 47], 'admin/services.validation.duration_grid', ['n' => '5']],
    'duration of zero' => [['duration_min' => 0], 'admin/services.validation.duration', []],
    'blank name' => [['name' => '   '], 'admin/services.validation.name', []],
    'description as a list' => [['description' => ['Wash']], 'admin/services.validation.description', []],
    'negative gap' => [['processing_gap_min' => -5], 'admin/services.validation.processing_gap', []],
    'negative buffer before' => [['buffer_before_min' => -5], 'admin/services.validation.buffer_before', []],
    'negative buffer after' => [['buffer_after_min' => -5], 'admin/services.validation.buffer_after', []],
    'unreadable price' => [['price_minor' => 'twelve'], 'admin/services.validation.price', []],
    'unknown payment mode' => [['payment_mode' => 'cheque'], 'admin/services.validation.payment_mode', []],
    'approval not a flag' => [['requires_approval' => 'maybe'], 'admin/services.validation.requires_approval', []],
    'approval hold of zero' => [['approval_hold_hours' => 0], 'admin/services.validation.approval_hold', []],
    'unknown timeout' => [['on_approval_timeout' => 'wait'], 'admin/services.validation.timeout', []],
    'negative cancellation window' => [['cancel_window_hours' => -1], 'admin/services.validation.cancel_window', []],
    'negative reschedule window' => [['reschedule_window_hours' => -1], 'admin/services.validation.reschedule_window', []],
    'negative lead time' => [['lead_time_min' => -1], 'admin/services.validation.lead_time', []],
    'negative horizon' => [['horizon_days' => -1], 'admin/services.validation.horizon', []],
    'horizon past a year' => [['horizon_days' => 366], ServiceRequest::HORIZON_TOO_FAR, ['n' => (string) Chain::MAX_HORIZON_DAYS]],
    'unknown status' => [['status' => 'paused'], 'admin/services.validation.status', []],
    'category past sixty characters' => [['category' => str_repeat('a', 61)], ServiceRequest::CATEGORY_LENGTH, ['n' => (string) ServiceRequest::CATEGORY_MAX]],
    'category as a list' => [['category' => ['Hair']], ServiceRequest::CATEGORY_TEXT, []],
    'online not a flag' => [['online' => 'sometimes'], ServiceRequest::ONLINE_FLAG, []],
]);

it('holds the booking horizon at 365 days or fewer, P9F-L15-d: 365 is written, 366 refused with its own sentence', function () {
    catalogWrite($this, catalogOwner(), 'PUT', '/services/'.haircut(), ['horizon_days' => 365])->assertRedirect(tenantUrl('luna', '/admin/services'));

    expect(DB::table('tenant_luna.services')->where('id', haircut())->value('horizon_days'))->toBe(365);

    catalogWrite($this, catalogOwner(), 'POST', '/services', newService(['horizon_days' => 366]))
        ->assertStatus(400)->assertJsonPath('code', 'invalid_request')->assertJsonPath('data.detail', adminLine(ServiceRequest::HORIZON_TOO_FAR, ['n' => (string) Chain::MAX_HORIZON_DAYS]));

    expect(DB::table('tenant_luna.services')->where('name', 'Hot towel shave')->exists())->toBeFalse();
});

it('writes the category trimmed, at most sixty characters, an empty one as null, and the online flag, and carries the distinct categories in catalogue order in the page props', function () {
    $owner = catalogOwner();
    $beard = LunaFixture::id('services', 'Beard trim');

    catalogWrite($this, $owner, 'PUT', '/services/'.haircut(), ['category' => '  Hair  ', 'online' => false])->assertRedirect(tenantUrl('luna', '/admin/services'));
    catalogWrite($this, $owner, 'PUT', '/services/'.$beard, ['category' => str_repeat('b', 60)])->assertRedirect(tenantUrl('luna', '/admin/services'));
    catalogWrite($this, $owner, 'POST', '/services', newService(['category' => 'Hair']));

    expect(DB::table('tenant_luna.services')->where('id', haircut())->first(['category', 'online', 'status']))->toEqual((object) ['category' => 'Hair', 'online' => false, 'status' => 'active'])
        ->and(DB::table('tenant_luna.services')->where('name', 'Hot towel shave')->first(['category', 'online']))->toEqual((object) ['category' => 'Hair', 'online' => true])
        ->and(publicCatalogNames($this))->not->toContain('Haircut')->toContain('Hot towel shave');

    $this->actingAs($owner)->get(tenantUrl('luna', '/admin/services'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('categories', ['Hair', str_repeat('b', 60)])
            ->where('services.0.category', 'Hair')
            ->where('services.0.online', false));

    catalogWrite($this, $owner, 'PUT', '/services/'.$beard, ['category' => ''])->assertRedirect(tenantUrl('luna', '/admin/services'));

    expect(DB::table('tenant_luna.services')->where('id', $beard)->value('category'))->toBeNull();
    $this->actingAs($owner)->get(tenantUrl('luna', '/admin/services'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('categories', ['Hair']));
});

it('refuses a new service missing its name, priced in another currency or naming a status, before writing anything', function () {
    catalogWrite($this, catalogOwner(), 'POST', '/services', newService(['name' => '']))
        ->assertStatus(400)->assertJsonPath('data.detail', adminLine('admin/services.validation.name'));
    catalogWrite($this, catalogOwner(), 'POST', '/services', newService(['currency' => 'USD']))
        ->assertStatus(400)->assertJsonPath('data.detail', adminLine('admin/services.validation.currency', ['currency' => 'EUR']));
    catalogWrite($this, catalogOwner(), 'POST', '/services', newService(['status' => 'inactive']))
        ->assertStatus(400)->assertJsonPath('data.detail', adminLine('admin/services.validation.status_new'));

    expect(DB::table('tenant_luna.services')->count())->toBe(5);
});

it('refuses to delete a service a cancelled booking\'s item names, 409 referenced, and deletes an unreferenced one back to the list', function () {
    LunaFixture::booking('cancelled', service: 'Haircut');

    catalogWrite($this, catalogOwner(), 'DELETE', '/services/'.haircut())
        ->assertStatus(409)->assertJsonPath('code', ServiceReferenced::CODE)->assertJsonPath('data.detail', adminLine(ServiceController::REFERENCED));
    expect(DB::table('tenant_luna.services')->where('id', haircut())->exists())->toBeTrue();

    $perm = LunaFixture::id('services', 'Perm');
    catalogWrite($this, catalogOwner(), 'DELETE', '/services/'.$perm)->assertRedirect(tenantUrl('luna', '/admin/services'));
    expect(session('flash'))->toBe(['kind' => 'success', 'message' => adminLine(ServiceController::DELETED)])
        ->and(DB::table('tenant_luna.services')->where('id', $perm)->exists())->toBeFalse()
        ->and(DB::table('tenant_luna.service_staff')->where('service_id', $perm)->exists())->toBeFalse();

    catalogWrite($this, catalogOwner(), 'DELETE', '/services/'.$perm)->assertNotFound();
});

it('replaces the services one member performs whole, leaving every other member as they were, and refuses an id not in the catalog', function () {
    $nikos = LunaFixture::id('staff', 'Nikos Georgiou');
    $elena = LunaFixture::id('staff', 'Elena Papadaki');
    $elenaBefore = DB::table('tenant_luna.service_staff')->where('staff_id', $elena)->orderBy('service_id')->pluck('service_id')->all();
    $bridal = LunaFixture::id('services', 'Bridal trial');
    $beard = LunaFixture::id('services', 'Beard trim');

    catalogWrite($this, catalogOwner(), 'PUT', '/staff/'.$nikos.'/services', ['service_ids' => [$beard, $bridal, $bridal]])
        ->assertRedirect(tenantUrl('luna', '/admin/services'));

    expect(session('flash'))->toBeNull()
        ->and(DB::table('tenant_luna.service_staff')->where('staff_id', $nikos)->orderBy('service_id')->pluck('service_id')->all())->toBe([min($beard, $bridal), max($beard, $bridal)])
        ->and(DB::table('tenant_luna.service_staff')->where('staff_id', $elena)->orderBy('service_id')->pluck('service_id')->all())->toBe($elenaBefore);

    catalogWrite($this, catalogOwner(), 'PUT', '/staff/'.$nikos.'/services', ['service_ids' => [$beard, 999999]])
        ->assertStatus(400)->assertJsonPath('data.detail', adminLine(StaffServicesController::UNKNOWN_SERVICE));
    catalogWrite($this, catalogOwner(), 'PUT', '/staff/'.$nikos.'/services', ['service_ids' => 'all'])
        ->assertStatus(400)->assertJsonPath('data.detail', adminLine(StaffRequest::SERVICE_IDS));
    catalogWrite($this, catalogOwner(), 'PUT', '/staff/999999/services', ['service_ids' => []])->assertNotFound();

    catalogWrite($this, catalogOwner(), 'PUT', '/staff/'.$nikos.'/services', ['service_ids' => []])->assertRedirect(tenantUrl('luna', '/admin/services'));
    expect(DB::table('tenant_luna.service_staff')->where('staff_id', $nikos)->exists())->toBeFalse();
});

it('refuses a member\'s services sent as no list in the user\'s language', function (SiteLanguage $language) {
    User::query()->where('email', 'owner@luna.example')->update(['locale' => $language->value]);

    catalogWrite($this, catalogOwner(), 'PUT', '/staff/'.LunaFixture::id('staff', 'Nikos Georgiou').'/services', ['service_ids' => 'all'])
        ->assertStatus(400)->assertJsonPath('data.detail', adminLine(StaffRequest::SERVICE_IDS, [], $language));
})->with(['a user at en' => [SiteLanguage::English], 'a user at el' => [SiteLanguage::Greek]]);

it('classifies every live column of the services table for the admin, the sort and the timestamps alone left out', function () {
    expect(array_values(array_diff(liveColumns('tenant_luna', 'services'), ServicePayload::ADMIN)))->toBe(['sort', 'created_at', 'updated_at'])
        ->and(array_diff(ServicePayload::ADMIN, ServicePayload::PUBLISHED, ServicePayload::WITHHELD))->toBe([])
        ->and(array_diff(ServicePayload::ADMIN_COUNTS, ServicePayload::ADMIN))->toBe([]);
});
