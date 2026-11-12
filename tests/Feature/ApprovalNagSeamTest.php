<?php

use App\Application\Clock;
use App\Application\Dto\HoldWindow;
use App\Application\Event\BookingHeld;
use App\Application\Notification\MessageKey;
use App\Domain\Audit\Actor;
use App\Domain\Enum\BookingStatus;
use App\Domain\Enum\HoldClass;
use App\Infrastructure\BookingTransitionFactory;
use App\Infrastructure\Db\BookingReader;
use App\Infrastructure\Db\SqlText;
use App\Infrastructure\Events\TransitionPublishers;
use App\Infrastructure\ExpireHoldsFactory;
use App\Jobs\SendAcknowledgement;
use App\Jobs\SendApprovalNag;
use App\Jobs\SendApprovalRequest;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Testing\Fakes\QueueFake;
use Tests\Support\FixedClock;
use Tests\Support\LunaFixture;
use Tests\Support\SeamLog;
use Tests\TestCase;

pest()->use(DatabaseTruncation::class);

/*
 * The three nags about a request nobody has decided, over luna's real rows: when they are
 * queued and for when, what each finds when it fires, and what it sends to whom.
 *
 * Not wrapped in a transaction: the hold and the transitions enter the transaction runner,
 * which refuses to open inside another.
 *
 * Which clock a test here trusts, and why. The window a request waits in is written by the
 * database: the insert sets hold_expires_at from now() and created_at defaults to the same now(),
 * so the three nag instants, quarters of that window by elapsed time, are the database's instants
 * and no clock of the application is in them. The file therefore pins no Clock and places its
 * requests relative to today, the way ApprovalRequestSeamTest and DecisionPagesTest do, and reads
 * the window back off the row to check the instants against. When a nag fires, whether there is
 * still a request to nag about is the database's answer as well, the status and
 * BookingReader::holdIsLive; the one thing the job asks the Clock port is how long is left, for
 * the words, and the tests that read those words pin the Clock at the nag's own instant so that
 * what is read is what a nag fired on time says.
 *
 * The seam helpers, seamHold, seamHeld, seamSent, seamOnly, seamAddresses, seamSwitchOff,
 * seamEveryKeyBut, addTenantUser, seamTextAt and seamLinksIn, live in tests/Pest.php, and SeamLog
 * under tests/Support. Shared on purpose rather than copied, so two notification tests cannot
 * acquire two ideas of what a sent message is, and this file runs alone.
 *
 * luna is in Europe/Athens. Bridal trial is Elena's two-hour service that requires approval and
 * she works Tuesdays 09:00 to 13:00, so a request for 09:00 on the Tuesday of the first whole
 * availability window lands awaiting_approval on an approval hold with luna's default window of
 * forty-eight hours, which is nagged twelve, twenty-four and thirty-six hours in.
 */

const NAG_CUSTOMER = 'perpetua.vandersnoot@example.test';

const NAG_OWNER = 'owner@luna.example';

beforeEach(function () {
    dropDemoSchemas();
    seedDemo();
    seamTransport()->flush();
});

afterEach(function () {
    dropDemoSchemas();
    DB::table('users')->delete();
    DB::table('tenants')->delete();
});

/**
 * A request lodged with the business, taken the way the booking page takes one, on the Tuesday
 * at the Athens hour given.
 *
 * @return array{uuid: string, token: string}
 */
function nagRequested(TestCase $test, int $hour = 9): array
{
    [$url, $body] = seamHold('Bridal trial', firstWindowDay(2)->setTime($hour, 0)->format('Y-m-d H:i'));

    return seamHeld($test->postJson($url, $body));
}

/**
 * The request's window as the row holds it, read back from the server: the instant the hold
 * was written and the instant it runs out, both named UTC on the way in and read in UTC.
 *
 * @return array{from: DateTimeImmutable, until: DateTimeImmutable}
 */
function nagWindowOf(string $uuid): array
{
    $row = DB::selectOne(
        'select created_at::text as created_at, hold_expires_at::text as hold_expires_at from tenant_luna.bookings where uuid = ?::uuid',
        [$uuid],
    );

    assert($row instanceof stdClass && is_string($row->created_at) && is_string($row->hold_expires_at));

    $utc = new DateTimeZone('UTC');

    return [
        'from' => (new DateTimeImmutable($row->created_at, $utc))->setTimezone($utc),
        'until' => (new DateTimeImmutable($row->hold_expires_at, $utc))->setTimezone($utc),
    ];
}

