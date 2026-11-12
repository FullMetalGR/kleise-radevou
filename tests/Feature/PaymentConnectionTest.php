<?php

use App\Application\Clock;
use App\Application\Payment\ConnectionCheck;
use App\Application\Payment\ConnectionMode;
use App\Application\Payment\ConnectionState;
use App\Application\Payment\Credentials;
use App\Application\Payment\NewConnection;
use App\Application\Payment\NullPaymentProvider;
use App\Application\Payment\PaymentConnection;
use App\Application\Payment\PaymentConnections;
use App\Application\Payment\ProviderDescriptor;
use App\Application\Payment\ProviderRegistry;
use App\Application\Payment\RegistrationRefused;
use App\Application\Payment\Stripe\StripeAdapter;
use App\Application\Payment\Stripe\StripePaymentProvider;
use App\Application\Payment\TenantPaymentProvider;
use App\Http\ErrorEnvelope;
use App\Infrastructure\Db\SettingsReader;
use App\Infrastructure\Payment\PaymentRoutes;
use App\Infrastructure\PaymentFactory;
use App\Tenancy\Tenant;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FakePaymentAdapter;
use Tests\Support\FakePaymentProvider;
use Tests\Support\FixedClock;
use Tests\Support\FixtureDay;
use Tests\TestCase;

pest()->use(DatabaseTruncation::class);

/*
 * The payment connection on luna's real rows: what the store writes and what it will not hand
 * back, who answers the port for the tenant, and the settlement rule through the real seam, the
 * booking app's own two requests, POST /holds and POST /bookings/{uuid}/confirm.
 *
 * Not wrapped in a transaction: a confirm enters the transaction runner, which refuses to open
 * inside another. luna's Colour is an online service and Haircut a pay-on-site one. The clock
 * stands on the Monday before the fixture's Tuesday.
 */

beforeEach(function () {
    dropDemoSchemas();
    seedDemo();
    app()->instance(Clock::class, FixedClock::at('2026-11-02 06:00:00'));
    Http::preventStrayRequests();
});

afterEach(function () {
    Http::assertNothingSent();
    dropDemoSchemas();
    DB::table('users')->delete();
    DB::table('tenants')->delete();
});

/**
 * The stored credentials column as the database holds it, read past the store.
 */
function connectionCredentialsRaw(): ?string
{
    $raw = DB::table('tenant_luna.payment_connections')->value('credentials');

    assert($raw === null || is_string($raw));

    return $raw;
}

function connectionProvider(): TenantPaymentProvider
{
    $provider = app(PaymentFactory::class)->provider(Tenant::query()->where('slug', 'luna')->sole());

    assert($provider instanceof TenantPaymentProvider);

    return $provider;
}

/**
 * A hold taken over the wire for one of luna's services with Elena, the whole answer.
 *
 * @return array<string, mixed>
 */
function connectionHold(TestCase $test, string $service, string $start = FixtureDay::START): array
{
    $ids = fn (string $table, string $name): mixed => DB::table('tenant_luna.'.$table)->where('name', $name)->value('id');
    $held = $test->postJson(tenantUrl('luna', '/api/v1/holds'), [
        'customer_name' => 'Casey Customer',
        'customer_email' => 'casey@example.com',
        'customer_phone' => '+30 210 0000000',
        'items' => [['service_id' => $ids('services', $service), 'staff_id' => $ids('staff', 'Elena Papadaki')]],
        'start' => (new DateTimeImmutable($start, new DateTimeZone(FixtureDay::ZONE)))->format(DateTimeImmutable::RFC3339),
    ])->assertCreated()->json();

    assert(is_array($held) && is_string($held['uuid']) && is_string($held['manage_token']));

    /** @var array<string, mixed> $held */
    return $held;
}

/**
 * @param  array<string, mixed>  $held
 * @return TestResponse<Response>
 */
