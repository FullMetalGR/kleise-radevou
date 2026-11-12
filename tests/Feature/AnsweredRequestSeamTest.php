<?php

use App\Application\Clock;
use App\Application\Event\BookingApproved;
use App\Application\Event\BookingExpired;
use App\Application\Event\BookingRejected;
use App\Application\Event\TransitionEvent;
use App\Application\ExpireHolds;
use App\Application\Notification\MessageKey;
use App\Domain\Audit\Actor;
use App\Domain\Enum\BookingStatus;
use App\Domain\Enum\HoldClass;
use App\Infrastructure\BookingTransitionFactory;
use App\Infrastructure\Events\TransitionPublishers;
use App\Infrastructure\ExpireHoldsFactory;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mime\Part\DataPart;
use Tests\Support\FixedClock;
use Tests\Support\LunaFixture;
use Tests\Support\SeamLog;
use Tests\TestCase;

pest()->use(DatabaseTruncation::class);

/*
 * The three ways a request ends, over luna's real rows, reached every way they can be: through
 * the use cases an owner's decision runs, through the sweep that settles what the clock decided,
 * and through the reap a later booking's transaction performs on the staff-days it locked. The
 * listeners know none of the three apart, and that is the thing being asserted.
 *
 * Not wrapped in a transaction: the hold, the transitions and the sweep all enter the
 * transaction runner, which refuses to open inside another.
 *
 * The seam helpers, seamHold, seamHeld, seamSent, seamOnly, seamAt, seamAddresses, seamSwitchOff
 * and seamEveryKeyBut, live in tests/Pest.php, and SeamLog under tests/Support. They are shared
 * on purpose rather than copied, so that two notification tests cannot acquire two ideas of
 * what a sent message is, and this file runs alone.
 *
 * luna is in Europe/Athens, UTC+2 in November. 2026-11-03 is a Tuesday, when Elena works 09:00
 * to 13:00 and 14:00 to 18:00. Bridal trial is the service that requires approval and Haircut
 * the ordinary pay-on-site one. Each seeded booking takes an hour of its own, so two blocking
 * items never meet on the exclusion constraint. luna's demo rows carry one user, the owner, and
 * its named staff rows have no addresses of their own, so the business recipient rule resolves
 * to the owner alone.
 */

const ANSWER_AT = '2026-11-02 06:00:00';

beforeEach(function () {
    dropDemoSchemas();
    seedDemo();
    app()->instance(Clock::class, FixedClock::at(ANSWER_AT));
    seamTransport()->flush();

    // An approval, by a user or by the sweep, schedules the reminder as well, which under the
    // suite's sync queue runs inside the transition and would be counted here as one more
    // message. It is switched off at the tenant rather than filtered out of every assertion: a
    // lead of zero is how a tenant sends no reminders, and the reminder has its own file,
    // ReminderSeamTest.
    settingsOf('luna')->update(['reminder_lead_hours' => 0]);
});

afterEach(function () {
    dropDemoSchemas();
    DB::table('users')->delete();
    DB::table('tenants')->delete();
});

/**
 * A request lodged with the owner, taken the way the booking page takes one, with the two
 * messages the hold itself sends cleared away: they are not this file's subject.
 *
 * @return array{uuid: string, token: string}
 */
function answerRequested(TestCase $test): array
{
    [$url, $body] = seamHold('Bridal trial');
    $held = seamHeld($test->postJson($url, $body));

    seamTransport()->flush();

    return $held;
}

/**
 * A request of luna whose window has already closed, written straight to the rows with the
 * timeout policy named, on its own hour of the Tuesday. The expiry is the database's own clock,
 * because whether a hold has lapsed is the server's comparison inside the sweep's selection.
 */
function answerLapsedRequest(string $onTimeout, int $hour = 10): string
{
    return LunaFixture::booking(
        'awaiting_approval',
        holdClass: 'approval',
        expiry: 'past',
        start: sprintf('2026-11-03 %02d:00', $hour),
        service: 'Bridal trial',
        requiresApproval: true,
        onApprovalTimeout: $onTimeout,
    );
}

function answerSweep(): void
{
    app(ExpireHoldsFactory::class)->for(LunaFixture::tenant())->sweep();
}

function answerApprove(string $uuid): void
{
    app(BookingTransitionFactory::class)->approve(LunaFixture::tenant())->approve($uuid, Actor::user(7));
}

