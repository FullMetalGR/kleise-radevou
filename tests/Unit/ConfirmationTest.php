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
use App\Notifications\BookAgainLine;
use App\Notifications\Confirmation;
use App\Notifications\CustomerMailLines;
use App\Notifications\IcsBuilder;
use App\Notifications\TenantClock;
use App\Site\SiteLanguage;
use App\Tenancy\Slug;
use App\Tenancy\TenantUrls;
use Tests\Support\LangLines;

/*
 * What the customer is told when the slot is theirs: the items with their times, the total,
 * their magic link, and the calendar file.
 *
 * The times are the tenant's, named once. The staff member is named where the row is not the
 * tenant's implicit one, which is the same rule the rest of the product draws its screens by and
 * needs no entitlement lookup to apply.
 *
 * The total renders in the currency the booking row carries and never in the tenant's current
 * default: changing the setting must not restate a price somebody has already agreed to.
 *
 * The booking's facts are lines of decision.php read through a translator over the lang directory,
 * and an instant is ICU's medium date and short time. Its own words are lines of customer-mail.php,
 * read in the booking's language, English here; CustomerMailKeysTest holds the whole body to its
 * keys. The heading over the Important info is the review step's key of booking.php, Book again and
 * the line to My bookings customer-mail.php's, each read in the same language.
 */

beforeEach(function () {
    LangLines::bind();
});

afterEach(function () {
    LangLines::release();
});

/**
 * A confirmed booking of one tenant, its items spelled as [service, staff, implicit, hour].
 *
 * @param  list<array{string, string, bool, int}>  $items
 */
function confirmedBooking(array $items = [['Haircut', 'Elena Papadaki', false, 10]], string $currency = 'EUR', string $timezone = 'Europe/Athens'): BookingSnapshot
{
    $zone = new DateTimeZone($timezone);
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
        new Customer('Casey Customer', 'casey@example.test', null),
        new Money(3500 * count($items), Currency::of($currency)),
        $snapshots,
    );
}

const CONFIRMED_LINK = 'https://luna.example.test/manage/7b7f0e5a-0000-4000-8000-000000000001?token=abcdef';

/** Book again as the job hands it over for the default booking: Haircut, service 7, on Elena, staff 3. */
const CONFIRMED_AGAIN = 'https://luna.example.test/?service=7&staff=3';

function confirmedMessage(BookingSnapshot $booking, ?string $link = CONFIRMED_LINK, string $tenant = 'Luna Hair Studio', string $timezone = 'Europe/Athens', string $again = CONFIRMED_AGAIN, ?string $myBookings = null): Message
{
    return Confirmation::of($booking, $tenant, new TenantClock(new DateTimeZone($timezone)), $link, new DateTimeImmutable('2026-10-01 07:00:00', new DateTimeZone('UTC')), $again, myBookings: $myBookings);
}

it('is the confirmed message of the catalog, to the address on the booking, about that booking and no other', function () {
    $message = confirmedMessage(confirmedBooking());

    expect($message->key)->toBe(MessageKey::Confirmed)
        ->and($message->to)->toEqual(new Recipient('casey@example.test'))
        ->and($message->bookingUuid)->toBe('7b7f0e5a-0000-4000-8000-000000000001')
        ->and($message->subject)->toBe(LangLines::line('customer-mail.confirmed.subject', ['business' => 'Luna Hair Studio']))
        ->and($message->body)->toStartWith(LangLines::line('customer-mail.greeting', ['customer' => 'Casey Customer'])."\n\n".LangLines::line('customer-mail.confirmed.confirmed', ['business' => 'Luna Hair Studio']));
});

