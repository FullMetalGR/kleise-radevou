<?php

use App\Application\Clock;
use App\Http\ConsoleSession;
use App\Platform\Operator;
use App\Platform\TenantList;
use App\Platform\TenantPlan;
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
 * The tenant list: GET / under operator lists every tenant row newest signup first, twenty-five a
 * page, each with its slug, name, status, plan, subscription state and the signup instant in the
 * console's zone, the zone itself named once by the top bar from the shared prop. The plan is
 * derived by TenantPlan and never stored, and the plan filter narrows in SQL to exactly the rows
 * the derivation names. search matches the slug and the name by substring, its wildcards literal;
 * status, state and plan each take one of their words; from and to are two dates over the signup
 * instant in the console's zone, To inclusive. A malformed value reads as unset, an inverted pair
 * as no dates at all, and the whole state is the query string.
 */

beforeEach(function () {
    $this->withoutVite();
    app()->instance(Clock::class, FixedClock::at('2026-10-14 09:00'));
});

/**
 * A tenant row as a test writes it, past the provisioner: the row tenant:provision writes,
 * active in state active with no trial end and no subscriptions row, but for the shape given,
 * signed up at the UTC instant given. No schema stands behind it; the list reads none.
 *
 * @param  array{status?: string, state?: string, trial_ends_at?: string, plan_ref?: string, subscribed?: bool}  $shape
 */
function tenantsScreenRow(string $slug, string $name, string $signedUpUtc, array $shape = []): Tenant
{
    $tenant = Tenant::factory()->create(['slug' => $slug, 'name' => $name]);
    DB::table('tenants')->where('id', $tenant->id)->update([
        'status' => $shape['status'] ?? 'active',
        'subscription_state' => $shape['state'] ?? 'active',
        'trial_ends_at' => isset($shape['trial_ends_at']) ? $shape['trial_ends_at'].'+00:00' : null,
        'plan_ref' => $shape['plan_ref'] ?? null,
        'created_at' => $signedUpUtc.'+00:00',
    ]);

    if ($shape['subscribed'] ?? false) {
        DB::table('subscriptions')->insert(['tenant_id' => $tenant->id, 'provider_customer_id' => 'cus_'.$slug]);
    }

    return $tenant;
}

/**
 * The list as the operator given asks for it, with the query given.
 *
 * @param  array<string, string>  $query
 * @return TestResponse<Response>
 */
function tenantsScreen(TestCase $test, Operator $operator, array $query = []): TestResponse
{
    return $test->actingAs($operator, ConsoleSession::GUARD)
        ->withSession(freshConsoleSession())
        ->get(consoleUrl('/'.($query === [] ? '' : '?'.http_build_query($query))));
}

/**
 * The page's props as the response carries them.
 *
 * @param  TestResponse<Response>  $response
 * @return array<mixed>
 */
function tenantsScreenProps(TestResponse $response): array
{
    $page = $response->assertOk()->viewData('page');

    assert(is_array($page) && is_array($page['props']));

    return $page['props'];
}

/**
 * The slugs the list carries, in its order.
 *
 * @param  TestResponse<Response>  $response
 * @return list<mixed>
 */
function tenantsScreenSlugs(TestResponse $response): array
{
    $props = tenantsScreenProps($response);

    assert(is_array($props['tenants']) && is_array($props['tenants']['rows']));

    return array_values(array_map(fn (mixed $row): mixed => is_array($row) ? $row['slug'] : $row, $props['tenants']['rows']));
}

/**
 * Every shape the derivation knows, one row each, a minute apart so the order is the slugs' own:
 * a free row of the shape tenant:provision writes, a trial, a floor and a staff subscriber by
 * their test prices, a subscriber on both prices, a subscriptions row with no price reference, one
 * on a price no plan names, a trial that ended unpaid with no checkout, and a signup's
 * provisioning row.
 */
