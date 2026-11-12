<?php

use App\Application\Payment\AccountStanding;
use App\Application\Payment\CheckoutRequest;
use App\Application\Payment\CheckoutSession;
use App\Application\Payment\ConnectionCheck;
use App\Application\Payment\ConnectionMode;
use App\Application\Payment\ConnectionState;
use App\Application\Payment\CredentialField;
use App\Application\Payment\Credentials;
use App\Application\Payment\FeeMechanism;
use App\Application\Payment\NewConnection;
use App\Application\Payment\NotificationRefused;
use App\Application\Payment\NullPaymentProvider;
use App\Application\Payment\OnboardingEntry;
use App\Application\Payment\PaymentAdapter;
use App\Application\Payment\PaymentConnection;
use App\Application\Payment\PaymentConnections;
use App\Application\Payment\PaymentNotice;
use App\Application\Payment\PaymentProvider;
use App\Application\Payment\PlatformNotice;
use App\Application\Payment\ProviderDescriptor;
use App\Application\Payment\ProviderRegistry;
use App\Application\Payment\RegistrationRefused;
use App\Application\Payment\SettlesByProvider;
use App\Application\Payment\Stripe\StripeAdapter;
use App\Application\Payment\TenantPaymentProvider;
use App\Application\Payment\TestConnection;
use App\Application\Payment\WebhookSignature;
use App\Domain\Enum\PaymentMode;
use Tests\Support\FakePaymentAdapter;
use Tests\Support\FakePaymentProvider;
use Tests\Support\FakeStripePaymentTransport;
use Tests\Support\FixedClock;
use Tests\Support\FixedPlatform;
use Tests\Support\RecordingPaymentLog;
use Tests\Support\SnapshotFixture;

/*
 * The payment port, the null provider, the provider registry and who answers the port for a
 * tenant, with no database and no container: the store here is one row held in memory.
 */

/**
 * A descriptor that registers, with one of its eleven fields replaced.
 *
 * @param  array<mixed>  $override  by field name
 */
function paymentDescriptor(array $override = []): ProviderDescriptor
{
    /** @var array{slug: string, name: string, modes: list<ConnectionMode>, credentialFields: list<CredentialField>, onboardingEntry: OnboardingEntry|null, feeMechanism: FeeMechanism, webhookSignature: WebhookSignature, supportsRefundFlag: bool, currencies: list<string>, rateUrl: string, canExpireCheckout: bool|null} $fields */
    $fields = [...[
        'slug' => 'tillbox',
        'name' => 'Tillbox',
        'modes' => [ConnectionMode::Platform, ConnectionMode::Own],
        'credentialFields' => [new CredentialField('key', 'Key', 'The key.', true)],
        'onboardingEntry' => OnboardingEntry::HostedLink,
        'feeMechanism' => FeeMechanism::Agreement,
        'webhookSignature' => WebhookSignature::signedHeader('Tillbox-Signature'),
        'supportsRefundFlag' => false,
        'currencies' => ['EUR'],
        'rateUrl' => 'https://tillbox.test/pricing',
        'canExpireCheckout' => false,
    ], ...$override];

    return new ProviderDescriptor(...$fields);
}

function paymentAdapterOf(ProviderDescriptor $descriptor): PaymentAdapter
{
    return new class($descriptor) implements PaymentAdapter
    {
        public function __construct(private ProviderDescriptor $descriptor) {}

        public function descriptor(): ProviderDescriptor
        {
            return $this->descriptor;
        }

        public function provider(PaymentConnection $connection, PaymentConnections $connections): PaymentProvider
        {
            return new NullPaymentProvider;
        }

        public function openAccount(Credentials $platform): string
        {
            throw new LogicException('Not asked of this adapter.');
        }

        public function onboardingLink(Credentials $platform, string $accountRef, string $returnUrl): string
        {
            throw new LogicException('Not asked of this adapter.');
        }

        public function verifyPlatform(string $body, string $signature, Credentials $platform): PlatformNotice
        {
            throw NotificationRefused::because('Not asked of this adapter.');
        }

        public function check(Credentials $credentials): ConnectionCheck
        {
            return ConnectionCheck::passed();
        }

        public function verify(string $body, string $signature, Credentials $credentials): PaymentNotice
        {
            throw NotificationRefused::because('This adapter verifies nothing.');
        }
    };
}

