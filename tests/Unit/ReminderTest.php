<?php

use App\Application\Notification\Message;
use App\Application\Notification\MessageKey;
use App\Application\Notification\Recipient;
use App\Notifications\BookAgainLine;
use App\Notifications\Reminder;
use App\Notifications\TenantClock;
use App\Tenancy\Slug;
use App\Tenancy\TenantUrls;
use Tests\Support\LangLines;
use Tests\Support\SnapshotFixture;

/*
 * What the customer is reminded of: the appointments with their times in the tenant's clock,
 * the staff member where the row is not the tenant's implicit one, the total, and the way back
 * to the booking.
 *
 * The way back is a reference and not a link. The plaintext manage token is minted in two
 * moments, and a reminder is neither, so the message points at the link the customer already
 * holds. Which earlier message carried it depends on how the booking was settled, and whether
 * any did depends on the tenant's switches and on the admin's booking, which carries none, so
 * the sentence is worded to be true whether or not one went, rather than naming a message this
 * one never read the switch of.
 *
 * The booking's facts are lines of decision.php read through a translator over the lang
 * directory, and an instant is ICU's medium date and short time; the words are
 * customer-mail.php's reminder group and its shared lines, read through the same translator, and
 * Book again is under the prefix of the booking's language.
 */

beforeEach(function () {
    LangLines::bind();
});

afterEach(function () {
    LangLines::release();
});

/**
 * One line of customer-mail.php read through the translator, the business filled in with what
 * else is given.
 *
 * @param  array<string, string>  $replace
 */
function reminderLine(string $key, array $replace = []): string
{
    return LangLines::line('customer-mail.'.$key, [...$replace, 'business' => 'Luna Hair Studio']);
}

/**
 * The reminder of a booking of luna's, Book again built for it as the job builds it.
 *
 * @param  list<array{string, string, bool, int}>  $items  each as [service, staff, implicit, hour]
 */
function reminderMessage(array $items = [['Haircut', 'Elena Papadaki', false, 10]], string $tenant = 'Luna Hair Studio', string $timezone = 'Europe/Athens', ?string $myBookings = null): Message
{
    $booking = SnapshotFixture::booking(items: $items, timezone: $timezone);

    return Reminder::of($booking, $tenant, new TenantClock(new DateTimeZone($timezone)), BookAgainLine::for(new TenantUrls('https://kleiseradevou.gr'), Slug::of('luna'), $booking), $myBookings);
}

it('reminds the customer under the reminder key, to the address on the booking, about that booking', function () {
    $message = reminderMessage();

    expect($message->key)->toBe(MessageKey::Reminder)
        ->and($message->to)->toEqual(new Recipient('casey@example.test'))
        ->and($message->bookingUuid)->toBe(SnapshotFixture::UUID)
        ->and($message->subject)->toBe(reminderLine('reminder.subject'))
        ->and($message->language)->toBe('en')
        ->and($message->body)->toStartWith(reminderLine('greeting', ['customer' => 'Casey Customer'])."\n\n".reminderLine('reminder.reminder')."\n\n")
        ->and($message->body)->toContain(LangLines::line('decision.facts.item', ['service' => 'Haircut', 'staff' => 'Elena Papadaki']))
        ->and($message->body)->toContain(LangLines::line('decision.facts.total', ['amount' => '35.00 EUR']))
        ->and($message->attachments)->toBe([]);
});

it('names the time in the tenant clock and the zone once, never the hour the row holds', function () {
    $body = reminderMessage()->body;

    // 10:00 in Athens is 08:00 in the row.
    expect($body)->toContain('3 Nov 2026, 10:00')
        ->and($body)->not->toContain(', 08:00')
        ->and(substr_count($body, 'Europe/Athens'))->toBe(1)
        ->and($body)->toContain(LangLines::line('customer-mail.zone', ['zone' => 'Europe/Athens', 'business' => 'Luna Hair Studio']));
});

