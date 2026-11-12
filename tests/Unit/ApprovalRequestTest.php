<?php

use App\Application\Dto\BookingItemSnapshot;
use App\Application\Dto\BookingSnapshot;
use App\Application\Dto\Customer;
use App\Application\Notification\Message;
use App\Application\Notification\MessageKey;
use App\Application\Notification\Recipient;
use App\Domain\Enum\BookingStatus;
use App\Domain\Enum\HoldClass;
use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use App\Notifications\ApprovalLinks;
use App\Notifications\ApprovalRequest;
use App\Notifications\CustomerNote;
use App\Notifications\TenantClock;
use Tests\Support\LangLines;

/*
 * What the business is asked when a customer has requested something a human has to say yes to.
 *
 * Two things make it different from every other notification. It carries a credential, so what
 * it says has to be right for exactly one person: the links are minted per user and the copy
 * that reaches a mailbox with nobody behind it carries none. And it is read by people with
 * different permissions, so what each of them is told about the customer follows the same rule
 * the new-booking notice and the decision page follow.
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
 * A request on luna, its items spelled as [service, staff, implicit, hour].
 *
 * A settled one is the same booking after somebody answered it: the status has moved on and the
 * hold columns are cleared, which is what every transition out of awaiting_approval writes.
 *
 * @param  list<array{string, string, bool, int}>  $items
 */
function requestedBooking(array $items = [['Bridal trial', 'Elena Papadaki', false, 9]], ?string $phone = '+30 210 1234567', bool $settled = false): BookingSnapshot
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
            $start->add(new DateInterval('PT2H'))->setTimezone($utc),
            new Money(15000, Currency::of('EUR')),
        );
    }

    return new BookingSnapshot(
        '7b7f0e5a-0000-4000-8000-000000000001',
        $settled ? BookingStatus::Rejected : BookingStatus::AwaitingApproval,
        $settled ? null : HoldClass::Approval,
        $settled ? null : new DateTimeImmutable('2026-11-04 06:00', $utc),
        new Customer('Casey Customer', 'casey@example.test', $phone),
        new Money(15000 * count($items), Currency::of('EUR')),
        $snapshots,
    );
}

function requestApprovalLinks(): ApprovalLinks
{
    return new ApprovalLinks(
        'http://luna.localhost:8000/decide/7b7f0e5a-0000-4000-8000-000000000001/approve?expires=1&user=4&signature=abc',
        'http://luna.localhost:8000/decide/7b7f0e5a-0000-4000-8000-000000000001/reject?expires=1&user=4&signature=def',
    );
}

function requestMessage(?Recipient $to = null, ?ApprovalLinks $links = null, ?BookingSnapshot $booking = null, bool $stillWaiting = true, ?string $note = null): Message
{
    return ApprovalRequest::of(
        $booking ?? requestedBooking(),
        'Luna Hair Studio',
        new TenantClock(new DateTimeZone('Europe/Athens')),
        $to ?? new Recipient('owner@luna.example', 4, true),
        $links ?? requestApprovalLinks(),
        $stillWaiting,
        $note,
    );
}

/**
 * The same request to a mailbox with nobody behind it: a staff row's own address, which has no
 * user for a link to decide as. The request itself is still waiting; there is simply nobody
 * here to act as.
 */
function requestToAMailbox(Recipient $to): Message
{
    return ApprovalRequest::of(
        requestedBooking(),
        'Luna Hair Studio',
        new TenantClock(new DateTimeZone('Europe/Athens')),
        $to,
        null,
        true,
        null,
    );
}

/**
 * The request as it reaches a mailbox after somebody has answered it, which a queue that ran
 * late produces: the announcement still goes out, and there is nothing left for anybody to
 * press. Built directly rather than through requestMessage, whose null links stand for the
 * owner's pair: a settled request carries none, and the constructor refuses the pair with it.
 */
function requestAlreadySettled(?Recipient $to = null): Message
{
    return ApprovalRequest::of(
        requestedBooking(settled: true),
        'Luna Hair Studio',
        new TenantClock(new DateTimeZone('Europe/Athens')),
        $to ?? new Recipient('owner@luna.example', 4, true),
        null,
        false,
        null,
    );
}

it('is the approval_request message of the catalog, to the one recipient it was built for', function () {
    $message = requestMessage();

    expect($message->key)->toBe(MessageKey::ApprovalRequest)
        ->and($message->to->address)->toBe('owner@luna.example')
        ->and($message->bookingUuid)->toBe('7b7f0e5a-0000-4000-8000-000000000001')
        ->and($message->subject)->toContain('Luna Hair Studio')
        ->and($message->attachments)->toBe([]);
});

