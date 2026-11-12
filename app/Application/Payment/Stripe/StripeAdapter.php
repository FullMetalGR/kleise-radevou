<?php

declare(strict_types=1);

namespace App\Application\Payment\Stripe;

use App\Application\Clock;
use App\Application\Payment\AccountStanding;
use App\Application\Payment\ConnectionCheck;
use App\Application\Payment\ConnectionMode;
use App\Application\Payment\CredentialField;
use App\Application\Payment\Credentials;
use App\Application\Payment\FeeMechanism;
use App\Application\Payment\NotificationRefused;
use App\Application\Payment\NullPaymentProvider;
use App\Application\Payment\OnboardingEntry;
use App\Application\Payment\PaymentAdapter;
use App\Application\Payment\PaymentConnection;
use App\Application\Payment\PaymentConnections;
use App\Application\Payment\PaymentLog;
use App\Application\Payment\PaymentNotice;
use App\Application\Payment\PaymentProvider;
use App\Application\Payment\PlatformConfiguration;
use App\Application\Payment\PlatformNotice;
use App\Application\Payment\ProviderDescriptor;
use App\Application\Payment\WebhookSignature;
use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use Closure;
use SensitiveParameter;

/**
 * Stripe as a tenant's payment provider: hosted Checkout by redirect, confirmation by webhook,
 * a note on the PaymentIntent. Own mode takes payments with the tenant's key. Platform mode
 * opens the connected account, links to Stripe's hosted onboarding and reads the account's
 * updates on the platform's endpoint, and takes payments on the connected account with the
 * platform's key and the platform's fee, their notifications arriving on the platform's
 * endpoint too.
 *
 * It speaks to Stripe through StripeTransport alone and shares nothing with the platform's
 * billing, which happens to use the same vendor.
 */
