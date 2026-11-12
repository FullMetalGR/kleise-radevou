<?php

declare(strict_types=1);

namespace App\Http;

use App\Domain\Enum\Decision;
use App\Infrastructure\Notification\BusinessDirectories;
use App\Notifications\TenantUser;
use App\Tenancy\Tenant;
use DateTimeImmutable;

/**
 * The three checks a verified signature is not: the user the link names exists and belongs to
 * this tenant, that user's role may approve, and a staff-role user has an item on their own
 * linked staff row.
 *
 * They are three and not one because they can fail for three different reasons that time can
 * bring about after a link was minted: a user can be deleted or moved, a role can be changed,
 * and a booking's staff can be reassigned. A signature proves who minted a link and cannot
 * prove any of that, so the checks run on every request and never at mint time alone.
 *
 * **Every failure is one refusal.** The caller answers all of them the same way and says
 * nothing about which it was, for the reason the manage token guard levels its own four: a
 * refusal that varied would tell whoever is holding a stale link which of the facts changed,
 * and would answer questions about a tenant's people to somebody who has already been refused.
 *
 * The rows come from the recipient rule's own directory, which is the one place a tenant's
 * users and the staff on a booking are read for this. Reading them here through the same port
 * is what keeps "who may decide" and "who is told there is something to decide" one answer:
 * the recipient rule names a staff user only where the booking has an item on their row, and
 * this refuses one where it has not.
 */
final readonly class DecisionLinkGuard
{
    public function __construct(
        private BusinessDirectories $directories,
        private ApproverScope $scope,
    ) {}

    /**
     * The decision this link carries where all three checks pass, and null where any of them
     * does not.
     */
    public function verified(Tenant $tenant, string $uuid, Decision $decision, int $userId, DateTimeImmutable $expiresAt): ?SignedDecision
    {
        // The directory reads the users of this tenant and of no other, so a user of another
        // tenant is absent here rather than found and then refused: one answer covers both a
        // user who never existed and a user who is somebody else's.
        $user = TenantUser::among($this->directories->for($tenant)->users(), $userId);

        // The role and the approver scope, asked of the one class the admin's buttons ask: a
        // staff user decides the bookings on their own row and no others. A booking that does
        // not exist has no staff rows either, so a link for one is refused before anything reads
        // it, which is the same answer as a booking they are not on.
        if ($user === null || ! $this->scope->admits($tenant, $uuid, $user->role, $user->id)) {
            return null;
        }

        return new SignedDecision($uuid, $decision, $user->id, $user->role, $expiresAt);
    }
}
