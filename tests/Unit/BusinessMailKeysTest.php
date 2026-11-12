<?php

use App\Application\Dto\BookingSnapshot;
use App\Application\Dto\Customer;
use App\Application\Notification\Message;
use App\Application\Notification\MessageKey;
use App\Application\Notification\Recipient;
use App\Domain\Audit\Actor;
use App\Domain\Enum\BookingStatus;
use App\Notifications\ApprovalLinks;
use App\Notifications\ApprovalNag;
use App\Notifications\ApprovalRequest;
use App\Notifications\AutoApprovedNotice;
use App\Notifications\CancellationNotice;
use App\Notifications\LatePaymentNotice;
use App\Notifications\MoveNotice;
use App\Notifications\NewBookingNotice;
use App\Notifications\TenantClock;
use App\Notifications\TenantTime;
use App\Notifications\TenantUser;
use App\Notifications\WhoActed;
use App\Site\SiteLanguage;
use App\Tenancy\Role;
use PHPUnit\Framework\Assert;
use Tests\Support\FixedDirectory;
use Tests\Support\KeyedLines;
use Tests\Support\SnapshotFixture;

/*
 * Every business message as keys: every form and every subject of the seven rendered through the
 * keyed table, whose every line is its own key in brackets with the placeholders the English
 * names after it, so each body below is held whole, byte for byte, to keys, the values that fill
 * them, and the template's own layout: the blank lines between paragraphs, the booking's
 * indented lines, the customer's facts, and the decision links set on a line of their own. A
 * sentence left in a class would be words outside a bracket. The instants are ICU's in the
 * recipient's language, never a key; who acted is one sentence per shape WhoActed tells apart,
 * its name filled in; the switch the automatic approval names is MessageKey's label as :label.
 *
 * And the recipient's language: every line a template reads is asked of the translator in the
 * language the Recipient carries, never the application's locale, Greek for a Greek recipient
 * and English for an English one, the instants spelled by ICU in the same language, and the
 * message records it.
 */

beforeEach(function () {
    KeyedLines::bind();
});

afterEach(function () {
    KeyedLines::release();
});

const BMK_TENANT = 'Luna Hair Studio';

const BMK_PLATFORM = 'Example Bookings';

const BMK_APPROVE = 'https://luna.kleiseradevou.gr/en/decide/7b7f0e5a-0000-4000-8000-000000000005/approve?expires=1&user=4&signature=a';

const BMK_REJECT = 'https://luna.kleiseradevou.gr/en/decide/7b7f0e5a-0000-4000-8000-000000000005/reject?expires=1&user=4&signature=r';

const BMK_NOTE = "Allergic to lavender.\nPlease use the quiet room.";

function bmkClock(): TenantClock
{
    return new TenantClock(new DateTimeZone('Europe/Athens'));
}

/**
 * An owner, who holds manageBookings, reading the language given.
 */
function bmkOwner(string $language = 'en'): Recipient
{
    return new Recipient('owner@luna.example', 4, true, $language);
}

/**
 * A staff row's own address, nobody behind it, reading the language given.
 */
function bmkRow(string $language = 'en'): Recipient
{
    return new Recipient('chair@luna.example', null, false, $language);
}

function bmkLinks(): ApprovalLinks
{
    return new ApprovalLinks(BMK_APPROVE, BMK_REJECT);
}

/** The request runs out on the Thursday at 10:00 in Athens, which is 08:00 in the row. */
function bmkExpiry(): DateTimeImmutable
{
    return new DateTimeImmutable('2026-11-05 08:00:00', new DateTimeZone('UTC'));
}

/** Thirty-six hours before the request runs out. */
function bmkNow(): DateTimeImmutable
{
    return new DateTimeImmutable('2026-11-03 20:00:00', new DateTimeZone('UTC'));
}

/**
 * The start a moved booking left, the Monday at 09:00 in Athens; the start it has is the
 * fixture's, the Tuesday at 10:00.
 */
function bmkMovedFrom(): DateTimeImmutable
{
    return SnapshotFixture::at('2026-11-02 09:00');
}

