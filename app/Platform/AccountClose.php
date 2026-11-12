<?php

declare(strict_types=1);

namespace App\Platform;

use App\Application\Clock;
use App\Billing\BillingNotConfigured;
use App\Billing\BillingProvider;
use App\Billing\BillingProviderFailed;
use App\Billing\ProviderStatus;
use App\Billing\SubscriptionState;
use App\Billing\SubscriptionSync;
use App\Infrastructure\Db\SqlState;
use App\Infrastructure\Db\SqlText;
use App\Tenancy\Tenant;
use App\Tenancy\TenantStatus;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Psr\Log\LoggerInterface;
use stdClass;
use Throwable;

/**
 * The one writer of a close: the shape the hourly lapse writes, for one tenant, the account then
 * kept for the retention window and dropped after it by the sweep's line. The owner's close from
 * the Settings Danger zone, the console's and the retention sweep's close of a lapsed subscriber
 * all write it here, each naming itself by the Closer it hands over, which decides the rows it
 * closes, writes its own record beside the close and tells the owners in its own words.
 *
 * One transaction on the central connection takes the tenant row for update and reads it again, so
 * two presses at once, or a press on a page the hourly close or the console moved under, decide on
 * the row as it stands. A row already closing, or carrying a closing_at, is refused as already
 * closing, and a row the closer does not close with the closer's refusal, each writing nothing and
 * sending nothing: the closer is handed the row as read under the lock, its status, its
 * subscription state, its trial end, its last period's end and its last update (LockedAccount), so
 * the retention sweep's close of a lapsed subscriber decides on the row as it stands too.
 * Otherwise one write sets closing_at to the Clock port's now, as UTC text with its offset, and
 * the status closing, and clears the suspension's two columns, so a suspended row the console
 * closes keeps no suspension behind it; on an active row they are null already. The closer's
 * record follows on the same connection, and the same transaction reads the subscriptions row
 * beside the tenant row, and the owner users.
 *
 * After the commit, a subscription the row names is cancelled at the provider at once wherever the
 * tenant row's state is anything but canceled, past due and incomplete among them, since a
 * provider still collecting on either would charge the closed account; the free shape and a row
 * naming no subscription, and an ended one, cancel nothing. The cancel's answer is landed through
 * the sync, which writes canceled and names no closing, so the Billing screen offers the way
 * back's checkout at once and the provider's own event that follows is the landed no-op. Then the
 * closer tells the owners.
 *
 * Once the close has committed, nothing after it changes what the owners see or get: the row stays
 * closing, the owners are told, and the caller answers as for a close that landed. A provider that
 * refuses the cancel or cannot be reached is logged once at error with the provider's sentence,
 * and so is a deploy with no billing key, BillingNotConfigured: the Billing screen lets that raise
 * as a deploy fault because nothing has been written there yet, while here it would answer a 500
 * for a close that stands and leave its owners unmailed. A landing that throws after the provider
 * answered, the sync's own locked transaction failing, is logged once at error by the exception's
 * class and its SQLSTATE alone, since a database's message carries the statement's values, and the
 * provider's own event of the cancel lands the answer in its turn. The log names the tenant's slug
 * and the provider's subscription id, which are references and no secret.
 */
final readonly class AccountClose
{
    public const string CANCEL_LOGGED = 'The billing provider could not cancel the subscription of an account being closed; the close stands and the subscription is to be cancelled at the provider by hand';

    public const string LANDING_LOGGED = 'The billing provider cancelled the subscription of an account being closed and its answer could not be written; the close stands and the provider\'s own event lands the answer';

    /** How the close names itself when a row it reads cannot be read. */
    private const string READER = 'The close';

    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
        private BillingProvider $provider,
        private SubscriptionSync $sync,
        private LoggerInterface $log,
    ) {}

    /**
     * Closes the tenant for the closer and answers the instant written as closing_at, in UTC.
     *
     * @throws CloseRefused where the row is already closing or the closer does not close its status
     */
    public function close(Tenant $tenant, Closer $closer): DateTimeImmutable
    {
        $at = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));

        /** @var array{owners: AccountOwners, cancel: string|null, free: bool} $closed */
        $closed = $this->db->connection()->transaction(function (Connection $db) use ($tenant, $closer, $at): array {
            $row = $db->selectOne(
                'select t.slug, t.name, t.timezone, t.status, t.closing_at, t.subscription_state, t.trial_ends_at,
                        '.SqlText::instantColumn('t.updated_at').' as updated_at,
                        s.tenant_id as subscribed, s.provider_subscription_id, s.status as provider_status,
                        '.SqlText::instantColumn('s.current_period_end').' as current_period_end
                 from tenants t left join subscriptions s on s.tenant_id = t.id
                 where t.id = ? for update of t',
                [$tenant->id],
            );

            if (! $row instanceof stdClass) {
                throw CentralRow::gone($tenant);
            }

            $status = TenantStatus::from(CentralRow::text($row, 'status', self::READER));
            $state = SubscriptionState::from(CentralRow::text($row, 'subscription_state', self::READER));
            $refusal = $status === TenantStatus::Closing || $row->closing_at !== null
                ? CloseRefused::alreadyClosing()
                : $closer->refusal(new LockedAccount(
                    $status,
                    $state,
                    $row->trial_ends_at !== null,
                    self::instantOf($row, 'current_period_end'),
                    self::instantOf($row, 'updated_at'),
                    CentralRow::optionalText($row, 'provider_status', self::READER) === ProviderStatus::IncompleteExpired->value,
                ));

            if ($refusal !== null) {
                throw $refusal;
            }

            $db->update(
                'update tenants set closing_at = ?::timestamptz, status = ?, suspended_at = null, suspension_reason = null, updated_at = now() where id = ?',
                [SqlText::instant($at), TenantStatus::Closing->value, $tenant->id],
            );
            $closer->record($db, $tenant);

            $subscription = CentralRow::optionalText($row, 'provider_subscription_id', self::READER);

            return [
                'owners' => AccountOwners::read($db, $tenant, $row, self::READER),
                'cancel' => $subscription !== null && $state !== SubscriptionState::Canceled ? $subscription : null,
                'free' => TenantPlan::free($state, $row->trial_ends_at !== null, $row->subscribed !== null),
            ];
        });

        if ($closed['cancel'] !== null) {
            $this->cancel($tenant, $closed['cancel']);
        }

        $closer->tell($closed['owners'], $at, $closed['free']);

        return $at;
    }

    /**
     * An instant column of the locked row, read in UTC, or null where the column holds none.
     */
    private static function instantOf(stdClass $row, string $column): ?DateTimeImmutable
    {
        $text = CentralRow::optionalText($row, $column, self::READER);

        return $text === null ? null : SqlText::readInstant($text);
    }

    /**
     * Cancels the subscription at the provider and lands its answer; a refusal, or a landing that
     * throws, is logged once and the close stands.
     */
    private function cancel(Tenant $tenant, string $subscriptionId): void
    {
        $named = ['tenant' => $tenant->slug, 'subscription' => $subscriptionId];

        try {
            $answer = $this->provider->cancel($subscriptionId);
        } catch (BillingProviderFailed|BillingNotConfigured $refused) {
            $this->log->error(self::CANCEL_LOGGED, [...$named, 'message' => $refused->getMessage()]);

            return;
        }

        try {
            $this->sync->apply($answer);
        } catch (Throwable $failure) {
            $this->log->error(self::LANDING_LOGGED, [...$named, 'exception' => $failure::class, 'sqlstate' => SqlState::codeOf($failure)]);
        }
    }
}
