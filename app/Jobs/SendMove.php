<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Application\Clock;
use App\Application\Notification\Message;
use App\Application\Notification\MessageKey;
use App\Infrastructure\Db\BookingReader;
use App\Infrastructure\Db\SettingsReader;
use App\Infrastructure\Db\SqlText;
use App\Infrastructure\Notification\Messengers;
use App\Notifications\Move;
use App\Notifications\MyBookingsLine;
use App\Notifications\TenantClock;
use App\Tenancy\Languages;
use App\Tenancy\Tenant;
use App\Tenancy\TenantUrls;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The queued half of the customer's move message: the re-read, the words, and for an
 * appointment the customer already keeps, the calendar file at its next revision.
 *
 * **It is queued in the clear, because there is nothing in it that must not be**: the tenant's
 * id, the booking's public identifier, three facts off the record, and the start the booking
 * left. The plaintext manage token is minted in two moments, and a move is neither.
 *
 * **The start it left rides on the job as text**, in the one spelling the trail uses for the
 * same instant, because a move deletes the old items and the record is the last place that start
 * exists; it is read back naming its zone, the way every instant that crosses a row is. The
 * start it has now is not carried: it is the row's, re-read like every other field.
 *
 * **The revision is the booking's and not the message's.** It is asked of
 * BookingReader::revision at send time, which is what makes two files for one booking agree on
 * their order however the messages are queued, and it is asked only for a booking that carries a
 * file: a request still in front of the owner, and a booking approved and waiting to be paid,
 * get the words and no file, Move says why, so their number is not read. The second's first file
 * is the confirmation's, once it is paid or settled on site at the pay hop.
 *
 * **Every job re-reads before it acts**. This message announces that a booking was moved, and
 * what it prints beside that is the booking as it stands. It skips only where there is nothing
 * to mail: a tenant that has gone, and a booking that no longer exists.
 */
final class SendMove implements ShouldQueue
{
    use Queueable;

    /**
     * @param  bool  $wasARequest  whether the booking was still a request in front of the owner when it moved
     * @param  bool  $byTheCustomer  whether the customer moved it themselves
     * @param  string  $movedFrom  the start the booking left, as UTC text with its offset
     * @param  bool  $awaitingPayment  whether the booking was approved and waiting to be paid when it moved
     */
    public function __construct(
        public readonly int $tenantId,
        public readonly string $uuid,
        public readonly bool $wasARequest,
        public readonly bool $byTheCustomer,
        public readonly string $movedFrom,
        public readonly bool $awaitingPayment = false,
    ) {}

    public function handle(BookingReader $bookings, SettingsReader $settings, Messengers $messengers, Clock $clock, TenantUrls $urls, LoggerInterface $log): void
    {
        try {
            $tenant = JobTenant::find($this->tenantId, self::class, $log);

            if (! $tenant instanceof Tenant) {
                return;
            }

            $booking = $bookings->snapshot($tenant, $this->uuid);

            if ($booking === null || ErasedBooking::endsHere($booking, $tenant, MessageKey::Moved, $log)) {
                return;
            }

            $messenger = $messengers->for($tenant);
            // The tenant's clock, its zone and its formats read off the settings row at the send.
            $tenantClock = TenantClock::of($tenant, $settings->read($tenant));

            $movedFrom = SqlText::readInstant($this->movedFrom);
            $stampedAt = $clock->now();
            $revision = $this->wasARequest || $this->awaitingPayment ? 0 : $bookings->revision($tenant, $this->uuid);
            $myBookings = MyBookingsLine::for($messenger, $urls, $tenant, Languages::ofBooking($booking->language));

            // Written inside the seam's promise, after its switch.
            $messenger->send(MessageKey::Moved, $booking->uuid, fn (): Message => Move::of(
                $booking,
                $tenant->name,
                $tenantClock,
                $movedFrom,
                $this->wasARequest,
                $this->byTheCustomer,
                $stampedAt,
                $revision,
                $this->awaitingPayment,
                $myBookings,
            ));
        } catch (Throwable $failure) {
            // Nothing from below this line leaves the job, for the reason the other message
            // jobs give: the frames under it take the customer's name and address as
            // arguments, and failed_jobs.exception is a stringified trace in a column nothing
            // encrypts. See MessageNotSent, which is made here, at this frame.
            throw MessageNotSent::after($failure, $this->uuid);
        }
    }
}
