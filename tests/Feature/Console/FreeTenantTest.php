<?php

use App\Application\Checkpoint;
use App\Application\Clock;
use App\Application\Notification\PlatformMessageKind;
use App\Application\Notification\PlatformMessenger;
use App\Billing\Entitlements;
use App\Http\ConsoleFeatures;
use App\Http\ConsoleSession;
use App\Http\Controllers\Admin\LoginController;
use App\Http\Controllers\Admin\SetPasswordController;
use App\Http\Controllers\Console\NewTenantController;
use App\Http\Requests\NewTenantRequest;
use App\Http\Requests\SettingsRequest;
use App\Http\Requests\TenantFeaturesRequest;
use App\Platform\FreeTenantRefused;
use App\Platform\FreeTenants;
use App\Platform\NewFreeTenant;
use App\Platform\Operator;
use App\Platform\PasswordLinks;
use App\Site\SiteLanguage;
use App\Tenancy\BusinessCategory;
use App\Tenancy\Slug;
use App\Tenancy\SlugProblem;
use App\Tenancy\TenantProvisioner;
use App\Tenancy\TenantUrls;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FixedClock;
use Tests\Support\LevelledPlatformMessages;
use Tests\Support\SeamLog;
use Tests\TestCase;

pest()->use(DatabaseTruncation::class);

/*
 * The free tenant: the one exception to the no-trial rule, granted by the operator alone. GET
 * /tenants/new is the form and POST /tenants builds the tenant through the provisioner's console
 * path, as tenant:provision does: active once its schema is built, the subscription state active,
 * the chosen set on the row, no subscriptions row and no trial end, the owner written with a
 * random secret of 32 bytes as its password that nobody is shown. Then tenant_created is recorded
 * with the features, and the set-password link of 3.4 is minted beside it and mailed to the owner
 * after the commit, so the owner gets in through the link and no other way.
 *
 * The form judges the slug itself through SlugRules, as the signup does, because the console path
 * is idempotent for a retried tenant:provision and would hand back a taken row rather than refuse
 * it; the build refuses a suspended or closing row the provisioner meets in a race, and any row
 * the create did not make as taken, an active one, another create's build in flight and a row
 * another run wrote before the create's insert, proven on the service directly and, for the last,
 * through the form's POST as well.
 *
 * "After the commit" is read off a recorder that notes the transaction level each message was
 * handed over at, so the file truncates rather than wrapping each test in a transaction, and drops
 * every tenant schema a test built. The clock is pinned, so the link's expiry is spelled here.
 *
 * The form's Language choice, is written to tenants.locale and the set-password mail follows it;
 * the Greek case lays Greek lines over the loaded lang/el/mail.php, as TrialNoticeTest does, to
 * tell the test's lines from the file's whatever the file holds.
 */

beforeEach(function () {
    $this->withoutVite();
    app()->instance(Clock::class, FixedClock::at('2026-10-15 09:00'));
    $this->seam = app(LevelledPlatformMessages::class);
    app()->instance(PlatformMessenger::class, $this->seam);
});

afterEach(function () {
    Checkpoint::observe(null);

    foreach (DB::table('tenants')->pluck('schema_name') as $schema) {
        DB::statement(sprintf('drop schema if exists "%s" cascade', is_string($schema) ? $schema : ''));
    }

    DB::table('platform_audit_log')->delete();
    DB::table('operators')->delete();
    DB::table('subscriptions')->delete();
    DB::table('users')->delete();
    DB::table('tenants')->delete();
});

/**
 * The form's fields as an operator fills them, one overridable at a time; a null drops the field.
 *
 * @param  array<mixed>  $overrides
 * @return array<mixed>
 */
function freeTenantForm(array $overrides = []): array
{
    return array_filter([
        'slug' => 'acme',
        'name' => 'Acme Barbers',
        'timezone' => 'Europe/Lisbon',
        'currency' => 'GBP',
        'category' => 'barber',
        'owner_name' => 'Alex Owner',
        'owner_email' => 'alex@acme.example',
        'locale' => 'en',
        'features' => ['staff'],
        ...$overrides,
    ], fn (mixed $value): bool => $value !== null);
}