function tenantsScreenShapes(): void
{
    tenantsScreenRow('luna', 'Luna Hair Studio', '2026-10-01 09:00:00');
    tenantsScreenRow('trial', 'Trial Salon', '2026-10-01 09:01:00', ['state' => 'trial', 'trial_ends_at' => '2026-10-08 09:01:00']);
    tenantsScreenRow('floor', 'Floor Salon', '2026-10-01 09:02:00', ['subscribed' => true, 'plan_ref' => 'price_floor_test']);
    tenantsScreenRow('staff', 'Staff Salon', '2026-10-01 09:03:00', ['subscribed' => true, 'plan_ref' => 'price_staff_test']);
    tenantsScreenRow('both', 'Both Salon', '2026-10-01 09:04:00', ['subscribed' => true, 'plan_ref' => 'price_staff_test,price_floor_test']);
    tenantsScreenRow('unpriced', 'Unpriced Salon', '2026-10-01 09:05:00', ['subscribed' => true]);
    tenantsScreenRow('retired', 'Retired Salon', '2026-10-01 09:06:00', ['subscribed' => true, 'state' => 'past_due', 'plan_ref' => 'price_retired']);
    tenantsScreenRow('ended', 'Ended Salon', '2026-10-01 09:07:00', ['state' => 'canceled', 'trial_ends_at' => '2026-10-08 09:07:00']);
    tenantsScreenRow('reserved', 'Reserved Salon', '2026-10-01 09:08:00', ['status' => 'provisioning', 'state' => 'incomplete']);
}

it('lists every tenant newest first, each with its slug, name, status, plan, subscription state and the signup instant in the console\'s zone', function () {
    tenantsScreenRow('acme', 'Acme Salon', '2026-10-13 07:30:00');
    tenantsScreenRow('pier', 'Pier Physiotherapy', '2026-10-14 08:00:00', ['status' => 'suspended', 'state' => 'past_due', 'subscribed' => true, 'plan_ref' => 'price_staff_test']);
    tenantsScreenRow('luna', 'Luna Hair Studio', '2026-10-12 21:15:00', ['state' => 'trial', 'trial_ends_at' => '2026-10-19 21:15:00']);

    tenantsScreen($this, addOperator())
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('console/Tenants')
            ->where('filters', ['search' => '', 'status' => '', 'state' => '', 'plan' => '', 'from' => '', 'to' => ''])
            ->where('tenants', [
                'rows' => [
                    ['slug' => 'pier', 'detail' => '/tenants/pier', 'name' => 'Pier Physiotherapy', 'status' => 'suspended', 'plan' => 'staff', 'state' => 'past_due', 'signed_up' => 'Wednesday 14 October 2026 at 11:00'],
                    ['slug' => 'acme', 'detail' => '/tenants/acme', 'name' => 'Acme Salon', 'status' => 'active', 'plan' => 'free', 'state' => 'active', 'signed_up' => 'Tuesday 13 October 2026 at 10:30'],
                    ['slug' => 'luna', 'detail' => '/tenants/luna', 'name' => 'Luna Hair Studio', 'status' => 'active', 'plan' => 'trial', 'state' => 'trial', 'signed_up' => 'Tuesday 13 October 2026 at 00:15'],
                ],
                'total' => 3,
                'page' => 1,
                'page_size' => TenantList::PAGE_SIZE,
            ])
            ->where('plan_names', [['plan' => 'floor', 'name' => 'Solo'], ['plan' => 'staff', 'name' => 'Team']])
            ->where('zone', 'Europe/Athens'));
});

it('names floor and staff from config/billing.php, a renamed plan followed and a price unset as in development leaving the names standing', function () {
    config(['billing.plans.floor.name' => 'Starter', 'billing.plans.staff.price' => null]);

    tenantsScreen($this, addOperator())
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('plan_names', [['plan' => 'floor', 'name' => 'Starter'], ['plan' => 'staff', 'name' => 'Team']]));
});

it('derives the plan of every shape: trial, free as the provisioner builds it, floor and staff by price, unlisted, and none while provisioning', function () {
    tenantsScreenShapes();
    provisionTenant('pier', ['name' => 'Pier Physiotherapy']);

    $props = tenantsScreenProps(tenantsScreen($this, addOperator()));

    assert(is_array($props['tenants']) && is_array($props['tenants']['rows']));

    $plans = [];

    foreach ($props['tenants']['rows'] as $row) {
        assert(is_array($row) && is_string($row['slug']));
        $plans[$row['slug']] = $row['plan'];
    }

    expect($plans)->toBe([
        'pier' => 'free',
        'reserved' => null,
        'ended' => 'unlisted',
        'retired' => 'unlisted',
        'unpriced' => 'unlisted',
        'both' => 'floor',
        'staff' => 'staff',
        'floor' => 'floor',
        'trial' => 'trial',
        'luna' => 'free',
    ]);
});

