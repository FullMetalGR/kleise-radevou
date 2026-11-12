<?php

use App\Application\Dto\BookingSnapshot;
use App\Application\Notification\Message;
use App\Application\Notification\MessageKey;
use App\Application\Notification\Recipient;
use App\Domain\Audit\Actor;
use App\Domain\Enum\BookingStatus;
use App\Notifications\CustomerMailLines;
use App\Notifications\IcsBuilder;
use App\Notifications\Move;
use App\Notifications\MoveNotice;
use App\Notifications\TenantClock;
use App\Notifications\TenantTime;
use App\Notifications\TenantUser;
use App\Notifications\WhoActed;
use App\Site\SiteLanguage;
use App\Tenancy\Role;
use Tests\Support\FixedDirectory;
use Tests\Support\FixtureDay;
use Tests\Support\LangLines;
use Tests\Support\SnapshotFixture;

/*
 * A booking moved to another time, as the customer reads it and as the business reads it: the
 * start it left, the start it has now, both in the tenant's clock, and for an appointment
 * somebody already keeps in a diary, the file that updates it.
 *
 * The start it left comes off the record, because the old items are gone by the time any
 * message runs; the start it has now is read off the booking as it stands, so the headline, the
 * items and the calendar file agree. The fixture moved from the Tuesday at 10:00 to the
 * Wednesday at 15:00; 15:00 in Athens is 13:00 in the row, and neither row hour may appear.
 *
 * A request still in front of the owner has had no calendar file and gets none here; a booking
 * gets the same entry at the revision it was handed, which is a number the booking's rows give
 * and never one the message chose.
 *
 * The booking's facts are lines of decision.php read through a translator over the lang
 * directory, and an instant is ICU's medium date and short time; the customer's words are
 * customer-mail.php's moved group, read through the same translator with both starts filled in
 * as ICU spells them.
 */

beforeEach(function () {
    LangLines::bind();
});

afterEach(function () {
    LangLines::release();
});

const MOVE_STAMP = '2026-11-02 06:00:00';

function movedStamp(): DateTimeImmutable
{
    return new DateTimeImmutable(MOVE_STAMP, new DateTimeZone('UTC'));
}

function movedBooking(bool $wasARequest = false): BookingSnapshot
{
    return SnapshotFixture::booking($wasARequest ? BookingStatus::AwaitingApproval : BookingStatus::Confirmed, day: '2026-11-04', items: [['Haircut', 'Elena Papadaki', false, 15]]);
}

function movedMessage(bool $wasARequest = false, bool $byTheCustomer = true, int $revision = 1, string $tenant = 'Luna Hair Studio', string $timezone = 'Europe/Athens'): Message
{
    return Move::of(
        movedBooking($wasARequest),
        $tenant,
        new TenantClock(new DateTimeZone($timezone)),
        SnapshotFixture::at(FixtureDay::START),
        $wasARequest,
        $byTheCustomer,
        movedStamp(),
        $revision,
    );
}

/**
 * Who moved it, as WhoActed answers an actor: the customer on the booking, or Demo Owner, the
 * user with id 4.
 */
function movedBy(Actor $actor): WhoActed
{
    return WhoActed::of($actor, movedBooking()->person(), new FixedDirectory([new TenantUser(4, 'Demo Owner', 'owner@luna.example', Role::Owner)]), 'Example Bookings');
}

function movedNotice(bool $wasARequest = false, ?Actor $actor = null, bool $managesBookings = true): Message
{
    return MoveNotice::of(
        movedBooking($wasARequest),
        'Luna Hair Studio',
        new TenantClock(new DateTimeZone('Europe/Athens')),
        new Recipient('owner@luna.example', 4, $managesBookings),
        SnapshotFixture::at(FixtureDay::START),
        $wasARequest,
        movedBy($actor ?? Actor::customer()),
    );
}

/**
 * One line of business-mail.php's move_notice group read through the translator, the business
 * and the two starts filled in as movedLine fills the customer's, the name of who moved it where
 * the line names one.
 *
 * @param  array<string, string>  $replace
 */
function movedNoticeLine(string $key, array $replace = []): string
{
    $clock = new TenantClock(new DateTimeZone('Europe/Athens'));

    return LangLines::line('business-mail.move_notice.'.$key, [
        'business' => 'Luna Hair Studio',
        'from' => TenantTime::instant(SnapshotFixture::at(FixtureDay::START), $clock, SiteLanguage::English),
        'to' => TenantTime::instant(movedBooking()->startsAt(), $clock, SiteLanguage::English),
        ...$replace,
    ]);
}

