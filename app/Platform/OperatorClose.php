<?php

declare(strict_types=1);

namespace App\Platform;

use App\Notifications\AccountClosing;
use App\Tenancy\Tenant;
use App\Tenancy\TenantStatus;
use DateTimeImmutable;
use Illuminate\Database\Connection;

/**
 * The operator closing an account from the console, as the Closer AccountClose writes the close
 * for: one close by one operator with the reason the owners are sent, built for that close and
 * asked after it how many owners it told.
 *
 * It closes an active or a suspended row, the writer clearing the suspension in its one write, so
 * a closing supersedes a pause. A row still provisioning is refused with the console's sentence,
 * and a closed one with its own; a closing row never reaches this closer, the writer refusing it
 * as already closing first, and a closed row neither, since its kept closing_at is refused the same
 * way and no slug reaches it. It records the platform audit row tenant_closed, its payload the
 * reason, on the writer's connection inside its transaction, so the close and its record commit or
 * roll back together. It tells the owners with the closing email's console opening, the reason a
 * paragraph of its own as the suspension's notice gives it, and counts them, so the console's flash
 * promises no mail where nobody was sent one.
 */
final class OperatorClose implements Closer
{
    private int $told = 0;

    /**
     * @param  string  $reason  the operator's words, already checked and trimmed at the boundary
     */
    public function __construct(
        private readonly AccountClosing $closing,
        private readonly Operator $operator,
        private readonly string $reason,
    ) {}

    public function refusal(LockedAccount $account): ?CloseRefused
    {
        return match ($account->status) {
            TenantStatus::Active, TenantStatus::Suspended => null,
            TenantStatus::Provisioning => CloseRefused::provisioning(),
            TenantStatus::Closing => CloseRefused::alreadyClosing(),
            TenantStatus::Closed => CloseRefused::closed(),
        };
    }

    public function record(Connection $db, Tenant $tenant): void
    {
        PlatformAudit::record($db, $this->operator->id, new PlatformAuditEntry(PlatformAuditAction::TenantClosed, [PlatformAuditKey::Reason->value => $this->reason]), $tenant->id);
    }

    public function tell(AccountOwners $account, DateTimeImmutable $closedAt, bool $free): void
    {
        $this->closing->consoleClose($account, $closedAt, $free, $this->reason);
        $this->told = count($account->owners);
    }

    /**
     * How many owner users the close told, nought before it has told them and for a tenant with
     * none.
     */
    public function told(): int
    {
        return $this->told;
    }
}
