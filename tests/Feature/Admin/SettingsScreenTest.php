<?php

use App\Application\Clock;
use App\Application\Notification\MessageKey;
use App\Billing\EntitlementResolver;
use App\Billing\Entitlements;
use App\Billing\Feature;
use App\Http\Controllers\Admin\BillingController;
use App\Http\Controllers\Admin\LogoController;
use App\Http\Controllers\Admin\PaymentsController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\CountryNames;
use App\Http\Middleware\RequiresActiveSubscription;
use App\Http\Requests\CoverRequest;
use App\Http\Requests\GalleryPhotoRequest;
use App\Http\Requests\LogoRequest;
use App\Http\Requests\SettingsRequest;
use App\Http\Requests\WeeklyWindows;
use App\Http\ServedImages;
use App\Infrastructure\Db\TextArray;
use App\Infrastructure\Db\WeeklyPattern;
use App\Infrastructure\Storage\TenantImages;
use App\Models\User;
use App\Site\SiteLanguage;
use App\Tenancy\Profile;
use App\Tenancy\Settings;
use App\Tenancy\Slug;
use App\Tenancy\Tenant;
use App\Tenancy\TenantUrls;
use App\Tenancy\Theme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FakeDisks;
use Tests\Support\FixedClock;
use Tests\Support\LunaFixture;
use Tests\Support\StartInterval;
use Tests\Support\TemporaryFiles;
use Tests\TestCase;

pest()->use(RefreshDatabase::class);

/*
 * The Settings screen and its writes, 3.2 and 4.4 under /admin: the page's props, the whole
 * patch to PUT /admin/settings and what it restates and what it never does, the form
 * request's sentences, the implicit member's week to PUT /admin/opening-hours under a route
 * naming no member and the public availability reading it on its very next read, the logo's
 * upload, its serving under the tenant's own origin, its refusals and its removal, and the
 * gate: the reads never, the writes always. None of these writes enters the transaction
 * runner, so the suite's transaction wraps each test; the seam reading a switched-off key is
 * proven in SettingsEmailsSeamTest, which cannot be wrapped.
 */

beforeEach(function () {
    $this->withoutVite();
    seedDemo();
});

function settingsOwner(): User
{
    return User::query()->where('email', 'owner@luna.example')->sole();
}

/**
 * @param  array<string, mixed>  $body
 * @return TestResponse<Response>
 */
function settingsWrite(TestCase $test, User $user, string $method, string $path, array $body = []): TestResponse
{
    return $test->actingAs($user)->from(tenantUrl('luna', '/admin/settings'))->json($method, tenantUrl('luna', '/admin'.$path), $body, ['X-Inertia' => 'true']);
}

/**
 * The profile as the screen's values carry it for a tenant that never wrote one: every field
 * null but the country, whose column defaults to GR.
 *
 * @return array<string, string|null>
 */
function emptyProfile(): array
{
    return [...array_fill_keys(Profile::FIELDS, null), 'country' => 'GR'];
}

/**
 * The whole patch as the page sends it, every field every time and never a null: every
 * profile field but the country is the empty string.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function wholePatch(array $overrides = []): array
{
    return [
        'currency' => 'EUR', 'theme' => 'violet', 'checkout_ttl_min' => 15, 'approval_ttl_hours' => 48, 'payment_ttl_hours' => 24, 'reminder_lead_hours' => 24, 'start_interval_min' => 15, 'emails_off' => [],
        ...array_fill_keys(Profile::FIELDS, ''), 'country' => 'GR',
        ...$overrides,
    ];
}

/**
 * The implicit member's pattern as Opening hours writes it: its windows as the wire spells them,
 * and its cadence, start and end.
 *
 * @return array{list<array{int, string, string}>, array{int, string, string|null}|null}
 */
function settingsImplicitWeek(): array
{
    $implicit = implicitStaffId('tenant_luna');
    $pattern = WeeklyPattern::stored(DB::connection(), 'tenant_luna.', [$implicit])[$implicit] ?? null;

    return [
        array_map(fn (array $window): array => [$window['weekday'], $window['start'], $window['end']], $pattern['windows'] ?? []),
        $pattern === null ? null : [$pattern['cadence_weeks'], $pattern['starts_on'], $pattern['ends_on']],
    ];
}

/**
 * The starts luna's public availability offers the implicit member for the haircut on one day,
 * through the default cache store as production reads it.
 *
 * @return list<string>
 */
function implicitStarts(TestCase $test, string $day): array
{
    app()->instance(Clock::class, FixedClock::at('2026-11-30 12:00:00'));
    $next = new DateTimeImmutable($day, new DateTimeZone('Europe/Athens'));
    $query = http_build_query([
        'items' => (string) json_encode([['service_id' => LunaFixture::id('services', 'Haircut'), 'staff_id' => implicitStaffId('tenant_luna')]]),
        'from' => $day,
        'to' => $next->add(new DateInterval('P1D'))->format('Y-m-d'),
    ]);
    $days = $test->get(tenantUrl('luna', '/api/v1/availability?'.$query))->assertOk()->json();
    assert(is_array($days));
    $starts = $days[$day] ?? [];
    assert(is_array($starts));

    return array_values(array_map(fn (mixed $start): string => is_string($start) ? $start : '', $starts));
}

/**
 * The background the hosted page's own stylesheet paints the header's Book now pill with, as
 * written: the pill carries the saved theme by reading the primary the stylesheet keys on the
 * body's data-theme, the header itself being white, so a pill rule that stopped naming the
 * property fails here and the browser walk's computed colour agrees.
 */
function bookNowBackgroundOf(DOMDocument $hosted): ?string
{
    $pill = (new DOMXPath($hosted))->query("//header[contains(@class, 'hosted-header')]/a[@class='hosted-book']");
    assert($pill !== false);
    expect($pill)->toHaveCount(1);
    $styles = $hosted->getElementsByTagName('style')->item(0)->textContent ?? '';
    preg_match('/\\.hosted-book\\s*\\{[^}]*?background:\\s*([^;]+);/', $styles, $found);

    return $found[1] ?? null;
}

/**
 * luna's owner reading in the language given, users.locale set to it: the Greek user and the
 * English colleague of a case that runs once per language.
 */
function settingsReader(SiteLanguage $language): User
{
    User::query()->where('email', 'owner@luna.example')->update(['locale' => $language->value]);

    return settingsOwner();
}

/**
 * The start interval's refusal at a granularity in the language named, the six intervals listed
 * by that language's list words, read through the translator.
 */
function settingsIntervalRefusalIn(int $granularity, SiteLanguage $language): string
{
    $intervals = explode(',', SettingsRequest::START_INTERVALS);
    $last = array_pop($intervals);
    $listed = adminLine(SettingsRequest::LIST_LAST, ['items' => implode(adminLine(SettingsRequest::LIST_SEPARATOR, [], $language), $intervals), 'last' => (string) $last], $language);

    return adminLine(SettingsRequest::START_INTERVAL, ['intervals' => $listed, 'n' => (string) $granularity], $language);
}

/**
 * The theme's refusal in the language named: Theme's cases counted in that language's words by
 * ICU and their names listed by its list words, read through the translator.
 */
function settingsThemeRefusalIn(SiteLanguage $language): string
{
    $names = array_map(fn (Theme $theme): string => adminLine('admin/settings.booking_page.themes.'.$theme->value, [], $language), Theme::cases());
    $last = array_pop($names);
    $listed = adminLine(SettingsRequest::LIST_LAST, ['items' => implode(adminLine(SettingsRequest::LIST_SEPARATOR, [], $language), $names), 'last' => (string) $last], $language);
    $count = (new NumberFormatter($language->value, NumberFormatter::SPELLOUT))->format(count(Theme::cases()));

    return adminLine(SettingsRequest::THEME, ['count' => (string) $count, 'themes' => $listed], $language);
}

/** A one-megabyte-and-more PNG: a real image whose reported size is over the limit. */
/**
 * A sentence of settings.php as the request's language reads it, through the translator.
 *
 * @param  array<string, string>  $values
 */
function settingsLine(string $key, array $values = []): string
{
    $line = __($key, $values);
    assert(is_string($line));

    return $line;
}

/**
 * The start interval's refusal at a granularity: the six intervals listed by the file's list words.
 */
