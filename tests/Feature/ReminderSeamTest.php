<?php

use App\Application\Clock;
use App\Application\Event\BookingConfirmed;
use App\Application\Notification\MessageKey;
use App\Domain\Audit\Actor;
use App\Domain\Enum\BookingOutcome;
use App\Domain\Enum\BookingStatus;
use App\Domain\Enum\HoldClass;
use App\Infrastructure\BookingTransitionFactory;
use App\Infrastructure\Db\BookingReader;
use App\Infrastructure\Events\TransitionPublishers;
use App\Infrastructure\ExpireHoldsFactory;
use App\Infrastructure\SystemClock;
use App\Jobs\ErasedBooking;
use App\Jobs\SendConfirmation;
use App\Jobs\SendMove;
use App\Jobs\SendReminder;
use App\Jobs\SendRequestApproved;
use App\Jobs\SendRequestExpired;
use App\Jobs\SendRequestRejected;
use App\Retention\Erasure;
use App\Site\SiteLanguage;
use App\Tenancy\Slug;
use App\Tenancy\TenantUrls;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Testing\Fakes\QueueFake;
use Tests\Support\FixedClock;
use Tests\Support\FixtureDay;
use Tests\Support\HaltedClock;
use Tests\Support\LunaFixture;
use Tests\Support\SeamLog;
use Tests\TestCase;

pest()->use(DatabaseTruncation::class);

/*
 * The reminder, scheduled rather than triggered, over luna's real rows: when a reminder is
 * queued and for when, when nothing is, and what the job finds when it fires days later and the
 * booking has or has not moved on.
 *
 * Not wrapped in a transaction: the hold, the confirm and the transitions all enter the
 * transaction runner, which refuses to open inside another.
 *
 * The seam helpers, seamHold, seamHeld, seamConfirm, seamSent, seamOnly, seamAddresses,
 * seamSwitchOff, seamEveryKeyBut and instantAt, live in tests/Pest.php, and SeamLog and
 * HaltedClock under tests/Support. Shared on purpose rather than copied, so two notification
 * tests cannot acquire two ideas of what a sent message is, and this file runs alone.
 *
 * Which clock a test here trusts, and why. The delay a reminder is queued with is an instant,
 * the start less the lead, computed by the application's Clock port from the start the row
 * holds; the queue stores that instant and makes the row available at it by its own clock. So
 * the tests that ask when a reminder was queued pin the Clock and read the delay off the job
 * through the queue fake, where no other clock is in the answer; the one test that lets the
 * database queue store the row runs on the system clock and a start seven days out, because the
 * row's available_at is compared against the real clock by the worker and a pinned Clock would
 * put the row's instant in a November the worker's clock has not reached. The job, when it
 * fires, asks the Clock port one question, whether the start it was queued for is still ahead,
 * and the row the rest: whether to send is then the row's status, its start and its revision.
 * The tests that run a job pin that Clock where the file's beforeEach put it, three days before
 * the start, unless they are asking about the start itself.
 *
 * luna is in Europe/Athens, UTC+2 in November. 2026-11-03 is a Tuesday and 2026-11-04 a
 * Wednesday; Elena works both, 09:00 to 13:00 and 14:00 to 18:00. Haircut is the ordinary
 * pay-on-site service and Bridal trial the one that requires approval. The clock stands three
 * days before the Tuesday's 10:00, so the reminder for it is two days away, and luna's lead is
 * the default, 24 hours.
 */

const REMIND_AT = '2026-10-31 08:00:00';

const REMIND_CUSTOMER = 'perpetua.vandersnoot@example.test';

/** The Tuesday's 10:00 in Athens as the job spells the start it was queued for: 08:00 UTC. */
const REMIND_START = '2026-11-03T08:00:00.000000+00:00';

/** Twenty-four hours before it. */
const REMIND_SEND = '2026-11-02T08:00:00+00:00';

beforeEach(function () {
    dropDemoSchemas();
    seedDemo();
    app()->instance(Clock::class, FixedClock::at(REMIND_AT));
    seamTransport()->flush();
});

afterEach(function () {
    dropDemoSchemas();
    DB::table('users')->delete();
    DB::table('tenants')->delete();
});

/**
 * A confirmed booking taken the way the booking page takes one.
 *
 * @return array{uuid: string, token: string}
 */
