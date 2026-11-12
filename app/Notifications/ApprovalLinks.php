<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Application\Notification\Recipient;
use App\Domain\Enum\Decision;
use App\Site\SiteLanguage;
use App\Tenancy\DecisionLinks;
use App\Tenancy\Languages;
use App\Tenancy\Tenant;
use DateTimeImmutable;

/**
 * The two links one person can decide a request with: approve, and reject.
 *
 * They travel as a pair because they are minted as one, for one user, from one booking and one
 * window, and because a message that carried one of them alone would be a message offering half
 * a decision. A recipient with nobody to act as - a staff row's own mailbox - gets no pair at
 * all rather than an empty one.
 *
 * The pair is minted from one place, for(), for the two jobs that send it, SendApprovalRequest
 * and SendApprovalNag, and written out from one place, asButtons(), for the two messages that
 * carry it, with the sentence for a mailbox that gets no pair beside it, whoCanDecideInstead():
 * the request and the nags about it read like one message because they are spelled from one
 * place.
 *
 * **A pair is minted under its recipient's language**: the prefix of the language the message
 * is written in, always explicit, the tenant default's included, so the page opens in the
 * language the mail was written in whatever the user or the tenant changes later. The signature
 * covers the path, so a link verifies under that prefix alone, and the page posts back to a
 * link minted again under it. The captions and the sentence are business-mail.php's links
 * group, read in the same language.
 */
final readonly class ApprovalLinks
{
    public function __construct(
        public string $approve,
        public string $reject,
    ) {}

    /**
     * The pair this recipient can decide with, minted afresh under the prefix of the language
     * they read, or none for a mailbox with nobody behind it, because a decision lands as a user
     * and a staff row's own address is not one. The expiry is the hold's own: a link may not
     * outlive the request it decides.
     */
    public static function for(DecisionLinks $links, Tenant $tenant, string $uuid, Recipient $to, DateTimeImmutable $window): ?self
    {
        if ($to->userId === null) {
            return null;
        }

        $language = Languages::ofRecipient($to);

        return new self(
            $links->link($tenant, $uuid, Decision::Approve, $to->userId, $window, $language),
            $links->link($tenant, $uuid, Decision::Reject, $to->userId, $window, $language),
        );
    }

    /**
     * The two links, each under the caption that says what pressing it does. Each opens a page
     * that asks once more before anything happens, so what is written here is what the link
     * leads to rather than what it does on its own.
     */
    public function asButtons(SiteLanguage $language): string
    {
        return sprintf(
            <<<'TEXT'
                %s

                    %s

                %s

                    %s
                TEXT,
            BusinessMailLines::line('business-mail.links.approve', $language),
            $this->approve,
            BusinessMailLines::line('business-mail.links.reject', $language),
            $this->reject,
        );
    }

    /**
     * What is printed where this recipient gets no pair: a staff row's own mailbox, an address
     * that no user among the booking's recipients holds, so there is no user for a link to
     * decide as. Whose it is the row does not say. Where the row is linked to nobody, it
     * belongs to a person who holds hours and never signs in. Where the row is linked to a user
     * at another address, that user is a recipient in their own right, BusinessRecipients'
     * first or second limb, gets a pair of their own at their address and decides from it
     * without signing in, while the row's address gets this copy from the third limb. The
     * sentence is true of that copy either way and claims nothing of any other: this copy
     * cannot decide; an owner or a manager can from their own copy; and so can a user linked to
     * the same staff row, from theirs, because the recipient rule gives every user a pair of
     * their own, and a user on the row the booking is on is one the decision page admits. Not
     * "a login": a linked recipient decides from a signed link without signing in. It is one
     * sentence for the request and the nags because the same mailbox reads both.
     */
    public static function whoCanDecideInstead(SiteLanguage $language): string
    {
        return BusinessMailLines::line('business-mail.links.none', $language);
    }
}