/**
 * The queue fake a test put in place, which is where a queued nag can be read back.
 */
function nagQueue(): QueueFake
{
    $queue = Queue::getFacadeRoot();

    assert($queue instanceof QueueFake);

    return $queue;
}

/**
 * Every nag the queue fake took, in the order they were queued.
 *
 * @return list<SendApprovalNag>
 */
function nagQueued(): array
{
    $jobs = [];

    foreach (nagQueue()->pushed(SendApprovalNag::class) as $job) {
        assert($job instanceof SendApprovalNag);

        $jobs[] = $job;
    }

    return $jobs;
}

/**
 * The instant a queued nag is delayed to. The delay is asserted to be an instant and not a
 * number of seconds, because seconds would be counted from whichever clock the queue reads and
 * an instant is the same on every clock.
 */
function nagSendAt(SendApprovalNag $job): DateTimeImmutable
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
function nagRun(SendApprovalNag $job): void
{
    app(Dispatcher::class)->dispatchNow($job);
}

/**
 * The request's window closed, with the sweeper not yet past it: written by the database's own
 * clock, because that is the clock that decides whether a hold is still live and the only one
 * whose answer the job reads for it.
 */
function nagWindowClosed(string $uuid): void
{
    DB::update(
        "update tenant_luna.bookings set hold_expires_at = now() - interval '1 minute' where uuid = ?::uuid",
        [$uuid],
    );
}

it('queues three nags on an approval hold, delayed to a quarter, a half and three quarters of the way through the window as elapsed time from the hold, each carrying the expiry it was queued against', function () {
    Queue::fake();

    $held = nagRequested($this);
    $window = nagWindowOf($held['uuid']);
    $jobs = nagQueued();

    // The window is the database's: created_at and hold_expires_at from one now(), luna's
    // default forty-eight hours apart. Nothing here is measured against a clock of the test's.
    expect($jobs)->toHaveCount(3)
        ->and($window['until']->getTimestamp() - $window['from']->getTimestamp())->toBe(48 * 3600)
        ->and(LunaFixture::row($held['uuid']))->toMatchArray(['status' => 'awaiting_approval', 'hold_class' => 'approval']);

    foreach ($jobs as $quarter => $job) {
        $sendAt = nagSendAt($job);
        $hoursIn = ($quarter + 1) * 12;

        expect($job->tenantId)->toBe(LunaFixture::tenant()->id)
            ->and($job->uuid)->toBe($held['uuid'])
            // The expiry it was queued against, spelled as the wire spells an instant, so the
            // job can hold the row's to it when it fires.
            ->and($job->expiresAt)->toBe(SqlText::instant($window['until']))
            // The delay as an interval from the hold, and again as one to the expiry: twelve,
            // twenty-four and thirty-six hours in, thirty-six, twenty-four and twelve to go.
            ->and($window['from']->diff($sendAt)->format('%R %a %H:%I:%S'))->toBe(sprintf('+ %d %02d:00:00', intdiv($hoursIn, 24), $hoursIn % 24))
            ->and($sendAt->getTimestamp() - $window['from']->getTimestamp())->toBe($hoursIn * 3600)
            ->and($window['until']->getTimestamp() - $sendAt->getTimestamp())->toBe((48 - $hoursIn) * 3600);
    }

    // The hold's two messages went the way they always do; the nags are beside them and not
    // instead of them.
    Queue::assertPushed(SendAcknowledgement::class, 1);
    Queue::assertPushed(SendApprovalRequest::class, 1);
});

it('queues no nag on a checkout hold, because there is nobody to nag about a customer still in the flow', function () {
    Queue::fake();

    [$url, $body] = seamHold('Haircut', firstWindowDay(2)->setTime(10, 0)->format('Y-m-d H:i'));
    seamHeld($this->postJson($url, $body));

    Queue::assertNotPushed(SendApprovalNag::class);
});

