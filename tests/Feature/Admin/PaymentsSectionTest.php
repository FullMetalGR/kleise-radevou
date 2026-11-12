<?php

use App\Application\Clock;
use App\Application\Payment\ConnectionCheck;
use App\Application\Payment\ConnectionMode;
use App\Application\Payment\ConnectionState;
use App\Application\Payment\CredentialField;
use App\Application\Payment\Credentials;
use App\Application\Payment\NewConnection;
use App\Application\Payment\NullPaymentProvider;
use App\Application\Payment\ProviderDescriptor;
use App\Application\Payment\ProviderRegistry;
use App\Application\Payment\Stripe\StripeAdapter;
use App\Application\Payment\Stripe\StripePaymentProvider;
use App\Application\Payment\Stripe\StripeTransport;
use App\Application\Payment\TenantPaymentProvider;
use App\Application\Payment\TestConnection;
use App\Console\Commands\ExportLangCommand;
use App\Http\Controllers\Admin\PaymentsController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\ErrorEnvelope;
use App\Http\Middleware\RequiresActiveSubscription;
use App\Http\Requests\PaymentCredentialsRequest;
use App\Infrastructure\Payment\LoggedPayments;
use App\Infrastructure\Payment\PaymentRoutes;
use App\Infrastructure\PaymentFactory;
use App\Models\User;
use App\Site\SiteLanguage;
use App\Tenancy\Tenant;
use App\Tenancy\TenantUrls;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FakePaymentAdapter;
use Tests\Support\FakePaymentProvider;
use Tests\Support\FakeStripePaymentTransport;
use Tests\Support\FixedClock;
use Tests\TestCase;

pest()->use(DatabaseTruncation::class);

/*
 * The Payments section's backend: the Settings page's payments prop, built from the registry
 * and luna's row alone, and the six writes under /admin/settings/payments, through the
 * routes, on the fake provider and on Stripe's adapter over its fake transport, with every
 * stray request refused.
 *
 * Truncated rather than wrapped: the racing save is played by a second connection, which sees
 * only what is committed. The clock stands pinned, so a landed check's instant is a literal.
 */

const PAYMENTS_NOW = '2026-11-02 06:00:00';

const PAYMENTS_STRIPE = ['secret_key' => 'sk_test_luna_51Hq7secret', 'webhook_secret' => 'whsec_luna_9b2signing'];

/** The key of the label Stripe's descriptor names for its webhook signing secret. */
const PAYMENTS_STRIPE_SIGNING_LABEL = StripeAdapter::FIELDS.StripeAdapter::WEBHOOK_SECRET.'.label';

beforeEach(function () {
    $this->withoutVite();
    dropDemoSchemas();
    seedDemo();
    app()->instance(Clock::class, FixedClock::at(PAYMENTS_NOW));
    Http::preventStrayRequests();
});

afterEach(function () {
    Http::assertNothingSent();
    DB::purge('racer');
    dropDemoSchemas();
    DB::table('users')->delete();
    DB::table('tenants')->delete();
});

function paymentsOwner(): User
{
    return User::query()->where('email', 'owner@luna.example')->sole();
}

/**
 * One of the section's writes as the page sends it, from the Settings screen.
 *
 * @param  array<array-key, mixed>  $body
 * @return TestResponse<Response>
 */
function paymentsWrite(TestCase $test, string $method, string $path, array $body = [], ?User $user = null): TestResponse
{
    return $test->actingAs($user ?? paymentsOwner())->from(tenantUrl('luna', '/admin/settings'))
        ->json($method, tenantUrl('luna', '/admin/settings/payments'.$path), $body, ['X-Inertia' => 'true']);
}

/**
 * The save's body for the fake, its credentials overridden or left out where given.
 *
 * @param  array<string, string|null>  $credentials
 * @return array<string, mixed>
 */
function paymentsSave(array $credentials = [], string $provider = FakePaymentAdapter::SLUG): array
{
    $fields = array_filter([...paymentFakeCredentials(), ...$credentials], fn (?string $value): bool => $value !== null);

    return ['provider' => $provider, 'credentials' => $fields];
}

/**
 * The Settings page as the owner opens it, whole.
 *
 * @return array<string, mixed>
 */
function paymentsPage(TestCase $test): array
{
    $page = $test->actingAs(paymentsOwner())->get(tenantUrl('luna', '/admin/settings'))->assertOk()->viewData('page');

    assert(is_array($page) && ($page['component'] ?? null) === SettingsController::PAGE);

    /** @var array<string, mixed> $page */
    return $page;
}

/**
 * The page's payments prop.
 *
 * @return array<string, mixed>
 */
function paymentsProp(TestCase $test): array
{
    $props = paymentsPage($test)['props'];

    assert(is_array($props) && is_array($props['payments']));

    /** @var array<string, mixed> */
    return $props['payments'];
}

/**
 * The prop's connection, which the case expects to be there.
 *
 * @return array<string, mixed>
 */
function paymentsConnection(TestCase $test): array
{
    $connection = paymentsProp($test)['connection'];

    assert(is_array($connection));

    /** @var array<string, mixed> */
    return $connection;
}

/**
 * luna's stored connection row, read past the store.
 *
 * @return array<array-key, mixed>
 */
function paymentsRow(): array
{
    return (array) DB::table('tenant_luna.payment_connections')
        ->selectRaw('provider, mode, state, requirements::text as requirements, last_check_ok, webhook_token, credentials')
        ->sole();
}

/**
 * luna's stored requirements decoded, null where none, its top-level keys in alphabetical order,
 * since jsonb keeps an object's keys in an order of its own.
 *
 * @return array<array-key, mixed>|null
 */
function paymentsRowRequirements(): ?array
{
    $stored = paymentsRow()['requirements'];

    if (! is_string($stored)) {
        return null;
    }

    $decoded = (array) json_decode($stored, true, 512, JSON_THROW_ON_ERROR);
    ksort($decoded);

    return $decoded;
}

/**
 * The row's requirements a failed check of ours records: the en line of its key filled with its
 * values, which is the English the check records, beside the key and the values (the proof that
 * the two copies agree).
 *
 * @param  array<string, string>  $values
 * @return array{key: string, reason: string, values: array<string, string>}
 */
function paymentsReasonRow(string $key, array $values = []): array
{
    return ['key' => $key, 'reason' => adminLine($key, $values), 'values' => $values];
}

function paymentsTenantProvider(): string
{
    $current = app(PaymentFactory::class)->provider(Tenant::query()->where('slug', 'luna')->sole());

    assert($current instanceof TenantPaymentProvider);

    return $current->current()::class;
}

/**
 * Stripe's adapter rebuilt over a fake transport answering what the case scripts, its key the
 * one the case saves.
 */
function paymentsStripe(): FakeStripePaymentTransport
{
    $transport = new FakeStripePaymentTransport(PAYMENTS_STRIPE['secret_key']);
    app()->instance(StripeTransport::class, $transport);
    app()->forgetInstance(StripeAdapter::class);
    app()->forgetInstance(ProviderRegistry::class);

    return $transport;
}

it('renders both descriptors from the registry, each with its published rate, platform mode not offered while configuration holds no fee, and no connection, asking no provider', function () {
    $transport = paymentsStripe();
    $payments = paymentsProp($this);

    expect($payments)->toBe([
        'providers' => [
            [
                'slug' => 'stripe',
                'name' => 'Stripe',
                'rate_url' => 'https://stripe.com/pricing',
                'modes' => [['mode' => 'platform', 'offered' => false, 'takes_fee' => true, 'fee' => null], ['mode' => 'own', 'offered' => true, 'takes_fee' => false, 'fee' => null]],
                'fields' => array_map(fn ($field): array => ['name' => $field->name, 'label' => adminLine($field->label), 'help' => adminLine($field->help), 'secret' => $field->secret], app(StripeAdapter::class)->descriptor()->credentialFields),
            ],
            [
                'slug' => FakePaymentAdapter::SLUG,
                'name' => 'Paydesk',
                'rate_url' => 'https://paydesk.test/pricing',
                'modes' => [['mode' => 'platform', 'offered' => false, 'takes_fee' => true, 'fee' => null], ['mode' => 'own', 'offered' => true, 'takes_fee' => false, 'fee' => null]],
                'fields' => [
                    ['name' => 'merchant_code', 'label' => 'Merchant code', 'help' => 'The code Paydesk gave your business.', 'secret' => false],
                    ['name' => 'api_token', 'label' => 'API token', 'help' => 'The token Paydesk calls are made with.', 'secret' => true],
                    ['name' => 'signing_phrase', 'label' => 'Signing phrase', 'help' => 'The phrase Paydesk signs its notifications with.', 'secret' => true],
                ],
            ],
        ],
        'connection' => null,
    ])
        ->and(array_map(fn ($field): array => [$field->name, $field->secret], app(StripeAdapter::class)->descriptor()->credentialFields))->toBe([['secret_key', true], ['webhook_secret', true]])
        ->and($transport->sent)->toBe([])
        ->and(paymentFake()->asked)->toBe([]);
});

