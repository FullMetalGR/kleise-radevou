<?php

use App\Application\Clock;
use App\Http\ConsoleSession;
use App\Platform\Operator;
use App\Platform\PlatformAuditAction;
use App\Tenancy\Tenant;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FixedClock;
use Tests\TestCase;

pest()->use(RefreshDatabase::class);

/*
 * The Audit screen: GET /audit under operator lists the platform audit log newest first, fifty a
 * page, each row with the operator's name and the tenant's slug joined in one query, the action as
 * its vocabulary value for the page to word, the payload's facts in the key list's order, and the
 * instant spelled on the console's clock, the zone left to the top bar, which names it once from
 * the shared prop. ?tenant={slug} narrows the list to one tenant, a malformed or unknown slug
 * reading as unset, and ?page= picks the page, anything malformed reading as the first. The rail
 * the console's adapter shares carries Audit, current on this screen, after Tenants and Messages.
 */

beforeEach(function () {
    $this->withoutVite();
    app()->instance(Clock::class, FixedClock::at('2026-10-14 09:00'));
});

/**
 * A row of the log as a test writes it, past the writer, at the UTC instant given.
 *
 * @param  array<string, mixed>  $payload
 */
function auditScreenRow(Operator $operator, PlatformAuditAction $action, string $utc, ?Tenant $tenant = null, array $payload = []): void
{
    DB::table('platform_audit_log')->insert([
        'operator_id' => $operator->id,
        'action' => $action->value,
        'tenant_id' => $tenant?->id,
        'payload' => json_encode((object) $payload, JSON_THROW_ON_ERROR),
        'created_at' => $utc.'+00:00',
    ]);
}

/**
 * The Audit screen as the operator given asks for it, with the query given.
 *
 * @param  array<string, string>  $query
 * @return TestResponse<Response>
 */
function auditScreen(TestCase $test, Operator $operator, array $query = []): TestResponse
{
    return $test->actingAs($operator, ConsoleSession::GUARD)
        ->withSession(freshConsoleSession())
        ->get(consoleUrl('/audit'.($query === [] ? '' : '?'.http_build_query($query))));
}

/**
 * The rows the screen carries, each without its id, which the database chose.
 *
 * @param  TestResponse<Response>  $response
 * @return list<mixed>
 */
function auditScreenRows(TestResponse $response): array
{
    $page = $response->viewData('page');

    assert(is_array($page) && is_array($page['props']) && is_array($page['props']['entries']) && is_array($page['props']['entries']['rows']));

    return array_map(fn (mixed $row): mixed => is_array($row) ? array_diff_key($row, ['id' => true]) : $row, array_values($page['props']['entries']['rows']));
}

it('lists the rows newest first, the operator\'s name and the tenant\'s slug joined, the facts in the key list\'s order and each instant in the console\'s zone', function () {
    $pat = addOperator();
    $sam = addOperator('sam@inbox.example', 'operator password', 'Sam Operator');
    $acme = Tenant::factory()->create(['slug' => 'acme']);
    auditScreenRow($pat, PlatformAuditAction::SignIn, '2026-10-14 06:00:00');
    auditScreenRow($sam, PlatformAuditAction::TenantSuspended, '2026-10-14 07:30:00', $acme, ['user' => 12, 'reason' => 'Unpaid since March', 'features' => ['staff']]);
    auditScreenRow($pat, PlatformAuditAction::SignOut, '2026-10-14 08:15:00');

    $response = auditScreen($this, $pat);

    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('console/Audit')
            ->where('filters', ['tenant' => null])
            ->where('entries.total', 3)
            ->where('entries.page', 1)
            ->where('entries.page_size', 50));

    expect(auditScreenRows($response))->toBe([
        ['at' => 'Wednesday 14 October 2026 at 11:15', 'operator' => 'Pat Operator', 'action' => 'sign_out', 'tenant' => null, 'facts' => []],
        ['at' => 'Wednesday 14 October 2026 at 10:30', 'operator' => 'Sam Operator', 'action' => 'tenant_suspended', 'tenant' => 'acme', 'facts' => [
            ['key' => 'reason', 'value' => 'Unpaid since March'],
            ['key' => 'features', 'value' => ['staff']],
            ['key' => 'user', 'value' => 12],
        ]],
        ['at' => 'Wednesday 14 October 2026 at 09:00', 'operator' => 'Pat Operator', 'action' => 'sign_in', 'tenant' => null, 'facts' => []],
    ]);
});

