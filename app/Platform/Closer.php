<?php

declare(strict_types=1);

namespace App\Platform;

use App\Tenancy\Tenant;
use DateTimeImmutable;
use Illuminate\Database\Connection;

/**
 * Who closes an account: what AccountClose, the one writer of a close, asks of the closer and
 * nothing more. The writer keeps the rest: the row's lock, the refusal of a row already closing,
 * the one write of the closing shape with any suspension cleared in it, the cancel at the provider
 * and the landing of its answer, and that nothing after the commit undoes the close or keeps it
 * from the owners.
 *
 * The owner from the Settings Danger zone, OwnerClose, closes an active row alone, records nothing
 * and mails the closing email's own-close opening. The operator from the console, OperatorClose,
 * closes an active or a suspended row with a reason, records the platform audit row tenant_closed
 * carrying it and mails the owners the reason. The retention sweep, LapsedClose, closes an active
 * row left canceled with no trial end thirty days after its last period's end, records nothing and
 * mails the lapsed subscriber's opening.
 */
interface Closer
{
    /**
     * The refusal of the row as the writer read it under its lock, or null where this closer
     * closes it. A row already closing, or carrying a closing_at, is refused by the writer before
     * this is asked.
     */
    public function refusal(LockedAccount $account): ?CloseRefused;

    /**
     * Writes this closer's record of the close on the writer's connection, inside its transaction
     * and after the close's write, so the two commit or roll back together.
     */
    public function record(Connection $db, Tenant $tenant): void;

    /**
     * Tells the owners the account is closing, once the close has committed and the cancel's
     * answer has landed; $free is the free shape, whose way back is support rather than a plan.
     * Through the platform's seam, which never throws.
     */
    public function tell(AccountOwners $account, DateTimeImmutable $closedAt, bool $free): void;
}