it('offers platform mode once configuration holds a fee and the platform\'s keys for the provider, carrying the fee as configured in the tenant\'s own currency', function (array $configured, ?array $fee) {
    config(['payments.platform_fee' => $configured, 'payments.platform.'.FakePaymentAdapter::SLUG => FakePaymentAdapter::PLATFORM_KEYS]);

    $providers = paymentsProp($this)['providers'];

    assert(is_array($providers) && is_array($providers[0] ?? null) && is_array($providers[0]['modes'] ?? null) && is_array($providers[1] ?? null));

    expect($providers[1]['modes'])->toBe([['mode' => 'platform', 'offered' => true, 'takes_fee' => true, 'fee' => $fee], ['mode' => 'own', 'offered' => true, 'takes_fee' => false, 'fee' => null]])
        ->and($providers[0]['modes'][0] ?? null)->toBe(['mode' => 'platform', 'offered' => false, 'takes_fee' => true, 'fee' => null]);
})->with([
    'a percentage' => [['percent' => '1.5', 'fixed' => null], ['percent' => '1.5', 'fixed_minor' => null, 'currency' => 'EUR']],
    'a fixed amount' => [['percent' => null, 'fixed' => 'JPY:30,EUR:25'], ['percent' => null, 'fixed_minor' => 25, 'currency' => 'EUR']],
    'both' => [['percent' => '2.50', 'fixed' => 'EUR:25'], ['percent' => '2.5', 'fixed_minor' => 25, 'currency' => 'EUR']],
    'a fixed amount in another currency alone' => [['fixed' => 'GBP:20'], ['percent' => null, 'fixed_minor' => null, 'currency' => 'EUR']],
    'zero' => [['percent' => '0'], ['percent' => '0', 'fixed_minor' => null, 'currency' => 'EUR']],
]);

it('reads a stored connection from the row alone: provider, mode, state, the check and its instant, requirements verbatim, a mark per stored secret and the webhook URL with its token, asking no provider', function () {
    $transport = paymentsStripe();
    connectPaymentFake(ConnectionState::Restricted, false);
    DB::table('tenant_luna.payment_connections')->update(['requirements' => '{"reason": "Paydesk refused the token.", "codes": ["token"]}']);

    expect(paymentsConnection($this))->toBe([
        'provider' => FakePaymentAdapter::SLUG,
        'name' => 'Paydesk',
        'mode' => 'own',
        'state' => 'restricted',
        'last_checked_at' => '2026-11-02T06:00:00+00:00',
        'last_check_ok' => false,
        'requirements' => ['codes' => ['token'], 'reason' => 'Paydesk refused the token.'],
        'information_due' => false,
        'fee' => null,
        'stored_secrets' => ['api_token', 'signing_phrase'],
        'webhook_url' => app(TenantUrls::class)->central('/webhooks/'.FakePaymentAdapter::SLUG.'/hook-token-luna'),
    ])
        ->and(paymentsConnection($this)['webhook_url'])->toBe(centralUrl('/webhooks/paydesk/hook-token-luna'))
        ->and($transport->sent)->toBe([])
        ->and(paymentFake()->asked)->toBe([]);
});

it('marks no secret and names no webhook URL for a platform connection, which holds neither', function () {
    paymentConnections()->write(new NewConnection(FakePaymentAdapter::SLUG, ConnectionMode::Platform, ConnectionState::Pending, null, 'acct_luna_7'));
    $connection = paymentsConnection($this);

    expect([$connection['mode'], $connection['state'], $connection['stored_secrets'], $connection['webhook_url'], $connection['last_checked_at'], $connection['last_check_ok']])
        ->toBe(['platform', 'pending', [], null, null, null]);
});

it('saves own-mode credentials, runs the one check while the tenant\'s provider is still the null one, and lands active on a pass', function () {
    $during = null;
    paymentFake()->whileChecked = function () use (&$during): void {
        $during = [paymentsTenantProvider(), DB::table('tenant_luna.payment_connections')->value('state')];
    };

    paymentsWrite($this, 'POST', '', paymentsSave())->assertRedirect(tenantUrl('luna', '/admin/settings'))
        ->assertSessionHas('flash', ['kind' => 'success', 'message' => adminLine(PaymentsController::SAVED)]);
    $row = paymentsRow();

    assert(is_string($row['webhook_token']));

    expect($during)->toBe([NullPaymentProvider::class, 'restricted'])
        ->and([$row['provider'], $row['mode'], $row['state'], $row['requirements'], $row['last_check_ok']])->toBe([FakePaymentAdapter::SLUG, 'own', 'active', null, true])
        ->and(is_string($row['credentials']) ? json_decode(Crypt::decryptString($row['credentials']), true) : null)->toBe(paymentFakeCredentials())
        ->and($row['webhook_token'])->toMatch('/\A[A-Za-z0-9_-]{32}\z/')
        ->and(DB::table('payment_routes')->pluck('webhook_token')->all())->toBe([$row['webhook_token']])
        ->and(paymentsTenantProvider())->toBe(FakePaymentProvider::class)
        ->and(paymentsConnection($this))->toMatchArray([
            'state' => 'active', 'last_checked_at' => '2026-11-02T06:00:00+00:00', 'last_check_ok' => true, 'requirements' => null,
            'stored_secrets' => ['api_token', 'signing_phrase'], 'webhook_url' => centralUrl('/webhooks/paydesk/'.$row['webhook_token']),
        ]);
});

it('keeps a failed save saved and restricted, the provider\'s reason struck of every declared credential value in requirements, the merchant code too, the tenant on the null provider, and Test connection lands it active once the check passes', function () {
    paymentFake()->checkFailsWith = 'Paydesk refused the token pdk_token_9f3c1e77a2b4 for merchant MERCHANT-LUNA-0042.';

    paymentsWrite($this, 'POST', '', paymentsSave())->assertRedirect(tenantUrl('luna', '/admin/settings'))
        ->assertSessionHas('flash', ['kind' => 'error', 'message' => adminLine(PaymentsController::SAVED_UNCHECKED)]);

    expect(paymentsRow()['state'])->toBe('restricted')
        ->and(paymentsTenantProvider())->toBe(NullPaymentProvider::class)
        ->and(paymentsConnection($this))->toMatchArray([
            'state' => 'restricted', 'last_check_ok' => false, 'last_checked_at' => '2026-11-02T06:00:00+00:00',
            'requirements' => ['reason' => 'Paydesk refused the token '.TestConnection::STRUCK.' for merchant '.TestConnection::STRUCK.'.'],
        ]);

    paymentFake()->checkFailsWith = null;
    app()->instance(Clock::class, FixedClock::at('2026-11-02 06:05:00'));

    paymentsWrite($this, 'POST', '/test')->assertRedirect(tenantUrl('luna', '/admin/settings'))
        ->assertSessionHas('flash', ['kind' => 'success', 'message' => adminLine(PaymentsController::TEST_PASSED)]);

    expect(paymentsConnection($this))->toMatchArray(['state' => 'active', 'last_check_ok' => true, 'last_checked_at' => '2026-11-02T06:05:00+00:00', 'requirements' => null])
        ->and(paymentsTenantProvider())->toBe(FakePaymentProvider::class);
});

