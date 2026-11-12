<?php

use App\Application\Dto\BookingSnapshot;
use App\Application\MyBookings\RequestBookingsLink;
use App\Application\Notification\Message;
use App\Domain\Enum\BookingStatus;
use App\Notifications\Acknowledgement;
use App\Notifications\BookAgainLine;
use App\Notifications\BookingsLink;
use App\Notifications\Cancellation;
use App\Notifications\Confirmation;
use App\Notifications\Move;
use App\Notifications\MyBookingsLine;
use App\Notifications\Reminder;
use App\Notifications\RequestApproved;
use App\Notifications\RequestExpired;
use App\Notifications\RequestRejected;
use App\Notifications\TenantClock;
use App\Notifications\TenantTime;
use App\Notifications\ThankYou;
use App\Site\SiteLanguage;
use App\Tenancy\Languages;
use Tests\Support\KeyedLines;
use Tests\Support\SnapshotFixture;

/*
 * Every customer message as keys: every form and every subject rendered through the keyed table,
 * whose every line is its own key in brackets with the placeholders the English names after it,
 * so each body below is held whole, byte for byte, to keys, the values that fill them, and the
 * template's own layout: the blank lines between paragraphs, the booking's indented lines, and
 * the links. A sentence left in a class would be words outside a bracket. The instant is ICU's in
 * the booking's language, never a key, and the zone is the footer's own placeholder; so is a
 * move's two starts, and the bookings link's lifetime is ICU's spelled number in its own
 * placeholder.
 *
 * The lines every customer message shares are keys too: Book again and the line to My bookings,
 * each with its link as a placeholder, and the heading over the Important info, the review step's
 * own key of booking.php. Each form is built with no My bookings page, so the closing line is
 * absent from every body but the one case that pins its place.
 *
 * And the booking's language: every line a template reads is asked of the translator in the
 * language the snapshot carries, bookings.language, never the application's locale, the Greek
 * booking's in el and the English one's in en, the instant spelled by ICU in the same language,
 * and the calendar file's description with it; the bookings link, about no booking, in the
 * language its job hands over, the booking's here.
 */

beforeEach(function () {
    KeyedLines::bind();
});

afterEach(function () {
    KeyedLines::release();
});

const CMK_TENANT = 'Luna Hair Studio';

const CMK_LINK = 'https://luna.kleiseradevou.gr/en/manage/7b7f0e5a-0000-4000-8000-000000000005?token=abc';

const CMK_PAY = 'https://luna.kleiseradevou.gr/en/pay/7b7f0e5a-0000-4000-8000-000000000005/link?expires=1&signature=s';

const CMK_AGAIN = 'https://luna.kleiseradevou.gr/en?service=7&staff=3';

const CMK_REVIEWS = 'https://g.page/r/luna-hair-studio/review';

const CMK_BOOKINGS = 'https://luna.kleiseradevou.gr/en/my-bookings/0f0f0f0f';

const CMK_INFO = "Arrive five minutes early.\nCash only.";

function cmkClock(): TenantClock
{
    return new TenantClock(new DateTimeZone('Europe/Athens'));
}

function cmkStamp(): DateTimeImmutable
{
    return new DateTimeImmutable('2026-11-02 06:00:00', new DateTimeZone('UTC'));
}

/**
 * The start a moved booking left, the Monday at 09:00 in Athens; the start it has is the
 * fixture's, the Tuesday at 10:00.
 */
function cmkMovedFrom(): DateTimeImmutable
{
    return SnapshotFixture::at('2026-11-02 09:00');
}

/**
 * How many minutes the bookings link opens for, spelled out by ICU in English, the one language
 * the keyed table holds.
 */
function cmkLifetime(): string
{
    $spelled = (new NumberFormatter('en', NumberFormatter::SPELLOUT))->format((new DateInterval(RequestBookingsLink::LIFETIME))->i);
    assert(is_string($spelled));

    return $spelled;
}

/**
 * The fixture's booking, a Haircut with Elena at 10:00 in Athens for Casey Customer, 35.00 EUR,
 * made in the language given.
 */
