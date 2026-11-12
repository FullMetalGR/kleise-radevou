<?php

use App\Application\Notification\Message;
use App\Domain\Enum\BookingStatus;
use App\Notifications\Acknowledgement;
use App\Notifications\Cancellation;
use App\Notifications\Confirmation;
use App\Notifications\Move;
use App\Notifications\MyBookingsLine;
use App\Notifications\Reminder;
use App\Notifications\RequestApproved;
use App\Notifications\RequestExpired;
use App\Notifications\RequestRejected;
use App\Notifications\TenantClock;
use App\Notifications\ThankYou;
use App\Site\SiteLanguage;
use Tests\Support\LangLines;
use Tests\Support\SnapshotFixture;

/*
 * The closing line of every customer message: customer-mail.php's my_bookings line and the
 * tenant's My bookings page, the last line of the message, where the job hands the page over
 * because the key is on, and nothing where it hands none. Eleven forms across the nine customer
 * templates, each pinned here, the thank-you among them. The booking's facts the templates print
 * read decision.php through a translator over the lang directory, and the line is read through
 * the same translator in the booking's language.
 */

beforeEach(function () {
    LangLines::bind();
});

afterEach(function () {
    LangLines::release();
});

const MYB_LINE_URL = 'https://luna.kleiseradevou.gr/en/my-bookings';

/**
 * The closing line as the translator reads it in English, the page given.
 */
function mybLine(string $page): string
{
    return LangLines::line(MyBookingsLine::LINE, ['link' => $page]);
}

/**
 * Every customer form, built with the page or without it.
 *
 * @return array<string, Closure(?string): Message>
 */
function mybLineForms(): array
{
    $zone = new TenantClock(new DateTimeZone('Europe/Athens'));
    $stamp = new DateTimeImmutable('2026-11-02 06:00:00', new DateTimeZone('UTC'));
    $confirmed = SnapshotFixture::booking();
    $request = SnapshotFixture::booking(BookingStatus::AwaitingApproval);
    $completed = SnapshotFixture::booking(BookingStatus::Completed);
    $link = 'https://luna.kleiseradevou.gr/manage/'.SnapshotFixture::UUID.'?token=abc';
    $again = 'https://luna.kleiseradevou.gr/?service=7&staff=3';

    return [
        'the acknowledgement' => fn (?string $page): Message => Acknowledgement::of($request, 'Luna Hair Studio', $zone, $link, $page),
        'the confirmation' => fn (?string $page): Message => Confirmation::of($confirmed, 'Luna Hair Studio', $zone, $link, $stamp, $again, myBookings: $page),
        'the approval' => fn (?string $page): Message => RequestApproved::of($confirmed, 'Luna Hair Studio', $zone, $stamp, true, $page),
        'the approval awaiting payment' => fn (?string $page): Message => RequestApproved::awaitingPayment($request, 'Luna Hair Studio', $zone, 'https://luna.kleiseradevou.gr/pay/x/link', $page),
        'the rejection' => fn (?string $page): Message => RequestRejected::of($request, 'Luna Hair Studio', $zone, $page),
        'the cancellation' => fn (?string $page): Message => Cancellation::of($confirmed, 'Luna Hair Studio', $zone, false, true, $page),
        'the move' => fn (?string $page): Message => Move::of($confirmed, 'Luna Hair Studio', $zone, $stamp, false, true, $stamp, 1, myBookings: $page),
        'the reminder' => fn (?string $page): Message => Reminder::of($confirmed, 'Luna Hair Studio', $zone, $again, $page),
        'the timeout' => fn (?string $page): Message => RequestExpired::of($request, 'Luna Hair Studio', $zone, $page),
        'the unpaid lapse' => fn (?string $page): Message => RequestExpired::unpaid($request, 'Luna Hair Studio', $zone, $page),
        'the thank-you' => fn (?string $page): Message => ThankYou::of($completed, 'Luna Hair Studio', 'https://g.page/r/luna/review', $again, $page),
    ];
}

it('closes {0} with the line to My bookings where the page is handed over, and carries no line where none is', function (string $form) {
    $build = mybLineForms()[$form];

    $with = $build(MYB_LINE_URL)->body;
    $without = $build(null)->body;

    expect($with)->toEndWith("\n\n".mybLine(MYB_LINE_URL))
        ->and(substr_count($with, mybLine(MYB_LINE_URL)))->toBe(1)
        ->and($without)->not->toContain(mybLine(MYB_LINE_URL))
        ->and($without)->not->toContain(MYB_LINE_URL)
        ->and($with)->toBe($without.MyBookingsLine::closing(MYB_LINE_URL, SiteLanguage::English));
})->with(fn () => array_keys(mybLineForms()));

it('spells the line once, its constant holding its key, and nothing at all for no page', function () {
    expect(MyBookingsLine::closing(MYB_LINE_URL, SiteLanguage::English))->toBe("\n\n".mybLine(MYB_LINE_URL))
        ->and(MyBookingsLine::LINE)->toBe('customer-mail.my_bookings')
        ->and(MyBookingsLine::closing(null, SiteLanguage::English))->toBe('');
});