it('carries one attachment, which is this booking calendar file', function () {
    $booking = confirmedBooking();
    $message = confirmedMessage($booking);

    expect($message->attachments)->toHaveCount(1)
        ->and($message->attachments[0]->name)->toBe('booking.ics')
        ->and($message->attachments[0]->contentType)->toBe('text/calendar')
        ->and($message->attachments[0]->contents)->toBe(
            IcsBuilder::file($booking, CustomerMailLines::summary($booking, 'Luna Hair Studio', SiteLanguage::English), new DateTimeImmutable('2026-10-01 07:00:00', new DateTimeZone('UTC')), 0),
        );
});

it('renders every item in the tenant clock and not in the clock the row is stored in', function () {
    $body = confirmedMessage(confirmedBooking([['Colour', 'Elena Papadaki', false, 10], ['Haircut', 'Nikos Georgiou', false, 12]]))->body;

    expect($body)->toContain('3 Nov 2026, 10:00')
        ->and($body)->toContain('3 Nov 2026, 12:00')
        // 08:00 and 10:00 are what the row holds, in UTC. Neither hour may be presented as the
        // time of the appointment.
        ->and($body)->not->toContain(', 08:00');
});

it('names the zone once, because a time nobody can place is worse than a line of housekeeping', function () {
    $body = confirmedMessage(confirmedBooking())->body;

    expect(substr_count($body, 'Europe/Athens'))->toBe(1)
        ->and($body)->toContain(LangLines::line('customer-mail.zone', ['zone' => 'Europe/Athens', 'business' => 'Luna Hair Studio']));
});

it('names the staff member of every item whose row is not the implicit one', function () {
    $named = confirmedMessage(confirmedBooking([['Haircut', 'Elena Papadaki', false, 10]]))->body;
    $implicit = confirmedMessage(
        confirmedBooking([['Initial assessment', 'Pier Physiotherapy', true, 10]], 'GBP', 'Europe/London'),
        tenant: 'Pier Physiotherapy',
        timezone: 'Europe/London',
    )->body;

    expect($named)->toContain(LangLines::line('decision.facts.item', ['service' => 'Haircut', 'staff' => 'Elena Papadaki']))
        ->and($implicit)->toContain("    Initial assessment\n")
        // The business is named where the message speaks of the business, and nowhere as the
        // person the appointment is with.
        ->and($implicit)->not->toContain(LangLines::line('decision.facts.item', ['service' => 'Initial assessment', 'staff' => 'Pier Physiotherapy']));
});

it('totals in the currency the booking row carries and never in another', function () {
    $pier = confirmedMessage(
        confirmedBooking([['Initial assessment', 'Pier Physiotherapy', true, 10]], 'GBP', 'Europe/London'),
        tenant: 'Pier Physiotherapy',
        timezone: 'Europe/London',
    )->body;

    expect($pier)->toContain(LangLines::line('decision.facts.total', ['amount' => '35.00 GBP']))
        ->and($pier)->not->toContain('EUR')
        ->and(confirmedMessage(confirmedBooking())->body)->toContain(LangLines::line('decision.facts.total', ['amount' => '35.00 EUR']));
});

it('carries the magic link where the confirming request had one', function () {
    $body = confirmedMessage(confirmedBooking())->body;

    expect($body)->toContain(LangLines::line('customer-mail.confirmed.manage')."\n\n    ".CONFIRMED_LINK)
        // Once, and nowhere else: a body quoted into a support ticket leaks one thing and not
        // two.
        ->and(substr_count($body, 'abcdef'))->toBe(1);
});

it('says nothing about a manage link where the confirmation was reached without one, Book again its one link', function () {
    $body = confirmedMessage(confirmedBooking(), link: null)->body;

    // Book again is the hosted page with a preselection, which asks nobody who they are: no
    // credential and no booking anybody could act on.
    expect(seamLinksIn($body))->toBe([CONFIRMED_AGAIN])
        ->and($body)->not->toContain('/manage/')
        ->and($body)->not->toContain('7b7f0e5a-0000-4000-8000-000000000001')
        ->and($body)->toContain('3 Nov 2026, 10:00')
        ->and($body)->toContain('35.00 EUR');
});

