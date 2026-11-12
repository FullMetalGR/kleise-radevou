<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Application\Notification\Message;
use App\Application\Notification\MessageKey;
use App\Infrastructure\Db\BookingReader;
use App\Infrastructure\Db\SettingsReader;
use App\Infrastructure\Notification\Messengers;
use App\Notifications\Acknowledgement;
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
 * The queued half of the acknowledgement: the re-read, the words, and the seam.
 *
 * **It carries the plaintext manage token, and that is why it is encrypted.** Only the hash is
 * stored on the booking row; the plaintext travels from the commit to the send in here, and a
 * queue row is a row. ShouldBeEncrypted is what keeps the payload out of the clear, so the
 * credential sits in no row and no log anybody can read and is gone once the message has been
 * sent.
 *
 * **It is a job rather than a queued listener**, and the difference is the tenant. A queued
 * listener is pushed with the event alone, and a transition record names the booking and never
 * the tenant, because it is built inside a use case and a use case never learns which tenant it
 * runs for. So the listener runs where the tenant is still known, and what it queues is this,
 * which carries the tenant's id the way every other job of this build does.
 *
 * **Every job re-reads before it acts**. The re-read supplies the current fields; it does not
 * decide whether to send. This message announces that a request was made, so a booking the
 * owner has already approved still gets it and the approval's own message follows. Only the
 * scheduled messages, the reminder and the nags, skip on a re-read that finds the state
 * changed, because those were queued against a future that may not have arrived.
 *
 * It skips where there is nothing to mail: a tenant that has gone, and a booking that no longer
 * exists. The third case the rule names, a customer whose address has been erased, is a
 * snapshot with no customer since tenant migration 000028, and this ends on it quietly, logged
 * once at info, as every sender does (ErasedBooking). The fourth, a key the tenant switched
 * off, is the seam's answer and not this one's.
 */
final class SendAcknowledgement implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $tenantId,
        public readonly string $uuid,
        public readonly ?string $manageToken,
    ) {}

    public function handle(BookingReader $bookings, SettingsReader $settings, Messengers $messengers, TenantUrls $urls, LoggerInterface $log): void
    {
        try {
            $tenant = JobTenant::find($this->tenantId, self::class, $log);

            if (! $tenant instanceof Tenant) {
                return;
            }

            $booking = $bookings->snapshot($tenant, $this->uuid);

            if ($booking === null || ErasedBooking::endsHere($booking, $tenant, MessageKey::Acknowledged, $log)) {
                return;
            }

            $messenger = $messengers->for($tenant);
            // The tenant's clock, its zone and its formats read off the settings row at the send.
            $tenantClock = TenantClock::of($tenant, $settings->read($tenant));

            // The manage link under the prefix of the booking's language, so the page it opens
            // speaks the language the message is written in.
            $link = $urls->manageLink(Slug::of($tenant->slug), $booking->uuid, $this->manageToken, Languages::ofBooking($booking->language));
            $myBookings = MyBookingsLine::for($messenger, $urls, $tenant, Languages::ofBooking($booking->language));

            // Written inside the seam's promise, after its switch.
            $messenger->send(MessageKey::Acknowledged, $booking->uuid, fn (): Message => Acknowledgement::of(
                $booking,
                $tenant->name,
                $tenantClock,
                $link,
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
