<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Application\Clock;
use App\Application\Notification\Message;
use App\Application\Notification\MessageKey;
use App\Domain\Audit\AuditEntry;
use App\Domain\Enum\BookingStatus;
use App\Infrastructure\Db\BookingReader;
use App\Infrastructure\Db\SettingsReader;
use App\Infrastructure\Db\SqlText;
use App\Infrastructure\Notification\Messengers;
use App\Notifications\BookAgainLine;
use App\Notifications\MyBookingsLine;
use App\Notifications\Reminder;
use App\Notifications\TenantClock;
use App\Tenancy\Languages;
use App\Tenancy\Slug;
use App\Tenancy\Tenant;
use App\Tenancy\TenantUrls;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The reminder, on the queue until the tenant's lead before the appointment: the re-read that
 * decides whether it goes at all, and the words.
 *
 * **It is a job of its own and not a queued listener**, because it fires at a time and not on
 * an event, and its identity is the booking and the state it was queued for: the first start,
 * and the revision of the items as BookingReader::revision numbers them. RemindTheCustomer
 * queues one when a booking lands on confirmed or a confirmed booking moves, where the tenant's
 * lead is above zero and the start is still further off than the lead, delayed to the instant
 * ReminderTime names, and nothing ever dequeues one.
 *
 * **It goes only before the start it was queued for**, and that is asked first, of the instant
 * it carries and the Clock port, before any row is read. A worker down across the start pops
 * the row late, still confirmed and still at that start, and a reminder of an appointment that
 * has begun is not a reminder. The port and not the database's now(), because the reason the
 * hold window is read in SQL, that the sweep and the guards decide there and a page on another
 * clock would contradict them, has no counterpart here: no statement decides anything about a
 * reminder. The question is the one ReminderTime asked when the job was placed, whether the
 * reminder is still ahead of what it reminds of, and one rule asks one clock at both of its
 * ends; in production that port is the machine's clock, the same one the worker popped the row
 * by, and in a test it is whatever the test pins. The boundary is closed: at the start itself
 * nothing goes.
 *
 * **The re-read is the whole of the cancellation mechanism**: the timer is scheduled
 * optimistically and cancelled best-effort, and the re-read is what keeps a reminder off an
 * appointment that is not happening. The transition messages announce what happened and send
 * whatever the re-read finds short of nothing; this one announces nothing and skips on any
 * change. The booking must still be confirmed, so a cancelled, completed or no-show booking is
 * not reminded, and the row must still be the state this job was queued for, its first start
 * and its revision both, so a moved booking's old reminder finds a row it was not queued for
 * and does nothing while the one queued for the state the row holds speaks. Anything else and
 * it does nothing, silently: a skip is the ordinary end of a job queued days ago, and a line
 * per skip would be a line per cancellation.
 *
 * **The revision is what keeps two reminders from reaching one customer for one start.** A
 * confirmed booking moved away and back holds three jobs, and the first and the third were both
 * queued for the start the row holds again; the start cannot tell them apart, and the revision
 * can, because a move deletes and re-inserts the items and leaves the highest id higher than it
 * found it, so the move back left the row at a revision only the third job carries. The start
 * rides beside it and is compared all the same, because the listener reads the two a statement
 * apart, and a start and a revision read across a move that changed the start name no state the
 * row ever held: a job carrying such a pair skips on the start, where the revision alone would
 * let it send about a start it was not timed for.
 *
 * **It is unique on that state while a job for it waits**, which is what closes the narrower
 * case the revision cannot: two listeners reading one state. A confirmation's listener carries
 * no start and reads the row after its commit, and a move committing between that commit and
 * the read leaves it reading the very state the move's own listener queues for, the same
 * start at the same revision; a move that kept its start, which nothing refuses, does the
 * same to a pair read across it. Two jobs for one state would be two reminders, and from
 * inside a listener a late read is indistinguishable from a first one. So the queue tells
 * them apart: ShouldBeUnique, keyed on the tenant, the booking, the revision and the start,
 * takes the first hand-over of a state and drops the second at the dispatch, PendingDispatch,
 * through the cache's lock. The lock lives an hour or until the job runs, whichever is first.
 * An hour is what it has to cover with room to spare, the reads of the listeners of one
 * landing, which is the width of the requests that committed it; the days the job then waits
 * it need not cover, because once a landing's listeners have run nothing can hand that state
 * over again without a move, and a move is a new revision. The identity holds the invariant
 * across the queue's life and the lock holds it across that window, and neither needs the
 * other's reach. It is the cache's lock, cache_locks here, an array in a test, and a store
 * without locks would fail the hand-over inside QueuedMessages' guard, one error line and no
 * reminder, never a booking.
 *
 * **The start rides as UTC text with its offset**, the spelling the trail uses for an instant,
 * and the row's start is spelled the same way before the two are compared, so what is compared
 * is one spelling of two instants and no clock and no zone is in it.
 *
 * **It is queued in the clear, because there is nothing in it that must not be**: the tenant's
 * id, the booking's public identifier, an instant and a number. The plaintext manage token is
 * minted in two moments, and a reminder is neither; the message points at the link the customer
 * already holds, Reminder.
 *
 * **The number of reminder jobs waiting grows with the bookings.** Every confirmed booking
 * whose start is beyond the lead holds one row on the queue until its instant, and every move
 * of one adds a row rather than replacing one. That is not a v1 problem and is deliberately
 * not solved here, as the sweeper's dispatch per tenant per minute was not: a queue holds rows
 * cheaply and the database driver's pop passes over a row until its instant, and a shape that
 * replaced or deleted a row on a move would need a job identity the queue drivers do not
 * share. The unique lock above is the cache's and not the queue's: it drops a hand-over and
 * reaches no row already queued.
 */
