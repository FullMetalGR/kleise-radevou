<?php

use App\Application\Clock;
use App\Http\AdminSession;
use App\Http\Controllers\Admin\PaymentsController;
use App\Http\Setup\SetupWizard;
use App\Infrastructure\Db\SqlText;
use App\Models\User;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FakePaymentAdapter;
use Tests\Support\FixedClock;
use Tests\TestCase;

pest()->use(DatabaseTruncation::class);

/*
 * The Online payments step of setup, on the fake provider with platform mode offered: the panel
 * handed Settings' Payments prop and words whole; Connect through us and Continue onboarding on
 * the step's own routes, the provider told to bring the owner back to the step's fixed way back
 * whatever the request carries; and that way back landing on Home, the wizard opened on Online
 * payments for an owner still in setup and plain Home for anyone else. The step's two writes are
 * refused to anyone the wizard is not offered to. Neither a write nor the way back records setup
 * as finished: where the provider closes the last open step meanwhile, the write still lands and
 * the Home answer after it is the one that says so.
 */

beforeEach(function () {
    $this->withoutVite();
    dropDemoSchemas();
    seedDemo();
    app()->instance(Clock::class, FixedClock::at('2026-11-02 06:00:00'));
    Http::preventStrayRequests();
    config(['payments.platform_fee' => ['percent' => '1.5'], 'payments.platform.'.FakePaymentAdapter::SLUG => FakePaymentAdapter::PLATFORM_KEYS]);
    DB::table('tenant_luna.settings')->where('id', 1)->update([
        'setup_opened_at' => null, 'setup_finished_at' => null, 'setup_profile_saved_at' => null,
        'setup_team_skipped_at' => null, 'setup_payments_skipped_at' => null, 'setup_link_finished_at' => null,
        'hours_confirmed_at' => null, 'link_copied_at' => null,
    ]);
});

afterEach(function () {
    Http::assertNothingSent();
    dropDemoSchemas();
    DB::table('support_sessions')->delete();
    DB::table('users')->delete();
    DB::table('tenants')->delete();
});

function paymentsStepOwner(): User
{
    return User::query()->where('email', 'owner@luna.example')->sole();
}

/**
 * One of the step's writes from Home, as the wizard sends it.
 *
 * @param  array<array-key, mixed>  $body
 * @return TestResponse<Response>
 */
function paymentsStepWrite(TestCase $test, string $path, array $body = [], ?User $user = null): TestResponse
{
    return $test->actingAs($user ?? paymentsStepOwner())->from(tenantUrl('luna', '/admin'))
        ->json('POST', tenantUrl('luna', '/admin/setup/payments'.$path), $body, ['X-Inertia' => 'true']);
}

/**
 * Home's page for the viewer.
 *
 * @return array<string, mixed>
 */
function paymentsStepHome(TestCase $test, ?User $user = null): array
{
    $page = $test->actingAs($user ?? paymentsStepOwner())->get(tenantUrl('luna', '/admin'))->assertOk()->viewData('page');
    assert(is_array($page) && is_array($page['props']));

    /** @var array<string, mixed> */
    return $page['props'];
}

function paymentsStepBack(): string
{
    return tenantUrl('luna', '/admin/setup/payments/back');
}

/** Closes every step of luna's setup but Online payments, each by the business's own data. */
function paymentsStepLastOpen(): void
{
    DB::table('tenant_luna.settings')->where('id', 1)->update(['about' => 'Haircuts since 1998.', 'hours_confirmed_at' => DB::raw('now()'), 'setup_link_finished_at' => DB::raw('now()')]);
}

/**
 * The provider's notice landing, no Home answer beside it: luna's connection now works, which
 * closes the step, the provider still listing what it needs where one thing is named as due.
 */
function paymentsStepWorking(?string $due = null): void
{
    DB::table('tenant_luna.payment_connections')->update([
        'state' => 'active', 'last_check_ok' => true, 'requirements' => $due === null ? null : json_encode(['currently_due' => [$due]], JSON_THROW_ON_ERROR),
    ]);
}

function paymentsStepFinished(): bool
{
    return DB::table('tenant_luna.settings')->where('id', 1)->value('setup_finished_at') !== null;
}

/** A support session of luna open for an hour from the clock's now, as the console's open leaves it. */
function paymentsStepSupportSession(): int
{
    $now = app(Clock::class)->now();

    return DB::table('support_sessions')->insertGetId([
        'operator_id' => addOperator()->id,
        'tenant_id' => Tenant::query()->where('slug', 'luna')->value('id'),
        'opened_at' => SqlText::instant($now),
        'expires_at' => SqlText::instant($now->add(new DateInterval('PT60M'))),
        'created_at' => SqlText::instant($now),
    ]);
}

