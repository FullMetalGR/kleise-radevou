<?php

use App\Application\Clock;
use App\Application\Payment\ConnectionModeTaken;
use App\Application\Payment\CredentialField;
use App\Application\Payment\PlatformUnavailable;
use App\Application\Payment\Stripe\StripeAdapter;
use App\Http\Controllers\Admin\PaymentsController;
use App\Http\Requests\PlatformConnectRequest;
use App\Infrastructure\Payment\PaymentRoutes;
use App\Infrastructure\Payment\PlatformWebhook;
use App\Models\User;
use App\Site\SiteLanguage;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FakePaymentAdapter;
use Tests\Support\FixedClock;
use Tests\TestCase;

pest()->use(DatabaseTruncation::class);

/*
 * Platform mode, on the fake provider, which declares a platform mode of its own: the platform's
 * keys and the fee deciding whether the mode is offered, the one button opening the connected
 * account and writing it pending with its routing row, resume, the one-mode rule, and the
 * platform webhook landing the provider's word. The clock stands pinned, so a landed standing's
 * instant is a literal.
 */

const PLATFORM_NOW = '2026-11-02 06:00:00';

beforeEach(function () {
    $this->withoutVite();
    dropDemoSchemas();
    seedDemo();
    app()->instance(Clock::class, FixedClock::at(PLATFORM_NOW));
    Http::preventStrayRequests();
    config(['payments.platform_fee' => ['percent' => '1.5'], 'payments.platform.'.FakePaymentAdapter::SLUG => FakePaymentAdapter::PLATFORM_KEYS]);
});

afterEach(function () {
    Http::assertNothingSent();
    dropDemoSchemas();
    DB::table('users')->delete();
    DB::table('tenants')->delete();
});

/**
 * @param  array<array-key, mixed>  $body
 * @return TestResponse<Response>
 */
function platformWrite(TestCase $test, string $path, array $body = [], string $method = 'POST'): TestResponse
{
    return $test->actingAs(User::query()->where('email', 'owner@luna.example')->sole())->from(tenantUrl('luna', '/admin/settings'))
        ->json($method, tenantUrl('luna', '/admin/settings/payments'.$path), $body, ['X-Inertia' => 'true']);
}

/**
 * @param  array<array-key, mixed>  $outstanding
 */
function platformBody(string $event, string $account, string $standing, array $outstanding = [], string $type = FakePaymentAdapter::ACCOUNT): string
{
    return (string) json_encode(['id' => $event, 'type' => $type, 'account' => $account, 'standing' => $standing, 'outstanding' => $outstanding]);
}

/**
 * @return TestResponse<Response>
 */
function platformDeliver(TestCase $test, string $body, ?string $seal = null): TestResponse
{
    return $test->call('POST', centralUrl('/webhooks/'.FakePaymentAdapter::SLUG), [], [], [], [
        'HTTP_PAYDESK_SEAL' => $seal ?? FakePaymentAdapter::seal($body, FakePaymentAdapter::PLATFORM_KEYS['signing_phrase']),
        'CONTENT_TYPE' => 'application/json',
    ], $body);
}

/**
 * @return array<string, mixed>
 */
function platformProp(TestCase $test): array
{
    $page = $test->actingAs(User::query()->where('email', 'owner@luna.example')->sole())->get(tenantUrl('luna', '/admin/settings'))->assertOk()->viewData('page');
    assert(is_array($page) && is_array($page['props']) && is_array($page['props']['payments']));

    /** @var array<string, mixed> */
    return $page['props']['payments'];
}

/**
 * @return array<array-key, mixed>|null
 */
function platformRow(): ?array
{
    $row = DB::selectOne('select provider, mode, state, account_ref, requirements::text as requirements, last_check_ok, last_checked_at at time zone \'UTC\' as checked, credentials, webhook_token from tenant_luna.payment_connections');

    return is_object($row) ? (array) $row : null;
}

function platformLunaId(): int
{
    return Tenant::query()->where('slug', 'luna')->sole()->id;
}

/**
 * @return list<string>
 */
