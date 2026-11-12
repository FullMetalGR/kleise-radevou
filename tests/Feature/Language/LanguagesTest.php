<?php

use App\Application\Notification\Recipient;
use App\Http\Middleware\SetTenantLocale;
use App\Infrastructure\Db\SettingsReader;
use App\Site\SiteLanguage;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Languages;
use App\Tenancy\Tenant;
use App\Tenancy\TenantStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

pest()->use(RefreshDatabase::class);

/*
 * The resolver of the four languages, on a provisioned tenant's real rows: the tenant default
 * from settings.language else tenants.locale, a tenant with no schema answering its row's locale;
 * the request's language from a page's prefix, the API's lang, else the default; the user's
 * language their users.locale, else the default; the language of a platform mail to a user, the
 * recipient's language; the booking's from its column.
 */

beforeEach(function () {
    provisionTenant('acme');
});

/**
 * The resolver as the container would build it.
 */
function languageResolver(): Languages
{
    return new Languages(app(SettingsReader::class));
}

/**
 * acme's row, its locale and its chosen language set as given, read back fresh.
 */
function languageTenant(SiteLanguage $locale, ?SiteLanguage $chosen): Tenant
{
    DB::table('tenants')->where('slug', 'acme')->update(['locale' => $locale->value]);
    DB::table('tenant_acme.settings')->where('id', 1)->update(['language' => $chosen?->value]);

    return Tenant::query()->where('slug', 'acme')->sole();
}

/**
 * A request to acme's host at the path given, by the method given, carrying the query given.
 *
 * @param  array<mixed>  $query
 */
function languageRequest(string $path, array $query = [], string $method = 'GET'): Request
{
    return Request::create(tenantUrl('acme', $path).($query === [] ? '' : '?'.http_build_query($query)), $method);
}

it('reads the tenant default from settings.language where the tenant chose one, else from tenants.locale, and the language of a user who chose none as the default', function (SiteLanguage $locale, ?SiteLanguage $chosen, SiteLanguage $default) {
    $tenant = languageTenant($locale, $chosen);
    $resolver = languageResolver();

    expect($resolver->tenantDefault($tenant))->toBe($default)
        ->and($resolver->ofUser($tenant, null))->toBe($default);
})->with([
    'an English signup, no choice' => [SiteLanguage::English, null, SiteLanguage::English],
    'a Greek signup, no choice' => [SiteLanguage::Greek, null, SiteLanguage::Greek],
    'an English signup that chose Greek' => [SiteLanguage::English, SiteLanguage::Greek, SiteLanguage::Greek],
    'a Greek signup that chose English' => [SiteLanguage::Greek, SiteLanguage::English, SiteLanguage::English],
]);

it('reads a user\'s own choice, users.locale, over the tenant default, whatever the default is', function (SiteLanguage $locale, ?SiteLanguage $default, SiteLanguage $user) {
    $tenant = languageTenant($locale, $default);

    expect(languageResolver()->ofUser($tenant, $user))->toBe($user);
})->with([
    'a Greek user at an English tenant' => [SiteLanguage::English, null, SiteLanguage::Greek],
    'an English user at a Greek signup' => [SiteLanguage::Greek, null, SiteLanguage::English],
    'a Greek user at a tenant that chose English' => [SiteLanguage::Greek, SiteLanguage::English, SiteLanguage::Greek],
]);

it('writes a platform mail in its recipient\'s language: users.locale, else the tenant default', function (SiteLanguage $locale, ?SiteLanguage $default, ?SiteLanguage $user, SiteLanguage $language) {
    $tenant = languageTenant($locale, $default);

    expect(languageResolver()->ofPlatformMail($tenant, $user))->toBe($language);
})->with([
    'a Greek user at an English row' => [SiteLanguage::English, null, SiteLanguage::Greek, SiteLanguage::Greek],
    'an English user at a Greek row' => [SiteLanguage::Greek, null, SiteLanguage::English, SiteLanguage::English],
    'no choice at a Greek row' => [SiteLanguage::Greek, null, null, SiteLanguage::Greek],
    'no choice at an English row' => [SiteLanguage::English, null, null, SiteLanguage::English],
    'no choice at an English row whose settings chose Greek' => [SiteLanguage::English, SiteLanguage::Greek, null, SiteLanguage::Greek],
]);

it('answers a tenant with no schema to read by its row\'s locale, a signup still provisioning and a closed tenant alike, reading no settings row', function (TenantStatus $status) {
    $tenant = Tenant::factory()->create();
    $closed = $status === TenantStatus::Closed;
    DB::table('tenants')->where('id', $tenant->id)->update(['status' => $status->value, 'locale' => 'el', 'slug' => $closed ? null : $tenant->slug, 'closed_at' => $closed ? DB::raw('now()') : null, 'closing_at' => $closed ? DB::raw('now()') : null]);
    $row = Tenant::query()->whereKey($tenant->id)->sole();

    DB::enableQueryLog();
    $default = languageResolver()->tenantDefault($row);

    expect($default)->toBe(SiteLanguage::Greek)
        ->and(DB::getQueryLog())->toBe([]);
})->with([
    'provisioning' => [TenantStatus::Provisioning],
    'closed' => [TenantStatus::Closed],
]);

