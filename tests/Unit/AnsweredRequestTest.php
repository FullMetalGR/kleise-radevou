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
use App\Notifications\AutoApprovedNotice;
use App\Notifications\CustomerMailLines;
use App\Notifications\IcsBuilder;
use App\Notifications\RequestApproved;
use App\Notifications\RequestExpired;
use App\Notifications\RequestRejected;
use App\Notifications\TenantClock;
use App\Site\SiteLanguage;
use Tests\Support\LangLines;

/*
 * The three ways a request lodged with a human ends, as the customer reads them, and the one
 * of the three the business is told about as well.
 *
 * Each of them is about a request somebody has been waiting on, so each of them names the
 * booking by its times rather than by an identifier nobody memorised. The times are the
 * tenant's, named once.
 *
 * Two things are absent by design and are asserted as absences. The owner's reason for
 * declining never reaches the customer; and no answer carries the magic link, because the
 * record none of these is built from carries the token and the acknowledgement was its one
 * carrier.
 *
 * Two of them carry a sentence about another message, and a sentence like that is true only of
 * a tenant who sends the message it names: emails_off may carry any key, so each is asserted in
 * both of its forms.
 *
 * The booking's facts are lines of decision.php read through a translator over the lang
 * directory, and an instant is ICU's medium date and short time. The customer's three answers
 * are lines of customer-mail.php in the booking's language, English here, each read through the
 * translator below; CustomerMailKeysTest holds their whole bodies to their keys. The business
 * notice's words are lines of business-mail.php in the recipient's language, English here, each
 * read through the translator below; BusinessMailKeysTest holds its four forms whole to their
 * keys.
 */

/**
 * A line of the customer's messages as the translator over the lang directory reads it, the
 * business filled in.
 */
function answeredLine(string $key, string $tenant = 'Luna Hair Studio'): string
{
    return LangLines::line('customer-mail.'.$key, ['business' => $tenant]);
}

/**
 * A paragraph of the business's notice of an automatic approval as the translator reads it, the
 * switch named by the customer's approval message's label and the business filled in.
 */
function answeredAutoLine(string $form): string
{
    return LangLines::line('business-mail.auto_approved.'.$form, ['label' => LangLines::line(MessageKey::Approved->labelKey()), 'business' => 'Luna Hair Studio']);
}

beforeEach(function () {
    LangLines::bind();
});

afterEach(function () {
    LangLines::release();
});

/**
 * A booking of one tenant in the status named, its items spelled as [service, staff, implicit,
 * hour], on the Athens Tuesday the notification fixtures use.
 *
 * @param  list<array{string, string, bool, int}>  $items
 */
function answeredBooking(
    BookingStatus $status = BookingStatus::Confirmed,
    array $items = [['Bridal trial', 'Elena Papadaki', false, 10]],
    string $currency = 'EUR',
    string $timezone = 'Europe/Athens',
): BookingSnapshot {
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
        '7b7f0e5a-0000-4000-8000-000000000004',
        $status,
        null,
        null,
        new Customer('Casey Customer', 'casey@example.test', '+30 210 7654321'),
        new Money(3500 * count($items), Currency::of($currency)),
        $snapshots,
    );
}

const ANSWERED_STAMP = '2026-10-01 07:00:00';

function answeredStamp(): DateTimeImmutable
{
    return new DateTimeImmutable(ANSWERED_STAMP, new DateTimeZone('UTC'));
}

function answeredApproval(?BookingSnapshot $booking = null, string $tenant = 'Luna Hair Studio', string $timezone = 'Europe/Athens', bool $acknowledgementWasSent = true): Message
{
    return RequestApproved::of($booking ?? answeredBooking(), $tenant, new TenantClock(new DateTimeZone($timezone)), answeredStamp(), $acknowledgementWasSent);
}

function answeredRejection(?BookingSnapshot $booking = null, string $tenant = 'Luna Hair Studio', string $timezone = 'Europe/Athens'): Message
{
    return RequestRejected::of($booking ?? answeredBooking(BookingStatus::Rejected), $tenant, new TenantClock(new DateTimeZone($timezone)));
}

function answeredTimeout(?BookingSnapshot $booking = null, string $tenant = 'Luna Hair Studio', string $timezone = 'Europe/Athens'): Message
{
    return RequestExpired::of($booking ?? answeredBooking(BookingStatus::Expired), $tenant, new TenantClock(new DateTimeZone($timezone)));
}

