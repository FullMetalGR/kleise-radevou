<?php

use App\Application\Clock;
use App\Application\ConfirmPaidBooking;
use App\Application\Event\BookingApproved;
use App\Application\Event\BookingCancelled;
use App\Application\Event\BookingCompleted;
use App\Application\Event\BookingConfirmed;
use App\Application\Event\BookingExpired;
use App\Application\Event\BookingHeld;
use App\Application\Event\BookingNoShow;
use App\Application\Event\BookingPaidLate;
use App\Application\Event\BookingRejected;
use App\Application\Event\BookingReleased;
use App\Application\Event\BookingRescheduled;
use App\Application\Event\TransitionEvent;
use App\Application\MyBookings\BookingsLinkRequested;
use App\Application\Notification\Audience;
use App\Application\Notification\MessageKey;
use App\Domain\Audit\Actor;
use App\Domain\Enum\BookingOutcome;
use App\Domain\Enum\BookingStatus;
use App\Domain\Enum\Decision;
use App\Domain\Enum\HoldClass;
use App\Infrastructure\BookingTransitionFactory;
use App\Infrastructure\Cache\StaffDayMaskCache;
use App\Infrastructure\Db\BookingReader;
use App\Infrastructure\Events\TransitionPublishers;
use App\Infrastructure\ExpireHoldsFactory;
use App\Infrastructure\SystemClock;
use App\Jobs\MessageNotSent;
use App\Jobs\SendAcknowledgement;
use App\Jobs\SendBookingsLink;
use App\Jobs\SendConfirmation;
use App\Notifications\AcknowledgeApprovalHold;
use App\Notifications\AnnounceAutoApproval;
use App\Notifications\AnnounceCancellation;
use App\Notifications\AnnounceLatePayment;
use App\Notifications\AnnounceMove;
use App\Notifications\AnnounceNewBooking;
use App\Notifications\ApproveToTheCustomer;
use App\Notifications\AskTheBusinessToDecide;
use App\Notifications\CancelToTheCustomer;
use App\Notifications\ConfirmToTheCustomer;
use App\Notifications\ExpireToTheCustomer;
use App\Notifications\MailTheBookingsLink;
use App\Notifications\MoveToTheCustomer;
use App\Notifications\NagTheBusiness;
use App\Notifications\NewBookingNotice;
use App\Notifications\QueuedMessages;
use App\Notifications\RejectToTheCustomer;
use App\Notifications\RemindTheCustomer;
use App\Notifications\ThankTheCustomer;
use App\Tenancy\DecisionLinks;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Tests\Support\FixedClock;
use Tests\Support\LunaFixture;
use Tests\Support\RefusingTransport;
use Tests\Support\SeamLog;

pest()->use(DatabaseTruncation::class);

/*
 * The matrix is every announced record (the transitions, and the late payment that moves
 * nothing) crossed with every hold class that record can carry, driven through the road that
 * announces it with every listener registered, and what each cell asserts is the exact set of
 * messages sent, as pairs of audience and key, and nothing else.
 * Beside it: the structural assertions that make the matrix mean what it says, the transport
 * assertion, and the decision-link assertion, which is the gate's own sentence.
 *
 * **How a sent message's key is read.** No mail carries its key: the seam builds a raw message
 * from the words alone, and the per-key proof every seam suite uses is every other key switched
 * off, which is one transition per key and too slow for a table of this width. The one place a
 * message's key can be read from outside is the seam's own account of a message it could not
 * send, the error line MailedMessages writes with the tenant, the key and the booking, and never
 * the address. So every cell runs under a transport that refuses every send and keeps what it
 * refused, and reads the keys off those lines: one line per message, in the order the seam
 * handed them over, and the refused message beside each line is the rendered mail, whose address
 * is held to the key's audience. Everything the seam does before the transport, the switches,
 * the recipient rule, the re-reads and the words, is the same under this transport as under one
 * that delivers, so the set of lines is the set of messages sent. It is also the transport
 * assertion, cell by cell: the transition commits, its audit row is written, and the log carries
 * one error per message that failed and names no address.
 *
 * **Which messages a cell counts, and why.** Everything the transition's request sends. Two keys
 * of the catalog are scheduled rather than triggered: the reminder, queued on a landing on
 * confirmed for the lead before the start, and the approval nag, queued three times on an
 * approval hold for the quarters of its window. Under the suite's sync queue driver a delayed
 * job runs at its dispatch, SyncQueue::later drops the delay, so with luna's default lead and
 * the nag switched on the scheduled messages go out inside the request that queued them, days
 * early by the clock but exactly as they would go if the booking were still in that state when
 * their instants came. They are counted, because they were sent, and they are held apart from
 * the triggered set: a held approval sends the acknowledgement and the request, and schedules
 * three nags. The scheduled two are pinned in their cells rather than switched off at the
 * tenant, so the matrix says which landings schedule what.
 *
 * **What the actor changes.** The words, and one set: the sweeper granting a request is the one
 * approval the business hears about, auto_approved, so the approved record is crossed with its
 * two actors. Every other pair of actors sends the same keys, and the acts are driven by the
 * actor that reaches them in production: the customer holds, confirms, releases and moves, the
 * owner cancels, decides and marks, and the sweeper settles what lapsed. Some records are
 * announced through the per-tenant publisher, which is the same path a use case takes after its
 * commit and the path this matrix asserts is the only one: those no use case publishes with the
 * class given, a hold with the payment class, a confirmation from an approval, a release under
 * any class but checkout; and one that a use case does publish and this matrix still announces,
 * a confirmation with no hold at all (an admin's create, HoldBooking). A confirmation from a
 * payment hold runs through its two use cases, the paid transition (ConfirmPaidBooking) and the
 * pay hop's settlement on site (ConfirmBooking::settleAtTheHop), since the business hears of the
 * one and not the other and the record's actor is what tells them apart. The expiry of a payment
 * hold is not among the announced: the sweep settles any lapsed hold whatever its class, so that
 * cell runs through the sweeper like the other two expiries.
 *
 * Every cell gets a booking of its own and the tables are cleared between cells: the sweep
 * settles every lapsed hold of the tenant, and a matrix that seeded several bookings at once
 * would have it answering for the others' rows.
 *
 * luna is in Europe/Athens, UTC+2 in November. 2026-11-03 is a Tuesday, when Elena works 09:00
 * to 13:00 and 14:00 to 18:00; the clock stands on the Monday before, outside every window, and
 * an hour after the start for the two outcomes, which may only be marked on a booking that has
 * begun. luna's demo rows carry one user, the owner, and no staff address, so the business is
 * one mailbox and every business key is one message; the recipient rule is
 * BusinessRecipientsTest's.
 */

const NOTIFY_NOW = '2026-11-02 06:00:00';

const NOTIFY_AFTER_START = '2026-11-03 11:00:00';

const NOTIFY_START = '2026-11-03 10:00';

const NOTIFY_TARGET = '2026-11-03 15:00';

const NOTIFY_CUSTOMER = 'perpetua.vandersnoot@example.test';

const NOTIFY_OWNER = 'owner@luna.example';

/**
 * The two keys that are scheduled rather than triggered: queued against a future, and skipping
 * when the re-read finds it did not arrive.
 */
const NOTIFY_SCHEDULED = ['reminder', 'approval_nag'];

/**
 * The three global functions that send mail, which notifyReferencesIn answers as a call of
 * each, "mail()", and reads as a callable where a string names one.
 */
const NOTIFY_MAIL_FUNCTIONS = ['mail', 'mb_send_mail', 'imap_mail'];

beforeEach(function () {
    dropDemoSchemas();
    seedDemo();
    app()->instance(StaffDayMaskCache::class, coldMaskCache());
    app()->instance(Clock::class, FixedClock::at(NOTIFY_NOW));

    // The premise the scheduled cells rest on: a delayed job runs at its dispatch under this
    // driver, so the reminder and the nags go out inside the request that queued them.
    expect(config('queue.default'))->toBe('sync');
});

afterEach(function () {
    dropDemoSchemas();
    DB::table('users')->delete();
    DB::table('tenants')->delete();
});

/**
 * A start for a request placed on the system clock: Athens 09:00 on the Tuesday of the first
 * whole availability window, when Elena works and Bridal trial fits, spelled as seamHold and
 * LunaFixture::hold take a start.
 */
function notifyUpcomingStart(): string
{
    return firstWindowDay(2)->setTime(9, 0)->format('Y-m-d H:i');
}

/**
 * The seam watched from outside: a log that keeps every line, and a transport that refuses
 * every send and keeps what it refused. Bound afresh, so what is read afterwards is the act's
 * and not the arrangement's.
 *
 * @return array{SeamLog, RefusingTransport}
 */
function notifyObserve(): array
{
    $log = new SeamLog;
    $transport = new RefusingTransport;

    Log::swap($log);
    seamMailer()->setSymfonyTransport($transport);

    return [$log, $transport];
}

/**
 * The booking a cell acts on, in the state its row names: a hold taken the way the booking
 * page takes one, or a row written straight for the states reached no other way here.
 */
function notifyArrange(string $arrangement): string
{
    return match ($arrangement) {
        'nothing' => '',
        'a checkout hold' => LunaFixture::hold(['Haircut'], NOTIFY_START),
        'a request' => LunaFixture::hold(['Bridal trial'], NOTIFY_START),
        'a confirmed booking' => LunaFixture::booking('confirmed'),
        'a payment hold' => LunaFixture::booking('awaiting_payment', holdClass: 'payment', expiry: 'future'),
        // An approved online booking waiting for its payment, which the paid transition confirms
        // and the pay hop settles on site where nothing can take the money.
        'an online payment hold' => LunaFixture::booking('awaiting_payment', holdClass: 'payment', expiry: 'future', paymentMode: 'online'),
        'a cancelled booking' => LunaFixture::booking('cancelled', blocks: false),
        'a lapsed checkout hold' => LunaFixture::booking('pending', holdClass: 'checkout', expiry: 'past'),
        'a lapsed payment hold' => LunaFixture::booking('awaiting_payment', holdClass: 'payment', expiry: 'past'),
        'a lapsed request under expire' => LunaFixture::booking('awaiting_approval', holdClass: 'approval', expiry: 'past', service: 'Bridal trial', requiresApproval: true, onApprovalTimeout: 'expire'),
        'a lapsed request under auto_approve' => LunaFixture::booking('awaiting_approval', holdClass: 'approval', expiry: 'past', service: 'Bridal trial', requiresApproval: true, onApprovalTimeout: 'auto_approve'),
        // An online request under a working payment connection, which an approval lands awaiting
        // its payment.
        'an online request under a working connection' => notifyConnected(LunaFixture::hold(['Bridal trial'], NOTIFY_START)),
        'a lapsed online request under auto_approve and a working connection' => notifyConnected(LunaFixture::booking('awaiting_approval', holdClass: 'approval', expiry: 'past', service: 'Bridal trial', paymentMode: 'online', requiresApproval: true, onApprovalTimeout: 'auto_approve')),
        default => throw new LogicException(sprintf('The matrix has no arrangement called "%s".', $arrangement)),
    };
}

