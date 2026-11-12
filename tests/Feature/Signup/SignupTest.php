<?php

use App\Application\Clock;
use App\Application\Notification\PlatformMessenger;
use App\Domain\Lifetime;
use App\Http\Controllers\Central\SignupVerifyController;
use App\Http\Middleware\HandleCentralInertiaRequests;
use App\Http\Middleware\PublicRoute;
use App\Http\Middleware\RequiresBillingSignature;
use App\Http\Middleware\SetSiteLocale;
use App\Http\Requests\PasswordRule;
use App\Http\Requests\SignupRequest;
use App\Http\SignupHolder;
use App\Http\SignupStrings;
use App\Models\User;
use App\Site\SiteLanguage;
use App\Site\SitePages;
use App\Site\TermsVersion;
use App\Tenancy\SignupVerification;
use App\Tenancy\Slug;
use App\Tenancy\SlugProblem;
use App\Tenancy\Tenant;
use App\Tenancy\TenantStatus;
use App\Tenancy\TenantUrls;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Translation\Translator;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FixedClock;
use Tests\Support\RecordingPlatformMessages;
use Tests\TestCase;

pest()->use(RefreshDatabase::class);

/*
 * The signup on the central host: the form and its refusals, the row it leaves in state
 * provisioning with the owner under it, and the page that waits. Nothing is provisioned here; the
 * rows are central rows alone. The verification link and its click are SignupVerifyTest's, and
 * the Greek signup's walk SignupInGreekTest's. Every sentence is read through the translator from
 * signup.php, and the override case proves the page's strings and the refusals follow the route's
 * language, lang/el under /el.
 */

/**
 * Where this process writes a temporary Greek signup.php, emptied after every case.
 */
function signupOverrideRoot(): string
{
    return sys_get_temp_dir().'/kr-signup-'.getmypid();
}

/**
 * A temporary Greek signup.php holding the values given, in a directory of this process's own
 * added to the translator's paths after lang/, so its values stand over the real file's for el
 * and nothing on disk changes.
 *
 * @param  array<string, mixed>  $values
 */
function signupGreekOverride(array $values): void
{
    $root = signupOverrideRoot();
    File::ensureDirectoryExists($root.'/el');
    File::put($root.'/el/signup.php', '<?php return '.var_export($values, true).';');
    app(Translator::class)->addPath($root);
}

afterEach(fn () => File::deleteDirectory(signupOverrideRoot()));