function answerReject(string $uuid, ?string $reason): void
{
    app(BookingTransitionFactory::class)->reject(LunaFixture::tenant())->reject($uuid, Actor::user(7), $reason);
}

/**
 * The booking moved on again, which is what a job here may find where it re-reads.
 */
function answerCancel(string $uuid): void
{
    app(BookingTransitionFactory::class)->cancel(LunaFixture::tenant())->cancel($uuid, Actor::user(7));
}

/**
 * A transition announced for luna the way a use case announces one, after the commit, which is
 * how a record this file does not drive through its use case is put on trial: a payment hold
 * lapsing, announced as the sweeper announces it over a seeded row (an approval creates such a
 * hold, but this file seeds it rather than approving through a connection), and a record
 * arriving for a booking that has since gone.
 */
function answerPublish(TransitionEvent $event): void
{
    app(TransitionPublishers::class)->for(Tenant::query()->where('slug', 'luna')->sole())->publish($event);
}

/**
 * A request whose window has closed, swept under the policy named.
 */
function answerSweptUnder(string $onTimeout): void
{
    answerLapsedRequest($onTimeout);
    answerSweep();
}

/**
 * The one act each of this file's four messages is produced by, named rather than passed as a
 * closure so that the dataset reads as a list of messages.
 */
function answerAct(string $act, TestCase $test): void
{
    match ($act) {
        'approve' => answerApprove(answerRequested($test)['uuid']),
        'reject' => answerReject(answerRequested($test)['uuid'], null),
        'sweep an auto-approving request' => answerSweptUnder('auto_approve'),
        'sweep an expiring request' => answerSweptUnder('expire'),
        default => throw new LogicException(sprintf('There is no act called "%s" here.', $act)),
    };
}

it('answers the customer when the owner approves, with the calendar file, no token and no link, and tells the business nothing about their own decision', function () {
    $held = answerRequested($this);

    answerApprove($held['uuid']);

    $message = seamAt('perpetua.vandersnoot@example.test');
    $text = (string) $message->getTextBody();
    $attachments = $message->getAttachments();

    expect(seamAddresses())->toBe(['perpetua.vandersnoot@example.test'])
        ->and($message->getSubject())->toContain('Luna Hair Studio')
        ->and($text)->toContain('Bridal trial')
        // 10:00 in Athens is 08:00 in the row. The customer is told the hour the salon means.
        ->and($text)->toContain('3 Nov 2026, 10:00')
        ->and($text)->not->toContain(', 08:00')
        ->and(substr_count($text, 'Europe/Athens'))->toBe(1)
        // The record carries no token, so the message carries no link and says where the one
        // the customer already has is.
        ->and($text)->not->toContain($held['token'])
        ->and($text)->not->toContain('/manage/')
        ->and($text)->toContain(customerMailLine('customer-mail.approved.confirmed.where_link.acknowledged'))
        ->and(array_map(fn (DataPart $part): ?string => $part->getFilename(), $attachments))->toBe(['booking.ics'])
        ->and($attachments[0]->getBody())->toContain('UID:'.$held['uuid'])
        ->and($attachments[0]->getBody())->not->toContain($held['token'])
        ->and(LunaFixture::row($held['uuid'])['status'])->toBe('confirmed');

    // The link the customer was given at hold time is still the one that works, which is what
    // the message is telling them to go back to.
    $this->getJson(tenantUrl('luna', '/api/v1/bookings/'.$held['uuid'].'?token='.$held['token']))
        ->assertOk()
        ->assertJsonPath('uuid', $held['uuid']);
});

it('points a customer whose tenant sends no acknowledgement at the business instead, because the message it would have named was never written', function () {
    // Off before the request is placed, so this customer really is one nobody has written to. The
    // booking app holds their token in memory, never renders it and loses it on a reload, so the
    // business is the only way back to the booking they have. The approval nags are off beside it,
    // because under the sync queue they run inside the hold and the count below is of the request
    // alone.
    seamSwitchOff(['acknowledged', 'approval_nag']);

    [$url, $body] = seamHold('Bridal trial');
    $held = seamHeld($this->postJson($url, $body));

    // One switch silences one audience: the owner's request to decide still went.
    expect(seamAddresses())->toBe(['owner@luna.example']);

    seamTransport()->flush();

    answerApprove($held['uuid']);

    $text = (string) seamAt('perpetua.vandersnoot@example.test')->getTextBody();

    expect(seamAddresses())->toBe(['perpetua.vandersnoot@example.test'])
        ->and($text)->not->toContain(customerMailLine('customer-mail.approved.confirmed.where_link.acknowledged'))
        ->and($text)->toContain(customerMailLine('customer-mail.approved.confirmed.where_link.unacknowledged', ['business' => 'Luna Hair Studio']))
        // Everything else is the message it always was, the file included.
        ->and($text)->toContain('3 Nov 2026, 10:00')
        ->and($text)->not->toContain($held['token'])
        ->and(seamOnly()->getAttachments())->toHaveCount(1);
});