function bmkBooking(BookingStatus $status = BookingStatus::Confirmed): BookingSnapshot
{
    return SnapshotFixture::booking($status);
}

/**
 * Who acted, by the shape named: the customer, Marina the manager, a user the tenant no longer
 * has, the platform's support, the system.
 */
function bmkWho(string $shape): WhoActed
{
    $team = new FixedDirectory([new TenantUser(9, 'Marina Manager', 'marina@luna.example', Role::Manager)]);
    $actor = match ($shape) {
        'customer' => Actor::customer(),
        'user' => Actor::user(9),
        'removed' => Actor::user(77),
        'support' => Actor::support(3),
        default => Actor::system(),
    };

    return WhoActed::of($actor, new Customer('Casey Customer', 'casey@example.test', null), $team, BMK_PLATFORM);
}

/**
 * A line of business-mail.php as the keyed table writes it, the values given filled in.
 */
function bmk(string $key, string ...$values): string
{
    return KeyedLines::line('business-mail.'.$key, ...$values);
}

/**
 * The booking's facts as BookingLines and CustomerFacts write them through the keyed table: its
 * one item, the customer as a reader with manageBookings sees them and as one without, its total
 * and the business's clock line.
 *
 * @return array{lines: string, all: string, name: string, total: string, zone: string}
 */
function bmkFacts(SiteLanguage $language = SiteLanguage::English): array
{
    return [
        'lines' => '    '.KeyedLines::line('decision.facts.item', 'Haircut', 'Elena Papadaki')."\n    ".TenantTime::instant(bmkBooking()->startsAt(), bmkClock(), $language),
        'all' => KeyedLines::line('decision.facts.customer').": Casey Customer\n".KeyedLines::line('decision.facts.email').": casey@example.test\n".KeyedLines::line('decision.facts.phone').': +30 210 7654321',
        'name' => KeyedLines::line('decision.facts.customer').': Casey Customer',
        'total' => KeyedLines::line('decision.facts.total', '35.00 EUR'),
        'zone' => KeyedLines::line('decision.facts.zone', 'Europe/Athens'),
    ];
}

/**
 * The two decision links as asButtons sets them out, each under its caption.
 */
function bmkButtons(): string
{
    return bmk('links.approve')."\n\n    ".BMK_APPROVE."\n\n".bmk('links.reject')."\n\n    ".BMK_REJECT;
}

/**
 * Every form of the seven, each built for a recipient reading the language given, as the jobs
 * build them.
 *
 * @return array<string, Closure(string): Message>
 */