function answeredUnpaid(?BookingSnapshot $booking = null, string $tenant = 'Luna Hair Studio', string $timezone = 'Europe/Athens'): Message
{
    return RequestExpired::unpaid($booking ?? answeredBooking(BookingStatus::Expired), $tenant, new TenantClock(new DateTimeZone($timezone)));
}

function answeredAutoNotice(bool $managesBookings = true, ?BookingSnapshot $booking = null, string $tenant = 'Luna Hair Studio', bool $customerWasTold = true, bool $awaitingPayment = false): Message
{
    return AutoApprovedNotice::of(
        $booking ?? answeredBooking(),
        $tenant,
        new TenantClock(new DateTimeZone('Europe/Athens')),
        new Recipient('owner@luna.example', 4, $managesBookings),
        $customerWasTold,
        $awaitingPayment,
    );
}

/**
 * Every message of this file, so a rule that holds over all four is asserted over all four rather
 * than over whichever one was being written at the time. Each is built when its case runs, after
 * the translator over the lang directory is bound, so a dataset holds the closure.
 *
 * @return array<string, array{Closure(): Message}>
 */
function answeredMessages(): array
{
    return [
        'the approval' => [fn (): Message => answeredApproval()],
        'the rejection' => [fn (): Message => answeredRejection()],
        'the timeout' => [fn (): Message => answeredTimeout()],
        'the business notice' => [fn (): Message => answeredAutoNotice()],
        // The two that a switch elsewhere rewrites, in their other form: a rule that holds over
        // a message holds over both things that message can say.
        'the approval where no acknowledgement went' => [fn (): Message => answeredApproval(acknowledgementWasSent: false)],
        'the business notice where the customer was not told' => [fn (): Message => answeredAutoNotice(customerWasTold: false)],
        // The payment forms: a payment hold that lapsed unpaid, and a grant that landed
        // awaiting its payment rather than confirmed, told and untold.
        'the unpaid lapse' => [fn (): Message => answeredUnpaid()],
        'the business notice where the grant awaits its payment' => [fn (): Message => answeredAutoNotice(awaitingPayment: true)],
        'the business notice where the grant awaits its payment and the customer was not told' => [fn (): Message => answeredAutoNotice(customerWasTold: false, awaitingPayment: true)],
    ];
}

it('tells the customer their request was approved, under the approved key, to the address on the booking', function () {
    $message = answeredApproval();

    expect($message->key)->toBe(MessageKey::Approved)
        ->and($message->to)->toEqual(new Recipient('casey@example.test'))
        ->and($message->bookingUuid)->toBe('7b7f0e5a-0000-4000-8000-000000000004')
        ->and($message->subject)->toBe(answeredLine('approved.confirmed.subject'))
        ->and($message->body)->toContain(answeredLine('approved.confirmed.approved'))
        ->and($message->body)->toContain('Casey Customer')
        ->and($message->body)->toContain('Bridal trial')
        ->and($message->body)->toContain('35.00 EUR');
});

it('carries the calendar file with the approval, because that is the message that tells the customer they have an appointment', function () {
    $booking = answeredBooking();
    $message = answeredApproval($booking);

    expect($message->attachments)->toHaveCount(1)
        ->and($message->attachments[0]->name)->toBe('booking.ics')
        ->and($message->attachments[0]->contentType)->toBe('text/calendar')
        ->and($message->attachments[0]->contents)->toBe(
            IcsBuilder::file($booking, CustomerMailLines::summary($booking, 'Luna Hair Studio', SiteLanguage::English), answeredStamp(), 0),
        );
});

it('points the customer at the acknowledgement for their link, because the approval carries no token to build one from', function () {
    $body = answeredApproval()->body;

    expect($body)->not->toContain('http')
        ->and($body)->not->toContain('/manage/')
        // The acknowledgement is named as where the link is. Every approval hold is
        // acknowledged whatever the re-read found, which is what makes this sentence true of
        // every customer of a tenant who sends that message at all.
        ->and($body)->toContain(answeredLine('approved.confirmed.where_link.acknowledged'));
});