function platformOffered(TestCase $test): array
{
    $providers = platformProp($test)['providers'];
    assert(is_array($providers));
    $offered = [];
    foreach ($providers as $provider) {
        assert(is_array($provider) && is_array($provider['modes']));
        foreach ($provider['modes'] as $mode) {
            if (is_array($mode) && $mode['mode'] === 'platform' && $mode['offered'] === true) {
                $offered[] = is_string($provider['slug']) ? $provider['slug'] : '';
            }
        }
    }

    return $offered;
}

it('offers platform mode for the provider whose keys are all set while the fee is configured, and no other', function () {
    expect(platformOffered($this))->toBe([FakePaymentAdapter::SLUG]);
});

it('offers no platform mode and refuses its routes while a key is unset or the fee is not configured', function (array $keys, mixed $fee) {
    config(['payments.platform.'.FakePaymentAdapter::SLUG => $keys, 'payments.platform_fee' => $fee]);

    expect(platformOffered($this))->toBe([]);
    platformWrite($this, '/platform', ['provider' => FakePaymentAdapter::SLUG])->assertStatus(400)->assertJsonPath('data.detail', adminLine(PlatformConnectRequest::NOT_PLATFORM));
    platformWrite($this, '/platform', ['provider' => 'stripe'])->assertStatus(400)->assertJsonPath('data.detail', adminLine(PlatformConnectRequest::NOT_PLATFORM));
    expect(paymentFake()->accounts)->toBe([])->and(platformRow())->toBeNull();
})->with([
    'a key empty' => [['api_token' => FakePaymentAdapter::PLATFORM_KEYS['api_token'], 'signing_phrase' => ''], ['percent' => '1.5']],
    'no keys' => [[], ['percent' => '1.5']],
    'no fee' => [FakePaymentAdapter::PLATFORM_KEYS, null],
    'neither part of the fee set' => [FakePaymentAdapter::PLATFORM_KEYS, ['percent' => null, 'fixed' => null]],
    'a fee it cannot read' => [FakePaymentAdapter::PLATFORM_KEYS, ['percent' => '1.5%']],
]);