final class SendReminder implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * How long the hand-over of one state stays unique, in seconds: the window between a
     * landing's commit and its listeners' reads, with room to spare, and not the days the job
     * waits. The class docblock says why that is enough.
     */
    public const int UNIQUE_FOR_SECONDS = 3600;

    /**
     * @param  string  $queuedFor  the first start this reminder is for, as UTC text with its offset
     * @param  int  $revision  the booking's revision when the reminder was timed, BookingReader::revision
     */
    public function __construct(
        public readonly int $tenantId,
        public readonly string $uuid,
        public readonly string $queuedFor,
        public readonly int $revision,
    ) {}

    /**
     * An instant as this job carries one and as it spells the row's start to compare against
     * what it carries: UTC text with the offset written out, the trail's spelling, never a
     * bound date object. One spelling in one place, so the scheduler and the re-read cannot
     * come to spell one instant two ways.
     */
    public static function instant(DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(new DateTimeZone('UTC'))->format(AuditEntry::INSTANT);
    }

    /**
     * The state this reminder is for, as the lock keys it: the tenant, because the cache is
     * central and a booking's uuid is unique within its schema and only by chance beyond it;
     * the booking; and the two halves of the state. The start is in it as well as the
     * revision because a pair read across a move that changed the start shares the revision
     * with the true state and must not take its lock: the job that will skip would then be
     * the one that waits, and the one that would have sent the one dropped.
     */
    public function uniqueId(): string
    {
        return sprintf('%d:%s:%d:%s', $this->tenantId, $this->uuid, $this->revision, $this->queuedFor);
    }

    public function uniqueFor(): int
    {
        return self::UNIQUE_FOR_SECONDS;
    }

    public function handle(BookingReader $bookings, SettingsReader $settings, Messengers $messengers, Clock $clock, TenantUrls $urls, LoggerInterface $log): void
    {
        try {
            if ($clock->now() >= SqlText::readInstant($this->queuedFor)) {
                return;
            }

            $tenant = JobTenant::find($this->tenantId, self::class, $log);

            if (! $tenant instanceof Tenant) {
                return;
            }

            $booking = $bookings->snapshot($tenant, $this->uuid);

            if ($booking === null || ErasedBooking::endsHere($booking, $tenant, MessageKey::Reminder, $log)) {
                return;
            }

            if ($booking->status !== BookingStatus::Confirmed) {
                return;
            }

            // The state it was queued for, both halves: a row at another start or another
            // revision is a row this reminder is not for.
            if (self::instant($booking->startsAt()) !== $this->queuedFor || $bookings->revision($tenant, $this->uuid) !== $this->revision) {
                return;
            }

            $messenger = $messengers->for($tenant);
            // The tenant's clock, its zone and its formats read off the settings row at the send.
            $tenantClock = TenantClock::of($tenant, $settings->read($tenant));

            $bookAgain = BookAgainLine::for($urls, Slug::of($tenant->slug), $booking);
            $myBookings = MyBookingsLine::for($messenger, $urls, $tenant, Languages::ofBooking($booking->language));

            // Written inside the seam's promise, after its switch.
            $messenger->send(MessageKey::Reminder, $booking->uuid, fn (): Message => Reminder::of($booking, $tenant->name, $tenantClock, $bookAgain, $myBookings));
        } catch (Throwable $failure) {
            // Nothing from below this line leaves the job, for the reason the other message
            // jobs give: the frames under it take the customer's name and address as
            // arguments, and failed_jobs.exception is a stringified trace in a column nothing
            // encrypts. See MessageNotSent, which is made here, at this frame.
            throw MessageNotSent::after($failure, $this->uuid);
        }
    }
}
