<?php

use App\Application\Dto\BookingItemSnapshot;
use App\Application\Dto\BookingSnapshot;
use App\Application\Dto\Customer;
use App\Application\Notification\Message;
use App\Application\Notification\MessageKey;
use App\Application\Notification\Recipient;
use App\Domain\Enum\BookingStatus;
use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use App\Notifications\CustomerNote;
use App\Notifications\NewBookingNotice;
use App\Notifications\TenantClock;
use Tests\Support\LangLines;

/*
 * What the business is told when a customer books and settles it themselves.
 *
 * It carries no magic link, and that is the point to guard. The link is the customer's
 * credential for their own booking, and the business reaches the same booking through the
 * admin as themselves. A link in this message would put a customer's credential into every
 * owner's and manager's mailbox, and into whatever their mail rules forward it to.
 *
 * One recipient per message, so the rule that names four people produces four messages and a
 * refused mailbox silences none of the others. Which of them is told how to reach the customer
 * is asked of the recipient rather than of the template: an owner and a manager hold
 * manageBookings, a staff user and a staff row's own mailbox do not, and the message they get
 * says whose booking it is and nothing about where that person lives.
 *
 * The booking's facts are lines of decision.php read through a translator over the lang
 * directory, and an instant is ICU's medium date and short time; the message's own words are
 * business-mail.php's, read the same way, and BusinessMailKeysTest holds every form to its
 * keys.
 */

beforeEach(function () {
    LangLines::bind();
});

afterEach(function () {
    LangLines::release();
});

/**
 * A booking on luna, its items spelled as [service, staff, implicit, hour].
 *
 * @param  list<array{string, string, bool, int}>  $items
 */
function noticedBooking(array $items = [['Haircut', 'Elena Papadaki', false, 10]], string $currency = 'EUR', ?string $phone = '+30 210 1234567'): BookingSnapshot
{
    $zone = new DateTimeZone('Europe/Athens');
    $utc = new DateTimeZone('UTC');
    $snapshots = [];

    foreach ($items as $sort => [$service, $staff, $implicit, $hour]) {
        $start = (new DateTimeImmutable('2026-11-03 00:00', $zone))->add(new DateInterval(sprintf('PT%dH', $hour)));

        $snapshots[] = new BookingItemSnapshot(
            $sort,
            7 + $sort,
            $service,
            3 + $sort,
            $staff,
            $implicit,
            $start->setTimezone($utc),
            $start->add(new DateInterval('PT1H'))->setTimezone($utc),
            new Money(3500, Currency::of($currency)),
        );
    }

    return new BookingSnapshot(
        '7b7f0e5a-0000-4000-8000-000000000001',
        BookingStatus::Confirmed,
        null,
        null,
        new Customer('Casey Customer', 'casey@example.test', $phone),
        new Money(3500 * count($items), Currency::of($currency)),
        $snapshots,
    );
}

function noticeMessage(BookingSnapshot $booking, ?Recipient $to = null, string $tenant = 'Luna Hair Studio', ?string $note = null): Message
{
    return NewBookingNotice::of($booking, $tenant, new TenantClock(new DateTimeZone('Europe/Athens')), $to ?? noticeOwner(), $note);
}

/**
 * The recipient the rule's first limb names: a user who runs the diary, and therefore one who
 * holds manageBookings, which the customer's contact details hang on.
 */
function noticeOwner(): Recipient
{
    return new Recipient('owner@luna.example', 4, true);
}

it('is the new_booking message of the catalog, to the one recipient it was built for', function () {
    $message = noticeMessage(noticedBooking());

    expect($message->key)->toBe(MessageKey::NewBooking)
        ->and($message->to)->toEqual(noticeOwner())
        ->and($message->bookingUuid)->toBe('7b7f0e5a-0000-4000-8000-000000000001')
        ->and($message->subject)->toContain('Luna Hair Studio');
});

