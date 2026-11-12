<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Application\Checkpoint;
use App\Billing\BillingNotice;
use App\Billing\BillingProvider;
use App\Billing\Checkout;
use App\Billing\NoticeKind;
use App\Billing\Plan;
use App\Billing\SignatureRefused;
use App\Billing\SnapshotItem;
use App\Billing\SubscriptionSnapshot;
use App\Billing\TaxId;
use JsonException;
use LogicException;
use Throwable;

/**
 * The billing provider every test meets, named by BILLING_PROVIDER in phpunit.xml and bound by
 * the application's own provider, so every process a test run starts, a concurrency worker
 * booted from nothing included, meets it and none opens a network connection to charge anybody.
 *
 * It answers the snapshots a test scripts, keyed by subscription id, and records every question
 * about a customer, a subscription or a notification in the order asked, a cancel among them,
 * so a test proving the port was asked what a use case needed reads $asked; the header name it
 * reads a signature from is a constant of the fake, not a question, and is not recorded. Once it has answered for a
 * subscription it passes the checkpoint ANSWERED, where a concurrency test holds a worker that
 * has read the provider and not yet written what it read.
 * A notification is the fake's own JSON, built by notice() and signed by sign() with a secret
 * the test knows; verify() refuses any payload whose signature is not that one.
 */
final class FakeBillingProvider implements BillingProvider
{
    public const string SECRET = 'fake-billing-signing-secret';

    /**
     * The header the fake reads a notification's signature from, named apart from any real
     * provider's so a route reading a vendor's header by name cannot pass against the fake.
     */
    public const string HEADER = 'Fake-Billing-Signature';

    public const string ANSWERED = 'billing.provider.answered';

    /** Passed when a checkout is asked for, before the fake answers or throws. */
    public const string CHECKOUT = 'billing.provider.checkout';

    /** @var list<array{0: string, 1: array<string, mixed>}> */
    public array $asked = [];

    /** @var array<string, SubscriptionSnapshot> */
    private array $scripted = [];

    /** @var array<string, list<SubscriptionSnapshot>> answers still to give before the scripted one */
    private array $turns = [];

    private int $customers = 0;

    private int $sessions = 0;

    /** What startCheckout throws instead of answering, once a test sets it. */
    public ?Throwable $checkoutFails = null;

    /** What cancel throws instead of answering, once a test sets it: the provider refusing. */
    public ?Throwable $cancelFails = null;

    /** @var array<string, list<TaxId>|Throwable> what taxIds() answers or throws per customer */
    private array $taxIds = [];

    /**
     * What subscription() answers from now on for each snapshot's subscription id.
     */
    public function script(SubscriptionSnapshot ...$snapshots): self
    {
        foreach ($snapshots as $snapshot) {
            $this->scripted[$snapshot->subscriptionId] = $snapshot;
            unset($this->turns[$snapshot->subscriptionId]);
        }

        return $this;
    }

    /**
     * What taxIds() answers from now on for the customer, or throws: the provider holding those
     * tax ids, or failing to read them. A customer never scripted holds none.
     *
     * @param  list<TaxId>|Throwable  $answer
     */
    public function scriptTaxIds(string $customerId, array|Throwable $answer): self
    {
        $this->taxIds[$customerId] = $answer;

        return $this;
    }

    /**
     * The fake as the container binds it in this process, a harness worker's included, since the
     * test environment names it.
     */
    public static function bound(): self
    {
        $port = self::port();

        return $port instanceof self ? $port : throw new LogicException('The fake billing provider is not bound.');
    }

    private static function port(): BillingProvider
    {
        return app(BillingProvider::class);
    }

    /**
     * Answers given one per read, in turn, the last repeating from then on: the provider's truth
     * moving between two reads of one subscription.
     */
    public function scriptInTurn(SubscriptionSnapshot $first, SubscriptionSnapshot ...$then): self
    {
        $answers = array_values([$first, ...$then]);
        $last = array_pop($answers);
        $this->script($last);
        $this->turns[$last->subscriptionId] = $answers;

        return $this;
    }

    public function startCheckout(Plan $plan, ?string $customerId, string $email, string $successUrl, string $cancelUrl): Checkout
    {
        $this->asked[] = ['startCheckout', ['plan' => $plan->key, 'price' => $plan->priceId, 'customer' => $customerId, 'email' => $email, 'success' => $successUrl, 'cancel' => $cancelUrl]];
        Checkpoint::reach(self::CHECKOUT);

        if ($this->checkoutFails !== null) {
            throw $this->checkoutFails;
        }

        $customer = $customerId ?? 'cus_fake_'.++$this->customers;

        return new Checkout(
            customerId: $customer,
            sessionId: 'cs_fake_'.++$this->sessions,
            url: self::checkoutUrl($customer, $plan->key, $successUrl),
        );
    }