/**
 * A store holding the one row given, or none, lending the fields given to a check where a case
 * gives any, and keeping the check that landed for the case to read.
 *
 * @param  array<string, string>|null  $lends
 */
function paymentRow(?PaymentConnection $row, ?array $lends = null): PaymentConnections
{
    return new class($row, $lends) implements PaymentConnections
    {
        public int $reads = 0;

        public ?ConnectionCheck $landed = null;

        /**
         * @param  array<string, string>|null  $lends
         */
        public function __construct(private ?PaymentConnection $row, private ?array $lends) {}

        public function primary(): ?PaymentConnection
        {
            $this->reads++;

            return $this->row;
        }

        public function write(NewConnection $connection): void {}

        public function recordCheck(bool $ok, DateTimeImmutable $at): void {}

        public function landCheck(ConnectionCheck $check, DateTimeImmutable $at): bool
        {
            $this->landed = $check;

            return false;
        }

        public function landStanding(string $accountRef, AccountStanding $standing, DateTimeImmutable $at): bool
        {
            return false;
        }

        public function setEnabled(bool $enabled): bool
        {
            return false;
        }

        public function rotateToken(string $token): bool
        {
            return false;
        }

        public function remove(): bool
        {
            return false;
        }

        public function webhookToken(): ?string
        {
            return null;
        }

        public function withCredentials(PaymentAdapter $adapter, Closure $call): mixed
        {
            return $this->lends === null ? null : $call(new Credentials($this->lends));
        }
    };
}

function paymentConnection(ConnectionState $state, ?bool $checkOk = null, string $provider = FakePaymentAdapter::SLUG, ConnectionMode $mode = ConnectionMode::Own): PaymentConnection
{
    return new PaymentConnection($provider, $mode, $state, null, null, null, $checkOk, $mode === ConnectionMode::Own);
}

function paymentRegistry(): ProviderRegistry
{
    $registry = new ProviderRegistry;
    $registry->register(new StripeAdapter(new FakeStripePaymentTransport, FixedClock::at('2026-11-01 09:00:00'), new FixedPlatform, new RecordingPaymentLog));
    $registry->register(new FakePaymentAdapter);

    return $registry;
}

it('has the five operations on the port and no sixth', function () {
    $operations = array_map(fn (ReflectionMethod $method): string => $method->getName(), (new ReflectionClass(PaymentProvider::class))->getMethods());

    expect($operations)->toBe(['isAvailable', 'checkoutUrl', 'paymentRef', 'flagPayment', 'expireCheckout']);
});

it('answers every operation from the null provider and throws from none: nothing available, nowhere to send the customer, no payment object, a note dropped', function () {
    $null = new NullPaymentProvider;
    $booking = SnapshotFixture::booking();

    $null->flagPayment($booking, 'pd_1', 'The slot is released and nothing has been refunded.');
    $null->expireCheckout($booking, 'pd_1');

    expect($null->isAvailable())->toBeFalse()
        ->and($null->checkoutUrl(new CheckoutRequest($booking, null, 'https://luna.test/manage/x?token=t', $booking->startsAt())))->toBeNull()
        ->and($null->paymentRef($booking))->toBeNull();
});

it('declares the eleven fields on a descriptor, by name and in order', function () {
    $fields = array_map(fn (ReflectionParameter $field): string => $field->getName(), (new ReflectionMethod(ProviderDescriptor::class, '__construct'))->getParameters());

    expect($fields)->toBe(['slug', 'name', 'modes', 'credentialFields', 'onboardingEntry', 'feeMechanism', 'webhookSignature', 'supportsRefundFlag', 'currencies', 'rateUrl', 'canExpireCheckout']);
});

