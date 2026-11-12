<?php

use App\Application\Clock;
use App\Application\Notification\PlatformMessenger;
use App\Http\AdminProps;
use App\Http\AdminSession;
use App\Http\ConsoleSession;
use App\Http\Controllers\Admin\SupportController as OpenController;
use App\Http\Controllers\Console\SupportEndController;
use App\Http\Middleware\KeepLinkTokensOutOfTheSession;
use App\Platform\Operator;
use App\Platform\SupportSessions;
use App\Site\SiteLanguage;
use App\Tenancy\Tenant;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FixedClock;
use Tests\Support\FixtureDay;
use Tests\Support\LunaFixture;
use Tests\Support\RecordingPlatformMessages;
use Tests\Support\SeamLog;
use Tests\TestCase;

pest()->use(DatabaseTruncation::class);

/*
 * The support session opened. The operator presses Open as support on a tenant's detail: the
 * console writes a support_sessions row with the sha256 of a one-time token and an expiry sixty
 * minutes from the clock's now, records support_opened in the same transaction, and answers a
 * location to the tenant host's link, the token answered once and kept nowhere else. The tenant
 * host's link claims the row in one statement, unopened, unended, unexpired and pressed within five
 * minutes, at its own tenant alone; it ends whatever admin session the browser held, regenerates
 * the session with the row's id under the support key and lands on the calendar, where the
 * principal is support. Every other token answers the sign-in page and opens nothing.
 *
 * The principal re-reads the row once per request: ended, or expired by the clock's now, the key is
 * forgotten and the sign-in page shown. Billing is refused as a manager is refused, 403, and absent
 * from the rail; support holds no viewOwnCalendar, so the Calendar it opens through viewBookings
 * offers no Me, and My calendar's retired address redirects it there as it does anybody; every
 * other screen and action is the owner's, and a write stamps the support actor in the trail. The
 * tenant is not emailed.
 *
 * The end: the admin's Sign out, a sign-in in the same browser and a second support link opened
 * there each end the session the browser held, and the console's End ends one from the tenant's
 * detail, which lists the tenant's open sessions; each writes ended_at as the clock's now and
 * records support_ended with the tenant in one transaction, and a second end is refused. The sixty
 * minutes end a session by themselves and record nothing. A route keeps the controller it resolved,
 * and the clock built into it, for the rest of the test, so the file's one way to move the clock,
 * openingMoveClock, forgets every resolved controller as it moves it, and a case pins that a route
 * visited before the move reads the moved clock after it.
 *
 * The cancel enters the transaction runner, so the file truncates and seeds the demo afresh,
 * luna and pier with their owners. The clock is pinned, FixtureDay's 26 hours before the
 * fixture's start, and moved where a case reads a lapse: the press writes every instant of the
 * row from the clock and the claim and the re-read compare against the clock alone.
 */

beforeEach(function () {
    $this->withoutVite();
    dropDemoSchemas();
    seedDemo();
    FixtureDay::pinClock();
    $this->seam = new RecordingPlatformMessages;
    app()->instance(PlatformMessenger::class, $this->seam);
});

afterEach(function () {
    dropDemoSchemas();
    DB::table('support_sessions')->delete();
    DB::table('platform_audit_log')->delete();
    DB::table('operators')->delete();
    DB::table('subscriptions')->delete();
    DB::table('users')->delete();
    DB::table('tenants')->delete();
});

/**
 * The console's press for the tenant given, as the detail's Open as support sends it: an Inertia
 * visit unless the case asks for a plain request.
 *
 * @return TestResponse<Response>
 */
function openingPress(TestCase $test, Operator $operator, string $slug = 'luna', bool $inertia = true): TestResponse
{
    return $test->actingAs($operator, ConsoleSession::GUARD)
        ->withSession(freshConsoleSession())
        ->from(consoleUrl('/tenants/'.$slug))
        ->post(consoleUrl('/tenants/'.$slug.'/support'), [], $inertia ? ['X-Inertia' => 'true'] : []);
}

/**
 * The clear token the press's location carries, the empty string where it carries none.
 *
 * @param  TestResponse<Response>  $response
 */
function openingTokenOf(TestResponse $response, string $slug = 'luna'): string
{
    $location = (string) ($response->headers->get('X-Inertia-Location') ?? $response->headers->get('Location'));

    return preg_match('~\A'.preg_quote(tenantUrl($slug, '/admin/support/'), '~').'([0-9a-f]{64})\z~', $location, $match) === 1 ? $match[1] : '';
}

/**
 * Presses Open as support on the tenant given and answers the token of the link it answered.
 */
function openingLink(TestCase $test, Operator $operator, string $slug = 'luna'): string
{
    $token = openingTokenOf(openingPress($test, $operator, $slug)->assertStatus(409), $slug);

    expect($token)->not->toBe('');

    return $token;
}

/**
 * The browser crossing to the tenant's host, which presents a cookie of its own: the session the
 * console's requests wrote is not the tenant's, and the admin's guard is the web one.
 */
function openingOnTheTenantHost(TestCase $test): void
{
    $test->flushSession();
    restoreWebGuard();
}

/**
 * Opens the link on the tenant's host, as the browser does after the location.
 *
 * @return TestResponse<Response>
 */
function openingVisit(TestCase $test, string $token, string $slug = 'luna'): TestResponse
{
    return $test->get(tenantUrl($slug, '/admin/support/'.$token));
}

/**
 * Every support_sessions row with its instants in UTC to the second.
 *
 * @return list<array<mixed>>
 */
