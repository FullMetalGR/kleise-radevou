<?php

use App\Application\Clock;
use App\Billing\PlanChangeRefused;
use App\Http\ConsoleFeatures;
use App\Http\ConsoleSession;
use App\Http\Controllers\Console\TenantFeaturesController;
use App\Http\Requests\TenantFeaturesRequest;
use App\Platform\FeaturesRefused;
use App\Platform\Operator;
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
 * The console's Change features: POST /tenants/{slug}/features with one list of feature slugs,
 * admitted on a tenant in the free shape alone, no subscriptions row and no trial end, where one
 * statement writes the set and clears narrowed beside the platform audit row features_changed
 * carrying the set after. A row with a subscriptions row is refused, its plan billing's; removing
 * staff while more than one staff member is active, the implicit one counted, is refused with the
 * plan change's count sentence. The detail offers the control on the free shape alone. The tenant
 * row is taken for update before the subscriptions row is looked at.
 */

beforeEach(function () {
    $this->withoutVite();
    app()->instance(Clock::class, FixedClock::at('2026-10-15 09:00'));
});

/**
 * The console's POST of a set, as the operator given saves it from the tenant's detail.
 *
 * @param  array<mixed>  $body
 * @return TestResponse<Response>
 */
function featuresPost(TestCase $test, Operator $operator, string $slug, array $body): TestResponse
{
    return $test->actingAs($operator, ConsoleSession::GUARD)
        ->withSession(freshConsoleSession())
        ->from(consoleUrl('/tenants/'.$slug))
        ->post(consoleUrl('/tenants/'.$slug.'/features'), $body);
}

/**
 * The two set columns of a tenant's row, as their text.
 *
 * @return array<mixed>
 */
function featuresColumns(string $slug = 'acme'): array
{
    return (array) DB::selectOne('select entitlements, narrowed, subscription_state, plan_ref from tenants where slug = ?', [$slug]);
}

/**
 * Every platform audit row as its action, tenant and payload, the payload decoded.
 *
 * @return list<array<mixed>>
 */
function featuresAudit(): array
{
    $rows = [];

    foreach (DB::select('select operator_id, action, tenant_id, payload::text as payload from platform_audit_log order by id') as $row) {
        $row = (array) $row;
        $rows[] = [...$row, 'payload' => json_decode(is_string($row['payload'] ?? null) ? $row['payload'] : '', true, 512, JSON_THROW_ON_ERROR)];
    }

    return $rows;
}

/**
 * A second staff member on the tenant's roster, active unless said otherwise.
 */
function featuresMember(string $slug, string $name, string $status = 'active'): void
{
    DB::table(sprintf('tenant_%s.staff', $slug))->insert(['name' => $name, 'status' => $status]);
}

/**
 * A subscriptions row beside the tenant's, as a checkout or a webhook writes one.
 */
function featuresSubscribed(string $slug): void
{
    DB::table('subscriptions')->insert([
        'tenant_id' => Tenant::query()->where('slug', $slug)->value('id'),
        'provider_customer_id' => 'cus_'.$slug,
        'provider_subscription_id' => 'sub_'.$slug,
        'status' => 'active',
        'items' => '[]',
    ]);
}

it('writes the set on a free tenant and clears narrowed in one update, with features_changed carrying the set after, and lands on the detail with the success flash', function (string $held, string $saved, string $written) {
    $before = $held === '' ? [] : [$held];
    $chosen = $saved === '' ? [] : [$saved];
    provisionTenant('acme', features: $before);
    DB::table('tenants')->where('slug', 'acme')->update(['narrowed' => '{staff}']);
    $operator = addOperator();

    featuresPost($this, $operator, 'acme', ['features' => $chosen])
        ->assertRedirect(consoleUrl('/tenants/acme'))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('flash', ['kind' => 'success', 'message' => TenantFeaturesController::SAVED]);

    expect(featuresColumns())->toBe(['entitlements' => $written, 'narrowed' => '{}', 'subscription_state' => 'active', 'plan_ref' => null])
        ->and(featuresAudit())->toBe([
            ['operator_id' => $operator->id, 'action' => 'features_changed', 'tenant_id' => Tenant::query()->where('slug', 'acme')->value('id'), 'payload' => ['features' => $chosen]],
        ])
        ->and(DB::table('subscriptions')->count())->toBe(0);
})->with([
    'staff added to the floor' => ['', 'staff', '{staff}'],
    'staff removed with the implicit member alone active' => ['staff', '', '{}'],
    'the set saved as it stands' => ['staff', 'staff', '{staff}'],
]);

it('removes staff where the second member is inactive, since only active members count', function () {
    provisionTenant('acme', features: ['staff']);
    featuresMember('acme', 'Nikos', 'inactive');

    featuresPost($this, addOperator(), 'acme', ['features' => []])->assertSessionHas('flash.kind', 'success');

    expect(featuresColumns()['entitlements'] ?? null)->toBe('{}');
});

it('refuses removing staff while more than one staff member is active, the implicit one counted, with the plan change\'s count sentence, writing nothing', function () {
    provisionTenant('acme', features: ['staff']);
    featuresMember('acme', 'Elena');
    featuresMember('acme', 'Nikos');

    featuresPost($this, addOperator(), 'acme', ['features' => []])
        ->assertRedirect(consoleUrl('/tenants/acme'))
        ->assertSessionHas('flash', ['kind' => 'error', 'message' => adminChoice(PlanChangeRefused::DOWNGRADE_SENTENCE, 3)]);

    expect(featuresColumns()['entitlements'] ?? null)->toBe('{staff}')
        ->and(featuresAudit())->toBe([]);
});