it('holds Stripe and the fake side by side, each read by its slug, the fake with credential fields that differ from Stripe\'s in number and in name', function () {
    $registry = paymentRegistry();
    $stripe = $registry->descriptor('stripe');
    $fake = $registry->descriptor(FakePaymentAdapter::SLUG);

    assert($stripe !== null && $fake !== null);
    $stripeFields = array_map(fn (CredentialField $field): string => $field->name, $stripe->credentialFields);
    $fakeFields = array_map(fn (CredentialField $field): string => $field->name, $fake->credentialFields);

    expect(array_map(fn (ProviderDescriptor $descriptor): string => $descriptor->slug, $registry->descriptors()))->toBe(['stripe', FakePaymentAdapter::SLUG])
        ->and($registry->descriptor('nobody'))->toBeNull()
        ->and($stripe->name)->toBe('Stripe')
        ->and($stripe->modes)->toBe([ConnectionMode::Platform, ConnectionMode::Own])
        ->and($stripeFields)->toBe(['secret_key', 'webhook_secret'])
        ->and(array_map(fn (CredentialField $field): bool => $field->secret, $stripe->credentialFields))->toBe([true, true])
        ->and($stripe->onboardingEntry)->toBe(OnboardingEntry::HostedLink)
        ->and($stripe->feeMechanism)->toBe(FeeMechanism::Parameter)
        ->and($stripe->webhookSignature->header)->toBe('Stripe-Signature')
        ->and($stripe->supportsRefundFlag)->toBeTrue()
        ->and($stripe->canExpireCheckout)->toBeTrue()
        ->and($fake->canExpireCheckout)->toBeTrue()
        ->and($stripe->currencies)->toContain('EUR')
        ->and($stripe->rateUrl)->toBe('https://stripe.com/pricing')
        ->and($fake->rateUrl)->toBe('https://paydesk.test/pricing')
        ->and($fake->modes)->toBe([ConnectionMode::Platform, ConnectionMode::Own])
        ->and($fakeFields)->toHaveCount(3)
        ->and(array_intersect($fakeFields, $stripeFields))->toBe([])
        ->and(array_map(fn (CredentialField $field): bool => $field->secret, $fake->credentialFields))->toBe([false, true, true]);
});

it('refuses a descriptor that leaves a field out when it is registered, naming the provider and the field, and holds nothing of it', function (array $override, string $field) {
    $registry = new ProviderRegistry;

    expect(fn () => $registry->register(paymentAdapterOf(paymentDescriptor($override))))
        ->toThrow(RegistrationRefused::class, sprintf('does not declare %s.', $field))
        ->and($registry->descriptors())->toBe([]);
})->with([
    'no slug' => [['slug' => ''], 'slug'],
    'a slug no URL could carry' => [['slug' => 'Till Box'], 'slug'],
    'no name' => [['name' => ' '], 'name'],
    'no mode' => [['modes' => []], 'modes'],
    'a mode twice' => [['modes' => [ConnectionMode::Own, ConnectionMode::Own]], 'modes'],
    'own mode with no credential field' => [['credentialFields' => []], 'credentialFields'],
    'a credential field with no label' => [['credentialFields' => [new CredentialField('key', '', 'The key.', true)]], 'credentialFields'],
    'a credential field with no help' => [['credentialFields' => [new CredentialField('key', 'Key', '', true)]], 'credentialFields'],
    'two credential fields of one name' => [['credentialFields' => [new CredentialField('key', 'Key', 'The key.', true), new CredentialField('key', 'Other', 'The other.', false)]], 'credentialFields'],
    'platform mode with no way in' => [['onboardingEntry' => null], 'onboardingEntry'],
    'platform mode with no fee mechanism' => [['feeMechanism' => FeeMechanism::None], 'feeMechanism'],
    'no signature header' => [['webhookSignature' => WebhookSignature::signedHeader('')], 'webhookSignature'],
    'no currency' => [['currencies' => []], 'currencies'],
    'a currency that is no code' => [['currencies' => ['EUR', 'euro']], 'currencies'],
    'no published rate' => [['rateUrl' => ' '], 'rateUrl'],
    'a published rate that is no address' => [['rateUrl' => 'the pricing page'], 'rateUrl'],
    'a published rate off https' => [['rateUrl' => 'http://tillbox.test/pricing'], 'rateUrl'],
    'a published rate that runs script' => [['rateUrl' => 'javascript:alert(1)'], 'rateUrl'],
    'no answer on expiring a checkout session' => [['canExpireCheckout' => null], 'canExpireCheckout'],
]);