/**
 * One line of customer-mail.php's moved group read through the translator, the business and the
 * two starts filled in: the start the fixture left, the Tuesday at 10:00, and the start it has
 * now, the Wednesday at 15:00, each as TenantTime spells it in the tenant's clock.
 */
function movedLine(string $key): string
{
    $clock = new TenantClock(new DateTimeZone('Europe/Athens'));

    return LangLines::line('customer-mail.moved.'.$key, [
        'business' => 'Luna Hair Studio',
        'from' => TenantTime::instant(SnapshotFixture::at(FixtureDay::START), $clock, SiteLanguage::English),
        'to' => TenantTime::instant(movedBooking()->startsAt(), $clock, SiteLanguage::English),
    ]);
}

/**
 * Every message of the move, each built when its case runs, after the translator is bound.
 *
 * @return array<string, array{Closure(): Message}>
 */
function movedMessages(): array
{
    return [
        'a booking the customer moved' => [fn (): Message => movedMessage()],
        'a request the customer moved' => [fn (): Message => movedMessage(wasARequest: true)],
        'a booking the business moved' => [fn (): Message => movedMessage(byTheCustomer: false)],
        'a request the business moved' => [fn (): Message => movedMessage(wasARequest: true, byTheCustomer: false)],
        'the notice about a booking' => [fn (): Message => movedNotice()],
        'the notice about a request' => [fn (): Message => movedNotice(wasARequest: true)],
    ];
}

it('tells the customer under the moved key, to the address on the booking, about that booking', function () {
    $message = movedMessage();

    expect($message->key)->toBe(MessageKey::Moved)
        ->and($message->to)->toEqual(new Recipient('casey@example.test'))
        ->and($message->bookingUuid)->toBe(SnapshotFixture::UUID)
        ->and($message->subject)->toBe(movedLine('booking.subject'))
        ->and($message->language)->toBe('en')
        ->and($message->body)->toContain('Casey Customer')
        ->and($message->body)->toContain('Haircut')
        ->and($message->body)->toContain('35.00 EUR');
});

it('names the start it left and the start it has now, both in the tenant clock, whichever message it is', function (Message $message) {
    $left = strpos($message->body, '3 Nov 2026, 10:00');
    $now = strpos($message->body, '4 Nov 2026, 15:00');

    // The start it left first, then the start it has now, as the sentence carrying both reads
    // them; the customer's sentence itself is each form's own case below.
    expect($left)->toBeInt()
        ->and($now)->toBeInt()
        ->and($left < $now)->toBeTrue()
        // 08:00 and 13:00 are what the rows held and hold, in UTC. Neither is an hour anybody
        // is expected at.
        ->and($message->body)->not->toContain(', 08:00')
        ->and($message->body)->not->toContain(', 13:00');
})->with(movedMessages(...));

it('carries the calendar file for a booking, at the revision it was handed, and says what the file does', function () {
    $booking = movedBooking();
    $message = movedMessage(revision: 2);

    expect($message->attachments)->toHaveCount(1)
        ->and($message->attachments[0]->name)->toBe('booking.ics')
        ->and($message->attachments[0]->contentType)->toBe('text/calendar')
        ->and($message->attachments[0]->contents)->toBe(IcsBuilder::file($booking, CustomerMailLines::summary($booking, 'Luna Hair Studio', SiteLanguage::English), movedStamp(), 2))
        ->and($message->attachments[0]->contents)->toContain(icsSequenceLine(2))
        ->and($message->attachments[0]->contents)->toContain('DTSTART:20261104T130000Z')
        ->and($message->body)->toContain(movedLine('calendar'));
});

it('carries no calendar file for a request, because none has been sent for it and the approval will send the first', function () {
    $message = movedMessage(wasARequest: true);

    expect($message->subject)->toBe(movedLine('request.subject'))
        ->and($message->attachments)->toBe([])
        ->and($message->body)->toContain(movedLine('request.by_customer'))
        ->and($message->body)->toContain(movedLine('unchanged'))
        ->and($message->body)->not->toContain(movedLine('calendar'))
        ->and($message->body)->not->toContain(movedLine('booking.by_customer'))
        ->and($message->body)->not->toContain(movedLine('awaiting_payment'));
});