    /**
     * Where the fake's checkout sends the person: straight back to the success URL, as though
     * they had paid at once, with the customer and the plan in a query the return page never
     * reads. A browser walk lands on the signup's pending page and posts the completion itself.
     */
    public static function checkoutUrl(string $customerId, string $planKey, string $successUrl): string
    {
        return $successUrl.(str_contains($successUrl, '?') ? '&' : '?').http_build_query(['fake_checkout' => $customerId, 'plan' => $planKey]);
    }

    public function openPortal(string $customerId, string $returnUrl): string
    {
        $this->asked[] = ['openPortal', ['customer' => $customerId, 'return' => $returnUrl]];

        return 'https://billing.test/portal/'.$customerId;
    }

    /**
     * @return list<TaxId>
     */
    public function taxIds(string $customerId): array
    {
        $this->asked[] = ['taxIds', ['customer' => $customerId]];
        $answer = $this->taxIds[$customerId] ?? [];

        return $answer instanceof Throwable ? throw $answer : $answer;
    }

    public function subscription(string $subscriptionId): SubscriptionSnapshot
    {
        $this->asked[] = ['subscription', ['subscription' => $subscriptionId]];
        $turn = $this->turns[$subscriptionId] ?? [];
        $this->turns[$subscriptionId] = array_slice($turn, 1);
        $answer = $turn[0] ?? $this->scripted[$subscriptionId] ?? throw new LogicException("No snapshot is scripted for {$subscriptionId}.");
        Checkpoint::reach(self::ANSWERED);

        return $answer;
    }

    /**
     * Answers the scripted snapshot with its items replaced by one of the plan's price, and
     * scripts that answer for the next read.
     */
    public function changePlan(string $subscriptionId, Plan $plan): SubscriptionSnapshot
    {
        $this->asked[] = ['changePlan', ['subscription' => $subscriptionId, 'plan' => $plan->key, 'price' => $plan->priceId]];
        $current = $this->scripted[$subscriptionId] ?? throw new LogicException("No snapshot is scripted for {$subscriptionId}.");
        $changed = new SubscriptionSnapshot($current->subscriptionId, $current->customerId, $current->status, $current->periodEnd, [new SnapshotItem($plan->priceId, 1)]);
        $this->script($changed);

        return $changed;
    }

    /**
     * Answers the scripted snapshot at the status canceled, its items and period kept, and
     * scripts that answer for the next read, the provider holding it ended from then on. A test
     * that set cancelFails is refused instead, after the question is recorded.
     */
    public function cancel(string $subscriptionId): SubscriptionSnapshot
    {
        $this->asked[] = ['cancel', ['subscription' => $subscriptionId]];

        if ($this->cancelFails !== null) {
            throw $this->cancelFails;
        }

        $current = $this->scripted[$subscriptionId] ?? throw new LogicException("No snapshot is scripted for {$subscriptionId}.");
        $ended = new SubscriptionSnapshot($current->subscriptionId, $current->customerId, 'canceled', $current->periodEnd, $current->items);
        $this->script($ended);

        return $ended;
    }

    public function signatureHeader(): string
    {
        return self::HEADER;
    }

    public function verify(string $payload, string $signature): BillingNotice
    {
        $this->asked[] = ['verify', ['signature' => $signature]];

        if (! hash_equals(self::sign($payload), $signature)) {
            throw new SignatureRefused('The notification does not carry the billing provider\'s signature.');
        }

        try {
            $notice = json_decode($payload, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new SignatureRefused('The notification is not one the billing provider sends.');
        }

        if (! is_array($notice) || ! is_string($notice['id'] ?? null) || ! is_string($notice['type'] ?? null) || ! is_string($notice['kind'] ?? null)) {
            throw new SignatureRefused('The notification is not one the billing provider sends.');
        }

        return new BillingNotice(
            $notice['id'],
            $notice['type'],
            NoticeKind::from($notice['kind']),
            is_string($notice['customer'] ?? null) ? $notice['customer'] : null,
            is_string($notice['subscription'] ?? null) ? $notice['subscription'] : null,
        );
    }

    /**
     * The fake's own shape carries the customer at its top, as notice() writes it.
     *
     * @return non-empty-list<string>
     */
    public function customerPath(): array
    {
        return ['customer'];
    }

    /**
     * The signature verify() accepts for a payload.
     */
    public static function sign(string $payload): string
    {
        return hash_hmac('sha256', $payload, self::SECRET);
    }

    /**
     * A notification in the fake's own shape, for sign() and a route that verifies it.
     */
    public static function notice(string $eventId, NoticeKind $kind, ?string $customerId = null, ?string $subscriptionId = null, ?string $type = null): string
    {
        return json_encode([
            'id' => $eventId,
            'type' => $type ?? 'fake.'.$kind->value,
            'kind' => $kind->value,
            'customer' => $customerId,
            'subscription' => $subscriptionId,
        ], JSON_THROW_ON_ERROR);
    }
}
