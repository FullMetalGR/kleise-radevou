<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Application\Notification\Message;
use App\Application\Notification\MessageKey;
use App\Infrastructure\Db\BookingNotes;
use App\Infrastructure\Db\BookingReader;
use App\Infrastructure\Db\SettingsReader;
use App\Infrastructure\Notification\BusinessDirectories;
use App\Infrastructure\Notification\Messengers;
use App\Notifications\BusinessRecipients;
use App\Notifications\NewBookingNotice;
use App\Notifications\TenantClock;
use App\Tenancy\Languages;
use App\Tenancy\Tenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The queued half of the new-booking notice: the re-read, the recipient rule, and one message
 * per person it names.
 *
 * **It is queued in the clear, because it carries nothing that must not be.** What it holds is
 * the tenant's id and the booking's public identifier; the magic link is the customer's and
 * this message has none, so there is no credential for an encrypted payload to protect. Only
 * the jobs that carry the plaintext token are encrypted, which keeps the encryption meaning
 * something.
 *
 * **One message per recipient.** The rule names an owner, a manager, a staff user and a staff
 * row's own address, and a message is built for one of them at a time: a refused mailbox
 * silences nobody else, and the set is where an address named twice is reduced to one.
 *
 * **Every job re-reads before it acts**, and so does the recipient rule: who should hear about
 * a booking is read now and not at the moment the listener ran, because a staff member removed
 * from the booking in between is no longer party to it. This message announces that a booking
 * was made, so a booking already cancelled still produces it and the cancellation's own notice
 * follows. It skips only where there is nothing to mail: a tenant that has gone, a booking
 * that no longer exists, and a booking nobody at the business is a recipient of.
 *
 * **The paid form** is the same message about an approved booking its payment confirmed: the
 * flag says which words to send and changes nobody it goes to.
 */
final class SendNewBookingNotice implements ShouldQueue
{
    use Queueable;

    /**
     * @param  bool  $paid  the paid form: an approved booking whose payment confirmed it
     */
    public function __construct(
        public readonly int $tenantId,
        public readonly string $uuid,
        public readonly bool $paid = false,
    ) {}

    public function handle(BookingReader $bookings, SettingsReader $settings, BookingNotes $notes, Messengers $messengers, BusinessDirectories $directories, Languages $languages, LoggerInterface $log): void
    {
        try {
            $tenant = JobTenant::find($this->tenantId, self::class, $log);

            if (! $tenant instanceof Tenant) {
                return;
            }

            $booking = $bookings->snapshot($tenant, $this->uuid);

            if ($booking === null || ErasedBooking::endsHere($booking, $tenant, MessageKey::NewBooking, $log)) {
                return;
            }

            $messenger = $messengers->for($tenant);
            // The tenant's clock, its zone and its formats read off the settings row at the send.
            $tenantClock = TenantClock::of($tenant, $settings->read($tenant));
            $recipients = BusinessRecipients::of($directories->for($tenant), $languages, $tenant)->forBooking($booking->uuid);
            $note = $notes->of($tenant, $booking->uuid);

            foreach ($recipients as $recipient) {
                // Written inside the seam's promise, after its switch, one recipient's apart
                // from the next.
                $messenger->send(MessageKey::NewBooking, $booking->uuid, fn (): Message => NewBookingNotice::of($booking, $tenant->name, $tenantClock, $recipient, $note, $this->paid));
            }
        } catch (Throwable $failure) {
            // The same guarantee one message along, for the same reason. There is no token in
            // here, but what this job does hold is people's addresses, and the frames under it
            // take them as arguments: failed_jobs.exception is a stringified trace in a column
            // nothing encrypts, and a log is not a place to put a person. See MessageNotSent,
            // which is made at this frame and carries nothing from the ones below it.
            throw MessageNotSent::after($failure, $this->uuid);
        }
    }
}