function connectionConfirm(TestCase $test, array $held): TestResponse
{
    assert(is_string($held['uuid']));

    return $test->postJson(tenantUrl('luna', '/api/v1/bookings/'.$held['uuid'].'/confirm'), ['token' => $held['manage_token']]);
}

it('holds Stripe\'s descriptor and the fake\'s beside it in the application\'s registry, one registry and one fake for the whole application', function () {
    $registry = app(ProviderRegistry::class);

    expect(array_map(fn (ProviderDescriptor $descriptor): string => $descriptor->slug, $registry->descriptors()))->toBe(['stripe', FakePaymentAdapter::SLUG])
        ->and(app(ProviderRegistry::class))->toBe($registry)
        ->and(paymentFake())->toBe(app(FakePaymentAdapter::class));
});

it('registers Stripe alone where the environment names no provider beside it, which is production', function () {
    config(['payments.provider_beside' => null]);
    app()->forgetInstance(ProviderRegistry::class);

    expect(array_map(fn (ProviderDescriptor $descriptor): string => $descriptor->slug, app(ProviderRegistry::class)->descriptors()))->toBe(['stripe']);
});

it('refuses a configured class that is no adapter when the registry is built, naming the class', function () {
    config(['payments.provider_beside' => stdClass::class]);
    app()->forgetInstance(ProviderRegistry::class);

    expect(fn () => app(ProviderRegistry::class))->toThrow(RegistrationRefused::class, 'stdClass is not a payment adapter');
});

it('reads no connection for a tenant that has connected nothing', function () {
    expect(paymentConnections()->primary())->toBeNull();
});

it('encrypts the credentials before they reach the row: the column read raw holds no field in the clear, and the framework\'s encrypter opens it to exactly what was written', function () {
    connectPaymentFake();
    $raw = connectionCredentialsRaw();

    assert(is_string($raw));
    $envelope = base64_decode($raw, true);

    expect($envelope)->toBeString();

    foreach (paymentFakeCredentials() as $name => $value) {
        expect($raw)->not->toContain($value)
            ->and($raw)->not->toContain($name)
            ->and($envelope)->not->toContain($value)
            ->and($envelope)->not->toContain($name);
    }

    expect(json_decode(Crypt::decryptString($raw), true))->toBe(paymentFakeCredentials())
        ->and(DB::table('tenant_luna.payment_connections')->count())->toBe(1);
});

it('puts no credential in a statement\'s bindings, a log line or the read, whichever of the store\'s operations is called', function () {
    $bound = [];
    DB::listen(function (QueryExecuted $query) use (&$bound): void {
        $bound[] = $query->sql.' '.json_encode($query->bindings);
    });
    $logged = [];
    Log::listen(function (MessageLogged $message) use (&$logged): void {
        $logged[] = $message->message;
    });

    connectPaymentFake(ConnectionState::Active, true);
    $read = paymentConnections()->primary();
    paymentConnections()->landCheck(ConnectionCheck::failed('The provider refused the token.'), app(Clock::class)->now());
    paymentConnections()->setEnabled(false);
    paymentConnections()->setEnabled(true);
    paymentConnections()->rotateToken('hook-token-rotated');
    $token = paymentConnections()->webhookToken();
    paymentConnections()->remove();
    $said = implode("\n", $bound).serialize($read).json_encode($read).print_r($read, true).$token;

    foreach (paymentFakeCredentials() as $value) {
        expect($said)->not->toContain($value);
    }

    expect($bound)->not->toBe([])
        ->and($logged)->toBe([])
        ->and(implode("\n", $bound))->not->toContain('select credentials')
        ->and(array_map(fn (ReflectionMethod $method): string => $method->getName(), (new ReflectionClass(PaymentConnections::class))->getMethods()))->toBe(['primary', 'write', 'recordCheck', 'landCheck', 'setEnabled', 'rotateToken', 'remove', 'webhookToken', 'withCredentials', 'landStanding'])
        ->and(array_map(fn (ReflectionProperty $property): string => $property->getName(), (new ReflectionClass(PaymentConnection::class))->getProperties()))
        ->toBe(['provider', 'mode', 'state', 'accountRef', 'requirements', 'lastCheckedAt', 'lastCheckOk', 'hasCredentials']);
});