function cmkBooking(BookingStatus $status = BookingStatus::Confirmed, string $language = 'en'): BookingSnapshot
{
    return SnapshotFixture::booking($status, language: $language);
}

/**
 * A line of customer-mail.php as the keyed table writes it, the values given filled in.
 */
function cmk(string $key, string ...$values): string
{
    return KeyedLines::line('customer-mail.'.$key, ...$values);
}

/**
 * The booking's one item and its total as BookingLines writes them through the keyed table.
 *
 * @return array{string, string}
 */
function cmkFacts(SiteLanguage $language = SiteLanguage::English): array
{
    $start = cmkBooking()->startsAt();

    return [
        '    '.KeyedLines::line('decision.facts.item', 'Haircut', 'Elena Papadaki')."\n    ".TenantTime::instant($start, cmkClock(), $language),
        KeyedLines::line('decision.facts.total', '35.00 EUR'),
    ];
}

/**
 * The value of one property of a calendar file, unfolded and its escapes read back.
 */
function cmkIcs(string $file, string $property): string
{
    $lines = [];

    foreach (explode("\r\n", rtrim($file, "\r\n")) as $line) {
        if ($lines !== [] && str_starts_with($line, ' ')) {
            $lines[count($lines) - 1] .= substr($line, 1);

            continue;
        }

        $lines[] = $line;
    }

    foreach ($lines as $line) {
        if (str_starts_with($line, $property.':')) {
            return str_replace(['\\n', '\\,', '\\;', '\\\\'], ["\n", ',', ';', '\\'], substr($line, strlen($property) + 1));
        }
    }

    throw new RuntimeException(sprintf('The file carries no %s.', $property));
}

/**
 * Every form of the six, built over the booking given, as the jobs build them with no My bookings
 * page.
 *
 * @return array<string, Closure(BookingSnapshot): Message>
 */