it('answers the customer and tells the business when the sweep grants a request nobody decided', function () {
    $uuid = answerLapsedRequest('auto_approve');

    answerSweep();

    $customer = (string) seamAt('perpetua.vandersnoot@example.test')->getTextBody();
    $business = (string) seamAt('owner@luna.example')->getTextBody();

    expect(seamAddresses())->toBe(['perpetua.vandersnoot@example.test', 'owner@luna.example'])
        ->and(LunaFixture::row($uuid))->toMatchArray(['status' => 'confirmed', 'approved_by' => null, 'blocks' => [true]])
        // The customer reads the same answer they would have read from an owner pressing
        // Approve: the request was granted and the booking is theirs.
        ->and($customer)->toContain('Bridal trial')
        ->and($customer)->toContain('3 Nov 2026, 10:00')
        ->and($customer)->not->toContain(', 08:00')
        ->and(substr_count($customer, 'Europe/Athens'))->toBe(1)
        ->and(seamAt('perpetua.vandersnoot@example.test')->getAttachments())->toHaveCount(1)
        // The business reads the fact they have not seen: nobody answered, and the policy they
        // set on the service answered for them.
        ->and($business)->toContain(businessMailLine('business-mail.auto_approved.confirmed.told'))
        ->and($business)->toContain('Bridal trial')
        ->and($business)->toContain('Perpetua Vandersnoot')
        // The business reads the hour in their own clock too. 10:00 in Athens is 08:00 in the
        // row, and a business told the wrong hour about a booking they have never seen has
        // nothing else to check it against.
        ->and($business)->toContain('3 Nov 2026, 10:00')
        ->and($business)->not->toContain(', 08:00')
        ->and(substr_count($business, 'Europe/Athens'))->toBe(1)
        ->and(seamAt('owner@luna.example')->getAttachments())->toBe([]);
});

it('goes on answering the customer when the business has switched the auto-approval notice off, because one switch never silences two audiences', function () {
    seamSwitchOff(['auto_approved']);
    answerLapsedRequest('auto_approve');

    answerSweep();

    expect(seamAddresses())->toBe(['perpetua.vandersnoot@example.test'])
        ->and(seamOnly()->getAttachments())->toHaveCount(1);
});

it('tells the business their customer has heard nothing where the message telling them is switched off, because the notice is then the only word of it', function () {
    seamSwitchOff(['approved']);
    answerLapsedRequest('auto_approve');

    answerSweep();

    $business = (string) seamAt('owner@luna.example')->getTextBody();

    expect(seamAddresses())->toBe(['owner@luna.example'])
        ->and($business)->not->toContain(businessMailLine('business-mail.auto_approved.confirmed.told'))
        // Named by the words beside its own checkbox in Settings, so the owner reading this
        // knows which switch did it.
        ->and($business)->toContain(businessMailLine('business-mail.auto_approved.confirmed.untold', ['label' => adminLine(MessageKey::Approved->labelKey()), 'business' => 'Luna Hair Studio']));
});

it('tells the business nothing when an owner approves, whatever the sweep would have done with the same request', function () {
    // The policy that would have granted this one is the policy the notice is about, and it is
    // set here to prove the notice turns on who decided rather than on what the service says.
    // The window is still open, because an owner may decide only a request whose window is.
    $uuid = LunaFixture::booking(
        'awaiting_approval',
        holdClass: 'approval',
        expiry: 'future',
        start: '2026-11-03 11:00',
        service: 'Bridal trial',
        requiresApproval: true,
        onApprovalTimeout: 'auto_approve',
    );

    answerApprove($uuid);

    expect(seamAddresses())->toBe(['perpetua.vandersnoot@example.test']);
});