it('lends the credentials only to the adapter of the row\'s own provider, for that call alone, on a value that cannot be serialized, logged or dumped', function () {
    connectPaymentFake();
    $logged = [];
    Log::listen(function (MessageLogged $message) use (&$logged): void {
        $logged[] = $message->message.json_encode($message->context);
    });
    $stripe = app(ProviderRegistry::class)->adapter('stripe');

    assert($stripe !== null);

    $lent = null;
    $answer = paymentConnections()->withCredentials(paymentFake(), function (Credentials $credentials) use (&$lent): string {
        $lent = $credentials;

        return $credentials->field('signing_phrase');
    });
    $dumped = print_r($lent, true).var_export(json_encode($lent), true);

    expect($answer)->toBe(paymentFakeCredentials()['signing_phrase'])
        ->and(paymentConnections()->withCredentials($stripe, fn (Credentials $credentials): string => 'decrypted for the wrong provider'))->toBeNull()
        ->and(fn () => serialize($lent))->toThrow(LogicException::class, 'Credentials are lent for one call and are never serialized.')
        ->and($logged)->toBe([]);

    foreach (paymentFakeCredentials() as $value) {
        expect($dumped)->not->toContain($value);
    }
});

it('reads no credential for a platform connection, which holds none to lend', function () {
    paymentConnections()->write(new NewConnection(FakePaymentAdapter::SLUG, ConnectionMode::Platform, ConnectionState::Active, null, 'acct_luna_7'));

    expect(paymentConnections()->withCredentials(paymentFake(), fn (Credentials $credentials): bool => true))->toBeNull();
});

it('writes the connection\'s central routing row in the same transaction, holding no credential, and replaces it with the connection', function () {
    connectPaymentFake();
    $luna = Tenant::query()->where('slug', 'luna')->sole()->id;
    $route = fn (): array => array_map(fn ($row): array => (array) $row, DB::table('payment_routes')->get(['tenant_id', 'provider', 'webhook_token', 'account_ref'])->all());

    expect($route())->toBe([['tenant_id' => $luna, 'provider' => FakePaymentAdapter::SLUG, 'webhook_token' => 'hook-token-luna', 'account_ref' => null]])
        ->and(DB::select("select column_name from information_schema.columns where table_schema = 'public' and table_name = 'payment_routes' order by ordinal_position"))
        ->toEqual([(object) ['column_name' => 'id'], (object) ['column_name' => 'tenant_id'], (object) ['column_name' => 'provider'], (object) ['column_name' => 'webhook_token'], (object) ['column_name' => 'account_ref'], (object) ['column_name' => 'created_at'], (object) ['column_name' => 'updated_at']]);

    paymentConnections()->write(new NewConnection(FakePaymentAdapter::SLUG, ConnectionMode::Platform, ConnectionState::Pending, null, 'acct_luna_7'));

    expect($route())->toBe([['tenant_id' => $luna, 'provider' => FakePaymentAdapter::SLUG, 'webhook_token' => null, 'account_ref' => 'acct_luna_7']]);
});

it('leaves the connection and its route as they stood when the routing row cannot be written, the two writes being one transaction', function () {
    connectPaymentFake();
    connectPaymentFake(ConnectionState::Active, null, 'pier');

    // pier's token is luna's: the routing row's unique key refuses it, and the connection
    // write it shares a transaction with goes with it.
    expect(fn () => paymentConnections('luna')->write(new NewConnection(FakePaymentAdapter::SLUG, ConnectionMode::Own, ConnectionState::Disabled, paymentFakeCredentials(), null, 'hook-token-pier')))
        ->toThrow(UniqueConstraintViolationException::class);

    expect(DB::table('tenant_luna.payment_connections')->value('state'))->toBe('active')
        ->and(DB::table('tenant_luna.payment_connections')->value('webhook_token'))->toBe('hook-token-luna')
        ->and(DB::table('payment_routes')->orderBy('webhook_token')->pluck('webhook_token')->all())->toBe(['hook-token-luna', 'hook-token-pier']);
});