/**
 * The console's POST of the form, as the operator given sends it from GET /tenants/new.
 *
 * @param  array<mixed>  $form
 * @return TestResponse<Response>
 */
function freeTenantPost(TestCase $test, Operator $operator, array $form): TestResponse
{
    return $test->actingAs($operator, ConsoleSession::GUARD)
        ->withSession(freshConsoleSession())
        ->from(consoleUrl('/tenants/new'))
        ->post(consoleUrl('/tenants'), $form);
}

/**
 * Every platform audit row as its operator, action, tenant and payload, the payload decoded.
 *
 * @return list<array<mixed>>
 */
function freeTenantAudit(): array
{
    $rows = [];

    foreach (DB::select('select operator_id, action, tenant_id, payload::text as payload from platform_audit_log order by id') as $row) {
        $row = (array) $row;
        $rows[] = [...$row, 'payload' => json_decode(is_string($row['payload'] ?? null) ? $row['payload'] : '', true, 512, JSON_THROW_ON_ERROR)];
    }

    return $rows;
}

/**
 * The tenant row's columns the free shape is read from, every instant as text.
 *
 * @return array<mixed>
 */
function freeTenantRow(string $slug = 'acme'): array
{
    return (array) DB::selectOne(
        'select slug, name, schema_name, timezone, currency, category, locale, status, subscription_state, entitlements, narrowed, plan_ref,'
        .' trial_ends_at::text as trial_ends_at, grace_ends_at::text as grace_ends_at, closing_at::text as closing_at, suspended_at::text as suspended_at,'
        .' terms_accepted_at::text as terms_accepted_at, terms_version::text as terms_version'
        .' from tenants where slug = ?',
        [$slug],
    );
}

/**
 * The clear token a message's link carries, the empty string where it carries none.
 */
function freeTenantToken(string $body): string
{
    return preg_match('~/admin/set-password/([0-9a-f]{64})~', $body, $match) === 1 ? $match[1] : '';
}

/**
 * The schemas the database holds that a tenant's name could take, in name order.
 *
 * @return list<string>
 */
function freeTenantSchemas(): array
{
    /** @var list<string> */
    return DB::table('information_schema.schemata')->where('schema_name', 'like', 'tenant\_%')->orderBy('schema_name')->pluck('schema_name')->all();
}

/**
 * Whether the schema a slug's tenant would be built in exists, the one this test's own create
 * would make, whatever schemas the database holds besides.
 */
function freeTenantSchemaExists(string $slug = 'acme'): bool
{
    return DB::table('information_schema.schemata')->where('schema_name', 'tenant_'.$slug)->exists();
}

/**
 * Makes another run take the slug at the instant the console path's claim has judged it free and
 * not yet written its row: an ordinary tenant:provision of the slug, built whole, whose row the
 * claim's insert then meets in the unique index.
 */
function freeTenantRacedBy(string $slug): void
{
    Checkpoint::observe(function (string $name) use ($slug): void {
        if ($name !== TenantProvisioner::CLAIMING) {
            return;
        }

        Checkpoint::observe(null);
        provisionTenant($slug, ['name' => 'Other Studio', 'ownerEmail' => 'owner@other.example']);
    });
}

/**
 * What the console's form hands the service for a tenant, as NewTenantRequest reads it.
 *
 * @param  list<string>  $features
 */
function freeTenantDetails(string $slug = 'acme', array $features = ['staff'], SiteLanguage $locale = SiteLanguage::English): NewFreeTenant
{
    return new NewFreeTenant($slug, 'Acme Barbers', new DateTimeZone('Europe/Lisbon'), 'GBP', BusinessCategory::Barber->value, 'Alex Owner', 'alex@acme.example', Entitlements::fromSlugs($features), $locale);
}

