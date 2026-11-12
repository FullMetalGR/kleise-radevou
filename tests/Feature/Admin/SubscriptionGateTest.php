<?php

use App\Http\Middleware\RequiresActiveSubscription;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\SetTenantLocale;
use App\Platform\ClosingWindow;
use App\Site\SiteLanguage;
use App\Tenancy\TenantUrls;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

pest()->use(RefreshDatabase::class);

beforeEach(fn () => $this->withoutVite());

/*
 * The subscription gate for configuration writes. It is driven here through a route registered
 * for the test on the tenant's domain behind tenant resolution and the request's language, as
 * the tenant's routes are (routes/tenant.php's group). A closing tenant is refused by its status
 * beside its state, with a sentence of its own.
 */

beforeEach(function () {
    provisionTenant('luna');

    Route::domain('{tenant}.'.app(TenantUrls::class)->centralHost())
        ->middleware(['web', ResolveTenant::class, SetTenantLocale::class])
        ->group(function (): void {
            Route::post('/gate-probe', fn () => response('written'))->middleware(RequiresActiveSubscription::NAME);
        });
});

/**
 * luna's default language set to the one given: the probe is a request nobody is signed in on, so
 * it reads the tenant default.
 */
function gateIn(SiteLanguage $language): void
{
    DB::table('tenants')->where('slug', 'luna')->update(['locale' => $language->value]);
    DB::table('tenant_luna.settings')->update(['language' => null]);
}

/**
 * A gate's line read through the translator in the language given, and for Greek asserted unlike
 * its English, so a Greek case cannot pass on the fallback.
 *
 * @param  array<string, string>  $replace
 */
function gateLine(string $key, array $replace, SiteLanguage $language): string
{
    $line = adminLine($key, $replace, $language);

    if ($language === SiteLanguage::Greek) {
        expect($line)->not->toBe(adminLine($key, $replace), $key);
    }

    return $line;
}

/**
 * The day a window opened at the UTC instant given ends on in Athens, as ClosingWindow::dayOf
 * spells it in the language given: the month and the weekday are ICU's, never typed.
 */
function gateDay(string $closingAtUtc, SiteLanguage $language): string
{
    return ClosingWindow::dayOf(new DateTimeImmutable($closingAtUtc, new DateTimeZone('UTC')), new DateTimeZone('Europe/Athens'), $language);
}

dataset('gate languages', [
    'the tenant default en' => [SiteLanguage::English],
    'the tenant default el' => [SiteLanguage::Greek],
]);

it('lets a write through while the subscription is active or in grace', function (string $state) {
    DB::table('tenants')->where('slug', 'luna')->update(['subscription_state' => $state, 'grace_ends_at' => $state === 'grace' ? '2026-10-01 09:00:00+00' : null]);

    $this->withoutMiddleware(PreventRequestForgery::class)
        ->post(tenantUrl('luna', '/gate-probe'))->assertOk()->assertSee('written');
})->with(['active', 'grace']);

it('refuses a write with the subscription_required envelope naming the state in every other state, in the request\'s language', function (string $state, SiteLanguage $language) {
    gateIn($language);
    DB::table('tenants')->where('slug', 'luna')->update(['subscription_state' => $state]);

    $this->withoutMiddleware(PreventRequestForgery::class)
        ->post(tenantUrl('luna', '/gate-probe'))
        ->assertForbidden()
        ->assertJsonPath('code', 'subscription_required')
        ->assertJsonPath('message', 'subscription_required')
        ->assertJsonPath('data.status', 403)
        ->assertJsonPath('data.state', $state)
        ->assertJsonPath('data.detail', gateLine(RequiresActiveSubscription::DETAIL, [], $language));
})->with(['past_due', 'canceled', 'incomplete'])->with('gate languages');

it('refuses a closing tenant\'s write by its status whatever its state, the account_closing envelope naming the day the window ends in the tenant\'s clock, the way back and the instant it ends, in the request\'s language', function (string $state, ?string $trialEndsAt, SiteLanguage $language) {
    gateIn($language);
    // Closed at 22:30 in UTC, half an hour past midnight in Athens, so the window ends on the
    // Thursday there while UTC still reads the Wednesday.
    expect(gateDay('2026-11-02 22:30', SiteLanguage::English))->toBe('Thursday 3 December 2026');
    DB::table('tenants')->where('slug', 'luna')->update(['subscription_state' => $state, 'trial_ends_at' => $trialEndsAt, 'status' => 'closing', 'closing_at' => '2026-11-02 22:30:00+00']);
    DB::table('subscriptions')->insert(['tenant_id' => DB::table('tenants')->where('slug', 'luna')->value('id'), 'provider_customer_id' => 'cus_luna']);

    $this->withoutMiddleware(PreventRequestForgery::class)
        ->post(tenantUrl('luna', '/gate-probe'))
        ->assertForbidden()
        ->assertExactJson(['code' => 'account_closing', 'message' => 'account_closing', 'data' => [
            'status' => 403,
            'detail' => gateLine(ClosingWindow::FROZEN, ['day' => gateDay('2026-11-02 22:30', $language)], $language).' '.gateLine(ClosingWindow::WAY_BACK, [], $language),
            'closes_at' => '2026-12-02T22:30:00+00:00',
        ]]);
})->with([
    'active, closed by its owner with the subscription still active' => ['active', null],
    'in a trial' => ['trial', '2026-11-05 09:00:00+00'],
    'canceled' => ['canceled', null],
])->with('gate languages');

it('names support as a free tenant\'s way back while closing, its Billing screen offering no plan, in the request\'s language', function (SiteLanguage $language) {
    gateIn($language);
    DB::table('tenants')->where('slug', 'luna')->update(['status' => 'closing', 'closing_at' => '2026-11-02 06:00:00+00']);
    $support = config('site.contact_address');

    assert(is_string($support));

    $this->withoutMiddleware(PreventRequestForgery::class)
        ->post(tenantUrl('luna', '/gate-probe'))
        ->assertForbidden()
        ->assertJsonPath('code', 'account_closing')
        ->assertJsonPath('data.detail', gateLine(ClosingWindow::FROZEN, ['day' => gateDay('2026-11-02 06:00', $language)], $language).' '.gateLine(ClosingWindow::WAY_BACK_FREE, ['address' => $support], $language));
})->with('gate languages');