it('registers the fake declaring that it can expire a checkout session and declaring that it cannot, and refuses it declaring neither, naming the field', function () {
    $declares = new FakePaymentAdapter;
    $declaresNot = new FakePaymentAdapter;
    $declaresNot->canExpireCheckout = false;
    $silent = new FakePaymentAdapter;
    $silent->canExpireCheckout = null;
    $present = new ProviderRegistry;
    $absent = new ProviderRegistry;
    $present->register($declares);
    $absent->register($declaresNot);

    expect($present->descriptor(FakePaymentAdapter::SLUG)?->canExpireCheckout)->toBeTrue()
        ->and($absent->descriptor(FakePaymentAdapter::SLUG)?->canExpireCheckout)->toBeFalse()
        ->and(fn () => (new ProviderRegistry)->register($silent))->toThrow(RegistrationRefused::class, 'does not declare canExpireCheckout.');
});

it('registers an own-only provider with no onboarding entry and no fee mechanism, and a platform-only one with no credential field, since each declares all its modes need', function () {
    $registry = new ProviderRegistry;

    $registry->register(paymentAdapterOf(paymentDescriptor(['slug' => 'acquirer', 'modes' => [ConnectionMode::Own], 'onboardingEntry' => null, 'feeMechanism' => FeeMechanism::None])));
    $registry->register(paymentAdapterOf(paymentDescriptor(['slug' => 'partner', 'modes' => [ConnectionMode::Platform], 'credentialFields' => []])));

    expect($registry->descriptors())->toHaveCount(2);
});

it('refuses a second provider under a slug already taken', function () {
    $registry = paymentRegistry();

    expect(fn () => $registry->register(new FakePaymentAdapter))->toThrow(RegistrationRefused::class, 'already registered');
});

it('refuses the slug billing as it refuses a taken one, since POST /webhooks/billing is the platform\'s own billing webhook and would shadow that provider\'s platform webhook, and holds nothing of it', function () {
    $registry = new ProviderRegistry;

    expect(fn () => $registry->register(paymentAdapterOf(paymentDescriptor(['slug' => 'billing']))))
        ->toThrow(RegistrationRefused::class, 'The slug "billing" is the platform\'s billing webhook; no payment provider may be registered as it.')
        ->and($registry->descriptors())->toBe([])
        ->and($registry->adapter('billing'))->toBeNull();
});

it('answers a tenant with the null provider for no row and for every row that does not work, and with the connection\'s adapter only for an active row whose stored check has not failed', function (?PaymentConnection $row, bool $adapter) {
    $tenant = new TenantPaymentProvider(paymentRow($row), paymentRegistry());

    expect($tenant->current())->toBeInstanceOf($adapter ? FakePaymentProvider::class : NullPaymentProvider::class)
        ->and($tenant->isAvailable())->toBe($adapter);
})->with([
    'no row' => [null, false],
    'pending' => [fn () => paymentConnection(ConnectionState::Pending), false],
    'restricted' => [fn () => paymentConnection(ConnectionState::Restricted), false],
    'disabled' => [fn () => paymentConnection(ConnectionState::Disabled), false],
    'pending with a passed check' => [fn () => paymentConnection(ConnectionState::Pending, true), false],
    'active with a failed check' => [fn () => paymentConnection(ConnectionState::Active, false), false],
    'active with no check yet' => [fn () => paymentConnection(ConnectionState::Active), true],
    'active with a passed check' => [fn () => paymentConnection(ConnectionState::Active, true), true],
    'active in platform mode' => [fn () => paymentConnection(ConnectionState::Active, true, FakePaymentAdapter::SLUG, ConnectionMode::Platform), true],
    'active for a provider this build does not register' => [fn () => paymentConnection(ConnectionState::Active, true, 'nobody'), false],
]);