it('provisions a free tenant: active on the chosen set, state active, no subscriptions row and no trial end, the schema built with the implicit member, tenant_created recorded with the features, and the owner\'s link mailed after the commit', function () {
    $operator = addOperator();

    freeTenantPost($this, $operator, freeTenantForm())
        ->assertRedirect(consoleUrl('/tenants/acme'))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('flash', ['kind' => 'success', 'message' => sprintf(NewTenantController::CREATED, 'alex@acme.example')]);

    $tenantId = DB::table('tenants')->where('slug', 'acme')->value('id');
    $owner = (array) DB::selectOne('select id, name, email, role, email_verified_at, password from users where tenant_id = ?', [$tenantId]);
    $message = $this->seam->message(0);
    $token = freeTenantToken($message->body);

    expect(freeTenantRow())->toBe([
        'slug' => 'acme',
        'name' => 'Acme Barbers',
        'schema_name' => 'tenant_acme',
        'timezone' => 'Europe/Lisbon',
        'currency' => 'GBP',
        'category' => 'barber',
        'locale' => 'en',
        'status' => 'active',
        'subscription_state' => 'active',
        'entitlements' => '{staff}',
        'narrowed' => '{}',
        'plan_ref' => null,
        'trial_ends_at' => null,
        'grace_ends_at' => null,
        'closing_at' => null,
        'suspended_at' => null,
        'terms_accepted_at' => null,
        'terms_version' => null,
    ])
        ->and(DB::table('subscriptions')->where('tenant_id', $tenantId)->count())->toBe(0)
        ->and(DB::table('tenant_acme.staff')->get(['name', 'status', 'is_implicit'])->map(fn (object $row): array => (array) $row)->all())->toBe([['name' => 'Acme Barbers', 'status' => 'active', 'is_implicit' => true]])
        ->and(DB::table('tenant_acme.settings')->count())->toBe(1)
        ->and(DB::table('tenant_acme.shift_patterns')->count())->toBe(1)
        ->and(DB::table('users')->where('tenant_id', $tenantId)->count())->toBe(1)
        ->and(array_diff_key($owner, ['id' => true, 'password' => true]))->toBe(['name' => 'Alex Owner', 'email' => 'alex@acme.example', 'role' => 'owner', 'email_verified_at' => null])
        ->and(freeTenantAudit())->toBe([
            ['operator_id' => $operator->id, 'action' => 'tenant_created', 'tenant_id' => $tenantId, 'payload' => ['features' => ['staff']]],
        ])
        ->and($this->seam->lines())->toBe(['alex@acme.example set_password 0'])
        ->and($message->kind)->toBe(PlatformMessageKind::SetPassword)
        ->and($message->tenantId)->toBe($tenantId)
        ->and($message->toName)->toBe('Alex Owner')
        ->and($message->subject)->toBe(siteSentence('mail.set_password.subject', SiteLanguage::English))
        ->and($message->body)->toContain(tenantUrl('acme', '/admin/set-password/'.$token))
        ->and($message->body)->toContain('Acme Barbers')
        ->and($message->body)->toContain("\n\n".mailChoice('mail.set_password.window_first', PasswordLinks::hours())."\n\n")
        ->and($message->body)->not->toContain(mailChoice('mail.set_password.window', PasswordLinks::hours()))
        ->and($message->html)->toContain(mailChoice('mail.set_password.window_first', PasswordLinks::hours()))
        ->and($message->html)->not->toContain(mailChoice('mail.set_password.window', PasswordLinks::hours()))
        ->and(mailChoice('mail.set_password.window_first', PasswordLinks::hours()))->toContain((string) PasswordLinks::hours())
        ->and($message->html)->toContain('<html lang="en">')
        ->and((array) DB::selectOne("select password_link_hash as hash, to_char(password_link_expires_at at time zone 'UTC', 'YYYY-MM-DD HH24:MI:SS') as expires_at from users where tenant_id = ?", [$tenantId]))
        ->toBe(['hash' => hash('sha256', $token), 'expires_at' => '2026-10-18 09:00:00']);
});