it('queues no nag for a held record announced after the request was settled, because the window it would schedule against is gone', function () {
    $held = nagRequested($this);
    app(BookingTransitionFactory::class)->approve(LunaFixture::tenant())->approve($held['uuid'], Actor::user(tenantUserId(NAG_OWNER)));

    Queue::fake();

    // The record announced once more, after the approval has committed, the way
    // ConfirmationSeamTest announces one: the listener re-reads the row for its window and
    // finds no approval hold to measure.
    app(TransitionPublishers::class)
        ->for(LunaFixture::tenant())
        ->publish(new BookingHeld($held['uuid'], null, BookingStatus::AwaitingApproval, HoldClass::Approval, Actor::customer(), $held['token']));

    Queue::assertNotPushed(SendApprovalNag::class);
});

it('reads the window of an approval hold as the insert wrote it, and nothing for a checkout hold, a settled request or a uuid nobody has', function () {
    $reader = app(BookingReader::class);
    $tenant = LunaFixture::tenant();
    $held = nagRequested($this);
    $row = nagWindowOf($held['uuid']);

    $window = $reader->approvalWindow($tenant, $held['uuid']);

    expect($window)->toBeInstanceOf(HoldWindow::class)
        ->and($window === null ? null : SqlText::instant($window->from))->toBe(SqlText::instant($row['from']))
        ->and($window === null ? null : SqlText::instant($window->until))->toBe(SqlText::instant($row['until']));

    [$url, $body] = seamHold('Haircut', firstWindowDay(2)->setTime(12, 0)->format('Y-m-d H:i'));
    $checkout = seamHeld($this->postJson($url, $body));
    app(BookingTransitionFactory::class)->approve($tenant)->approve($held['uuid'], Actor::user(tenantUserId(NAG_OWNER)));

    expect($reader->approvalWindow($tenant, $checkout['uuid']))->toBeNull()
        ->and($reader->approvalWindow($tenant, $held['uuid']))->toBeNull()
        ->and($reader->approvalWindow($tenant, '7b7f0e5a-0000-4000-8000-000000000009'))->toBeNull()
        ->and($reader->approvalWindow($tenant, 'not-a-uuid'))->toBeNull();
});

it('sends the business one nag per recipient when it fires with the request still waiting, keyed approval_nag, each user copy carrying fresh links that verify for that user and expire with the hold, and saying how long is left', function () {
    // The arrangement luna's demo rows do not have: a second person who runs the diary, and a
    // chair with an address of her own and nobody behind it. The rule itself is proved over its
    // own fixtures in BusinessRecipientsTest; what is on trial here is that each nag reaches it.
    $manager = addTenantUser('marina@luna.example', 'manager');
    DB::table('tenant_luna.staff')->where('name', 'Elena Papadaki')->update(['email' => 'chair@luna.example']);
    $owner = tenantUserId(NAG_OWNER);

    Queue::fake();
    $held = nagRequested($this);
    $window = nagWindowOf($held['uuid']);
    $jobs = nagQueued();

    // Everything in the catalog but this key is off, so a message that still goes carries this
    // key and nothing else could have sent it.
    seamSwitchOff(seamEveryKeyBut(MessageKey::ApprovalNag));

    foreach ($jobs as $quarter => $job) {
        seamTransport()->flush();

        // The Clock at the nag's own instant, so what is read is what a nag fired on time says
        // about the time left; whether there is anything left at all is the database's answer
        // and this pin does not reach it. See the file's note on clocks.
        app()->instance(Clock::class, new FixedClock(nagSendAt($job)));

        nagRun($job);

        $hoursLeft = 36 - $quarter * 12;

        expect(seamAddressSet())->toBe(['chair@luna.example', 'marina@luna.example', NAG_OWNER]);

        foreach ([NAG_OWNER => $owner, 'marina@luna.example' => $manager] as $address => $user) {
            $text = seamTextAt($address);
            $links = seamLinksIn($text);

            expect($links)->toHaveCount(2)
                ->and($links[0])->toContain('/decide/'.$held['uuid'].'/approve')
                ->and($links[1])->toContain('/decide/'.$held['uuid'].'/reject')
                // One link per user: the decision has to land as somebody.
                ->and($links[0])->toContain('user='.$user)
                ->and($links[1])->toContain('user='.$user)
                // And each expires with the hold: the link's lifetime is the row's expiry, to
                // the second.
                ->and($links[0])->toContain('expires='.$window['until']->getTimestamp())
                ->and($links[1])->toContain('expires='.$window['until']->getTimestamp())
                ->and($text)->toStartWith(businessMailLine('business-mail.approval_nag.with_links', ['business' => 'Luna Hair Studio'])."\n\n")
                ->and($text)->toContain(businessMailChoice('business-mail.approval_nag.runs_out', $hoursLeft, ['end' => lunaInstant($window['until'])]))
                ->and($text)->toContain(decisionLine('decision.facts.item', ['service' => 'Bridal trial', 'staff' => 'Elena Papadaki']))
                ->and($text)->toContain('Perpetua Vandersnoot')
                ->and(substr_count($text, 'Europe/Athens'))->toBe(1);

            // They verify: each opens the page that asks once more.
            foreach ($links as $link) {
                $this->get($link)->assertOk();
            }

            expect($this->get($links[0])->getContent())->toContain(decisionLine('decision.decisions.approve.verb'))
                ->and($this->get($links[1])->getContent())->toContain(decisionLine('decision.decisions.reject.verb'));
        }

        $chair = seamTextAt('chair@luna.example');

        // A mailbox with nobody behind it: no link, no way to reach the customer, and the
        // sentence that is true of it.
        expect(seamLinksIn($chair))->toBe([])
            ->and($chair)->toStartWith(businessMailLine('business-mail.approval_nag.without_links', ['business' => 'Luna Hair Studio'])."\n\n")
            ->and($chair)->toContain(businessMailLine('business-mail.links.none'))
            ->and($chair)->not->toContain(NAG_CUSTOMER)
            ->and($chair)->toContain(businessMailChoice('business-mail.approval_nag.runs_out', $hoursLeft, ['end' => lunaInstant($window['until'])]));
    }

    // Three nags decided nothing.
    expect(LunaFixture::row($held['uuid']))->toMatchArray(['status' => 'awaiting_approval', 'hold_class' => 'approval'])
        ->and(array_map(fn (array $row): mixed => $row['action'], LunaFixture::trail()))->toBe(['held']);
});