function bmkForms(): array
{
    $request = fn (): BookingSnapshot => bmkBooking(BookingStatus::AwaitingApproval);

    return [
        'the new-booking notice to an owner, with the note' => fn (string $language): Message => NewBookingNotice::of(bmkBooking(), BMK_TENANT, bmkClock(), bmkOwner($language), BMK_NOTE),
        'the new-booking notice to a staff row\'s address' => fn (string $language): Message => NewBookingNotice::of(bmkBooking(), BMK_TENANT, bmkClock(), bmkRow($language), null),
        'the paid new-booking notice' => fn (string $language): Message => NewBookingNotice::of(bmkBooking(), BMK_TENANT, bmkClock(), bmkOwner($language), null, paid: true),
        'the approval request with the recipient\'s links, with the note' => fn (string $language): Message => ApprovalRequest::of($request(), BMK_TENANT, bmkClock(), bmkOwner($language), bmkLinks(), true, BMK_NOTE),
        'the approval request to a staff row\'s address' => fn (string $language): Message => ApprovalRequest::of($request(), BMK_TENANT, bmkClock(), bmkRow($language), null, true, null),
        'the approval request settled since' => fn (string $language): Message => ApprovalRequest::of(bmkBooking(BookingStatus::Rejected), BMK_TENANT, bmkClock(), bmkOwner($language), null, false, null),
        'the nag with the recipient\'s links' => fn (string $language): Message => ApprovalNag::of($request(), BMK_TENANT, bmkClock(), bmkOwner($language), bmkLinks(), bmkExpiry(), bmkNow()),
        'the nag to a staff row\'s address' => fn (string $language): Message => ApprovalNag::of($request(), BMK_TENANT, bmkClock(), bmkRow($language), null, bmkExpiry(), bmkNow()),
        'the automatic approval, confirmed, the customer told' => fn (string $language): Message => AutoApprovedNotice::of(bmkBooking(), BMK_TENANT, bmkClock(), bmkOwner($language), true),
        'the automatic approval, confirmed, the customer not told' => fn (string $language): Message => AutoApprovedNotice::of(bmkBooking(), BMK_TENANT, bmkClock(), bmkOwner($language), false),
        'the automatic approval awaiting payment, the customer told' => fn (string $language): Message => AutoApprovedNotice::of(bmkBooking(BookingStatus::AwaitingPayment), BMK_TENANT, bmkClock(), bmkOwner($language), true, true),
        'the automatic approval awaiting payment, the customer not told' => fn (string $language): Message => AutoApprovedNotice::of(bmkBooking(BookingStatus::AwaitingPayment), BMK_TENANT, bmkClock(), bmkOwner($language), false, true),
        'the cancellation of a request by the customer' => fn (string $language): Message => CancellationNotice::of($request(), BMK_TENANT, bmkClock(), bmkOwner($language), true, bmkWho('customer')),
        'the cancellation of a booking by the customer' => fn (string $language): Message => CancellationNotice::of(bmkBooking(), BMK_TENANT, bmkClock(), bmkOwner($language), false, bmkWho('customer')),
        'the cancellation of a booking by a user' => fn (string $language): Message => CancellationNotice::of(bmkBooking(), BMK_TENANT, bmkClock(), bmkRow($language), false, bmkWho('user')),
        'the cancellation of a booking by a user since removed' => fn (string $language): Message => CancellationNotice::of(bmkBooking(), BMK_TENANT, bmkClock(), bmkOwner($language), false, bmkWho('removed')),
        'the cancellation of a booking by support' => fn (string $language): Message => CancellationNotice::of(bmkBooking(), BMK_TENANT, bmkClock(), bmkOwner($language), false, bmkWho('support')),
        'the cancellation of a booking by the system' => fn (string $language): Message => CancellationNotice::of(bmkBooking(), BMK_TENANT, bmkClock(), bmkOwner($language), false, bmkWho('system')),
        'the move of a request by the customer' => fn (string $language): Message => MoveNotice::of($request(), BMK_TENANT, bmkClock(), bmkOwner($language), bmkMovedFrom(), true, bmkWho('customer')),
        'the move of a booking by the customer' => fn (string $language): Message => MoveNotice::of(bmkBooking(), BMK_TENANT, bmkClock(), bmkOwner($language), bmkMovedFrom(), false, bmkWho('customer')),
        'the move of a booking by a user' => fn (string $language): Message => MoveNotice::of(bmkBooking(), BMK_TENANT, bmkClock(), bmkRow($language), bmkMovedFrom(), false, bmkWho('user')),
        'the move of a booking by a user since removed' => fn (string $language): Message => MoveNotice::of(bmkBooking(), BMK_TENANT, bmkClock(), bmkOwner($language), bmkMovedFrom(), false, bmkWho('removed')),
        'the move of a booking by support' => fn (string $language): Message => MoveNotice::of(bmkBooking(), BMK_TENANT, bmkClock(), bmkOwner($language), bmkMovedFrom(), false, bmkWho('support')),
        'the move of a booking by the system' => fn (string $language): Message => MoveNotice::of(bmkBooking(), BMK_TENANT, bmkClock(), bmkOwner($language), bmkMovedFrom(), false, bmkWho('system')),
        'the late payment notice' => fn (string $language): Message => LatePaymentNotice::of(bmkBooking(), BMK_TENANT, bmkClock(), bmkOwner($language)),
    ];
}

/**
 * Each form's subject and body as the keyed table writes them, for an English recipient.
 *
 * @return array<string, array{string, string}>
 */
