<?php

declare(strict_types=1);

namespace App\Billing;

use App\Application\Clock;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Container\Attributes\Config;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use JsonException;

/**
 * The billing port over Stripe, the platform's own account. It speaks the REST API form-encoded
 * over HTTP Basic auth with the secret key, through the framework's HTTP client, which is the
 * transport a test fakes. Its keys come from configuration when it is built, and a missing one is
 * refused at the first question that needs it, never at boot, with a sentence naming that key.
 *
 * The objects are read in the shape the account's default API version renders, which is also the
 * shape of the events it sends: a subscription's period end is on its items from 2025-03-31.basil
 * and on the subscription before it, and an invoice names its subscription under
 * parent.subscription_details from basil and at the top level before it; both are read.
 */
final readonly class StripeBillingProvider implements BillingProvider
{
    public const string API = 'https://api.stripe.com/v1/';

    public const string HEADER = 'Stripe-Signature';

    /**
     * How far a notification's signed timestamp may stand from now, in seconds: the official
     * libraries' default of five minutes.
     */
    public const int TOLERANCE = 300;

    /** Where an event carries the object it is about, the keys leading to it. */
    private const array OBJECT = ['data', 'object'];

    /**
     * Stripe's event types by our own names; every other type is Other. Both of the provider's
     * success events are the succeeded charge, whichever the endpoint sends, the re-read making
     * the second delivery for one payment a no-op.
     */
    private const array KINDS = [
        'checkout.session.completed' => NoticeKind::CheckoutCompleted,
        'customer.subscription.created' => NoticeKind::SubscriptionChanged,
        'customer.subscription.updated' => NoticeKind::SubscriptionChanged,
        'customer.subscription.deleted' => NoticeKind::SubscriptionDeleted,
        'invoice.payment_failed' => NoticeKind::ChargeFailed,
        'invoice.paid' => NoticeKind::ChargeSucceeded,
        'invoice.payment_succeeded' => NoticeKind::ChargeSucceeded,
    ];

    public function __construct(
        private Factory $http,
        private Clock $clock,
        #[Config('billing.provider.secret_key')] private mixed $secretKey,
        #[Config('billing.provider.webhook_secret')] private mixed $webhookSecret,
        #[Config('billing.provider.automatic_tax')] private mixed $automaticTax,
    ) {}

    /**
     * Every session collects the business's tax id, which needs no tax setup, and saves the name
     * it carries on the customer the session names. Automatic tax, with the billing address saved
     * on the customer for it, is asked only where billing.provider.automatic_tax is the boolean
     * true: the provider refuses it while the account's tax settings hold no head office address,
     * and with no registration there it adds nothing.
     */
    public function startCheckout(Plan $plan, ?string $customerId, string $email, string $successUrl, string $cancelUrl): Checkout
    {
        $customer = $customerId ?? self::text($this->post('create a customer', 'customers', ['email' => $email]), 'create a customer', 'id');
        $tax = $this->automaticTax === true;

        $session = $this->post('start a checkout', 'checkout/sessions', [
            'mode' => 'subscription',
            'customer' => $customer,
            'line_items' => [['price' => $plan->priceId, 'quantity' => 1]],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'tax_id_collection' => ['enabled' => 'true'],
            'customer_update' => ['name' => 'auto', ...($tax ? ['address' => 'auto'] : [])],
            ...($tax ? ['automatic_tax' => ['enabled' => 'true']] : []),
        ]);

        return new Checkout(
            customerId: $customer,
            sessionId: self::text($session, 'start a checkout', 'id'),
            url: self::text($session, 'start a checkout', 'url'),
        );
    }

    public function openPortal(string $customerId, string $returnUrl): string
    {
        $session = $this->post('open the portal', 'billing_portal/sessions', ['customer' => $customerId, 'return_url' => $returnUrl]);

        return self::text($session, 'open the portal', 'url');
    }

    /**
     * Stripe's list of a customer's tax ids, GET /v1/customers/{id}/tax_ids, read from its first
     * page: each entry's type and value, an entry lacking either refused rather than skipped.
     *
     * @return list<TaxId>
     */
    public function taxIds(string $customerId): array
    {
        $operation = 'read the tax ids';
        $list = self::at($this->get($operation, 'customers/'.rawurlencode($customerId).'/tax_ids'), 'data');

        if (! is_array($list) || ! array_is_list($list)) {
            throw BillingProviderFailed::unreadable($operation, 'data');
        }

        return array_map(
            fn (mixed $entry): TaxId => is_array($entry)
                ? new TaxId(self::text($entry, $operation, 'type'), self::text($entry, $operation, 'value'))
                : throw BillingProviderFailed::unreadable($operation, 'tax id'),
            $list,
        );
    }

    public function subscription(string $subscriptionId): SubscriptionSnapshot
    {
        return self::snapshot($this->get('read a subscription', 'subscriptions/'.rawurlencode($subscriptionId)), 'read a subscription');
    }

    /**
     * Replaces the price of the subscription's one item, as Stripe's change-price guide does,
     * leaving proration to the provider's default. A subscription carrying any other number of
     * items is not one this build sold, and is refused rather than guessed at.
     */
    public function changePlan(string $subscriptionId, Plan $plan): SubscriptionSnapshot
    {
        $path = 'subscriptions/'.rawurlencode($subscriptionId);
        $items = self::items($this->get('change a plan', $path), 'change a plan');

        if (count($items) !== 1 || ! is_array($items[0])) {
            throw BillingProviderFailed::unreadable('change a plan', 'single subscription item');
        }

        $item = self::text($items[0], 'change a plan', 'items.data.0.id', ['id']);

        return self::snapshot($this->post('change a plan', $path, ['items' => [['id' => $item, 'price' => $plan->priceId]]]), 'change a plan');
    }

    /**
     * Stripe's cancel, DELETE /v1/subscriptions/{id}, which ends the subscription at once and, with
     * neither invoice_now nor prorate sent, raises no final invoice and credits nothing; it answers
     * the subscription object at the status canceled, read as any snapshot is.
     */
    public function cancel(string $subscriptionId): SubscriptionSnapshot
    {
        return self::snapshot($this->delete('cancel a subscription', 'subscriptions/'.rawurlencode($subscriptionId)), 'cancel a subscription');
    }

    public function signatureHeader(): string
    {
        return self::HEADER;
    }

    /**
     * Stripe's manual verification: the header's t and every v1, an HMAC-SHA256 of "t.body" under
     * the endpoint's signing secret compared in constant time against each v1, then the timestamp
     * within the tolerance. Only then is the body read, and a body that is not an event (no id, no
     * type, no object) is refused.
     */
    /**
     * Stripe's event carries the customer on its object, data.object.customer, for every kind
     * notice() reads one from: a checkout session, a subscription and an invoice alike.
     *
     * @return non-empty-list<string>
     */
    public function customerPath(): array
    {
        return [...self::OBJECT, 'customer'];
    }

    public function verify(string $payload, string $signature): BillingNotice
    {
        $secret = self::configured($this->webhookSecret) ?? throw BillingNotConfigured::noWebhookSecret();
        [$timestamp, $signatures] = self::signatureParts($signature);
        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

        if (array_filter($signatures, fn (string $given): bool => hash_equals($expected, $given)) === []) {
            throw new SignatureRefused('The notification does not carry a signature made with the signing secret.');
        }

        if (abs($this->clock->now()->getTimestamp() - (int) $timestamp) > self::TOLERANCE) {
            throw new SignatureRefused('The notification was signed outside the tolerance of five minutes.');
        }

        return self::notice($payload);
    }

    /**
     * @param  array<string, mixed>  $form
     * @return array<array-key, mixed>
     */
    private function post(string $operation, string $path, array $form): array
    {
        return $this->answer($operation, fn (): Response => $this->client()->asForm()->post(self::API.$path, $form));
    }

    /**
     * @return array<array-key, mixed>
     */
    private function get(string $operation, string $path): array
    {
        return $this->answer($operation, fn (): Response => $this->client()->get(self::API.$path));
    }

    /**
     * @return array<array-key, mixed>
     */
    private function delete(string $operation, string $path): array
    {
        return $this->answer($operation, fn (): Response => $this->client()->delete(self::API.$path));
    }

    private function client(): PendingRequest
    {
        $key = self::configured($this->secretKey) ?? throw BillingNotConfigured::noSecretKey();

        return $this->http->withBasicAuth($key, '')->acceptJson()->timeout(30);
    }

    /**
     * @param  Closure(): Response  $send
     * @return array<array-key, mixed>
     */
    private function answer(string $operation, Closure $send): array
    {
        try {
            $response = $send();
        } catch (ConnectionException $failed) {
            throw BillingProviderFailed::unreachable($operation, $failed->getMessage());
        }

        $body = $response->json();

        if ($response->failed()) {
            $error = is_array($body) && is_array($body['error'] ?? null) ? $body['error'] : [];

            throw BillingProviderFailed::refused(
                $operation,
                $response->status(),
                is_string($error['code'] ?? null) ? $error['code'] : 'none',
                is_string($error['message'] ?? null) ? $error['message'] : 'no message',
            );
        }

        return is_array($body) ? $body : throw BillingProviderFailed::unreadable($operation, 'body');
    }

    private static function configured(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /**
     * @param  array<array-key, mixed>  $subscription
     */
    private static function snapshot(array $subscription, string $operation): SubscriptionSnapshot
    {
        $items = [];
        $ends = [];

        foreach (self::items($subscription, $operation) as $item) {
            if (! is_array($item) || ! is_array($item['price'] ?? null) || ! is_int($item['quantity'] ?? null)) {
                throw BillingProviderFailed::unreadable($operation, 'subscription item');
            }

            $items[] = new SnapshotItem(self::text($item['price'], $operation, 'item price', ['id']), $item['quantity']);

            if (is_int($item['current_period_end'] ?? null)) {
                $ends[] = $item['current_period_end'];
            }
        }

        $end = $ends === [] ? ($subscription['current_period_end'] ?? null) : min($ends);

        if (! is_int($end)) {
            throw BillingProviderFailed::unreadable($operation, 'current_period_end');
        }

        return new SubscriptionSnapshot(
            self::text($subscription, $operation, 'id'),
            self::text($subscription, $operation, 'customer'),
            self::text($subscription, $operation, 'status'),
            (new DateTimeImmutable('@'.$end, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC')),
            $items,
        );
    }

    /**
     * @param  array<array-key, mixed>  $subscription
     * @return list<mixed>
     */
    private static function items(array $subscription, string $operation): array
    {
        $items = is_array($subscription['items'] ?? null) ? ($subscription['items']['data'] ?? null) : null;

        return is_array($items) && array_is_list($items) ? $items : throw BillingProviderFailed::unreadable($operation, 'items');
    }

    /**
     * @param  array<array-key, mixed>  $object
     * @param  list<string>|null  $path  the keys to the value, the field's own name when null
     */
    private static function text(array $object, string $operation, string $field, ?array $path = null): string
    {
        $value = self::at($object, ...($path ?? [$field]));

        return is_string($value) && $value !== '' ? $value : throw BillingProviderFailed::unreadable($operation, $field);
    }

    private static function at(mixed $value, string ...$keys): mixed
    {
        foreach ($keys as $key) {
            $value = is_array($value) ? ($value[$key] ?? null) : null;
        }

        return $value;
    }

    /**
     * @return array{0: string, 1: list<string>}
     */
    private static function signatureParts(string $header): array
    {
        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $element) {
            [$prefix, $value] = array_pad(explode('=', trim($element), 2), 2, '');

            if ($prefix === 't' && ctype_digit($value)) {
                $timestamp = $value;
            } elseif ($prefix === 'v1' && $value !== '') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || $signatures === []) {
            throw new SignatureRefused('The notification carries no timestamp and v1 signature.');
        }

        return [$timestamp, $signatures];
    }

    private static function notice(string $payload): BillingNotice
    {
        try {
            $event = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new SignatureRefused('The notification is signed but is not JSON.');
        }

        $id = self::at($event, 'id');
        $type = self::at($event, 'type');
        $object = self::at($event, ...self::OBJECT);

        if (! is_string($id) || $id === '' || ! is_string($type) || $type === '' || ! is_array($object)) {
            throw new SignatureRefused('The notification is signed but is not an event.');
        }

        $kind = self::KINDS[$type] ?? NoticeKind::Other;
        [$customer, $subscription] = match ($kind) {
            NoticeKind::CheckoutCompleted => [self::at($object, 'customer'), self::at($object, 'subscription')],
            NoticeKind::SubscriptionChanged, NoticeKind::SubscriptionDeleted => [self::at($object, 'customer'), self::at($object, 'id')],
            NoticeKind::ChargeFailed, NoticeKind::ChargeSucceeded => [self::at($object, 'customer'), self::at($object, 'parent', 'subscription_details', 'subscription') ?? self::at($object, 'subscription')],
            NoticeKind::Other => [null, null],
        };

        return new BillingNotice(
            $id,
            $type,
            $kind,
            is_string($customer) && $customer !== '' ? $customer : null,
            is_string($subscription) && $subscription !== '' ? $subscription : null,
        );
    }
}