it('renders the form on the central host with the seven kinds of business by their English labels from signup.php and no plan, sharing the flash, the brand, the language and the signup\'s strings alone', function () {
    $this->get(centralUrl('/signup'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('signup/Signup')
            ->where('categories', [
                ['value' => 'hair_salon', 'label' => signupSentence('categories.hair_salon')],
                ['value' => 'barber', 'label' => signupSentence('categories.barber')],
                ['value' => 'nails', 'label' => signupSentence('categories.nails')],
                ['value' => 'beauty', 'label' => signupSentence('categories.beauty')],
                ['value' => 'massage', 'label' => signupSentence('categories.massage')],
                ['value' => 'physiotherapy', 'label' => signupSentence('categories.physiotherapy')],
                ['value' => 'other', 'label' => signupSentence('categories.other')],
            ])
            ->missing('plans')
            ->missing('defaultPlan')
            ->where('centralHost', app(TenantUrls::class)->centralHost())
            ->where('action', '/signup')
            ->where('refusal', null)
            ->has('timezones', count(DateTimeZone::listIdentifiers()))
            ->where('flash', null)
            ->where('brand', ['name' => siteName()])
            ->where('language', 'en')
            ->where('strings', centralStrings(SiteLanguage::English))
            ->missing('tenant')
            ->missing('auth'));

    expect(__('signup', [], 'en'))->toHaveKeys(['form', 'pending', 'categories', 'too_many', 'expired', 'resent', 'errors'])
        ->and(signupSentence('form.heading'))->toBe('Start your booking page');
});

it('hands the pages the form\'s and the pending page\'s sections alone, every refusal and the server\'s own sentences left on the server', function (SiteLanguage $language) {
    app()->setLocale($language->value);
    $strings = app(SignupStrings::class)->props()['strings'];

    expect(array_keys($strings))->toBe(['form', 'pending'])
        ->and($strings['form'])->toBe(__('signup.form', [], $language->value));
})->with([
    'a signup at en' => [SiteLanguage::English],
    'a signup at el' => [SiteLanguage::Greek],
]);

it('sends the pending page its waiting sentence chosen by the verification link\'s hours, the page\'s three placeholders left for it', function (SiteLanguage $language) {
    app()->setLocale($language->value);
    $strings = app(SignupStrings::class)->props()['strings'];
    $waiting = data_get($strings, SignupStrings::WAITING);
    assert(is_string($waiting));
    $hours = Lifetime::hours(SignupVerification::LINK_LIFETIME);

    expect($waiting)->toBe(pageChoice('signup.pending.waiting', $hours, [], $language))
        ->and($waiting)->toContain(':email', ':business', ':address')
        ->and($waiting)->not->toContain(':count')
        ->and(preg_match('/[{}\[\]|]/', $waiting))->toBe(0)
        ->and(data_get($strings, 'pending.waiting_title'))->toBe(signupSentence('pending.waiting_title', $language));
})->with([
    'a signup at en' => [SiteLanguage::English],
    'a signup at el' => [SiteLanguage::Greek],
]);

it('holds signup.php pending.waiting to one form at one hour and another from two, in both languages', function () {
    $key = 'signup.pending.waiting';
    $read = 0;

    foreach ([SiteLanguage::English, SiteLanguage::Greek] as $language) {
        $raw = __($key, [], $language->value);
        assert(is_string($raw));
        $cases = array_map(fn (string $part): string => explode(' ', $part, 2)[0], explode('|', $raw));
        $one = pageChoice($key, 1, [], $language);
        $two = pageChoice($key, 2, [], $language);
        $answers = [$one, $two, pageChoice($key, 21, [], $language), pageChoice($key, Lifetime::hours(SignupVerification::LINK_LIFETIME), [], $language)];

        expect($cases)->toBe(['{1}', '[2,*]'], $language->value)
            ->and($one)->not->toBe($two)
            ->and(preg_match('/\d/', $one))->toBe(0)
            ->and($two)->toContain('2')
            ->and($answers[2])->toBe(str_replace('2', '21', $two))
            ->and($answers[3])->toBe(str_replace('2', (string) Lifetime::hours(SignupVerification::LINK_LIFETIME), $two));

        foreach ($answers as $answer) {
            expect($answer)->toContain(':email', ':business', ':address')
                ->and(preg_match('/[{}\[\]|]/', $answer))->toBe(0);
            $read++;
        }
    }

    expect($read)->toBe(8);
});

it('has no signup page on a tenant host', function () {
    provisionTenant('pier');

    $this->get(tenantUrl('pier', '/signup'))->assertNotFound();
    $this->post(tenantUrl('pier', '/signup'), signupForm())->assertNotFound();
});

it('refuses each bad field with its own sentence on the field, read from signup.php, asking the provider nothing and leaving no row', function (string $field, string $value, string $key) {
    $this->from(centralUrl('/signup'))->post(centralUrl('/signup'), signupForm([$field => $value]))
        ->assertRedirect(centralUrl('/signup'))
        ->assertSessionHasErrors([$field => signupSentence('errors.'.$key)]);

    expect(Tenant::query()->count())->toBe(0)
        ->and(User::query()->count())->toBe(0)
        ->and(billingFake()->asked)->toBe([]);
})->with([
    'a malformed slug' => ['slug', 'luna_studio', 'slug.'.SlugProblem::InvalidCharacter->value],
    'a slug with a leading hyphen' => ['slug', '-luna', 'slug.'.SlugProblem::LeadingHyphen->value],
    'a slug with a trailing hyphen' => ['slug', 'luna-', 'slug.'.SlugProblem::TrailingHyphen->value],
    'a slug in the punycode form' => ['slug', 'xn--luna', 'slug.'.SlugProblem::PunycodeForm->value],
    'a slug of 57 characters' => ['slug', str_repeat('a', 57), 'slug.'.SlugProblem::TooLong->value],
    'a reserved slug' => ['slug', 'api', 'slug.'.SlugProblem::Reserved->value],
    'no slug' => ['slug', '', 'slug.'.SlugProblem::Empty->value],
    'an unknown timezone' => ['timezone', 'Mars/Olympus', 'timezone'],
    'a currency that is not three letters' => ['currency', 'EURO', 'currency'],
    'a lower-case currency, never folded' => ['currency', 'eur', 'currency'],
    'an email that is not one' => ['owner_email', 'owner at luna', 'owner_email'],
    'no business name' => ['name', '', 'name'],
    'no owner name' => ['owner_name', '', 'owner_name'],
    'a short password' => ['password', 'short', 'password_short'],
    'no password' => ['password', '', 'password_short'],
    'a password of 73 bytes' => ['password', str_repeat('a', 73), 'password_long'],
    'a password of 37 two-byte letters, 74 bytes' => ['password', str_repeat("\u{03b1}", 37), 'password_long'],
    'no category' => ['category', '', 'category'],
    'a category outside the seven' => ['category', 'spa', 'category'],
]);

it('spells the code\'s constants in the refusals that name one as placeholders, filled in, and words every slug problem with a sentence of its own in signup.php, the console keeping SlugProblem\'s', function () {
    expect(__('signup.errors.slug.'.SlugProblem::TooLong->value, [], 'en'))->toContain(':max')
        ->and(signupSentence('errors.slug.'.SlugProblem::TooLong->value))->toContain((string) Slug::MAX_LENGTH)->not->toContain(':max')
        ->and(__('signup.errors.password_short', [], 'en'))->toContain(':floor')
        ->and(signupSentence('errors.password_short'))->toContain((string) PasswordRule::FLOOR_CHARACTERS)->not->toContain(':floor')
        ->and(__('signup.errors.password_long', [], 'en'))->toContain(':ceiling')
        ->and(signupSentence('errors.password_long'))->toContain((string) PasswordRule::CEILING_BYTES)->not->toContain(':ceiling');

    $sentences = array_map(fn (SlugProblem $problem): string => signupSentence('errors.slug.'.$problem->value), SlugProblem::cases());

    expect(array_unique($sentences))->toHaveCount(count(SlugProblem::cases()));
});

it('takes a password from eight characters to 72 bytes, the one rule\'s floor and ceiling', function (string $password) {
    $this->post(centralUrl('/signup'), signupForm(['password' => $password]))
        ->assertSessionDoesntHaveErrors()
        ->assertRedirect(centralUrl('/signup/luna/pending'));

    expect(Hash::check($password, User::query()->sole()->password))->toBeTrue();
})->with([
    'eight characters' => [str_repeat('a', 8)],
    '72 bytes' => [str_repeat('a', 72)],
    '36 two-byte letters, 72 bytes' => [str_repeat("\u{03b1}", 36)],
]);

it('refuses a slug an active or closing row holds as taken', function (TenantStatus $status) {
    $held = Tenant::query()->create(['slug' => 'luna', 'name' => 'Held', 'timezone' => 'UTC', 'currency' => 'EUR']);
    $held->status = $status;
    $held->save();

    $this->post(centralUrl('/signup'), signupForm(['slug' => 'LUNA']))
        ->assertSessionHasErrors(['slug' => signupSentence('errors.slug.'.SlugProblem::Taken->value)]);

    expect(Tenant::query()->count())->toBe(1)
        ->and(billingFake()->asked)->toBe([]);
})->with([TenantStatus::Active, TenantStatus::Closing]);

it('refuses a slug a signup in progress holds with a sentence of its own, asking the provider nothing', function () {
    $this->post(centralUrl('/signup'), signupForm())->assertRedirect();
    billingFake()->asked = [];

    $this->from(centralUrl('/signup'))->post(centralUrl('/signup'), signupForm(['slug' => 'Luna', 'owner_email' => 'someone@else.example']))
        ->assertRedirect(centralUrl('/signup'))
        ->assertSessionHasErrors(['slug' => signupSentence('errors.slug.'.SlugProblem::HeldBySignup->value)]);

    expect(Tenant::query()->count())->toBe(1)
        ->and(billingFake()->asked)->toBe([]);
});

it('words a held slug beside any other refusal on the form, as a stranger reads it', function () {
    $this->post(centralUrl('/signup'), signupForm())->assertRedirect();

    $this->from(centralUrl('/signup'))->post(centralUrl('/signup'), signupForm(['name' => '']))
        ->assertSessionHasErrors(['slug' => signupSentence('errors.slug.'.SlugProblem::HeldBySignup->value), 'name' => signupSentence('errors.name')]);
});

it('stores the currency exactly as given', function () {
    $this->post(centralUrl('/signup'), signupForm(['currency' => 'USD']))->assertRedirect();

    expect(Tenant::query()->where('slug', 'luna')->value('currency'))->toBe('USD');
});

it('refuses the eleventh signup POST in a minute from one address with the form at 429 and the worded limit, carrying the brand, the language and the strings the limiter answered before the adapter could share', function () {
    for ($i = 0; $i < 10; $i++) {
        $this->post(centralUrl('/signup'), signupForm(['slug' => 'luna_'.$i]))->assertRedirect();
    }

    $this->post(centralUrl('/signup'), signupForm())
        ->assertStatus(429)
        ->assertInertia(fn (Assert $page) => $page->component('signup/Signup')
            ->where('refusal', signupSentence('too_many'))
            ->where('brand', ['name' => siteName()])
            ->where('language', 'en')
            ->where('strings', centralStrings(SiteLanguage::English)));

    expect(Tenant::query()->count())->toBe(0);
});

it('reads the page\'s strings and every refusal from the route\'s language\'s file, lang/el under /el, on the adapter\'s road and on the refusal\'s, and lang/en at the root whatever the locale was before the request', function () {
    signupGreekOverride([
        'form' => ['heading' => 'A heading of the other language'],
        'errors' => ['name' => 'A name refusal of the other language'],
        'expired' => 'An expired link of the other language',
        'too_many' => 'A limit of the other language',
    ]);

    $this->get(centralUrl('/el/signup'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('signup/Signup')
            ->where('language', 'el')
            ->where('strings.form.heading', 'A heading of the other language')
            ->where('strings.form.title', __('signup.form.title', [], 'el'))
            ->missing('strings.errors'));

    $this->from(centralUrl('/el/signup'))->post(centralUrl('/el/signup'), signupForm(['name' => '']))
        ->assertRedirect(centralUrl('/el/signup'))
        ->assertSessionHasErrors(['name' => 'A name refusal of the other language']);

    $this->get(centralUrl('/el/signup/verify/'.str_repeat('ab', 32)))
        ->assertStatus(410)
        ->assertInertia(fn (Assert $page) => $page->component('signup/Signup')
            ->where('language', 'el')
            ->where('refusal', 'An expired link of the other language')
            ->where('strings.form.heading', 'A heading of the other language')
            ->where('brand', ['name' => siteName()]));

    app()->setLocale('el');

    $this->get(centralUrl('/signup'))
        ->assertInertia(fn (Assert $page) => $page->where('language', 'en')->where('strings.form.heading', 'Start your booking page'));
    $this->from(centralUrl('/signup'))->post(centralUrl('/signup'), signupForm(['name' => '']))
        ->assertSessionHasErrors(['name' => 'Enter the name of your business.']);

    for ($i = 0; $i < 8; $i++) {
        $this->post(centralUrl('/el/signup'), signupForm(['slug' => 'luna-'.$i]))->assertRedirect();
    }

    $this->post(centralUrl('/el/signup'), signupForm())
        ->assertStatus(429)
        ->assertInertia(fn (Assert $page) => $page->where('language', 'el')->where('refusal', 'A limit of the other language')->where('strings.form.heading', 'A heading of the other language'));
    $this->post(centralUrl('/signup'), signupForm())
        ->assertStatus(429)
        ->assertInertia(fn (Assert $page) => $page->where('language', 'en')->where('refusal', signupSentence('too_many')));
});

it('marks the browser that signs up with one cookie naming the slug as stored, encrypted, host-only and living the hold\'s 24 hours', function () {
    $this->freezeTime();

    $response = $this->post(centralUrl('/signup'), signupForm(['slug' => 'Luna']))->assertRedirect()->assertCookie(SignupHolder::COOKIE, 'luna');
    $cookie = $response->getCookie(SignupHolder::COOKIE, decrypt: false);

    expect($cookie?->getExpiresTime())->toBe(now()->getTimestamp() + 24 * 60 * 60)
        ->and($cookie?->getDomain())->toBeNull()
        ->and($cookie?->getPath())->toBe('/')
        ->and($cookie?->isHttpOnly())->toBeTrue()
        ->and($cookie?->getValue())->not->toContain('luna');

    $this->post(centralUrl('/signup'), signupForm(['slug' => 'sol', 'owner_email' => 'owner@sol.example']))->assertRedirect()->assertCookie(SignupHolder::COOKIE, 'sol');
});

it('waits on the pending page while the row is provisioning, naming the business to the browser holding the signup alone and re-reading the row on each partial reload', function (Closure $browser, ?string $business) {
    $this->post(centralUrl('/signup'), signupForm());
    $browser($this);

    $this->get(centralUrl('/signup/luna/pending'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('signup/Pending')
            ->where('business', $business)
            ->where('email', $business === null ? null : 'owner@luna.example')
            ->where('verified', false)
            ->where('resend', '/signup/luna/resend')
            ->where('address', app(TenantUrls::class)->origin(Slug::of('luna')))
            ->where('status', 'provisioning'));

    $version = app(HandleCentralInertiaRequests::class)->version(request());
    $partial = fn () => $this->withHeaders([
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) $version,
        'X-Inertia-Partial-Component' => 'signup/Pending',
        'X-Inertia-Partial-Data' => 'status',
        'X-Requested-With' => 'XMLHttpRequest',
    ])->get(centralUrl('/signup/luna/pending'));

    $partial()->assertOk()->assertJsonPath('props.status', 'provisioning');

    DB::table('tenants')->where('slug', 'luna')->update(['status' => 'active']);

    $partial()->assertStatus(409)->assertHeader('X-Inertia-Location', tenantUrl('luna', '/admin/login'));
})->with([
    'the holder, its cookie naming the slug' => [fn (TestCase $test) => $test->withCookie(SignupHolder::COOKIE, 'luna'), 'Luna Studio'],
    'a browser with no cookie' => [fn () => null, null],
    'a browser holding another signup' => [fn (TestCase $test) => $test->withCookie(SignupHolder::COOKIE, 'sol'), null],
    'a cookie naming the slug the framework did not encrypt' => [fn (TestCase $test) => $test->withUnencryptedCookie(SignupHolder::COOKIE, 'luna'), null],
]);

it('sends a plain visit to the subdomain sign-in page once the row is active, whatever the return URL carries', function () {
    $this->post(centralUrl('/signup'), signupForm());

    $this->get(centralUrl('/signup/luna/pending?status=active&session_id=cs_1'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('signup/Pending')->where('status', 'provisioning'));

    DB::table('tenants')->where('slug', 'luna')->update(['status' => 'active']);

    $this->get(centralUrl('/signup/luna/pending?status=provisioning'))->assertRedirect(tenantUrl('luna', '/admin/login'));
});

it('answers 404 on the pending page for a slug no signup holds', function (string $slug) {
    $held = Tenant::query()->create(['slug' => 'shut', 'name' => 'Shut', 'timezone' => 'UTC', 'currency' => 'EUR']);
    $held->status = TenantStatus::Closing;
    $held->save();

    $this->get(centralUrl('/signup/'.$slug.'/pending'))->assertNotFound();
})->with(['nobody', 'api', 'shut', 'bad_slug']);

it('declares one access check on each central route and nothing else, the signup\'s six in both languages, each with its locale and the POSTs under the one limit', function () {
    $this->get(centralUrl('/signup'));

    $names = ['signup', 'signup.store', 'signup.pending', 'signup.resend', 'signup.verify', 'signup.verify.malformed'];
    $declared = collect([...$names, ...array_map(fn (string $name): string => 'el.'.$name, $names), 'webhooks.billing'])
        ->mapWithKeys(function (string $name): array {
            $route = Route::getRoutes()->getByName($name);
            assert($route !== null);

            return [$name => array_values(array_filter($route->gatherMiddleware(), fn (mixed $middleware): bool => is_string($middleware) && ! in_array($middleware, ['web', HandleCentralInertiaRequests::class], true)))];
        })
        ->all();

    $expected = ['webhooks.billing' => [RequiresBillingSignature::NAME]];

    foreach (['en', 'el'] as $language) {
        $prefix = $language === 'en' ? '' : 'el.';
        $locale = SetSiteLocale::NAME.':'.$language;
        $expected[$prefix.'signup'] = [PublicRoute::NAME, $locale];
        $expected[$prefix.'signup.store'] = [PublicRoute::NAME, $locale, 'throttle:signup'];
        $expected[$prefix.'signup.pending'] = [PublicRoute::NAME, $locale];
        $expected[$prefix.'signup.resend'] = [PublicRoute::NAME, $locale, 'throttle:signup'];
        $expected[$prefix.'signup.verify'] = [PublicRoute::NAME, $locale];
        $expected[$prefix.'signup.verify.malformed'] = [PublicRoute::NAME, $locale];
    }

    ksort($declared);
    ksort($expected);

    expect($declared)->toBe($expected)
        ->and(Route::getRoutes()->getByName('el.signup')?->uri())->toBe('el/signup')
        ->and(Route::getRoutes()->getByName('el.signup.verify')?->wheres)->toBe(['token' => SignupVerifyController::TOKEN])
        ->and(Route::getRoutes()->getByName('el.signup.verify.malformed')?->uri())->toBe('el/signup/verify/{malformed}');
});

/*
 * The acceptance of the terms: the box on the form, its refusal on its field, and the two
 * columns the reservation alone writes.
 */

/**
 * The row's two acceptance columns as text, the instant in UTC to the second, both null where
 * nothing was recorded.
 *
 * @return array{terms_accepted_at: string|null, terms_version: string|null}
 */
function termsColumns(string $slug): array
{
    $row = DB::selectOne(
        "select to_char(terms_accepted_at at time zone 'UTC', 'YYYY-MM-DD HH24:MI:SS') as terms_accepted_at, terms_version::text as terms_version from tenants where slug = ?",
        [$slug],
    );

    assert($row instanceof stdClass);
    $at = $row->terms_accepted_at;
    $version = $row->terms_version;
    assert(($at === null || is_string($at)) && ($version === null || is_string($version)));

    return ['terms_accepted_at' => $at, 'terms_version' => $version];
}

it('refuses a POST whose terms box is {case} on the box\'s field with signup.php\'s sentence, writing nothing and sending nothing', function (array $form) {
    $seam = new RecordingPlatformMessages;
    app()->instance(PlatformMessenger::class, $seam);

    $this->from(centralUrl('/signup'))->post(centralUrl('/signup'), $form)
        ->assertRedirect(centralUrl('/signup'))
        ->assertSessionHasErrors([SignupRequest::TERMS => signupSentence('errors.terms')]);

    expect(Tenant::query()->count())->toBe(0)
        ->and(User::query()->count())->toBe(0)
        ->and($seam->sent)->toBe([]);
})->with([
    'absent' => [fn (): array => array_diff_key(signupForm(), [SignupRequest::TERMS => true])],
    'empty' => [fn (): array => signupForm([SignupRequest::TERMS => ''])],
    'no' => [fn (): array => signupForm([SignupRequest::TERMS => 'no'])],
    'zero' => [fn (): array => signupForm([SignupRequest::TERMS => '0'])],
    'off' => [fn (): array => signupForm([SignupRequest::TERMS => 'off'])],
    'false' => [fn (): array => signupForm([SignupRequest::TERMS => 'false'])],
    'an array' => [fn (): array => [...signupForm(), SignupRequest::TERMS => ['1']]],
]);

it('words the box\'s refusal beside any other refusal on the form', function () {
    $this->from(centralUrl('/signup'))->post(centralUrl('/signup'), signupForm(['name' => '', SignupRequest::TERMS => '']))
        ->assertSessionHasErrors(['name' => signupSentence('errors.name'), SignupRequest::TERMS => signupSentence('errors.terms')]);
});

it('takes the box ticked as {value}, one of the accepted rule\'s set', function (string $value) {
    $this->post(centralUrl('/signup'), signupForm([SignupRequest::TERMS => $value]))
        ->assertSessionDoesntHaveErrors()
        ->assertRedirect(centralUrl('/signup/luna/pending'));
})->with(['1', 'on', 'yes', 'true']);

it('writes the acceptance on the reserved row: the instant the clock\'s, as the row\'s created_at, and the version the day the terms file names', function () {
    app()->instance(Clock::class, FixedClock::at('2026-11-10 09:00:00'));

    $this->post(centralUrl('/signup'), signupForm())->assertRedirect(centralUrl('/signup/luna/pending'));

    expect(termsColumns('luna'))->toBe(['terms_accepted_at' => '2026-11-10 09:00:00', 'terms_version' => app(TermsVersion::class)->day()])
        ->and(DB::scalar('select terms_accepted_at = created_at from tenants where slug = ?', ['luna']))->toBeTrue();
});

it('hands the form the two legal pages\' addresses in its language, on the form, at 429 and at 410', function () {
    $legal = fn (SiteLanguage $language): array => [
        'terms' => app(SitePages::class)->url(SitePages::TERMS, $language),
        'privacy' => app(SitePages::class)->url(SitePages::PRIVACY, $language),
    ];

    expect($legal(SiteLanguage::English))->toBe(['terms' => centralUrl('/terms'), 'privacy' => centralUrl('/privacy')])
        ->and($legal(SiteLanguage::Greek))->toBe(['terms' => centralUrl('/el/terms'), 'privacy' => centralUrl('/el/privacy')]);

    $this->get(centralUrl('/signup'))->assertInertia(fn (Assert $page) => $page->where('legal', $legal(SiteLanguage::English)));
    $this->get(centralUrl('/el/signup'))->assertInertia(fn (Assert $page) => $page->where('legal', $legal(SiteLanguage::Greek)));
    $this->get(centralUrl('/el/signup/verify/'.str_repeat('ab', 32)))
        ->assertStatus(410)
        ->assertInertia(fn (Assert $page) => $page->where('legal', $legal(SiteLanguage::Greek)));

    for ($i = 0; $i < 10; $i++) {
        $this->post(centralUrl('/signup'), signupForm(['slug' => 'luna_'.$i]))->assertRedirect();
    }

    $this->post(centralUrl('/signup'), signupForm())
        ->assertStatus(429)
        ->assertInertia(fn (Assert $page) => $page->where('legal', $legal(SiteLanguage::English)));
});

it('holds the box\'s sentence in each language to exactly the two tag pairs, the terms\' and the policy\'s, once each and well formed in either order, with nothing else read as a tag', function () {
    $walked = 0;

    foreach (SiteLanguage::cases() as $language) {
        $sentence = __('signup.form.terms', [], $language->value);

        assert(is_string($sentence));
        preg_match_all('/<\/?([a-z]+)>/', $sentence, $tags);

        expect($tags[0])->toBeIn([
            ['<terms>', '</terms>', '<privacy>', '</privacy>'],
            ['<privacy>', '</privacy>', '<terms>', '</terms>'],
        ], $language->value)
            ->and(substr_count($sentence, '<'))->toBe(4)
            ->and(substr_count($sentence, '>'))->toBe(4)
            ->and(preg_match('/<terms>[^<]+<\/terms>/', $sentence))->toBe(1)
            ->and(preg_match('/<privacy>[^<]+<\/privacy>/', $sentence))->toBe(1);
        $walked++;
    }

    expect($walked)->toBe(count(SiteLanguage::cases()));
});

it('holds the two acceptance columns set together or null together by tenants_terms_check: {case}', function (array $columns, bool $admitted) {
    $insert = fn () => DB::table('tenants')->insert([
        'slug' => 'acme', 'name' => 'Acme', 'schema_name' => 'tenant_acme', 'timezone' => 'Europe/Athens', 'currency' => 'EUR', ...$columns,
    ]);

    if ($admitted) {
        $insert();
        expect(DB::table('tenants')->where('slug', 'acme')->count())->toBe(1);
    } else {
        expect($insert)->toThrow(QueryException::class, 'tenants_terms_check');
    }
})->with([
    'both set' => [fn (): array => ['terms_accepted_at' => DB::raw('now()'), 'terms_version' => DB::raw('current_date')], true],
    'both null' => [['terms_accepted_at' => null, 'terms_version' => null], true],
    'the instant alone' => [fn (): array => ['terms_accepted_at' => DB::raw('now()')], false],
    'the version alone' => [fn (): array => ['terms_version' => DB::raw('current_date')], false],
]);

it('leaves both columns null on the rows the signup form did not write: {case}', function (Closure $write, string $slug) {
    $write($this);

    expect(termsColumns($slug))->toBe(['terms_accepted_at' => null, 'terms_version' => null]);
})->with([
    'tenant:provision' => [fn () => Artisan::call('tenant:provision', [
        'slug' => 'acme', '--name' => 'Acme Salon', '--timezone' => 'Europe/Athens', '--currency' => 'EUR',
        '--owner-name' => 'Alex Owner', '--owner-email' => 'owner@acme.example', '--owner-password' => 'correct horse battery staple',
    ]), 'acme'],
    'the provisioner\'s console path, which the free tenant form takes' => [fn () => provisionTenant('acme'), 'acme'],
]);
