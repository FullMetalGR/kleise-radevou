<?php

use App\Application\MyBookings\RequestBookingsLink;
use App\Application\Notification\MessageKey;
use App\Application\Notification\Recipient;
use App\Notifications\BookingsLink;
use App\Site\SiteLanguage;
use Illuminate\Contracts\Translation\Translator;
use Tests\Support\LangLines;

/*
 * The My bookings link as the customer receives it: the link and nothing else, about no booking.
 * Its words are customer-mail.php's my_bookings_link group, read through a translator over the
 * lang directory in the language the job hands it, and how long the link opens for is the
 * request's own lifetime spelled out in words by ICU.
 */

beforeEach(function () {
    LangLines::bind();
});

afterEach(function () {
    LangLines::release();
});

const BOOKINGS_LINK_URL = 'https://luna.kleiseradevou.gr/en/my-bookings/0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f';

/**
 * How long the link opens for as the message words it: the request's lifetime in minutes, chosen
 * by and spelled out in English by ICU.
 */
function bookingsLinkLifetime(): string
{
    $minutes = (new DateInterval(RequestBookingsLink::LIFETIME))->i;
    $spelled = (new NumberFormatter('en', NumberFormatter::SPELLOUT))->format($minutes);
    assert(is_string($spelled));

    return app(Translator::class)->choice('customer-mail.my_bookings_link.once', $minutes, ['count' => $spelled], 'en');
}

it('goes to the address asked for under the bookings link key, about no booking, in the language handed over', function () {
    $message = BookingsLink::of('casey@example.test', 'Luna Hair Studio', BOOKINGS_LINK_URL, SiteLanguage::English);

    expect($message->key)->toBe(MessageKey::MyBookingsLink)
        ->and($message->to)->toEqual(new Recipient('casey@example.test'))
        ->and($message->bookingUuid)->toBeNull()
        ->and($message->subject)->toBe(LangLines::line(BookingsLink::SUBJECT, ['business' => 'Luna Hair Studio']))
        ->and($message->language)->toBe('en')
        ->and($message->attachments)->toBe([])
        ->and(BookingsLink::of('casey@example.test', 'Luna Hair Studio', BOOKINGS_LINK_URL, SiteLanguage::Greek)->language)->toBe('el');
});

it('carries the link and no other, says it opens once within the request\'s fifteen minutes, and asks for no password', function () {
    $body = BookingsLink::of('casey@example.test', 'Luna Hair Studio', BOOKINGS_LINK_URL, SiteLanguage::English)->body;

    preg_match_all('#https?://\S+#', $body, $links);

    expect($links[0])->toBe([BOOKINGS_LINK_URL])
        ->and($body)->toBe(implode("\n\n", [
            LangLines::line('customer-mail.my_bookings_link.greeting'),
            LangLines::line(BookingsLink::BODY, ['business' => 'Luna Hair Studio']),
            '    '.BOOKINGS_LINK_URL,
            bookingsLinkLifetime(),
        ]))
        ->and(strtolower($body))->not->toContain('password')
        ->and($body)->not->toContain('casey@example.test');
});