it('sends nothing and logs nothing when it fires after the request was {act}, because each nag finds out for itself whether there is still anything to nag about', function (string $act, string $status) {
    Queue::fake();
    $held = nagRequested($this);
    $jobs = nagQueued();
    $tenant = LunaFixture::tenant();

    match ($act) {
        'approved' => app(BookingTransitionFactory::class)->approve($tenant)->approve($held['uuid'], Actor::user(tenantUserId(NAG_OWNER))),
        'rejected' => app(BookingTransitionFactory::class)->reject($tenant)->reject($held['uuid'], Actor::user(tenantUserId(NAG_OWNER)), null),
        'cancelled' => app(BookingTransitionFactory::class)->cancel($tenant)->cancel($held['uuid'], Actor::user(tenantUserId(NAG_OWNER))),
        'expired' => (function () use ($tenant, $held): void {
            // The window closed by the database's own clock, which is the clock the sweep
            // selects on, and then the sweep, which is what settles a lapsed request.
            nagWindowClosed($held['uuid']);
            app(ExpireHoldsFactory::class)->for($tenant)->sweep();
        })(),
        default => throw new LogicException(sprintf('There is no act called "%s" here.', $act)),
    };

    $log = new SeamLog;
    Log::swap($log);

    // All three, in the order they would fire: a request decided after the first nag gets no
    // second and no third, and here none at all.
    foreach ($jobs as $job) {
        nagRun($job);
    }

    expect(LunaFixture::row($held['uuid']))->toMatchArray(['status' => $status, 'hold_class' => null, 'hold_expires_at' => null])
        ->and(seamSent())->toBe([])
        ->and($log->records)->toBe([]);
})->with([
    'approved' => ['approved', 'confirmed'],
    'rejected' => ['rejected', 'rejected'],
    'cancelled' => ['cancelled', 'cancelled'],
    'expired' => ['expired', 'expired'],
]);

