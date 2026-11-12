<?php

use App\Application\Clock;
use App\Http\AdminSession;
use App\Infrastructure\Db\SqlText;
use App\Models\User;
use App\Site\SiteLanguage;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FixedClock;

pest()->use(RefreshDatabase::class);

/*
 * The admin in the signed-in user's language: users.locale, else the tenant default. A page with
 * nobody signed in, the door and the set-password page, and a support session, which has no user,
 * read the tenant default. The language rides in the block every admin page carries, the admin's
 * root view writes it as its lang, and on every route under /admin the application's locale is it
 * for the length of the request, read here where the root view renders. The top bar's control:
 * its two labels, each language's own name read from site.language as the site's switch reads it,
 * are shared to a signed-in user, and its write, PUT /admin/language/{language}, signed-in,
 * answers 404 for a support session.
 *
 * The clock is pinned, so a support session's end is the one spelled here.
 */

beforeEach(function () {
    $this->withoutVite();
    app()->instance(Clock::class, FixedClock::at('2026-11-03 09:00'));
    provisionTenant('luna', ['name' => 'Luna Hair Studio', 'ownerEmail' => 'owner@luna.example'], ['staff']);
});

/**
 * Luna's row in the language given, its settings choosing the default given, and the owner's
 * users.locale the choice given.
 */
function adminLanguageAt(SiteLanguage $locale, ?SiteLanguage $default = null, ?SiteLanguage $owner = null): User
{
    DB::table('tenants')->where('slug', 'luna')->update(['locale' => $locale->value]);
    DB::table('tenant_luna.settings')->where('id', 1)->update(['language' => $default?->value]);
    DB::table('users')->where('email', 'owner@luna.example')->update(['locale' => $owner?->value]);

    return User::query()->where('email', 'owner@luna.example')->sole();
}

/**
 * The application's locale at the moment the admin's root view renders, which is inside the
 * request and so under the locale the tenant group's middleware set; null until it renders.
 */
function adminLocaleAtRender(): Closure
{
    $seen = null;
    View::composer('admin.app', function () use (&$seen): void {
        $seen = app()->getLocale();
    });

    return function () use (&$seen): ?string {
        return $seen;
    };
}

/**
 * The lang of the page's root element as the response wrote it, null where it wrote none.
 *
 * @param  TestResponse<Response>  $response
 */
function adminRootLang(TestResponse $response): ?string
{
    return preg_match('/<html lang="([a-z]+)">/', (string) $response->getContent(), $found) === 1 ? $found[1] : null;
}

/**
 * A support session of luna open at the clock's now for sixty minutes, its operator a fresh one, as
 * the open leaves it; answers the row's id, for the session key.
 */
function adminSupportSession(): int
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

/**
 * The top bar control's labels as the server reads them: each language's own name, site.language
 * in that language, read through the translator as the site's switch reads it.
 *
 * @return list<array{value: string, name: string}>
 */
function adminLanguageLabels(): array
{
    return array_map(fn (SiteLanguage $language): array => ['value' => $language->value, 'name' => __('site.language', [], $language->value)], SiteLanguage::cases());
}

it('answers each user of one tenant in their own language, a user in Greek and a colleague who chose none beside them on the same page', function () {
    $owner = adminLanguageAt(SiteLanguage::English, owner: SiteLanguage::Greek);
    addTenantUser('manager@luna.example', 'manager');
    $colleague = User::query()->where('email', 'manager@luna.example')->sole();
    $locale = adminLocaleAtRender();

    $greek = $this->actingAs($owner)->get(tenantUrl('luna', '/admin/bookings'));
    $greek->assertOk()->assertInertia(fn (Assert $page) => $page->component('Bookings')->where('language', 'el'));

    expect(adminRootLang($greek))->toBe('el')
        ->and($locale())->toBe('el');

    freshGuards();
    $english = $this->actingAs($colleague)->get(tenantUrl('luna', '/admin/bookings'));
    $english->assertOk()->assertInertia(fn (Assert $page) => $page->component('Bookings')->where('language', 'en'));

    expect(adminRootLang($english))->toBe('en')
        ->and($locale())->toBe('en');
});

