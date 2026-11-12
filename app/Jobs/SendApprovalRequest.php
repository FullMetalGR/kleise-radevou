<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Application\Notification\Message;
use App\Application\Notification\MessageKey;
use App\Application\Notification\Recipient;
use App\Domain\Enum\Decision;
use App\Infrastructure\Db\BookingNotes;
use App\Infrastructure\Db\BookingReader;
use App\Infrastructure\Db\SettingsReader;
use App\Infrastructure\Notification\BusinessDirectories;
use App\Infrastructure\Notification\Messengers;
use App\Notifications\ApprovalLinks;
use App\Notifications\ApprovalRequest;
use App\Notifications\BusinessRecipients;
use App\Notifications\TenantClock;
use App\Tenancy\DecisionLinks;
use App\Tenancy\Languages;
use App\Tenancy\Tenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The queued half of the approval request: the re-read, the recipient rule, the links, and one
 * message per person the rule names.
 *
 * **It is queued in the clear, and the links are why that is safe.** They are minted here,
 * inside the job, from the tenant id and the booking uuid it carries and from the window the
 * re-read finds; nothing that could decide anything is ever written into a queue row. That is
 * the same rule the encrypted jobs follow from the other side: a credential in a payload has to
 * be ciphertext, and the way to have neither is to carry no credential.
 *
 * **What it fails with is MessageNotSent**, and here the hazard is live rather than theoretical.
 * The links are arguments of the call that writes them into the words, a stringified trace
 * prints the arguments of every frame, and failed_jobs.exception is a column nothing encrypts.
 * So nothing from below this frame leaves the job.
 *
 * **One message per recipient.** Two approvers get two different messages about one booking,
 * because a link decides as the person it was minted for; a refused mailbox silences nobody
 * else; and the recipient set is where an address named twice is reduced to one.
 *
 * **Every job re-reads before it acts**, and so does the recipient rule: who should be asked is
 * read now and not when the listener ran, because a staff member taken off the booking in
 * between is no longer party to it. This message announces that a request was made, so a request
 * somebody has already decided still produces it; what it cannot produce for a booking that has
 * moved on is a link, because the expiry a link carries is the hold's and a settled booking has
 * none. The message is told which case it is being written for, because the sentence about what
 * can be done about it differs and both have to be true. It skips only where there is nothing to
 * mail: a tenant that has gone, a booking that no longer exists, and a booking nobody at the
 * business is a recipient of.
 *
 * **Whether there is still a decision to make is the database's answer**, the same answer the
 * pages read and the sweep selects on: hold_expires_at against now() inside the statement. A job
 * comparing the row to a clock of its own would offer links the sweeper had already voided by
 * whatever the two clocks differ by.
 */
final class SendApprovalRequest implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $tenantId,
        public readonly string $uuid,
    ) {}

    public function handle(BookingReader $bookings, SettingsReader $settings, BookingNotes $notes, Messengers $messengers, BusinessDirectories $directories, DecisionLinks $links, Languages $languages, LoggerInterface $log): void
    {
        try {
            $tenant = JobTenant::find($this->tenantId, self::class, $log);

            if (! $tenant instanceof Tenant) {
                return;
            }

            $booking = $bookings->snapshot($tenant, $this->uuid);

            if ($booking === null || ErasedBooking::endsHere($booking, $tenant, MessageKey::ApprovalRequest, $log)) {
                return;
            }

            $messenger = $messengers->for($tenant);
            // The tenant's clock, its zone and its formats read off the settings row at the send.
            $tenantClock = TenantClock::of($tenant, $settings->read($tenant));
            $recipients = BusinessRecipients::of($directories->for($tenant), $languages, $tenant)->forBooking($booking->uuid);
            $stillWaiting = $booking->status->awaitsADecision() && $bookings->holdIsLive($tenant, $this->uuid);
            // A booking whose window has gone reaches no link: the expiry a link carries is the
            // hold's own, so a booking settled since the hold committed has no window to sign and a
            // link with an expiry invented here would be a credential outliving the thing it
            // decides. The window is read from the row all the same, because a null there is a
            // column this could not sign whatever the status said.
            $window = $stillWaiting ? $booking->holdExpiresAt : null;
            $note = $notes->of($tenant, $booking->uuid);

            foreach ($recipients as $recipient) {
                $decisionLinks = $window === null ? null : ApprovalLinks::for($links, $tenant, $this->uuid, $recipient, $window);

                // Written inside the seam's promise, after its switch, one recipient's apart
                // from the next.
                $messenger->send(MessageKey::ApprovalRequest, $booking->uuid, fn (): Message => ApprovalRequest::of(
                    $booking,
                    $tenant->name,
                    $tenantClock,
                    $recipient,
                    $decisionLinks,
                    $stillWaiting,
                    $note,
                ));
            }
        } catch (Throwable $failure) {
            // Nothing from below this line leaves the job: see the note above, and
            // MessageNotSent, which is made here, at this frame, where the only arguments are
            // the services the container injected.
            throw MessageNotSent::after($failure, $this->uuid);
        }
    }
}
