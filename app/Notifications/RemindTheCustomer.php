<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Application\Clock;
use App\Application\Event\BookingApproved;
use App\Application\Event\BookingConfirmed;
use App\Application\Event\BookingRescheduled;
use App\Application\Notification\ReminderTime;
use App\Domain\Enum\BookingStatus;
use App\Infrastructure\Db\BookingReader;
use App\Infrastructure\Db\SettingsReader;
use App\Jobs\SendReminder;
use App\Tenancy\CurrentTenant;

/**
 * Every booking that lands on confirmed is scheduled a reminder, and a confirmed booking that
 * moves is scheduled a fresh one for the start it took.
 *
 * **It subscribes to three records and asks each where the booking now stands.** A customer
 * confirming their own hold, an admin's create, a payment confirming a held booking, a settlement
 * on site at the pay hop, an owner approving a request and the sweeper granting one that timed out
 * all land the booking on confirmed, and BookingConfirmed and BookingApproved say so in what they
 * landed on. An approval, the owner's or the sweeper's, of a booking to be paid online through a
 * working connection lands on awaiting_payment, its record says so, and no reminder is queued for
 * an appointment nobody has yet: the BookingConfirmed of its payment or its settlement on site
 * queues one. A move carries the status it left in place, BookingRescheduled, and the start it
 * took beside it, and only a confirmed booking's move is a new appointment to be reminded of: a
 * moved request has no appointment yet, and a moved checkout hold is a customer still in the flow.
 * It is one listener on three records rather than three, so that when a reminder is queued is a
 * rule written once.
 *
 * **What it schedules for is one state of the booking, its first start and its revision**, and
 * both ride on the job, which sends only for a row that still holds both, SendReminder. The start
 * is the record's where the record carries one, a move, and the row's where it does not, a
 * confirmation or an approval; the revision is BookingReader::revision, read after the commit
 * either way, the number a move raises and nothing lowers. The start alone could not tell two
 * landings on one start apart: a booking moved away and back holds three jobs, two of them queued
 * for the start the row holds again, and both would send. The revision tells them apart, because
 * the move back left it higher than the first landing found it, and only the job queued at the
 * row's revision speaks.
 *
 * **The two are read a statement apart, and a move can commit between them.** A confirmation's
 * start and revision are two reads after its commit, and a move's start is its record's while
 * its revision is a read after its commit; a move landing in between hands this listener a
 * start from before it and a revision from after it. Where that move changed the start, the
 * pair names no state the row ever held, and the job carrying it skips when it fires, on the
 * start, while the move that made the row's state queues its own: a pair read apart costs a
 * job that does nothing and never a reminder that should not go. Where that move kept the
 * start, which nothing refuses, or where a confirmation's listener reads both after a move
 * has committed, the pair is the state the move's own listener queues for, and two jobs for
 * one state would be two reminders. That is the queue's to close: SendReminder is unique on
 * that state while a job for it waits, and the second hand-over is dropped at the dispatch.
 * Its docblock says how long and why that is enough.
 *
 * **The lead is the tenant's, and zero switches reminders off**. It is read here and not carried
 * on the record, because a record is built inside a use case and a use case never learns which
 * tenant it runs for; the tenant is the publisher's binding, the same place QueuedMessages reads
 * it. A lead of zero returns before the clock or the booking is read, so a tenant who sends no
 * reminders pays one settings read per landing.
 *
 * **When it goes is ReminderTime's answer**: the lead before the start as elapsed time, or nothing
 * where that instant has already passed. The job is delayed to that instant and not by a number of
 * seconds from now. An instant is what the queue stores, so the row becomes available at the send
 * instant by the queue's own clock and the application's clock decides only whether there is still
 * a reminder to send, here against the send instant and in the job against the start; a delay in
 * seconds would have been the application's now added to the queue's now, wrong by whatever the
 * two differ by, which in a test that pins the Clock is the whole of the pinning.
 *
 * **Nothing here dequeues the reminder a move supersedes.** The job re-reads before it sends
 * and skips on a state it was not queued for, SendReminder, and that is the whole of the
 * cancellation.
 *
 * **It runs after the commit and it does not throw**, and the other listeners on the same
 * record run whether this one worked or not. Both promises are QueuedMessages', and everything
 * that can fail here, the reads included, happens inside its guard.
 */
final readonly class RemindTheCustomer
{
    public function __construct(
        private QueuedMessages $messages,
        private CurrentTenant $current,
        private SettingsReader $settings,
        private BookingReader $bookings,
        private Clock $clock,
    ) {}

    public function handle(BookingConfirmed|BookingApproved|BookingRescheduled $announced): void
    {
        [$status, $start] = $announced instanceof BookingRescheduled
            ? [$announced->status, $announced->movedTo]
            : [$announced->to, null];

        if ($status !== BookingStatus::Confirmed) {
            return;
        }

        $this->messages->hand(
            SendReminder::class,
            $announced->uuid,
            function (int $tenantId) use ($announced, $start): void {
                // The row behind the id the guard hands in: the publisher's binding.
                $tenant = $this->current->get();
                $lead = $this->settings->read($tenant)->reminderLeadHours;

                if ($lead === 0) {
                    return;
                }

                $start ??= $this->bookings->snapshot($tenant, $announced->uuid)?->startsAt();
                // Zero is the reader's answer for a uuid no booking has, and a booking has at
                // least one item: a row gone since the commit is nothing to remind.
                $revision = $this->bookings->revision($tenant, $announced->uuid);

                if ($start === null || $revision === 0) {
                    return;
                }

                $sendAt = ReminderTime::of($start, $lead, $this->clock->now());

                if ($sendAt === null) {
                    return;
                }

                SendReminder::dispatch($tenantId, $announced->uuid, SendReminder::instant($start), $revision)->delay($sendAt);
            },
        );
    }
}
