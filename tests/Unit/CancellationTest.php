<?php

use App\Application\Notification\Message;
use App\Application\Notification\MessageKey;
use App\Application\Notification\Recipient;
use App\Domain\Audit\Actor;
use App\Domain\Enum\BookingStatus;
use App\Notifications\Cancellation;
use App\Notifications\CancellationNotice;
use App\Notifications\TenantClock;
use App\Notifications\TenantUser;
use App\Notifications\WhoActed;
use App\Tenancy\Role;
use Tests\Support\FixedDirectory;
use Tests\Support\LangLines;
use Tests\Support\SnapshotFixture;

/*
 * A booking given up, as the customer reads it and as the business reads it.
 *
 * The customer's message has four forms and each is asserted, because they differ in the one
 * sentence that has to be true of the case: who did it, and whether there was ever an
 * appointment to cancel. A request nobody had answered is withdrawn; a confirmed appointment is
 * cancelled; the customer is told what they did, or told what the business did and who to ask.
 *
 * The business's notice goes every time and names who acted, one sentence per shape of actor
 * WhoActed tells apart, the name it hands filled in; here the answer is built from an actor,
 * because turning an actor into a shape is that class's test, and BusinessMailKeysTest holds
 * every shape's sentence to its key.
 *
 * The times are the tenant's, named once. Neither message carries a file, a link or an
 * identifier: there is nothing left to keep and nothing left to manage.
 *
 * The booking's facts are lines of decision.php read through a translator over the lang
 * directory, and an instant is ICU's medium date and short time; the customer's words are
 * customer-mail.php's, read through the same translator, every form in its own words and in no
 * other form's, and the business's business-mail.php's.
 */

beforeEach(function () {
    LangLines::bind();
});

afterEach(function () {
    LangLines::release();
});

function cancelledMessage(bool $wasARequest = false, bool $byTheCustomer = true, string $tenant = 'Luna Hair Studio', string $timezone = 'Europe/Athens'): Message
{
    return Cancellation::of(
        SnapshotFixture::booking(BookingStatus::Cancelled),
        $tenant,
        new TenantClock(new DateTimeZone($timezone)),
        $wasARequest,
        $byTheCustomer,
    );
}

/**
 * Who cancelled it, as WhoActed answers an actor: the customer on the booking, or Demo Owner,
 * the user with id 4.
 */
function cancelledBy(Actor $actor): WhoActed
{
    $booking = SnapshotFixture::booking(BookingStatus::Cancelled);

    return WhoActed::of($actor, $booking->person(), new FixedDirectory([new TenantUser(4, 'Demo Owner', 'owner@luna.example', Role::Owner)]), 'Example Bookings');
}

function cancelledNotice(bool $wasARequest = false, ?Actor $actor = null, bool $managesBookings = true): Message
{
    return CancellationNotice::of(
        SnapshotFixture::booking(BookingStatus::Cancelled),
        'Luna Hair Studio',
        new TenantClock(new DateTimeZone('Europe/Athens')),
        new Recipient('owner@luna.example', 4, $managesBookings),
        $wasARequest,
        cancelledBy($actor ?? Actor::customer()),
    );
}

/**
 * A line of the business's cancellation notice, read through the translator, the business filled
 * in where the line names it.
 *
 * @param  array<string, string>  $replace
 */
function cancelledNoticeLine(string $key, array $replace = []): string
{
    return LangLines::line('business-mail.cancellation_notice.'.$key, $replace);
}

/**
 * The customer's four forms as customer-mail.php's cancelled group keys them, by what was
 * cancelled and by who did it.
 *
 * @return list<string>
 */
function cancelledForms(): array
{
    return ['request.by_customer', 'request.by_business', 'booking.by_customer', 'booking.by_business'];
}

/**
 * Asserts a message to the customer is the one form named, its subject, its opening and what
 * follows from it read through the translator with the business filled in, and that no other
 * form's opening or closing paragraph is in it.
 */
function expectCancelledForm(Message $message, string $form): void
{
    $line = fn (string $form, string $key): string => LangLines::line('customer-mail.cancelled.'.$form.'.'.$key, ['business' => 'Luna Hair Studio']);

    expect($message->subject)->toBe($line($form, 'subject'))
        ->and($message->body)->toContain($line($form, 'cancelled'))
        ->and($message->body)->toContain($line($form, 'means'))
        ->and($message->language)->toBe('en');

    foreach (array_diff(cancelledForms(), [$form]) as $other) {
        expect($message->body)->not->toContain($line($other, 'cancelled'))
            ->and($message->body)->not->toContain($line($other, 'means'))
            ->and($message->subject)->not->toBe($line($other, 'subject'));
    }
}

/**
 * Every form of both messages, so a rule that holds over all of them is asserted over all of
 * them rather than over whichever one was being written at the time, each built when its case
 * runs, after the translator is bound.
 *
 * @return array<string, array{Closure(): Message}>
 */
function cancelledMessages(): array
{
    return [
        'a booking the customer cancelled' => [fn (): Message => cancelledMessage()],
        'a request the customer withdrew' => [fn (): Message => cancelledMessage(wasARequest: true)],
        'a booking the business cancelled' => [fn (): Message => cancelledMessage(byTheCustomer: false)],
        'a request the business cancelled' => [fn (): Message => cancelledMessage(wasARequest: true, byTheCustomer: false)],
        'the notice about a booking' => [fn (): Message => cancelledNotice()],
        'the notice about a request' => [fn (): Message => cancelledNotice(wasARequest: true)],
        'the notice about a user cancelling' => [fn (): Message => cancelledNotice(actor: Actor::user(4))],
    ];
}