it('answers an active row in a mode its provider does not declare with the null provider', function () {
    $registry = new ProviderRegistry;
    $registry->register(new class implements PaymentAdapter
    {
        public function descriptor(): ProviderDescriptor
        {
            return paymentDescriptor(['modes' => [ConnectionMode::Own], 'onboardingEntry' => null, 'feeMechanism' => FeeMechanism::None]);
        }

        public function provider(PaymentConnection $connection, PaymentConnections $connections): PaymentProvider
        {
            return new FakePaymentProvider(new FakePaymentAdapter);
        }

        public function openAccount(Credentials $platform): string
        {
            throw new LogicException('Not asked of this adapter.');
        }

        public function onboardingLink(Credentials $platform, string $accountRef, string $returnUrl): string
        {
            throw new LogicException('Not asked of this adapter.');
        }

        public function verifyPlatform(string $body, string $signature, Credentials $platform): PlatformNotice
        {
            throw NotificationRefused::because('Not asked of this adapter.');
        }

        public function check(Credentials $credentials): ConnectionCheck
        {
            return ConnectionCheck::passed();
        }

        public function verify(string $body, string $signature, Credentials $credentials): PaymentNotice
        {
            throw NotificationRefused::because('This adapter verifies nothing.');
        }
    });

    $own = new TenantPaymentProvider(paymentRow(paymentConnection(ConnectionState::Active, true, 'tillbox', ConnectionMode::Own)), $registry);
    $platform = new TenantPaymentProvider(paymentRow(paymentConnection(ConnectionState::Active, true, 'tillbox', ConnectionMode::Platform)), $registry);

    expect($own->current())->toBeInstanceOf(FakePaymentProvider::class)
        ->and($platform->current())->toBeInstanceOf(NullPaymentProvider::class);
});

it('reads the row again for every question, so a connection that stopped working is off for the next one', function () {
    $store = paymentRow(paymentConnection(ConnectionState::Active));
    $tenant = new TenantPaymentProvider($store, paymentRegistry());

    $tenant->isAvailable();
    $tenant->isAvailable();

    expect($store)->toHaveProperty('reads', 2);
});

it('hands the tenant\'s questions to the adapter behind a working connection and to nobody behind none', function () {
    $adapter = new FakePaymentAdapter;
    $registry = new ProviderRegistry;
    $registry->register($adapter);
    $booking = SnapshotFixture::booking();
    $request = new CheckoutRequest($booking, null, 'https://luna.test/manage/'.SnapshotFixture::UUID.'?token=t', new DateTimeImmutable('2026-11-03 08:15:00', new DateTimeZone('UTC')));
    $connected = new TenantPaymentProvider(paymentRow(paymentConnection(ConnectionState::Active)), $registry);
    $unconnected = new TenantPaymentProvider(paymentRow(null), $registry);

    $unconnected->flagPayment($booking, 'pd_1', 'Released.');
    $unconnected->expireCheckout($booking, 'pd_1');
    $nowhere = [$unconnected->checkoutUrl($request), $unconnected->paymentRef($booking), $adapter->asked];
    $before = $connected->paymentRef($booking);
    $session = $connected->checkoutUrl($request);
    $connected->flagPayment($booking, 'pd_1', 'Released.');
    $connected->expireCheckout($booking, 'pd_1');

    assert($session instanceof CheckoutSession);

    expect($nowhere)->toBe([null, null, []])
        ->and($before)->toBeNull()
        ->and($session->url)->toBe('https://luna.test/manage/'.SnapshotFixture::UUID.'?token=t&paydesk_session=pd_1')
        ->and($session->ref)->toBe('pd_1')
        ->and($connected->paymentRef($booking))->toBe('pd_1')
        ->and($adapter->expired)->toBe(['pd_1'])
        ->and($adapter->transactionOpen)->toBe([null, null, null])
        ->and($adapter->asked)->toBe([
            ['paymentRef', SnapshotFixture::UUID],
            ['checkoutUrl', SnapshotFixture::UUID],
            ['flagPayment', SnapshotFixture::UUID, 'pd_1', 'Released.'],
            ['expireCheckout', SnapshotFixture::UUID, 'pd_1'],
            ['paymentRef', SnapshotFixture::UUID],
        ]);
});