function remindConfirmed(TestCase $test, string $service = 'Haircut', string $start = FixtureDay::START): array
{
    [$url, $body] = seamHold($service, $start);
    $held = seamHeld($test->postJson($url, $body));
    seamConfirm($test, $held['uuid'], $held['token']);

    return $held;
}

/**
 * The queue fake a test put in place, which is where a queued reminder can be read back.
 */
function remindQueue(): QueueFake
{
    $queue = Queue::getFacadeRoot();

    assert($queue instanceof QueueFake);

    return $queue;
}

/**
 * Every reminder the queue fake took, in the order they were queued.
 *
 * @return list<SendReminder>
 */
function remindQueued(): array
{
    $jobs = [];

    foreach (remindQueue()->pushed(SendReminder::class) as $job) {
        assert($job instanceof SendReminder);

        $jobs[] = $job;
    }

    return $jobs;
}

function remindOnly(): SendReminder
{
    $jobs = remindQueued();

    expect($jobs)->toHaveCount(1);

    return $jobs[0];
}

/**
 * The instant a queued reminder is delayed to. The delay is asserted to be an instant and not
 * a number of seconds, because seconds would be counted from whichever clock the queue reads
 * and an instant is the same on every clock.
 */
function remindSendAt(SendReminder $job): DateTimeImmutable
{
    expect($job->delay)->toBeInstanceOf(DateTimeImmutable::class);
    assert($job->delay instanceof DateTimeImmutable);

    return $job->delay->setTimezone(new DateTimeZone('UTC'));
}

/**
 * The job run the way a worker runs it, in the process, with whatever the rows hold now.
 * dispatchNow and not dispatchSync: the latter hands a job that should queue back to the
 * queue on its sync connection, and the queue in these tests is the fake, which keeps it.
 */
function remindRun(SendReminder $job): void
{
    app(Dispatcher::class)->dispatchNow($job);
}

it('queues one reminder when the customer confirms, delayed to the instant twenty-four hours before the first start, as elapsed time between two instants', function () {
    Queue::fake();

    $held = remindConfirmed($this);
    $job = remindOnly();
    $sendAt = remindSendAt($job);
    $start = instantAt(REMIND_START);
    $now = instantAt(REMIND_AT);

    expect($job->tenantId)->toBe(LunaFixture::tenant()->id)
        ->and($job->uuid)->toBe($held['uuid'])
        ->and($job->queuedFor)->toBe(REMIND_START)
        // The other half of the state it is for: the items as the reader numbers them.
        ->and($job->revision)->toBe(app(BookingReader::class)->revision(LunaFixture::tenant(), $held['uuid']))
        ->and($sendAt->format('Y-m-d\TH:i:sP'))->toBe(REMIND_SEND)
        // The lead as an interval: exactly a day of elapsed time before the start, and two
        // days of it from the clock the confirm ran on.
        ->and($sendAt->diff($start)->format('%R %a %H:%I:%S'))->toBe('+ 1 00:00:00')
        ->and($start->getTimestamp() - $sendAt->getTimestamp())->toBe(24 * 3600)
        ->and($sendAt->getTimestamp() - $now->getTimestamp())->toBe(48 * 3600)
        ->and(LunaFixture::row($held['uuid'])['status'])->toBe('confirmed');

    // The confirmation itself went the way it always does; the reminder is beside it and not
    // instead of it.
    Queue::assertPushed(SendConfirmation::class, 1);
});