it('carries no magic link and nothing that could be a token', function () {
    $body = noticeMessage(noticedBooking())->body;

    expect($body)->not->toContain('http')
        ->and($body)->not->toContain('/manage/')
        ->and($body)->not->toContain('token');
});

it('says who booked and how to reach them, which is what the business needs from it', function () {
    $body = noticeMessage(noticedBooking())->body;

    expect($body)->toContain('Casey Customer')
        ->and($body)->toContain('casey@example.test')
        ->and($body)->toContain('+30 210 1234567');
});

it('carries the customer\'s note under its heading, after who they are and before the total, to every recipient', function () {
    $note = 'Allergic to lavender.';

    foreach ([noticeOwner(), new Recipient('elena@luna.example', 9), new Recipient('chair@luna.example')] as $to) {
        expect(noticeMessage(noticedBooking(), $to, note: $note)->body)->toContain("\n\n".LangLines::line(CustomerNote::HEADING)."\n".$note."\n\n".LangLines::line('decision.facts.total', ['amount' => '35.00 EUR']));
    }

    expect(noticeMessage(noticedBooking(), note: $note)->body)->toContain(LangLines::line('decision.facts.phone').": +30 210 1234567\n\n".LangLines::line(CustomerNote::HEADING)."\nAllergic to lavender.\n\n".LangLines::line('decision.facts.total', ['amount' => '35.00 EUR']));
});

it('says nothing of a note where the customer wrote none, no heading over nothing', function () {
    expect(noticeMessage(noticedBooking())->body)->not->toContain(LangLines::line(CustomerNote::HEADING))
        ->and(noticeMessage(noticedBooking())->body)->toContain(LangLines::line('decision.facts.phone').": +30 210 1234567\n\n".LangLines::line('decision.facts.total', ['amount' => '35.00 EUR']));
});

it('leaves out the phone line for a customer who gave no phone, rather than printing an empty one', function () {
    $body = noticeMessage(noticedBooking(phone: null))->body;

    expect($body)->toContain('casey@example.test')
        ->and($body)->not->toContain(LangLines::line('decision.facts.phone'))
        // The address is the last line of the block, with one blank line before the total and
        // not two: a label with nothing after it reads as a message that lost something.
        ->and($body)->toContain(LangLines::line('decision.facts.email').": casey@example.test\n\n".LangLines::line('decision.facts.total', ['amount' => '35.00 EUR']));
});

it('leaves the phone line out for a blank phone as for a missing one, because a space is not a number', function () {
    expect(noticeMessage(noticedBooking(phone: '   '))->body)->not->toContain(LangLines::line('decision.facts.phone'));
});

it('tells a recipient without manageBookings whose booking it is and never how to reach them, because a message is a surface like any other', function () {
    // customer_email and customer_phone require manageBookings, and the rule names two
    // recipients who do not hold it. A staff user hears about the bookings on their own row and
    // a staff row's own mailbox is nobody at all; both are told the name, because a name is how
    // a person recognises their own morning.
    $staffUser = noticeMessage(noticedBooking(), new Recipient('elena@luna.example', 9));
    $staffRow = noticeMessage(noticedBooking(), new Recipient('chair@luna.example'));

    foreach ([$staffUser, $staffRow] as $message) {
        expect($message->body)->toContain('Casey Customer')
            ->and($message->body)->toContain('Haircut')
            ->and($message->body)->toContain('3 Nov 2026, 10:00')
            ->and($message->body)->toContain(LangLines::line('decision.facts.total', ['amount' => '35.00 EUR']))
            ->and($message->body)->toContain(LangLines::line('decision.facts.customer').': Casey Customer')
            ->and($message->body)->not->toContain('casey@example.test')
            ->and($message->body)->not->toContain('+30 210 1234567')
            ->and($message->body)->not->toContain(LangLines::line('decision.facts.email'))
            ->and($message->body)->not->toContain(LangLines::line('decision.facts.phone'));
    }
});

it('shows a recipient nobody classified the less rather than the more, because a whitelist that defaults open is not one', function () {
    expect(noticeMessage(noticedBooking(), new Recipient('somebody@luna.example'))->body)
        ->not->toContain('casey@example.test');
});