final readonly class StripeAdapter implements PaymentAdapter
{
    public const string SECRET_KEY = 'secret_key';

    public const string WEBHOOK_SECRET = 'webhook_secret';

    /**
     * Where our words for the two fields' labels and helps sit, keys of
     * lang/{locale}/admin/payments.php under the provider's slug, which the screen and the
     * credential refusals read in the request's language.
     */
    public const string FIELDS = 'admin/payments.fields.stripe.';

    /**
     * The keys of payments.php's reasons group a failed test names beside its English: no secret
     * key stored, Stripe unreachable, an error answer with no message of its own (:status the
     * HTTP status) and one with Stripe's message (:reason, its words as sent, struck of the
     * connection's secrets).
     */
    public const string REASON_NO_KEY = ConnectionCheck::REASONS.'no_secret_key';

    public const string REASON_UNREACHABLE = ConnectionCheck::REASONS.'unreachable';

    public const string REASON_STATUS = ConnectionCheck::REASONS.'answered_status';

    public const string REASON_MESSAGE = ConnectionCheck::REASONS.'answered_message';

    /** The operation a failed test's English names, StripeFailed's. */
    private const string TEST = 'answer the test';

    /** How far a signature's timestamp may stand from this clock, the tolerance Stripe's own libraries default to. */
    public const int TOLERANCE = 300;

    /** The events that say a booking's payment succeeded, each carrying its Checkout Session. */
    public const array PAID_EVENTS = ['checkout.session.completed', 'checkout.session.async_payment_succeeded'];

    /** The connected account's controller properties as sent: the tenant is the merchant and bears its own losses. */
    public const array CONTROLLER = [
        'controller[fees][payer]' => 'account',
        'controller[losses][payments]' => 'stripe',
        'controller[requirement_collection]' => 'stripe',
        'controller[stripe_dashboard][type]' => 'full',
    ];

    public function __construct(
        private StripeTransport $transport,
        private Clock $clock,
        private PlatformConfiguration $platform,
        private PaymentLog $log,
    ) {}

    public function descriptor(): ProviderDescriptor
    {
        return new ProviderDescriptor(
            slug: 'stripe',
            name: 'Stripe',
            modes: [ConnectionMode::Platform, ConnectionMode::Own],
            credentialFields: [
                new CredentialField(self::SECRET_KEY, self::FIELDS.'secret_key.label', self::FIELDS.'secret_key.help', true),
                new CredentialField(self::WEBHOOK_SECRET, self::FIELDS.'webhook_secret.label', self::FIELDS.'webhook_secret.help', true),
            ],
            onboardingEntry: OnboardingEntry::HostedLink,
            feeMechanism: FeeMechanism::Parameter,
            webhookSignature: WebhookSignature::signedHeader('Stripe-Signature'),
            supportsRefundFlag: true,
            currencies: ['EUR', 'USD', 'GBP', 'CHF', 'SEK', 'NOK', 'DKK', 'PLN', 'CZK', 'HUF', 'RON', 'BGN', 'CAD', 'AUD', 'NZD', 'JPY'],
            rateUrl: 'https://stripe.com/pricing',
            canExpireCheckout: true,
        );
    }

    /**
     * Calls nothing: an own connection's port borrows the credentials through the store's door
     * when it is asked something that leaves the process. A platform connection's port is lent
     * the platform's keys and names the connection's account, with the fee as configured now; it
     * answers the null provider where those keys are unset or the row names no connected
     * account. A fee unset after a connection was made opens payments with no fee: the mode is
     * no longer offered to anybody new, and a connected tenant keeps taking payments rather than
     * every online booking falling back to payment in person, each session so opened saying so
     * on one error line.
     */
    public function provider(PaymentConnection $connection, PaymentConnections $connections): PaymentProvider
    {
        if ($connection->mode === ConnectionMode::Own) {
            return new StripePaymentProvider(
                $this->transport,
                $this->clock,
                fn (Closure $call): mixed => $connections->withCredentials($this, $call),
                $this->log,
            );
        }

        $platform = $this->platform->read();
        $keys = $platform->keys($connection->provider);
        $account = self::accountId($connection->accountRef);

        return $keys === null || $account === null
            ? new NullPaymentProvider
            : new StripePaymentProvider($this->transport, $this->clock, fn (Closure $call): mixed => $call($keys), $this->log, $account, $platform->fee());
    }

    /**
     * Stripe's scheme: the Stripe-Signature header carries t, the signing instant, and one v1
     * per active secret, each the hex HMAC-SHA256 of "t.body" keyed by the endpoint's signing
     * secret. A match is required and its t must stand within TOLERANCE of the port's clock. A
     * verified paid event names the booking from the session's metadata, the payment by the
     * Checkout Session's id, the kind the hop records, and the amount it took from the session's
     * amount_total and currency, recorded and never compared; every other event is a notice that
     * confirms nothing.
     */
    public function verify(string $body, string $signature, Credentials $credentials): PaymentNotice
    {
        $this->signed($body, $signature, $credentials->field(self::WEBHOOK_SECRET));

        return self::notice($body);
    }

    /**
     * A Standard-shaped connected account, the platform's controller properties saying the
     * tenant is the merchant: the account pays Stripe's fees, Stripe and not the platform bears
     * its losses, Stripe collects its requirements, and the account has Stripe's full dashboard.
     * The platform is never the merchant of record.
     */
    public function openAccount(Credentials $platform): string
    {
        $key = $platform->field(self::SECRET_KEY);
        $answer = $this->platformCall('open the connected account', '/v1/accounts', self::CONTROLLER, $platform);
        $id = $answer->body['id'] ?? null;

        return is_string($id) && str_starts_with($id, 'acct_')
            ? $id
            : throw StripeFailed::because('open the connected account', 'the answer names no account.', [$key]);
    }

    /**
     * An Account Link of type account_onboarding for the account, whose return and refresh
     * addresses are both the address given: an expired or revisited link lands the owner back
     * on Settings, where the pending connection offers a fresh one.
     */
    public function onboardingLink(Credentials $platform, string $accountRef, string $returnUrl): string
    {
        $answer = $this->platformCall('open the onboarding link', '/v1/account_links', [
            'account' => $accountRef,
            'refresh_url' => $returnUrl,
            'return_url' => $returnUrl,
            'type' => 'account_onboarding',
        ], $platform);
        $url = $answer->body['url'] ?? null;

        return is_string($url) && str_starts_with($url, 'https://')
            ? $url
            : throw StripeFailed::because('open the onboarding link', 'the answer carries no link.', [$platform->field(self::SECRET_KEY)]);
    }

    /**
     * Stripe's scheme with the platform's Connect endpoint secret. A connected account's event
     * names it in the event's account; account.updated carries the Account: charges_enabled is
     * taking payments, details_submitted not true is onboarding unfinished, and anything else is
     * Stripe wanting something, its requirements stored as Stripe sent them. A paid event of a
     * session opened on the connected account carries its payment notice, read as own mode's.
     */
    public function verifyPlatform(string $body, string $signature, Credentials $platform): PlatformNotice
    {
        $this->signed($body, $signature, $platform->field(self::WEBHOOK_SECRET));
        [$id, $type, $event] = self::event($body);
        $account = self::accountId($event['account'] ?? null);
        $data = $event['data'] ?? null;
        $object = is_array($data) ? ($data['object'] ?? null) : null;

        return match (true) {
            $type === 'account.updated' && is_array($object) && ($object['object'] ?? null) === 'account' => new PlatformNotice($id, $type, self::accountId($object['id'] ?? null) ?? $account, self::standing($object)),
            in_array($type, self::PAID_EVENTS, true) => new PlatformNotice($id, $type, $account, null, self::notice($body)),
            default => new PlatformNotice($id, $type, $account),
        };
    }

    /**
     * Lists one Checkout Session, the read a key that can open a checkout can make. Each of the
     * four failures names its reason's key and the values that fill it beside its stored
     * English: the no-key line spelled here, the other three StripeFailed's words.
     */
    public function check(Credentials $credentials): ConnectionCheck
    {
        $key = $credentials->field(self::SECRET_KEY);

        if ($key === '') {
            return ConnectionCheck::failed('The connection holds no secret key.', self::REASON_NO_KEY);
        }

        try {
            $answer = $this->transport->send('GET', '/v1/checkout/sessions', ['limit' => 1], $key);
        } catch (StripeUnreachable $unreachable) {
            return ConnectionCheck::failed(StripeFailed::because(self::TEST, $unreachable->getMessage())->getMessage(), self::REASON_UNREACHABLE);
        }

        if ($answer->ok()) {
            return ConnectionCheck::passed();
        }

        $secrets = [$key, $credentials->field(self::WEBHOOK_SECRET)];
        $reason = StripeFailed::because(self::TEST, $answer->reason(), $secrets)->getMessage();
        $message = $answer->message();

        return $message === null
            ? ConnectionCheck::failed($reason, self::REASON_STATUS, ['status' => (string) $answer->status])
            : ConnectionCheck::failed($reason, self::REASON_MESSAGE, ['reason' => StripeFailed::struck($message, $secrets)]);
    }

    /**
     * @throws NotificationRefused unless one v1 of the header matches the body under the secret
     *                             and its t stands within TOLERANCE of the port's clock
     */
    private function signed(string $body, string $signature, #[SensitiveParameter] string $secret): void
    {
        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $signature) as $part) {
            [$name, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            $timestamp = $name === 't' && ctype_digit($value) ? (int) $value : $timestamp;
            $signatures = $name === 'v1' && $value !== '' ? [...$signatures, $value] : $signatures;
        }
        if ($secret === '' || $timestamp === null || $signatures === []) {
            throw NotificationRefused::because('The Stripe signature or the signing secret is missing.');
        }
        $expected = hash_hmac('sha256', $timestamp.'.'.$body, $secret);
        if (array_filter($signatures, fn (string $candidate): bool => hash_equals($expected, $candidate)) === []) {
            throw NotificationRefused::because('No Stripe signature matches the body.');
        }
        if (abs($this->clock->now()->getTimestamp() - $timestamp) > self::TOLERANCE) {
            throw NotificationRefused::because('The Stripe signature is outside the timestamp tolerance.');
        }
    }

    /**
     * @param  array<string, string>  $form
     *
     * @throws StripeFailed where Stripe cannot be reached or refuses, the platform's keys struck
     */
    private function platformCall(string $operation, string $path, array $form, Credentials $platform): StripeAnswer
    {
        $key = $platform->field(self::SECRET_KEY);
        $secrets = [$key, $platform->field(self::WEBHOOK_SECRET)];
        try {
            $answer = $this->transport->send('POST', $path, $form, $key);
        } catch (StripeUnreachable $unreachable) {
            throw StripeFailed::because($operation, $unreachable->getMessage(), $secrets);
        }

        return $answer->ok() ? $answer : throw StripeFailed::because($operation, $answer->reason(), $secrets);
    }

    /**
     * @throws NotificationRefused when the verified body is not a Stripe event
     */
    private static function notice(string $body): PaymentNotice
    {
        [$id, $type, $event] = self::event($body);

        if (! in_array($type, self::PAID_EVENTS, true)) {
            return new PaymentNotice($id, $type, false);
        }

        $data = $event['data'] ?? null;
        $session = is_array($data) ? ($data['object'] ?? null) : null;
        $ref = is_array($session) ? ($session['id'] ?? null) : null;

        if (! is_array($session) || ($session['object'] ?? null) !== 'checkout.session' || ! is_string($ref) || ! str_starts_with($ref, 'cs_')) {
            throw NotificationRefused::because('The Stripe event carries no Checkout Session.');
        }

        $metadata = $session['metadata'] ?? null;
        $booking = is_array($metadata) ? ($metadata['booking'] ?? null) : null;
        $booking = is_string($booking) && $booking !== '' ? $booking : null;
        $paid = $booking !== null && ($session['payment_status'] ?? null) === 'paid';

        return new PaymentNotice($id, $type, $paid, $booking, $paid ? $ref : null, $paid ? self::amount($session) : null);
    }

    /**
     * What a Checkout Session took: amount_total in the minor units of its currency, which
     * Stripe spells in lower case, or null where either is missing or unreadable. A paid event
     * is never refused for it, since the amount is recorded and compared with nothing.
     *
     * @param  array<array-key, mixed>  $session
     */
    private static function amount(array $session): ?Money
    {
        $minor = $session['amount_total'] ?? null;
        $currency = $session['currency'] ?? null;

        return is_int($minor) && $minor >= 0 && is_string($currency) && preg_match('/\A[a-z]{3}\z/', $currency) === 1
            ? new Money($minor, Currency::of(strtoupper($currency)))
            : null;
    }

    /**
     * The verified body read as a Stripe event: its evt_ id, its type and the whole object.
     *
     * @return array{string, string, array<array-key, mixed>}
     *
     * @throws NotificationRefused when the body is not a Stripe event
     */
    private static function event(string $body): array
    {
        $event = json_decode($body, true);
        $id = is_array($event) ? ($event['id'] ?? null) : null;
        $type = is_array($event) ? ($event['type'] ?? null) : null;

        if (! is_array($event) || ($event['object'] ?? null) !== 'event' || ! is_string($id) || ! str_starts_with($id, 'evt_') || ! is_string($type) || $type === '') {
            throw NotificationRefused::because('The body is not a Stripe event.');
        }

        return [$id, $type, $event];
    }

    /**
     * An Account's standing from the three fields Stripe documents on it: charges_enabled is
     * taking payments, details_submitted not true is onboarding unfinished, and anything else is
     * Stripe wanting something, the requirements object kept as Stripe sent it. An account taking
     * payments keeps the object while Stripe still lists something currently due or past due,
     * which it asks for before the account's deadline; one due only eventually needs nothing yet.
     *
     * @param  array<array-key, mixed>  $account
     */
    private static function standing(array $account): AccountStanding
    {
        $requirements = $account['requirements'] ?? null;
        $requirements = is_array($requirements) ? $requirements : [];
        $due = fn (string $list): bool => is_array($requirements[$list] ?? null) && $requirements[$list] !== [];

        return match (true) {
            ($account['charges_enabled'] ?? null) === true => AccountStanding::takingPayments($due('currently_due') || $due('past_due') ? $requirements : null),
            ($account['details_submitted'] ?? null) !== true => AccountStanding::unfinished($requirements),
            default => AccountStanding::wanting($requirements),
        };
    }

    private static function accountId(mixed $candidate): ?string
    {
        return is_string($candidate) && str_starts_with($candidate, 'acct_') ? $candidate : null;
    }
}
