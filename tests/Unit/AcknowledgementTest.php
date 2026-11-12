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
use App\Notifications\Acknowledgement;
use App\Notifications\TenantClock;
use Tests\Support\FixtureDay;
use Tests\Support\LangLines;

/*
 * The acknowledgement: what a customer is told when they have asked for something a human has to
 * say yes to.
 *
 * It has one job beyond the words. It is the only carrier of the magic link for a customer whose
 * request is approved later, because an approval carries no token and only the hash is stored.
 * So the link is asserted here rather than left to a reader's eye.
 *
 * The instants render in the tenant's clock and the zone is named once. Naming it on every line
 * would be noise and naming it on none would be a time nobody can act on: a customer reading
 * 10:00 needs to know whose ten it is, once.
 *
 * Its words are lines of customer-mail.php read through a translator over the lang directory, in
 * the booking's language, English here; CustomerMailKeysTest holds the whole body to its keys.
 */

beforeEach(function () {
    LangLines::bind();
});

afterEach(function () {
    LangLines::release();
});

function ackBooking(string ...$services): BookingSnapshot
{
    $items = [];

    foreach (array_values($services === [] ? ['Bridal trial'] : $services) as $sort => $service) {
        $start = FixtureDay::start()->add(new DateInterval(sprintf('PT%dH', $sort)));

        $items[] = new BookingItemSnapshot(
            $sort,
            7 + $sort,
            $service,
            3,
            'Elena Papadaki',
            false,
            $start->setTimezone(new DateTimeZone('UTC')),
            $start->add(new DateInterval('PT1H'))->setTimezone(new DateTimeZone('UTC')),
            new Money(15000, Currency::of('EUR')),
        );
    }

    return new BookingSnapshot(
        '7b7f0e5a-0000-4000-8000-000000000001',
        BookingStatus::AwaitingApproval,
        HoldClass::Approval,
        null,
        new Customer('Perpetua Vandersnoot', 'perpetua@example.test', null),
        new Money(15000, Currency::of('EUR')),
        $items,
    );
}

function ackMessage(BookingSnapshot $booking, string $link = 'https://luna.example.test/manage/7b7f0e5a-0000-4000-8000-000000000001?token=abc'): Message
{
    return Acknowledgement::of($booking, 'Luna Hair Studio', new TenantClock(new DateTimeZone('Europe/Athens')), $link);
}

it('is the acknowledged message of the catalog, to the address on the booking, about that booking and no other', function () {
    $message = ackMessage(ackBooking());

    expect($message->key)->toBe(MessageKey::Acknowledged)
        ->and($message->to)->toEqual(new Recipient('perpetua@example.test'))
        ->and($message->bookingUuid)->toBe('7b7f0e5a-0000-4000-8000-000000000001')
        ->and($message->attachments)->toBe([]);
});

it('carries the magic link, which is the only durable copy the customer gets of it, after the line that says what it opens', function () {
    expect(ackMessage(ackBooking())->body)->toContain(LangLines::line('customer-mail.acknowledged.manage')."\n\n    https://luna.example.test/manage/7b7f0e5a-0000-4000-8000-000000000001?token=abc");
});

it('tells them the request is with the business and names the business doing the deciding', function () {
    $message = ackMessage(ackBooking());

    expect($message->subject)->toBe(LangLines::line('customer-mail.acknowledged.subject', ['business' => 'Luna Hair Studio']))
        ->and($message->body)->toStartWith(LangLines::line('customer-mail.greeting', ['customer' => 'Perpetua Vandersnoot']))
        ->and($message->body)->toContain(LangLines::line('customer-mail.acknowledged.deciding', ['business' => 'Luna Hair Studio']));
});

it('renders the start in the tenant clock and not in the clock the row is stored in, as ICU\'s medium date and short time with no connector word', function () {
    expect(ackMessage(ackBooking())->body)
        ->toContain('3 Nov 2026, 10:00')
        ->not->toContain(' at 10:00')
        ->not->toContain('08:00');
});

it('names the zone once, because a time nobody can place is worse than a line of housekeeping', function () {
    $body = ackMessage(ackBooking())->body;

    expect(substr_count($body, 'Europe/Athens'))->toBe(1)
        ->and($body)->toContain(LangLines::line('customer-mail.zone', ['zone' => 'Europe/Athens', 'business' => 'Luna Hair Studio']));
});

it('names every service of a chain, in chain order, and counts the whole of it as one appointment', function () {
    $body = ackMessage(ackBooking('Colour', 'Bridal trial'))->body;

    expect($body)->toContain(LangLines::line('customer-mail.acknowledged.received', ['service' => 'Colour, Bridal trial', 'business' => 'Luna Hair Studio']))
        ->and(substr_count($body, '3 Nov 2026, 10:00'))->toBe(1);
});

it('is ASCII, like every template', function () {
    $message = ackMessage(ackBooking());

    expect(preg_match('/\A[\x09\x0a\x20-\x7e]+\z/', $message->body))->toBe(1)
        ->and(preg_match('/\A[\x20-\x7e]+\z/', $message->subject))->toBe(1);
});

it('carries no credential anywhere but the link, so a body quoted into a ticket leaks one thing and not two', function () {
    $body = ackMessage(ackBooking())->body;

    expect(substr_count($body, 'abc'))->toBe(1);
});
