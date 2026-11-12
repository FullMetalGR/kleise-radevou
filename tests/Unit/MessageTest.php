<?php

use App\Application\Notification\Message;
use App\Application\Notification\MessageKey;
use App\Application\Notification\Recipient;

/*
 * One message: a key, one recipient, the words, and the booking it is about, which is what a
 * failed send is logged against. The bookings link alone is about no booking.
 */

it('names its booking under every key but the bookings link, which names none', function (MessageKey $key) {
    $message = new Message($key, new Recipient('casey@example.com'), 'Subject', 'Body', $key === MessageKey::MyBookingsLink ? null : '7b7f0e5a-0000-4000-8000-000000000001');

    expect($message->bookingUuid === null)->toBe($key === MessageKey::MyBookingsLink);
})->with(fn () => MessageKey::cases());

it('refuses a booking\'s message with no booking named', function () {
    expect(fn () => new Message(MessageKey::Confirmed, new Recipient('casey@example.com'), 'Subject', 'Body', null))
        ->toThrow(InvalidArgumentException::class);
});

it('refuses the bookings link naming a booking', function () {
    expect(fn () => new Message(MessageKey::MyBookingsLink, new Recipient('casey@example.com'), 'Subject', 'Body', '7b7f0e5a-0000-4000-8000-000000000001'))
        ->toThrow(InvalidArgumentException::class);
});

it('is written in English where its builder names no language, and in the language a builder names', function () {
    $unnamed = new Message(MessageKey::Confirmed, new Recipient('casey@example.com'), 'Subject', 'Body', '7b7f0e5a-0000-4000-8000-000000000001');
    $greek = new Message(MessageKey::Confirmed, new Recipient('casey@example.com'), 'Subject', 'Body', '7b7f0e5a-0000-4000-8000-000000000001', language: 'el');

    expect($unnamed->language)->toBe('en')
        ->and($greek->language)->toBe('el');
});