it('reads an own-mode connection back as its provider, mode, state and stored check, saying only that credentials are stored', function () {
    connectPaymentFake(ConnectionState::Restricted);
    $read = paymentConnections()->primary();

    assert($read !== null);

    expect([$read->provider, $read->mode, $read->state, $read->accountRef, $read->requirements, $read->lastCheckedAt, $read->lastCheckOk, $read->hasCredentials])
        ->toBe([FakePaymentAdapter::SLUG, ConnectionMode::Own, ConnectionState::Restricted, null, null, null, null, true])
        ->and(DB::table('tenant_luna.payment_connections')->value('webhook_token'))->toBe('hook-token-luna')
        ->and(DB::table('tenant_luna.payment_connections')->value('is_primary'))->toBeTrue();
});

it('stores a platform-mode connection with its account reference and no credentials at all', function () {
    paymentConnections()->write(new NewConnection(FakePaymentAdapter::SLUG, ConnectionMode::Platform, ConnectionState::Pending, null, 'acct_luna_7'));
    DB::table('tenant_luna.payment_connections')->update(['requirements' => '{"currently_due": ["business_profile.url"]}']);
    $read = paymentConnections()->primary();

    assert($read !== null);

    expect([$read->mode, $read->state, $read->accountRef, $read->hasCredentials])->toBe([ConnectionMode::Platform, ConnectionState::Pending, 'acct_luna_7', false])
        ->and($read->requirements)->toBe(['currently_due' => ['business_profile.url']])
        ->and(connectionCredentialsRaw())->toBeNull();
});

it('stores the result of a check and when it was made, and reads the instant back in UTC', function () {
    connectPaymentFake();
    $at = new DateTimeImmutable('2026-11-02 08:00:00.250000', new DateTimeZone(FixtureDay::ZONE));

    paymentConnections()->recordCheck(false, $at);
    $read = paymentConnections()->primary();

    assert($read !== null && $read->lastCheckedAt !== null);

    expect($read->lastCheckOk)->toBeFalse()
        ->and($read->lastCheckedAt->format('Y-m-d H:i:s.u P'))->toBe('2026-11-02 06:00:00.250000 +00:00')
        ->and($read->works())->toBeFalse();
});

it('keeps one row and marks it primary: a second write replaces the first, its stored check with it', function () {
    connectPaymentFake(ConnectionState::Active, false);

    paymentConnections()->write(new NewConnection('stripe', ConnectionMode::Platform, ConnectionState::Pending, null, 'acct_1'));
    $read = paymentConnections()->primary();

    assert($read !== null);

    expect(DB::table('tenant_luna.payment_connections')->count())->toBe(1)
        ->and([$read->provider, $read->mode, $read->state, $read->lastCheckOk, $read->lastCheckedAt, $read->hasCredentials])->toBe(['stripe', ConnectionMode::Platform, ConnectionState::Pending, null, null, false]);
});

it('answers the tenant with the null provider for no row and for every row that does not work, and with the fake only for an active row whose stored check has not failed, asking nobody; the settings read says the tenant takes payments exactly then, the approved key\'s lock', function (?ConnectionState $state, ?bool $checkOk, bool $adapter) {
    if ($state !== null) {
        connectPaymentFake($state, $checkOk);
    }

    expect(connectionProvider()->current())->toBeInstanceOf($adapter ? FakePaymentProvider::class : NullPaymentProvider::class)
        ->and(connectionProvider()->isAvailable())->toBe($adapter)
        ->and(app(SettingsReader::class)->read(Tenant::query()->where('slug', 'luna')->sole())->takesPayments)->toBe($adapter)
        ->and(paymentFake()->asked)->toBe([]);
})->with([
    'no row' => [null, null, false],
    'pending' => [ConnectionState::Pending, null, false],
    'restricted' => [ConnectionState::Restricted, true, false],
    'disabled' => [ConnectionState::Disabled, true, false],
    'active, the stored check failed' => [ConnectionState::Active, false, false],
    'active, never checked' => [ConnectionState::Active, null, true],
    'active, the stored check passed' => [ConnectionState::Active, true, true],
]);

