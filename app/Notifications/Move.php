<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Application\Dto\BookingSnapshot;
use App\Application\Notification\Message;
use App\Application\Notification\MessageKey;
use App\Application\Notification\Recipient;
use App\Domain\Enum\BookingStatus;
use App\Site\SiteLanguage;
use App\Tenancy\Languages;
use DateTimeImmutable;

/**
 * What the customer is told when their booking is moved to another time: the start it left, the
 * start it has now, and for an appointment they already hold in a diary, the file that updates
 * it.
 *
 * **The start it left is the record's, and the start it has is the row's.** A move deletes the old
 * items, so the start the booking left survives nowhere but the record that announced it, which is
 * why BookingRescheduled carries it. The start it has now is read back like every other field: the
 * message announces that a move happened, and what it prints beside that is the booking as it
 * stands when the message goes, so the items, the headline and the calendar file cannot disagree
 * with one another. A booking moved twice before the queue reached the first message is told twice
 * where it was and once where it is.
 *
 * **The calendar file goes where a calendar file has gone before.** A confirmed booking was sent a
 * file when the customer was told they had an appointment, and a calendar that imported it holds
 * an entry at the old time; this file is the same entry at a later revision, IcsBuilder, so
 * importing it updates the entry rather than adding a second beside it, and the revision is asked
 * of the booking rather than chosen by the message, BookingReader::revision. A request still in
 * front of the owner has had no file, because the acknowledgement carries none and the approval's
 * is the first; sending one here would put a revision above zero into the customer's calendar
 * before the zero arrives, and the approval's file would then be ignored by the very client it was
 * written for. A booking approved and waiting to be paid has had none either: the approval that
 * put it there carries a payment link and no file, and the confirmation after the payment carries
 * the first; a file here would leave an entry in the customer's calendar for a hold that may lapse
 * unpaid, and the confirmation's would repeat its revision. So a moved request, or a booking moved
 * while it awaited its payment, carries the two instants and no file.
 *
 * **It is worded by who acted and by what was moved.** A customer who moved their own appointment
 * is told what they did; one whose booking the business moved is told that, and told who to ask. A
 * request is a request and not an appointment, and nothing else about it changes on a move: same
 * hold, same expiry, same decision still to be made. Saying so is a present-tense claim about the
 * request as it stands, though, and this message goes after a queue: a request moved and then
 * approved before the worker reached the move is a confirmed booking by the time the customer
 * reads it. So that one sentence is the row's and not the record's, printed only while the re-read
 * still finds the decision pending; a request settled since is told its move and nothing about its
 * standing. What moved stays the record's word: a request moved, whatever it has become.
 *
 * A checkout hold never reaches this template. A checkout hold gets no mail on any event, and the
 * listener is where the hold class can still be read.
 *
 * Its words are customer-mail.php's moved group, read in the booking's language, the one the
 * booking was made in, and so are the booking's facts, both instants, the clock's line and the
 * calendar file's summary and description: the subject one whole key by what moved, the opening
 * one whole sentence by what moved and who moved it with the two starts as its placeholders, and
 * each sentence after the booking a key of its own, two of them joined into one paragraph where
 * both are true.
 */
