<?php

declare(strict_types=1);

namespace App\Billing;

use Illuminate\Database\DatabaseManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * What the billing webhook does with a notification the port verified. Nothing here reads the
 * provider's own shape: the port has already read it as a notice of ours.
 *
 * The event is recorded by its event id before anything else happens, and that row is the
 * idempotency key: an id recorded and marked processed is a repeat and does nothing, and an id
 * recorded but never marked, because an earlier attempt failed midway, is handled again, the
 * provider's retry being the only second chance it gets. The row is marked once handling
 * completes. A handling failure is logged once, a fixed sentence with the event in context, is
 * reported by the framework as well, which keeps its trace, and leaves the event unmarked; an
 * event the sync cannot resolve is one.
 *
 * The tenant is found through the subscriptions row, by the customer the event names or, naming
 * none, by its subscription. An event naming nothing a row knows, one naming no subscription
 * where one is needed, and one naming a subscription that is not the row's current one while
 * the row names one, are logged and ignored, never an error: a customer who subscribes again
 * gets a new subscription on the same row, and a late event for the old one must not write
 * over it. A completed checkout is the one event that may name a new subscription, since it is
 * what sets one.
 *
 * A changed or deleted subscription is applied by re-reading it through the port, never from the
 * event, so events arriving out of order land on the current truth. A charge event is applied
 * only once the re-read shows the subscription in the matching status, past due for a failure and
 * active for a success, since the port cannot read an invoice and a charge delivered late must
 * neither open grace on a paid tenant nor close it on an unpaid one. Every other kind is
 * recorded, marked and acted on by nobody.
 *
 * For one tenant, the comparison, the re-read, the status decision and the write hold together:
 * the row is read with its revision, the provider is asked outside any transaction, and the
 * sync's write, the checkout seam's included, is made at that revision under the tenant row's
 * lock and refused as Stale once another write has moved it, whereupon the event is handled
 * again from the row. Two deliveries for one tenant handled at the same moment so land as if one
 * had been handled after the other, and no lock is held across a question to the provider.
 */