it('answers the platform webhook 404, recorded, while the provider\'s keys are unset, whatever the fee', function () {
    config(['payments.platform.'.FakePaymentAdapter::SLUG => ['api_token' => FakePaymentAdapter::PLATFORM_KEYS['api_token'], 'signing_phrase' => '']]);

    platformDeliver($this, platformBody('evt_1', 'pdk_acct_1', 'taking'))->assertNotFound();
    $this->call('POST', centralUrl('/webhooks/nowhere'), [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}')->assertNotFound();

    expect(DB::table('payment_events')->where('route', 'platform')->orderBy('id')->pluck('refusal')->all())->toBe(['unknown_route', 'unknown_provider']);
});

it('opens the connected account, writes it pending with its routing row, and sends the owner to the hosted onboarding, Settings reading the stored row after', function () {
    $answer = platformWrite($this, '/platform', ['provider' => FakePaymentAdapter::SLUG]);

    $answer->assertStatus(409)->assertHeader('X-Inertia-Location', 'https://onboarding.paydesk.test/pdk_acct_1/1');
    expect(paymentFake()->accounts)->toBe(['pdk_acct_1'])
        ->and(paymentFake()->links)->toBe([['account' => 'pdk_acct_1', 'return' => tenantUrl('luna', '/admin/settings'), 'token' => FakePaymentAdapter::PLATFORM_KEYS['api_token']]])
        ->and(platformRow())->toMatchArray(['provider' => FakePaymentAdapter::SLUG, 'mode' => 'platform', 'state' => 'pending', 'account_ref' => 'pdk_acct_1', 'credentials' => null, 'webhook_token' => null, 'last_check_ok' => null])
        ->and(app(PaymentRoutes::class)->byAccount(FakePaymentAdapter::SLUG, 'pdk_acct_1'))->toBe(platformLunaId());

    $connection = platformProp($this)['connection'];

    expect($connection)->toMatchArray(['provider' => FakePaymentAdapter::SLUG, 'mode' => 'platform', 'state' => 'pending', 'last_check_ok' => null, 'stored_secrets' => [], 'webhook_url' => null])
        ->and(paymentFake()->accounts)->toHaveCount(1)
        ->and(paymentFake()->links)->toHaveCount(1);
});

it('answers a plain redirect to the onboarding outside an Inertia visit', function () {
    $this->actingAs(User::query()->where('email', 'owner@luna.example')->sole())
        ->post(tenantUrl('luna', '/admin/settings/payments/platform'), ['provider' => FakePaymentAdapter::SLUG])
        ->assertRedirect('https://onboarding.paydesk.test/pdk_acct_1/1');
});

it('leaves nothing where the provider fails to open the account, and a pending row the section shows where it fails at the link, each a sentence and a log line naming the class alone', function () {
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $message) use (&$logged): void {
        $logged[] = $message->message.' '.json_encode($message->context);
    });
    paymentFake()->openFailsWith = 'Paydesk is down for '.FakePaymentAdapter::PLATFORM_KEYS['api_token'];

    platformWrite($this, '/platform', ['provider' => FakePaymentAdapter::SLUG])->assertRedirect(tenantUrl('luna', '/admin/settings'))
        ->assertSessionHas('flash', ['kind' => 'error', 'message' => adminLine(PaymentsController::ONBOARDING_FAILED)]);
    expect(platformRow())->toBeNull()->and(DB::table('payment_routes')->count())->toBe(0);

    paymentFake()->openFailsWith = null;
    paymentFake()->linkFailsWith = 'No link for '.FakePaymentAdapter::PLATFORM_KEYS['signing_phrase'];
    platformWrite($this, '/platform', ['provider' => FakePaymentAdapter::SLUG])
        ->assertSessionHas('flash', ['kind' => 'error', 'message' => adminLine(PaymentsController::ONBOARDING_FAILED)]);
    expect(platformRow())->toMatchArray(['state' => 'pending', 'account_ref' => 'pdk_acct_1'])
        ->and(implode("\n", $logged))->toContain(PaymentsController::ONBOARDING_FAILED_LOG)->toContain('RuntimeException')
        ->not->toContain(FakePaymentAdapter::PLATFORM_KEYS['api_token'])->not->toContain(FakePaymentAdapter::PLATFORM_KEYS['signing_phrase']);

    platformWrite($this, '', [], 'DELETE')->assertSessionHas('flash', ['kind' => 'success', 'message' => adminLine(PaymentsController::DISCONNECTED)]);
    expect(platformRow())->toBeNull()->and(DB::table('payment_routes')->count())->toBe(0);
});

it('resumes a pending connection with a fresh link for the same account and opens no second one, and the 404 with nothing to resume', function () {
    platformWrite($this, '/platform/resume')->assertNotFound();
    platformWrite($this, '/platform', ['provider' => FakePaymentAdapter::SLUG])->assertStatus(409);

    platformWrite($this, '/platform/resume')->assertStatus(409)->assertHeader('X-Inertia-Location', 'https://onboarding.paydesk.test/pdk_acct_1/2');
    platformWrite($this, '/platform', ['provider' => FakePaymentAdapter::SLUG])->assertStatus(409)->assertHeader('X-Inertia-Location', 'https://onboarding.paydesk.test/pdk_acct_1/3');

    expect(paymentFake()->accounts)->toBe(['pdk_acct_1'])
        ->and(array_column(paymentFake()->links, 'account'))->toBe(['pdk_acct_1', 'pdk_acct_1', 'pdk_acct_1'])
        ->and(DB::table('payment_routes')->where('account_ref', 'pdk_acct_1')->count())->toBe(1);

    platformDeliver($this, platformBody('evt_1', 'pdk_acct_1', 'taking'))->assertOk();
    platformWrite($this, '/platform/resume')->assertNotFound();
});