it('says what is being asked: the customer, the items, their times in the tenant clock, and the total', function () {
    $body = requestMessage()->body;

    expect($body)->toContain(LangLines::line('decision.facts.customer').': Casey Customer')
        ->toContain(LangLines::line('decision.facts.item', ['service' => 'Bridal trial', 'staff' => 'Elena Papadaki']))
        // 09:00 in Athens is 07:00 in the row: the business is told the hour their own diary
        // means.
        ->toContain('3 Nov 2026, 09:00')
        ->not->toContain(', 07:00')
        ->toContain(LangLines::line('decision.facts.total', ['amount' => '150.00 EUR']))
        ->toContain(LangLines::line('decision.facts.zone', ['zone' => 'Europe/Athens']))
        ->and(substr_count($body, 'Europe/Athens'))->toBe(1);
});

it('carries the two links the recipient can act with, and each of them once', function () {
    $links = requestApprovalLinks();
    $body = requestMessage(links: $links)->body;

    expect(substr_count($body, $links->approve))->toBe(1)
        ->and(substr_count($body, $links->reject))->toBe(1);
});

it('offers nobody a way to decide a request that has already been settled, because a sentence is true of the case it is printed for', function () {
    $body = requestAlreadySettled()->body;

    // The window a link carries is the hold's own, so a booking settled between the hold and the
    // job has none to sign and the message carries no link; that it carries none is the
    // constructor's refusal below, not this fixture's, whose links are null by construction. What
    // it may not do is print the sentence the linkless copy prints for a mailbox with nobody
    // behind it: nobody can do anything about this one.
    expect($body)->not->toContain(LangLines::line('business-mail.links.none'))
        ->not->toContain(LangLines::line('business-mail.approval_request.waiting.without_links', ['business' => 'Luna Hair Studio']))
        ->not->toContain(LangLines::line('business-mail.approval_request.waiting.with_links', ['business' => 'Luna Hair Studio']))
        ->toStartWith(LangLines::line('business-mail.approval_request.settled.opening', ['business' => 'Luna Hair Studio'])."\n\n")
        ->toContain(LangLines::line('business-mail.approval_request.settled.nothing_left'))
        ->toContain('Bridal trial')
        ->and(requestAlreadySettled()->subject)->toBe(LangLines::line('business-mail.approval_request.settled.subject', ['business' => 'Luna Hair Studio']))
        ->and(requestAlreadySettled()->subject)->not->toBe(requestMessage()->subject);
});

it('carries no link at all for a mailbox with nobody behind it, and says what is true of that copy: it cannot decide, and who can from theirs', function () {
    $body = requestToAMailbox(new Recipient('chair@luna.example'))->body;

    expect($body)->not->toContain('http')
        ->not->toContain('/decide/')
        // The reader holds hours on the row the booking is on and never signs in. The one
        // sentence true of their copy is that it decides nothing and who can from their own:
        // an owner or manager, and a user linked to that same row, since the recipient rule
        // gives every user a pair. The copy carries that one line and none of the request's
        // other copies' words, so the sentence that told a staff member they could decide from
        // a copy with no links in it cannot come back by another road; and the line names no
        // login, where a linked recipient decides from a signed link without signing in.
        ->toContain(LangLines::line('business-mail.links.none'))
        ->not->toContain(LangLines::line('business-mail.approval_request.waiting.with_links', ['business' => 'Luna Hair Studio']))
        ->toStartWith(LangLines::line('business-mail.approval_request.waiting.without_links', ['business' => 'Luna Hair Studio'])."\n\n")
        ->and(LangLines::line('business-mail.links.none'))->not->toContain('login')
        ->and($body)
        ->toContain('Bridal trial')
        ->toContain('Luna Hair Studio');
});

it('refuses to be built with links for a request that has been settled, because a link decides as the hold it was minted for and a settled booking has none', function () {
    expect(fn () => requestMessage(links: requestApprovalLinks(), booking: requestedBooking(settled: true), stillWaiting: false))
        ->toThrow(InvalidArgumentException::class, 'settled');
});