it('carries no calendar file for a booking moved while awaiting its payment and says it still awaits the payment while the row does, promising no other message, because the approval sent no file and the confirmation, switchable apart, sends the first', function () {
    $moved = fn (BookingStatus $now, bool $byTheCustomer = true): Message => Move::of(
        SnapshotFixture::booking($now, day: '2026-11-04', items: [['Haircut', 'Elena Papadaki', false, 15]]),
        'Luna Hair Studio',
        new TenantClock(new DateTimeZone('Europe/Athens')),
        SnapshotFixture::at(FixtureDay::START),
        false,
        $byTheCustomer,
        movedStamp(),
        0,
        true,
    );
    $waiting = $moved(BookingStatus::AwaitingPayment);
    $paidSince = $moved(BookingStatus::Confirmed);
    $theirs = $moved(BookingStatus::AwaitingPayment, false);

    $total = LangLines::line('decision.facts.total', ['amount' => '35.00 EUR']);
    $zone = LangLines::line('customer-mail.zone', ['zone' => 'Europe/Athens', 'business' => 'Luna Hair Studio']);

    expect($waiting->subject)->toBe(movedLine('booking.subject'))
        ->and($waiting->attachments)->toBe([])
        ->and($waiting->body)->toContain(movedLine('booking.by_customer'))
        // The confirmation goes under a key whose switch this message never reads, so nothing here
        // promises it or its file: the one paragraph after the total is the sentence that it still
        // awaits its payment.
        ->and($waiting->body)->toContain($total."\n\n".movedLine('awaiting_payment')."\n\n".$zone)
        ->and($waiting->body)->not->toContain(movedLine('calendar'))
        ->and($paidSince->attachments)->toBe([])
        ->and($paidSince->body)->not->toContain(movedLine('awaiting_payment'))
        ->and($paidSince->body)->not->toContain(movedLine('calendar'))
        ->and($paidSince->body)->toContain($total."\n\n".$zone)
        ->and($theirs->attachments)->toBe([])
        ->and($theirs->body)->toContain($total."\n\n".movedLine('awaiting_payment').' '.movedLine('unexpected')."\n\n".$zone)
        ->and($theirs->body)->not->toContain(movedLine('calendar'))
        ->and(movedMessage()->attachments)->toHaveCount(1);
});

it('says nothing else about the request has changed only while the row still awaits a decision, because a request approved before the queue reached the move is a booking by the time it is read', function () {
    // The record said a request moved; the row, re-read when the message goes, says the owner
    // has since approved it. The words that name what moved stay the record's, and the sentence
    // about the request as it stands is dropped, because it is no longer true of it.
    $decided = fn (bool $byTheCustomer): Message => Move::of(
        SnapshotFixture::booking(BookingStatus::Confirmed, day: '2026-11-04', items: [['Haircut', 'Elena Papadaki', false, 15]]),
        'Luna Hair Studio',
        new TenantClock(new DateTimeZone('Europe/Athens')),
        SnapshotFixture::at('2026-11-03 10:00'),
        true,
        $byTheCustomer,
        movedStamp(),
        0,
    );
    $own = $decided(true);
    $theirs = $decided(false);

    expect(movedMessage(wasARequest: true)->body)->toContain(movedLine('unchanged'))
        ->and($own->body)->not->toContain(movedLine('unchanged'))
        ->and($own->subject)->toBe(movedLine('request.subject'))
        ->and($own->body)->toContain(movedLine('request.by_customer'))
        ->and($own->attachments)->toBe([])
        // No blank paragraph where the sentence was: the footer follows the total.
        ->and($own->body)->not->toContain("\n\n\n")
        ->and($own->body)->toContain(LangLines::line('decision.facts.total', ['amount' => '35.00 EUR'])."\n\n".LangLines::line('customer-mail.zone', ['zone' => 'Europe/Athens', 'business' => 'Luna Hair Studio']))
        // Where the business moved it, who to ask is still said; it is true whatever was decided.
        ->and($theirs->body)->not->toContain(movedLine('unchanged'))
        ->and($theirs->body)->toContain(movedLine('request.by_business'))
        ->and($theirs->body)->toContain(movedLine('unexpected'));
});