it('answers the customer when the owner declines, without the reason they gave their own staff, and says the same thing when they gave none', function () {
    $reason = 'Perpetua Vandersnoot cancelled twice on perpetua.vandersnoot@example.test and we will not hold the chair again';
    $withReason = answerRequested($this);

    answerReject($withReason['uuid'], $reason);

    $declined = (string) seamAt('perpetua.vandersnoot@example.test')->getTextBody();

    expect(seamAddresses())->toBe(['perpetua.vandersnoot@example.test'])
        ->and(LunaFixture::row($withReason['uuid']))->toMatchArray(['status' => 'rejected', 'rejection_reason' => $reason])
        ->and($declined)->toContain('declined')
        ->and($declined)->toContain('Bridal trial')
        ->and($declined)->toContain('3 Nov 2026, 10:00')
        ->and(substr_count($declined, 'Europe/Athens'))->toBe(1)
        ->and(seamOnly()->getAttachments())->toBe([])
        // The owner's note is on the row, where erasure reaches it, and nowhere in the
        // customer's mail. Not one clause of it, not the word the row and the message could
        // have shared.
        ->and($declined)->not->toContain($reason)
        ->and($declined)->not->toContain('will not hold the chair');

    seamTransport()->flush();

    $withNone = answerRequested($this);

    answerReject($withNone['uuid'], null);

    // The same message either way, byte for byte. Two bodies that differed at all would be a
    // body carrying something of the reason, and a booking neither names is a booking neither
    // could name it about.
    expect((string) seamOnly()->getTextBody())->toBe($declined)
        ->and(LunaFixture::row($withNone['uuid'])['rejection_reason'])->toBeNull();
});

it('answers the customer when the sweep expires a request nobody decided and the policy was to let it go', function () {
    $uuid = answerLapsedRequest('expire');

    answerSweep();

    $text = (string) seamOnly()->getTextBody();

    expect(seamAddresses())->toBe(['perpetua.vandersnoot@example.test'])
        ->and(LunaFixture::row($uuid))->toMatchArray(['status' => 'expired', 'hold_class' => null, 'blocks' => [false]])
        ->and($text)->toContain('expired')
        ->and($text)->toContain('Bridal trial')
        ->and($text)->toContain('3 Nov 2026, 10:00')
        ->and($text)->not->toContain(', 08:00')
        ->and(substr_count($text, 'Europe/Athens'))->toBe(1)
        ->and(seamOnly()->getAttachments())->toBe([]);
});

it('says nothing at all about a checkout the customer walked away from, whether the sweep lets it go or a later booking reaps it', function () {
    $swept = LunaFixture::booking('pending', holdClass: 'checkout', expiry: 'past', start: '2026-11-03 12:00');

    answerSweep();

    expect(seamSent())->toBe([])
        ->and(LunaFixture::row($swept)['status'])->toBe('expired');

    $reaped = LunaFixture::booking('pending', holdClass: 'checkout', expiry: 'past', start: '2026-11-03 10:00');

    // A hold over the hour that one is lapsed on, whose transaction reaps it on the way past:
    // the reap takes the holds whose blocking items overlap the window it locked. The booking
    // it takes is an ordinary pay-on-site one, so nothing it does sends either.
    [$url, $body] = seamHold('Haircut', '2026-11-03 10:00');
    seamHeld($this->postJson($url, $body));

    expect(seamSent())->toBe([])
        ->and(LunaFixture::row($reaped)['status'])->toBe('expired');
});

it('answers the customer when a later booking reaps the request nobody decided, which is the third way an approval hold ends', function () {
    $uuid = answerLapsedRequest('expire');

    // Nothing sweeps here. A hold taken on the same staff member over the same hour reaps what
    // it finds on the staff-days it locked, and publishes the expiry from inside that
    // transaction with the class it let go of and the engine as its actor. The request is owed
    // the same answer it would have been owed by the sweep: the listener knows the two paths
    // apart in no way at all.
    [$url, $body] = seamHold('Haircut', '2026-11-03 10:00');
    seamHeld($this->postJson($url, $body));

    $text = (string) seamOnly()->getTextBody();

    expect(seamAddresses())->toBe(['perpetua.vandersnoot@example.test'])
        ->and(LunaFixture::row($uuid))->toMatchArray(['status' => 'expired', 'hold_class' => null, 'blocks' => [false]])
        ->and($text)->toContain('expired')
        ->and($text)->toContain('Bridal trial')
        ->and($text)->toContain('3 Nov 2026, 10:00')
        ->and($text)->not->toContain(', 08:00')
        ->and(substr_count($text, 'Europe/Athens'))->toBe(1)
        ->and(seamOnly()->getAttachments())->toBe([]);
});

