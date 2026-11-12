<?php

use App\Application\Notification\Message;
use App\Application\Notification\MessageKey;
use App\Application\Notification\Recipient;
use App\Domain\Enum\BookingStatus;
use App\Notifications\ApprovalLinks;
use App\Notifications\ApprovalNag;
use App\Notifications\ApprovalRequest;
use App\Notifications\TenantClock;
use Tests\Support\LangLines;
use Tests\Support\SnapshotFixture;

/*
 * What the business is told when a request has sat undecided for a quarter, a half or three
 * quarters of its window: that it is still waiting, when it runs out and how long that is from
 * now, and, for a recipient who can act, two fresh links to act with.
 *
 * It is read by people with different permissions, so what each of them is told about the
 * customer follows the rule the approval request and the decision pages follow; and it carries
 * a credential, so what it says has to be right for exactly one person.
 *
 * The booking's facts are lines of decision.php read through a translator over the lang
 * directory, and an instant is ICU's medium date and short time; the message's own words are
 * business-mail.php's, read the same way, the time left one line read as a choice by the hours,
 * and BusinessMailKeysTest holds every form to its keys.
 */

beforeEach(function () {
    LangLines::bind();
});

afterEach(function () {
    LangLines::release();
});

/** The Tuesday's request runs out on the Thursday at 10:00 in Athens, which is 08:00 in the row. */
function nagExpiry(): DateTimeImmutable
{
    return new DateTimeImmutable('2026-11-05 08:00:00', new DateTimeZone('UTC'));
}

function nagLinks(): ApprovalLinks
{
    return new ApprovalLinks(
        'http://luna.localhost:8000/decide/'.SnapshotFixture::UUID.'/approve?expires=1&user=4&signature=abc',
        'http://luna.localhost:8000/decide/'.SnapshotFixture::UUID.'/reject?expires=1&user=4&signature=def',
    );
}

/**
 * The nag as the owner reads it, by default a quarter of the way through a forty-eight hour
 * window, so thirty-six hours before it runs out.
 */
function nagMessage(?Recipient $to = null, ?ApprovalLinks $links = null, ?DateTimeImmutable $now = null, ?string $phone = '+30 210 7654321'): Message
{
    return ApprovalNag::of(
        SnapshotFixture::booking(BookingStatus::AwaitingApproval, [['Bridal trial', 'Elena Papadaki', false, 9]], phone: $phone),
        'Luna Hair Studio',
        new TenantClock(new DateTimeZone('Europe/Athens')),
        $to ?? new Recipient('owner@luna.example', 4, true),
        $links ?? nagLinks(),
        nagExpiry(),
        $now ?? new DateTimeImmutable('2026-11-03 20:00:00', new DateTimeZone('UTC')),
    );
}

/**
 * The same nag to a mailbox with nobody behind it: a staff row's own address, which has no
 * user for a link to decide as, so there are no links to carry.
 */
function nagToAMailbox(Recipient $to): Message
{
    return ApprovalNag::of(
        SnapshotFixture::booking(BookingStatus::AwaitingApproval, [['Bridal trial', 'Elena Papadaki', false, 9]]),
        'Luna Hair Studio',
        new TenantClock(new DateTimeZone('Europe/Athens')),
        $to,
        null,
        nagExpiry(),
        new DateTimeImmutable('2026-11-03 20:00:00', new DateTimeZone('UTC')),
    );
}

it('is the approval_nag message of the catalog, to the one recipient it was built for, carrying no file', function () {
    $message = nagMessage();

    expect($message->key)->toBe(MessageKey::ApprovalNag)
        ->and($message->to->address)->toBe('owner@luna.example')
        ->and($message->bookingUuid)->toBe(SnapshotFixture::UUID)
        ->and($message->subject)->toBe(LangLines::line('business-mail.approval_nag.subject', ['business' => 'Luna Hair Studio']))
        ->and($message->attachments)->toBe([]);
});

it('says the request is still waiting, what it is, and when it is, in the tenant clock with the zone named once', function () {
    $body = nagMessage()->body;

    expect($body)->toStartWith(LangLines::line('business-mail.approval_nag.with_links', ['business' => 'Luna Hair Studio'])."\n\n")
        ->toContain('Casey Customer')
        ->toContain(LangLines::line('decision.facts.item', ['service' => 'Bridal trial', 'staff' => 'Elena Papadaki']))
        // 09:00 in Athens is 07:00 in the row: the business is told the hour their own diary
        // means.
        ->toContain('3 Nov 2026, 09:00')
        ->not->toContain(', 07:00')
        ->toContain(LangLines::line('decision.facts.total', ['amount' => '35.00 EUR']))
        ->toContain(LangLines::line('decision.facts.zone', ['zone' => 'Europe/Athens']))
        ->and(substr_count($body, 'Europe/Athens'))->toBe(1);
});

it('says when the request runs out, in the tenant clock, and how long that is from now', function () {
    $body = nagMessage()->body;

    expect($body)->toContain(LangLines::choice('business-mail.approval_nag.runs_out', 36, ['end' => '5 Nov 2026, 10:00']))
        ->toContain('36')
        ->not->toContain(', 08:00');
});