it('sends the first nag with the request still waiting, and nothing for the second and the third once the owner decided between them, because each nag finds out for itself', function () {
    Queue::fake();
    $held = nagRequested($this);
    $jobs = nagQueued();
    $tenant = LunaFixture::tenant();

    seamSwitchOff(seamEveryKeyBut(MessageKey::ApprovalNag));
    app()->instance(Clock::class, new FixedClock(nagSendAt($jobs[0])));

    nagRun($jobs[0]);

    // The first nag went, about a request still waiting.
    expect(seamAddressSet())->toBe([NAG_OWNER])
        ->and(seamTextAt(NAG_OWNER))->toStartWith(businessMailLine('business-mail.approval_nag.with_links', ['business' => 'Luna Hair Studio'])."\n\n");

    // Decided after the first nag and before the second: the three are independent, and
    // nothing dequeues the two still waiting. The approval's own messages go to the queue fake
    // and are not this test's subject.
    app(BookingTransitionFactory::class)->approve($tenant)->approve($held['uuid'], Actor::user(tenantUserId(NAG_OWNER)));
    seamTransport()->flush();

    $log = new SeamLog;
    Log::swap($log);

    foreach ([$jobs[1], $jobs[2]] as $job) {
        app()->instance(Clock::class, new FixedClock(nagSendAt($job)));
        nagRun($job);
    }

    expect(LunaFixture::row($held['uuid']))->toMatchArray(['status' => 'confirmed', 'hold_class' => null, 'hold_expires_at' => null])
        ->and(seamSent())->toBe([])
        ->and($log->records)->toBe([]);
});

it('sends nothing when the window has closed by the database clock and the sweeper has not yet reached the request, because its own links would open a page that refuses them', function () {
    Queue::fake();
    $held = nagRequested($this);
    $tenant = LunaFixture::tenant();

    nagWindowClosed($held['uuid']);

    // The job built against the window as it now stands, so that what stops it is liveness alone
    // and not the expiry having changed: the window binds on both surfaces (ApproveBooking says
    // so), and a nag on a request the database says has closed is a nag about a request its own
    // links would refuse.
    $job = new SendApprovalNag($tenant->id, $held['uuid'], SqlText::instant(nagWindowOf($held['uuid'])['until']));

    $log = new SeamLog;
    Log::swap($log);

    nagRun($job);

    expect(LunaFixture::row($held['uuid']))->toMatchArray(['status' => 'awaiting_approval', 'hold_class' => 'approval'])
        ->and(app(BookingReader::class)->holdIsLive($tenant, $held['uuid']))->toBeFalse()
        ->and(seamSent())->toBe([])
        ->and($log->records)->toBe([]);
});

it('sends nothing when the expiry it re-reads is not the one it was queued against, although the request still waits on a live window, because the nags timed for that window are not these', function () {
    Queue::fake();
    $held = nagRequested($this);
    $jobs = nagQueued();
    $tenant = LunaFixture::tenant();

    // A window somebody lengthened by hand. Nothing in this build rewrites an open request's
    // expiry, RescheduleBooking keeps it, so the row is written straight, the way
    // DecisionPagesTest closes one; what is pinned is the rule for whatever does rewrite it.
    DB::update(
        "update tenant_luna.bookings set hold_expires_at = hold_expires_at + interval '1 hour' where uuid = ?::uuid",
        [$held['uuid']],
    );

    $log = new SeamLog;
    Log::swap($log);

    foreach ($jobs as $job) {
        nagRun($job);
    }

    expect(LunaFixture::row($held['uuid']))->toMatchArray(['status' => 'awaiting_approval', 'hold_class' => 'approval'])
        ->and(app(BookingReader::class)->holdIsLive($tenant, $held['uuid']))->toBeTrue()
        ->and(seamSent())->toBe([])
        ->and($log->records)->toBe([]);
});