function openingRows(): array
{
    $rows = [];

    foreach (DB::select(
        "select id, operator_id, tenant_id, token_hash,
            to_char(opened_at at time zone 'UTC', 'YYYY-MM-DD HH24:MI:SS') as opened_at,
            to_char(expires_at at time zone 'UTC', 'YYYY-MM-DD HH24:MI:SS') as expires_at,
            to_char(ended_at at time zone 'UTC', 'YYYY-MM-DD HH24:MI:SS') as ended_at,
            to_char(created_at at time zone 'UTC', 'YYYY-MM-DD HH24:MI:SS') as created_at
         from support_sessions order by id",
    ) as $row) {
        $rows[] = (array) $row;
    }

    return $rows;
}

/**
 * Every platform audit row as its operator, action, tenant and payload.
 *
 * @return list<array<mixed>>
 */
function openingAudit(): array
{
    $rows = [];

    foreach (DB::select('select operator_id, action, tenant_id, payload::text as payload from platform_audit_log order by id') as $row) {
        $rows[] = (array) $row;
    }

    return $rows;
}

/**
 * The tenant row of the slug given.
 */
function openingTenant(string $slug): Tenant
{
    return Tenant::query()->where('slug', $slug)->sole();
}

/**
 * Asserts the answer every link that opens nothing reads: the sign-in page with the sentence as
 * an error, and no support session in the browser's.
 *
 * @param  TestResponse<Response>  $response
 */
function openingRefused(TestResponse $response, string $slug = 'luna'): void
{
    $response->assertRedirect(tenantUrl($slug, '/admin/login'))
        ->assertSessionHas('flash', ['kind' => 'error', 'message' => adminLine(OpenController::INVALID)])
        ->assertSessionMissing(AdminSession::SUPPORT_KEY);
}

/**
 * Moves the bound clock by the elapsed time given from where it stands, and forgets the controller
 * every route has resolved, as the next request's process would: a route keeps the controller it
 * first resolved, and the clock built into it, for the rest of the test, so without the forgetting
 * a request to a route already visited would read the clock from before the move. The next request
 * resolves its controller through the container on the moved one.
 */
function openingMoveClock(string $by): void
{
    app()->instance(Clock::class, new FixedClock(app(Clock::class)->now()->add(new DateInterval($by))));
    freshRouteControllers();
}

/**
 * Opens a support session on the tenant given as the browser does, the press, the crossing to
 * the tenant's host and the link, and answers the row's id: the browser is then on the tenant's
 * host as support.
 */
function endingOpened(TestCase $test, Operator $operator, string $slug = 'luna'): int
{
    $token = openingLink($test, $operator, $slug);
    openingOnTheTenantHost($test);
    openingVisit($test, $token, $slug)->assertRedirect(tenantUrl($slug, '/admin'));

    $id = session(AdminSession::SUPPORT_KEY);
    assert(is_int($id));

    return $id;
}

/**
 * The console's End on the session the id names, as the detail's control sends it from the
 * tenant's detail, pressed by the operator given; the browser's next request to a tenant's host
 * is the admin's guard's again.
 *
 * @return TestResponse<Response>
 */
function endingPress(TestCase $test, Operator $operator, int|string $id, string $slug = 'luna', bool $inertia = true): TestResponse
{
    $response = $test->actingAs($operator, ConsoleSession::GUARD)
        ->withSession(freshConsoleSession())
        ->from(consoleUrl('/tenants/'.$slug))
        ->post(consoleUrl('/support/'.$id.'/end'), [], $inertia ? ['X-Inertia' => 'true'] : []);
    restoreWebGuard();

    return $response;
}

/**
 * The audit of one session opened and ended by the operator given at the tenant given.
 *
 * @return list<array<string, mixed>>
 */
function endingAudit(Operator $operator, string $slug, ?Operator $endedBy = null): array
{
    $tenant = openingTenant($slug)->id;

    return [
        ['operator_id' => $operator->id, 'action' => 'support_opened', 'tenant_id' => $tenant, 'payload' => '{}'],
        ['operator_id' => ($endedBy ?? $operator)->id, 'action' => 'support_ended', 'tenant_id' => $tenant, 'payload' => '{}'],
    ];
}

/**
 * The labels of the rail a page answered.
 *
 * @param  TestResponse<Response>  $response
 * @return list<string>
 */
function openingRail(TestResponse $response): array
{
    $nav = data_get($response->viewData('page'), 'props.nav');
    assert(is_array($nav));

    return array_values(array_map(fn (mixed $entry): string => is_array($entry) && is_string($entry['label'] ?? null) ? $entry['label'] : '', $nav));
}

it('writes the row on the press, the token\'s sha256 and the expiry sixty minutes from the clock\'s now, records support_opened naming the tenant, and answers the location to the tenant host\'s link', function () {
    $operator = addOperator();

    $pressed = openingPress($this, $operator)->assertStatus(409);
    $token = openingTokenOf($pressed);

    expect($token)->toMatch('/\A[0-9a-f]{64}\z/')
        ->and($pressed->headers->get('X-Inertia-Location'))->toBe(tenantUrl('luna', '/admin/support/'.$token))
        ->and(openingRows())->toBe([[
            'id' => openingRows()[0]['id'] ?? null,
            'operator_id' => $operator->id,
            'tenant_id' => openingTenant('luna')->id,
            'token_hash' => hash('sha256', $token),
            'opened_at' => null,
            'expires_at' => '2026-11-02 07:00:00',
            'ended_at' => null,
            'created_at' => '2026-11-02 06:00:00',
        ]])
        ->and(openingAudit())->toBe([
            ['operator_id' => $operator->id, 'action' => 'support_opened', 'tenant_id' => openingTenant('luna')->id, 'payload' => '{}'],
        ]);
});