final readonly class BillingWebhook
{
    public const string UNKNOWN = 'The billing webhook heard of a customer and subscription no tenant answers to; the event is ignored';

    public const string NO_SUBSCRIPTION = 'The billing webhook heard of an event naming no subscription; the event is ignored';

    public const string NOT_CURRENT = 'The billing webhook heard of a subscription that is not the tenant\'s current one; the event is ignored';

    public const string NOT_PAST_DUE = 'The billing provider reported a failed charge on a subscription it does not hold past due; no grace window is opened';

    public const string NOT_ACTIVE = 'The billing provider reported a succeeded charge on a subscription it does not hold active; no grace window is closed';

    public const string UNHANDLED = 'The billing webhook could not finish handling an event; it is left unprocessed for the provider to deliver again';

    /**
     * How many times an event is handled from the row before a row that keeps moving leaves it
     * for the provider's retry.
     */
    public const int ATTEMPTS = 3;

    public function __construct(
        private DatabaseManager $db,
        private BillingProvider $provider,
        private SubscriptionSync $sync,
        private CheckoutCompletion $checkout,
        private LoggerInterface $log,
    ) {}

    /**
     * Records a verified notification and handles it unless it has been handled already.
     *
     * @param  string  $payload  the body as the provider signed it
     *
     * @throws NoticeUnhandled when handling could not finish, the event left unmarked
     */
    public function receive(BillingNotice $notice, string $payload): void
    {
        if (! $this->stillToHandle($notice, $payload)) {
            return;
        }

        try {
            $this->handle($notice);
        } catch (Throwable $cause) {
            $this->log->error(self::UNHANDLED, ['event' => $notice->eventId, 'exception' => $cause::class, 'message' => $cause->getMessage()]);

            throw $cause instanceof NoticeUnhandled ? $cause : NoticeUnhandled::after($cause);
        }

        $this->db->connection()->update(
            'update webhook_events set processed_at = now(), updated_at = now() where provider_event_id = ?',
            [$notice->eventId],
        );
    }

    /**
     * Writes the event's row unless its id is already recorded, and answers whether it still
     * has to be handled: not yet marked processed.
     */
    private function stillToHandle(BillingNotice $notice, string $payload): bool
    {
        $db = $this->db->connection();

        $db->statement(
            'insert into webhook_events (provider_event_id, type, payload) values (?, ?, ?::jsonb) on conflict (provider_event_id) do nothing',
            [$notice->eventId, $notice->type, $payload],
        );

        $row = (array) $db->selectOne('select processed_at from webhook_events where provider_event_id = ?', [$notice->eventId]);

        return ($row['processed_at'] ?? null) === null;
    }

    private function handle(BillingNotice $notice): void
    {
        for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
            $outcome = $this->attempt($notice);

            if ($outcome === SyncOutcome::Unresolved) {
                throw NoticeUnhandled::unresolved();
            }

            if ($outcome !== SyncOutcome::Stale) {
                return;
            }
        }

        throw NoticeUnhandled::contended();
    }

    /**
     * One pass from the row: what the sync or the seam answered, or null when nothing was
     * written.
     */
    private function attempt(BillingNotice $notice): ?SyncOutcome
    {
        if ($notice->kind === NoticeKind::Other) {
            return null;
        }

        $row = $this->rowOf($notice);

        if ($row === null) {
            $this->log->warning(self::UNKNOWN, ['event' => $notice->eventId, 'customer' => $notice->customerId, 'subscription' => $notice->subscriptionId]);

            return null;
        }

        $subscription = $notice->subscriptionId;

        if ($subscription === null) {
            $this->log->warning(self::NO_SUBSCRIPTION, ['event' => $notice->eventId, 'type' => $notice->type]);

            return null;
        }

        if ($notice->kind !== NoticeKind::CheckoutCompleted && $row['subscription'] !== null && $row['subscription'] !== $subscription) {
            $this->log->warning(self::NOT_CURRENT, ['event' => $notice->eventId, 'tenant' => $row['tenant'], 'subscription' => $subscription, 'current' => $row['subscription']]);

            return null;
        }

        return match ($notice->kind) {
            NoticeKind::CheckoutCompleted => $this->checkout->completed($row['customer'], $subscription, $row['revision']),
            NoticeKind::SubscriptionChanged, NoticeKind::SubscriptionDeleted => $this->sync->refresh($subscription, $row['revision']),
            NoticeKind::ChargeFailed, NoticeKind::ChargeSucceeded => $this->charged($notice, $row['customer'], $subscription, $row['revision']),
        };
    }

    /**
     * A charge applied once the re-read shows the subscription in the status the charge means.
     */
    private function charged(BillingNotice $notice, string $customerId, string $subscriptionId, int $revision): ?SyncOutcome
    {
        $failed = $notice->kind === NoticeKind::ChargeFailed;
        $status = $this->provider->subscription($subscriptionId)->status;

        if (ProviderStatus::tryFrom($status) !== ($failed ? ProviderStatus::PastDue : ProviderStatus::Active)) {
            $this->log->info($failed ? self::NOT_PAST_DUE : self::NOT_ACTIVE, ['event' => $notice->eventId, 'subscription' => $subscriptionId, 'status' => $status]);

            return null;
        }

        return $failed
            ? $this->sync->chargeFailed($customerId, $revision)
            : $this->sync->chargeSucceeded($customerId, $revision);
    }

    /**
     * The subscriptions row the notice names, by its customer or, naming none, by its
     * subscription, with the tenant it belongs to and the revision it stands at.
     *
     * @return array{tenant: string, customer: string, subscription: string|null, revision: int}|null
     */
    private function rowOf(BillingNotice $notice): ?array
    {
        [$column, $value] = $notice->customerId !== null
            ? ['provider_customer_id', $notice->customerId]
            : ['provider_subscription_id', $notice->subscriptionId];

        if ($value === null) {
            return null;
        }

        $row = (array) $this->db->connection()->selectOne(
            "select t.slug, s.provider_customer_id, s.provider_subscription_id, s.revision from subscriptions s join tenants t on t.id = s.tenant_id where s.{$column} = ?",
            [$value],
        );
        $slug = $row['slug'] ?? null;
        $customer = $row['provider_customer_id'] ?? null;
        $subscription = $row['provider_subscription_id'] ?? null;
        $revision = $row['revision'] ?? null;

        return is_string($slug) && is_string($customer) && is_int($revision)
            ? ['tenant' => $slug, 'customer' => $customer, 'subscription' => is_string($subscription) ? $subscription : null, 'revision' => $revision]
            : null;
    }
}