it('renders the items in the tenant clock, naming the zone once', function () {
    $body = noticeMessage(noticedBooking([['Colour', 'Elena Papadaki', false, 10], ['Haircut', 'Nikos Georgiou', false, 12]]))->body;

    expect($body)->toContain('3 Nov 2026, 10:00')
        ->and($body)->toContain('3 Nov 2026, 12:00')
        ->and($body)->not->toContain(', 08:00')
        ->and($body)->toContain(LangLines::line('decision.facts.zone', ['zone' => 'Europe/Athens']))
        ->and(substr_count($body, 'Europe/Athens'))->toBe(1);
});

it('names the staff member of an item whose row is not the implicit one, and no name at all where it is', function () {
    expect(noticeMessage(noticedBooking())->body)->toContain(LangLines::line('decision.facts.item', ['service' => 'Haircut', 'staff' => 'Elena Papadaki']))
        ->and(noticeMessage(noticedBooking([['Initial assessment', 'Pier Physiotherapy', true, 10]]), tenant: 'Pier Physiotherapy')->body)
        ->toContain("    Initial assessment\n")
        ->not->toContain(LangLines::line('decision.facts.item', ['service' => 'Initial assessment', 'staff' => 'Pier Physiotherapy']));
});

it('totals in the currency the booking row carries', function () {
    expect(noticeMessage(noticedBooking(currency: 'GBP'))->body)->toContain(LangLines::line('decision.facts.total', ['amount' => '35.00 GBP']))
        ->and(noticeMessage(noticedBooking())->body)->toContain(LangLines::line('decision.facts.total', ['amount' => '35.00 EUR']));
});

it('carries no attachment, because the calendar file is the customer diary entry and not the diary the business keeps', function () {
    expect(noticeMessage(noticedBooking())->attachments)->toBe([]);
});

it('is ASCII, like every template', function () {
    $message = noticeMessage(noticedBooking());

    expect(preg_match('/\A[\x09\x0a\x20-\x7e]+\z/', $message->body))->toBe(1)
        ->and(preg_match('/\A[\x20-\x7e]+\z/', $message->subject))->toBe(1);
});

it('says in its paid form that the payment landed for an approved booking, now confirmed, and carries the rest as the checkout form does', function () {
    $zone = new TenantClock(new DateTimeZone('Europe/Athens'));
    $paid = NewBookingNotice::of(noticedBooking(), 'Luna Hair Studio', $zone, noticeOwner(), 'Allergic to lavender.', paid: true);
    $checkout = noticeMessage(noticedBooking(), note: 'Allergic to lavender.');
    $after = fn (Message $message): string => substr($message->body, (int) strpos($message->body, "\n\n"));

    expect($paid->key)->toBe(MessageKey::NewBooking)
        ->and($paid->to)->toEqual(noticeOwner())
        ->and($paid->subject)->toBe(LangLines::line(NewBookingNotice::PAID_SUBJECT, ['business' => 'Luna Hair Studio']))
        ->and($paid->body)->toStartWith(LangLines::line(NewBookingNotice::PAID_OPENING, ['business' => 'Luna Hair Studio'])."\n\n")
        ->and($after($paid))->toBe($after($checkout))
        ->and($checkout->subject)->toBe(LangLines::line(NewBookingNotice::SUBJECT, ['business' => 'Luna Hair Studio']))
        ->and($checkout->body)->toStartWith(LangLines::line(NewBookingNotice::OPENING, ['business' => 'Luna Hair Studio'])."\n\n")
        ->and($paid->subject)->not->toBe($checkout->subject)
        ->and($paid->body)->not->toContain('http')
        ->and($paid->attachments)->toBe([])
        ->and(preg_match('/\A[\x09\x0a\x20-\x7e]+\z/', $paid->body))->toBe(1)
        ->and(preg_match('/\A[\x20-\x7e]+\z/', $paid->subject))->toBe(1);
});