it('lists a row in a frozen state with no subscriptions row and no trial end as unlisted, never free, the column and the filter reading the one free predicate', function () {
    tenantsScreenRow('luna', 'Luna Hair Studio', '2026-10-01 09:00:00');
    tenantsScreenRow('frozen', 'Frozen Salon', '2026-10-01 09:01:00', ['state' => 'canceled']);
    tenantsScreenRow('unpaid', 'Unpaid Salon', '2026-10-01 09:02:00', ['state' => 'incomplete']);
    $operator = addOperator();

    tenantsScreen($this, $operator)->assertInertia(fn (Assert $page) => $page
        ->where('tenants.rows.0.slug', 'unpaid')->where('tenants.rows.0.plan', 'unlisted')
        ->where('tenants.rows.1.slug', 'frozen')->where('tenants.rows.1.plan', 'unlisted')
        ->where('tenants.rows.2.slug', 'luna')->where('tenants.rows.2.plan', 'free'));

    expect(tenantsScreenSlugs(tenantsScreen($this, $operator, ['plan' => 'free'])))->toBe(['luna'])
        ->and(tenantsScreenSlugs(tenantsScreen($this, $operator, ['plan' => 'unlisted'])))->toBe(['unpaid', 'frozen']);
});

it('narrows by plan to exactly the rows the derivation names, the provisioning row under none: {case}', function (string $plan, array $slugs) {
    tenantsScreenShapes();

    expect(tenantsScreenSlugs(tenantsScreen($this, addOperator(), ['plan' => $plan])))->toBe($slugs);
})->with([
    'trial' => ['trial', ['trial']],
    'free' => ['free', ['luna']],
    'floor' => ['floor', ['both', 'floor']],
    'staff' => ['staff', ['staff']],
    'unlisted' => ['unlisted', ['ended', 'retired', 'unpriced']],
]);

it('agrees with the derivation for every plan, the filter and the row never naming two plans for one tenant', function () {
    tenantsScreenShapes();
    $operator = addOperator();
    $props = tenantsScreenProps(tenantsScreen($this, $operator));

    assert(is_array($props['tenants']) && is_array($props['tenants']['rows']));

    foreach (TenantPlan::cases() as $plan) {
        $derived = [];

        foreach ($props['tenants']['rows'] as $row) {
            if (is_array($row) && $row['plan'] === $plan->value) {
                $derived[] = $row['slug'];
            }
        }

        expect(tenantsScreenSlugs(tenantsScreen($this, $operator, ['plan' => $plan->value])))->toBe($derived, $plan->value);
    }
});

it('lists every paid row unlisted and narrows floor and staff to none where the catalogue cannot be read, trial and free unchanged', function () {
    tenantsScreenShapes();
    config(['billing.plans' => null]);
    $operator = addOperator();

    expect(tenantsScreenSlugs(tenantsScreen($this, $operator, ['plan' => 'floor'])))->toBe([])
        ->and(tenantsScreenSlugs(tenantsScreen($this, $operator, ['plan' => 'staff'])))->toBe([])
        ->and(tenantsScreenSlugs(tenantsScreen($this, $operator, ['plan' => 'unlisted'])))->toBe(['ended', 'retired', 'unpriced', 'both', 'staff', 'floor'])
        ->and(tenantsScreenSlugs(tenantsScreen($this, $operator, ['plan' => 'trial'])))->toBe(['trial'])
        ->and(tenantsScreenSlugs(tenantsScreen($this, $operator, ['plan' => 'free'])))->toBe(['luna']);

    tenantsScreen($this, $operator, ['search' => 'floor'])->assertInertia(fn (Assert $page) => $page->where('tenants.rows.0.plan', 'unlisted')->where('plan_names', []));
});

it('searches the slug and the name by substring whatever the case, its wildcards literal: {case}', function (string $search, array $slugs) {
    tenantsScreenRow('luna', 'Luna Hair Studio', '2026-10-01 09:00:00');
    tenantsScreenRow('pier', 'Pier Physiotherapy', '2026-10-01 09:01:00');
    tenantsScreenRow('moon-spa', 'Lunar Spa', '2026-10-01 09:02:00');
    tenantsScreenRow('hundred', '100% Nails', '2026-10-01 09:03:00');

    $response = tenantsScreen($this, addOperator(), ['search' => $search]);

    $response->assertInertia(fn (Assert $page) => $page->where('filters.search', trim($search)));
    expect(tenantsScreenSlugs($response))->toBe($slugs);
})->with([
    'the slug and the name at once' => ['LUN', ['moon-spa', 'luna']],
    'the name alone' => ['physio', ['pier']],
    'the slug alone' => ['oon-s', ['moon-spa']],
    'trimmed' => ['  pier ', ['pier']],
    'a percent sign as itself' => ['%', ['hundred']],
    'an underscore as itself' => ['_', []],
    'nothing' => ['zzz', []],
]);