it('answers the request\'s language by the prefix of a page\'s path, and a bare path by the tenant default', function (string $path, SiteLanguage $language) {
    $tenant = languageTenant(SiteLanguage::Greek, null);

    expect(languageResolver()->ofRequest(languageRequest($path), $tenant))->toBe($language);
})->with([
    'the Greek home' => ['/el', SiteLanguage::Greek],
    'a Greek token path' => ['/el/my-bookings/'.str_repeat('a', 64), SiteLanguage::Greek],
    'the English prefix' => ['/en/manage/x', SiteLanguage::English],
    'the English prefix over a Greek default' => ['/en', SiteLanguage::English],
    'the Greek prefix' => ['/el/pay/x', SiteLanguage::Greek],
    'a bare page in the Greek default' => ['/', SiteLanguage::Greek],
    'the admin in the Greek default' => ['/admin/settings', SiteLanguage::Greek],
    'a path that only starts with the letters' => ['/else', SiteLanguage::Greek],
]);

it('answers the API\'s request by its lang query parameter, a value naming neither language or no value as absent, a GET and a POST alike', function (string $method, array $query, SiteLanguage $language) {
    $tenant = languageTenant(SiteLanguage::English, null);

    expect(languageResolver()->ofRequest(languageRequest('/api/v1/holds', $query, $method), $tenant))->toBe($language);
})->with([
    'a GET in Greek' => ['GET', ['lang' => 'el'], SiteLanguage::Greek],
    'a POST in Greek' => ['POST', ['lang' => 'el'], SiteLanguage::Greek],
    'a GET in English' => ['GET', ['lang' => 'en'], SiteLanguage::English],
    'no lang' => ['GET', [], SiteLanguage::English],
    'a language neither' => ['GET', ['lang' => 'fr'], SiteLanguage::English],
    'a region subtag, which the embed strips' => ['GET', ['lang' => 'el-GR'], SiteLanguage::English],
    'an upper-cased code' => ['GET', ['lang' => 'EL'], SiteLanguage::English],
    'a list' => ['GET', ['lang' => ['el']], SiteLanguage::English],
]);

it('reads the API\'s lang from the query string alone, never from a body', function () {
    $tenant = languageTenant(SiteLanguage::English, null);
    $request = Request::create(tenantUrl('acme', '/api/v1/holds'), 'POST', ['lang' => 'el']);

    expect(languageResolver()->ofRequest($request, $tenant))->toBe(SiteLanguage::English);
});

it('reads the API\'s lang over a Greek default, and the default where none is asked', function () {
    $tenant = languageTenant(SiteLanguage::Greek, SiteLanguage::Greek);

    expect(languageResolver()->ofRequest(languageRequest('/api/v1/config', ['lang' => 'en']), $tenant))->toBe(SiteLanguage::English)
        ->and(languageResolver()->ofRequest(languageRequest('/api/v1/config'), $tenant))->toBe(SiteLanguage::Greek);
});

it('answers the booking\'s language from its column, en or el', function (string $stored, SiteLanguage $language) {
    expect(languageResolver()->ofBooking($stored))->toBe($language);
})->with([
    'English' => ['en', SiteLanguage::English],
    'Greek' => ['el', SiteLanguage::Greek],
]);

it('answers a business message\'s language from the code its recipient carries, en for a code that is neither, the key unread', function (string $code, SiteLanguage $language) {
    expect(Languages::ofRecipient(new Recipient('owner@luna.example', 4, true, $code)))->toBe($language)
        ->and(Languages::ofRecipient(new Recipient('chair@luna.example')))->toBe(SiteLanguage::English);
})->with([
    'English' => ['en', SiteLanguage::English],
    'Greek' => ['el', SiteLanguage::Greek],
    'neither' => ['fr', SiteLanguage::English],
]);

it('is the resolver the container builds', function () {
    $tenant = languageTenant(SiteLanguage::Greek, SiteLanguage::Greek);

    expect(app(Languages::class)->tenantDefault($tenant))->toBe(SiteLanguage::Greek);
});

it('sets the application locale to the request\'s language for the length of a tenant request and puts it back after, a throw included', function () {
    $tenant = languageTenant(SiteLanguage::Greek, null);
    $middleware = app(SetTenantLocale::class);
    app(CurrentTenant::class)->bind($tenant);
    $seen = [];

    try {
        foreach (['/manage/x' => [], '/en/manage/x' => [], '/api/v1/services' => ['lang' => 'en'], '/api/v1/config' => []] as $path => $query) {
            $middleware->handle(languageRequest($path, $query), function () use (&$seen, $path): Response {
                $seen[$path] = app()->getLocale();

                return new Response;
            });

            expect(app()->getLocale())->toBe('en');
        }

        expect(fn () => $middleware->handle(languageRequest('/el'), fn (): Response => throw new RuntimeException('a step that throws')))->toThrow(RuntimeException::class)
            ->and(app()->getLocale())->toBe('en');
    } finally {
        app(CurrentTenant::class)->release();
    }

    expect($seen)->toBe(['/manage/x' => 'el', '/en/manage/x' => 'en', '/api/v1/services' => 'en', '/api/v1/config' => 'el']);
});