it('still sends after a move of the request, naming the times it now holds, because a move keeps the expiry it was queued against', function () {
    Queue::fake();
    $held = nagRequested($this);
    $before = nagWindowOf($held['uuid']);
    $jobs = nagQueued();
    $day = firstWindowDay(2);

    // The customer moves the request two hours on, inside Elena's Tuesday morning. The status,
    // the class and the expiry are left exactly as the lock found them, RescheduleBooking, so
    // the request is still in front of the owner at its new time, on the same window.
    app(BookingTransitionFactory::class)->reschedule(LunaFixture::tenant())->reschedule($held['uuid'], Actor::customer(), $day->setTime(11, 0));

    $after = nagWindowOf($held['uuid']);

    seamSwitchOff(seamEveryKeyBut(MessageKey::ApprovalNag));
    app()->instance(Clock::class, new FixedClock(nagSendAt($jobs[0])));

    nagRun($jobs[0]);

    $text = seamTextAt(NAG_OWNER);
    $links = seamLinksIn($text);

    expect(SqlText::instant($after['until']))->toBe(SqlText::instant($before['until']))
        ->and(SqlText::instant($after['from']))->toBe(SqlText::instant($before['from']))
        ->and(LunaFixture::row($held['uuid']))->toMatchArray(['status' => 'awaiting_approval', 'hold_class' => 'approval'])
        // The times are the row's now, read when the nag fires, and not the ones it was
        // queued beside.
        ->and($text)->toContain(lunaInstant($day->setTime(11, 0)))
        ->and($text)->not->toContain(lunaInstant($day->setTime(9, 0)))
        ->and($text)->toContain(businessMailChoice('business-mail.approval_nag.runs_out', 36, ['end' => lunaInstant($after['until'])]))
        ->and($links)->toHaveCount(2)
        ->and($links[0])->toContain('expires='.$before['until']->getTimestamp());

    // The move queued no nags of its own: the three from the hold are the three there are.
    Queue::assertPushed(SendApprovalNag::class, 3);
});

it('sends nothing and does not fail when the tenant has switched the nag off, read when it fires and not when it was queued', function () {
    Queue::fake();
    $held = nagRequested($this);
    $jobs = nagQueued();

    // Off after the nags were queued: the switch is the seam's and is read at send time,
    // so a tenant who stops sending nags stops the ones already waiting.
    seamSwitchOff(['approval_nag']);

    $log = new SeamLog;
    Log::swap($log);

    foreach ($jobs as $job) {
        nagRun($job);
    }

    expect(seamSent())->toBe([])
        ->and($log->errors())->toBe([])
        ->and(LunaFixture::row($held['uuid'])['status'])->toBe('awaiting_approval');
});

it('sends nothing at all for a booking that is no longer there, because a re-read that finds nothing has nothing to say', function () {
    Queue::fake();
    $held = nagRequested($this);
    $jobs = nagQueued();

    DB::table('tenant_luna.bookings')->where('uuid', $held['uuid'])->delete();

    $log = new SeamLog;
    Log::swap($log);

    foreach ($jobs as $job) {
        nagRun($job);
    }

    expect(seamSent())->toBe([])
        ->and($log->records)->toBe([]);
});

it('waits on the database queue as three rows available at the quarters of the window, carrying the tenant, the booking and the expiry and nobody, and keeps the links it mints out of every log line', function () {
    config(['queue.default' => 'database']);
    $log = new SeamLog;
    Log::swap($log);

    $held = nagRequested($this);
    $window = nagWindowOf($held['uuid']);

    /** @var list<stdClass> $rows */
    $rows = DB::table('jobs')->orderBy('available_at')->get()->filter(function (stdClass $row): bool {
        assert(is_string($row->payload));

        return str_contains($row->payload, class_basename(SendApprovalNag::class));
    })->values()->all();

    expect($rows)->toHaveCount(3)
        ->and(array_map(fn (stdClass $row): mixed => $row->available_at, $rows))->toBe([
            $window['from']->getTimestamp() + 12 * 3600,
            $window['from']->getTimestamp() + 24 * 3600,
            $window['from']->getTimestamp() + 36 * 3600,
        ]);

    foreach ($rows as $row) {
        assert(is_string($row->payload));

        // Queued in the clear, and clear of anything that must not be: the booking's public
        // identifier and its expiry are there, and no person and no credential is.
        expect($row->payload)->toContain($held['uuid'])
            ->and($row->payload)->not->toContain($held['token'])
            ->and($row->payload)->not->toContain(NAG_CUSTOMER)
            ->and($row->payload)->not->toContain('Perpetua')
            ->and($row->payload)->not->toContain('signature=')
            ->and($row->payload)->not->toContain('/decide/');
    }

    // A nag run for real, with the log still swapped: the links it mints reach the message and
    // nothing else, because a signed link is a credential like the token beside it.
    nagRun(new SendApprovalNag(LunaFixture::tenant()->id, $held['uuid'], SqlText::instant($window['until'])));

    expect(seamLinksIn(seamTextAt(NAG_OWNER)))->toHaveCount(2)
        ->and($log->errors())->toBe([])
        ->and($log->text())->not->toContain('signature=')
        ->and($log->text())->not->toContain('/decide/')
        ->and($log->text())->not->toContain($held['token']);
});