it('queues it twenty-four elapsed hours before the start across the autumn clock change, where a day by the tenant wall clock would be an hour out', function () {
    // The Friday before the change, 09:00 in Athens on summer time. The Tuesday after it is on
    // winter time, so its 10:00 is 08:00 UTC, and the reminder goes at 08:00 UTC on the Monday,
    // 10:00 by the wall clock. From the Friday's 09:00 to the Monday's 10:00 the wall clock
    // counts three days and one hour; the timeline counts three days and two, and the queue
    // counts the timeline.
    app()->instance(Clock::class, FixedClock::at('2026-10-23 06:00:00'));
    Queue::fake();

    remindConfirmed($this, 'Haircut', '2026-10-27 10:00');
    $job = remindOnly();
    $sendAt = remindSendAt($job);
    $start = LunaFixture::at('2026-10-27 10:00');
    $now = instantAt('2026-10-23 06:00:00');
    $athens = new DateTimeZone('Europe/Athens');

    expect($start->format('P'))->toBe('+02:00')
        ->and($now->setTimezone($athens)->format('l j F Y \a\t H:i P'))->toBe('Friday 23 October 2026 at 09:00 +03:00')
        ->and($job->queuedFor)->toBe('2026-10-27T08:00:00.000000+00:00')
        ->and($sendAt->format('Y-m-d\TH:i:sP'))->toBe('2026-10-26T08:00:00+00:00')
        ->and($sendAt->setTimezone($athens)->format('l j F Y \a\t H:i P'))->toBe('Monday 26 October 2026 at 10:00 +02:00')
        ->and($start->getTimestamp() - $sendAt->getTimestamp())->toBe(24 * 3600)
        ->and($sendAt->getTimestamp() - $now->getTimestamp())->toBe(74 * 3600);
});

it('queues nothing with the lead at zero, and the confirmation still goes', function () {
    settingsOf('luna')->update(['reminder_lead_hours' => 0]);
    Queue::fake();

    remindConfirmed($this);

    Queue::assertNotPushed(SendReminder::class);
    Queue::assertPushed(SendConfirmation::class, 1);
});

it('queues nothing for a booking confirmed twenty hours before its start, because a reminder sent on the spot is not a reminder', function () {
    [$url, $body] = seamHold('Haircut');
    $held = seamHeld($this->postJson($url, $body));

    // The hold was taken three days out; the customer sits on it and confirms inside the lead.
    // The hold's own window is the database clock's and is still open.
    app()->instance(Clock::class, FixedClock::at('2026-11-02 12:00:00'));
    Queue::fake();

    seamConfirm($this, $held['uuid'], $held['token']);

    Queue::assertNotPushed(SendReminder::class);
    Queue::assertPushed(SendConfirmation::class, 1);
});

it('sends the customer one message keyed reminder, with the times in the tenant clock and no manage link, Book again its one link, when it runs with the booking still confirmed at the same start', function () {
    Queue::fake();
    $held = remindConfirmed($this);
    $job = remindOnly();

    // Everything in the catalog but this key is off, so a message that still goes carries this
    // key and nothing else could have sent it.
    seamSwitchOff(seamEveryKeyBut(MessageKey::Reminder));

    remindRun($job);

    $message = seamOnly();
    $text = (string) $message->getTextBody();

    expect(seamAddresses())->toBe([REMIND_CUSTOMER])
        ->and($message->getSubject())->toBe(customerMailLine('customer-mail.reminder.subject', ['business' => 'Luna Hair Studio']))
        ->and($text)->toContain(customerMailLine('customer-mail.reminder.reminder', ['business' => 'Luna Hair Studio']))
        ->and($text)->toContain(customerMailLine('customer-mail.reminder.earlier', ['business' => 'Luna Hair Studio']))
        ->and($text)->toContain(decisionLine('decision.facts.item', ['service' => 'Haircut', 'staff' => 'Elena Papadaki']))
        // 10:00 in Athens is 08:00 in the row. The customer is told the hour the salon means.
        ->and($text)->toContain('3 Nov 2026, 10:00')
        ->and($text)->not->toContain(', 08:00')
        ->and(substr_count($text, 'Europe/Athens'))->toBe(1)
        ->and($text)->toContain('35.00 EUR')
        ->and($text)->not->toContain($held['token'])
        ->and($text)->not->toContain('/manage/')
        ->and($text)->not->toContain($held['uuid'])
        // Book again, the hosted page preselecting Haircut on Elena, is the one link, under the
        // prefix of the booking's language: it carries no credential, and the bookings link is off
        // here, so there is no closing line.
        ->and(seamLinksIn($text))->toBe([app(TenantUrls::class)->bookingLink(Slug::of('luna'), ['service' => seamId('services', 'Haircut'), 'staff' => seamId('staff', 'Elena Papadaki')], SiteLanguage::English)])
        ->and($message->getAttachments())->toBe([]);
});

