<?php

declare(strict_types=1);

namespace App\Platform;

use App\Application\Clock;
use App\Billing\SubscriptionState;
use App\Notifications\TenantReopened;
use App\Tenancy\Tenant;
use App\Tenancy\TenantStatus;
use DateTimeZone;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use stdClass;

/**
 * The console's reopen: the operator keeps a closing account in the free shape open inside its
 * retention window, the road a free tenant's owners are pointed to, since their Billing screen
 * offers no plan to choose.
 *
 * One transaction on the central connection takes the tenant row for update beside its
 * subscriptions row and reads both again, so a press on a page the drop, the owner or the
 * checkout's completion moved under decides on the row as it stands. A row not closing is refused,
 * and so is a closing row outside the free shape that TenantPlan::free names, the state active with
 * no subscriptions row and no trial end: a subscribed, a trial or a lapsed trial's closing row has
 * a plan to choose, and its way back is the owner's checkout on the Billing screen, whose
 * completion reopens it; a lapsed trial written active here would be closed again by the next
 * hourly lapse besides. Otherwise one write sets the status active and clears closing_at, the shape
 * the checkout's completion writes, and the platform audit row tenant_reopened is written on the
 * same connection with an empty payload, so the write and its record commit or roll back together.
 * The owner users are read in that transaction, with the row's language, and mailed once it has
 * committed, the kind reopened; the seam never throws, so a transport that fails leaves the reopen
 * standing. A refusal writes nothing and sends nothing.
 *
 * Nothing touches billing: the free shape has no subscription to start or cancel.
 */
final readonly class AccountReopen
{
    public const string NOT_CLOSING = 'This account is not closing, so there is nothing to reopen.';

    /** A closing row outside the free shape, whose way back is its owner's checkout. */
    public const string BILLING = 'Only the owner can keep this account, by choosing a plan on the Billing screen.';

    /** How the reopen names itself when a row it reads cannot be read. */
    private const string READER = 'The reopen';

    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
        private TenantReopened $reopened,
    ) {}

    /**
     * Reopens a closing tenant in the free shape and mails its owners that it stays open. Answers
     * how many owner users were mailed, nought for a tenant with none, so the console's flash
     * promises no mail nobody was sent.
     *
     * @throws ReopenRefused where the row is not closing or not in the free shape
     */
    public function reopen(Tenant $tenant, Operator $operator): int
    {
        $at = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));

        $account = $this->db->connection()->transaction(function (Connection $db) use ($tenant, $operator): AccountOwners {
            $row = $db->selectOne(
                'select t.slug, t.name, t.timezone, t.status, t.subscription_state, t.trial_ends_at, s.tenant_id as subscribed
                 from tenants t left join subscriptions s on s.tenant_id = t.id
                 where t.id = ? for update of t',
                [$tenant->id],
            );

            if (! $row instanceof stdClass) {
                throw CentralRow::gone($tenant);
            }

            if (TenantStatus::from(CentralRow::text($row, 'status', self::READER)) !== TenantStatus::Closing) {
                throw ReopenRefused::because(self::NOT_CLOSING);
            }

            $state = SubscriptionState::from(CentralRow::text($row, 'subscription_state', self::READER));

            if (! TenantPlan::free($state, $row->trial_ends_at !== null, $row->subscribed !== null)) {
                throw ReopenRefused::because(self::BILLING);
            }

            $db->update(
                'update tenants set status = ?, closing_at = null, updated_at = now() where id = ?',
                [TenantStatus::Active->value, $tenant->id],
            );
            PlatformAudit::record($db, $operator->id, new PlatformAuditEntry(PlatformAuditAction::TenantReopened), $tenant->id);

            return AccountOwners::read($db, $tenant, $row, self::READER);
        });

        $this->reopened->send($account, $at);

        return count($account->owners);
    }
}
