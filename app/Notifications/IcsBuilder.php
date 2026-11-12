<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Application\Dto\BookingItemSnapshot;
use App\Application\Dto\BookingSnapshot;
use App\Application\Notification\Attachment;
use App\Tenancy\Languages;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The calendar file that travels with a booking's messages.
 *
 * **Its reader is other people's software**, which is what makes it different from every other
 * template. A body a person reads forgives a stray character; a calendar client refuses the file
 * for one, so the line endings, the line length and the escaping here are the format's rules
 * rather than this build's taste, and the unit tests assert them as rules.
 *
 * **One event for the whole chain.** A customer keeps one appointment in their diary; the gaps
 * inside a chain are the salon's arrangement of it and not something to put on somebody's
 * calendar as a second entry. So the event runs from the first segment's start to the last
 * one's end, and the services are named inside it.
 *
 * **Identified by the booking's uuid, and revised rather than re-added.** A client that has
 * imported this entry once must update it when the next one arrives, which is what the identifier
 * and the revision number are for: same identifier, higher revision, no earlier stamp. That is
 * why the revision is an argument rather than a constant. A confirmation is the first file for a
 * booking, at zero; a move re-sends the same entry at a higher number the booking's rows give,
 * BookingReader::revision.
 *
 * **Instants are UTC, here and only here.** Every other message renders the tenant's clock,
 * because a customer is told the hour the salon means. A calendar file is read by a machine that
 * will render it in whatever clock its owner keeps, so writing a local time into it would be
 * handing that machine the wrong instant to convert.
 *
 * **No magic link and no token anywhere in it.** Calendar entries are shared, forwarded and
 * synchronised onto other people's devices; a credential that let anyone cancel the booking would
 * travel with them.
 *
 * **Its summary arrives spelled.** The event's name is a sentence, the booking's services at the
 * business, and a sentence is a line of customer-mail.php in the booking's language, which the
 * message the file travels with reads and hands in, CustomerMailLines::summary; so this class
 * reads no translator for it. Its description names the items in the booking's language too, the
 * language the snapshot carries, so the file reads as the message beside it does whichever
 * template hands it over.
 *
 * It takes primitives and value objects and returns a string, with no framework in it but the one
 * line ItemName reads for an item with its staff member, through the translator the container
 * holds, so it runs in the Unit suite with no database and no tenant, a translator over the lang
 * directory handed in.
 */
final readonly class IcsBuilder
{
    /**
     * What the file arrives as. One name for every booking: a mailbox shows it beside the
     * message it came with, and a name carrying the customer's booking would put an identifier
     * in a place people forward without thinking.
     */
    public const string NAME = 'booking.ics';

    public const string CONTENT_TYPE = 'text/calendar';

    /**
     * The format's line length, in octets and not characters, excluding the line break.
     */
    private const int LINE_OCTETS = 75;

    /**
     * The file, ready to attach.
     *
     * @param  string  $summary  the event's name as the message spells it, CustomerMailLines::summary
     * @param  int  $revision  which version of this booking's entry this is: zero for the first file, and the booking's own number after that, BookingReader::revision
     */
    public static function attachment(BookingSnapshot $booking, string $summary, DateTimeImmutable $stampedAt, int $revision): Attachment
    {
        return new Attachment(self::NAME, self::CONTENT_TYPE, self::file($booking, $summary, $stampedAt, $revision));
    }

    /**
     * @param  string  $summary  the event's name as the message spells it, CustomerMailLines::summary
     * @param  int  $revision  which version of this booking's entry this is: zero for the first file, and the booking's own number after that, BookingReader::revision
     */
    public static function file(BookingSnapshot $booking, string $summary, DateTimeImmutable $stampedAt, int $revision): string
    {
        // Each item named the way the message beside this file names it, ItemName's rule, in the
        // booking's language the message is written in: a customer holding the mail and the diary
        // entry open at once is reading one sentence twice and not two sentences about the same
        // hour.
        $language = Languages::ofBooking($booking->language);
        $described = array_map(fn (BookingItemSnapshot $item): string => ItemName::of($item, $language), $booking->items);

        return self::lines([
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Kleise Radevou//Booking//EN',
            'CALSCALE:GREGORIAN',
            // The file states what it is for: an entry published to whoever it was sent to,
            // rather than an invitation expecting a reply. There is no organizer and no
            // attendee in it, because answering one would be mail nothing here reads.
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:'.self::text($booking->uuid),
            'SEQUENCE:'.max(0, $revision),
            'DTSTAMP:'.self::instant($stampedAt),
            'DTSTART:'.self::instant($booking->startsAt()),
            'DTEND:'.self::instant($booking->endsAt()),
            'SUMMARY:'.self::text($summary),
            'DESCRIPTION:'.self::text(implode("\n", $described)),
            'END:VEVENT',
            'END:VCALENDAR',
        ]);
    }

    /**
     * @param  list<string>  $lines
     */
    private static function lines(array $lines): string
    {
        $file = '';

        foreach ($lines as $line) {
            $file .= self::fold($line)."\r\n";
        }

        return $file;
    }

    /**
     * An instant as the format spells one in UTC. The zone is set rather than assumed, and the
     * instant it is set on named its own zone when it was built.
     */
    private static function instant(DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
    }

    /**
     * One value, escaped the way the format escapes a text: the backslash first, so that the
     * backslashes the other rules add are not escaped a second time, then the two characters
     * that would otherwise end the value, then the line breaks that would otherwise end the
     * line.
     *
     * The control characters the format has no spelling for are dropped rather than written
     * out. A tenant who pastes one into a service name gets a file that opens; the alternative
     * is a file every client in the chain refuses, over a character nobody meant to type.
     */
    private static function text(string $value): string
    {
        $escaped = str_replace(
            ['\\', ';', ',', "\r\n", "\r", "\n"],
            ['\\\\', '\\;', '\\,', '\\n', '\\n', '\\n'],
            $value,
        );

        return preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', '', $escaped) ?? $escaped;
    }

    /**
     * One content line, folded to the format's length: the break is a line ending followed by
     * one space, and a parser removes both before it reads anything.
     *
     * The count is octets, which is what the limit is written in. A fold is taken back to the
     * start of a character rather than into the middle of a multi-octet one: a reader that
     * unfolds first recovers either way, and one that does not would be handed half a
     * character. Tenant text is not this repository's ASCII, whatever the templates are.
     */
    private static function fold(string $line): string
    {
        $folded = '';
        $limit = self::LINE_OCTETS;

        while (strlen($line) > $limit) {
            $take = $limit;

            while ($take > 1 && (ord($line[$take]) & 0xC0) === 0x80) {
                $take--;
            }

            $folded .= substr($line, 0, $take)."\r\n ";
            $line = substr($line, $take);
            // A continuation line spends one of its octets on the space that marks it as one.
            $limit = self::LINE_OCTETS - 1;
        }

        return $folded.$line;
    }
}