it('lands a check that throws against its contract as a failed check naming the class alone, never what it said, and logs it once at warning with the class and the message struck of every declared credential value', function () {
    paymentFake()->checkThrows = new RuntimeException('The client failed holding pdk_token_9f3c1e77a2b4 for MERCHANT-LUNA-0042.');
    $logged = [];
    Log::listen(function (MessageLogged $message) use (&$logged): void {
        $logged[] = [$message->level, $message->message, $message->context];
    });

    paymentsWrite($this, 'POST', '', paymentsSave())->assertRedirect(tenantUrl('luna', '/admin/settings'))
        ->assertSessionHas('flash', ['kind' => 'error', 'message' => adminLine(PaymentsController::SAVED_UNCHECKED)]);

    $row = paymentsReasonRow(TestConnection::REASON_UNEXPECTED, ['class' => RuntimeException::class]);

    expect(paymentsConnection($this))->toMatchArray(['state' => 'restricted', 'last_check_ok' => false, 'requirements' => ['reason' => $row['reason']]])
        ->and(paymentsRowRequirements())->toBe($row)
        ->and(array_values(array_filter($logged, fn (array $line): bool => $line[1] === LoggedPayments::CHECK_THREW)))->toBe([[
            'warning', LoggedPayments::CHECK_THREW,
            ['provider' => FakePaymentAdapter::SLUG, 'exception' => RuntimeException::class, 'message' => 'The client failed holding '.TestConnection::STRUCK.' for '.TestConnection::STRUCK.'.'],
        ]])
        ->and((string) json_encode($logged))->not->toContain('pdk_token_9f3c1e77a2b4')->not->toContain('MERCHANT-LUNA-0042');
});

it('names an anonymous class a check throws by its debug type, so the row holds no NUL byte and no path and the prop words the line', function () {
    paymentFake()->checkThrows = new class('Unexpected.') extends RuntimeException {};

    paymentsWrite($this, 'POST', '', paymentsSave())->assertRedirect(tenantUrl('luna', '/admin/settings'));

    $class = RuntimeException::class.'@anonymous';
    $row = paymentsRowRequirements();

    expect($row)->toBe(paymentsReasonRow(TestConnection::REASON_UNEXPECTED, ['class' => $class]))
        ->and(data_get($row, 'reason'))->toBeString()->not->toContain("\0")->not->toContain('/')
        ->and(data_get($row, 'values.class'))->toBeString()->not->toContain("\0")->not->toContain('/')
        ->and(paymentsConnection($this)['requirements'])->toBe(['reason' => adminLine(TestConnection::REASON_UNEXPECTED, ['class' => $class])]);
});

it('strikes a short non-secret value where it stands as a whole word and leaves the reason readable around it, and a secret wherever it occurs, in the stored reason and in the warning line alike', function (string $merchant, string $said, string $struck) {
    $logged = [];
    Log::listen(function (MessageLogged $message) use (&$logged): void {
        $logged[] = [$message->level, $message->message, $message->context];
    });
    paymentFake()->checkFailsWith = $said;

    paymentsWrite($this, 'POST', '', paymentsSave(['merchant_code' => $merchant]))->assertRedirect(tenantUrl('luna', '/admin/settings'))
        ->assertSessionHas('flash', ['kind' => 'error', 'message' => adminLine(PaymentsController::SAVED_UNCHECKED)]);

    expect(paymentsConnection($this))->toMatchArray(['state' => 'restricted', 'last_check_ok' => false, 'requirements' => ['reason' => $struck]]);

    paymentFake()->checkFailsWith = null;
    paymentFake()->checkThrows = new RuntimeException($said);

    paymentsWrite($this, 'POST', '/test')->assertRedirect(tenantUrl('luna', '/admin/settings'))
        ->assertSessionHas('flash', ['kind' => 'error', 'message' => adminLine(PaymentsController::TEST_FAILED)]);

    expect(array_values(array_filter($logged, fn (array $line): bool => $line[1] === LoggedPayments::CHECK_THREW)))
        ->toBe([['warning', LoggedPayments::CHECK_THREW, ['provider' => FakePaymentAdapter::SLUG, 'exception' => RuntimeException::class, 'message' => $struck]]])
        ->and((string) json_encode($logged))->not->toContain('pdk_token_9f3c1e77a2b4');
})->with([
    'a merchant code of one digit, inside a time and an error code' => [
        '1',
        'Paydesk refused merchant 1 at 10:41 (error 401); the token pdk_token_9f3c1e77a2b4 and pdk_token_9f3c1e77a2b4x are both unknown.',
        'Paydesk refused merchant '.TestConnection::STRUCK.' at 10:41 (error 401); the token '.TestConnection::STRUCK.' and '.TestConnection::STRUCK.'x are both unknown.',
    ],
    'a merchant code of two letters, inside a word and before a hyphen' => [
        'eu',
        'Paydesk refused merchant eu in europe (region eu-west-1).',
        'Paydesk refused merchant '.TestConnection::STRUCK.' in europe (region '.TestConnection::STRUCK.'-west-1).',
    ],
]);

/*
 * The stored reason alone, no warning line: the log is a file of this checkout, and every file
 * outside lang/el stays ASCII, so no Greek is written to it. The Greek is spelled as escapes for
 * the same reason.
 */
it('strikes a non-secret where it stands as a whole word in any script, two Greek capitals left alone inside a Greek word', function () {
    paymentFake()->checkFailsWith = "Paydesk refused merchant \u{0391}\u{0398} in \u{0391}\u{0398}\u{0397}\u{039D}\u{0391}.";

    paymentsWrite($this, 'POST', '', paymentsSave(['merchant_code' => "\u{0391}\u{0398}"]))->assertRedirect(tenantUrl('luna', '/admin/settings'))
        ->assertSessionHas('flash', ['kind' => 'error', 'message' => adminLine(PaymentsController::SAVED_UNCHECKED)]);

    expect(paymentsConnection($this))->toMatchArray(['state' => 'restricted', 'requirements' => ['reason' => 'Paydesk refused merchant '.TestConnection::STRUCK." in \u{0391}\u{0398}\u{0397}\u{039D}\u{0391}."]]);
});

it('strikes the whole reason where the strike cannot run, a provider\'s answer that is not UTF-8, so nothing of it is readable in the stored reason or the warning line', function () {
    $logged = [];
    Log::listen(function (MessageLogged $message) use (&$logged): void {
        $logged[] = [$message->level, $message->message, $message->context];
    });
    $said = "Paydesk refused the token pdk_token_9f3c1e77a2b4 for merchant MERCHANT-LUNA-0042 \xff\xfe.";
    paymentFake()->checkFailsWith = $said;

    paymentsWrite($this, 'POST', '', paymentsSave())->assertRedirect(tenantUrl('luna', '/admin/settings'))
        ->assertSessionHas('flash', ['kind' => 'error', 'message' => adminLine(PaymentsController::SAVED_UNCHECKED)]);

    expect(paymentsConnection($this))->toMatchArray(['state' => 'restricted', 'last_check_ok' => false, 'requirements' => ['reason' => TestConnection::STRUCK]]);

    paymentFake()->checkFailsWith = null;
    paymentFake()->checkThrows = new RuntimeException($said);

    paymentsWrite($this, 'POST', '/test')->assertRedirect(tenantUrl('luna', '/admin/settings'))
        ->assertSessionHas('flash', ['kind' => 'error', 'message' => adminLine(PaymentsController::TEST_FAILED)]);

    expect(array_values(array_filter($logged, fn (array $line): bool => $line[1] === LoggedPayments::CHECK_THREW)))
        ->toBe([['warning', LoggedPayments::CHECK_THREW, ['provider' => FakePaymentAdapter::SLUG, 'exception' => RuntimeException::class, 'message' => TestConnection::STRUCK]]]);
});

it('refuses a body the registry or the descriptor does not admit with the first field\'s sentence at 400, and writes nothing', function (array $body, string $sentence) {
    paymentsWrite($this, 'POST', '', $body)->assertStatus(400)
        ->assertJsonPath('code', 'invalid_request')
        ->assertJsonPath('data.detail', $sentence)
        ->assertJsonMissingPath('errors');

    expect(DB::table('tenant_luna.payment_connections')->count())->toBe(0)
        ->and(DB::table('payment_routes')->count())->toBe(0)
        ->and(paymentFake()->asked)->toBe([]);
})->with([
    'no provider' => [['credentials' => paymentFakeCredentials()], fn (): string => adminLine(PaymentCredentialsRequest::NO_PROVIDER)],
    'a provider the registry does not hold' => [paymentsSave([], 'paypal'), fn (): string => adminLine(PaymentCredentialsRequest::NOT_OWN)],
    'the first missing field' => [paymentsSave(['merchant_code' => null, 'signing_phrase' => null]), fn (): string => adminLine(PaymentCredentialsRequest::MISSING, ['field' => 'Merchant code'])],
    'a blank secret' => [paymentsSave(['api_token' => '']), fn (): string => adminLine(PaymentCredentialsRequest::MISSING, ['field' => 'API token'])],
    'a secret that is no string' => [['provider' => FakePaymentAdapter::SLUG, 'credentials' => [...paymentFakeCredentials(), 'signing_phrase' => ['nested']]], fn (): string => adminLine(PaymentCredentialsRequest::MISSING, ['field' => 'Signing phrase'])],
    'a secret longer than any issued' => [paymentsSave(['api_token' => str_repeat('k', PaymentCredentialsRequest::LONGEST + 1)]), fn (): string => adminLine(PaymentCredentialsRequest::TOO_LONG, ['field' => 'API token'])],
    'Stripe without its signing secret' => [['provider' => 'stripe', 'credentials' => ['secret_key' => 'sk_test_x']], fn (): string => adminLine(PaymentCredentialsRequest::MISSING, ['field' => adminLine(PAYMENTS_STRIPE_SIGNING_LABEL)])],
]);

