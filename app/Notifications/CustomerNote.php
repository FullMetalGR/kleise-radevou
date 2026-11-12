<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Site\SiteLanguage;

/**
 * The customer's note as the business's two messages print it: the new-booking notice and the
 * approval request, under its heading, after who the customer is and before the total, and
 * nothing at all where they wrote none. A label over nothing reads as a message that lost
 * something on the way, which is CustomerFacts' rule for a blank phone too.
 *
 * It is the business's alone. The note is the customer's words to the business and is never
 * echoed in the customer's own messages: no customer template takes it, and the job of each
 * business message reads it through BookingNotes at the send. Every recipient of the two messages
 * reads it, as every viewer of the dialog does, since the person who will do the work is the one
 * it is written for and it is not a way to reach the customer, which is the one thing kept from a
 * reader without manageBookings.
 *
 * The heading is business-mail.php's, read in the recipient's language; the note itself is the
 * customer's words as typed, in whatever language they wrote it.
 */
final readonly class CustomerNote
{
    public const string HEADING = 'business-mail.note.heading';

    /**
     * The block to follow the customer's facts: a blank line, the heading and the note, or the
     * empty string where there is no note.
     */
    public static function after(?string $note, SiteLanguage $language): string
    {
        return $note === null ? '' : "\n\n".BusinessMailLines::line(self::HEADING, $language)."\n".$note;
    }
}
