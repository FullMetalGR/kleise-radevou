<?php

declare(strict_types=1);

namespace App\Domain\Enum;

/**
 * What happens to an approval request the owner never answered: the slot is released, or the
 * request proceeds as though the owner said yes. Spelled as both the
 * services.on_approval_timeout and the bookings.on_approval_timeout columns store it, and
 * copied from the services onto the booking at hold time so that a later service edit cannot
 * restate what the customer was promised. The blocking predicate reads the copy: an
 * auto-approving request blocks past its expiry.
 */
enum ApprovalTimeout: string
{
    case Expire = 'expire';
    case AutoApprove = 'auto_approve';

    /**
     * The policy of a whole chain from the policies of the segments that require approval:
     * auto_approve only when every one of them says so. A chain requiring no approval, an
     * empty list here, is expire, since there is nothing for the clock to grant.
     *
     * @param  iterable<self>  $requiringApproval
     */
    public static function acrossChain(iterable $requiringApproval): self
    {
        $any = false;

        foreach ($requiringApproval as $policy) {
            if ($policy === self::Expire) {
                return self::Expire;
            }

            $any = true;
        }

        return $any ? self::AutoApprove : self::Expire;
    }
}
