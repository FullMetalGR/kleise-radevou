<?php

use App\Application\Clock;
use App\Billing\EntitlementResolver;
use App\Billing\Entitlements;
use App\Billing\StoredEntitlementResolver;
use App\Http\AdminSession;
use App\Http\Middleware\HandleInertiaRequests;
use App\Infrastructure\Db\BookingRecords;
use App\Infrastructure\Db\SettingsReader;
use App\Infrastructure\Db\SqlText;
use App\Infrastructure\Db\WizardCatalog;
use App\Models\User;
use App\Tenancy\Tenant;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FixedClock;
use Tests\Support\LunaFixture;
use Tests\Support\StartInterval;

pest()->use(RefreshDatabase::class);

/*
 * GET /admin/api/availability: the one admin JSON endpoint, answering the public availability
 * route's shape byte for byte for the same chain and window, its 400 sentences identical, over
 * the session, for manageBookings or viewOwnCalendar, never gated by the subscription and
 * carrying no limit. The surface test names it as the one route under /admin/api.
 *
 * The last test is about proportion: the wizard's catalog is a shared prop, read when a page
 * is rendered for a user who may book by hand and not on a request that renders none, this
 * read among them, which the wizard makes on every day change while holding the catalog its
 * page gave it.
 */

beforeEach(function () {
    $this->withoutVite();
    seedDemo();
    app()->instance(Clock::class, FixedClock::at('2026-11-02 06:00:00'));
});

/**
 * @param  array<string, mixed>  $query
 */
function adminAvailabilityQuery(array $query = []): string
{
    return http_build_query([
        'items' => (string) json_encode([['service_id' => LunaFixture::id('services', 'Haircut'), 'staff_id' => null], ['service_id' => LunaFixture::id('services', 'Colour'), 'staff_id' => LunaFixture::id('staff', 'Elena Papadaki')]]),
        'from' => '2026-11-03',
        'to' => '2026-11-05',
        'same_staff' => 'true',
        ...$query,
    ]);
}

/**
 * The first statement a closure runs, as the listener sees its SQL.
 */
function firstStatementOf(Closure $run): string
{
    $seen = new class
    {
        /** @var list<string> */
        public array $sql = [];
    };
    DB::listen(function (QueryExecuted $query) use ($seen): void {
        $seen->sql[] = $query->sql;
    });
    $run();

    return $seen->sql[0] ?? throw new LogicException('The closure ran no statement.');
}

function adminAvailabilityUser(string $email = 'owner@luna.example'): User
{
    return User::query()->where('email', $email)->sole();
}

it('answers the public route\'s shape byte for byte for the same chain and window, no-store, and requires a session: a JSON caller without one is answered 401 as the envelope and never sent to an HTML page', function () {
    StartInterval::set('tenant_luna', 5);
    $query = adminAvailabilityQuery();
    // The guests first: actingAs holds for the rest of the test. The wizard asks for JSON and
    // reads what it is answered, so a lapsed session is a sentence it can show; a browser
    // asking for a page is sent to the sign-in page as any admin visit is.
    $this->getJson(tenantUrl('luna', '/admin/api/availability?'.$query))
        ->assertStatus(401)
        ->assertExactJson(['code' => 'unauthenticated', 'message' => 'unauthenticated', 'data' => ['status' => 401, 'detail' => apiSentence('api.status.unauthenticated')]]);
    $this->get(tenantUrl('luna', '/admin/api/availability?'.$query))->assertRedirect(tenantUrl('luna', '/admin/login'));
    $public = $this->getJson(tenantUrl('luna', '/api/v1/availability?'.$query))->assertOk();
    $admin = $this->actingAs(adminAvailabilityUser())->getJson(tenantUrl('luna', '/admin/api/availability?'.$query))->assertOk();

    expect($admin->getContent())->toBe($public->getContent())
        ->and($admin->json('2026-11-03'))->not->toBe([])
        ->and($admin->headers->get('Cache-Control'))->toContain('no-store');
});

it('answers an empty window with its days alone, while the public read names the next day holding a start beside them', function () {
    $query = adminAvailabilityQuery(['from' => '2026-11-08', 'to' => '2026-11-09', 'same_staff' => 'false']);
    $public = $this->getJson(tenantUrl('luna', '/api/v1/availability?'.$query))->assertOk();
    $admin = $this->actingAs(adminAvailabilityUser())->getJson(tenantUrl('luna', '/admin/api/availability?'.$query))->assertOk();

    // 2026-11-08 is a Sunday, when nobody at luna works.
    $admin->assertExactJson(['2026-11-08' => []]);
    $public->assertJsonPath('2026-11-08', [])->assertJsonPath('next_available_from', '2026-11-09');
});

