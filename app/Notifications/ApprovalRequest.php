<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Application\Dto\BookingSnapshot;
use App\Application\Notification\Message;
use App\Application\Notification\MessageKey;
use App\Application\Notification\Recipient;
use App\Site\SiteLanguage;
use App\Tenancy\Languages;
use InvalidArgumentException;

/**
 * What the business is asked when a customer has requested something a human has to say yes to.
 *
 * It is the other half of the acknowledgement: the customer is told their request is with the
 * business, and this is what reaches the business. Without it nobody would know there is
 * anything to decide until the window closed and the clock decided for them.
 *
 * **The links are this recipient's own.** A decision has to land as somebody, so a link is
 * minted per user, and two approvers therefore read two different messages about one booking.
 * A recipient with nobody to act as - a staff row's own address, held by no user among the
 * booking's recipients - gets the request without links, because there is no user for a link
 * to decide as. They are told what is true of their own copy instead of being shown two buttons
 * that would refuse them: that it cannot decide, and who can from theirs, an owner or a
 * manager, and a user linked to the same staff row, which is who the recipient rule gives a
 * pair to, BusinessRecipients. The words are ApprovalLinks::whoCanDecideInstead, shared with
 * the nag, so the two messages the same mailbox reads about one request agree.
 *
 * **A message with no links has two reasons and two sets of words.** The other is a request
 * somebody answered before the queue reached this: the announcement still goes out, because it
 * says a request was made, but the expiry a link carries is the hold's own and a settled
 * booking has none to sign. Telling that reader who can approve or reject it would be false of
 * the booking they are reading about, so the case the words are printed for is carried in
 * rather than guessed from the absence of a link, and the one combination that would print
 * links for a settled request is refused at the door, the way ApprovalWindow refuses the same
 * shape: a link is a credential minted for a hold, and a settled booking has none for it to
 * have been minted for.
 *
 * **A link is a credential**, under the same rule the magic link lives under: it is built where
 * it is sent and goes nowhere else, and it is in the body of exactly one message, to exactly
 * one mailbox.
 *
 * **How to reach the customer is manageBookings' to know**, on a message as on every other
 * surface. The recipient carries the answer; the role it was read from is BusinessRecipients'.
 * The customer's note follows who they are, under its heading, where they wrote one,
 * CustomerNote.
 *
 * Its words are business-mail.php's approval_request group, read in the recipient's language:
 * each of the three cases its own subject and opening, whole keys, so no clause is spliced into
 * a sentence; the links are minted under the same language's prefix, ApprovalLinks.
 */
final readonly class ApprovalRequest
{
    /**
     * @param  bool  $stillWaiting  whether there is still a decision to make, which is the
     *                              database's answer and the job's to ask, never a comparison
     *                              against a clock of ours
     * @param  string|null  $note  the customer's note, read by the job through BookingNotes, null where none
     */
    public static function of(
        BookingSnapshot $booking,
        string $tenantName,
        TenantClock $clock,
        Recipient $to,
        ?ApprovalLinks $links,
        bool $stillWaiting,
        ?string $note,
    ): Message {
        if ($links !== null && ! $stillWaiting) {
            throw new InvalidArgumentException('A request that has been settled carries no links: a link decides as the hold it was minted for, and a settled booking has none.');
        }

        $language = Languages::ofRecipient($to);
        $business = ['business' => $tenantName];
        [$subject, $opening] = self::form($links, $stillWaiting);

        $body = implode("\n\n", [
            BusinessMailLines::line($opening, $language, $business),
            BookingLines::of($booking, $clock, $language),
            CustomerFacts::lines($booking->person(), $to->managesBookings, $language).CustomerNote::after($note, $language),
            BookingLines::total($booking, $language),
            self::whatCanBeDoneAboutIt($links, $stillWaiting, $language),
            TenantTime::footerForTheBusiness($clock->zone, $language),
        ]);

        return new Message(
            MessageKey::ApprovalRequest,
            $to,
            BusinessMailLines::line($subject, $language, $business),
            $body,
            $booking->uuid,
            language: $language->value,
        );
    }

    /**
     * The case whose words are true of this copy: still waiting, for a recipient who holds the
     * links to decide with or one who holds none, or settled before the queue reached it; its
     * subject and its opening, two keys of business-mail.php's approval_request group.
     *
     * @return array{string, string}
     */
    private static function form(?ApprovalLinks $links, bool $stillWaiting): array
    {
        return match (true) {
            ! $stillWaiting => [
                'business-mail.approval_request.settled.subject',
                'business-mail.approval_request.settled.opening',
            ],
            $links === null => [
                'business-mail.approval_request.waiting.subject',
                'business-mail.approval_request.waiting.without_links',
            ],
            default => [
                'business-mail.approval_request.waiting.subject',
                'business-mail.approval_request.waiting.with_links',
            ],
        };
    }

    /**
     * The last thing the message says, which is the one sentence that has to be true of the
     * case it is printed for: press one of these, or this copy cannot and here is who can, or
     * nothing at all because it has been answered already.
     */
    private static function whatCanBeDoneAboutIt(?ApprovalLinks $links, bool $stillWaiting, SiteLanguage $language): string
    {
        if (! $stillWaiting) {
            return BusinessMailLines::line('business-mail.approval_request.settled.nothing_left', $language);
        }

        return $links === null
            ? ApprovalLinks::whoCanDecideInstead($language)
            : $links->asButtons($language);
    }
}
