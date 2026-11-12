<?php

use App\Application\Clock;
use App\Http\AdminSession;
use App\Http\ApproverScope;
use App\Http\Middleware\RequiresPermission;
use App\Http\Principal;
use App\Infrastructure\Db\CalendarRoster;
use App\Infrastructure\Db\SqlText;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Permission;
use App\Tenancy\Tenant;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response as Answer;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\Support\FixedClock;

pest()->use(RefreshDatabase::class);

/*
 * The support principal against the readers that need a schema to answer it. The permission
 * middleware refuses it manageBilling and viewOwnCalendar and admits it manageSettings, the
 * approver's scope admits it on manageBookings before any row is read, so a booking no staff row
 * is on is still its to decide, and the calendar's roster answers it no own staff row without
 * reading one. The scope and the roster are handed a principal built by hand; the middleware
 * reads one from a session naming an open support row, as the open leaves the browser's session.
 * The clock is pinned, so the end spelled here is the one the row is written with.
 *
 * Every case here touches the database, which is why the file refreshes it: Pest applies a trait
 * to a file's whole class, so the readers that need no schema, the rail and the shared props, are
 * pinned in tests/Unit/PrincipalTest.php instead.
 */

beforeEach(function () {
    app()->instance(Clock::class, FixedClock::at('2026-11-02 06:00'));
});

function supportPrincipal(): Principal
{
    return Principal::support(31, 4, 'Pat Operator', new DateTimeImmutable('2026-11-02 07:00:00', new DateTimeZone('UTC')));
}

/**
 * A request to luna's admin whose session names an open support row of luna, as the open writes
 * one: the row opened at the clock's now and ending sixty minutes on, its operator a fresh one.
 */
function supportRequest(): Request
{
    provisionTenant('luna', ['name' => 'Luna Hair Studio', 'ownerEmail' => 'owner@luna.example']);
    $tenant = Tenant::query()->where('slug', 'luna')->sole();
    $now = app(Clock::class)->now();
    $id = DB::table('support_sessions')->insertGetId([
        'operator_id' => addOperator()->id,
        'tenant_id' => $tenant->id,
        'opened_at' => SqlText::instant($now),
        'expires_at' => SqlText::instant($now->add(new DateInterval('PT60M'))),
        'created_at' => SqlText::instant($now),
    ]);
    app(CurrentTenant::class)->bind($tenant);

    $session = new Store('admin', new ArraySessionHandler(60));
    $session->put(AdminSession::SUPPORT_KEY, $id);
    $request = Request::create('https://luna.kleiseradevou.test/admin/settings');
    $request->setLaravelSession($session);

    return $request;
}

function supportThrough(Request $request, Permission $permission): Answer
{
    return app(RequiresPermission::class)->handle($request, fn (Request $request): Response => new Response('through'), $permission->value);
}

it('refuses support manageBilling and viewOwnCalendar at the permission middleware and admits it manageSettings, reading the principal from the session', function () {
    $request = supportRequest();

    expect(fn (): Answer => supportThrough($request, Permission::ManageBilling))->toThrow(AccessDeniedHttpException::class)
        ->and(fn (): Answer => supportThrough($request, Permission::ViewOwnCalendar))->toThrow(AccessDeniedHttpException::class)
        ->and(supportThrough($request, Permission::ManageSettings)->getContent())->toBe('through')
        ->and(supportThrough($request, Permission::ManageBookings)->getContent())->toBe('through');
});

it('admits support to decide a booking no staff row is on, where a staff user is refused it', function () {
    provisionTenant('luna', ['name' => 'Luna Hair Studio', 'ownerEmail' => 'owner@luna.example'], ['staff']);
    $staff = User::query()->findOrFail(addTenantUser('staff@luna.example', 'staff', staff: 'Elena Papadaki'));
    $tenant = Tenant::query()->where('slug', 'luna')->sole();
    $scope = app(ApproverScope::class);
    $nobodys = '00000000-0000-4000-8000-000000000000';

    expect($scope->admitsPrincipal($tenant, $nobodys, supportPrincipal()))->toBeTrue()
        ->and($scope->admitsPrincipal($tenant, $nobodys, Principal::user($staff)))->toBeFalse();
});

it('answers support no own staff row on the calendar\'s roster without reading one, where a linked user\'s row is read once', function () {
    provisionTenant('luna', ['name' => 'Luna Hair Studio', 'ownerEmail' => 'owner@luna.example'], ['staff']);
    DB::table('tenant_luna.staff')->insert(['name' => 'Elena Papadaki', 'status' => 'active', 'sort' => 1]);
    $staff = User::query()->findOrFail(addTenantUser('staff@luna.example', 'staff', staff: 'Elena Papadaki'));
    $tenant = Tenant::query()->where('slug', 'luna')->sole();
    $roster = app(CalendarRoster::class);
    $reads = 0;
    DB::listen(function (QueryExecuted $query) use (&$reads): void {
        $reads++;
    });

    $support = $roster->ownRow($tenant, supportPrincipal()->ownStaffUserId());
    $readForSupport = $reads;
    $linked = $roster->ownRow($tenant, Principal::user($staff)->ownStaffUserId());

    expect($support)->toBeNull()
        ->and($readForSupport)->toBe(0)
        ->and($linked['name'] ?? null)->toBe('Elena Papadaki')
        ->and($reads)->toBe(1);
});