it('writes the language the form chose to the row: a Greek tenant\'s set-password mail follows it, lang/el\'s words through the translator in both parts, the one mail the creation sends and no welcome', function () {
    // Greek lines are laid over the loaded lang/el/mail.php to tell the test's lines from the
    // file's whatever the file holds.
    __('mail.greeting', [], 'el');
    app('translator')->addLines([
        'mail.set_password.subject' => 'GREEK SET PASSWORD',
        'mail.set_password.choose' => 'GREEK :business AT :address',
        'mail.set_password.window' => 'GREEK RESET WINDOW',
        'mail.set_password.window_first' => 'GREEK FIRST WINDOW',
    ], 'el');

    freeTenantPost($this, addOperator(), freeTenantForm(['locale' => 'el']))->assertRedirect(consoleUrl('/tenants/acme'));

    $message = $this->seam->message(0);
    $token = freeTenantToken($message->body);

    expect(freeTenantRow())->toMatchArray(['locale' => 'el', 'status' => 'active', 'subscription_state' => 'active'])
        ->and($this->seam->lines())->toBe(['alex@acme.example set_password 0'])
        ->and($message->kind)->toBe(PlatformMessageKind::SetPassword)
        ->and($message->subject)->toBe('GREEK SET PASSWORD')
        ->and($message->body)->toContain("\n\nGREEK Acme Barbers AT ".tenantUrl('acme', '/admin/login')."\n\n".tenantUrl('acme', '/admin/set-password/'.$token)."\n\nGREEK FIRST WINDOW\n\n")
        ->and($message->body)->not->toContain('GREEK RESET WINDOW')
        ->and($message->html)->toContain('<html lang="el">')
        ->and($message->html)->toContain('<title>GREEK SET PASSWORD</title>')
        ->and($message->html)->toContain('GREEK Acme Barbers AT '.tenantUrl('acme', '/admin/login'))
        ->and(array_map(fn (array $sent): string => $sent['message']->kind->value, $this->seam->sent))->not->toContain('welcome');
});

it('writes the floor alone where no feature is checked, the audit\'s features the empty list, and the business with no category where none is chosen', function (array $overrides) {
    $operator = addOperator();

    freeTenantPost($this, $operator, freeTenantForm($overrides))->assertRedirect(consoleUrl('/tenants/acme'));

    expect(freeTenantRow())->toMatchArray(['entitlements' => '{}', 'category' => null, 'status' => 'active', 'subscription_state' => 'active'])
        ->and(array_column(freeTenantAudit(), 'payload'))->toBe([['features' => []]]);
})->with([
    'the features an empty list and the category empty' => [['features' => [], 'category' => '']],
    'neither field sent' => [['features' => null, 'category' => null]],
]);

it('writes the owner\'s password as a hash of a secret nobody is shown: the sign-in refuses the empty string and any guess, and the link alone lets the owner in', function () {
    freeTenantPost($this, addOperator(), freeTenantForm())->assertRedirect();
    $hash = DB::table('users')->where('email', 'alex@acme.example')->value('password');
    $token = freeTenantToken($this->seam->bodyTo('alex@acme.example'));
    restoreWebGuard();

    expect(is_string($hash) ? password_get_info($hash)['algoName'] : null)->toBe('bcrypt')
        ->and(is_string($hash) && Hash::check('', $hash))->toBeFalse();

    // The door refuses the empty string before it asks for the pair, and every guess as a pair.
    $this->post(tenantUrl('acme', '/admin/login'), ['email' => 'alex@acme.example', 'password' => ''])
        ->assertSessionHasErrors('password');
    $this->assertGuest('web');

    foreach (['password', 'Alex Owner', str_repeat('0', 64), $token] as $guess) {
        $this->post(tenantUrl('acme', '/admin/login'), ['email' => 'alex@acme.example', 'password' => $guess])
            ->assertSessionHasErrors(['email' => adminLine(LoginController::REFUSED)]);
        $this->assertGuest('web');
    }

    $this->get(tenantUrl('acme', '/admin/set-password/'.$token))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('auth/SetPassword'));
    $this->post(tenantUrl('acme', '/admin/set-password/'.$token), ['password' => 'the owner chose this'])
        ->assertRedirect(tenantUrl('acme', '/admin/login'))
        ->assertSessionHas('flash', ['kind' => 'success', 'message' => adminLine(SetPasswordController::SET)]);
    $this->post(tenantUrl('acme', '/admin/login'), ['email' => 'alex@acme.example', 'password' => 'the owner chose this'])
        ->assertRedirect(tenantUrl('acme', '/admin'));
});

