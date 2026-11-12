<?php

declare(strict_types=1);

namespace App\Billing;

use App\Tenancy\TenantStatus;
use Illuminate\Database\DatabaseManager;
use Psr\Log\LoggerInterface;

/**
 * The checkout completion: the provider's completed checkout lands the subscription the session
 * names on a built tenant's row, the way back's checkout from the Billing screen. The signup
 * opens no checkout and builds its row on the verification link's click, so nothing here builds:
 * a completion that finds the row still provisioning, which no road in the product opens, writes
 * nothing and leaves a line at warning naming the row it found (P9F-50b), where one that finds no
 * row at all leaves an error line, a customer having paid with no tenant to show.
 *
 * The pair has landed when the sync's write for it has: the refresh answered Applied, whatever
 * state it landed at, incomplete included, or the row already names the session's subscription,
 * read here and not taken from the webhook. A row naming it at incomplete is read once more all
 * the same, in case the provider has moved on since; that is one further read, not a second rule
 * of what has landed. A refresh answering anything but Applied (Stale, Unresolved, UnknownTenant)
 * is answered before anything else is written, and an Applied that landed on another row, which
 * only a provider naming another known customer could cause, leaves a line. Two completions for
 * one pair at once land as one through the revision the sync writes at. The contract is the
 * interface's.
 *
 * Once the pair has landed the checkout the row remembers is forgotten and one line at info says
 * nothing was provisioned, the schema being there. A completion naming the row's own subscription
 * goes through the plain refresh while the row holds it at incomplete, so an expired first charge
 * lands as canceled; at any other state it is the landed no-op, and at canceled, a late
 * completion for the ended pair the row still names, it leaves the way back's open checkout
 * alone, since that record is a newer session's. On a row naming another subscription, one the
 * provider holds ended never lands: a late completion for a pair since replaced, or the way
 * back's own subscription ended before its event was handled, writes nothing over the row,
 * leaves a line and forgets the checkout the row remembers, the session it completed being spent.
 *
 * A tenant in trial, or one whose trial ended unpaid, names no subscription at all: its
 * checkout's completion is the row's first, read through the plain refresh, and the sync's write
 * that lands its state clears trial_ends_at. A completion landing inside a closing window also
 * clears closing_at and writes the status active.
 */
final readonly class SignupCompletion implements CheckoutCompletion
{
    public const string NO_ROW = 'The checkout completion found no row for a completed checkout; the customer has paid and has no tenant';

    public const string NOT_LANDED = 'The checkout completion\'s subscription landed on no row of its customer; the row is left as it was';

    public const string UNBUILT = 'The checkout completion found its customer\'s row never built; the signup opens no checkout, so nothing is landed';

    public const string BUILT = 'The checkout completion landed its subscription on a tenant already built; nothing is provisioned';

    public const string ENDED = 'The checkout completion named a subscription that has ended over the tenant\'s current one; nothing is landed and its spent checkout is forgotten';

    public function __construct(
        private DatabaseManager $db,
        private SubscriptionSync $sync,
        private BillingProvider $provider,
        private LoggerInterface $log,
    ) {}

    public function completed(string $customerId, string $subscriptionId, int $revision): ?SyncOutcome
    {
        $row = (array) $this->db->connection()->selectOne(
            'select t.id, t.slug, t.status, t.subscription_state, s.provider_subscription_id from subscriptions s join tenants t on t.id = s.tenant_id where s.provider_customer_id = ?',
            [$customerId],
        );
        $tenantId = $row['id'] ?? null;

        if (! is_int($tenantId)) {
            $this->log->error(self::NO_ROW, ['customer' => $customerId, 'subscription' => $subscriptionId]);

            return null;
        }

        if (($row['status'] ?? null) === TenantStatus::Provisioning->value) {
            $this->log->warning(self::UNBUILT, ['tenant' => self::slugOf($row), 'customer' => $customerId, 'subscription' => $subscriptionId]);

            return null;
        }

        $current = $row['provider_subscription_id'] ?? null;
        $state = $row['subscription_state'] ?? null;

        if ($current === $subscriptionId && $state !== SubscriptionState::Incomplete->value) {
            if ($state !== SubscriptionState::Canceled->value) {
                $this->sync->forgetCheckout($tenantId);
                $this->reopen($tenantId);
            }

            return null;
        }

        $outcome = is_string($current) && $current !== $subscriptionId
            ? $this->landOver($tenantId, self::slugOf($row), $current, $subscriptionId, $revision)
            : $this->sync->refresh($subscriptionId, $revision);

        if ($outcome !== SyncOutcome::Applied) {
            return $outcome;
        }

        if (! $this->db->connection()->table('subscriptions')->where('tenant_id', $tenantId)->where('provider_subscription_id', $subscriptionId)->exists()) {
            $this->log->error(self::NOT_LANDED, ['tenant' => $tenantId, 'customer' => $customerId, 'subscription' => $subscriptionId]);

            return $outcome;
        }

        $this->sync->forgetCheckout($tenantId);
        $this->reopen($tenantId);
        $this->log->info(self::BUILT, ['tenant' => self::slugOf($row), 'customer' => $customerId, 'subscription' => $subscriptionId]);

        return $outcome;
    }

    /**
     * A subscription landing inside a closing window ends the closing: closing_at is cleared and
     * the tenant is active again; a tenant with no closing set is left as it is, and so is a closed
     * one, whose closing_at the drop kept beside its closed_at: the drop ended the window, and a
     * closed row holds no schema to serve. It runs wherever the seam finds the pair landed, so a
     * provider retry after a write that landed the subscription and stopped short of this one still
     * ends the closing, and so does a completion arriving after the webhook landed its
     * subscription.
     */
    private function reopen(int $tenantId): void
    {
        $this->db->connection()->update(
            'update tenants set closing_at = null, status = ?, updated_at = now() where id = ? and status = ? and closing_at is not null',
            [TenantStatus::Active->value, $tenantId, TenantStatus::Closing->value],
        );
    }

    /**
     * The re-read for a built row naming another subscription, the way back's case: the
     * subscription the completion names is read as refresh would read it and applied at the
     * revision, unless the provider holds it ended. That one is a late completion for a pair
     * since replaced, or the way back's own subscription ended before its event was handled; it
     * writes nothing over the row and leaves a line, and the checkout the row remembers is
     * forgotten, the session it completed being spent, so the screen stops offering Continue to
     * checkout for a session nobody can finish.
     */
    private function landOver(int $tenantId, ?string $slug, string $current, string $subscriptionId, int $revision): ?SyncOutcome
    {
        $snapshot = $this->provider->subscription($subscriptionId);

        if (ProviderStatus::tryFrom($snapshot->status)?->state() === SubscriptionState::Canceled) {
            $this->sync->forgetCheckout($tenantId);
            $this->log->warning(self::ENDED, ['tenant' => $slug, 'subscription' => $subscriptionId, 'current' => $current]);

            return null;
        }

        return $this->sync->apply($snapshot, $revision);
    }

    /**
     * The tenant's slug as the row read it, for a log line's context.
     *
     * @param  array<mixed>  $row
     */
    private static function slugOf(array $row): ?string
    {
        $slug = $row['slug'] ?? null;

        return is_string($slug) ? $slug : null;
    }
}
