<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Application\Clock;
use App\Application\Notification\Message;
use App\Application\Notification\MessageKey;
use App\Application\Notification\Recipient;
use App\Domain\Enum\Decision;
use App\Infrastructure\Db\BookingReader;
use App\Infrastructure\Db\SettingsReader;
use App\Infrastructure\Db\SqlText;
use App\Infrastructure\Notification\BusinessDirectories;
use App\Infrastructure\Notification\Messengers;
use App\Notifications\ApprovalLinks;
use App\Notifications\ApprovalNag;
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
 * One of the three nags about a request nobody has decided, on the queue until a quarter, a
 * half or three quarters of the way through the request's window, NagTheBusiness: the re-read
 * that decides whether it goes at all, the recipient rule, the links, and one message per
 * person the rule names.
 *
 * **It is a job of its own and the three are independent.** Each fires at its own instant and finds
 * out for itself whether there is still a request to nag about, so a request decided after the
 * first nag gets no second and no third, and a request that lapses gets none past its lapse.
 * Nothing dequeues one: the re-read is the whole of the cancellation, as it is for the reminder. It
 * is the one message, beside the reminder, that announces nothing and therefore skips on any
 * change: a nag about a request somebody has answered is not a late announcement, it is noise, and
 * a skip is the ordinary end of a job queued days ago, so nothing is logged for one.
 *
 * **The state it was queued for is the request still waiting on the same window**, and two reads,
 * the snapshot and holdIsLive, answer three checks, in this order. The row's status must still
 * await a decision. The row's expiry must be the one this job carries, spelled as the wire spells
 * an instant, SqlText::instant, on both sides: a window somebody rewrote is a window this job was
 * not timed for, and the nags timed for it, if any, are somebody else's. A move keeps the expiry,
 * RescheduleBooking leaves the status, the class and the expiry exactly as the lock found them, so
 * a moved request is still nagged, about the times it now holds, since the message is built from
 * the re-read. And the window must still be live by the database's clock,
 * BookingReader::holdIsLive. The second check answers the first in every state a row can reach
 * today, because every transition out of awaiting_approval nulls the expiry as well; the status is
 * asked all the same, because it is the first question and a transition that ever left the window
 * standing would be caught by it.
 *
 * **Which clock answers what.** Whether there is still a decision to make is the database's answer
 * and never a comparison against a clock of ours: hold_expires_at was written by now(), the sweep
 * selects against now(), and the pages a nag's links open ask holdIsLive too, as the guards of
 * approve and reject do, so a nag that read another clock would carry links to a page that refuses
 * them, or fall silent on a request the pages still offer buttons for. This is the question whether
 * the job is still ahead of what it is about, asked of the clock that decides it: the reminder asks
 * the Clock port because no statement decides anything about a reminder, and a nag asks the
 * database because a statement does decide about its window. A nag that fires late, after the
 * expiry it was queued against, finds the window closed and sends nothing. The Clock port is read
 * once, after that gate, for the words alone: how long is left is the distance from now to the
 * expiry, and ApprovalNag rounds it to the hour, so the seconds the two clocks can differ by never
 * reach the words.
 *
 * **It is not unique on its state, and the reason the reminder is does not apply here.** The
 * reminder is ShouldBeUnique because two listeners can read one state, a confirmation's
 * listener reading after a move committed, and hand over two jobs for it. A nag is handed over
 * by one listener on one record, BookingHeld, which HoldBooking publishes once per booking after
 * its commit and which no later transition publishes again, so there is one hand-over per
 * request and three jobs that differ by their instant. A lock would cost a cache_locks row per
 * nag per hold and answer no case.
 *
 * **It is queued in the clear, and the links are why that is safe**: they are minted here, inside
 * the job, from the tenant and the booking it carries and the window the re-read finds, so no queue
 * row ever holds one, the rule SendApprovalRequest follows. The switch is asked before any link is
 * minted, because a credential built for a message the seam will drop is a credential built for
 * nothing; the seam reads the switch again at the send, and that read is the one that decides. And
 * what it fails with is MessageNotSent, made at this frame, because the links are arguments of the
 * frames below and failed_jobs.exception is a column nothing encrypts.
 *
 * **Three rows wait on the queue for every approval hold** until their instants pass, whatever
 * becomes of the request in the meantime, since nothing dequeues them. That is not a v1 problem
 * and is deliberately not solved here, for the reason the reminder gives: a queue holds rows
 * cheaply, the database driver's pop passes over a row until its instant, and a shape that
 * deleted a row on a decision would need a job identity the queue drivers do not share.
 */
final class SendApprovalNag implements ShouldQueue
{
    use Queueable;

    /**
     * @param  string  $expiresAt  the request's expiry when the nag was timed, as UTC text with its offset
     */
    public function __construct(
        public readonly int $tenantId,
        public readonly string $uuid,
        public readonly string $expiresAt,
    ) {}

    public function handle(BookingReader $bookings, SettingsReader $settings, Messengers $messengers, BusinessDirectories $directories, DecisionLinks $links, Clock $clock, Languages $languages, LoggerInterface $log): void
    {
        try {
            $tenant = JobTenant::find($this->tenantId, self::class, $log);

            if (! $tenant instanceof Tenant) {
                return;
            }

            $booking = $bookings->snapshot($tenant, $this->uuid);

            if ($booking === null || ErasedBooking::endsHere($booking, $tenant, MessageKey::ApprovalNag, $log)) {
                return;
            }

            if (! $booking->status->awaitsADecision() || $booking->holdExpiresAt === null) {
                return;
            }

            // The window it was timed against: a row on another one is a row this nag is not for.
            if (SqlText::instant($booking->holdExpiresAt) !== $this->expiresAt) {
                return;
            }

            // And that window is still open by the clock that wrote it and will close it.
            if (! $bookings->holdIsLive($tenant, $this->uuid)) {
                return;
            }

            $messenger = $messengers->for($tenant);
            // The tenant's clock, its zone and its formats read off the settings row at the send.
            $tenantClock = TenantClock::of($tenant, $settings->read($tenant));

            if (! $messenger->sends(MessageKey::ApprovalNag)) {
                return;
            }

            $now = $clock->now();
            $recipients = BusinessRecipients::of($directories->for($tenant), $languages, $tenant)->forBooking($booking->uuid);

            $expiresAt = $booking->holdExpiresAt;

            foreach ($recipients as $recipient) {
                $decisionLinks = ApprovalLinks::for($links, $tenant, $this->uuid, $recipient, $expiresAt);

                // Written inside the seam's promise, after its switch, one recipient's apart
                // from the next.
                $messenger->send(MessageKey::ApprovalNag, $booking->uuid, fn (): Message => ApprovalNag::of(
                    $booking,
                    $tenant->name,
                    $tenantClock,
                    $recipient,
                    $decisionLinks,
                    $expiresAt,
                    $now,
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