it('narrows by status, each of the five', function () {
    tenantsScreenRow('open', 'Open', '2026-10-01 09:00:00');
    tenantsScreenRow('paused', 'Paused', '2026-10-01 09:01:00', ['status' => 'suspended']);
    tenantsScreenRow('leaving', 'Leaving', '2026-10-01 09:02:00', ['status' => 'closing']);
    tenantsScreenRow('reserved', 'Reserved', '2026-10-01 09:03:00', ['status' => 'provisioning', 'state' => 'incomplete']);
    tenantsScreenRow('gone', 'Gone', '2026-10-01 09:04:00');
    writeClosed('gone');
    $operator = addOperator();

    expect(tenantsScreenSlugs(tenantsScreen($this, $operator, ['status' => 'active'])))->toBe(['open'])
        ->and(tenantsScreenSlugs(tenantsScreen($this, $operator, ['status' => 'suspended'])))->toBe(['paused'])
        ->and(tenantsScreenSlugs(tenantsScreen($this, $operator, ['status' => 'closing'])))->toBe(['leaving'])
        ->and(tenantsScreenSlugs(tenantsScreen($this, $operator, ['status' => 'provisioning'])))->toBe(['reserved'])
        ->and(tenantsScreenSlugs(tenantsScreen($this, $operator, ['status' => 'closed'])))->toBe([null]);

    tenantsScreen($this, $operator, ['status' => 'closing'])->assertInertia(fn (Assert $page) => $page->where('filters.status', 'closing')->where('tenants.total', 1));
});

it('lists a closed row, whose slug the drop released, by its name with no slug, its detail at its id, found by its name and never by the slug it held', function () {
    tenantsScreenRow('acme', 'Acme Salon', '2026-10-13 07:30:00');
    $gone = tenantsScreenRow('gone', 'Closed Salon', '2026-10-12 07:30:00', ['subscribed' => true, 'state' => 'canceled']);
    writeClosed('gone');
    $operator = addOperator();

    tenantsScreen($this, $operator)->assertInertia(fn (Assert $page) => $page->where('tenants.rows', [
        ['slug' => 'acme', 'detail' => '/tenants/acme', 'name' => 'Acme Salon', 'status' => 'active', 'plan' => 'free', 'state' => 'active', 'signed_up' => 'Tuesday 13 October 2026 at 10:30'],
        ['slug' => null, 'detail' => '/tenants/closed/'.$gone->id, 'name' => 'Closed Salon', 'status' => 'closed', 'plan' => 'unlisted', 'state' => 'canceled', 'signed_up' => 'Monday 12 October 2026 at 10:30'],
    ]));

    expect(tenantsScreenSlugs(tenantsScreen($this, $operator, ['search' => 'closed salon'])))->toBe([null])
        ->and(tenantsScreenSlugs(tenantsScreen($this, $operator, ['search' => 'gone'])))->toBe([]);
});

it('narrows by subscription state, each of the six', function () {
    $states = ['active', 'grace', 'past_due', 'canceled', 'incomplete', 'trial'];

    foreach ($states as $minute => $state) {
        tenantsScreenRow(str_replace('_', '-', $state), ucfirst($state), sprintf('2026-10-01 09:%02d:00', $minute), ['state' => $state, ...($state === 'trial' ? ['trial_ends_at' => '2026-10-08 09:00:00'] : [])]);
    }

    $operator = addOperator();

    foreach ($states as $state) {
        expect(tenantsScreenSlugs(tenantsScreen($this, $operator, ['state' => $state])))->toBe([str_replace('_', '-', $state)], $state);
    }
});