function cmkForms(): array
{
    return [
        'the acknowledgement' => fn (BookingSnapshot $booking): Message => Acknowledgement::of($booking, CMK_TENANT, cmkClock(), CMK_LINK),
        'the confirmation with its manage link' => fn (BookingSnapshot $booking): Message => Confirmation::of($booking, CMK_TENANT, cmkClock(), CMK_LINK, cmkStamp(), CMK_AGAIN),
        'the confirmation after a payment, the acknowledgement sent' => fn (BookingSnapshot $booking): Message => Confirmation::of($booking, CMK_TENANT, cmkClock(), null, cmkStamp(), CMK_AGAIN, 1, true),
        'the confirmation after a payment, no acknowledgement sent' => fn (BookingSnapshot $booking): Message => Confirmation::of($booking, CMK_TENANT, cmkClock(), null, cmkStamp(), CMK_AGAIN, 1, false),
        'the approval, the acknowledgement sent' => fn (BookingSnapshot $booking): Message => RequestApproved::of($booking, CMK_TENANT, cmkClock(), cmkStamp(), true),
        'the approval, no acknowledgement sent' => fn (BookingSnapshot $booking): Message => RequestApproved::of($booking, CMK_TENANT, cmkClock(), cmkStamp(), false),
        'the approval awaiting payment' => fn (BookingSnapshot $booking): Message => RequestApproved::awaitingPayment($booking, CMK_TENANT, cmkClock(), CMK_PAY),
        'the rejection' => fn (BookingSnapshot $booking): Message => RequestRejected::of($booking, CMK_TENANT, cmkClock()),
        'the timeout' => fn (BookingSnapshot $booking): Message => RequestExpired::of($booking, CMK_TENANT, cmkClock()),
        'the unpaid lapse' => fn (BookingSnapshot $booking): Message => RequestExpired::unpaid($booking, CMK_TENANT, cmkClock()),
        'the confirmation with the tenant\'s Important info' => fn (BookingSnapshot $booking): Message => Confirmation::of($booking, CMK_TENANT, cmkClock(), CMK_LINK, cmkStamp(), CMK_AGAIN, importantInfo: CMK_INFO),
        'the cancellation of a booking by the customer' => fn (BookingSnapshot $booking): Message => Cancellation::of($booking, CMK_TENANT, cmkClock(), false, true),
        'the cancellation of a booking by the business' => fn (BookingSnapshot $booking): Message => Cancellation::of($booking, CMK_TENANT, cmkClock(), false, false),
        'the withdrawal of a request by the customer' => fn (BookingSnapshot $booking): Message => Cancellation::of($booking, CMK_TENANT, cmkClock(), true, true),
        'the cancellation of a request by the business' => fn (BookingSnapshot $booking): Message => Cancellation::of($booking, CMK_TENANT, cmkClock(), true, false),
        'the move of a booking by the customer' => fn (BookingSnapshot $booking): Message => Move::of($booking, CMK_TENANT, cmkClock(), cmkMovedFrom(), false, true, cmkStamp(), 1),
        'the move of a booking by the business' => fn (BookingSnapshot $booking): Message => Move::of($booking, CMK_TENANT, cmkClock(), cmkMovedFrom(), false, false, cmkStamp(), 1),
        'the move of a request by the customer' => fn (BookingSnapshot $booking): Message => Move::of(cmkBooking(BookingStatus::AwaitingApproval, $booking->language), CMK_TENANT, cmkClock(), cmkMovedFrom(), true, true, cmkStamp(), 0),
        'the move of a request by the business' => fn (BookingSnapshot $booking): Message => Move::of(cmkBooking(BookingStatus::AwaitingApproval, $booking->language), CMK_TENANT, cmkClock(), cmkMovedFrom(), true, false, cmkStamp(), 0),
        'the move of a request approved since' => fn (BookingSnapshot $booking): Message => Move::of($booking, CMK_TENANT, cmkClock(), cmkMovedFrom(), true, true, cmkStamp(), 0),
        'the move of a booking awaiting its payment' => fn (BookingSnapshot $booking): Message => Move::of(cmkBooking(BookingStatus::AwaitingPayment, $booking->language), CMK_TENANT, cmkClock(), cmkMovedFrom(), false, true, cmkStamp(), 0, true),
        'the move by the business of a booking paid since' => fn (BookingSnapshot $booking): Message => Move::of($booking, CMK_TENANT, cmkClock(), cmkMovedFrom(), false, false, cmkStamp(), 0, true),
        'the reminder' => fn (BookingSnapshot $booking): Message => Reminder::of($booking, CMK_TENANT, cmkClock(), CMK_AGAIN),
        'the thank-you with the reviews line' => fn (BookingSnapshot $booking): Message => ThankYou::of(cmkBooking(BookingStatus::Completed, $booking->language), CMK_TENANT, CMK_REVIEWS, CMK_AGAIN),
        'the thank-you with no reviews link' => fn (BookingSnapshot $booking): Message => ThankYou::of(cmkBooking(BookingStatus::Completed, $booking->language), CMK_TENANT, null, CMK_AGAIN),
        // About no booking: the language is the request's, which its job hands over, here the
        // booking's so one dataset walks every message in both languages.
        'the bookings link' => fn (BookingSnapshot $booking): Message => BookingsLink::of($booking->person()->email, CMK_TENANT, CMK_BOOKINGS, Languages::ofBooking($booking->language)),
    ];
}

/**
 * Each form's subject and body as the keyed table writes them, for the English booking.
 *
 * @return array<string, array{string, string}>
 */