final readonly class Move
{
    /**
     * @param  DateTimeImmutable  $movedFrom  the start the booking left, off the record
     * @param  bool  $wasARequest  whether the booking was still a request in front of the owner when it moved
     * @param  bool  $byTheCustomer  whether the customer moved it themselves
     * @param  DateTimeImmutable  $stampedAt  when this is being sent, which is what the calendar file stamps itself with
     * @param  int  $revision  which version of this booking's calendar entry the file is, from the booking and above zero; unused for a request or a booking awaiting its payment, which carry no file
     * @param  bool  $wasAwaitingPayment  whether the booking was approved and waiting to be paid when it moved, the record's word
     */
    public static function of(
        BookingSnapshot $booking,
        string $tenantName,
        TenantClock $clock,
        DateTimeImmutable $movedFrom,
        bool $wasARequest,
        bool $byTheCustomer,
        DateTimeImmutable $stampedAt,
        int $revision,
        bool $wasAwaitingPayment = false,
        ?string $myBookings = null,
    ): Message {
        $language = Languages::ofBooking($booking->language);
        $business = ['business' => $tenantName];
        [$subject, $opening] = self::form($wasARequest, $byTheCustomer);

        // One paragraph may be absent, so the body is the paragraphs that exist and not a
        // template with a hole in it.
        $body = implode("\n\n", array_filter([
            CustomerMailLines::line('customer-mail.greeting', $language, ['customer' => $booking->person()->name]),
            CustomerMailLines::line($opening, $language, [
                ...$business,
                'from' => TenantTime::instant($movedFrom, $clock, $language),
                'to' => TenantTime::instant($booking->startsAt(), $clock, $language),
            ]),
            BookingLines::of($booking, $clock, $language),
            BookingLines::total($booking, $language),
            self::whatItMeans($tenantName, $wasARequest, $booking->status->awaitsADecision(), $wasAwaitingPayment, $booking->status === BookingStatus::AwaitingPayment, $byTheCustomer, $language),
            TenantTime::footer($clock->zone, $tenantName, $language),
        ], fn (?string $paragraph): bool => $paragraph !== null));

        return new Message(
            MessageKey::Moved,
            new Recipient($booking->person()->email),
            CustomerMailLines::line($subject, $language, $business),
            $body.MyBookingsLine::closing($myBookings, $language),
            $booking->uuid,
            $wasARequest || $wasAwaitingPayment ? [] : [IcsBuilder::attachment($booking, CustomerMailLines::summary($booking, $tenantName, $language), $stampedAt, $revision)],
            $language->value,
        );
    }

    /**
     * The subject by what moved, a request or a booking, and the opening by what moved and who
     * moved it, the two keys of customer-mail.php's moved group that form holds; the opening
     * carries the start the booking left and the start it has now as :from and :to.
     *
     * @return array{string, string}
     */
    private static function form(bool $wasARequest, bool $byTheCustomer): array
    {
        return match (true) {
            $wasARequest && $byTheCustomer => ['customer-mail.moved.request.subject', 'customer-mail.moved.request.by_customer'],
            $wasARequest => ['customer-mail.moved.request.subject', 'customer-mail.moved.request.by_business'],
            $byTheCustomer => ['customer-mail.moved.booking.subject', 'customer-mail.moved.booking.by_customer'],
            default => ['customer-mail.moved.booking.subject', 'customer-mail.moved.booking.by_business'],
        };
    }

    /**
     * The paragraph after the times, or none: what the file beside the message is for; for a
     * request the row still holds as one, that nothing else about it has changed; for a booking the
     * row still holds awaiting its payment, that it does and that the payment confirms it, and
     * nothing about a file, since the confirmation that would carry one goes under a key whose
     * switch this message never reads; and where the business acted, who to ask. A request settled
     * since it moved, or a booking paid or ended since, by the customer's own hand, has no
     * paragraph here, because every sentence it could hold is either false of it or about a message
     * this one never read the switch of. Each sentence is a whole key of the moved group; two that
     * are both true are two whole sentences side by side in one paragraph.
     *
     * @param  bool  $wasARequest  whether the booking was a request when it moved, the record's word
     * @param  bool  $stillAwaitsADecision  whether the row re-read at send time is still one
     * @param  bool  $wasAwaitingPayment  whether it was awaiting its payment when it moved, the record's word
     * @param  bool  $stillAwaitsPayment  whether the row re-read at send time still is
     */
    private static function whatItMeans(string $tenantName, bool $wasARequest, bool $stillAwaitsADecision, bool $wasAwaitingPayment, bool $stillAwaitsPayment, bool $byTheCustomer, SiteLanguage $language): ?string
    {
        $sentences = [];

        if ($wasAwaitingPayment) {
            if ($stillAwaitsPayment) {
                $sentences[] = CustomerMailLines::line('customer-mail.moved.awaiting_payment', $language);
            }
        } elseif (! $wasARequest) {
            $sentences[] = CustomerMailLines::line('customer-mail.moved.calendar', $language);
        } elseif ($stillAwaitsADecision) {
            $sentences[] = CustomerMailLines::line('customer-mail.moved.unchanged', $language);
        }

        if (! $byTheCustomer) {
            $sentences[] = CustomerMailLines::line('customer-mail.moved.unexpected', $language, ['business' => $tenantName]);
        }

        return $sentences === [] ? null : implode(' ', $sentences);
    }
}