it('answers every start on the granularity grid, not only those on the start interval: the public read at the default 15 answers the quarter hours among them', function () {
    $query = adminAvailabilityQuery();
    $public = $this->getJson(tenantUrl('luna', '/api/v1/availability?'.$query))->assertOk()->json('2026-11-03');
    $admin = $this->actingAs(adminAvailabilityUser())->getJson(tenantUrl('luna', '/admin/api/availability?'.$query))->assertOk()->json('2026-11-03');

    assert(is_array($public) && is_array($admin));

    // Athens is a whole number of hours from UTC, so the minute the wire spells is the minute
    // on the tenant's wall clock.
    $minute = fn (mixed $start): int => is_string($start) ? (int) substr($start, 14, 2) : -1;

    expect(DB::table('tenant_luna.settings')->where('id', 1)->value('start_interval_min'))->toBe(15)
        ->and(array_filter($admin, fn (mixed $start): bool => $minute($start) % 15 !== 0))->not->toBe([])
        ->and($public)->not->toBe([])
        ->and($public)->toBe(array_values(array_filter($admin, fn (mixed $start): bool => $minute($start) % 15 === 0)));
});

it('offers the admin every future start inside the lead time and on days past the horizon, which bind customers alone, for the signed-in user and for support alike, while the public read hides them', function () {
    DB::table('tenant_luna.services')->where('name', 'Haircut')->update(['lead_time_min' => 300, 'horizon_days' => 3]);
    // The clock reads 08:00 in Athens on Monday 2026-11-02: the lead time of five hours reaches
    // 13:00, and the horizon of three days ends on the Thursday.
    $haircut = fn (array $window): string => http_build_query(['items' => (string) json_encode([['service_id' => LunaFixture::id('services', 'Haircut'), 'staff_id' => LunaFixture::id('staff', 'Elena Papadaki')]]), 'same_staff' => 'false', ...$window]);
    $today = $haircut(['from' => '2026-11-02', 'to' => '2026-11-03']);
    $later = $haircut(['from' => '2026-11-10', 'to' => '2026-11-11']);

    $admin = $this->actingAs(adminAvailabilityUser())->getJson(tenantUrl('luna', '/admin/api/availability?'.$today))->assertOk()->json('2026-11-02');
    $adminLater = $this->getJson(tenantUrl('luna', '/admin/api/availability?'.$later))->assertOk()->json('2026-11-10');
    auth()->logout();
    $public = $this->getJson(tenantUrl('luna', '/api/v1/availability?'.$today))->assertOk()->json('2026-11-02');
    $publicLater = $this->getJson(tenantUrl('luna', '/api/v1/availability?'.$later))->assertOk()->json('2026-11-10');

    $now = app(Clock::class)->now();
    $session = DB::table('support_sessions')->insertGetId([
        'operator_id' => addOperator()->id,
        'tenant_id' => LunaFixture::tenant()->id,
        'opened_at' => SqlText::instant($now),
        'expires_at' => SqlText::instant($now->add(new DateInterval('PT60M'))),
        'created_at' => SqlText::instant($now),
    ]);
    $support = $this->withSession([AdminSession::SUPPORT_KEY => $session])->getJson(tenantUrl('luna', '/admin/api/availability?'.$today))->assertOk()->json('2026-11-02');

    assert(is_array($admin) && is_array($adminLater) && is_array($public) && is_array($publicLater));

    // 08:00Z is 10:00 in Athens, inside the lead time; 07:00Z, 09:00, is the first start of
    // Elena's day; 06:00Z, now, is before she opens.
    expect($admin)->toContain('2026-11-02T08:00:00+00:00')
        ->and($admin[0])->toBe('2026-11-02T07:00:00+00:00')
        ->and($support)->toBe($admin)
        ->and($adminLater)->toContain('2026-11-10T08:00:00+00:00')
        ->and($public)->not->toContain('2026-11-02T08:00:00+00:00')
        ->and($public[0] ?? null)->toBe('2026-11-02T12:00:00+00:00')
        ->and($publicLater)->toBe([]);
});

it('refuses a bad query with the public route\'s 400 envelope, sentence for sentence', function () {
    foreach ([['to' => '2026-11-01'], ['items' => 'not json'], ['items' => (string) json_encode([['service_id' => 999999, 'staff_id' => null]])]] as $bad) {
        $query = adminAvailabilityQuery($bad);
        $public = $this->getJson(tenantUrl('luna', '/api/v1/availability?'.$query))->assertStatus(400);
        $admin = $this->actingAs(adminAvailabilityUser())->getJson(tenantUrl('luna', '/admin/api/availability?'.$query))->assertStatus(400);

        expect($admin->getContent())->toBe($public->getContent())
            ->and($admin->json('code'))->toBe('invalid_request')
            ->and($admin->json('data.detail'))->toBeString()->not->toBe('');
    }
});

