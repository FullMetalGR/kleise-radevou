<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Billing\BillingNotice;
use App\Billing\BillingProvider;
use App\Billing\Checkout;
use App\Billing\Plan;
use App\Billing\SubscriptionSnapshot;
use App\Billing\TaxId;
use Closure;
use Throwable;

/**
 * The fake billing provider with a fault, a race or a rewritten answer put in front of it, bound
 * in its place for one test: a plan change, a read or a portal that throws what the test hands; a
 * step run just before the fake answers a plan change or a read, where a webhook's write would
 * land between a caller's read of the row and its own write; or the snapshot a plan change answers
 * rewritten, the provider having taken the change and answered something the sync cannot land.
 * Everything else is the fake's, which still records what it was asked.
 */
final readonly class BillingPortDecorator implements BillingProvider
{
    /**
     * @param  (Closure(SubscriptionSnapshot): SubscriptionSnapshot)|null  $changeAnswers
     */
    private function __construct(
        private FakeBillingProvider $fake,
        private ?Closure $beforeChange,
        private ?Closure $beforeRead,
        private ?Throwable $changeFails,
        private ?Throwable $portalFails,
        private ?Throwable $readFails,
        private ?Closure $changeAnswers,
    ) {}

    /**
     * $changeAnswers, where given, rewrites the snapshot the fake answers a plan change with: the
     * provider having taken the change and answered something the sync cannot land.
     *
     * @param  (Closure(SubscriptionSnapshot): SubscriptionSnapshot)|null  $changeAnswers
     */
    public static function around(FakeBillingProvider $fake, ?Closure $beforeChange = null, ?Closure $beforeRead = null, ?Throwable $changeFails = null, ?Throwable $portalFails = null, ?Throwable $readFails = null, ?Closure $changeAnswers = null): void
    {
        app()->instance(BillingProvider::class, new self($fake, $beforeChange, $beforeRead, $changeFails, $portalFails, $readFails, $changeAnswers));
    }

    public function startCheckout(Plan $plan, ?string $customerId, string $email, string $successUrl, string $cancelUrl): Checkout
    {
        return $this->fake->startCheckout($plan, $customerId, $email, $successUrl, $cancelUrl);
    }

    public function openPortal(string $customerId, string $returnUrl): string
    {
        if ($this->portalFails !== null) {
            throw $this->portalFails;
        }

        return $this->fake->openPortal($customerId, $returnUrl);
    }

    /**
     * @return list<TaxId>
     */
    public function taxIds(string $customerId): array
    {
        return $this->fake->taxIds($customerId);
    }

    public function subscription(string $subscriptionId): SubscriptionSnapshot
    {
        if ($this->beforeRead !== null) {
            ($this->beforeRead)();
        }

        if ($this->readFails !== null) {
            throw $this->readFails;
        }

        return $this->fake->subscription($subscriptionId);
    }

    public function changePlan(string $subscriptionId, Plan $plan): SubscriptionSnapshot
    {
        if ($this->changeFails !== null) {
            throw $this->changeFails;
        }

        if ($this->beforeChange !== null) {
            ($this->beforeChange)();
        }

        $answer = $this->fake->changePlan($subscriptionId, $plan);

        return $this->changeAnswers === null ? $answer : ($this->changeAnswers)($answer);
    }

    public function cancel(string $subscriptionId): SubscriptionSnapshot
    {
        return $this->fake->cancel($subscriptionId);
    }

    public function signatureHeader(): string
    {
        return $this->fake->signatureHeader();
    }

    public function verify(string $payload, string $signature): BillingNotice
    {
        return $this->fake->verify($payload, $signature);
    }

    /**
     * @return non-empty-list<string>
     */
    public function customerPath(): array
    {
        return $this->fake->customerPath();
    }
}