it('calls the thing a request in the body as it does in the subject, and asks the recipient holding the links for their own decision', function () {
    expect(requestMessage()->body)->toStartWith(LangLines::line('business-mail.approval_request.waiting.with_links', ['business' => 'Luna Hair Studio'])."\n\n")
        ->and(requestMessage()->subject)->toBe(LangLines::line('business-mail.approval_request.waiting.subject', ['business' => 'Luna Hair Studio']))
        ->and(requestToAMailbox(new Recipient('chair@luna.example'))->subject)->toBe(requestMessage()->subject);
});

it('tells a recipient without manageBookings whose request it is and never how to reach them, because a message is a surface like any other', function () {
    // A staff user hears about the bookings on their own row and a staff row's own mailbox is
    // nobody at all, and neither holds manageBookings.
    $staffUser = requestMessage(new Recipient('elena@luna.example', 9), requestApprovalLinks());
    $staffRow = requestToAMailbox(new Recipient('chair@luna.example'));

    foreach ([$staffUser, $staffRow] as $message) {
        expect($message->body)->toContain('Casey Customer')
            ->toContain('Bridal trial')
            ->not->toContain('casey@example.test')
            ->not->toContain('+30 210 1234567');
    }

    expect(requestMessage()->body)->toContain('casey@example.test')
        ->toContain('+30 210 1234567');
});

it('shows a recipient nobody classified the less rather than the more, because a whitelist that defaults open is not one', function () {
    expect(requestMessage(new Recipient('somebody@luna.example', 11), requestApprovalLinks())->body)
        ->not->toContain('casey@example.test');
});

it('leaves out the phone line for a customer who gave no phone, rather than printing an empty one', function () {
    expect(requestMessage(booking: requestedBooking(phone: null))->body)->not->toContain(LangLines::line('decision.facts.phone'))
        ->and(requestMessage(booking: requestedBooking(phone: '   '))->body)->not->toContain(LangLines::line('decision.facts.phone'));
});

it('times every segment of a chain, because a chain can sit hours apart', function () {
    $body = requestMessage(booking: requestedBooking([
        ['Colour', 'Elena Papadaki', false, 9],
        ['Bridal trial', 'Nikos Georgiou', false, 12],
    ]))->body;

    expect($body)->toContain('3 Nov 2026, 09:00')
        ->toContain('3 Nov 2026, 12:00')
        ->toContain(LangLines::line('decision.facts.total', ['amount' => '300.00 EUR']));
});

it('names the staff member of an item whose row is not the implicit one, and no name at all where it is', function () {
    expect(requestMessage(booking: requestedBooking([['Initial assessment', 'Pier Physiotherapy', true, 9]]))->body)
        ->toContain("    Initial assessment\n")
        ->not->toContain(LangLines::line('decision.facts.item', ['service' => 'Initial assessment', 'staff' => 'Pier Physiotherapy']))
        ->and(requestMessage()->body)->toContain(LangLines::line('decision.facts.item', ['service' => 'Bridal trial', 'staff' => 'Elena Papadaki']));
});

it('carries the customer\'s note under its heading, after who they are and before the total, to every recipient', function () {
    $note = "Allergic to lavender.\nPlease use the quiet room.";

    foreach ([new Recipient('owner@luna.example', 4, true), new Recipient('elena@luna.example', 9), new Recipient('chair@luna.example')] as $to) {
        $body = requestMessage($to, note: $note)->body;

        expect($body)->toContain(LangLines::line(CustomerNote::HEADING)."\n".$note."\n\n".LangLines::line('decision.facts.total', ['amount' => '150.00 EUR']))
            ->and(strpos($body, 'Casey Customer'))->toBeLessThan((int) strpos($body, LangLines::line(CustomerNote::HEADING)));
    }

    expect(CustomerNote::HEADING)->toBe('business-mail.note.heading');
});

it('says nothing of a note where the customer wrote none, no heading over nothing', function () {
    expect(requestMessage()->body)->not->toContain(LangLines::line(CustomerNote::HEADING))
        ->and(requestMessage()->body)->toContain(LangLines::line('decision.facts.email').": casey@example.test\n".LangLines::line('decision.facts.phone').": +30 210 1234567\n\n".LangLines::line('decision.facts.total', ['amount' => '150.00 EUR']));
});

it('is ASCII, like every template', function () {
    $message = requestMessage();

    expect(preg_match('/\A[\x09\x0a\x20-\x7e]+\z/', $message->body))->toBe(1)
        ->and(preg_match('/\A[\x20-\x7e]+\z/', $message->subject))->toBe(1);
});