it('answers the button\'s road for a row already connected through us, active or disabled, with a fresh link for its own account and nothing written, the section never offering it there', function () {
    platformWrite($this, '/platform', ['provider' => FakePaymentAdapter::SLUG])->assertStatus(409);
    platformDeliver($this, platformBody('evt_1', 'pdk_acct_1', 'taking'))->assertOk();
    $active = platformRow();

    platformWrite($this, '/platform', ['provider' => FakePaymentAdapter::SLUG])->assertStatus(409)->assertHeader('X-Inertia-Location', 'https://onboarding.paydesk.test/pdk_acct_1/2');
    expect(platformRow())->toBe($active);

    platformWrite($this, '/disable')->assertRedirect();
    $disabled = platformRow();
    platformWrite($this, '/platform', ['provider' => FakePaymentAdapter::SLUG])->assertStatus(409)->assertHeader('X-Inertia-Location', 'https://onboarding.paydesk.test/pdk_acct_1/3');

    expect(platformRow())->toBe($disabled)
        ->and($disabled['state'] ?? null)->toBe('disabled')
        ->and(paymentFake()->accounts)->toBe(['pdk_acct_1'])
        ->and(DB::table('payment_routes')->where('account_ref', 'pdk_acct_1')->count())->toBe(1)
        ->and(platformProp($this)['connection'])->toMatchArray(['mode' => 'platform', 'state' => 'disabled']);
});

it('lands a verified account update active, restricted with what the provider said verbatim, or still pending, with the time, and the section reads each', function () {
    platformWrite($this, '/platform', ['provider' => FakePaymentAdapter::SLUG]);
    $outstanding = ['currently_due' => ['external_account', 'individual.verification.document'], 'disabled_reason' => 'requirements.past_due'];

    platformDeliver($this, platformBody('evt_1', 'pdk_acct_1', 'wanting', $outstanding))->assertOk()->assertContent('');
    $stored = DB::scalar('select requirements::text from tenant_luna.payment_connections');
    assert(is_string($stored));
    expect(platformRow())->toMatchArray(['state' => 'restricted', 'last_check_ok' => false, 'checked' => PLATFORM_NOW])
        ->and(json_decode($stored, true))->toBe($outstanding)
        ->and(platformProp($this)['connection'])->toMatchArray(['state' => 'restricted', 'requirements' => $outstanding, 'last_checked_at' => '2026-11-02T06:00:00+00:00']);

    platformDeliver($this, platformBody('evt_2', 'pdk_acct_1', 'unfinished', ['currently_due' => ['tos_acceptance.date']]))->assertOk();
    expect(platformRow())->toMatchArray(['state' => 'pending', 'last_check_ok' => null]);

    platformDeliver($this, platformBody('evt_3', 'pdk_acct_1', 'taking'))->assertOk();
    expect(platformRow())->toMatchArray(['state' => 'active', 'last_check_ok' => true, 'requirements' => null])
        ->and(platformProp($this)['connection'])->toMatchArray(['state' => 'active', 'requirements' => null]);
});

it('states the fee in force on a connection made through us, read from configuration on each render as the provider list reads it, nothing where it comes to nothing or is unset, since payments then open with no fee', function (mixed $configured, ?array $fee) {
    platformWrite($this, '/platform', ['provider' => FakePaymentAdapter::SLUG])->assertStatus(409);
    platformDeliver($this, platformBody('evt_1', 'pdk_acct_1', 'taking'))->assertOk();
    config(['payments.platform_fee' => $configured]);

    expect(platformProp($this)['connection'])->toMatchArray(['mode' => 'platform', 'state' => 'active', 'fee' => $fee]);
})->with([
    'the fee the connection was made under' => [['percent' => '1.5'], ['percent' => '1.5', 'fixed_minor' => null, 'currency' => 'EUR']],
    'a fee raised after connecting' => [['percent' => '2.50', 'fixed' => 'EUR:25'], ['percent' => '2.5', 'fixed_minor' => 25, 'currency' => 'EUR']],
    'zero, the launch fee' => [['percent' => '0'], ['percent' => '0', 'fixed_minor' => null, 'currency' => 'EUR']],
    'a fixed part in another currency alone' => [['fixed' => 'GBP:20'], ['percent' => null, 'fixed_minor' => null, 'currency' => 'EUR']],
    'unset after connecting' => [null, ['percent' => null, 'fixed_minor' => null, 'currency' => 'EUR']],
]);