it('confirms the customer\'s own move back to them as their act, and tells them of the business\'s as its act with who to ask', function () {
    $own = movedMessage()->body;
    $theirs = movedMessage(byTheCustomer: false)->body;

    expect($own)->toContain(movedLine('booking.by_customer'))
        ->and($own)->not->toContain(movedLine('unexpected'))
        ->and($own)->not->toContain(movedLine('booking.by_business'))
        ->and($theirs)->toContain(movedLine('booking.by_business'))
        ->and($theirs)->toContain(movedLine('calendar').' '.movedLine('unexpected'))
        ->and($theirs)->not->toContain(movedLine('booking.by_customer'));
});

it('tells the business under the move_notice key, to the one recipient it was built for, naming who moved it', function () {
    $message = movedNotice();

    expect($message->key)->toBe(MessageKey::MoveNotice)
        ->and($message->to)->toEqual(new Recipient('owner@luna.example', 4, true))
        ->and($message->bookingUuid)->toBe(SnapshotFixture::UUID)
        ->and($message->subject)->toBe(movedNoticeLine('booking.subject'))
        ->and($message->body)->toStartWith(movedNoticeLine('booking.opening')."\n\n")
        ->and($message->body)->toContain(movedNoticeLine('by.customer', ['customer' => 'Casey Customer']))
        ->and($message->body)->toContain('35.00 EUR')
        ->and($message->language)->toBe('en')
        ->and($message->attachments)->toBe([])
        ->and(movedNotice(actor: Actor::user(4))->body)->toContain(movedNoticeLine('by.user', ['staff' => 'Demo Owner']))
        ->and(movedNotice(actor: Actor::user(4))->body)->not->toContain(movedNoticeLine('by.customer', ['customer' => 'Casey Customer']));
});

it('tells the business a moved request is a request, and nothing about what has since been decided', function () {
    $message = movedNotice(wasARequest: true);

    // Its own subject and opening and none of the booking form's; that it says nothing else,
    // nothing about a decision among it, is BusinessMailKeysTest's, which holds the form whole to
    // its keys.
    expect($message->subject)->toBe(movedNoticeLine('request.subject'))
        ->and($message->body)->toStartWith(movedNoticeLine('request.opening')."\n\n")
        ->and($message->body)->not->toContain(movedNoticeLine('booking.opening'))
        ->and($message->subject)->not->toBe(movedNotice()->subject);
});

it('tells a business recipient how to reach the customer only where that recipient manages bookings', function () {
    $manager = movedNotice()->body;
    $chair = movedNotice(managesBookings: false)->body;

    expect($manager)->toContain('casey@example.test')
        ->and($manager)->toContain('+30 210 7654321')
        ->and($chair)->toContain('Casey Customer')
        ->and($chair)->not->toContain('casey@example.test')
        ->and($chair)->not->toContain('+30 210 7654321');
});

it('names the zone once, because a time nobody can place is worse than a line of housekeeping', function (Message $message) {
    expect(substr_count($message->body, 'Europe/Athens'))->toBe(1);
})->with(movedMessages(...));

it('names the customer clock to the customer and the business own clock to the business', function () {
    expect(movedMessage()->body)->toContain(LangLines::line('customer-mail.zone', ['zone' => 'Europe/Athens', 'business' => 'Luna Hair Studio']))
        ->and(movedNotice()->body)->toContain(LangLines::line('decision.facts.zone', ['zone' => 'Europe/Athens']));
});

it('carries no magic link and no identifier anybody could act on, whichever message it is', function (Message $message) {
    expect($message->body)->not->toContain('http')
        ->and($message->body)->not->toContain('/manage/')
        ->and($message->body)->not->toContain($message->bookingUuid);
})->with(movedMessages(...));

it('keeps the credential and the identifier out of the file too, since a calendar entry gets forwarded', function () {
    $file = movedMessage()->attachments[0]->contents;

    expect($file)->not->toContain('/manage/')
        ->and($file)->not->toContain('http')
        ->and($file)->toContain('UID:'.SnapshotFixture::UUID);
});

it('is ASCII, like every template', function (Message $message) {
    expect(preg_match('/\A[\x09\x0a\x20-\x7e]+\z/', $message->body))->toBe(1)
        ->and(preg_match('/\A[\x20-\x7e]+\z/', $message->subject))->toBe(1);
})->with(movedMessages(...));
