<?php

use App\Application\Clock;
use App\Billing\BillingNotConfigured;
use App\Billing\BillingProviderFailed;
use App\Billing\Entitlements;
use App\Billing\NoticeKind;
use App\Billing\PlanCatalogue;
use App\Billing\PlanChange;
use App\Billing\PlanChangeRefused;
use App\Billing\ResubscribeRefused;
use App\Billing\SignupCompletion;
use App\Billing\SnapshotItem;
use App\Billing\SubscriptionSnapshot;
use App\Billing\SubscriptionState;
use App\Billing\TaxId;
use App\Http\Controllers\Admin\BillingController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\ErrorEnvelope;
use App\Http\Middleware\RequiresActiveSubscription;
use App\Http\Requests\ChangePlanRequest;
use App\Http\Requests\CheckoutRequest;
use App\Models\User;
use App\Platform\CloseRefused;
use App\Platform\ClosingWindow;
use App\Platform\TenantPlan;
use App\Site\SiteLanguage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Psr\Log\LoggerInterface;
use Tests\Support\BillingPortDecorator;
use Tests\Support\FakeBillingProvider;
use Tests\Support\FixedClock;
use Tests\Support\SeamLog;
use Tests\TestCase;

pest()->use(RefreshDatabase::class);

/*
 * The Billing screen: reachable by an owner in every one of the six subscription states and by
 * nobody else, rendered from props with no call to the provider, one whole sentence per state,
 * and the two writes, the plan change and the portal, both through the port and neither gated
 * by the subscription. The provider every case meets is the fake the test environment binds; a
 * case needing it to refuse or to race wraps it in BillingPortDecorator.
 */

beforeEach(function () {
    // Every case here spells its dates against this clock, the grace end and the trial's among
    // them; a case that needs another instant binds its own.
    app()->instance(Clock::class, FixedClock::at('2026-10-05 09:00'));
    $this->withoutVite();
    provisionTenant('luna', ['name' => 'Luna Hair Studio', 'ownerEmail' => 'owner@luna.example']);
});

function billingOwner(): User
{
    return User::query()->where('email', 'owner@luna.example')->sole();
}

/**
 * A line of the billing section in the language named, English unless named, its placeholders
 * filled.
 *
 * @param  array<string, string>  $replace
 */
function billingLine(string $key, array $replace = [], SiteLanguage $language = SiteLanguage::English): string
{
    $line = adminLine($key, $replace, $language);

    // A Greek line read is lang/el's own, never the English the fallback would hand back.
    if ($language === SiteLanguage::Greek) {
        expect($line)->not->toBe(adminLine($key, $replace), $key);
    }

    return $line;
}

/**
 * A plan change's refusal in the language named, English unless named, read through the
 * translator by the key and the values it carries, a floor refusal chosen by its active members.
 */
function billingRefusal(PlanChangeRefused $refused, SiteLanguage $language = SiteLanguage::English): string
{
    $read = fn (SiteLanguage $in): string => $refused->count === null
        ? adminLine($refused->key, $refused->values, $in)
        : adminChoice($refused->key, $refused->count, [...$refused->values, 'count' => (string) $refused->count], $in);

    if ($language === SiteLanguage::Greek) {
        expect($read($language))->not->toBe($read(SiteLanguage::English), $refused->key);
    }

    return $read($language);
}

/**
 * What a plan card lists in the language named, English unless named: the floor's two lines and
 * the staff line given.
 *
 * @return list<string>
 */
function billingIncludes(string $staff, SiteLanguage $language = SiteLanguage::English): array
{
    return array_map(fn (string $key): string => billingLine($key, [], $language), [...BillingController::FLOOR_INCLUDES, $staff]);
}

/**
 * luna's owner reading the admin in the language given, so a case run over both languages reads
 * each line as that owner does.
 */
function billingReader(SiteLanguage $language): User
{
    billingOwner()->forceFill(['locale' => $language->value])->save();

    return billingOwner();
}

/**
 * The Billing screen's full load for luna's owner reading in the language given, its root element
 * in that language.
 *
 * @return TestResponse<Response>
 */
function billingPage(TestCase $test, SiteLanguage $language): TestResponse
{
    $response = $test->actingAs(billingReader($language))->get(tenantUrl('luna', '/admin/billing'))->assertOk();

    expect((string) $response->getContent())->toContain('<html lang="'.$language->value.'">');

    return $response;
}

/**
 * luna subscribed at the provider on one plan: the subscriptions row naming sub_luna, the tenant
 * row as the sync would have left it, and the fake answering the same subscription.
 */
function billingOnPlan(string $plan, string $state = 'active'): void
{
    $price = config("billing.plans.{$plan}.price");
    $features = config("billing.plans.{$plan}.features");
    $price = is_string($price) ? $price : throw new LogicException("No price is configured for {$plan}.");
    $entitlements = '{'.implode(',', is_array($features) ? array_filter($features, is_string(...)) : []).'}';
    $tenant = DB::table('tenants')->where('slug', 'luna')->value('id');

    DB::table('subscriptions')->insert([
        'tenant_id' => $tenant,
        'provider_customer_id' => 'cus_luna',
        'provider_subscription_id' => 'sub_luna',
        'status' => 'active',
        'items' => json_encode([['price' => $price, 'quantity' => 1]], JSON_THROW_ON_ERROR),
    ]);
    billingSet([
        'plan_ref' => $price,
        'entitlements' => $entitlements,
        'subscription_state' => $state,
        'grace_ends_at' => $state === 'grace' ? '2026-10-06 22:30:00+00' : null,
        // The table holds a trial to its end (tenants_trial_ends_check).
        'trial_ends_at' => $state === 'trial' ? '2026-10-08 09:00:00+00' : null,
    ]);
    billingFake()->script(billingSnapshot('sub_luna', 'cus_luna', 'active', [$price]));
}

function billingMember(string $name, string $status = 'active'): void
{
    DB::table('tenant_luna.staff')->insert(['name' => $name, 'status' => $status, 'sort' => 1]);
}

/**
 * @return list<string>
 */
function billingChanges(): array
{
    return array_values(array_map(
        fn (array $question): string => is_string($question[1]['plan'] ?? null) ? $question[1]['plan'] : '',
        array_filter(billingFake()->asked, fn (array $question): bool => $question[0] === 'changePlan'),
    ));
}

/**
 * The rail as the nav prop hands it to a where() closure, a collection of entries.
 *
 * @return list<mixed>
 */
function billingRail(mixed $nav): array
{
    return array_values($nav instanceof Collection ? $nav->all() : (is_array($nav) ? $nav : []));
}

function billingRailHas(mixed $nav, string $label): bool
{
    return in_array($label, array_map(fn (mixed $entry): mixed => is_array($entry) ? ($entry['label'] ?? null) : null, billingRail($nav)), true);
}

function billingLog(): SeamLog
{
    $log = new SeamLog;
    app()->instance(LoggerInterface::class, $log);

    return $log;
}

dataset('billing readers', [
    'the owner at en' => [SiteLanguage::English],
    'the owner at el' => [SiteLanguage::Greek],
]);