it('lands an account taking payments while its provider still lists information due active with what it lists, the section saying so, and resumes its onboarding for the same account; one listing nothing carries no such word and no resume', function () {
    platformWrite($this, '/platform', ['provider' => FakePaymentAdapter::SLUG])->assertStatus(409);
    // In the order jsonb keeps its keys, shorter first, so the stored object reads back equal.
    $due = ['past_due' => [], 'currently_due' => ['individual.verification.document']];

    platformDeliver($this, platformBody('evt_1', 'pdk_acct_1', 'taking', $due))->assertOk();
    $stored = DB::scalar('select requirements::text from tenant_luna.payment_connections');
    assert(is_string($stored));
    expect(platformRow())->toMatchArray(['state' => 'active', 'last_check_ok' => true])
        ->and(json_decode($stored, true))->toBe($due)
        ->and(platformProp($this)['connection'])->toMatchArray(['state' => 'active', 'requirements' => $due, 'information_due' => true]);

    platformWrite($this, '/platform/resume')->assertStatus(409)->assertHeader('X-Inertia-Location', 'https://onboarding.paydesk.test/pdk_acct_1/2');
    expect(paymentFake()->accounts)->toBe(['pdk_acct_1'])
        ->and(platformRow())->toMatchArray(['state' => 'active']);

    platformDeliver($this, platformBody('evt_2', 'pdk_acct_1', 'taking'))->assertOk();
    expect(platformRow())->toMatchArray(['state' => 'active', 'requirements' => null])
        ->and(platformProp($this)['connection'])->toMatchArray(['state' => 'active', 'requirements' => null, 'information_due' => false]);
    platformWrite($this, '/platform/resume')->assertNotFound();

    // Switched off, the row is not taking payments here, so the line and the resume wait for
    // Switch back on.
    platformDeliver($this, platformBody('evt_3', 'pdk_acct_1', 'taking', $due))->assertOk();
    platformWrite($this, '/disable')->assertRedirect();
    expect(platformProp($this)['connection'])->toMatchArray(['state' => 'disabled', 'requirements' => $due, 'information_due' => false]);
    platformWrite($this, '/platform/resume')->assertNotFound();
    expect(paymentFake()->links)->toHaveCount(2);
});

it('refuses a bad signature with the envelope and records it, logs and ignores an account no connection holds, and does nothing on a repeat delivery', function () {
    platformWrite($this, '/platform', ['provider' => FakePaymentAdapter::SLUG]);
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $message) use (&$logged): void {
        $logged[] = $message->message.' '.json_encode($message->context);
    });

    platformDeliver($this, platformBody('evt_1', 'pdk_acct_1', 'taking'), FakePaymentAdapter::seal(platformBody('evt_1', 'pdk_acct_1', 'taking'), paymentFakeCredentials()['signing_phrase']))
        ->assertStatus(400)->assertJsonPath('code', 'invalid_signature');
    expect(DB::table('payment_events')->get(['route', 'refusal', 'tenant_id', 'event_id'])->map(fn (object $row): array => (array) $row)->all())
        ->toBe([['route' => 'platform', 'refusal' => 'signature', 'tenant_id' => null, 'event_id' => null]])
        ->and(platformRow())->toMatchArray(['state' => 'pending']);

    $before = platformRow();
    platformDeliver($this, platformBody('evt_2', 'pdk_acct_99', 'taking'))->assertOk();
    expect(implode("\n", $logged))->toContain(PlatformWebhook::UNKNOWN_ACCOUNT)->toContain('pdk_acct_99')
        ->and(DB::table('payment_events')->whereNotNull('event_id')->count())->toBe(0)
        ->and(platformRow())->toBe($before);

    $wanting = platformBody('evt_3', 'pdk_acct_1', 'wanting', ['currently_due' => ['external_account']]);
    platformDeliver($this, $wanting)->assertOk();
    platformDeliver($this, platformBody('evt_4', 'pdk_acct_1', 'taking'))->assertOk();
    platformDeliver($this, $wanting)->assertOk();

    expect(platformRow())->toMatchArray(['state' => 'active', 'requirements' => null])
        ->and(DB::table('payment_events')->where('route', 'platform')->whereNotNull('processed_at')->pluck('event_id')->sort()->values()->all())->toBe(['evt_3', 'evt_4']);
});