it('sends nothing and logs nothing when it finds the booking {act}, because the re-read is the whole of the cancellation', function (string $act) {
    Queue::fake();
    $held = remindConfirmed($this);
    $job = remindOnly();
    $tenant = LunaFixture::tenant();

    match ($act) {
        'cancelled' => app(BookingTransitionFactory::class)->cancel($tenant)->cancel($held['uuid'], Actor::customer()),
        'moved' => app(BookingTransitionFactory::class)->reschedule($tenant)->reschedule($held['uuid'], Actor::customer(), LunaFixture::at('2026-11-04 15:00')),
        'marked completed' => (function () use ($tenant, $held): void {
            // A booking is marked once it has started, by the clock the guard reads; the
            // clock is then put back to three days before, so that what stops the job below
            // is the status and not the start having passed, which is its own test.
            app()->instance(Clock::class, FixedClock::at('2026-11-03 10:00:00'));
            app(BookingTransitionFactory::class)->markOutcome($tenant)->mark($held['uuid'], BookingOutcome::Completed, Actor::user(tenantUserId()));
            app()->instance(Clock::class, FixedClock::at(REMIND_AT));
        })(),
        default => throw new LogicException(sprintf('There is no act called "%s" here.', $act)),
    };

    $log = new SeamLog;
    Log::swap($log);

    remindRun($job);

    expect(seamSent())->toBe([])
        ->and($log->records)->toBe([]);
})->with(['cancelled', 'moved', 'marked completed']);

it('sends nothing and logs nothing when it fires {when} the start it was queued for, because a reminder of an appointment that has begun is not a reminder', function (string $now) {
    Queue::fake();
    $held = remindConfirmed($this);
    $job = remindOnly();

    // A worker down across the start pops the row late. The booking is still confirmed and
    // still at the start the job carries, and that is not enough: a reminder must not arrive
    // a minute after the confirmation, and that holds after the start as it holds inside the
    // lead. The clock is the Clock port, the file's note on clocks says why, and here it
    // stands at the start itself and then a day past it.
    app()->instance(Clock::class, FixedClock::at($now));

    $log = new SeamLog;
    Log::swap($log);

    remindRun($job);

    expect(seamSent())->toBe([])
        ->and($log->records)->toBe([])
        ->and(LunaFixture::row($held['uuid'])['status'])->toBe('confirmed');
})->with(['at' => '2026-11-03 08:00:00', 'a day after' => '2026-11-04 08:00:00']);

it('still sends one second before the start, because the lead decides when a reminder is placed and the start alone decides when it is too late', function () {
    Queue::fake();
    remindConfirmed($this);
    $job = remindOnly();

    // Inside the lead by a day less a second, which is not the reminder the tenant asked for
    // and is still a reminder: a booking is only refused one at scheduling, ReminderTime, and
    // a job that waited is refused only by the start.
    app()->instance(Clock::class, FixedClock::at('2026-11-03 07:59:59'));

    remindRun($job);

    expect(seamAddresses())->toBe([REMIND_CUSTOMER])
        ->and((string) seamOnly()->getTextBody())->toContain('3 Nov 2026, 10:00');
});

it('queues a second reminder when a confirmed booking moves, for the new start, and running both in {order} sends exactly one, for the new start', function (string $order) {
    Queue::fake();
    $held = remindConfirmed($this);

    app(BookingTransitionFactory::class)->reschedule(LunaFixture::tenant())->reschedule($held['uuid'], Actor::customer(), LunaFixture::at('2026-11-04 15:00'));

    $jobs = remindQueued();

    expect($jobs)->toHaveCount(2)
        ->and($jobs[0]->queuedFor)->toBe(REMIND_START)
        // 15:00 in Athens on the Wednesday is 13:00 UTC, and the fresh reminder is a day before
        // that.
        ->and($jobs[1]->queuedFor)->toBe('2026-11-04T13:00:00.000000+00:00')
        ->and(remindSendAt($jobs[1])->format('Y-m-d\TH:i:sP'))->toBe('2026-11-03T13:00:00+00:00')
        ->and($jobs[1]->uuid)->toBe($held['uuid']);

    // Nothing dequeued the first one. Whichever fires first, the one queued for a start the
    // row no longer holds finds out for itself.
    foreach ($order === 'the order queued' ? $jobs : array_reverse($jobs) as $job) {
        remindRun($job);
    }

    $text = (string) seamOnly()->getTextBody();

    expect(seamAddresses())->toBe([REMIND_CUSTOMER])
        ->and($text)->toContain('4 Nov 2026, 15:00')
        ->and($text)->not->toContain('3 Nov 2026');
})->with(['the order queued', 'the reverse order']);

