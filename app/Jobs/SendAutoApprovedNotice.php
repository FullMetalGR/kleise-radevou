<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Application\Notification\Message;
use App\Application\Notification\MessageKey;
use App\Infrastructure\Db\BookingReader;
use App\Infrastructure\Db\SettingsReader;
use App\Infrastructure\Notification\BusinessDirectories;
use App\Infrastructure\Notification\Messengers;
use App\Notifications\AutoApprovedNotice;
use App\Notifications\BusinessRecipients;
use App\Notifications\TenantClock;
use App\Tenancy\Languages;
use App\Tenancy\Tenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The queued half of the auto-approval notice: the re-read, the recipient rule, and one message
 * per person it names.
 *
 * **It is queued in the clear, because it carries nothing that must not be**: the tenant's id,
 * the booking's public identifier, and whether the grant landed awaiting its payment, which the
 * notice then says in place of confirmed. The links a request carries are the approval
 * request's, and there is nothing left to decide here in any case.
 *
 * **Whether the customer heard is asked of the seam, once, and told to every recipient.** The
 * notice says whether the customer has been written to, which is false where the tenant
 * switched their approval message off; the seam is what drops such a message, so the seam is
 * what is asked.
 *
 * **One message per recipient.** The rule names an owner, a manager, a staff user and a staff
 * row's own address, and a message is built for one of them at a time: a refused mailbox
 * silences nobody else, and the set is where an address named twice is reduced to one.
 *
 * **Every job re-reads before it acts**, and so does the recipient rule: who should hear about
 * a booking is read now and not at the moment the listener ran, because a staff member taken
 * off the booking in between is no longer party to it. This message announces that the clock
 * granted a request, so a booking already cancelled still produces it and the cancellation's
 * own notice follows. It skips only where there is nothing to mail: a tenant that has gone, a
 * booking that no longer exists, and a booking nobody at the business is a recipient of.
 */
final class SendAutoApprovedNotice implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $tenantId,
        public readonly string $uuid,
        public readonly bool $awaitingPayment = false,
    ) {}

    public function handle(BookingReader $bookings, SettingsReader $settings, Messengers $messengers, BusinessDirectories $directories, Languages $languages, LoggerInterface $log): void
    {
        try {
            $tenant = JobTenant::find($this->tenantId, self::class, $log);

            if (! $tenant instanceof Tenant) {
                return;
            }

            $booking = $bookings->snapshot($tenant, $this->uuid);

            if ($booking === null || ErasedBooking::endsHere($booking, $tenant, MessageKey::AutoApproved, $log)) {
                return;
            }

            $messenger = $messengers->for($tenant);
            // The tenant's clock, its zone and its formats read off the settings row at the send.
            $tenantClock = TenantClock::of($tenant, $settings->read($tenant));
            $recipients = BusinessRecipients::of($directories->for($tenant), $languages, $tenant)->forBooking($booking->uuid);
            $customerWasTold = $messenger->sends(MessageKey::Approved);

            foreach ($recipients as $recipient) {
                // Written inside the seam's promise, after its switch, one recipient's apart
                // from the next.
                $messenger->send(MessageKey::AutoApproved, $booking->uuid, fn (): Message => AutoApprovedNotice::of($booking, $tenant->name, $tenantClock, $recipient, $customerWasTold, $this->awaitingPayment));
            }
        } catch (Throwable $failure) {
            // Nothing from below this line leaves the job. What it holds is people's addresses,
            // the business's as well as the customer's, and the frames under it take them as
            // arguments: failed_jobs.exception is a stringified trace in a column nothing
            // encrypts, and a log is not a place to put a person. See MessageNotSent, which is
            // made here, at this frame.
            throw MessageNotSent::after($failure, $this->uuid);
        }
    }
}