it('answers an active own-mode Stripe connection with Stripe\'s provider, available, and asks Stripe nothing to say so', function () {
    paymentConnections()->write(new NewConnection('stripe', ConnectionMode::Own, ConnectionState::Active, ['secret_key' => 'sk_test_never_called', 'webhook_secret' => 'whsec_never_read']));
    $current = connectionProvider()->current();

    expect($current)->toBeInstanceOf(StripePaymentProvider::class)
        ->and($current->isAvailable())->toBeTrue();
});

it('degrades end to end: with nothing that works connected an online service is held and confirmed exactly as a pay-on-site one is, and the hold answer carries no checkout_url key but says the online one is paid at the venue', function (?ConnectionState $state, ?bool $checkOk) {
    if ($state !== null) {
        connectPaymentFake($state, $checkOk);
    }

    $onsite = connectionHold($this, 'Haircut', FixtureDay::DATE.' 09:00');
    $online = connectionHold($this, 'Colour', FixtureDay::DATE.' 14:00');
    $onsiteConfirmed = connectionConfirm($this, $onsite)->assertOk()->json();
    $onlineConfirmed = connectionConfirm($this, $online)->assertOk()->json();

    assert(is_array($onsiteConfirmed) && is_array($onlineConfirmed));

    expect($online)->not->toHaveKey('checkout_url')
        ->and($online['pays_at_venue'] ?? null)->toBeTrue()
        ->and($onsite)->not->toHaveKey('pays_at_venue')
        ->and(array_keys($online))->toBe([...array_keys($onsite), 'pays_at_venue'])
        ->and([$online['status'], $online['hold_class']])->toBe([$onsite['status'], $onsite['hold_class']])
        ->and(array_keys($onlineConfirmed))->toBe(array_keys($onsiteConfirmed))
        ->and($onlineConfirmed)->not->toHaveKey('checkout_url')
        ->and([$onlineConfirmed['status'], $onlineConfirmed['hold_class'], $onlineConfirmed['hold_expires_at']])->toBe(['confirmed', null, null])
        ->and([$onsiteConfirmed['status'], $onsiteConfirmed['hold_class'], $onsiteConfirmed['hold_expires_at']])->toBe(['confirmed', null, null])
        ->and(DB::table('tenant_luna.bookings')->orderBy('id')->pluck('payment_mode')->all())->toBe(['onsite', 'online'])
        ->and(DB::table('tenant_luna.bookings')->pluck('status')->unique()->all())->toBe(['confirmed'])
        ->and(DB::table('tenant_luna.bookings')->whereNotNull('payment_ref')->count())->toBe(0)
        ->and(DB::table('tenant_luna.audit_log')->where('action', 'confirmed')->count())->toBe(2)
        ->and(paymentFake()->asked)->toBe([]);
})->with([
    'nothing connected' => [null, null],
    'an onboarding not finished' => [ConnectionState::Pending, null],
    'a connection the provider restricted' => [ConnectionState::Restricted, true],
    'a connection switched off' => [ConnectionState::Disabled, true],
    'an active connection whose stored check failed' => [ConnectionState::Active, false],
]);

it('refuses the confirm of an online booking with payment_required at 402 while the fake is connected and active, and writes nothing', function () {
    connectPaymentFake();
    $online = connectionHold($this, 'Colour');

    connectionConfirm($this, $online)->assertStatus(402)->assertExactJson([
        'code' => 'payment_required',
        'message' => 'payment_required',
        'data' => ['status' => 402, 'detail' => apiSentence(ErrorEnvelope::PAYMENT_REQUIRED_DETAIL)],
    ]);

    expect((array) DB::table('tenant_luna.bookings')->where('uuid', $online['uuid'])->first(['status', 'hold_class', 'payment_mode']))->toBe(['status' => 'pending', 'hold_class' => 'checkout', 'payment_mode' => 'online'])
        ->and(DB::table('tenant_luna.audit_log')->where('action', 'confirmed')->count())->toBe(0);
});