it('spells each instant in the zone the configuration names, and shares that zone for the top bar', function () {
    config(['console.timezone' => 'America/New_York']);
    $pat = addOperator();
    auditScreenRow($pat, PlatformAuditAction::SignIn, '2026-10-14 06:00:00');

    auditScreen($this, $pat)->assertInertia(fn (Assert $page) => $page->where('entries.rows.0.at', 'Wednesday 14 October 2026 at 02:00')->where('zone', 'America/New_York'));
});

it('keeps the same instant\'s rows in the order they were written, the later first', function () {
    $pat = addOperator();
    auditScreenRow($pat, PlatformAuditAction::SignIn, '2026-10-14 06:00:00');
    auditScreenRow($pat, PlatformAuditAction::SignOut, '2026-10-14 06:00:00');

    auditScreen($this, $pat)->assertInertia(fn (Assert $page) => $page->where('entries.rows.0.action', 'sign_out')->where('entries.rows.1.action', 'sign_in'));
});

it('pages fifty at a time, the next page carrying on where the first stopped', function () {
    $pat = addOperator();
    $start = new DateTimeImmutable('2026-10-01 08:00:00', new DateTimeZone('UTC'));

    foreach (range(0, 50) as $minutes) {
        auditScreenRow($pat, PlatformAuditAction::SignIn, $start->add(new DateInterval('PT'.$minutes.'M'))->format('Y-m-d H:i:s'));
    }

    auditScreen($this, $pat)->assertInertia(fn (Assert $page) => $page
        ->has('entries.rows', 50)
        ->where('entries.total', 51)
        ->where('entries.rows.0.at', 'Thursday 1 October 2026 at 11:50')
        ->where('entries.rows.49.at', 'Thursday 1 October 2026 at 11:01'));

    auditScreen($this, $pat, ['page' => '2'])->assertInertia(fn (Assert $page) => $page
        ->has('entries.rows', 1)
        ->where('entries.page', 2)
        ->where('entries.total', 51)
        ->where('entries.rows.0.at', 'Thursday 1 October 2026 at 11:00'));
});

it('reads a malformed page as the first: {page}', function (string $page) {
    $pat = addOperator();
    auditScreenRow($pat, PlatformAuditAction::SignIn, '2026-10-14 06:00:00');

    auditScreen($this, $pat, ['page' => $page])->assertOk()->assertInertia(fn (Assert $page) => $page->where('entries.page', 1)->has('entries.rows', 1));
})->with(['0', '-1', 'two', '1.5', '9999999999']);

it('answers a page past the end with no rows and the total', function () {
    $pat = addOperator();
    auditScreenRow($pat, PlatformAuditAction::SignIn, '2026-10-14 06:00:00');

    auditScreen($this, $pat, ['page' => '3'])->assertOk()->assertInertia(fn (Assert $page) => $page->where('entries.page', 3)->where('entries.total', 1)->has('entries.rows', 0));
});