it('sends a customer whose tenant switched the acknowledgement off to the business instead, because there is no message for them to look in and no link in their hands', function () {
    $told = answeredApproval()->body;
    $never = answeredApproval(acknowledgementWasSent: false)->body;

    // Not the same paragraph with a word changed: the one sentence that names another message is
    // gone, because for this customer no such message exists. The booking app never renders the
    // token and loses it on a reload, so there is nothing else to point at and the business is who
    // can still act for them.
    $acknowledged = answeredLine('approved.confirmed.where_link.acknowledged');
    $unacknowledged = answeredLine('approved.confirmed.where_link.unacknowledged');

    expect($never)->not->toContain($acknowledged)
        ->and($never)->toContain($unacknowledged)
        ->and($told)->not->toContain($unacknowledged)
        ->and($told)->toContain($acknowledged)
        // Both of them still say where the appointment is, because the file is attached either
        // way and that sentence turns on nothing: the two whole lines open on the same sentence.
        ->and(strtok($unacknowledged, '.'))->toBe(strtok($acknowledged, '.'));
});

it('tells the customer their request was declined, under the rejected key, without a calendar file', function () {
    $message = answeredRejection();

    expect($message->key)->toBe(MessageKey::Rejected)
        ->and($message->to)->toEqual(new Recipient('casey@example.test'))
        ->and($message->attachments)->toBe([])
        ->and($message->subject)->toBe(answeredLine('rejected.subject'))
        ->and($message->body)->toContain(answeredLine('rejected.declined'))
        ->and($message->body)->toContain('Casey Customer')
        ->and($message->body)->toContain('Bridal trial')
        ->and($message->body)->toContain('3 Nov 2026, 10:00');
});

it('tells the customer nobody decided in time, under the request_expired key, without a calendar file', function () {
    $message = answeredTimeout();

    expect($message->key)->toBe(MessageKey::RequestExpired)
        ->and($message->to)->toEqual(new Recipient('casey@example.test'))
        ->and($message->attachments)->toBe([])
        ->and($message->subject)->toBe(answeredLine('request_expired.timed_out.subject'))
        ->and($message->body)->toContain(answeredLine('request_expired.timed_out.expired'))
        ->and($message->body)->toContain('Casey Customer')
        ->and($message->body)->toContain('Bridal trial')
        ->and($message->body)->toContain('3 Nov 2026, 10:00');
});

it('tells the customer an approved booking whose payment did not reach the business in time has expired, under the request_expired key, without a calendar file, and never says nobody answered', function () {
    $message = answeredUnpaid();

    expect($message->key)->toBe(MessageKey::RequestExpired)
        ->and($message->to)->toEqual(new Recipient('casey@example.test'))
        ->and($message->attachments)->toBe([])
        ->and($message->subject)->toBe(answeredLine('request_expired.unpaid.subject'))
        ->and($message->body)->toContain('Casey Customer')
        ->and($message->body)->toContain('Bridal trial')
        ->and($message->body)->toContain('3 Nov 2026, 10:00')
        // The business did answer; it is the payment that did not arrive, and the time is
        // given back as the approved message said it would be.
        ->and($message->body)->toContain(answeredLine('request_expired.unpaid.expired'))
        ->and($message->body)->toContain(answeredLine('request_expired.unpaid.free_again'))
        ->and($message->body)->not->toContain(answeredLine('request_expired.timed_out.expired'))
        ->and($message->subject)->not->toBe(answeredLine('request_expired.timed_out.subject'));
});

it('takes no reason at all, so the owner note cannot reach the customer through the one message it would fit in', function () {
    $signature = (new ReflectionMethod(RequestRejected::class, 'of'))->getParameters();

    // The reason is not withheld by this template, it is unavailable to it: the snapshot a job
    // re-reads does not carry the column, and nothing may pass one in. A rule kept by arithmetic in
    // the words above it is a rule one edit away from being lost. The snapshot's shape is pinned
    // whole rather than one spelling of one column, so the reason reaching it under any name, or
    // any other column joining it, fails here. The one parameter after the clock is the closing
    // line's page, My bookings' URL or null, typed as a nullable string the job spells from the
    // tenant's origin and nothing else; the clock is the tenant's zone and formats; the language
    // the booking was made in is a code beside the rest, the one its messages are written in.
    expect(array_map(fn (ReflectionParameter $parameter): string => $parameter->getName(), $signature))
        ->toBe(['booking', 'tenantName', 'clock', 'myBookings'])
        ->and((string) ($signature[3] ?? null)?->getType())->toBe('?string')
        ->and(array_map(fn (ReflectionProperty $property): string => $property->getName(), (new ReflectionClass(BookingSnapshot::class))->getProperties()))
        ->toBe(['items', 'uuid', 'status', 'holdClass', 'holdExpiresAt', 'customer', 'total', 'language']);
});