it('opens one session per booking in the fake and answers it again when its id is handed back, asking for the booking\'s own total and ending the session its lifetime after the end asked for', function () {
    $adapter = new FakePaymentAdapter;
    $provider = new FakePaymentProvider($adapter);
    $booking = SnapshotFixture::booking();
    $asked = new DateTimeImmutable('2026-11-03 08:15:00.250000', new DateTimeZone('UTC'));

    $first = $provider->checkoutUrl(new CheckoutRequest($booking, null, 'https://luna.test/manage/x?token=t', $asked));

    assert($first instanceof CheckoutSession);

    $again = $provider->checkoutUrl(new CheckoutRequest($booking, $first->ref, 'https://luna.test/manage/x?token=t', $asked->add(new DateInterval('PT1H'))));
    $adapter->sessionOverrunSeconds = -300;
    $shorter = $provider->checkoutUrl(new CheckoutRequest($booking, null, 'https://luna.test/manage/x?token=t', $asked));
    // A session after a move cleared one ends with the hold as asked, whatever the lifetime.
    $later = $provider->checkoutUrl(new CheckoutRequest($booking, null, 'https://luna.test/manage/x?token=t', $asked, 1));

    assert($again instanceof CheckoutSession && $shorter instanceof CheckoutSession && $later instanceof CheckoutSession);

    expect($adapter->created())->toBe(3)
        ->and($again->ref)->toBe($first->ref)
        ->and($again->url)->toBe($first->url)
        ->and($again->endsAt)->toEqual($first->endsAt)
        ->and($first->endsAt->format('Y-m-d H:i:s.u P'))->toBe('2026-11-03 08:30:00.250000 +00:00')
        ->and($shorter->ref)->not->toBe($first->ref)
        ->and($shorter->endsAt->format('Y-m-d H:i:s.u P'))->toBe('2026-11-03 08:10:00.250000 +00:00')
        ->and($later->ref)->toBe('pd_3')
        ->and($later->endsAt)->toEqual($asked)
        ->and($adapter->sessions['pd_3']['sessionsCleared'] ?? null)->toBe(1)
        ->and($adapter->sessions[$first->ref]['total'])->toBe($booking->total);
});

it('settles a free and a pay-on-site booking with nothing paid whoever the provider is, and an online one only while the provider is the null one', function (PaymentMode $mode, bool $connected, bool $settles) {
    $provider = new TenantPaymentProvider(paymentRow($connected ? paymentConnection(ConnectionState::Active) : null), paymentRegistry());

    expect((new SettlesByProvider($provider))->settlesWithoutPayment($mode))->toBe($settles);
})->with([
    'free, nothing connected' => [PaymentMode::Free, false, true],
    'free, a working connection' => [PaymentMode::Free, true, true],
    'onsite, nothing connected' => [PaymentMode::Onsite, false, true],
    'onsite, a working connection' => [PaymentMode::Onsite, true, true],
    'online, nothing connected: the degrade rule' => [PaymentMode::Online, false, true],
    'online, a working connection' => [PaymentMode::Online, true, false],
]);

