<?php

use App\Application\Clock;
use App\Billing\EntitlementResolver;
use App\Billing\Entitlements;
use App\Http\Controllers\Admin\BlackoutController;
use App\Http\Controllers\Admin\StaffColourController;
use App\Http\Controllers\Admin\StaffController;
use App\Http\Controllers\Admin\StaffPhotoController;
use App\Http\Controllers\Admin\StaffServicesController;
use App\Http\Middleware\RequiresActiveSubscription;
use App\Http\Requests\BlackoutRequest;
use App\Http\Requests\StaffColourRequest;
use App\Http\Requests\StaffPhotoRequest;
use App\Http\Requests\StaffRequest;
use App\Infrastructure\Db\ImplicitStaffKept;
use App\Infrastructure\Db\StaffReferenced;
use App\Infrastructure\Db\WeeklyPattern;
use App\Models\User;
use App\Site\SiteLanguage;
use App\Tenancy\StaffColour;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FixedClock;
use Tests\Support\LunaFixture;
use Tests\TestCase;

pest()->use(RefreshDatabase::class);

/*
 * The Staff screen and its writes under /admin: one page for the list, the create route and
 * the detail route, every one carrying every member whole with the catalog, the tenant's
 * users and the tenant-wide closures; the writes behind manageSettings and the subscription
 * gate; the form requests' sentences; the delete guard's 409; the closures matched by date
 * and window; and the public availability seeing a rule or a closure on its very next read
 * with no cache action between. None of these writes enters the transaction runner, so the
 * suite's transaction wraps each test. The implicit member is edited as an ordinary row and
 * never deleted, and the create route alone answers not-found under the empty entitlement
 * set.
 */

beforeEach(function () {
    $this->withoutVite();
    seedDemo();
});

function staffOwner(): User
{
    return User::query()->where('email', 'owner@luna.example')->sole();
}

function staffUser(string $role): User
{
    return User::query()->findOrFail(addTenantUser($role.'@luna.example', $role));
}

function elena(): int
{
    return LunaFixture::id('staff', 'Elena Papadaki');
}

function nikos(): int
{
    return LunaFixture::id('staff', 'Nikos Georgiou');
}

/**
 * A new member's body as the form sends it: the row and the links, replace-all, and no week,
 * since a member's hours are set on Shifts.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function newMember(array $overrides = []): array
{
    return [
        'name' => 'Maria Ioannou', 'email' => 'maria@luna.example', 'user_id' => null, 'status' => 'active',
        'service_ids' => [LunaFixture::id('services', 'Haircut')],
        ...$overrides,
    ];
}

/**
 * @param  array<string, mixed>  $body
 * @return TestResponse<Response>
 */
function staffWrite(TestCase $test, User $user, string $method, string $path, array $body = []): TestResponse
{
    return $test->actingAs($user)->from(tenantUrl('luna', '/admin/staff'))->json($method, tenantUrl('luna', '/admin'.$path), $body, ['X-Inertia' => 'true']);
}

/**
 * The windows of one member's pattern as the wire spells them, none where they hold no pattern.
 *
 * @return list<array{int, string, string}>
 */
function patternWindowsOf(int $staffId): array
{
    $pattern = WeeklyPattern::stored(DB::connection(), 'tenant_luna.', [$staffId])[$staffId] ?? null;

    return array_map(fn (array $window): array => [$window['weekday'], $window['start'], $window['end']], $pattern['windows'] ?? []);
}

/**
 * The starts luna's public availability offers on one day for the haircut, with no one picked
 * or with one member picked, read through the default cache store as production reads it.
 *
 * @return list<string>
 */
function haircutStarts(TestCase $test, string $day, ?int $staffId = null): array
{
    app()->instance(Clock::class, FixedClock::at('2026-11-30 12:00:00'));
    $next = new DateTimeImmutable($day, new DateTimeZone('Europe/Athens'));
    $query = http_build_query([
        'items' => (string) json_encode([['service_id' => LunaFixture::id('services', 'Haircut'), 'staff_id' => $staffId]]),
        'from' => $day,
        'to' => $next->add(new DateInterval('P1D'))->format('Y-m-d'),
    ]);
    $days = $test->get(tenantUrl('luna', '/api/v1/availability?'.$query))->assertOk()->json();
    assert(is_array($days));
    $starts = $days[$day] ?? [];
    assert(is_array($starts));

    return array_values(array_map(fn (mixed $start): string => is_string($start) ? $start : '', $starts));
}

