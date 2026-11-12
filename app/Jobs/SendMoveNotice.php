<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Application\Notification\Message;
use App\Application\Notification\MessageKey;
use App\Domain\Audit\Actor;
use App\Infrastructure\Db\BookingReader;
use App\Infrastructure\Db\SettingsReader;
use App\Infrastructure\Db\SqlText;
use App\Infrastructure\Notification\BusinessDirectories;
use App\Infrastructure\Notification\Messengers;
use App\Notifications\BusinessRecipients;
use App\Notifications\MoveNotice;
use App\Notifications\TenantClock;
use App\Notifications\WhoActed;
use App\Site\Brand;
use App\Tenancy\Languages;
use App\Tenancy\Tenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The queued half of the move notice: the re-read, the recipient rule, who acted, and one
 * message per person the rule names.
 *
 * **It is queued in the clear, because it carries nothing that must not be**: the tenant's id,
 * the booking's public identifier, whether it was still a request, the actor as the trail spells
 * it, and the start the booking left as UTC text, read back naming its zone like every instant
 * that crosses a row.
 *
 * **Who acted is turned into a name here**, from the tenant's users at the moment the message
 * goes, and support in the public name, for the reason SendCancellationNotice gives. **One
 * message per recipient**, for the reason every business notice gives. **Every job re-reads
 * before it acts**, and this one announces that a booking was moved; it skips only where there
 * is nothing to mail: a tenant that has gone, a booking that no longer exists, and a booking
 * nobody at the business is a recipient of.
 */
final class SendMoveNotice implements ShouldQueue
{
    use Queueable;

    /**
     * @param  bool  $wasARequest  whether the booking was still a request in front of the owner when it moved
     * @param  string  $actor  who moved it, as the trail spells an actor
     * @param  string  $movedFrom  the start the booking left, as UTC text with its offset
     */
    public function __construct(
        public readonly int $tenantId,
        public readonly string $uuid,
        public readonly bool $wasARequest,
        public readonly string $actor,
        public readonly string $movedFrom,
    ) {}

    public function handle(BookingReader $bookings, SettingsReader $settings, Messengers $messengers, BusinessDirectories $directories, Brand $brand, Languages $languages, LoggerInterface $log): void
    {
        try {
            $tenant = JobTenant::find($this->tenantId, self::class, $log);

            if (! $tenant instanceof Tenant) {
                return;
            }

            $booking = $bookings->snapshot($tenant, $this->uuid);

            if ($booking === null || ErasedBooking::endsHere($booking, $tenant, MessageKey::MoveNotice, $log)) {
                return;
            }

            $messenger = $messengers->for($tenant);
            // The tenant's clock, its zone and its formats read off the settings row at the send.
            $tenantClock = TenantClock::of($tenant, $settings->read($tenant));
            $directory = $directories->for($tenant);
            $recipients = BusinessRecipients::of($directory, $languages, $tenant)->forBooking($booking->uuid);
            $movedBy = WhoActed::of(Actor::of($this->actor), $booking->person(), $directory, $brand->name);
            $movedFrom = SqlText::readInstant($this->movedFrom);

            foreach ($recipients as $recipient) {
                // Written inside the seam's promise, after its switch, one recipient's apart
                // from the next.
                $messenger->send(MessageKey::MoveNotice, $booking->uuid, fn (): Message => MoveNotice::of($booking, $tenant->name, $tenantClock, $recipient, $movedFrom, $this->wasARequest, $movedBy));
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