it('sends exactly one reminder, for the start the row holds, when a confirmed booking is moved away and back and the jobs run in {order}, although two of the three were queued for that start', function (string $order) {
    Queue::fake();
    $held = remindConfirmed($this);
    $tenant = LunaFixture::tenant();

    // Away to the Wednesday and back to the Tuesday's 10:00, which the first move freed. Three
    // jobs wait, and the first and the third were both queued for the start the row holds
    // again; the start alone cannot tell them apart, and two reminders for one start must
    // never go out.
    app(BookingTransitionFactory::class)->reschedule($tenant)->reschedule($held['uuid'], Actor::customer(), LunaFixture::at('2026-11-04 15:00'));
    app(BookingTransitionFactory::class)->reschedule($tenant)->reschedule($held['uuid'], Actor::customer(), LunaFixture::at('2026-11-03 10:00'));

    $jobs = remindQueued();
    $revisions = array_map(fn (SendReminder $job): int => $job->revision, $jobs);

    // What tells the first and the third apart is the revision, which every move raised.
    expect(array_map(fn (SendReminder $job): string => $job->queuedFor, $jobs))->toBe([REMIND_START, '2026-11-04T13:00:00.000000+00:00', REMIND_START])
        ->and($revisions[0] < $revisions[1] && $revisions[1] < $revisions[2])->toBeTrue()
        ->and(LunaFixture::row($held['uuid'])['status'])->toBe('confirmed');

    foreach ($order === 'the order queued' ? $jobs : array_reverse($jobs) as $job) {
        remindRun($job);
    }

    $text = (string) seamOnly()->getTextBody();

    expect(seamAddresses())->toBe([REMIND_CUSTOMER])
        ->and($text)->toContain('3 Nov 2026, 10:00')
        ->and($text)->not->toContain('4 Nov 2026');
})->with(['the order queued', 'the reverse order']);

it('queues one job and not two when a confirmation\'s listener reads the row after a move has committed, the width of the window between a commit and its listener\'s reads', function () {
    Queue::fake();
    $held = remindConfirmed($this);
    $tenant = LunaFixture::tenant();

    app(BookingTransitionFactory::class)->reschedule($tenant)->reschedule($held['uuid'], Actor::customer(), LunaFixture::at('2026-11-04 15:00'));

    // The confirmation's record announced once more, after the move has committed: its
    // listener carries no start and re-reads the row, and what it reads is the state the
    // move's own listener has just queued a job for. That is the window seen from inside the
    // listener, which cannot tell a late read from a first one; the queue can, because a job
    // for that state is already waiting.
    seamAnnounceConfirmed($held['uuid'], HoldClass::Checkout, $held['token']);

    $jobs = remindQueued();

    expect(array_map(fn (SendReminder $job): string => $job->queuedFor, $jobs))->toBe([REMIND_START, '2026-11-04T13:00:00.000000+00:00']);

    foreach ($jobs as $job) {
        remindRun($job);
    }

    $text = (string) seamOnly()->getTextBody();

    expect(seamAddresses())->toBe([REMIND_CUSTOMER])
        ->and($text)->toContain('4 Nov 2026, 15:00');
});

it('queues the reminder on {landing}, the same way a confirm does', function (string $landing) {
    Queue::fake();
    $tenant = LunaFixture::tenant();

    $uuid = match ($landing) {
        'a user approving a request' => (function () use ($tenant): string {
            [$url, $body] = seamHold('Bridal trial');
            $held = seamHeld($this->postJson($url, $body));
            app(BookingTransitionFactory::class)->approve($tenant)->approve($held['uuid'], Actor::user(tenantUserId()));

            return $held['uuid'];
        })(),
        'the sweeper granting a request nobody decided' => (function () use ($tenant): string {
            $uuid = LunaFixture::booking('awaiting_approval', holdClass: 'approval', expiry: 'past', service: 'Bridal trial', requiresApproval: true, onApprovalTimeout: 'auto_approve');
            app(ExpireHoldsFactory::class)->for($tenant)->sweep();

            return $uuid;
        })(),
        default => throw new LogicException(sprintf('There is no landing called "%s" here.', $landing)),
    };

    $job = remindOnly();

    expect(LunaFixture::row($uuid)['status'])->toBe('confirmed')
        ->and($job->uuid)->toBe($uuid)
        ->and($job->queuedFor)->toBe(REMIND_START)
        ->and(remindSendAt($job)->format('Y-m-d\TH:i:sP'))->toBe(REMIND_SEND);

    Queue::assertPushed(SendRequestApproved::class, 1);
})->with(['a user approving a request', 'the sweeper granting a request nobody decided']);

