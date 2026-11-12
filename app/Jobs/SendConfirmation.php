<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Application\Clock;
use App\Application\Notification\Message;
use App\Application\Notification\MessageKey;
use App\Infrastructure\Db\BookingReader;
use App\Infrastructure\Db\SettingsReader;
use App\Infrastructure\Notification\Messengers;
use App\Notifications\BookAgainLine;
use App\Notifications\Confirmation;
use App\Notifications\MyBookingsLine;
use App\Notifications\TenantClock;
use App\Tenancy\Languages;
use App\Tenancy\Slug;
use App\Tenancy\Tenant;
use App\Tenancy\TenantUrls;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The queued half of the confirmation: the re-read, the words, the calendar file and the seam.
 *
 * **It carries the plaintext manage token, and that is why it is encrypted**, the same reason
 * SendAcknowledgement is. That only the hash is stored is about the booking row; the plaintext
 * travels from the commit to the send in here, and a queue row is a row.
 *
 * **It is a job rather than a queued listener**, and the difference is the tenant. A transition
 * record names the booking and never the tenant, because it is built inside a use case and a
 * use case never learns which tenant it runs for. So the listener runs where the tenant is
 * still known, and what it queues is this.
 *
 * **Every job re-reads before it acts**. The re-read supplies the current fields; it does not
 * decide whether to send. This message announces that a booking was confirmed, so a booking the
 * customer has since cancelled still gets it and the cancellation's own message follows. It
 * skips only where there is nothing to mail: a tenant that has gone, a booking that no longer
 * exists, and a booking whose customer was erased, which the snapshot reads as no customer
 * since tenant migration 000028 and every sender ends on quietly, logged once at info
 * (ErasedBooking). A key the tenant switched off is the seam's answer and not this one's.
 *
 * **The link is built only where a token came with the confirmation.** A confirm request the
 * guard verified is the second and last moment the plaintext exists; an admin's confirmation
 * has none, and the message then carries the booking without a link rather than a link with no
 * credential in it.
 *
 * The tenant's Important info is read off the settings row at the send, as the rest of the
 * message is read at the re-read: the text the owner holds now is the text the customer gets.
 */
final class SendConfirmation implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $tenantId,
        public readonly string $uuid,
        public readonly ?string $manageToken,
        public readonly bool $afterPayment = false,
        public readonly bool $requested = false,
    ) {}

    public function handle(BookingReader $bookings, SettingsReader $settings, Messengers $messengers, TenantUrls $urls, Clock $clock, LoggerInterface $log): void
    {
        try {
            $tenant = JobTenant::find($this->tenantId, self::class, $log);

            if (! $tenant instanceof Tenant) {
                return;
            }

            $booking = $bookings->snapshot($tenant, $this->uuid);

            if ($booking === null || ErasedBooking::endsHere($booking, $tenant, MessageKey::Confirmed, $log)) {
                return;
            }

            $messenger = $messengers->for($tenant);
            // The settings row at the send: the tenant's clock, its zone and its formats, and the
            // Important info.
            $row = $settings->read($tenant);
            $tenantClock = TenantClock::of($tenant, $row);
            $importantInfo = $row->profile->importantInfo;

            // The manage link under the prefix of the booking's language, so the page it opens
            // speaks the language the message is written in.
            $link = $this->manageToken === null ? null : $urls->manageLink(Slug::of($tenant->slug), $booking->uuid, $this->manageToken, Languages::ofBooking($booking->language));
            $stampedAt = $clock->now();
            $bookAgain = BookAgainLine::for($urls, Slug::of($tenant->slug), $booking);
            // After a payment the file may not be the first for this uuid, an approval having sent
            // one at zero, so its number is the reader's. With no token the message says where the
            // customer's link is, as the approved message does: in the acknowledgement of their
            // request, where one was sent.
            $revision = $this->afterPayment ? $bookings->revision($tenant, $booking->uuid) : 0;
            $acknowledged = $this->afterPayment && $this->manageToken === null ? $this->requested && $messenger->sends(MessageKey::Acknowledged) : null;
            $myBookings = MyBookingsLine::for($messenger, $urls, $tenant, Languages::ofBooking($booking->language));

            // Written inside the seam's promise, after its switch.
            $messenger->send(MessageKey::Confirmed, $booking->uuid, fn (): Message => Confirmation::of(
                $booking,
                $tenant->name,
                $tenantClock,
                $link,
                $stampedAt,
                $bookAgain,
                $revision,
                $acknowledged,
                $importantInfo,
                $myBookings,
            ));
        } catch (Throwable $failure) {
            // Nothing from below this line leaves the job. The link is an argument of the call
            // that writes it into the words, and an argument is what a stringified trace
            // prints; failed_jobs.exception is where such a trace is kept and nothing encrypts
            // that column. See MessageNotSent, which is made here, at this frame, and carries
            // nothing from the ones under it.
            throw MessageNotSent::after($failure, $this->uuid);
        }
    }
}