/*
 * A browser sends a form as UTF-8, so only a crafted request carries a value that is not; the
 * sealed object is JSON and the strike reads UTF-8, so such a value is refused at the boundary
 * rather than met by either. The JSON helper cannot carry the bytes, so the case posts a form.
 */
it('refuses a credential value that is not readable text with the field\'s sentence at 400, writes nothing and asks nobody, never a 500', function () {
    $this->actingAs(paymentsOwner())->from(tenantUrl('luna', '/admin/settings'))
        ->post(tenantUrl('luna', '/admin/settings/payments'), paymentsSave(['merchant_code' => "MERCHANT-\xff\xfe-0042"]), ['X-Inertia' => 'true'])
        ->assertStatus(400)
        ->assertJsonPath('code', 'invalid_request')
        ->assertJsonPath('data.detail', adminLine(PaymentCredentialsRequest::UNREADABLE, ['field' => 'Merchant code']))
        ->assertJsonMissingPath('errors');

    expect(DB::table('tenant_luna.payment_connections')->count())->toBe(0)
        ->and(DB::table('payment_routes')->count())->toBe(0)
        ->and(paymentFake()->asked)->toBe([]);
});

it('words the refusals of a provider the registry does not hold, a credential past its longest and one that is not readable text in the user\'s language, a field named by its label in that language', function (SiteLanguage $language) {
    User::query()->where('email', 'owner@luna.example')->update(['locale' => $language->value]);
    $secret = ['field' => adminLine(StripeAdapter::FIELDS.StripeAdapter::SECRET_KEY.'.label', [], $language)];
    $stripe = paymentsStripe();

    paymentsWrite($this, 'POST', '', paymentsSave([], 'paypal'))
        ->assertStatus(400)->assertJsonPath('data.detail', adminLine(PaymentCredentialsRequest::NOT_OWN, [], $language));
    paymentsWrite($this, 'POST', '', ['provider' => 'stripe', 'credentials' => ['secret_key' => str_repeat('k', PaymentCredentialsRequest::LONGEST + 1), 'webhook_secret' => 'whsec_x']])
        ->assertStatus(400)->assertJsonPath('data.detail', adminLine(PaymentCredentialsRequest::TOO_LONG, $secret, $language));
    $this->actingAs(paymentsOwner())->from(tenantUrl('luna', '/admin/settings'))
        ->post(tenantUrl('luna', '/admin/settings/payments'), ['provider' => 'stripe', 'credentials' => ['secret_key' => "sk_test_\xff\xfe", 'webhook_secret' => 'whsec_x']], ['X-Inertia' => 'true'])
        ->assertStatus(400)->assertJsonPath('data.detail', adminLine(PaymentCredentialsRequest::UNREADABLE, $secret, $language));

    expect(DB::table('tenant_luna.payment_connections')->count())->toBe(0)
        ->and($stripe->sent)->toBe([]);
})->with(['a user at en' => [SiteLanguage::English], 'a user at el' => [SiteLanguage::Greek]]);

it('stores the declared fields alone, dropping one the descriptor does not declare', function () {
    paymentsWrite($this, 'POST', '', ['provider' => FakePaymentAdapter::SLUG, 'credentials' => [...paymentFakeCredentials(), 'extra' => 'not-declared']])->assertRedirect();
    $credentials = paymentsRow()['credentials'];

    expect(is_string($credentials) ? json_decode(Crypt::decryptString($credentials), true) : null)->toBe(paymentFakeCredentials());
});

it('keeps the webhook token when the credentials of the provider already connected are replaced, and mints a new one for another provider', function () {
    $transport = paymentsStripe()->answer('GET', '/v1/checkout/sessions', 200, ['object' => 'list', 'data' => []]);
    connectPaymentFake();

    paymentsWrite($this, 'POST', '', paymentsSave(['api_token' => 'pdk_token_replaced']))->assertRedirect();
    expect(paymentsRow()['webhook_token'])->toBe('hook-token-luna');

    paymentsWrite($this, 'POST', '', ['provider' => 'stripe', 'credentials' => PAYMENTS_STRIPE])->assertRedirect();
    $token = paymentsRow()['webhook_token'];

    expect($token)->not->toBe('hook-token-luna')
        ->and(DB::table('payment_routes')->get(['provider', 'webhook_token'])->map(fn ($row): array => (array) $row)->all())->toBe([['provider' => 'stripe', 'webhook_token' => $token]])
        ->and(app(PaymentRoutes::class)->byToken(FakePaymentAdapter::SLUG, 'hook-token-luna'))->toBeNull()
        ->and(count($transport->sent))->toBe(1);
});

it('saves Stripe credentials through the registry and lands what its one live check answers, passed, failed with the reason struck of the key, and unreachable', function (?int $status, array $body, string $state, ?array $row) {
    $transport = paymentsStripe();
    $status === null ? $transport->unreachable('GET', '/v1/checkout/sessions') : $transport->answer('GET', '/v1/checkout/sessions', $status, $body);

    paymentsWrite($this, 'POST', '', ['provider' => 'stripe', 'credentials' => PAYMENTS_STRIPE])->assertRedirect(tenantUrl('luna', '/admin/settings'));
    $connection = paymentsConnection($this);

    expect($transport->sent)->toHaveCount(1)
        ->and($transport->sent[0]['keyMatched'] ?? null)->toBeTrue()
        ->and([$connection['provider'], $connection['state'], $connection['last_check_ok'], $connection['requirements'], $connection['stored_secrets']])
        ->toBe(['stripe', $state, $state === 'active', $row === null ? null : ['reason' => $row['reason']], ['secret_key', 'webhook_secret']])
        ->and(paymentsRowRequirements())->toBe($row)
        ->and(paymentsTenantProvider())->toBe($state === 'active' ? StripePaymentProvider::class : NullPaymentProvider::class);
})->with([
    'passed' => [200, ['object' => 'list', 'data' => []], 'active', null],
    'failed' => [401, ['error' => ['message' => 'Invalid API Key provided: '.PAYMENTS_STRIPE['secret_key'], 'type' => 'invalid_request_error']], 'restricted', fn (): array => paymentsReasonRow(StripeAdapter::REASON_MESSAGE, ['reason' => 'Invalid API Key provided: [secret]'])],
    'unreachable' => [null, [], 'restricted', fn (): array => paymentsReasonRow(StripeAdapter::REASON_UNREACHABLE)],
]);

it('tests the stored connection with one live call and lands passed, failed and unreachable, the render after it asking Stripe nothing', function (?int $status, array $body, string $state, string $flash, ?array $row) {
    $transport = paymentsStripe();
    paymentConnections()->write(new NewConnection('stripe', ConnectionMode::Own, ConnectionState::Active, PAYMENTS_STRIPE, null, 'hook-token-stripe'));
    $status === null ? $transport->unreachable('GET', '/v1/checkout/sessions') : $transport->answer('GET', '/v1/checkout/sessions', $status, $body);

    paymentsWrite($this, 'POST', '/test')->assertRedirect(tenantUrl('luna', '/admin/settings'))->assertSessionHas('flash.message', $flash);

    expect(paymentsConnection($this))->toMatchArray(['state' => $state, 'last_check_ok' => $state === 'active', 'last_checked_at' => '2026-11-02T06:00:00+00:00', 'requirements' => $row === null ? null : ['reason' => $row['reason']]])
        ->and(paymentsRowRequirements())->toBe($row)
        ->and($transport->sent)->toHaveCount(1);
})->with([
    'passed' => [200, ['object' => 'list', 'data' => []], 'active', fn (): string => adminLine(PaymentsController::TEST_PASSED), null],
    'failed' => [403, ['error' => ['message' => 'The key '.PAYMENTS_STRIPE['secret_key'].' lacks the permission rak_checkout_session_read.']], 'restricted', fn (): string => adminLine(PaymentsController::TEST_FAILED), fn (): array => paymentsReasonRow(StripeAdapter::REASON_MESSAGE, ['reason' => 'The key [secret] lacks the permission rak_checkout_session_read.'])],
    'unreachable' => [null, [], 'restricted', fn (): string => adminLine(PaymentsController::TEST_FAILED), fn (): array => paymentsReasonRow(StripeAdapter::REASON_UNREACHABLE)],
]);

