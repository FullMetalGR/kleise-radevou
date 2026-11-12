<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Application\Notification\Message;
use App\Application\Notification\MessageKey;
use App\Infrastructure\Db\BookingReader;
use App\Infrastructure\Db\SettingsReader;
use App\Infrastructure\Notification\BusinessDirectories;
use App\Infrastructure\Notification\Messengers;
use App\Notifications\BusinessRecipients;
use App\Notifications\LatePaymentNotice;
use App\Notifications\TenantClock;
use App\Tenancy\Languages;
use App\Tenancy\Tenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The queued half of the late payment's notice: the re-read, the owners, and one message per
 * owner.
 *
 * **It is queued in the clear, because it carries nothing that must not be**: the tenant's id
 * and the booking's public identifier. Not the payment's id and nothing the notice said: the
 * message names the booking, which is what the owner looks the payment up by.
 *
 * **The owners alone**, the key's own declaration, MessageKey::ownersAlone, which
 * BusinessRecipients::under sends by: what to do with money that moved is an owner's decision,
 * so a manager and a staff member, who hear of a booking's diary, are not told. A tenant with no
 * owner row mails nobody.
 *
 * **Every job re-reads before it acts**. It skips only where there is nothing to mail: a tenant
 * that has gone and a booking that no longer exists. The tenant's switch is the seam's, like
 * every key's: emails_off may carry late_payment.
 */
final class SendLatePaymentNotice implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $tenantId,
        public readonly string $uuid,
    ) {}

    public function handle(BookingReader $bookings, SettingsReader $settings, Messengers $messengers, BusinessDirectories $directories, Languages $languages, LoggerInterface $log): void
    {
        try {
            $tenant = JobTenant::find($this->tenantId, self::class, $log);

            if (! $tenant instanceof Tenant) {
                return;
            }

            $booking = $bookings->snapshot($tenant, $this->uuid);

            if ($booking === null || ErasedBooking::endsHere($booking, $tenant, MessageKey::LatePayment, $log)) {
                return;
            }

            $messenger = $messengers->for($tenant);
            // The tenant's clock, its zone and its formats read off the settings row at the send.
            $tenantClock = TenantClock::of($tenant, $settings->read($tenant));

            foreach (BusinessRecipients::of($directories->for($tenant), $languages, $tenant)->under(MessageKey::LatePayment, $booking->uuid) as $owner) {
                // Written inside the seam's promise, after its switch, one owner's apart from the
                // next.
                $messenger->send(MessageKey::LatePayment, $booking->uuid, fn (): Message => LatePaymentNotice::of($booking, $tenant->name, $tenantClock, $owner));
            }
        } catch (Throwable $failure) {
            // Nothing from below this line leaves the job, the house pattern: the frames under it
            // hold people's addresses, and failed_jobs.exception is a trace nothing encrypts.
            throw MessageNotSent::after($failure, $this->uuid);
        }
    }
}