it('keeps the link\'s token out of every answer, flash, session, log line, audit row and queue, the hash alone kept', function () {
    $log = new SeamLog;
    Log::swap($log);
    $operator = addOperator();

    $posted = freeTenantPost($this, $operator, freeTenantForm());
    $token = freeTenantToken($this->seam->bodyTo('alex@acme.example'));
    $detail = $this->actingAs($operator, ConsoleSession::GUARD)->get(consoleUrl('/tenants/acme'));

    expect($token)->not->toBe('');

    foreach ([$posted, $detail] as $answer) {
        expect((string) $answer->getContent())->not->toContain($token)
            ->and((string) $answer->headers)->not->toContain($token);
    }

    $rows = json_encode([DB::table('users')->get(), DB::table('platform_audit_log')->get(), DB::table('tenants')->get()], JSON_THROW_ON_ERROR);

    expect($rows)->not->toContain($token)
        ->and($rows)->toContain(hash('sha256', $token))
        ->and(json_encode(session()->all(), JSON_THROW_ON_ERROR))->not->toContain($token)
        ->and($log->text())->not->toContain($token)
        ->and(DB::table('jobs')->count())->toBe(0);
});

it('refuses a slug {case} on the form with its sentence, and builds nothing, records nothing and sends nothing', function (Closure $arrange, string $slug, string $sentence) {
    $arrange();
    $tenants = DB::table('tenants')->orderBy('id')->get()->all();
    $schemas = freeTenantSchemas();

    freeTenantPost($this, addOperator(), freeTenantForm(['slug' => $slug]))
        ->assertRedirect(consoleUrl('/tenants/new'))
        ->assertSessionHasErrors(['slug' => $sentence]);

    expect(DB::table('tenants')->orderBy('id')->get()->all())->toEqual($tenants)
        ->and(freeTenantSchemas())->toBe($schemas)
        ->and(DB::table('users')->where('email', 'alex@acme.example')->count())->toBe(0)
        ->and(freeTenantAudit())->toBe([])
        ->and($this->seam->sent)->toBe([]);
})->with([
    'held by a signup in progress' => [fn () => app(TenantProvisioner::class)->reserve(signupFor('held', ['ownerEmail' => 'signup@held.example'])), 'held', SlugProblem::HeldBySignup->detail()],
    'reserved' => [fn () => null, 'support', SlugProblem::Reserved->detail()],
    'reserved for the form\'s own path' => [fn () => null, 'new', SlugProblem::Reserved->detail()],
    'taken by an active tenant' => [fn () => provisionTenant('taken', ['ownerEmail' => 'owner@taken.example']), 'taken', SlugProblem::Taken->detail()],
    'taken whatever its case' => [fn () => provisionTenant('taken', ['ownerEmail' => 'owner@taken.example']), 'TAKEN', SlugProblem::Taken->detail()],
    'of a suspended tenant' => [fn () => tap(provisionTenant('paused', ['ownerEmail' => 'owner@paused.example']), fn () => DB::table('tenants')->where('slug', 'paused')->update(['status' => 'suspended', 'suspended_at' => '2026-10-14 09:00:00+00', 'suspension_reason' => 'Under review.'])), 'paused', SlugProblem::Taken->detail()],
    'of a closing tenant' => [fn () => tap(provisionTenant('ending', ['ownerEmail' => 'owner@ending.example']), fn () => DB::table('tenants')->where('slug', 'ending')->update(['status' => 'closing', 'closing_at' => '2026-10-14 09:00:00+00'])), 'ending', SlugProblem::Taken->detail()],
    'of the wrong shape' => [fn () => null, '-acme', SlugProblem::LeadingHyphen->detail()],
    'left empty' => [fn () => null, '', SlugProblem::Empty->detail()],
]);