it('answers a user who chose none in the tenant default, the settings\' choice over the row\'s locale: {case}', function (SiteLanguage $locale, ?SiteLanguage $default, string $language) {
    $owner = adminLanguageAt($locale, $default);

    $page = $this->actingAs($owner)->get(tenantUrl('luna', '/admin'));
    $page->assertOk()->assertInertia(fn (Assert $page) => $page->where('language', $language));

    expect(adminRootLang($page))->toBe($language);
})->with([
    'a Greek signup' => [SiteLanguage::Greek, null, 'el'],
    'an English signup that chose Greek in Settings' => [SiteLanguage::English, SiteLanguage::Greek, 'el'],
    'a Greek signup that chose English in Settings' => [SiteLanguage::Greek, SiteLanguage::English, 'en'],
]);

it('answers a page with nobody signed in in the tenant default, the door and the set-password page: {case}', function (string $path, string $component) {
    adminLanguageAt(SiteLanguage::Greek, owner: SiteLanguage::English);
    DB::table('users')->where('email', 'owner@luna.example')->update(['password_link_hash' => hash('sha256', str_repeat('b', 64)), 'password_link_expires_at' => SqlText::instant(app(Clock::class)->now()->add(new DateInterval('PT72H')))]);
    $locale = adminLocaleAtRender();

    $page = $this->get(tenantUrl('luna', $path));
    $page->assertOk()->assertInertia(fn (Assert $page) => $page->component($component)->where('language', 'el')->where('auth.user', null)->missing('languages'));

    expect(adminRootLang($page))->toBe('el')
        ->and($locale())->toBe('el');
})->with([
    'the door' => ['/admin/login', 'auth/Login'],
    'the set-password page' => ['/admin/set-password/'.str_repeat('b', 64), 'auth/SetPassword'],
]);

it('answers a support session in the tenant default, since no user stands behind it, and offers it no language control', function () {
    adminLanguageAt(SiteLanguage::Greek, owner: SiteLanguage::English);
    $locale = adminLocaleAtRender();

    $page = $this->withSession([AdminSession::SUPPORT_KEY => adminSupportSession()])->get(tenantUrl('luna', '/admin'));
    $page->assertOk()->assertInertia(fn (Assert $page) => $page->where('auth.user.role', 'support')->where('language', 'el')->missing('languages'));

    expect(adminRootLang($page))->toBe('el')
        ->and($locale())->toBe('el');
});