function cmkExpected(): array
{
    $greeting = cmk('greeting', 'Casey Customer');
    $zone = cmk('zone', 'Europe/Athens', CMK_TENANT);
    [$lines, $total] = cmkFacts();
    $instant = TenantTime::instant(cmkBooking()->startsAt(), cmkClock(), SiteLanguage::English);
    $from = TenantTime::instant(cmkMovedFrom(), cmkClock(), SiteLanguage::English);
    $again = "\n\n".cmk('book_again', CMK_AGAIN);
    $item = '    '.KeyedLines::line('decision.facts.item', 'Haircut', 'Elena Papadaki');

    return [
        'the acknowledgement' => [cmk('acknowledged.subject', CMK_TENANT), implode("\n\n", [
            $greeting,
            cmk('acknowledged.received', 'Haircut', CMK_TENANT),
            '    '.$instant,
            cmk('acknowledged.deciding', CMK_TENANT),
            cmk('acknowledged.manage'),
            '    '.CMK_LINK,
            $zone,
        ])],
        'the confirmation with its manage link' => [cmk('confirmed.subject', CMK_TENANT), implode("\n\n", [
            $greeting,
            cmk('confirmed.confirmed', CMK_TENANT),
            $lines,
            $total,
            cmk('confirmed.calendar'),
            cmk('confirmed.manage'),
            '    '.CMK_LINK,
            $zone,
        ]).$again],
        'the confirmation after a payment, the acknowledgement sent' => [cmk('confirmed.subject', CMK_TENANT), implode("\n\n", [
            $greeting,
            cmk('confirmed.confirmed', CMK_TENANT),
            $lines,
            $total,
            cmk('confirmed.calendar'),
            cmk('confirmed.where_link.acknowledged'),
            $zone,
        ]).$again],
        'the confirmation after a payment, no acknowledgement sent' => [cmk('confirmed.subject', CMK_TENANT), implode("\n\n", [
            $greeting,
            cmk('confirmed.confirmed', CMK_TENANT),
            $lines,
            $total,
            cmk('confirmed.calendar'),
            cmk('confirmed.where_link.unacknowledged', CMK_TENANT),
            $zone,
        ]).$again],
        'the approval, the acknowledgement sent' => [cmk('approved.confirmed.subject', CMK_TENANT), implode("\n\n", [
            $greeting,
            cmk('approved.confirmed.approved', CMK_TENANT),
            $lines,
            $total,
            cmk('approved.confirmed.where_link.acknowledged'),
            $zone,
        ])],
        'the approval, no acknowledgement sent' => [cmk('approved.confirmed.subject', CMK_TENANT), implode("\n\n", [
            $greeting,
            cmk('approved.confirmed.approved', CMK_TENANT),
            $lines,
            $total,
            cmk('approved.confirmed.where_link.unacknowledged', CMK_TENANT),
            $zone,
        ])],
        'the approval awaiting payment' => [cmk('approved.awaiting_payment.subject', CMK_TENANT), implode("\n\n", [
            $greeting,
            cmk('approved.awaiting_payment.approved', CMK_TENANT),
            $lines,
            $total,
            cmk('approved.awaiting_payment.pay'),
            CMK_PAY,
            cmk('approved.awaiting_payment.held'),
            $zone,
        ])],
        'the rejection' => [cmk('rejected.subject', CMK_TENANT), implode("\n\n", [
            $greeting,
            cmk('rejected.declined', CMK_TENANT),
            $lines,
            cmk('rejected.free_again'),
            $zone,
        ])],
        'the timeout' => [cmk('request_expired.timed_out.subject', CMK_TENANT), implode("\n\n", [
            $greeting,
            cmk('request_expired.timed_out.expired', CMK_TENANT),
            $lines,
            cmk('request_expired.timed_out.free_again'),
            $zone,
        ])],
        'the unpaid lapse' => [cmk('request_expired.unpaid.subject', CMK_TENANT), implode("\n\n", [
            $greeting,
            cmk('request_expired.unpaid.expired', CMK_TENANT),
            $lines,
            cmk('request_expired.unpaid.free_again'),
            $zone,
        ])],
        'the confirmation with the tenant\'s Important info' => [cmk('confirmed.subject', CMK_TENANT), implode("\n\n", [
            $greeting,
            cmk('confirmed.confirmed', CMK_TENANT),
            $lines,
            $total,
            cmk('confirmed.calendar'),
            // The review step's heading, one key of booking.php for the page and the mail; the
            // owner's text under it as typed.
            KeyedLines::line('booking.review.important_info')."\n".CMK_INFO,
            cmk('confirmed.manage'),
            '    '.CMK_LINK,
            $zone,
        ]).$again],
        'the cancellation of a booking by the customer' => [cmk('cancelled.booking.by_customer.subject', CMK_TENANT), implode("\n\n", [
            $greeting,
            cmk('cancelled.booking.by_customer.cancelled', CMK_TENANT),
            $lines,
            cmk('cancelled.booking.by_customer.means'),
            $zone,
        ])],
        'the cancellation of a booking by the business' => [cmk('cancelled.booking.by_business.subject', CMK_TENANT), implode("\n\n", [
            $greeting,
            cmk('cancelled.booking.by_business.cancelled', CMK_TENANT),
            $lines,
            cmk('cancelled.booking.by_business.means', CMK_TENANT),
            $zone,
        ])],
        'the withdrawal of a request by the customer' => [cmk('cancelled.request.by_customer.subject', CMK_TENANT), implode("\n\n", [
            $greeting,
            cmk('cancelled.request.by_customer.cancelled', CMK_TENANT),
            $lines,
            cmk('cancelled.request.by_customer.means'),
            $zone,
        ])],
        'the cancellation of a request by the business' => [cmk('cancelled.request.by_business.subject', CMK_TENANT), implode("\n\n", [
            $greeting,
            cmk('cancelled.request.by_business.cancelled', CMK_TENANT),
            $lines,
            cmk('cancelled.request.by_business.means', CMK_TENANT),
            $zone,
        ])],
        'the move of a booking by the customer' => [cmk('moved.booking.subject', CMK_TENANT), implode("\n\n", [
            $greeting,
            cmk('moved.booking.by_customer', CMK_TENANT, $from, $instant),
            $lines,
            $total,
            cmk('moved.calendar'),
            $zone,
        ])],
        // Two whole sentences side by side in one paragraph, not a sentence of fragments.
        'the move of a booking by the business' => [cmk('moved.booking.subject', CMK_TENANT), implode("\n\n", [
            $greeting,
            cmk('moved.booking.by_business', CMK_TENANT, $from, $instant),
            $lines,
            $total,
            cmk('moved.calendar').' '.cmk('moved.unexpected', CMK_TENANT),
            $zone,
        ])],
        'the move of a request by the customer' => [cmk('moved.request.subject', CMK_TENANT), implode("\n\n", [
            $greeting,
            cmk('moved.request.by_customer', CMK_TENANT, $from, $instant),
            $lines,
            $total,
            cmk('moved.unchanged'),
            $zone,
        ])],
        'the move of a request by the business' => [cmk('moved.request.subject', CMK_TENANT), implode("\n\n", [
            $greeting,
            cmk('moved.request.by_business', CMK_TENANT, $from, $instant),
            $lines,
            $total,
            cmk('moved.unchanged').' '.cmk('moved.unexpected', CMK_TENANT),
            $zone,
        ])],
        'the move of a request approved since' => [cmk('moved.request.subject', CMK_TENANT), implode("\n\n", [
            $greeting,
            cmk('moved.request.by_customer', CMK_TENANT, $from, $instant),
            $lines,
            $total,
            $zone,
        ])],
        'the move of a booking awaiting its payment' => [cmk('moved.booking.subject', CMK_TENANT), implode("\n\n", [
            $greeting,
            cmk('moved.booking.by_customer', CMK_TENANT, $from, $instant),
            $lines,
            $total,
            cmk('moved.awaiting_payment'),
            $zone,
        ])],
        'the move by the business of a booking paid since' => [cmk('moved.booking.subject', CMK_TENANT), implode("\n\n", [
            $greeting,
            cmk('moved.booking.by_business', CMK_TENANT, $from, $instant),
            $lines,
            $total,
            cmk('moved.unexpected', CMK_TENANT),
            $zone,
        ])],
        'the reminder' => [cmk('reminder.subject', CMK_TENANT), implode("\n\n", [
            $greeting,
            cmk('reminder.reminder', CMK_TENANT),
            $lines,
            $total,
            cmk('reminder.earlier', CMK_TENANT),
            $zone,
        ]).$again],
        'the thank-you with the reviews line' => [cmk('thank_you.subject', CMK_TENANT), implode("\n\n", [
            $greeting,
            cmk('thank_you.thanks', CMK_TENANT),
            $item,
            cmk('thank_you.again'),
            cmk('thank_you.review', CMK_REVIEWS),
        ]).$again],
        'the thank-you with no reviews link' => [cmk('thank_you.subject', CMK_TENANT), implode("\n\n", [
            $greeting,
            cmk('thank_you.thanks', CMK_TENANT),
            $item,
            cmk('thank_you.again'),
        ]).$again],
        'the bookings link' => [cmk('my_bookings_link.subject', CMK_TENANT), implode("\n\n", [
            cmk('my_bookings_link.greeting'),
            cmk('my_bookings_link.here', CMK_TENANT),
            '    '.CMK_BOOKINGS,
            cmk('my_bookings_link.once', cmkLifetime()),
        ])],
    ];
}