it('reads the dates in the console\'s zone over the signup instant, To inclusive, around Athens\'s midnight', function () {
    tenantsScreenRow('before', 'Before', '2026-10-12 20:59:59');
    tenantsScreenRow('late', 'Late', '2026-10-13 20:59:59');
    tenantsScreenRow('midnight', 'Midnight', '2026-10-13 21:00:00');
    tenantsScreenRow('after', 'After', '2026-10-14 21:00:00');
    $operator = addOperator();

    expect(tenantsScreenSlugs(tenantsScreen($this, $operator, ['from' => '2026-10-14'])))->toBe(['after', 'midnight'])
        ->and(tenantsScreenSlugs(tenantsScreen($this, $operator, ['to' => '2026-10-13'])))->toBe(['late', 'before'])
        ->and(tenantsScreenSlugs(tenantsScreen($this, $operator, ['from' => '2026-10-13', 'to' => '2026-10-13'])))->toBe(['late'])
        ->and(tenantsScreenSlugs(tenantsScreen($this, $operator, ['from' => '2026-10-14', 'to' => '2026-10-14'])))->toBe(['midnight'])
        ->and(tenantsScreenSlugs(tenantsScreen($this, $operator, ['from' => '2026-10-13', 'to' => '2026-10-15'])))->toBe(['after', 'midnight', 'late']);

    tenantsScreen($this, $operator, ['from' => '2026-10-13', 'to' => '2026-10-14'])
        ->assertInertia(fn (Assert $page) => $page->where('filters.from', '2026-10-13')->where('filters.to', '2026-10-14'));
});

it('counts a day of the clock change as the calendar counts it, To reaching its own midnight and no further', function () {
    // Athens leaves summer time on 2026-10-25: that day runs from 21:00 UTC on the 24th to 22:00
    // UTC on the 25th, twenty-five hours, which a day counted as twenty-four would cut short.
    tenantsScreenRow('eve', 'Eve', '2026-10-24 20:59:59');
    tenantsScreenRow('first', 'First', '2026-10-24 21:00:00');
    tenantsScreenRow('last', 'Last', '2026-10-25 21:59:59');
    tenantsScreenRow('next', 'Next', '2026-10-25 22:00:00');
    $operator = addOperator();

    expect(tenantsScreenSlugs(tenantsScreen($this, $operator, ['from' => '2026-10-25', 'to' => '2026-10-25'])))->toBe(['last', 'first'])
        ->and(tenantsScreenSlugs(tenantsScreen($this, $operator, ['from' => '2026-10-26'])))->toBe(['next'])
        ->and(tenantsScreenSlugs(tenantsScreen($this, $operator, ['to' => '2026-10-24'])))->toBe(['eve']);
});

it('reads a malformed value as unset, the whole list answering: {case}', function (string $key, string $value) {
    tenantsScreenRow('acme', 'Acme Salon', '2026-10-13 07:30:00');
    tenantsScreenRow('pier', 'Pier Physiotherapy', '2026-10-14 08:00:00');

    $response = tenantsScreen($this, addOperator(), [$key => $value]);

    $response->assertInertia(fn (Assert $page) => $page->component('console/Tenants')
        ->where('filters', ['search' => '', 'status' => '', 'state' => '', 'plan' => '', 'from' => '', 'to' => ''])
        ->where('tenants.page', 1));

    expect(tenantsScreenSlugs($response))->toBe(['pier', 'acme']);
})->with([
    'a status of none of the four' => ['status', 'open'],
    'a status in capitals' => ['status', 'ACTIVE'],
    'a state of none of the six' => ['state', 'lapsed'],
    'a plan of none of the five' => ['plan', 'gold'],
    'a blank search' => ['search', '   '],
    'a date of no calendar' => ['from', '2026-02-30'],
    'a date in another shape' => ['to', '14/10/2026'],
    'a page of no number' => ['page', 'two'],
    'a page of nought' => ['page', '0'],
    'a page past nine digits' => ['page', '1234567890'],
    'a search that is not UTF-8' => ['search', "lun\xC3"],
    'a search holding a NUL' => ['search', "lu\0na"],
]);

it('answers the whole list for the calendar\'s first day as From and its last as To, each a date like any other', function () {
    tenantsScreenRow('acme', 'Acme Salon', '2026-10-13 07:30:00');
    tenantsScreenRow('pier', 'Pier Physiotherapy', '2026-10-14 08:00:00');
    $operator = addOperator();

    $response = tenantsScreen($this, $operator, ['from' => '0001-01-01', 'to' => '9999-12-31']);

    $response->assertInertia(fn (Assert $page) => $page->where('filters.from', '0001-01-01')->where('filters.to', '9999-12-31'));
    expect(tenantsScreenSlugs($response))->toBe(['pier', 'acme'])
        ->and(tenantsScreenSlugs(tenantsScreen($this, $operator, ['to' => '0001-01-01'])))->toBe([])
        ->and(tenantsScreenSlugs(tenantsScreen($this, $operator, ['from' => '9999-12-31'])))->toBe([]);
});

