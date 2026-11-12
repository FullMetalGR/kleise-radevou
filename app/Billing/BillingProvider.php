<?php

declare(strict_types=1);

namespace App\Billing;

/**
 * The platform's billing provider, the one that charges tenants for this product. It is separate
 * from the payment port, the providers a tenant uses to charge their own customers: the two
 * systems share a vendor at v1 and no code may assume they do.
 *
 * It answers what billing needs and nothing more, in values of ours, so nothing on this side
 * reads the provider's shape and the adapter reads nothing of ours but these. Nothing here is
 * called by a gate: every gate reads the tenant row the sync wrote, and touches no network.
 */
interface BillingProvider
{
    /**
     * Start a hosted checkout selling the plan and hand back where to send the person. With no
     * customer given, the provider's customer is created first and named in the answer, so the
     * caller can record it before the person leaves and the first event finds its tenant.
     */
    public function startCheckout(Plan $plan, ?string $customerId, string $email, string $successUrl, string $cancelUrl): Checkout;

    /**
     * Open the provider's hosted portal for a customer and hand back its URL.
     */
    public function openPortal(string $customerId, string $returnUrl): string;

    /**
     * Read the tax ids the provider's first answer carries for a customer, the business's VAT
     * number among them, in its order: the empty list where it carries none. No further page is
     * asked for; the Billing screen reads the first id, after its render, never on it.
     *
     * @return list<TaxId>
     */
    public function taxIds(string $customerId): array;

    /**
     * Read a subscription as the provider holds it now.
     */
    public function subscription(string $subscriptionId): SubscriptionSnapshot;

    /**
     * Change a subscription's items to the plan and hand back the subscription the provider
     * answers with.
     */
    public function changePlan(string $subscriptionId, Plan $plan): SubscriptionSnapshot;

    /**
     * Cancel a subscription at once, with no final invoice and no proration, and hand back the
     * subscription the provider answers with, ended. The owner's close calls it after its commit,
     * so nothing more is charged from then on.
     */
    public function cancel(string $subscriptionId): SubscriptionSnapshot;

    /**
     * The request header an inbound notification carries its signature in. The provider names
     * it, not the route, so the webhook hands verify() what this provider signs with whichever
     * provider is bound.
     */
    public function signatureHeader(): string;

    /**
     * Verify an inbound notification against its signature and read it as a notice of ours.
     *
     * @throws SignatureRefused for anything it cannot verify
     */
    public function verify(string $payload, string $signature): BillingNotice;

    /**
     * Where a notification this provider sends carries the platform customer it is about, as the
     * keys leading to it in the payload verify() reads: the provider's own shape, which no code
     * outside the adapter may assume. The drop deletes a closed tenant's stored notifications by
     * it (webhook_events); a notification carrying no customer there is left to its ninety days.
     *
     * @return non-empty-list<string>
     */
    public function customerPath(): array;
}