/*
 * The strike of TestConnection reads its text as UTF-8, so a word is a word in any script, and
 * where it cannot run it strikes the whole text. No request admits a value that is not UTF-8 and
 * no sealed row holds one, so the value is lent here straight to the check; the strike must
 * throw for no value all the same.
 */
it('strikes the whole reason and the whole logged message where a lent value is not UTF-8, throwing for none', function () {
    $fake = new FakePaymentAdapter;
    $registry = new ProviderRegistry;
    $registry->register($fake);
    $log = new RecordingPaymentLog;
    $test = new TestConnection($registry, FixedClock::at('2026-11-02 06:00:00'), $log);
    $said = 'Paydesk refused the token pdk_token_9f3c1e77a2b4 for merchant MERCHANT-LUNA-0042.';
    $store = paymentRow(paymentConnection(ConnectionState::Restricted, false), ['merchant_code' => "MERCHANT-\xff\xfe-0042", 'api_token' => 'pdk_token_9f3c1e77a2b4', 'signing_phrase' => 'correct horse paydesk staple']);

    $fake->checkFailsWith = $said;
    expect($test->test($store)?->reason)->toBe(TestConnection::STRUCK)
        ->and($store)->toHaveProperty('landed', ConnectionCheck::failed(TestConnection::STRUCK));

    $fake->checkFailsWith = null;
    $fake->checkThrows = new RuntimeException($said);
    $threw = $test->test($store);
    // The class is code and holds no input, so neither the English nor its value is struck.
    expect($threw?->reason)->toBe(sprintf(TestConnection::THREW, RuntimeException::class))
        ->and([$threw?->key, $threw?->values])->toBe([TestConnection::REASON_UNEXPECTED, ['class' => RuntimeException::class]])
        ->and($log->threw)->toBe([[FakePaymentAdapter::SLUG, RuntimeException::class, TestConnection::STRUCK]]);
});

/*
 * A check that names its reason's key keeps the key and has every value struck as its reason
 * is, so a secret the provider echoed reads STRUCK in the reason and in the value the screen
 * fills its line with alike.
 */
it('strikes every value a failed check records as it strikes the reason, keeping the key it names', function () {
    $fake = new FakePaymentAdapter;
    $registry = new ProviderRegistry;
    $registry->register($fake);
    $test = new TestConnection($registry, FixedClock::at('2026-11-02 06:00:00'), new RecordingPaymentLog);
    $store = paymentRow(paymentConnection(ConnectionState::Active, true), ['merchant_code' => 'MERCHANT-LUNA-0042', 'api_token' => 'pdk_token_9f3c1e77a2b4', 'signing_phrase' => 'correct horse paydesk staple']);
    $said = 'Refused pdk_token_9f3c1e77a2b4 for MERCHANT-LUNA-0042 at 10:42.';
    $struck = 'Refused '.TestConnection::STRUCK.' for '.TestConnection::STRUCK.' at 10:42.';

    $fake->checkAnswers = ConnectionCheck::failed('Paydesk: '.$said, StripeAdapter::REASON_MESSAGE, ['reason' => $said]);
    $check = $test->test($store);

    expect([$check?->reason, $check?->key, $check?->values])->toBe(['Paydesk: '.$struck, StripeAdapter::REASON_MESSAGE, ['reason' => $struck]])
        ->and($store)->toHaveProperty('landed', $check);
});

it('asks nobody about a free or a pay-on-site booking: cash is never a connection', function () {
    $store = paymentRow(paymentConnection(ConnectionState::Active));
    $rule = new SettlesByProvider(new TenantPaymentProvider($store, paymentRegistry()));

    $rule->settlesWithoutPayment(PaymentMode::Free);
    $rule->settlesWithoutPayment(PaymentMode::Onsite);

    expect($store)->toHaveProperty('reads', 0);
});