it('writes {0} as keys with their values filled in and its own layout around them, its subject one key, and no sentence of its own', function (string $form) {
    $message = cmkForms()[$form](cmkBooking());
    [$subject, $body] = cmkExpected()[$form];

    expect($message->subject)->toBe($subject)
        ->and($message->body)->toBe($body)
        ->and($message->language)->toBe('en');
})->with(fn () => array_keys(cmkForms()));

it('names the calendar file\'s event by the summary\'s key, the booking\'s services at the business, and its items by ItemName\'s key, on the three messages that carry a file', function (string $form) {
    $message = cmkForms()[$form](cmkBooking());

    expect($message->attachments)->toHaveCount(1)
        ->and(cmkIcs($message->attachments[0]->contents, 'SUMMARY'))->toBe(cmk('calendar.summary', 'Haircut', CMK_TENANT))
        ->and(cmkIcs($message->attachments[0]->contents, 'DESCRIPTION'))->toBe(KeyedLines::line('decision.facts.item', 'Haircut', 'Elena Papadaki'));
})->with([
    'the confirmation with its manage link',
    'the confirmation after a payment, the acknowledgement sent',
    'the approval, the acknowledgement sent',
    'the approval, no acknowledgement sent',
    'the move of a booking by the customer',
    'the move of a booking by the business',
]);