it('queues nothing when a request or a checkout hold moves, when a request is declined, or when one times out, because none of them lands an appointment', function () {
    Queue::fake();
    $tenant = LunaFixture::tenant();

    [$url, $body] = seamHold('Bridal trial');
    $request = seamHeld($this->postJson($url, $body));
    [$url, $body] = seamHold('Haircut', '2026-11-03 12:00');
    $checkout = seamHeld($this->postJson($url, $body));
    [$url, $body] = seamHold('Bridal trial', '2026-11-04 10:00');
    $declined = seamHeld($this->postJson($url, $body));
    $lapsed = LunaFixture::booking('awaiting_approval', holdClass: 'approval', expiry: 'past', start: '2026-11-04 15:00', service: 'Bridal trial', requiresApproval: true, onApprovalTimeout: 'expire');

    app(BookingTransitionFactory::class)->reschedule($tenant)->reschedule($request['uuid'], Actor::customer(), LunaFixture::at('2026-11-03 15:00'));
    app(BookingTransitionFactory::class)->reschedule($tenant)->reschedule($checkout['uuid'], Actor::customer(), LunaFixture::at('2026-11-04 12:00'));
    app(BookingTransitionFactory::class)->reject($tenant)->reject($declined['uuid'], Actor::user(tenantUserId()), null);
    app(ExpireHoldsFactory::class)->for($tenant)->sweep();

    expect(LunaFixture::row($request['uuid']))->toMatchArray(['status' => 'awaiting_approval', 'hold_class' => 'approval'])
        ->and(LunaFixture::row($checkout['uuid']))->toMatchArray(['status' => 'pending', 'hold_class' => 'checkout'])
        ->and(LunaFixture::row($declined['uuid'])['status'])->toBe('rejected')
        ->and(LunaFixture::row($lapsed)['status'])->toBe('expired');

    Queue::assertNotPushed(SendReminder::class);
    // The acts themselves were announced: the transitions' own messages were queued.
    Queue::assertPushed(SendMove::class, 1);
    Queue::assertPushed(SendRequestRejected::class, 1);
    Queue::assertPushed(SendRequestExpired::class, 1);
});

it('sends nothing and does not fail when the tenant has switched the reminder off, read when it fires and not when it was queued', function () {
    Queue::fake();
    $held = remindConfirmed($this);
    $job = remindOnly();

    // Off after the reminder was queued: the switch is the seam's and is read at send time,
    // so a tenant who stops sending reminders stops the ones already waiting.
    seamSwitchOff(['reminder']);

    $log = new SeamLog;
    Log::swap($log);

    remindRun($job);

    expect(seamSent())->toBe([])
        ->and($log->errors())->toBe([])
        ->and(LunaFixture::row($held['uuid'])['status'])->toBe('confirmed');
});

it('sends nothing at all for a booking that is no longer there, because a re-read that finds nothing has nothing to say', function () {
    Queue::fake();
    $held = remindConfirmed($this);
    $job = remindOnly();

    DB::table('tenant_luna.bookings')->where('uuid', $held['uuid'])->delete();

    $log = new SeamLog;
    Log::swap($log);

    remindRun($job);

    expect(seamSent())->toBe([])
        ->and($log->records)->toBe([]);
});