it('shares the top bar control\'s labels to a signed-in user, each language named in its own language, read as the site\'s switch reads it', function () {
    $owner = adminLanguageAt(SiteLanguage::English);

    $this->actingAs($owner)->get(tenantUrl('luna', '/admin'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('languages', adminLanguageLabels()));

    expect(array_column(adminLanguageLabels(), 'name'))->toBe([siteSentence('site.language', SiteLanguage::English), siteSentence('site.language', SiteLanguage::Greek)])
        ->and(siteSentence('site.language', SiteLanguage::Greek))->not->toBe(siteSentence('site.language', SiteLanguage::English));
});

it('writes the signed-in user\'s language for them alone and sends them back to the page they chose it on, the next page in it', function (string $language) {
    $owner = adminLanguageAt(SiteLanguage::English);
    addTenantUser('manager@luna.example', 'manager');

    $this->actingAs($owner)->from(tenantUrl('luna', '/admin/bookings?page=2'))
        ->put(tenantUrl('luna', '/admin/language/'.$language))
        ->assertRedirect(tenantUrl('luna', '/admin/bookings?page=2'))
        ->assertSessionMissing('flash');

    expect(DB::table('users')->where('tenant_id', $owner->tenant_id)->orderBy('id')->pluck('locale', 'email')->all())
        ->toBe(['owner@luna.example' => $language, 'manager@luna.example' => null]);

    freshGuards();
    $next = $this->actingAs(User::query()->whereKey($owner->id)->sole())->get(tenantUrl('luna', '/admin/bookings'));
    $next->assertOk()->assertInertia(fn (Assert $page) => $page->where('language', $language));

    expect(adminRootLang($next))->toBe($language);
})->with(['el', 'en']);

it('writes a user\'s language in any subscription state and on any plan, a person\'s preference and no configuration of the business', function (string $state) {
    $owner = adminLanguageAt(SiteLanguage::English);
    DB::table('tenants')->where('slug', 'luna')->update(['subscription_state' => $state, 'entitlements' => '{}']);

    $this->actingAs($owner)->from(tenantUrl('luna', '/admin'))
        ->put(tenantUrl('luna', '/admin/language/el'))
        ->assertRedirect(tenantUrl('luna', '/admin'));

    expect(DB::table('users')->where('id', $owner->id)->value('locale'))->toBe('el');
})->with(['past_due', 'canceled']);

it('refuses the write 404 to a support session, which has no user row to write, writing nothing', function () {
    adminLanguageAt(SiteLanguage::English);

    $this->withSession([AdminSession::SUPPORT_KEY => adminSupportSession()])
        ->put(tenantUrl('luna', '/admin/language/el'))
        ->assertNotFound();

    expect(DB::table('users')->pluck('locale')->unique()->all())->toBe([null]);
});

it('sends a visitor who is not signed in to the door, the write declared signed-in', function () {
    $this->put(tenantUrl('luna', '/admin/language/el'))->assertRedirect(tenantUrl('luna', '/admin/login'));

    expect(DB::table('users')->pluck('locale')->unique()->all())->toBe([null]);
});

it('routes no language but the two, a path naming another answered as any verb the admin does not take there, writing nothing', function (string $language) {
    $owner = adminLanguageAt(SiteLanguage::English);

    $this->actingAs($owner)->put(tenantUrl('luna', '/admin/language/'.$language))->assertStatus(405);

    expect(DB::table('users')->where('id', $owner->id)->value('locale'))->toBeNull();
})->with(['fr', 'EL', 'el-GR']);

it('keeps the console\'s root view in English whatever the language', function () {
    expect(adminRootLang($this->get(consoleUrl('/login'))->assertOk()))->toBe('en');
});

/*
 * The admin's words in the request's language: the strings prop carries the shell's section of
 * lang/{en,el}/admin read in the language every page is answered in, the rail's labels and every
 * sentence the server words are read in it. The shell's Greek is lang/el/admin/shell.php; the
 * cases about which language answers lay a stand-in over a key for themselves alone (greekLines),
 * so the line they read names its key.
 */

it('carries the shell\'s strings in the user\'s language: language el, the root view\'s lang el, and the shell and the rail read in Greek', function () {
    $owner = adminLanguageAt(SiteLanguage::English, owner: SiteLanguage::Greek);

    $page = $this->actingAs($owner)->get(tenantUrl('luna', '/admin'));
    $page->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('language', 'el')
        ->where('strings.shell', __('admin/shell', [], 'el'))
        ->where('nav.1.label', navLabel('calendar', SiteLanguage::Greek)));

    expect(adminRootLang($page))->toBe('el')
        ->and(__('admin/shell', [], 'el'))->not->toBe(__('admin/shell', [], 'en'))
        ->and(navLabel('calendar', SiteLanguage::Greek))->not->toBe(navLabel('calendar'));
});