it('refuses {case} on its field with its sentence, and builds nothing', function (array $overrides, string $field, string $sentence) {
    freeTenantPost($this, addOperator(), freeTenantForm($overrides))
        ->assertRedirect(consoleUrl('/tenants/new'))
        ->assertSessionHasErrors([$field => $sentence]);

    expect(DB::table('tenants')->count())->toBe(0)
        ->and(freeTenantSchemaExists())->toBeFalse()
        ->and(freeTenantAudit())->toBe([])
        ->and($this->seam->sent)->toBe([]);
})->with([
    'a missing name' => [['name' => ''], 'name', NewTenantRequest::NO_NAME],
    'a timezone off the list' => [['timezone' => 'Mars/Olympus'], 'timezone', NewTenantRequest::BAD_TIMEZONE],
    'a currency in lower case' => [['currency' => 'eur'], 'currency', fn (): string => __(SettingsRequest::CURRENCY, [], 'en')],
    'a category off the list' => [['category' => 'florist'], 'category', NewTenantRequest::BAD_CATEGORY],
    'a missing owner name' => [['owner_name' => ''], 'owner_name', NewTenantRequest::NO_OWNER_NAME],
    'an owner email that is no address' => [['owner_email' => 'alex at acme'], 'owner_email', NewTenantRequest::BAD_EMAIL],
    'a language outside the two' => [['locale' => 'de'], 'locale', NewTenantRequest::BAD_LOCALE],
    'no language' => [['locale' => null], 'locale', NewTenantRequest::BAD_LOCALE],
    'a feature outside the cases' => [['features' => ['staff', 'gold']], 'features', TenantFeaturesRequest::BAD_FEATURES],
    'features that are no list' => [['features' => 'staff'], 'features', TenantFeaturesRequest::BAD_FEATURES],
]);

it('refuses on the service a slug whose row turned {case} between the form\'s check and the build, with the provisioner\'s sentence, and builds nothing, records nothing and sends nothing', function (Closure $arrange, string $slug, Closure $sentence) {
    $arrange();
    $before = DB::table('tenants')->orderBy('id')->get()->all();
    $users = DB::table('users')->orderBy('id')->get()->all();
    $schemas = freeTenantSchemas();

    $refused = null;

    try {
        app(FreeTenants::class)->create(freeTenantDetails($slug), addOperator());
    } catch (FreeTenantRefused $caught) {
        $refused = $caught;
    }

    expect($refused?->getMessage())->toBe($sentence())
        ->and(DB::table('tenants')->orderBy('id')->get()->all())->toEqual($before)
        ->and(DB::table('users')->orderBy('id')->get()->all())->toEqual($users)
        ->and(freeTenantSchemas())->toBe($schemas)
        ->and(freeTenantAudit())->toBe([])
        ->and($this->seam->sent)->toBe([]);
})->with([
    'suspended' => [
        fn () => tap(provisionTenant('paused', ['ownerEmail' => 'owner@paused.example']), fn () => DB::table('tenants')->where('slug', 'paused')->update(['status' => 'suspended', 'suspended_at' => '2026-10-14 09:00:00+00', 'suspension_reason' => 'Under review.'])),
        'paused',
        fn (): string => 'Tenant "paused" is suspended and cannot be provisioned.',
    ],
    'closing' => [
        fn () => tap(provisionTenant('ending', ['ownerEmail' => 'owner@ending.example']), fn () => DB::table('tenants')->where('slug', 'ending')->update(['status' => 'closing', 'closing_at' => '2026-10-14 09:00:00+00'])),
        'ending',
        fn (): string => 'Tenant "ending" is closing and cannot be provisioned.',
    ],
    'held by a signup in progress' => [
        fn () => app(TenantProvisioner::class)->reserve(signupFor('held', ['ownerEmail' => 'signup@held.example'])),
        'held',
        fn (): string => 'Tenant "held" is held by a signup in progress and cannot be provisioned from the console.',
    ],
    'active, taken by another tenant, which the console path would hand back rather than refuse' => [
        fn () => provisionTenant('taken', ['ownerEmail' => 'owner@taken.example']),
        'taken',
        fn (): string => SlugProblem::Taken->detail(),
    ],
    'provisioning with no owner yet, another create\'s build in flight, which the build leaves unbuilt rather than finish' => [
        fn () => tap(app(TenantProvisioner::class)->reserve(signupFor('inflight', ['ownerEmail' => 'owner@inflight.example'])), fn ($tenant) => DB::table('users')->where('tenant_id', $tenant->id)->delete()),
        'inflight',
        fn (): string => SlugProblem::Taken->detail(),
    ],
    'reserved, which the rules refuse at the build as at the form' => [
        fn () => null,
        'support',
        fn (): string => SlugProblem::Reserved->detail(),
    ],
]);