it('narrows the list to one tenant by its slug, whatever its case, the filter naming the slug', function (string $asked) {
    $pat = addOperator();
    $acme = Tenant::factory()->create(['slug' => 'acme']);
    $bold = Tenant::factory()->create(['slug' => 'bold']);
    auditScreenRow($pat, PlatformAuditAction::SignIn, '2026-10-14 06:00:00');
    auditScreenRow($pat, PlatformAuditAction::TenantSuspended, '2026-10-14 06:10:00', $acme, ['reason' => 'Unpaid']);
    auditScreenRow($pat, PlatformAuditAction::TenantSuspended, '2026-10-14 06:20:00', $bold, ['reason' => 'Asked to']);
    auditScreenRow($pat, PlatformAuditAction::TenantResumed, '2026-10-14 06:30:00', $acme);

    auditScreen($this, $pat, ['tenant' => $asked])->assertInertia(fn (Assert $page) => $page
        ->where('filters', ['tenant' => 'acme'])
        ->where('entries.total', 2)
        ->where('entries.rows.0.action', 'tenant_resumed')
        ->where('entries.rows.1.action', 'tenant_suspended')
        ->where('entries.rows.1.tenant', 'acme'));
})->with(['acme', 'ACME']);

it('reads a slug that is {case} as unset, listing every row', function (string $asked) {
    $pat = addOperator();
    $acme = Tenant::factory()->create(['slug' => 'acme']);
    auditScreenRow($pat, PlatformAuditAction::SignIn, '2026-10-14 06:00:00');
    auditScreenRow($pat, PlatformAuditAction::TenantResumed, '2026-10-14 06:30:00', $acme);

    auditScreen($this, $pat, ['tenant' => $asked])->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('filters', ['tenant' => null])
        ->where('entries.total', 2));
})->with([
    'unknown' => ['nobody'],
    'malformed' => ['not a slug!'],
    'empty' => [''],
    'too long' => [str_repeat('a', 57)],
]);

it('reads the rows in one statement whatever a page holds, the operator and the tenant joined into it', function () {
    $pat = addOperator();
    $acme = Tenant::factory()->create(['slug' => 'acme']);

    foreach (range(1, 6) as $minute) {
        auditScreenRow($pat, PlatformAuditAction::TenantResumed, sprintf('2026-10-14 06:%02d:00', $minute), $acme);
    }

    $statements = [];
    DB::listen(function (QueryExecuted $query) use (&$statements): void {
        if (str_contains($query->sql, 'platform_audit_log')) {
            $statements[] = $query->sql;
        }
    });

    auditScreen($this, $pat)->assertOk()->assertInertia(fn (Assert $page) => $page->has('entries.rows', 6));

    expect($statements)->toHaveCount(2)
        ->and(array_values(array_filter($statements, fn (string $sql): bool => str_contains($sql, 'join operators') && str_contains($sql, 'join tenants'))))->toHaveCount(1);
});

it('hands the console\'s rail, Audit lit on the Audit screen and dark elsewhere, and an empty one at the door', function () {
    $pat = addOperator();
    $tenants = ['label' => 'Tenants', 'url' => '/', 'group' => 'Platform', 'pending' => null];
    $messages = ['label' => 'Messages', 'url' => '/messages', 'group' => 'Platform', 'pending' => null];
    $audit = ['label' => 'Audit', 'url' => '/audit', 'group' => 'Platform', 'pending' => null];

    auditScreen($this, $pat)->assertInertia(fn (Assert $page) => $page->where('nav', [[...$tenants, 'current' => false], [...$messages, 'current' => false], [...$audit, 'current' => true]]));

    $this->actingAs($pat, ConsoleSession::GUARD)->withSession(freshConsoleSession())->get(consoleUrl('/'))
        ->assertInertia(fn (Assert $page) => $page->component('console/Tenants')->where('nav', [[...$tenants, 'current' => true], [...$messages, 'current' => false], [...$audit, 'current' => false]]));

    $this->actingAs($pat, ConsoleSession::GUARD)->withSession(freshConsoleSession())->get(consoleUrl('/audit/nothing'))
        ->assertNotFound()
        ->assertInertia(fn (Assert $page) => $page->component('console/NotFound')->where('nav', [[...$tenants, 'current' => false], [...$messages, 'current' => false], [...$audit, 'current' => false]]));
});

it('sends a visitor with no operator session from the Audit screen to the door', function () {
    $this->get(consoleUrl('/audit'))->assertRedirect(consoleUrl('/login'));
});