it('records a check on a disabled connection and leaves it disabled, the switch being the tenant\'s alone', function (?string $fails, ?bool $ok) {
    connectPaymentFake(ConnectionState::Disabled, null);
    paymentFake()->checkFailsWith = $fails;

    paymentsWrite($this, 'POST', '/test')->assertRedirect();

    expect(paymentsConnection($this))->toMatchArray(['state' => 'disabled', 'last_check_ok' => $ok, 'requirements' => $fails === null ? null : ['reason' => $fails]]);
})->with([
    'passed' => [null, true],
    'failed' => ['Refused.', false],
]);

it('rotates the webhook URL: the row\'s token and its routing row change together, the credentials, state and check stay, and the old token names no tenant', function () {
    connectPaymentFake(ConnectionState::Active, true);
    $before = paymentsRow();
    $url = paymentsConnection($this)['webhook_url'];

    paymentsWrite($this, 'POST', '/rotate')->assertRedirect(tenantUrl('luna', '/admin/settings'))
        ->assertSessionHas('flash', ['kind' => 'success', 'message' => adminLine(PaymentsController::ROTATED)]);
    $after = paymentsRow();
    $token = $after['webhook_token'];

    assert(is_string($token));

    expect($token)->not->toBe('hook-token-luna')
        ->and($token)->toMatch('/\A[A-Za-z0-9_-]{32}\z/')
        ->and(array_diff_key($after, ['webhook_token' => true]))->toBe(array_diff_key($before, ['webhook_token' => true]))
        ->and(DB::table('payment_routes')->pluck('webhook_token')->all())->toBe([$token])
        ->and(app(PaymentRoutes::class)->byToken(FakePaymentAdapter::SLUG, 'hook-token-luna'))->toBeNull()
        ->and(app(PaymentRoutes::class)->byToken(FakePaymentAdapter::SLUG, $token))->toBe(Tenant::query()->where('slug', 'luna')->sole()->id)
        ->and(paymentsConnection($this)['webhook_url'])->not->toBe($url)
        ->and(paymentsConnection($this)['webhook_url'])->toBe(centralUrl('/webhooks/paydesk/'.$token))
        ->and(paymentFake()->asked)->toBe([]);
});

it('switches the connection off, the tenant\'s provider becoming the null one, and back on to active or restricted by the stored check', function (?bool $checkOk, string $state, string $provider) {
    connectPaymentFake(ConnectionState::Active, $checkOk);

    paymentsWrite($this, 'POST', '/disable')->assertRedirect(tenantUrl('luna', '/admin/settings'))
        ->assertSessionHas('flash', ['kind' => 'success', 'message' => adminLine(PaymentsController::DISABLED)]);
    expect(paymentsConnection($this)['state'])->toBe('disabled')
        ->and(paymentsTenantProvider())->toBe(NullPaymentProvider::class);

    paymentsWrite($this, 'POST', '/enable')->assertRedirect(tenantUrl('luna', '/admin/settings'))
        ->assertSessionHas('flash', ['kind' => 'success', 'message' => adminLine(PaymentsController::ENABLED)]);
    expect(paymentsConnection($this)['state'])->toBe($state)
        ->and(paymentsTenantProvider())->toBe($provider)
        ->and(paymentFake()->asked)->toBe([]);
})->with([
    'the stored check passed' => [true, 'active', FakePaymentProvider::class],
    'no check has run' => [null, 'active', FakePaymentProvider::class],
    'the stored check failed' => [false, 'restricted', NullPaymentProvider::class],
]);

it('disconnects: the row and its routing row removed, the section back to the provider list, and a second press a no-op', function () {
    connectPaymentFake(ConnectionState::Active, true);
    connectPaymentFake(ConnectionState::Active, true, 'pier');

    paymentsWrite($this, 'DELETE', '')->assertRedirect(tenantUrl('luna', '/admin/settings'))
        ->assertSessionHas('flash', ['kind' => 'success', 'message' => adminLine(PaymentsController::DISCONNECTED)]);

    expect(DB::table('tenant_luna.payment_connections')->count())->toBe(0)
        ->and(DB::table('payment_routes')->pluck('webhook_token')->all())->toBe(['hook-token-pier'])
        ->and(paymentsProp($this)['connection'])->toBeNull()
        ->and(paymentsTenantProvider())->toBe(NullPaymentProvider::class);

    paymentsWrite($this, 'DELETE', '')->assertRedirect(tenantUrl('luna', '/admin/settings'));
});

it('answers the framework\'s 404 to a write meeting no connection to act on, and to a test or a rotation of a platform connection', function (bool $platform, string $path) {
    if ($platform) {
        paymentConnections()->write(new NewConnection(FakePaymentAdapter::SLUG, ConnectionMode::Platform, ConnectionState::Pending, null, 'acct_luna_7'));
    }

    paymentsWrite($this, 'POST', $path)->assertNotFound();
})->with([
    'test, nothing connected' => [false, '/test'],
    'rotate, nothing connected' => [false, '/rotate'],
    'disable, nothing connected' => [false, '/disable'],
    'enable, nothing connected' => [false, '/enable'],
    'test, a platform connection' => [true, '/test'],
    'rotate, a platform connection' => [true, '/rotate'],
]);

/**
 * The six writes, each with the body it is sent with.
 *
 * @return list<array{string, string, array<string, mixed>}>
 */
function paymentsWrites(): array
{
    return [['POST', '', paymentsSave()], ['POST', '/test', []], ['POST', '/rotate', []], ['POST', '/disable', []], ['POST', '/enable', []], ['DELETE', '', []]];
}

it('opens every write to a manager and refuses a staff user each one, writing nothing', function () {
    $manager = User::query()->findOrFail(addTenantUser('manager@luna.example', 'manager'));
    paymentsWrite($this, 'POST', '', paymentsSave(), $manager)->assertRedirect(tenantUrl('luna', '/admin/settings'));
    expect(paymentsRow()['state'])->toBe('active');

    $staff = User::query()->findOrFail(addTenantUser('staff@luna.example', 'staff'));
    $before = paymentsRow();

    foreach (paymentsWrites() as [$method, $path, $body]) {
        paymentsWrite($this, $method, $path, $body, $staff)->assertForbidden();
    }

    expect(paymentsRow())->toBe($before);
});

it('refuses every write under a lapse with the gate\'s sentence, while the section still renders', function () {
    connectPaymentFake(ConnectionState::Active, true);
    $before = paymentsRow();
    DB::table('tenants')->where('slug', 'luna')->update(['subscription_state' => 'past_due']);

    foreach (paymentsWrites() as [$method, $path, $body]) {
        paymentsWrite($this, $method, $path, $body)->assertForbidden()
            ->assertJsonPath('code', 'subscription_required')
            ->assertJsonPath('data.state', 'past_due')
            ->assertJsonPath('data.detail', adminLine(RequiresActiveSubscription::DETAIL));
    }

    expect(paymentsRow())->toBe($before)
        ->and(paymentsConnection($this)['state'])->toBe('active')
        ->and(paymentFake()->asked)->toBe([]);
});