/**
 * The booking the arrangement made, with luna's payment connection written working beside it.
 */
function notifyConnected(string $uuid): string
{
    connectPaymentFake();

    return $uuid;
}

/**
 * One act on the booking, through its use case, or through the publisher for a record no use
 * case can publish with that class. Answers the uuid the act was about, which for a hold is
 * the booking it made.
 */
function notifyAct(string $act, string $uuid): string
{
    // An outcome may only be marked on a booking that has begun, so the two outcomes run an
    // hour after the start; the clock is rebound before the factory is built, because the
    // factory takes the clock it is built with.
    if (str_starts_with($act, 'mark ')) {
        app()->instance(Clock::class, FixedClock::at(NOTIFY_AFTER_START));
    }

    $tenant = LunaFixture::tenant();
    $transitions = app(BookingTransitionFactory::class);
    $owner = Actor::user(tenantUserId(NOTIFY_OWNER));
    $target = LunaFixture::at(NOTIFY_TARGET);

    switch ($act) {
        case 'hold on Haircut':
            return LunaFixture::hold(['Haircut'], NOTIFY_START);
        case 'hold on Bridal trial':
            return LunaFixture::hold(['Bridal trial'], NOTIFY_START);
        case 'announce held on a payment hold':
            // From nothing, as HoldBooking builds the record; the three listeners on this
            // record read the class alone.
            notifyPublish(new BookingHeld($uuid, null, BookingStatus::AwaitingPayment, HoldClass::Payment, Actor::customer()));
            break;
        case 'confirm as the customer':
            $transitions->confirm($tenant)->confirm($uuid, Actor::customer());
            break;
        case 'announce confirmed from an approval hold':
            notifyPublish(new BookingConfirmed($uuid, BookingStatus::AwaitingApproval, BookingStatus::Confirmed, HoldClass::Approval, $owner));
            break;
        case 'confirm by the payment':
            $transitions->paid($tenant)->confirm($uuid, 'pi_notify_matrix');
            break;
        case 'settle at the pay hop as the customer':
            $transitions->confirm($tenant)->settleAtTheHop($uuid, null);
            break;
        case 'announce confirmed with no hold':
            notifyPublish(new BookingConfirmed($uuid, null, BookingStatus::Confirmed, null, $owner));
            break;
        case 'approve as the owner':
            $transitions->approve($tenant)->approve($uuid, $owner);
            break;
        case 'reject as the owner':
            $transitions->reject($tenant)->reject($uuid, $owner, null);
            break;
        case 'sweep':
            app(ExpireHoldsFactory::class)->for($tenant)->sweep();
            break;
        case 'cancel as the owner':
            $transitions->cancel($tenant)->cancel($uuid, $owner);
            break;
        case 'release':
            $transitions->release($tenant)->release($uuid);
            break;
        case 'announce released from an approval hold':
            notifyPublish(new BookingReleased($uuid, BookingStatus::AwaitingApproval, BookingStatus::Cancelled, HoldClass::Approval, Actor::customer()));
            break;
        case 'announce released from a payment hold':
            notifyPublish(new BookingReleased($uuid, BookingStatus::AwaitingPayment, BookingStatus::Cancelled, HoldClass::Payment, Actor::customer()));
            break;
        case 'announce released with no hold':
            notifyPublish(new BookingReleased($uuid, BookingStatus::Confirmed, BookingStatus::Cancelled, null, Actor::customer()));
            break;
        case 'announce paid late on a cancelled booking':
            notifyPublish(new BookingPaidLate($uuid, BookingStatus::Cancelled, BookingStatus::Cancelled, null, Actor::job(ConfirmPaidBooking::ACTOR)));
            break;
        case 'move as the customer':
            $transitions->reschedule($tenant)->reschedule($uuid, Actor::customer(), $target);
            break;
        case 'mark completed as the owner':
            $transitions->markOutcome($tenant)->mark($uuid, BookingOutcome::Completed, $owner);
            break;
        case 'mark no_show as the owner':
            $transitions->markOutcome($tenant)->mark($uuid, BookingOutcome::NoShow, $owner);
            break;
        default:
            throw new LogicException(sprintf('The matrix has no act called "%s".', $act));
    }

    return $uuid;
}

/**
 * A record announced for luna the way a use case announces one after its commit: through the
 * per-tenant publisher, which binds the tenant around the dispatch.
 */
function notifyPublish(TransitionEvent $event): void
{
    app(TransitionPublishers::class)->for(LunaFixture::tenant())->publish($event);
}

/**
 * The address of the one mailbox a message to an audience reaches on luna as seeded.
 */
function notifyMailboxOf(Audience $audience): string
{
    return $audience === Audience::Customer ? NOTIFY_CUSTOMER : NOTIFY_OWNER;
}

/**
 * What the seam sent during one act, read off its own lines: every message, as its audience
 * and key, split into the triggered and the scheduled, each list sorted so the cell names a
 * set rather than the order the listeners happen to be registered in.
 *
 * Every error the log holds is a transport refusal about this booking with the seam's four
 * fields and nothing else, so a listener that failed to queue, or a job that failed, shows up
 * here as a line that is not one. The refused mail beside each line is held to the key's
 * audience by the address it was going to, and the log to the rule that it names nobody.
 *
 * @return array{sent: list<string>, scheduled: list<string>}
 */
function notifyObserved(SeamLog $log, RefusingTransport $transport, string $uuid): array
{
    $errors = $log->errors();

    expect(array_map(fn (array $record): string => $record['message'], $errors))
        ->toBe(array_fill(0, count($errors), 'A message could not be sent'))
        ->and($transport->refused)->toHaveCount(count($errors))
        ->and($log->text())->not->toContain(NOTIFY_CUSTOMER)
        ->and($log->text())->not->toContain('Perpetua')
        ->and($log->text())->not->toContain(NOTIFY_OWNER)
        ->and($log->text())->not->toContain('token=')
        ->and($log->text())->not->toContain('signature=');

    $sent = [];
    $scheduled = [];

    foreach ($errors as $position => $record) {
        expect(array_keys($record['context']))->toBe(['tenant', 'key', 'booking', 'exception'])
            ->and($record['context']['tenant'])->toBe('luna')
            ->and($record['context']['booking'])->toBe($uuid)
            ->and($record['context']['exception'])->toBe(TransportException::class);

        $value = $record['context']['key'];

        assert(is_string($value));

        // The catalog is the one list, and a key the catalog does not carry fails here rather
        // than being counted under a name nothing declares.
        expect($value)->toBeIn(array_map(fn (MessageKey $key): string => $key->value, MessageKey::cases()));

        $key = MessageKey::from($value);
        $mail = $transport->refused[$position];

        assert($mail instanceof Email);

        expect(array_map(fn ($to): string => $to->getAddress(), $mail->getTo()))->toBe([notifyMailboxOf($key->audience())]);

        $pair = $key->audience()->value.':'.$key->value;

        if (in_array($key->value, NOTIFY_SCHEDULED, true)) {
            $scheduled[] = $pair;
        } else {
            $sent[] = $pair;
        }
    }

    sort($sent);
    sort($scheduled);

    return ['sent' => $sent, 'scheduled' => $scheduled];
}

/**
 * The table: every announced record crossed with every hold class it can carry, and for the
 * approval the two actors that send different things. Each row is how the cell is reached, what
 * the row and the trail look like afterwards, the triggered set and the scheduled set.
 *
 * @return array<string, array{class-string<TransitionEvent>, string, string, string, list<string>, list<string>, list<string>}>
 */