it('carries the tenant\'s Important info under its heading where set, after the booking and before the way back to it', function () {
    $info = "Please arrive five minutes early.\nWe take cash only.";
    $body = Confirmation::of(confirmedBooking(), 'Luna Hair Studio', new TenantClock(new DateTimeZone('Europe/Athens')), CONFIRMED_LINK, new DateTimeImmutable('2026-10-01 07:00:00', new DateTimeZone('UTC')), CONFIRMED_AGAIN, importantInfo: $info)->body;

    $heading = LangLines::line(Confirmation::IMPORTANT_INFO_HEADING);
    // Split at the paragraph: once in the body, the booking before it and the link after it.
    $around = explode($heading."\n".$info, $body);

    // One heading for the review step and the mail: the review step's own key of booking.php.
    expect(Confirmation::IMPORTANT_INFO_HEADING)->toBe('booking.review.important_info')
        ->and($around)->toHaveCount(2)
        ->and(substr_count($body, $heading))->toBe(1)
        ->and($around[0])->toContain('35.00 EUR')
        ->and($around[0])->not->toContain(CONFIRMED_LINK)
        ->and($around[1] ?? '')->toContain(CONFIRMED_LINK);
});

it('carries no Important info heading where the tenant set none', function () {
    expect(confirmedMessage(confirmedBooking())->body)->not->toContain(LangLines::line(Confirmation::IMPORTANT_INFO_HEADING));
});

it('carries Book again, the first item\'s service and its staff member, after the clock\'s line and before the closing line', function () {
    $booking = confirmedBooking([['Colour', 'Elena Papadaki', false, 10], ['Haircut', 'Nikos Georgiou', false, 12]]);
    $again = BookAgainLine::for(new TenantUrls('https://kleiseradevou.gr'), Slug::of('luna'), $booking);
    $body = confirmedMessage($booking, again: $again, myBookings: 'https://luna.kleiseradevou.gr/en/my-bookings')->body;
    $line = LangLines::line('customer-mail.book_again', ['link' => 'https://luna.kleiseradevou.gr/en?service=7&staff=3']);

    expect($again)->toBe('https://luna.kleiseradevou.gr/en?service=7&staff=3')
        ->and($body)->toEndWith(
            LangLines::line('customer-mail.zone', ['zone' => 'Europe/Athens', 'business' => 'Luna Hair Studio'])
            ."\n\n".$line
            ."\n\n".LangLines::line('customer-mail.my_bookings', ['link' => 'https://luna.kleiseradevou.gr/en/my-bookings']),
        )
        ->and(substr_count($body, $line))->toBe(1);
});

it('leaves the staff out of Book again where the first item runs on the implicit member', function () {
    $booking = confirmedBooking([['Initial assessment', 'Pier Physiotherapy', true, 10]], 'GBP', 'Europe/London');
    $again = BookAgainLine::for(new TenantUrls('https://kleiseradevou.gr'), Slug::of('pier'), $booking);
    $body = confirmedMessage($booking, tenant: 'Pier Physiotherapy', timezone: 'Europe/London', again: $again)->body;

    expect($body)->toEndWith("\n\n".LangLines::line('customer-mail.book_again', ['link' => 'https://pier.kleiseradevou.gr/en?service=7']))
        ->and($body)->not->toContain('staff=');
});

it('keeps the credential out of the file that travels with it', function () {
    $message = confirmedMessage(confirmedBooking());

    expect($message->attachments[0]->contents)->not->toContain('abcdef')
        ->and($message->attachments[0]->contents)->not->toContain('/manage/')
        ->and($message->attachments[0]->contents)->not->toContain('?service=');
});

it('is ASCII, like every template', function () {
    $message = confirmedMessage(confirmedBooking());

    expect(preg_match('/\A[\x09\x0a\x20-\x7e]+\z/', $message->body))->toBe(1)
        ->and(preg_match('/\A[\x20-\x7e]+\z/', $message->subject))->toBe(1);
});
