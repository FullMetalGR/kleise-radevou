<?php

declare(strict_types=1);

namespace App\Platform;

use App\Application\Clock;
use App\Infrastructure\Db\SqlText;
use App\Notifications\TenantResumed;
use App\Notifications\TenantSuspended;
use App\Tenancy\Tenant;
use App\Tenancy\TenantStatus;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use stdClass;

/**
 * The console's suspend and resume: the operator pauses a business with a reason its owners read,
 * and ends the pause.
 *
 * Each is one transaction on the central connection: the tenant row is taken for update and its
 * status read again, so two operators pressing at once, or a press on a page the hourly close has
 * moved under, decide on the row as it stands; the status and the two suspension columns are
 * written, the instant from the Clock port as UTC text with its offset; and the platform audit row
 * is written on the same connection, so the write and its record commit or roll back together. The
 * owner users are read in that transaction, with the row's language, and mailed once it has
 * committed, through the two classes that hand the platform's seam a message per owner in that
 * language; the seam never throws, so a transport that fails leaves the write standing.
 *
 * Suspend takes an active row alone: a closing row is refused with one sentence, a row already
 * suspended or still provisioning with a sentence of its own. Resume takes a suspended row alone. A
 * refusal writes nothing and sends nothing.
 *
 * Neither touches billing: the subscription columns, the subscriptions row and the hourly commands
 * are left as they stand, so a suspended trial still lapses to canceled, and the close, which reads
 * active rows alone, waits for the resume.
 */
final readonly class Suspension
{
    public const string CLOSING = 'A closing account cannot be suspended.';

    public const string ALREADY_SUSPENDED = 'This account is already suspended.';

    public const string CLOSED = 'A closed account cannot be suspended.';

    public const string PROVISIONING = 'An account still being set up cannot be suspended.';

    public const string NOT_SUSPENDED = 'This account is not suspended, so there is nothing to resume.';

    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
        private TenantSuspended $suspended,
        private TenantResumed $resumed,
    ) {}

    /** How the suspension names itself when a row it reads cannot be read. */
    private const string READER = 'The suspension';

    /**
     * Suspends an active tenant with the operator's reason and mails its owners the reason.
     * Answers how many owner users were mailed, nought for a tenant with none, so the console's
     * flash promises no mail nobody was sent.
     *
     * @param  string  $reason  the operator's words, already checked and trimmed at the boundary
     *
     * @throws SuspensionRefused where the row is not active
     */
    public function suspend(Tenant $tenant, Operator $operator, string $reason): int
    {
        $at = $this->now();

        $account = $this->connection()->transaction(function (Connection $db) use ($tenant, $operator, $reason, $at): AccountOwners {
            $row = self::locked($db, $tenant);
            $refusal = match (TenantStatus::from(CentralRow::text($row, 'status', self::READER))) {
                TenantStatus::Active => null,
                TenantStatus::Closing => self::CLOSING,
                TenantStatus::Suspended => self::ALREADY_SUSPENDED,
                TenantStatus::Provisioning => self::PROVISIONING,
                TenantStatus::Closed => self::CLOSED,
            };

            if ($refusal !== null) {
                throw SuspensionRefused::because($refusal);
            }

            $db->update(
                'update tenants set status = ?, suspended_at = ?::timestamptz, suspension_reason = ?, updated_at = now() where id = ?',
                [TenantStatus::Suspended->value, SqlText::instant($at), $reason, $tenant->id],
            );
            PlatformAudit::record($db, $operator->id, new PlatformAuditEntry(PlatformAuditAction::TenantSuspended, [PlatformAuditKey::Reason->value => $reason]), $tenant->id);

            return AccountOwners::read($db, $tenant, $row, self::READER);
        });

        $this->suspended->send($account, $at, $reason);

        return count($account->owners);
    }

    /**
     * Ends a suspension, clearing its instant and reason, and mails the owners that it has ended.
     * Answers how many owner users were mailed, nought for a tenant with none.
     *
     * @throws SuspensionRefused where the row is not suspended
     */
    public function resume(Tenant $tenant, Operator $operator): int
    {
        $at = $this->now();

        $account = $this->connection()->transaction(function (Connection $db) use ($tenant, $operator): AccountOwners {
            $row = self::locked($db, $tenant);

            if (TenantStatus::from(CentralRow::text($row, 'status', self::READER)) !== TenantStatus::Suspended) {
                throw SuspensionRefused::because(self::NOT_SUSPENDED);
            }

            $db->update(
                'update tenants set status = ?, suspended_at = null, suspension_reason = null, updated_at = now() where id = ?',
                [TenantStatus::Active->value, $tenant->id],
            );
            PlatformAudit::record($db, $operator->id, new PlatformAuditEntry(PlatformAuditAction::TenantResumed), $tenant->id);

            return AccountOwners::read($db, $tenant, $row, self::READER);
        });

        $this->resumed->send($account, $at);

        return count($account->owners);
    }

    /**
     * The tenant row as it stands, taken for update.
     */
    private static function locked(Connection $db, Tenant $tenant): stdClass
    {
        $row = $db->selectOne('select slug, name, timezone, status from tenants where id = ? for update', [$tenant->id]);

        return $row instanceof stdClass ? $row : throw CentralRow::gone($tenant);
    }

    private function now(): DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
    }

    private function connection(): Connection
    {
        return $this->db->connection();
    }
}