it('tells the customer an approved booking lapsed unpaid in the expiry message\'s unpaid form, under the request_expired key, when a payment hold lapses', function () {
    $uuid = LunaFixture::booking('awaiting_payment', holdClass: 'payment', expiry: 'past', start: '2026-11-03 12:00');

    // The record is announced the way the sweeper announces it after its commit. The listener
    // reads the class off the record, which is the only place it survives the settlement.
    answerPublish(new BookingExpired($uuid, BookingStatus::AwaitingPayment, BookingStatus::Expired, HoldClass::Payment, Actor::system()));

    $sent = seamSent();

    expect($sent)->toHaveCount(1)
        ->and((string) $sent[0]->getSubject())->toBe(customerMailLine('customer-mail.request_expired.unpaid.subject', ['business' => 'Luna Hair Studio']))
        ->and((string) $sent[0]->getTextBody())->toContain(customerMailLine('customer-mail.request_expired.unpaid.expired', ['business' => 'Luna Hair Studio']));
});

it('sends the answer its transition made true even where the booking has moved on before the worker reaches the job', function () {
    $held = answerRequested($this);

    // The cancellation that moves the booking on has two messages of its own, to both
    // audiences. They are switched off at the tenant rather than filtered out of the
    // assertion, so what is counted below is the approval alone; their own file proves they
    // go, and a customer who cancelled is owed both rather than neither.
    seamSwitchOff(['cancelled', 'cancellation_notice']);

    // The queue takes the job instead of running it inside the dispatch, which is the only way
    // to get between the transition and the send. What the job re-reads is then not the booking
    // the listener handed over.
    config(['queue.default' => 'database']);

    answerApprove($held['uuid']);

    expect(seamSent())->toBe([]);

    answerCancel($held['uuid']);

    // No memory ceiling for the worker: its check reads the memory of the whole test process,
    // which a full run grows past the default 128 MB by the time this file runs, and a worker
    // over it stops after the first job and leaves the other due ones queued.
    Artisan::call('queue:work', ['connection' => 'database', '--stop-when-empty' => true, '--memory' => 0, '--no-interaction' => true]);

    $text = (string) seamOnly()->getTextBody();

    // A transition message announces what happened, and what happened is that the request was
    // approved.
    expect(seamAddresses())->toBe(['perpetua.vandersnoot@example.test'])
        ->and($text)->toContain('has been approved')
        ->and($text)->toContain('Bridal trial')
        ->and(LunaFixture::row($held['uuid'])['status'])->toBe('cancelled');
});

it('sends nothing at all for a booking that is no longer there, because a re-read that finds nothing has nothing to say', function () {
    $uuid = answerLapsedRequest('expire');

    DB::table('tenant_luna.bookings')->where('uuid', $uuid)->delete();

    $log = new SeamLog;
    Log::swap($log);

    answerPublish(new BookingApproved($uuid, BookingStatus::AwaitingApproval, BookingStatus::Confirmed, HoldClass::Approval, ExpireHolds::actor()));
    answerPublish(new BookingRejected($uuid, BookingStatus::AwaitingApproval, BookingStatus::Rejected, HoldClass::Approval, Actor::user(7)));
    answerPublish(new BookingExpired($uuid, BookingStatus::AwaitingApproval, BookingStatus::Expired, HoldClass::Approval, Actor::system()));

    expect(seamSent())->toBe([])
        ->and($log->errors())->toBe([]);
});

it('sends each of the four under its own key and under no other, with every other key in the catalog switched off', function (string $act, string $key, string $address) {
    // What a message is keyed is not visible in the mail it becomes, so it is asked of the one
    // thing that reads the key: the switches. Everything in the catalog but this key is off, so
    // a message that still goes carries this key and nothing else could have sent it. The list
    // is built from MessageKey::cases(), so a key added later joins the test by existing.
    seamSwitchOff(seamEveryKeyBut(MessageKey::from($key)));

    answerAct($act, $this);

    expect(seamAddresses())->toBe([$address]);
})->with([
    'the approval to the customer' => ['approve', 'approved', 'perpetua.vandersnoot@example.test'],
    'the rejection to the customer' => ['reject', 'rejected', 'perpetua.vandersnoot@example.test'],
    'the timeout to the customer' => ['sweep an expiring request', 'request_expired', 'perpetua.vandersnoot@example.test'],
    'the auto-approval to the business' => ['sweep an auto-approving request', 'auto_approved', 'owner@luna.example'],
]);