it('refuses with "This account\'s plan belongs to billing." where {case}, writing nothing', function (Closure $arrange) {
    provisionTenant('acme', features: ['staff']);
    $arrange();
    $before = featuresColumns();

    featuresPost($this, addOperator(), 'acme', ['features' => []])
        ->assertRedirect(consoleUrl('/tenants/acme'))
        ->assertSessionHas('flash', ['kind' => 'error', 'message' => FeaturesRefused::BILLING]);

    expect(featuresColumns())->toBe($before)
        ->and(featuresAudit())->toBe([])
        ->and(FeaturesRefused::BILLING)->toBe('This account\'s plan belongs to billing.');
})->with([
    'a subscriptions row exists' => [fn () => featuresSubscribed('acme')],
    'a subscriptions row names no subscription yet, a checkout opened' => [fn () => DB::table('subscriptions')->insert(['tenant_id' => Tenant::query()->where('slug', 'acme')->value('id'), 'provider_customer_id' => 'cus_acme', 'status' => null, 'items' => '[]'])],
    'the tenant is in its trial, whose plan billing\'s checkout chooses' => [fn () => DB::table('tenants')->where('slug', 'acme')->update(['subscription_state' => 'trial', 'trial_ends_at' => '2026-10-20 09:00:00+00'])],
    'the trial ended unpaid' => [fn () => DB::table('tenants')->where('slug', 'acme')->update(['subscription_state' => 'canceled', 'trial_ends_at' => '2026-10-10 09:00:00+00'])],
    'the row is still provisioning, with no plan yet, whatever its shape' => [fn () => DB::table('tenants')->where('slug', 'acme')->update(['status' => 'provisioning'])],
    'canceled with no subscriptions row and no trial end, a frozen state no writer leaves' => [fn () => DB::table('tenants')->where('slug', 'acme')->update(['subscription_state' => 'canceled'])],
]);

it('refuses a body {case} on the field with its sentence, writing nothing', function (array $body) {
    provisionTenant('acme', features: ['staff']);

    featuresPost($this, addOperator(), 'acme', $body)
        ->assertRedirect(consoleUrl('/tenants/acme'))
        ->assertSessionHasErrors(['features' => TenantFeaturesRequest::BAD_FEATURES]);

    expect(featuresColumns()['entitlements'] ?? null)->toBe('{staff}')
        ->and(featuresAudit())->toBe([]);
})->with([
    'naming a slug outside the cases' => [['features' => ['staff', 'gold']]],
    'naming a slug that is no string' => [['features' => [7]]],
    'that is no list' => [['features' => 'staff']],
    'that is a map' => [['features' => ['first' => 'staff']]],
    'naming no list at all' => [[]],
]);

it('answers the console\'s 404 for {case}, writing nothing', function (string $slug) {
    Tenant::factory()->create(['slug' => 'support', 'name' => 'A row on a reserved label']);

    featuresPost($this, addOperator(), $slug, ['features' => ['staff']])
        ->assertNotFound()
        ->assertInertia(fn (Assert $page) => $page->component('console/NotFound'));

    expect(featuresAudit())->toBe([])
        ->and(featuresColumns('support')['entitlements'] ?? null)->toBe('{}');
})->with([
    'a slug no row holds' => ['nobody'],
    'a malformed slug' => ['acme_studio'],
    'a reserved slug a row holds' => ['support'],
]);

it('sends a visitor with no operator session to the door, writing nothing', function () {
    provisionTenant('acme');

    $this->post(consoleUrl('/tenants/acme/features'), ['features' => ['staff']])->assertRedirect(consoleUrl('/login'));

    expect(featuresColumns()['entitlements'] ?? null)->toBe('{}')
        ->and(featuresAudit())->toBe([]);
});

it('takes the tenant row for update before it looks at the subscriptions row', function () {
    provisionTenant('acme');
    $operator = addOperator();
    $statements = [];
    DB::listen(function (QueryExecuted $query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    featuresPost($this, $operator, 'acme', ['features' => ['staff']])->assertSessionHas('flash.kind', 'success');

    $locked = array_key_first(array_filter($statements, fn (string $sql): bool => str_contains($sql, 'from tenants') && str_contains($sql, 'for update')));
    $subscriptions = array_key_first(array_filter($statements, fn (string $sql): bool => str_contains($sql, '"subscriptions"') || str_contains($sql, 'from subscriptions')));

    expect($locked)->not->toBeNull()
        ->and($subscriptions)->not->toBeNull()
        ->and($locked < $subscriptions)->toBeTrue();
});

it('offers Change features on the detail of a free tenant alone, the set it holds and one choice per feature', function (Closure $arrange, ?array $features) {
    provisionTenant('acme', features: ['staff']);
    $arrange();

    $this->actingAs(addOperator(), ConsoleSession::GUARD)->withSession(freshConsoleSession())
        ->get(consoleUrl('/tenants/acme'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('console/Tenant')->where('features', $features));
})->with([
    'free, on staff' => [fn () => null, ['current' => ['staff'], 'offered' => ConsoleFeatures::choices()]],
    'free, on the floor' => [fn () => DB::table('tenants')->where('slug', 'acme')->update(['entitlements' => '{}']), ['current' => [], 'offered' => ConsoleFeatures::choices()]],
    'subscribed' => [fn () => featuresSubscribed('acme'), null],
    'in a trial' => [fn () => DB::table('tenants')->where('slug', 'acme')->update(['subscription_state' => 'trial', 'trial_ends_at' => '2026-10-20 09:00:00+00']), null],
]);
