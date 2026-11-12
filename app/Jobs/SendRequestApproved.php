<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Application\Clock;
use App\Application\Notification\Message;
use App\Application\Notification\MessageKey;
use App\Infrastructure\Db\BookingReader;
use App\Infrastructure\Db\SettingsReader;
use App\Infrastructure\Notification\Messengers;
use App\Notifications\MyBookingsLine;
use App\Notifications\RequestApproved;
use App\Notifications\TenantClock;
use App\Tenancy\Languages;
use App\Tenancy\PayLinks;
use App\Tenancy\Tenant;
use App\Tenancy\TenantUrls;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The queued half of the customer's yes: the re-read, the words and the calendar file.
 *
 * **It is queued in the clear, because there is nothing in it that must not be.** It carries the
 * tenant's id and the booking's public identifier and no credential at all: the plaintext manage
 * token is minted in two moments, and an approval is neither of them. The message says so in
 * words, pointing the customer at the acknowledgement that carried their link, rather than
 * inventing a second copy of it to put in a queue row.
 *
 * **An approval that landed awaiting_payment carries the payment link instead**: the listener
 * says which landing it was, from the record's "to", and the link is minted here from the
 * booking, so the queue row carries no signature either.
 *
 * **It asks the seam whether that acknowledgement is a message this tenant sends**, because the
 * sentence pointing at it is false where it is switched off and that customer holds no link at
 * all. The question goes to the seam and not to the settings row, because the seam is what drops
 * a switched-off message and one rule read two ways is two rules.
 *
 * **It is a job rather than a queued listener**, and the difference is the tenant. A transition
 * record names the booking and never the tenant, because it is built inside a use case and a use
 * case never learns which tenant it runs for. So the listener runs where the tenant is still
 * known, and what it queues is this.
 *
 * **Every job re-reads before it acts**. The re-read supplies the current fields; it does not
 * decide whether to send. This message announces that a request was approved, so a booking the
 * customer has since cancelled still gets it and the cancellation's own message follows. It
 * skips only where there is nothing to mail: a tenant that has gone, and a booking that no
 * longer exists. A key the tenant switched off is the seam's answer and not this one's.
 *
 * The clock is read for the calendar file's own stamp, which is what a client compares two
 * imports of one booking by; see IcsBuilder.
 */
final class SendRequestApproved implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $tenantId,
        public readonly string $uuid,
        public readonly bool $awaitingPayment = false,
    ) {}

    public function handle(BookingReader $bookings, SettingsReader $settings, Messengers $messengers, Clock $clock, PayLinks $links, TenantUrls $urls, LoggerInterface $log): void
    {
        try {
            $tenant = JobTenant::find($this->tenantId, self::class, $log);

            if (! $tenant instanceof Tenant) {
                return;
            }

            $booking = $bookings->snapshot($tenant, $this->uuid);

            if ($booking === null || ErasedBooking::endsHere($booking, $tenant, MessageKey::Approved, $log)) {
                return;
            }

            $messenger = $messengers->for($tenant);
            // The tenant's clock, its zone and its formats read off the settings row at the send.
            $tenantClock = TenantClock::of($tenant, $settings->read($tenant));

            $myBookings = MyBookingsLine::for($messenger, $urls, $tenant, Languages::ofBooking($booking->language));

            if ($this->awaitingPayment) {
                // The payment link under the prefix of the booking's language, so the page it opens
                // speaks the language the message is written in.
                $payLink = $links->link($tenant, $booking->uuid, $booking->endsAt(), Languages::ofBooking($booking->language));

                // Written inside the seam's promise, after its switch.
                $messenger->send(MessageKey::Approved, $booking->uuid, fn (): Message => RequestApproved::awaitingPayment(
                    $booking,
                    $tenant->name,
                    $tenantClock,
                    $payLink,
                    $myBookings,
                ));

                return;
            }

            $stampedAt = $clock->now();
            $acknowledged = $messenger->sends(MessageKey::Acknowledged);

            $messenger->send(MessageKey::Approved, $booking->uuid, fn (): Message => RequestApproved::of(
                $booking,
                $tenant->name,
                $tenantClock,
                $stampedAt,
                $acknowledged,
                $myBookings,
            ));
        } catch (Throwable $failure) {
            // Nothing from below this line leaves the job. There is no manage token in here,
            // but the frames under it take the customer's name and address as arguments, and
            // on an awaiting_payment landing the signed payment link, a credential, and
            // failed_jobs.exception is a stringified trace in a column nothing encrypts. See
            // MessageNotSent, which is made here, at this frame, and carries nothing from the
            // ones below it.
            throw MessageNotSent::after($failure, $this->uuid);
        }
    }
}