it('confirms a free and a pay-on-site booking as before while the fake is connected and active: cash is never a connection', function () {
    connectPaymentFake(ConnectionState::Active, true);
    DB::table('tenant_luna.services')->where('name', 'Colour')->update(['payment_mode' => 'free', 'price_minor' => 0]);
    $onsite = connectionHold($this, 'Haircut', FixtureDay::DATE.' 09:00');
    $free = connectionHold($this, 'Colour', FixtureDay::DATE.' 14:00');

    connectionConfirm($this, $onsite)->assertOk()->assertJsonPath('status', 'confirmed');
    connectionConfirm($this, $free)->assertOk()->assertJsonPath('status', 'confirmed');

    expect(DB::table('tenant_luna.bookings')->orderBy('id')->pluck('payment_mode')->all())->toBe(['onsite', 'free'])
        ->and(DB::table('tenant_luna.bookings')->pluck('status')->unique()->all())->toBe(['confirmed']);
});

it('reads the booking\'s own payment mode and never the service\'s: a booking held online stays payable after its service is switched to pay on site', function () {
    connectPaymentFake();
    $online = connectionHold($this, 'Colour');
    DB::table('tenant_luna.services')->where('name', 'Colour')->update(['payment_mode' => 'onsite']);

    connectionConfirm($this, $online)->assertStatus(402)->assertJsonPath('code', 'payment_required');
});

it('lets the confirm through the moment the connection stops working, since the answer is the row\'s and is read when it is asked', function () {
    connectPaymentFake();
    $online = connectionHold($this, 'Colour');

    connectionConfirm($this, $online)->assertStatus(402);
    paymentConnections()->recordCheck(false, FixedClock::at('2026-11-02 06:00:30')->now());

    connectionConfirm($this, $online)->assertOk()->assertJsonPath('status', 'confirmed');
});

/**
 * luna's connection row as the database holds it, read past the store: the state, what the
 * provider said, the stored check and its instant, the token and the credentials' ciphertext.
 *
 * @return array{state: mixed, requirements: mixed, last_check_ok: mixed, last_checked_at: mixed, webhook_token: mixed, credentials: mixed}
 */
function connectionRow(): array
{
    $row = DB::table('tenant_luna.payment_connections')->selectRaw("state, requirements::text as requirements, last_check_ok, to_char(last_checked_at at time zone 'UTC', 'YYYY-MM-DD HH24:MI:SS') as last_checked_at, webhook_token, credentials")->sole();

    /** @var array{state: mixed, requirements: mixed, last_check_ok: mixed, last_checked_at: mixed, webhook_token: mixed, credentials: mixed} */
    return (array) $row;
}

/**
 * The tenant id each routing row names, by its token.
 *
 * @return array<string, int>
 */
function connectionRoutes(): array
{
    /** @var array<string, int> */
    return DB::table('payment_routes')->whereNotNull('webhook_token')->orderBy('webhook_token')->pluck('tenant_id', 'webhook_token')->all();
}

