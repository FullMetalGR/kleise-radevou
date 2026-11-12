<?php

use App\Application\Clock;
use App\Domain\Enum\BookingStatus;
use App\Http\ConsoleSession;
use App\Platform\ClosingWindow;
use App\Platform\Operator;
use App\Platform\TenantPlan;
use App\Platform\TenantReadout;
use App\Site\SiteLanguage;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Role;
use App\Tenancy\Slug;
use App\Tenancy\Tenant;
use App\Tenancy\TenantStatus;
use App\Tenancy\TenantUrls;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FixedClock;
use Tests\TestCase;

pest()->use(RefreshDatabase::class);

/*
 * One tenant read whole for the console's detail: the central row's columns, the plan TenantPlan
 * derives with the subscriptions row's facts where one exists, every users row, and from the
 * tenant's own schema, in one read-only transaction with the search path set locally, the count of
 * active services, of active staff members with the implicit one counted, of bookings per status,
 * and the latest booking's creation instant. A provisioning row has no schema and is read without
 * that transaction.
 */

beforeEach(function () {
    $this->withoutVite();
    app()->instance(Clock::class, FixedClock::at('2026-10-14 09:00'));
});

/**
 * The tenant a slug names, as the console's detail is handed it.
 */
function detailTenant(string $slug): Tenant
{
    return Tenant::query()->where('slug', $slug)->firstOrFail();
}

/**
 * Writes the central columns given onto a tenant's row, as a dataset spells them: by name, an
 * instant as UTC text with its offset.
 *
 * @param  array<mixed>  $columns
 */
function detailColumns(string $slug, array $columns): void
{
    $named = [];

    foreach ($columns as $column => $value) {
        $named[is_string($column) ? $column : throw new LogicException('Every column of the dataset is named.')] = $value;
    }

    if ($named !== []) {
        DB::table('tenants')->where('slug', $slug)->update($named);
    }
}

/**
 * The statements a closure ran, by their text, and where asked the readout's own transaction's
 * start and end among them as begin and rollback.
 *
 * @return list<string>
 */