it('tells the customer under the cancelled key, to the address on the booking, about that booking', function () {
    $message = cancelledMessage();

    expect($message->key)->toBe(MessageKey::Cancelled)
        ->and($message->to)->toEqual(new Recipient('casey@example.test'))
        ->and($message->bookingUuid)->toBe(SnapshotFixture::UUID)
        ->and($message->subject)->toContain('Luna Hair Studio')
        ->and($message->body)->toContain('Casey Customer')
        ->and($message->body)->toContain('Haircut')
        ->and($message->attachments)->toBe([]);
});

it('confirms the customer\'s own cancellation of a booking back to them, as their act', function () {
    expectCancelledForm(cancelledMessage(), 'booking.by_customer');
});

it('calls a request the customer gave up withdrawn, because no appointment ever existed to cancel', function () {
    expectCancelledForm(cancelledMessage(wasARequest: true), 'request.by_customer');
});

it('tells the customer the business cancelled their booking, and who to ask, because that is the one case they did not expect', function () {
    expectCancelledForm(cancelledMessage(byTheCustomer: false), 'booking.by_business');
});

it('tells the customer the business cancelled their request, without calling it an appointment', function () {
    expectCancelledForm(cancelledMessage(wasARequest: true, byTheCustomer: false), 'request.by_business');
});

it('tells the business under the cancellation_notice key, to the one recipient it was built for, with the total the morning was worth', function () {
    $message = cancelledNotice();

    expect($message->key)->toBe(MessageKey::CancellationNotice)
        ->and($message->to)->toEqual(new Recipient('owner@luna.example', 4, true))
        ->and($message->bookingUuid)->toBe(SnapshotFixture::UUID)
        ->and($message->subject)->toBe(cancelledNoticeLine('booking.subject', ['business' => 'Luna Hair Studio']))
        ->and($message->body)->toStartWith(cancelledNoticeLine('booking.opening', ['business' => 'Luna Hair Studio'])."\n\n")
        ->and($message->body)->toContain('Haircut')
        ->and($message->body)->toContain('35.00 EUR')
        ->and($message->body)->toContain(cancelledNoticeLine('booking.means'))
        ->and($message->language)->toBe('en')
        ->and($message->attachments)->toBe([]);
});

it('names who cancelled it in the sentence for whoever that was, before what follows from it', function () {
    $byTheCustomer = cancelledNoticeLine('by.customer', ['customer' => 'Casey Customer']);
    $byTheOwner = cancelledNoticeLine('by.user', ['staff' => 'Demo Owner']);

    expect(cancelledNotice()->body)->toContain($byTheCustomer.' '.cancelledNoticeLine('booking.means'))
        ->and(cancelledNotice(actor: Actor::user(4))->body)->toContain($byTheOwner.' '.cancelledNoticeLine('booking.means'))
        ->and(cancelledNotice(actor: Actor::user(4))->body)->not->toContain($byTheCustomer);
});

it('tells the business a cancelled request is one with nothing left to decide, rather than a time that came free', function () {
    $message = cancelledNotice(wasARequest: true);

    expect($message->subject)->toBe(cancelledNoticeLine('request.subject', ['business' => 'Luna Hair Studio']))
        ->and($message->body)->toStartWith(cancelledNoticeLine('request.opening', ['business' => 'Luna Hair Studio'])."\n\n")
        ->and($message->body)->toContain(cancelledNoticeLine('request.means'))
        ->and($message->body)->not->toContain(cancelledNoticeLine('booking.means'))
        ->and($message->subject)->not->toBe(cancelledNotice()->subject);
});

it('tells a business recipient how to reach the customer only where that recipient manages bookings', function () {
    $manager = cancelledNotice()->body;
    $chair = cancelledNotice(managesBookings: false)->body;

    expect($manager)->toContain('casey@example.test')
        ->and($manager)->toContain('+30 210 7654321')
        ->and($chair)->toContain('Casey Customer')
        ->and($chair)->not->toContain('casey@example.test')
        ->and($chair)->not->toContain('+30 210 7654321');
});

it('renders every item in the tenant clock and not in the clock the row is stored in', function (Message $message) {
    expect($message->body)->toContain('3 Nov 2026, 10:00')
        ->and($message->body)->not->toContain(', 08:00');
})->with(cancelledMessages(...));

it('names the zone once, because a time nobody can place is worse than a line of housekeeping', function (Message $message) {
    expect(substr_count($message->body, 'Europe/Athens'))->toBe(1);
})->with(cancelledMessages(...));

it('names the customer clock to the customer and the business own clock to the business', function () {
    expect(cancelledMessage()->body)->toContain(LangLines::line('customer-mail.zone', ['zone' => 'Europe/Athens', 'business' => 'Luna Hair Studio']))
        ->and(cancelledNotice()->body)->toContain(LangLines::line('decision.facts.zone', ['zone' => 'Europe/Athens']));
});

it('carries no magic link and no identifier anybody could act on, whichever message it is', function (Message $message) {
    expect($message->body)->not->toContain('http')
        ->and($message->body)->not->toContain('/manage/')
        ->and($message->body)->not->toContain($message->bookingUuid);
})->with(cancelledMessages(...));

it('is ASCII, like every template', function (Message $message) {
    expect(preg_match('/\A[\x09\x0a\x20-\x7e]+\z/', $message->body))->toBe(1)
        ->and(preg_match('/\A[\x20-\x7e]+\z/', $message->subject))->toBe(1);
})->with(cancelledMessages(...));