it('answers the second of two racing saves a worded 409 through the envelope in the user\'s language, never a 500, the first save standing', function (SiteLanguage $language) {
    User::query()->where('email', 'owner@luna.example')->update(['locale' => $language->value]);
    $default = config('database.default');

    assert(is_string($default));
    config(['database.connections.racer' => config('database.connections.'.$default)]);
    $raced = false;
    DB::listen(function (QueryExecuted $query) use (&$raced): void {
        if (! $raced && str_starts_with($query->sql, 'delete from "tenant_luna"."payment_connections"')) {
            $raced = true;
            DB::connection('racer')->table('tenant_luna.payment_connections')->insert([
                'provider' => 'stripe', 'mode' => 'own', 'state' => 'restricted', 'webhook_token' => 'hook-token-racer', 'is_primary' => true,
            ]);
        }
    });

    $answer = paymentsWrite($this, 'POST', '', paymentsSave())->assertStatus(409)
        ->assertJsonPath('code', PaymentsController::CONTENDED)
        ->assertJsonPath('data.detail', adminLine(PaymentsController::CONTENDED_DETAIL, [], $language))
        ->assertJsonPath('data.status', 409);

    expect($raced)->toBeTrue()
        ->and(paymentsRow()['provider'])->toBe('stripe')
        ->and(DB::table('payment_routes')->count())->toBe(0)
        ->and(paymentFake()->asked)->toBe([]);

    foreach (paymentFakeCredentials() as $value) {
        expect((string) $answer->getContent())->not->toContain($value);
    }
})->with(['a user at en' => [SiteLanguage::English], 'a user at el' => [SiteLanguage::Greek]]);

/*
 * Save and test and Test connection each make one live call to the provider, so the two share a
 * limit of their own in the billing writes' shape: ten a minute per address and tenant, past it
 * the envelope at 429 and no call made. The section's other writes ask the provider nothing and
 * carry no limit.
 */

it('answers the eleventh live call of Save and test and Test connection in a minute from one address with the envelope at 429 and checks nothing more, the two drawing on one count and the other writes on none', function () {
    $checks = 0;
    paymentFake()->whileChecked = function () use (&$checks): void {
        $checks++;
    };

    foreach (range(1, 10) as $press) {
        ($press % 2 === 1 ? paymentsWrite($this, 'POST', '', paymentsSave()) : paymentsWrite($this, 'POST', '/test'))
            ->assertRedirect(tenantUrl('luna', '/admin/settings'));
    }

    expect($checks)->toBe(10);

    foreach ([['', paymentsSave()], ['/test', []]] as [$path, $body]) {
        paymentsWrite($this, 'POST', $path, $body)
            ->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertExactJson(['code' => 'rate_limited', 'message' => 'rate_limited', 'data' => ['status' => 429, 'detail' => apiSentence(ErrorEnvelope::RATE_LIMITED_DETAIL)]]);
    }

    expect($checks)->toBe(10);

    foreach (['/rotate', '/disable', '/enable'] as $path) {
        paymentsWrite($this, 'POST', $path)->assertRedirect(tenantUrl('luna', '/admin/settings'));
    }
    paymentsWrite($this, 'DELETE', '')->assertRedirect(tenantUrl('luna', '/admin/settings'));
});

it('counts the live calls per address and tenant: the same owner from another address and another tenant\'s owner at the same address are still answered', function () {
    foreach (range(1, 10) as $press) {
        paymentsWrite($this, 'POST', '/test')->assertNotFound();
    }
    paymentsWrite($this, 'POST', '/test')->assertStatus(429);

    $this->actingAs(User::query()->where('email', 'owner@pier.example')->sole())->from(tenantUrl('pier', '/admin/settings'))
        ->json('POST', tenantUrl('pier', '/admin/settings/payments/test'), [], ['X-Inertia' => 'true'])
        ->assertNotFound();
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9']);
    paymentsWrite($this, 'POST', '/test')->assertNotFound();
});

it('never hands a secret back: the page\'s props, every flash, a validation refusal and each write\'s answer are searched for every credential value, and so is the log', function () {
    $logged = [];
    Log::listen(function (MessageLogged $message) use (&$logged): void {
        $logged[] = $message->message.json_encode($message->context);
    });
    $said = [];
    $hear = function (TestResponse $response) use (&$said): void {
        $said[] = $response->getStatusCode().json_encode($response->headers->all()).$response->getContent().json_encode(session('flash'));
    };

    $hear(paymentsWrite($this, 'POST', '', paymentsSave(['merchant_code' => null])));
    $hear(paymentsWrite($this, 'POST', '', paymentsSave(['api_token' => str_repeat('k', PaymentCredentialsRequest::LONGEST + 1)])));
    paymentFake()->checkFailsWith = 'Refused: '.implode(' ; ', paymentFakeCredentials());

    foreach (paymentsWrites() as [$method, $path, $body]) {
        $hear(paymentsWrite($this, $method, $path, $body));
        $said[] = json_encode(paymentsPage($this));
    }

    paymentsWrite($this, 'POST', '', paymentsSave())->assertRedirect();
    $said[] = json_encode(paymentsPage($this));
    $all = implode("\n", [...$said, ...$logged]);

    expect($all)->toContain('stored_secrets')
        ->and($all)->toContain('Refused: [secret] ; [secret] ; [secret]');

    foreach (['api_token', 'signing_phrase'] as $secret) {
        expect($all)->not->toContain(paymentFakeCredentials()[$secret]);
    }
});

it('words the eight flashes of an own connection, the validation and the descriptor\'s fields in the user\'s language: a user at el reads payments.php\'s Greek, a colleague at en its English', function () {
    User::query()->where('email', 'owner@luna.example')->update(['locale' => SiteLanguage::Greek->value]);
    $manager = User::query()->findOrFail(addTenantUser('manager@luna.example', 'manager'));
    $manager->forceFill(['locale' => SiteLanguage::English->value])->save();
    $flash = fn (string $key, SiteLanguage $language) => expect(session('flash'))->toBe(['kind' => 'success', 'message' => adminLine($key, [], $language)]);
    $failed = fn (string $key, SiteLanguage $language) => expect(session('flash'))->toBe(['kind' => 'error', 'message' => adminLine($key, [], $language)]);
    $signing = ['field' => adminLine(PAYMENTS_STRIPE_SIGNING_LABEL, [], SiteLanguage::Greek)];
    $walked = 0;

    paymentsWrite($this, 'POST', '', ['provider' => 'stripe', 'credentials' => ['secret_key' => 'sk_test_x']])
        ->assertStatus(400)->assertJsonPath('data.detail', adminLine(PaymentCredentialsRequest::MISSING, $signing, SiteLanguage::Greek));
    paymentsWrite($this, 'POST', '', ['credentials' => paymentFakeCredentials()])
        ->assertStatus(400)->assertJsonPath('data.detail', adminLine(PaymentCredentialsRequest::NO_PROVIDER, [], SiteLanguage::Greek));

    // The credentials refused by the test, then saved and tested, switched off and on, the URL
    // changed and the connection taken away: each write's flash.
    paymentFake()->checkFailsWith = 'Paydesk refused the token.';
    paymentsWrite($this, 'POST', '', paymentsSave())->assertRedirect();
    $failed(PaymentsController::SAVED_UNCHECKED, SiteLanguage::Greek);
    paymentsWrite($this, 'POST', '/test')->assertRedirect();
    $failed(PaymentsController::TEST_FAILED, SiteLanguage::Greek);
    paymentFake()->checkFailsWith = null;
    paymentsWrite($this, 'POST', '/test')->assertRedirect();
    $flash(PaymentsController::TEST_PASSED, SiteLanguage::Greek);
    paymentsWrite($this, 'POST', '', paymentsSave())->assertRedirect();
    $flash(PaymentsController::SAVED, SiteLanguage::Greek);

    foreach ([['/disable', PaymentsController::DISABLED], ['/enable', PaymentsController::ENABLED], ['/rotate', PaymentsController::ROTATED]] as [$path, $key]) {
        paymentsWrite($this, 'POST', $path)->assertRedirect();
        $flash($key, SiteLanguage::Greek);
        $walked++;
    }

    paymentsWrite($this, 'DELETE', '')->assertRedirect();
    $flash(PaymentsController::DISCONNECTED, SiteLanguage::Greek);

    // The colleague at en, the same tenant, reads the English of the same requests.
    paymentsWrite($this, 'POST', '', ['credentials' => paymentFakeCredentials()], $manager)
        ->assertStatus(400)->assertJsonPath('data.detail', adminLine(PaymentCredentialsRequest::NO_PROVIDER));
    paymentsWrite($this, 'POST', '', paymentsSave(), $manager)->assertRedirect();
    $flash(PaymentsController::SAVED, SiteLanguage::English);

    $fields = app(StripeAdapter::class)->descriptor()->credentialFields;
    $stripe = paymentsProp($this);
    expect(data_get($stripe, 'providers.0.fields.*.label'))->toBe(array_map(fn (CredentialField $field): string => adminLine($field->label, [], SiteLanguage::Greek), $fields))
        ->and(data_get($stripe, 'providers.0.fields.*.help'))->toBe(array_map(fn (CredentialField $field): string => adminLine($field->help, [], SiteLanguage::Greek), $fields))
        // Each label's Greek differs from its English, so a label read in the wrong language
        // cannot pass the line above.
        ->and(array_filter($fields, fn (CredentialField $field): bool => adminLine($field->label, [], SiteLanguage::Greek) === adminLine($field->label)))->toBe([])
        ->and(adminLine(PaymentsController::SAVED, [], SiteLanguage::Greek))->not->toBe(adminLine(PaymentsController::SAVED))
        ->and($walked)->toBe(3);
});