function notifyMatrix(): array
{
    return [
        // The acknowledgement rule: a customer still in the booking flow hears nothing, a
        // customer waiting on a human is acknowledged and the business is asked, and nagged at
        // the quarters of the window while it waits. None of the three listeners on the record
        // answers a hold with the payment class: the cell pins that it sends and schedules
        // nothing, so the day one does, this row is what fails.
        'BookingHeld, checkout' => [BookingHeld::class, 'nothing', 'hold on Haircut', 'pending', ['held'], [], []],
        'BookingHeld, approval' => [BookingHeld::class, 'nothing', 'hold on Bridal trial', 'awaiting_approval', ['held'], ['business:approval_request', 'customer:acknowledged'], ['business:approval_nag', 'business:approval_nag', 'business:approval_nag']],
        'BookingHeld, payment' => [BookingHeld::class, 'a payment hold', 'announce held on a payment hold', 'awaiting_payment', [], [], []],
        // A confirmation tells the customer whatever it was settled from, and the business only
        // where nobody there was asked: the checkout hold the customer settled themselves. An
        // approval the business decided and the admin's manual booking with no hold at all are
        // theirs already. A payment hold the business approved into is theirs too, but its
        // payment is not: the paid transition tells the business under new_booking that the
        // payment landed, and the pay hop's settlement on site, which moved no money, tells them
        // nothing. Every landing on confirmed schedules the reminder.
        'BookingConfirmed, checkout' => [BookingConfirmed::class, 'a checkout hold', 'confirm as the customer', 'confirmed', ['held', 'confirmed'], ['business:new_booking', 'customer:confirmed'], ['customer:reminder']],
        'BookingConfirmed, approval' => [BookingConfirmed::class, 'a confirmed booking', 'announce confirmed from an approval hold', 'confirmed', [], ['customer:confirmed'], ['customer:reminder']],
        'BookingConfirmed, payment' => [BookingConfirmed::class, 'an online payment hold', 'confirm by the payment', 'confirmed', ['confirmed'], ['business:new_booking', 'customer:confirmed'], ['customer:reminder']],
        'BookingConfirmed, payment, settled at the pay hop' => [BookingConfirmed::class, 'an online payment hold', 'settle at the pay hop as the customer', 'confirmed', ['confirmed'], ['customer:confirmed'], ['customer:reminder']],
        'BookingConfirmed, no hold' => [BookingConfirmed::class, 'a confirmed booking', 'announce confirmed with no hold', 'confirmed', [], ['customer:confirmed'], ['customer:reminder']],
        // The customer's yes, from a person and from the clock: the business hears of the one it
        // never made.
        'BookingApproved, approval, by the owner' => [BookingApproved::class, 'a request', 'approve as the owner', 'confirmed', ['held', 'approved'], ['customer:approved'], ['customer:reminder']],
        'BookingApproved, approval, by the sweeper' => [BookingApproved::class, 'a lapsed request under auto_approve', 'sweep', 'confirmed', ['approved'], ['business:auto_approved', 'customer:approved'], ['customer:reminder']],
        // An approval of an online request under a working connection lands awaiting its payment.
        // The customer is sent the approved message with the payment link, the business hears of
        // the clock's grant in the form that says the booking awaits its payment, and no reminder
        // is queued until the payment confirms it.
        'BookingApproved, approval, by the owner, awaiting payment' => [BookingApproved::class, 'an online request under a working connection', 'approve as the owner', 'awaiting_payment', ['held', 'approved'], ['customer:approved'], []],
        'BookingApproved, approval, by the sweeper, awaiting payment' => [BookingApproved::class, 'a lapsed online request under auto_approve and a working connection', 'sweep', 'awaiting_payment', ['approved'], ['business:auto_approved', 'customer:approved'], []],
        'BookingRejected, approval' => [BookingRejected::class, 'a request', 'reject as the owner', 'rejected', ['held', 'rejected'], ['customer:rejected'], []],
        // The same rule on expiry: an abandoned checkout lapsing is not news, a timed-out request
        // is the customer's answer, and an approved booking whose payment did not arrive in time is
        // told so under the same key, in the expiry message's unpaid form.
        'BookingExpired, checkout' => [BookingExpired::class, 'a lapsed checkout hold', 'sweep', 'expired', ['expired'], [], []],
        'BookingExpired, approval' => [BookingExpired::class, 'a lapsed request under expire', 'sweep', 'expired', ['expired'], ['customer:request_expired'], []],
        'BookingExpired, payment' => [BookingExpired::class, 'a lapsed payment hold', 'sweep', 'expired', ['expired'], ['customer:request_expired'], []],
        // A checkout hold gets no mail on any event; every other cancellation tells both
        // sides.
        'BookingCancelled, checkout' => [BookingCancelled::class, 'a checkout hold', 'cancel as the owner', 'cancelled', ['held', 'cancelled'], [], []],
        'BookingCancelled, approval' => [BookingCancelled::class, 'a request', 'cancel as the owner', 'cancelled', ['held', 'cancelled'], ['business:cancellation_notice', 'customer:cancelled'], []],
        'BookingCancelled, payment' => [BookingCancelled::class, 'a payment hold', 'cancel as the owner', 'cancelled', ['cancelled'], ['business:cancellation_notice', 'customer:cancelled'], []],
        'BookingCancelled, no hold' => [BookingCancelled::class, 'a confirmed booking', 'cancel as the owner', 'cancelled', ['cancelled'], ['business:cancellation_notice', 'customer:cancelled'], []],
        // A released hold sends nothing whatever its class: the release is the record nothing
        // subscribes to. Only the checkout class can be released; the other three are announced
        // so their rows are pinned as well.
        'BookingReleased, checkout' => [BookingReleased::class, 'a checkout hold', 'release', 'cancelled', ['held', 'released'], [], []],
        'BookingReleased, approval' => [BookingReleased::class, 'a cancelled booking', 'announce released from an approval hold', 'cancelled', [], [], []],
        'BookingReleased, payment' => [BookingReleased::class, 'a cancelled booking', 'announce released from a payment hold', 'cancelled', [], [], []],
        'BookingReleased, no hold' => [BookingReleased::class, 'a cancelled booking', 'announce released with no hold', 'cancelled', [], [], []],
        // A move tells both sides for everything but a checkout hold, and only a confirmed
        // booking's move is a new appointment to be reminded of.
        'BookingRescheduled, checkout' => [BookingRescheduled::class, 'a checkout hold', 'move as the customer', 'pending', ['held', 'rescheduled'], [], []],
        'BookingRescheduled, approval' => [BookingRescheduled::class, 'a request', 'move as the customer', 'awaiting_approval', ['held', 'rescheduled'], ['business:move_notice', 'customer:moved'], []],
        'BookingRescheduled, payment' => [BookingRescheduled::class, 'a payment hold', 'move as the customer', 'awaiting_payment', ['rescheduled'], ['business:move_notice', 'customer:moved'], []],
        'BookingRescheduled, no hold' => [BookingRescheduled::class, 'a confirmed booking', 'move as the customer', 'confirmed', ['rescheduled'], ['business:move_notice', 'customer:moved'], ['customer:reminder']],
        // The two outcomes are the owner's bookkeeping about an appointment that is over: a
        // completed one thanks the customer, with the reviews link and Book again, and nothing
        // subscribes to a no-show.
        'BookingCompleted, no hold' => [BookingCompleted::class, 'a confirmed booking', 'mark completed as the owner', 'completed', ['completed'], ['customer:thank_you'], []],
        'BookingNoShow, no hold' => [BookingNoShow::class, 'a confirmed booking', 'mark no_show as the owner', 'no_show', ['no_show'], [], []],
        // A payment that landed for a booking it did not confirm moves nothing and is announced by
        // the payment webhook after it marks the event, through the per-tenant publisher; the
        // owners alone hear that it bought nothing. luna's one user is its owner, so the business's
        // mailbox here is the owner's.
        'BookingPaidLate, no hold' => [BookingPaidLate::class, 'a cancelled booking', 'announce paid late on a cancelled booking', 'cancelled', [], ['business:late_payment'], []],
    ];
}

it('sends, for {record}, exactly the messages the table names and nothing else, with the transition committed and its audit row written under a transport that refuses every send', function (string $record, string $arrangement, string $act, string $status, array $trail, array $sent, array $scheduled) {
    $uuid = notifyArrange($arrangement);
    [$log, $transport] = notifyObserve();
    $published = [];

    // The one record the act announces, and no other: a cell is one booking, so no reap and no
    // sweep announces anything for anybody else, and the set of messages is that record's.
    Event::listen(TransitionEvent::class, function (TransitionEvent $event) use (&$published): void {
        $published[] = $event::class;
    });

    $uuid = notifyAct($act, $uuid);

    expect($published)->toBe([$record])
        ->and(LunaFixture::row($uuid)['status'])->toBe($status)
        ->and(array_map(fn (array $row): mixed => $row['action'], LunaFixture::trail()))->toBe($trail)
        ->and(notifyObserved($log, $transport, $uuid))->toBe(['sent' => $sent, 'scheduled' => $scheduled]);
})->with(fn () => array_map(fn (array $row): array => ['record' => $row[0], 'arrangement' => $row[1], 'act' => $row[2], 'status' => $row[3], 'trail' => $row[4], 'sent' => $row[5], 'scheduled' => $row[6]], notifyMatrix()));

it('sends the approved message with its key switched off while a working payment connection exists, the auto-approval notice saying the customer was sent the link, and holds it back without one', function (string $arrangement, string $act, array $sent, ?string $told) {
    $uuid = notifyArrange($arrangement);
    seamSwitchOff([MessageKey::Approved->value]);
    [$log, $transport] = notifyObserve();

    $uuid = notifyAct($act, $uuid);

    expect(notifyObserved($log, $transport, $uuid)['sent'])->toBe($sent);

    if ($told !== null) {
        expect(notifyWordsOf(notifyBusinessMail($transport)))->toContain(businessMailLine($told, ['label' => adminLine(MessageKey::Approved->labelKey()), 'business' => 'Luna Hair Studio']));
    }
})->with([
    'by the owner, under a working connection' => ['an online request under a working connection', 'approve as the owner', ['customer:approved'], null],
    'by the sweeper, under a working connection' => ['a lapsed online request under auto_approve and a working connection', 'sweep', ['business:auto_approved', 'customer:approved'], 'business-mail.auto_approved.awaiting_payment.told'],
    'by the owner, with no connection' => ['a request', 'approve as the owner', [], null],
    'by the sweeper, with no connection' => ['a lapsed request under auto_approve', 'sweep', ['business:auto_approved'], 'business-mail.auto_approved.confirmed.untold'],
]);

it('tells the business under new_booking that the payment landed on an approved booking it confirmed, and nothing of a settlement on site at the pay hop', function () {
    $uuid = notifyArrange('an online payment hold');
    [$log, $transport] = notifyObserve();

    notifyAct('confirm by the payment', $uuid);
    $mail = notifyBusinessMail($transport);

    expect(notifyObserved($log, $transport, $uuid)['sent'])->toBe(['business:new_booking', 'customer:confirmed'])
        ->and($mail->getSubject())->toBe(businessMailLine(NewBookingNotice::PAID_SUBJECT, ['business' => 'Luna Hair Studio']))
        ->and(notifyWordsOf($mail))->toStartWith(businessMailLine(NewBookingNotice::PAID_OPENING, ['business' => 'Luna Hair Studio'])."\n\n");
});

/**
 * The one refused mail that was going to the business's mailbox, and the assertion that there
 * was exactly one.
 */
function notifyBusinessMail(RefusingTransport $transport): Email
{
    $business = [];

    foreach ($transport->refused as $mail) {
        if ($mail instanceof Email && array_map(fn (Address $to): string => $to->getAddress(), $mail->getTo()) === [NOTIFY_OWNER]) {
            $business[] = $mail;
        }
    }

    expect($business)->toHaveCount(1);

    return $business[0];
}

/**
 * A refused mail's words, its plain-text body.
 */
function notifyWordsOf(Email $mail): string
{
    $body = $mail->getTextBody();
    assert(is_string($body));

    return $body;
}

it('covers every announced record, a transition or the late payment that moves nothing, sends every key of the catalog from some cell, and sends no key the catalog does not carry', function () {
    $records = [];
    $keys = [];

    foreach (notifyMatrix() as $row) {
        $records[$row[0]] = true;

        foreach ([...$row[5], ...$row[6]] as $pair) {
            [$audience, $key] = explode(':', $pair);

            // The audience half of every pair is the catalog's own answer for that key, so a
            // row cannot claim a business key reached the customer.
            expect(MessageKey::from($key)->audience()->value)->toBe($audience);

            $keys[$key] = true;
        }
    }

    $named = array_keys($records);
    sort($named);
    // Every key but the bookings link, which no transition sends: the hosted page's request does,
    // after its row is written, and MyBookingsLinkTest holds it. The registration and the job's
    // shape are held below with the rest.
    $catalog = array_values(array_diff(array_map(fn (MessageKey $key): string => $key->value, MessageKey::cases()), [MessageKey::MyBookingsLink->value]));
    $found = array_keys($keys);
    sort($catalog);
    sort($found);

    expect($named)->toBe(announcedRecords())
        ->and($found)->toBe($catalog);
});