it('answers a plain request\'s press with a redirect to the same link', function () {
    $pressed = openingPress($this, addOperator(), inertia: false);

    $pressed->assertRedirect(tenantUrl('luna', '/admin/support/'.openingTokenOf($pressed)));

    expect(openingTokenOf($pressed))->not->toBe('');
});

it('opens a session on a closing row too, whose host resolves as an active one\'s', function () {
    DB::table('tenants')->where('slug', 'luna')->update(['status' => 'closing', 'closing_at' => DB::raw('now()')]);
    $token = openingLink($this, addOperator());
    openingOnTheTenantHost($this);

    openingVisit($this, $token)->assertRedirect(tenantUrl('luna', '/admin'));

    expect(openingRows()[0]['opened_at'] ?? null)->toBe('2026-11-02 06:00:00');
});

it('refuses the press on a {case} row with its sentence, writing nothing', function (string $status, ?string $suspendedAt, string $sentence) {
    DB::table('tenants')->where('slug', 'luna')->update(['status' => $status, 'suspended_at' => $suspendedAt, 'suspension_reason' => $suspendedAt === null ? null : 'Chargebacks under review.']);

    openingPress($this, addOperator())
        ->assertRedirect(consoleUrl('/tenants/luna'))
        ->assertSessionHas('flash', ['kind' => 'error', 'message' => $sentence]);

    expect(openingRows())->toBe([])
        ->and(openingAudit())->toBe([]);
})->with([
    'suspended' => ['suspended', '2026-11-01 09:00:00+00', SupportSessions::SUSPENDED],
    'provisioning' => ['provisioning', null, SupportSessions::PROVISIONING],
]);

it('answers the console\'s 404 for a slug no tenant holds, writing nothing', function () {
    openingPress($this, addOperator(), 'nobody', inertia: false)
        ->assertNotFound()
        ->assertInertia(fn (Assert $page) => $page->component('console/NotFound'));

    expect(openingRows())->toBe([]);
});

it('sends a visitor with no operator session to the door, and writes nothing', function () {
    $this->post(consoleUrl('/tenants/luna/support'))->assertRedirect(consoleUrl('/login'));

    expect(openingRows())->toBe([])
        ->and(openingAudit())->toBe([]);
});

it('writes nothing when the audit row cannot be written after the session row has landed, the row and its record sharing one transaction', function () {
    $operator = addOperator();
    $luna = openingTenant('luna');
    DB::unprepared(<<<'SQL'
        create function opening_refuse_audit() returns trigger language plpgsql as $$ begin raise exception 'the platform audit insert is refused'; end $$;
        create trigger opening_refuse_audit before insert on platform_audit_log for each row execute function opening_refuse_audit();
        SQL);
    $landed = false;
    DB::listen(function (QueryExecuted $query) use (&$landed): void {
        $landed = $landed || str_starts_with($query->sql, 'insert into support_sessions');
    });

    try {
        expect(fn () => app(SupportSessions::class)->open($operator, $luna))->toThrow(QueryException::class, 'the platform audit insert is refused');
    } finally {
        DB::unprepared('drop trigger opening_refuse_audit on platform_audit_log; drop function opening_refuse_audit();');
    }

    expect($landed)->toBeTrue()
        ->and(openingRows())->toBe([])
        ->and(openingAudit())->toBe([]);
});