it('hands the step Settings\' Payments prop and the payments words whole, before a connection and with one pending', function () {
    $settings = fn (): mixed => data_get($this->actingAs(paymentsStepOwner())->get(tenantUrl('luna', '/admin/settings'))->viewData('page'), 'props.payments');

    expect(data_get(paymentsStepHome($this), 'setup.data.payments'))->toBe($settings())
        ->and(data_get(paymentsStepHome($this), 'strings.payments'))->toBe(__('admin/'.PaymentsController::SECTION, [], 'en'))
        ->and(data_get(paymentsStepHome($this), 'setup.data.payments.connection'))->toBeNull();

    paymentsStepWrite($this, '/platform', ['provider' => FakePaymentAdapter::SLUG])->assertStatus(409);

    expect(data_get(paymentsStepHome($this), 'setup.data.payments'))->toBe($settings())
        ->and(data_get(paymentsStepHome($this), 'setup.data.payments.connection.state'))->toBe('pending');
});

it('connects through us from the step, the provider told to bring the owner back to the step\'s way back, a way back the body names ignored', function () {
    paymentsStepWrite($this, '/platform', ['provider' => FakePaymentAdapter::SLUG, 'return' => 'https://elsewhere.example/', 'back' => '/admin/settings', 'path' => '//elsewhere.example'])
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', 'https://onboarding.paydesk.test/pdk_acct_1/1');

    expect(paymentFake()->accounts)->toBe(['pdk_acct_1'])
        ->and(paymentFake()->links)->toBe([['account' => 'pdk_acct_1', 'return' => paymentsStepBack(), 'token' => FakePaymentAdapter::PLATFORM_KEYS['api_token']]])
        ->and(DB::table('tenant_luna.payment_connections')->value('state'))->toBe('pending');
});

it('continues the onboarding from the step for the same account, back to the step\'s way back, and the 404 with nothing to continue', function () {
    paymentsStepWrite($this, '/platform/resume')->assertNotFound();
    paymentsStepWrite($this, '/platform', ['provider' => FakePaymentAdapter::SLUG])->assertStatus(409);

    paymentsStepWrite($this, '/platform/resume', ['return' => 'https://elsewhere.example/'])
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', 'https://onboarding.paydesk.test/pdk_acct_1/2');

    expect(paymentFake()->accounts)->toBe(['pdk_acct_1'])
        ->and(array_column(paymentFake()->links, 'return'))->toBe([paymentsStepBack(), paymentsStepBack()]);
});

it('answers a provider failing from the step with Settings\' error flash, back on Home', function () {
    paymentFake()->openFailsWith = 'Paydesk is down';

    paymentsStepWrite($this, '/platform', ['provider' => FakePaymentAdapter::SLUG])
        ->assertRedirect(tenantUrl('luna', '/admin'))
        ->assertSessionHas('flash', ['kind' => 'error', 'message' => adminLine(PaymentsController::ONBOARDING_FAILED)]);

    expect(DB::table('tenant_luna.payment_connections')->count())->toBe(0);
});

it('lands the way back on Home with the wizard opened on Online payments for an owner in setup, whatever the address carries beside its path', function (string $query) {
    $this->actingAs(paymentsStepOwner())->get(paymentsStepBack().$query)
        ->assertRedirect(tenantUrl('luna', '/admin'))
        ->assertSessionHas(SetupWizard::OPEN_ON, 'payments');

    expect(data_get(paymentsStepHome($this), 'setup.opens'))->toBe('payments');
})->with([
    'nothing beside' => [''],
    'a step and an address forged' => ['?step=team&to=https%3A%2F%2Felsewhere.example%2F&back=%2F%2Felsewhere.example'],
]);

it('lands the way back on plain Home for a manager and for an owner whose setup is finished, opening no wizard', function () {
    $manager = User::query()->findOrFail(addTenantUser('mira@luna.example', 'manager'));

    $this->actingAs($manager)->get(paymentsStepBack().'?step=payments')
        ->assertRedirect(tenantUrl('luna', '/admin'))
        ->assertSessionMissing(SetupWizard::OPEN_ON);
    expect(paymentsStepHome($this, $manager))->not->toHaveKey('setup');

    DB::table('tenant_luna.settings')->where('id', 1)->update(['setup_finished_at' => DB::raw('now()')]);
    $this->actingAs(paymentsStepOwner())->get(paymentsStepBack())
        ->assertRedirect(tenantUrl('luna', '/admin'))
        ->assertSessionMissing(SetupWizard::OPEN_ON);
    expect(paymentsStepHome($this))->not->toHaveKey('setup');
});

it('refuses the step\'s two writes to a manager, an owner whose setup is finished and a lapsed subscription, opening nothing', function () {
    $manager = User::query()->findOrFail(addTenantUser('mira@luna.example', 'manager'));

    paymentsStepWrite($this, '/platform', ['provider' => FakePaymentAdapter::SLUG], $manager)->assertForbidden();
    paymentsStepWrite($this, '/platform/resume', [], $manager)->assertForbidden();

    DB::table('tenant_luna.settings')->where('id', 1)->update(['setup_finished_at' => DB::raw('now()')]);
    paymentsStepWrite($this, '/platform', ['provider' => FakePaymentAdapter::SLUG])->assertForbidden();
    paymentsStepWrite($this, '/platform/resume')->assertForbidden();

    DB::table('tenant_luna.settings')->where('id', 1)->update(['setup_finished_at' => null]);
    DB::table('tenants')->where('slug', 'luna')->update(['subscription_state' => 'past_due']);
    paymentsStepWrite($this, '/platform', ['provider' => FakePaymentAdapter::SLUG])->assertForbidden()->assertJsonPath('code', 'subscription_required');
    paymentsStepWrite($this, '/platform/resume')->assertForbidden()->assertJsonPath('code', 'subscription_required');

    expect(paymentFake()->accounts)->toBe([])
        ->and(DB::table('tenant_luna.payment_connections')->count())->toBe(0);
});

