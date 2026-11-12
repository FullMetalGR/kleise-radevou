<?php

declare(strict_types=1);

namespace App\Billing;

/**
 * What a completed checkout sets going: the seam the billing webhook hands a checkout-completed
 * event to, once it has found the tenant the customer names. SignupCompletion fills it: it syncs
 * the subscription the session names onto a built tenant's row, the way back's checkout from the
 * Billing screen, which is what sets a new subscription identifier on a row that already names
 * one; the signup opens no checkout.
 *
 * The customer is the tenant's reference on the provider's side, recorded on the subscriptions
 * row before the person left for the checkout; the subscription is the one the session created;
 * the revision is the subscriptions row's as the webhook read it. The contract an implementation
 * keeps:
 * - It writes the billing columns through the sync alone, handing it this revision: the plain
 *   refresh (SubscriptionSync::refresh($subscriptionId, $revision)), or, where it has read the
 *   subscription itself first to decide whether it may land, SubscriptionSync::apply on that
 *   snapshot, as SignupCompletion does over a row naming another subscription. It answers that
 *   write's outcome: Stale sends the webhook back to read the row and call again, Unresolved
 *   leaves the event unmarked. It answers null when it wrote nothing through the sync.
 * - It asks the provider outside any transaction and holds no lock across the question; the
 *   revision is what orders it against every other event for the tenant.
 * - One that could not finish throws, and the event stays unmarked for the provider's retry.
 * - Two attempts for one pair may run at once and must land as one, and an attempt for a pair
 *   already completed does nothing; it may be called again for the same pair after a Stale.
 */
interface CheckoutCompletion
{
    public function completed(string $customerId, string $subscriptionId, int $revision): ?SyncOutcome;
}