it('keeps a disabled platform row disabled under an update and switches it back on where the provider last left it, pending while onboarding is unfinished', function () {
    platformWrite($this, '/platform', ['provider' => FakePaymentAdapter::SLUG]);

    platformWrite($this, '/disable')->assertSessionHas('flash', ['kind' => 'success', 'message' => adminLine(PaymentsController::DISABLED)]);
    platformWrite($this, '/enable');
    expect(platformRow())->toMatchArray(['state' => 'pending']);

    platformWrite($this, '/disable');
    platformDeliver($this, platformBody('evt_1', 'pdk_acct_1', 'wanting', ['currently_due' => ['external_account']]))->assertOk();
    expect(platformRow())->toMatchArray(['state' => 'disabled', 'last_check_ok' => false]);
    platformWrite($this, '/enable');
    expect(platformRow())->toMatchArray(['state' => 'restricted']);

    platformWrite($this, '/disable');
    platformDeliver($this, platformBody('evt_2', 'pdk_acct_1', 'taking'))->assertOk();
    platformWrite($this, '/enable');
    expect(platformRow())->toMatchArray(['state' => 'active']);
});

it('refuses a provider connected in one mode the other with its sentence, and connects the other after a disconnect', function () {
    platformWrite($this, '', ['provider' => FakePaymentAdapter::SLUG, 'credentials' => paymentFakeCredentials()])->assertRedirect();

    platformWrite($this, '/platform', ['provider' => FakePaymentAdapter::SLUG])->assertStatus(409)
        ->assertJsonPath('code', ConnectionModeTaken::CODE)->assertJsonPath('data.detail', adminLine(ConnectionModeTaken::OWN, ['provider' => 'Paydesk']));
    expect(paymentFake()->accounts)->toBe([])->and(platformRow())->toMatchArray(['mode' => 'own']);

    platformWrite($this, '', [], 'DELETE');
    platformWrite($this, '/platform', ['provider' => FakePaymentAdapter::SLUG])->assertStatus(409)->assertHeader('X-Inertia-Location');

    platformWrite($this, '', ['provider' => FakePaymentAdapter::SLUG, 'credentials' => paymentFakeCredentials()])->assertStatus(409)
        ->assertJsonPath('code', ConnectionModeTaken::CODE)->assertJsonPath('data.detail', adminLine(ConnectionModeTaken::PLATFORM, ['provider' => 'Paydesk']));
    expect(platformRow())->toMatchArray(['mode' => 'platform', 'state' => 'pending', 'credentials' => null]);

    platformWrite($this, '', [], 'DELETE');
    platformWrite($this, '', ['provider' => FakePaymentAdapter::SLUG, 'credentials' => paymentFakeCredentials()])->assertRedirect();
    expect(platformRow())->toMatchArray(['mode' => 'own', 'state' => 'active']);
});

it('carries the platform\'s keys in no log line, prop or answer on any road', function () {
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $message) use (&$logged): void {
        $logged[] = $message->message.' '.json_encode($message->context);
    });
    $answers = [];
    $answers[] = platformWrite($this, '/platform', ['provider' => FakePaymentAdapter::SLUG])->baseResponse;
    $answers[] = platformWrite($this, '/platform/resume')->baseResponse;
    $answers[] = platformDeliver($this, platformBody('evt_1', 'pdk_acct_1', 'wanting', ['x' => 1]))->baseResponse;
    $answers[] = platformDeliver($this, platformBody('evt_2', 'pdk_acct_1', 'taking'), 'forged')->baseResponse;
    $answers[] = platformDeliver($this, platformBody('evt_3', 'pdk_acct_7', 'taking'))->baseResponse;
    paymentFake()->linkFailsWith = 'boom';
    $answers[] = platformWrite($this, '/platform/resume')->baseResponse;
    $rows = DB::scalar('select string_agg(r.text, chr(10)) from (select t::text as text from tenant_luna.payment_connections t union all select t::text from payment_routes t union all select t::text from payment_events t) r');
    $counted = DB::scalar('select (select count(*) from tenant_luna.payment_connections) + (select count(*) from payment_routes) + (select count(*) from payment_events)');
    assert(is_string($rows));
    $seen = implode("\n", [...$logged, ...array_map(fn (Response $answer): string => $answer->headers.$answer->getContent(), $answers), (string) json_encode(platformProp($this)), $rows]);

    foreach (FakePaymentAdapter::PLATFORM_KEYS as $key) {
        expect($seen)->not->toContain($key);
    }
    expect($seen)->toContain(PlatformWebhook::REFUSED)
        ->and($counted)->toEqual(1 + 1 + 2);
});