function bmkExpected(): array
{
    $facts = bmkFacts();
    $note = "\n\n".bmk('note.heading')."\n".BMK_NOTE;
    $label = KeyedLines::line(MessageKey::Approved->labelKey());
    $from = TenantTime::instant(bmkMovedFrom(), bmkClock(), SiteLanguage::English);
    $to = TenantTime::instant(bmkBooking()->startsAt(), bmkClock(), SiteLanguage::English);
    $end = TenantTime::instant(bmkExpiry(), bmkClock(), SiteLanguage::English);
    $notice = fn (string $opening, string $customer, string $closing): string => implode("\n\n", [$opening, $facts['lines'], $customer, $facts['total'], $closing, $facts['zone']]);
    $cancelled = fn (string $what, string $by, string $customer, string ...$name): string => $notice(bmk('cancellation_notice.'.$what.'.opening', BMK_TENANT), $customer, bmk('cancellation_notice.by.'.$by, ...$name).' '.bmk('cancellation_notice.'.$what.'.means'));
    $moved = fn (string $what, string $by, string $customer, string ...$name): string => $notice(bmk('move_notice.'.$what.'.opening', BMK_TENANT, $from, $to), $customer, bmk('move_notice.by.'.$by, ...$name));

    return [
        'the new-booking notice to an owner, with the note' => [bmk('new_booking.checkout.subject', BMK_TENANT), implode("\n\n", [
            bmk('new_booking.checkout.opening', BMK_TENANT),
            $facts['lines'],
            $facts['all'].$note,
            $facts['total'],
            $facts['zone'],
        ])],
        'the new-booking notice to a staff row\'s address' => [bmk('new_booking.checkout.subject', BMK_TENANT), implode("\n\n", [
            bmk('new_booking.checkout.opening', BMK_TENANT),
            $facts['lines'],
            $facts['name'],
            $facts['total'],
            $facts['zone'],
        ])],
        'the paid new-booking notice' => [bmk('new_booking.paid.subject', BMK_TENANT), implode("\n\n", [
            bmk('new_booking.paid.opening', BMK_TENANT),
            $facts['lines'],
            $facts['all'],
            $facts['total'],
            $facts['zone'],
        ])],
        'the approval request with the recipient\'s links, with the note' => [bmk('approval_request.waiting.subject', BMK_TENANT), implode("\n\n", [
            bmk('approval_request.waiting.with_links', BMK_TENANT),
            $facts['lines'],
            $facts['all'].$note,
            $facts['total'],
            bmkButtons(),
            $facts['zone'],
        ])],
        'the approval request to a staff row\'s address' => [bmk('approval_request.waiting.subject', BMK_TENANT), implode("\n\n", [
            bmk('approval_request.waiting.without_links', BMK_TENANT),
            $facts['lines'],
            $facts['name'],
            $facts['total'],
            bmk('links.none'),
            $facts['zone'],
        ])],
        'the approval request settled since' => [bmk('approval_request.settled.subject', BMK_TENANT), implode("\n\n", [
            bmk('approval_request.settled.opening', BMK_TENANT),
            $facts['lines'],
            $facts['all'],
            $facts['total'],
            bmk('approval_request.settled.nothing_left'),
            $facts['zone'],
        ])],
        'the nag with the recipient\'s links' => [bmk('approval_nag.subject', BMK_TENANT), implode("\n\n", [
            bmk('approval_nag.with_links', BMK_TENANT),
            $facts['lines'],
            $facts['all'],
            $facts['total'],
            bmk('approval_nag.runs_out', $end, '36'),
            bmkButtons(),
            $facts['zone'],
        ])],
        'the nag to a staff row\'s address' => [bmk('approval_nag.subject', BMK_TENANT), implode("\n\n", [
            bmk('approval_nag.without_links', BMK_TENANT),
            $facts['lines'],
            $facts['name'],
            $facts['total'],
            bmk('approval_nag.runs_out', $end, '36'),
            bmk('links.none'),
            $facts['zone'],
        ])],
        'the automatic approval, confirmed, the customer told' => [bmk('auto_approved.subject', BMK_TENANT), $notice(bmk('auto_approved.opening', BMK_TENANT), $facts['all'], bmk('auto_approved.confirmed.told'))],
        'the automatic approval, confirmed, the customer not told' => [bmk('auto_approved.subject', BMK_TENANT), $notice(bmk('auto_approved.opening', BMK_TENANT), $facts['all'], bmk('auto_approved.confirmed.untold', $label, BMK_TENANT))],
        'the automatic approval awaiting payment, the customer told' => [bmk('auto_approved.subject', BMK_TENANT), $notice(bmk('auto_approved.opening', BMK_TENANT), $facts['all'], bmk('auto_approved.awaiting_payment.told'))],
        'the automatic approval awaiting payment, the customer not told' => [bmk('auto_approved.subject', BMK_TENANT), $notice(bmk('auto_approved.opening', BMK_TENANT), $facts['all'], bmk('auto_approved.awaiting_payment.untold', $label, BMK_TENANT))],
        'the cancellation of a request by the customer' => [bmk('cancellation_notice.request.subject', BMK_TENANT), $cancelled('request', 'customer', $facts['all'], 'Casey Customer')],
        'the cancellation of a booking by the customer' => [bmk('cancellation_notice.booking.subject', BMK_TENANT), $cancelled('booking', 'customer', $facts['all'], 'Casey Customer')],
        'the cancellation of a booking by a user' => [bmk('cancellation_notice.booking.subject', BMK_TENANT), $cancelled('booking', 'user', $facts['name'], 'Marina Manager')],
        'the cancellation of a booking by a user since removed' => [bmk('cancellation_notice.booking.subject', BMK_TENANT), $cancelled('booking', 'removed', $facts['all'])],
        'the cancellation of a booking by support' => [bmk('cancellation_notice.booking.subject', BMK_TENANT), $cancelled('booking', 'support', $facts['all'], BMK_PLATFORM)],
        'the cancellation of a booking by the system' => [bmk('cancellation_notice.booking.subject', BMK_TENANT), $cancelled('booking', 'system', $facts['all'])],
        'the move of a request by the customer' => [bmk('move_notice.request.subject', BMK_TENANT), $moved('request', 'customer', $facts['all'], 'Casey Customer')],
        'the move of a booking by the customer' => [bmk('move_notice.booking.subject', BMK_TENANT), $moved('booking', 'customer', $facts['all'], 'Casey Customer')],
        'the move of a booking by a user' => [bmk('move_notice.booking.subject', BMK_TENANT), $moved('booking', 'user', $facts['name'], 'Marina Manager')],
        'the move of a booking by a user since removed' => [bmk('move_notice.booking.subject', BMK_TENANT), $moved('booking', 'removed', $facts['all'])],
        'the move of a booking by support' => [bmk('move_notice.booking.subject', BMK_TENANT), $moved('booking', 'support', $facts['all'], BMK_PLATFORM)],
        'the move of a booking by the system' => [bmk('move_notice.booking.subject', BMK_TENANT), $moved('booking', 'system', $facts['all'])],
        'the late payment notice' => [bmk('late_payment.subject', BMK_TENANT), implode("\n\n", [
            bmk('late_payment.arrived', BMK_TENANT),
            $facts['lines'],
            $facts['all'],
            $facts['total'],
            bmk('late_payment.reference').': '.SnapshotFixture::UUID,
            bmk('late_payment.stands'),
            $facts['zone'],
        ])],
    ];
}