it('rounds the time left to the nearest hour and words the last half hour without a number, because the queue fires a nag when it can and not to the second', function (string $now, int $hours) {
    $line = fn (int $count): string => LangLines::choice('business-mail.approval_nag.runs_out', $count, ['end' => '5 Nov 2026, 10:00']);
    $body = nagMessage(now: new DateTimeImmutable($now, new DateTimeZone('UTC')))->body;

    expect($body)->toContain($line($hours));

    // The time left's plural: under half an hour, about an hour and about a number of hours are
    // three sentences of one key, and the body carries only the one its hours choose.
    foreach (array_diff([0, 1, 24], [$hours]) as $other) {
        expect($line($other))->not->toBe($line($hours));

        if ($hours > 1 && $other > 1) {
            continue;
        }

        expect($body)->not->toContain($line($other));
    }
})->with([
    'a half of the window, on the second' => ['2026-11-04 08:00:00', 24],
    'three quarters, fired a few minutes late' => ['2026-11-04 20:07:30', 12],
    'twenty-nine minutes past the half hour rounds up' => ['2026-11-04 08:31:00', 23],
    'an hour and a bit' => ['2026-11-05 06:50:00', 1],
    'the last half hour' => ['2026-11-05 07:31:00', 0],
    'the last second, with the clocks a hair apart' => ['2026-11-05 08:00:01', 0],
]);

it('spells the hours as the number they are and the hour and the half hour as words', function () {
    $line = fn (int $count): string => LangLines::choice('business-mail.approval_nag.runs_out', $count, ['end' => '5 Nov 2026, 10:00']);

    $left = fn (int $count): string => str_replace('5 Nov 2026, 10:00', '', $line($count));

    expect($left(36))->toContain('36')
        ->and($left(2))->toContain('2')
        ->and($left(1))->not->toMatch('/\d/')
        ->and($left(0))->not->toMatch('/\d/');
});

it('carries the two links the recipient can act with, and each of them once', function () {
    $links = nagLinks();
    $body = nagMessage(links: $links)->body;

    expect(substr_count($body, $links->approve))->toBe(1)
        ->and(substr_count($body, $links->reject))->toBe(1)
        ->and($body)->toContain(LangLines::line('business-mail.links.approve')."\n\n    ".$links->approve)
        ->toContain(LangLines::line('business-mail.links.reject')."\n\n    ".$links->reject);
});

it('carries no link at all for a mailbox with nobody behind it, and says only what is true of that copy, in the words the request prints for the same mailbox', function () {
    $chair = new Recipient('chair@luna.example');
    $body = nagToAMailbox($chair)->body;
    $request = ApprovalRequest::of(
        SnapshotFixture::booking(BookingStatus::AwaitingApproval, [['Bridal trial', 'Elena Papadaki', false, 9]]),
        'Luna Hair Studio',
        new TenantClock(new DateTimeZone('Europe/Athens')),
        $chair,
        null,
        true,
        null,
    )->body;

    // A staff row's own address has no user for a link to decide as. What is true of its copy
    // is that it decides nothing and who can from their own, and the request says the same to
    // the same mailbox, word for word, because the two are spelled from one place. What is
    // not said is that it can be decided from a login: a linked recipient decides from a
    // signed link without signing in.
    expect($body)->not->toContain('http')
        ->not->toContain('/decide/')
        ->toStartWith(LangLines::line('business-mail.approval_nag.without_links', ['business' => 'Luna Hair Studio'])."\n\n")
        ->not->toContain(LangLines::line('business-mail.approval_nag.with_links', ['business' => 'Luna Hair Studio']))
        ->toContain(LangLines::line('business-mail.links.none'))
        ->not->toContain(LangLines::line('business-mail.links.approve'))
        ->toContain('Bridal trial')
        ->and($request)->toContain(LangLines::line('business-mail.links.none'))
        ->and(LangLines::line('business-mail.links.none'))->not->toContain('login');
});

it('tells a recipient without manageBookings whose request it is and never how to reach them, because a message is a surface like any other', function () {
    $staffUser = nagMessage(new Recipient('elena@luna.example', 9), nagLinks());
    $staffRow = nagToAMailbox(new Recipient('chair@luna.example'));

    foreach ([$staffUser, $staffRow] as $message) {
        expect($message->body)->toContain('Casey Customer')
            ->not->toContain('casey@example.test')
            ->not->toContain('+30 210 7654321');
    }

    expect(nagMessage()->body)->toContain('casey@example.test')
        ->toContain('+30 210 7654321');
});

it('leaves out the phone line for a customer who gave no phone, rather than printing an empty one', function () {
    expect(nagMessage(phone: null)->body)->not->toContain(LangLines::line('decision.facts.phone'))
        ->and(nagMessage()->body)->toContain(LangLines::line('decision.facts.phone').': +30 210 7654321');
});

it('is ASCII, like every template', function () {
    $message = nagMessage();

    expect(preg_match('/\A[\x09\x0a\x20-\x7e]+\z/', $message->body))->toBe(1)
        ->and(preg_match('/\A[\x20-\x7e]+\z/', $message->subject))->toBe(1);
});