it('tells the business the clock granted a request, under the auto_approved key, to the one recipient it was built for', function () {
    $message = answeredAutoNotice();

    expect($message->key)->toBe(MessageKey::AutoApproved)
        ->and($message->to)->toEqual(new Recipient('owner@luna.example', 4, true))
        ->and($message->bookingUuid)->toBe('7b7f0e5a-0000-4000-8000-000000000004')
        ->and($message->attachments)->toBe([])
        ->and($message->body)->toContain('Bridal trial')
        ->and($message->body)->toContain('35.00 EUR')
        // What happened, said as what it was: nobody answered, and the policy the tenant set on
        // the service answered for them.
        ->and($message->body)->toContain(answeredAutoLine('confirmed.told'))
        ->and($message->subject)->toBe(LangLines::line('business-mail.auto_approved.subject', ['business' => 'Luna Hair Studio']))
        ->and($message->language)->toBe('en');
});

it('tells the business the customer has been told only where the message telling them is one this tenant sends', function () {
    $told = answeredAutoNotice()->body;
    $untold = answeredAutoNotice(customerWasTold: false)->body;

    expect($told)->toContain(answeredAutoLine('confirmed.told'))
        ->and($told)->not->toContain(answeredAutoLine('confirmed.untold'))
        // A business whose customer has heard nothing is a business that may want to telephone
        // them, and the setting that did it is named by the words beside its own checkbox.
        ->and($untold)->not->toContain(answeredAutoLine('confirmed.told'))
        ->and($untold)->toContain(answeredAutoLine('confirmed.untold'))
        ->and(answeredAutoLine('confirmed.untold'))->toContain(LangLines::line(MessageKey::Approved->labelKey()))
        // Whose settings, by name: a staff row's own address reads this too and has none.
        ->and(answeredAutoLine('confirmed.untold'))->toContain('Luna Hair Studio');
});

it('tells the business a grant that landed awaiting its payment awaits it and never that it is confirmed, under the auto_approved key, told and untold', function () {
    $told = answeredAutoNotice(awaitingPayment: true);
    $untold = answeredAutoNotice(customerWasTold: false, awaitingPayment: true);

    expect($told->key)->toBe(MessageKey::AutoApproved)
        ->and($untold->key)->toBe(MessageKey::AutoApproved)
        ->and($told->body)->toContain(answeredAutoLine('awaiting_payment.told'))
        ->and($told->body)->not->toContain(answeredAutoLine('confirmed.told'))
        ->and($told->body)->not->toContain(answeredAutoLine('confirmed.untold'))
        // Untold, the customer holds no link, so the business is told they have none to pay
        // with and the time is given back when the hold ends (the switch keeps its
        // meaning).
        ->and($untold->body)->toContain(answeredAutoLine('awaiting_payment.untold'))
        ->and($untold->body)->not->toContain(answeredAutoLine('awaiting_payment.told'))
        ->and(answeredAutoLine('awaiting_payment.untold'))->toContain(LangLines::line(MessageKey::Approved->labelKey()))
        ->and(answeredAutoLine('awaiting_payment.untold'))->toContain('Luna Hair Studio')
        ->and($untold->body)->not->toContain(answeredAutoLine('confirmed.untold'))
        // The four forms are four paragraphs, and the confirmed form is untouched.
        ->and(array_unique(array_map(answeredAutoLine(...), ['confirmed.told', 'confirmed.untold', 'awaiting_payment.told', 'awaiting_payment.untold'])))->toHaveCount(4)
        ->and(answeredAutoNotice()->body)->toContain(answeredAutoLine('confirmed.told'));
});