it('refuses the step\'s two writes to a support session and a staff login, opening nothing', function () {
    $write = fn (string $path, array $body = []): TestResponse => $this->from(tenantUrl('luna', '/admin'))->json('POST', tenantUrl('luna', '/admin/setup/payments'.$path), $body, ['X-Inertia' => 'true']);

    $this->withSession([AdminSession::SUPPORT_KEY => paymentsStepSupportSession()]);
    $write('/platform', ['provider' => FakePaymentAdapter::SLUG])->assertForbidden();
    $write('/platform/resume')->assertForbidden();
    $this->flushSession();

    $staff = User::query()->findOrFail(addTenantUser('elena@luna.example', 'staff', staff: 'Elena Papadaki'));
    paymentsStepWrite($this, '/platform', ['provider' => FakePaymentAdapter::SLUG], $staff)->assertForbidden();
    paymentsStepWrite($this, '/platform/resume', [], $staff)->assertForbidden();

    expect(paymentFake()->accounts)->toBe([])
        ->and(DB::table('tenant_luna.payment_connections')->count())->toBe(0);
});

it('lands the way back on plain Home for a support session and a staff login, neither told a step to open', function () {
    // Home, where a visitor who is not signed in would be sent to sign in.
    $this->withSession([AdminSession::SUPPORT_KEY => paymentsStepSupportSession()])->get(paymentsStepBack())
        ->assertRedirect(tenantUrl('luna', '/admin'))
        ->assertSessionMissing(SetupWizard::OPEN_ON);
    $this->flushSession();

    $staff = User::query()->findOrFail(addTenantUser('elena@luna.example', 'staff', staff: 'Elena Papadaki'));
    $this->actingAs($staff)->get(paymentsStepBack())
        ->assertRedirect(tenantUrl('luna', '/admin'))
        ->assertSessionMissing(SetupWizard::OPEN_ON);
});

it('offers the demo account\'s owner neither of the step\'s writes and no step to open on the way back', function () {
    config(['site.demo_slug' => 'luna']);

    paymentsStepWrite($this, '/platform', ['provider' => FakePaymentAdapter::SLUG])->assertForbidden();
    paymentsStepWrite($this, '/platform/resume')->assertForbidden();
    $this->actingAs(paymentsStepOwner())->get(paymentsStepBack())
        ->assertRedirect(tenantUrl('luna', '/admin'))
        ->assertSessionMissing(SetupWizard::OPEN_ON);

    expect(paymentsStepHome($this))->not->toHaveKey('setup')
        ->and(paymentFake()->accounts)->toBe([])
        ->and(DB::table('tenant_luna.payment_connections')->count())->toBe(0);
});

it('continues the onboarding for an owner whose last open step the provider closed meanwhile, the Home answer after it the one that says setup is finished', function () {
    paymentsStepLastOpen();
    paymentsStepWrite($this, '/platform', ['provider' => FakePaymentAdapter::SLUG])->assertStatus(409);
    expect(data_get(paymentsStepHome($this), 'setup.steps.4'))->toBe(['key' => 'payments', 'done' => false, 'skipped' => false]);

    paymentsStepWorking('business_profile.url');
    paymentsStepWrite($this, '/platform/resume')
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', 'https://onboarding.paydesk.test/pdk_acct_1/2');

    // With nothing left due the answer is Settings' own, nothing to continue, never the refusal.
    paymentsStepWorking();
    paymentsStepWrite($this, '/platform/resume')->assertNotFound();
    expect(paymentsStepFinished())->toBeFalse();

    expect(paymentsStepHome($this))->toHaveKey('setup_finished', true)->not->toHaveKey('setup');
    expect(paymentsStepFinished())->toBeTrue()
        ->and(paymentsStepHome($this))->not->toHaveKey('setup_finished')->not->toHaveKey('setup');
});

it('records nothing on the way back where the provider closed the last open step meanwhile, the Home answer after it the one that says setup is finished', function () {
    paymentsStepLastOpen();
    paymentsStepWrite($this, '/platform', ['provider' => FakePaymentAdapter::SLUG])->assertStatus(409);
    paymentsStepWorking();

    $this->actingAs(paymentsStepOwner())->get(paymentsStepBack())->assertRedirect(tenantUrl('luna', '/admin'));
    expect(paymentsStepFinished())->toBeFalse();

    expect(paymentsStepHome($this))->toHaveKey('setup_finished', true)->not->toHaveKey('setup');
    expect(paymentsStepFinished())->toBeTrue()
        ->and(paymentsStepHome($this))->not->toHaveKey('setup_finished')->not->toHaveKey('setup');
});