it('reads an inverted pair as no dates at all, since no signup answers it', function () {
    tenantsScreenRow('acme', 'Acme Salon', '2026-10-13 07:30:00');
    tenantsScreenRow('pier', 'Pier Physiotherapy', '2026-10-14 08:00:00');

    $response = tenantsScreen($this, addOperator(), ['from' => '2026-10-14', 'to' => '2026-10-13']);

    $response->assertInertia(fn (Assert $page) => $page->where('filters.from', '')->where('filters.to', ''));
    expect(tenantsScreenSlugs($response))->toBe(['pier', 'acme']);
});

it('reads one malformed date of a pair as unset and keeps the other', function () {
    tenantsScreenRow('acme', 'Acme Salon', '2026-10-13 07:30:00');
    tenantsScreenRow('pier', 'Pier Physiotherapy', '2026-10-14 08:00:00');

    $response = tenantsScreen($this, addOperator(), ['from' => 'yesterday', 'to' => '2026-10-13']);

    $response->assertInertia(fn (Assert $page) => $page->where('filters.from', '')->where('filters.to', '2026-10-13'));
    expect(tenantsScreenSlugs($response))->toBe(['acme']);
});

it('pages at twenty-five, newest first, a tie in the instant broken by the later row first', function () {
    for ($minute = 0; $minute < 26; $minute++) {
        tenantsScreenRow(sprintf('salon-%02d', $minute), sprintf('Salon %02d', $minute), sprintf('2026-10-01 09:%02d:00', $minute));
    }

    tenantsScreenRow('tie-first', 'Tie First', '2026-10-02 09:00:00');
    tenantsScreenRow('tie-second', 'Tie Second', '2026-10-02 09:00:00');
    $operator = addOperator();

    $first = tenantsScreenSlugs(tenantsScreen($this, $operator));
    $second = tenantsScreen($this, $operator, ['page' => '2']);

    expect($first)->toHaveCount(25)
        ->and(array_slice($first, 0, 4))->toBe(['tie-second', 'tie-first', 'salon-25', 'salon-24'])
        ->and(tenantsScreenSlugs($second))->toBe(['salon-02', 'salon-01', 'salon-00']);

    $second->assertInertia(fn (Assert $page) => $page->where('tenants.page', 2)->where('tenants.total', 28)->where('tenants.page_size', 25));

    expect(tenantsScreenSlugs(tenantsScreen($this, $operator, ['page' => '9'])))->toBe([]);
});

it('reads a page in two statements over tenants left join subscriptions, the count and the rows, whatever the page holds', function () {
    tenantsScreenShapes();
    $statements = [];
    DB::listen(function (QueryExecuted $query) use (&$statements): void {
        if (str_contains($query->sql, 'tenants')) {
            $statements[] = $query->sql;
        }
    });

    tenantsScreen($this, addOperator(), ['plan' => 'unlisted', 'search' => 'e'])->assertOk();

    expect($statements)->toHaveCount(2)
        ->and(array_filter($statements, fn (string $sql): bool => str_contains($sql, 'from tenants tenant left join subscriptions subscription')))->toHaveCount(2);
});

it('carries Tenants first on the rail, lit on the list, with Messages and Audit after it', function () {
    tenantsScreen($this, addOperator())->assertInertia(fn (Assert $page) => $page->where('nav', [
        ['label' => 'Tenants', 'url' => '/', 'group' => 'Platform', 'pending' => null, 'current' => true],
        ['label' => 'Messages', 'url' => '/messages', 'group' => 'Platform', 'pending' => null, 'current' => false],
        ['label' => 'Audit', 'url' => '/audit', 'group' => 'Platform', 'pending' => null, 'current' => false],
    ]));
});

it('sends a visitor with no operator session from the list to the door, whatever the query', function () {
    $this->get(consoleUrl('/'))->assertRedirect(consoleUrl('/login'));
    $this->get(consoleUrl('/?search=luna&status=active'))->assertRedirect(consoleUrl('/login'));
});
