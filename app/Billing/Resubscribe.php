<?php

declare(strict_types=1);

namespace App\Billing;

use App\Application\Clock;
use App\Infrastructure\Db\Busy;
use App\Infrastructure\Db\SqlState;
use App\Infrastructure\Db\SqlText;
use App\Infrastructure\Db\StaffRecords;
use App\Platform\ClosingWindow;
use App\Tenancy\Tenant;
use App\Tenancy\TenantProvisioner;
use DateInterval;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;

/**
 * The Billing screen's checkout: the way back from canceled, and a trial's one road to a card. It
 * serves three states. In trial, and in canceled with trial_ends_at still set, a tenant that
 * never subscribed, the owner chooses the plan; with no subscriptions row the provider creates
 * the customer with the checkout and a new row records it, while a row an earlier checkout left
 * lends its customer. In canceled with a row, the way back, the checkout is for the row's own
 * customer, never a new one, on the plan the body names. Any other state is refused.
 *
 * The session is recorded on the subscriptions row, its id, its URL and when it opened, so a
 * second press is sent to the same session rather than a second one beside it, whatever plan that
 * press names, the session's own plan being the provider's to keep. A recorded session is open
 * for the provider's default session lifetime, the 24 hours a signup's slug is held for; past it
 * a press opens a new one. A press takes the subscriptions row's lock before it reads the record
 * and holds it across the provider's answer until the new session is recorded, so two presses at
 * once, two tabs, open one session: the second waits and answers the first's URL. It waits
 * LOCK_TIMEOUT at most, and past it answers Busy, nothing asked of the provider. The first
 * checkout of a tenant with no subscriptions row has no row to lock, and two such presses at once
 * still open a session each, the row recording the later one's customer.
 *
 * A closing account whose window ends before a checkout opened now would lapse is refused before
 * the recorded session is read, so no card is charged after the account is gone.
 *
 * The floor is refused with the count while more than one active staff member exists, before any
 * call to the provider, in the sentence the plan choice shows beside it, worded for a plan being
 * chosen in the state the checkout serves (PlanChange::choiceRefusal). The completion returns
 * through the checkout-completion seam, which lands the subscription on the same row.
 */
final readonly class Resubscribe
{
    /**
     * How long a press waits for another press of the same tenant to finish: long enough for the
     * provider to answer the first in the ordinary case, short enough that a stuck holder frees
     * the second request quickly with a sentence to try again.
     */
    public const string LOCK_TIMEOUT = '3s';

    /**
     * How long a recorded checkout stays open: the provider's default session lifetime, the 24
     * hours a signup's slug is held for. A record opened this long ago or longer is gone, here and
     * to the retention sweep's line that clears it (App\Retention\CheckoutRecordsLine).
     */
    public const string CHECKOUT_LIFETIME = TenantProvisioner::ABANDONED_AFTER;

    public function __construct(
        private DatabaseManager $db,
        private PlanCatalogue $catalogue,
        private BillingProvider $provider,
        private SubscriptionSync $sync,
        private StaffRecords $staff,
        private Clock $clock,
    ) {}

    /**
     * Answers where to send the owner to pay, asking the provider nothing while a recorded
     * session is open.
     *
     * @throws ResubscribeRefused outside trial and canceled, and in canceled with neither a
     *                            subscriptions row nor a trial behind it
     * @throws PlanChangeRefused for the floor while more than one staff member is active
     * @throws CatalogueUnreadable
     * @throws BillingProviderFailed
     * @throws Busy when another press of the same tenant held the row past the lock timeout
     */
    public function start(Tenant $tenant, string $planKey, string $email, string $returnUrl): string
    {
        try {
            return $this->db->connection()->transaction(
                fn (Connection $db): string => $this->startLocked($db, $tenant, $planKey, $email, $returnUrl),
            );
        } catch (QueryException $cause) {
            throw SqlState::of($cause) === SqlState::LockNotAvailable ? Busy::on($tenant->slug, $cause) : $cause;
        }
    }

    /**
     * The start under the subscriptions row's lock, taken before the recorded session is read
     * and held across the provider's answer until the new session is recorded.
     */
    private function startLocked(Connection $db, Tenant $tenant, string $planKey, string $email, string $returnUrl): string
    {
        $db->statement(sprintf("set local lock_timeout = '%s'", self::LOCK_TIMEOUT));
        $db->selectOne('select tenant_id from subscriptions where tenant_id = ? for update', [$tenant->id]);
        $row = SubscriptionRow::of($this->db, $tenant);
        $state = SubscriptionState::from($tenant->subscription_state);

        if (! self::serves($state, $row !== null, $tenant->trial_ends_at !== null)) {
            throw new ResubscribeRefused;
        }

        if ($this->windowEndsFirst($tenant)) {
            throw ResubscribeRefused::windowEnding();
        }

        $open = $this->openCheckout($tenant);

        if ($open !== null) {
            return $open;
        }

        $plan = $this->catalogue->plan($planKey);
        $refused = PlanChange::choiceRefusal($plan, $this->staff->activeCount($tenant), $state);

        if ($refused !== null) {
            throw $refused;
        }

        $checkout = $this->provider->startCheckout($plan, $row?->customerId, $email, $returnUrl, $returnUrl);
        $this->sync->attachCustomer($tenant, $checkout);

        return $checkout->url;
    }

    /**
     * Whether the checkout serves a tenant: a trial, and a canceled tenant with a row to start
     * again from or a trial it never subscribed from.
     */
    public static function serves(SubscriptionState $state, bool $hasRow, bool $wasTrial): bool
    {
        return match ($state) {
            SubscriptionState::Trial => true,
            SubscriptionState::Canceled => $hasRow || $wasTrial,
            default => false,
        };
    }

    /**
     * Whether the tenant is closing and its window ends before a checkout opened now would
     * lapse: a checkout completed after the account is dropped charges a card with no account
     * left to record it, so none is opened, nor a recorded one handed back, that could outlive
     * the window.
     */
    private function windowEndsFirst(Tenant $tenant): bool
    {
        $closingAt = ClosingWindow::of($tenant);

        return $closingAt !== null
            && ClosingWindow::endOf($closingAt) < $this->clock->now()->add(new DateInterval(self::CHECKOUT_LIFETIME));
    }

    /**
     * The URL of the checkout the row records, while it opened less than 24 hours ago.
     */
    public function openCheckout(Tenant $tenant): ?string
    {
        $since = SqlText::instant($this->clock->now()->sub(new DateInterval(self::CHECKOUT_LIFETIME)));
        $url = $this->db->connection()->table('subscriptions')
            ->where('tenant_id', $tenant->id)
            ->whereNotNull('checkout_session_id')
            ->whereRaw('checkout_opened_at > ?::timestamptz', [$since])
            ->value('checkout_url');

        return is_string($url) ? $url : null;
    }
}