function settingsIntervalRefusal(int $granularity): string
{
    $intervals = explode(',', SettingsRequest::START_INTERVALS);
    $last = array_pop($intervals);
    $listed = settingsLine(SettingsRequest::LIST_LAST, ['items' => implode(settingsLine(SettingsRequest::LIST_SEPARATOR), $intervals), 'last' => $last]);

    return settingsLine(SettingsRequest::START_INTERVAL, ['intervals' => $listed, 'n' => (string) $granularity]);
}

/**
 * The theme's refusal as Theme's cases make it: how many there are, spelled in words by ICU in
 * English, and each case's name from booking_page.themes listed by the file's list words, so a
 * theme added or removed changes the sentence with no copy edit.
 */
function settingsThemeRefusal(): string
{
    $names = array_map(fn (Theme $theme): string => settingsLine('admin/settings.booking_page.themes.'.$theme->value), Theme::cases());
    $last = array_pop($names);
    $listed = settingsLine(SettingsRequest::LIST_LAST, ['items' => implode(settingsLine(SettingsRequest::LIST_SEPARATOR), $names), 'last' => (string) $last]);
    $count = (new NumberFormatter('en', NumberFormatter::SPELLOUT))->format(count(Theme::cases()));

    return settingsLine(SettingsRequest::THEME, ['count' => (string) $count, 'themes' => $listed]);
}

function oversizedLogo(): UploadedFile
{
    return UploadedFile::fake()->image('big.png', 40, 40)->size(LogoRequest::MAX_KILOBYTES * 2);
}

/**
 * An SVG carrying a PNG's name and a PNG's declared type: a real upload rather than the fake,
 * because the fake reports its type from its name and production reads the bytes.
 */