it('reads the shell, the rail and the server\'s words in Greek where a Greek line stands, ', function () {
    $owner = adminLanguageAt(SiteLanguage::English, owner: SiteLanguage::Greek);
    greekLines([
        'admin/shell.nav.calendar' => 'calendar',
        'admin/shell.nav.groups.schedule' => 'schedule',
        'admin/shell.top_bar.sign_out' => 'sign out',
        'admin/shell.flashes.signed_out' => 'signed out',
    ]);

    $this->actingAs($owner)->get(tenantUrl('luna', '/admin'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('language', 'el')
            ->where('strings.shell.top_bar.sign_out', 'GREEK sign out')
            ->where('nav.1', ['label' => 'GREEK calendar', 'url' => '/admin/calendar', 'group' => 'GREEK schedule', 'pending' => null, 'current' => false]));

    $this->post(tenantUrl('luna', '/admin/logout'))
        ->assertSessionHas('flash', ['kind' => 'success', 'message' => 'GREEK signed out']);
});

it('reads support\'s name and the shell in the tenant default, since no user stands behind a support session', function () {
    adminLanguageAt(SiteLanguage::Greek, owner: SiteLanguage::English);
    greekLines(['admin/shell.shared.support.named' => 'support :operator', 'admin/shell.nav.calendar' => 'calendar']);

    $this->withSession([AdminSession::SUPPORT_KEY => adminSupportSession()])->get(tenantUrl('luna', '/admin'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('language', 'el')
            ->where('auth.user.name', fn (string $name): bool => str_starts_with($name, 'GREEK support '))
            ->where('strings.shell.nav.calendar', 'GREEK calendar')
            ->where('nav.1.label', 'GREEK calendar'));
});

it('answers the door past its limit with the refusal, the language and the shell\'s strings in the tenant default', function () {
    adminLanguageAt(SiteLanguage::Greek);
    greekLines(['admin/shell.refusals.too_many' => 'too many', 'admin/shell.login.submit' => 'sign in']);

    // The sign-in limit's ten a minute spent from the test's address, as ten refused attempts
    // would spend it, keyed as the framework keys a named limiter.
    foreach (range(1, 10) as $attempt) {
        RateLimiter::hit(md5('login127.0.0.1'), 60);
    }

    $answer = $this->post(tenantUrl('luna', '/admin/login'), ['email' => 'owner@luna.example', 'password' => 'wrong']);
    $answer->assertStatus(429)->assertInertia(fn (Assert $page) => $page
        ->component('auth/Login')
        ->where('refusal', 'GREEK too many')
        ->where('language', 'el')
        ->where('strings.shell.login.submit', 'GREEK sign in'));

    expect(adminRootLang($answer))->toBe('el');
});

it('writes the root view of the door past its limit in the tenant default Settings names over the row\'s language, the page and the document alike: {case}', function (SiteLanguage $locale, SiteLanguage $default) {
    adminLanguageAt($locale, default: $default);

    foreach (range(1, 10) as $attempt) {
        RateLimiter::hit(md5('login127.0.0.1'), 60);
    }

    $answer = $this->post(tenantUrl('luna', '/admin/login'), ['email' => 'owner@luna.example', 'password' => 'wrong']);
    $answer->assertStatus(429)->assertInertia(fn (Assert $page) => $page
        ->where('language', $default->value)
        ->where('refusal', adminLine('admin/shell.refusals.too_many', [], $default)));

    expect(adminRootLang($answer))->toBe($default->value);
})->with([
    'a Greek row whose default is English' => [SiteLanguage::Greek, SiteLanguage::English],
    'an English row whose default is Greek' => [SiteLanguage::English, SiteLanguage::Greek],
]);

it('words the door\'s four framework lines as the form\'s own, in the tenant default', function () {
    adminLanguageAt(SiteLanguage::Greek);
    $keys = ['admin/shell.validation.login.email_required', 'admin/shell.validation.login.password_string'];

    $this->post(tenantUrl('luna', '/admin/login'), ['password' => ['not', 'text']])
        ->assertSessionHasErrors(['email' => adminLine($keys[0], [], SiteLanguage::Greek), 'password' => adminLine($keys[1], [], SiteLanguage::Greek)]);

    expect(array_map(fn (string $key): bool => adminLine($key, [], SiteLanguage::Greek) !== adminLine($key), $keys))->toBe([true, true]);
});
