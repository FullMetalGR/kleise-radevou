<?php

declare(strict_types=1);

namespace App\Billing;

use App\Application\Clock;
use App\Infrastructure\Db\SqlText;
use App\Infrastructure\Db\TextArray;
use App\Tenancy\Tenant;
use DateInterval;
use DateTimeZone;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;

/**
 * The one writer of the tenant row's billing columns and of the subscriptions row once they exist.
 * The provisioner creates the tenant row with its entitlements column, empty, the set
 * tenant:provision was handed or a trial's full set, and from then on nothing but this writes it;
 * trial is the one state this never writes, and the hourly lapse ends it. Every gate reads what
 * this wrote and touches no network.
 *
 * A snapshot resolves its items to features through the catalogue, a price it does not know
 * contributing nothing, and writes the resolved slugs beside the raw identifiers, the state the
 * provider's status maps to, and the narrowing record's four cases: a resolve that takes
 * something away records what it took, one that gives something back clears the record, one that
 * changes nothing keeps it, and one the owner asked for records nothing of what the owner asked to
 * remove. The owner's own plan change is the one caller that says who asked, by handing the set
 * the owner chose; the webhook hands none. A resolve that throws, on a malformed snapshot or a
 * catalogue nobody can read, changes nothing and logs, which grants what is already stored.
 *
 * The invoice events own the grace window. A failed charge on an active tenant opens it with an
 * end date the configured days ahead, and a second failure never extends it; a succeeded charge
 * closes it. A snapshot never closes an open window by carrying a paid-up status: only a
 * succeeded charge does, or the lapse, which writes past_due once the end has passed, so a gate
 * reads one column and never compares a date. A subscription the provider deleted is the one
 * snapshot that ends a window, writing canceled whatever was stored, and no charge revives it.
 *
 * Every write but three takes the tenant row for update inside one transaction, so two events for
 * one tenant apply one after the other, and a write that fails midway leaves every column as it
 * was. The three are attachCustomer, which writes the subscriptions row alone before any event can
 * name the customer, forgetCheckout, which clears the checkout it recorded and no billing column,
 * and the lapse, one statement whose own row locks order it against the rest. Every locked write
 * bumps the subscriptions row's revision, even one that changes no state, and a caller handing the
 * revision it read the row at is refused with Stale, nothing written, once it has moved: the
 * webhook reads the row, compares, asks the provider outside any transaction and writes at what it
 * read, so two events for one tenant land as if one after the other with no lock held across a
 * network call. A caller handing no revision writes whatever moved. A tenant is found by the
 * provider's customer, which the Billing screen's checkout records through attachCustomer on the
 * built tenant's row before the person leaves for the provider; a row TenantProvisioner::release()
 * gives back takes its subscriptions row with it, and only while it names no subscription. A
 * snapshot that takes the staff feature away also sets the tenant's implicit member back to offered
 * online, in the same transaction, the one write here into a tenant schema.
 * Instants cross to the database as UTC text with the offset written out, and updated_at is the
 * server's now() in every statement.
 */