it('verifies and lands the platform webhook with the keys set and the fee unset, so a connection made keeps hearing from its provider', function () {
    platformWrite($this, '/platform', ['provider' => FakePaymentAdapter::SLUG])->assertStatus(409);
    config(['payments.platform_fee' => null]);

    platformDeliver($this, platformBody('evt_1', 'pdk_acct_1', 'taking'))->assertOk();

    expect(platformRow())->toMatchArray(['state' => 'active', 'account_ref' => 'pdk_acct_1'])
        ->and(DB::table('payment_events')->where('route', 'platform')->whereNotNull('processed_at')->pluck('event_id')->all())->toBe(['evt_1']);
});

it('resumes a restricted connection with a fresh link for the same account', function () {
    platformWrite($this, '/platform', ['provider' => FakePaymentAdapter::SLUG])->assertStatus(409);
    platformDeliver($this, platformBody('evt_1', 'pdk_acct_1', 'wanting', ['currently_due' => ['external_account']]))->assertOk();

    platformWrite($this, '/platform/resume')->assertStatus(409)->assertHeader('X-Inertia-Location', 'https://onboarding.paydesk.test/pdk_acct_1/2');

    expect(paymentFake()->accounts)->toBe(['pdk_acct_1'])
        ->and(platformRow())->toMatchArray(['state' => 'restricted']);
});

it('answers resume a worded refusal through the envelope while the provider\'s keys are unset, never the 404 of a connection gone, a pending row standing as it was and no link asked', function () {
    platformWrite($this, '/platform', ['provider' => FakePaymentAdapter::SLUG])->assertStatus(409);
    $before = platformRow();
    config(['payments.platform.'.FakePaymentAdapter::SLUG => ['api_token' => FakePaymentAdapter::PLATFORM_KEYS['api_token'], 'signing_phrase' => '']]);

    platformWrite($this, '/platform/resume')
        ->assertStatus(409)
        ->assertHeaderMissing('X-Inertia-Location')
        ->assertExactJson(['code' => PlatformUnavailable::CODE, 'message' => PlatformUnavailable::CODE, 'data' => ['status' => 409, 'detail' => adminLine(PlatformUnavailable::DETAIL)]]);

    expect(platformRow())->toBe($before)
        ->and($before)->toMatchArray(['state' => 'pending'])
        ->and(paymentFake()->links)->toHaveCount(1);
});

it('logs a verified platform event naming no account with its own line and ignores it', function () {
    platformWrite($this, '/platform', ['provider' => FakePaymentAdapter::SLUG])->assertStatus(409);
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $message) use (&$logged): void {
        $logged[] = $message->message;
    });
    $before = platformRow();

    platformDeliver($this, (string) json_encode(['id' => 'evt_1', 'type' => FakePaymentAdapter::ACCOUNT, 'account' => null, 'standing' => 'taking']))->assertOk();

    expect($logged)->toBe([PlatformWebhook::NO_ACCOUNT])
        ->and(DB::table('payment_events')->count())->toBe(0)
        ->and(platformRow())->toBe($before);
});