it('writes {0} as keys, the values that fill them and its own layout, with no sentence of its own', function (string $form) {
    $message = bmkForms()[$form]('en');
    [$subject, $body] = bmkExpected()[$form];

    expect($message->subject)->toBe($subject)
        ->and($message->body)->toBe($body);
})->with(fn () => array_keys(bmkForms()));

it('walks every key of business-mail.php through the forms above, so no key is left that no form reads', function () {
    $lines = require dirname(__DIR__, 2).'/lang/en/business-mail.php';
    Assert::assertIsArray($lines);
    $keys = [];
    $walk = function (array $lines, string $prefix) use (&$walk, &$keys): void {
        foreach ($lines as $key => $value) {
            is_array($value) ? $walk($value, $prefix.$key.'.') : $keys[] = $prefix.$key;
        }
    };
    $walk($lines, 'business-mail.');

    foreach (bmkForms() as $build) {
        $build('en');
    }

    $read = array_values(array_unique(array_map(fn (array $line): string => $line[0], KeyedLines::asked())));

    expect(array_values(array_diff($keys, $read)))->toBe([]);
});

/**
 * Every line of the files a business message reads that the keyed table was asked for, with the
 * language asked: business-mail.php and decision.php's facts.
 *
 * @return list<array{string, string}>
 */
