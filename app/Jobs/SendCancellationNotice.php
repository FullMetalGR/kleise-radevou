<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Application\Notification\Message;
use App\Application\Notification\MessageKey;
use App\Domain\Audit\Actor;
use App\Infrastructure\Db\BookingReader;
use App\Infrastructure\Db\SettingsReader;
use App\Infrastructure\Notification\BusinessDirectories;
use App\Infrastructure\Notification\Messengers;
use App\Notifications\BusinessRecipients;
use App\Notifications\CancellationNotice;
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
 * The queued half of the cancellation notice: the re-read, the recipient rule, who acted, and
 * one message per person the rule names.
 *
 * **It is queued in the clear, because it carries nothing that must not be**: the tenant's id,
 * the booking's public identifier, whether it was still a request, and the actor as the trail
 * spells it, in one of the five shapes audit_log holds: the role words customer and system, a
 * job's name, a user's id or a support operator's id, and never a name or an address.
 *
 * **Who acted is turned into a name here and not on the listener**, because the name is read
 * from the tenant's users at the moment the message goes, the way the recipients are: a user
 * renamed or removed between the click and the send is described as they are now. The actor
 * itself is the record's, since the row cannot say who cancelled it. Support is said in the
 * public name, read from configuration as the job runs.
 *
 * **One message per recipient.** The rule names an owner, a manager, a staff user and a staff
 * row's own address, and a message is built for one of them at a time: a refused mailbox
 * silences nobody else, and the set is where an address named twice is reduced to one.
 *
 * **Every job re-reads before it acts**, and so does the recipient rule. This message announces
 * that a booking was cancelled; it skips only where there is nothing to mail: a tenant that has
 * gone, a booking that no longer exists, and a booking nobody at the business is a recipient
 * of.
 */
final class SendCancellationNotice implements ShouldQueue
{
    use Queueable;

    /**
     * @param  bool  $wasARequest  whether the booking was still a request in front of the owner when it was cancelled
     * @param  string  $actor  who cancelled it, as the trail spells an actor
     */
    public function __construct(
        public readonly int $tenantId,
        public readonly string $uuid,
        public readonly bool $wasARequest,
        public readonly string $actor,
    ) {}

    public function handle(BookingReader $bookings, SettingsReader $settings, Messengers $messengers, BusinessDirectories $directories, Brand $brand, Languages $languages, LoggerInterface $log): void
    {
        try {
            $tenant = JobTenant::find($this->tenantId, self::class, $log);

            if (! $tenant instanceof Tenant) {
                return;
            }

            $booking = $bookings->snapshot($tenant, $this->uuid);

            if ($booking === null || ErasedBooking::endsHere($booking, $tenant, MessageKey::CancellationNotice, $log)) {
                return;
            }

            $messenger = $messengers->for($tenant);
            // The tenant's clock, its zone and its formats read off the settings row at the send.
            $tenantClock = TenantClock::of($tenant, $settings->read($tenant));
            $directory = $directories->for($tenant);
            $recipients = BusinessRecipients::of($directory, $languages, $tenant)->forBooking($booking->uuid);
            $cancelledBy = WhoActed::of(Actor::of($this->actor), $booking->person(), $directory, $brand->name);

            foreach ($recipients as $recipient) {
                // Written inside the seam's promise, after its switch, one recipient's apart
                // from the next.
                $messenger->send(MessageKey::CancellationNotice, $booking->uuid, fn (): Message => CancellationNotice::of($booking, $tenant->name, $tenantClock, $recipient, $this->wasARequest, $cancelledBy));
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
