<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Application\Dto\BookingSnapshot;
use App\Application\Notification\Message;
use App\Application\Notification\MessageKey;
use App\Application\Notification\Recipient;
use App\Tenancy\Languages;
use DateTimeImmutable;

/**
 * What the business is told when a request has sat undecided for a quarter, a half or three
 * quarters of its window: that it is still waiting, when it runs out and how long that is from
 * now, and, for a recipient who can act, two fresh links to act with.
 *
 * **It is built only for a request the re-read found still waiting**, SendApprovalNag, so every
 * sentence here is the row's and true at the moment it is printed: there is no settled form of
 * this message, because a nag about a request somebody has answered is not a late announcement
 * of anything, it is noise, and the job sends nothing instead.
 *
 * **The links are this recipient's own**, minted per user for the reason the approval request
 * gives, and minted afresh at each nag rather than copied from the request: a link is a
 * credential built where it is sent, and this message is sent three times. A recipient with
 * nobody to act as, a staff row's own address, gets the nag without links and is told what is
 * true of that copy, in the words the request prints for the same mailbox,
 * ApprovalLinks::whoCanDecideInstead: it cannot decide, and who can, from their own copy.
 *
 * **The time left is rounded to the hour and worded as about**, because the queue fires a nag
 * when a worker gets to it and not to the second, and because the instant it is measured from
 * is the machine's clock, the Clock port, while whether there is anything left at all was the
 * database's answer a moment earlier, SendApprovalNag. The two agree in production, and a
 * rounding to the hour keeps the seconds they can differ by out of the words; a request in its
 * last half hour is given no number at all rather than a wrong one. The instant it runs out is
 * printed beside the duration, in the tenant's clock, so the reader has the deadline as well as
 * the distance to it.
 *
 * **How to reach the customer is manageBookings' to know**, on a message as on every other
 * surface; the recipient carries the answer, BusinessRecipients reads the role.
 *
 * Its words are business-mail.php's approval_nag group, read in the recipient's language: the
 * opening one whole key per copy, and when it runs out one line read as a choice by the hours
 * left, the instant ICU's in the same language; the links are minted under that language's
 * prefix, ApprovalLinks.
 */
final readonly class ApprovalNag
{
    /**
     * @param  DateTimeImmutable  $expiresAt  when the request runs out, as the row holds it and the re-read confirmed it
     * @param  DateTimeImmutable  $now  the current instant, from the Clock port, for the time left
     */
    public static function of(
        BookingSnapshot $booking,
        string $tenantName,
        TenantClock $clock,
        Recipient $to,
        ?ApprovalLinks $links,
        DateTimeImmutable $expiresAt,
        DateTimeImmutable $now,
    ): Message {
        $language = Languages::ofRecipient($to);
        $business = ['business' => $tenantName];

        $body = implode("\n\n", [
            BusinessMailLines::line($links === null ? 'business-mail.approval_nag.without_links' : 'business-mail.approval_nag.with_links', $language, $business),
            BookingLines::of($booking, $clock, $language),
            CustomerFacts::lines($booking->person(), $to->managesBookings, $language),
            BookingLines::total($booking, $language),
            BusinessMailLines::choice('business-mail.approval_nag.runs_out', self::hoursLeft($expiresAt, $now), $language, ['end' => TenantTime::instant($expiresAt, $clock, $language)]),
            $links === null
                ? ApprovalLinks::whoCanDecideInstead($language)
                : $links->asButtons($language),
            TenantTime::footerForTheBusiness($clock->zone, $language),
        ]);

        return new Message(
            MessageKey::ApprovalNag,
            $to,
            BusinessMailLines::line('business-mail.approval_nag.subject', $language, $business),
            $body,
            $booking->uuid,
            language: $language->value,
        );
    }

    /**
     * How far off the expiry is, to the nearest hour: whole hours from thirty minutes past the
     * previous one, so a nag fired a few minutes late by the queue says the number it was timed
     * to say. Under thirty minutes, which includes an expiry the clocks put a hair behind now,
     * is none, which the line words without a number.
     */
    private static function hoursLeft(DateTimeImmutable $expiresAt, DateTimeImmutable $now): int
    {
        $seconds = $expiresAt->getTimestamp() - $now->getTimestamp();

        return $seconds < 1800 ? 0 : intdiv($seconds + 1800, 3600);
    }
}