function bmkAsked(): array
{
    return array_values(array_filter(KeyedLines::asked(), fn (array $line): bool => str_starts_with($line[0], 'business-mail.') || str_starts_with($line[0], 'decision.')));
}

it('asks every line of {0} in the language its recipient reads, Greek for a Greek recipient whatever the application\'s locale, its instants ICU\'s Greek', function (string $form) {
    $message = bmkForms()[$form]('el');
    $asked = bmkAsked();
    $notGreek = array_values(array_filter($asked, fn (array $line): bool => $line[1] !== 'el'));

    expect(array_filter($asked, fn (array $line): bool => $line[1] === 'el'))->not->toBe([])
        // The nag's time left is a choice, which the translator reads in the language the file
        // holds the line in, its fallback while the Greek twin is pending, the keyed table
        // holding English alone; every other line of every form is asked in Greek.
        ->and($notGreek)->toBe(str_starts_with($form, 'the nag') ? [['business-mail.approval_nag.runs_out', 'en']] : [])
        ->and($message->language)->toBe('el')
        ->and($message->body)->toContain(TenantTime::instant(bmkBooking()->startsAt(), bmkClock(), SiteLanguage::Greek))
        ->and($message->body)->not->toContain(TenantTime::instant(bmkBooking()->startsAt(), bmkClock(), SiteLanguage::English));
})->with(fn () => array_keys(bmkForms()));

it('asks every line of {0} in English for a recipient reading English', function (string $form) {
    $message = bmkForms()[$form]('en');
    $asked = bmkAsked();

    expect($asked)->not->toBe([])
        ->and(array_values(array_unique(array_map(fn (array $line): string => $line[1], $asked))))->toBe(['en'])
        ->and($message->language)->toBe('en');
})->with(fn () => array_keys(bmkForms()));

it('spells the move\'s two starts and the nag\'s expiry in the recipient\'s language', function () {
    $move = bmkForms()['the move of a booking by the customer']('el');
    $nag = bmkForms()['the nag with the recipient\'s links']('el');

    expect($move->body)->toContain(bmk('move_notice.booking.opening', BMK_TENANT, TenantTime::instant(bmkMovedFrom(), bmkClock(), SiteLanguage::Greek), TenantTime::instant(bmkBooking()->startsAt(), bmkClock(), SiteLanguage::Greek)))
        ->and($nag->body)->toContain(bmk('approval_nag.runs_out', TenantTime::instant(bmkExpiry(), bmkClock(), SiteLanguage::Greek), '36'));
});

it('reads who acted once for every recipient, a shape and a name and no words, whichever language each reads', function () {
    $who = bmkWho('user');
    $english = CancellationNotice::of(bmkBooking(), BMK_TENANT, bmkClock(), bmkOwner('en'), false, $who);
    $greek = CancellationNotice::of(bmkBooking(), BMK_TENANT, bmkClock(), bmkRow('el'), false, $who);

    expect($english->body)->toContain(bmk('cancellation_notice.by.user', 'Marina Manager'))
        ->and($greek->body)->toContain(bmk('cancellation_notice.by.user', 'Marina Manager'))
        ->and(array_values(array_filter(KeyedLines::asked(), fn (array $line): bool => $line[0] === 'business-mail.cancellation_notice.by.user')))
        ->toBe([['business-mail.cancellation_notice.by.user', 'en'], ['business-mail.cancellation_notice.by.user', 'el']]);
});