it('carries no calendar file on {0}, which has had none or has nothing left to keep', function (string $form) {
    expect(cmkForms()[$form](cmkBooking())->attachments)->toBe([]);
})->with([
    'the cancellation of a booking by the customer',
    'the move of a request by the customer',
    'the move of a request approved since',
    'the move of a booking awaiting its payment',
    'the reminder',
    'the thank-you with the reviews line',
    'the bookings link',
]);

it('lists a chain\'s services in chain order in the acknowledgement and the summary alike, one placeholder each', function () {
    $chain = SnapshotFixture::booking(items: [['Colour', 'Elena Papadaki', false, 10], ['Haircut', 'Nikos Georgiou', false, 12]]);

    expect(Acknowledgement::of($chain, CMK_TENANT, cmkClock(), CMK_LINK)->body)->toContain(cmk('acknowledged.received', 'Colour, Haircut', CMK_TENANT))
        ->and(cmkIcs(Confirmation::of($chain, CMK_TENANT, cmkClock(), CMK_LINK, cmkStamp(), CMK_AGAIN)->attachments[0]->contents, 'SUMMARY'))
        ->toBe(cmk('calendar.summary', 'Colour, Haircut', CMK_TENANT));
});

/**
 * Every line of the files a customer message reads that the keyed table was asked for, with the
 * language asked: customer-mail.php, decision.php's facts and booking.php's one heading.
 *
 * @return list<array{string, string}>
 */
function cmkAsked(): array
{
    return array_values(array_filter(KeyedLines::asked(), fn (array $line): bool => str_starts_with($line[0], 'customer-mail.') || str_starts_with($line[0], 'decision.') || str_starts_with($line[0], 'booking.')));
}