it('renders every member whole on the three routes, the implicit one as an ordinary row keeping its flag, with the catalog, the users and the closures today onward', function () {
    app()->instance(Clock::class, FixedClock::at('2026-11-30 12:00:00'));
    $owner = staffOwner();
    $implicit = implicitStaffId('tenant_luna');

    $this->actingAs($owner)->get(tenantUrl('luna', '/admin/staff'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component(StaffController::PAGE)
            ->where('view', ['kind' => 'list'])
            ->has('staff', 3)
            ->where('staff.0', fn (Collection $row): bool => $row['id'] === $implicit && $row['is_implicit'] === true && $row['status'] === 'active' && $row['online'] === true && $row['user_id'] === null && count((array) data_get($row, 'pattern.windows')) === 5)
            ->where('staff.1', fn (Collection $row): bool => $row->keys()->all() === ['id', 'name', 'email', 'status', 'online', 'is_implicit', 'user_id', 'title', 'bio', 'service_ids', 'pattern', 'blackouts', 'colour', 'photo', 'hours'])
            ->where('staff.1.name', 'Elena Papadaki')
            ->where('staff.1.is_implicit', false)
            ->where('staff.1.pattern', fn (Collection $pattern): bool => $pattern->keys()->all() === ['cadence_weeks', 'starts_on', 'ends_on', 'windows'] && $pattern['cadence_weeks'] === 1 && $pattern['starts_on'] === '1999-12-27' && $pattern['ends_on'] === null)
            ->where('staff.1.pattern.windows.0', ['weekday' => 1, 'start' => '09:00', 'end' => '13:00'])
            ->where('staff.1.pattern.windows.1', ['weekday' => 1, 'start' => '14:00', 'end' => '18:00'])
            ->where('staff.1.service_ids', fn (Collection $ids): bool => $ids->contains(LunaFixture::id('services', 'Haircut')))
            ->where('staff.2.blackouts', [['date' => '2026-12-04', 'until' => null, 'start' => '14:00', 'end' => '16:00', 'reason' => 'Dentist']])
            ->has('services', 5)
            ->where('users', fn (Collection $users): bool => $users->count() === 1 && is_array($users[0]) && $users[0]['email'] === 'owner@luna.example' && is_int($users[0]['id']))
            ->where('closures', [['staff_id' => nikos(), 'date' => '2026-12-04', 'until' => null, 'start' => '14:00', 'end' => '16:00', 'reason' => 'Dentist'], ['staff_id' => null, 'date' => '2026-12-25', 'until' => null, 'start' => null, 'end' => null, 'reason' => 'Christmas Day']]));

    $this->actingAs($owner)->get(tenantUrl('luna', '/admin/staff/new'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('view', ['kind' => 'create'])->has('staff', 3));
    $this->actingAs($owner)->get(tenantUrl('luna', '/admin/staff/'.elena()))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('view', ['kind' => 'detail', 'id' => elena()]));
    $this->actingAs($owner)->get(tenantUrl('luna', '/admin/staff/999999'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('view', ['kind' => 'detail', 'id' => 999999]));
    $this->actingAs($owner)->get(tenantUrl('luna', '/admin/staff/abc'))->assertNotFound();
});

it('answers a member id too long to be a whole number at the router, never a server error: the page not-found, a write as any write to a path no route takes', function (string $method, string $path) {
    $owner = staffOwner();
    $before = DB::table('tenant_luna.staff')->count();

    if ($method === 'GET') {
        $this->actingAs($owner)->get(tenantUrl('luna', '/admin'.$path))->assertNotFound();
    } else {
        staffWrite($this, $owner, $method, $path, ['name' => 'Nobody'])->assertStatus(405);
    }

    expect(DB::table('tenant_luna.staff')->count())->toBe($before);
})->with([
    'the page' => ['GET', '/staff/99999999999999999999'],
    'a save' => ['PUT', '/staff/99999999999999999999'],
    'a delete' => ['DELETE', '/staff/99999999999999999999'],
    'the services' => ['PUT', '/staff/99999999999999999999/services'],
    'the colour' => ['PUT', '/staff/99999999999999999999/colour'],
    'a closure' => ['POST', '/staff/99999999999999999999/blackouts'],
]);

it('carries the staff section beside the shell\'s on the three routes, the floor\'s included, with the photo\'s limit spelled', function () {
    $owner = staffOwner();
    $strings = ['shell' => __('admin/shell', [], 'en'), StaffController::SECTION => __('admin/staff', [], 'en')];
    $limit = ['megabytes' => intdiv(StaffPhotoRequest::MAX_KILOBYTES, 1024), 'spelled' => (string) (new NumberFormatter('en', NumberFormatter::SPELLOUT))->format(intdiv(StaffPhotoRequest::MAX_KILOBYTES, 1024))];

    foreach (['/admin/staff', '/admin/staff/new', '/admin/staff/'.elena()] as $path) {
        $this->actingAs($owner)->get(tenantUrl('luna', $path))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component(StaffController::PAGE)->where('strings', $strings)->where('photo_limit', $limit));
    }

    // The floor, the screen under the rail's Closures label, is the same page and carries the same.
    app()->instance(EntitlementResolver::class, new class implements EntitlementResolver
    {
        public function resolve(Tenant $tenant): Entitlements
        {
            return Entitlements::none();
        }
    });
    $this->actingAs($owner)->get(tenantUrl('luna', '/admin/staff'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component(StaffController::PAGE)->where('entitlements', [])->where('strings', $strings));
});

it('words the flashes, the refusals and the validation sentences in the user\'s language: a user at el reads staff.php\'s Greek, a colleague at en its English', function () {
    User::query()->where('email', 'owner@luna.example')->update(['locale' => SiteLanguage::Greek->value]);
    $owner = staffOwner();
    $manager = staffUser('manager');
    $manager->forceFill(['locale' => SiteLanguage::English->value])->save();
    $flash = fn (string $key, SiteLanguage $language) => expect(session('flash'))->toBe(['kind' => 'success', 'message' => adminLine($key, [], $language)]);
    $refused = fn (TestResponse $answer, string $key, SiteLanguage $language, ?string $n = null) => $answer->assertStatus(400)
        ->assertJsonPath('data.detail', adminLine($key, $n === null ? [] : ['n' => $n], $language));

    staffWrite($this, $owner, 'POST', '/staff', newMember())->assertRedirect();
    $flash(StaffController::CREATED, SiteLanguage::Greek);
    staffWrite($this, $owner, 'PUT', '/staff/'.elena(), ['name' => 'Elena Papadaki'])->assertRedirect();
    $flash(StaffController::SAVED, SiteLanguage::Greek);
    staffWrite($this, $owner, 'PUT', '/staff/'.elena(), ['status' => 'inactive'])->assertRedirect();
    $flash(StaffController::DEACTIVATED, SiteLanguage::Greek);
    staffWrite($this, $owner, 'PUT', '/staff/'.elena(), ['status' => 'active'])->assertRedirect();
    $flash(StaffController::REACTIVATED, SiteLanguage::Greek);

    // A member saved with no name, a title and a bio past their lengths, a service the catalog
    // does not hold, on the member's save and on the links' own route.
    $refused(staffWrite($this, $owner, 'PUT', '/staff/'.elena(), ['name' => '']), 'admin/staff.validation.name', SiteLanguage::Greek);
    $refused(staffWrite($this, $owner, 'PUT', '/staff/'.elena(), ['title' => str_repeat('a', StaffRequest::TITLE_MAX + 1)]), StaffRequest::TITLE_LENGTH, SiteLanguage::Greek, (string) StaffRequest::TITLE_MAX);
    $refused(staffWrite($this, $owner, 'PUT', '/staff/'.elena(), ['bio' => str_repeat('a', StaffRequest::BIO_MAX + 1)]), StaffRequest::BIO_LENGTH, SiteLanguage::Greek, (string) StaffRequest::BIO_MAX);
    $refused(staffWrite($this, $owner, 'PUT', '/staff/'.elena(), ['service_ids' => [999999]]), StaffServicesController::UNKNOWN_SERVICE, SiteLanguage::Greek);
    $refused($this->actingAs($owner)->from(tenantUrl('luna', '/admin/services'))->json('PUT', tenantUrl('luna', '/admin/staff/'.elena().'/services'), ['service_ids' => [999999]], ['X-Inertia' => 'true']), StaffServicesController::UNKNOWN_SERVICE, SiteLanguage::Greek);

    // A closure ending before it starts, an Until before its date, and the two closure flashes.
    $refused(staffWrite($this, $owner, 'POST', '/blackouts', ['date' => '2026-12-24', 'start_time' => '15:00', 'end_time' => '14:00']), BlackoutRequest::WINDOW_ORDER, SiteLanguage::Greek);
    $refused(staffWrite($this, $owner, 'POST', '/blackouts', ['date' => '2026-12-24', 'until' => '2026-12-22']), BlackoutRequest::UNTIL_ORDER, SiteLanguage::Greek);
    staffWrite($this, $owner, 'POST', '/blackouts', ['date' => '2026-12-24'])->assertRedirect();
    $flash(BlackoutController::ADDED, SiteLanguage::Greek);
    staffWrite($this, $owner, 'DELETE', '/blackouts', ['date' => '2026-12-24'])->assertRedirect();
    $flash(BlackoutController::REMOVED, SiteLanguage::Greek);

    // The colour's refusal, its palette's size spelled by ICU in Greek, its flash, and the
    // photo's removal.
    $palette = [
        'automatic' => adminLine(StaffColourRequest::AUTOMATIC, [], SiteLanguage::Greek),
        'count' => (string) (new NumberFormatter(SiteLanguage::Greek->value, NumberFormatter::SPELLOUT))->format(count(StaffColour::cases())),
    ];
    staffWrite($this, $owner, 'PUT', '/staff/'.elena().'/colour', ['colour' => 'pink'])
        ->assertStatus(400)->assertJsonPath('data.detail', adminLine(StaffColourRequest::REFUSED, $palette, SiteLanguage::Greek));
    staffWrite($this, $owner, 'PUT', '/staff/'.elena().'/colour', ['colour' => 'purple'])->assertRedirect();
    $flash(StaffColourController::SAVED, SiteLanguage::Greek);
    staffWrite($this, $owner, 'DELETE', '/staff/'.elena().'/photo')->assertRedirect();
    $flash(StaffPhotoController::REMOVED, SiteLanguage::Greek);

    LunaFixture::booking('cancelled', service: 'Haircut');
    staffWrite($this, $owner, 'DELETE', '/staff/'.elena())->assertStatus(409)->assertJsonPath('data.detail', adminLine(StaffController::REFERENCED, [], SiteLanguage::Greek));
    staffWrite($this, $owner, 'DELETE', '/staff/'.implicitStaffId('tenant_luna'))->assertStatus(409)->assertJsonPath('data.detail', adminLine(StaffController::IMPLICIT, [], SiteLanguage::Greek));

    // The colleague at en, the same tenant, reads the English of the same requests.
    $refused(staffWrite($this, $manager, 'PUT', '/staff/'.elena(), ['name' => '']), 'admin/staff.validation.name', SiteLanguage::English);
    $refused(staffWrite($this, $manager, 'POST', '/blackouts', ['date' => '2026-12-24', 'start_time' => '15:00', 'end_time' => '14:00']), BlackoutRequest::WINDOW_ORDER, SiteLanguage::English);
    staffWrite($this, $manager, 'DELETE', '/staff/'.elena())->assertStatus(409)->assertJsonPath('data.detail', adminLine(StaffController::REFERENCED));
    staffWrite($this, $manager, 'PUT', '/staff/'.elena().'/colour', ['colour' => 'blue'])->assertRedirect();
    $flash(StaffColourController::SAVED, SiteLanguage::English);
    staffWrite($this, $owner, 'DELETE', '/staff/'.nikos())->assertRedirect();
    $flash(StaffController::DELETED, SiteLanguage::Greek);

    expect(adminLine(StaffController::DELETED, [], SiteLanguage::Greek))->not->toBe(adminLine(StaffController::DELETED))
        ->and(adminLine('admin/staff.validation.name', [], SiteLanguage::Greek))->not->toBe(adminLine('admin/staff.validation.name'));
});

it('reads each of staff.php\'s three choices by its count, the bound of one reaching the first case and two the second, in Greek and in English', function (string $key, array $values) {
    $replace = [];
    foreach ($values as $name => $value) {
        if (is_string($name) && is_string($value)) {
            $replace[$name] = $value;
        }
    }
    $walked = 0;

    foreach ([SiteLanguage::Greek, SiteLanguage::English] as $language) {
        $raw = __($key, [], $language->value);
        assert(is_string($raw));
        $cases = explode('|', $raw);

        expect($cases)->toHaveCount(2)
            ->and($cases[0])->toStartWith('{1} ')
            ->and($cases[1])->toStartWith('[2,*] ');

        foreach ([1 => 0, 2 => 1, 9 => 1] as $count => $case) {
            $filled = strtr(substr($cases[$case], strpos($cases[$case], ' ') + 1), [':count' => (string) $count, ...array_combine(array_map(fn (string $name): string => ':'.$name, array_keys($replace)), $replace)]);
            expect(adminChoice($key, $count, $replace, $language))->toBe($filled);
            $walked++;
        }
    }

    expect($walked)->toBe(6);
})->with([
    'table.count' => ['admin/staff.table.count', []],
    'form.bio_count' => ['admin/staff.form.bio_count', ['max' => '500']],
    'form.photo_help' => ['admin/staff.form.photo_help', ['n' => 'n-token']],
]);

it('opens the reads to a manager and refuses a staff user on every route of the screen', function () {
    $manager = staffUser('manager');
    $elena = elena();
    $this->actingAs($manager)->get(tenantUrl('luna', '/admin/staff'))->assertOk();
    staffWrite($this, $manager, 'PUT', '/staff/'.$elena, ['name' => 'Elena P.'])->assertRedirect(tenantUrl('luna', '/admin/staff'));

    $staff = staffUser('staff');
    foreach (['/staff', '/staff/new', '/staff/'.$elena] as $path) {
        $this->actingAs($staff)->get(tenantUrl('luna', '/admin'.$path))->assertForbidden();
    }
    foreach ([['POST', '/staff'], ['PUT', '/staff/'.$elena], ['DELETE', '/staff/'.$elena], ['POST', '/staff/'.$elena.'/photo'], ['DELETE', '/staff/'.$elena.'/photo'], ['POST', '/staff/'.$elena.'/blackouts'], ['DELETE', '/staff/'.$elena.'/blackouts'], ['POST', '/blackouts'], ['DELETE', '/blackouts']] as [$method, $path]) {
        staffWrite($this, $staff, $method, $path, ['date' => '2026-12-24', 'name' => 'X'])->assertForbidden();
    }
});

it('refuses every write on a past_due tenant with subscription_required, while the reads still render', function () {
    DB::table('tenants')->where('slug', 'luna')->update(['subscription_state' => 'past_due']);
    $owner = staffOwner();
    $writes = [
        ['POST', '/staff', newMember()], ['PUT', '/staff/'.elena(), ['name' => 'Cut']], ['DELETE', '/staff/'.elena(), []],
        ['POST', '/staff/'.elena().'/photo', []], ['DELETE', '/staff/'.elena().'/photo', []],
        ['POST', '/staff/'.elena().'/blackouts', ['date' => '2026-12-24']], ['DELETE', '/staff/'.elena().'/blackouts', ['date' => '2026-12-24']],
        ['POST', '/blackouts', ['date' => '2026-12-24']], ['DELETE', '/blackouts', ['date' => '2026-12-25']],
    ];

    foreach ($writes as [$method, $path, $body]) {
        staffWrite($this, $owner, $method, $path, $body)->assertForbidden()->assertJsonPath('code', 'subscription_required')->assertJsonPath('data.state', 'past_due')->assertJsonPath('data.detail', adminLine(RequiresActiveSubscription::DETAIL));
    }

    expect(DB::table('tenant_luna.staff')->count())->toBe(3)->and(DB::table('tenant_luna.blackouts')->count())->toBe(2);
    $this->actingAs($owner)->get(tenantUrl('luna', '/admin/staff'))->assertOk();
    $this->actingAs($owner)->get(tenantUrl('luna', '/admin/staff/'.elena()))->assertOk();
});

it('creates a member with their links and no hours, lands on the page with the flash, and offers no start of theirs until Shifts gives them hours', function () {
    // 07:00 in Athens on a Wednesday in December is 05:00Z, before anyone else's first window.
    $wednesday = '2026-12-09';

    $response = staffWrite($this, staffOwner(), 'POST', '/staff', newMember());
    $id = DB::table('tenant_luna.staff')->where('name', 'Maria Ioannou')->value('id');
    assert(is_int($id));
    $response->assertRedirect(tenantUrl('luna', '/admin/staff/'.$id));

    expect(session('flash'))->toBe(['kind' => 'success', 'message' => adminLine(StaffController::CREATED)])
        ->and((array) DB::table('tenant_luna.staff')->where('id', $id)->first())->toMatchArray(['email' => 'maria@luna.example', 'status' => 'active', 'is_implicit' => false, 'user_id' => null])
        ->and(patternWindowsOf($id))->toBe([])
        ->and(DB::table('tenant_luna.service_staff')->where('staff_id', $id)->pluck('service_id')->all())->toBe([LunaFixture::id('services', 'Haircut')])
        ->and(haircutStarts($this, $wednesday, $id))->toBe([])
        ->and(haircutStarts($this, $wednesday))->not->toContain('2026-12-09T05:00:00+00:00');
});

it('saves links replace-all and the linked user by id, the member\'s pattern as it was', function () {
    $owner = staffOwner();
    $elena = elena();
    $before = patternWindowsOf($elena);

    staffWrite($this, $owner, 'PUT', '/staff/'.$elena, ['name' => 'Elena Papadaki', 'email' => '', 'user_id' => $owner->id, 'status' => 'active', 'service_ids' => [LunaFixture::id('services', 'Colour')]])
        ->assertRedirect(tenantUrl('luna', '/admin/staff'));

    expect(session('flash'))->toBe(['kind' => 'success', 'message' => adminLine(StaffController::SAVED)])
        ->and(patternWindowsOf($elena))->toBe($before)->toHaveCount(10)
        ->and((array) DB::table('tenant_luna.staff')->where('id', $elena)->first())->toMatchArray(['email' => null, 'user_id' => $owner->id])
        ->and(DB::table('tenant_luna.service_staff')->where('staff_id', $elena)->pluck('service_id')->all())->toBe([LunaFixture::id('services', 'Colour')]);

    // The name alone, and the links stand as stored.
    staffWrite($this, $owner, 'PUT', '/staff/'.$elena, ['name' => 'Elena P.'])->assertRedirect(tenantUrl('luna', '/admin/staff'));
    expect(DB::table('tenant_luna.service_staff')->where('staff_id', $elena)->pluck('service_id')->all())->toBe([LunaFixture::id('services', 'Colour')])
        ->and(DB::table('tenant_luna.staff')->where('id', $elena)->value('user_id'))->toBe($owner->id);

    staffWrite($this, $owner, 'PUT', '/staff/999999', ['name' => 'Nobody'])->assertNotFound();
});

it('refuses a user of another tenant, a service not in the catalog, and every other bad field with its own sentence, in the user\'s language', function (array $body, string $sentence, array $values, SiteLanguage $language) {
    /** @var array<string, string> $values */
    /** @var array<string, mixed> $body */
    User::query()->where('email', 'owner@luna.example')->update(['locale' => $language->value]);

    if (isset($body['user_id']) && $body['user_id'] === 'pier') {
        $body['user_id'] = User::query()->where('email', 'owner@pier.example')->sole()->id;
    }

    // Every refusal is a key of staff.php read through the translator, the lengths filled from
    // the columns.
    staffWrite($this, staffOwner(), 'PUT', '/staff/'.elena(), $body)->assertStatus(400)->assertJsonPath('code', 'invalid_request')->assertJsonPath('data.detail', adminLine($sentence, $values, $language));
    expect(DB::table('tenant_luna.staff')->where('id', elena())->value('name'))->toBe('Elena Papadaki')->and(count(patternWindowsOf(elena())))->toBe(10);
})->with([
    'user of another tenant' => [['user_id' => 'pier'], 'admin/staff.validation.user_exists', []],
    'unknown user' => [['user_id' => 999999], 'admin/staff.validation.user_exists', []],
    'user not a number' => [['user_id' => 'owner'], 'admin/staff.validation.user_id', []],
    'blank name' => [['name' => '  '], 'admin/staff.validation.name', []],
    'bad email' => [['email' => 'not-an-address'], 'admin/staff.validation.email', []],
    'unknown status' => [['status' => 'away'], 'admin/staff.validation.status', []],
    'online not a flag' => [['online' => 'sometimes'], StaffRequest::ONLINE_FLAG, []],
    'title past its longest' => [['title' => str_repeat('a', StaffRequest::TITLE_MAX + 1)], StaffRequest::TITLE_LENGTH, ['n' => (string) StaffRequest::TITLE_MAX]],
    'title not text' => [['title' => ['Senior']], StaffRequest::TITLE_TEXT, []],
    'bio past its longest' => [['bio' => str_repeat('a', StaffRequest::BIO_MAX + 1)], StaffRequest::BIO_LENGTH, ['n' => (string) StaffRequest::BIO_MAX]],
    'bio not text' => [['bio' => 7], StaffRequest::BIO_TEXT, []],
    'service by name' => [['service_ids' => ['Haircut']], StaffRequest::SERVICE_ID, []],
    'service not in the catalog' => [['service_ids' => [999999]], StaffServicesController::UNKNOWN_SERVICE, []],
    'a week, from a page open since before Shifts set the hours' => [['name' => 'Elena P.', 'rules' => [['weekday' => 2, 'start' => '09:00', 'end' => '13:00']]], StaffRequest::RULES_ELSEWHERE, []],
    'an empty week' => [['rules' => []], StaffRequest::RULES_ELSEWHERE, []],
])->with(['a user at en' => [SiteLanguage::English], 'a user at el' => [SiteLanguage::Greek]]);

it('refuses a new member whose body names a week, saying the hours are set on Shifts, and adds no one', function () {
    staffWrite($this, staffOwner(), 'POST', '/staff', newMember(['rules' => [['weekday' => 1, 'start' => '09:00', 'end' => '12:00']]]))
        ->assertStatus(400)->assertJsonPath('code', 'invalid_request')->assertJsonPath('data.detail', adminLine(StaffRequest::RULES_ELSEWHERE));

    expect(DB::table('tenant_luna.staff')->where('name', 'Maria Ioannou')->exists())->toBeFalse();
});

it('edits the implicit member as an ordinary row, the name written, its week left as Opening hours set it and the flag kept on the one row', function () {
    $implicit = implicitStaffId('tenant_luna');

    staffWrite($this, staffOwner(), 'PUT', '/staff/'.$implicit, ['name' => 'Luna Front Desk'])->assertRedirect(tenantUrl('luna', '/admin/staff'));

    expect((array) DB::table('tenant_luna.staff')->where('id', $implicit)->first())->toMatchArray(['name' => 'Luna Front Desk', 'is_implicit' => true, 'status' => 'active'])
        ->and(patternWindowsOf($implicit))->toHaveCount(5)
        ->and(DB::table('tenant_luna.staff')->where('is_implicit', true)->pluck('id')->all())->toBe([$implicit]);
});

it('answers not-found on the create route under the empty entitlement set while the list and a member still render, 6.5', function () {
    app()->instance(EntitlementResolver::class, new class implements EntitlementResolver
    {
        public function resolve(Tenant $tenant): Entitlements
        {
            return Entitlements::none();
        }
    });
    $owner = staffOwner();

    $this->actingAs($owner)->get(tenantUrl('luna', '/admin/staff/new'))->assertNotFound();
    $this->actingAs($owner)->get(tenantUrl('luna', '/admin/staff'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component(StaffController::PAGE)->where('entitlements', [])->where('view', ['kind' => 'list'])->has('staff', 3));
    $this->actingAs($owner)->get(tenantUrl('luna', '/admin/staff/'.elena()))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component(StaffController::PAGE)->where('view', ['kind' => 'detail', 'id' => elena()])->has('staff', 3));
});

it('writes the online flag on create and on edit, a new member offered online unless told otherwise, and keeps the member on the roster either way', function () {
    $owner = staffOwner();

    staffWrite($this, $owner, 'POST', '/staff', newMember(['name' => 'Off the page', 'online' => false]));
    staffWrite($this, $owner, 'POST', '/staff', newMember(['name' => 'On the page']));
    staffWrite($this, $owner, 'PUT', '/staff/'.elena(), ['online' => false])->assertRedirect(tenantUrl('luna', '/admin/staff'));

    expect(DB::table('tenant_luna.staff')->where('name', 'Off the page')->value('online'))->toBeFalse()
        ->and(DB::table('tenant_luna.staff')->where('name', 'On the page')->value('online'))->toBeTrue()
        ->and(DB::table('tenant_luna.staff')->where('id', elena())->first(['online', 'status']))->toEqual((object) ['online' => false, 'status' => 'active']);

    $this->actingAs($owner)->get(tenantUrl('luna', '/admin/staff'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('staff.1.online', false)->where('staff.1.name', 'Elena Papadaki'));
});

it('patches the status alone as the remedy of 9.2, wording the flash for it', function () {
    staffWrite($this, staffOwner(), 'PUT', '/staff/'.elena(), ['status' => 'inactive'])->assertRedirect(tenantUrl('luna', '/admin/staff'));
    expect(session('flash'))->toBe(['kind' => 'success', 'message' => adminLine(StaffController::DEACTIVATED)])
        ->and(DB::table('tenant_luna.staff')->where('id', elena())->value('status'))->toBe('inactive')
        ->and(count(patternWindowsOf(elena())))->toBe(10);
});

it('refuses to delete a member a booking\'s item names, 409 referenced, and deletes an unbooked one with their pattern and links', function () {
    LunaFixture::booking('cancelled', service: 'Haircut');
    $elena = elena();

    staffWrite($this, staffOwner(), 'DELETE', '/staff/'.$elena)->assertStatus(409)->assertJsonPath('code', StaffReferenced::CODE)->assertJsonPath('data.detail', adminLine(StaffController::REFERENCED));
    expect(DB::table('tenant_luna.staff')->where('id', $elena)->exists())->toBeTrue();

    $nikos = nikos();
    staffWrite($this, staffOwner(), 'DELETE', '/staff/'.$nikos)->assertRedirect(tenantUrl('luna', '/admin/staff'));
    expect(session('flash'))->toBe(['kind' => 'success', 'message' => adminLine(StaffController::DELETED)])
        ->and(DB::table('tenant_luna.staff')->where('id', $nikos)->exists())->toBeFalse()
        ->and(DB::table('tenant_luna.shift_patterns')->where('staff_id', $nikos)->exists())->toBeFalse()
        ->and(DB::table('tenant_luna.service_staff')->where('staff_id', $nikos)->exists())->toBeFalse()
        ->and(DB::table('tenant_luna.blackouts')->where('staff_id', $nikos)->exists())->toBeFalse();
    staffWrite($this, staffOwner(), 'DELETE', '/staff/'.$nikos)->assertNotFound();
});

it('refuses to delete the implicit member no booking names, 409 implicit, so the one row keeping the flag survives with its pattern', function () {
    $implicit = implicitStaffId('tenant_luna');
    expect(DB::table('tenant_luna.booking_items')->where('staff_id', $implicit)->exists())->toBeFalse();

    staffWrite($this, staffOwner(), 'DELETE', '/staff/'.$implicit)->assertStatus(409)->assertJsonPath('code', ImplicitStaffKept::CODE)->assertJsonPath('data.detail', adminLine(StaffController::IMPLICIT));

    expect(DB::table('tenant_luna.staff')->where('is_implicit', true)->pluck('id')->all())->toBe([$implicit])
        ->and(count(patternWindowsOf($implicit)))->toBe(5)
        ->and(DB::table('tenant_luna.staff')->count())->toBe(3);
});

it('empties a Monday for every member on the next public read after a tenant-wide closure, for one member alone after a personal one, and removes each closure by date and window presence, never by id', function () {
    $owner = staffOwner();
    $monday = '2026-12-07';
    expect(haircutStarts($this, $monday))->not->toBe([]);

    staffWrite($this, $owner, 'POST', '/blackouts', ['date' => $monday, 'reason' => 'Stocktake'])->assertRedirect(tenantUrl('luna', '/admin/staff'));
    expect(session('flash'))->toBe(['kind' => 'success', 'message' => adminLine(BlackoutController::ADDED)])
        ->and(haircutStarts($this, $monday))->toBe([])
        ->and(haircutStarts($this, $monday, elena()))->toBe([]);

    // A partial closure on the same date beside the whole one; the removal by date alone takes the
    // whole one and leaves the partial.
    staffWrite($this, $owner, 'POST', '/blackouts', ['date' => $monday, 'start_time' => '09:00', 'end_time' => '10:00'])->assertRedirect(tenantUrl('luna', '/admin/staff'));
    staffWrite($this, $owner, 'DELETE', '/blackouts', ['date' => $monday, 'id' => 999999])->assertRedirect(tenantUrl('luna', '/admin/staff'));
    expect(session('flash'))->toBe(['kind' => 'success', 'message' => adminLine(BlackoutController::REMOVED)])
        ->and(DB::table('tenant_luna.blackouts')->whereNull('staff_id')->where('date', $monday)->get()->map(fn (object $row): ?string => is_string($row->start_time) ? substr($row->start_time, 0, 5) : null)->all())->toBe(['09:00'])
        ->and(haircutStarts($this, $monday, elena()))->not->toContain('2026-12-07T07:00:00+00:00')
        ->and(haircutStarts($this, $monday, elena()))->toContain('2026-12-07T08:00:00+00:00');

    staffWrite($this, $owner, 'DELETE', '/blackouts', ['date' => $monday, 'start_time' => '09:00', 'end_time' => '10:00'])->assertRedirect(tenantUrl('luna', '/admin/staff'));
    expect(DB::table('tenant_luna.blackouts')->whereNull('staff_id')->where('date', $monday)->exists())->toBeFalse();
    staffWrite($this, $owner, 'DELETE', '/blackouts', ['date' => $monday])->assertNotFound();

    // A personal closure empties the day for that member alone: on a Tuesday, where Nikos works
    // too, his starts remain.
    $tuesday = '2026-12-08';
    staffWrite($this, $owner, 'POST', '/staff/'.elena().'/blackouts', ['date' => $tuesday])->assertRedirect(tenantUrl('luna', '/admin/staff'));
    expect(haircutStarts($this, $tuesday, elena()))->toBe([])
        ->and(haircutStarts($this, $tuesday, nikos()))->not->toBe([])
        ->and(haircutStarts($this, $tuesday))->not->toBe([])
        ->and(DB::table('tenant_luna.blackouts')->where('staff_id', elena())->where('date', $tuesday)->exists())->toBeTrue();
    staffWrite($this, $owner, 'DELETE', '/staff/'.elena().'/blackouts', ['date' => $tuesday])->assertRedirect(tenantUrl('luna', '/admin/staff'));
    expect(haircutStarts($this, $tuesday, elena()))->not->toBe([]);
    staffWrite($this, $owner, 'POST', '/staff/999999/blackouts', ['date' => $tuesday])->assertNotFound();
});

it('refuses a closure with a bad field in its own sentence, in the user\'s language', function (array $body, string $sentence, SiteLanguage $language) {
    /** @var array<string, mixed> $body */
    User::query()->where('email', 'owner@luna.example')->update(['locale' => $language->value]);
    staffWrite($this, staffOwner(), 'POST', '/blackouts', $body)->assertStatus(400)->assertJsonPath('data.detail', adminLine($sentence, [], $language));
    expect(DB::table('tenant_luna.blackouts')->count())->toBe(2);
})->with([
    'no date' => [['reason' => 'x'], 'admin/staff.validation.closure_date'],
    'date in words' => [['date' => 'Christmas'], 'admin/staff.validation.closure_date'],
    'start alone' => [['date' => '2026-12-24', 'start_time' => '09:00'], 'admin/staff.validation.closure_window'],
    'reason not words' => [['date' => '2026-12-24', 'reason' => ['x']], 'admin/staff.validation.closure_reason'],
    'end before start' => [['date' => '2026-12-24', 'start_time' => '15:00', 'end_time' => '14:00'], BlackoutRequest::WINDOW_ORDER],
])->with(['a user at en' => [SiteLanguage::English], 'a user at el' => [SiteLanguage::Greek]]);
