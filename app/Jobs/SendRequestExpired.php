<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Application\Notification\Message;
use App\Application\Notification\MessageKey;
use App\Infrastructure\Db\BookingReader;
use App\Infrastructure\Db\SettingsReader;
use App\Infrastructure\Notification\Messengers;
use App\Notifications\MyBookingsLine;
use App\Notifications\RequestExpired;
use App\Notifications\TenantClock;
use App\Tenancy\Languages;
use App\Tenancy\Tenant;
use App\Tenancy\TenantUrls;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The queued half of the expiry message, in either form: the request nobody answered, or the
 * payment hold an approval left that lapsed unpaid. The re-read and the words.
 *
 * **It is queued in the clear, because there is nothing in it that must not be**: the tenant's
 * id, the booking's public identifier and whether the form is the unpaid one, and no credential
 * anywhere near it.
 *
 * **It is a job rather than a queued listener**, and the difference is the tenant. A transition
 * record names the booking and never the tenant, because it is built inside a use case and a
 * use case never learns which tenant it runs for.
 *
 * **Whether this expiry was news at all is decided before the job exists.** The hold class is
 * on the record and nowhere else once the row is settled, so the listener reads it and only an
 * approval hold or a payment hold reaches here, the second with $unpaid set; a checkout lapsing
 * queues nothing. That rule, the payment hold's lapse included, is kept at the one place that
 * can still see the class.
 *
 * **Every job re-reads before it acts**, and the re-read supplies the current fields rather
 * than deciding whether to send: this message announces that a request timed out, or that a
 * payment hold lapsed unpaid. It skips only where there is nothing to mail: a tenant that has
 * gone, and a booking that no longer exists.
 */
final class SendRequestExpired implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $tenantId,
        public readonly string $uuid,
        public readonly bool $unpaid = false,
    ) {}

    public function handle(BookingReader $bookings, SettingsReader $settings, Messengers $messengers, TenantUrls $urls, LoggerInterface $log): void
    {
        try {
            $tenant = JobTenant::find($this->tenantId, self::class, $log);

            if (! $tenant instanceof Tenant) {
                return;
            }

            $booking = $bookings->snapshot($tenant, $this->uuid);

            if ($booking === null || ErasedBooking::endsHere($booking, $tenant, MessageKey::RequestExpired, $log)) {
                return;
            }

            $messenger = $messengers->for($tenant);
            // The tenant's clock, its zone and its formats read off the settings row at the send.
            $tenantClock = TenantClock::of($tenant, $settings->read($tenant));
            $page = MyBookingsLine::for($messenger, $urls, $tenant, Languages::ofBooking($booking->language));

            // Written inside the seam's promise, after its switch.
            $messenger->send(MessageKey::RequestExpired, $booking->uuid, fn (): Message => $this->unpaid
                ? RequestExpired::unpaid($booking, $tenant->name, $tenantClock, $page)
                : RequestExpired::of($booking, $tenant->name, $tenantClock, $page));
        } catch (Throwable $failure) {
            // Nothing from below this line leaves the job, for the reason the other message
            // jobs give: the frames under it take the customer's name and address as
            // arguments, and failed_jobs.exception is a stringified trace in a column nothing
            // encrypts. See MessageNotSent, which is made here, at this frame.
            throw MessageNotSent::after($failure, $this->uuid);
        }
    }
}
