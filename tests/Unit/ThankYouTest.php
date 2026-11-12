<?php

use App\Application\Notification\Message;
use App\Application\Notification\MessageKey;
use App\Application\Notification\Recipient;
use App\Domain\Enum\BookingStatus;
use App\Notifications\BookAgainLine;
use App\Notifications\ThankYou;
use App\Site\SiteLanguage;
use Tests\Support\LangLines;
use Tests\Support\SnapshotFixture;

/*
 * The thank-you: what the customer is sent once the business marks their booking completed. It
 * thanks them for their visit, naming the business and what they came for, carries the tenant's
 * Google reviews link under "Leave us a review" where the profile holds one and nothing where not,
 * and the Book again link; the closing line to My bookings is MyBookingsLineTest's.
 *
 * No time, no total and no file: the appointment is over, and nothing about it is left to keep. An
 * item with its staff member is a line of decision.php, read through a translator over the lang
 * directory, and the words are customer-mail.php's thank_you group and its shared lines, read
 * through the same translator.
 */

beforeEach(function () {
    LangLines::bind();
});

afterEach(function () {
    LangLines::release();
});

/**
 * An item with its staff member as the message names it.
 */
function thxItem(string $service, string $staff): string
{
    return LangLines::line('decision.facts.item', ['service' => $service, 'staff' => $staff]);
}

/**
 * One line of customer-mail.php read through the translator, the business filled in with what
 * else is given.
 *
 * @param  array<string, string>  $replace
 */
function thxLine(string $key, array $replace = []): string
{
    return LangLines::line('customer-mail.'.$key, [...$replace, 'business' => 'Luna Hair Studio']);
}

/**
 * The body as the thank-you lays it out: its lines in order, a blank line between each.
 */
function thxBody(string ...$paragraphs): string
{
    return implode("\n\n", $paragraphs);
}

const THX_REVIEWS = 'https://g.page/r/luna-hair-studio/review';

const THX_AGAIN = 'https://luna.kleiseradevou.gr/?service=7&staff=3';

/**
 * @param  list<array{string, string, bool, int}>  $items  each as [service, staff, implicit, hour]
 */
function thxMessage(?string $reviews = THX_REVIEWS, array $items = [['Haircut', 'Elena Papadaki', false, 10]], string $again = THX_AGAIN): Message
{
    return ThankYou::of(SnapshotFixture::booking(BookingStatus::Completed, $items), 'Luna Hair Studio', $reviews, $again);
}

it('thanks the customer under the thank_you key, to the address on the booking, about that booking, with no file', function () {
    $message = thxMessage();

    expect($message->key)->toBe(MessageKey::ThankYou)
        ->and($message->to)->toEqual(new Recipient('casey@example.test'))
        ->and($message->bookingUuid)->toBe(SnapshotFixture::UUID)
        ->and($message->subject)->toBe(thxLine('thank_you.subject'))
        ->and($message->language)->toBe('en')
        ->and($message->attachments)->toBe([]);
});

it('is these words exactly with the reviews line, Leave us a review and the link, where the profile holds one', function () {
    expect(thxMessage()->body)->toBe(thxBody(
        thxLine('greeting', ['customer' => 'Casey Customer']),
        thxLine('thank_you.thanks'),
        '    '.thxItem('Haircut', 'Elena Papadaki'),
        thxLine('thank_you.again'),
        thxLine('thank_you.review', ['link' => THX_REVIEWS]),
        thxLine('book_again', ['link' => THX_AGAIN]),
    ));
});

it('is the same words with no reviews line at all where the profile holds no link', function () {
    $body = thxMessage(reviews: null)->body;

    expect($body)->toBe(thxBody(
        thxLine('greeting', ['customer' => 'Casey Customer']),
        thxLine('thank_you.thanks'),
        '    '.thxItem('Haircut', 'Elena Papadaki'),
        thxLine('thank_you.again'),
        thxLine('book_again', ['link' => THX_AGAIN]),
    ))
        ->and($body)->not->toContain(THX_REVIEWS);
});

it('names the staff member where the row is not the implicit one, and the service alone where it is', function () {
    $implicit = thxMessage(items: [['Haircut', 'Luna Hair Studio', true, 10]], again: 'https://luna.kleiseradevou.gr/?service=7')->body;

    expect(thxMessage()->body)->toContain('    '.thxItem('Haircut', 'Elena Papadaki')."\n")
        ->and($implicit)->toContain("    Haircut\n")
        ->and($implicit)->not->toContain(thxItem('Haircut', 'Luna Hair Studio'));
});

it('names every item of a chain, one to a line, in chain order', function () {
    $body = thxMessage(items: [['Colour', 'Elena Papadaki', false, 9], ['Haircut', 'Nikos Georgiou', false, 11]])->body;

    expect($body)->toContain(thxLine('thank_you.thanks')."\n\n    ".thxItem('Colour', 'Elena Papadaki')."\n    ".thxItem('Haircut', 'Nikos Georgiou')."\n\n".thxLine('thank_you.again'));
});

it('carries the reviews link and Book again and no other link, no time, no total and no identifier anybody could act on', function () {
    $message = thxMessage();

    expect(seamLinksIn($message->body))->toBe([THX_REVIEWS, THX_AGAIN])
        ->and($message->body)->not->toContain('/manage/')
        ->and($message->body)->not->toContain($message->bookingUuid)
        ->and($message->body)->not->toContain('10:00')
        ->and($message->body)->not->toContain('EUR');
});

it('reads its words through the keys its constants hold, which keep their names', function () {
    expect(ThankYou::SUBJECT)->toBe('customer-mail.thank_you.subject')
        ->and(ThankYou::BODY)->toBe('customer-mail.thank_you.thanks')
        ->and(ThankYou::REVIEW_LINE)->toBe('customer-mail.thank_you.review')
        ->and(thxMessage()->subject)->toBe(LangLines::line(ThankYou::SUBJECT, ['business' => 'Luna Hair Studio']))
        ->and(thxMessage()->body)->toContain(LangLines::line(ThankYou::REVIEW_LINE, ['link' => THX_REVIEWS]).BookAgainLine::line(THX_AGAIN, SiteLanguage::English))
        ->and(thxMessage()->body)->toStartWith(thxLine('greeting', ['customer' => 'Casey Customer'])."\n\n".LangLines::line(ThankYou::BODY, ['business' => 'Luna Hair Studio'])."\n\n");
});

it('is ASCII, like every template', function () {
    $message = thxMessage();

    expect(preg_match('/\A[\x09\x0a\x20-\x7e]+\z/', $message->body))->toBe(1)
        ->and(preg_match('/\A[\x20-\x7e]+\z/', $message->subject))->toBe(1);
});