it('sends nothing when it finds the booking\'s customer erased, still confirmed at the start it was queued for, and ends quietly with one line at info naming no address', function () {
    Queue::fake();
    $held = remindConfirmed($this);
    $job = remindOnly();
    $tenant = LunaFixture::tenant();
    $id = DB::table('tenant_luna.bookings')->where('uuid', $held['uuid'])->value('id');
    assert(is_int($id));
    app(Erasure::class)->bookings($tenant, [$id]);

    $log = new SeamLog;
    Log::swap($log);

    remindRun($job);

    expect(seamSent())->toBe([])
        ->and($log->records)->toBe([
            ['level' => 'info', 'message' => ErasedBooking::NOT_SENT, 'context' => ['tenant' => 'luna', 'key' => MessageKey::Reminder->value, 'booking' => $held['uuid']]],
        ])
        ->and($log->text())->not->toContain(REMIND_CUSTOMER)
        ->and(LunaFixture::row($held['uuid'])['status'])->toBe('confirmed');
});

it('schedules nothing and throws nothing when its clock cannot answer, and the business still hears, because one listener is not another', function () {
    [$url, $body] = seamHold('Haircut');
    $held = seamHeld($this->postJson($url, $body));
    seamTransport()->flush();

    $log = new SeamLog;
    Log::swap($log);

    // The confirmation's job reads the clock to stamp its calendar file and the reminder's
    // scheduling reads it to place the reminder; the business's notice reads no clock at all.
    // The record is announced directly, the way ConfirmationSeamTest announces one, so the
    // clock that fails is the listeners' and not the confirm's.
    app()->instance(Clock::class, new HaltedClock);

    app(TransitionPublishers::class)
        ->for(LunaFixture::tenant())
        ->publish(new BookingConfirmed($held['uuid'], BookingStatus::Pending, BookingStatus::Confirmed, HoldClass::Checkout, Actor::customer(), $held['token']));

    $errors = $log->errors();

    expect(seamAddresses())->toBe(['owner@luna.example'])
        ->and(array_map(fn (array $record): mixed => $record['context']['job'] ?? null, $errors))->toBe([SendConfirmation::class, SendReminder::class])
        ->and(array_map(fn (array $record): mixed => $record['message'], $errors))->toBe(['A message could not be queued', 'A message could not be queued'])
        ->and(array_map(fn (array $record): mixed => $record['context']['booking'] ?? null, $errors))->toBe([$held['uuid'], $held['uuid']])
        ->and($log->text())->not->toContain($held['token'])
        ->and($log->text())->not->toContain(REMIND_CUSTOMER);
});

it('lands on the database queue as a row available at the instant the lead names, carrying the tenant, the booking and the start and nobody, which the worker leaves until then', function () {
    // The system clock and a start a week or more out, so the row's instant is ahead of the
    // clock the worker compares it against; see the file's note on clocks.
    app()->instance(Clock::class, new SystemClock);
    config(['queue.default' => 'database']);

    $day = firstWindowDay(2);
    $start = $day->setTime(10, 0);

    [$url, $body] = seamHold('Haircut', $day->format('Y-m-d').' 10:00');
    $held = seamHeld($this->postJson($url, $body));
    seamConfirm($this, $held['uuid'], $held['token']);

    /** @var list<stdClass> $queued */
    $queued = DB::table('jobs')->orderBy('id')->get()->all();

    expect($queued)->toHaveCount(3)
        ->and(seamSent())->toBe([]);

    $reminders = array_values(array_filter($queued, function (stdClass $row): bool {
        assert(is_string($row->payload));

        return str_contains($row->payload, 'SendReminder');
    }));

    expect($reminders)->toHaveCount(1);

    $row = $reminders[0];

    assert(is_string($row->payload));

    expect($row->available_at)->toBe($start->getTimestamp() - 24 * 3600)
        // Queued in the clear, and clear of anything that must not be: the booking's public
        // identifier is there, and no person and no credential is.
        ->and($row->payload)->toContain($held['uuid'])
        ->and($row->payload)->not->toContain($held['token'])
        ->and($row->payload)->not->toContain(REMIND_CUSTOMER)
        ->and($row->payload)->not->toContain('Perpetua');

    // No memory ceiling for the worker: its check reads the memory of the whole test process,
    // which a full run grows past the default 128 MB by the time this file runs, and a worker
    // over it stops after the first job and leaves the second due one queued.
    Artisan::call('queue:work', ['connection' => 'database', '--stop-when-empty' => true, '--memory' => 0, '--no-interaction' => true]);

    // The confirmation and the notice were due and went; the reminder is not due and stays.
    expect(DB::table('jobs')->count())->toBe(1)
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and(seamAddresses())->toBe([REMIND_CUSTOMER, 'owner@luna.example']);
});