function detailStatements(Closure $run, bool $withTransactions = false): array
{
    $statements = [];
    DB::listen(function (QueryExecuted $query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    if ($withTransactions) {
        Event::listen(TransactionBeginning::class, function () use (&$statements): void {
            $statements[] = 'begin';
        });
        Event::listen(TransactionRolledBack::class, function () use (&$statements): void {
            $statements[] = 'rollback';
        });
    }

    $run();

    return array_values(array_filter($statements, is_string(...)));
}

it('reads a provisioned tenant\'s counts from its own schema: the active services alone, the active staff with the implicit one, the bookings per status, and the last activity', function () {
    provisionTenant('luna', ['name' => 'Luna Hair Studio']);
    DB::table('tenant_luna.services')->insert([serviceRow('Cut'), serviceRow('Colour'), serviceRow('Perm', ['status' => 'inactive'])]);
    DB::table('tenant_luna.staff')->insert([['name' => 'Elena', 'status' => 'active'], ['name' => 'Nikos', 'status' => 'inactive']]);
    DB::table('tenant_luna.bookings')->insert([
        [...bookingRow(), 'status' => 'confirmed', 'created_at' => '2026-10-10 08:00:00+00:00'],
        [...bookingRow(), 'status' => 'confirmed', 'created_at' => '2026-10-12 17:45:30+00:00'],
        [...bookingRow(), 'status' => 'cancelled', 'created_at' => '2026-10-11 09:00:00+00:00'],
        [...bookingRow(), 'status' => 'awaiting_approval', 'created_at' => '2026-10-09 09:00:00+00:00'],
    ]);

    $activity = app(TenantReadout::class)->of(detailTenant('luna'))->activity;

    expect($activity)->not->toBeNull()
        ->and($activity?->services)->toBe(2)
        ->and($activity?->activeStaff)->toBe(2)
        ->and($activity?->bookings)->toBe([
            'pending' => 0,
            'awaiting_approval' => 1,
            'awaiting_payment' => 0,
            'confirmed' => 2,
            'completed' => 0,
            'no_show' => 0,
            'cancelled' => 1,
            'rejected' => 0,
            'expired' => 0,
        ])
        ->and($activity?->lastActivityAt?->format(DATE_ATOM))->toBe('2026-10-12T17:45:30+00:00');
});

it('reads a tenant with no bookings as nought of each status and no last activity, the implicit member its one active staff member', function () {
    provisionTenant('luna');

    $activity = app(TenantReadout::class)->of(detailTenant('luna'))->activity;

    expect($activity?->services)->toBe(0)
        ->and($activity?->activeStaff)->toBe(1)
        ->and(array_sum($activity->bookings ?? []))->toBe(0)
        ->and(array_keys($activity->bookings ?? []))->toBe(array_map(fn (BookingStatus $status): string => $status->value, BookingStatus::cases()))
        ->and($activity?->lastActivityAt)->toBeNull();
});

it('reads a provisioning row\'s central facts and no counts, opening no transaction on a schema it does not have', function () {
    $tenant = Tenant::factory()->create(['slug' => 'reserved', 'name' => 'Reserved Salon']);
    detailColumns('reserved', ['status' => 'provisioning', 'subscription_state' => 'incomplete', 'created_at' => '2026-10-14 08:30:00+00:00']);

    $statements = detailStatements(fn () => app(TenantReadout::class)->of($tenant));
    $facts = app(TenantReadout::class)->of($tenant);

    expect($facts->status)->toBe(TenantStatus::Provisioning)
        ->and($facts->name)->toBe('Reserved Salon')
        ->and($facts->signedUpAt->format(DATE_ATOM))->toBe('2026-10-14T08:30:00+00:00')
        ->and($facts->plan)->toBeNull()
        ->and($facts->activity)->toBeNull()
        ->and(array_filter($statements, fn (string $sql): bool => str_contains($sql, 'search_path') || str_contains($sql, 'read only')))->toBe([]);
});

it('reads each row\'s instants as the row holds them: {case}', function (array $columns, array $expected) {
    provisionTenant('luna');
    detailColumns('luna', $columns);

    $facts = app(TenantReadout::class)->of(detailTenant('luna'));

    expect([
        'status' => $facts->status->value,
        'state' => $facts->state->value,
        'signed_up' => $facts->signedUpAt->format(DATE_ATOM),
        'trial_ends' => $facts->trialEndsAt?->format(DATE_ATOM),
        'grace_ends' => $facts->graceEndsAt?->format(DATE_ATOM),
        'closing' => $facts->closingAt?->format(DATE_ATOM),
    ])->toBe($expected)
        ->and($facts->activity)->not->toBeNull();
})->with([
    'a trial' => [
        ['subscription_state' => 'trial', 'trial_ends_at' => '2026-10-19 21:15:00+00:00', 'created_at' => '2026-10-12 21:15:00+00:00'],
        ['status' => 'active', 'state' => 'trial', 'signed_up' => '2026-10-12T21:15:00+00:00', 'trial_ends' => '2026-10-19T21:15:00+00:00', 'grace_ends' => null, 'closing' => null],
    ],
    'a grace' => [
        ['subscription_state' => 'grace', 'grace_ends_at' => '2026-10-20 00:00:00+00:00', 'created_at' => '2026-09-01 10:00:00+00:00'],
        ['status' => 'active', 'state' => 'grace', 'signed_up' => '2026-09-01T10:00:00+00:00', 'trial_ends' => null, 'grace_ends' => '2026-10-20T00:00:00+00:00', 'closing' => null],
    ],
    'a trial that ended unpaid, canceled' => [
        ['subscription_state' => 'canceled', 'trial_ends_at' => '2026-10-05 08:00:00+00:00', 'created_at' => '2026-09-28 08:00:00+00:00'],
        ['status' => 'active', 'state' => 'canceled', 'signed_up' => '2026-09-28T08:00:00+00:00', 'trial_ends' => '2026-10-05T08:00:00+00:00', 'grace_ends' => null, 'closing' => null],
    ],
    'a closing row, read as an active one' => [
        ['status' => 'closing', 'subscription_state' => 'canceled', 'trial_ends_at' => '2026-09-05 08:00:00+00:00', 'closing_at' => '2026-10-05 08:00:00.250000+00:00', 'created_at' => '2026-08-29 08:00:00+00:00'],
        ['status' => 'closing', 'state' => 'canceled', 'signed_up' => '2026-08-29T08:00:00+00:00', 'trial_ends' => '2026-09-05T08:00:00+00:00', 'grace_ends' => null, 'closing' => '2026-10-05T08:00:00+00:00'],
    ],
    'a suspended row, read as an active one' => [
        ['status' => 'suspended', 'created_at' => '2026-09-01 10:00:00+00:00'],
        ['status' => 'suspended', 'state' => 'active', 'signed_up' => '2026-09-01T10:00:00+00:00', 'trial_ends' => null, 'grace_ends' => null, 'closing' => null],
    ],
]);

it('reads the plan and the subscriptions row\'s facts: {case}', function (array $columns, ?array $subscription, TenantPlan $plan, ?array $expected) {
    provisionTenant('luna');
    detailColumns('luna', $columns);

    if ($subscription !== null) {
        DB::table('subscriptions')->insert(['tenant_id' => detailTenant('luna')->id, ...$subscription]);
    }

    $facts = app(TenantReadout::class)->of(detailTenant('luna'));

    expect($facts->plan)->toBe($plan)
        ->and($facts->subscription === null ? null : [
            'customer' => $facts->subscription->customerId,
            'status' => $facts->subscription->status,
            'period_end' => $facts->subscription->currentPeriodEnd?->format(DATE_ATOM),
        ])->toBe($expected);
})->with([
    'free, as tenant:provision writes it, with no subscriptions row' => [[], null, TenantPlan::Free, null],
    'floor by its price' => [
        ['plan_ref' => 'price_floor_test'],
        ['provider_customer_id' => 'cus_floor', 'provider_subscription_id' => 'sub_floor', 'status' => 'active', 'current_period_end' => '2026-11-01 09:00:00+00:00'],
        TenantPlan::Floor,
        ['customer' => 'cus_floor', 'status' => 'active', 'period_end' => '2026-11-01T09:00:00+00:00'],
    ],
    'staff by its price, past due' => [
        ['plan_ref' => 'price_staff_test', 'subscription_state' => 'past_due'],
        ['provider_customer_id' => 'cus_staff', 'provider_subscription_id' => 'sub_staff', 'status' => 'past_due', 'current_period_end' => '2026-10-10 12:00:00+00:00'],
        TenantPlan::Staff,
        ['customer' => 'cus_staff', 'status' => 'past_due', 'period_end' => '2026-10-10T12:00:00+00:00'],
    ],
    'a customer recorded before any snapshot, no status and no period yet' => [
        ['subscription_state' => 'incomplete'],
        ['provider_customer_id' => 'cus_new'],
        TenantPlan::Unlisted,
        ['customer' => 'cus_new', 'status' => null, 'period_end' => null],
    ],
]);

it('reads every user in the order they were made, by id, with the role and whether the email was verified', function () {
    provisionTenant('luna', ['ownerName' => 'Alex Owner', 'ownerEmail' => 'owner@luna.example']);
    $tenant = detailTenant('luna');
    DB::table('users')->insert([
        ['tenant_id' => $tenant->id, 'name' => 'Maria Manager', 'email' => 'maria@luna.example', 'password' => 'x', 'role' => 'manager', 'email_verified_at' => '2026-10-01 09:00:00+00:00'],
        ['tenant_id' => $tenant->id, 'name' => 'Sam Staff', 'email' => 'sam@luna.example', 'password' => 'x', 'role' => 'staff', 'email_verified_at' => null],
    ]);
    Tenant::factory()->create(['slug' => 'other'])->users()->create(['name' => 'Elsewhere', 'email' => 'else@other.example', 'password' => 'x', 'role' => Role::Owner]);

    $users = array_map(fn ($person): array => [$person->id, $person->name, $person->email, $person->role, $person->verified], app(TenantReadout::class)->of($tenant)->users);

    expect($users)->toBe([
        [tenantUserId('owner@luna.example'), 'Alex Owner', 'owner@luna.example', Role::Owner, false],
        [tenantUserId('maria@luna.example'), 'Maria Manager', 'maria@luna.example', Role::Manager, true],
        [tenantUserId('sam@luna.example'), 'Sam Staff', 'sam@luna.example', Role::Staff, false],
    ]);
});

it('reads the schema in one read-only transaction on the tenant\'s path, writes nothing, binds no tenant and leaves the connection\'s path central', function () {
    provisionTenant('luna');
    $tenant = detailTenant('luna');
    $central = DB::scalar("select current_setting('search_path')");
    $bound = [];
    DB::listen(function () use (&$bound): void {
        try {
            app(CurrentTenant::class)->get();
            $bound[] = true;
        } catch (LogicException) {
        }
    });

    $statements = detailStatements(fn () => app(TenantReadout::class)->of($tenant), withTransactions: true);
    $begin = array_search('begin', $statements, true);
    $end = array_search('rollback', $statements, true);

    expect(array_filter($statements, fn (string $sql): bool => preg_match('/^\s*(insert|update|delete)\b/i', $sql) === 1))->toBe([])
        ->and(array_count_values($statements)['begin'] ?? 0)->toBe(1)
        ->and($begin)->toBeInt()
        ->and(array_slice($statements, (int) $begin + 1, 3))->toBe([
            'set transaction read only',
            'set local search_path to "tenant_luna", "public"',
            'select current_schema()',
        ])
        ->and(array_filter(array_slice($statements, 0, (int) $begin), fn (string $sql): bool => preg_match('/\b(services|staff|bookings)\b/', $sql) === 1))->toBe([])
        ->and(array_filter(array_slice($statements, (int) $end), fn (string $sql): bool => preg_match('/\b(services|staff|bookings)\b/', $sql) === 1))->toBe([])
        ->and(DB::scalar("select current_setting('search_path')"))->toBe($central)
        ->and(DB::scalar("select current_setting('transaction_read_only')"))->toBe('off')
        ->and($bound)->toBe([]);
});

/**
 * The detail as the operator given asks for it.
 *
 * @return TestResponse<Response>
 */
function detailScreen(TestCase $test, Operator $operator, string $slug): TestResponse
{
    return $test->actingAs($operator, ConsoleSession::GUARD)
        ->withSession(freshConsoleSession())
        ->get(consoleUrl('/tenants/'.$slug));
}

/**
 * The two links out to a tenant's origin and the audit narrowed to it, as the detail carries them.
 *
 * @return array{hosted_page: string, sign_in: string, audit: string}
 */
function detailLinks(string $slug): array
{
    $urls = app(TenantUrls::class);

    return ['hosted_page' => $urls->hostedPage(Slug::of($slug)), 'sign_in' => $urls->signIn(Slug::of($slug)), 'audit' => '/audit?tenant='.$slug];
}

it('renders the detail with every instant on the console\'s clock, the plan, the people, the counts, the last activity and the links', function () {
    provisionTenant('luna', ['name' => 'Luna Hair Studio', 'ownerName' => 'Alex Owner', 'ownerEmail' => 'owner@luna.example']);
    detailColumns('luna', ['subscription_state' => 'trial', 'trial_ends_at' => '2026-10-19 21:15:00+00:00', 'created_at' => '2026-10-12 21:15:00+00:00', 'category' => 'hair_salon']);
    DB::table('tenant_luna.services')->insert([serviceRow('Cut'), serviceRow('Colour')]);
    DB::table('tenant_luna.bookings')->insert([
        [...bookingRow(), 'status' => 'confirmed', 'created_at' => '2026-10-13 06:05:00+00:00'],
        [...bookingRow(), 'status' => 'awaiting_payment', 'created_at' => '2026-10-13 09:30:00+00:00'],
    ]);

    detailScreen($this, addOperator(), 'luna')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('console/Tenant')
            ->where('tenant', [
                'slug' => 'luna',
                'name' => 'Luna Hair Studio',
                'status' => 'active',
                'timezone' => 'Europe/Athens',
                'currency' => 'EUR',
                'category' => 'Hair salon',
                'signed_up' => 'Tuesday 13 October 2026 at 00:15',
                'trial_ends' => 'Tuesday 20 October 2026 at 00:15',
                'grace_ends' => null,
                'closing' => null,
                'closed' => null,
                'suspension' => null,
                'terms' => null,
                'state' => 'trial',
                'plan' => 'trial',
                'subscription' => null,
                'users' => [['id' => tenantUserId('owner@luna.example'), 'name' => 'Alex Owner', 'email' => 'owner@luna.example', 'role' => 'owner', 'verified' => false]],
                'activity' => [
                    'services' => 2,
                    'staff' => 1,
                    'bookings' => [
                        ['status' => 'pending', 'count' => 0],
                        ['status' => 'awaiting_approval', 'count' => 0],
                        ['status' => 'awaiting_payment', 'count' => 1],
                        ['status' => 'confirmed', 'count' => 1],
                        ['status' => 'completed', 'count' => 0],
                        ['status' => 'no_show', 'count' => 0],
                        ['status' => 'cancelled', 'count' => 0],
                        ['status' => 'rejected', 'count' => 0],
                        ['status' => 'expired', 'count' => 0],
                    ],
                    'last' => 'Tuesday 13 October 2026 at 12:30',
                ],
            ])
            ->where('links', detailLinks('luna'))
            ->where('zone', 'Europe/Athens'));
});

it('shows a closing row\'s close beside the day its retention window ends, thirty days on, on the console\'s clock across the clock change, in English for a Greek tenant', function () {
    provisionTenant('luna');
    detailColumns('luna', ['status' => 'closing', 'subscription_state' => 'canceled', 'trial_ends_at' => '2026-09-05 08:00:00+00:00', 'closing_at' => '2026-10-05 08:00:00+00:00', 'locale' => SiteLanguage::Greek->value]);
    // The tenant and its owner read Greek; the console stays English, so the day's month and
    // weekday are ICU's English ones, never the Greek.
    DB::table('users')->where('email', 'owner@luna.example')->update(['locale' => SiteLanguage::Greek->value]);
    $closingAt = new DateTimeImmutable('2026-10-05 08:00:00', new DateTimeZone('UTC'));
    $zone = new DateTimeZone('Europe/Athens');

    expect(ClosingWindow::dayOf($closingAt, $zone, SiteLanguage::English))->toBe('Wednesday 4 November 2026')
        ->and(ClosingWindow::dayOf($closingAt, $zone, SiteLanguage::Greek))->not->toBe('Wednesday 4 November 2026');

    detailScreen($this, addOperator(), 'luna')->assertInertia(fn (Assert $page) => $page
        ->where('tenant.status', 'closing')
        ->where('tenant.trial_ends', 'Saturday 5 September 2026 at 11:00')
        ->where('tenant.closing', ['at' => 'Monday 5 October 2026 at 11:00', 'retention_ends' => 'Wednesday 4 November 2026 at 10:00', 'deleted_on' => 'Wednesday 4 November 2026'])
        ->where('tenant.plan', 'unlisted')
        ->where('tenant.activity.staff', 1));
});

it('shows a grace row\'s end and a subscriber\'s customer, provider status and period end on the console\'s clock', function () {
    provisionTenant('luna');
    detailColumns('luna', ['subscription_state' => 'grace', 'grace_ends_at' => '2026-10-20 22:00:00+00:00', 'plan_ref' => 'price_staff_test']);
    DB::table('subscriptions')->insert(['tenant_id' => detailTenant('luna')->id, 'provider_customer_id' => 'cus_luna', 'provider_subscription_id' => 'sub_luna', 'status' => 'past_due', 'current_period_end' => '2026-11-01 09:00:00+00:00']);

    detailScreen($this, addOperator(), 'luna')->assertInertia(fn (Assert $page) => $page
        ->where('tenant.state', 'grace')
        ->where('tenant.grace_ends', 'Wednesday 21 October 2026 at 01:00')
        ->where('tenant.plan', 'staff')
        ->where('plan_names', [['plan' => 'floor', 'name' => 'Solo'], ['plan' => 'staff', 'name' => 'Team']])
        ->where('tenant.subscription', ['customer' => 'cus_luna', 'status' => 'past_due', 'period_end' => 'Sunday 1 November 2026 at 11:00']));
});

it('names the plans from config/billing.php for the detail to word, a renamed plan followed', function () {
    provisionTenant('luna');
    detailColumns('luna', ['plan_ref' => 'price_floor_test']);
    DB::table('subscriptions')->insert(['tenant_id' => detailTenant('luna')->id, 'provider_customer_id' => 'cus_luna', 'provider_subscription_id' => 'sub_luna', 'status' => 'active', 'current_period_end' => '2026-11-01 09:00:00+00:00']);
    config(['billing.plans.floor.name' => 'Starter']);

    detailScreen($this, addOperator(), 'luna')->assertInertia(fn (Assert $page) => $page
        ->where('tenant.plan', 'floor')
        ->where('plan_names', [['plan' => 'floor', 'name' => 'Starter'], ['plan' => 'staff', 'name' => 'Team']]));
});

it('shows a suspended row\'s instant and reason on the console\'s clock, read from the two columns, and none on a row the resume cleared', function () {
    provisionTenant('luna');
    detailColumns('luna', ['status' => 'suspended', 'suspended_at' => '2026-10-14 06:00:00.500000+00:00', 'suspension_reason' => 'Chargebacks under review.']);

    expect(app(TenantReadout::class)->of(detailTenant('luna'))->suspension?->at->format('Y-m-d H:i:s.u P'))->toBe('2026-10-14 06:00:00.500000 +00:00');

    detailScreen($this, addOperator(), 'luna')->assertInertia(fn (Assert $page) => $page
        ->where('tenant.status', 'suspended')
        ->where('tenant.suspension', ['at' => 'Wednesday 14 October 2026 at 09:00', 'reason' => 'Chargebacks under review.'])
        ->where('tenant.activity.staff', 1));

    detailColumns('luna', ['status' => 'active', 'suspended_at' => null, 'suspension_reason' => null]);

    detailScreen($this, addOperator('pat2@inbox.example'), 'luna')->assertInertia(fn (Assert $page) => $page
        ->where('tenant.status', 'active')
        ->where('tenant.suspension', null));
});

it('shows the owner\'s acceptance of the terms as one fact, the instant on the console\'s clock and the version\'s day beside it, read from the two columns, and none where the row records none', function () {
    provisionTenant('luna');
    detailColumns('luna', ['terms_accepted_at' => '2026-10-12 21:15:00.250000+00:00', 'terms_version' => '2026-10-26']);

    $facts = app(TenantReadout::class)->of(detailTenant('luna'));

    expect($facts->terms?->at->format('Y-m-d H:i:s.u P'))->toBe('2026-10-12 21:15:00.250000 +00:00')
        ->and($facts->terms?->version)->toBe('2026-10-26');

    detailScreen($this, addOperator(), 'luna')->assertInertia(fn (Assert $page) => $page
        ->where('tenant.terms', ['at' => 'Tuesday 13 October 2026 at 00:15', 'version' => '26 October 2026']));

    detailColumns('luna', ['terms_accepted_at' => null, 'terms_version' => null]);

    expect(app(TenantReadout::class)->of(detailTenant('luna'))->terms)->toBeNull();

    detailScreen($this, addOperator('pat2@inbox.example'), 'luna')->assertInertia(fn (Assert $page) => $page->where('tenant.terms', null));
});

it('renders a provisioning row with its status, no plan and no counts', function () {
    Tenant::factory()->create(['slug' => 'reserved', 'name' => 'Reserved Salon']);
    detailColumns('reserved', ['status' => 'provisioning', 'subscription_state' => 'incomplete', 'created_at' => '2026-10-14 08:30:00+00:00']);

    detailScreen($this, addOperator(), 'reserved')->assertOk()->assertInertia(fn (Assert $page) => $page->component('console/Tenant')
        ->where('tenant.status', 'provisioning')
        ->where('tenant.signed_up', 'Wednesday 14 October 2026 at 11:30')
        ->where('tenant.plan', null)
        ->where('tenant.activity', null)
        ->where('links', detailLinks('reserved')));
});

it('lights Tenants on the rail on a tenant\'s detail', function () {
    provisionTenant('luna');

    detailScreen($this, addOperator(), 'luna')->assertInertia(fn (Assert $page) => $page->where('nav', [
        ['label' => 'Tenants', 'url' => '/', 'group' => 'Platform', 'pending' => null, 'current' => true],
        ['label' => 'Messages', 'url' => '/messages', 'group' => 'Platform', 'pending' => null, 'current' => false],
        ['label' => 'Audit', 'url' => '/audit', 'group' => 'Platform', 'pending' => null, 'current' => false],
    ]));
});

it('answers the console\'s 404 inside its shell for {case}, the rail lighting no entry though the route is named under Tenants', function (string $slug) {
    Tenant::factory()->create(['slug' => 'support', 'name' => 'A row on a reserved label']);

    detailScreen($this, addOperator(), $slug)
        ->assertNotFound()
        ->assertInertia(fn (Assert $page) => $page->component('console/NotFound')->where('operator', 'Pat Operator')->has('nav', 3)
            ->where('nav', fn (Collection $nav): bool => $nav->where('current', true)->isEmpty()));
})->with([
    'a slug no row holds' => ['nobody'],
    'a malformed slug' => ['luna_studio'],
    'a slug past the ceiling' => [str_repeat('a', 57)],
    'a reserved slug, a row holding it or not' => ['support'],
]);

it('reads a closed row\'s central facts, no slug, no users and no counts, opening no transaction on the schema the drop removed', function () {
    provisionTenant('luna', ['name' => 'Luna Hair Studio']);
    detailColumns('luna', ['status' => 'closing', 'closing_at' => '2026-09-01 08:00:00+00:00']);
    $id = writeClosed('luna');
    DB::table('tenants')->where('id', $id)->update(['closed_at' => '2026-10-01 01:30:00+00:00']);
    $tenant = Tenant::query()->findOrFail($id);

    $statements = detailStatements(fn () => app(TenantReadout::class)->of($tenant));
    $facts = app(TenantReadout::class)->of($tenant);

    expect($facts->status)->toBe(TenantStatus::Closed)
        ->and($facts->slug)->toBeNull()
        ->and($facts->name)->toBe('Luna Hair Studio')
        ->and($facts->closingAt?->format(DATE_ATOM))->toBe('2026-09-01T08:00:00+00:00')
        ->and($facts->closedAt?->format(DATE_ATOM))->toBe('2026-10-01T01:30:00+00:00')
        ->and($facts->users)->toBe([])
        ->and($facts->activity)->toBeNull()
        ->and(array_filter($statements, fn (string $sql): bool => str_contains($sql, 'search_path') || str_contains($sql, 'read only')))->toBe([]);
});

it('renders a closed row at its id, its instants on the console\'s clock, offering no link, no features and no support session', function () {
    provisionTenant('luna', ['name' => 'Luna Hair Studio']);
    detailColumns('luna', ['status' => 'closing', 'closing_at' => '2026-09-01 08:00:00+00:00']);
    $id = writeClosed('luna');
    DB::table('tenants')->where('id', $id)->update(['closed_at' => '2026-10-01 01:30:00+00:00']);

    $this->actingAs(addOperator(), ConsoleSession::GUARD)
        ->withSession(freshConsoleSession())
        ->get(consoleUrl('/tenants/closed/'.$id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('console/Tenant')
            ->where('tenant.slug', null)
            ->where('tenant.name', 'Luna Hair Studio')
            ->where('tenant.status', 'closed')
            ->where('tenant.closing', ['at' => 'Tuesday 1 September 2026 at 11:00', 'retention_ends' => 'Thursday 1 October 2026 at 11:00', 'deleted_on' => 'Thursday 1 October 2026'])
            ->where('tenant.closed', 'Thursday 1 October 2026 at 04:30')
            ->where('tenant.users', [])
            ->where('tenant.activity', null)
            ->where('links', null)
            ->where('features', null)
            ->where('support_sessions', [])
            ->where('nav.0.current', true));
});

it('answers the console\'s 404 at /tenants/closed for {case}', function (string $id) {
    provisionTenant('luna');

    $this->actingAs(addOperator(), ConsoleSession::GUARD)
        ->withSession(freshConsoleSession())
        ->get(consoleUrl('/tenants/closed/'.str_replace('{luna}', (string) detailTenant('luna')->id, $id)))
        ->assertNotFound()
        ->assertInertia(fn (Assert $page) => $page->component('console/NotFound'));
})->with([
    'the id of a row that is not closed, which its slug addresses' => ['{luna}'],
    'an id no row holds' => ['424242'],
    'an id that is no number' => ['luna'],
    'an id past a bigint' => [str_repeat('9', 20)],
]);

it('reads the slug whatever its case, as the column does', function () {
    provisionTenant('luna');

    detailScreen($this, addOperator(), 'LUNA')->assertOk()->assertInertia(fn (Assert $page) => $page->component('console/Tenant')->where('tenant.slug', 'luna'));
});

it('opens no write and leaves the connection\'s search path central over the whole request', function () {
    provisionTenant('luna');
    DB::table('tenant_luna.bookings')->insert([...bookingRow(), 'created_at' => '2026-10-13 06:05:00+00:00']);
    $operator = addOperator();
    $central = DB::scalar("select current_setting('search_path')");

    DB::flushQueryLog();
    DB::enableQueryLog();
    detailScreen($this, $operator, 'luna')->assertOk();
    $log = DB::getQueryLog();
    DB::disableQueryLog();

    expect(array_filter(array_column($log, 'query'), fn (string $sql): bool => preg_match('/^\s*(insert|update|delete)\b/i', $sql) === 1))->toBe([])
        ->and(array_column($log, 'query'))->toContain('set transaction read only')
        ->and(DB::scalar("select current_setting('search_path')"))->toBe($central);
});

it('sends a visitor with no operator session from a tenant\'s detail to the door', function () {
    provisionTenant('luna');

    $this->get(consoleUrl('/tenants/luna'))->assertRedirect(consoleUrl('/login'));
    $this->get(consoleUrl('/tenants/nobody'))->assertRedirect(consoleUrl('/login'));
});