/**
 * Every record under app/Application/Event that carries the marker, sorted: the transitions,
 * and BookingPaidLate, which announces a payment that moved nothing.
 *
 * @return list<class-string>
 */
function announcedRecords(): array
{
    $records = [];

    foreach (scandir(dirname(__DIR__, 2).'/app/Application/Event') ?: [] as $entry) {
        if (! str_ends_with($entry, '.php')) {
            continue;
        }

        /** @var class-string $name */
        $name = 'App\\Application\\Event\\'.substr($entry, 0, -4);

        if (class_exists($name) && in_array(TransitionEvent::class, class_implements($name) ?: [], true)) {
            $records[] = $name;
        }
    }

    sort($records);

    return $records;
}

/*
 * The structural assertions: the registration the matrix ran under, the shape of every
 * listener and every job, and the one path from a transition to a message.
 */

/**
 * The notification listeners, by the record each answers, in the order AppServiceProvider
 * registers them, which is the order the dispatcher runs them on one record: the customer's
 * message first, then the business's, and last the scheduling of what is timed off the row,
 * the reminder or the nags. ReminderSeamTest reads that order off the log, the confirmation's
 * failure before the reminder's, so it is pinned here rather than relied on. The reminder is
 * one listener on three records.
 *
 * @return array<class-string<TransitionEvent>, list<class-string>>
 */
function notifyListenerMap(): array
{
    return [
        BookingHeld::class => [AcknowledgeApprovalHold::class, AskTheBusinessToDecide::class, NagTheBusiness::class],
        BookingConfirmed::class => [ConfirmToTheCustomer::class, AnnounceNewBooking::class, RemindTheCustomer::class],
        BookingApproved::class => [ApproveToTheCustomer::class, AnnounceAutoApproval::class, RemindTheCustomer::class],
        BookingRejected::class => [RejectToTheCustomer::class],
        BookingExpired::class => [ExpireToTheCustomer::class],
        BookingCancelled::class => [CancelToTheCustomer::class, AnnounceCancellation::class],
        BookingReleased::class => [],
        BookingRescheduled::class => [MoveToTheCustomer::class, AnnounceMove::class, RemindTheCustomer::class],
        BookingCompleted::class => [ThankTheCustomer::class],
        BookingNoShow::class => [],
        BookingPaidLate::class => [AnnounceLatePayment::class],
    ];
}

/**
 * The listeners of the one record that is no transition's: the My bookings link a customer asks
 * for on the hosted page, announced after its row is written through the bookings link's own
 * announcer and mailed by the same machinery, a listener handing one queued job through
 * QueuedMessages. It is not among the records above, which are the announced transitions the
 * matrix runs, and it is held to every structural rule they are.
 *
 * @return array<class-string, list<class-string>>
 */
function notifyRequestListenerMap(): array
{
    return [
        BookingsLinkRequested::class => [MailTheBookingsLink::class],
    ];
}

/**
 * Every notification listener class, once each, the bookings link's among them.
 *
 * @return list<class-string>
 */
function notifyListeners(): array
{
    $listeners = array_values(array_unique(array_merge(...array_values(notifyListenerMap()), ...array_values(notifyRequestListenerMap()))));
    sort($listeners);

    return $listeners;
}

/**
 * Every name a source text refers to, resolved the way the engine resolves it, and read from
 * the tokens so that a name in a comment is a word and not a reference. It is the reach of
 * the three structural assertions below, stated so they are read at their width, and the
 * self-check under the path assertion proves every shape named here with a case of its own:
 *
 * - a class name in any position, resolved through the file's namespace, braced or not and
 *   however many the file declares, and its imports in every shape the grammar allows, plain,
 *   aliased, several at once or grouped under a prefix: a bare name after new, before ::, in
 *   a type, an instanceof, an extends, an implements, a catch, an attribute with or without
 *   arguments, a trait use or a constant's type, wherever it sits, a switch's case and a
 *   ternary's arms included; a name qualified relative to the namespace or to an import; one
 *   relative to the namespace keyword; and a fully qualified one; each in whatever case it
 *   is spelled, since the engine reads a class name in any case, and notifyNamesUnder
 *   matches the refused names without regard to it;
 * - a root-level name with no namespace in it, answered through the aliases the framework
 *   registers, so \Mail is the mail facade and \Notification the notification facade;
 * - a class name spelled inside a string literal or a heredoc, a qualified name anywhere in
 *   it; a whole string that is one of the keys the container resolves the mailer by, mailer
 *   and mail.manager; and a whole string that names one of the mail functions below, a
 *   callable wherever the code hands it to something that takes one, answered as a call of
 *   it, so a key or a label spelled 'mail' would be refused with it, and none is;
 * - a call of one of the three global functions that send mail, mail, mb_send_mail and
 *   imap_mail, bare, fully qualified, as a first-class callable, or by the name a use function
 *   imports one under, answered as "mail()", "mb_send_mail()" and "imap_mail()"; and, the
 *   other way about, a call by a name a use function gave some other function, mail among
 *   the names it may give, is a call of that function and not of mail.
 *
 * What it does not reach, so nobody reads more into it: a name assembled at run time from
 * pieces, and a class resolved from a variable, through reflection or through the container
 * by a value nothing here can read; a root alias spelled as a whole string, which is
 * variable-free PHP in three spellings, 'Notification'::send(), call_user_func(['Notification',
 * 'send']) and new ('Notification'), left unread because reading it would refuse what is not a
 * reference: the alias table holds Password, and 'password' stands as a plain word on the user
 * model, a fillable, a hidden attribute and a cast, and in TenantProvisioner, the owner's row,
 * two files that send nothing, so a whole string is read as a container key and as a callable
 * and never as an alias; a transport spoken by hand, a socket or a curl handle opened to a
 * mail server, a process started to run sendmail, or error_log with the message type that
 * mails, each a name that is not mail until its arguments make it so; and a word in a comment,
 * a member after -> or ::, a named argument, a label, a constant or an enum case where it is
 * declared, a declare directive, a constant before a case's colon, or a declaration's own
 * name, which are not references. A path built the first three ways is a path hidden on
 * purpose, which a review finds and a scan does not.
 *
 * @return list<string>
 */
function notifyReferencesIn(string $source): array
{
    return notifyScan($source)['names'];
}

/**
 * Every static call a source makes, as Class::method(), with the class resolved the way the
 * references above are, so a call in a comment is not one.
 *
 * @return list<string>
 */
function notifyStaticCallsIn(string $source): array
{
    return notifyScan($source)['calls'];
}

/**
 * One pass over the tokens for the two readers above.
 *
 * @return array{names: list<string>, calls: list<string>}
 */
function notifyScan(string $source): array
{
    $tokens = array_values(array_filter(
        PhpToken::tokenize($source),
        fn (PhpToken $token): bool => ! $token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_CLOSE_TAG]),
    ));
    /** @var array<string, string> $facades */
    $facades = array_change_key_case(Facade::defaultAliases()->all(), CASE_LOWER);
    $reserved = ['self', 'static', 'parent', 'true', 'false', 'null', 'string', 'int', 'float', 'bool', 'array', 'mixed', 'void', 'never', 'iterable', 'object', 'callable'];
    $namespace = '';
    /** @var array<string, string> $imports */
    $imports = [];
    // The functions a use function imports, by the name it gives each, lower-cased both ways,
    // so a call by that name is read as a call of the function it stands for.
    /** @var array<string, string> $functions */
    $functions = [];
    /** @var list<string> $names */
    $names = [];
    /** @var list<string> $calls */
    $calls = [];
    $depth = 0;
    // The depth an import sits at: the file's own, or one deeper inside a braced namespace.
    $top = 0;
    // Inside an attribute group, from #[ to the bracket that closes it: the brackets and the
    // parentheses opened within, so that its own closing bracket is told from an array's and
    // the name that opens an argument list is told from a function call.
    /** @var array{brackets: int, parens: int}|null $attribute */
    $attribute = null;
    $count = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];
        $previous = $tokens[$i - 1] ?? null;
        $next = $tokens[$i + 1] ?? null;

        if ($token->is(['{', T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
            $depth++;

            continue;
        }

        if ($token->is('}')) {
            $depth--;

            // The brace that closes a braced namespace: whatever follows is outside it.
            if ($top > 0 && $depth < $top) {
                $namespace = '';
                $imports = [];
                $functions = [];
                $top = 0;
            }

            continue;
        }

        if ($token->is(T_ATTRIBUTE)) {
            $attribute = ['brackets' => 0, 'parens' => 0];

            continue;
        }

        if ($attribute !== null && $token->is(['(', ')', '[', ']'])) {
            if ($token->is('(')) {
                $attribute['parens']++;
            } elseif ($token->is(')')) {
                $attribute['parens']--;
            } elseif ($token->is('[')) {
                $attribute['brackets']++;
            } elseif ($attribute['brackets'] > 0) {
                $attribute['brackets']--;
            } else {
                $attribute = null;
            }

            continue;
        }

        // A declaration names the namespace or, braced and bare, the global one, and either
        // way the imports start afresh. The keyword before a backslash, namespace\Foo, is a
        // relative name and a token of its own.
        if ($token->is(T_NAMESPACE)) {
            $named = $next !== null && $next->is([T_STRING, T_NAME_QUALIFIED]);
            $namespace = $named ? $next->text : '';
            $imports = [];
            $functions = [];
            $opens = $named ? ($tokens[$i + 2] ?? null) : $next;
            $top = $opens !== null && $opens->is('{') ? $depth + 1 : $depth;
            $i += $named ? 1 : 0;

            continue;
        }

        // An import is a use at the namespace's own level; deeper, a use is a trait or a
        // closure's captures, and a trait's name is read like any other bare name.
        if ($token->is(T_USE) && $depth === $top) {
            $i = notifyImport($tokens, $i + 1, $imports, $functions, $names);

            continue;
        }

        // A quoted string is read whole; so is a heredoc's body where nothing is interpolated
        // into it, and a part of a string with a value inside is read for the qualified names
        // it spells and nothing else, since the string it belongs to is assembled at run time.
        if ($token->is([T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE])) {
            $whole = $token->is(T_CONSTANT_ENCAPSED_STRING)
                || ($previous !== null && $previous->is(T_START_HEREDOC) && $next !== null && $next->is(T_END_HEREDOC));

            foreach (notifyNamesSpelledIn($token, $whole) as $spelled) {
                $names[] = $spelled;
            }

            continue;
        }

        // A name before a parenthesis calls a function, unless new made it a class or it is
        // the attribute an attribute group names, #[Name(arguments)].
        $namesAnAttribute = $attribute !== null && $attribute['parens'] === 0;
        $callsAFunction = $next !== null && $next->is('(') && ! ($previous !== null && $previous->is(T_NEW)) && ! $namesAnAttribute;
        $resolved = null;

        if ($token->is(T_NAME_FULLY_QUALIFIED)) {
            $name = ltrim($token->text, '\\');

            if ($callsAFunction) {
                if (in_array(strtolower($name), NOTIFY_MAIL_FUNCTIONS, true)) {
                    $names[] = strtolower($name).'()';
                }

                continue;
            }

            $resolved = str_contains($name, '\\') ? $name : ($facades[strtolower($name)] ?? $name);
        } elseif ($token->is(T_NAME_RELATIVE)) {
            if ($callsAFunction) {
                continue;
            }

            $resolved = ($namespace === '' ? '' : $namespace.'\\').substr($token->text, strlen('namespace\\'));
        } elseif ($token->is(T_NAME_QUALIFIED)) {
            if ($callsAFunction) {
                continue;
            }

            [$first, $rest] = explode('\\', $token->text, 2);
            $resolved = notifyResolve($first, $namespace, $imports, $facades).'\\'.$rest;
        } elseif ($token->is(T_STRING)) {
            // A member after -> or ::, a declaration's own name and a label's are not
            // references to a class. A constant's name is told from its type by what follows
            // it, below; an enum case's name by the semicolon after it, here, where a case
            // with a value is caught below too and a switch's case before :: is read.
            if ($previous !== null && $previous->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM, T_GOTO])) {
                continue;
            }

            if ($previous !== null && $previous->is(T_CASE) && $next !== null && $next->is(';')) {
                continue;
            }

            // A bare call is of the function a use function imported under that name, where
            // one did, and otherwise is read as of the global function, which the engine calls
            // only where the namespace has none of that name.
            if ($callsAFunction) {
                $function = $functions[strtolower($token->text)] ?? strtolower($token->text);

                if (in_array($function, NOTIFY_MAIL_FUNCTIONS, true)) {
                    $names[] = $function.'()';
                }

                continue;
            }

            // Before = the name is a constant or an enum case being declared, or a declare
            // directive, strict_types. Before a single colon it is a named argument, a label,
            // or a constant before a case's or a ternary's colon, unless new or instanceof
            // made it a class.
            if ($next !== null && ($next->is('=') || ($next->is(':') && ! ($previous !== null && $previous->is([T_NEW, T_INSTANCEOF]))))) {
                continue;
            }

            if (in_array(strtolower($token->text), $reserved, true)) {
                continue;
            }

            $resolved = notifyResolve($token->text, $namespace, $imports, $facades);
        }

        if ($resolved === null) {
            continue;
        }

        $names[] = $resolved;

        $method = $tokens[$i + 2] ?? null;
        $opens = $tokens[$i + 3] ?? null;

        if ($next !== null && $next->is(T_DOUBLE_COLON) && $method !== null && $method->is(T_STRING) && $opens !== null && $opens->is('(')) {
            $calls[] = $resolved.'::'.$method->text.'()';
        }
    }

    return ['names' => $names, 'calls' => $calls];
}