it('words the section\'s prop, the refusals and the flashes of platform mode in Greek for a user at el, pending and active, and in English for a colleague at en', function () {
    User::query()->where('email', 'owner@luna.example')->update(['locale' => SiteLanguage::Greek->value]);
    $manager = User::query()->findOrFail(addTenantUser('manager@luna.example', 'manager'));
    $manager->forceFill(['locale' => SiteLanguage::English->value])->save();
    $fields = fn (array $payments, string $part): array => (array) data_get($payments, 'providers.0.fields.*.'.$part);
    $greekFields = fn (string $part): array => array_map(fn (CredentialField $field): string => adminLine($part === 'label' ? $field->label : $field->help, [], SiteLanguage::Greek), app(StripeAdapter::class)->descriptor()->credentialFields);
    $states = [];

    platformWrite($this, '/platform', ['provider' => 'stripe'])->assertStatus(400)
        ->assertJsonPath('data.detail', adminLine(PlatformConnectRequest::NOT_PLATFORM, [], SiteLanguage::Greek));

    // Pending, then active: the fields' words are the server's, the provider's requirements none.
    platformWrite($this, '/platform', ['provider' => FakePaymentAdapter::SLUG])->assertStatus(409);
    foreach ([null, 'taking'] as $standing) {
        if ($standing !== null) {
            platformDeliver($this, platformBody('evt_1', 'pdk_acct_1', $standing))->assertOk();
        }
        $payments = platformProp($this);
        expect($fields($payments, 'label'))->toBe($greekFields('label'))
            ->and($fields($payments, 'help'))->toBe($greekFields('help'))
            ->and(data_get($payments, 'connection.requirements'))->toBeNull();
        $states[] = data_get($payments, 'connection.state');
    }

    platformWrite($this, '', ['provider' => FakePaymentAdapter::SLUG, 'credentials' => paymentFakeCredentials()])->assertStatus(409)
        ->assertJsonPath('data.detail', adminLine(ConnectionModeTaken::PLATFORM, ['provider' => 'Paydesk'], SiteLanguage::Greek));
    $this->actingAs($manager)->from(tenantUrl('luna', '/admin/settings'))
        ->json('POST', tenantUrl('luna', '/admin/settings/payments'), ['provider' => FakePaymentAdapter::SLUG, 'credentials' => paymentFakeCredentials()], ['X-Inertia' => 'true'])
        ->assertStatus(409)->assertJsonPath('data.detail', adminLine(ConnectionModeTaken::PLATFORM, ['provider' => 'Paydesk']));
    platformWrite($this, '', [], 'DELETE')
        ->assertSessionHas('flash', ['kind' => 'success', 'message' => adminLine(PaymentsController::DISCONNECTED, [], SiteLanguage::Greek)]);

    platformWrite($this, '', ['provider' => FakePaymentAdapter::SLUG, 'credentials' => paymentFakeCredentials()])->assertRedirect();
    platformWrite($this, '/platform', ['provider' => FakePaymentAdapter::SLUG])->assertStatus(409)
        ->assertJsonPath('data.detail', adminLine(ConnectionModeTaken::OWN, ['provider' => 'Paydesk'], SiteLanguage::Greek));
    platformWrite($this, '', [], 'DELETE')->assertRedirect();

    paymentFake()->openFailsWith = 'open-token';
    platformWrite($this, '/platform', ['provider' => FakePaymentAdapter::SLUG])
        ->assertSessionHas('flash', ['kind' => 'error', 'message' => adminLine(PaymentsController::ONBOARDING_FAILED, [], SiteLanguage::Greek)]);
    paymentFake()->openFailsWith = null;

    // A pending row resumed while the platform's keys are unset.
    platformWrite($this, '/platform', ['provider' => FakePaymentAdapter::SLUG])->assertStatus(409);
    config(['payments.platform.'.FakePaymentAdapter::SLUG => ['api_token' => FakePaymentAdapter::PLATFORM_KEYS['api_token'], 'signing_phrase' => '']]);
    platformWrite($this, '/platform/resume')->assertStatus(409)
        ->assertJsonPath('data.detail', adminLine(PlatformUnavailable::DETAIL, [], SiteLanguage::Greek));

    expect($states)->toBe(['pending', 'active'])
        ->and(adminLine(PlatformUnavailable::DETAIL, [], SiteLanguage::Greek))->not->toBe(adminLine(PlatformUnavailable::DETAIL));
});