it('claims the row once on the tenant host and lands on the calendar as the support principal: auth.user named for the operator, the support prop with the end, and every owner permission but manageBilling and viewOwnCalendar', function () {
    $operator = addOperator();
    $token = openingLink($this, $operator);
    openingOnTheTenantHost($this);

    openingVisit($this, $token)->assertRedirect(tenantUrl('luna', '/admin'));

    $row = openingRows()[0] ?? [];

    expect($row)->toMatchArray(['token_hash' => null, 'opened_at' => '2026-11-02 06:00:00', 'ended_at' => null])
        ->and(session(AdminSession::SUPPORT_KEY))->toBe($row['id'] ?? null);

    $this->get(tenantUrl('luna', '/admin'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Home')
            ->where('auth.user', ['id' => null, 'name' => adminLine(AdminProps::SUPPORT_NAME, ['operator' => 'Pat Operator']), 'role' => 'support'])
            ->where('support', ['operator' => 'Pat Operator', 'ends_at' => '2026-11-02T07:00:00+00:00'])
            ->where('permissions', ['viewBookings', 'manageBookings', 'approveBookings', 'manageSettings']));
});

it('opens a link pressed four minutes and fifty-nine seconds before', function () {
    $token = openingLink($this, addOperator());
    openingOnTheTenantHost($this);
    openingMoveClock('PT4M59S');

    openingVisit($this, $token)->assertRedirect(tenantUrl('luna', '/admin'));

    expect(openingRows()[0]['opened_at'] ?? null)->toBe('2026-11-02 06:04:59');
});

it('refuses a second link the moved clock took past its five minutes, though the link\'s route resolved its controller on the clock before the move', function () {
    $first = openingLink($this, addOperator());
    $second = openingLink($this, addOperator('sam@inbox.example', name: 'Sam Operator'));
    openingOnTheTenantHost($this);
    openingVisit($this, $first)->assertRedirect(tenantUrl('luna', '/admin'));
    $resolved = Route::getRoutes()->getByName('admin.support.open')?->controller;

    openingMoveClock('PT5M1S');
    openingOnTheTenantHost($this);

    expect($resolved)->toBeInstanceOf(OpenController::class);
    openingRefused(openingVisit($this, $second));
    expect(array_column(openingRows(), 'opened_at'))->toBe(['2026-11-02 06:00:00', null]);
});

it('answers the sign-in page with the sentence and opens nothing for {case}', function (Closure $arrange) {
    $operator = addOperator();
    $token = openingLink($this, $operator);
    $asked = $arrange($this, $token, $operator);
    assert(is_array($asked) && is_string($asked[0] ?? null) && is_string($asked[1] ?? null));
    [$visit, $slug] = $asked;
    $before = openingRows();
    $audited = openingAudit();
    openingOnTheTenantHost($this);

    openingRefused(openingVisit($this, $visit, $slug), $slug);

    expect(openingRows())->toBe($before)
        ->and(openingAudit())->toBe($audited);

    $this->get(tenantUrl($slug, '/admin'))->assertRedirect(tenantUrl($slug, '/admin/login'));
})->with([
    'a second open of the same link' => [function (TestCase $test, string $token): array {
        openingOnTheTenantHost($test);
        openingVisit($test, $token)->assertRedirect(tenantUrl('luna', '/admin'));

        return [$token, 'luna'];
    }],
    'a link for another tenant' => [fn (TestCase $test, string $token): array => [$token, 'pier']],
    'a lapsed link, the clock moved past the hour' => [function (TestCase $test, string $token): array {
        openingMoveClock('PT61M');

        return [$token, 'luna'];
    }],
    'a lapsed expiry inside the five minutes, the row written so' => [function (TestCase $test, string $token, Operator $operator): array {
        $lapsed = str_repeat('b', 64);
        DB::table('support_sessions')->insert([
            'operator_id' => $operator->id,
            'tenant_id' => openingTenant('luna')->id,
            'token_hash' => hash('sha256', $lapsed),
            'expires_at' => '2026-11-02 05:59:59+00',
            'created_at' => '2026-11-02 05:58:00+00',
        ]);

        return [$lapsed, 'luna'];
    }],
    'a link whose session was ended before it was opened' => [function (TestCase $test, string $token): array {
        DB::table('support_sessions')->update(['ended_at' => '2026-11-02 06:01:00+00']);

        return [$token, 'luna'];
    }],
    'a link pressed more than five minutes before' => [function (TestCase $test, string $token): array {
        openingMoveClock('PT5M1S');

        return [$token, 'luna'];
    }],
    'a tampered token' => [fn (TestCase $test, string $token): array => [substr($token, 0, -1).($token[63] === '0' ? '1' : '0'), 'luna']],
    'a token in capitals' => [fn (TestCase $test, string $token): array => [strtoupper($token), 'luna']],
    'a token too short' => [fn (TestCase $test, string $token): array => [substr($token, 0, 63), 'luna']],
    'a token that is not hex' => [fn (TestCase $test, string $token): array => [str_repeat('z', 64), 'luna']],
]);

it('words a refused link in the tenant default, Greek at a default of el through the translator', function () {
    $token = openingLink($this, addOperator());
    DB::table('tenant_luna.settings')->where('id', 1)->update(['language' => SiteLanguage::Greek->value]);
    openingOnTheTenantHost($this);

    openingVisit($this, substr($token, 0, 63))
        ->assertRedirect(tenantUrl('luna', '/admin/login'))
        ->assertSessionHas('flash', ['kind' => 'error', 'message' => adminLine(OpenController::INVALID, [], SiteLanguage::Greek)])
        ->assertSessionMissing(AdminSession::SUPPORT_KEY);

    expect(adminLine(OpenController::INVALID, [], SiteLanguage::Greek))->not->toBe(adminLine(OpenController::INVALID));
});

it('still opens its own tenant\'s link after a refused try at another tenant and a tampered one', function () {
    $token = openingLink($this, addOperator(), 'pier');
    openingOnTheTenantHost($this);

    openingRefused(openingVisit($this, $token, 'luna'));
    openingRefused(openingVisit($this, substr($token, 0, 63), 'pier'), 'pier');
    openingVisit($this, $token, 'pier')->assertRedirect(tenantUrl('pier', '/admin'));

    expect(openingRows()[0]['tenant_id'] ?? null)->toBe(openingTenant('pier')->id);
});

it('constrains the open\'s token on the route to 64 lowercase hex characters, the link\'s own shape, its malformed sibling taking any other', function () {
    $open = Route::getRoutes()->getByName('admin.support.open');
    $malformed = Route::getRoutes()->getByName('admin.support.malformed');
    assert($open !== null && $malformed !== null);

    expect($open->wheres)->toBe(['token' => SupportSessions::TOKEN])
        ->and(SupportSessions::TOKEN)->toBe('[0-9a-f]{64}')
        ->and($malformed->uri())->toBe('admin/support/{malformed}')
        ->and(openingLink($this, addOperator()))->toMatch('/\A'.SupportSessions::TOKEN.'\z/');
});

it('keeps the link\'s URL out of the session\'s previous-URL slot, on the open and on its malformed sibling alike', function () {
    $token = openingLink($this, addOperator());
    $second = openingLink($this, addOperator('sam@inbox.example'));
    openingOnTheTenantHost($this);
    $this->get(tenantUrl('luna', '/admin/login'))->assertOk();

    openingRefused(openingVisit($this, strtoupper($second)));
    $afterMalformed = (string) session()->previousUrl();
    openingVisit($this, $token)->assertRedirect(tenantUrl('luna', '/admin'));

    expect(KeepLinkTokensOutOfTheSession::ROUTES)->toBe(['my-bookings.open', 'my-bookings.open.act', 'admin.set-password', 'admin.set-password.malformed', 'admin.support.open', 'admin.support.malformed', 'manage', 'pay', 'pay.link', 'api.booking', 'api.booking.availability', 'signup.verify', 'signup.verify.malformed'])
        ->and($afterMalformed)->toBe(tenantUrl('luna', '/admin/login'))
        ->and((string) session()->previousUrl())->toBe(tenantUrl('luna', '/admin/login'));
});

it('keeps the clear token out of every row, log line, flash, session value on either host, audit row, queue and later answer, the hash alone kept', function () {
    $log = new SeamLog;
    Log::swap($log);
    $operator = addOperator();

    $token = openingLink($this, $operator);
    $detail = $this->actingAs($operator, ConsoleSession::GUARD)->get(consoleUrl('/tenants/luna'));
    // The console host's session, the one the press and the detail ran in, read before the
    // browser crosses to the tenant's host and presents that host's cookie instead.
    $console = session()->all();
    openingOnTheTenantHost($this);
    $opened = openingVisit($this, $token);
    $calendar = $this->get(tenantUrl('luna', '/admin'));
    $tenant = session()->all();

    foreach ([$detail, $opened, $calendar] as $answer) {
        expect((string) $answer->getContent())->not->toContain($token)
            ->and((string) $answer->headers)->not->toContain($token);
    }

    $rows = json_encode([DB::table('support_sessions')->get(), DB::table('platform_audit_log')->get(), DB::table('tenants')->get()], JSON_THROW_ON_ERROR);

    expect($rows)->not->toContain($token)
        ->and($console)->toHaveKey(ConsoleSession::SIGNED_IN_AT)
        ->and($console)->not->toHaveKey(AdminSession::SUPPORT_KEY)
        ->and(json_encode($console, JSON_THROW_ON_ERROR))->not->toContain($token)
        ->and($tenant)->toHaveKey(AdminSession::SUPPORT_KEY)
        ->and($tenant)->not->toHaveKey(ConsoleSession::SIGNED_IN_AT)
        ->and(json_encode($tenant, JSON_THROW_ON_ERROR))->not->toContain($token)
        ->and($log->text())->not->toContain($token)
        ->and(DB::table('jobs')->count())->toBe(0);
});

it('mails nobody at the press or the open: the platform\'s seam and the tenant\'s transport record nothing', function () {
    $token = openingLink($this, addOperator());
    openingOnTheTenantHost($this);
    openingVisit($this, $token)->assertRedirect(tenantUrl('luna', '/admin'));
    $this->get(tenantUrl('luna', '/admin'))->assertOk();

    expect($this->seam->sent)->toBe([])
        ->and(seamSent())->toBe([]);
});

it('ends whatever admin session the browser held at the open: a user signed in there is signed out, and does not come back when the support session ends', function () {
    $operator = addOperator();
    $token = openingLink($this, $operator);
    openingOnTheTenantHost($this);
    $this->post(tenantUrl('luna', '/admin/login'), ['email' => 'owner@luna.example', 'password' => 'password'])->assertRedirect(tenantUrl('luna', '/admin'));

    openingVisit($this, $token)->assertRedirect(tenantUrl('luna', '/admin'));

    $this->get(tenantUrl('luna', '/admin'))->assertInertia(fn (Assert $page) => $page->where('auth.user.role', 'support'));
    expect(Auth::guard('web')->check())->toBeFalse();

    DB::table('support_sessions')->update(['ended_at' => '2026-11-02 06:10:00+00']);

    $this->get(tenantUrl('luna', '/admin'))->assertRedirect(tenantUrl('luna', '/admin/login'));
});

it('ends the support session at a sign-in in the same browser, ended_at the clock\'s now and support_ended naming the row\'s operator and tenant, and then answers as the user who signed in', function () {
    $operator = addOperator();
    endingOpened($this, $operator);
    openingMoveClock('PT10M');

    $this->post(tenantUrl('luna', '/admin/login'), ['email' => 'owner@luna.example', 'password' => 'password'])
        ->assertRedirect(tenantUrl('luna', '/admin'))
        ->assertSessionMissing(AdminSession::SUPPORT_KEY);

    expect(openingRows()[0] ?? [])->toMatchArray(['opened_at' => '2026-11-02 06:00:00', 'ended_at' => '2026-11-02 06:10:00'])
        ->and(openingAudit())->toBe(endingAudit($operator, 'luna'));

    $this->get(tenantUrl('luna', '/admin'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('auth.user.role', 'owner')->where('support', null));
});

it('leaves the support session standing at a refused sign-in in the same browser, ending nothing', function () {
    endingOpened($this, addOperator());

    $this->post(tenantUrl('luna', '/admin/login'), ['email' => 'owner@luna.example', 'password' => 'not the password'])
        ->assertSessionHasErrors('email');

    expect(openingRows()[0] ?? [])->toMatchArray(['ended_at' => null])
        ->and(array_column(openingAudit(), 'action'))->toBe(['support_opened']);

    $this->get(tenantUrl('luna', '/admin'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('auth.user.role', 'support'));
});

it('ends the support session at the admin\'s Sign out, ended_at the clock\'s now and support_ended naming the row\'s operator and tenant, the next request the sign-in page', function () {
    $operator = addOperator();
    endingOpened($this, $operator);
    openingMoveClock('PT20M');

    $this->post(tenantUrl('luna', '/admin/logout'))
        ->assertRedirect(tenantUrl('luna', '/admin/login'))
        ->assertSessionMissing(AdminSession::SUPPORT_KEY);

    expect(openingRows()[0] ?? [])->toMatchArray(['opened_at' => '2026-11-02 06:00:00', 'expires_at' => '2026-11-02 07:00:00', 'ended_at' => '2026-11-02 06:20:00'])
        ->and(openingAudit())->toBe(endingAudit($operator, 'luna'));

    $this->get(tenantUrl('luna', '/admin'))->assertRedirect(tenantUrl('luna', '/admin/login'));
});

it('records nothing at a sign-out once the session has lapsed, expiry being no operator\'s act', function () {
    endingOpened($this, addOperator());
    openingMoveClock('PT60M');

    $this->post(tenantUrl('luna', '/admin/logout'))->assertRedirect(tenantUrl('luna', '/admin/login'));

    expect(openingRows()[0] ?? [])->toMatchArray(['ended_at' => null])
        ->and(array_column(openingAudit(), 'action'))->toBe(['support_opened']);
});

it('ends the session a second support link displaces in the same browser, and opens the second', function () {
    $pat = addOperator();
    $sam = addOperator('sam@inbox.example', name: 'Sam Operator');
    $first = endingOpened($this, $pat);
    $second = openingLink($this, $sam);
    restoreWebGuard();

    openingVisit($this, $second)->assertRedirect(tenantUrl('luna', '/admin'));

    $rows = openingRows();
    $luna = openingTenant('luna')->id;

    expect(array_map(fn (array $row): array => [$row['id'], $row['opened_at'], $row['ended_at']], $rows))->toBe([
        [$first, '2026-11-02 06:00:00', '2026-11-02 06:00:00'],
        [$rows[1]['id'] ?? null, '2026-11-02 06:00:00', null],
    ])
        ->and(session(AdminSession::SUPPORT_KEY))->toBe($rows[1]['id'] ?? null)
        ->and(openingAudit())->toBe([
            ['operator_id' => $pat->id, 'action' => 'support_opened', 'tenant_id' => $luna, 'payload' => '{}'],
            ['operator_id' => $sam->id, 'action' => 'support_opened', 'tenant_id' => $luna, 'payload' => '{}'],
            ['operator_id' => $pat->id, 'action' => 'support_ended', 'tenant_id' => $luna, 'payload' => '{}'],
        ]);

    $this->get(tenantUrl('luna', '/admin'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('support.operator', 'Sam Operator'));
});

it('re-reads the row on the next request and drops the principal once it is {case}, forgetting the key, the sign-in page answering', function (Closure $end) {
    $token = openingLink($this, addOperator());
    openingOnTheTenantHost($this);
    openingVisit($this, $token)->assertRedirect(tenantUrl('luna', '/admin'));
    $this->get(tenantUrl('luna', '/admin'))->assertOk();

    $end();

    $this->get(tenantUrl('luna', '/admin'))
        ->assertRedirect(tenantUrl('luna', '/admin/login'))
        ->assertSessionMissing(AdminSession::SUPPORT_KEY);
    $this->get(tenantUrl('luna', '/admin/bookings'))->assertRedirect(tenantUrl('luna', '/admin/login'));
})->with([
    'ended' => [fn () => DB::table('support_sessions')->update(['ended_at' => '2026-11-02 06:30:00+00'])],
    'expired by the clock, sixty minutes from the press' => [fn () => openingMoveClock('PT60M')],
]);

it('stands up to a second before its end', function () {
    $token = openingLink($this, addOperator());
    openingOnTheTenantHost($this);
    openingVisit($this, $token)->assertRedirect(tenantUrl('luna', '/admin'));
    openingMoveClock('PT59M59S');

    $this->get(tenantUrl('luna', '/admin'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('auth.user.role', 'support'));
});

it('reads the support row once for a page, however many readers ask for the principal', function () {
    $token = openingLink($this, addOperator());
    openingOnTheTenantHost($this);
    openingVisit($this, $token)->assertRedirect(tenantUrl('luna', '/admin'));

    $reads = 0;
    DB::listen(function (QueryExecuted $query) use (&$reads): void {
        $reads += str_contains($query->sql, 'support_sessions') ? 1 : 0;
    });

    $this->get(tenantUrl('luna', '/admin/bookings'))->assertOk();

    expect($reads)->toBe(1);
});

it('reads a row of another tenant under the key as nobody, forgetting the key', function () {
    $token = openingLink($this, addOperator(), 'pier');
    openingOnTheTenantHost($this);
    openingVisit($this, $token, 'pier')->assertRedirect(tenantUrl('pier', '/admin'));

    $this->get(tenantUrl('luna', '/admin'))
        ->assertRedirect(tenantUrl('luna', '/admin/login'))
        ->assertSessionMissing(AdminSession::SUPPORT_KEY);
});

it('refuses support the Billing screen and its three writes at 403, leaves Billing off the rail, opens the Calendar with no own row and redirects My calendar there, every other screen the owner\'s', function () {
    $token = openingLink($this, addOperator());
    openingOnTheTenantHost($this);
    openingVisit($this, $token)->assertRedirect(tenantUrl('luna', '/admin'));

    $calendar = $this->get(tenantUrl('luna', '/admin/calendar'))->assertOk();
    $calendar->assertInertia(fn (Assert $page) => $page->component('Calendar')->where('own', null)->where('pinned', false));

    expect(openingRail($calendar))->toBe(array_map(fn (string $entry): string => navLabel($entry), ['home', 'calendar', 'bookings', 'shifts', 'services', 'staff', 'customers', 'reports', 'settings']));

    $this->get(tenantUrl('luna', '/admin/billing'))->assertForbidden();
    $this->get(tenantUrl('luna', '/admin/my-calendar'))->assertRedirect(tenantUrl('luna', '/admin/calendar'));
    $this->post(tenantUrl('luna', '/admin/billing/plan'), ['plan' => 'staff'])->assertForbidden();
    $this->post(tenantUrl('luna', '/admin/billing/portal'))->assertForbidden();
    $this->post(tenantUrl('luna', '/admin/billing/checkout'), ['plan' => 'staff'])->assertForbidden();

    foreach (['/admin/bookings', '/admin/services', '/admin/staff', '/admin/customers', '/admin/reports', '/admin/settings'] as $screen) {
        $this->get(tenantUrl('luna', $screen))->assertOk();
    }
});

it('stamps a cancel made in the session with the support actor, and the dialog\'s trail names the operator', function () {
    $operator = addOperator();
    $uuid = LunaFixture::booking('confirmed');
    $token = openingLink($this, $operator);
    openingOnTheTenantHost($this);
    openingVisit($this, $token)->assertRedirect(tenantUrl('luna', '/admin'));

    $this->from(tenantUrl('luna', '/admin/bookings/'.$uuid))
        ->post(tenantUrl('luna', '/admin/bookings/'.$uuid.'/cancel'))
        ->assertRedirect(tenantUrl('luna', '/admin/bookings/'.$uuid))
        ->assertSessionHas('flash.kind', 'success');

    expect(LunaFixture::row($uuid)['status'])->toBe('cancelled')
        ->and(array_column(LunaFixture::trail(), 'actor'))->toBe(['support:'.$operator->id]);

    $this->get(tenantUrl('luna', '/admin/bookings/'.$uuid))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('detail.trail.0.actor', 'support:'.$operator->id)
            ->where('detail.trail.0.kind', 'support')
            ->where('detail.trail.0.name', 'Pat Operator'));
});

it('names the refusals of the press and the open in their own sentences', function () {
    expect(SupportSessions::SUSPENDED)->toBe('A suspended account cannot be opened as support. Resume it first.')
        ->and(SupportSessions::PROVISIONING)->toBe('An account still being set up cannot be opened as support.')
        ->and(OpenController::INVALID)->toBe('admin/shell.refusals.support_link')
        ->and(adminLine(OpenController::INVALID))->not->toBe(OpenController::INVALID);
});

/*
 * The session's end: sixty minutes from the press by the clock's now, recording nothing; the
 * admin's Sign out and End session, which post the admin's sign-out; and the console's End
 * from the tenant's detail, which lists the tenant's open sessions. Each of the two an
 * operator makes writes ended_at as the clock's now and records support_ended with the tenant
 * in one transaction, and the tenant host's next request is the sign-in page.
 */

it('ends at sixty minutes from the press on a moved clock alone: a second before it the session answers, at it the sign-in page, the row untouched and nothing audited', function () {
    $operator = addOperator();
    endingOpened($this, $operator);
    $opened = openingRows();

    openingMoveClock('PT59M59S');
    $this->get(tenantUrl('luna', '/admin/bookings'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('auth.user.role', 'support'));

    openingMoveClock('PT1S');
    $this->get(tenantUrl('luna', '/admin/bookings'))
        ->assertRedirect(tenantUrl('luna', '/admin/login'))
        ->assertSessionMissing(AdminSession::SUPPORT_KEY);

    expect(openingRows())->toBe($opened)
        ->and(openingRows()[0] ?? [])->toMatchArray(['ended_at' => null])
        ->and(openingAudit())->toBe([
            ['operator_id' => $operator->id, 'action' => 'support_opened', 'tenant_id' => openingTenant('luna')->id, 'payload' => '{}'],
        ]);
});

it('ends an open session from the console: ended_at the clock\'s now, support_ended naming the operator who pressed End and the row\'s tenant, the success flash on the detail, and the tenant host\'s next request the sign-in page', function () {
    $pat = addOperator();
    $sam = addOperator('sam@inbox.example', name: 'Sam Operator');
    $id = endingOpened($this, $pat);
    openingMoveClock('PT25M');

    endingPress($this, $sam, $id)
        ->assertRedirect(consoleUrl('/tenants/luna'))
        ->assertSessionHas('flash', ['kind' => 'success', 'message' => SupportEndController::ENDED]);

    expect(openingRows()[0] ?? [])->toMatchArray(['id' => $id, 'ended_at' => '2026-11-02 06:25:00'])
        ->and(openingAudit())->toBe(endingAudit($pat, 'luna', $sam));

    $this->get(tenantUrl('luna', '/admin'))
        ->assertRedirect(tenantUrl('luna', '/admin/login'))
        ->assertSessionMissing(AdminSession::SUPPORT_KEY);
});

it('refuses the console\'s End on a session {case} as an error flash on the detail, writing nothing', function (Closure $arrange) {
    $operator = addOperator();
    $id = endingOpened($this, $operator);
    $arrange($this, $operator, $id);
    $rows = openingRows();
    $audited = openingAudit();

    endingPress($this, $operator, $id)
        ->assertRedirect(consoleUrl('/tenants/luna'))
        ->assertSessionHas('flash', ['kind' => 'error', 'message' => SupportEndController::ALREADY_ENDED]);

    expect(openingRows())->toBe($rows)
        ->and(openingAudit())->toBe($audited);
})->with([
    'ended already from the console' => [fn (TestCase $test, Operator $operator, int $id) => endingPress($test, $operator, $id)->assertSessionHas('flash.kind', 'success')],
    'ended by its own Sign out' => [fn (TestCase $test) => $test->post(tenantUrl('luna', '/admin/logout'))->assertRedirect(tenantUrl('luna', '/admin/login'))],
    'lapsed at sixty minutes from the press' => [fn () => openingMoveClock('PT60M')],
]);

it('ends from the console a pressed session whose link was never opened, so the link then opens nothing', function () {
    $operator = addOperator();
    $token = openingLink($this, $operator);
    $id = openingRows()[0]['id'] ?? null;
    assert(is_int($id));

    endingPress($this, $operator, $id)->assertSessionHas('flash', ['kind' => 'success', 'message' => SupportEndController::ENDED]);
    openingOnTheTenantHost($this);

    openingRefused(openingVisit($this, $token));

    expect(openingRows()[0] ?? [])->toMatchArray(['opened_at' => null, 'ended_at' => '2026-11-02 06:00:00'])
        ->and(openingAudit())->toBe(endingAudit($operator, 'luna'));
});

it('answers the console\'s 404 for an id {case}, writing nothing', function (?string $id) {
    $operator = addOperator();
    $opened = endingOpened($this, $operator);
    $rows = openingRows();

    endingPress($this, $operator, $id ?? (string) ($opened + 1), inertia: false)
        ->assertNotFound()
        ->assertInertia(fn (Assert $page) => $page->component('console/NotFound'));

    expect(openingRows())->toBe($rows)
        ->and(array_column(openingAudit(), 'action'))->toBe(['support_opened']);
})->with([
    'no row holds, the one after the only row' => [null],
    'of nought' => ['0'],
    'below nought' => ['-1'],
    'that is not a whole number' => ['abc'],
    'past the largest whole number' => ['99999999999999999999'],
]);

it('sends a visitor with no operator session from the console\'s End to the door, ending nothing', function () {
    $id = endingOpened($this, addOperator());

    $this->post(consoleUrl('/support/'.$id.'/end'))->assertRedirect(consoleUrl('/login'));

    expect(openingRows()[0] ?? [])->toMatchArray(['ended_at' => null])
        ->and(array_column(openingAudit(), 'action'))->toBe(['support_opened']);
});

it('ends nothing when the audit row cannot be written, the end and its record sharing one transaction', function () {
    $id = endingOpened($this, addOperator());

    expect(fn () => app(SupportSessions::class)->end($id, 999999))->toThrow(QueryException::class);

    expect(openingRows()[0] ?? [])->toMatchArray(['ended_at' => null])
        ->and(array_column(openingAudit(), 'action'))->toBe(['support_opened']);
});

it('lists the tenant\'s open sessions on its detail, the operator, the opening and the end on the console\'s clock, the earliest opened first, and no session ended, lapsed, unopened or another tenant\'s', function () {
    $pat = addOperator();
    $sam = addOperator('sam@inbox.example', name: 'Sam Operator');
    $row = fn (Operator $operator, string $slug, ?string $opened, string $expires, ?string $ended = null): int => DB::table('support_sessions')->insertGetId([
        'operator_id' => $operator->id,
        'tenant_id' => openingTenant($slug)->id,
        'token_hash' => $opened === null ? hash('sha256', str_repeat('c', 64)) : null,
        'opened_at' => $opened,
        'expires_at' => $expires,
        'ended_at' => $ended,
        'created_at' => $opened ?? '2026-11-02 05:58:00+00',
    ]);
    $later = $row($sam, 'luna', '2026-11-02 05:45:00+00', '2026-11-02 06:45:00+00');
    $earlier = $row($pat, 'luna', '2026-11-02 05:31:00+00', '2026-11-02 06:31:00+00');
    $row($pat, 'luna', '2026-11-02 05:00:00+00', '2026-11-02 06:00:00+00');
    $row($pat, 'luna', '2026-11-02 05:10:00+00', '2026-11-02 06:10:00+00', '2026-11-02 05:20:00+00');
    $row($pat, 'luna', null, '2026-11-02 06:58:00+00');
    $row($pat, 'pier', '2026-11-02 05:50:00+00', '2026-11-02 06:50:00+00');

    $this->actingAs($pat, ConsoleSession::GUARD)
        ->withSession(freshConsoleSession())
        ->get(consoleUrl('/tenants/luna'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('console/Tenant')->where('support_sessions', [
            ['id' => $earlier, 'operator' => 'Pat Operator', 'opened' => 'Monday 2 November 2026 at 07:31', 'ends' => 'Monday 2 November 2026 at 08:31'],
            ['id' => $later, 'operator' => 'Sam Operator', 'opened' => 'Monday 2 November 2026 at 07:45', 'ends' => 'Monday 2 November 2026 at 08:45'],
        ]));

    $this->get(consoleUrl('/tenants/pier'))->assertInertia(fn (Assert $page) => $page->has('support_sessions', 1));
});

it('lists no session on the detail once the session has ended', function () {
    $operator = addOperator();
    $id = endingOpened($this, $operator);

    $this->actingAs($operator, ConsoleSession::GUARD)
        ->withSession(freshConsoleSession())
        ->get(consoleUrl('/tenants/luna'))
        ->assertInertia(fn (Assert $page) => $page->where('support_sessions.0.id', $id)->has('support_sessions', 1));

    endingPress($this, $operator, $id)->assertSessionHas('flash.kind', 'success');

    $this->actingAs($operator, ConsoleSession::GUARD)
        ->get(consoleUrl('/tenants/luna'))
        ->assertInertia(fn (Assert $page) => $page->where('support_sessions', []));
});

it('hands the banner its prop on every page of the session, the operator and the end, and a user null', function () {
    endingOpened($this, addOperator());

    foreach (['/admin', '/admin/bookings', '/admin/services', '/admin/settings', '/admin/not-a-screen'] as $path) {
        $this->get(tenantUrl('luna', $path))
            ->assertInertia(fn (Assert $page) => $page->where('support', ['operator' => 'Pat Operator', 'ends_at' => '2026-11-02T07:00:00+00:00']));
    }

    $this->post(tenantUrl('luna', '/admin/login'), ['email' => 'owner@luna.example', 'password' => 'password'])->assertRedirect(tenantUrl('luna', '/admin'));

    foreach (['/admin', '/admin/bookings', '/admin/billing'] as $path) {
        $this->get(tenantUrl('luna', $path))->assertOk()->assertInertia(fn (Assert $page) => $page->where('support', null));
    }
});

it('names the console\'s End in its own sentences', function () {
    expect(SupportEndController::ENDED)->toBe('The support session has ended.')
        ->and(SupportEndController::ALREADY_ENDED)->toBe('This support session has already ended.');
});