it('puts Billing in an owner\'s rail under Business, current on its own screen, and in nobody else\'s', function () {
    billingOnPlan('floor');

    $this->actingAs(billingOwner())->get(tenantUrl('luna', '/admin/billing'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Billing')
            ->where('nav', fn (mixed $nav): bool => in_array(['label' => navLabel('billing'), 'url' => '/admin/billing', 'group' => navLabel('groups.business'), 'pending' => null, 'current' => true], billingRail($nav), true)));

    $manager = addTenantUser('manager@luna.example', 'manager');

    $this->actingAs(User::query()->findOrFail($manager))->get(tenantUrl('luna', '/admin/settings'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('nav', fn (mixed $nav): bool => ! billingRailHas($nav, navLabel('billing'))));
    $this->actingAs(User::query()->findOrFail($manager))->get(tenantUrl('luna', '/admin/billing'))->assertForbidden();
});

it('renders each state its own sentence and kind, the paused paragraph off active alone, the grace end in grace alone, with Billing still in the rail, in the reader\'s language', function (string $state, string $kind, string $sentence, ?string $paused, SiteLanguage $language) {
    billingOnPlan('floor', $state);

    billingPage($this, $language)
        ->assertInertia(fn (Assert $page) => $page->component('Billing')
            ->where('billing.notice', ['kind' => $kind, 'sentence' => billingLine($sentence, [], $language), 'paused' => $paused === null ? null : billingLine($paused, [], $language)])
            ->where('subscription', $state === 'grace' ? ['state' => 'grace', 'grace_ends_at' => '2026-10-06T22:30:00+00:00'] : ['state' => $state])
            ->where('nav', fn (mixed $nav): bool => billingRailHas($nav, navLabel('billing', $language))));

    expect(billingPortReads())->toBe([]);
})->with([
    'active' => ['active', 'success', BillingController::ACTIVE, null],
    'grace' => ['grace', 'warning', BillingController::GRACE, BillingController::PAUSES_AFTER_GRACE],
    'past_due' => ['past_due', 'warning', BillingController::PAST_DUE, BillingController::PAUSED],
    'canceled' => ['canceled', 'warning', BillingController::CANCELED, BillingController::PAUSED],
    'incomplete' => ['incomplete', 'warning', BillingController::INCOMPLETE, BillingController::PAUSED],
])->with('billing readers');

it('holds past due, the paused paragraph and the grace paragraph as keys of the billing section, their words kept in the file', function () {
    expect(BillingController::PAST_DUE)->toBe('admin/billing.notices.past_due')
        ->and(BillingController::PAUSED)->toBe('admin/billing.notices.paused')
        ->and(BillingController::PAUSES_AFTER_GRACE)->toBe('admin/billing.notices.after_grace');
});

it('shows the current plan with its catalogue price and what it includes, and offers the other plan, in the reader\'s language', function (string $plan, array $current, string $staff, array $change, SiteLanguage $language) {
    billingOnPlan($plan);

    billingPage($this, $language)
        ->assertInertia(fn (Assert $page) => $page
            ->where('billing.plan', [...$current, 'includes' => billingIncludes($staff, $language)])
            ->where('billing.change', $change)
            ->where('billing.portal', true)
            ->where('billing.unavailable', null));
})->with([
    'the floor' => ['floor', ['name' => 'Solo', 'amount' => 1495, 'currency' => 'EUR'], BillingController::ONE_MEMBER,
        ['plan' => 'staff', 'adds' => true, 'name' => 'Team', 'amount' => 2495, 'currency' => 'EUR', 'blocked' => null]],
    'with staff' => ['staff', ['name' => 'Team', 'amount' => 2495, 'currency' => 'EUR'], BillingController::STAFF_INCLUDES,
        ['plan' => 'floor', 'adds' => false, 'name' => 'Solo', 'amount' => 1495, 'currency' => 'EUR', 'blocked' => null]],
])->with('billing readers');

it('names the plans as config/billing.php names them, Solo and Team, and follows a renamed plan with no derivation from its features', function () {
    expect(config('billing.plans.floor.name'))->toBe('Solo')
        ->and(config('billing.plans.staff.name'))->toBe('Team');

    config(['billing.plans.floor.name' => 'Starter', 'billing.plans.staff.name' => 'Studio']);
    billingOnPlan('floor');

    $this->actingAs(billingOwner())->get(tenantUrl('luna', '/admin/billing'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('billing.plan.name', 'Starter')
            ->where('billing.change.name', 'Studio'));
});

it('names the removal\'s refusal beside the control while more than one member is active, the implicit one counted and an inactive one not, in the reader\'s language', function (SiteLanguage $language) {
    billingOnPlan('staff');
    billingMember('Elena Papadaki');
    billingMember('Nikos Georgiou', 'inactive');

    $this->actingAs(billingReader($language))->get(tenantUrl('luna', '/admin/billing'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('billing.change.blocked', billingRefusal(PlanChangeRefused::downgrade(2), $language)));
})->with('billing readers');

it('adds staff through the port, applies the snapshot it answers, and the redirect back carries the feature, the Staff entry and the success sentence in the reader\'s language', function (SiteLanguage $language) {
    billingOnPlan('floor');
    billingSet(['narrowed' => '{staff}']);

    $this->actingAs(billingReader($language))->from(tenantUrl('luna', '/admin/billing'))
        ->post(tenantUrl('luna', '/admin/billing/plan'), ['plan' => 'staff'])
        ->assertRedirect(tenantUrl('luna', '/admin/billing'));

    expect(billingChanges())->toBe(['staff'])
        ->and(billingColumns())->toMatchArray(['entitlements' => '{staff}', 'narrowed' => '{}', 'plan_ref' => 'price_staff_test'])
        ->and(subscriptionRevision())->toBe(1);

    $this->actingAs(billingOwner())->get(tenantUrl('luna', '/admin/billing'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('entitlements', ['staff'])
            ->where('narrowed', [])
            ->where('flash', ['kind' => 'success', 'message' => billingLine(BillingController::STAFF_ADDED, [], $language)])
            ->where('nav', fn (mixed $nav): bool => billingRailHas($nav, navLabel('staff', $language))));
})->with('billing readers');

it('refuses to remove staff while two members are active, naming the count in the reader\'s language, and never asks the port', function (SiteLanguage $language) {
    billingOnPlan('staff');
    billingMember('Elena Papadaki');

    $this->actingAs(billingReader($language))->from(tenantUrl('luna', '/admin/billing'))
        ->post(tenantUrl('luna', '/admin/billing/plan'), ['plan' => 'floor'])
        ->assertRedirect(tenantUrl('luna', '/admin/billing'))
        ->assertSessionHas('flash', ['kind' => 'error', 'message' => billingRefusal(PlanChangeRefused::downgrade(2), $language)]);

    expect(billingPortReads())->toBe([])
        ->and(billingColumns())->toMatchArray(['entitlements' => '{staff}', 'plan_ref' => 'price_staff_test']);
})->with('billing readers');

it('removes staff with one active member, the implicit one, and the redirect back carries the floor with no record of what the owner asked to remove, its flash in the reader\'s language', function (SiteLanguage $language) {
    billingOnPlan('staff');
    billingMember('Elena Papadaki', 'inactive');

    $this->actingAs(billingReader($language))->from(tenantUrl('luna', '/admin/billing'))
        ->post(tenantUrl('luna', '/admin/billing/plan'), ['plan' => 'floor'])
        ->assertRedirect(tenantUrl('luna', '/admin/billing'))
        ->assertSessionHas('flash', ['kind' => 'success', 'message' => billingLine(BillingController::STAFF_REMOVED, [], $language)]);

    expect(billingChanges())->toBe(['floor'])
        ->and(billingColumns())->toMatchArray(['entitlements' => '{}', 'narrowed' => '{}', 'plan_ref' => 'price_floor_test']);

    // The owner asked, so the record names nothing, and the shell's banner and the plan
    // card's "No longer on your plan" have nothing to show.
    $this->actingAs(billingOwner())->get(tenantUrl('luna', '/admin/billing'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('entitlements', [])
            ->where('narrowed', [])
            ->where('flash', ['kind' => 'success', 'message' => billingLine(BillingController::STAFF_REMOVED, [], $language)])
            ->where('nav', fn (mixed $nav): bool => ! billingRailHas($nav, navLabel('staff', $language)) && billingRailHas($nav, navLabel('closures', $language))));

    // The Staff screen, reached from the rail's entry now labelled Closures, on a roster holding
    // another row reads the same empty record, so its read-only list carries its own info notice.
    $this->actingAs(billingOwner())->get(tenantUrl('luna', '/admin/staff'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Staff')
            ->where('entitlements', [])
            ->where('narrowed', []));
})->with('billing readers');

it('still records a removal the owner did not ask for, a webhook\'s snapshot taking staff away, for the banner and the plan card to name', function () {
    billingOnPlan('staff');
    billingFake()->script(billingSnapshot('sub_luna', 'cus_luna', 'active', ['price_floor_test']));

    deliverBillingNotice($this, FakeBillingProvider::notice('evt_1', NoticeKind::SubscriptionChanged, 'cus_luna', 'sub_luna'))->assertOk();

    expect(billingColumns())->toMatchArray(['entitlements' => '{}', 'narrowed' => '{staff}']);

    $this->actingAs(billingOwner())->get(tenantUrl('luna', '/admin/billing'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('entitlements', [])
            ->where('narrowed', ['staff']));
});

it('records nothing either when the owner\'s removal met a moved revision and was re-read, the webhook for the same change having landed first', function () {
    billingOnPlan('staff');
    $fake = billingFake();
    // The provider's event for this very change handled while the provider is still answering:
    // the webhook applies the floor unmarked, records staff and moves the row on.
    BillingPortDecorator::around($fake, beforeChange: fn () => syncWith()->apply(billingSnapshot('sub_luna', 'cus_luna', 'active', ['price_floor_test'])));

    $this->actingAs(billingOwner())->from(tenantUrl('luna', '/admin/billing'))->post(tenantUrl('luna', '/admin/billing/plan'), ['plan' => 'floor'])
        ->assertSessionHas('flash', ['kind' => 'success', 'message' => billingLine(BillingController::STAFF_REMOVED)]);

    expect(array_map(fn (array $question): string => $question[0], $fake->asked))->toBe(['changePlan', 'subscription'])
        ->and(billingColumns())->toMatchArray(['entitlements' => '{}', 'narrowed' => '{}'])
        ->and(subscriptionRevision())->toBe(2);
});

it('opens the provider\'s portal for this customer returning to this screen, as an external redirect', function () {
    billingOnPlan('floor');

    $this->actingAs(billingOwner())->post(tenantUrl('luna', '/admin/billing/portal'), [], ['X-Inertia' => 'true'])
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', 'https://billing.test/portal/cus_luna');

    expect(billingPortReads())->toBe([['openPortal', ['customer' => 'cus_luna', 'return' => tenantUrl('luna', '/admin/billing')]]]);
});

it('renders a free tenant, state active with no subscriptions row, as the one free sentence in a success notice with no plan card, no change, no checkout and no portal, and refuses both writes in their handlers', function (SiteLanguage $language) {
    // luna as tenant:provision builds it is the free shape, as the console's free tenant
    // is.
    billingPage($this, $language)
        ->assertInertia(fn (Assert $page) => $page
            ->where('billing.notice', ['kind' => 'success', 'sentence' => billingLine(BillingController::FREE, [], $language), 'paused' => null])
            ->where('billing.plan', null)
            ->where('billing.change', null)
            ->where('billing.unavailable', null)
            ->where('billing.resubscribe', null)
            ->where('billing.portal', false)
            ->where('subscription', ['state' => 'active']));

    $this->actingAs(billingReader($language))->from(tenantUrl('luna', '/admin/billing'))->post(tenantUrl('luna', '/admin/billing/plan'), ['plan' => 'staff'])
        ->assertSessionHas('flash', ['kind' => 'error', 'message' => billingRefusal(PlanChangeRefused::noSubscription(), $language)]);
    $this->actingAs(billingReader($language))->from(tenantUrl('luna', '/admin/billing'))->post(tenantUrl('luna', '/admin/billing/portal'))
        ->assertRedirect(tenantUrl('luna', '/admin/billing'))
        ->assertSessionHas('flash', ['kind' => 'error', 'message' => billingRefusal(PlanChangeRefused::noSubscription(), $language)]);

    expect(billingPortReads())->toBe([]);
})->with('billing readers');

it('reads the free sentence exactly where the console reads the free shape, one predicate for both: {case}', function (string $state, bool $subscribed, bool $trialEnd, bool $free) {
    $tenant = DB::table('tenants')->where('slug', 'luna')->value('id');
    billingSet(['subscription_state' => $state, 'trial_ends_at' => $trialEnd ? '2026-10-08 09:00:00+00' : null]);

    if ($subscribed) {
        DB::table('subscriptions')->insert(['tenant_id' => $tenant, 'provider_customer_id' => 'cus_luna']);
    }

    $this->actingAs(billingOwner())->get(tenantUrl('luna', '/admin/billing'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('billing.notice.sentence', fn (string $sentence): bool => ($sentence === billingLine(BillingController::FREE)) === $free));

    expect(TenantPlan::free(SubscriptionState::from($state), $trialEnd, $subscribed))->toBe($free);
})->with([
    'active, no subscriptions row, no trial end: the free shape' => ['active', false, false, true],
    'active beside a subscriptions row' => ['active', true, false, false],
    'active beside a trial end, no subscriptions row' => ['active', false, true, false],
    'a trial that ended unpaid' => ['canceled', false, true, false],
    'canceled with no subscriptions row and no trial end, a frozen state no writer leaves' => ['canceled', false, false, false],
]);

it('offers no plan change while the first charge is pending or once the subscription has ended, and refuses one in the handler', function (string $state, PlanChangeRefused $refusal, bool $worded, SiteLanguage $language) {
    billingOnPlan('floor', $state);

    // A trial's plan card is the Free trial's and its road the plan choice, so no sentence stands
    // where Change plan would; the handler still refuses it as a row naming no subscription.
    billingPage($this, $language)
        ->assertInertia(fn (Assert $page) => $page
            ->where('billing.change', null)
            ->where('billing.portal', true)
            ->where('billing.unavailable', $worded ? billingRefusal($refusal, $language) : null));

    $this->actingAs(billingReader($language))->from(tenantUrl('luna', '/admin/billing'))->post(tenantUrl('luna', '/admin/billing/plan'), ['plan' => 'staff'])
        ->assertSessionHas('flash', ['kind' => 'error', 'message' => billingRefusal($refusal, $language)]);

    expect(billingPortReads())->toBe([]);
})->with([
    'incomplete, a charge in progress' => ['incomplete', PlanChangeRefused::chargePending(), true],
    'canceled' => ['canceled', PlanChangeRefused::ended(), true],
    'trial, which has no subscription to change' => ['trial', PlanChangeRefused::noSubscription(), false],
])->with('billing readers');

/**
 * The plan choice the Billing screen offers, the catalogue's plans in its order with their names
 * and prices as the plan card spells them, the floor carrying its refusal while the roster refuses
 * it.
 *
 * @return list<array<string, mixed>>
 */
function billingChoice(?string $floorBlocked = null): array
{
    return [
        ['plan' => 'floor', 'name' => 'Solo', 'amount' => 1495, 'currency' => 'EUR', 'blocked' => $floorBlocked],
        ['plan' => 'staff', 'name' => 'Team', 'amount' => 2495, 'currency' => 'EUR', 'blocked' => null],
    ];
}

it('renders a trial as a success notice with its end on the shared prop, nothing paused, the Free trial plan card carrying the full set with no Change plan, and Choose your plan with the plan choice', function (SiteLanguage $language) {
    app()->instance(Clock::class, FixedClock::at('2026-10-08 09:00'));
    billingSet(['subscription_state' => 'trial', 'entitlements' => '{staff}', 'trial_ends_at' => '2026-10-15 09:00:00+00']);

    billingPage($this, $language)
        ->assertInertia(fn (Assert $page) => $page->component('Billing')
            ->where('billing.notice', ['kind' => 'success', 'sentence' => billingLine(BillingController::TRIAL, [], $language), 'paused' => null])
            ->where('subscription', ['state' => 'trial', 'trial_ends_at' => '2026-10-15T09:00:00+00:00'])
            ->where('entitlements', ['staff'])
            ->where('billing.plan', ['name' => billingLine(BillingController::TRIAL_PLAN, [], $language), 'amount' => null, 'currency' => null, 'includes' => billingIncludes(BillingController::STAFF_INCLUDES, $language)])
            ->where('billing.change', null)
            ->where('billing.unavailable', null)
            ->where('billing.portal', false)
            ->where('billing.resubscribe', ['label' => billingLine(BillingController::CHOOSE_PLAN, [], $language), 'pending' => null, 'plan' => null, 'choice' => billingChoice()]));

    $this->actingAs(billingReader($language))->from(tenantUrl('luna', '/admin/billing'))->post(tenantUrl('luna', '/admin/billing/plan'), ['plan' => 'floor'])
        ->assertSessionHas('flash', ['kind' => 'error', 'message' => billingRefusal(PlanChangeRefused::noSubscription(), $language)]);

    expect(billingFake()->asked)->toBe([])
        ->and(billingPortReads())->toBe([]);
})->with('billing readers');

it('meets a catalogue nobody can read in a trial with the Free trial card, the catalogue\'s sentence where the choice would be and no control, logged once', function (SiteLanguage $language) {
    billingSet(['subscription_state' => 'trial', 'entitlements' => '{staff}', 'trial_ends_at' => '2026-10-08 09:00:00+00']);
    config(['billing.plans.staff.price' => '']);
    $log = billingLog();

    billingPage($this, $language)
        ->assertInertia(fn (Assert $page) => $page
            ->where('billing.plan.name', billingLine(BillingController::TRIAL_PLAN, [], $language))
            ->where('billing.unavailable', billingLine(BillingController::CATALOGUE_UNREADABLE, [], $language))
            ->where('billing.resubscribe', null));

    expect(array_map(fn (array $record): array => [$record['level'], $record['message']], $log->records))->toBe([['error', BillingController::CATALOGUE_LOGGED]]);
})->with('billing readers');

it('words the trial\'s two notices whole and naming no instant, the end being the screen\'s own paragraph from the shared prop as the grace end is', function () {
    expect(BillingController::TRIAL)->toBe('admin/billing.notices.trial')
        ->and(BillingController::TRIAL_ENDED)->toBe('admin/billing.notices.trial_ended')
        ->and(BillingController::TRIAL_PLAN)->toBe('admin/billing.plan.trial')
        ->and(billingLine(BillingController::TRIAL).billingLine(BillingController::TRIAL_ENDED))->not->toContain(':end');
});

it('sends no placeholder for the screen to fill, in a trial or after one, the instant crossing on the shared prop alone', function (string $state, string $sentence) {
    billingSet(['subscription_state' => $state, 'entitlements' => $state === 'trial' ? '{staff}' : '{}', 'trial_ends_at' => '2026-10-08 09:00:00+00']);

    $this->actingAs(billingOwner())->get(tenantUrl('luna', '/admin/billing'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('billing.notice.sentence', $sentence)
            ->where('billing', fn (mixed $billing): bool => ! str_contains(json_encode($billing, JSON_THROW_ON_ERROR), ':end'))
            ->where('subscription', ['state' => $state, 'trial_ends_at' => '2026-10-08T09:00:00+00:00']));
})->with([
    'a trial' => ['trial', fn () => billingLine(BillingController::TRIAL)],
    'a trial that ended unpaid' => ['canceled', fn () => billingLine(BillingController::TRIAL_ENDED)],
]);

it('renders a trial the hourly lapse ended as the trial-ended notice with the paused paragraph, the floor in the rail, and Subscribe with the plan choice', function (SiteLanguage $language) {
    billingSet(['subscription_state' => 'trial', 'entitlements' => '{staff}', 'trial_ends_at' => '2026-10-01 09:00:00+00']);
    app()->instance(Clock::class, FixedClock::at('2026-10-01 09:00'));
    Artisan::call('billing:lapse');

    billingPage($this, $language)
        ->assertInertia(fn (Assert $page) => $page->component('Billing')
            ->where('billing.notice', ['kind' => 'warning', 'sentence' => billingLine(BillingController::TRIAL_ENDED, [], $language), 'paused' => billingLine(BillingController::PAUSED, [], $language)])
            ->where('subscription', ['state' => 'canceled', 'trial_ends_at' => '2026-10-01T09:00:00+00:00'])
            ->where('entitlements', [])
            ->where('billing.plan', null)
            ->where('billing.unavailable', billingRefusal(PlanChangeRefused::noSubscription(), $language))
            ->where('billing.resubscribe', ['label' => billingLine(BillingController::SUBSCRIBE, [], $language), 'pending' => null, 'plan' => null, 'choice' => billingChoice()])
            ->where('nav', fn (mixed $nav): bool => billingRailHas($nav, navLabel('billing', $language))));
})->with('billing readers');

it('words the floor\'s refusal in the plan choice for the choice, the plan change keeping its own: deactivating on offer in a trial, a plan with Staff first while setup changes are paused', function () {
    // The choice's two sentences, each its own key, the plan change's standing beside them.
    expect(PlanChangeRefused::unchoosable('Solo', 2, paused: false)->key)->toBe(PlanChangeRefused::UNCHOOSABLE_SENTENCE)
        ->and(PlanChangeRefused::unchoosable('Solo', 3, paused: true)->key)->toBe(PlanChangeRefused::UNCHOOSABLE_PAUSED_SENTENCE)
        ->and(PlanChangeRefused::unchoosable('Solo', 3, paused: true)->sentence())->toBe(adminChoice(PlanChangeRefused::UNCHOOSABLE_PAUSED_SENTENCE, 3, ['plan' => 'Solo']))
        ->and(PlanChangeRefused::unchoosable('Solo', 2, paused: true)->reason)->toBe(PlanChangeRefused::DOWNGRADE)
        ->and(PlanChangeRefused::downgrade(2)->key)->toBe(PlanChangeRefused::DOWNGRADE_SENTENCE);

    $catalogue = app(PlanCatalogue::class);

    expect(PlanChange::choiceRefusal($catalogue->plan('floor'), 1, SubscriptionState::Trial))->toBeNull()
        ->and(PlanChange::choiceRefusal($catalogue->plan('staff'), 4, SubscriptionState::Canceled))->toBeNull()
        ->and(PlanChange::choiceRefusal($catalogue->plan('floor'), 2, SubscriptionState::Trial)?->sentence())->toBe(billingRefusal(PlanChangeRefused::unchoosable('Solo', 2, paused: false)))
        ->and(PlanChange::choiceRefusal($catalogue->plan('floor'), 2, SubscriptionState::Canceled)?->sentence())->toBe(billingRefusal(PlanChangeRefused::unchoosable('Solo', 2, paused: true)));
});

it('disables the floor in the plan choice of a trial and of a trial that ended with the choice\'s sentence for the state while more than one member is active, the implicit one counted', function (string $state, bool $paused, SiteLanguage $language) {
    billingSet(['subscription_state' => $state, 'entitlements' => '{staff}', 'trial_ends_at' => '2026-10-08 09:00:00+00']);
    billingMember('Elena Papadaki');
    billingMember('Nikos Georgiou', 'inactive');

    billingPage($this, $language)
        ->assertInertia(fn (Assert $page) => $page
            ->where('billing.resubscribe.choice', billingChoice(billingRefusal(PlanChangeRefused::unchoosable('Solo', 2, $paused), $language))));
})->with(['trial' => ['trial', false], 'canceled after a trial' => ['canceled', true]])->with('billing readers');

it('reads Continue to checkout with the on-its-way sentence while a trial\'s checkout is open, the plan choice still shown', function (string $state, SiteLanguage $language) {
    billingSet(['subscription_state' => $state, 'entitlements' => '{staff}', 'trial_ends_at' => '2026-10-08 09:00:00+00']);
    $this->actingAs(billingReader($language))->post(billingCheckout(), ['plan' => 'staff'], ['X-Inertia' => 'true']);

    billingPage($this, $language)
        ->assertInertia(fn (Assert $page) => $page
            ->where('billing.notice.kind', $state === 'trial' ? 'success' : 'warning')
            ->where('billing.resubscribe', ['label' => billingLine(BillingController::CONTINUE_CHECKOUT, [], $language), 'pending' => billingLine(BillingController::ON_ITS_WAY, [], $language), 'plan' => null, 'choice' => billingChoice()]));

    DB::table('subscriptions')->update(['checkout_opened_at' => DB::raw("checkout_opened_at - interval '24 hours'")]);

    $this->actingAs(billingReader($language))->get(tenantUrl('luna', '/admin/billing'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('billing.resubscribe.label', $state === 'trial' ? billingLine(BillingController::CHOOSE_PLAN, [], $language) : billingLine(BillingController::SUBSCRIBE, [], $language))
            ->where('billing.resubscribe.pending', null));
})->with(['trial' => ['trial'], 'canceled after a trial' => ['canceled']])->with('billing readers');

it('offers the plan choice in place of Subscribe again where the plan the row last carried is unlisted, or the floor while the roster refuses it, so no canceled tenant is left without a road to a card', function (string $plan, ?string $planRef, int $members, ?PlanChangeRefused $floorBlocked, SiteLanguage $language) {
    billingOnPlan($plan, 'canceled');
    billingSet(['plan_ref' => $planRef]);

    for ($member = 1; $member <= $members; $member++) {
        billingMember("Member {$member}");
    }

    $screen = fn () => billingPage($this, $language);

    $screen()->assertInertia(fn (Assert $page) => $page
        ->where('billing.notice.sentence', billingLine(BillingController::CANCELED, [], $language))
        ->where('billing.resubscribe', ['label' => billingLine(BillingController::SUBSCRIBE_AGAIN, [], $language), 'pending' => null, 'plan' => null, 'choice' => billingChoice($floorBlocked === null ? null : billingRefusal($floorBlocked, $language))]));

    $this->actingAs(billingReader($language))->post(billingCheckout(), ['plan' => 'staff'], ['X-Inertia' => 'true'])
        ->assertStatus(409);

    $screen()->assertInertia(fn (Assert $page) => $page
        ->where('billing.resubscribe', ['label' => billingLine(BillingController::CONTINUE_CHECKOUT, [], $language), 'pending' => billingLine(BillingController::ON_ITS_WAY, [], $language), 'plan' => null, 'choice' => billingChoice($floorBlocked === null ? null : billingRefusal($floorBlocked, $language))]));
    expect(billingCheckouts()[0]['plan'] ?? null)->toBe('staff');
})->with([
    'a price no catalogue plan carries, one member' => ['staff', 'price_retired', 0, null],
    'no price at all, two members' => ['staff', null, 1, PlanChangeRefused::unchoosable('Solo', 2, paused: true)],
    'the floor with two members' => ['floor', 'price_floor_test', 1, PlanChangeRefused::unchoosable('Solo', 2, paused: true)],
])->with('billing readers');

it('refuses the floor on the way back while more than one member is active in the choice\'s sentence for a paused tenant, asking the provider nothing', function (SiteLanguage $language) {
    billingOnPlan('floor', 'canceled');
    billingMember('Ana');

    $this->actingAs(billingReader($language))->from(tenantUrl('luna', '/admin/billing'))->post(billingCheckout(), ['plan' => 'floor'])
        ->assertRedirect(tenantUrl('luna', '/admin/billing'))
        ->assertSessionHas('flash', ['kind' => 'error', 'message' => billingRefusal(PlanChangeRefused::unchoosable('Solo', 2, paused: true), $language)]);

    expect(billingFake()->asked)->toBe([]);
})->with('billing readers');

it('keeps Subscribe again posting the row\'s plan where it is listed and the roster allows it', function (string $plan, int $members, SiteLanguage $language) {
    billingOnPlan($plan, 'canceled');

    for ($member = 1; $member <= $members; $member++) {
        billingMember("Member {$member}");
    }

    billingPage($this, $language)
        ->assertInertia(fn (Assert $page) => $page
            ->where('billing.resubscribe', ['label' => billingLine(BillingController::SUBSCRIBE_AGAIN, [], $language), 'pending' => null, 'plan' => $plan, 'choice' => null]));
})->with([
    'the floor, one member' => ['floor', 0],
    'staff, two members' => ['staff', 1],
])->with('billing readers');

it('changes plan in grace and past due, the billing routes never gated by the subscription', function (string $state, SiteLanguage $language) {
    billingOnPlan('floor', $state);

    $this->actingAs(billingReader($language))->from(tenantUrl('luna', '/admin/billing'))->post(tenantUrl('luna', '/admin/billing/plan'), ['plan' => 'staff'])
        ->assertSessionHas('flash', ['kind' => 'success', 'message' => billingLine(BillingController::STAFF_ADDED, [], $language)]);

    expect(billingChanges())->toBe(['staff']);
})->with(['grace', 'past_due'])->with('billing readers');

it('refuses a plan the catalogue does not hold as a worded answer', function (SiteLanguage $language) {
    billingOnPlan('floor');

    $this->actingAs(billingReader($language))->from(tenantUrl('luna', '/admin/billing'))->post(tenantUrl('luna', '/admin/billing/plan'), ['plan' => 'gold'])
        ->assertSessionHas('flash', ['kind' => 'error', 'message' => billingRefusal(PlanChangeRefused::unknownPlan(), $language)]);

    expect(billingPortReads())->toBe([]);
})->with('billing readers');

it('refuses a body naming no plan with the 400 envelope and the field\'s sentence, never a redirect back with the errors bag, and asks the provider nothing', function (array $body, SiteLanguage $language) {
    billingOnPlan('floor');

    $this->actingAs(billingReader($language))->from(tenantUrl('luna', '/admin/billing'))->post(tenantUrl('luna', '/admin/billing/plan'), $body)
        ->assertStatus(400)
        ->assertJsonPath('code', 'invalid_request')
        ->assertJsonPath('data.detail', billingLine(ChangePlanRequest::NO_PLAN, [], $language))
        ->assertSessionMissing('errors')
        ->assertSessionMissing('flash');

    expect(billingPortReads())->toBe([]);
})->with([
    'no plan' => [[]],
    'a plan that is not a string' => [['plan' => ['staff']]],
])->with('billing readers');

it('meets a catalogue nobody can read with a worded state on render and on the write, never a 500, and reports it', function (SiteLanguage $language) {
    billingOnPlan('floor');
    config(['billing.plans.staff.price' => '']);
    $log = billingLog();

    billingPage($this, $language)
        ->assertInertia(fn (Assert $page) => $page
            ->where('billing.plan', null)
            ->where('billing.change', null)
            ->where('billing.portal', true)
            ->where('billing.unavailable', billingLine(BillingController::CATALOGUE_UNREADABLE, [], $language)));

    $this->actingAs(billingReader($language))->from(tenantUrl('luna', '/admin/billing'))->post(tenantUrl('luna', '/admin/billing/plan'), ['plan' => 'staff'])
        ->assertSessionHas('flash', ['kind' => 'error', 'message' => billingLine(BillingController::CATALOGUE_UNREADABLE, [], $language)]);

    expect(billingPortReads())->toBe([])
        ->and(array_map(fn (array $record): array => [$record['level'], $record['message']], $log->records))->toBe([
            ['error', BillingController::CATALOGUE_LOGGED],
            ['error', BillingController::CATALOGUE_LOGGED],
        ]);
})->with('billing readers');

it('meets a catalogue nobody can read on the way back\'s checkout with the catalogue\'s sentence as an error flash, never a 500, asking the provider nothing, and reports it', function (SiteLanguage $language) {
    billingOnPlan('staff', 'canceled');
    config(['billing.plans.staff.price' => '']);
    $log = billingLog();

    $this->actingAs(billingReader($language))->from(tenantUrl('luna', '/admin/billing'))->post(billingCheckout(), ['plan' => 'staff'])
        ->assertRedirect(tenantUrl('luna', '/admin/billing'))
        ->assertSessionHas('flash', ['kind' => 'error', 'message' => billingLine(BillingController::CATALOGUE_UNREADABLE, [], $language)]);

    expect(billingFake()->asked)->toBe([])
        ->and(checkoutRecord()['checkout_session_id'])->toBeNull()
        ->and($log->records)->toBe([[
            'level' => 'error',
            'message' => BillingController::CATALOGUE_LOGGED,
            'context' => ['tenant' => 'luna', 'message' => 'The billing plan "staff" is sold under no price identifier: billing.plans.staff.price is unset or blank in the environment.'],
        ]]);
})->with('billing readers');

it('words a provider that could not change the plan as a retry, the provider\'s sentence in the log alone', function (SiteLanguage $language) {
    billingOnPlan('floor');
    BillingPortDecorator::around(billingFake(), changeFails: BillingProviderFailed::unreachable('change a plan', 'connection refused'));
    $log = billingLog();

    $this->actingAs(billingReader($language))->from(tenantUrl('luna', '/admin/billing'))->post(tenantUrl('luna', '/admin/billing/plan'), ['plan' => 'staff'])
        ->assertSessionHas('flash', ['kind' => 'error', 'message' => billingLine(BillingController::PROVIDER_FAILED, [], $language)]);

    expect(billingColumns())->toMatchArray(['entitlements' => '{}', 'plan_ref' => 'price_floor_test'])
        ->and($log->records)->toBe([['level' => 'warning', 'message' => BillingController::PROVIDER_LOGGED, 'context' => ['tenant' => 'luna', 'message' => 'The billing provider could not be reached to change a plan: connection refused']]]);
})->with('billing readers');

it('words a portal the provider could not open as a retry, the provider\'s sentence in the log alone', function (SiteLanguage $language) {
    billingOnPlan('floor');
    BillingPortDecorator::around(billingFake(), portalFails: BillingProviderFailed::unreachable('open the portal', 'timed out'));
    $log = billingLog();

    $this->actingAs(billingReader($language))->from(tenantUrl('luna', '/admin/billing'))->post(tenantUrl('luna', '/admin/billing/portal'))
        ->assertRedirect(tenantUrl('luna', '/admin/billing'))
        ->assertSessionHas('flash', ['kind' => 'error', 'message' => billingLine(BillingController::PORTAL_FAILED, [], $language)]);

    expect($log->records)->toBe([['level' => 'warning', 'message' => BillingController::PROVIDER_LOGGED, 'context' => ['tenant' => 'luna', 'message' => 'The billing provider could not be reached to open the portal: timed out']]]);
})->with('billing readers');

it('raises a missing billing key on each of the three writes, and on the re-read after the row moved, as the deploy fault it is and never a retry sentence', function (string $path, string $fault, string $state) {
    billingOnPlan('floor', $state);
    $missing = BillingNotConfigured::noSecretKey();
    $bump = fn () => DB::table('subscriptions')->update(['revision' => DB::raw('revision + 1')]);
    match ($fault) {
        'change' => BillingPortDecorator::around(billingFake(), changeFails: $missing),
        'portal' => BillingPortDecorator::around(billingFake(), portalFails: $missing),
        'reread' => BillingPortDecorator::around(billingFake(), beforeChange: $bump, readFails: $missing),
        'checkout' => billingFake()->checkoutFails = $missing,
        default => throw new LogicException(sprintf('No fault named %s.', $fault)),
    };
    $log = billingLog();
    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs(billingOwner())->from(tenantUrl('luna', '/admin/billing'))->post(tenantUrl('luna', $path), ['plan' => 'staff']))
        ->toThrow(BillingNotConfigured::class, BillingNotConfigured::NO_SECRET_KEY);
    expect($log->records)->toBe([])
        ->and(session('flash'))->toBeNull()
        ->and(billingColumns())->toMatchArray(['entitlements' => '{}', 'plan_ref' => 'price_floor_test'])
        ->and(checkoutRecord()['checkout_session_id'])->toBeNull();
})->with([
    'the plan change' => ['/admin/billing/plan', 'change', 'active'],
    'the portal' => ['/admin/billing/portal', 'portal', 'active'],
    'the re-read after a Stale' => ['/admin/billing/plan', 'reread', 'active'],
    'the way back\'s checkout' => ['/admin/billing/checkout', 'checkout', 'canceled'],
]);

it('words a re-read the provider cannot answer after the row moved as the change on its way, logs it, and writes nothing over the webhook\'s row', function () {
    billingOnPlan('floor');
    $fake = billingFake();
    BillingPortDecorator::around(
        $fake,
        beforeChange: fn () => DB::table('subscriptions')->update(['revision' => DB::raw('revision + 1')]),
        readFails: BillingProviderFailed::unreachable('read a subscription', 'connection reset'),
    );
    $log = billingLog();

    $this->actingAs(billingOwner())->from(tenantUrl('luna', '/admin/billing'))->post(tenantUrl('luna', '/admin/billing/plan'), ['plan' => 'staff'])
        ->assertRedirect(tenantUrl('luna', '/admin/billing'))
        ->assertSessionHas('flash', ['kind' => 'info', 'message' => billingLine(BillingController::CHANGE_PENDING)]);

    expect(array_map(fn (array $question): string => $question[0], $fake->asked))->toBe(['changePlan'])
        ->and(billingColumns())->toMatchArray(['entitlements' => '{}', 'plan_ref' => 'price_floor_test'])
        ->and(subscriptionRevision())->toBe(1)
        ->and($log->records)->toBe([['level' => 'warning', 'message' => PlanChange::REREAD_FAILED, 'context' => ['tenant' => 'luna', 'message' => 'The billing provider could not be reached to read a subscription: connection reset']]]);
});

it('offers no change to a tenant on a price no catalogue plan carries, and refuses one in the handler without asking the provider', function (SiteLanguage $language) {
    billingOnPlan('floor');
    billingSet(['plan_ref' => 'price_legacy']);

    billingPage($this, $language)
        ->assertInertia(fn (Assert $page) => $page
            ->where('billing.plan', null)
            ->where('billing.change', null)
            ->where('billing.portal', true)
            ->where('billing.unavailable', billingRefusal(PlanChangeRefused::unlisted(), $language)));

    $this->actingAs(billingReader($language))->from(tenantUrl('luna', '/admin/billing'))->post(tenantUrl('luna', '/admin/billing/plan'), ['plan' => 'staff'])
        ->assertSessionHas('flash', ['kind' => 'error', 'message' => billingRefusal(PlanChangeRefused::unlisted(), $language)]);

    expect(billingPortReads())->toBe([])
        ->and(billingColumns())->toMatchArray(['entitlements' => '{}', 'plan_ref' => 'price_legacy'])
        ->and(subscriptionRevision())->toBe(0);
})->with('billing readers');

it('asks the provider nothing for the plan the subscription is already on, and says nothing changed', function (string $plan, string $entitlements, SiteLanguage $language) {
    billingOnPlan($plan);

    $this->actingAs(billingReader($language))->from(tenantUrl('luna', '/admin/billing'))->post(tenantUrl('luna', '/admin/billing/plan'), ['plan' => $plan])
        ->assertRedirect(tenantUrl('luna', '/admin/billing'))
        ->assertSessionHas('flash', ['kind' => 'info', 'message' => billingLine(BillingController::ALREADY_ON, [], $language)]);

    expect(billingPortReads())->toBe([])
        ->and(billingColumns())->toMatchArray(['entitlements' => $entitlements])
        ->and(subscriptionRevision())->toBe(0);
})->with([
    'the floor' => ['floor', '{}'],
    'with staff' => ['staff', '{staff}'],
])->with('billing readers');

it('reads the unlisted sentence before the state\'s on a pending or ended subscription whose snapshot carried no plan, on the render and in the handler alike', function (string $state, SiteLanguage $language) {
    billingOnPlan('floor', $state);
    billingSet(['plan_ref' => null]);

    billingPage($this, $language)
        ->assertInertia(fn (Assert $page) => $page
            ->where('billing.plan', null)
            ->where('billing.change', null)
            ->where('billing.unavailable', billingRefusal(PlanChangeRefused::unlisted(), $language)));

    $this->actingAs(billingReader($language))->from(tenantUrl('luna', '/admin/billing'))->post(tenantUrl('luna', '/admin/billing/plan'), ['plan' => 'staff'])
        ->assertSessionHas('flash', ['kind' => 'error', 'message' => billingRefusal(PlanChangeRefused::unlisted(), $language)]);

    expect(billingPortReads())->toBe([]);
})->with([
    'the first charge pending' => ['incomplete'],
    'ended' => ['canceled'],
])->with('billing readers');

it('answers every state that takes no plan change with the one code, each in its own sentence, and a plan no catalogue lists with its own', function (PlanChangeRefused $refusal, string $code) {
    $envelope = ErrorEnvelope::for($refusal);

    expect($envelope->code)->toBe($code)
        ->and($envelope->status)->toBe(409)
        ->and($envelope->detail)->toBe(billingRefusal($refusal));
})->with([
    'no subscription on file' => [PlanChangeRefused::noSubscription(), 'plan_unchangeable'],
    'the first charge pending' => [PlanChangeRefused::chargePending(), 'plan_unchangeable'],
    'ended' => [PlanChangeRefused::ended(), 'plan_unchangeable'],
    'a price no plan carries' => [PlanChangeRefused::unlisted(), 'plan_unlisted'],
]);

it('hands the sync the revision read before the provider was asked, and on a row that moved meanwhile re-reads the provider at the new revision', function (SiteLanguage $language) {
    billingOnPlan('floor');
    $fake = billingFake();
    // A webhook's write landing while the provider is being asked: the row moves on.
    BillingPortDecorator::around($fake, beforeChange: fn () => DB::table('subscriptions')->update(['revision' => DB::raw('revision + 1')]));

    $this->actingAs(billingReader($language))->from(tenantUrl('luna', '/admin/billing'))->post(tenantUrl('luna', '/admin/billing/plan'), ['plan' => 'staff'])
        ->assertSessionHas('flash', ['kind' => 'success', 'message' => billingLine(BillingController::STAFF_ADDED, [], $language)]);

    expect(array_map(fn (array $question): string => $question[0], $fake->asked))->toBe(['changePlan', 'subscription'])
        ->and(billingColumns())->toMatchArray(['entitlements' => '{staff}'])
        ->and(subscriptionRevision())->toBe(2);
})->with('billing readers');

it('words the change the provider took as its own error, never on its way, naming the support address configured, when the sync cannot land the answer, logging it beside the sync\'s own line', function (Closure $answers, string $sentence, string $logged, string $cause, SiteLanguage $language) {
    // The sentence asks the owner to contact support at site.contact_address.
    config(['site.contact_address' => 'help@billing.example']);
    billingOnPlan('floor');
    $fake = billingFake();
    BillingPortDecorator::around($fake, changeAnswers: $answers);
    $log = billingLog();

    expect(billingLine($sentence, [], $language))->toContain(':address');

    $this->actingAs(billingReader($language))->from(tenantUrl('luna', '/admin/billing'))->post(tenantUrl('luna', '/admin/billing/plan'), ['plan' => 'staff'])
        ->assertRedirect(tenantUrl('luna', '/admin/billing'))
        ->assertSessionHas('flash', ['kind' => 'error', 'message' => billingLine($sentence, ['address' => 'help@billing.example'], $language)]);

    expect(array_column($fake->asked, 0))->toBe(['changePlan'])
        ->and(billingColumns())->toMatchArray(['entitlements' => '{}', 'plan_ref' => 'price_floor_test'])
        ->and(subscriptionRevision())->toBe(0)
        ->and(array_column($log->records, 'message'))->toBe([$cause, $logged])
        ->and($log->records[1])->toBe(['level' => 'error', 'message' => $logged, 'context' => ['tenant' => 'luna', 'plan' => 'staff']]);
})->with([
    'an answer the sync cannot resolve' => [
        fn (SubscriptionSnapshot $answer): SubscriptionSnapshot => new SubscriptionSnapshot($answer->subscriptionId, $answer->customerId, $answer->status, $answer->periodEnd, [new SnapshotItem('', 1)]),
        BillingController::CHANGE_UNREADABLE,
        BillingController::UNREADABLE_LOGGED,
        'The subscription sync left a tenant as it was',
    ],
    'an answer naming a customer no tenant answers to' => [
        fn (SubscriptionSnapshot $answer): SubscriptionSnapshot => billingSnapshot('sub_luna', 'cus_elsewhere', 'active', ['price_staff_test']),
        BillingController::CHANGE_UNMATCHED,
        BillingController::UNMATCHED_LOGGED,
        'The billing provider named a customer no tenant answers to; nothing was written',
    ],
])->with('billing readers');

it('names in the Applied flash the feature the change moved, the plan before compared with the plan after, a third catalogue plan beside the two', function (string $from, string $to, string $sentence, SiteLanguage $language) {
    config([
        'billing.plans.team' => ['name' => 'Team Plus', 'price' => 'price_team_test', 'features' => ['staff'], 'display' => ['amount' => 5900, 'currency' => 'EUR']],
        'billing.plans.lite' => ['name' => 'Lite', 'price' => 'price_lite_test', 'features' => [], 'display' => ['amount' => 900, 'currency' => 'EUR']],
    ]);
    billingOnPlan($from);

    $this->actingAs(billingReader($language))->from(tenantUrl('luna', '/admin/billing'))->post(tenantUrl('luna', '/admin/billing/plan'), ['plan' => $to])
        ->assertSessionHas('flash', ['kind' => 'success', 'message' => billingLine($sentence, [], $language)]);

    expect(billingChanges())->toBe([$to])
        ->and(subscriptionRevision())->toBe(1);
})->with([
    'staff to a third plan carrying staff moves no feature' => ['staff', 'team', BillingController::PLAN_CHANGED],
    'the floor to a third plan without staff moves no feature' => ['floor', 'lite', BillingController::PLAN_CHANGED],
    'a third plan without staff to staff adds it' => ['lite', 'staff', BillingController::STAFF_ADDED],
    'a third plan carrying staff to the floor removes it' => ['team', 'floor', BillingController::STAFF_REMOVED],
])->with('billing readers');

it('says the change is on its way when the row keeps moving, never a 500 and never a silent floor', function (SiteLanguage $language) {
    billingOnPlan('floor');
    BillingPortDecorator::around(billingFake(), beforeChange: fn () => DB::table('subscriptions')->update(['revision' => DB::raw('revision + 1')]), beforeRead: fn () => DB::table('subscriptions')->update(['revision' => DB::raw('revision + 1')]));

    $this->actingAs(billingReader($language))->from(tenantUrl('luna', '/admin/billing'))->post(tenantUrl('luna', '/admin/billing/plan'), ['plan' => 'staff'])
        ->assertSessionHas('flash', ['kind' => 'info', 'message' => billingLine(BillingController::CHANGE_PENDING, [], $language)]);

    expect(billingColumns())->toMatchArray(['entitlements' => '{}']);
})->with('billing readers');

/*
 * The three billing writes each ask the provider, so they carry a limit of their own: ten a
 * minute per address and tenant, counted across the three, past it the envelope at 429 that the
 * screen words as a warning notice.
 */

it('answers the eleventh billing write in a minute from one address with the envelope at 429 and asks the provider nothing more, each of the ten before it a press the provider was asked for', function (string $path, string $plan, string $state, Closure $body) {
    billingOnPlan($plan, $state);
    $fake = billingFake();
    $press = function (int $count) use ($path, $body) {
        $data = $body($count);

        return $this->actingAs(billingOwner())->from(tenantUrl('luna', '/admin/billing'))
            ->post(tenantUrl('luna', $path), is_array($data) ? $data : throw new LogicException('A press names its body as an array.'));
    };

    foreach (range(1, 10) as $count) {
        $asked = count($fake->asked);
        $press($count)->assertRedirect();

        expect($fake->asked)->toHaveCount($asked + 1);
    }

    $asked = count($fake->asked);

    $press(11)
        ->assertStatus(429)
        ->assertHeader('Retry-After')
        ->assertExactJson(['code' => 'rate_limited', 'message' => 'rate_limited', 'data' => ['status' => 429, 'detail' => apiSentence(ErrorEnvelope::RATE_LIMITED_DETAIL)]]);

    expect($fake->asked)->toHaveCount($asked);
})->with([
    // Each press moves to the other plan, so none is the plan already carried.
    'the plan change, between the two plans' => ['/admin/billing/plan', 'floor', 'active', fn (int $press): array => ['plan' => $press % 2 === 1 ? 'staff' : 'floor']],
    'the portal' => ['/admin/billing/portal', 'floor', 'active', fn (int $press): array => []],
    // Each press finds the session the last one opened past its 24 hours, so none is answered
    // the open session's URL without asking.
    'the way back\'s checkout' => ['/admin/billing/checkout', 'staff', 'canceled', function (int $press): array {
        DB::table('subscriptions')->update(['checkout_opened_at' => DB::raw("checkout_opened_at - interval '24 hours'")]);

        return ['plan' => 'staff'];
    }],
]);

it('counts the three billing writes as one budget per address and tenant, another tenant\'s owner at the same address and the same owner at another address still answered', function () {
    billingOnPlan('floor');
    provisionTenant('pier', ['name' => 'Pier Barbers', 'ownerEmail' => 'owner@pier.example']);

    foreach (['/admin/billing/plan', '/admin/billing/portal', '/admin/billing/checkout'] as $path) {
        foreach (range(1, $path === '/admin/billing/plan' ? 4 : 3) as $press) {
            $this->actingAs(billingOwner())->from(tenantUrl('luna', '/admin/billing'))->post(tenantUrl('luna', $path), ['plan' => 'floor'])->assertRedirect();
        }
    }

    $this->actingAs(billingOwner())->from(tenantUrl('luna', '/admin/billing'))->post(tenantUrl('luna', '/admin/billing/portal'))->assertStatus(429);
    $this->actingAs(User::query()->where('email', 'owner@pier.example')->sole())->from(tenantUrl('pier', '/admin/billing'))->post(tenantUrl('pier', '/admin/billing/portal'))
        ->assertRedirect(tenantUrl('pier', '/admin/billing'));
    // The address half of the key: luna's owner, whose budget is spent at the test's default
    // address, is still answered from another, so the count is not kept per user or per host.
    $this->actingAs(billingOwner())->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->from(tenantUrl('luna', '/admin/billing'))->post(tenantUrl('luna', '/admin/billing/portal'))
        ->assertRedirect('https://billing.test/portal/cus_luna');
});

/*
 * The way back from canceled: a checkout for the row's own customer on the plan it last carried,
 * returning here, the session recorded on the subscriptions row.
 */

function billingCheckout(): string
{
    return tenantUrl('luna', '/admin/billing/checkout');
}

/**
 * @return list<array<string, mixed>>
 */
function billingCheckouts(): array
{
    return array_values(array_map(
        fn (array $question): array => $question[1],
        array_filter(billingFake()->asked, fn (array $question): bool => $question[0] === 'startCheckout'),
    ));
}

it('starts a checkout in canceled for the row\'s customer on the plan the body names, not the one last carried, returning here, recorded on the row, as an external redirect', function (string $carried, ?string $planRef, string $key, string $price) {
    billingOnPlan($carried, 'canceled');
    billingSet(['plan_ref' => $planRef]);
    $screen = tenantUrl('luna', '/admin/billing');
    $url = FakeBillingProvider::checkoutUrl('cus_luna', $key, $screen);

    $this->actingAs(billingOwner())->post(billingCheckout(), ['plan' => $key], ['X-Inertia' => 'true'])
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', $url);

    expect(billingCheckouts())->toBe([['plan' => $key, 'price' => $price, 'customer' => 'cus_luna', 'email' => 'owner@luna.example', 'success' => $screen, 'cancel' => $screen]])
        ->and(checkoutRecord())->toMatchArray(['checkout_session_id' => 'cs_fake_1', 'checkout_url' => $url])
        ->and(checkoutRecord()['checkout_opened_at'])->toBe('2026-10-05 09:00:00.000000')
        ->and(subscriptionColumns()['provider_subscription_id'])->toBe('sub_luna');
})->with([
    'the staff plan, last on staff' => ['staff', 'price_staff_test', 'staff', 'price_staff_test'],
    'the floor, last on staff' => ['staff', 'price_staff_test', 'floor', 'price_floor_test'],
    'the staff plan, last on the floor' => ['floor', 'price_floor_test', 'staff', 'price_staff_test'],
    'the staff plan, last on a price the catalogue does not list' => ['staff', 'price_gone', 'staff', 'price_staff_test'],
]);

it('starts a trial\'s checkout with no subscriptions row by creating the customer, and records a new row with the customer, the session, the URL and the instant', function (string $state, string $key, string $price) {
    billingSet(['subscription_state' => $state, 'entitlements' => $state === 'trial' ? '{staff}' : '{}', 'trial_ends_at' => $state === 'trial' ? '2026-10-08 09:00:00+00' : '2026-10-04 09:00:00+00']);
    $screen = tenantUrl('luna', '/admin/billing');
    $url = FakeBillingProvider::checkoutUrl('cus_fake_1', $key, $screen);

    $this->actingAs(billingOwner())->post(billingCheckout(), ['plan' => $key], ['X-Inertia' => 'true'])
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', $url);

    expect(billingCheckouts())->toBe([['plan' => $key, 'price' => $price, 'customer' => null, 'email' => 'owner@luna.example', 'success' => $screen, 'cancel' => $screen]])
        ->and(subscriptionColumns())->toMatchArray(['provider_customer_id' => 'cus_fake_1', 'provider_subscription_id' => null])
        ->and(checkoutRecord())->toBe(['checkout_session_id' => 'cs_fake_1', 'checkout_url' => $url, 'checkout_opened_at' => '2026-10-05 09:00:00.000000'])
        ->and(billingColumns()['subscription_state'])->toBe($state);
})->with([
    'in trial, the staff plan' => ['trial', 'staff', 'price_staff_test'],
    'in trial, the floor' => ['trial', 'floor', 'price_floor_test'],
    'canceled after a trial, the staff plan' => ['canceled', 'staff', 'price_staff_test'],
]);

it('reuses the customer of a trial\'s row its earlier checkout left, on the plan the body names', function (string $state) {
    billingSet(['subscription_state' => $state, 'trial_ends_at' => $state === 'trial' ? '2026-10-08 09:00:00+00' : '2026-10-04 09:00:00+00']);
    DB::table('subscriptions')->insert([
        'tenant_id' => DB::table('tenants')->where('slug', 'luna')->value('id'),
        'provider_customer_id' => 'cus_luna',
        'checkout_session_id' => 'cs_spent',
        'checkout_url' => 'https://checkout.example/cs_spent',
        'checkout_opened_at' => '2026-10-03 09:00:00+00',
    ]);
    $url = FakeBillingProvider::checkoutUrl('cus_luna', 'floor', tenantUrl('luna', '/admin/billing'));

    $this->actingAs(billingOwner())->post(billingCheckout(), ['plan' => 'floor'], ['X-Inertia' => 'true'])->assertHeader('X-Inertia-Location', $url);

    expect(array_column(billingCheckouts(), 'customer'))->toBe(['cus_luna'])
        ->and(DB::table('subscriptions')->count())->toBe(1)
        ->and(checkoutRecord())->toMatchArray(['checkout_session_id' => 'cs_fake_1', 'checkout_url' => $url]);
})->with(['trial' => ['trial'], 'canceled after a trial' => ['canceled']]);

it('answers a trial\'s open checkout under 24 hours with its URL whatever plan the body names, asking the provider nothing', function () {
    billingSet(['subscription_state' => 'trial', 'entitlements' => '{staff}', 'trial_ends_at' => '2026-10-08 09:00:00+00']);
    $url = FakeBillingProvider::checkoutUrl('cus_fake_1', 'staff', tenantUrl('luna', '/admin/billing'));

    $this->actingAs(billingOwner())->post(billingCheckout(), ['plan' => 'staff'], ['X-Inertia' => 'true'])->assertHeader('X-Inertia-Location', $url);
    $this->actingAs(billingOwner())->post(billingCheckout(), ['plan' => 'floor'], ['X-Inertia' => 'true'])->assertHeader('X-Inertia-Location', $url);

    expect(billingCheckouts())->toHaveCount(1);
});

it('refuses the floor with the count while more than one member is active, the implicit one counted, before any call to the provider, in the choice\'s sentence beside the button', function (string $state, bool $paused, SiteLanguage $language) {
    billingSet(['subscription_state' => $state, 'entitlements' => '{staff}', 'trial_ends_at' => '2026-10-08 09:00:00+00']);
    billingMember('Ana');
    billingMember('Kostas', 'inactive');

    $this->actingAs(billingReader($language))->from(tenantUrl('luna', '/admin/billing'))->post(billingCheckout(), ['plan' => 'floor'])
        ->assertRedirect(tenantUrl('luna', '/admin/billing'))
        ->assertSessionHas('flash', ['kind' => 'error', 'message' => billingRefusal(PlanChangeRefused::unchoosable('Solo', 2, $paused), $language)]);

    expect(billingFake()->asked)->toBe([])
        ->and(DB::table('subscriptions')->count())->toBe(0);

    $this->actingAs(billingReader($language))->post(billingCheckout(), ['plan' => 'staff'], ['X-Inertia' => 'true'])->assertStatus(409);

    expect(billingCheckouts())->toHaveCount(1);
})->with(['trial' => ['trial', false], 'canceled after a trial' => ['canceled', true]])->with('billing readers');

it('refuses a body naming no catalogue plan with the 400 envelope and its sentence, asking the provider nothing', function (array $body, SiteLanguage $language) {
    billingSet(['subscription_state' => 'trial', 'entitlements' => '{staff}', 'trial_ends_at' => '2026-10-08 09:00:00+00']);

    $this->actingAs(billingReader($language))->from(tenantUrl('luna', '/admin/billing'))->post(billingCheckout(), $body)
        ->assertStatus(400)
        ->assertJsonPath('code', 'invalid_request')
        ->assertJsonPath('data.detail', billingLine(CheckoutRequest::NO_PLAN, [], $language))
        ->assertSessionMissing('errors')
        ->assertSessionMissing('flash');

    expect(billingFake()->asked)->toBe([]);
})->with([
    'no plan' => [[]],
    'a plan the catalogue does not hold' => [['plan' => 'gold']],
    'a plan as a list' => [['plan' => ['staff']]],
])->with('billing readers');

it('refuses a checkout outside trial and canceled, and in canceled with neither a row nor a trial behind it, asking the provider nothing, and offers no control', function (?string $state, string $rowState, SiteLanguage $language) {
    if ($state !== null) {
        billingOnPlan('staff', $state);
    }

    billingSet(['subscription_state' => $rowState]);

    $this->actingAs(billingReader($language))->get(tenantUrl('luna', '/admin/billing'))
        ->assertInertia(fn (Assert $page) => $page->where('billing.resubscribe', null));
    $this->actingAs(billingReader($language))->from(tenantUrl('luna', '/admin/billing'))->post(billingCheckout(), ['plan' => 'staff'])
        ->assertRedirect(tenantUrl('luna', '/admin/billing'))
        ->assertSessionHas('flash', ['kind' => 'error', 'message' => billingLine(ResubscribeRefused::SENTENCE, [], $language)]);

    expect(billingFake()->asked)->toBe([]);
})->with([
    'active' => ['active', 'active'],
    'grace' => ['grace', 'grace'],
    'past_due' => ['past_due', 'past_due'],
    'incomplete' => ['incomplete', 'incomplete'],
    'no subscriptions row' => [null, 'active'],
    'canceled with no subscriptions row and no trial' => [null, 'canceled'],
])->with('billing readers');

it('lands a trial\'s checkout completion on the built tenant, provisioning nothing, clearing the trial end, the closing where one was set, and the record, and the next page reads the plan\'s state and set', function (string $status, string $price, string $state, string $stored, array $entitlements, ?string $closing) {
    billingSet(['subscription_state' => 'trial', 'entitlements' => '{staff}', 'trial_ends_at' => '2026-10-08 09:00:00+00', ...($closing === null ? [] : ['status' => 'closing', 'closing_at' => $closing])]);
    $log = billingLog();

    $this->actingAs(billingOwner())->post(billingCheckout(), ['plan' => 'staff'], ['X-Inertia' => 'true'])->assertStatus(409);
    billingFake()->script(billingSnapshot('sub_first', 'cus_fake_1', $status, [$price]));
    deliverBillingNotice($this, FakeBillingProvider::notice('evt_first', NoticeKind::CheckoutCompleted, 'cus_fake_1', 'sub_first'))->assertOk();

    $tenant = (array) DB::selectOne('select status, closing_at, trial_ends_at from tenants where slug = ?', ['luna']);

    expect($tenant)->toBe(['status' => 'active', 'closing_at' => null, 'trial_ends_at' => null])
        ->and(billingColumns())->toMatchArray(['subscription_state' => $state, 'entitlements' => $stored, 'plan_ref' => $price, 'narrowed' => '{}'])
        ->and(subscriptionColumns()['provider_subscription_id'])->toBe('sub_first')
        ->and(checkoutRecord()['checkout_session_id'])->toBeNull()
        ->and(array_column($log->records, 'message'))->toContain(SignupCompletion::BUILT);

    $this->actingAs(billingOwner())->get(tenantUrl('luna', '/admin/billing'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('subscription', ['state' => $state])
            ->where('entitlements', $entitlements));
})->with([
    'active on the staff plan' => ['active', 'price_staff_test', 'active', '{staff}', ['staff'], null],
    'active on the floor, nothing recorded narrowed' => ['active', 'price_floor_test', 'active', '{}', [], null],
    'incomplete on the staff plan, its set frozen until the charge lands' => ['incomplete', 'price_staff_test', 'incomplete', '{staff}', [], null],
    'active on the staff plan inside a closing window' => ['active', 'price_staff_test', 'active', '{staff}', ['staff'], '2026-10-04 09:00:00+00'],
]);

it('takes a tenant the platform closed after an unpaid trial back through this screen\'s checkout inside the window: closing_at cleared, active, the subscription landed and configuration writes admitted again', function () {
    billingSet(['subscription_state' => 'trial', 'entitlements' => '{staff}', 'trial_ends_at' => '2026-10-08 09:00:00+00']);
    app()->instance(Clock::class, FixedClock::at('2026-10-08 09:00'));
    Artisan::call('billing:lapse');
    app()->instance(Clock::class, FixedClock::at('2026-11-07 09:00'));
    Artisan::call('billing:lapse');
    $screen = tenantUrl('luna', '/admin/billing');
    $tenant = fn (): array => (array) DB::selectOne("select status, to_char(closing_at at time zone 'UTC', 'YYYY-MM-DD HH24:MI') as closing_at, trial_ends_at from tenants where slug = ?", ['luna']);

    expect($tenant())->toBe(['status' => 'closing', 'closing_at' => '2026-11-07 09:00', 'trial_ends_at' => '2026-10-08 09:00:00+00'])
        ->and(billingColumns()['subscription_state'])->toBe('canceled');
    $this->actingAs(billingOwner())->get($screen)->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('subscription.state', 'canceled')
            ->where('billing.notice', ['kind' => 'warning', 'sentence' => billingLine(ClosingWindow::CLOSES, ['day' => 'Monday 7 December 2026']).' '.billingLine(ClosingWindow::WAY_BACK), 'paused' => billingLine(BillingController::PAUSED)])
            ->where('billing.resubscribe.label', billingLine(BillingController::SUBSCRIBE)));
    // The gate reads the status beside the state: a closing account is refused as closing,
    // naming the day, whatever its state.
    $this->actingAs(billingOwner())->postJson(tenantUrl('luna', '/admin/blackouts'), ['date' => '2026-12-25'])
        ->assertForbidden()
        ->assertJsonPath('code', RequiresActiveSubscription::CLOSING)
        ->assertJsonPath('data.detail', billingLine(ClosingWindow::FROZEN, ['day' => 'Monday 7 December 2026']).' '.billingLine(ClosingWindow::WAY_BACK))
        ->assertJsonPath('data.closes_at', '2026-12-07T09:00:00+00:00');

    $this->actingAs(billingOwner())->post(billingCheckout(), ['plan' => 'staff'], ['X-Inertia' => 'true'])
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', FakeBillingProvider::checkoutUrl('cus_fake_1', 'staff', $screen));
    billingFake()->script(billingSnapshot('sub_back', 'cus_fake_1', 'active', ['price_staff_test']));
    deliverBillingNotice($this, FakeBillingProvider::notice('evt_back', NoticeKind::CheckoutCompleted, 'cus_fake_1', 'sub_back'))->assertOk();

    expect($tenant())->toBe(['status' => 'active', 'closing_at' => null, 'trial_ends_at' => null])
        ->and(billingColumns())->toMatchArray(['subscription_state' => 'active', 'entitlements' => '{staff}', 'plan_ref' => 'price_staff_test'])
        ->and(subscriptionColumns()['provider_subscription_id'])->toBe('sub_back')
        ->and(checkoutRecord()['checkout_session_id'])->toBeNull();
    $this->actingAs(billingOwner())->get($screen)
        ->assertInertia(fn (Assert $page) => $page
            ->where('billing.notice', ['kind' => 'success', 'sentence' => billingLine(BillingController::ACTIVE), 'paused' => null])
            ->where('subscription', ['state' => 'active']));
    $this->actingAs(billingOwner())->from($screen)->post(tenantUrl('luna', '/admin/blackouts'), ['date' => '2026-12-25'])
        ->assertRedirect($screen);

    expect(DB::table('tenant_luna.blackouts')->pluck('date')->all())->toBe(['2026-12-25']);
});

it('words the refusal as its own code, a 409 with its one sentence', function () {
    $envelope = ErrorEnvelope::for(new ResubscribeRefused);

    expect([$envelope->code, $envelope->status, $envelope->detail])->toBe(['resubscribe_refused', 409, billingLine(ResubscribeRefused::SENTENCE)])
        ->and(ResubscribeRefused::SENTENCE)->toBe('admin/billing.refusals.resubscribe');
});

it('sends a second press to the open checkout the row records, asking the provider nothing, and opens a new one once the record is 24 hours old', function () {
    billingOnPlan('staff', 'canceled');
    $url = FakeBillingProvider::checkoutUrl('cus_luna', 'staff', tenantUrl('luna', '/admin/billing'));

    $this->actingAs(billingOwner())->post(billingCheckout(), ['plan' => 'staff'], ['X-Inertia' => 'true'])->assertHeader('X-Inertia-Location', $url);
    $this->actingAs(billingOwner())->post(billingCheckout(), ['plan' => 'staff'], ['X-Inertia' => 'true'])->assertHeader('X-Inertia-Location', $url);

    expect(billingCheckouts())->toHaveCount(1);

    DB::table('subscriptions')->update(['checkout_opened_at' => DB::raw("checkout_opened_at - interval '24 hours'")]);
    $this->actingAs(billingOwner())->post(billingCheckout(), ['plan' => 'staff'], ['X-Inertia' => 'true'])->assertHeader('X-Inertia-Location', $url);

    expect(billingCheckouts())->toHaveCount(2)
        ->and(checkoutRecord()['checkout_session_id'])->toBe('cs_fake_2');
});

it('offers Subscribe again beside the ended sentence in canceled, and Continue to checkout with the on-its-way sentence while a checkout is open', function (SiteLanguage $language) {
    billingOnPlan('staff', 'canceled');
    $screen = fn () => billingPage($this, $language);

    $screen()->assertInertia(fn (Assert $page) => $page
        ->where('billing.notice.sentence', billingLine(BillingController::CANCELED, [], $language))
        ->where('billing.resubscribe', ['label' => billingLine(BillingController::SUBSCRIBE_AGAIN, [], $language), 'pending' => null, 'plan' => 'staff', 'choice' => null])
        ->where('billing.portal', true));

    $this->actingAs(billingReader($language))->post(billingCheckout(), ['plan' => 'staff'], ['X-Inertia' => 'true']);

    $screen()->assertInertia(fn (Assert $page) => $page
        ->where('billing.notice.sentence', billingLine(BillingController::CANCELED, [], $language))
        ->where('billing.resubscribe', ['label' => billingLine(BillingController::CONTINUE_CHECKOUT, [], $language), 'pending' => billingLine(BillingController::ON_ITS_WAY, [], $language), 'plan' => 'staff', 'choice' => null]));

    DB::table('subscriptions')->update(['checkout_opened_at' => DB::raw("checkout_opened_at - interval '24 hours'")]);

    $screen()->assertInertia(fn (Assert $page) => $page
        ->where('billing.resubscribe', ['label' => billingLine(BillingController::SUBSCRIBE_AGAIN, [], $language), 'pending' => null, 'plan' => 'staff', 'choice' => null]));
})->with('billing readers');

it('reads active with Staff in the rail once the checkout\'s completion lands the new subscription, the control gone', function () {
    billingOnPlan('staff', 'canceled');

    $this->actingAs(billingOwner())->get(tenantUrl('luna', '/admin/billing'))
        ->assertInertia(fn (Assert $page) => $page->where('nav', fn (mixed $nav): bool => ! billingRailHas($nav, navLabel('staff'))));
    $this->actingAs(billingOwner())->post(billingCheckout(), ['plan' => 'staff'], ['X-Inertia' => 'true']);
    billingFake()->script(billingSnapshot('sub_back', 'cus_luna', 'active', ['price_staff_test']));
    deliverBillingNotice($this, FakeBillingProvider::notice('evt_back', NoticeKind::CheckoutCompleted, 'cus_luna', 'sub_back'))->assertOk();

    $this->actingAs(billingOwner())->get(tenantUrl('luna', '/admin/billing'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('billing.notice', ['kind' => 'success', 'sentence' => billingLine(BillingController::ACTIVE), 'paused' => null])
            ->where('billing.resubscribe', null)
            ->where('subscription', ['state' => 'active'])
            ->where('nav', fn (mixed $nav): bool => billingRailHas($nav, navLabel('staff'))));

    expect(subscriptionColumns()['provider_subscription_id'])->toBe('sub_back')
        ->and(checkoutRecord()['checkout_session_id'])->toBeNull();
});

it('words a checkout the provider could not open as a retry, the provider\'s sentence in the log alone', function (SiteLanguage $language) {
    billingOnPlan('staff', 'canceled');
    $log = billingLog();
    billingFake()->checkoutFails = BillingProviderFailed::unreachable('start a checkout', 'timed out');

    $this->actingAs(billingReader($language))->from(tenantUrl('luna', '/admin/billing'))->post(billingCheckout(), ['plan' => 'staff'])
        ->assertRedirect(tenantUrl('luna', '/admin/billing'))
        ->assertSessionHas('flash', ['kind' => 'error', 'message' => billingLine(BillingController::CHECKOUT_FAILED, [], $language)]);

    expect($log->records[0]['message'] ?? null)->toBe(BillingController::PROVIDER_LOGGED)
        ->and(checkoutRecord()['checkout_session_id'])->toBeNull();
})->with('billing readers');

/*
 * The billing section, lang/en/admin/billing.php: every constant that held a sentence the owner
 * reads holds its key, the plan change's refusals carry theirs with their values, and each is
 * read in the request's language, the user's.
 */

it('refuses a closing account\'s checkout in the reader\'s language when the window ends before a checkout opened now would lapse, asking the provider nothing, and opens one when the window outlasts it', function (string $closingAt, bool $refused, SiteLanguage $language) {
    app()->instance(Clock::class, FixedClock::at('2026-11-07 09:00'));
    billingOnPlan('staff', 'canceled');
    billingSet(['status' => 'closing', 'closing_at' => $closingAt]);
    $screen = tenantUrl('luna', '/admin/billing');
    $press = $this->actingAs(billingReader($language))->from($screen)->post(billingCheckout(), ['plan' => 'staff'], $refused ? [] : ['X-Inertia' => 'true']);

    if ($refused) {
        $press->assertRedirect($screen)
            ->assertSessionHas('flash', ['kind' => 'error', 'message' => billingLine(ResubscribeRefused::WINDOW_ENDING_SENTENCE, [], $language)]);

        expect(billingFake()->asked)->toBe([]);

        return;
    }

    $press->assertStatus(409)->assertHeader('X-Inertia-Location', FakeBillingProvider::checkoutUrl('cus_luna', 'staff', $screen));
})->with([
    'the window ends in twelve hours' => ['2026-10-08 21:00:00+00', true],
    'the window ends a minute short of a day' => ['2026-10-09 08:59:00+00', true],
    'the window ends a day and an hour away' => ['2026-10-09 10:00:00+00', false],
])->with('billing readers');

it('sets the implicit member back to offered online when a snapshot takes the staff feature away, whoever asked, leaving every named member\'s flag as it was', function (?Entitlements $chosen) {
    billingOnPlan('staff');
    billingMember('Ana', 'inactive');
    DB::table('tenant_luna.staff')->update(['online' => false]);

    syncWith()->apply(billingSnapshot('sub_luna', 'cus_luna', 'active', ['price_floor_test']), chosen: $chosen);

    expect(DB::table('tenant_luna.staff')->orderBy('id')->pluck('online', 'is_implicit')->all())->toBe([1 => true, 0 => false])
        ->and(billingColumns()['entitlements'])->toBe('{}');
})->with([
    'the owner chose the floor' => [Entitlements::none()],
    'the provider changed it, as the webhook hands it' => [null],
]);

it('leaves the implicit member\'s flag as it was when a snapshot keeps the staff feature or never carried it', function (string $from, string $price) {
    billingOnPlan($from);
    DB::table('tenant_luna.staff')->update(['online' => false]);

    syncWith()->apply(billingSnapshot('sub_luna', 'cus_luna', 'active', [$price]));

    expect(DB::table('tenant_luna.staff')->where('is_implicit', true)->value('online'))->toBeFalse();
})->with([
    'staff kept' => ['staff', 'price_staff_test'],
    'the floor kept' => ['floor', 'price_floor_test'],
]);

it('words the closing window\'s refusal as its own code, a 409 with its one sentence', function () {
    $envelope = ErrorEnvelope::for(ResubscribeRefused::windowEnding());

    expect([$envelope->code, $envelope->status, $envelope->detail])->toBe(['window_ending', 409, billingLine(ResubscribeRefused::WINDOW_ENDING_SENTENCE)]);
});

it('holds in billing.php every key the Billing screen\'s, the refusals\', the closing\'s and the gate\'s constants name, so no key path reaches a screen', function () {
    $missing = [];
    $walked = 0;

    foreach ([BillingController::class, PlanChangeRefused::class, ResubscribeRefused::class, ClosingWindow::class, CloseRefused::class, RequiresActiveSubscription::class, ChangePlanRequest::class, CheckoutRequest::class, SettingsController::class] as $class) {
        foreach ((new ReflectionClass($class))->getReflectionConstants(ReflectionClassConstant::IS_PUBLIC) as $constant) {
            $name = $constant->getName();
            $value = $constant->getValue();

            foreach (is_array($value) ? $value : [$value] as $key) {
                if (! is_string($key) || ! str_starts_with($key, 'admin/billing.')) {
                    continue;
                }

                if (! app('translator')->has($key, 'en', false)) {
                    $missing[] = $class.'::'.$name.': '.$key;
                }

                $walked++;
            }
        }
    }

    expect($missing)->toBe([])
        ->and($walked)->toBe(52)
        ->and(BillingController::SECTION)->toBe('billing');
});

it('reads the plan\'s floor refusals as choices by the active members, at one member and at several, the count filled', function (int $active) {
    expect(PlanChangeRefused::downgrade($active)->sentence())->toBe(adminChoice(PlanChangeRefused::DOWNGRADE_SENTENCE, $active))
        ->and(PlanChangeRefused::unchoosable('Solo', $active, paused: false)->sentence())->toBe(adminChoice(PlanChangeRefused::UNCHOOSABLE_SENTENCE, $active, ['plan' => 'Solo']))
        ->and(PlanChangeRefused::unchoosable('Solo', $active, paused: true)->sentence())->toBe(adminChoice(PlanChangeRefused::UNCHOOSABLE_PAUSED_SENTENCE, $active, ['plan' => 'Solo']))
        ->and(PlanChangeRefused::downgrade($active)->sentence())->toContain((string) $active)
        ->and(PlanChangeRefused::downgrade($active)->getMessage())->toBe(PlanChangeRefused::DOWNGRADE_SENTENCE);
})->with([
    'one member' => [1],
    'several members' => [3],
]);

it('words the notice, the paused paragraph, the floor\'s refusal, a flash and the gate\'s refusal of a setup write in the reader\'s language, each the file\'s own line', function (SiteLanguage $language) {
    billingOnPlan('staff', 'past_due');
    billingMember('Elena');

    billingPage($this, $language)
        ->assertInertia(fn (Assert $page) => $page
            ->where('billing.notice', ['kind' => 'warning', 'sentence' => billingLine(BillingController::PAST_DUE, [], $language), 'paused' => billingLine(BillingController::PAUSED, [], $language)])
            ->where('billing.change.blocked', billingRefusal(PlanChangeRefused::downgrade(2), $language))
            ->where('billing.resubscribe', null)
            ->where('billing.plan.includes', billingIncludes(BillingController::STAFF_INCLUDES, $language)));
    $this->actingAs(billingOwner())->from(tenantUrl('luna', '/admin/billing'))->post(tenantUrl('luna', '/admin/billing/plan'), ['plan' => 'floor'])
        ->assertSessionHas('flash', ['kind' => 'error', 'message' => billingRefusal(PlanChangeRefused::downgrade(2), $language)]);
    $this->actingAs(billingOwner())->from(tenantUrl('luna', '/admin/billing'))->post(tenantUrl('luna', '/admin/billing/plan'), ['plan' => 'staff'])
        ->assertSessionHas('flash', ['kind' => 'info', 'message' => billingLine(BillingController::ALREADY_ON, [], $language)]);
    // The gate's 403 on a setup write while the subscription is not active.
    $this->actingAs(billingOwner())->postJson(tenantUrl('luna', '/admin/blackouts'), ['date' => '2026-12-25'])
        ->assertForbidden()
        ->assertJsonPath('data.detail', billingLine(RequiresActiveSubscription::DETAIL, [], $language));
})->with('billing readers');

it('words a refusal that carries no count in the language named, through the Greek file\'s own line rather than the key or the English', function () {
    expect(PlanChangeRefused::ended()->count)->toBeNull()
        ->and(PlanChangeRefused::ended()->sentence(SiteLanguage::Greek))->toBe(billingLine(PlanChangeRefused::ENDED_SENTENCE, [], SiteLanguage::Greek))
        ->and(PlanChangeRefused::ended()->sentence(SiteLanguage::English))->toBe(billingLine(PlanChangeRefused::ENDED_SENTENCE));
});

it('reaches each case of the five choice lines in Greek at one and at two, the two cases unlike', function (string $key) {
    $one = adminChoice($key, 1, ['plan' => 'Solo', 'count' => '1', 'day' => 'day-token', 'zone' => 'Europe/Athens'], SiteLanguage::Greek);
    $two = adminChoice($key, 2, ['plan' => 'Solo', 'count' => '2', 'day' => 'day-token', 'zone' => 'Europe/Athens'], SiteLanguage::Greek);

    expect($one)->not->toBe($two)
        ->and($one)->not->toBe(adminChoice($key, 1, ['plan' => 'Solo', 'count' => '1', 'day' => 'day-token', 'zone' => 'Europe/Athens']))
        ->and($two)->not->toBe(adminChoice($key, 2, ['plan' => 'Solo', 'count' => '2', 'day' => 'day-token', 'zone' => 'Europe/Athens']))
        ->and(unfilledIn($one.$two))->toBe([]);
})->with([
    'the floor\'s removal refused' => [PlanChangeRefused::DOWNGRADE_SENTENCE],
    'the floor refused in the plan choice' => [PlanChangeRefused::UNCHOOSABLE_SENTENCE],
    'the floor refused in the plan choice while paused' => [PlanChangeRefused::UNCHOOSABLE_PAUSED_SENTENCE],
    'the close\'s line in the Danger zone' => [SettingsController::CLOSE_WHAT],
    'the close\'s day in its dialog' => ['admin/billing.danger.close.when'],
]);

/*
 * The payment card's two tax lines: the VAT line in the first render beside the portal, and the
 * tax id line a deferred prop the client loads after it, so the render itself asks the provider
 * nothing.
 */

/**
 * The deferred props a first render names, by group, as the page object carries them: null where
 * it names none.
 *
 * @param  TestResponse<Response>  $response
 */
function billingDeferred(TestResponse $response): mixed
{
    $page = $response->viewData('page');

    return is_array($page) ? ($page['deferredProps'] ?? null) : null;
}

it('renders the VAT line beside the portal and defers the tax id line, the first render asking the provider nothing', function () {
    billingOnPlan('floor');
    billingFake()->scriptTaxIds('cus_luna', [new TaxId('eu_vat', 'EL123456789'), new TaxId('eu_vat', 'DE123456789')]);

    $response = $this->actingAs(billingOwner())->get(tenantUrl('luna', '/admin/billing'));
    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('billing.vat', billingLine(BillingController::VAT_LINE))
        ->where('billing.portal', true)
        ->missing(BillingController::TAX_ID));

    expect(billingFake()->asked)->toBe([])
        ->and(billingDeferred($response))->toBe(['default' => [BillingController::TAX_ID]]);

    $response->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $reload) => $reload
        ->where(BillingController::TAX_ID, billingLine(BillingController::TAX_ID_NUMBER, ['tax_id' => 'EL123456789']))));

    expect(billingFake()->asked)->toBe([['taxIds', ['customer' => 'cus_luna']]]);
});

it('words the loaded tax id line for a number, for none on file and for a provider that could not read it or a missing key, in the user\'s language', function (SiteLanguage $language, TaxId|Throwable|null $held, string $key, ?string $taxId, bool $logged) {
    billingOwner()->forceFill(['locale' => $language->value])->save();
    billingOnPlan('floor');
    billingFake()->scriptTaxIds('cus_luna', $held instanceof TaxId ? [$held] : $held ?? []);
    $log = billingLog();

    $this->actingAs(billingOwner())->get(tenantUrl('luna', '/admin/billing'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('billing.vat', adminLine(BillingController::VAT_LINE, [], $language))
            ->where('billing.notice.kind', 'success')
            ->where('billing.plan.name', 'Solo')
            ->loadDeferredProps(fn (Assert $reload) => $reload->where(BillingController::TAX_ID, adminLine($key, $taxId === null ? [] : ['tax_id' => $taxId], $language))));

    expect(billingFake()->asked)->toBe([['taxIds', ['customer' => 'cus_luna']]])
        ->and($log->records)->toBe($logged
            ? [['level' => 'warning', 'message' => BillingController::TAX_ID_LOGGED, 'context' => ['tenant' => 'luna', 'message' => $held instanceof Throwable ? $held->getMessage() : null]]]
            : []);
})->with([
    'English' => [SiteLanguage::English],
    // The Greek file is lang/el/admin/tax.php itself, never a fallback: the next case holds each
    // of its lines apart from the English one.
    'Greek' => [SiteLanguage::Greek],
])->with([
    'a number' => [new TaxId('eu_vat', 'EL123456789'), BillingController::TAX_ID_NUMBER, 'EL123456789', false],
    'none on file' => [null, BillingController::TAX_ID_NONE, null, false],
    'the provider failing' => [fn () => BillingProviderFailed::unreachable('read the tax ids', 'timed out'), BillingController::TAX_ID_UNREADABLE, null, true],
    // A deploy without the secret key: a visit words it as the provider failing, never raises.
    'a missing key' => [fn () => BillingNotConfigured::noSecretKey(), BillingController::TAX_ID_UNREADABLE, null, true],
]);

it('reads each tax line in Greek from the Greek file, not the English one by the fallback', function (string $key) {
    expect(adminLine($key, ['tax_id' => 'EL123456789'], SiteLanguage::Greek))->not->toBe(adminLine($key, ['tax_id' => 'EL123456789']));
})->with([
    'the VAT line' => [BillingController::VAT_LINE],
    'the number' => [BillingController::TAX_ID_NUMBER],
    'none on file' => [BillingController::TAX_ID_NONE],
    'could not be read' => [BillingController::TAX_ID_UNREADABLE],
]);

it('answers the deferred request with the tax id line alone, working out none of the page again', function () {
    billingOnPlan('floor');
    config(['billing.plans.staff.price' => '']);
    $log = billingLog();

    $this->actingAs(billingOwner())->get(tenantUrl('luna', '/admin/billing'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('billing.unavailable', billingLine(BillingController::CATALOGUE_UNREADABLE))
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->where(BillingController::TAX_ID, billingLine(BillingController::TAX_ID_NONE))
                ->missing('billing')));

    // The full visit reads the catalogue once; the deferred request reads it never.
    expect(array_map(fn (array $record): array => [$record['level'], $record['message']], $log->records))
        ->toBe([['error', BillingController::CATALOGUE_LOGGED]]);
});

it('carries neither tax line where no subscriptions row exists, a trial\'s or a free tenant\'s, and asks the provider nothing', function (string $state) {
    billingSet(['subscription_state' => $state, 'trial_ends_at' => $state === 'trial' ? '2026-10-08 09:00:00+00' : null]);

    $response = $this->actingAs(billingOwner())->get(tenantUrl('luna', '/admin/billing'));
    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('billing.vat', null)
        ->where('billing.portal', false)
        ->missing(BillingController::TAX_ID));

    expect($response->viewData('page'))->toBeArray()
        ->and(billingDeferred($response))->toBeNull()
        ->and(billingFake()->asked)->toBe([]);
})->with([
    'a trial' => ['trial'],
    'a free tenant' => ['active'],
]);

it('holds the four tax line constants to the keys of lang/en/admin/tax.php, every key named once', function () {
    $named = array_map(
        fn (string $key): string => substr($key, strlen('admin/tax.')),
        [BillingController::VAT_LINE, BillingController::TAX_ID_NUMBER, BillingController::TAX_ID_NONE, BillingController::TAX_ID_UNREADABLE],
    );

    $file = require lang_path('en/admin/tax.php');

    expect($named)->toHaveCount(4)
        ->and($file)->toBeArray()
        ->and(array_keys(is_array($file) ? $file : []))->toBe($named);
});