it('admits manageBookings and viewOwnCalendar, and refuses neither on a past_due tenant', function () {
    addTenantUser('staff@luna.example', 'staff');
    DB::table('tenants')->where('slug', 'luna')->update(['subscription_state' => 'past_due']);
    $query = adminAvailabilityQuery();

    $this->actingAs(adminAvailabilityUser())->getJson(tenantUrl('luna', '/admin/api/availability?'.$query))->assertOk();
    $this->actingAs(adminAvailabilityUser('staff@luna.example'))->getJson(tenantUrl('luna', '/admin/api/availability?'.$query))->assertOk();
});

it('carries no catalog and runs none of the catalog\'s statements, while a page rendered for a user who may book by hand runs them and carries the catalog, and a staff page carries null', function () {
    $luna = Tenant::query()->where('slug', 'luna')->sole();
    $statements = [];
    DB::listen(function (QueryExecuted $query) use (&$statements): void {
        $statements[] = $query->sql;
    });
    $catalog = app(WizardCatalog::class)->read($luna);
    $own = $statements;
    $query = adminAvailabilityQuery();

    expect($own)->toHaveCount(3)
        ->and($catalog['services'])->not->toBe([]);

    $statements = [];
    $answer = $this->actingAs(adminAvailabilityUser())->getJson(tenantUrl('luna', '/admin/api/availability?'.$query))->assertOk();

    expect(array_values(array_intersect($statements, $own)))->toBe([])
        ->and($answer->json())->not->toHaveKey('catalog');

    $statements = [];
    $this->actingAs(adminAvailabilityUser())->get(tenantUrl('luna', '/admin/bookings'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('catalog', $catalog));

    expect(array_values(array_intersect($own, $statements)))->toBe($own);

    addTenantUser('staff@luna.example', 'staff');
    $statements = [];
    $this->actingAs(adminAvailabilityUser('staff@luna.example'))->get(tenantUrl('luna', '/admin/calendar'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('catalog', null));

    expect(array_values(array_intersect($statements, $own)))->toBe([]);
});

it('runs no statement for the entitlements, the settings or the pending count on the availability read, an action\'s redirect or a partial reload naming none of them, while a page render runs each once', function () {
    $luna = Tenant::query()->where('slug', 'luna')->sole();
    $resolves = 0;
    app()->instance(EntitlementResolver::class, new class($resolves) implements EntitlementResolver
    {
        public function __construct(private int &$resolves) {}

        public function resolve(Tenant $tenant): Entitlements
        {
            $this->resolves++;

            return (new StoredEntitlementResolver)->resolve($tenant);
        }
    });
    $settings = firstStatementOf(fn () => app(SettingsReader::class)->read($luna));
    $pending = firstStatementOf(fn () => app(BookingRecords::class)->pendingCount($luna));
    $statements = [];
    DB::listen(function (QueryExecuted $query) use (&$statements): void {
        $statements[] = $query->sql;
    });
    $counts = function () use (&$statements, &$resolves, $settings, $pending): array {
        $counted = [count(array_keys($statements, $settings, true)), count(array_keys($statements, $pending, true)), $resolves];
        $statements = [];
        $resolves = 0;

        return $counted;
    };
    $query = adminAvailabilityQuery();

    $this->getJson(tenantUrl('luna', '/api/v1/availability?'.$query))->assertOk();
    [$publicSettings] = $counts();
    $this->actingAs(adminAvailabilityUser())->getJson(tenantUrl('luna', '/admin/api/availability?'.$query))->assertOk();
    // One settings read fewer than the public read, which reads the row again for the start
    // interval that this read does not apply.
    expect($counts())->toBe([$publicSettings - 1, 0, 0]);

    $this->actingAs(adminAvailabilityUser())->from(tenantUrl('luna', '/admin/staff'))->post(tenantUrl('luna', '/admin/blackouts'), ['date' => '2026-12-25'])
        ->assertRedirect(tenantUrl('luna', '/admin/staff'));
    expect($counts())->toBe([0, 0, 0]);

    $version = (string) app(HandleInertiaRequests::class)->version(request());
    $this->actingAs(adminAvailabilityUser())->get(tenantUrl('luna', '/admin/services'), ['X-Inertia' => 'true', 'X-Inertia-Version' => $version, 'X-Inertia-Partial-Component' => 'Services', 'X-Inertia-Partial-Data' => 'roster'])
        ->assertOk()
        ->assertJsonMissingPath('props.settings')
        ->assertJsonMissingPath('props.entitlements')
        ->assertJsonMissingPath('props.nav');
    expect($counts())->toBe([0, 0, 0]);

    $this->actingAs(adminAvailabilityUser())->get(tenantUrl('luna', '/admin/services'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('entitlements', ['staff'])->has('settings.granularity')->has('nav', 11));
    expect($counts())->toBe([1, 1, 1]);
});