it('lands a passing check active with requirements cleared and a failing one restricted with the reason verbatim, the result and its instant recorded either way', function (ConnectionState $from, ConnectionCheck $check, string $state, ?string $requirements) {
    connectPaymentFake($from, false);
    DB::table('tenant_luna.payment_connections')->update(['requirements' => '{"reason": "an earlier refusal"}']);

    expect(paymentConnections()->landCheck($check, new DateTimeImmutable('2026-11-02 08:00:00', new DateTimeZone(FixtureDay::ZONE))))->toBeTrue();
    $row = connectionRow();

    expect([$row['state'], $row['requirements'], $row['last_check_ok'], $row['last_checked_at']])->toBe([$state, $requirements, $check->ok, '2026-11-02 06:00:00']);
})->with([
    'restricted, passed' => [ConnectionState::Restricted, ConnectionCheck::passed(), 'active', null],
    'active, failed' => [ConnectionState::Active, ConnectionCheck::failed('The token "pdk_x" was refused.'), 'restricted', '{"reason": "The token \\"pdk_x\\" was refused."}'],
    'restricted, failed again' => [ConnectionState::Restricted, ConnectionCheck::failed('Still refused.'), 'restricted', '{"reason": "Still refused."}'],
    'disabled, passed: the switch stays the tenant\'s' => [ConnectionState::Disabled, ConnectionCheck::passed(), 'disabled', null],
    'disabled, failed: the reason kept for a re-enable' => [ConnectionState::Disabled, ConnectionCheck::failed('Refused.'), 'disabled', '{"reason": "Refused."}'],
    'pending, passed: onboarding\'s state stays its own' => [ConnectionState::Pending, ConnectionCheck::passed(), 'pending', null],
]);

it('lands a failed check naming its reason\'s key with the key and the values beside the reason, an empty set of values an object', function (?string $status) {
    connectPaymentFake(ConnectionState::Active, true);
    $values = $status === null ? [] : ['status' => $status];

    paymentConnections()->landCheck(ConnectionCheck::failed('A recorded reason.', StripeAdapter::REASON_STATUS, $values), new DateTimeImmutable('2026-11-02 08:00:00', new DateTimeZone(FixtureDay::ZONE)));
    $stored = connectionRow()['requirements'];

    expect(is_string($stored) ? json_decode($stored, false, 512, JSON_THROW_ON_ERROR) : null)
        ->toEqual((object) ['reason' => 'A recorded reason.', 'key' => StripeAdapter::REASON_STATUS, 'values' => (object) $values])
        ->and(paymentConnections()->primary()?->requirements)->toEqual(['reason' => 'A recorded reason.', 'key' => StripeAdapter::REASON_STATUS, 'values' => $values]);
})->with([
    'with a value' => ['503'],
    'with none' => [null],
]);

it('lands no check, and answers false, where nothing is connected', function () {
    expect(paymentConnections()->landCheck(ConnectionCheck::passed(), app(Clock::class)->now()))->toBeFalse()
        ->and(DB::table('tenant_luna.payment_connections')->count())->toBe(0);
});

it('switches a connection off whatever its state, and back on to active where the stored check passed or none has run and to restricted where it failed', function (ConnectionState $from, ?bool $checkOk, bool $enabled, string $state) {
    connectPaymentFake($from, $checkOk);

    expect(paymentConnections()->setEnabled($enabled))->toBeTrue()
        ->and(connectionRow()['state'])->toBe($state)
        ->and(connectionRow()['last_check_ok'])->toBe($checkOk);
})->with([
    'off from active' => [ConnectionState::Active, true, false, 'disabled'],
    'off from restricted' => [ConnectionState::Restricted, false, false, 'disabled'],
    'on, the check passed' => [ConnectionState::Disabled, true, true, 'active'],
    'on, no check has run' => [ConnectionState::Disabled, null, true, 'active'],
    'on, the check failed' => [ConnectionState::Disabled, false, true, 'restricted'],
    'on from active: nothing moves' => [ConnectionState::Active, true, true, 'active'],
    'on from restricted: nothing moves' => [ConnectionState::Restricted, true, true, 'restricted'],
]);

it('switches nothing, and answers false, where nothing is connected', function (bool $enabled) {
    expect(paymentConnections()->setEnabled($enabled))->toBeFalse();
})->with([true, false]);