final readonly class SubscriptionSync
{
    private const int ATTEMPTS = 3;

    public function __construct(
        private DatabaseManager $db,
        private PlanCatalogue $catalogue,
        private BillingProvider $provider,
        private Clock $clock,
        private LoggerInterface $log,
    ) {}

    /**
     * Records the provider's customer signup created for the tenant, before the person is sent
     * to the checkout, so the first event finds its tenant, and in the same write the checkout
     * itself: its session, its URL and the instant it opened, which a holder coming back is sent
     * to. The subscription stays unnamed until the first snapshot.
     */
    public function attachCustomer(Tenant $tenant, Checkout $checkout): void
    {
        $this->db->connection()->statement(
            'insert into subscriptions (tenant_id, provider_customer_id, checkout_session_id, checkout_url, checkout_opened_at) values (?, ?, ?, ?, ?::timestamptz)
             on conflict (tenant_id) do update set provider_customer_id = excluded.provider_customer_id, checkout_session_id = excluded.checkout_session_id,
                 checkout_url = excluded.checkout_url, checkout_opened_at = excluded.checkout_opened_at, updated_at = now()',
            [$tenant->id, $checkout->customerId, $checkout->sessionId, $checkout->url, SqlText::instant($this->clock->now())],
        );
    }

    /**
     * Forgets the checkout the tenant's row remembers, once the sync's write for the pair it
     * completed has landed: the session is spent and nobody is sent to it again. It touches no
     * billing column and moves no revision, as attachCustomer does not.
     */
    public function forgetCheckout(int $tenantId): void
    {
        $this->db->connection()->update(
            'update subscriptions set checkout_session_id = null, checkout_url = null, checkout_opened_at = null, updated_at = now()
             where tenant_id = ? and checkout_session_id is not null',
            [$tenantId],
        );
    }

    /**
     * Resolves a snapshot onto its tenant's row and keeps it on the subscriptions row. $chosen is
     * the set the owner chose when the snapshot answers the owner's own plan change, and null for
     * every snapshot nobody here asked for, the webhook's among them.
     */
    public function apply(SubscriptionSnapshot $snapshot, ?int $revision = null, ?Entitlements $chosen = null): SyncOutcome
    {
        $tenant = $this->tenantOf($snapshot->customerId);

        if ($tenant === null) {
            return $this->unknown($snapshot->customerId);
        }

        try {
            $resolved = $this->catalogue->entitlementsFor($snapshot->items)->toSlugs();
        } catch (CatalogueUnreadable|MalformedSnapshot $cause) {
            return $this->unresolved($tenant['slug'], $cause);
        }

        $status = ProviderStatus::tryFrom($snapshot->status);

        $outcome = $this->db->connection()->transaction(function (Connection $db) use ($tenant, $snapshot, $resolved, $status, $revision, $chosen): SyncOutcome {
            $row = $this->locked($db, $tenant['id']);

            if (! $this->heldAt($db, $tenant['id'], $revision)) {
                return SyncOutcome::Stale;
            }

            $stored = SubscriptionState::from($row['subscription_state']);
            $state = self::stateAfter($stored, $status);
            $columns = ['subscription_state' => $state->value];

            // While the row stays in trial its set is the trial's full one until the trial ends or
            // a subscription lands: a status kept as stored leaves the entitlements, plan_ref and
            // the record where they are.
            if ($state !== SubscriptionState::Trial) {
                $columns['entitlements'] = TextArray::encode($resolved);
                $columns['plan_ref'] = $snapshot->items === [] ? null : implode(',', array_map(fn (SnapshotItem $item): string => $item->priceId, $snapshot->items));
                // The trial's full set was the platform's to give and nobody's to buy: the first
                // subscription's set is the plan the owner chose at the checkout, so what it
                // leaves out of the trial's is not recorded as taken.
                $owned = $stored === SubscriptionState::Trial ? $resolved : $chosen?->toSlugs();
                $narrowed = self::narrowedAfter(self::slugsOf($row['entitlements']), $resolved, Narrowing::ofLiteral($row['narrowed']), $owned);

                if ($narrowed !== null) {
                    $columns['narrowed'] = TextArray::encode($narrowed);
                }

                if (in_array(Feature::Staff->value, self::slugsOf($row['entitlements']), true) && ! in_array(Feature::Staff->value, $resolved, true)) {
                    $this->offerImplicitOnline($db, $tenant['schema']);
                }

                // Likewise only a trial carries its end: the write landing the first
                // subscription's state clears it, and a status kept as stored keeps the trial and
                // the end the lapse reads.
                $columns['trial_ends_at'] = null;
            }

            // Only an open window carries an end date: a state the snapshot moved out of grace
            // leaves none behind for a later reader to mistake for one.
            if ($state !== SubscriptionState::Grace) {
                $columns['grace_ends_at'] = null;
            }

            $db->table('tenants')->where('id', $tenant['id'])->update([...$columns, 'updated_at' => $db->raw('now()')]);
            $db->table('subscriptions')->where('tenant_id', $tenant['id'])->update([
                'provider_subscription_id' => $snapshot->subscriptionId,
                'status' => $snapshot->status,
                'current_period_end' => SqlText::instant($snapshot->periodEnd),
                'items' => json_encode(array_map(fn (SnapshotItem $item): array => ['price' => $item->priceId, 'quantity' => $item->quantity], $snapshot->items), JSON_THROW_ON_ERROR),
                'revision' => $db->raw('revision + 1'),
                'updated_at' => $db->raw('now()'),
            ]);

            return SyncOutcome::Applied;
        }, self::ATTEMPTS);

        // A status this build does not know is reported once the write it rode on has landed,
        // never before the revision check: a delivery whose row moves is applied again from the
        // row, and a line per pass would report one status as several.
        if ($status === null && $outcome === SyncOutcome::Applied) {
            $this->log->warning('The billing provider reported a subscription status this build does not know; the stored state is kept', [
                'tenant' => $tenant['slug'],
                'status' => $snapshot->status,
            ]);
        }

        return $outcome;
    }

    /**
     * Asks the provider for the subscription as it stands and applies the answer, so an event is
     * told apart from a real change by re-reading rather than by trusting it. The question is
     * asked before any transaction opens: no network inside one. The owner's plan change
     * re-reading after its own write met a moved revision hands the set the owner chose, as apply
     * takes it: it is still the owner's change.
     */
    public function refresh(string $subscriptionId, ?int $revision = null, ?Entitlements $chosen = null): SyncOutcome
    {
        return $this->apply($this->provider->subscription($subscriptionId), $revision, $chosen);
    }

    /**
     * A failed charge opens the grace window on an active tenant, its end the configured days
     * ahead. An open window is never extended, and a tenant already lapsed, canceled or
     * incomplete gets no window: grace is the space between a paid-up tenant and the freeze. A
     * grace length nobody can read changes nothing and logs, as an unreadable catalogue does.
     */
    public function chargeFailed(string $customerId, ?int $revision = null): SyncOutcome
    {
        $tenant = $this->tenantOf($customerId);

        if ($tenant === null) {
            return $this->unknown($customerId);
        }

        try {
            $days = $this->catalogue->graceDays();
        } catch (CatalogueUnreadable $cause) {
            return $this->unresolved($tenant['slug'], $cause);
        }

        // Whole days counted in UTC, so a window runs the same number of hours whichever zone
        // the clock answers in.
        $ends = SqlText::instant($this->clock->now()->setTimezone(new DateTimeZone('UTC'))->add(new DateInterval(sprintf('P%dD', $days))));

        return $this->db->connection()->transaction(function (Connection $db) use ($tenant, $ends, $revision): SyncOutcome {
            $stored = SubscriptionState::from($this->locked($db, $tenant['id'])['subscription_state']);

            if (! $this->heldAt($db, $tenant['id'], $revision)) {
                return SyncOutcome::Stale;
            }

            if ($stored === SubscriptionState::Active) {
                $this->writeState($db, $tenant['id'], SubscriptionState::Grace, $ends);
            }

            return $this->bumped($db, $tenant['id']);
        }, self::ATTEMPTS);
    }

    /**
     * A succeeded charge closes the window: active, and the date cleared. It brings back a tenant
     * the lapse froze and one whose first charge was incomplete, but it revives no subscription
     * the provider deleted; a new subscription's snapshot does that.
     */
    public function chargeSucceeded(string $customerId, ?int $revision = null): SyncOutcome
    {
        $tenant = $this->tenantOf($customerId);

        if ($tenant === null) {
            return $this->unknown($customerId);
        }

        return $this->db->connection()->transaction(function (Connection $db) use ($tenant, $revision): SyncOutcome {
            $stored = SubscriptionState::from($this->locked($db, $tenant['id'])['subscription_state']);

            if (! $this->heldAt($db, $tenant['id'], $revision)) {
                return SyncOutcome::Stale;
            }

            if (in_array($stored, [SubscriptionState::Grace, SubscriptionState::PastDue, SubscriptionState::Incomplete], true)) {
                $this->writeState($db, $tenant['id'], SubscriptionState::Active, null);
            }

            return $this->bumped($db, $tenant['id']);
        }, self::ATTEMPTS);
    }

    /**
     * Lapses every tenant whose grace window has run out: past_due, and the date cleared, so the
     * gate reads one column and never compares a date. One statement, so a charge succeeding at
     * the same moment either closed the window first or finds the tenant lapsed and revives it.
     *
     * @return int how many tenants lapsed
     */
    public function lapseExpired(): int
    {
        return $this->db->connection()->update(
            'update tenants set subscription_state = '.SqlText::literal(SubscriptionState::PastDue).', grace_ends_at = null, updated_at = now()
             where subscription_state = '.SqlText::literal(SubscriptionState::Grace).' and grace_ends_at <= ?::timestamptz',
            [SqlText::instant($this->clock->now())],
        );
    }

    /**
     * Ends every trial whose end has passed by the clock: canceled, so configuration writes
     * freeze as for any lapse and bookings keep running, and Subscribe again is the way back.
     * grace_ends_at, which a trial never opens, is untouched, and trial_ends_at stays as the
     * instant the trial ended. One statement of its own beside the grace lapse, so the first
     * subscription's write landing at the same moment either cleared the trial first or finds it
     * canceled, a state its snapshot moves on from.
     *
     * @return int how many trials ended
     */
    public function lapseTrials(): int
    {
        return $this->db->connection()->update(
            'update tenants set subscription_state = '.SqlText::literal(SubscriptionState::Canceled).', updated_at = now()
             where subscription_state = '.SqlText::literal(SubscriptionState::Trial).' and trial_ends_at <= ?::timestamptz',
            [SqlText::instant($this->clock->now())],
        );
    }

    /**
     * The state a snapshot leaves: a subscription the provider deleted is canceled whatever was
     * stored, an open window included; any other snapshot leaves an open window open; a status
     * the map leaves to the charge events or does not know keeps what is stored; and every other
     * status writes the state it maps to. No status maps to trial, which provisioning alone
     * writes: a snapshot on a trial row is the first subscription's and lands the state its status
     * maps to, and only a status kept as stored leaves the trial, and its end, where they are.
     */
    private static function stateAfter(SubscriptionState $stored, ?ProviderStatus $status): SubscriptionState
    {
        $mapped = $status?->state();

        if ($mapped === SubscriptionState::Canceled) {
            return $mapped;
        }

        if ($stored === SubscriptionState::Grace) {
            return $stored;
        }

        return $mapped ?? $stored;
    }

    /**
     * The narrowing record after a resolve, or null to keep it as it is: what the resolve took
     * away unasked, else the empty list when it gave something back, else, having taken nothing
     * unasked and given nothing back, null for a snapshot nobody here asked for, and on the
     * owner's own change never null but what of the record the chosen set carries.
     *
     * On the owner's own plan change, $chosen the set the owner chose, what the resolve took that
     * the chosen set lacks is what the owner asked to remove, and the record describes what the
     * subscription did without being asked, so it is not recorded; what it took that the chosen
     * set carries still is. Where nothing else is recorded or given back, the record keeps only
     * what the chosen set carries, so a record the webhook for this same change left, having
     * landed first and taken the feature unmarked, goes with the owner's re-read, and a record of
     * what the chosen set carries stays, an Add Staff answered by a snapshot still lacking staff.
     *
     * @param  list<string>  $before
     * @param  list<string>  $after
     * @param  list<string>  $recorded
     * @param  list<string>|null  $chosen
     * @return list<string>|null
     */
    private static function narrowedAfter(array $before, array $after, array $recorded, ?array $chosen): ?array
    {
        $taken = array_values(array_diff($before, $after));
        $unasked = $chosen === null ? $taken : array_values(array_intersect($taken, $chosen));

        if ($unasked !== []) {
            return $unasked;
        }

        if (array_diff($after, $before) !== []) {
            return [];
        }

        return $chosen === null ? null : array_values(array_intersect($recorded, $chosen));
    }

    /**
     * The stored set as the features this build knows, a garbled value reading as the floor.
     *
     * @return list<string>
     */
    private static function slugsOf(mixed $literal): array
    {
        try {
            return Entitlements::fromSlugs(is_string($literal) ? TextArray::decode($literal) : [])->toSlugs();
        } catch (InvalidArgumentException) {
            return [];
        }
    }

    /**
     * @return array{id: int, slug: string, schema: string}|null
     */
    private function tenantOf(string $customerId): ?array
    {
        $row = (array) $this->db->connection()->selectOne(
            'select t.id, t.slug, t.schema_name from subscriptions s join tenants t on t.id = s.tenant_id where s.provider_customer_id = ?',
            [$customerId],
        );
        $id = $row['id'] ?? null;
        $slug = $row['slug'] ?? null;
        $schema = $row['schema_name'] ?? null;

        return is_int($id) && is_string($slug) && is_string($schema) ? ['id' => $id, 'slug' => $slug, 'schema' => $schema] : null;
    }

    /**
     * Sets the implicit member back to offered online once the staff feature has left: on the
     * floor no screen shows that member's flag, so one left unticked would empty the booking
     * page with no visible cause and no way to tick it again. A tenant whose schema is gone, or
     * was never built, has no member to set.
     */
    private function offerImplicitOnline(Connection $db, string $schema): void
    {
        if ($db->scalar('select to_regclass(?) is not null', [$schema.'.staff']) !== true) {
            return;
        }

        $db->update('update '.$schema.'.staff set online = true, updated_at = now() where is_implicit and not online');
    }

    private function unknown(string $customerId): SyncOutcome
    {
        $this->log->warning('The billing provider named a customer no tenant answers to; nothing was written', [
            'customer' => $customerId,
        ]);

        return SyncOutcome::UnknownTenant;
    }

    /**
     * A resolve that threw, on our side of the seam: nothing is written, which grants what is
     * already stored, and one line says which tenant and why.
     */
    private function unresolved(string $tenant, CatalogueUnreadable|MalformedSnapshot $cause): SyncOutcome
    {
        $this->log->warning('The subscription sync left a tenant as it was', [
            'tenant' => $tenant,
            'exception' => $cause::class,
            'message' => $cause->getMessage(),
        ]);

        return SyncOutcome::Unresolved;
    }

    /**
     * @return array{subscription_state: string, entitlements: mixed, narrowed: mixed}
     */
    private function locked(Connection $db, int $tenantId): array
    {
        $row = (array) $db->selectOne('select subscription_state, entitlements, narrowed from tenants where id = ? for update', [$tenantId]);
        $state = $row['subscription_state'] ?? null;

        if (! is_string($state)) {
            throw new InvalidArgumentException("Tenant {$tenantId} has no row to write billing onto.");
        }

        return ['subscription_state' => $state, 'entitlements' => $row['entitlements'] ?? null, 'narrowed' => $row['narrowed'] ?? null];
    }

    /**
     * Whether the subscriptions row still stands at the revision the caller read it at, asked
     * with the tenant row locked, which every bump is made under; no revision asks nothing.
     */
    private function heldAt(Connection $db, int $tenantId, ?int $revision): bool
    {
        return $revision === null
            || $db->scalar('select revision from subscriptions where tenant_id = ?', [$tenantId]) === $revision;
    }

    private function bumped(Connection $db, int $tenantId): SyncOutcome
    {
        $db->update('update subscriptions set revision = revision + 1, updated_at = now() where tenant_id = ?', [$tenantId]);

        return SyncOutcome::Applied;
    }

    private function writeState(Connection $db, int $tenantId, SubscriptionState $state, ?string $graceEndsAt): void
    {
        $db->table('tenants')->where('id', $tenantId)->update([
            'subscription_state' => $state->value,
            'grace_ends_at' => $graceEndsAt,
            'updated_at' => $db->raw('now()'),
        ]);
    }
}
