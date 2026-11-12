<?php

declare(strict_types=1);

namespace App\Platform;

use App\Notifications\AccountClosing;
use App\Tenancy\Tenant;
use App\Tenancy\TenantStatus;
use DateTimeImmutable;
use Illuminate\Database\Connection;

/**
 * The owner closing the account from the Settings Danger zone, as the Closer AccountClose writes
 * the close for.
 *
 * It closes an active row alone. A suspended host answers the resolver's static page before the
 * admin is reached and a rebuilding demo answers 404, so a suspended or a provisioning row meets
 * this closer only where the console moved the row after the page was read, under the lock, and is
 * refused as not open. It records nothing: closing_at and the mail are the record, the tenant's
 * trail being the bookings' and the platform's the operators'. It tells the owners with the
 * closing email's own-close opening.
 */
final readonly class OwnerClose implements Closer
{
    public function __construct(private AccountClosing $closing) {}

    public function refusal(LockedAccount $account): ?CloseRefused
    {
        return $account->status === TenantStatus::Active ? null : CloseRefused::notOpen();
    }

    public function record(Connection $db, Tenant $tenant): void {}

    public function tell(AccountOwners $account, DateTimeImmutable $closedAt, bool $free): void
    {
        $this->closing->ownClose($account, $closedAt, $free);
    }
}