it('names the staff member where the row is not the implicit one, and the service alone where it is', function () {
    $named = reminderMessage()->body;
    $implicit = reminderMessage([['Haircut', 'Luna Hair Studio', true, 10]])->body;

    expect($named)->toContain(LangLines::line('decision.facts.item', ['service' => 'Haircut', 'staff' => 'Elena Papadaki']))
        ->and($implicit)->toContain("    Haircut\n")
        ->and($implicit)->not->toContain(LangLines::line('decision.facts.item', ['service' => 'Haircut', 'staff' => 'Luna Hair Studio']));
});

it('times every segment of a chain, because the later ones are not at the first one\'s hour', function () {
    $body = reminderMessage([['Colour', 'Elena Papadaki', false, 9], ['Haircut', 'Elena Papadaki', false, 11]])->body;

    expect($body)->toContain(LangLines::line('decision.facts.item', ['service' => 'Colour', 'staff' => 'Elena Papadaki'])."\n    3 Nov 2026, 09:00")
        ->and($body)->toContain(LangLines::line('decision.facts.item', ['service' => 'Haircut', 'staff' => 'Elena Papadaki'])."\n    3 Nov 2026, 11:00")
        ->and($body)->toContain(LangLines::line('decision.facts.total', ['amount' => '70.00 EUR']));
});

it('points at the link the customer already holds without claiming a message carried it, and names who to ask instead', function () {
    $body = reminderMessage()->body;

    // The one paragraph between the total and the clock's line is the earlier-message sentence,
    // which names no message; the confirmation's sentence naming the one we sent is not here.
    expect($body)->toContain(LangLines::line('decision.facts.total', ['amount' => '35.00 EUR'])."\n\n".reminderLine('reminder.earlier')."\n\n".reminderLine('zone', ['zone' => 'Europe/Athens']))
        ->and($body)->not->toContain(reminderLine('confirmed.where_link.acknowledged'));
});

it('carries no magic link and no identifier anybody could act on, Book again its one link', function () {
    $message = reminderMessage();

    // Book again is the hosted page with a preselection, which asks nobody who they are: no
    // credential and no booking anybody could act on.
    expect(seamLinksIn($message->body))->toBe(['https://luna.kleiseradevou.gr/en?service=7&staff=3'])
        ->and($message->body)->not->toContain('/manage/')
        ->and($message->body)->not->toContain($message->bookingUuid);
});

it('carries Book again, the first item\'s service and its staff member, after the clock\'s line and before the closing line', function () {
    $body = reminderMessage([['Colour', 'Elena Papadaki', false, 9], ['Haircut', 'Nikos Georgiou', false, 11]], myBookings: 'https://luna.kleiseradevou.gr/en/my-bookings')->body;

    $again = reminderLine('book_again', ['link' => 'https://luna.kleiseradevou.gr/en?service=7&staff=3']);

    expect($body)->toEndWith(
        reminderLine('zone', ['zone' => 'Europe/Athens'])
        ."\n\n".$again
        ."\n\n".reminderLine('my_bookings', ['link' => 'https://luna.kleiseradevou.gr/en/my-bookings']),
    )
        ->and(substr_count($body, $again))->toBe(1);
});

it('leaves the staff out of Book again where the first item runs on the implicit member', function () {
    $body = reminderMessage([['Haircut', 'Luna Hair Studio', true, 10]])->body;

    expect($body)->toEndWith("\n\n".reminderLine('book_again', ['link' => 'https://luna.kleiseradevou.gr/en?service=7']))
        ->and($body)->not->toContain('staff=');
});

it('is ASCII, like every template', function () {
    $message = reminderMessage();

    expect(preg_match('/\A[\x09\x0a\x20-\x7e]+\z/', $message->body))->toBe(1)
        ->and(preg_match('/\A[\x20-\x7e]+\z/', $message->subject))->toBe(1);
});