it('words every server-worded line of the section\'s prop in Greek for a user at el and in English for a colleague at en in each state an own connection takes: none, tested and passed, and failed with each reason of ours or the stored English of a reason with no key', function (Closure $connect, Closure $requirements) {
    User::query()->where('email', 'owner@luna.example')->update(['locale' => SiteLanguage::Greek->value]);
    $manager = User::query()->findOrFail(addTenantUser('manager@luna.example', 'manager'));
    $manager->forceFill(['locale' => SiteLanguage::English->value])->save();
    $connect();
    $fields = app(StripeAdapter::class)->descriptor()->credentialFields;
    $walked = 0;

    foreach ([SiteLanguage::Greek, SiteLanguage::English] as $language) {
        $user = $language === SiteLanguage::Greek ? paymentsOwner() : $manager;
        $page = $this->actingAs($user)->get(tenantUrl('luna', '/admin/settings'))->assertOk()->viewData('page');

        expect(data_get($page, 'props.payments.providers.0.fields.*.label'))->toBe(array_map(fn (CredentialField $field): string => adminLine($field->label, [], $language), $fields))
            ->and(data_get($page, 'props.payments.providers.0.fields.*.help'))->toBe(array_map(fn (CredentialField $field): string => adminLine($field->help, [], $language), $fields))
            ->and(data_get($page, 'props.payments.connection.requirements'))->toBe($requirements($language));
        $walked++;
    }

    expect($walked)->toBe(2)
        ->and(adminLine(StripeAdapter::FIELDS.StripeAdapter::SECRET_KEY.'.help', [], SiteLanguage::Greek))->not->toBe(adminLine(StripeAdapter::FIELDS.StripeAdapter::SECRET_KEY.'.help'));
})->with([
    'no connection' => [fn () => null, fn (SiteLanguage $language): ?array => null],
    'connected and tested, passed' => [fn () => connectPaymentFake(ConnectionState::Active, true), fn (SiteLanguage $language): ?array => null],
    ...array_combine(
        ['failed: unexpected', 'failed: no secret key', 'failed: unreachable', 'failed: answered a status', 'failed: answered a message'],
        array_map(fn (array $reason): array => [
            function () use ($reason): void {
                connectPaymentFake(ConnectionState::Restricted, false);
                DB::table('tenant_luna.payment_connections')->update(['requirements' => json_encode(paymentsReasonRow(...$reason), JSON_THROW_ON_ERROR)]);
            },
            fn (SiteLanguage $language): array => ['reason' => adminLine($reason[0], $reason[1], $language)],
        ], [
            [TestConnection::REASON_UNEXPECTED, ['class' => RuntimeException::class]],
            [StripeAdapter::REASON_NO_KEY, []],
            [StripeAdapter::REASON_UNREACHABLE, []],
            [StripeAdapter::REASON_STATUS, ['status' => '503']],
            [StripeAdapter::REASON_MESSAGE, ['reason' => 'reason-token']],
        ]),
    ),
    'failed with a reason of no key, quoted as stored' => [
        function (): void {
            connectPaymentFake(ConnectionState::Restricted, false);
            DB::table('tenant_luna.payment_connections')->update(['requirements' => json_encode(['reason' => 'stored-token'], JSON_THROW_ON_ERROR)]);
        },
        fn (SiteLanguage $language): array => ['reason' => 'stored-token'],
    ],
]);

it('spells every event Stripe\'s adapter counts as paid in the help of its signing secret, so the line cannot go stale when the list changes', function () {
    $help = adminLine(StripeAdapter::FIELDS.StripeAdapter::WEBHOOK_SECRET.'.help');
    $walked = 0;

    foreach (StripeAdapter::PAID_EVENTS as $event) {
        expect($help)->toContain($event);
        $walked++;
    }

    expect($walked)->toBe(2);
});

it('holds in payments.php the label and the help of every credential field each registered provider declares, so no key path reaches a screen', function () {
    // The application's own wiring, the test run's fake provider left out: its words are its own.
    config(['payments.provider_beside' => null]);
    app()->forgetInstance(ProviderRegistry::class);

    $missing = [];
    $walked = 0;

    foreach (app(ProviderRegistry::class)->descriptors() as $descriptor) {
        foreach ($descriptor->credentialFields as $field) {
            foreach ([$field->label, $field->help] as $key) {
                if (! str_starts_with($key, 'admin/payments.') || ! app('translator')->has($key, 'en', false)) {
                    $missing[] = $descriptor->slug.': '.$key;
                }
            }

            $walked++;
        }
    }

    expect($missing)->toBe([])
        ->and($walked)->toBe(2);
});

/*
 * A failed connection test's reason by key: five sentences of ours under payments.php's reasons
 * group, each recorded on the row as its English beside its key and the values that fill it, and
 * worded on the server in the request's language when the section shows it; the provider's own
 * words stay as sent inside the line that carries them.
 */

/**
 * The reason keys payments.php's reasons group holds, read from the file.
 *
 * @return list<string>
 */
function paymentsReasonKeys(): array
{
    $group = __(rtrim(ConnectionCheck::REASONS, '.'), [], 'en');

    assert(is_array($group));

    return array_map(fn (int|string $name): string => ConnectionCheck::REASONS.$name, array_keys($group));
}

it('records each of the five reasons of ours as the en line of its key filled with its values, so the English and the key cannot drift', function () {
    $transport = paymentsStripe()
        ->unreachable('GET', '/v1/checkout/sessions')
        ->answer('GET', '/v1/checkout/sessions', 503, [])
        ->answer('GET', '/v1/checkout/sessions', 401, ['error' => ['message' => 'Invalid API Key provided: '.PAYMENTS_STRIPE['secret_key']]]);
    $stripe = app(StripeAdapter::class);
    connectPaymentFake();
    paymentFake()->checkThrows = new RuntimeException('Unexpected.');

    $checks = [
        $stripe->check(new Credentials([])),
        $stripe->check(new Credentials(PAYMENTS_STRIPE)),
        $stripe->check(new Credentials(PAYMENTS_STRIPE)),
        $stripe->check(new Credentials(PAYMENTS_STRIPE)),
        app(TestConnection::class)->test(paymentConnections()),
    ];
    $walked = [];

    foreach ($checks as $check) {
        assert($check instanceof ConnectionCheck && $check->key !== null);

        expect($check->ok)->toBeFalse()
            ->and(adminLine($check->key, $check->values))->toBe($check->reason);
        $walked[] = $check->key;
    }

    expect($walked)->toHaveCount(5)
        ->and(array_unique($walked))->toEqualCanonicalizing(paymentsReasonKeys())
        ->and($transport->sent)->toHaveCount(3);
});

it('holds every reason key a class under app/Application/Payment names to a line of the reasons group, and every line of the group to a class naming it, so no key path reaches a screen', function () {
    $named = [];
    $unread = [];

    foreach (File::allFiles(app_path('Application/Payment')) as $file) {
        $class = 'App\\Application\\Payment\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

        if (! class_exists($class) && ! interface_exists($class)) {
            $unread[] = $class;

            continue;
        }

        foreach ((new ReflectionClass($class))->getReflectionConstants() as $constant) {
            $value = $constant->getValue();

            if ($constant->getDeclaringClass()->getName() === $class && is_string($value) && str_starts_with($value, ConnectionCheck::REASONS) && $value !== ConnectionCheck::REASONS) {
                $named[] = $value;
            }
        }
    }

    $missing = array_values(array_filter($named, fn (string $key): bool => ! app('translator')->has($key, 'en', false) || ! is_string(__($key, [], 'en'))));

    expect($unread)->toBe([])
        ->and($named)->toHaveCount(5)
        ->and($missing)->toBe([])
        ->and($named)->toEqualCanonicalizing(paymentsReasonKeys());
});