/**
 * A bare name, resolved: the import it aliases, else the namespace's own, else, at the root,
 * the facade alias the framework registers under it where there is one, the alias and the
 * import each matched in any case as the engine matches them.
 *
 * @param  array<string, string>  $imports  by alias, lower-cased
 * @param  array<string, string>  $facades  by alias, lower-cased
 */
function notifyResolve(string $name, string $namespace, array $imports, array $facades): string
{
    $lower = strtolower($name);

    if (isset($imports[$lower])) {
        return $imports[$lower];
    }

    if ($namespace === '') {
        return $facades[$lower] ?? $name;
    }

    return $namespace.'\\'.$name;
}

/**
 * One import statement, from the token after use to its semicolon, in every shape the
 * grammar allows: plain, aliased, several at once, and grouped under a prefix. Records each
 * alias and each imported name, and answers the index of the semicolon. A use that imports
 * functions records each under the name it gives it, lower-cased as the engine matches a
 * function, so that a call by that name is read as a call of the function it stands for. A
 * function inside a mixed group, use Foo\{Bar, function mail as post}, is recorded as a class
 * import, which is no hole: a grouped name carries the group's prefix and is never the global
 * function. A use that opens a closure's captures, or that imports a constant, records nothing.
 *
 * @param  list<PhpToken>  $tokens
 * @param  array<string, string>  $imports
 * @param  array<string, string>  $functions
 * @param  list<string>  $names
 */
function notifyImport(array $tokens, int $i, array &$imports, array &$functions, array &$names): int
{
    $count = count($tokens);

    if ($i >= $count || $tokens[$i]->is('(')) {
        return $i - 1;
    }

    $importsFunctions = $tokens[$i]->is(T_FUNCTION);
    $skip = $tokens[$i]->is(T_CONST);
    $prefix = '';

    for (; $i < $count && ! $tokens[$i]->is(';'); $i++) {
        $token = $tokens[$i];

        if ($skip || ! $token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
            continue;
        }

        $name = ltrim($token->text, '\\');
        $separator = $tokens[$i + 1] ?? null;
        $brace = $tokens[$i + 2] ?? null;

        // A group: the name before the brace prefixes every name inside it.
        if ($separator !== null && $separator->is(T_NS_SEPARATOR) && $brace !== null && $brace->is('{')) {
            $prefix = $name.'\\';
            $i += 2;

            continue;
        }

        $full = $prefix.$name;
        $segments = explode('\\', $full);
        $alias = (string) end($segments);
        $as = $tokens[$i + 1] ?? null;
        $given = $tokens[$i + 2] ?? null;

        if ($as !== null && $as->is(T_AS) && $given !== null && $given->is(T_STRING)) {
            $alias = $given->text;
            $i += 2;
        }

        if ($importsFunctions) {
            $functions[strtolower($alias)] = strtolower($full);

            continue;
        }

        $imports[strtolower($alias)] = $full;
        $names[] = $full;
    }

    return $i;
}

/**
 * The names a string token spells: every qualified name inside it, with the doubled
 * backslashes of a quoted string read as one; and, where the token is the whole string, with
 * a heredoc's indentation and closing newline aside, the string itself where it is a single
 * lower-case word of the kind the container is asked by, and a call of one of the mail
 * functions where it names one, in any case and with or without a leading backslash as the
 * engine takes a callable string, because a string is a callable wherever the code hands it
 * to something that takes one.
 *
 * @return list<string>
 */
function notifyNamesSpelledIn(PhpToken $token, bool $whole): array
{
    $text = $token->is(T_CONSTANT_ENCAPSED_STRING) ? substr($token->text, 1, -1) : trim($token->text);
    $text = str_replace('\\\\', '\\', $text);
    $found = [];

    preg_match_all('/\\\\?[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)+/', $text, $matches);

    foreach ($matches[0] as $name) {
        $found[] = ltrim($name, '\\');
    }

    if (! $whole) {
        return $found;
    }

    if (preg_match('/\A[a-z][a-z0-9_.]*\z/', $text) === 1) {
        $found[] = $text;
    }

    $word = strtolower(ltrim($text, '\\'));

    if (in_array($word, NOTIFY_MAIL_FUNCTIONS, true)) {
        $found[] = $word.'()';
    }

    return $found;
}

/**
 * The names among those given that begin with one of the prefixes, in source order and in the
 * source's own spelling, matched without regard to case because the engine reads a class name
 * in any case. The container keys and the mail functions among the prefixes are matched the
 * same way, by prefix and without regard to case, so a key refuses more than the key: a class
 * named Mailer in a root-namespace file, resolved to the name Mailer, and a whole string
 * 'mailers' or 'mailer_queue' are refused under mailer, each an error that refuses rather
 * than lets through.
 *
 * @param  list<string>  $names
 * @param  list<string>  $prefixes
 * @return list<string>
 */
function notifyNamesUnder(array $names, array $prefixes): array
{
    return array_values(array_filter($names, function (string $name) use ($prefixes): bool {
        foreach ($prefixes as $prefix) {
            if (str_starts_with(strtolower($name), strtolower($prefix))) {
                return true;
            }
        }

        return false;
    }));
}

/**
 * The source of a class, for the token reads below.
 *
 * @param  class-string  $class
 */
function notifySourceOf(string $class): string
{
    $path = (new ReflectionClass($class))->getFileName();

    assert(is_string($path));

    return (string) file_get_contents($path);
}

/**
 * The names a listener may not reference, because a listener hands its send to a job and sends
 * nothing itself: the port, the seam, the platform's own port and its adapter beside them (the
 * two are admitted in the notification directories alone), the framework's mail and its
 * notifications with their two facades, the keys the container resolves the mailer by, the
 * three global functions that send mail as notifyReferencesIn spells a call of them, and the
 * framework's password broker with its notifications, its facade and its container keys, which
 * carry the two messages the framework sends of its own accord and outside the seam, a
 * password reset and an address verification. The business directory beside the seam is not
 * among them: it answers who is at the business and sends nothing, and the decision link guard
 * reads it to find the user a link names.
 *
 * @return list<string>
 */
function notifyMailNames(): array
{
    return [
        'App\\Application\\Notification\\Messenger',
        'App\\Infrastructure\\Notification\\Messengers',
        'App\\Infrastructure\\Notification\\MailedMessages',
        'App\\Application\\Notification\\PlatformMessenger',
        'App\\Infrastructure\\Notification\\MailedPlatformMessages',
        'Illuminate\\Mail\\',
        'Illuminate\\Contracts\\Mail\\',
        'Illuminate\\Support\\Facades\\Mail',
        'Illuminate\\Notifications\\',
        'Illuminate\\Contracts\\Notifications\\',
        'Illuminate\\Support\\Facades\\Notification',
        'Illuminate\\Auth\\Notifications\\',
        'Illuminate\\Auth\\Passwords\\',
        'Illuminate\\Contracts\\Auth\\PasswordBroker',
        'Illuminate\\Support\\Facades\\Password',
        'Symfony\\Component\\Mailer\\',
        'mailer',
        'mail.manager',
        'auth.password',
        ...array_map(fn (string $function): string => $function.'()', NOTIFY_MAIL_FUNCTIONS),
    ];
}

/**
 * Every notification job: the classes under app/Jobs whose name says they send a message.
 *
 * @return list<class-string>
 */
function notifyJobs(): array
{
    $jobs = [];

    foreach (scandir(dirname(__DIR__, 2).'/app/Jobs') ?: [] as $entry) {
        if (str_starts_with($entry, 'Send') && str_ends_with($entry, '.php')) {
            /** @var class-string $job */
            $job = 'App\\Jobs\\'.substr($entry, 0, -4);
            $jobs[] = $job;
        }
    }

    sort($jobs);

    return $jobs;
}

