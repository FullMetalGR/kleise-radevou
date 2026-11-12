<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Application\Notification\Message;
use App\Application\Notification\MessageKey;
use App\Domain\Enum\BookingStatus;
use App\Infrastructure\Db\BookingReader;
use App\Infrastructure\Db\SettingsReader;
use App\Infrastructure\Notification\Messengers;
use App\Notifications\BookAgainLine;
use App\Notifications\MyBookingsLine;
use App\Notifications\ThankYou;
use App\Tenancy\Languages;
use App\Tenancy\Slug;
use App\Tenancy\Tenant;
use App\Tenancy\TenantUrls;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The queued half of the thank-you: the re-read, the words and the seam.
 *
 * **It is queued in the clear, because there is nothing in it that must not be**: the tenant's id
 * and the booking's public identifier. No credential is minted for a booking that is over, and
 * the message's two links, Book again and the reviews link, ask nobody who they are.
 *
 * **It is a job rather than a queued listener**, and the difference is the tenant. A transition
 * record names the booking and never the tenant, because it is built inside a use case and a use
 * case never learns which tenant it runs for.
 *
 * **Every job re-reads before it acts**, and this one sends only while the booking is still
 * completed: a thank-you for a visit is true of a visit the business still records as one. Nothing
 * in this build moves a booking out of completed, so the skip guards a later act rather than a
 * present one, and it is silent as the reminder's is: a skip is an ordinary end, not a fault. It
 * skips likewise where the tenant has gone or the booking no longer exists. A key the tenant
 * switched off is the seam's answer and not this one's.
 *
 * The reviews link is read off the settings row at the send, as the rest of the message is read at
 * the re-read: the link the owner holds now is the link the customer gets.
 */
final class SendThankYou implements ShouldQueue
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

            if ($booking === null || ErasedBooking::endsHere($booking, $tenant, MessageKey::ThankYou, $log)) {
                return;
            }

            if ($booking->status !== BookingStatus::Completed) {
                return;
            }

            $messenger = $messengers->for($tenant);

            $reviews = $settings->read($tenant)->profile->googleReviewsUrl;
            $bookAgain = BookAgainLine::for($urls, Slug::of($tenant->slug), $booking);
            $myBookings = MyBookingsLine::for($messenger, $urls, $tenant, Languages::ofBooking($booking->language));

            // Written inside the seam's promise, after its switch.
            $messenger->send(MessageKey::ThankYou, $booking->uuid, fn (): Message => ThankYou::of(
                $booking,
                $tenant->name,
                $reviews,
                $bookAgain,
                $myBookings,
            ));
        } catch (Throwable $failure) {
            // Nothing from below this line leaves the job, for the reason the other jobs give:
            // the frames under it take the customer's name and address as arguments, and
            // failed_jobs.exception is a stringified trace in a column nothing encrypts. See
            // MessageNotSent, which is made here, at this frame.
            throw MessageNotSent::after($failure, $this->uuid);
        }
    }
}