it('quotes the stored reason where the row holds no key of the reasons group, and words one that does, every other shape passing as stored', function (array $stored, Closure $shown) {
    $this->allowMissingKey(StripeAdapter::REASON_STATUS.'_retired');
    connectPaymentFake(ConnectionState::Restricted, false);
    DB::table('tenant_luna.payment_connections')->update(['requirements' => json_encode($stored, JSON_THROW_ON_ERROR)]);

    $carried = paymentsConnection($this)['requirements'];

    expect($carried)->toBe($shown())
        ->and(is_array($carried) ? array_intersect_key($carried, ['key' => true, 'values' => true]) : [])->toBe([]);
})->with([
    'a row written before the key was recorded' => [['reason' => 'Refused by the provider.'], fn (): array => ['reason' => 'Refused by the provider.']],
    'a key of the group with its values' => [['reason' => 'Recorded.', 'key' => StripeAdapter::REASON_STATUS, 'values' => ['status' => '503']], fn (): array => ['reason' => adminLine(StripeAdapter::REASON_STATUS, ['status' => '503'])]],
    'a key outside the group' => [['reason' => 'Recorded.', 'key' => PaymentsController::DISABLED, 'values' => []], fn (): array => ['reason' => 'Recorded.']],
    'a key the group no longer has' => [['reason' => 'Recorded.', 'key' => StripeAdapter::REASON_STATUS.'_retired', 'values' => []], fn (): array => ['reason' => 'Recorded.']],
    'the group itself' => [['reason' => 'Recorded.', 'key' => rtrim(ConnectionCheck::REASONS, '.'), 'values' => []], fn (): array => ['reason' => 'Recorded.']],
    'a line below a line of the group' => [['reason' => 'Recorded.', 'key' => StripeAdapter::REASON_STATUS.'.deeper', 'values' => []], fn (): array => ['reason' => 'Recorded.']],
    'values not shaped as written' => [['reason' => 'Recorded.', 'key' => StripeAdapter::REASON_STATUS, 'values' => ['status' => 503]], fn (): array => ['reason' => 'Recorded.']],
    'values missing a placeholder the line has' => [['reason' => 'Recorded.', 'key' => StripeAdapter::REASON_STATUS, 'values' => []], fn (): array => ['reason' => 'Recorded.']],
    'a value named for no placeholder of the line' => [['reason' => 'Recorded.', 'key' => StripeAdapter::REASON_STATUS, 'values' => ['status' => '503', 'retired' => 'x']], fn (): array => ['reason' => 'Recorded.']],
    'a value named nothing' => [['reason' => 'Recorded.', 'key' => StripeAdapter::REASON_STATUS, 'values' => ['status' => '503', '' => 'x']], fn (): array => ['reason' => 'Recorded.']],
    'a key that is not a string' => [['reason' => 'Recorded.', 'key' => 7, 'values' => []], fn (): array => ['reason' => 'Recorded.']],
    'values missing altogether' => [['reason' => 'Recorded.', 'key' => StripeAdapter::REASON_STATUS], fn (): array => ['reason' => 'Recorded.']],
    'values a list' => [['reason' => 'Recorded.', 'key' => StripeAdapter::REASON_STATUS, 'values' => ['503']], fn (): array => ['reason' => 'Recorded.']],
    'a key and no reason' => [['note' => 'Recorded.', 'key' => StripeAdapter::REASON_STATUS, 'values' => ['status' => '503']], fn (): array => ['note' => 'Recorded.']],
    'a key and a null reason' => [['reason' => null, 'key' => StripeAdapter::REASON_STATUS, 'values' => ['status' => '503']], fn (): array => ['reason' => null]],
    'values and no key' => [['reason' => 'Recorded.', 'values' => ['status' => '503']], fn (): array => ['reason' => 'Recorded.']],
    'the platform\'s requirements' => [['currently_due' => ['external_account']], fn (): array => ['currently_due' => ['external_account']]],
]);

it('keeps the provider\'s own words as sent inside the line that carries them, a colon-word among them never filled', function () {
    $said = 'Rate :status reached; :reason and :Reason stay as sent.';
    paymentsStripe()->answer('GET', '/v1/checkout/sessions', 429, ['error' => ['message' => $said]]);

    paymentsWrite($this, 'POST', '', ['provider' => 'stripe', 'credentials' => PAYMENTS_STRIPE])->assertRedirect(tenantUrl('luna', '/admin/settings'));
    $shown = paymentsConnection($this)['requirements'];

    expect($shown)->toBe(['reason' => adminLine(StripeAdapter::REASON_MESSAGE, ['reason' => $said])])
        ->and(is_array($shown) ? $shown['reason'] : null)->toEndWith(' '.$said)
        ->and(paymentsRowRequirements())->toBe(paymentsReasonRow(StripeAdapter::REASON_MESSAGE, ['reason' => $said]));
});

it('words a reason of ours in Greek for a user at el and in English for a colleague at en, the provider\'s own words inside it as sent and the row\'s English unchanged', function () {
    User::query()->where('email', 'owner@luna.example')->update(['locale' => SiteLanguage::Greek->value]);
    $manager = User::query()->findOrFail(addTenantUser('manager@luna.example', 'manager'));
    $manager->forceFill(['locale' => SiteLanguage::English->value])->save();
    paymentsStripe()->answer('GET', '/v1/checkout/sessions', 401, ['error' => ['message' => 'Invalid API Key provided: '.PAYMENTS_STRIPE['secret_key']]]);
    paymentsWrite($this, 'POST', '', ['provider' => 'stripe', 'credentials' => PAYMENTS_STRIPE])->assertRedirect();
    $values = ['reason' => 'Invalid API Key provided: [secret]'];
    $greek = adminLine(StripeAdapter::REASON_MESSAGE, $values, SiteLanguage::Greek);
    $english = $this->actingAs($manager)->get(tenantUrl('luna', '/admin/settings'))->assertOk()->viewData('page');

    expect(paymentsConnection($this)['requirements'])->toBe(['reason' => $greek])
        ->and($greek)->not->toBe(adminLine(StripeAdapter::REASON_MESSAGE, $values))
        ->and($greek)->toEndWith(' '.$values['reason'])
        ->and(data_get($english, 'props.payments.connection.requirements'))->toBe(['reason' => adminLine(StripeAdapter::REASON_MESSAGE, $values)])
        ->and(paymentsRowRequirements())->toBe(paymentsReasonRow(StripeAdapter::REASON_MESSAGE, $values));
});

it('lets no secret typed into a credential reach the row\'s values, the prop or the log, whatever Stripe echoes', function () {
    $logged = [];
    Log::listen(function (MessageLogged $message) use (&$logged): void {
        $logged[] = [$message->level, $message->message, $message->context];
    });
    paymentsStripe()->answer('GET', '/v1/checkout/sessions', 401, ['error' => ['message' => 'Neither '.PAYMENTS_STRIPE['secret_key'].' nor '.PAYMENTS_STRIPE['webhook_secret'].' is known.']]);

    paymentsWrite($this, 'POST', '', ['provider' => 'stripe', 'credentials' => PAYMENTS_STRIPE])->assertRedirect();
    $row = paymentsRowRequirements();

    expect(is_array($row) ? $row['key'] : null)->toBe(StripeAdapter::REASON_MESSAGE);

    foreach (PAYMENTS_STRIPE as $secret) {
        expect((string) json_encode($row))->not->toContain($secret)
            ->and((string) json_encode(paymentsProp($this)))->not->toContain($secret)
            ->and((string) json_encode($logged))->not->toContain($secret);
    }
});

/*
 * The admin's generated module is lang/ written out for its tests, never code: payments.php keys
 * our words for a provider's fields by its slug, so that module names it and the scan passes it
 * by its one path. The bundle's module holds no slug and is read like any other file.
 */
it('names no registered provider in any file under resources/js, so the section stays generated from the registry', function () {
    $slugs = array_map(fn (ProviderDescriptor $descriptor): string => $descriptor->slug, app(ProviderRegistry::class)->descriptors());
    expect($slugs)->toContain('stripe', FakePaymentAdapter::SLUG);

    $named = [];
    $sources = array_filter(File::allFiles(resource_path('js')), fn (SplFileInfo $file): bool => $file->getPathname() !== base_path(ExportLangCommand::ADMIN_MODULE));

    foreach ($sources as $file) {
        foreach ($slugs as $slug) {
            if (preg_match('/\b'.preg_quote($slug, '/').'\b/i', $file->getContents()) === 1) {
                $named[] = $file->getRelativePathname().': '.$slug;
            }
        }
    }

    expect($named)->toBe([]);
});