it('registers every notification listener on the record it answers, in the order the provider lists them, and nothing on the two records nobody hears about, which is the list the matrix ran under', function () {
    $registered = [];

    foreach (array_keys(notifyListenerMap()) as $record) {
        $listeners = [];

        $raw = Event::getRawListeners()[$record] ?? [];

        expect($raw)->toBeArray();
        assert(is_array($raw));

        foreach ($raw as $listener) {
            // A listener is registered as [Class, 'handle'], one line each, AppServiceProvider;
            // anything else here is a shape nobody registered.
            expect($listener)->toBeArray();
            assert(is_array($listener) && isset($listener[0], $listener[1]));

            expect($listener[1])->toBe('handle');

            $listeners[] = $listener[0];
        }

        $registered[$record] = $listeners;
    }

    expect($registered)->toBe(notifyListenerMap())
        ->and(array_keys($registered))->toBe(array_keys(notifyListenerMap()))
        ->and(announcedRecords())->toBe((function (): array {
            $records = array_keys(notifyListenerMap());
            sort($records);

            return $records;
        })());
});

it('registers the bookings link\'s listener on its record alone, the one message no transition sends', function () {
    foreach (notifyRequestListenerMap() as $record => $listeners) {
        $raw = Event::getRawListeners()[$record] ?? [];

        expect($raw)->toBe(array_map(fn (string $listener): array => [$listener, 'handle'], $listeners))
            ->and(in_array(TransitionEvent::class, class_implements($record) ?: [], true))->toBeFalse();
    }
});

it('hands, in every listener, the send to one queued job through QueuedMessages, touches no mailer, and reads the database in the two listeners that time a scheduled message and nowhere else', function () {
    $readers = [];

    foreach (notifyListeners() as $listener) {
        $reflection = new ReflectionClass($listener);
        $constructor = $reflection->getConstructor();
        $injected = array_map(
            fn (ReflectionParameter $parameter): string => (string) $parameter->getType(),
            $constructor?->getParameters() ?? [],
        );
        $names = notifyReferencesIn(notifySourceOf($listener));
        $jobs = notifyNamesUnder($names, ['App\\Jobs\\Send']);

        // One job, queued, and QueuedMessages to hand it over: the two promises, after the
        // commit and never throwing, are QueuedMessages' and a listener built without it has
        // neither.
        expect($injected)->toContain(QueuedMessages::class)
            ->and($reflection->hasMethod('handle'))->toBeTrue()
            ->and(array_values(array_unique($jobs)))->toHaveCount(1)
            ->and(in_array(ShouldQueue::class, class_implements($jobs[0]) ?: [], true))->toBeTrue()
            ->and(notifyNamesUnder($names, notifyMailNames()))->toBe([])
            ->and(notifyNamesUnder($names, ['Illuminate\\Support\\Facades\\DB', 'Illuminate\\Database\\']))->toBe([]);

        $reads = array_values(array_unique(notifyNamesUnder($names, ['App\\Infrastructure\\Db\\'])));
        sort($reads);

        if ($reads !== []) {
            $readers[$listener] = $reads;
        }
    }

    // The two scheduled messages are timed off the row after the commit: the reminder reads the
    // lead, the start and the revision, and the nag reads the window. They are the only
    // listeners that read the database, the other fifteen reading none, and the read is inside
    // QueuedMessages' guard in both.
    ksort($readers);

    expect($readers)->toBe([
        NagTheBusiness::class => ['App\\Infrastructure\\Db\\BookingReader', 'App\\Infrastructure\\Db\\SqlText'],
        RemindTheCustomer::class => ['App\\Infrastructure\\Db\\BookingReader', 'App\\Infrastructure\\Db\\SettingsReader'],
    ]);
});

it('queues every job, encrypts exactly the three that carry a plaintext token, the two manage tokens and the bookings link\'s, and fails every one through MessageNotSent so that no frame below the job reaches failed_jobs', function () {
    $encrypted = [];

    foreach (notifyJobs() as $job) {
        $parameters = array_map(
            fn (ReflectionParameter $parameter): string => $parameter->getName(),
            (new ReflectionClass($job))->getConstructor()?->getParameters() ?? [],
        );
        $carriesTheToken = array_intersect(['manageToken', 'linkToken'], $parameters) !== [];

        expect(in_array(ShouldQueue::class, class_implements($job) ?: [], true))->toBeTrue()
            // What every job carries: the tenant it was queued for and the booking it is about,
            // except SendBookingsLink, which is about the link it mails and no booking, and carries
            // the link's id in the booking's place.
            ->and(array_slice($parameters, 0, 2))->toBe($job === SendBookingsLink::class ? ['tenantId', 'linkId'] : ['tenantId', 'uuid'])
            // Encrypted where, and only where, a credential rides in the payload; the jobs
            // that mint their links inside themselves are queued in the clear.
            ->and(in_array(ShouldBeEncrypted::class, class_implements($job) ?: [], true))->toBe($carriesTheToken)
            // Read off the tokens as a call, so a comment naming the wrapper is not one.
            ->and(notifyStaticCallsIn(notifySourceOf($job)))->toContain(MessageNotSent::class.'::after()');

        if ($carriesTheToken) {
            $encrypted[] = $job;
        }
    }

    expect(notifyJobs())->toHaveCount(17)
        ->and($encrypted)->toBe([SendAcknowledgement::class, SendBookingsLink::class, SendConfirmation::class]);
});

/**
 * The names nothing outside the notification machinery may reference: the listeners, the jobs,
 * the port, the seam, and the framework's mail, its notifications and its password broker as
 * notifyMailNames lists them. A use case or a controller that named one would be a second path
 * from a transition to a message, beside the publisher.
 *
 * @return list<string>
 */
function notifyMachineryNames(): array
{
    return [...notifyListeners(), QueuedMessages::class, 'App\\Jobs\\Send', ...notifyMailNames()];
}

/**
 * Every source file the path assertion reads, outside the machinery itself, by path from the
 * repository root: under app/, everything but four places, app/Notifications, the listeners and the
 * templates beside them, app/Infrastructure/Notification, the seam's adapter, the platform's
 * adapter beside it and the business directory, app/Jobs/Send*, the jobs, and
 * app/Providers/AppServiceProvider.php, which registers the listeners and binds the platform's
 * port; under routes/, every file, since a route's closure is code a request reaches; and under
 * bootstrap/, the two files written by hand and not the cache, which lists every provider of every
 * package and calls none of them. MessageNotSent stays in: it names nothing the machinery is made
 * of, and it is a file like any other to this scan. Not read, and said so nobody reads more into
 * it: config/, where a class named is wired and not called; database/ and resources/, where a
 * seeder or a view that sent mail would be a path hidden on purpose; bin/, the repository's own
 * gate scripts, which no request reaches; and vendor/, which is not this application.
 *
 * @return list<string>
 */
function notifyFilesOutsideTheMachinery(): array
{
    $root = dirname(__DIR__, 2);
    $files = [];
    $walk = function (string $directory) use (&$walk, &$files, $root): void {
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory.'/'.$entry;
            $relative = substr($path, strlen($root) + 1);

            if (is_dir($path)) {
                if (! in_array($relative, ['app/Notifications', 'app/Infrastructure/Notification', 'bootstrap/cache'], true)) {
                    $walk($path);
                }
            } elseif (str_ends_with($entry, '.php')
                && $relative !== 'app/Providers/AppServiceProvider.php'
                && ! str_starts_with($relative, 'app/Jobs/Send')) {
                $files[] = $relative;
            }
        }
    };

    foreach (['app', 'routes', 'bootstrap'] as $directory) {
        $walk($root.'/'.$directory);
    }

    sort($files);

    return $files;
}

it('reaches a message from a transition through the publisher and no other way: no use case, controller, factory, reader, model, command, route file or bootstrap file names a listener, a job, the port, the seam, the mailer, the framework\'s notifications or its password broker, in any shape notifyReferencesIn reads', function () {
    $files = notifyFilesOutsideTheMachinery();
    $offences = [];

    expect($files)->toContain('app/Application/ConfirmBooking.php')
        ->toContain('app/Http/Controllers/DecideController.php')
        ->toContain('app/Infrastructure/BookingTransitionFactory.php')
        ->toContain('app/Jobs/MessageNotSent.php')
        ->toContain('app/Jobs/SweepExpiredHolds.php')
        ->toContain('app/Models/User.php')
        ->toContain('routes/tenant.php')
        ->toContain('bootstrap/app.php')
        ->not->toContain('bootstrap/cache/packages.php');

    // The one reference outside the machinery that is allowed, by file, by name and by the
    // name's spelling: the Notifiable trait on the User model. Illuminate\Foundation\Auth\User,
    // which User extends, calls notify() from CanResetPassword for a password reset, and the
    // trait is where that method lives; it is not a message of this application, and a
    // notification written to be sent through it would extend
    // Illuminate\Notifications\Notification, which this scan refuses wherever it reads, outside
    // the four places it leaves alone.
    $allowed = ['app/Models/User.php: Illuminate\\Notifications\\Notifiable'];

    foreach ($files as $relative) {
        $names = notifyNamesUnder(notifyReferencesIn((string) file_get_contents(dirname(__DIR__, 2).'/'.$relative)), notifyMachineryNames());

        foreach ($names as $name) {
            if (! in_array($relative.': '.$name, $allowed, true)) {
                $offences[] = $relative.': '.$name;
            }
        }
    }

    expect($offences)->toBe([]);
});

