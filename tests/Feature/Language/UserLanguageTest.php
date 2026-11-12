<?php

use App\Application\Clock;
use App\Application\Notification\PlatformMessenger;
use App\Billing\Entitlements;
use App\Models\User;
use App\Platform\FreeTenants;
use App\Platform\NewFreeTenant;
use App\Site\SiteLanguage;
use App\Tenancy\Signup;
use App\Tenancy\Tenant;
use App\Tenancy\TenantProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\FixedClock;
use Tests\Support\RecordingPlatformMessages;

pest()->use(RefreshDatabase::class);

/*
 * The user's language: users.locale, null on every new user whoever writes the row, so a new
 * user reads the tenant default and follows it until they choose; nothing is copied from the
 * tenant's row, a Greek signup's owner included. The clock is pinned for the demo's reset,
 * which dates its week from it.
 */

beforeEach(function () {
    app()->instance(Clock::class, FixedClock::at('2026-11-03 09:00', 'Europe/Athens'));
    app()->instance(PlatformMessenger::class, new RecordingPlatformMessages);
});

/**
 * The users.locale of every user of the tenant, by address.
 *
 * @return array<string, string|null>
 */
function userLocales(string $slug): array
{
    $tenant = Tenant::query()->where('slug', $slug)->sole();

    /** @var array<string, string|null> */
    return DB::table('users')->where('tenant_id', $tenant->id)->orderBy('id')->pluck('locale', 'email')->all();
}

it('leaves a new user\'s language null whichever writer made the row, a Greek tenant\'s included: {case}', function (Closure $write, string $slug, ?string $address) {
    $write();
    $locales = userLocales($slug);

    expect($locales)->not->toBeEmpty()
        ->and(array_unique(array_values($locales)))->toBe([null]);

    if ($address !== null) {
        expect($locales)->toHaveKey($address);
    }
})->with([
    'the signup\'s reservation of a Greek signup' => [fn () => app(TenantProvisioner::class)->reserve(new Signup('acme', 'Acme Salon', new DateTimeZone('Europe/Athens'), 'EUR', 'Alex Owner', 'owner@acme.example', 'correct horse battery staple', locale: SiteLanguage::Greek)), 'acme', 'owner@acme.example'],
    'the provisioner, tenant:provision\'s path' => [fn () => provisionTenant('acme'), 'acme', 'owner@example.com'],
    'tenant:provision' => [fn () => Artisan::call('tenant:provision', ['slug' => 'acme', '--name' => 'Acme Salon', '--timezone' => 'Europe/Athens', '--currency' => 'EUR', '--owner-name' => 'Alex Owner', '--owner-email' => 'owner@acme.example', '--owner-password' => 'correct horse battery staple']), 'acme', 'owner@acme.example'],
    'the console\'s free tenant in Greek' => [fn () => app(FreeTenants::class)->create(new NewFreeTenant('acme', 'Acme Barbers', new DateTimeZone('Europe/Athens'), 'EUR', null, 'Alex Owner', 'alex@acme.example', Entitlements::none(), SiteLanguage::Greek), addOperator()), 'acme', 'alex@acme.example'],
    'the demo\'s reset, a Greek tenant' => [fn () => Artisan::call('demo:reset'), 'demo', null],
    'tenant:add-user' => [function () {
        provisionTenant('acme');
        Artisan::call('tenant:add-user', ['slug' => 'acme', '--name' => 'Sam Staff', '--email' => 'sam@acme.example', '--password' => 'correct horse battery staple', '--role' => 'staff']);
    }, 'acme', 'sam@acme.example'],
]);

it('reads a user\'s language as the model\'s cast, a language or null', function (?string $stored, ?SiteLanguage $read) {
    provisionTenant('acme');
    DB::table('users')->update(['locale' => $stored]);

    expect(User::query()->sole()->locale)->toBe($read);
})->with([
    'English' => ['en', SiteLanguage::English],
    'Greek' => ['el', SiteLanguage::Greek],
    'no choice' => [null, null],
]);