it('asks every line of {0} in the language the booking was made in, Greek for a Greek booking whatever the application\'s locale, its instant ICU\'s Greek and its calendar file\'s description with it', function (string $form) {
    $message = cmkForms()[$form](cmkBooking(language: 'el'));
    $asked = cmkAsked();
    $notGreek = array_values(array_filter($asked, fn (array $line): bool => $line[1] !== 'el'));

    expect(array_filter($asked, fn (array $line): bool => $line[1] === 'el'))->not->toBe([])
        // The bookings link's lifetime is a choice, which the translator reads in the language
        // the file holds the line in, its fallback while the Greek twin is pending, the keyed
        // table holding English alone; every other line of every form is asked in Greek.
        ->and($notGreek)->toBe($form === 'the bookings link' ? [['customer-mail.my_bookings_link.once', 'en']] : [])
        ->and($message->language)->toBe('el');

    if ($message->bookingUuid !== null && $form !== 'the thank-you with the reviews line' && $form !== 'the thank-you with no reviews link') {
        expect($message->body)->toContain(TenantTime::instant(cmkBooking()->startsAt(), cmkClock(), SiteLanguage::Greek))
            ->and($message->body)->not->toContain(TenantTime::instant(cmkBooking()->startsAt(), cmkClock(), SiteLanguage::English));
    }
})->with(fn () => array_keys(cmkForms()));

it('asks every line of {0} in English for a booking made in English', function (string $form) {
    $message = cmkForms()[$form](cmkBooking());
    $asked = cmkAsked();

    expect($asked)->not->toBe([])
        ->and(array_values(array_unique(array_map(fn (array $line): string => $line[1], $asked))))->toBe(['en'])
        ->and($message->language)->toBe('en');
})->with(fn () => array_keys(cmkForms()));

it('asks the calendar file\'s summary in the booking\'s language, as the message beside it is written', function () {
    $message = cmkForms()['the confirmation with its manage link'](cmkBooking(language: 'el'));

    expect(array_values(array_filter(KeyedLines::asked(), fn (array $line): bool => $line[0] === 'customer-mail.calendar.summary')))
        ->toBe([['customer-mail.calendar.summary', 'el']])
        ->and($message->attachments)->toHaveCount(1);
});

it('keeps the line to My bookings last, its key with the page as its link, where the job hands a page over', function () {
    $page = 'https://luna.kleiseradevou.gr/en/my-bookings';
    $message = Acknowledgement::of(cmkBooking(), CMK_TENANT, cmkClock(), CMK_LINK, $page);

    expect($message->body)->toBe(cmkExpected()['the acknowledgement'][1]."\n\n".cmk('my_bookings', $page))
        ->and(MyBookingsLine::closing($page, SiteLanguage::English))->toBe("\n\n".cmk('my_bookings', $page))
        ->and(BookAgainLine::line(CMK_AGAIN, SiteLanguage::English))->toBe("\n\n".cmk('book_again', CMK_AGAIN));
});

it('asks the three shared lines in the language handed in, the booking\'s, Greek for a Greek booking', function () {
    MyBookingsLine::closing('https://luna.kleiseradevou.gr/el/my-bookings', SiteLanguage::Greek);
    BookAgainLine::line('https://luna.kleiseradevou.gr/el?service=7&staff=3', SiteLanguage::Greek);
    Confirmation::of(cmkBooking(language: 'el'), CMK_TENANT, cmkClock(), CMK_LINK, cmkStamp(), CMK_AGAIN, importantInfo: CMK_INFO);

    $asked = array_values(array_filter(cmkAsked(), fn (array $line): bool => in_array($line[0], [MyBookingsLine::LINE, BookAgainLine::LINE, Confirmation::IMPORTANT_INFO_HEADING], true)));

    expect($asked)->toBe([
        [MyBookingsLine::LINE, 'el'],
        [BookAgainLine::LINE, 'el'],
        [Confirmation::IMPORTANT_INFO_HEADING, 'el'],
        [BookAgainLine::LINE, 'el'],
    ]);
});