it('quotes the approved message\'s label in the mail\'s language, the Greek mail lang/el/admin/emails.php\'s own label, unlike the English the English mail quotes', function () {
    $greek = fn (): string => AutoApprovedNotice::of(answeredBooking(), 'Luna Hair Studio', new TenantClock(new DateTimeZone('Europe/Athens')), new Recipient('owner@luna.example', 4, true, SiteLanguage::Greek->value), false)->body;
    $untold = fn (string $label): string => LangLines::line('business-mail.auto_approved.confirmed.untold', ['label' => $label, 'business' => 'Luna Hair Studio'], SiteLanguage::Greek);
    $label = LangLines::line(MessageKey::Approved->labelKey(), [], SiteLanguage::Greek);

    // The label is read in the mail's language, Greek here, and not the English the suite runs in.
    expect($label)->not->toBe(LangLines::line(MessageKey::Approved->labelKey()))
        ->and($greek())->toContain($untold($label))
        ->and(answeredAutoNotice(customerWasTold: false)->body)->toContain(answeredAutoLine('confirmed.untold'))
        ->and(answeredAutoNotice(customerWasTold: false)->body)->not->toContain($label);
});

it('tells a business recipient how to reach the customer only where that recipient manages bookings', function () {
    $manager = answeredAutoNotice()->body;
    $chair = answeredAutoNotice(managesBookings: false)->body;

    expect($manager)->toContain('Casey Customer')
        ->and($manager)->toContain('casey@example.test')
        ->and($manager)->toContain('+30 210 7654321')
        ->and($chair)->toContain('Casey Customer')
        ->and($chair)->not->toContain('casey@example.test')
        ->and($chair)->not->toContain('+30 210 7654321');
});

it('renders every item in the tenant clock and not in the clock the row is stored in', function (Message $message) {
    expect($message->body)->toContain('3 Nov 2026, 10:00')
        // 08:00 is what the row holds, in UTC. It is never the hour the appointment is called.
        ->and($message->body)->not->toContain(', 08:00');
})->with(answeredMessages(...));

it('names the zone once, because a time nobody can place is worse than a line of housekeeping', function (Message $message) {
    expect(substr_count($message->body, 'Europe/Athens'))->toBe(1);
})->with(answeredMessages(...));

it('names the customer clock to the customer and the business own clock to the business', function () {
    $customerClock = LangLines::line('customer-mail.zone', ['zone' => 'Europe/Athens', 'business' => 'Luna Hair Studio']);

    expect(answeredApproval()->body)->toContain($customerClock)
        ->and(answeredRejection()->body)->toContain($customerClock)
        ->and(answeredTimeout()->body)->toContain($customerClock)
        ->and(answeredUnpaid()->body)->toContain($customerClock)
        ->and(answeredAutoNotice()->body)->not->toContain($customerClock)
        ->and(answeredAutoNotice()->body)->toContain(LangLines::line('decision.facts.zone', ['zone' => 'Europe/Athens']));
});

it('names the staff member of every item whose row is not the implicit one', function () {
    $named = answeredApproval()->body;
    $implicit = answeredApproval(
        answeredBooking(items: [['Initial assessment', 'Pier Physiotherapy', true, 10]], currency: 'GBP', timezone: 'Europe/London'),
        tenant: 'Pier Physiotherapy',
        timezone: 'Europe/London',
    )->body;

    expect($named)->toContain(LangLines::line('decision.facts.item', ['service' => 'Bridal trial', 'staff' => 'Elena Papadaki']))
        ->and($implicit)->toContain("    Initial assessment\n")
        ->and($implicit)->not->toContain(LangLines::line('decision.facts.item', ['service' => 'Initial assessment', 'staff' => 'Pier Physiotherapy']));
});

it('times every segment of a chain, because a chain the customer thinks of as one morning is several hours', function () {
    $body = answeredTimeout(answeredBooking(BookingStatus::Expired, [
        ['Colour', 'Elena Papadaki', false, 10],
        ['Haircut', 'Nikos Georgiou', false, 12],
    ]))->body;

    expect($body)->toContain('3 Nov 2026, 10:00')
        ->and($body)->toContain('3 Nov 2026, 12:00');
});

it('carries no magic link and no identifier anybody could act on, whichever of the answers it is', function (Message $message) {
    expect($message->body)->not->toContain('http')
        ->and($message->body)->not->toContain('/manage/')
        ->and($message->body)->not->toContain($message->bookingUuid);
})->with(answeredMessages(...));

it('is ASCII, like every template', function (Message $message) {
    expect(preg_match('/\A[\x09\x0a\x20-\x7e]+\z/', $message->body))->toBe(1)
        ->and(preg_match('/\A[\x20-\x7e]+\z/', $message->subject))->toBe(1);
})->with(answeredMessages(...));