it('refuses on the service a slug another run wrote between the rules\' check and the insert, the unique index answered as the slug taken, and builds, records and sends nothing of its own', function () {
    freeTenantRacedBy('acme');

    $refused = null;

    try {
        app(FreeTenants::class)->create(freeTenantDetails(), addOperator());
    } catch (FreeTenantRefused $caught) {
        $refused = $caught;
    }

    expect($refused?->getMessage())->toBe(SlugProblem::Taken->detail())
        ->and(DB::table('tenants')->pluck('name')->all())->toBe(['Other Studio'])
        ->and(DB::table('users')->pluck('email')->all())->toBe(['owner@other.example'])
        ->and(freeTenantAudit())->toBe([])
        ->and($this->seam->sent)->toBe([]);
});

it('answers the build\'s refusal on the slug\'s field through the errors bag, a row another run wrote between the form\'s check and the build, nothing of its own built, recorded or sent', function () {
    freeTenantRacedBy('acme');

    freeTenantPost($this, addOperator(), freeTenantForm())
        ->assertRedirect(consoleUrl('/tenants/new'))
        ->assertSessionHasErrors(['slug' => SlugProblem::Taken->detail()])
        ->assertSessionMissing('flash');

    expect(DB::table('tenants')->pluck('name')->all())->toBe(['Other Studio'])
        ->and(DB::table('users')->where('email', 'alex@acme.example')->exists())->toBeFalse()
        ->and(freeTenantAudit())->toBe([])
        ->and($this->seam->sent)->toBe([]);
});

it('renders the form inside the console\'s shell with the kinds of business, the timezones, the central host and one choice per feature, the rail\'s Tenants lit', function () {
    $this->actingAs(addOperator(), ConsoleSession::GUARD)->withSession(freshConsoleSession())
        ->get(consoleUrl('/tenants/new'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('console/NewTenant')
            ->where('categories', array_map(fn (BusinessCategory $category): array => ['value' => $category->value, 'label' => $category->label()], BusinessCategory::cases()))
            ->where('timezones', DateTimeZone::listIdentifiers())
            ->where('centralHost', app(TenantUrls::class)->centralHost())
            ->where('features', ConsoleFeatures::choices())
            ->where('nav', [
                ['label' => 'Tenants', 'url' => '/', 'group' => 'Platform', 'pending' => null, 'current' => true],
                ['label' => 'Messages', 'url' => '/messages', 'group' => 'Platform', 'pending' => null, 'current' => false],
                ['label' => 'Audit', 'url' => '/audit', 'group' => 'Platform', 'pending' => null, 'current' => false],
            ]));

    expect(ConsoleFeatures::choices())->toBe([['value' => 'staff', 'label' => 'Staff', 'help' => ConsoleFeatures::STAFF_HELP]]);
});

it('sends a visitor with no operator session to the door from the form and from its POST, and builds nothing', function () {
    $this->get(consoleUrl('/tenants/new'))->assertRedirect(consoleUrl('/login'));
    $this->post(consoleUrl('/tenants'), freeTenantForm())->assertRedirect(consoleUrl('/login'));

    expect(DB::table('tenants')->count())->toBe(0)
        ->and(freeTenantSchemaExists())->toBeFalse()
        ->and($this->seam->sent)->toBe([]);
});

it('names the slug the row was given, lower-cased, whatever case the operator typed', function () {
    freeTenantPost($this, addOperator(), freeTenantForm(['slug' => 'Acme']))->assertRedirect(consoleUrl('/tenants/acme'));

    expect(Slug::of('Acme')->value)->toBe('acme')
        ->and(freeTenantRow()['slug'] ?? null)->toBe('acme');
});