it('catches {shape} in a source it is shown, resolved to the name it stands for, so the path assertion above can fail on that shape', function (string $source, array $expected) {
    $found = array_values(array_unique(notifyNamesUnder(notifyReferencesIn($source), notifyMachineryNames())));
    sort($found);

    expect($found)->toBe($expected);
})->with([
    'a plain import, a fully qualified call and a fully qualified new' => [<<<'PHP'
    <?php

    namespace App\Application;

    use App\Infrastructure\Notification\Messengers;

    final class Sample
    {
        public function run(Messengers $messengers): void
        {
            \App\Jobs\SendConfirmation::dispatch(1);
            new \App\Notifications\ConfirmToTheCustomer($messengers);
        }
    }
    PHP, ['App\\Infrastructure\\Notification\\Messengers', 'App\\Jobs\\SendConfirmation', 'App\\Notifications\\ConfirmToTheCustomer']],
    'an aliased import used by its alias' => [<<<'PHP'
    <?php

    namespace App\Application;

    use App\Jobs\SendConfirmation as Job;

    final class Sample
    {
        public function run(): void
        {
            Job::dispatch(1);
        }
    }
    PHP, ['App\\Jobs\\SendConfirmation']],
    'a grouped import, one of its names aliased' => [<<<'PHP'
    <?php

    namespace App\Application;

    use App\Jobs\{SendConfirmation, SendMove as Moving};

    final class Sample
    {
        public function run(): void
        {
            Moving::dispatch(1);
        }
    }
    PHP, ['App\\Jobs\\SendConfirmation', 'App\\Jobs\\SendMove']],
    'several imports in one statement, one of them aliased, each used by the name it gives' => [<<<'PHP'
    <?php

    namespace App\Application;

    use App\Jobs\SendConfirmation, App\Jobs\SendMove as Moving, Illuminate\Support\Str;

    final class Sample
    {
        public function run(): void
        {
            SendConfirmation::dispatch(1);
            Moving::dispatch(Str::random());
        }
    }
    PHP, ['App\\Jobs\\SendConfirmation', 'App\\Jobs\\SendMove']],
    'a bare name inside the jobs namespace, with no import, as a call and as a type' => [<<<'PHP'
    <?php

    namespace App\Jobs;

    final class Sample
    {
        public function run(SendMove $move): void
        {
            SendConfirmation::dispatch(1);
        }
    }
    PHP, ['App\\Jobs\\SendConfirmation', 'App\\Jobs\\SendMove']],
    'a name qualified relative to the namespace, and one relative to the namespace keyword' => [<<<'PHP'
    <?php

    namespace App;

    final class Sample
    {
        public function run(): void
        {
            Jobs\SendConfirmation::dispatch(1);
            namespace\Jobs\SendMove::dispatch(1);
        }
    }
    PHP, ['App\\Jobs\\SendConfirmation', 'App\\Jobs\\SendMove']],
    'a name qualified relative to an import' => [<<<'PHP'
    <?php

    namespace App\Application;

    use App\Jobs;

    final class Sample
    {
        public function run(): void
        {
            Jobs\SendConfirmation::dispatch(1);
        }
    }
    PHP, ['App\\Jobs\\SendConfirmation']],
    'a class name spelled in a string, single or double quoted' => [<<<'PHP'
    <?php

    namespace App\Application;

    final class Sample
    {
        public function run(): void
        {
            $single = 'App\\Jobs\\SendConfirmation';
            $double = "App\\Jobs\\SendMove";
            dispatch(new $single);
            dispatch(new $double);
        }
    }
    PHP, ['App\\Jobs\\SendConfirmation', 'App\\Jobs\\SendMove']],
    'a qualified name inside wider string text, single quoted, double quoted with a leading backslash, and in a heredoc with words around it' => [<<<'PHP'
    <?php

    namespace App\Application;

    final class Sample
    {
        public function run(): string
        {
            $single = 'queue App\\Jobs\\SendConfirmation now';
            $double = "queue \\App\\Jobs\\SendMove now";

            return $single.$double.<<<TXT
            The job App\\Jobs\\SendReminder is queued.
            TXT;
        }
    }
    PHP, ['App\\Jobs\\SendConfirmation', 'App\\Jobs\\SendMove', 'App\\Jobs\\SendReminder']],
    'the mailer resolved from the container by either of its keys' => [<<<'PHP'
    <?php

    namespace App\Application;

    final class Sample
    {
        public function run(): void
        {
            app('mailer');
            resolve('mail.manager');
        }
    }
    PHP, ['mail.manager', 'mailer']],
    'the root aliases of the two facades' => [<<<'PHP'
    <?php

    namespace App\Application;

    final class Sample
    {
        public function run(): void
        {
            \Mail::raw('x', fn () => null);
            \Notification::send([], null);
        }
    }
    PHP, ['Illuminate\\Support\\Facades\\Mail', 'Illuminate\\Support\\Facades\\Notification']],
    'the global mail function, bare and fully qualified' => [<<<'PHP'
    <?php

    namespace App\Application;

    final class Sample
    {
        public function run(): void
        {
            mail('a', 'b', 'c');
            \mail('a', 'b', 'c');
        }
    }
    PHP, ['mail()']],
    'the framework notifications and their facade, imported' => [<<<'PHP'
    <?php

    namespace App\Application;

    use Illuminate\Notifications\Notification;
    use Illuminate\Support\Facades\Notification as Sender;

    final class Sample
    {
        public function run(): void
        {
            Sender::send([], new Notification);
        }
    }
    PHP, ['Illuminate\\Notifications\\Notification', 'Illuminate\\Support\\Facades\\Notification']],
    'a bare name as an attribute, a parent, a trait, an instanceof and a catch, and a class name spelled in a heredoc' => [<<<'PHP'
    <?php

    namespace App\Jobs;

    #[SendMove]
    final class Sample extends SendConfirmation
    {
        use SendReminder;

        public function run(object $x): bool
        {
            try {
                return $x instanceof SendCancellation;
            } catch (SendMoveNotice $e) {
                return false;
            }
        }

        public function text(): string
        {
            return <<<TXT
            App\\Jobs\\SendNewBookingNotice
            TXT;
        }
    }
    PHP, ['App\\Jobs\\SendCancellation', 'App\\Jobs\\SendConfirmation', 'App\\Jobs\\SendMove', 'App\\Jobs\\SendMoveNotice', 'App\\Jobs\\SendNewBookingNotice', 'App\\Jobs\\SendReminder']],
    'the port and the seam, as constructor types' => [<<<'PHP'
    <?php

    namespace App\Application;

    use App\Application\Notification\Messenger;
    use App\Infrastructure\Notification\MailedMessages;

    final class Sample
    {
        public function __construct(private Messenger $port, private MailedMessages $seam) {}
    }
    PHP, ['App\\Application\\Notification\\Messenger', 'App\\Infrastructure\\Notification\\MailedMessages']],
    'the same names in comments, in members, in a named argument, in a label, before a case\'s colon, in a method called mail, a root alias as a whole string and a mail function inside a wider string, which are none of them references' => [<<<'PHP'
    <?php

    namespace App\Application;

    // use App\Jobs\SendConfirmation; \Mail::raw(); mail(); 'App\\Jobs\\SendMove'; app('mailer');

    /** App\Jobs\SendConfirmation and Illuminate\Support\Facades\Mail in a docblock are words. */
    final class Sample
    {
        public function mail(): void
        {
            $this->mail();
            self::mail();
            $this->SendConfirmation;
            $this->run(SendConfirmation: 1, facade: 'Notification', column: 'password', words: "mail $this->x");
        }

        public function run(int $SendConfirmation, string $facade, string $column, string $words): int
        {
            goto SendMove;
            SendMove:
            switch ($SendConfirmation) {
                case SendReminder:
                    return 1;
            }

            return 0;
        }
    }
    PHP, []],
    'a bare name before :: as a switch case under the jobs namespace, beside an enum whose cases are names and not classes' => [<<<'PHP'
    <?php

    namespace App\Jobs;

    enum Kind
    {
        case SendMove;
        case SendReminder = 'r';
    }

    final class Sample
    {
        public function run(string $x): int
        {
            switch ($x) {
                case SendConfirmation::class:
                    return 1;
                case SendCancellation::class;
                    return 2;
                case SOME_CONSTANT:
                    return 3;
            }

            return 0;
        }
    }
    PHP, ['App\\Jobs\\SendCancellation', 'App\\Jobs\\SendConfirmation']],
    'an attribute with arguments, bare, fully qualified and grouped, on a class, a method, a parameter and a property' => [<<<'PHP'
    <?php

    namespace App\Jobs;

    #[SendMove(1)]
    final class Sample
    {
        #[SendReminder(['a' => [1, 2]], name: 'x')]
        public string $x = '';

        #[\App\Jobs\SendConfirmation(1, 2)]
        public function run(#[SendCancellation, SendMoveNotice(strlen('y'))] int $y): void {}
    }
    PHP, ['App\\Jobs\\SendCancellation', 'App\\Jobs\\SendConfirmation', 'App\\Jobs\\SendMove', 'App\\Jobs\\SendMoveNotice', 'App\\Jobs\\SendReminder']],
    'an import inside a braced namespace, in a named one, in the global one and in a second named one with imports of its own' => [<<<'PHP'
    <?php

    namespace App\Http {
        use App\Jobs\SendConfirmation as J;

        final class A
        {
            public function run(): void
            {
                J::dispatch(1);
            }
        }
    }

    namespace {
        use App\Jobs\SendReminder;

        final class B
        {
            public function run(): void
            {
                SendReminder::dispatch(1);
            }
        }
    }

    namespace App\Application {
        use App\Jobs\SendMove;

        final class C
        {
            public function run(): void
            {
                SendMove::dispatch(1);
                J::dispatch(1);
            }
        }
    }
    PHP, ['App\\Jobs\\SendConfirmation', 'App\\Jobs\\SendMove', 'App\\Jobs\\SendReminder']],
    'a class name in another case, fully qualified, imported, qualified relative to the namespace and as a root alias' => [<<<'PHP'
    <?php

    namespace app;

    use APP\JOBS\SENDMOVE;

    final class Sample
    {
        public function run(): void
        {
            \app\jobs\SendConfirmation::dispatch(1);
            SENDMOVE::dispatch(1);
            jobs\sendreminder::dispatch(1);
            \mail::raw('x', fn () => null);
        }
    }
    PHP, ['APP\\JOBS\\SENDMOVE', 'Illuminate\\Support\\Facades\\Mail', 'app\\jobs\\SendConfirmation', 'app\\jobs\\sendreminder']],
    'the two other mail functions, mb_send_mail and imap_mail, bare, fully qualified and as a first-class callable' => [<<<'PHP'
    <?php

    namespace App\Application;

    final class Sample
    {
        public function run(): void
        {
            mb_send_mail('a', 'b', 'c');
            \imap_mail('a', 'b', 'c');
            $send = MB_SEND_MAIL(...);
        }
    }
    PHP, ['imap_mail()', 'mb_send_mail()']],
    'a mail function imported under another name and called by it, bare and as a first-class callable, beside a function of another name imported as mail, a call of which is not a call of mail' => [<<<'PHP'
    <?php

    namespace App\Application;

    use function mb_send_mail as post, imap_mail as send;
    use function strlen as mail;

    final class Sample
    {
        public function run(): int
        {
            post('a', 'b', 'c');
            $send = send(...);

            return mail('x');
        }
    }
    PHP, ['imap_mail()', 'mb_send_mail()']],
    'a mail function named as a callable string, bare, with a leading backslash, in another case, and as the whole body of a heredoc' => [<<<'PHP'
    <?php

    namespace App\Application;

    final class Sample
    {
        public function run(): void
        {
            call_user_func('mail', 'a', 'b', 'c');
            array_map('\\mail', []);
            array_map("MB_SEND_MAIL", []);
            $late = <<<'TXT'
                imap_mail
                TXT;
            usort([], $late);
        }
    }
    PHP, ['imap_mail()', 'mail()', 'mb_send_mail()']],
    'a bare name after new or after instanceof before a ternary\'s colon and after one, after implements, and as a constant\'s type' => [<<<'PHP'
    <?php

    namespace App\Jobs;

    final class Sample implements SendReminder
    {
        public const SendCancellation X = null;

        public function run(bool $x, object $y): mixed
        {
            $job = $x ? new SendMove : null;

            return $x ? $y instanceof SendConfirmation : new SendMoveNotice;
        }
    }
    PHP, ['App\\Jobs\\SendCancellation', 'App\\Jobs\\SendConfirmation', 'App\\Jobs\\SendMove', 'App\\Jobs\\SendMoveNotice', 'App\\Jobs\\SendReminder']],
    'a second namespace declared without braces after the first, whose imports start afresh, so a bare name the first namespace aliased to something else is the second namespace\'s own' => [<<<'PHP'
    <?php

    namespace App\Http;

    use Illuminate\Support\Str as SendMove;

    final class A
    {
        public function run(): string
        {
            return SendMove::random();
        }
    }

    namespace App\Jobs;

    final class B
    {
        public function run(): void
        {
            SendMove::dispatch(1);
        }
    }
    PHP, ['App\\Jobs\\SendMove']],
    'the platform\'s own port taken by a controller, and its adapter made by a fully qualified new, which app/Http may not do any more than it may send through the tenant\'s seam' => [<<<'PHP'
    <?php

    namespace App\Http\Controllers\Central;

    use App\Application\Notification\PlatformMessage;
    use App\Application\Notification\PlatformMessenger;

    final class SampleController
    {
        public function __invoke(PlatformMessenger $platform, PlatformMessage $message): void
        {
            $platform->send($message);
            new \App\Infrastructure\Notification\MailedPlatformMessages();
        }
    }
    PHP, ['App\\Application\\Notification\\PlatformMessenger', 'App\\Infrastructure\\Notification\\MailedPlatformMessages']],
]);

it('reads a static call off the tokens and never off a comment, so the shape assertion on the jobs can fail', function () {
    $call = <<<'PHP'
    <?php

    namespace App\Jobs;

    // MessageNotSent::after($failure, $uuid) in a comment is words.
    final class Sample
    {
        public function run(\Throwable $failure): void
        {
            throw MessageNotSent::after($failure, 'x');
        }
    }
    PHP;
    $comment = <<<'PHP'
    <?php

    namespace App\Jobs;

    // MessageNotSent::after($failure, $uuid)
    final class Sample {}
    PHP;

    expect(notifyStaticCallsIn($call))->toBe([MessageNotSent::class.'::after()'])
        ->and(notifyStaticCallsIn($comment))->toBe([]);
});

/*
 * The transport assertion over the wire: every route a booking transition answers on, and the
 * two decision POSTs, under a transport that refuses every send, answer the status they
 * answer under one that delivers, with the transition committed and its audit row written,
 * and the log carrying one error per message that failed and naming no address. The cells
 * above assert the same of every use case; this is the same promise where a request is
 * waiting for an answer.
 */

it('answers {route} as it would have under a transport that refuses every send, commits, writes the audit row and logs one error per message naming no address', function (string $route, int $status, array $trail, array $sent, array $scheduled) {
    $tenant = LunaFixture::tenant();
    [$log, $transport] = notifyObserve();

    switch ($route) {
        case 'POST /holds for a checkout':
            [$url, $body] = seamHold('Haircut');
            $held = seamHeld($this->postJson($url, $body));
            break;
        case 'POST /holds for a request':
            [$url, $body] = seamHold('Bridal trial');
            $held = seamHeld($this->postJson($url, $body));
            break;
        case 'POST /bookings/{uuid}/confirm':
            [$url, $body] = seamHold('Haircut');
            $held = seamHeld($this->postJson($url, $body));
            [$log, $transport] = notifyObserve();
            $this->postJson(tenantUrl('luna', '/api/v1/bookings/'.$held['uuid'].'/confirm'), ['token' => $held['token']])->assertStatus($status);
            break;
        case 'POST /bookings/{uuid}/cancel':
            [$url, $body] = seamHold('Haircut');
            $held = seamHeld($this->postJson($url, $body));
            seamConfirm($this, $held['uuid'], $held['token']);
            [$log, $transport] = notifyObserve();
            $this->postJson(tenantUrl('luna', '/api/v1/bookings/'.$held['uuid'].'/cancel'), ['token' => $held['token']])->assertStatus($status);
            break;
        case 'POST /bookings/{uuid}/reschedule':
            [$url, $body] = seamHold('Haircut');
            $held = seamHeld($this->postJson($url, $body));
            seamConfirm($this, $held['uuid'], $held['token']);
            [$log, $transport] = notifyObserve();
            $this->postJson(tenantUrl('luna', '/api/v1/bookings/'.$held['uuid'].'/reschedule'), [
                'token' => $held['token'],
                'start' => LunaFixture::at(NOTIFY_TARGET)->format(DateTimeImmutable::RFC3339),
            ])->assertStatus($status);
            break;
        case 'DELETE /holds/{uuid}':
            [$url, $body] = seamHold('Haircut');
            $held = seamHeld($this->postJson($url, $body));
            [$log, $transport] = notifyObserve();
            $this->deleteJson(tenantUrl('luna', '/api/v1/holds/'.$held['uuid'].'?token='.$held['token']))->assertStatus($status);
            break;
        case 'POST /decide/{uuid}/approve':
        case 'POST /decide/{uuid}/reject':
            // A link's own lifetime is the hold's expiry judged against the application clock,
            // and the hold writes that expiry from the database's; a request placed today on the
            // system clock is the one arrangement in which a minted link is live, which is what
            // DecisionPagesTest runs on and why.
            app()->instance(Clock::class, new SystemClock);
            [$url, $body] = seamHold('Bridal trial', notifyUpcomingStart());
            $held = seamHeld($this->postJson($url, $body));
            $decision = str_ends_with($route, 'approve') ? Decision::Approve : Decision::Reject;
            $expiry = app(BookingReader::class)->snapshot($tenant, $held['uuid'])?->holdExpiresAt;

            assert($expiry instanceof DateTimeImmutable);

            [$log, $transport] = notifyObserve();
            $response = $this->post(app(DecisionLinks::class)->link($tenant, $held['uuid'], $decision, tenantUserId(NOTIFY_OWNER), $expiry));

            $response->assertStatus($status);

            expect((string) $response->getContent())->toContain(decisionLine($decision === Decision::Approve ? 'decision.outcome.confirmed' : 'decision.outcome.rejected'));
            break;
        default:
            throw new LogicException(sprintf('The matrix has no route called "%s".', $route));
    }

    // The two holds answered with their own status inside seamHeld, which asserts 201.
    expect(array_map(fn (array $row): mixed => $row['action'], LunaFixture::trail()))->toBe($trail)
        ->and(notifyObserved($log, $transport, $held['uuid']))->toBe(['sent' => $sent, 'scheduled' => $scheduled]);
})->with(function (): array {
    $rows = [];

    foreach (notifyRoutes() as $route => $row) {
        $rows[$route] = [$route, ...$row];
    }

    return $rows;
});

/**
 * The booking transition routes and the two decision POSTs, each with the status it answers,
 * the trail it leaves, and what it sends and schedules. The route is named once, as the row's
 * key, and handed to the test from there.
 *
 * @return array<string, array{int, list<string>, list<string>, list<string>}>
 */
function notifyRoutes(): array
{
    return [
        'POST /holds for a checkout' => [201, ['held'], [], []],
        'POST /holds for a request' => [201, ['held'], ['business:approval_request', 'customer:acknowledged'], ['business:approval_nag', 'business:approval_nag', 'business:approval_nag']],
        'POST /bookings/{uuid}/confirm' => [200, ['held', 'confirmed'], ['business:new_booking', 'customer:confirmed'], ['customer:reminder']],
        'POST /bookings/{uuid}/cancel' => [200, ['held', 'confirmed', 'cancelled'], ['business:cancellation_notice', 'customer:cancelled'], []],
        'POST /bookings/{uuid}/reschedule' => [200, ['held', 'confirmed', 'rescheduled'], ['business:move_notice', 'customer:moved'], ['customer:reminder']],
        'DELETE /holds/{uuid}' => [204, ['held', 'released'], [], []],
        'POST /decide/{uuid}/approve' => [200, ['held', 'approved'], ['customer:approved'], ['customer:reminder']],
        'POST /decide/{uuid}/reject' => [200, ['held', 'rejected'], ['customer:rejected'], []],
    ];
}

/*
 * The decision-link assertion, repeated here: a GET on an approve link and a GET on a
 * reject link each leave the booking, the audit log and the published events exactly as
 * they were, and send nothing.
 */

it('decides nothing and sends nothing on a GET of the {decision} link, however many times it is fetched, because a mail scanner fetches every link', function (Decision $decision) {
    // On the system clock, with the request placed relative to today, for the reason the
    // decision routes above give: a link's lifetime is judged against the application clock.
    app()->instance(Clock::class, new SystemClock);

    $tenant = LunaFixture::tenant();
    $uuid = LunaFixture::hold(['Bridal trial'], notifyUpcomingStart());
    $expiry = app(BookingReader::class)->snapshot($tenant, $uuid)?->holdExpiresAt;

    assert($expiry instanceof DateTimeImmutable);

    $link = app(DecisionLinks::class)->link($tenant, $uuid, $decision, tenantUserId(NOTIFY_OWNER), $expiry);
    $before = LunaFixture::row($uuid);
    $trail = LunaFixture::trail();
    $published = [];
    [$log, $transport] = notifyObserve();

    Event::listen(TransitionEvent::class, function (TransitionEvent $event) use (&$published): void {
        $published[] = $event::class;
    });

    for ($fetch = 0; $fetch < 3; $fetch++) {
        $page = $this->get($link);

        $page->assertOk();

        expect((string) $page->getContent())->toContain(decisionLine('decision.decisions.'.$decision->value.'.verb'));
    }

    expect($trail)->toHaveCount(1)
        ->and(LunaFixture::row($uuid))->toBe($before)
        ->and(LunaFixture::row($uuid)['status'])->toBe('awaiting_approval')
        ->and(LunaFixture::trail())->toBe($trail)
        ->and($published)->toBe([])
        ->and($transport->refused)->toBe([])
        ->and($log->records)->toBe([]);
})->with(['approve' => [Decision::Approve], 'reject' => [Decision::Reject]]);
