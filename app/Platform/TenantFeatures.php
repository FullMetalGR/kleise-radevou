<?php

declare(strict_types=1);

namespace App\Platform;

use App\Billing\Entitlements;
use App\Billing\PlanChange;
use App\Billing\SubscriptionState;
use App\Infrastructure\Db\StaffRecords;
use App\Infrastructure\Db\TextArray;
use App\Site\SiteLanguage;
use App\Tenancy\Tenant;
use App\Tenancy\TenantStatus;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use stdClass;
use UnexpectedValueException;

/**
 * The console's Change features: the one write of a tenant's entitlement set outside the
 * subscription sync and the row's creation.
 *
 * One transaction on the central connection. The tenant row is taken for update first and its shape
 * read again, then its subscriptions row is looked for, so two presses at once, or a checkout
 * landing a subscription meanwhile, decide on the row as it stands. The set changes on a tenant in
 * the free shape alone, TenantPlan::free, the one predicate of the shape the Billing screen reads
 * too: the state active, no subscriptions row and no trial end. Any other row's plan belongs to
 * billing, and the console never overrides it, a trial's among them, since the trial's plan is
 * chosen at billing's checkout; a provisioning row has no plan yet and is refused alike. A set
 * without staff while more than one staff member is active, the implicit one counted, is refused
 * with the plan change's own count sentence through the counter and the threshold the plan change
 * reads, never absorbed as a silent floor.
 *
 * Otherwise one statement writes the set and clears narrowed, since a set the operator chose takes
 * nothing away behind anybody's back, and the platform audit row features_changed carries the set
 * after, both in the same transaction. Nothing touches the subscription columns, billing or a
 * network.
 */
final readonly class TenantFeatures
{
    public function __construct(
        private DatabaseManager $db,
        private StaffRecords $staff,
    ) {}

    /**
     * @throws FeaturesRefused where the row is not in the free shape or the roster refuses the set
     */
    public function change(Tenant $tenant, Operator $operator, Entitlements $features): void
    {
        $this->connection()->transaction(function (Connection $db) use ($tenant, $operator, $features): void {
            $row = $db->selectOne('select status, subscription_state, trial_ends_at is not null as trial_end from tenants where id = ? for update', [$tenant->id]);

            if (! $row instanceof stdClass || ! is_string($row->status ?? null) || ! is_string($row->subscription_state ?? null) || ! is_bool($row->trial_end ?? null)) {
                throw new UnexpectedValueException(sprintf('The features change read no tenant row of tenant "%s" it can read.', $tenant->slug));
            }

            $subscribed = $db->table('subscriptions')->where('tenant_id', $tenant->id)->exists();

            if (TenantStatus::from($row->status) === TenantStatus::Provisioning || ! TenantPlan::free(SubscriptionState::from($row->subscription_state), $row->trial_end, $subscribed)) {
                throw FeaturesRefused::billing();
            }

            $refused = PlanChange::featureSetRefusal($features, $this->staff->activeCount($tenant));

            if ($refused !== null) {
                // The console's copy stays English.
                throw FeaturesRefused::because($refused->sentence(SiteLanguage::English));
            }

            $db->update(
                'update tenants set entitlements = ?::text[], narrowed = ?::text[], updated_at = now() where id = ?',
                [TextArray::encode($features->toSlugs()), TextArray::encode([]), $tenant->id],
            );
            PlatformAudit::record($db, $operator->id, new PlatformAuditEntry(PlatformAuditAction::FeaturesChanged, [PlatformAuditKey::Features->value => $features->toSlugs()]), $tenant->id);
        });
    }

    private function connection(): Connection
    {
        return $this->db->connection();
    }
}
