<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Application\Notification\Message;
use App\Application\Notification\MessageKey;
use App\Infrastructure\Db\BookingReader;
use App\Infrastructure\Db\SettingsReader;
use App\Infrastructure\Notification\Messengers;
use App\Notifications\MyBookingsLine;
use App\Notifications\RequestRejected;
use App\Notifications\TenantClock;
use App\Tenancy\Languages;
use App\Tenancy\Tenant;
use App\Tenancy\TenantUrls;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The queued half of the customer's no: the re-read and the words.
 *
 * **It is queued in the clear, because there is nothing in it that must not be.** It carries
 * the tenant's id and the booking's public identifier, and the one thing about a rejection that
 * would have to be protected is the owner's reason, which reaches neither this job nor the
 * message it builds: the record does not carry it and the snapshot does not read it.
 *
 * **It is a job rather than a queued listener**, and the difference is the tenant. A transition
 * record names the booking and never the tenant, because it is built inside a use case and a
 * use case never learns which tenant it runs for.
 *
 * **Every job re-reads before it acts**, and the re-read supplies the current fields rather
 * than deciding whether to send: this message announces that a request was declined, and a
 * booking that has moved on since still gets it. It skips only where there is nothing to mail:
 * a tenant that has gone, and a booking that no longer exists.
 */
final class SendRequestRejected implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $tenantId,
        public readonly string $uuid,
    ) {}

    public function handle(BookingReader $bookings, SettingsReader $settings, Messengers $messengers, TenantUrls $urls, LoggerInterface $log): void
    {
        try {
            $tenant = JobTenant::find($this->tenantId, self::class, $log);

            if (! $tenant instanceof Tenant) {
                return;
            }

            $booking = $bookings->snapshot($tenant, $this->uuid);

            if ($booking === null || ErasedBooking::endsHere($booking, $tenant, MessageKey::Rejected, $log)) {
                return;
            }

            $messenger = $messengers->for($tenant);
            // The tenant's clock, its zone and its formats read off the settings row at the send.
            $tenantClock = TenantClock::of($tenant, $settings->read($tenant));

            $myBookings = MyBookingsLine::for($messenger, $urls, $tenant, Languages::ofBooking($booking->language));

            // Written inside the seam's promise, after its switch.
            $messenger->send(MessageKey::Rejected, $booking->uuid, fn (): Message => RequestRejected::of($booking, $tenant->name, $tenantClock, $myBookings));
        } catch (Throwable $failure) {
            // Nothing from below this line leaves the job, for the reason the other message
            // jobs give: the frames under it take the customer's name and address as
            // arguments, and failed_jobs.exception is a stringified trace in a column nothing
            // encrypts. See MessageNotSent, which is made here, at this frame.
            throw MessageNotSent::after($failure, $this->uuid);
        }
    }
}