function disguisedSvg(): UploadedFile
{
    $path = TemporaryFiles::make('kr-logo');
    file_put_contents($path, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

    return new UploadedFile($path, 'logo.png', 'image/png', null, true);
}

it('renders the values the form edits, no logo, the hosted URL, the snippet carrying the saved theme, the implicit week and the active roster', function () {
    $implicit = implicitStaffId('tenant_luna');
    $active = DB::table('tenant_luna.staff')->where('status', 'active')->orderBy('sort')->orderBy('id')->pluck('name')->all();

    $this->actingAs(settingsOwner())->get(tenantUrl('luna', '/admin/settings'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component(SettingsController::PAGE)
            ->where('values', ['currency' => 'EUR', 'theme' => 'violet', 'language' => 'en', 'checkout_ttl_min' => 15, 'approval_ttl_hours' => 48, 'payment_ttl_hours' => 24, 'reminder_lead_hours' => 24, 'start_interval_min' => 15, 'emails_off' => [], ...emptyProfile()])
            ->where('logo', null)
            ->where('cover', null)
            ->where('gallery', [])
            ->where('map', ['state' => 'none', 'image' => null])
            ->where('urls.hosted', hostedPage('luna'))
            ->where('urls.snippet', "<div class=\"kr-booking\" data-theme=\"violet\"></div>\n<script src=\"".tenantUrl('luna', '/embed.js').'" async></script>')
            ->has('week', 5)
            ->where('week.0', ['weekday' => 1, 'start' => '09:00', 'end' => '17:00'])
            ->has('roster', count($active))
            ->where('roster.0.id', $implicit)
            ->where('roster.0.name', 'Luna Hair Studio')
            ->has('roster.0.windows', 5)
            ->where('roster.0.windows.0', ['weekday' => 1, 'start' => '09:00', 'end' => '17:00'])
            ->where('nav.9', ['label' => navLabel('settings'), 'url' => '/admin/settings', 'group' => navLabel('groups.business'), 'pending' => null, 'current' => true]));

    expect($active)->toContain('Elena Papadaki');
});

it('carries the map\'s state, and its image on the images route where it is ready alone', function (string $state, bool $drawn) {
    settingsOf('luna')->update(['lat' => 37.9763295, 'lng' => 23.7323814, 'map_path' => 'tenants/luna/map-0123456789abcdef.png', 'map_state' => $state]);
    $image = ['name' => 'map-0123456789abcdef.png', 'url' => app(TenantUrls::class)->image(Slug::of('luna'), 'map-0123456789abcdef.png')];

    $this->actingAs(settingsOwner())->get(tenantUrl('luna', '/admin/settings'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('map', ['state' => $state, 'image' => $drawn ? $image : null]));
})->with([
    'ready' => ['ready', true],
    'pending, a last map standing' => ['pending', false],
    'failed, a last map standing' => ['failed', false],
    'none, a last map standing' => ['none', false],
]);

it('never carries a ready map with no image, which the screen\'s type forbids: a ready row naming no path reads as none', function () {
    settingsOf('luna')->update(['lat' => 37.9763295, 'lng' => 23.7323814, 'map_path' => null, 'map_state' => 'ready']);

    $this->actingAs(settingsOwner())->get(tenantUrl('luna', '/admin/settings'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('map', ['state' => 'none', 'image' => null]));
});

it('opens the read and the writes to a manager and refuses a staff user everywhere', function () {
    $manager = User::query()->findOrFail(addTenantUser('manager@luna.example', 'manager'));
    $this->actingAs($manager)->get(tenantUrl('luna', '/admin/settings'))->assertOk();
    settingsWrite($this, $manager, 'PUT', '/settings', wholePatch(['reminder_lead_hours' => 12, 'language' => 'el']))->assertRedirect(tenantUrl('luna', '/admin/settings'));
    expect(settingsOf('luna')->value('reminder_lead_hours'))->toBe(12)
        ->and(settingsOf('luna')->value('language'))->toBe('el');

    $staff = User::query()->findOrFail(addTenantUser('staff@luna.example', 'staff'));
    $this->actingAs($staff)->get(tenantUrl('luna', '/admin/settings'))->assertForbidden();
    foreach ([['PUT', '/settings', wholePatch(['language' => 'en'])], ['PUT', '/opening-hours', ['rules' => []]], ['POST', '/settings/logo', []], ['DELETE', '/settings/logo', []], ['POST', '/settings/cover', []], ['DELETE', '/settings/cover', []], ['POST', '/settings/gallery', []], ['DELETE', '/settings/gallery/gallery-0123456789abcdef.png', []]] as [$method, $path, $body]) {
        settingsWrite($this, $staff, $method, $path, $body)->assertForbidden();
    }
});

it('refuses every write on a past_due tenant with subscription_required while the read still renders', function () {
    DB::table('tenants')->where('slug', 'luna')->update(['subscription_state' => 'past_due']);
    $owner = settingsOwner();

    foreach ([['PUT', '/settings', wholePatch(['currency' => 'USD', 'language' => 'el'])], ['PUT', '/opening-hours', ['rules' => []]], ['POST', '/settings/logo', []], ['DELETE', '/settings/logo', []], ['POST', '/settings/cover', []], ['DELETE', '/settings/cover', []], ['POST', '/settings/gallery', []], ['DELETE', '/settings/gallery/gallery-0123456789abcdef.png', []]] as [$method, $path, $body]) {
        settingsWrite($this, $owner, $method, $path, $body)->assertForbidden()->assertJsonPath('code', 'subscription_required')->assertJsonPath('data.state', 'past_due')->assertJsonPath('data.detail', adminLine(RequiresActiveSubscription::DETAIL));
    }

    expect(DB::table('tenants')->where('slug', 'luna')->value('currency'))->toBe('EUR')
        ->and(settingsOf('luna')->value('language'))->toBeNull()
        ->and(settingsImplicitWeek()[0])->toHaveCount(5);
    $this->actingAs($owner)->get(tenantUrl('luna', '/admin/settings'))->assertOk()->assertInertia(fn (Assert $page) => $page->component(SettingsController::PAGE)->where('subscription.state', 'past_due'));
});

it('saves the whole patch: the currency to the tenant row, the rest to the settings row, a stored key it does not know kept, and the next read carrying it all back', function () {
    seamSwitchOff(['mystery_key']);
    $owner = settingsOwner();

    settingsWrite($this, $owner, 'PUT', '/settings', wholePatch(['currency' => 'USD', 'theme' => 'rose', 'checkout_ttl_min' => 20, 'approval_ttl_hours' => 72, 'payment_ttl_hours' => 36, 'reminder_lead_hours' => 0, 'start_interval_min' => 30, 'emails_off' => ['mystery_key', 'reminder']]))
        ->assertRedirect(tenantUrl('luna', '/admin/settings'))
        ->assertSessionHas('flash', ['kind' => 'success', 'message' => settingsLine(SettingsController::SAVED)]);

    expect(DB::table('tenants')->where('slug', 'luna')->value('currency'))->toBe('USD')
        ->and((array) settingsOf('luna')->selectRaw('theme, checkout_ttl_min, approval_ttl_hours, payment_ttl_hours, reminder_lead_hours, start_interval_min, emails_off::text as emails_off')->first())
        ->toBe(['theme' => 'rose', 'checkout_ttl_min' => 20, 'approval_ttl_hours' => 72, 'payment_ttl_hours' => 36, 'reminder_lead_hours' => 0, 'start_interval_min' => 30, 'emails_off' => '{mystery_key,reminder}']);

    $this->actingAs($owner)->get(tenantUrl('luna', '/admin/settings'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('values', ['currency' => 'USD', 'theme' => 'rose', 'language' => 'en', 'checkout_ttl_min' => 20, 'approval_ttl_hours' => 72, 'payment_ttl_hours' => 36, 'reminder_lead_hours' => 0, 'start_interval_min' => 30, 'emails_off' => ['mystery_key', 'reminder'], ...emptyProfile()])
            ->where('tenant.currency', 'USD')
            ->where('urls.snippet', fn (string $snippet): bool => str_starts_with($snippet, '<div class="kr-booking" data-theme="rose"></div>')));

    $hosted = documentOf($this->get(hostedPage('luna'))->assertOk());
    expect(mountOf($hosted)->getAttribute('data-theme'))->toBe('rose')
        ->and($hosted->getElementsByTagName('body')->item(0)?->getAttribute('data-theme'))->toBe('rose')
        ->and(mountOf($hosted)->hasAttribute('style'))->toBeFalse()
        ->and(bookNowBackgroundOf($hosted))->toBe('var(--kr-primary)');
});

it('saves the default language to settings.language, keeps it through a body naming none, refuses a third code, and the hosted page answers in it from the next request', function () {
    $owner = settingsOwner();
    expect(settingsOf('luna')->value('language'))->toBeNull();
    $this->actingAs($owner)->get(tenantUrl('luna', '/admin/settings'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('values.language', 'en')
            ->where('languages', [['value' => 'en', 'name' => __('site.language', [], 'en')], ['value' => 'el', 'name' => __('site.language', [], 'el')]]));

    settingsWrite($this, $owner, 'PUT', '/settings', wholePatch(['language' => 'el']))->assertRedirect(tenantUrl('luna', '/admin/settings'));
    expect(settingsOf('luna')->value('language'))->toBe('el')
        ->and(documentOf($this->get(hostedPage('luna'))->assertOk())->documentElement?->getAttribute('lang'))->toBe('el');
    $this->actingAs($owner)->get(tenantUrl('luna', '/admin/settings'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('values.language', 'el'));

    settingsWrite($this, $owner, 'PUT', '/settings', wholePatch(['reminder_lead_hours' => 6]))->assertRedirect(tenantUrl('luna', '/admin/settings'));
    expect(settingsOf('luna')->value('language'))->toBe('el');

    // The owner has no choice of their own, so the refusal reads in the tenant default, now Greek.
    settingsWrite($this, $owner, 'PUT', '/settings', wholePatch(['language' => 'de']))
        ->assertStatus(400)->assertJsonPath('code', 'invalid_request')->assertJsonPath('data.detail', adminLine(SettingsRequest::LANGUAGE, [], SiteLanguage::Greek));
    expect(settingsOf('luna')->value('language'))->toBe('el');

    settingsWrite($this, $owner, 'PUT', '/settings', wholePatch(['language' => 'en']))->assertRedirect(tenantUrl('luna', '/admin/settings'));
    expect(settingsOf('luna')->value('language'))->toBe('en')
        ->and(documentOf($this->get(hostedPage('luna'))->assertOk())->documentElement?->getAttribute('lang'))->toBe('en');
});

it('ignores the approved key in a save that lists it off while a working payment connection exists, keeps every other key, and stores the list as given once none works', function () {
    $owner = settingsOwner();
    $off = ['reminder', MessageKey::Approved->value, 'mystery_key'];
    connectPaymentFake();

    settingsWrite($this, $owner, 'PUT', '/settings', wholePatch(['emails_off' => $off]))
        ->assertRedirect(tenantUrl('luna', '/admin/settings'))
        ->assertSessionHas('flash', ['kind' => 'success', 'message' => settingsLine(SettingsController::SAVED)]);
    expect(settingsOf('luna')->selectRaw('emails_off::text as off')->value('off'))->toBe('{reminder,mystery_key}');

    paymentConnections()->setEnabled(false);
    settingsWrite($this, $owner, 'PUT', '/settings', wholePatch(['emails_off' => $off]))->assertRedirect(tenantUrl('luna', '/admin/settings'));
    expect(settingsOf('luna')->selectRaw('emails_off::text as off')->value('off'))->toBe('{reminder,approved,mystery_key}');
});

it('marks the approved message locked in the catalog the Emails section draws while a working payment connection exists and nothing locked without one, and the late payment alone as the owners\'', function () {
    $owner = settingsOwner();
    $flags = function () use ($owner): array {
        $messages = data_get($this->actingAs($owner)->get(tenantUrl('luna', '/admin/settings'))->assertOk()->viewData('page'), 'props.settings.messages');
        assert(is_array($messages));
        $catalog = collect($messages);

        return ['locked' => $catalog->where('locked', true)->pluck('key')->all(), 'owners' => $catalog->where('owners_alone', true)->pluck('key')->all(), 'shape' => $catalog->first()];
    };

    expect($flags())->toBe(['locked' => [], 'owners' => ['late_payment'], 'shape' => ['key' => 'acknowledged', 'label' => adminLine(MessageKey::Acknowledged->labelKey()), 'audience' => 'customer', 'owners_alone' => false, 'locked' => false]]);

    connectPaymentFake();
    seamSwitchOff([MessageKey::Approved->value]);

    expect($flags())->toMatchArray(['locked' => ['approved'], 'owners' => ['late_payment']]);
});

it('restates no service and no booking when the currency changes, while a new service inherits the new one', function () {
    $owner = settingsOwner();
    $haircut = LunaFixture::id('services', 'Haircut');
    $bookingId = DB::table('tenant_luna.bookings')->insertGetId(bookingRow());

    settingsWrite($this, $owner, 'PUT', '/settings', wholePatch(['currency' => 'USD']))->assertRedirect(tenantUrl('luna', '/admin/settings'));

    expect(DB::table('tenant_luna.services')->where('id', $haircut)->value('currency'))->toBe('EUR')
        ->and(DB::table('tenant_luna.bookings')->where('id', $bookingId)->value('currency'))->toBe('EUR');

    settingsWrite($this, $owner, 'POST', '/services', [
        'name' => 'Hot towel shave', 'description' => 'Shown on the booking page.', 'duration_min' => 30, 'price_minor' => 2500, 'currency' => 'USD', 'payment_mode' => 'onsite',
        'requires_approval' => false, 'approval_hold_hours' => null, 'on_approval_timeout' => 'expire', 'cancel_window_hours' => 24, 'reschedule_window_hours' => 24, 'lead_time_min' => 0, 'horizon_days' => 60,
    ])->assertRedirect();
    expect(DB::table('tenant_luna.services')->where('name', 'Hot towel shave')->value('currency'))->toBe('USD');
});

it('takes no accent: one the body still sends is neither stored nor carried back, the snippet carrying the theme alone and the hosted page writing no style attribute', function () {
    settingsWrite($this, settingsOwner(), 'PUT', '/settings', wholePatch(['brand_accent' => '#0e7490']))->assertRedirect(tenantUrl('luna', '/admin/settings'));

    expect(DB::table('information_schema.columns')->where(['table_schema' => 'tenant_luna', 'table_name' => 'settings', 'column_name' => 'brand_accent'])->exists())->toBeFalse();
    $this->actingAs(settingsOwner())->get(tenantUrl('luna', '/admin/settings'))
        ->assertInertia(fn (Assert $page) => $page->missing('values.brand_accent')->where('urls.snippet', "<div class=\"kr-booking\" data-theme=\"violet\"></div>\n<script src=\"".tenantUrl('luna', '/embed.js').'" async></script>'));
    expect(mountOf(documentOf($this->get(hostedPage('luna'))))->hasAttribute('style'))->toBeFalse();
});

it('refuses a bad field with its own sentence, the reminder lead\'s different from the holds\', and writes nothing', function (array $overrides, string $sentence) {
    /** @var array<string, mixed> $overrides */
    settingsWrite($this, settingsOwner(), 'PUT', '/settings', wholePatch($overrides))
        ->assertStatus(400)->assertJsonPath('code', 'invalid_request')->assertJsonPath('data.detail', $sentence);

    expect(DB::table('tenants')->where('slug', 'luna')->value('currency'))->toBe('EUR')
        ->and(settingsOf('luna')->value('checkout_ttl_min'))->toBe(15)
        ->and(settingsOf('luna')->value('start_interval_min'))->toBe(15);
})->with([
    'two letters' => [['currency' => 'EU'], fn (): string => settingsLine(SettingsRequest::CURRENCY)],
    'four letters' => [['currency' => 'EURO'], fn (): string => settingsLine(SettingsRequest::CURRENCY)],
    'lowercase letters' => [['currency' => 'eur'], fn (): string => settingsLine(SettingsRequest::CURRENCY)],
    'no currency' => [['currency' => ''], fn (): string => settingsLine(SettingsRequest::CURRENCY)],
    'a theme outside the seven' => [['theme' => 'teal'], fn (): string => settingsThemeRefusal()],
    'a theme in capitals' => [['theme' => 'Violet'], fn (): string => settingsThemeRefusal()],
    'a checkout hold of zero' => [['checkout_ttl_min' => 0], fn (): string => settingsLine(SettingsRequest::CHECKOUT)],
    'a checkout hold in halves' => [['checkout_ttl_min' => 1.5], fn (): string => settingsLine(SettingsRequest::CHECKOUT)],
    'an approval hold of zero' => [['approval_ttl_hours' => 0], fn (): string => settingsLine(SettingsRequest::APPROVAL)],
    'a payment hold of zero' => [['payment_ttl_hours' => 0], fn (): string => settingsLine(SettingsRequest::PAYMENT)],
    'a reminder lead below zero' => [['reminder_lead_hours' => -1], fn (): string => settingsLine(SettingsRequest::REMINDER)],
    'start times every 7 minutes, which does not divide the hour' => [['start_interval_min' => 7], fn (): string => settingsIntervalRefusal(5)],
    'start times every 0 minutes' => [['start_interval_min' => 0], fn (): string => settingsIntervalRefusal(5)],
    'start times every 45 minutes, on the granularity but not dividing the hour' => [['start_interval_min' => 45], fn (): string => settingsIntervalRefusal(5)],
    'start times every -15 minutes' => [['start_interval_min' => -15], fn (): string => settingsIntervalRefusal(5)],
    'start times in words' => [['start_interval_min' => 'fifteen'], fn (): string => settingsIntervalRefusal(5)],
    'an off list that is not a list' => [['emails_off' => 'reminder'], fn (): string => settingsLine(SettingsRequest::EMAILS)],
    'a key that is not a key' => [['emails_off' => ['Bad Key']], fn (): string => settingsLine(SettingsRequest::EMAIL_KEY)],
    'an about over 2000 characters' => [['about' => str_repeat('a', 2001)], fn (): string => settingsLine(SettingsRequest::ABOUT, ['max' => (string) SettingsRequest::ABOUT_MAX])],
    'an address line over 200 characters' => [['address_line2' => str_repeat('a', 201)], fn (): string => settingsLine(SettingsRequest::ADDRESS_LINE, ['max' => (string) SettingsRequest::ADDRESS_LINE_MAX])],
    'a city over 100 characters' => [['city' => str_repeat('a', 101)], fn (): string => settingsLine(SettingsRequest::CITY, ['max' => (string) SettingsRequest::CITY_MAX])],
    'a postal code over 20 characters' => [['postal_code' => str_repeat('1', 21)], fn (): string => settingsLine(SettingsRequest::POSTAL_CODE, ['max' => (string) SettingsRequest::POSTAL_CODE_MAX])],
    'a country of three letters' => [['country' => 'GRC'], fn (): string => settingsLine(SettingsRequest::COUNTRY)],
    'a country in lowercase' => [['country' => 'gr'], fn (): string => settingsLine(SettingsRequest::COUNTRY)],
    'a phone over 40 characters' => [['phone' => str_repeat('1', 41)], fn (): string => settingsLine(SettingsRequest::PHONE, ['max' => (string) SettingsRequest::PHONE_MAX])],
    'a public email that is not an address' => [['public_email' => 'hello at luna'], fn (): string => settingsLine(SettingsRequest::PUBLIC_EMAIL)],
    'a website on http' => [['website' => 'http://luna.example'], fn (): string => settingsLine(SettingsRequest::WEBSITE)],
    'an Instagram link that is not a link' => [['instagram' => '@luna'], fn (): string => settingsLine(SettingsRequest::INSTAGRAM)],
    'a Facebook link on http' => [['facebook' => 'http://facebook.com/luna'], fn (): string => settingsLine(SettingsRequest::FACEBOOK)],
    'a Google reviews link that is not a link' => [['google_reviews_url' => 'g.page/luna'], fn (): string => settingsLine(SettingsRequest::GOOGLE_REVIEWS)],
    'important info over 1000 characters' => [['important_info' => str_repeat('a', 1001)], fn (): string => settingsLine(SettingsRequest::IMPORTANT_INFO, ['max' => (string) SettingsRequest::IMPORTANT_INFO_MAX])],
]);

it('saves the profile with the patch, an empty field and a blank one landing as null, and the next read carrying it back', function () {
    $owner = settingsOwner();
    $profile = [
        'about' => 'A small studio by the sea.', 'address_line1' => '12 Odos Ermou', 'address_line2' => '', 'city' => 'Athens', 'postal_code' => '105 63', 'country' => 'CY',
        'phone' => '+30 210 000 0000', 'public_email' => 'hello@luna.example', 'website' => 'https://luna.example', 'instagram' => 'https://instagram.com/luna',
        'facebook' => '   ', 'google_reviews_url' => 'https://g.page/r/luna/review', 'important_info' => 'Please arrive five minutes early.',
    ];

    settingsWrite($this, $owner, 'PUT', '/settings', wholePatch($profile))->assertRedirect(tenantUrl('luna', '/admin/settings'));

    $stored = [...$profile, 'address_line2' => null, 'facebook' => null];
    expect((array) settingsOf('luna')->select(Profile::FIELDS)->first())->toBe($stored);
    $this->actingAs($owner)->get(tenantUrl('luna', '/admin/settings'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('values.about', $profile['about'])->where('values.address_line2', null)->where('values.country', 'CY')->where('values.important_info', $profile['important_info']));
});

it('fills the theme\'s refusal from Theme\'s cases, the file spelling neither their count nor a name', function () {
    $line = __(SettingsRequest::THEME, [], 'en');

    expect($line)->toBeString()->toContain(':count')->toContain(':themes');

    foreach (Theme::cases() as $theme) {
        expect($line)->not->toContain(settingsLine('admin/settings.booking_page.themes.'.$theme->value));
        expect(settingsThemeRefusal())->toContain(settingsLine('admin/settings.booking_page.themes.'.$theme->value));
    }
});

it('refuses a partial patch: a body missing any one of the twenty-two fields is refused with that field\'s sentence and writes nothing', function (string $field, string $sentence) {
    $partial = wholePatch(['currency' => 'USD', 'checkout_ttl_min' => 20]);
    unset($partial[$field]);
    expect($partial)->toHaveCount(20);

    settingsWrite($this, settingsOwner(), 'PUT', '/settings', $partial)->assertStatus(400)->assertJsonPath('code', 'invalid_request')->assertJsonPath('data.detail', $sentence);

    expect(DB::table('tenants')->where('slug', 'luna')->value('currency'))->toBe('EUR')
        ->and(settingsOf('luna')->value('checkout_ttl_min'))->toBe(15);
})->with([
    'no currency' => ['currency', fn (): string => settingsLine(SettingsRequest::CURRENCY)],
    'no theme' => ['theme', fn (): string => settingsThemeRefusal()],
    'no checkout hold' => ['checkout_ttl_min', fn (): string => settingsLine(SettingsRequest::CHECKOUT)],
    'no approval hold' => ['approval_ttl_hours', fn (): string => settingsLine(SettingsRequest::APPROVAL)],
    'no payment hold' => ['payment_ttl_hours', fn (): string => settingsLine(SettingsRequest::PAYMENT)],
    'no reminder lead' => ['reminder_lead_hours', fn (): string => settingsLine(SettingsRequest::REMINDER)],
    'no start interval' => ['start_interval_min', fn (): string => settingsIntervalRefusal(5)],
    'no off list' => ['emails_off', fn (): string => settingsLine(SettingsRequest::EMAILS)],
    'no about' => ['about', fn (): string => settingsLine(SettingsRequest::ABOUT, ['max' => (string) SettingsRequest::ABOUT_MAX])],
    'no address line 1' => ['address_line1', fn (): string => settingsLine(SettingsRequest::ADDRESS_LINE, ['max' => (string) SettingsRequest::ADDRESS_LINE_MAX])],
    'no address line 2' => ['address_line2', fn (): string => settingsLine(SettingsRequest::ADDRESS_LINE, ['max' => (string) SettingsRequest::ADDRESS_LINE_MAX])],
    'no city' => ['city', fn (): string => settingsLine(SettingsRequest::CITY, ['max' => (string) SettingsRequest::CITY_MAX])],
    'no postal code' => ['postal_code', fn (): string => settingsLine(SettingsRequest::POSTAL_CODE, ['max' => (string) SettingsRequest::POSTAL_CODE_MAX])],
    'no country' => ['country', fn (): string => settingsLine(SettingsRequest::COUNTRY)],
    'no phone' => ['phone', fn (): string => settingsLine(SettingsRequest::PHONE, ['max' => (string) SettingsRequest::PHONE_MAX])],
    'no public email' => ['public_email', fn (): string => settingsLine(SettingsRequest::PUBLIC_EMAIL)],
    'no website' => ['website', fn (): string => settingsLine(SettingsRequest::WEBSITE)],
    'no Instagram link' => ['instagram', fn (): string => settingsLine(SettingsRequest::INSTAGRAM)],
    'no Facebook link' => ['facebook', fn (): string => settingsLine(SettingsRequest::FACEBOOK)],
    'no Google reviews link' => ['google_reviews_url', fn (): string => settingsLine(SettingsRequest::GOOGLE_REVIEWS)],
    'no important info' => ['important_info', fn (): string => settingsLine(SettingsRequest::IMPORTANT_INFO, ['max' => (string) SettingsRequest::IMPORTANT_INFO_MAX])],
]);

it('carries the settings, payments, billing and emails sections beside the shell\'s, in the request\'s language, and the image helps\' sizes spelled from their constants', function () {
    $this->actingAs(settingsOwner())->get(tenantUrl('luna', '/admin/settings'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component(SettingsController::PAGE)
            ->where('strings', ['shell' => __('admin/shell', [], 'en'), SettingsController::SECTION => __('admin/settings', [], 'en'), PaymentsController::SECTION => __('admin/payments', [], 'en'), BillingController::SECTION => __('admin/billing', [], 'en'), SettingsController::EMAILS => __('admin/emails', [], 'en')])
            ->where('limits.logo.megabytes', intdiv(LogoRequest::MAX_KILOBYTES, 1024))
            ->where('limits.cover.megabytes', intdiv(CoverRequest::MAX_KILOBYTES, 1024))
            ->where('limits.gallery.megabytes', intdiv(GalleryPhotoRequest::MAX_KILOBYTES, 1024))
            ->where('limits.capacity.count', Settings::GALLERY_CAPACITY)
            ->where('limits.logo.spelled', (new NumberFormatter('en', NumberFormatter::SPELLOUT))->format(intdiv(LogoRequest::MAX_KILOBYTES, 1024)))
            ->where('limits.capacity.spelled', (new NumberFormatter('en', NumberFormatter::SPELLOUT))->format(Settings::GALLERY_CAPACITY)));
});

it('carries the country select\'s names, every code of countries.php in its order with its name in the user\'s language', function (string $locale) {
    User::query()->where('email', 'owner@luna.example')->update(['locale' => $locale]);
    $names = __('countries', [], $locale);
    assert(is_array($names));

    $this->actingAs(settingsOwner())->get(tenantUrl('luna', '/admin/settings'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component(SettingsController::PAGE)->where('countries', $names));
    $english = __('countries', [], 'en');
    assert(is_array($english));
    expect(array_keys($names))->toHaveCount(39)->toBe(array_keys($english))
        ->and(app(CountryNames::class)->in(SiteLanguage::from($locale)))->toBe($names);
})->with(['en', 'el']);

it('refuses each case of tests/Support/settings-refusals.json in the words the screen\'s model states it in, the same key with the same values', function () {
    $cases = json_decode((string) file_get_contents(base_path('tests/Support/settings-refusals.json')), true, flags: JSON_THROW_ON_ERROR);
    assert(is_array($cases) && $cases !== []);

    foreach ($cases as $case) {
        assert(is_array($case) && is_string($case['field']) && is_string($case['typed']) && is_int($case['times']) && is_string($case['key']) && is_array($case['values']));
        $typed = str_repeat($case['typed'], $case['times']);
        $body = wholePatch([$case['field'] => $typed]);
        $values = [];

        foreach ($case['values'] as $name => $value) {
            $values[(string) $name] = is_scalar($value) ? (string) $value : '';
        }

        settingsWrite($this, settingsOwner(), 'PUT', '/settings', $body)
            ->assertStatus(400)->assertJsonPath('data.detail', settingsLine('admin/settings.'.$case['key'], $values));
    }
});

it('words the three flashes in the user\'s language, Greek for a user at el and English for a colleague at en at the same tenant', function (SiteLanguage $language) {
    $user = settingsReader($language);

    settingsWrite($this, $user, 'PUT', '/settings', wholePatch())->assertRedirect();
    expect(session('flash'))->toBe(['kind' => 'success', 'message' => adminLine(SettingsController::SAVED, [], $language)]);
    settingsWrite($this, $user, 'PUT', '/opening-hours', ['rules' => [['weekday' => 2, 'start' => '09:00', 'end' => '13:00']]])->assertRedirect();
    expect(session('flash'))->toBe(['kind' => 'success', 'message' => adminLine(SettingsController::HOURS_SAVED, [], $language)]);
    $this->actingAs($user)->from(tenantUrl('luna', '/admin'))->json('PUT', tenantUrl('luna', '/admin/settings/colour-by'), ['colour_by' => 'status'], ['X-Inertia' => 'true'])->assertRedirect();
    expect(session('flash'))->toBe(['kind' => 'success', 'message' => adminLine(SettingsController::COLOUR_BY_SAVED, [], $language)]);

    $flashes = [SettingsController::SAVED, SettingsController::HOURS_SAVED, SettingsController::COLOUR_BY_SAVED];

    foreach ($flashes as $key) {
        expect(adminLine($key, [], SiteLanguage::Greek))->not->toBe(adminLine($key, [], SiteLanguage::English), $key);
    }

    // The three are every key of the file's flashes group.
    $group = __('admin/settings.flashes', [], 'en');
    assert(is_array($group));
    expect(collect($flashes)->sort()->values()->all())
        ->toBe(collect(array_keys($group))->map(fn (int|string $key): string => 'admin/settings.flashes.'.$key)->sort()->values()->all());
})->with(['a user at el' => [SiteLanguage::Greek], 'a user at en' => [SiteLanguage::English]]);

it('refuses a save breaking each of SettingsRequest\'s twenty-two rules with its sentence in the user\'s language, the Greek unlike the English, the lists joined by the language\'s own list words and the themes\' count spelled in it', function (SiteLanguage $language) {
    $user = settingsReader($language);
    $line = fn (string $key): Closure => fn (SiteLanguage $in): string => adminLine($key, [], $in);
    $max = fn (string $key, int $limit): Closure => fn (SiteLanguage $in): string => adminLine($key, ['max' => (string) $limit], $in);
    // Each rule's key, the field that breaks it, and its sentence read in a language. The list's
    // two words are no sentence of their own: the theme's and the start interval's lines read
    // them, so they are proven inside those two.
    $rules = [
        [SettingsRequest::CURRENCY, ['currency' => 'EU'], $line(SettingsRequest::CURRENCY)],
        [SettingsRequest::LANGUAGE, ['language' => 'de'], $line(SettingsRequest::LANGUAGE)],
        [SettingsRequest::THEME, ['theme' => 'teal'], fn (SiteLanguage $in): string => settingsThemeRefusalIn($in)],
        [SettingsRequest::CHECKOUT, ['checkout_ttl_min' => 0], $line(SettingsRequest::CHECKOUT)],
        [SettingsRequest::APPROVAL, ['approval_ttl_hours' => 0], $line(SettingsRequest::APPROVAL)],
        [SettingsRequest::PAYMENT, ['payment_ttl_hours' => 0], $line(SettingsRequest::PAYMENT)],
        [SettingsRequest::REMINDER, ['reminder_lead_hours' => -1], $line(SettingsRequest::REMINDER)],
        [SettingsRequest::START_INTERVAL, ['start_interval_min' => 7], fn (SiteLanguage $in): string => settingsIntervalRefusalIn(5, $in)],
        [SettingsRequest::EMAILS, ['emails_off' => 'reminder'], $line(SettingsRequest::EMAILS)],
        [SettingsRequest::EMAIL_KEY, ['emails_off' => ['Bad Key']], $line(SettingsRequest::EMAIL_KEY)],
        [SettingsRequest::ABOUT, ['about' => str_repeat('a', SettingsRequest::ABOUT_MAX + 1)], $max(SettingsRequest::ABOUT, SettingsRequest::ABOUT_MAX)],
        [SettingsRequest::ADDRESS_LINE, ['address_line1' => str_repeat('a', SettingsRequest::ADDRESS_LINE_MAX + 1)], $max(SettingsRequest::ADDRESS_LINE, SettingsRequest::ADDRESS_LINE_MAX)],
        [SettingsRequest::CITY, ['city' => str_repeat('a', SettingsRequest::CITY_MAX + 1)], $max(SettingsRequest::CITY, SettingsRequest::CITY_MAX)],
        [SettingsRequest::POSTAL_CODE, ['postal_code' => str_repeat('1', SettingsRequest::POSTAL_CODE_MAX + 1)], $max(SettingsRequest::POSTAL_CODE, SettingsRequest::POSTAL_CODE_MAX)],
        [SettingsRequest::COUNTRY, ['country' => 'GRC'], $line(SettingsRequest::COUNTRY)],
        [SettingsRequest::PHONE, ['phone' => str_repeat('1', SettingsRequest::PHONE_MAX + 1)], $max(SettingsRequest::PHONE, SettingsRequest::PHONE_MAX)],
        [SettingsRequest::PUBLIC_EMAIL, ['public_email' => 'hello at luna'], $line(SettingsRequest::PUBLIC_EMAIL)],
        [SettingsRequest::WEBSITE, ['website' => 'http://luna.example'], $line(SettingsRequest::WEBSITE)],
        [SettingsRequest::INSTAGRAM, ['instagram' => '@luna'], $line(SettingsRequest::INSTAGRAM)],
        [SettingsRequest::FACEBOOK, ['facebook' => 'http://facebook.com/luna'], $line(SettingsRequest::FACEBOOK)],
        [SettingsRequest::GOOGLE_REVIEWS, ['google_reviews_url' => 'g.page/luna'], $line(SettingsRequest::GOOGLE_REVIEWS)],
        [SettingsRequest::IMPORTANT_INFO, ['important_info' => str_repeat('a', SettingsRequest::IMPORTANT_INFO_MAX + 1)], $max(SettingsRequest::IMPORTANT_INFO, SettingsRequest::IMPORTANT_INFO_MAX)],
    ];
    $keys = [];

    foreach ($rules as [$key, $broken, $read]) {
        settingsWrite($this, $user, 'PUT', '/settings', wholePatch($broken))
            ->assertStatus(400)->assertJsonPath('code', 'invalid_request')->assertJsonPath('data.detail', $read($language));
        expect($read(SiteLanguage::Greek))->not->toBe($read(SiteLanguage::English), $key);
        $keys[] = Str::after($key, 'admin/settings.validation.');
    }

    // Every key of the validation group is provoked, the list's two words aside.
    $group = __('admin/settings.validation', [], 'en');
    assert(is_array($group));
    expect($keys)->toBe(array_values(array_diff(array_keys($group), ['list'])))->toHaveCount(22);
})->with(['a user at el' => [SiteLanguage::Greek], 'a user at en' => [SiteLanguage::English]]);

it('joins the start interval\'s and the theme\'s lists by the list words read through the translator, never typed in the code', function () {
    $user = settingsReader(SiteLanguage::Greek);
    // The Greek list words equal the English ones, so two fabricated words prove the join reads
    // them through the translator. The Greek file is loaded first, so its sentences stand beside
    // the two words added over it.
    app('translator')->load('*', 'admin/settings', 'el');
    app('translator')->addLines([
        SettingsRequest::LIST_SEPARATOR => '; ',
        SettingsRequest::LIST_LAST => ':items or else :last',
    ], 'el');

    $interval = settingsWrite($this, $user, 'PUT', '/settings', wholePatch(['start_interval_min' => 7]))
        ->assertStatus(400)->assertJsonPath('data.detail', settingsIntervalRefusalIn(5, SiteLanguage::Greek));
    expect($interval->json('data.detail'))->toContain('5; 10')->toContain(' or else 60');
    $theme = settingsWrite($this, $user, 'PUT', '/settings', wholePatch(['theme' => 'teal']))
        ->assertStatus(400)->assertJsonPath('data.detail', settingsThemeRefusalIn(SiteLanguage::Greek));
    expect($theme->json('data.detail'))->toContain('; ')->toContain(' or else ');
});

it('hands the Greek owner the settings section whole under lang el on Solo and on Team, and with the map pending, ready and failed', function (Entitlements $plan, bool $hours, string $map) {
    app()->instance(EntitlementResolver::class, new class($plan) implements EntitlementResolver
    {
        public function __construct(private readonly Entitlements $plan) {}

        public function resolve(Tenant $tenant): Entitlements
        {
            return $this->plan;
        }
    });
    settingsOf('luna')->update(['lat' => 37.9763295, 'lng' => 23.7323814, 'map_path' => 'tenants/luna/map-0123456789abcdef.png', 'map_state' => $map]);
    $page = $this->actingAs(settingsReader(SiteLanguage::Greek))->get(tenantUrl('luna', '/admin/settings'))->assertOk();

    expect((string) $page->getContent())->toContain('<html lang="el">');
    // The page is handed the entitlements Opening hours is decided by: hoursBranch in
    // resources/js/admin/settings/model.ts decides, and its own test file proves it.
    $page->assertInertia(fn (Assert $inertia) => $inertia->component(SettingsController::PAGE)
        ->where('strings.'.SettingsController::SECTION, __('admin/settings', [], 'el'))
        ->where('entitlements', fn (Collection $slugs): bool => $slugs->contains(Feature::Staff->value) === ! $hours)
        ->where('map.state', $map)
        ->where('map.image', fn (mixed $image): bool => ($image !== null) === ($map === 'ready'))
        ->where('limits.logo.spelled', (new NumberFormatter('el', NumberFormatter::SPELLOUT))->format(intdiv(LogoRequest::MAX_KILOBYTES, 1024)))
        ->where('limits.cover.spelled', (new NumberFormatter('el', NumberFormatter::SPELLOUT))->format(intdiv(CoverRequest::MAX_KILOBYTES, 1024)))
        ->where('limits.gallery.spelled', (new NumberFormatter('el', NumberFormatter::SPELLOUT))->format(intdiv(GalleryPhotoRequest::MAX_KILOBYTES, 1024)))
        ->where('limits.capacity.spelled', (new NumberFormatter('el', NumberFormatter::SPELLOUT))->format(Settings::GALLERY_CAPACITY)));
    expect(__('admin/settings', [], 'el'))->not->toBe(__('admin/settings', [], 'en'));
})->with([
    'Solo, the map ready' => [Entitlements::none(), true, 'ready'],
    'Solo, the map pending' => [Entitlements::none(), true, 'pending'],
    'Solo, the map failed' => [Entitlements::none(), true, 'failed'],
    'Team, the map ready' => [Entitlements::fromSlugs([Feature::Staff->value]), false, 'ready'],
    'Team, the map pending' => [Entitlements::fromSlugs([Feature::Staff->value]), false, 'pending'],
    'Team, the map failed' => [Entitlements::fromSlugs([Feature::Staff->value]), false, 'failed'],
]);

it('labels every message of the Emails section in the user\'s language, the seventeen walked, each the language file\'s own label and the Greek ones unlike the English', function (SiteLanguage $language) {
    User::query()->where('email', 'owner@luna.example')->update(['locale' => $language->value]);
    $messages = data_get($this->actingAs(settingsOwner())->get(tenantUrl('luna', '/admin/settings'))->assertOk()->viewData('page'), 'props.settings.messages');
    assert(is_array($messages));
    $labels = array_column($messages, 'label', 'key');
    $expected = [];
    $english = [];

    foreach (MessageKey::cases() as $key) {
        $expected[$key->value] = adminLine($key->labelKey(), [], $language);
        $english[$key->value] = adminLine($key->labelKey());
    }

    expect($labels)->toHaveCount(count(MessageKey::cases()))->toHaveCount(17)->toBe($expected);

    // A Greek label the same as its English would hide a fallback: none is.
    if ($language === SiteLanguage::Greek) {
        expect(array_intersect_assoc($expected, $english))->toBe([]);
    }
})->with([
    'the owner at en' => [SiteLanguage::English],
    'the owner at el' => [SiteLanguage::Greek],
]);

it('admits a reminder lead of zero, which saves and re-reads as zero', function () {
    settingsWrite($this, settingsOwner(), 'PUT', '/settings', wholePatch(['reminder_lead_hours' => 0]))->assertRedirect(tenantUrl('luna', '/admin/settings'));

    expect(settingsOf('luna')->value('reminder_lead_hours'))->toBe(0);
});

it('admits start times every 5, 10, 15, 20, 30 and 60 minutes at the 5-minute granularity, each landing in the row and the page carrying the last', function () {
    $owner = settingsOwner();

    // Each value differs from the one before it, the first from the default 15, so every write is
    // seen to land.
    foreach ([5, 10, 15, 20, 30, 60] as $minutes) {
        settingsWrite($this, $owner, 'PUT', '/settings', wholePatch(['start_interval_min' => $minutes]))->assertRedirect(tenantUrl('luna', '/admin/settings'));
        expect(settingsOf('luna')->value('start_interval_min'))->toBe($minutes);
    }

    $this->actingAs($owner)->get(tenantUrl('luna', '/admin/settings'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('values.start_interval_min', 60));
});

it('refuses start times off a coarser granularity with the sentence naming it, before the CHECK could, and admits one on it', function () {
    StartInterval::set('tenant_luna', 30);
    settingsOf('luna')->update(['granularity_min' => 10]);
    $owner = settingsOwner();

    settingsWrite($this, $owner, 'PUT', '/settings', wholePatch(['start_interval_min' => 15]))
        ->assertStatus(400)->assertJsonPath('code', 'invalid_request')->assertJsonPath('data.detail', settingsIntervalRefusal(10));
    expect(settingsOf('luna')->value('start_interval_min'))->toBe(30);

    settingsWrite($this, $owner, 'PUT', '/settings', wholePatch(['start_interval_min' => 20]))->assertRedirect(tenantUrl('luna', '/admin/settings'));
    expect(settingsOf('luna')->value('start_interval_min'))->toBe(20);
});

it('replaces the implicit member\'s whole week through the route naming no member as a weekly pattern from the one start with no end, and offers the new day on the very next public read', function () {
    $owner = settingsOwner();
    $implicit = implicitStaffId('tenant_luna');
    DB::table('tenant_luna.service_staff')->insert(['service_id' => LunaFixture::id('services', 'Haircut'), 'staff_id' => $implicit]);
    $sunday = '2026-12-06';

    expect(implicitStarts($this, $sunday))->toBe([]);

    settingsWrite($this, $owner, 'PUT', '/opening-hours', ['rules' => [['weekday' => 7, 'start' => '10:00', 'end' => '12:00'], ['weekday' => 2, 'start' => '09:00', 'end' => '13:00'], ['weekday' => 2, 'start' => '14:00', 'end' => '18:07']]])
        ->assertRedirect(tenantUrl('luna', '/admin/settings'))
        ->assertSessionHas('flash', ['kind' => 'success', 'message' => settingsLine(SettingsController::HOURS_SAVED)]);

    [$week, $pattern] = settingsImplicitWeek();
    expect($week)->toBe([[2, '09:00', '13:00'], [2, '14:00', '18:07'], [7, '10:00', '12:00']])
        ->and($pattern)->toBe([1, '1999-12-27', null])
        ->and(implicitStarts($this, $sunday))->not->toBe([])
        ->and(DB::table('tenant_luna.shift_patterns')->where('staff_id', LunaFixture::id('staff', 'Elena Papadaki'))->exists())->toBeTrue();

    $this->actingAs($owner)->get(tenantUrl('luna', '/admin/settings'))
        ->assertInertia(fn (Assert $page) => $page->where('week', [['weekday' => 2, 'start' => '09:00', 'end' => '13:00'], ['weekday' => 2, 'start' => '14:00', 'end' => '18:07'], ['weekday' => 7, 'start' => '10:00', 'end' => '12:00']]));

    settingsWrite($this, $owner, 'PUT', '/opening-hours', ['rules' => []])->assertRedirect(tenantUrl('luna', '/admin/settings'));
    expect(settingsImplicitWeek())->toBe([[], null]);
});

it('refuses a bad week with its own sentence and leaves the week as it was, in English for a user at en and in the shell\'s Greek for a user at el', function (array $body, string $sentence, SiteLanguage $language) {
    /** @var array<string, mixed> $body */
    User::query()->where('email', 'owner@luna.example')->update(['locale' => $language->value]);
    settingsWrite($this, settingsOwner(), 'PUT', '/opening-hours', $body)->assertStatus(400)->assertJsonPath('data.detail', adminLine($sentence, [], $language));

    expect(settingsImplicitWeek()[0])->toHaveCount(5)
        ->and(adminLine($sentence, [], SiteLanguage::Greek))->not->toBe(adminLine($sentence));
})->with([
    'no list at all' => [[], WeeklyWindows::LIST],
    'a list that is not one' => [['rules' => 'Monday'], WeeklyWindows::LIST],
    'a weekday of eight' => [['rules' => [['weekday' => 8, 'start' => '09:00', 'end' => '17:00']]], WeeklyWindows::WEEKDAY],
    'a start past the day' => [['rules' => [['weekday' => 1, 'start' => '24:00', 'end' => '17:00']]], WeeklyWindows::TIMES],
    'a window ending first' => [['rules' => [['weekday' => 1, 'start' => '13:00', 'end' => '09:00']]], WeeklyWindows::ORDER],
])->with(['in English' => [fn (): SiteLanguage => SiteLanguage::English], 'in Greek' => [fn (): SiteLanguage => SiteLanguage::Greek]]);

it('still renders under the empty entitlement set, week and roster included, since nothing here is created', function () {
    app()->instance(EntitlementResolver::class, new class implements EntitlementResolver
    {
        public function resolve(Tenant $tenant): Entitlements
        {
            return Entitlements::none();
        }
    });

    $this->actingAs(settingsOwner())->get(tenantUrl('luna', '/admin/settings'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component(SettingsController::PAGE)->where('entitlements', [])->has('week', 5)->has('roster', 3));
});

it('hands the roster of active members alone, so a tenant with two members of whom one is inactive gets the editor under the empty entitlement set', function () {
    app()->instance(EntitlementResolver::class, new class implements EntitlementResolver
    {
        public function resolve(Tenant $tenant): Entitlements
        {
            return Entitlements::none();
        }
    });
    DB::table('tenant_luna.staff')->where('name', 'Nikos Georgiou')->delete();
    DB::table('tenant_luna.staff')->where('name', 'Elena Papadaki')->update(['status' => 'inactive']);
    expect(DB::table('tenant_luna.staff')->count())->toBe(2);

    $this->actingAs(settingsOwner())->get(tenantUrl('luna', '/admin/settings'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component(SettingsController::PAGE)
            ->where('entitlements', [])
            ->has('week', 5)
            ->has('roster', 1)
            ->where('roster.0.id', implicitStaffId('tenant_luna'))
            ->where('roster.0.name', 'Luna Hair Studio'));
});

it('uploads a PNG under a megabyte to the tenant\'s own path, records it, renders it in the hosted header from the tenant\'s origin, and replaces it on the next upload', function () {
    FakeDisks::fake(TenantImages::DISK);
    $owner = settingsOwner();

    settingsWrite($this, $owner, 'POST', '/settings/logo', ['logo' => UploadedFile::fake()->image('logo.png', 120, 40)])
        ->assertRedirect(tenantUrl('luna', '/admin/settings'))
        ->assertSessionHas('flash', ['kind' => 'success', 'message' => adminLine(LogoController::UPLOADED)]);

    $path = settingsOf('luna')->value('brand_logo_path');
    expect($path)->toMatch('/\Atenants\/luna\/logo-[0-9a-f]{16}\.png\z/');
    assert(is_string($path));
    Storage::disk(TenantImages::DISK)->assertExists($path);
    $file = basename($path);
    $url = tenantUrl('luna', '/logo/'.$file);

    $this->actingAs($owner)->get(tenantUrl('luna', '/admin/settings'))
        ->assertInertia(fn (Assert $page) => $page->where('logo', ['name' => $file, 'url' => $url]));

    $hosted = documentOf($this->get(hostedPage('luna'))->assertOk());
    $images = (new DOMXPath($hosted))->query("//header[contains(@class, 'hosted-header')]//img");
    assert($images !== false);
    expect($images)->toHaveCount(1);
    $image = $images->item(0);
    assert($image instanceof DOMElement);
    expect($image->getAttribute('src'))->toBe($url)->and($image->getAttribute('class'))->toBe('hosted-logo');

    $served = $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('Cache-Control', 'max-age='.ServedImages::MAX_AGE.', public');
    expect($served->getContent())->toBe(Storage::disk(TenantImages::DISK)->get($path));
    $this->get(tenantUrl('luna', '/logo/logo-0000000000000000.png'))->assertNotFound();
    $this->get(tenantUrl('pier', '/logo/'.$file))->assertNotFound();
    $this->get(tenantUrl('luna', '/logo/'.$file), ['If-None-Match' => (string) $served->headers->get('ETag')])->assertStatus(304);

    settingsWrite($this, $owner, 'POST', '/settings/logo', ['logo' => UploadedFile::fake()->image('other.png', 200, 60)])->assertRedirect(tenantUrl('luna', '/admin/settings'));
    $replaced = settingsOf('luna')->value('brand_logo_path');
    assert(is_string($replaced));
    expect($replaced)->not->toBe($path);
    Storage::disk(TenantImages::DISK)->assertExists($replaced);
    Storage::disk(TenantImages::DISK)->assertMissing($path);
    $this->get($url)->assertNotFound();
});

it('refuses an SVG, a GIF and an oversized file each with its own sentence, and stores nothing', function (UploadedFile $file, string $sentence) {
    FakeDisks::fake(TenantImages::DISK);

    settingsWrite($this, settingsOwner(), 'POST', '/settings/logo', ['logo' => $file])->assertStatus(400)->assertJsonPath('data.detail', uploadLine($sentence));

    expect(settingsOf('luna')->value('brand_logo_path'))->toBeNull()
        ->and(Storage::disk(TenantImages::DISK)->allFiles())->toBe([]);
})->with([
    'an SVG' => [UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'), LogoRequest::TYPE],
    'an SVG named and declared as a PNG' => fn () => [disguisedSvg(), LogoRequest::TYPE],
    'a GIF, a raster but not one of the three' => [UploadedFile::fake()->image('logo.gif', 40, 40), LogoRequest::TYPE],
    'two megabytes' => [oversizedLogo(), LogoRequest::SIZE],
]);

it('words the logo\'s type refusal in Greek for a user at el, and stores nothing', function () {
    FakeDisks::fake(TenantImages::DISK);
    User::query()->where('email', 'owner@luna.example')->update(['locale' => SiteLanguage::Greek->value]);

    settingsWrite($this, settingsOwner(), 'POST', '/settings/logo', ['logo' => UploadedFile::fake()->image('logo.gif', 40, 40)])
        ->assertStatus(400)->assertJsonPath('data.detail', uploadLine(LogoRequest::TYPE, SiteLanguage::Greek));

    expect(uploadLine(LogoRequest::TYPE, SiteLanguage::Greek))->not->toBe(uploadLine(LogoRequest::TYPE))
        ->and(settingsOf('luna')->value('brand_logo_path'))->toBeNull()
        ->and(Storage::disk(TenantImages::DISK)->allFiles())->toBe([]);
});

it('refuses a file whose bytes begin as a PNG and do not decode with the type sentence, and stores nothing, since the image store re-encodes every logo', function () {
    FakeDisks::fake(TenantImages::DISK);
    $path = TemporaryFiles::make('kr-logo');
    file_put_contents($path, "\x89PNG\r\n\x1a\n".str_repeat("\0", 64));

    settingsWrite($this, settingsOwner(), 'POST', '/settings/logo', ['logo' => new UploadedFile($path, 'logo.png', 'image/png', null, true)])
        ->assertStatus(400)->assertJsonPath('data.detail', uploadLine(LogoRequest::TYPE));

    expect(settingsOf('luna')->value('brand_logo_path'))->toBeNull()
        ->and(Storage::disk(TenantImages::DISK)->allFiles())->toBe([]);
});

it('refuses an upload naming no file', function () {
    settingsWrite($this, settingsOwner(), 'POST', '/settings/logo', [])->assertStatus(400)->assertJsonPath('data.detail', uploadLine(LogoRequest::MISSING));
});

it('carries the cover as its name and URL and the gallery as a list of the same in the stored order, each on the tenant\'s images route', function () {
    settingsOf('luna')->update([
        'cover_path' => 'tenants/luna/cover-0123456789abcdef.jpg',
        'gallery_paths' => TextArray::encode(['tenants/luna/gallery-bbbbbbbbbbbbbbbb.jpg', 'tenants/luna/gallery-aaaaaaaaaaaaaaaa.png']),
    ]);

    $this->actingAs(settingsOwner())->get(tenantUrl('luna', '/admin/settings'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('cover', ['name' => 'cover-0123456789abcdef.jpg', 'url' => tenantUrl('luna', '/images/cover-0123456789abcdef.jpg')])
            ->where('gallery', [
                ['name' => 'gallery-bbbbbbbbbbbbbbbb.jpg', 'url' => tenantUrl('luna', '/images/gallery-bbbbbbbbbbbbbbbb.jpg')],
                ['name' => 'gallery-aaaaaaaaaaaaaaaa.png', 'url' => tenantUrl('luna', '/images/gallery-aaaaaaaaaaaaaaaa.png')],
            ]));
});

it('removes the logo: the path cleared, the file gone, the header without it, and a second removal a no-op', function () {
    FakeDisks::fake(TenantImages::DISK);
    $owner = settingsOwner();
    settingsWrite($this, $owner, 'POST', '/settings/logo', ['logo' => UploadedFile::fake()->image('logo.png', 120, 40)])->assertRedirect();
    $path = settingsOf('luna')->value('brand_logo_path');
    assert(is_string($path));

    settingsWrite($this, $owner, 'DELETE', '/settings/logo')
        ->assertRedirect(tenantUrl('luna', '/admin/settings'))
        ->assertSessionHas('flash', ['kind' => 'success', 'message' => adminLine(LogoController::REMOVED)]);

    expect(settingsOf('luna')->value('brand_logo_path'))->toBeNull();
    Storage::disk(TenantImages::DISK)->assertMissing($path);
    expect((new DOMXPath(documentOf($this->get(hostedPage('luna')))))->query("//header[contains(@class, 'hosted-header')]//img"))->toHaveCount(0);
    $this->get(tenantUrl('luna', '/logo/'.basename($path)))->assertNotFound();
    $this->actingAs($owner)->get(tenantUrl('luna', '/admin/settings'))->assertInertia(fn (Assert $page) => $page->where('logo', null));

    settingsWrite($this, $owner, 'DELETE', '/settings/logo')->assertRedirect(tenantUrl('luna', '/admin/settings'));
});

it('reaches each case of the image helps\' counted lines in Greek at the bounds one and two, the megabytes spelled by ICU in Greek before the unit', function () {
    foreach (['admin/settings.booking_page.logo_help', 'admin/settings.booking_page.cover_help', 'admin/settings.booking_page.gallery_help'] as $key) {
        $forms = explode('|', (string) preg_replace('/(^|\|)\s*[{\[][-\d,*]*[}\]]\s*/', '$1', adminLine($key, [], SiteLanguage::Greek)));
        $spelled = fn (int $figure): string => (string) (new NumberFormatter(SiteLanguage::Greek->value, NumberFormatter::SPELLOUT))->format($figure);
        $values = fn (int $figure): array => ['n' => $spelled($figure), 'capacity' => $spelled(Settings::GALLERY_CAPACITY)];

        expect($forms)->toHaveCount(2, $key)
            ->and(adminChoice($key, 1, $values(1), SiteLanguage::Greek))->toBe(strtr($forms[0], [':n' => $spelled(1), ':capacity' => $spelled(Settings::GALLERY_CAPACITY)]), $key)
            ->and(adminChoice($key, 2, $values(2), SiteLanguage::Greek))->toBe(strtr($forms[1], [':n' => $spelled(2), ':capacity' => $spelled(Settings::GALLERY_CAPACITY)]), $key)
            ->and(adminChoice($key, 2, $values(2), SiteLanguage::Greek))->not->toBe(adminChoice($key, 2, $values(2)), $key);
    }
});