it('reads the webhook token of an own-mode connection, and none for a platform connection or no connection', function () {
    expect(paymentConnections()->webhookToken())->toBeNull();

    connectPaymentFake();
    expect(paymentConnections()->webhookToken())->toBe('hook-token-luna');

    paymentConnections()->write(new NewConnection(FakePaymentAdapter::SLUG, ConnectionMode::Platform, ConnectionState::Pending, null, 'acct_luna_7'));
    expect(paymentConnections()->webhookToken())->toBeNull();
});

it('rotates the token on the row and its routing row together, the credentials, the state and the check untouched, and the old token then names no tenant', function () {
    connectPaymentFake(ConnectionState::Restricted, false);
    connectPaymentFake(ConnectionState::Active, null, 'pier');
    DB::table('tenant_luna.payment_connections')->update(['requirements' => '{"reason": "Refused."}']);
    $before = connectionRow();
    $luna = Tenant::query()->where('slug', 'luna')->sole()->id;
    $pier = Tenant::query()->where('slug', 'pier')->sole()->id;

    expect(paymentConnections()->rotateToken('hook-token-luna-2'))->toBeTrue();

    expect(connectionRow())->toBe(array_replace($before, ['webhook_token' => 'hook-token-luna-2']))
        ->and(connectionRoutes())->toBe(['hook-token-luna-2' => $luna, 'hook-token-pier' => $pier])
        ->and(app(PaymentRoutes::class)->byToken(FakePaymentAdapter::SLUG, 'hook-token-luna'))->toBeNull()
        ->and(app(PaymentRoutes::class)->byToken(FakePaymentAdapter::SLUG, 'hook-token-luna-2'))->toBe($luna);
});

it('rotates nothing, and answers false, for a platform connection or no connection', function () {
    expect(paymentConnections()->rotateToken('hook-token-new'))->toBeFalse();

    paymentConnections()->write(new NewConnection(FakePaymentAdapter::SLUG, ConnectionMode::Platform, ConnectionState::Pending, null, 'acct_luna_7'));

    expect(paymentConnections()->rotateToken('hook-token-new'))->toBeFalse()
        ->and(DB::table('payment_routes')->pluck('account_ref')->all())->toBe(['acct_luna_7'])
        ->and(DB::table('tenant_luna.payment_connections')->value('webhook_token'))->toBeNull();
});

it('leaves the token where it stood on both rows when the routing row cannot take the new one, the rotation being one transaction', function () {
    connectPaymentFake();
    connectPaymentFake(ConnectionState::Active, null, 'pier');

    expect(fn () => paymentConnections()->rotateToken('hook-token-pier'))->toThrow(UniqueConstraintViolationException::class);

    expect(connectionRow()['webhook_token'])->toBe('hook-token-luna')
        ->and(array_keys(connectionRoutes()))->toBe(['hook-token-luna', 'hook-token-pier']);
});

it('removes the connection and its routing row, leaves another tenant\'s alone, and answers false the second time', function () {
    connectPaymentFake();
    connectPaymentFake(ConnectionState::Active, null, 'pier');

    expect(paymentConnections()->remove())->toBeTrue()
        ->and(DB::table('tenant_luna.payment_connections')->count())->toBe(0)
        ->and(array_keys(connectionRoutes()))->toBe(['hook-token-pier'])
        ->and(app(PaymentRoutes::class)->byToken(FakePaymentAdapter::SLUG, 'hook-token-luna'))->toBeNull()
        ->and(paymentConnections()->primary())->toBeNull()
        ->and(paymentConnections()->remove())->toBeFalse();
});

it('keeps the connection and its routing row both when the second removal fails, the removal being one transaction', function () {
    connectPaymentFake();
    DB::listen(function (QueryExecuted $query): void {
        if (str_starts_with($query->sql, 'delete from public.payment_routes')) {
            throw new RuntimeException('The routing row could not be removed.');
        }
    });

    expect(fn () => paymentConnections()->remove())->toThrow(RuntimeException::class, 'The routing row could not be removed.');

    expect(DB::table('tenant_luna.payment_connections')->count())->toBe(1)
        ->and(array_keys(connectionRoutes()))->toBe(['hook-token-luna']);
});
